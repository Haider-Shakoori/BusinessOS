<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FieldPulseEntityLink extends Model
{
    protected $fillable = [
        'business_id',
        'entity_type',
        'fieldpulse_uuid',
        'local_id',
    ];
}
