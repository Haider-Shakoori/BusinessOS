<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Supplier;
use App\Services\BusinessContext;
use App\Services\CurrencyService;
use App\Services\DocumentService;
use App\Services\PdfService;
use App\Services\SupplierLedgerService;
use App\Services\SupplierPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class SupplierController extends Controller
{
    public function __construct(
        private readonly SupplierLedgerService $ledger,
        private readonly SupplierPaymentService $payments,
        private readonly CurrencyService $currencies,
    ) {
        //
    }

    public function index(Request $request): View
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $search = trim((string) ($data['search'] ?? ''));
        $status = $data['status'] ?? null;

        $suppliers = Supplier::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('suppliers.index', [
            'suppliers' => $suppliers,
            'searchTerm' => $search,
            'statusFilter' => $status,
            'baseCurrency' => $this->currencies->baseCurrency(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $supplier = Supplier::create($this->validatedSupplier($request));

        return redirect()
            ->route('suppliers.show', $supplier)
            ->with('status', __('suppliers.messages.created'));
    }

    public function show(Supplier $supplier): View
    {
        return view('suppliers.show', [
            'supplier' => $supplier,
            'summary' => $this->ledger->summary($supplier),
            'baseCurrency' => $this->currencies->baseCurrency(),
            'purchases' => $supplier->purchaseOrders()->latest('order_date')->latest('id')->limit(10)->get(),
            'payments' => Payment::query()
                ->where('party_type', 'supplier')
                ->where('party_id', $supplier->id)
                ->latest('payment_date')
                ->latest('id')
                ->limit(10)
                ->get(),
            'payablePurchases' => $supplier->purchaseOrders()->where('status', 'received')->latest('order_date')->get(),
        ]);
    }

    public function edit(Supplier $supplier): View
    {
        return view('suppliers.edit', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validatedSupplier($request, $supplier));

        return redirect()
            ->route('suppliers.show', $supplier)
            ->with('status', __('suppliers.messages.updated'));
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $supplier->delete();

        return redirect()
            ->route('suppliers.index')
            ->with('status', __('suppliers.messages.deleted'));
    }

    public function ledger(Request $request, Supplier $supplier): View
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(['opening', 'purchase', 'return', 'payment', 'reversal'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $result = $this->ledger->ledger($supplier, $data);

        return view('suppliers.ledger', [
            'supplier' => $supplier,
            'summary' => $this->ledger->summary($supplier),
            'baseCurrency' => $this->currencies->baseCurrency(),
            'rows' => $result['rows'],
            'closingBalance' => $result['closing_balance'],
            'showRunningBalance' => $result['show_running_balance'],
            'hasPeriodFilter' => $result['has_period_filter'],
            'searchTerm' => $data['search'] ?? null,
            'type' => $data['type'] ?? null,
            'dateFrom' => $data['date_from'] ?? null,
            'dateTo' => $data['date_to'] ?? null,
        ]);
    }

    public function statement(Request $request, Supplier $supplier, DocumentService $documents): View
    {
        $document = $documents->supplierStatement($supplier, $this->statementFilters($request));

        return view($document['view'], $document['data']);
    }

    public function statementPdf(
        Request $request,
        Supplier $supplier,
        DocumentService $documents,
        PdfService $pdfs,
    ): Response {
        return $pdfs->render(
            $documents->supplierStatement($supplier, $this->statementFilters($request), forPdf: true),
            $request->boolean('download'),
        );
    }

    public function recordPayment(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $request->validate([
            'purchase_order_id' => [
                'nullable',
                Rule::exists('purchase_orders', 'id')->where(fn ($query) => $query
                    ->where('business_id', $supplier->business_id)
                    ->where('supplier_id', $supplier->id)),
            ],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'card', 'mobile', 'cheque', 'other'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->payments->record([
            ...$data,
            'supplier_id' => $supplier->id,
        ], (int) auth()->id());

        return redirect()
            ->route('suppliers.show', $supplier)
            ->with('status', __('suppliers.messages.payment_recorded'));
    }

    public function reversePayment(Request $request, Supplier $supplier, Payment $payment): RedirectResponse
    {
        abort_unless($payment->party_type === 'supplier' && (int) $payment->party_id === (int) $supplier->id, 404);

        $data = $request->validate([
            'reversal_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->payments->reverse($payment, $data['reversal_reason'] ?? null, (int) auth()->id());

        return redirect()
            ->route('suppliers.show', $supplier)
            ->with('status', __('suppliers.messages.payment_reversed'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedSupplier(Request $request, ?Supplier $supplier = null): array
    {
        $businessId = (int) app(BusinessContext::class)->currentId();

        return $request->validate([
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('suppliers', 'code')
                    ->where('business_id', $businessId)
                    ->ignore($supplier?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:2000'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'opening_balance_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'is_active' => ['nullable', 'boolean'],
        ]) + ['is_active' => $request->boolean('is_active', true)];
    }

    /**
     * @return array{date_from?: string|null, date_to?: string|null}
     */
    private function statementFilters(Request $request): array
    {
        return $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
    }
}
