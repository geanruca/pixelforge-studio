<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LedgerEntryType;
use App\Services\Credits\CreditLedger;
use Database\Factories\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One movement of a user's credit balance. Append-only and written only by
 * {@see CreditLedger}, in the same transaction that updates the balance, so
 * the sum of a user's entries always equals `users.balance_nanos`.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $generation_id
 * @property LedgerEntryType $type
 * @property int $amount_nanos Signed: negative takes credits away.
 * @property int $balance_after_nanos
 * @property string|null $reference Idempotency reference of a purchase.
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read Generation|null $generation
 */
#[Fillable(['user_id', 'generation_id', 'type', 'amount_nanos', 'balance_after_nanos', 'reference', 'created_at'])]
class LedgerEntry extends Model
{
    /** @use HasFactory<LedgerEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount_nanos' => 'integer',
            'balance_after_nanos' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
