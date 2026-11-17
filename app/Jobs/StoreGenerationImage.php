<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Copies a finished image to our own storage. Pixelforge's signed URL stops
 * working 24 hours after completion; our copy keeps the history page working.
 */
class StoreGenerationImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    /** Downloads can be slow; on their own queue they never delay a submission. */
    public const string QUEUE = 'images';

    public function __construct(public readonly int $generationId)
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 120, 600];
    }

    public function handle(): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null || $generation->image_path !== null || ! $generation->hasLiveProviderImage()) {
            return;
        }

        $response = Http::connectTimeout(5)->timeout(30)->get((string) $generation->image_url)->throw();

        $path = "generations/{$generation->user_id}/{$generation->id}.png";

        Storage::disk(config()->string('pixelforge.image_disk'))->put($path, $response->body());

        $generation->update(['image_path' => $path]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Generation image could not be stored', [
            'generation_id' => $this->generationId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
