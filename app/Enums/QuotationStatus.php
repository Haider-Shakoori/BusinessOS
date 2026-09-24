<?php

namespace App\Enums;

/**
 * Quotation lifecycle state (Batch 14).
 *
 * The roadmap workflow is: draft -> sent -> accepted/rejected/expired ->
 * converted. `converted` is the reserved terminal state for the Batch 15
 * quotation->invoice conversion path and is intentionally NOT settable through
 * any Batch 14 form, validation rule, or controller action. All other values
 * are selectable while a quotation is still a draft.
 */
enum QuotationStatus: string
{
    case Draft = 'draft';

    case Sent = 'sent';

    case Accepted = 'accepted';

    case Rejected = 'rejected';

    case Expired = 'expired';

    case Converted = 'converted';
}
