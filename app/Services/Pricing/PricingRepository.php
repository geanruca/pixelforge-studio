<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Exceptions\PixelforgeException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The current Pixelforge pricing table, cached so that showing an estimate
 * never spends a request of the per-minute rate limit.
 */
class PricingRepository
{
    private const string FRESH_KEY = 'pixelforge.pricing.fresh';

    private const string LAST_KNOWN_KEY = 'pixelforge.pricing.last-known';

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly Cache $cache,
    ) {}

    /**
     * Returns the pricing table, refreshing it when the cached copy expired.
     * When Pixelforge cannot be reached the last table we saw is used instead,
     * so a provider blip does not take the generate page down.
     *
     * @throws PixelforgeException when pricing is unreachable and was never fetched
     */
    public function current(): PricingTable
    {
        $fresh = $this->cache->get(self::FRESH_KEY);

        if (is_array($fresh)) {
            return PricingTable::fromApi($fresh);
        }

        try {
            $pricing = $this->client->pricing();
            $table = PricingTable::fromApi($pricing);
        } catch (PixelforgeException|InvalidArgumentException $exception) {
            $lastKnown = $this->cache->get(self::LAST_KNOWN_KEY);

            Log::warning('Pixelforge pricing could not be refreshed', [
                'error' => $exception->getMessage(),
                'using_last_known' => is_array($lastKnown),
            ]);

            if (is_array($lastKnown)) {
                return PricingTable::fromApi($lastKnown);
            }

            throw $exception instanceof PixelforgeException
                ? $exception
                : PixelforgeException::unexpected($exception->getMessage());
        }

        $this->cache->put(self::FRESH_KEY, $pricing, config()->integer('pixelforge.pricing_cache_seconds'));
        $this->cache->forever(self::LAST_KNOWN_KEY, $pricing);

        return $table;
    }
}
