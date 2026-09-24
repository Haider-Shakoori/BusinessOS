<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Global currency reference (Batch 19).
 *
 * A public registry shared by every business — deliberately NOT tenant-owned,
 * so the same currencies table serves all businesses. Codes are unique ISO
 * 4217 identifiers; `is_active` gates which codes may be enabled by a business
 * or used on new documents while keeping history renderable after a code is
 * deactivated. The per-business dimensions live in BusinessCurrency (enabled
 * currencies) and ExchangeRate (rates).
 */
class Currency extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'symbol',
        'decimals',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Only currently usable codes (for pickers and validation).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Which businesses have enabled this currency.
     */
    public function businesses(): HasMany
    {
        return $this->hasMany(BusinessCurrency::class, 'currency_code', 'code');
    }
}
