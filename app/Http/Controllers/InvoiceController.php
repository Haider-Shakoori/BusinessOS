<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Http\Requests\Invoice\StoreInvoiceRequest;
use App\Http\Requests\Invoice\UpdateInvoiceRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Tax;
use App\Services\BusinessSettings;
use App\Services\CurrencyService;
use App\Services\DocumentService;
use App\Services\PdfService;
use App\Services\InvoiceService;
use App\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Invoice CRUD + quotation conversion controller (Batch 15).
 *
 * All list queries are scoped to the current tenant by the BelongsToBusiness
 * global scope (Invoice::query()). Route-model binding resolves `{invoice}`
 * within the same scope — cross-business lookups return 404 automatically.
 *
 * Controller logic is intentionally thin: permission checks are handled by the
 * route middleware, business_id assignment by the BelongsToBusiness trait, the
 * draft-only lifecycle guard and the document number allocation by
 * InvoiceService (inside the same transaction as the insert), and the
 * one-to-one quotation conversion + concurrency protection by InvoiceService.
 *
 * `convert` posts to quotations.convert: convertibility (a non-converted
 * quotation in the CURRENT business) is re-verified under a row lock inside the
 * service. A rejected or expired quotation is still convertible — the operator
 * trusts the approved snapshot, never re-negotiates terms.
 *
 * The optional-tax feature gate (general.tax_enabled) mirrors quotations and
 * drives whether a tax selector is offered; tax rates are snapshotted from the
 * business's own Tax rows at write time.
 */
class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $service,
        private readonly BusinessSettings $settings,
        private readonly CurrencyService $currencies,
    ) {
        //
    }

    public function index(Request $request): View
    {
        $searchTerm = trim((string) $request->string('search'));
        $statusFilter = InvoiceStatus::tryFrom((string) $request->string('status'))?->value;

        $invoices = Invoice::query()
            ->with(['customer', 'createdBy', 'quotation'])
            ->when($searchTerm !== '', function ($query) use ($searchTerm) {
                $query->where(function ($query) use ($searchTerm) {
                    $query->where('invoice_number', 'like', "%{$searchTerm}%")
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

        return view('invoices.index', [
            'invoices' => $invoices,
            'searchTerm' => $searchTerm,
            'statusFilter' => $statusFilter,
            'statuses' => InvoiceStatus::cases(),
        ]);
    }

    public function create(): View
    {
        $taxesEnabled = $this->taxesEnabled();

        return view('invoices.create', [
            'invoicesForm' => true,
            'customers' => $this->selectableCustomers(),
            'products' => $this->selectableProducts(),
            'taxesEnabled' => $taxesEnabled,
            'taxes' => $taxesEnabled ? $this->selectableTaxes() : collect(),
            'currencies' => $this->currencyOptions(),
            'currencyBase' => $this->baseCurrencyCode(),
        ]);
    }

    public function store(StoreInvoiceRequest $request): RedirectResponse
    {
        $invoice = $this->service->create($request->validated(), (int) auth()->id());

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('status', __('invoices.created'));
    }

    public function show(Invoice $invoice): View
    {
        $invoice->load(['customer', 'items', 'createdBy', 'quotation']);

        return view('invoices.show', [
            'invoice' => $invoice,
            'editable' => $this->service->isEditable($invoice),
            'payable' => $this->isPayable($invoice),
            'baseCurrency' => $this->baseCurrencyCode(),
            'payments' => $invoice->payments()
                ->with('createdBy')
                ->orderBy('payment_date', 'desc')
                ->orderBy('id', 'desc')
                ->get(),
        ]);
    }

    public function print(Invoice $invoice, DocumentService $documents): View
    {
        $document = $documents->invoice($invoice);

        return view($document['view'], $document['data']);
    }

    public function pdf(Invoice $invoice, Request $request, DocumentService $documents, PdfService $pdfs): Response
    {
        return $pdfs->render(
            $documents->invoice($invoice, forPdf: true),
            $request->boolean('download'),
        );
    }

    /**
     * Whether the record-payment form can be offered on the show page: the
     * invoice must be issued but not fully paid, and there must be an actual
     * outstanding balance left. The service re-validates all of this again
     * under lock; this is only the UI affordance.
     */
    private function isPayable(Invoice $invoice): bool
    {
        return in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::PartiallyPaid], true)
            && Decimal::gt((string) $invoice->amount_due, '0');
    }

    public function edit(Invoice $invoice): View
    {
        // Editing is only possible while the invoice is still a draft; any
        // later status (sent, or converted) is read-only (mirrors update()/
        // destroy() in the service).
        abort_unless($this->service->isEditable($invoice), 403);

        $taxesEnabled = $this->taxesEnabled();

        return view('invoices.edit', [
            'invoice' => $invoice->load('items'),
            'customers' => $this->selectableCustomers(),
            'products' => $this->selectableProducts(),
            'taxesEnabled' => $taxesEnabled,
            'taxes' => $taxesEnabled ? $this->selectableTaxes() : collect(),
            'currencies' => $this->currencyOptions(),
            'currencyBase' => $this->baseCurrencyCode(),
        ]);
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->service->update($invoice, $request->validated());

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('status', __('invoices.updated'));
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $this->service->destroy($invoice);

        return redirect()
            ->route('invoices.index')
            ->with('status', __('invoices.deleted'));
    }

    /**
     * Convert the source quotation into its single invoice (POST + CSRF only).
     *
     * Permission, module, tenancy and one-to-one/concurrency rules are
     * enforced here and inside InvoiceService::convert(). A clean
     * (non-converted) quotation redirects to the new sent invoice.
     */
    public function convert(Quotation $quotation): RedirectResponse
    {
        $invoice = $this->service->convert($quotation, (int) auth()->id());

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('status', __('invoices.converted'));
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
     * Enabled currencies of the CURRENT business for the document form.
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
