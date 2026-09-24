<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;

/**
 * The single authoritative mechanism for resolving the current business.
 *
 * Resolution rules:
 * - Only authenticated users can have a current business.
 * - The session may store a selected business id, but it is trusted ONLY when
 *   it belongs to the authenticated user. Otherwise it is discarded.
 * - When no valid selection exists, the smallest accessible business id is
 *   chosen deterministically and persisted.
 * - A user with no businesses resolves to null (onboarding responsibility).
 *
 * The `Business` model exposes thin static aliases (Business::current,
 * Business::currentId, Business::isCurrent) that delegate here so there is
 * exactly ONE current-business mechanism in the codebase.
 */
class BusinessContext
{
    public function __construct(private readonly AuthFactory $auth)
    {
        //
    }

    private bool $userResolved = false;

    private ?User $user = null;

    private bool $businessesResolved = false;

    private ?Collection $businesses = null;

    private bool $currentResolved = false;

    private ?Business $current = null;

    private bool $membershipResolved = false;

    private ?BusinessMembership $membership = null;

    public function user(): ?User
    {
        if (! $this->userResolved) {
            $this->user = $this->auth->guard('web')->user();
            $this->userResolved = true;
        }

        return $this->user;
    }

    /**
     * All businesses the authenticated user is a member of, ordered by id.
     */
    public function businesses(): Collection
    {
        if (! $this->businessesResolved) {
            $this->businesses = $this->user()?->businesses()->orderBy('businesses.id')->get() ?? collect();
            $this->businessesResolved = true;
        }

        return $this->businesses;
    }

    /**
     * The ids of the businesses the authenticated user belongs to.
     */
    public function accessibleIds(): Collection
    {
        return $this->businesses()->pluck('id');
    }

    /**
     * Resolve the current business, validating any stored selection against the
     * user's membership. A stale or forged selection is discarded and replaced
     * deterministically. Returns null for unauthenticated users and for users
     * with no businesses.
     */
    public function current(): ?Business
    {
        if ($this->currentResolved) {
            return $this->current;
        }

        $this->currentResolved = true;

        if ($this->user() === null) {
            return $this->current = null;
        }

        $ids = $this->accessibleIds();

        if ($ids->isEmpty()) {
            return $this->current = null;
        }

        $selected = (int) Session::get($this->sessionKey(), 0);

        if ($selected !== 0 && $ids->contains($selected)) {
            return $this->current = Business::find($selected);
        }

        if ($selected !== 0) {
            // The stored selection does not belong to this user: discard it.
            Session::forget($this->sessionKey());
        }

        $fallbackId = (int) $ids->min();
        Session::put($this->sessionKey(), $fallbackId);

        return $this->current = Business::find($fallbackId);
    }

    /**
     * The id of the current business, or null when there is none.
     */
    public function currentId(): ?int
    {
        return $this->current()?->id;
    }

    /**
     * Resolve the authenticated user's membership in the current business.
     *
     * Resolved only from the authenticated user + the validated current
     * business context — never from arbitrary request values. Returns null for
     * guests, for users without a membership in the current business, or when
     * there is no current business at all.
     */
    public function membership(): ?BusinessMembership
    {
        if ($this->membershipResolved) {
            return $this->membership;
        }

        $this->membershipResolved = true;

        $user = $this->user();
        $current = $this->current();

        if ($user === null || $current === null) {
            return $this->membership = null;
        }

        return $this->membership = $user->memberships()->where('business_id', $current->id)->first();
    }

    /**
     * Whether a model belongs to the current business context.
     *
     * Accepts a Business instance or any tenant-owned model carrying a
     * business_id attribute (future modules using BelongsToBusiness).
     */
    public function isCurrent(mixed $model): bool
    {
        $currentId = $this->currentId();

        if ($currentId === null) {
            return false;
        }

        if ($model instanceof Business) {
            return $model->id === $currentId;
        }

        return ($model->business_id ?? null) === $currentId;
    }

    /**
     * Switch the current business for the authenticated user.
     *
     * Ownership is validated server-side against the current membership —
     * never against the request value alone. Returns the business on success,
     * or null when the id is not owned by the user (caller must reject it).
     */
    public function switchTo(int $businessId): ?Business
    {
        $user = $this->user();

        if ($user === null) {
            return null;
        }

        $business = $user->businesses()->find($businessId);

        if ($business === null) {
            return null;
        }

        Session::put($this->sessionKey(), $businessId);
        $this->current = $business;
        $this->currentResolved = true;
        $this->businesses = null;
        $this->businessesResolved = false;
        $this->membership = null;
        $this->membershipResolved = false;

        return $business;
    }

    /**
     * Clear the stored selection (do not clear memberships).
     */
    public function forget(): void
    {
        Session::forget($this->sessionKey());
        $this->current = null;
        $this->currentResolved = true;
        $this->membership = null;
        $this->membershipResolved = true;
    }

    protected function sessionKey(): string
    {
        return config('business.context.session_key', 'current_business');
    }
}
