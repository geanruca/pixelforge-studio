<?php

declare(strict_types=1);

namespace App\Services\Generations;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Exceptions\IdempotencyKeyReusedException;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PixelforgeException;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Services\Pricing\CostEstimate;
use App\Services\Pricing\Markup;
use App\Services\Pricing\PricingRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerationCreator
{
    public function __construct(
        private readonly PricingRepository $pricing,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * What the user will pay for a request: low, expected and worst case.
     *
     * @throws PixelforgeException when pricing is unavailable
     */
    public function estimatePrice(int $promptCharacters, GenerationSize $size, GenerationQuality $quality, int $referenceImages): CostEstimate
    {
        return $this->pricing->current()
            ->estimate($promptCharacters, $size, $quality, $referenceImages)
            ->withMarkup(Markup::fromConfig());
    }

    /**
     * Record the generation, reserve its worst-case price and queue its
     * submission — all or nothing. The submission is dispatched only after
     * the transaction commits, so the worker can never run before the row and
     * its hold exist.
     *
     * @param  list<string>  $referenceImages
     *
     * @throws InsufficientCreditsException
     * @throws PixelforgeException when pricing is unavailable
     */
    public function create(
        User $user,
        string $prompt,
        GenerationSize $size,
        GenerationQuality $quality,
        array $referenceImages,
        ?string $clientIdempotencyKey = null,
    ): Generation {
        $price = $this->estimatePrice(mb_strlen($prompt), $size, $quality, count($referenceImages));

        return DB::transaction(function () use ($user, $prompt, $size, $quality, $referenceImages, $price, $clientIdempotencyKey): Generation {
            $generation = $user->generations()->create([
                'idempotency_key' => (string) Str::uuid(),
                'client_idempotency_key' => $clientIdempotencyKey,
                'status' => GenerationStatus::Queued,
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $referenceImages,
                'estimated_price_nanos' => $price->expected,
                'reserved_nanos' => $price->maximum,
            ]);

            $this->ledger->hold($generation->setRelation('user', $user));

            SubmitGeneration::dispatch($generation->id)->afterCommit();

            return $generation;
        });
    }

    /**
     * Create a generation at most once per client idempotency key (API
     * clients retry on timeouts, exactly as we do against Pixelforge).
     * Returns the generation and whether it already existed.
     *
     * Two concurrent requests with the same key race on the unique index:
     * the loser's transaction rolls back before any credits are held, and it
     * returns the winner's generation.
     *
     * @param  list<string>  $referenceImages
     * @return array{Generation, bool}
     *
     * @throws IdempotencyKeyReusedException when the key was used with different parameters
     * @throws InsufficientCreditsException
     * @throws PixelforgeException when pricing is unavailable
     */
    public function createOnce(
        User $user,
        string $prompt,
        GenerationSize $size,
        GenerationQuality $quality,
        array $referenceImages,
        ?string $clientIdempotencyKey,
    ): array {
        if ($clientIdempotencyKey === null) {
            return [$this->create($user, $prompt, $size, $quality, $referenceImages), false];
        }

        $existing = fn (): ?Generation => $user->generations()->where('client_idempotency_key', $clientIdempotencyKey)->first();

        try {
            $generation = $existing() ?? $this->create($user, $prompt, $size, $quality, $referenceImages, $clientIdempotencyKey);
        } catch (UniqueConstraintViolationException) {
            $generation = $existing() ?? throw new IdempotencyKeyReusedException;
        }

        if ($generation->wasRecentlyCreated) {
            return [$generation, false];
        }

        $sameRequest = $generation->prompt === $prompt
            && $generation->size === $size
            && $generation->quality === $quality
            && $generation->reference_images === $referenceImages;

        return $sameRequest ? [$generation, true] : throw new IdempotencyKeyReusedException;
    }
}
