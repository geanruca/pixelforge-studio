<?php

declare(strict_types=1);

namespace App\Services\Credits;

use App\Enums\LedgerEntryType;
use App\Exceptions\InsufficientCreditsException;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The single door to a user's credit balance (`users.balance_nanos`).
 *
 * Every movement runs in one database transaction that locks the user row
 * (`SELECT … FOR UPDATE`), reads the fresh balance, appends a
 * {@see LedgerEntry} and updates the balance. Concurrent movements are
 * therefore serialized and the sum of a user's entries always equals the
 * balance.
 *
 * A generation moves money in two steps. Submitting it places a HOLD for the
 * worst-case price, so the user can never spend credits they do not have even
 * though the real price is unknown until completion. Reaching a final state
 * releases the hold and, only if it succeeded, charges the actual price.
 * Each step is idempotent: unique indexes on `(generation_id, type)` make a
 * second hold, release or charge of the same generation impossible.
 */
class CreditLedger
{
    /**
     * Add purchased credits. Idempotent per reference: repeating a purchase
     * (double click, retried request) credits it once and returns null after.
     */
    public function purchase(User $user, int $amountNanos, string $reference): ?LedgerEntry
    {
        return $this->transact($user, function (User $locked) use ($amountNanos, $reference): ?LedgerEntry {
            $alreadyCredited = $locked->ledgerEntries()
                ->where('type', LedgerEntryType::Purchase)
                ->where('reference', $reference)
                ->exists();

            if ($alreadyCredited || $amountNanos <= 0) {
                return null;
            }

            return $this->append($locked, LedgerEntryType::Purchase, $amountNanos, reference: $reference);
        });
    }

    /**
     * Set aside the generation's worst-case price.
     *
     * @throws InsufficientCreditsException when the balance does not cover it
     */
    public function hold(Generation $generation): LedgerEntry
    {
        return $this->transact($generation->user, function (User $locked) use ($generation): LedgerEntry {
            if ($locked->balance_nanos < $generation->reserved_nanos) {
                throw new InsufficientCreditsException($generation->reserved_nanos, $locked->balance_nanos);
            }

            return $this->append($locked, LedgerEntryType::Hold, -$generation->reserved_nanos, $generation);
        });
    }

    /**
     * Give the hold back because the generation failed: failed generations cost nothing.
     */
    public function release(Generation $generation): void
    {
        $this->transact($generation->user, function (User $locked) use ($generation): null {
            $this->releaseHold($locked, $generation);

            return null;
        });
    }

    /**
     * Close a succeeded generation: give the hold back and charge the actual
     * price, atomically. Returns false when it had already been charged.
     *
     * The charge is not capped by the balance. The hold covers the worst case,
     * so the balance cannot normally go negative; in the one corner where the
     * hold was already released (we gave up on a submission that Pixelforge
     * turned out to have accepted), the image was still delivered and billed
     * to us, so the truthful record is the full charge.
     */
    public function settle(Generation $generation, int $priceNanos): bool
    {
        return $this->transact($generation->user, function (User $locked) use ($generation, $priceNanos): bool {
            if ($this->has($generation, LedgerEntryType::Charge)) {
                return false;
            }

            $this->releaseHold($locked, $generation);
            $this->append($locked, LedgerEntryType::Charge, -$priceNanos, $generation);

            return true;
        });
    }

    private function releaseHold(User $locked, Generation $generation): void
    {
        $hold = $generation->ledgerEntries()->where('type', LedgerEntryType::Hold)->first();

        if ($hold === null || $this->has($generation, LedgerEntryType::HoldRelease)) {
            return;
        }

        $this->append($locked, LedgerEntryType::HoldRelease, -$hold->amount_nanos, $generation);
    }

    private function has(Generation $generation, LedgerEntryType $type): bool
    {
        return $generation->ledgerEntries()->where('type', $type)->exists();
    }

    private function append(
        User $locked,
        LedgerEntryType $type,
        int $amountNanos,
        ?Generation $generation = null,
        ?string $reference = null,
    ): LedgerEntry {
        $balance = $locked->balance_nanos + $amountNanos;

        $locked->forceFill(['balance_nanos' => $balance])->save();

        return LedgerEntry::query()->create([
            'user_id' => $locked->id,
            'generation_id' => $generation?->id,
            'type' => $type,
            'amount_nanos' => $amountNanos,
            'balance_after_nanos' => $balance,
            'reference' => $reference,
            'created_at' => now(),
        ]);
    }

    /**
     * Run a movement with the user row locked, then mirror the new balance
     * onto the caller's instance.
     *
     * @template TResult
     *
     * @param  Closure(User): TResult  $movement
     * @return TResult
     */
    private function transact(User $user, Closure $movement): mixed
    {
        [$result, $balance] = DB::transaction(function () use ($user, $movement): array {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            return [$movement($locked), $locked->balance_nanos];
        });

        $user->setAttribute('balance_nanos', $balance);
        $user->syncOriginalAttribute('balance_nanos');

        return $result;
    }
}
