<?php

namespace App\Http\Controllers;

use App\Services\ConsolidatedAccountingReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConsolidatedAccountingController extends Controller
{
    public function index(Request $request, ConsolidatedAccountingReportService $reports): View
    {
        $data = $request->validate([
            'business_ids' => ['nullable', 'array'],
            'business_ids.*' => ['integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $available = $reports->availableBusinesses();
        $selectedIds = collect($data['business_ids'] ?? $available->pluck('id')->all())
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        return view('accounting.consolidated', [
            'availableBusinesses' => $available,
            'selectedIds' => $selectedIds,
            'dateFrom' => $data['date_from'] ?? null,
            'dateTo' => $data['date_to'] ?? null,
            'report' => $reports->report($selectedIds, $data['date_from'] ?? null, $data['date_to'] ?? null),
        ]);
    }
}
