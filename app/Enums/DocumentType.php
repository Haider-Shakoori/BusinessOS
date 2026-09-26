<?php

namespace App\Enums;

/**
 * Document kinds that consume a numbering sequence (Batch 13).
 *
 * Values are stable machine keys shared by the document_number_sequences table
 * and the `numbering.{value}_prefix` settings overrides. Renaming a case would
 * silently change serialized data, so add new kinds instead of altering
 * existing ones. Each business maintains an independent monotonic sequence per
 * kind that never resets; the following kinds are the stable set the roadmap
 * plans their own batches for later in this release.
 */
enum DocumentType: string
{
    case Quotation = 'quotation';

    case Invoice = 'invoice';

    case Payment = 'payment';

    case Expense = 'expense';

    case PurchaseOrder = 'purchase_order';

    case PosSale = 'pos_sale';
}
