<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FieldPulseEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
        ];
    }
}
