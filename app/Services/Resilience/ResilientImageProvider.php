<?php

declare(strict_types=1);

namespace App\Services\Resilience;

use App\Contracts\ImageProvider;
use App\Exceptions\PixelforgeException;
use App\Models\Generation;
use App\Services\Pixelforge\ProviderGeneration;
use Carbon\CarbonInterface;
use Closure;

/**
 * Puts a circuit breaker, and optionally a fallback provider, in front of an
 * image provider. It is itself an {@see ImageProvider}, so callers cannot tell
 * the difference.
 *
 * What happens while the primary's circuit is open, or when a call to it
 * fails in a way that says "the provider is unwell":
 *
 *  - Creating a generation goes to the FALLBACK provider when one is bound.
 *    None is bound today (there is one provider), so the call fails fast with
 *    `circuit_open` and the submission job simply waits for the cooldown.
 *  - Reading a generation, pricing and usage belong to the provider that owns
 *    them and are never rerouted. Their callers degrade on their own: the
 *    estimate uses the last pricing table we saw, the admin report shows our
 *    numbers as "not checked", and polling tries again next minute.
 *
 * Bringing in a second provider is one binding in AppServiceProvider plus its
 * adapter. (It would also need each generation to remember which provider
 * served it, since webhooks and rates are provider-specific.)
 */
class ResilientImageProvider implements ImageProvider
{
    public function __construct(
        private readonly ImageProvider $primary,
        private readonly CircuitBreaker $breaker,
        private readonly ?ImageProvider $fallback = null,
    ) {}

    public function name(): string
    {
        return $this->primary->name();
    }

    public function createGeneration(Generation $generation): ProviderGeneration
    {
        return $this->guarded(
            fn (ImageProvider $provider): ProviderGeneration => $provider->createGeneration($generation),
            canFallBack: true,
        );
    }

    public function getGeneration(string $providerId): ProviderGeneration
    {
        return $this->guarded(fn (ImageProvider $provider): ProviderGeneration => $provider->getGeneration($providerId));
    }

    public function pricing(): array
    {
        return $this->guarded(fn (ImageProvider $provider): array => $provider->pricing());
    }

    public function usage(CarbonInterface $startDate, CarbonInterface $endDate): array
    {
        return $this->guarded(fn (ImageProvider $provider): array => $provider->usage($startDate, $endDate));
    }

    /**
     * @template TResult
     *
     * @param  Closure(ImageProvider): TResult  $call
     * @return TResult
     *
     * @throws PixelforgeException
     */
    private function guarded(Closure $call, bool $canFallBack = false): mixed
    {
        $service = $this->primary->name();
        $fallback = $canFallBack ? $this->fallback : null;

        if (! $this->breaker->isAvailable($service)) {
            return $fallback !== null
                ? $call($fallback)
                : throw PixelforgeException::circuitOpen($this->breaker->secondsUntilRetry($service));
        }

        try {
            $result = $call($this->primary);
        } catch (PixelforgeException $exception) {
            if (! $exception->indicatesUnhealthyProvider()) {
                // The provider answered and said no (validation, limits): it is healthy.
                $this->breaker->recordSuccess($service);

                throw $exception;
            }

            $this->breaker->recordFailure($service);

            return $fallback !== null ? $call($fallback) : throw $exception;
        }

        $this->breaker->recordSuccess($service);

        return $result;
    }
}
