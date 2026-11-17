<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PixelforgeException;
use App\Http\Requests\EstimateGenerationRequest;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Requests\UpdateGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Services\Generations\GenerationCreator;
use App\Services\Generations\SlotAllocator;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class GenerationController extends Controller
{
    private const int PER_PAGE = 12;

    public function create(Request $request): Response
    {
        $source = $request->integer('from') > 0
            ? $this->authenticatedUser($request)->generations()->find($request->integer('from'))
            : null;

        return Inertia::render('generations/Create', [
            'sizes' => array_map(
                fn (GenerationSize $size): array => ['value' => $size->value, 'label' => $size->label()],
                GenerationSize::cases(),
            ),
            'qualities' => array_map(
                fn (GenerationQuality $quality): array => ['value' => $quality->value, 'label' => $quality->label()],
                GenerationQuality::cases(),
            ),
            'limits' => [
                'prompt' => StoreGenerationRequest::MAX_PROMPT_LENGTH,
                'reference_images' => StoreGenerationRequest::MAX_REFERENCE_IMAGES,
            ],
            'creditPacks' => config()->array('pixelforge.credit_packs_usd'),
            'prefill' => $source === null ? null : [
                'prompt' => $source->prompt,
                'size' => $source->size->value,
                'quality' => $source->quality->value,
                'reference_images' => $source->reference_images,
            ],
        ]);
    }

    public function estimate(EstimateGenerationRequest $request, GenerationCreator $creator): JsonResponse
    {
        try {
            $price = $creator->estimatePrice(
                $request->integer('characters'),
                $request->enum('size', GenerationSize::class) ?? GenerationSize::Square,
                $request->enum('quality', GenerationQuality::class) ?? GenerationQuality::Medium,
                $request->integer('reference_images'),
            );
        } catch (PixelforgeException) {
            return response()->json(['message' => 'Pricing is temporarily unavailable.'], HttpResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json(['price' => $price->toArray()]);
    }

    public function store(StoreGenerationRequest $request, GenerationCreator $creator): RedirectResponse
    {
        try {
            $generation = $creator->create(
                $this->authenticatedUser($request),
                $request->string('prompt')->toString(),
                $request->enum('size', GenerationSize::class) ?? GenerationSize::Square,
                $request->enum('quality', GenerationQuality::class) ?? GenerationQuality::Medium,
                $request->referenceImages(),
            );
        } catch (InsufficientCreditsException $exception) {
            throw ValidationException::withMessages([
                'credits' => sprintf(
                    'This generation could cost up to $%s but you have $%s available. Buy credits to continue.',
                    $this->dollars($exception->requiredNanos),
                    $this->dollars($exception->availableNanos),
                ),
            ]);
        } catch (PixelforgeException) {
            throw ValidationException::withMessages([
                'credits' => 'Pricing is temporarily unavailable, so nothing was submitted or charged. Please try again in a moment.',
            ]);
        }

        return to_route('generations.index')->with('created_generation', $generation->id);
    }

    public function index(Request $request, SlotAllocator $slots): Response
    {
        $generations = $this->authenticatedUser($request)->generations()->latest('id')->paginate(self::PER_PAGE);

        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($generations),
            // Where each waiting generation stands in the line for a provider slot.
            'queuePositions' => (object) $slots->positions(),
            'justCreated' => $request->session()->get('created_generation'),
            'hasActive' => $this->authenticatedUser($request)->generations()->whereIn('status', GenerationStatus::active())->exists(),
        ]);
    }

    /**
     * The one thing a user can change about a generation: its name.
     */
    public function update(UpdateGenerationRequest $request, Generation $generation): RedirectResponse
    {
        $title = $request->string('title')->trim()->toString();

        $generation->update(['title' => $title === '' ? null : $title]);

        return back();
    }

    /**
     * Serves the image from our own copy, or sends the browser to
     * Pixelforge's signed URL while that still works and the copy is pending.
     */
    public function image(Generation $generation): HttpResponse
    {
        return $this->serveImage($generation);
    }

    /**
     * The same image behind a signed, expiring link instead of a session:
     * what the API and the MCP server hand out as `image_url`.
     */
    public function signedImage(Generation $generation): HttpResponse
    {
        return $this->serveImage($generation);
    }

    private function serveImage(Generation $generation): HttpResponse
    {
        if ($generation->image_path !== null) {
            return Storage::disk(config()->string('pixelforge.image_disk'))->response(
                $generation->image_path,
                headers: ['Cache-Control' => 'private, max-age=86400'],
            );
        }

        abort_unless($generation->hasLiveProviderImage(), HttpResponse::HTTP_NOT_FOUND);

        return redirect()->away((string) $generation->image_url);
    }

    /**
     * Whole cents are not enough here: round up to four decimals so the
     * amount shown as required is never less than what is actually needed.
     */
    private function dollars(int $nanos): string
    {
        return number_format(ceil($nanos / (Money::NANOS_PER_DOLLAR / 10_000)) / 10_000, 4);
    }
}
