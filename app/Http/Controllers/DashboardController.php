<?php

namespace App\Http\Controllers;

use App\Services\BusinessContext;
use App\Services\DashboardService;
use App\Services\ModuleManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly ModuleManager $modules,
        private readonly BusinessContext $context,
    ) {
        //
    }

    public function index(Request $request): View
    {
        $data = $request->validate([
            'range' => ['nullable', 'string', 'in:month,quarter,year,custom'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        [$range, $dateFrom, $dateTo] = $this->dateRange($data);

        $sales = $this->modules->isEnabled('sales');
        $expenses = $this->modules->isEnabled('expenses');
        $customers = $this->modules->isEnabled('customers');

        $canInvoices = Gate::allows('invoices.view');
        $canPayments = Gate::allows('payments.view');
        $canExpenses = Gate::allows('expenses.view');
        $canCustomers = Gate::allows('customers.view');

        $visibility = [
            'sales' => $sales && $canInvoices,
            'revenue' => $sales && $canPayments,
            'expenses' => $expenses && $canExpenses,
            'receivables' => $sales && $canInvoices,
            'top_customers' => $sales && $canInvoices && $customers && $canCustomers,
            'recent_invoices' => $sales && $canInvoices,
            'recent_payments' => $sales && $canPayments,
            'recent_expenses' => $expenses && $canExpenses,
        ];

        $current = $this->context->current();
        $membership = $this->context->membership();

        return view('dashboard.index', [
            'business' => $current,
            'roles' => $membership?->roles()->get() ?? collect(),
            'range' => $range,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'visibility' => $visibility,
            'dashboard' => $this->dashboard->dashboard($dateFrom, $dateTo, $visibility),
        ]);
    }

    /**
     * Resolve supported dashboard presets to inclusive business dates.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string, 2: string}
     */
    private function dateRange(array $data): array
    {
        $range = $data['range'] ?? null;

        if ($range === null && (! empty($data['date_from']) || ! empty($data['date_to']))) {
            $range = 'custom';
        }

        $range ??= 'month';
        $today = CarbonImmutable::today();

        return match ($range) {
            'quarter' => ['quarter', $today->startOfQuarter()->toDateString(), $today->toDateString()],
            'year' => ['year', $today->startOfYear()->toDateString(), $today->toDateString()],
            'custom' => [
                'custom',
                CarbonImmutable::parse($data['date_from'] ?? $today->startOfMonth()->toDateString())->toDateString(),
                CarbonImmutable::parse($data['date_to'] ?? $today->toDateString())->toDateString(),
            ],
            default => ['month', $today->startOfMonth()->toDateString(), $today->toDateString()],
        };
    }
}
