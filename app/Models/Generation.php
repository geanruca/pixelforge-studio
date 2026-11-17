<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One image generation requested by a user. Money is integer nanodollars.
 *
 * @property int $id
 * @property int $user_id
 * @property string $idempotency_key Sent to Pixelforge so a retried submission never creates a second generation.
 * @property string|null $client_idempotency_key The `Idempotency-Key` an API client sent, unique per user.
 * @property string|null $provider_id Pixelforge's `gen_…` id, null until the submission is accepted.
 * @property GenerationStatus $status
 * @property bool $outcome_confirmed True once Pixelforge itself confirmed the final state; such a state never changes.
 * @property string|null $title A name the user may give the image, asked for while it generates.
 * @property string $prompt
 * @property GenerationSize $size
 * @property GenerationQuality $quality
 * @property list<string> $reference_images
 * @property int $estimated_price_nanos Expected price shown to the user before submitting.
 * @property int $reserved_nanos Worst-case price held on the balance until the generation is final.
 * @property int|null $provider_cost_nanos What Pixelforge billed us, computed from usage × rates.
 * @property int|null $price_nanos What the user was charged: provider cost plus markup.
 * @property array<string, int>|null $usage
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $image_url Pixelforge's signed URL; expires 24 hours after completion.
 * @property Carbon|null $image_url_expires_at
 * @property string|null $image_path Our own copy of the image, which does not expire.
 * @property int $submission_failures Attempts Pixelforge refused or never answered (overload, rate limit, timeout).
 * @property Carbon|null $slot_claimed_at When a worker took a provider slot for it, before Pixelforge accepted it.
 * @property Carbon|null $submitted_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at Pixelforge's completion time: the UTC day it bills the generation on.
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id', 'idempotency_key', 'provider_id', 'status', 'outcome_confirmed', 'prompt', 'size', 'quality',
    'reference_images', 'estimated_price_nanos', 'reserved_nanos', 'provider_cost_nanos', 'price_nanos', 'usage',
    'error_code', 'error_message', 'image_url', 'image_url_expires_at', 'image_path', 'submitted_at', 'started_at',
    'completed_at', 'last_synced_at', 'submission_failures', 'client_idempotency_key', 'title', 'slot_claimed_at',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'submission_failures' => 0,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * Generations Pixelforge accepted and has not finished: the ones that
     * occupy one of the account's concurrency slots.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInFlight(Builder $query): void
    {
        $query->whereNotNull('provider_id')->whereIn('status', GenerationStatus::active());
    }

    /**
     * Generations Pixelforge knows about whose final state it has not yet
     * confirmed to us: everything in flight, plus any we gave up on locally
     * that turned out to have been accepted. These are what polling must keep
     * asking about.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAwaitingProviderOutcome(Builder $query): void
    {
        $query->whereNotNull('provider_id')->where('outcome_confirmed', false);
    }

    /**
     * Generations Pixelforge has not accepted and that no longer have a
     * submission job that could still be trying.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAbandonedBeforeSubmission(Builder $query): void
    {
        $query->whereNull('provider_id')
            ->where('status', GenerationStatus::Queued)
            ->where('created_at', '<', now()->subMinutes(config()->integer('pixelforge.submission_window_minutes') + 5));
    }

    /**
     * Generations waiting for Pixelforge to accept them, oldest first: the
     * line a new generation joins.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAwaitingSubmission(Builder $query): void
    {
        $query->whereNull('provider_id')->where('status', GenerationStatus::Queued);
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    /**
     * Whether the provider's signed URL can still be loaded.
     */
    public function hasLiveProviderImage(): bool
    {
        return $this->image_url !== null
            && $this->image_url_expires_at !== null
            && $this->image_url_expires_at->isFuture();
    }

    /**
     * Why a failed generation failed, in words meant for the user. Every
     * failure costs nothing, and says so.
     */
    public function failureMessage(): string
    {
        return match ($this->error_code) {
            'content_policy_violation' => 'This prompt was rejected by the content policy. You were not charged.',
            'generation_failed' => 'The model could not produce an image for this prompt. You were not charged — try rewording it.',
            'internal_error' => 'The image service hit an internal error. You were not charged — trying again usually works.',
            'daily_limit_exceeded' => 'The daily generation limit has been reached. You were not charged — please try again tomorrow (UTC).',
            'validation_failed' => 'The image service rejected this request as invalid. You were not charged.',
            'submission_timeout' => 'The image service was too busy to accept this request. Your reserved credits were returned — please try again.',
            'invalid_api_key' => 'Image generation is temporarily unavailable. You were not charged.',
            default => 'This generation failed. You were not charged.',
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => GenerationSize::class,
            'quality' => GenerationQuality::class,
            'outcome_confirmed' => 'boolean',
            'reference_images' => 'array',
            'usage' => 'array',
            'estimated_price_nanos' => 'integer',
            'reserved_nanos' => 'integer',
            'provider_cost_nanos' => 'integer',
            'price_nanos' => 'integer',
            'image_url_expires_at' => 'datetime',
            'slot_claimed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'submission_failures' => 'integer',
        ];
    }
}
