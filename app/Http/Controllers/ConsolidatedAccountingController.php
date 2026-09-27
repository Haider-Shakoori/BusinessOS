<?php

namespace App\Http\Controllers;

use App\Models\BusinessMembership;
use App\Models\ConsolidationElimination;
use App\Services\ConsolidatedAccountingReportService;
use App\Services\ConsolidationEliminationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
    public function storeElimination(
        Request $request,
        ConsolidatedAccountingReportService $reports,
        ConsolidationEliminationService $eliminations,
    ): RedirectResponse {
        $data = $request->validate([
            'business_ids' => ['required', 'array', 'min:2'],
            'business_ids.*' => ['integer'],
            'reference' => ['required', 'string', 'max:80'],
            'effective_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.statement_type' => ['required', 'string'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ]);

        $this->assertCanManageEveryBusiness($request, $data['business_ids']);
        $reports->report($data['business_ids']);

        $eliminations->create($data, $data['business_ids'], (int) $request->user()->id);

        return back()->with('status', 'Intercompany elimination posted to consolidated reporting.');
    }

    public function reverseElimination(
        Request $request,
        ConsolidationElimination $consolidationElimination,
        ConsolidatedAccountingReportService $reports,
        ConsolidationEliminationService $eliminations,
    ): RedirectResponse {
        $businessIds = collect(explode(':', $consolidationElimination->group_key))
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->assertCanManageEveryBusiness($request, $businessIds);
        $reports->report($businessIds);
        $eliminations->reverse($consolidationElimination, (int) $request->user()->id);

        return back()->with('status', 'Intercompany elimination reversed.');
    }

    private function assertCanManageEveryBusiness(Request $request, array $businessIds): void
    {
        $memberships = BusinessMembership::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('business_id', $businessIds)
            ->get()
            ->keyBy('business_id');

        foreach (collect($businessIds)->map(fn ($id): int => (int) $id)->unique() as $businessId) {
            if (! $memberships->get($businessId)?->hasPermission('accounting.manage')) {
                throw ValidationException::withMessages([
                    'business_ids' => 'Accounting management permission is required in every business included in an elimination.',
                ]);
            }
        }
    }

}
