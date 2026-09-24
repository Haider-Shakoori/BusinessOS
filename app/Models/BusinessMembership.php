<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BusinessMembership extends Model
{
    /**
     * Membership rows are created server-side only (onboarding, provisioning).
     * business_id/user_id are never accepted from requests.
     *
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'user_id',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Roles assigned to THIS membership (never to the user globally). The same
     * user may therefore hold different roles in different businesses.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'business_membership_role', 'membership_id', 'role_id')->withTimestamps();
    }

    /**
     * Assign a role to this membership, blocking cross-business assignments.
     *
     * The role must belong to the SAME business as the membership. A role from
     * another business can never be attached through the application API.
     */
    public function assignRole(Role $role): void
    {
        if ($role->business_id !== $this->business_id) {
            throw new \InvalidArgumentException('A role may only be assigned to a membership of its own business.');
        }

        $this->roles()->syncWithoutDetaching([$role->id]);
    }

    /**
     * Whether this membership's roles grant the given permission.
     *
     * Only roles belonging to this membership's own business are considered:
     * even a directly inserted, forged pivot row pointing at a foreign role is
     * ignored, so raw role ids can never escalate privileges cross-business.
     * The result is deterministic and independent of the "current" business.
     */
    public function hasPermission(string $permission): bool
    {
        return $this->roles()
            ->where('roles.business_id', $this->business_id)
            ->whereHas('permissions', fn (Builder $q) => $q->where('permissions.name', $permission))
            ->exists();
    }
}
