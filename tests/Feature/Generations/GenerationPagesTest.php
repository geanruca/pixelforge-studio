<?php

namespace Tests\Feature\Generations;

use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithPixelforge;
use Tests\TestCase;

class GenerationPagesTest extends TestCase
{
    use InteractsWithPixelforge;
    use RefreshDatabase;

    /** Medium, 1024x1024, 20 characters, no references: 5 text tokens. */
    private const int EXPECTED_PRICE = (5 * 5_000 + 1_100 * 40_000 + 1_100 * 12_500) * 5 / 4;

    private const int MAXIMUM_PRICE = (6 * 5_000 + 1_100 * 40_000 + 2_000 * 12_500) * 5 / 4;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePixelforge();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['*/pricing' => Http::response($this->pricingPayload())]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validInput(array $overrides = []): array
    {
        return [
            'prompt' => 'A lighthouse at dawn',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            ...$overrides,
        ];
    }

    /**
     * Replace the working fake with a Pixelforge that answers 503 to everything.
     */
    private function pixelforgeGoesDown(): void
    {
        Http::swap(new HttpFactory);
        Http::fake(['*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Busy']], 503)]);
    }

    public function test_guests_cannot_reach_any_generation_page(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('generations.index'))->assertRedirect(route('login'));
        $this->post(route('generations.store'), $this->validInput())->assertRedirect(route('login'));
        $this->getJson(route('generations.estimate'))->assertUnauthorized();

        $this->assertSame(0, Generation::query()->count());
    }

    public function test_the_generate_page_offers_the_sizes_and_qualities_pixelforge_accepts(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('generations/Create')
                ->where('sizes.0.value', '1024x1024')
                ->has('sizes', 3)
                ->has('qualities', 3)
                ->where('limits.prompt', 4000)
                ->where('limits.reference_images', 4)
                ->where('prefill', null));
    }

    public function test_the_estimate_shows_the_price_range_with_the_markup_included(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('generations.estimate', ['characters' => 20, 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => 0]))
            ->assertOk()
            ->assertExactJson(['price' => [
                // 5 text tokens may come in as low as floor(5 × 0.85) = 4.
                'minimum' => (4 * 5_000 + 1_100 * 40_000 + 200 * 12_500) * 5 / 4,
                'expected' => self::EXPECTED_PRICE,
                'maximum' => self::MAXIMUM_PRICE,
            ]]);
    }

    public function test_estimating_does_not_spend_a_pixelforge_request_each_time(): void
    {
        $user = User::factory()->create();
        $query = ['characters' => 20, 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => 0];

        $this->actingAs($user)->getJson(route('generations.estimate', $query))->assertOk();
        $this->actingAs($user)->getJson(route('generations.estimate', [...$query, 'characters' => 21]))->assertOk();

        Http::assertSentCount(1);
    }

    public function test_the_estimate_survives_a_pixelforge_outage_using_the_last_known_pricing(): void
    {
        $user = User::factory()->create();
        $query = ['characters' => 20, 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => 0];
        $this->actingAs($user)->getJson(route('generations.estimate', $query))->assertOk();

        Cache::forget('pixelforge.pricing.fresh');
        $this->pixelforgeGoesDown();

        $this->actingAs($user)->getJson(route('generations.estimate', $query))
            ->assertOk()
            ->assertJsonPath('price.expected', self::EXPECTED_PRICE);
    }

    public function test_the_estimate_says_so_when_pricing_was_never_available(): void
    {
        $this->pixelforgeGoesDown();

        $this->actingAs(User::factory()->create())
            ->getJson(route('generations.estimate', ['characters' => 20, 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => 0]))
            ->assertServiceUnavailable();
    }

    public function test_the_estimate_validates_its_input(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('generations.estimate', ['characters' => 4001, 'size' => '512x512', 'quality' => 'ultra', 'reference_images' => 5]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['characters', 'size', 'quality', 'reference_images']);
    }

    public function test_submitting_records_the_generation_holds_the_worst_case_and_queues_it(): void
    {
        $user = $this->userWithCredits(1);

        $this->actingAs($user)
            ->post(route('generations.store'), $this->validInput(['reference_images' => ['https://example.com/ref.png']]))
            ->assertRedirect(route('generations.index'));

        $generation = Generation::query()->sole();
        $maximum = self::MAXIMUM_PRICE + 765 * 10_000 * 5 / 4;

        $this->assertSame($user->id, $generation->user_id);
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertNull($generation->provider_id);
        $this->assertSame(['https://example.com/ref.png'], $generation->reference_images);
        $this->assertSame(self::EXPECTED_PRICE + 765 * 10_000 * 5 / 4, $generation->estimated_price_nanos);
        $this->assertSame($maximum, $generation->reserved_nanos);
        $this->assertSame(1_000_000_000 - $maximum, $user->refresh()->balance_nanos);
        $this->assertLedgerMatchesBalance($user);
        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job): bool => $job->generationId === $generation->id);
    }

    public function test_the_prompt_is_counted_in_unicode_characters_not_bytes(): void
    {
        $user = $this->userWithCredits(1);

        // 20 characters, 80 bytes: must be priced as 5 text tokens, like 20 ASCII characters.
        $this->actingAs($user)->post(route('generations.store'), $this->validInput(['prompt' => str_repeat('🎨', 20)]));

        $this->assertSame(self::EXPECTED_PRICE, Generation::query()->sole()->estimated_price_nanos);
    }

    public function test_a_user_who_cannot_afford_the_worst_case_is_blocked_and_nothing_is_created(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('generations.store'), $this->validInput())
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('credits');

        $this->assertSame(0, Generation::query()->count());
        $this->assertSame(0, $user->ledgerEntries()->count());
        Queue::assertNothingPushed();
    }

    public function test_credits_held_by_pending_generations_cannot_be_spent_twice(): void
    {
        $user = User::factory()->create();
        app(CreditLedger::class)->purchase($user, self::MAXIMUM_PRICE * 2 - 1, 'ref');

        $this->actingAs($user)->post(route('generations.store'), $this->validInput())->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('generations.store'), $this->validInput())->assertSessionHasErrors('credits');

        $this->assertSame(1, Generation::query()->count());
        $this->assertGreaterThanOrEqual(0, $user->refresh()->balance_nanos);
    }

    public function test_nothing_is_submitted_or_held_when_pricing_is_unavailable(): void
    {
        $this->pixelforgeGoesDown();
        $user = $this->userWithCredits(1);

        $this->actingAs($user)->post(route('generations.store'), $this->validInput())->assertSessionHasErrors('credits');

        $this->assertSame(0, Generation::query()->count());
        $this->assertSame(1_000_000_000, $user->refresh()->balance_nanos);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidInput(): array
    {
        return [
            'missing prompt' => [['prompt' => ''], 'prompt'],
            'whitespace-only prompt' => [['prompt' => "  \n "], 'prompt'],
            'prompt one character too long' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
            'unknown size' => [['size' => '512x512'], 'size'],
            'unknown quality' => [['quality' => 'ultra'], 'quality'],
            'five reference images' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
            'reference that is not a url' => [['reference_images' => ['not a url']], 'reference_images.0'],
            'reference with a non-http scheme' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
            'javascript url' => [['reference_images' => ['javascript:alert(1)']], 'reference_images.0'],
            'empty reference field' => [['reference_images' => ['']], 'reference_images.0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidInput')]
    public function test_invalid_requests_are_rejected_before_anything_is_held(array $overrides, string $field): void
    {
        $user = $this->userWithCredits(1);

        $this->actingAs($user)->post(route('generations.store'), $this->validInput($overrides))->assertSessionHasErrors($field);

        $this->assertSame(0, Generation::query()->count());
        $this->assertSame(1_000_000_000, $user->refresh()->balance_nanos);
    }

    public function test_the_longest_prompt_and_four_references_are_accepted(): void
    {
        $user = $this->userWithCredits(1);

        $this->actingAs($user)->post(route('generations.store'), $this->validInput([
            'prompt' => str_repeat('a', 4000),
            'reference_images' => array_fill(0, 4, 'https://example.com/a.png'),
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, Generation::query()->count());
    }

    public function test_history_lists_only_the_users_own_generations_newest_first(): void
    {
        $user = User::factory()->create();
        $older = Generation::factory()->for($user)->succeeded()->create();
        $newer = Generation::factory()->for($user)->processing()->create();
        Generation::factory()->succeeded()->create();

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('generations/Index')
                ->has('generations.data', 2)
                ->where('generations.data.0.id', $newer->id)
                ->where('generations.data.0.status', 'processing')
                ->where('generations.data.0.price_nanos', null)
                ->where('generations.data.1.id', $older->id)
                ->where('generations.data.1.price_nanos', $older->price_nanos)
                ->where('generations.data.1.estimated_price_nanos', $older->estimated_price_nanos)
                ->where('generations.data.1.image_url', route('generations.image', $older))
                ->where('hasActive', true));
    }

    public function test_history_stops_polling_once_nothing_is_in_flight(): void
    {
        $user = User::factory()->create();
        Generation::factory()->for($user)->succeeded()->create();
        Generation::factory()->for($user)->failed()->create();

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('hasActive', false));
    }

    public function test_a_failed_generation_explains_itself_and_shows_no_charge(): void
    {
        $user = User::factory()->create();
        Generation::factory()->for($user)->failed('content_policy_violation')->create();

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('generations.data.0.status', 'failed')
                ->where('generations.data.0.price_nanos', null)
                ->where('generations.data.0.error', 'This prompt was rejected by the content policy. You were not charged.'));
    }

    public function test_a_generation_not_yet_accepted_by_pixelforge_is_shown_as_waiting(): void
    {
        $user = User::factory()->create();
        Generation::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('generations.data.0.status', 'queued')
                ->where('generations.data.0.awaiting_slot', true));
    }

    public function test_an_expired_image_that_was_never_copied_is_reported_as_expired_not_broken(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->for($user)->succeeded()->create(['image_url_expires_at' => now()->subMinute()]);

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('generations.data.0.image_url', null)
                ->where('generations.data.0.image_expired', true));

        $this->actingAs($user)->get(route('generations.image', $generation))->assertNotFound();
    }

    public function test_the_stored_copy_is_served_even_after_the_provider_url_expired(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('generations/1/1.png', 'png-bytes');
        $user = User::factory()->create();
        $generation = Generation::factory()->for($user)->succeeded()->create([
            'image_path' => 'generations/1/1.png',
            'image_url_expires_at' => now()->subDays(3),
        ]);

        $response = $this->actingAs($user)->get(route('generations.image', $generation))->assertOk();

        $this->assertSame('png-bytes', $response->streamedContent());
    }

    public function test_the_provider_url_is_used_while_the_copy_is_still_pending(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->for($user)->succeeded()->create();

        $this->actingAs($user)->get(route('generations.image', $generation))->assertRedirect($generation->image_url);
    }

    public function test_nobody_can_load_another_users_image(): void
    {
        $generation = Generation::factory()->succeeded()->create();

        $this->actingAs(User::factory()->create())->get(route('generations.image', $generation))->assertForbidden();
        auth()->logout();
        $this->get(route('generations.image', $generation))->assertRedirect(route('login'));
    }

    public function test_try_again_prefills_the_form_only_from_the_users_own_generation(): void
    {
        $user = User::factory()->create();
        $own = Generation::factory()->for($user)->failed()->create(['prompt' => 'Mine', 'quality' => 'high']);
        $foreign = Generation::factory()->failed()->create(['prompt' => 'Secret prompt']);

        $this->actingAs($user)->get(route('dashboard', ['from' => $own->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('prefill.prompt', 'Mine')->where('prefill.quality', 'high'));

        $this->actingAs($user)->get(route('dashboard', ['from' => $foreign->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('prefill', null));
    }

    public function test_the_hold_is_recorded_as_a_ledger_entry_tied_to_the_generation(): void
    {
        $user = $this->userWithCredits(1);

        $this->actingAs($user)->post(route('generations.store'), $this->validInput());

        $this->assertSame(1, $this->ledgerCount(Generation::query()->sole(), LedgerEntryType::Hold));
    }
}
