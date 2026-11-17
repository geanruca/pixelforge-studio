<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use App\Enums\GenerationStatus;
use App\Services\Pricing\Rates;
use App\Services\Pricing\TokenUsage;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A generation exactly as Pixelforge described it at one moment: in the
 * response to a create or a retrieve, or inside a webhook event. All three
 * carry the same object, so they share this snapshot and one way to apply it.
 */
final readonly class ProviderGeneration
{
    /** Metadata key that carries our own generation id through Pixelforge and back. */
    public const string LOCAL_ID_METADATA_KEY = 'generation_id';

    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public ?int $localId,
        public ?TokenUsage $usage,
        public ?Rates $rates,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
    ) {}

    /**
     * @param  array<array-key, mixed>  $generation
     *
     * @throws InvalidArgumentException when the object is not a usable generation
     */
    public static function fromApi(array $generation): self
    {
        $id = $generation['id'] ?? null;
        $status = is_string($generation['status'] ?? null) ? GenerationStatus::tryFrom($generation['status']) : null;

        if (! is_string($id) || $id === '' || $status === null) {
            throw new InvalidArgumentException('The generation object has no id or a status we do not know.');
        }

        $usage = is_array($generation['usage'] ?? null) ? TokenUsage::fromApi($generation['usage']) : null;
        $rates = is_array($generation['rates'] ?? null) ? Rates::fromApi($generation['rates']) : null;

        if ($status === GenerationStatus::Succeeded && ($usage === null || $rates === null)) {
            throw new InvalidArgumentException("Succeeded generation [{$id}] has no usage or rates to bill from.");
        }

        $error = is_array($generation['error'] ?? null) ? $generation['error'] : [];
        $localId = is_array($generation['metadata'] ?? null) ? ($generation['metadata'][self::LOCAL_ID_METADATA_KEY] ?? null) : null;

        return new self(
            id: $id,
            status: $status,
            localId: is_string($localId) && ctype_digit($localId) ? (int) $localId : null,
            usage: $usage,
            rates: $rates,
            errorCode: is_string($error['code'] ?? null) ? $error['code'] : null,
            errorMessage: is_string($error['message'] ?? null) ? $error['message'] : null,
            imageUrl: is_string($generation['image_url'] ?? null) ? $generation['image_url'] : null,
            imageUrlExpiresAt: self::timestamp($generation['image_url_expires_at'] ?? null),
            startedAt: self::timestamp($generation['started_at'] ?? null),
            completedAt: self::timestamp($generation['completed_at'] ?? null),
        );
    }

    private static function timestamp(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
    }
}
