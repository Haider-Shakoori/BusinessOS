<?php

namespace App\Http\Controllers;

use App\Enums\QuotationStatus;
use App\Http\Requests\Quotation\StoreQuotationRequest;
use App\Http\Requests\Quotation\UpdateQuotationRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Tax;
use App\Services\BusinessSettings;
use App\Services\CurrencyService;
use App\Services\QuotationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Quotation CRUD controller (Batch 14).
 *
 * All list queries are scoped to the current tenant by the BelongsToBusiness
 * global scope (Quotation::query()). Route-model binding resolves
 * `{quotation}` within the same scope — cross-business lookups return 404
 * automatically.
 *
 * Controller logic is intentionally thin: permission checks are handled by the
 * route middleware, business_id assignment by the BelongsToBusiness trait, and
 * the draft-only lifecycle guard by QuotationService (which is also where the
 * document number is allocated inside the same transaction as the insert).
 *
 * The optional-tax feature gate (general.tax_enabled) is resolved per request
 * and drives whether a tax selector is offered; when disabled, item tax_id
 * never reaches validated(). Tax rates are snapshotted from the business's own
 * Tax rows at write time — the controller never computes amounts.
 */
class QuotationController extends Controller
{
    public function __construct(
        private readonly QuotationService $service,
        private readonly BusinessSettings $settings,
        private readonly CurrencyService $currencies,
    ) {
        //
    }

    public function index(Request $request): View
    {
        $searchTerm = trim((string) $request->string('search'));
        $statusFilter = QuotationStatus::tryFrom((string) $request->string('status'))?->value;

        $quotations = Quotation::query()
            ->with(['customer', 'createdBy'])
            ->when($searchTerm !== '', function ($query) use ($searchTerm) {
                $query->where(function ($query) use ($searchTerm) {
                    $query->where('quotation_number', 'like', "%{$searchTerm}%")
                        ->orWhereHas('customer', function ($query) use ($searchTerm) {
                            $query->where('name', 'like', "%{$searchTerm}%")
                                ->orWhere('company_name', 'like', "%{$searchTerm}%");
                        });
                });
            })
            ->when($statusFilter !== null, function ($query) use ($statusFilter) {
                $query->where('status', $statusFilter);
            })
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(15)
            ->withQueryString();

        return view('quotations.index', [
            'quotations' => $quotations,
            'searchTerm' => $searchTerm,
            'statusFilter' => $statusFilter,
            'statuses' => QuotationStatus::cases(),
        ]);
    }

    public function create(): View
    {
        $taxesEnabled = $this->taxesEnabled();

        return view('quotations.create', [
            'quotationsForm' => true,
            'customers' => $this->selectableCustomers(),
            'products' => $this->selectableProducts(),
            'taxesEnabled' => $taxesEnabled,
            'taxes' => $taxesEnabled ? $this->selectableTaxes() : collect(),
            'currencies' => $this->currencyOptions(),
            'currencyBase' => $this->baseCurrencyCode(),
        ]);
    }

    public function store(StoreQuotationRequest $request): RedirectResponse
    {
        $quotation = $this->service->create($request->validated(), (int) auth()->id());

        return redirect()
            ->route('quotations.show', $quotation)
            ->with('status', __('quotations.created'));
    }

    public function show(Quotation $quotation): View
    {
        return view('quotations.show', [
            'quotation' => $quotation->load(['customer', 'items', 'createdBy']),
            'editable' => $this->service->isEditable($quotation),
            'baseCurrency' => $this->baseCurrencyCode(),
        ]);
    }

    public function edit(Quotation $quotation): View
    {
        // Editing is only possible while the quotation is still a draft; any
        // later status is read-only (mirrors update()/destroy() in the service).
        abort_unless($this->service->isEditable($quotation), 403);

        $taxesEnabled = $this->taxesEnabled();

        return view('quotations.edit', [
            'quotation' => $quotation->load('items'),
            'customers' => $this->selectableCustomers(),
            'products' => $this->selectableProducts(),
            'taxesEnabled' => $taxesEnabled,
            'taxes' => $taxesEnabled ? $this->selectableTaxes() : collect(),
            'currencies' => $this->currencyOptions(),
            'currencyBase' => $this->baseCurrencyCode(),
        ]);
    }

    public function update(UpdateQuotationRequest $request, Quotation $quotation): RedirectResponse
    {
        $this->service->update($quotation, $request->validated());

        return redirect()
            ->route('quotations.show', $quotation)
            ->with('status', __('quotations.updated'));
    }

    public function destroy(Quotation $quotation): RedirectResponse
    {
        $this->service->destroy($quotation);

        return redirect()
            ->route('quotations.index')
            ->with('status', __('quotations.deleted'));
    }

    /**
     * Active (non soft-deleted) customers of the CURRENT business only.
     */
    private function selectableCustomers()
    {
        return Customer::query()->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Active (non soft-deleted) products of the CURRENT business only. Their
     * tax relation is loaded so the line editor can prefill a default tax.
     */
    private function selectableProducts()
    {
        return Product::query()->with('tax')->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Active (non soft-deleted) taxes of the CURRENT business only. Only used
     * while general.tax_enabled is on.
     */
    private function selectableTaxes()
    {
        return Tax::query()->orderBy('name')->orderBy('id')->get();
    }

    private function taxesEnabled(): bool
    {
        return (bool) $this->settings->get('general.tax_enabled', false);
    }

    /**
     * Enabled currencies of the CURRENT business for the quotation form.
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
