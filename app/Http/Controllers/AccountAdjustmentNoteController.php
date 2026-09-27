<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Services\AccountAdjustmentNoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountAdjustmentNoteController extends Controller
{
    public function customerCredit(Request $request, Invoice $invoice, AccountAdjustmentNoteService $notes): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'note_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $note = $notes->customerCredit($invoice, (string) $data['amount'], $data['note_date'], $data['reason'] ?? null, (int) auth()->id());

        return back()->with('status', 'Credit note '.$note->number.' posted.');
    }

    public function supplierDebit(Request $request, PurchaseOrder $purchaseOrder, AccountAdjustmentNoteService $notes): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'note_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $note = $notes->supplierDebit($purchaseOrder, (string) $data['amount'], $data['note_date'], $data['reason'] ?? null, (int) auth()->id());

        return back()->with('status', 'Debit note '.$note->number.' posted.');
    }
}
