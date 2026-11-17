<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Exceptions\PixelforgeException;
use App\Models\Generation;
use App\Services\Pixelforge\ProviderGeneration;
use Carbon\CarbonInterface;

/**
 * The port every external image-generation service is reached through.
 *
 * The application depends on this contract, never on a concrete client: the
 * container decides what stands behind it (see AppServiceProvider), which is
 * what lets a circuit breaker, a fallback provider or a test double be put in
 * front of Pixelforge without touching a single caller.
 */
interface ImageProvider
{
    /**
     * A stable key for this provider, used to key its circuit breaker.
     */
    public function name(): string;

    /**
     * Submit a generation. Implementations must be idempotent on the
     * generation's own idempotency key, because callers retry after timeouts.
     *
     * @throws PixelforgeException
     */
    public function createGeneration(Generation $generation): ProviderGeneration;

    /**
     * @throws PixelforgeException
     */
    public function getGeneration(string $providerId): ProviderGeneration;

    /**
     * The provider's current rates and token rules.
     *
     * @return array<array-key, mixed>
     *
     * @throws PixelforgeException
     */
    public function pricing(): array;

    /**
     * What the provider billed, per UTC day.
     *
     * @return array<array-key, mixed>
     *
     * @throws PixelforgeException
     */
    public function usage(CarbonInterface $startDate, CarbonInterface $endDate): array;
}
