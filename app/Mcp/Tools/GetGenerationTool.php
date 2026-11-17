<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_generation')]
#[Title('Get a generation')]
#[Description('Returns one generation: its status (queued, processing, succeeded, failed), estimated and actual price in USD, the failure reason if any, and a link to the image that is valid for one hour.')]
#[IsReadOnly]
#[IsIdempotent]
class GetGenerationTool extends StudioTool
{
    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'generation_id' => $schema->integer()->description('The id returned by generate_image or list_generations.')->required(),
        ];
    }

    protected function execute(Request $request, User $user): Response|ResponseFactory
    {
        $request->validate(['generation_id' => ['required', 'integer', 'min:1']]);

        $generation = $user->generations()->findOrFail($request->integer('generation_id'));

        return $this->respond($this->generationText($generation), ['generation' => $this->generationData($generation)]);
    }
}
