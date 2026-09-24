<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessModule extends Model
{
    /**
     * Business module rows are created by server-side provisioning only.
     *
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'module_key',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
