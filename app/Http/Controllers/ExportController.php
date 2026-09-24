<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Enums\ProductType;
use App\Services\ExportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Batch 21 CSV exports.
 *
 * Route middleware owns authentication, module availability and permissions.
 * ExportService owns tenant-scoped queries, filter application and CSV safety.
 */
class ExportController extends Controller
{
    public function __construct(private readonly ExportService $exports)
    {
        //
    }

    public function customers(Request $request): StreamedResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->exports->customers($filters);
    }

    public function products(Request $request): StreamedResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(ProductType::class)],
        ]);

        return $this->exports->products($filters);
    }

    public function invoices(Request $request): StreamedResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
        ]);

        return $this->exports->invoices($filters);
    }

    public function expenses(Request $request): StreamedResponse
    {
        return $this->exports->expenses($this->expenseFilters($request));
    }

    public function expenseReport(Request $request): StreamedResponse
    {
        return $this->exports->expenses($this->expenseFilters($request), 'expense-report');
    }

    /**
     * @return array<string, mixed>
     */
    private function expenseFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
    }
}
