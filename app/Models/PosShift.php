<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosShift extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'pos_register_id',
        'user_id',
        'status',
        'opened_at',
        'opening_cash',
        'closed_at',
        'closing_cash',
        'expected_cash',
        'cash_variance',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'decimal:4',
            'closing_cash' => 'decimal:4',
            'expected_cash' => 'decimal:4',
            'cash_variance' => 'decimal:4',
        ];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'pos_register_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(PosSale::class);
    }
}
