<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PixelforgeException;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\Api\V1\GenerationResource;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Base of the MCP tools: thin wrappers over the services the web app and the
 * API use, shaped with the API's own resource so every door returns the same
 * data. Expected domain outcomes become tool errors an agent can act on.
 */
abstract class StudioTool extends Tool
{
    abstract protected function execute(Request $request, User $user): Response|ResponseFactory;

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('Not authenticated. Connect with a Pixelforge Studio API token.');
        }

        try {
            return $this->execute($request, $user);
        } catch (ModelNotFoundException) {
            return Response::error('That generation does not exist or belongs to another account.');
        } catch (InsufficientCreditsException $exception) {
            return Response::error(sprintf(
                'Not enough credits: this generation could cost up to $%s and $%s is available. Nothing was created. The user can buy credits in the app.',
                Money::toDecimal($exception->requiredNanos),
                Money::toDecimal($exception->availableNanos),
            ));
        } catch (PixelforgeException) {
            return Response::error('Pricing is temporarily unavailable, so nothing was created or charged. Try again shortly.');
        }
    }

    /**
     * A readable summary for the agent plus the same data as structured content.
     *
     * @param  array<string, mixed>  $data
     */
    protected function respond(string $text, array $data): ResponseFactory
    {
        return Response::make(Response::text($text))->withStructuredContent($data);
    }

    /**
     * @return array<string, mixed>
     */
    protected function generationData(Generation $generation): array
    {
        return (new GenerationResource($generation))->resolve(request());
    }

    protected function generationText(Generation $generation): string
    {
        $imageUrl = $this->generationData($generation)['image_url'] ?? null;

        return match ($generation->status) {
            GenerationStatus::Succeeded => sprintf(
                'Generation %d succeeded. Charged $%s. Image: %s',
                $generation->id,
                Money::toDecimal($generation->price_nanos ?? 0),
                is_string($imageUrl) ? $imageUrl : 'no longer available',
            ),
            GenerationStatus::Failed => "Generation {$generation->id} failed and was not charged: {$generation->failureMessage()}",
            default => sprintf(
                'Generation %d is %s. Up to $%s is reserved; estimated $%s.',
                $generation->id,
                $generation->status->value,
                Money::toDecimal($generation->reserved_nanos),
                Money::toDecimal($generation->estimated_price_nanos),
            ),
        };
    }

    /**
     * The inputs of a generation, shared by estimate_price and generate_image.
     *
     * @return array<string, Type>
     */
    protected function generationSchema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()->description('What the image should show. Up to 4,000 characters.')->required(),
            'size' => $schema->string()->enum(GenerationSize::class)->description('Image size in pixels.')->required(),
            'quality' => $schema->string()->enum(GenerationQuality::class)->description('Higher quality costs more and its final price varies more.')->required(),
            'reference_images' => $schema->array()->items($schema->string())->description('Optional http(s) URLs of reference images, at most 4. Each adds a fixed amount to the price.'),
        ];
    }

    /**
     * The same rules the web form and the API apply.
     *
     * @return array<string, mixed>
     */
    protected function generationRules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:'.StoreGenerationRequest::MAX_PROMPT_LENGTH],
            'size' => ['required', Rule::enum(GenerationSize::class)],
            'quality' => ['required', Rule::enum(GenerationQuality::class)],
            'reference_images' => ['array', 'max:'.StoreGenerationRequest::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * @return list<string>
     */
    protected function referenceImages(Request $request): array
    {
        $references = $request->get('reference_images', []);

        return is_array($references) ? array_values(array_filter($references, is_string(...))) : [];
    }
}
