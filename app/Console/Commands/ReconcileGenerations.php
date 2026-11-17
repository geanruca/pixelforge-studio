<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\PixelforgeException;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Services\Generations\GenerationSynchronizer;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The safety net behind webhooks. Delivery is at-least-once but not
 * guaranteed (all six attempts can fail while we are down), so any in-flight
 * generation that has gone quiet is fetched from Pixelforge and brought up to
 * date through the same synchronizer the webhooks use.
 */
#[Signature('generations:reconcile {--limit=20 : Most generations to poll in one run, to stay inside the rate limit}')]
#[Description('Poll Pixelforge for in-flight generations whose webhooks have not arrived')]
class ReconcileGenerations extends Command
{
    public function handle(PixelforgeClient $client, GenerationSynchronizer $synchronizer): int
    {
        $quietSince = now()->subSeconds(config()->integer('pixelforge.reconcile_after_seconds'));

        $generations = Generation::query()
            ->inFlight()
            ->where('last_synced_at', '<', $quietSince)
            ->oldest('last_synced_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($generations as $generation) {
            try {
                $synchronizer->apply($generation, $client->getGeneration((string) $generation->provider_id));
            } catch (PixelforgeException $exception) {
                Log::warning('Could not reconcile a generation', [
                    'generation_id' => $generation->id,
                    'code' => $exception->errorCode,
                ]);

                if ($exception->errorCode === 'not_found') {
                    $synchronizer->failLocally(
                        $generation,
                        'not_found',
                        'The image service has no record of this generation. You were not charged.',
                        confirmed: true,
                    );
                }

                if ($exception->errorCode === 'rate_limit_exceeded') {
                    break;
                }
            }
        }

        $this->retryMissingImages();

        $this->info("Reconciled {$generations->count()} generation(s).");

        return self::SUCCESS;
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
            ->limit(20)
            ->get(['id'])
            ->each(fn (Generation $generation) => StoreGenerationImage::dispatch($generation->id));
    }
}
