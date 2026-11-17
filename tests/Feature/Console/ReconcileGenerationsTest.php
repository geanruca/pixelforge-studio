<?php

namespace Tests\Feature\Console;

use App\Console\Commands\ReconcileGenerations;
use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Services\Generations\GenerationSynchronizer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithPixelforge;
use Tests\TestCase;

class ReconcileGenerationsTest extends TestCase
{
    use InteractsWithPixelforge;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePixelforge();
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_a_generation_whose_webhooks_never_arrived_is_completed_by_polling(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user, ['last_synced_at' => now()->subMinutes(5)]);
        Http::fake(["*/generations/{$generation->provider_id}" => Http::response($this->providerGeneration($generation, 'succeeded'))]);

        $this->artisan(ReconcileGenerations::class)->assertSuccessful();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(self::USAGE_PRICE_NANOS, $generation->price_nanos);
        $this->assertSame(1_000_000_000 - self::USAGE_PRICE_NANOS, $user->refresh()->balance_nanos);
    }

    public function test_polling_after_the_webhook_already_applied_does_not_charge_twice(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user, ['last_synced_at' => now()->subMinutes(5)]);
        Http::fake(["*/generations/{$generation->provider_id}" => Http::response($this->providerGeneration($generation, 'succeeded'))]);

        $this->artisan(ReconcileGenerations::class);
        $this->deliverWebhook($this->webhookEvent('generation.succeeded', $this->providerGeneration($generation, 'succeeded')))
            ->assertJson(['outcome' => 'stale']);

        $this->assertSame(1_000_000_000 - self::USAGE_PRICE_NANOS, $user->refresh()->balance_nanos);
        $this->assertLedgerMatchesBalance($user);
    }

    public function test_it_leaves_recent_finished_and_unsubmitted_generations_alone(): void
    {
        $user = $this->userWithCredits(1);
        $this->heldGeneration($user);
        $this->heldGeneration($user, submitted: false);
        Generation::factory()->for($user)->succeeded()->create(['last_synced_at' => now()->subHour(), 'image_path' => 'x.png']);
        Http::fake();

        $this->artisan(ReconcileGenerations::class)->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_one_unreachable_generation_does_not_stop_the_others(): void
    {
        $user = $this->userWithCredits(1);
        $first = $this->heldGeneration($user, ['last_synced_at' => now()->subMinutes(9)]);
        $second = $this->heldGeneration($user, ['last_synced_at' => now()->subMinutes(5)]);
        Http::fake([
            "*/generations/{$first->provider_id}" => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Busy']], 503),
            "*/generations/{$second->provider_id}" => Http::response($this->providerGeneration($second, 'failed')),
        ]);

        $this->artisan(ReconcileGenerations::class)->assertSuccessful();

        $this->assertSame(GenerationStatus::Queued, $first->refresh()->status);
        $this->assertSame(GenerationStatus::Failed, $second->refresh()->status);
        $this->assertSame(1_000_000_000 - $first->reserved_nanos, $user->refresh()->balance_nanos);
    }

    public function test_it_stops_polling_as_soon_as_the_rate_limit_is_hit(): void
    {
        $user = $this->userWithCredits(1);
        $this->heldGeneration($user, ['last_synced_at' => now()->subMinutes(9)]);
        $this->heldGeneration($user, ['last_synced_at' => now()->subMinutes(5)]);
        Http::fake(['*' => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down']], 429)]);

        $this->artisan(ReconcileGenerations::class)->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_a_generation_pixelforge_has_no_record_of_is_failed_and_refunded(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user, ['last_synced_at' => now()->subMinutes(5)]);
        Http::fake(['*' => Http::response(['error' => ['code' => 'not_found', 'message' => 'No such generation']], 404)]);

        $this->artisan(ReconcileGenerations::class)->assertSuccessful();

        $this->assertSame(GenerationStatus::Failed, $generation->refresh()->status);
        $this->assertSame(1_000_000_000, $user->refresh()->balance_nanos);
    }

    public function test_it_retries_image_copies_that_never_landed_while_the_url_still_works(): void
    {
        $missing = Generation::factory()->succeeded()->create(['completed_at' => now()->subHour()]);
        Generation::factory()->succeeded()->create(['completed_at' => now()->subHour(), 'image_path' => 'stored.png']);
        Generation::factory()->succeeded()->create(['completed_at' => now()->subDays(2), 'image_url_expires_at' => now()->subDay()]);

        $this->artisan(ReconcileGenerations::class);

        Queue::assertPushed(StoreGenerationImage::class, 1);
        Queue::assertPushed(StoreGenerationImage::class, fn (StoreGenerationImage $job): bool => $job->generationId === $missing->id);
    }

    public function test_a_generation_whose_submission_job_was_lost_is_closed_and_refunded(): void
    {
        $user = $this->userWithCredits(1);
        $lost = $this->heldGeneration($user, ['created_at' => now()->subMinutes(21)], submitted: false);
        $stillTrying = $this->heldGeneration($user, ['created_at' => now()->subMinutes(10)], submitted: false);
        Http::fake();

        $this->artisan(ReconcileGenerations::class)->assertSuccessful();

        $lost->refresh();
        $this->assertSame(GenerationStatus::Failed, $lost->status);
        $this->assertSame('submission_timeout', $lost->error_code);
        $this->assertFalse($lost->outcome_confirmed);
        $this->assertSame(GenerationStatus::Queued, $stillTrying->refresh()->status);
        $this->assertSame(1_000_000_000 - $stillTrying->reserved_nanos, $user->refresh()->balance_nanos);
        $this->assertLedgerMatchesBalance($user);

        // Running again changes nothing.
        $this->artisan(ReconcileGenerations::class)->assertSuccessful();
        $this->assertSame(1_000_000_000 - $stillTrying->reserved_nanos, $user->refresh()->balance_nanos);
    }

    public function test_a_generation_we_gave_up_on_but_pixelforge_accepted_is_still_polled_and_billed(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user, submitted: false);
        app(GenerationSynchronizer::class)->failLocally($generation, 'submission_timeout', 'Too busy.', confirmed: false);

        // A late `processing` webhook reveals that Pixelforge had accepted it…
        $this->deliverWebhook($this->webhookEvent(
            'generation.processing',
            $this->providerGeneration($generation, 'processing', ['id' => 'gen_late']),
        ))->assertOk();
        $this->assertSame('gen_late', $generation->refresh()->provider_id);

        // …and its final webhook never arrives. Polling must still find it.
        $this->travel(2)->minutes();
        Http::fake(['*/generations/gen_late' => Http::response($this->providerGeneration($generation, 'succeeded'))]);
        $this->artisan(ReconcileGenerations::class)->assertSuccessful();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(1_000_000_000 - self::USAGE_PRICE_NANOS, $user->refresh()->balance_nanos);
        $this->assertLedgerMatchesBalance($user);
    }

    public function test_generations_that_keep_failing_to_sync_do_not_starve_the_others(): void
    {
        $user = $this->userWithCredits(1);
        $stuck = $this->heldGeneration($user, ['last_synced_at' => now()->subMinutes(9)]);
        $waiting = $this->heldGeneration($user, ['last_synced_at' => now()->subMinutes(5)]);
        Http::fake([
            "*/generations/{$stuck->provider_id}" => Http::response('<html>broken</html>', 200),
            "*/generations/{$waiting->provider_id}" => Http::response($this->providerGeneration($waiting, 'succeeded')),
        ]);

        // Only one per run: the stuck one goes first, fails, and must then queue behind the other.
        $this->artisan(ReconcileGenerations::class, ['--limit' => 1])->assertSuccessful();
        $this->assertSame(GenerationStatus::Queued, $waiting->refresh()->status);

        $this->artisan(ReconcileGenerations::class, ['--limit' => 1])->assertSuccessful();
        $this->assertSame(GenerationStatus::Succeeded, $waiting->refresh()->status);
    }

    public function test_it_is_scheduled_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'generations:reconcile'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }

    public function test_the_image_job_copies_the_png_to_our_own_disk(): void
    {
        Storage::fake('local');
        $generation = Generation::factory()->succeeded()->create();
        Http::fake([$generation->image_url => Http::response('png-bytes')]);

        (new StoreGenerationImage($generation->id))->handle();

        $path = "generations/{$generation->user_id}/{$generation->id}.png";
        $this->assertSame($path, $generation->refresh()->image_path);
        Storage::disk('local')->assertExists($path);
        $this->assertSame('png-bytes', Storage::disk('local')->get($path));
    }

    public function test_the_image_job_does_nothing_once_the_url_expired_or_the_copy_exists(): void
    {
        Storage::fake('local');
        Http::fake();
        $expired = Generation::factory()->succeeded()->create(['image_url_expires_at' => now()->subMinute()]);
        $stored = Generation::factory()->succeeded()->create(['image_path' => 'already.png']);

        (new StoreGenerationImage($expired->id))->handle();
        (new StoreGenerationImage($stored->id))->handle();
        (new StoreGenerationImage(999_999))->handle();

        Http::assertNothingSent();
        $this->assertNull($expired->refresh()->image_path);
    }

    public function test_the_image_job_fails_loudly_so_it_is_retried_when_the_download_fails(): void
    {
        Storage::fake('local');
        $generation = Generation::factory()->succeeded()->create();
        Http::fake([$generation->image_url => Http::response('gone', 500)]);

        $this->expectException(RequestException::class);

        (new StoreGenerationImage($generation->id))->handle();
    }
}
