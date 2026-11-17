<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Contracts\ImageProvider;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Generations\SlotAllocator;
use App\Services\Resilience\CircuitBreaker;

/**
 * What an operator looks at first when someone says "it is slow" or "it
 * failed": is the provider healthy, are the slots full, how long is the line,
 * is anything stuck, and why did recent generations fail.
 */
class OperationsSnapshot
{
    /** In-flight generations older than this are worth a look. */
    private const int STUCK_AFTER_MINUTES = 10;

    private const int FAILURE_WINDOW_HOURS = 24;

    public function __construct(
        private readonly ImageProvider $provider,
        private readonly CircuitBreaker $breaker,
        private readonly SlotAllocator $slots,
    ) {}

    /**
     * @return array{
     *     provider: array{name: string, healthy: bool, retry_in_seconds: int},
     *     slots: array{in_use: int, total: int, waiting: int},
     *     stuck: int,
     *     failures: list<array{code: string, count: int}>,
     *     failure_window_hours: int,
     * }
     */
    public function build(): array
    {
        $retryIn = $this->breaker->secondsUntilRetry($this->provider->name());

        $counts = [];

        Generation::query()
            ->where('status', GenerationStatus::Failed)
            ->where('completed_at', '>=', now()->subHours(self::FAILURE_WINDOW_HOURS))
            ->get(['error_code'])
            ->each(function (Generation $generation) use (&$counts): void {
                $code = $generation->error_code ?? 'unknown';
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            });

        arsort($counts);

        $failures = [];

        foreach ($counts as $code => $count) {
            $failures[] = ['code' => (string) $code, 'count' => $count];
        }

        return [
            'provider' => [
                'name' => $this->provider->name(),
                'healthy' => $retryIn === 0,
                'retry_in_seconds' => $retryIn,
            ],
            'slots' => [
                'in_use' => $this->slots->inUse(),
                'total' => config()->integer('pixelforge.max_concurrent_generations'),
                'waiting' => count($this->slots->line()),
            ],
            'stuck' => Generation::query()
                ->whereIn('status', GenerationStatus::active())
                ->where('created_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES))
                ->count(),
            'failures' => $failures,
            'failure_window_hours' => self::FAILURE_WINDOW_HOURS,
        ];
    }
}
