<?php

namespace Tests\Feature\Api;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithPixelforge;
use Tests\TestCase;

class GenerationApiTest extends TestCase
{
    use InteractsWithPixelforge;
    use RefreshDatabase;

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
    private function payload(array $overrides = []): array
    {
        return ['prompt' => 'A lighthouse at dawn', 'size' => '1024x1024', 'quality' => 'medium', ...$overrides];
    }

    public function test_every_endpoint_requires_a_token(): void
    {
        foreach ([
            ['GET', '/api/v1/balance'],
            ['POST', '/api/v1/estimates'],
            ['GET', '/api/v1/generations'],
            ['POST', '/api/v1/generations'],
            ['GET', '/api/v1/generations/1'],
        ] as [$method, $uri]) {
            $this->json($method, $uri)
                ->assertUnauthorized()
                ->assertExactJson(['error' => [
                    'type' => 'authentication_error',
                    'code' => 'invalid_api_token',
                    'message' => 'The API token is missing, malformed or revoked.',
                ]]);
        }
    }

    public function test_a_real_bearer_token_authenticates_and_a_revoked_one_does_not(): void
    {
        $user = $this->userWithCredits(1);
        $token = $user->createToken('CLI');

        $this->withToken($token->plainTextToken)->getJson('/api/v1/balance')
            ->assertOk()
            ->assertExactJson(['object' => 'balance', 'currency' => 'usd', 'available_usd' => '1.000000000']);

        $token->accessToken->delete();
        $this->app['auth']->forgetGuards();

        $this->withToken($token->plainTextToken)->getJson('/api/v1/balance')->assertUnauthorized();
    }

    public function test_the_estimate_returns_exact_decimal_strings(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/estimates', $this->payload())
            ->assertOk()
            ->assertExactJson([
                'object' => 'estimate',
                'currency' => 'usd',
                'minimum_price_usd' => '0.058150000',
                'expected_price_usd' => '0.072218750',
                'maximum_price_usd' => '0.086287500',
            ]);

        $this->assertSame(0, Generation::query()->count());
    }

    public function test_creating_a_generation_holds_credits_and_queues_it(): void
    {
        $user = $this->userWithCredits(1);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/generations', $this->payload(['reference_images' => ['https://example.com/a.png']]))
            ->assertAccepted()
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJson([
                'object' => 'generation',
                'status' => 'queued',
                'prompt' => 'A lighthouse at dawn',
                'size' => '1024x1024',
                'quality' => 'medium',
                'reference_images' => ['https://example.com/a.png'],
                'price_usd' => null,
                'error' => null,
                'image_url' => null,
            ]);

        $generation = Generation::query()->sole();
        $this->assertSame($generation->id, $response->json('id'));
        $this->assertSame('0.095850000', $response->json('maximum_price_usd'));
        $this->assertSame(1_000_000_000 - $generation->reserved_nanos, $user->refresh()->balance_nanos);
        $this->assertLedgerMatchesBalance($user);
        Queue::assertPushed(SubmitGeneration::class, 1);
    }

