<?php

namespace App\Http\Controllers;

use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\Customer;
use App\Services\BusinessContext;
use App\Services\BusinessSettings;
use App\Services\CurrencyService;
use App\Services\CustomerLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Customer CRUD + ledger/statement controller (Batch 10 / Batch 18).
 *
 * All list queries are scoped to the current tenant by the BelongsToBusiness
 * global scope (Customer::query()). Route-model binding resolves `{customer}`
 * within the same scope — cross-business lookups return 404 automatically, for
 * the show/edit pages and for the customer ledger/statement routes alike.
 *
 * Controller logic is intentionally thin: no forbidden-user checks here
 * (handled by the route middleware), no manual business_id assignment (handled
 * by the BelongsToBusiness trait on Customer::create). Ledger/statement pages
 * are read-only views over the CustomerLedgerService read model and reuse the
 * customers.view permission.
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerLedgerService $ledger,
        private readonly BusinessSettings $settings,
        private readonly BusinessContext $context,
        private readonly CurrencyService $currencies,
    ) {
        //
    }

    public function index(Request $request): View
    {
        $searchTerm = trim((string) $request->string('search'));

        $customers = Customer::query()
            ->when($searchTerm !== '', function ($query) use ($searchTerm) {
                $query->where(function ($query) use ($searchTerm) {
                    $query->where('name', 'like', "%{$searchTerm}%")
                        ->orWhere('company_name', 'like', "%{$searchTerm}%")
                        ->orWhere('email', 'like', "%{$searchTerm}%")
                        ->orWhere('phone', 'like', "%{$searchTerm}%");
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('customers.index', compact('customers', 'searchTerm'));
    }

    public function create(): View
    {
        return view('customers.create');
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $customer = Customer::create($request->validated());

        return redirect()
            ->route('customers.show', $customer)
            ->with('status', __('customers.created'));
    }

    public function show(Customer $customer): View
    {
        return view('customers.show', [
            'customer' => $customer,
            'summary' => $this->ledger->summary($customer),
            'baseCurrency' => $this->currencies->baseCurrency(),
        ]);
    }

    public function edit(Customer $customer): View
    {
        return view('customers.edit', compact('customer'));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $customer->update($request->validated());

        return redirect()
            ->route('customers.show', $customer)
            ->with('status', __('customers.updated'));
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $customer->delete();

        return redirect()
            ->route('customers.index')
            ->with('status', __('customers.deleted'));
    }

    /**
     * Customer ledger — tenant- and permission-protected read-only view
     * (module:customers + permission:customers.view enforced at the route).
     */
    public function ledger(Request $request, Customer $customer): View
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'in:invoice,payment'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $result = $this->ledger->ledger($customer, [
            'search' => $data['search'] ?? null,
            'type' => $data['type'] ?? null,
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
        ]);

        return view('customers.ledger', [
            'customer' => $customer,
            'summary' => $this->ledger->summary($customer),
            'baseCurrency' => $this->currencies->baseCurrency(),
            'rows' => $result['rows'],
            'broughtForward' => $result['brought_forward'],
            'closingBalance' => $result['closing_balance'],
            'showRunningBalance' => $result['show_running_balance'],
            'hasPeriodFilter' => $result['has_period_filter'],
            'searchTerm' => $data['search'] ?? null,
            'type' => $data['type'] ?? null,
            'dateFrom' => $data['date_from'] ?? null,
            'dateTo' => $data['date_to'] ?? null,
        ]);
    }

    /**
     * Print-ready customer statement — same read model as the ledger, rendered
     * in a clean printable layout with a window.print() action.
     */
    public function statement(Request $request, Customer $customer): View
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $result = $this->ledger->ledger($customer, [
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
        ]);

        $business = $this->context->current();

        return view('customers.statement', [
            'customer' => $customer,
            'business' => $business,
            'businessDetails' => $this->settings->group('general'),
            'baseCurrency' => $this->currencies->baseCurrency(),
            'rows' => $result['rows'],
            'broughtForward' => $result['brought_forward'],
            'openingBalance' => $this->ledger->openingBalance($customer),
            'closingBalance' => $result['closing_balance'],
            'dateFrom' => $data['date_from'] ?? null,
            'dateTo' => $data['date_to'] ?? null,
        ]);
    }
}
