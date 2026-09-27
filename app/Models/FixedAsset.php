<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FixedAsset extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    protected $fillable = [
        'asset_category_id',
        'cost_center_id',
        'asset_number',
        'name',
        'serial_number',
        'location',
        'acquisition_date',
        'in_service_date',
        'acquisition_cost',
        'salvage_value',
        'useful_life_months',
        'depreciation_method',
        'status',
        'accumulated_depreciation',
        'book_value',
        'last_depreciated_through',
        'disposed_at',
        'disposal_proceeds',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'acquisition_date' => 'date',
            'in_service_date' => 'date',
            'acquisition_cost' => 'decimal:4',
            'salvage_value' => 'decimal:4',
            'accumulated_depreciation' => 'decimal:4',
            'book_value' => 'decimal:4',
            'last_depreciated_through' => 'date',
            'disposed_at' => 'date',
            'disposal_proceeds' => 'decimal:4',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function depreciationEntries(): HasMany
    {
        return $this->hasMany(AssetDepreciationEntry::class);
    }
}
