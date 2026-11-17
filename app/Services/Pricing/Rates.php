<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Token rates, held as integer nanodollars per single token.
 *
 * Pixelforge quotes USD per 1M tokens ("12.50"), which is exactly
 * 12,500 nanodollars per token, so a cost is a plain integer sum.
 */
final readonly class Rates
{
    /** Decimals of a "USD per 1M tokens" rate that still map to whole nanodollars per token. */
    private const int RATE_SCALE = 3;

    public function __construct(
        public int $inputTextToken,
        public int $inputImageToken,
        public int $outputToken,
        public int $reasoningToken,
    ) {}

    /**
     * @param  array<array-key, mixed>  $rates  the `rates` object of a generation or of `GET /v1/pricing`
     */
    public static function fromApi(array $rates): self
    {
        return new self(
            self::rate($rates, 'input_text_tokens'),
            self::rate($rates, 'input_image_tokens'),
            self::rate($rates, 'output_tokens'),
            self::rate($rates, 'reasoning_tokens'),
        );
    }

    /**
     * The exact provider cost of the given usage, in nanodollars.
     */
    public function costOf(TokenUsage $usage): int
    {
        return $usage->inputTextTokens * $this->inputTextToken
            + $usage->inputImageTokens * $this->inputImageToken
            + $usage->outputTokens * $this->outputToken
            + $usage->reasoningTokens * $this->reasoningToken;
    }

    /**
     * @param  array<array-key, mixed>  $rates
     */
    private static function rate(array $rates, string $key): int
    {
        $value = $rates[$key] ?? null;

        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("Rates are missing a decimal [{$key}] rate.");
        }

        return Money::toScaledInteger((string) $value, self::RATE_SCALE);
    }
}
