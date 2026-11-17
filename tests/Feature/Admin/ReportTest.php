<?php

namespace Tests\Feature\Admin;

use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithPixelforge;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use InteractsWithPixelforge;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePixelforge();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00', 'UTC'));

        config(['pixelforge.admin_emails' => ['ops@example.com']]);
        $this->admin = User::factory()->create(['email' => 'Ops@Example.com']);
    }

    /**
     * @param  array<string, array{int, string}>  $days  date → [generations, cost_usd]
     */
    private function fakeUsage(array $days): void
    {
        Http::fake(['*/usage*' => Http::response([
            'object' => 'usage',
            'data' => collect($days)->map(fn (array $day, string $date): array => [
                'date' => $date,
                'generations' => $day[0],
                'cost_usd' => $day[1],
            ])->values()->all(),
        ])]);
    }

    private function succeededOn(User $user, string $completedAt, int $costNanos): Generation
    {
        return Generation::factory()->for($user)->succeeded($costNanos)->create([
            'completed_at' => CarbonImmutable::parse($completedAt, 'UTC'),
            'created_at' => CarbonImmutable::parse($completedAt, 'UTC')->subMinute(),
        ]);
    }

    public function test_only_operators_can_open_the_report(): void
    {
        $this->get(route('admin.report'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.report'))->assertForbidden();
    }

    public function test_it_reports_spend_cost_and_profit_per_day_and_user_and_matches_pixelforge(): void
    {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);
        $this->succeededOn($alice, '2026-11-16 09:00:00', 56_500_000);
        $this->succeededOn($alice, '2026-11-16 10:00:00', 20_000_000);
        $this->succeededOn($bob, '2026-11-16 11:00:00', 10_000_000);
        $this->succeededOn($bob, '2026-11-15 23:59:59', 40_000_000);
        Generation::factory()->for($alice)->failed()->create(['completed_at' => '2026-11-16 08:00:00']);
        Generation::factory()->for($alice)->processing()->create();

        $this->fakeUsage([
            '2026-11-15' => [1, '0.040000000'],
            '2026-11-16' => [3, '0.086500000'],
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.report'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/Report')
                ->where('report.pixelforge_available', true)
                ->has('report.days', 2)
                ->where('report.days.0.date', '2026-11-16')
                ->where('report.days.0.generations', 3)
                ->where('report.days.0.provider_cost_nanos', 86_500_000)
                ->where('report.days.0.revenue_nanos', 108_125_000)
                ->where('report.days.0.profit_nanos', 21_625_000)
                ->where('report.days.0.pixelforge_cost_nanos', 86_500_000)
                ->where('report.days.0.matches', true)
                ->has('report.days.0.users', 2)
                ->where('report.days.0.users.0.name', 'Alice')
                ->where('report.days.0.users.0.generations', 2)
                ->where('report.days.0.users.0.provider_cost_nanos', 76_500_000)
                ->where('report.days.0.users.0.profit_nanos', 19_125_000)
                ->where('report.days.0.users.1.name', 'Bob')
                ->where('report.days.1.date', '2026-11-15')
                ->where('report.days.1.matches', true)
                ->where('report.totals.generations', 4)
                ->where('report.totals.provider_cost_nanos', 126_500_000)
                ->where('report.totals.revenue_nanos', 158_125_000)
                ->where('report.totals.profit_nanos', 31_625_000)
                ->where('report.totals.pixelforge_cost_nanos', 126_500_000)
                ->where('report.totals.matches', true));
    }

    public function test_a_generation_is_reported_on_the_utc_day_it_completed_not_the_day_it_was_created(): void
    {
        $user = User::factory()->create();
        Generation::factory()->for($user)->succeeded(10_000_000)->create([
            'created_at' => CarbonImmutable::parse('2026-11-14 23:59:50', 'UTC'),
            'completed_at' => CarbonImmutable::parse('2026-11-15 00:00:10', 'UTC'),
        ]);
        $this->fakeUsage(['2026-11-14' => [0, '0.000000000'], '2026-11-15' => [1, '0.010000000']]);

        $this->actingAs($this->admin)
            ->get(route('admin.report'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('report.days', 1)
                ->where('report.days.0.date', '2026-11-15')
                ->where('report.days.0.matches', true));
    }

    public function test_a_day_that_differs_from_pixelforge_is_flagged(): void
    {
        $this->succeededOn(User::factory()->create(), '2026-11-16 09:00:00', 56_500_000);
        $this->fakeUsage([
            '2026-11-15' => [1, '0.020000000'],
            '2026-11-16' => [1, '0.056500001'],
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.report'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Off by a single nanodollar is still a mismatch.
                ->where('report.days.0.date', '2026-11-16')
                ->where('report.days.0.matches', false)
                // Billed by Pixelforge but unknown to us: shown, and flagged.
                ->where('report.days.1.date', '2026-11-15')
                ->where('report.days.1.generations', 0)
                ->where('report.days.1.pixelforge_cost_nanos', 20_000_000)
                ->where('report.days.1.matches', false)
                ->where('report.totals.matches', false));
    }

    public function test_the_report_still_shows_our_numbers_when_pixelforge_usage_is_down(): void
    {
        $this->succeededOn(User::factory()->create(), '2026-11-16 09:00:00', 56_500_000);
        Http::fake(['*/usage*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Busy']], 503)]);

        $this->actingAs($this->admin)
            ->get(route('admin.report'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report.pixelforge_available', false)
                ->where('report.days.0.provider_cost_nanos', 56_500_000)
                ->where('report.days.0.pixelforge_cost_nanos', null)
                ->where('report.days.0.matches', null));
    }

    public function test_it_asks_pixelforge_for_the_same_range_it_shows(): void
    {
        $this->fakeUsage([]);

        $this->actingAs($this->admin)->get(route('admin.report', ['start_date' => '2026-10-01', 'end_date' => '2026-10-31']))->assertOk();

        Http::assertSent(fn (Request $request): bool => $request['start_date'] === '2026-10-01' && $request['end_date'] === '2026-10-31');
    }

    public function test_the_default_range_is_the_last_seven_days(): void
    {
        $this->fakeUsage([]);

        $this->actingAs($this->admin)
            ->get(route('admin.report'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report.start_date', '2026-11-10')
                ->where('report.end_date', '2026-11-16')
                ->has('report.days', 0));
    }

    public function test_a_range_longer_than_pixelforge_allows_is_clamped(): void
    {
        $this->fakeUsage([]);

        $this->actingAs($this->admin)
            ->get(route('admin.report', ['start_date' => '2025-01-01', 'end_date' => '2026-11-16']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report.start_date', '2026-08-17')
                ->where('report.end_date', '2026-11-16'));
    }

    public function test_an_inverted_or_malformed_range_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.report', ['start_date' => '2026-11-16', 'end_date' => '2026-11-01']))
            ->assertSessionHasErrors('start_date');

        $this->actingAs($this->admin)
            ->get(route('admin.report', ['start_date' => 'yesterday', 'end_date' => '2026-11-01']))
            ->assertSessionHasErrors('start_date');
    }
}
