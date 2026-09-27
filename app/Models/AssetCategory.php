<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetCategory extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'code',
        'name',
        'depreciation_method',
        'useful_life_months',
        'salvage_percent',
        'asset_account_code',
        'accumulated_depreciation_account_code',
        'depreciation_expense_account_code',
    ];

    protected function casts(): array
    {
        return [
            'salvage_percent' => 'decimal:4',
        ];
    }

    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class);
    }
}
