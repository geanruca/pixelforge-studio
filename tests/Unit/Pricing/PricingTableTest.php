<?php

namespace Tests\Unit\Pricing;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Services\Pricing\Markup;
use App\Services\Pricing\PricingTable;
use App\Services\Pricing\Rates;
use App\Services\Pricing\TokenUsage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\InteractsWithPixelforge;

class PricingTableTest extends TestCase
{
    use InteractsWithPixelforge;

    private function table(): PricingTable
    {
        return PricingTable::fromApi($this->pricingPayload());
    }

    public function test_the_worst_case_matches_the_worked_example_in_the_pixelforge_docs(): void
    {
        // High quality, 1536x1024, 400 characters, 2 reference images:
        // the docs put the maximum at exactly 0.373875000 USD.
        $estimate = $this->table()->estimate(400, GenerationSize::Landscape, GenerationQuality::High, 2);

        $this->assertSame(373_875_000, $estimate->maximum);
    }

    public function test_an_estimate_is_a_low_expected_high_triple(): void
    {
        $estimate = $this->table()->estimate(400, GenerationSize::Square, GenerationQuality::Medium, 0);

        // text 85 / 100 / 115 tokens at 5,000; output 1,100 at 40,000; reasoning 200 / 1,100 / 2,000 at 12,500.
        $this->assertSame(85 * 5_000 + 1_100 * 40_000 + 200 * 12_500, $estimate->minimum);
        $this->assertSame(100 * 5_000 + 1_100 * 40_000 + 1_100 * 12_500, $estimate->expected);
        $this->assertSame(115 * 5_000 + 1_100 * 40_000 + 2_000 * 12_500, $estimate->maximum);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function promptLengths(): array
    {
        return [
            'empty prompt' => [0, 0],
            'one character still costs a token' => [1, 1],
            'exactly one token' => [4, 1],
            'one character over rounds up' => [5, 2],
            'the longest prompt allowed' => [4000, 1000],
        ];
    }

    #[DataProvider('promptLengths')]
    public function test_text_tokens_are_characters_divided_by_four_rounded_up(int $characters, int $tokens): void
    {
        $withPrompt = $this->table()->estimate($characters, GenerationSize::Square, GenerationQuality::Low, 0);
        $withoutPrompt = $this->table()->estimate(0, GenerationSize::Square, GenerationQuality::Low, 0);

        $this->assertSame($tokens * 5_000, $withPrompt->expected - $withoutPrompt->expected);
    }

    public function test_each_reference_image_adds_exactly_765_image_tokens(): void
    {
        $none = $this->table()->estimate(40, GenerationSize::Square, GenerationQuality::Low, 0);
        $four = $this->table()->estimate(40, GenerationSize::Square, GenerationQuality::Low, 4);

        $this->assertSame(4 * 765 * 10_000, $four->minimum - $none->minimum);
        $this->assertSame(4 * 765 * 10_000, $four->maximum - $none->maximum);
    }

    public function test_any_actual_usage_inside_the_published_ranges_falls_within_the_estimate(): void
    {
        $table = $this->table();

        foreach (GenerationQuality::cases() as $quality) {
            foreach (GenerationSize::cases() as $size) {
                $estimate = $table->estimate(1234, $size, $quality, 3);
                $textTokens = (int) ceil(1234 / 4);
                $reasoning = $table->reasoningTokens[$quality->value];

                $cheapest = $table->rates->costOf(new TokenUsage((int) ceil($textTokens * 0.85), 3 * 765, $table->outputTokens[$quality->value][$size->value], $reasoning['min']));
                $dearest = $table->rates->costOf(new TokenUsage((int) floor($textTokens * 1.15), 3 * 765, $table->outputTokens[$quality->value][$size->value], $reasoning['max']));

                $this->assertGreaterThanOrEqual($estimate->minimum, $cheapest);
                $this->assertLessThanOrEqual($estimate->maximum, $dearest);
                $this->assertGreaterThanOrEqual($estimate->minimum, $estimate->expected);
                $this->assertLessThanOrEqual($estimate->maximum, $estimate->expected);
            }
        }
    }

    public function test_rates_convert_usd_per_million_tokens_to_whole_nanodollars_per_token(): void
    {
        $rates = Rates::fromApi($this->ratesPayload());

        $this->assertSame(5_000, $rates->inputTextToken);
        $this->assertSame(10_000, $rates->inputImageToken);
        $this->assertSame(40_000, $rates->outputToken);
        $this->assertSame(12_500, $rates->reasoningToken);
    }

    public function test_a_rate_that_would_need_rounding_is_refused_instead_of_silently_rounded(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Rates::fromApi([...$this->ratesPayload(), 'output_tokens' => '40.00001']);
    }

    public function test_a_pricing_table_missing_a_size_is_refused(): void
    {
        $pricing = $this->pricingPayload();
        unset($pricing['tokens']['output']['high']['1024x1536']);

        $this->expectException(InvalidArgumentException::class);

        PricingTable::fromApi($pricing);
    }

    public function test_usage_without_a_token_count_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TokenUsage::fromApi(['input_text_tokens' => 10, 'output_tokens' => 10, 'reasoning_tokens' => 10]);
    }

    public function test_the_markup_adds_a_quarter_on_top_of_the_cost(): void
    {
        $markup = new Markup(2500);

        $this->assertSame(70_625_000, $markup->priceFor(56_500_000));
        $this->assertSame(0, $markup->priceFor(0));
    }

    public function test_a_markup_that_does_not_divide_evenly_rounds_up_to_the_next_nanodollar(): void
    {
        $markup = new Markup(2500);

        // 1 nanodollar × 1.25 = 1.25 → 2; 3 × 1.25 = 3.75 → 4. Never below the markup.
        $this->assertSame(2, $markup->priceFor(1));
        $this->assertSame(4, $markup->priceFor(3));
        $this->assertSame(5, $markup->priceFor(4));
    }

    public function test_estimates_carry_the_markup_on_every_bound(): void
    {
        $estimate = $this->table()->estimate(400, GenerationSize::Square, GenerationQuality::Medium, 0);
        $price = $estimate->withMarkup(new Markup(2500));

        $this->assertSame(intdiv($estimate->minimum * 5, 4), $price->minimum);
        $this->assertSame(intdiv($estimate->expected * 5, 4), $price->expected);
        $this->assertSame(intdiv($estimate->maximum * 5, 4), $price->maximum);
    }
}
