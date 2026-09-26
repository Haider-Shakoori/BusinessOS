<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaasPlan extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'monthly_price',
        'annual_price',
        'currency_code',
        'limits',
        'features',
        'is_active',
        'is_default',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'decimal:4',
            'annual_price' => 'decimal:4',
            'limits' => 'array',
            'features' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(BusinessSubscription::class);
    }
}
