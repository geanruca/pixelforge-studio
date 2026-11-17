<?php

namespace Tests\Feature\Resilience;

use App\Contracts\ImageProvider;
use App\Exceptions\PixelforgeException;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\ProviderGeneration;
use App\Services\Resilience\CircuitBreaker;
use App\Services\Resilience\ResilientImageProvider;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\InteractsWithPixelforge;
use Tests\TestCase;

class ResilientImageProviderTest extends TestCase
{
    use InteractsWithPixelforge;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePixelforge();
        config(['pixelforge.circuit_breaker' => ['failure_threshold' => 3, 'cooldown_seconds' => 30]]);
        Http::preventStrayRequests();
        Http::fake(fn () => ($this->answer)());
    }

    /** What the fake Pixelforge answers to the next requests. */
    private Closure $answer;

    private function pixelforgeAnswers(int $status, ?string $code = null): void
    {
        $this->answer = fn () => $status === 200
            ? Http::response($this->pricingPayload())
            : Http::response(['error' => ['code' => $code, 'message' => 'No.']], $status);
    }

    private function callAndCatch(ImageProvider $provider): ?PixelforgeException
    {
        try {
            $provider->pricing();

            return null;
        } catch (PixelforgeException $exception) {
            return $exception;
        }
    }

    public function test_the_application_resolves_the_contract_to_pixelforge_behind_the_breaker(): void
    {
        $provider = app(ImageProvider::class);

        $this->assertInstanceOf(ResilientImageProvider::class, $provider);
        $this->assertSame(PixelforgeClient::NAME, $provider->name());
    }

    public function test_the_circuit_opens_after_consecutive_failures_and_then_makes_no_calls(): void
    {
        $provider = app(ImageProvider::class);
        $this->pixelforgeAnswers(503, 'overloaded');

        foreach (range(1, 3) as $ignored) {
            $this->assertSame('overloaded', $this->callAndCatch($provider)?->errorCode);
        }
        Http::assertSentCount(3);

        $exception = $this->callAndCatch($provider);

        $this->assertSame(PixelforgeException::CIRCUIT_OPEN, $exception?->errorCode);
        $this->assertSame(30, $exception->retryAfterSeconds);
        $this->assertTrue($exception->isRetryable());
        Http::assertSentCount(3);
    }

    public function test_timeouts_and_unreadable_answers_also_count_as_failures(): void
    {
        $provider = app(ImageProvider::class);
        $this->answer = fn () => throw new ConnectionException('timed out');

        foreach (range(1, 3) as $ignored) {
            $this->assertSame(PixelforgeException::CONNECTION_FAILED, $this->callAndCatch($provider)?->errorCode);
        }

        $this->assertFalse(app(CircuitBreaker::class)->isAvailable(PixelforgeClient::NAME));
    }

    public function test_a_healthy_provider_refusing_requests_never_opens_the_circuit(): void
    {
        $provider = app(ImageProvider::class);

        foreach ([[429, 'rate_limit_exceeded'], [429, 'concurrency_limit_exceeded'], [422, 'validation_failed'], [400, 'content_policy_violation']] as [$status, $code]) {
            $this->pixelforgeAnswers($status, $code);

            foreach (range(1, 4) as $ignored) {
                $this->assertSame($code, $this->callAndCatch($provider)?->errorCode);
            }
        }

        $this->assertTrue(app(CircuitBreaker::class)->isAvailable(PixelforgeClient::NAME));
    }

    public function test_failures_must_be_consecutive(): void
    {
        $provider = app(ImageProvider::class);

        foreach (range(1, 4) as $ignored) {
            $this->pixelforgeAnswers(503, 'overloaded');
            $this->callAndCatch($provider);
            $this->callAndCatch($provider);
            $this->pixelforgeAnswers(200);
            $this->assertNull($this->callAndCatch($provider));
        }

        $this->assertTrue(app(CircuitBreaker::class)->isAvailable(PixelforgeClient::NAME));
    }

    public function test_after_the_cooldown_a_successful_probe_closes_the_circuit(): void
    {
        $provider = app(ImageProvider::class);
        $this->pixelforgeAnswers(503, 'overloaded');
        foreach (range(1, 3) as $ignored) {
            $this->callAndCatch($provider);
        }

        $this->travel(29)->seconds();
        $this->assertSame(1, $this->callAndCatch($provider)?->retryAfterSeconds);

        $this->travel(1)->seconds();
        $this->pixelforgeAnswers(200);

        $this->assertNull($this->callAndCatch($provider));
        $this->assertTrue(app(CircuitBreaker::class)->isAvailable(PixelforgeClient::NAME));

        // And it takes a full run of failures to open it again.
        $this->pixelforgeAnswers(503, 'overloaded');
        $this->callAndCatch($provider);
        $this->assertTrue(app(CircuitBreaker::class)->isAvailable(PixelforgeClient::NAME));
    }

    public function test_a_failed_probe_reopens_the_circuit_at_once(): void
    {
        $provider = app(ImageProvider::class);
        $this->pixelforgeAnswers(503, 'overloaded');
        foreach (range(1, 3) as $ignored) {
            $this->callAndCatch($provider);
        }

        $this->travel(30)->seconds();

        $this->assertSame('overloaded', $this->callAndCatch($provider)?->errorCode);
        $this->assertSame(PixelforgeException::CIRCUIT_OPEN, $this->callAndCatch($provider)?->errorCode);
        Http::assertSentCount(4);
    }

    public function test_a_fallback_provider_takes_over_creating_generations_when_the_primary_is_unwell(): void
    {
        $generation = Generation::factory()->create();
        $served = ProviderGeneration::fromApi($this->providerGeneration($generation, 'queued', ['id' => 'gen_from_fallback']));

        /** @var ImageProvider&MockInterface $fallback */
        $fallback = Mockery::mock(ImageProvider::class);
        $fallback->shouldReceive('createGeneration')->twice()->andReturn($served);
        $fallback->shouldNotReceive('getGeneration');

        $provider = new ResilientImageProvider(app(PixelforgeClient::class), app(CircuitBreaker::class), $fallback);
        $this->pixelforgeAnswers(503, 'overloaded');

        // The primary fails: the fallback serves the request.
        $this->assertSame('gen_from_fallback', $provider->createGeneration($generation)->id);

        // The circuit is open: the primary is not even called.
        app(CircuitBreaker::class)->recordFailure(PixelforgeClient::NAME);
        app(CircuitBreaker::class)->recordFailure(PixelforgeClient::NAME);
        $this->assertSame('gen_from_fallback', $provider->createGeneration($generation)->id);
        Http::assertSentCount(1);

        // Reading a generation belongs to the provider that owns it: never rerouted.
        $this->expectException(PixelforgeException::class);
        $provider->getGeneration('gen_owned_by_primary');
    }

    public function test_a_request_the_primary_rejects_on_its_merits_is_not_sent_to_the_fallback(): void
    {
        /** @var ImageProvider&MockInterface $fallback */
        $fallback = Mockery::mock(ImageProvider::class);
        $fallback->shouldNotReceive('createGeneration');

        $provider = new ResilientImageProvider(app(PixelforgeClient::class), app(CircuitBreaker::class), $fallback);
        $this->pixelforgeAnswers(400, 'content_policy_violation');

        $this->expectException(PixelforgeException::class);

        $provider->createGeneration(Generation::factory()->create());
    }
}
