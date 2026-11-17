<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * A low / expected / high amount in nanodollars.
 */
final readonly class CostEstimate
{
    public function __construct(
        public int $minimum,
        public int $expected,
        public int $maximum,
    ) {}

    /**
     * The same estimate as the user will pay it: every bound with the markup applied.
     */
    public function withMarkup(Markup $markup): self
    {
        return new self(
            $markup->priceFor($this->minimum),
            $markup->priceFor($this->expected),
            $markup->priceFor($this->maximum),
        );
    }

    /**
     * @return array{minimum: int, expected: int, maximum: int}
     */
    public function toArray(): array
    {
        return [
            'minimum' => $this->minimum,
            'expected' => $this->expected,
            'maximum' => $this->maximum,
        ];
    }
}
