<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WebhookOutcome;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A Pixelforge webhook event we have processed. The unique `event_id` is what
 * makes a duplicate delivery a no-op.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $provider_generation_id
 * @property WebhookOutcome $outcome
 * @property int|null $delivery_attempt
 * @property array<string, mixed> $payload
 * @property Carbon $created_at
 */
#[Fillable(['event_id', 'type', 'provider_generation_id', 'outcome', 'delivery_attempt', 'payload', 'created_at'])]
class WebhookEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => WebhookOutcome::class,
            'payload' => 'array',
            'delivery_attempt' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
