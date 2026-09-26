<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Models\Supplier;
use App\Services\AccountingPostingService;
use App\Services\BusinessContext;
use App\Services\CurrencyService;
use App\Services\PaymentService;
use App\Services\SupplierLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function __construct(
        private readonly SupplierLedgerService $ledger,
        private readonly CurrencyService $currencies,
        private readonly PaymentService $payments,
        private readonly AccountingPostingService $accounting,
    ) {
        //
    }

    public function index(Request $request): View
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($data['search'] ?? ''));

        $suppliers = Supplier::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $suppliers->getCollection()->each(function (Supplier $supplier): void {
            $supplier->setAttribute('outstanding_balance', $this->ledger->outstandingBalance($supplier));
        });

        return view('suppliers.index', [
            'suppliers' => $suppliers,
            'searchTerm' => $search,
            'baseCurrency' => $this->currencies->baseCurrency(),
        ]);
    }

    public function create(): View
    {
        return view('suppliers.create');
    }

    public function store(Request $request, BusinessContext $context): RedirectResponse
    {
        $data = $this->validatedSupplier($request, $context);

        if (! empty($data['code'])) {
            $data['code'] = Str::upper($data['code']);
        }

        $supplier = DB::transaction(function () use ($data): Supplier {
            $supplier = Supplier::create($data + ['is_active' => true]);

            if (empty($supplier->code)) {
                $supplier->update(['code' => $this->generatedCode($supplier)]);
            }

            $this->accounting->postSupplierOpeningBalance($supplier);

            return $supplier;
        });

        return redirect()
            ->route('suppliers.show', $supplier)
            ->with('status', __('suppliers.created'));
    }

    public function show(Supplier $supplier): View
    {
        return view('suppliers.show', [
            'supplier' => $supplier->load([
                'purchaseOrders' => fn ($query) => $query->latest('order_date')->latest('id')->limit(10),
                'payments' => fn ($query) => $query->latest('payment_date')->latest('id')->limit(10),
            ]),
            'summary' => $this->ledger->summary($supplier),
            'baseCurrency' => $this->currencies->baseCurrency(),
            'paymentMethods' => PaymentMethod::cases(),
        ]);
    }

    public function edit(Supplier $supplier): View
    {
        return view('suppliers.edit', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier, BusinessContext $context): RedirectResponse
    {
        $data = $this->validatedSupplier($request, $context, $supplier);
        $data['code'] = Str::upper((string) $data['code']);

        DB::transaction(function () use ($supplier, $data): void {
            $supplier->update($data);
            $this->accounting->replaceSupplierOpeningBalance($supplier);
        });

        return redirect()
            ->route('suppliers.show', $supplier)
            ->with('status', __('suppliers.updated'));
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        if ($supplier->purchaseOrders()->exists() || $supplier->payments()->exists()) {
            return back()->withErrors(['supplier' => __('suppliers.validation.has_history')]);
        }

        DB::transaction(function () use ($supplier): void {
            $this->accounting->reverseSupplierOpeningBalance($supplier);
            $supplier->delete();
        });

        return redirect()
            ->route('suppliers.index')
            ->with('status', __('suppliers.deleted'));
    }

    public function ledger(Request $request, Supplier $supplier): View
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(['purchase', 'return', 'payment'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $result = $this->ledger->ledger($supplier, $data);

        return view('suppliers.ledger', [
            'supplier' => $supplier,
            'summary' => $this->ledger->summary($supplier),
            'rows' => $result['rows'],
            'broughtForward' => $result['brought_forward'],
            'closingBalance' => $result['closing_balance'],
            'showRunningBalance' => $result['show_running_balance'],
            'baseCurrency' => $this->currencies->baseCurrency(),
            'searchTerm' => $data['search'] ?? null,
            'type' => $data['type'] ?? null,
            'dateFrom' => $data['date_from'] ?? null,
            'dateTo' => $data['date_to'] ?? null,
        ]);
    }

    public function statement(Request $request, Supplier $supplier): View
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $result = $this->ledger->ledger($supplier, $data);

        return view('suppliers.statement', [
            'supplier' => $supplier,
            'summary' => $this->ledger->summary($supplier),
            'rows' => $result['rows'],
            'broughtForward' => $result['brought_forward'],
            'closingBalance' => $result['closing_balance'],
            'baseCurrency' => $this->currencies->baseCurrency(),
            'dateFrom' => $data['date_from'] ?? null,
            'dateTo' => $data['date_to'] ?? null,
        ]);
    }

    public function storePayment(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.9999', 'decimal:0,4'],
            'payment_method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'payment_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->payments->recordSupplier($supplier, $data, (int) auth()->id());

        return redirect()
            ->route('suppliers.show', $supplier)
            ->with('status', __('suppliers.payment_created'));
    }

    public function reversePayment(Request $request, Supplier $supplier, Payment $payment): RedirectResponse
    {
        abort_unless(
            $payment->party_type === 'supplier' && (int) $payment->party_id === (int) $supplier->id,
            404,
        );

        $data = $request->validate([
            'reversal_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->payments->reverse(
            $payment,
            $data['reversal_reason'] ?? null,
            (int) auth()->id(),
        );

        return redirect()
            ->route('suppliers.show', $supplier)
            ->with('status', __('suppliers.payment_reversed'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedSupplier(Request $request, BusinessContext $context, ?Supplier $supplier = null): array
    {
        $uniqueCode = Rule::unique('suppliers', 'code')
            ->where('business_id', $context->currentId());

        if ($supplier !== null) {
            $uniqueCode->ignore($supplier->id);
        }

        return $request->validate([
            'code' => [$supplier ? 'required' : 'nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/', $uniqueCode],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:2000'],
            'opening_balance' => ['nullable', 'numeric', 'min:0', 'max:999999999999.9999', 'decimal:0,4'],
            'opening_balance_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
    }

    private function generatedCode(Supplier $supplier): string
    {
        $base = 'SUP-'.str_pad((string) $supplier->id, 6, '0', STR_PAD_LEFT);

        if (! Supplier::query()->where('code', $base)->whereKeyNot($supplier->id)->exists()) {
            return $base;
        }

        return $base.'-'.Str::upper(Str::random(4));
    }
}
