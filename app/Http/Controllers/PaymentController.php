<?php

namespace App\Http\Controllers;

use App\Http\Requests\Payment\ReversePaymentRequest;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Payment listing, viewing, recording and reversal (Batch 16).
 *
 * Controller logic is intentionally thin: permission checks are handled by the
 * route middleware, business_id assignment and tenancy by the
 * BelongsToBusiness global scope (cross-business route lookups return 404),
 * the payment number by DocumentNumberService, and all financial rules
 * (payable state, overpayment rejection, reconciliation, reversal semantics,
 * locks) by PaymentService inside transactions.
 *
 * Lists eager-load the allocation's invoice WITH its soft-deleted rows so the
 * financial history of a soft-deleted invoice remains fully renderable: the
 * payment record is never orphaned on-screen.
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $service,
    ) {
        //
    }

    public function index(): View
    {
        $searchTerm = trim((string) request()->string('search'));

        $payments = Payment::query()
            ->with([
                'allocations.invoice' => fn ($query) => $query->withTrashed(),
                'allocations.invoice.customer',
                'supplier',
                'createdBy',
            ])
            ->when($searchTerm !== '', function ($query) use ($searchTerm) {
                $query->where(function ($query) use ($searchTerm) {
                    $query->where('payment_number', 'like', "%{$searchTerm}%")
                        ->orWhere('reference', 'like', "%{$searchTerm}%")
                        ->orWhereHas('supplier', function ($query) use ($searchTerm) {
                            $query->where('name', 'like', "%{$searchTerm}%")
                                ->orWhere('code', 'like', "%{$searchTerm}%");
                        })
                        ->orWhereHas('allocations.invoice', function ($query) use ($searchTerm) {
                            $query->withTrashed()
                                ->where('invoice_number', 'like', "%{$searchTerm}%")
                                ->orWhereHas('customer', function ($query) use ($searchTerm) {
                                    $query->where('name', 'like', "%{$searchTerm}%")
                                        ->orWhere('company_name', 'like', "%{$searchTerm}%");
                                });
                        });
                });
            })
            ->orderBy('payment_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(15)
            ->withQueryString();

        return view('payments.index', [
            'payments' => $payments,
            'searchTerm' => $searchTerm,
        ]);
    }

    public function show(Payment $payment): View
    {
        return view('payments.show', [
            'payment' => $payment->load([
                'createdBy',
                'reversedBy',
                'supplier',
                'allocations.invoice' => fn ($query) => $query->withTrashed(),
                'allocations.invoice.customer',
            ]),
        ]);
    }

    /**
     * The Invoice show form records a payment and returns to that invoice.
     */
    public function store(StorePaymentRequest $request): RedirectResponse
    {
        $payment = $this->service->record($request->validated(), (int) auth()->id());

        return redirect()
            ->route('invoices.show', $payment->invoice)
            ->with('status', __('payments.created'));
    }

    /**
     * Mark a payment reversed (POST + CSRF only, never GET).
     */
    public function reverse(ReversePaymentRequest $request, Payment $payment): RedirectResponse
    {
        $this->service->reverse($payment, $request->validated()['reversal_reason'] ?? null, (int) auth()->id());

        return redirect()
            ->route('payments.show', $payment)
            ->with('status', __('payments.reversed'));
    }
}
