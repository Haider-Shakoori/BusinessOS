<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Role;
use App\Models\User;

/**
 * The single authorization entry point for the current-tenancy model.
 *
 * It answers the question the whole app asks:
 *
 *     Can the current user perform permission X inside the current business?
 *
 * The user defaults to the authenticated user and the business default to the
 * authoritative BusinessContext resolution. Explicit user/business parameters
 * are only used by tests and future server-side paths — never from requests.
 *
 * Conventions this batch establishes:
 * - Permissions are checked against the authenticated user's membership in the
 *   evaluated business (User + Current Business + Membership + Roles).
 * - The current business ALWAYS controls the authorization context; switching
 *   businesses changes the effective permissions.
 * - No business context (guest, or user without businesses) means deny.
 */
class MembershipAuthorization
{
    public function __construct(private readonly BusinessContext $context)
    {
        //
    }

    /**
     * Whether the user has the given permission in the given business.
     *
     * @param  User|null  $user  authenticated user (defaults to context user)
     * @param  Business|null  $business  business to evaluate in (defaults to current business)
     */
    public function can(string $permission, ?User $user = null, ?Business $business = null): bool
    {
        $user ??= $this->context->user();
        $business ??= $this->context->current();

        if ($user === null || $business === null) {
            return false;
        }

        $membership = $user->memberships()->where('business_id', $business->id)->first();

        return $membership?->hasPermission($permission) ?? false;
    }

    /**
     * The first role the membership holds in the given business, or null.
     *
     * Used by the shell for display only — never as an authorization check.
     */
    public function currentRole(?User $user = null, ?Business $business = null): ?Role
    {
        $user ??= $this->context->user();
        $business ??= $this->context->current();

        if ($user === null || $business === null) {
            return null;
        }

        $membership = $user->memberships()->where('business_id', $business->id)->first();

        return $membership?->roles()->first();
    }
}
