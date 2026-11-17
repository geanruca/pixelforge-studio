<?php

declare(strict_types=1);

namespace App\Services\Resilience;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;

/**
 * A circuit breaker per external service, shared by every web process and
 * queue worker through the cache.
 *
 *  - CLOSED: calls go through; consecutive failures are counted.
 *  - OPEN: after `failure_threshold` consecutive failures no call is made for
 *    `cooldown_seconds`. A struggling provider gets room to recover and our
 *    workers stop burning time and rate limit on calls that will fail.
 *  - HALF-OPEN: once the cooldown has passed, calls are let through again as
 *    probes. One success closes the circuit; one failure reopens it.
 */
class CircuitBreaker
{
    public function __construct(private readonly Cache $cache) {}

    /**
     * Whether a call to the service may be attempted right now.
     */
    public function isAvailable(string $service): bool
    {
        return $this->secondsUntilRetry($service) === 0;
    }

    /**
     * Seconds left before the open circuit lets a probe through; 0 when calls are allowed.
     */
    public function secondsUntilRetry(string $service): int
    {
        $openedAt = $this->cache->get($this->key($service, 'opened_at'));

        if (! is_int($openedAt)) {
            return 0;
        }

        return max(0, $openedAt + $this->cooldownSeconds() - now()->getTimestamp());
    }

    public function recordSuccess(string $service): void
    {
        if ($this->cache->get($this->key($service, 'opened_at')) !== null) {
            Log::info('Circuit closed: the service answered again', ['service' => $service]);
        }

        $this->cache->forget($this->key($service, 'failures'));
        $this->cache->forget($this->key($service, 'opened_at'));
    }

    public function recordFailure(string $service): void
    {
        $wasOpen = $this->cache->get($this->key($service, 'opened_at')) !== null;
        $failures = $this->cache->increment($this->key($service, 'failures'));

        // A failed probe reopens at once; otherwise open at the threshold.
        if ($wasOpen || $failures >= config()->integer('pixelforge.circuit_breaker.failure_threshold')) {
            $this->cache->forever($this->key($service, 'opened_at'), now()->getTimestamp());

            Log::warning('Circuit opened: calls to the service are paused', [
                'service' => $service,
                'consecutive_failures' => $failures,
                'cooldown_seconds' => $this->cooldownSeconds(),
            ]);
        }
    }

    private function cooldownSeconds(): int
    {
        return config()->integer('pixelforge.circuit_breaker.cooldown_seconds');
    }

    private function key(string $service, string $suffix): string
    {
        return "circuit-breaker.{$service}.{$suffix}";
    }
}
