<?php

namespace Tests\Feature\Generations;

use App\Jobs\StoreGenerationImage;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Generations\SlotAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithPixelforge;
use Tests\TestCase;

/**
 * The provider's three concurrency slots are shared by every user, so they
 * are handed out round-robin by user rather than first come, first served.
 */
class SlotAllocatorTest extends TestCase
{
    use InteractsWithPixelforge;
    use RefreshDatabase;

    private SlotAllocator $slots;

    protected function setUp(): void
    {
        parent::setUp();

        $this->slots = app(SlotAllocator::class);
    }

    /**
     * @return list<Generation>
     */
    private function waiting(User $user, int $count): array
    {
        return Generation::factory()->for($user)->count($count)->create()->all();
    }

    public function test_one_user_with_many_requests_does_not_make_everyone_else_wait_behind_all_of_them(): void
    {
        [$greedy, $second, $third] = User::factory()->count(3)->create()->all();
        $bulk = $this->waiting($greedy, 5);
        [$b] = $this->waiting($second, 1);
        [$c] = $this->waiting($third, 1);

        // First come, first served would be the five bulk requests, then b, then c.
        $this->assertSame(
            [$bulk[0]->id, $b->id, $c->id, $bulk[1]->id, $bulk[2]->id, $bulk[3]->id, $bulk[4]->id],
            $this->slots->line(),
        );
    }

    public function test_images_a_user_already_has_running_count_as_turns_taken(): void
    {
        [$busy, $idle] = User::factory()->count(2)->create()->all();
        Generation::factory()->for($busy)->processing()->count(2)->create();
        [$more] = $this->waiting($busy, 1);
        [$first, $second] = $this->waiting($idle, 2);

        $this->assertSame([$first->id, $second->id, $more->id], $this->slots->line());
    }

    public function test_within_the_same_turn_whoever_asked_first_goes_first(): void
    {
        [$early, $late] = User::factory()->count(2)->create()->all();
        [$a] = $this->waiting($early, 1);
        [$b] = $this->waiting($late, 1);

        $this->assertSame([$a->id, $b->id], $this->slots->line());
        $this->assertSame([$a->id => 1, $b->id => 2], $this->slots->positions());
    }

    public function test_only_as_many_generations_as_there_are_free_slots_may_start(): void
    {
        $users = User::factory()->count(5)->create();
        $generations = $users->map(fn (User $user): Generation => $this->waiting($user, 1)[0]);

        $this->assertTrue($this->slots->claim($generations[0]));
        $this->assertTrue($this->slots->claim($generations[1]));
        $this->assertTrue($this->slots->claim($generations[2]));
        $this->assertFalse($this->slots->claim($generations[3]), 'All three slots are taken.');

        $this->slots->release($generations[1]);

        $this->assertFalse($this->slots->claim($generations[4]), 'The freed slot belongs to whoever is next in line.');
        $this->assertTrue($this->slots->claim($generations[1]->refresh()));
    }

    public function test_a_generation_cannot_jump_the_line_even_when_a_slot_is_free(): void
    {
        [$greedy, $other] = User::factory()->count(2)->create()->all();
        Generation::factory()->for($greedy)->processing()->count(2)->create();
        [$third] = $this->waiting($greedy, 1);
        [$fair] = $this->waiting($other, 1);

        $this->assertFalse($this->slots->claim($third), 'One slot is free, but it is the other user\'s turn.');
        $this->assertTrue($this->slots->claim($fair));
    }

    public function test_a_slot_claimed_by_a_worker_that_crashed_is_freed_after_a_while(): void
    {
        $users = User::factory()->count(4)->create();
        $generations = $users->map(fn (User $user): Generation => $this->waiting($user, 1)[0]);
        foreach ($generations->take(3) as $generation) {
            $this->assertTrue($this->slots->claim($generation));
        }
        $this->assertFalse($this->slots->claim($generations[3]));

        // The workers died mid-submission and never reported back.
        $this->travel(91)->seconds();

        $this->assertTrue($this->slots->claim($generations[0]->refresh()), 'Expired claims rejoin the line, ahead by age.');
        $this->assertSame(4, count($this->slots->line()) + 1);
    }

    public function test_finished_generations_free_their_slot(): void
    {
        $user = User::factory()->create();
        Generation::factory()->for($user)->succeeded()->count(3)->create();
        Generation::factory()->for($user)->failed()->count(3)->create();
        [$next] = $this->waiting(User::factory()->create(), 1);

        $this->assertTrue($this->slots->claim($next));
    }

    public function test_the_submission_job_waits_when_it_is_not_its_turn_and_gives_the_slot_back_when_refused(): void
    {
        $this->configurePixelforge();
        [$greedy, $other] = User::factory()->count(2)->create()->all();
        Generation::factory()->for($greedy)->processing()->count(2)->create();
        [$third] = $this->waiting($greedy, 1);
        [$fair] = $this->waiting($other, 1);
        Http::fake(['*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Busy']], 503, ['Retry-After' => '2'])]);

        $job = (new SubmitGeneration($third->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);
        $job->assertReleased(3);
        Http::assertNothingSent();

        $job = (new SubmitGeneration($fair->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);
        $job->assertReleased(2);
        Http::assertSentCount(1);
        $this->assertNull($fair->refresh()->slot_claimed_at, 'A refused submission must not keep occupying a slot.');
    }

    public function test_the_history_page_shows_the_fair_position_not_the_arrival_order(): void
    {
        [$greedy, $other] = User::factory()->count(2)->create()->all();
        $this->waiting($greedy, 4);
        [$mine] = $this->waiting($other, 1);

        $this->actingAs($other)
            ->get(route('generations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where("queuePositions.{$mine->id}", 2));
    }

    public function test_submissions_and_image_copies_run_on_separate_queues(): void
    {
        $this->assertSame('submissions', (new SubmitGeneration(1))->queue);
        $this->assertSame('images', (new StoreGenerationImage(1))->queue);
    }
}
