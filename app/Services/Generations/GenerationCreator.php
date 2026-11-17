<?php

declare(strict_types=1);

namespace App\Services\Generations;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PixelforgeException;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Services\Pricing\CostEstimate;
use App\Services\Pricing\Markup;
use App\Services\Pricing\PricingRepository;
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
    public function create(User $user, string $prompt, GenerationSize $size, GenerationQuality $quality, array $referenceImages): Generation
    {
        $price = $this->estimatePrice(mb_strlen($prompt), $size, $quality, count($referenceImages));

        return DB::transaction(function () use ($user, $prompt, $size, $quality, $referenceImages, $price): Generation {
            $generation = $user->generations()->create([
                'idempotency_key' => (string) Str::uuid(),
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
}
