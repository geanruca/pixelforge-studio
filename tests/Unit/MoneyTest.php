<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function decimals(): array
    {
        return [
            'nine decimals, as the usage report sends them' => ['0.373875000', 373_875_000],
            'fewer decimals' => ['0.5', 500_000_000],
            'whole dollars' => ['12', 12_000_000_000],
            'smallest unit' => ['0.000000001', 1],
            'zero' => ['0.000000000', 0],
            'trailing zeros beyond nine places' => ['1.2500000000', 1_250_000_000],
        ];
    }

    #[DataProvider('decimals')]
    public function test_it_parses_decimal_strings_exactly(string $decimal, int $nanos): void
    {
        $this->assertSame($nanos, Money::fromDecimal($decimal));
    }

    public function test_it_refuses_precision_it_cannot_represent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('0.0000000001');
    }

    #[DataProvider('invalidDecimals')]
    public function test_it_refuses_values_that_are_not_plain_decimals(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal($value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDecimals(): array
    {
        return [
            'negative' => ['-1.00'],
            'scientific notation' => ['1e-9'],
            'empty' => [''],
            'text' => ['free'],
            'thousands separator' => ['1,000.00'],
        ];
    }

    public function test_it_renders_nanodollars_as_an_exact_decimal(): void
    {
        $this->assertSame('0.373875000', Money::toDecimal(373_875_000));
        $this->assertSame('5.000000000', Money::toDecimal(Money::fromDollars(5)));
        $this->assertSame('-0.070625000', Money::toDecimal(-70_625_000));
    }

    public function test_amounts_that_floats_get_wrong_stay_exact(): void
    {
        // 0.1 + 0.2 !== 0.3 in floating point; in nanodollars it is.
        $this->assertSame(Money::fromDecimal('0.3'), Money::fromDecimal('0.1') + Money::fromDecimal('0.2'));
    }
}
