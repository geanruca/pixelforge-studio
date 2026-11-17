<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Money is stored and computed as an integer number of nanodollars
 * (1 USD = 1,000,000,000). The provider bills fractions of a cent with nine
 * decimal places, so nanodollars represent every cost exactly and no float
 * ever touches an amount.
 */
final class Money
{
    public const int NANOS_PER_DOLLAR = 1_000_000_000;

    private const int SCALE = 9;

    /**
     * Parse an exact decimal string ("0.373875000") into nanodollars.
     */
    public static function fromDecimal(string $amount): int
    {
        return self::toScaledInteger($amount, self::SCALE);
    }

    public static function fromDollars(int $dollars): int
    {
        return $dollars * self::NANOS_PER_DOLLAR;
    }

    /**
     * Render nanodollars as an exact decimal string with nine decimals.
     */
    public static function toDecimal(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $absolute = abs($nanos);

        return sprintf(
            '%s%d.%09d',
            $sign,
            intdiv($absolute, self::NANOS_PER_DOLLAR),
            $absolute % self::NANOS_PER_DOLLAR,
        );
    }

    /**
     * Convert a non-negative decimal string into an integer scaled by
     * 10^$scale, refusing any value that does not fit exactly: silently
     * dropping digits is how money drifts.
     */
    public static function toScaledInteger(string $decimal, int $scale): int
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', trim($decimal), $matches) !== 1) {
            throw new InvalidArgumentException("[{$decimal}] is not a non-negative decimal number.");
        }

        $fraction = rtrim($matches[2] ?? '', '0');

        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException("[{$decimal}] has more than {$scale} decimal places.");
        }

        return (int) ($matches[1].str_pad($fraction, $scale, '0'));
    }
}
