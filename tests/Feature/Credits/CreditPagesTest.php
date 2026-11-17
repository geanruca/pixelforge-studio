<?php

namespace Tests\Feature\Credits;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithPixelforge;
use Tests\TestCase;

class CreditPagesTest extends TestCase
{
    use InteractsWithPixelforge;
    use RefreshDatabase;

    public function test_guests_cannot_see_or_buy_credits(): void
    {
        $this->get(route('credits.index'))->assertRedirect(route('login'));
        $this->post(route('credits.store'), ['pack' => 5, 'reference' => (string) Str::uuid()])->assertRedirect(route('login'));
    }

    public function test_a_new_user_starts_with_no_credits(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('credits.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('credits/Index')
                ->where('auth.user.balance_nanos', 0)
                ->where('packs', [5, 10, 25])
                ->has('entries', 0));
    }

    public function test_buying_a_pack_adds_it_to_the_balance(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('credits.index'))
            ->post(route('credits.store'), ['pack' => 10, 'reference' => (string) Str::uuid()])
            ->assertRedirect(route('credits.index'));

        $this->assertSame(10_000_000_000, $user->refresh()->balance_nanos);
        $this->assertLedgerMatchesBalance($user);

        $this->actingAs($user)->get(route('credits.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.balance_nanos', 10_000_000_000)
            ->where('entries.0.type', 'purchase')
            ->where('entries.0.amount_nanos', 10_000_000_000));
    }

    public function test_a_double_submitted_purchase_is_credited_once(): void
    {
        $user = User::factory()->create();
        $purchase = ['pack' => 5, 'reference' => (string) Str::uuid()];

        $this->actingAs($user)->post(route('credits.store'), $purchase);
        $this->actingAs($user)->post(route('credits.store'), $purchase);

        $this->assertSame(5_000_000_000, $user->refresh()->balance_nanos);
        $this->assertSame(1, $user->ledgerEntries()->count());
    }

    public function test_two_separate_purchases_both_count(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('credits.store'), ['pack' => 5, 'reference' => (string) Str::uuid()]);
        $this->actingAs($user)->post(route('credits.store'), ['pack' => 5, 'reference' => (string) Str::uuid()]);

        $this->assertSame(10_000_000_000, $user->refresh()->balance_nanos);
    }

    public function test_a_user_cannot_choose_their_own_amount(): void
    {
        $user = User::factory()->create();

        foreach ([1_000_000, -5, 0, 7, 'lots'] as $pack) {
            $this->actingAs($user)
                ->post(route('credits.store'), ['pack' => $pack, 'reference' => (string) Str::uuid()])
                ->assertSessionHasErrors('pack');
        }

        $this->assertSame(0, $user->refresh()->balance_nanos);
    }

    public function test_a_purchase_needs_a_reference(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('credits.store'), ['pack' => 5])->assertSessionHasErrors('reference');
        $this->actingAs($user)->post(route('credits.store'), ['pack' => 5, 'reference' => 'not-a-uuid'])->assertSessionHasErrors('reference');

        $this->assertSame(0, $user->refresh()->balance_nanos);
    }

    public function test_one_users_purchase_reference_cannot_block_another_users(): void
    {
        $reference = (string) Str::uuid();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($first)->post(route('credits.store'), ['pack' => 5, 'reference' => $reference]);
        $this->actingAs($second)->post(route('credits.store'), ['pack' => 5, 'reference' => $reference]);

        $this->assertSame(5_000_000_000, $first->refresh()->balance_nanos);
        $this->assertSame(5_000_000_000, $second->refresh()->balance_nanos);
    }

    public function test_the_activity_list_shows_only_the_users_own_entries(): void
    {
        $user = $this->userWithCredits(5);
        $this->userWithCredits(25);

        $this->actingAs($user)->get(route('credits.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('entries', 1));
    }
}
