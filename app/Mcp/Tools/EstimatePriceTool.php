<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Models\User;
use App\Services\Generations\GenerationCreator;
use App\Support\Money;
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

#[Name('estimate_price')]
#[Title('Estimate the price of a generation')]
#[Description('Returns the minimum, expected and maximum price in USD of a generation, without creating anything or spending credits. Call it before generate_image and tell the user the estimate.')]
#[IsReadOnly]
#[IsIdempotent]
class EstimatePriceTool extends StudioTool
{
    public function __construct(private readonly GenerationCreator $creator) {}

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->generationSchema($schema);
    }

    protected function execute(Request $request, User $user): Response|ResponseFactory
    {
        $request->validate($this->generationRules());

        $price = $this->creator->estimatePrice(
            mb_strlen($request->string('prompt')->toString()),
            GenerationSize::from($request->string('size')->toString()),
            GenerationQuality::from($request->string('quality')->toString()),
            count($this->referenceImages($request)),
        );

        $data = [
            'minimum_price_usd' => Money::toDecimal($price->minimum),
            'expected_price_usd' => Money::toDecimal($price->expected),
            'maximum_price_usd' => Money::toDecimal($price->maximum),
            'currency' => 'usd',
        ];

        return $this->respond(
            "Expected price \${$data['expected_price_usd']} (between \${$data['minimum_price_usd']} and \${$data['maximum_price_usd']}). The exact price is known when the image is delivered.",
            $data,
        );
    }
}
