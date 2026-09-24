<?php

namespace App\Enums;

/**
 * Payment channels accepted at recording time (Batch 16).
 *
 * Values are stable, DB-level machine keys (following the approved
 * DATABASE_PLAN method set). Requests validate against these exact values via
 * Rule::in so a form can never invent a channel. Display labels live in the
 * payments language files (en/fa/ar); a payment_method is just a label on the
 * financial event — it carries no side effects on invoices, taxes, or stock.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';

    case BankTransfer = 'bank_transfer';

    case Card = 'card';

    case Cheque = 'cheque';

    case Mobile = 'mobile';

    case Other = 'other';
}
