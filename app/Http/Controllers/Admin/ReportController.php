<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminReportRequest;
use App\Services\Reports\SpendReport;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __invoke(AdminReportRequest $request, SpendReport $report): Response
    {
        return Inertia::render('admin/Report', [
            'report' => $report->build($request->startDate(), $request->endDate()),
            'maxRangeDays' => AdminReportRequest::MAX_RANGE_DAYS,
        ]);
    }
}
