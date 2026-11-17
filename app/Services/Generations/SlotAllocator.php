<?php

declare(strict_types=1);

namespace App\Services\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Decides who gets the provider's few concurrency slots.
 *
 * Pixelforge runs three generations at a time for the whole account, so the
 * slots are the scarce resource every user shares. First-come-first-served
 * would let one user who submits twenty images make everyone else wait behind
 * all twenty. Instead the line is ordered ROUND-ROBIN BY USER: everybody's
 * first waiting image goes before anybody's second, and a user who already
 * has images running counts as having had those turns.
 *
 * Several queue workers ask at once, so checking for a free slot and taking
 * it happen under one lock: two workers can never both take the last slot
 * (which would only earn a 429 from Pixelforge and waste a request).
 */
class SlotAllocator
{
    /** Seconds a claimed slot is held for a submission that never reports back (a crashed worker). */
    private const int CLAIM_SECONDS = 90;

    private const string LOCK = 'pixelforge.slots';

    /** Waiting generations considered when ordering the line. */
    private const int LINE_LIMIT = 500;

    /**
     * Take a slot for the generation if it is its turn. Returns false when
     * every slot is busy or other users' generations go first.
     */
    public function claim(Generation $generation): bool
    {
        try {
            return (bool) $this->locks()->lock(self::LOCK, 10)->block(5, function () use ($generation): bool {
                // A job retried right after taking a slot still holds it.
                if ($generation->slot_claimed_at?->greaterThanOrEqualTo($this->claimExpiry()) === true) {
                    return true;
                }

                $free = config()->integer('pixelforge.max_concurrent_generations') - $this->occupied()->count();

                if ($free < 1 || ! in_array($generation->id, array_slice($this->line(), 0, $free), true)) {
                    return false;
                }

                $generation->update(['slot_claimed_at' => now()]);

                return true;
            });
        } catch (LockTimeoutException) {
            return false;
        }
    }

    /**
     * Give the slot back after a submission that Pixelforge did not accept.
     */
    public function release(Generation $generation): void
    {
        $generation->update(['slot_claimed_at' => null]);
    }

    /**
     * How many of the provider's slots are taken right now.
     */
    public function inUse(): int
    {
        return $this->occupied()->count();
    }

    /**
     * The ids of the generations waiting for a slot, in the order they will
     * get one.
     *
     * @return list<int>
     */
    public function line(): array
    {
        /** @var array<int, int> $turnsTaken user id → slots the user is using right now */
        $turnsTaken = $this->occupied()->get(['user_id'])->countBy('user_id')->all();

        $waiting = Generation::query()
            ->awaitingSubmission()
            ->where(fn ($query) => $query->whereNull('slot_claimed_at')->orWhere('slot_claimed_at', '<', $this->claimExpiry()))
            ->orderBy('id')
            ->limit(self::LINE_LIMIT)
            ->get(['id', 'user_id']);

        $line = [];

        foreach ($waiting as $generation) {
            $turn = $turnsTaken[$generation->user_id] = ($turnsTaken[$generation->user_id] ?? 0) + 1;
            $line[] = ['id' => $generation->id, 'turn' => $turn];
        }

        // Earlier turn first; within the same turn, whoever asked first.
        usort($line, fn (array $a, array $b): int => [$a['turn'], $a['id']] <=> [$b['turn'], $b['id']]);

        return array_column($line, 'id');
    }

    /**
     * Where each waiting generation stands in the line, starting at 1.
     *
     * @return array<int, int>
     */
    public function positions(): array
    {
        return array_map(fn (int $index): int => $index + 1, array_flip($this->line()));
    }

    /**
     * Generations holding a slot: accepted by Pixelforge and not finished,
     * or claimed by a worker that is submitting them right now.
     *
     * @return Builder<Generation>
     */
    private function occupied(): Builder
    {
        return Generation::query()
            ->whereIn('status', GenerationStatus::active())
            ->where(fn ($query) => $query
                ->whereNotNull('provider_id')
                ->orWhere('slot_claimed_at', '>=', $this->claimExpiry()));
    }

    private function claimExpiry(): CarbonInterface
    {
        return now()->subSeconds(self::CLAIM_SECONDS);
    }

    private function locks(): LockProvider
    {
        $store = Cache::store()->getStore();

        if (! $store instanceof LockProvider) {
            throw new \LogicException('The cache store must support atomic locks to allocate provider slots.');
        }

        return $store;
    }
}
