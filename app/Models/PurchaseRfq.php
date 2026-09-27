<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRfq extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'purchase_requisition_id',
        'number',
        'status',
        'issue_date',
        'response_due_date',
        'notes',
        'created_by',
        'opened_at',
        'awarded_at',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'response_due_date' => 'date',
            'opened_at' => 'datetime',
            'awarded_at' => 'datetime',
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invitedSuppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'purchase_rfq_suppliers')
            ->withPivot(['invited_at', 'responded_at'])
            ->withTimestamps();
    }

    public function supplierInvitations(): HasMany
    {
        return $this->hasMany(PurchaseRfqSupplier::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(SupplierQuotation::class);
    }
}
