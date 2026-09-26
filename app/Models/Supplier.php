<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    protected $fillable = ['code', 'name', 'email', 'phone', 'address', 'opening_balance', 'opening_balance_date', 'notes', 'is_active'];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:4',
            'opening_balance_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'party_id')
            ->where('party_type', 'supplier');
    }
}
