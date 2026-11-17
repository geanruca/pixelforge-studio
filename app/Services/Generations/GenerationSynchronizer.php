<?php

declare(strict_types=1);

namespace App\Services\Generations;

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Services\Credits\CreditLedger;
use App\Services\Pixelforge\ProviderGeneration;
use App\Services\Pricing\Markup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Brings a local generation up to date with what Pixelforge says about it.
 *
 * This is the ONE place a generation changes state or money moves because of
 * the provider. Create responses, webhooks and polling all hand their snapshot
 * to {@see apply()}, so they cannot disagree and the order they arrive in does
 * not matter:
 *
 *  - the generation row is locked, so two snapshots are applied one at a time;
 *  - a snapshot only applies when it moves the status FORWARD
 *    (queued → processing → succeeded | failed), so a late `processing` after
 *    `succeeded`, or any duplicate, is a no-op;
 *  - a final state confirmed by Pixelforge is never changed again;
 *  - money moves in the same transaction as the status, through the ledger,
 *    whose unique indexes make a second charge impossible.
 */
class GenerationSynchronizer
{
    public function __construct(private readonly CreditLedger $ledger) {}

    public function apply(Generation $generation, ProviderGeneration $snapshot): SyncOutcome
    {
        $outcome = DB::transaction(function () use ($generation, $snapshot): SyncOutcome {
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($locked->provider_id !== null && $locked->provider_id !== $snapshot->id) {
                throw new LogicException("Generation {$locked->id} belongs to {$locked->provider_id}, not {$snapshot->id}.");
            }

            $locked->fill([
                'provider_id' => $snapshot->id,
                'submitted_at' => $locked->submitted_at ?? now(),
                'last_synced_at' => now(),
            ]);

            if (! $this->movesForward($locked, $snapshot)) {
                $locked->save();

                return SyncOutcome::Stale;
            }

            $locked->fill([
                'status' => $snapshot->status,
                'started_at' => $snapshot->startedAt ?? $locked->started_at,
            ]);

            match ($snapshot->status) {
                GenerationStatus::Succeeded => $this->succeed($locked, $snapshot),
                GenerationStatus::Failed => $this->fail($locked, $snapshot),
                default => null,
            };

            $locked->save();

            return SyncOutcome::Applied;
        });

        $generation->refresh();

        if ($outcome === SyncOutcome::Applied && $generation->status === GenerationStatus::Succeeded) {
            StoreGenerationImage::dispatch($generation->id)->afterCommit();
        }

        return $outcome;
    }

    /**
     * Close a generation that Pixelforge never accepted. `$confirmed` tells
     * whether Pixelforge itself rejected it (nothing exists, final for good)
     * or we merely gave up waiting (it may still surface later, in which case
     * the provider's word wins — see {@see movesForward()}).
     */
    public function failLocally(Generation $generation, string $errorCode, string $message, bool $confirmed): void
    {
        DB::transaction(function () use ($generation, $errorCode, $message, $confirmed): void {
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($locked->isFinal()) {
                return;
            }

            $locked->fill([
                'status' => GenerationStatus::Failed,
                'outcome_confirmed' => $confirmed,
                'error_code' => $errorCode,
                'error_message' => $message,
                'completed_at' => now(),
            ])->save();

            $this->ledger->release($locked);
        });

        $generation->refresh();
    }

    private function movesForward(Generation $generation, ProviderGeneration $snapshot): bool
    {
        if ($generation->isFinal()) {
            // Only a failure we declared ourselves can be corrected, and only
            // by a final state from Pixelforge.
            return ! $generation->outcome_confirmed && $snapshot->status->isFinal();
        }

        return $snapshot->status->rank() > $generation->status->rank();
    }

    private function succeed(Generation $generation, ProviderGeneration $snapshot): void
    {
        if ($snapshot->usage === null || $snapshot->rates === null) {
            throw new LogicException("Succeeded generation {$snapshot->id} cannot be billed without usage and rates.");
        }

        $providerCost = $snapshot->rates->costOf($snapshot->usage);
        $price = Markup::fromConfig()->priceFor($providerCost);

        $generation->fill([
            'outcome_confirmed' => true,
            'provider_cost_nanos' => $providerCost,
            'price_nanos' => $price,
            'usage' => $snapshot->usage->toArray(),
            'error_code' => null,
            'error_message' => null,
            'image_url' => $snapshot->imageUrl,
            'image_url_expires_at' => $snapshot->imageUrlExpiresAt,
            'completed_at' => $snapshot->completedAt ?? now(),
        ]);

        if ($price > $generation->reserved_nanos) {
            Log::warning('Generation cost more than the amount reserved for it', [
                'generation_id' => $generation->id,
                'reserved_nanos' => $generation->reserved_nanos,
                'price_nanos' => $price,
            ]);
        }

        $this->ledger->settle($generation, $price);
    }

    private function fail(Generation $generation, ProviderGeneration $snapshot): void
    {
        $generation->fill([
            'outcome_confirmed' => true,
            'error_code' => $snapshot->errorCode ?? 'generation_failed',
            'error_message' => $snapshot->errorMessage,
            'completed_at' => $snapshot->completedAt ?? now(),
        ]);

        $this->ledger->release($generation);
    }
}
