<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Internal numbering state (Batch 13).
 *
 * One row per business + document_type holding the last allocated number. This
 * model is intentionally NOT part of the application's tenant models:
 *
 *   - no BelongsToBusiness scope — the service scopes queries explicitly by
 *     the current business id (mirroring Setting), because the row is shared
 *     mutable state, not a business-owned entity;
 *   - no mass-assignment surface ($guarded = ['*']) — only
 *     App\Services\DocumentNumberService creates or writes rows;
 *   - business_id is always supplied by the service from BusinessContext and
 *     is never taken from a request.
 */
class DocumentNumberSequence extends Model
{
    protected $guarded = ['*'];
}
