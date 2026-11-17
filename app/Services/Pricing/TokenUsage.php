<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use InvalidArgumentException;

final readonly class TokenUsage
{
    public function __construct(
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
    ) {}

    /**
     * @param  array<array-key, mixed>  $usage  the `usage` object of a Pixelforge generation
     */
    public static function fromApi(array $usage): self
    {
        return new self(
            self::tokens($usage, 'input_text_tokens'),
            self::tokens($usage, 'input_image_tokens'),
            self::tokens($usage, 'output_tokens'),
            self::tokens($usage, 'reasoning_tokens'),
        );
    }

    /**
     * @return array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int}
     */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => $this->inputTextTokens,
            'input_image_tokens' => $this->inputImageTokens,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $usage
     */
    private static function tokens(array $usage, string $key): int
    {
        $value = $usage[$key] ?? null;

        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("Usage is missing a valid [{$key}] count.");
        }

        return $value;
    }
}
