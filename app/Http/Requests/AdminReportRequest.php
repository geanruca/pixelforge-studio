<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class AdminReportRequest extends FormRequest
{
    /** Pixelforge's usage endpoint accepts at most this many days. */
    public const int MAX_RANGE_DAYS = 92;

    private const int DEFAULT_RANGE_DAYS = 7;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:end_date'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'required_with:start_date'],
        ];
    }

    public function endDate(): CarbonImmutable
    {
        $end = $this->validated('end_date');

        return is_string($end) ? CarbonImmutable::parse($end, 'UTC') : CarbonImmutable::now('UTC')->startOfDay();
    }

    public function startDate(): CarbonImmutable
    {
        $start = $this->validated('start_date');
        $end = $this->endDate();

        $start = is_string($start) ? CarbonImmutable::parse($start, 'UTC') : $end->subDays(self::DEFAULT_RANGE_DAYS - 1);

        return $start->max($end->subDays(self::MAX_RANGE_DAYS - 1));
    }
}
