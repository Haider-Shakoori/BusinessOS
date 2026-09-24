<?php

namespace App\Http\Controllers;

use App\Http\Requests\Expense\StoreExpenseRequest;
use App\Http\Requests\Expense\UpdateExpenseRequest;
use App\Models\Category;
use App\Models\Expense;
use App\Services\CurrencyService;
use App\Services\ExpenseService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expense CRUD, receipt serving and operational report (Batch 17).
 *
 * Controller logic is intentionally thin: permission checks are handled by the
 * route middleware, business_id assignment and tenancy by the BelongsToBusiness
 * global scope (cross-business route lookups return 404), the expense number
 * by DocumentNumberService, and all financial/upload/report rules by
 * ExpenseService inside transactions.
 *
 * Category pickers list only ACTIVE, current-business categories (soft-deleted
 * master rows are excluded automatically by the SoftDeletes scope). Index and
 * report date filters are validated server-side and preserved through
 * pagination via withQueryString().
 */
class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $service,
        private readonly CurrencyService $currencies,
    ) {
        //
    }

    public function index(Request $request): View
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $searchTerm = trim($data['search'] ?? '');
        $categoryId = $data['category_id'] ?? null;
        $dateFrom = $data['date_from'] ?? null;
        $dateTo = $data['date_to'] ?? null;

        $expenses = Expense::query()
            ->with(['category', 'createdBy'])
            ->when($searchTerm !== '', function ($query) use ($searchTerm) {
                $query->where(function ($query) use ($searchTerm) {
                    $query->where('expense_number', 'like', "%{$searchTerm}%")
                        ->orWhere('reference', 'like', "%{$searchTerm}%")
                        ->orWhere('vendor', 'like', "%{$searchTerm}%");
                });
            })
            ->when(! blank($categoryId), fn ($query) => $query->where('category_id', (int) $categoryId))
            ->when(! blank($dateFrom), fn ($query) => $query->whereDate('expense_date', '>=', $dateFrom))
            ->when(! blank($dateTo), fn ($query) => $query->whereDate('expense_date', '<=', $dateTo))
            ->orderBy('expense_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(15)
            ->withQueryString();

        return view('expenses.index', [
            'expenses' => $expenses,
            'categories' => $this->categories(),
            'searchTerm' => $searchTerm,
            'categoryId' => $categoryId,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ]);
    }

    public function create(): View
    {
        return view('expenses.create', [
            'categories' => $this->categories(),
            'currencies' => $this->currencyOptions(),
            'currencyBase' => $this->baseCurrencyCode(),
        ]);
    }

    public function store(StoreExpenseRequest $request): RedirectResponse
    {
        $expense = $this->service->store(
            $request->validated(),
            $request->file('receipt'),
            (int) auth()->id(),
        );

        return redirect()
            ->route('expenses.show', $expense)
            ->with('status', __('expenses.created'));
    }

    public function show(Expense $expense): View
    {
        return view('expenses.show', [
            'expense' => $expense->load(['category', 'createdBy']),
            'baseCurrency' => $this->baseCurrencyCode(),
        ]);
    }

    public function edit(Expense $expense): View
    {
        return view('expenses.edit', [
            'expense' => $expense,
            'categories' => $this->categories(),
            'currencies' => $this->currencyOptions(),
            'currencyBase' => $this->baseCurrencyCode(),
        ]);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $this->service->update(
            $expense,
            $request->validated(),
            $request->file('receipt'),
            (bool) $request->boolean('remove_receipt'),
            (int) auth()->id(),
        );

        return redirect()
            ->route('expenses.show', $expense)
            ->with('status', __('expenses.updated'));
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        $this->service->destroy($expense);

        return redirect()
            ->route('expenses.index')
            ->with('status', __('expenses.deleted'));
    }

    /**
     * Lightweight operational expense report (Batch 17 scope).
     */
    public function report(Request $request): View
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $result = $this->service->report($data);

        return view('expenses.report', [
            'expenses' => $result['expenses'],
            'total' => $result['total'],
            'baseCurrency' => $this->baseCurrencyCode(),
            'categories' => $this->categories(),
            'categoryId' => $data['category_id'] ?? null,
            'dateFrom' => $data['date_from'] ?? null,
            'dateTo' => $data['date_to'] ?? null,
        ]);
    }

    /**
     * Stream the expense receipt. Tenant isolation and permission are enforced
     * by the route wiring before this method is reached.
     */
    public function receipt(Expense $expense): StreamedResponse
    {
        return $this->service->receiptResponse($expense);
    }

    /**
     * ACTIVE, current-business categories only (the SoftDeletes + business
     * global scopes do the filtering automatically) — mirrors the catalog
     * selector convention (ProductController::selectableCategories).
     */
    private function categories(): Collection
    {
        return Category::query()->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Enabled currencies of the CURRENT business for the expense form.
     */
    private function currencyOptions()
    {
        return $this->currencies->enabledCurrencies();
    }

    private function baseCurrencyCode(): string
    {
        return $this->currencies->baseCurrency();
    }
}
