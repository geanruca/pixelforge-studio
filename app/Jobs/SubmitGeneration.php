<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PixelforgeException;
use App\Models\Generation;
use App\Services\Generations\GenerationSynchronizer;
use App\Services\Pixelforge\PixelforgeClient;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a generation to Pixelforge.
 *
 * Submitting happens in the queue, not in the web request, because Pixelforge
 * regularly says "not now": 3 concurrent generations per account, 60 requests
 * a minute, random 503s. The job waits as long as `Retry-After` asks and tries
 * again, for up to the submission window, while the user already sees their
 * generation as queued. Retrying is safe because every attempt carries the
 * generation's idempotency key.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Seconds to wait when Pixelforge gives no `Retry-After`. */
    private const int DEFAULT_RETRY_SECONDS = 5;

    /** Longest single wait, so one bad header cannot park a generation. */
    private const int MAX_RETRY_SECONDS = 60;

    /** Attempts are bounded by {@see retryUntil()}, not by a count. */
    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $generationId) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(config()->integer('pixelforge.submission_window_minutes'));
    }

    public function handle(PixelforgeClient $client, GenerationSynchronizer $synchronizer): void
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
            'The image service was too busy to accept this request. You were not charged — please try again.',
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

        $this->release(min(self::MAX_RETRY_SECONDS, $exception->retryAfterSeconds ?? self::DEFAULT_RETRY_SECONDS));
    }
}