    public function test_repeating_a_request_with_the_same_idempotency_key_creates_and_holds_once(): void
    {
        $user = $this->userWithCredits(1);
        Sanctum::actingAs($user);
        $headers = ['Idempotency-Key' => 'order-42'];

        $first = $this->postJson('/api/v1/generations', $this->payload(), $headers)->assertAccepted();
        $second = $this->postJson('/api/v1/generations', $this->payload(), $headers)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, Generation::query()->count());
        $this->assertSame(1_000_000_000 - Generation::query()->sole()->reserved_nanos, $user->refresh()->balance_nanos);
        Queue::assertPushed(SubmitGeneration::class, 1);
    }

    public function test_a_replay_returns_the_current_state_of_the_generation(): void
    {
        $user = $this->userWithCredits(1);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/generations', $this->payload(), ['Idempotency-Key' => 'order-42']);
        Generation::query()->sole()->update(['status' => GenerationStatus::Failed, 'error_code' => 'generation_failed']);

        $this->postJson('/api/v1/generations', $this->payload(), ['Idempotency-Key' => 'order-42'])
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error.code', 'generation_failed');
    }

    public function test_an_idempotency_key_reused_with_other_parameters_is_a_conflict(): void
    {
        $user = $this->userWithCredits(1);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/generations', $this->payload(), ['Idempotency-Key' => 'order-42'])->assertAccepted();

        $this->postJson('/api/v1/generations', $this->payload(['quality' => 'high']), ['Idempotency-Key' => 'order-42'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_key_reused');

        $this->assertSame(1, Generation::query()->count());
    }

    public function test_idempotency_keys_are_scoped_to_the_account(): void
    {
        Sanctum::actingAs($this->userWithCredits(1));
        $this->postJson('/api/v1/generations', $this->payload(), ['Idempotency-Key' => 'order-42'])->assertAccepted();

        Sanctum::actingAs($this->userWithCredits(1));
        $this->postJson('/api/v1/generations', $this->payload(), ['Idempotency-Key' => 'order-42'])->assertAccepted();

        $this->assertSame(2, Generation::query()->count());
    }

    public function test_without_a_key_every_request_creates_a_generation(): void
    {
        Sanctum::actingAs($this->userWithCredits(1));

        $this->postJson('/api/v1/generations', $this->payload())->assertAccepted();
        $this->postJson('/api/v1/generations', $this->payload())->assertAccepted();

        $this->assertSame(2, Generation::query()->count());
    }

    public function test_an_idempotency_key_longer_than_255_characters_is_rejected(): void
    {
        Sanctum::actingAs($this->userWithCredits(1));

        $this->postJson('/api/v1/generations', $this->payload(), ['Idempotency-Key' => str_repeat('k', 256)])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.param', 'Idempotency-Key');

        $this->assertSame(0, Generation::query()->count());
    }

    public function test_not_enough_credits_is_a_402_with_the_exact_amounts_and_creates_nothing(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/generations', $this->payload())
            ->assertStatus(402)
            ->assertExactJson(['error' => [
                'type' => 'invalid_request_error',
                'code' => 'insufficient_credits',
                'message' => 'The available credits do not cover the maximum price of this generation.',
                'required_usd' => '0.086287500',
                'available_usd' => '0.000000000',
            ]]);

        $this->assertSame(0, Generation::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_validation_errors_name_the_parameter(): void
    {
        Sanctum::actingAs($this->userWithCredits(1));

        $this->postJson('/api/v1/generations', $this->payload(['size' => '512x512']))
            ->assertUnprocessable()
            ->assertJsonPath('error.type', 'invalid_request_error')
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.param', 'size')
            ->assertJsonStructure(['error' => ['errors' => ['size']]]);
    }

    public function test_pricing_being_down_is_a_503_and_nothing_is_created_or_held(): void
    {
        Http::swap(new HttpFactory);
        Http::fake(['*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Busy']], 503)]);
        $user = $this->userWithCredits(1);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/generations', $this->payload())
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'pricing_unavailable');

        $this->assertSame(0, Generation::query()->count());
        $this->assertSame(1_000_000_000, $user->refresh()->balance_nanos);
    }

    public function test_a_succeeded_generation_shows_the_exact_price_and_a_signed_image_link(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('generations/1/1.png', 'png-bytes');
        $user = User::factory()->create();
        $generation = Generation::factory()->for($user)->succeeded(56_500_000)->create(['image_path' => 'generations/1/1.png']);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/generations/{$generation->id}")
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('price_usd', '0.070625000')
            ->assertJsonPath('error', null);

        // The link works without a session or token, which is the point of signing it.
        $this->app['auth']->forgetGuards();
        $image = $this->get($response->json('image_url'))->assertOk();
        $this->assertSame('png-bytes', $image->streamedContent());
    }

    public function test_an_image_link_that_was_tampered_with_or_expired_is_refused(): void
    {
        $generation = Generation::factory()->succeeded()->create(['image_path' => 'x.png']);
        $other = Generation::factory()->succeeded()->create(['image_path' => 'y.png']);
        Sanctum::actingAs($generation->user);
        $url = $this->getJson("/api/v1/generations/{$generation->id}")->json('image_url');

        $this->get(str_replace("/generations/{$generation->id}/", "/generations/{$other->id}/", $url))->assertForbidden();
        $this->get(route('generations.image.signed', $generation))->assertForbidden();

        $this->travel(61)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_a_failed_generation_carries_a_code_and_a_message_and_no_price(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->for($user)->failed('content_policy_violation')->create();
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/generations/{$generation->id}")
            ->assertOk()
            ->assertJsonPath('price_usd', null)
            ->assertJsonPath('image_url', null)
            ->assertJsonPath('error.code', 'content_policy_violation')
            ->assertJsonPath('error.message', 'This prompt was rejected by the content policy. You were not charged.');
    }

    public function test_another_accounts_generation_is_a_404_not_a_403(): void
    {
        $foreign = Generation::factory()->succeeded()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/generations/{$foreign->id}")->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->getJson('/api/v1/generations/999999')->assertNotFound();
        $this->getJson('/api/v1/generations/not-a-number')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_the_list_is_newest_first_filterable_and_cursor_paginated(): void
    {
        $user = User::factory()->create();
        $generations = Generation::factory()->for($user)->succeeded()->count(3)->create();
        Generation::factory()->for($user)->failed()->create();
        Generation::factory()->succeeded()->create();
        Sanctum::actingAs($user);

        $first = $this->getJson('/api/v1/generations?status=succeeded&limit=2')
            ->assertOk()
            ->assertJsonPath('object', 'list')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $generations[2]->id)
            ->assertJsonPath('data.1.id', $generations[1]->id)
            ->assertJsonPath('has_more', true);

        $this->getJson('/api/v1/generations?status=succeeded&limit=2&cursor='.$first->json('next_cursor'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $generations[0]->id)
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('next_cursor', null);
    }

    public function test_the_list_validates_its_filters(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/generations?limit=101')->assertUnprocessable()->assertJsonPath('error.param', 'limit');
        $this->getJson('/api/v1/generations?status=done')->assertUnprocessable()->assertJsonPath('error.param', 'status');
    }

    public function test_requests_are_rate_limited_per_account(): void
    {
        Sanctum::actingAs(User::factory()->create());

        for ($request = 0; $request < 60; $request++) {
            $this->getJson('/api/v1/balance')->assertOk();
        }

        $this->getJson('/api/v1/balance')
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'rate_limit_exceeded')
            ->assertHeader('Retry-After');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/balance')->assertOk();
    }
}
