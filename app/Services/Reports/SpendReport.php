<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Contracts\ImageProvider;
use App\Enums\GenerationStatus;
use App\Exceptions\PixelforgeException;
use App\Models\Generation;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The operator report: per UTC day and per user, what users were charged,
 * what Pixelforge billed us and the profit in between — with each day's
 * provider cost checked against Pixelforge's own usage report.
 *
 * Days are UTC days of COMPLETION, because that is the day Pixelforge bills a
 * generation on; grouping by creation would mismatch around midnight.
 */
class SpendReport
{
    public function __construct(private readonly ImageProvider $client) {}

    /**
     * @return array{
     *     start_date: string,
     *     end_date: string,
     *     days: list<array<string, mixed>>,
     *     totals: array<string, mixed>,
     *     pixelforge_available: bool,
     * }
     */
    public function build(CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        $succeeded = Generation::query()
            ->with('user:id,name,email')
            ->where('status', GenerationStatus::Succeeded)
            ->whereBetween('completed_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->get();

        $generations = $succeeded->groupBy(fn (Generation $generation): string => (string) $generation->completed_at?->utc()->toDateString());

        $billed = $this->billedByDay($startDate, $endDate);

        $days = [];

        for ($date = $endDate; $date->greaterThanOrEqualTo($startDate); $date = $date->subDay()) {
            $key = $date->toDateString();
            $ours = $generations->get($key) ?? new EloquentCollection;
            $theirs = $billed[$key] ?? null;

            if ($ours->isEmpty() && ($theirs === null || $theirs['generations'] === 0)) {
                continue;
            }

            $sums = $this->sums($ours);

            $days[] = [
                'date' => $key,
                ...$sums,
                'pixelforge_generations' => $theirs['generations'] ?? null,
                'pixelforge_cost_nanos' => $theirs['cost_nanos'] ?? null,
                'matches' => $theirs === null ? null : $theirs['cost_nanos'] === $sums['provider_cost_nanos'] && $theirs['generations'] === $sums['generations'],
                'users' => $ours
                    ->groupBy('user_id')
                    ->map(fn (EloquentCollection $group): array => [
                        'id' => $group->firstOrFail()->user_id,
                        'name' => $group->firstOrFail()->user->name,
                        'email' => $group->firstOrFail()->user->email,
                        ...$this->sums($group),
                    ])
                    ->sortByDesc('revenue_nanos')
                    ->values()
                    ->all(),
            ];
        }

        $totals = $this->sums($succeeded);
        $billedCost = $billed === null ? null : array_sum(array_column($billed, 'cost_nanos'));
        $billedCount = $billed === null ? null : array_sum(array_column($billed, 'generations'));

        return [
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'days' => $days,
            'totals' => [
                ...$totals,
                'pixelforge_generations' => $billedCount,
                'pixelforge_cost_nanos' => $billedCost,
                'matches' => $billed === null ? null : $billedCost === $totals['provider_cost_nanos'] && $billedCount === $totals['generations'],
            ],
            'pixelforge_available' => $billed !== null,
        ];
    }

    /**
     * @param  EloquentCollection<int, Generation>  $generations
     * @return array{generations: int, revenue_nanos: int, provider_cost_nanos: int, profit_nanos: int}
     */
    private function sums(EloquentCollection $generations): array
    {
        $revenue = $generations->reduce(fn (int $sum, Generation $generation): int => $sum + ($generation->price_nanos ?? 0), 0);
        $cost = $generations->reduce(fn (int $sum, Generation $generation): int => $sum + ($generation->provider_cost_nanos ?? 0), 0);

        return [
            'generations' => $generations->count(),
            'revenue_nanos' => $revenue,
            'provider_cost_nanos' => $cost,
            'profit_nanos' => $revenue - $cost,
        ];
    }

    /**
     * What Pixelforge says it billed this key, per UTC day; null when its
     * usage report cannot be read (the report still shows our own numbers).
     *
     * @return array<string, array{generations: int, cost_nanos: int}>|null
     */
    private function billedByDay(CarbonImmutable $startDate, CarbonImmutable $endDate): ?array
    {
        try {
            $usage = $this->client->usage($startDate, $endDate);
            $billed = [];

            foreach (is_array($usage['data'] ?? null) ? $usage['data'] : [] as $day) {
                if (! is_array($day) || ! is_string($day['date'] ?? null) || ! is_string($day['cost_usd'] ?? null)) {
                    throw new InvalidArgumentException('Pixelforge usage has a malformed day.');
                }

                $billed[$day['date']] = [
                    'generations' => is_int($day['generations'] ?? null) ? $day['generations'] : 0,
                    'cost_nanos' => Money::fromDecimal($day['cost_usd']),
                ];
            }

            return $billed;
        } catch (PixelforgeException|InvalidArgumentException $exception) {
            Log::warning('Pixelforge usage report unavailable', ['error' => $exception->getMessage()]);

            return null;
        }
    }
}
