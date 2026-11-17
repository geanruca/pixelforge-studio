<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hasImage = $this->status === GenerationStatus::Succeeded
            && ($this->image_path !== null || $this->hasLiveProviderImage());

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'awaiting_slot' => $this->status === GenerationStatus::Queued && $this->provider_id === null,
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_images' => $this->reference_images,
            'estimated_price_nanos' => $this->estimated_price_nanos,
            'reserved_nanos' => $this->reserved_nanos,
            'price_nanos' => $this->price_nanos,
            'error' => $this->status === GenerationStatus::Failed ? $this->failureMessage() : null,
            'image_url' => $hasImage ? route('generations.image', $this->id) : null,
            'image_expired' => $this->status === GenerationStatus::Succeeded && ! $hasImage,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
