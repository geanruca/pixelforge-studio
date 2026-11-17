<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ImageProvider;
use App\Enums\GenerationStatus;
use App\Exceptions\PixelforgeException;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Services\Credits\CreditLedger;
use App\Services\Generations\GenerationSynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The safety net behind webhooks and behind the submission job.
 *
 * Webhook delivery is at-least-once but not guaranteed (all six attempts can
 * fail while we are down), so any generation Pixelforge has accepted and not
 * yet confirmed as finished is fetched and brought up to date through the
 * same synchronizer the webhooks use. Generations whose submission job
 * disappeared are closed and refunded, and missing image copies retried.
 */
#[Signature('generations:reconcile {--limit=20 : Most generations to poll in one run, to stay inside the rate limit}')]
#[Description('Poll Pixelforge for in-flight generations whose webhooks have not arrived')]
class ReconcileGenerations extends Command
{
    public function handle(ImageProvider $client, GenerationSynchronizer $synchronizer): int
    {
        $quietSince = now()->subSeconds(config()->integer('pixelforge.reconcile_after_seconds'));

        $generations = Generation::query()
            ->awaitingProviderOutcome()
            ->where('last_synced_at', '<', $quietSince)
            ->oldest('last_synced_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($generations as $generation) {
            // Whatever happens, this one goes to the back of the line: a
            // generation that keeps failing to sync must not starve the rest.
            $generation->update(['last_synced_at' => now()]);

            try {
                $synchronizer->apply($generation, $client->getGeneration((string) $generation->provider_id));
            } catch (PixelforgeException $exception) {
                Log::warning('Could not reconcile a generation', [
                    'generation_id' => $generation->id,
                    'code' => $exception->errorCode,
                ]);

                if ($exception->errorCode === 'not_found') {
                    $this->closeUnknownToProvider($generation);
                }

                if (in_array($exception->errorCode, ['rate_limit_exceeded', PixelforgeException::CIRCUIT_OPEN], true)) {
                    break;
                }
            } catch (Throwable $exception) {
                Log::error('Reconciling a generation crashed', [
                    'generation_id' => $generation->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $abandoned = $this->closeAbandonedSubmissions($synchronizer);
        $this->retryMissingImages();

        $this->info("Reconciled {$generations->count()} generation(s), closed {$abandoned} abandoned submission(s).");

        return self::SUCCESS;
    }

    /**
     * Pixelforge says the generation does not exist, so it will never bill
     * it. `failLocally` refuses to touch a generation that has a provider id,
     * so this closes it directly, with the same lock and the same refund.
     */
    private function closeUnknownToProvider(Generation $generation): void
    {
        DB::transaction(function () use ($generation): void {
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($locked->outcome_confirmed) {
                return;
            }

            $wasFinal = $locked->isFinal();

            $locked->update([
                'status' => GenerationStatus::Failed,
                'outcome_confirmed' => true,
                'error_code' => $wasFinal ? $locked->error_code : 'not_found',
                'completed_at' => $locked->completed_at ?? now(),
            ]);

            app(CreditLedger::class)->release($locked);
        });
    }

    /**
     * The last line of defence for held credits. A submission normally ends
     * through its job (accepted, rejected or given up). If that job is lost —
     * the queue was flushed, or its `failed()` hook itself crashed — nothing
     * else would ever look at the generation again and its hold would stay
     * forever. Anything still unsubmitted well after the submission window
     * is closed here and its credits returned.
     */
    private function closeAbandonedSubmissions(GenerationSynchronizer $synchronizer): int
    {
        $abandoned = Generation::query()->abandonedBeforeSubmission()->limit(50)->get();

        foreach ($abandoned as $generation) {
            Log::error('Closing a generation whose submission job was lost', ['generation_id' => $generation->id]);

            $synchronizer->failLocally(
                $generation,
                'submission_timeout',
                'The image service was too busy to accept this request. Your reserved credits were returned.',
                confirmed: false,
            );
        }

        return $abandoned->count();
    }

    /**
     * Succeeded generations whose image copy never landed, while the
     * provider URL can still be downloaded.
     */
    private function retryMissingImages(): void
    {
        Generation::query()
            ->whereNull('image_path')
            ->whereNotNull('image_url')
            ->where('image_url_expires_at', '>', now())
            ->where('completed_at', '<', now()->subMinutes(15))
            ->oldest('completed_at')
            ->limit(20)
            ->get(['id'])
            ->each(fn (Generation $generation) => StoreGenerationImage::dispatch($generation->id));
    }
}
