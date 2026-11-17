<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * A generation as the public API and the MCP server expose it. Money is an
 * exact decimal string in USD, never a float.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /** Minutes a signed image link stays valid. */
    private const int IMAGE_LINK_MINUTES = 60;

    /**
     * No "data" envelope: the object is the response, like Pixelforge's own API.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $failed = $this->status === GenerationStatus::Failed;
        $hasImage = $this->status === GenerationStatus::Succeeded
            && ($this->image_path !== null || $this->hasLiveProviderImage());

        return [
            'id' => $this->id,
            'object' => 'generation',
            'status' => $this->status->value,
            'title' => $this->title,
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_images' => $this->reference_images,
            'estimated_price_usd' => Money::toDecimal($this->estimated_price_nanos),
            'maximum_price_usd' => Money::toDecimal($this->reserved_nanos),
            'price_usd' => $this->price_nanos === null ? null : Money::toDecimal($this->price_nanos),
            'error' => $failed ? ['code' => $this->error_code, 'message' => $this->failureMessage()] : null,
            'image_url' => $hasImage
                ? URL::temporarySignedRoute('generations.image.signed', now()->addMinutes(self::IMAGE_LINK_MINUTES), ['generation' => $this->id])
                : null,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
        ];
    }
}
