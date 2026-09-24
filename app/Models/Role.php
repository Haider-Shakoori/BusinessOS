<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    /**
     * Roles belong to exactly one business. business_id is filled from the
     * business relationship, never from user input.
     *
     * NOTE: Role deliberately does NOT use the BelongsToBusiness global scope.
     * Roles are authorization metadata, never user-facing tenant data: they are
     * reachable only through a business/membership relationship, and every
     * permission check filters them to the membership's own business. The
     * business_id foreign key + (business_id, slug) uniqueness carry the tenant
     * isolation, which keeps role provisioning/audit queries context-neutral.
     *
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'name',
        'slug',
        'description',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission')->withTimestamps();
    }

    /**
     * Whether this role directly grants the given permission key.
     */
    public function hasPermission(string $permission): bool
    {
        return $this->permissions()->where('permissions.name', $permission)->exists();
    }
}
