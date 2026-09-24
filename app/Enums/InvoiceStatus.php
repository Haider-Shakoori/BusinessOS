<?php

namespace App\Enums;

/**
 * Invoice lifecycle state (Build invoice lifecycle, payments owned later).
 *
 * `draft` invoices are editable and deletable; `sent` invoices are finalized,
 * immutable document snapshots (the "record the issued invoice" point).
 *
 * Batch 16 adds the two payment-derived states:
 *
 *   draft -> sent -> partially_paid -> paid
 *              ^________________________/
 *
 * PaymentService owns the transitions: recording or reversing a payment
 * recomputes the status from the active-payment aggregate — no money is ever
 * paid into a draft, and reaching an exact balance of zero (amount_due == 0)
 * marks the invoice `paid`. Once an invoice leaves `draft` it is never
 * editable or deletable, so a payed or partially-paid invoice is as immutable
 * as a sent one.
 *
 * `overdue` is deliberately NOT a stored status: it is a derived, time-based
 * view that belongs to a later batch owning a due-date basis and scheduling.
 * Cancellation likewise remains future work.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';

    case Sent = 'sent';

    case PartiallyPaid = 'partially_paid';

    case Paid = 'paid';
}
