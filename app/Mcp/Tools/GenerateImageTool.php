<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Models\User;
use App\Services\Generations\GenerationCreator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('generate_image')]
#[Title('Generate an image')]
#[Description('Starts an image generation and reserves its maximum price from the user\'s credits. Returns immediately with the generation id; poll get_generation until it succeeds or fails. Spends credits: call only when the user asked for an image. Pass idempotency_key to make a retry safe.')]
class GenerateImageTool extends StudioTool
{
    public function __construct(private readonly GenerationCreator $creator) {}

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->generationSchema($schema),
            'idempotency_key' => $schema->string()->description('Optional unique key for this request. Repeating the call with the same key returns the existing generation instead of creating and charging a second one.'),
        ];
    }

    protected function execute(Request $request, User $user): Response|ResponseFactory
    {
        $request->validate([...$this->generationRules(), 'idempotency_key' => ['sometimes', 'string', 'min:1', 'max:255']]);

        [$generation] = $this->creator->createOnce(
            $user,
            $request->string('prompt')->toString(),
            GenerationSize::from($request->string('size')->toString()),
            GenerationQuality::from($request->string('quality')->toString()),
            $this->referenceImages($request),
            $request->has('idempotency_key') ? $request->string('idempotency_key')->toString() : null,
        );

        return $this->respond($this->generationText($generation), ['generation' => $this->generationData($generation)]);
    }
}
