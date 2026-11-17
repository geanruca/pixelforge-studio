<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_generations')]
#[Title('List recent generations')]
#[Description('Lists the user\'s most recent generations, newest first, optionally filtered by status.')]
#[IsReadOnly]
#[IsIdempotent]
class ListGenerationsTool extends StudioTool
{
    private const int DEFAULT_LIMIT = 10;

    private const int MAX_LIMIT = 50;

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(GenerationStatus::class)->description('Only generations in this status.'),
            'limit' => $schema->integer()->min(1)->max(self::MAX_LIMIT)->description('How many to return. Defaults to '.self::DEFAULT_LIMIT.'.'),
        ];
    }

    protected function execute(Request $request, User $user): Response|ResponseFactory
    {
        $request->validate([
            'status' => ['sometimes', Rule::enum(GenerationStatus::class)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);

        $generations = $user->generations()
            ->when($request->has('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->latest('id')
            ->limit($request->integer('limit', self::DEFAULT_LIMIT))
            ->get();

        $text = $generations->isEmpty()
            ? 'No generations found.'
            : $generations->map(fn (Generation $generation): string => $this->generationText($generation))->implode("\n");

        return $this->respond($text, [
            'generations' => $generations->map(fn (Generation $generation): array => $this->generationData($generation))->all(),
        ]);
    }
}
