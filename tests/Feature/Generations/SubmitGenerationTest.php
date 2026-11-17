<?php

namespace Tests\Feature\Generations;

use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\InteractsWithPixelforge;
use Tests\TestCase;

class SubmitGenerationTest extends TestCase
{
    use InteractsWithPixelforge;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePixelforge();
        Http::preventStrayRequests();
    }

    private function runJob(Generation $generation): SubmitGeneration
    {
        $job = (new SubmitGeneration($generation->id))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        return $job;
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function fakeRejection(int $status, string $code, array $headers = []): void
    {
        Http::fake(['*/generations' => Http::response(
            ['error' => ['type' => 'error', 'code' => $code, 'message' => "Rejected: {$code}."]],
            $status,
            $headers,
        )]);
    }

    public function test_it_submits_the_generation_and_records_the_provider_id(): void
    {
        $generation = $this->heldGeneration($this->userWithCredits(1), [
            'prompt' => 'A lighthouse at dawn',
            'reference_images' => ['https://example.com/a.png'],
        ], submitted: false);

        Http::fake(['*/generations' => Http::response(
            $this->providerGeneration($generation, 'queued', ['id' => 'gen_abc']),
            202,
        )]);

        $this->runJob($generation)->assertNotReleased();

        $generation->refresh();
        $this->assertSame('gen_abc', $generation->provider_id);
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertNotNull($generation->submitted_at);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pixelforge.test/v1/generations'
            && $request->hasHeader('Authorization', 'Bearer pf_test_key')
            && $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
            && $request->data() === [
                'prompt' => 'A lighthouse at dawn',
                'size' => '1024x1024',
                'quality' => 'medium',
                'metadata' => ['generation_id' => (string) $generation->id],
                'reference_images' => ['https://example.com/a.png'],
            ]);
    }

    public function test_reference_images_are_omitted_when_there_are_none(): void
    {
        $generation = $this->heldGeneration($this->userWithCredits(1), submitted: false);

        Http::fake(['*/generations' => Http::response($this->providerGeneration($generation, 'queued'), 202)]);

        $this->runJob($generation);

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('reference_images', $request->data()));
    }

    /**
     * @return array<string, array{int, string, array<string, string>, int}>
     */
    public static function temporaryRejections(): array
    {
        return [
            'concurrency limit waits the 5 seconds asked for' => [429, 'concurrency_limit_exceeded', ['Retry-After' => '5'], 5],
            'rate limit waits for the rest of the minute' => [429, 'rate_limit_exceeded', ['Retry-After' => '37'], 37],
            'overload waits the 2 seconds asked for' => [503, 'overloaded', ['Retry-After' => '2'], 2],
            'no Retry-After falls back to a default wait' => [503, 'overloaded', [], 5],
            'an absurd Retry-After is capped' => [429, 'rate_limit_exceeded', ['Retry-After' => '86400'], 60],
            'an unreadable 500 is retried, not treated as final' => [500, 'whatever', [], 5],
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('temporaryRejections')]
    public function test_temporary_rejections_wait_and_retry_without_failing_or_charging(int $status, string $code, array $headers, int $delay): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user, submitted: false);
        $this->fakeRejection($status, $code, $headers);

        $this->runJob($generation)->assertReleased($delay);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertNull($generation->provider_id);
        $this->assertSame(1, $this->ledgerCount($generation, LedgerEntryType::Hold));
        $this->assertSame(0, $this->ledgerCount($generation, LedgerEntryType::HoldRelease));
    }

    public function test_a_connection_failure_is_retried_because_the_request_may_have_been_accepted(): void
    {
        $generation = $this->heldGeneration($this->userWithCredits(1), submitted: false);
        Http::fake(fn () => throw new ConnectionException('Operation timed out'));

        $this->runJob($generation)->assertReleased(5);

        $this->assertSame(GenerationStatus::Queued, $generation->refresh()->status);
    }

    public function test_a_success_response_we_cannot_read_is_retried_not_failed(): void
    {
        $generation = $this->heldGeneration($this->userWithCredits(1), submitted: false);
        Http::fake(['*/generations' => Http::response('<html>bad gateway page</html>', 200)]);

        $this->runJob($generation)->assertReleased(5);

        $this->assertSame(GenerationStatus::Queued, $generation->refresh()->status);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function definitiveRejections(): array
    {
        return [
            'content policy' => [400, 'content_policy_violation'],
            'validation' => [422, 'validation_failed'],
            'daily limit' => [429, 'daily_limit_exceeded'],
            'bad api key' => [401, 'invalid_api_key'],
            'idempotency key reused with other parameters' => [409, 'idempotency_key_reused'],
        ];
    }

    #[DataProvider('definitiveRejections')]
    public function test_definitive_rejections_fail_the_generation_and_refund_the_hold(int $status, string $code): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user, submitted: false);
        $this->fakeRejection($status, $code);

        $this->runJob($generation)->assertNotReleased();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame($code, $generation->error_code);
        $this->assertTrue($generation->outcome_confirmed);
        $this->assertStringContainsString('not charged', $generation->failureMessage());
        $this->assertSame(1_000_000_000, $user->refresh()->balance_nanos);
        $this->assertLedgerMatchesBalance($user);
    }

    public function test_it_does_not_call_pixelforge_while_all_concurrency_slots_are_taken(): void
    {
        $user = $this->userWithCredits(1);
        Generation::factory()->for($user)->processing()->count(3)->create();
        $generation = $this->heldGeneration($user, submitted: false);
        Http::fake();

        $this->runJob($generation)->assertReleased(5);

        Http::assertNothingSent();
        $this->assertNull($generation->refresh()->provider_id);
    }

    public function test_finished_generations_do_not_occupy_a_slot(): void
    {
        $user = $this->userWithCredits(1);
        Generation::factory()->for($user)->succeeded()->count(3)->create();
        Generation::factory()->for($user)->processing()->count(2)->create();
        $generation = $this->heldGeneration($user, submitted: false);
        Http::fake(['*/generations' => Http::response($this->providerGeneration($generation, 'queued'), 202)]);

        $this->runJob($generation)->assertNotReleased();

        $this->assertNotNull($generation->refresh()->provider_id);
    }

    public function test_a_job_that_runs_again_after_success_does_not_submit_twice(): void
    {
        $generation = $this->heldGeneration($this->userWithCredits(1));
        Http::fake();

        $this->runJob($generation);

        Http::assertNothingSent();
    }

    public function test_an_idempotent_replay_that_already_succeeded_is_settled_from_the_response(): void
    {
        Queue::fake();
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user, submitted: false);

        // The first attempt timed out but was accepted; this retry gets the finished generation back.
        Http::fake(['*/generations' => Http::response(
            $this->providerGeneration($generation, 'succeeded', ['id' => 'gen_replayed']),
            202,
            ['Idempotent-Replayed' => 'true'],
        )]);

        $this->runJob($generation);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(self::USAGE_PRICE_NANOS, $generation->price_nanos);
        $this->assertSame(1, $this->ledgerCount($generation, LedgerEntryType::Charge));
    }

    public function test_a_webhook_that_already_finished_the_generation_is_not_undone_by_the_create_response(): void
    {
        Queue::fake();
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user, submitted: false);

        Http::fake(function () use ($generation) {
            // The webhook lands while the create request is still in flight.
            $this->deliverWebhook($this->webhookEvent(
                'generation.succeeded',
                $this->providerGeneration($generation, 'succeeded', ['id' => 'gen_raced']),
            ))->assertOk();

            return Http::response($this->providerGeneration($generation, 'queued', ['id' => 'gen_raced']), 202);
        });

        $this->runJob($generation);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame('gen_raced', $generation->provider_id);
        $this->assertSame(1, $this->ledgerCount($generation, LedgerEntryType::Charge));
    }

    public function test_giving_up_after_the_submission_window_refunds_the_hold(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user, submitted: false);

        (new SubmitGeneration($generation->id))->failed(new RuntimeException('Too many attempts.'));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('submission_timeout', $generation->error_code);
        $this->assertFalse($generation->outcome_confirmed, 'Giving up is not proof that nothing was created.');
        $this->assertSame(1_000_000_000, $user->refresh()->balance_nanos);
    }

    public function test_giving_up_never_overrides_a_generation_that_was_accepted(): void
    {
        $generation = $this->heldGeneration($this->userWithCredits(1));

        (new SubmitGeneration($generation->id))->failed(new RuntimeException('Too many attempts.'));

        $this->assertSame(GenerationStatus::Queued, $generation->refresh()->status);
    }

    public function test_the_submission_window_bounds_how_long_it_keeps_trying(): void
    {
        config(['pixelforge.submission_window_minutes' => 15]);
        $this->freezeTime();

        $this->assertEquals(now()->addMinutes(15), (new SubmitGeneration(1))->retryUntil());
    }

    public function test_a_generation_deleted_before_the_job_runs_is_skipped(): void
    {
        Http::fake();

        $this->runJob(Generation::factory()->make(['id' => 12345]));

        Http::assertNothingSent();
    }
}
