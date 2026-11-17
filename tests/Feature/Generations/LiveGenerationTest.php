<?php

namespace Tests\Feature\Generations;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithPixelforge;
use Tests\TestCase;

/**
 * What the history page needs to narrate a generation while the user waits,
 * and the one detail it asks them for meanwhile: a name for the image.
 */
class LiveGenerationTest extends TestCase
{
    use InteractsWithPixelforge;
    use RefreshDatabase;

    public function test_submitting_lands_on_history_with_the_new_generation_pointed_out(): void
    {
        $this->configurePixelforge();
        Queue::fake();
        Http::fake(['*/pricing' => Http::response($this->pricingPayload())]);
        $user = $this->userWithCredits(1);

        $this->actingAs($user)
            ->followingRedirects()
            ->post(route('generations.store'), ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('generations/Index')
                ->where('justCreated', Generation::query()->sole()->id)
                ->where('generations.data.0.status', 'queued'));

        // Only once: a reload no longer points at it.
        $this->actingAs($user)->get(route('generations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('justCreated', null));
    }

    public function test_a_waiting_generation_knows_its_place_in_the_line_shared_by_all_users(): void
    {
        $user = User::factory()->create();
        Generation::factory()->create();
        Generation::factory()->processing()->create();
        Generation::factory()->create();
        $mine = Generation::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where("queuePositions.{$mine->id}", 3)
                ->where('generations.data.0.awaiting_slot', true)
                ->where('generations.data.0.progress.accepted_at', null));
    }

    public function test_a_generation_being_retried_reports_which_attempt_it_is_on(): void
    {
        $user = User::factory()->create();
        Generation::factory()->for($user)->create(['submission_failures' => 2]);

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('generations.data.0.progress.retry_attempt', 2)
                ->where('generations.data.0.progress.max_attempts', SubmitGeneration::MAX_SUBMISSION_FAILURES));
    }

    public function test_the_timeline_carries_when_each_step_happened(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->for($user)->processing()->create();

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('generations.data.0.awaiting_slot', false)
                ->where('generations.data.0.progress.accepted_at', $generation->submitted_at?->toIso8601String())
                ->where('generations.data.0.progress.started_at', $generation->started_at?->toIso8601String())
                ->where('queuePositions', []));
    }

    public function test_the_user_can_name_an_image_while_it_generates_and_rename_or_clear_it_later(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->for($user)->processing()->create();

        $this->actingAs($user)->from(route('generations.index'))
            ->patch(route('generations.update', $generation), ['title' => '  Lighthouse, take one  '])
            ->assertRedirect(route('generations.index'));
        $this->assertSame('Lighthouse, take one', $generation->refresh()->title);

        $this->actingAs($user)->patch(route('generations.update', $generation), ['title' => '']);
        $this->assertNull($generation->refresh()->title);

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('generations.data.0.title', null));
    }

    public function test_a_name_is_validated_and_only_the_owner_can_set_it(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->for($user)->create();

        $this->actingAs($user)->patch(route('generations.update', $generation), ['title' => str_repeat('n', 81)])->assertSessionHasErrors('title');
        $this->actingAs($user)->patch(route('generations.update', $generation), [])->assertSessionHasErrors('title');
        $this->actingAs(User::factory()->create())->patch(route('generations.update', $generation), ['title' => 'Mine now'])->assertForbidden();
        auth()->logout();
        $this->patch(route('generations.update', $generation), ['title' => 'Anonymous'])->assertRedirect(route('login'));

        $this->assertNull($generation->refresh()->title);
    }

    public function test_naming_an_image_never_touches_its_status_or_money(): void
    {
        $user = $this->userWithCredits(1);
        $generation = $this->heldGeneration($user);

        $this->actingAs($user)->patch(route('generations.update', $generation), [
            'title' => 'Named',
            'status' => 'succeeded',
            'price_nanos' => 0,
            'reserved_nanos' => 0,
        ]);

        $generation->refresh();
        $this->assertSame('Named', $generation->title);
        $this->assertSame('queued', $generation->status->value);
        $this->assertNull($generation->price_nanos);
        $this->assertSame(86_375_000, $generation->reserved_nanos);
    }
}
