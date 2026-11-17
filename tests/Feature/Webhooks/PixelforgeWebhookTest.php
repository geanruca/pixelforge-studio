<?php

namespace Tests\Feature\Webhooks;

use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Enums\WebhookOutcome;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InteractsWithPixelforge;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use InteractsWithPixelforge;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->configurePixelforge();
    }

    public function test_a_succeeded_event_completes_and_charges_the_generation(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);

        $this->deliverWebhook($this->webhookEvent('generation.succeeded', $this->providerGeneration($generation, 'succeeded')))
            ->assertOk()
            ->assertJson(['received' => true, 'outcome' => 'applied']);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(self::USAGE_PRICE_NANOS, $generation->price_nanos);
        $this->assertSame(1_000_000_000 - self::USAGE_PRICE_NANOS, $user->refresh()->balance_nanos);
    }

    public function test_a_duplicate_event_is_acknowledged_but_recorded_once(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);
        $event = $this->webhookEvent('generation.succeeded', $this->providerGeneration($generation, 'succeeded'));

        $this->deliverWebhook($event)->assertOk();
        $this->deliverWebhook($event)->assertOk()->assertJson(['duplicate' => true]);
        $this->deliverWebhook($event)->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, WebhookEvent::query()->count());
        $this->assertSame(1, $this->ledgerCount($generation, LedgerEntryType::Charge));
        $this->assertSame(1_000_000_000 - self::USAGE_PRICE_NANOS, $user->refresh()->balance_nanos);
        $this->assertLedgerMatchesBalance($user);
    }

    public function test_the_same_outcome_under_two_different_event_ids_still_charges_once(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);
        $object = $this->providerGeneration($generation, 'succeeded');

        // A dashboard "resend" could mint a new event id for the same fact.
        $this->deliverWebhook($this->webhookEvent('generation.succeeded', $object))->assertJson(['outcome' => 'applied']);
        $this->deliverWebhook($this->webhookEvent('generation.succeeded', $object))->assertJson(['outcome' => 'stale']);

        $this->assertSame(1, $this->ledgerCount($generation, LedgerEntryType::Charge));
        $this->assertLedgerMatchesBalance($user);
    }

    public function test_events_arriving_out_of_order_end_in_the_right_state(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);

        $this->deliverWebhook($this->webhookEvent('generation.succeeded', $this->providerGeneration($generation, 'succeeded')))
            ->assertJson(['outcome' => 'applied']);
        $this->deliverWebhook($this->webhookEvent('generation.processing', $this->providerGeneration($generation, 'processing')))
            ->assertOk()
            ->assertJson(['outcome' => 'stale']);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(self::USAGE_PRICE_NANOS, $generation->price_nanos);
        $this->assertSame(2, WebhookEvent::query()->count());
    }

    public function test_a_failed_event_releases_the_hold_and_charges_nothing(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);

        $this->deliverWebhook($this->webhookEvent('generation.failed', $this->providerGeneration($generation, 'failed')))->assertOk();

        $this->assertSame(GenerationStatus::Failed, $generation->refresh()->status);
        $this->assertSame(1_000_000_000, $user->refresh()->balance_nanos);
        $this->assertSame(0, $this->ledgerCount($generation, LedgerEntryType::Charge));
    }

    public function test_an_event_that_beats_the_create_response_finds_the_generation_by_its_metadata(): void
    {
        $user = $this->userWithCredits(1);
        // Not submitted from our point of view: we do not know the provider id yet.
        $generation = $this->heldGeneration($user, submitted: false);

        $this->deliverWebhook($this->webhookEvent(
            'generation.processing',
            $this->providerGeneration($generation, 'processing', ['id' => 'gen_arrived_first']),
        ))->assertJson(['outcome' => 'applied']);

        $generation->refresh();
        $this->assertSame('gen_arrived_first', $generation->provider_id);
        $this->assertSame(GenerationStatus::Processing, $generation->status);
    }

    public function test_the_status_inside_the_event_wins_over_the_event_type(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);

        $this->deliverWebhook($this->webhookEvent('generation.processing', $this->providerGeneration($generation, 'succeeded')))->assertOk();

        $this->assertSame(GenerationStatus::Succeeded, $generation->refresh()->status);
    }

    public function test_a_wrong_signature_is_rejected_and_changes_nothing(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);
        $event = $this->webhookEvent('generation.succeeded', $this->providerGeneration($generation, 'succeeded'));

        $this->deliverWebhook($event, secret: 'whsec_wrong')->assertUnauthorized();

        $this->assertSame(GenerationStatus::Queued, $generation->refresh()->status);
        $this->assertSame(0, WebhookEvent::query()->count());
    }

    public function test_a_body_altered_after_signing_is_rejected(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);
        $event = $this->webhookEvent('generation.failed', $this->providerGeneration($generation, 'failed'));
        $signedBody = (string) json_encode($event);
        $timestamp = now()->getTimestamp();
        $signature = hash_hmac('sha256', "{$timestamp}.{$signedBody}", self::WEBHOOK_SECRET);

        $this->call('POST', route('webhooks.pixelforge'), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], content: str_replace('failed', 'succeeded', $signedBody))->assertUnauthorized();

        $this->assertSame(GenerationStatus::Queued, $generation->refresh()->status);
    }

    public function test_a_replayed_old_delivery_is_rejected(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);
        $event = $this->webhookEvent('generation.succeeded', $this->providerGeneration($generation, 'succeeded'));

        $this->deliverWebhook($event, timestamp: now()->subSeconds(301)->getTimestamp())->assertUnauthorized();
        $this->deliverWebhook($event, timestamp: now()->addSeconds(301)->getTimestamp())->assertUnauthorized();
        $this->deliverWebhook($event, timestamp: now()->subSeconds(299)->getTimestamp())->assertOk();
    }

    public function test_a_request_without_a_signature_header_is_rejected(): void
    {
        $this->postJson(route('webhooks.pixelforge'), ['id' => 'evt_1', 'type' => 'ping'])->assertUnauthorized();
        $this->call('POST', route('webhooks.pixelforge'), server: ['HTTP_PIXELFORGE_SIGNATURE' => 'garbage'], content: '{}')
            ->assertUnauthorized();
    }

    public function test_nothing_is_accepted_while_no_signing_secret_is_configured(): void
    {
        config(['pixelforge.webhook_secret' => null]);

        $this->deliverWebhook($this->webhookEvent('ping', []), secret: '')->assertUnauthorized();
    }

    public function test_a_ping_is_acknowledged(): void
    {
        $this->deliverWebhook(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'created_at' => now()->toIso8601String()])
            ->assertOk()
            ->assertJson(['outcome' => 'ignored']);

        $this->assertSame(WebhookOutcome::Ignored, WebhookEvent::query()->sole()->outcome);
    }

    public function test_an_event_for_a_generation_we_do_not_know_is_acknowledged_without_effect(): void
    {
        $stranger = Generation::factory()->submitted()->make(['id' => 999_999]);

        $this->deliverWebhook($this->webhookEvent('generation.succeeded', $this->providerGeneration($stranger, 'succeeded')))
            ->assertOk()
            ->assertJson(['outcome' => 'ignored']);

        $this->assertSame(0, Generation::query()->count());
    }

    public function test_a_signed_body_that_is_not_an_event_is_a_bad_request(): void
    {
        $this->deliverWebhook([], body: 'not json')->assertBadRequest();
        $this->deliverWebhook(['type' => 'generation.succeeded'])->assertBadRequest();
    }

    public function test_an_unusable_succeeded_event_is_refused_and_its_retry_can_still_apply(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);
        $broken = $this->webhookEvent('generation.succeeded', $this->providerGeneration($generation, 'succeeded', ['usage' => null]));

        // Without usage there is nothing to bill from: refuse rather than guess a price.
        $this->deliverWebhook($broken)->assertUnprocessable();

        $this->assertSame(GenerationStatus::Queued, $generation->refresh()->status);
        $this->assertSame(0, WebhookEvent::query()->count(), 'A refused event must not be claimed, so a retry can apply.');

        $fixed = [...$broken, 'data' => ['object' => $this->providerGeneration($generation, 'succeeded')]];
        $this->deliverWebhook($fixed)->assertOk()->assertJson(['outcome' => 'applied']);

        $this->assertSame(self::USAGE_PRICE_NANOS, $generation->refresh()->price_nanos);
    }

    public function test_the_webhook_needs_no_session_or_csrf_token(): void
    {
        $this->deliverWebhook(['id' => 'evt_csrf', 'type' => 'ping'])->assertOk();

        $this->assertContains('api', app('router')->getRoutes()->getByName('webhooks.pixelforge')?->gatherMiddleware() ?? []);
    }
}
