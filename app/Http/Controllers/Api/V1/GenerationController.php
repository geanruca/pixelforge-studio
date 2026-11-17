<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Exceptions\ApiExceptionRenderer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListGenerationsRequest;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\Api\V1\GenerationResource;
use App\Services\Generations\GenerationCreator;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public API. It is a second door to exactly what the web app does: the
 * same validation, the same {@see GenerationCreator}, the same holds and
 * charges. Failures are rendered by {@see ApiExceptionRenderer}.
 */
class GenerationController extends Controller
{
    private const int MAX_IDEMPOTENCY_KEY_LENGTH = 255;

    public function index(ListGenerationsRequest $request): JsonResponse
    {
        $page = $this->authenticatedUser($request)->generations()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderByDesc('id')
            ->cursorPaginate($request->integer('limit', ListGenerationsRequest::DEFAULT_LIMIT));

        return response()->json([
            'object' => 'list',
            'data' => GenerationResource::collection($page->items())->resolve($request),
            'has_more' => $page->hasMorePages(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /**
     * Create a generation. With an `Idempotency-Key`, repeating the request
     * returns the generation that already exists (200, `Idempotent-Replayed`)
     * instead of creating and holding credits for a second one.
     */
    public function store(StoreGenerationRequest $request, GenerationCreator $creator): JsonResponse
    {
        $key = $request->header('Idempotency-Key');

        if ($key !== null && ($key === '' || mb_strlen($key) > self::MAX_IDEMPOTENCY_KEY_LENGTH)) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'The Idempotency-Key header must be between 1 and '.self::MAX_IDEMPOTENCY_KEY_LENGTH.' characters.',
            ]);
        }

        [$generation, $replayed] = $creator->createOnce(
            $this->authenticatedUser($request),
            $request->string('prompt')->toString(),
            $request->enum('size', GenerationSize::class) ?? GenerationSize::Square,
            $request->enum('quality', GenerationQuality::class) ?? GenerationQuality::Medium,
            $request->referenceImages(),
            $key,
        );

        return (new GenerationResource($generation))
            ->response($request)
            ->setStatusCode($replayed ? Response::HTTP_OK : Response::HTTP_ACCEPTED)
            ->header('Idempotent-Replayed', $replayed ? 'true' : 'false');
    }

    public function show(Request $request, int $generation): GenerationResource
    {
        return new GenerationResource(
            $this->authenticatedUser($request)->generations()->findOrFail($generation),
        );
    }

    /**
     * What a request would cost, without creating anything.
     */
    public function estimate(StoreGenerationRequest $request, GenerationCreator $creator): JsonResponse
    {
        $price = $creator->estimatePrice(
            mb_strlen($request->string('prompt')->toString()),
            $request->enum('size', GenerationSize::class) ?? GenerationSize::Square,
            $request->enum('quality', GenerationQuality::class) ?? GenerationQuality::Medium,
            count($request->referenceImages()),
        );

        return response()->json([
            'object' => 'estimate',
            'currency' => 'usd',
            'minimum_price_usd' => Money::toDecimal($price->minimum),
            'expected_price_usd' => Money::toDecimal($price->expected),
            'maximum_price_usd' => Money::toDecimal($price->maximum),
        ]);
    }

    public function balance(Request $request): JsonResponse
    {
        return response()->json([
            'object' => 'balance',
            'currency' => 'usd',
            'available_usd' => Money::toDecimal($this->authenticatedUser($request)->balance_nanos),
        ]);
    }
}
