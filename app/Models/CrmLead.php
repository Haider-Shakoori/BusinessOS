<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmLead extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    protected $fillable = [
        'name', 'company', 'email', 'phone', 'status', 'source',
        'estimated_value', 'next_follow_up_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:4',
            'next_follow_up_at' => 'datetime',
        ];
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CrmActivity::class);
    }
}
