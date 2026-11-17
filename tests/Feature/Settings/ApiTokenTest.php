<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // As if the user had just re-entered their password.
        $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_minting_a_token_asks_for_the_password_again(): void
    {
        $user = User::factory()->create();
        $this->withSession(['auth.password_confirmed_at' => time() - 100_000]);

        $this->actingAs($user)->get(route('api-tokens.index'))->assertRedirect(route('password.confirm'));
        $this->actingAs($user)->post(route('api-tokens.store'), ['name' => 'CLI'])->assertRedirect(route('password.confirm'));

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_tokens_expire(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('api-tokens.store'), ['name' => 'CLI']);
        $plain = $user->createToken('Direct', expiresAt: now()->addDays(90))->plainTextToken;

        $this->assertEqualsWithDelta(now()->addDays(90)->getTimestamp(), $user->tokens()->oldest('id')->firstOrFail()->expires_at?->getTimestamp(), 5);

        $this->travel(91)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/balance')->assertUnauthorized();
    }

    public function test_guests_cannot_manage_tokens(): void
    {
        $this->get(route('api-tokens.index'))->assertRedirect(route('login'));
        $this->post(route('api-tokens.store'), ['name' => 'CLI'])->assertRedirect(route('login'));
    }

    public function test_creating_a_token_shows_it_once_and_stores_only_its_hash(): void
    {
        $user = User::factory()->create();

        $created = $this->actingAs($user)
            ->followingRedirects()
            ->post(route('api-tokens.store'), ['name' => 'Claude Code'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/ApiTokens')
                ->where('tokens.0.name', 'Claude Code')
                ->where('tokens.0.last_used_at', null)
                ->where('apiUrl', url('api/v1'))
                ->where('mcpUrl', url('/mcp'))
                ->missing('tokens.0.token'));

        $plain = data_get($created->inertiaPage(), 'flash.apiToken');
        $stored = $user->tokens()->sole();

        $this->assertIsString($plain);
        $this->assertSame(hash('sha256', explode('|', $plain)[1]), $stored->token);

        // A second visit no longer carries the plain token.
        $again = $this->actingAs($user)->get(route('api-tokens.index'));
        $this->assertNull(data_get($again->inertiaPage(), 'flash.apiToken'));
    }

    public function test_the_created_token_works_against_the_api(): void
    {
        $user = User::factory()->create();
        $plain = $user->createToken('CLI')->plainTextToken;

        $this->withToken($plain)->getJson('/api/v1/balance')->assertOk();
    }

    public function test_a_token_needs_a_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('api-tokens.store'), ['name' => ''])->assertSessionHasErrors('name');
        $this->actingAs($user)->post(route('api-tokens.store'), ['name' => str_repeat('n', 61)])->assertSessionHasErrors('name');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_revoking_a_token_stops_it_working(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('CLI');

        $this->actingAs($user)->delete(route('api-tokens.destroy', $token->accessToken->id))->assertRedirect(route('api-tokens.index'));

        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken)->getJson('/api/v1/balance')->assertUnauthorized();
    }

    public function test_nobody_can_revoke_another_users_token(): void
    {
        $owner = User::factory()->create();
        $token = $owner->createToken('CLI');

        $this->actingAs(User::factory()->create())->delete(route('api-tokens.destroy', $token->accessToken->id))->assertNotFound();

        $this->assertSame(1, $owner->tokens()->count());
    }
}
