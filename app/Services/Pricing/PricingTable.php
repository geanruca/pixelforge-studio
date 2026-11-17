<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use InvalidArgumentException;

/**
 * The rules Pixelforge publishes at `GET /v1/pricing`: current rates plus how
 * many tokens a request will use.
 */
final readonly class PricingTable
{
    private const int BASIS_POINTS = 10_000;

    /**
     * @param  array<string, array<string, int>>  $outputTokens  quality → size → tokens (exact)
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  quality → range, known only after completion
     */
    public function __construct(
        public Rates $rates,
        public int $charactersPerTextToken,
        public int $textTokenVarianceBasisPoints,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /**
     * @param  array<array-key, mixed>  $pricing  the body of `GET /v1/pricing`
     */
    public static function fromApi(array $pricing): self
    {
        $tokens = $pricing['tokens'] ?? null;
        $rates = $pricing['rates'] ?? null;

        if (! is_array($tokens) || ! is_array($rates)) {
            throw new InvalidArgumentException('Pricing is missing its rates or tokens block.');
        }

        $charactersPerToken = $tokens['characters_per_text_token'] ?? null;
        $variance = $tokens['text_token_variance'] ?? null;
        $perReferenceImage = $tokens['per_reference_image'] ?? null;

        if (! is_int($charactersPerToken) || $charactersPerToken < 1 || ! is_numeric($variance) || ! is_int($perReferenceImage)) {
            throw new InvalidArgumentException('Pricing token rules are malformed.');
        }

        $output = [];
        $reasoning = [];

        foreach (GenerationQuality::cases() as $quality) {
            foreach (GenerationSize::cases() as $size) {
                $count = data_get($tokens, ['output', $quality->value, $size->value]);

                if (! is_int($count)) {
                    throw new InvalidArgumentException("Pricing has no output tokens for {$quality->value} {$size->value}.");
                }

                $output[$quality->value][$size->value] = $count;
            }

            $min = data_get($tokens, ['reasoning', $quality->value, 'min']);
            $max = data_get($tokens, ['reasoning', $quality->value, 'max']);

            if (! is_int($min) || ! is_int($max) || $min > $max) {
                throw new InvalidArgumentException("Pricing has no reasoning range for {$quality->value}.");
            }

            $reasoning[$quality->value] = ['min' => $min, 'max' => $max];
        }

        return new self(
            Rates::fromApi($rates),
            $charactersPerToken,
            (int) round(((float) $variance) * self::BASIS_POINTS),
            $perReferenceImage,
            $output,
            $reasoning,
        );
    }

    /**
     * Estimate what a request will cost the provider. Output and reference
     * image tokens are exact; text tokens can land within the published
     * variance of the estimate and reasoning tokens anywhere in the quality's
     * range, so the result is a low / expected / high triple.
     */
    public function estimate(int $promptCharacters, GenerationSize $size, GenerationQuality $quality, int $referenceImages): CostEstimate
    {
        $textTokens = intdiv($promptCharacters + $this->charactersPerTextToken - 1, $this->charactersPerTextToken);
        $minTextTokens = intdiv($textTokens * (self::BASIS_POINTS - $this->textTokenVarianceBasisPoints), self::BASIS_POINTS);
        $maxTextTokens = intdiv(
            $textTokens * (self::BASIS_POINTS + $this->textTokenVarianceBasisPoints) + self::BASIS_POINTS - 1,
            self::BASIS_POINTS,
        );

        $imageTokens = $referenceImages * $this->tokensPerReferenceImage;
        $outputTokens = $this->outputTokens[$quality->value][$size->value];
        $reasoning = $this->reasoningTokens[$quality->value];

        $cost = fn (int $text, int $reasoningTokens): int => $this->rates->costOf(
            new TokenUsage($text, $imageTokens, $outputTokens, $reasoningTokens),
        );

        return new CostEstimate(
            minimum: $cost($minTextTokens, $reasoning['min']),
            expected: $cost($textTokens, intdiv($reasoning['min'] + $reasoning['max'], 2)),
            maximum: $cost($maxTextTokens, $reasoning['max']),
        );
    }
}
