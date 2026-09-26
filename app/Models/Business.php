<?php

namespace App\Models;

use App\Services\BusinessContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Business extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * Batch 6 exposes only the business name. Ownership and membership are
     * always assigned server-side by the application, never from the browser.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    /**
     * The users that belong to this business.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'business_memberships')->withTimestamps();
    }

    /**
     * The membership rows linking users to this business.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(BusinessMembership::class);
    }

    /**
     * The business-scoped roles of this business.
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * The module enablement records for this business.
     */
    public function modules(): HasMany
    {
        return $this->hasMany(BusinessModule::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(BusinessSubscription::class);
    }

    /**
     * Provision the default enabled modules for this business.
     *
     * Idempotent: existing rows are reused (the unique business_id + module_key
     * key prevents duplicates). Called inside the same transaction as business
     * creation.
     */
    public function provisionDefaultModules(): void
    {
        foreach (config('modules.default_enabled', []) as $key) {
            BusinessModule::updateOrCreate(
                ['business_id' => $this->id, 'module_key' => $key],
                ['enabled' => true],
            );
        }
    }

    /**
     * Create the default roles for this business and map their permissions.
     *
     * Idempotent: existing system roles are reused (the unique business_id +
     * slug key prevents duplicates) and their permission grants are synced to
     * the configured defaults. Returns roles keyed by slug.
     *
     * @return array<string, Role>
     */
    public function provisionDefaultRoles(): array
    {
        $roles = [];

        foreach (config('roles.default_roles', []) as $slug => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $role = Role::firstOrCreate(
                ['business_id' => $this->id, 'slug' => $slug],
                [
                    'name' => $definition['name'] ?? $slug,
                    'description' => $definition['description'] ?? null,
                    'is_system' => $definition['is_system'] ?? false,
                ],
            );

            $permissionKeys = $definition['permissions'] ?? [];
            $permissionIds = Permission::whereIn('name', $permissionKeys)->pluck('id');
            $role->permissions()->sync($permissionIds);

            $roles[$slug] = $role;
        }

        return $roles;
    }

    /**
     * The current business for the authenticated user.
     *
     * Thin, documented aliases over the authoritative BusinessContext service.
     * New code should prefer app(BusinessContext::class) or these statics —
     * never a second resolution mechanism.
     */
    public static function current(): ?self
    {
        return app(BusinessContext::class)->current();
    }

    /**
     * The id of the current business, or null when there is none.
     */
    public static function currentId(): ?int
    {
        return app(BusinessContext::class)->currentId();
    }

    /**
     * Whether a business or tenant-owned model belongs to the current context.
     */
    public static function isCurrent(mixed $model): bool
    {
        return app(BusinessContext::class)->isCurrent($model);
    }
}
