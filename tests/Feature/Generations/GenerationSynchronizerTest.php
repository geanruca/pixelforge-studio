<?php

namespace Tests\Feature\Generations;

use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Services\Generations\GenerationSynchronizer;
use App\Services\Generations\SyncOutcome;
use App\Services\Pixelforge\ProviderGeneration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\Support\InteractsWithPixelforge;
use Tests\TestCase;

class GenerationSynchronizerTest extends TestCase
{
    use InteractsWithPixelforge;
    use RefreshDatabase;

    private GenerationSynchronizer $synchronizer;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->synchronizer = app(GenerationSynchronizer::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function apply(Generation $generation, string $status, array $overrides = []): SyncOutcome
    {
        return $this->synchronizer->apply(
            $generation,
            ProviderGeneration::fromApi($this->providerGeneration($generation, $status, $overrides)),
        );
    }

    public function test_it_walks_the_whole_lifecycle_and_charges_the_actual_price(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);

        $this->assertSame(SyncOutcome::Applied, $this->apply($generation, 'processing'));
        $this->assertSame(GenerationStatus::Processing, $generation->status);
        $this->assertNotNull($generation->started_at);

        $this->assertSame(SyncOutcome::Applied, $this->apply($generation, 'succeeded'));

        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(self::USAGE_COST_NANOS, $generation->provider_cost_nanos);
        $this->assertSame(self::USAGE_PRICE_NANOS, $generation->price_nanos);
        $this->assertSame(1_000_000_000 - self::USAGE_PRICE_NANOS, $user->refresh()->balance_nanos);
        $this->assertLedgerMatchesBalance($user);
        Queue::assertPushed(StoreGenerationImage::class, 1);
    }

    public function test_the_cost_is_computed_from_the_generations_own_rates_not_todays(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);

        // Rates are fixed when the generation is created; a later price change must not reprice it.
        $this->apply($generation, 'succeeded', ['rates' => [
            'input_text_tokens' => '10.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '80.00',
            'reasoning_tokens' => '25.00',
        ]]);

        $this->assertSame(self::USAGE_COST_NANOS * 2, $generation->provider_cost_nanos);
        $this->assertSame(self::USAGE_PRICE_NANOS * 2, $generation->price_nanos);
    }

    public function test_a_failed_generation_costs_nothing(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);

        $this->apply($generation, 'failed');

        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('generation_failed', $generation->error_code);
        $this->assertNull($generation->price_nanos);
        $this->assertSame(1_000_000_000, $user->refresh()->balance_nanos);
        $this->assertSame(0, $this->ledgerCount($generation, LedgerEntryType::Charge));
        $this->assertLedgerMatchesBalance($user);
        Queue::assertNothingPushed();
    }

    public function test_a_late_processing_snapshot_never_moves_a_succeeded_generation_back(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);
        $this->apply($generation, 'succeeded');

        $this->assertSame(SyncOutcome::Stale, $this->apply($generation, 'processing'));
        $this->assertSame(SyncOutcome::Stale, $this->apply($generation, 'queued'));

        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(self::USAGE_PRICE_NANOS, $generation->price_nanos);
    }

    public function test_succeeded_can_arrive_without_processing_ever_arriving(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);

        $this->assertSame(SyncOutcome::Applied, $this->apply($generation, 'succeeded'));

        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
    }

    public function test_applying_the_same_succeeded_snapshot_twice_charges_once(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);

        $this->apply($generation, 'succeeded');
        $this->assertSame(SyncOutcome::Stale, $this->apply($generation, 'succeeded'));

        $this->assertSame(1, $this->ledgerCount($generation, LedgerEntryType::Charge));
        $this->assertSame(1_000_000_000 - self::USAGE_PRICE_NANOS, $user->refresh()->balance_nanos);
        Queue::assertPushed(StoreGenerationImage::class, 1);
    }

    public function test_a_final_state_confirmed_by_the_provider_never_changes(): void
    {
        $user = $this->userWithCredits(1);
        $failed = $this->heldGeneration($user);
        $this->apply($failed, 'failed');

        $this->assertSame(SyncOutcome::Stale, $this->apply($failed, 'succeeded'));
        $this->assertSame(GenerationStatus::Failed, $failed->status);
        $this->assertSame(1_000_000_000, $user->refresh()->balance_nanos);

        $succeeded = $this->heldGeneration($user);
        $this->apply($succeeded, 'succeeded');

        $this->assertSame(SyncOutcome::Stale, $this->apply($succeeded, 'failed'));
        $this->assertSame(GenerationStatus::Succeeded, $succeeded->status);
        $this->assertLedgerMatchesBalance($user);
    }

    public function test_a_submission_we_gave_up_on_is_still_billed_if_the_provider_delivered_it(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user, submitted: false);

        // We stopped waiting (timeouts) and refunded the hold…
        $this->synchronizer->failLocally($generation, 'submission_timeout', 'Too busy.', confirmed: false);
        $this->assertSame(1_000_000_000, $user->refresh()->balance_nanos);

        // …but Pixelforge had accepted it, generated the image and billed us.
        $this->assertSame(SyncOutcome::Applied, $this->apply($generation, 'succeeded'));

        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertNull($generation->error_code);
        $this->assertSame(1_000_000_000 - self::USAGE_PRICE_NANOS, $user->refresh()->balance_nanos);
        $this->assertLedgerMatchesBalance($user);
    }

    public function test_a_rejection_confirmed_by_the_provider_cannot_be_reopened(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user, submitted: false);

        $this->synchronizer->failLocally($generation, 'content_policy_violation', 'Rejected.', confirmed: true);

        $this->assertSame(SyncOutcome::Stale, $this->apply($generation, 'succeeded'));
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame(1_000_000_000, $user->refresh()->balance_nanos);
    }

    public function test_a_generation_the_provider_accepted_cannot_be_closed_locally(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);

        $this->synchronizer->failLocally($generation, 'submission_timeout', 'Too busy.', confirmed: false);

        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame(1_000_000_000 - $generation->reserved_nanos, $user->refresh()->balance_nanos);
    }

    public function test_failing_locally_twice_releases_the_hold_once(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user, submitted: false);

        $this->synchronizer->failLocally($generation, 'submission_timeout', 'Too busy.', confirmed: false);
        $this->synchronizer->failLocally($generation, 'submission_timeout', 'Too busy.', confirmed: false);

        $this->assertSame(1_000_000_000, $user->refresh()->balance_nanos);
        $this->assertSame(1, $this->ledgerCount($generation, LedgerEntryType::HoldRelease));
    }

    public function test_a_snapshot_for_a_different_provider_generation_is_refused(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);

        $this->expectException(LogicException::class);

        $this->apply($generation, 'succeeded', ['id' => 'gen_someone_else']);
    }

    public function test_an_actual_price_above_the_hold_is_still_charged_in_full(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user, ['reserved_nanos' => 1_000]);

        $this->apply($generation, 'succeeded');

        // The provider billed it, so the truthful record is the full price.
        $this->assertSame(self::USAGE_PRICE_NANOS, $generation->price_nanos);
        $this->assertSame(1_000_000_000 - self::USAGE_PRICE_NANOS, $user->refresh()->balance_nanos);
        $this->assertLedgerMatchesBalance($user);
    }
}
