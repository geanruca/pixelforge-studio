<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\ImageProvider;
use App\Exceptions\PixelforgeException;
use App\Models\Generation;
use App\Services\Generations\GenerationSynchronizer;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a generation to Pixelforge.
 *
 * Submitting happens in the queue, not in the web request, because Pixelforge
 * regularly says "not now", and the two kinds of "not now" are handled
 * differently:
 *
 *  - All 3 concurrency slots are taken. Nothing is wrong: the generation
 *    simply waits its turn, for as long as the submission window allows.
 *  - Pixelforge is overloaded, rate limiting us or not answering. The job
 *    backs off exponentially (never sooner than `Retry-After`) and stops after
 *    a few attempts, so a struggling provider is not hammered.
 *
 * Meanwhile the user already sees their generation as queued. Retrying is
 * safe because every attempt carries the generation's idempotency key.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Seconds to wait when Pixelforge gives no `Retry-After`. */
    private const int DEFAULT_RETRY_SECONDS = 5;

    /** Longest single wait, so one bad header cannot park a generation. */
    private const int MAX_RETRY_SECONDS = 60;

    /** Attempts Pixelforge may refuse (overload, rate limit, no answer) before we stop. */
    public const int MAX_SUBMISSION_FAILURES = 6;

    private const string GAVE_UP_MESSAGE = 'The image service was too busy to accept this request. Your reserved credits were returned — please try again.';

    /** Attempts are bounded by {@see retryUntil()}, not by a count. */
    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 30;

    /**
     * Seconds between retries after an unexpected exception (a database
     * blip while applying the response), so three quick crashes in a row do
     * not use up `maxExceptions`.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [2, 10, 30];
    }

    public function __construct(public readonly int $generationId) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(config()->integer('pixelforge.submission_window_minutes'));
    }

    public function handle(ImageProvider $client, GenerationSynchronizer $synchronizer): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null || $generation->provider_id !== null || $generation->isFinal()) {
            return;
        }

        // Do not spend a rate-limited request we know will be refused.
        if (Generation::query()->inFlight()->count() >= config()->integer('pixelforge.max_concurrent_generations')) {
            $this->release(self::DEFAULT_RETRY_SECONDS);

            return;
        }

        try {
            $snapshot = $client->createGeneration($generation);
        } catch (PixelforgeException $exception) {
            $this->handleRejection($generation, $exception, $synchronizer);

            return;
        }

        $synchronizer->apply($generation, $snapshot);
    }

    /**
     * Runs when the submission window closed (or the job kept crashing)
     * without Pixelforge accepting the generation. The user gets their
     * reservation back. The outcome is recorded as unconfirmed because a
     * timed-out attempt may have been accepted after all; if so, its webhook
     * will still be applied.
     */
    public function failed(?Throwable $exception): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null || $generation->provider_id !== null || $generation->isFinal()) {
            return;
        }

        Log::error('Generation submission abandoned', [
            'generation_id' => $generation->id,
            'error' => $exception?->getMessage(),
        ]);

        app(GenerationSynchronizer::class)->failLocally(
            $generation,
            'submission_timeout',
            self::GAVE_UP_MESSAGE,
            confirmed: false,
        );
    }

    private function handleRejection(Generation $generation, PixelforgeException $exception, GenerationSynchronizer $synchronizer): void
    {
        Log::warning('Pixelforge did not accept a generation', [
            'generation_id' => $generation->id,
            'code' => $exception->errorCode,
            'http_status' => $exception->httpStatus,
            'retry_after' => $exception->retryAfterSeconds,
            'attempt' => $this->attempts(),
        ]);

        if ($exception->isDefinitiveRejection()) {
            $synchronizer->failLocally($generation, $exception->errorCode, $exception->getMessage(), confirmed: true);

            return;
        }

        // Neither is a failed attempt: a slot is busy, or the circuit breaker did not even call.
        if (in_array($exception->errorCode, ['concurrency_limit_exceeded', PixelforgeException::CIRCUIT_OPEN], true)) {
            $this->release(min(self::MAX_RETRY_SECONDS, $exception->retryAfterSeconds ?? self::DEFAULT_RETRY_SECONDS));

            return;
        }

        $failures = $generation->submission_failures + 1;
        $generation->update(['submission_failures' => $failures]);

        if ($failures >= self::MAX_SUBMISSION_FAILURES) {
            // Unconfirmed: an attempt that timed out may still have been accepted.
            $synchronizer->failLocally($generation, 'submission_timeout', self::GAVE_UP_MESSAGE, confirmed: false);

            return;
        }

        $this->release(min(self::MAX_RETRY_SECONDS, max($exception->retryAfterSeconds ?? 0, 2 ** $failures)));
    }
}
