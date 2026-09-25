<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports)
    {
        //
    }

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'report' => ['nullable', 'string', Rule::in(ReportService::TYPES)],
            'range' => ['nullable', 'string', 'in:month,quarter,year,custom'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $report = $filters['report'] ?? 'summary';
        [$range, $dateFrom, $dateTo] = $this->reports->dateRange($filters);

        return view('reports.index', [
            'reportType' => $report,
            'range' => $range,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'baseCurrency' => $this->reports->baseCurrency(),
            'reportData' => $this->reports->run($report, $dateFrom, $dateTo),
        ]);
    }
}
