<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * What the user pays for a provider cost: cost plus the configured markup.
 *
 * Sub-cent policy: prices are NOT rounded to cents. A typical generation
 * costs a fraction of a cent, so rounding each one to $0.01 would either give
 * the work away or multiply its price. Users are charged the exact price in
 * nanodollars; the only rounding is up to the next whole nanodollar when the
 * markup does not divide evenly, so the margin is never below the markup.
 */
final readonly class Markup
{
    private const int BASIS_POINTS = 10_000;

    public function __construct(public int $basisPoints) {}

    public static function fromConfig(): self
    {
        return new self(config()->integer('pixelforge.markup_basis_points'));
    }

    public function priceFor(int $costNanos): int
    {
        $numerator = $costNanos * (self::BASIS_POINTS + $this->basisPoints);

        return intdiv($numerator + self::BASIS_POINTS - 1, self::BASIS_POINTS);
    }
}
