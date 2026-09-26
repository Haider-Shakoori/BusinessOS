<?php

namespace App\Http\Controllers;

use App\Models\BusinessMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\BusinessContext;
use App\Services\BusinessNotificationService;
use App\Services\SaasUsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserRoleController extends Controller
{
    public function index(BusinessContext $context): View
    {
        $business = $context->current();

        abort_unless($business, 404);

        return view('system.users-roles', [
            'business' => $business,
            'memberships' => BusinessMembership::query()
                ->where('business_id', $business->id)
                ->with(['user', 'roles.permissions'])
                ->orderBy('id')
                ->get(),
            'roles' => Role::query()
                ->where('business_id', $business->id)
                ->with('permissions')
                ->orderByDesc('is_system')
                ->orderBy('name')
                ->get(),
            'permissions' => Permission::query()->orderBy('group')->orderBy('name')->get()->groupBy('group'),
        ]);
    }

    public function storeMember(
        Request $request,
        BusinessContext $context,
        BusinessNotificationService $notifications,
        SaasUsageService $saasUsage,
    ): RedirectResponse {
        $business = $context->current();
        $businessId = $business?->id;

        abort_unless($business, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')->where('business_id', $businessId),
            ],
        ]);

        $existingUser = User::query()
            ->where('email', strtolower($data['email']))
            ->first();
        $alreadyMember = $existingUser?->memberships()
            ->where('business_id', $businessId)
            ->exists() ?? false;

        if (! $alreadyMember && ! $saasUsage->canAdd($business, 'members')) {
            throw ValidationException::withMessages([
                'email' => __('saas.limit_reached', ['resource' => __('saas.members')]),
            ]);
        }

        $role = Role::query()
            ->where('business_id', $businessId)
            ->findOrFail($data['role_id']);

        $created = false;

        $membership = DB::transaction(function () use ($data, $businessId, $role, &$created): BusinessMembership {
            $user = User::query()->where('email', strtolower($data['email']))->first();

            if (! $user) {
                if (blank($data['password'] ?? null)) {
                    throw ValidationException::withMessages([
                        'password' => __('system.users.password_required_for_new_user'),
                    ]);
                }

                $user = User::create([
                    'name' => $data['name'],
                    'email' => strtolower($data['email']),
                    'password' => Hash::make($data['password']),
                ]);

                $created = true;
            }

            $membership = BusinessMembership::query()->firstOrCreate([
                'business_id' => $businessId,
                'user_id' => $user->id,
            ]);

            $membership->roles()->sync([$role->id]);

            return $membership;
        });

        $notifications->create(
            __('system.notifications.member_title'),
            __('system.notifications.member_message', ['name' => $membership->user->name]),
            route('system.users-roles.index'),
            null,
            'member',
            ['membership_id' => $membership->id],
        );

        return back()->with('status', $created
            ? __('system.users.member_created')
            : __('system.users.member_attached'));
    }

    public function updateMember(
        Request $request,
        BusinessMembership $membership,
        BusinessContext $context,
    ): RedirectResponse {
        abort_unless($membership->business_id === $context->currentId(), 404);

        $data = $request->validate([
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')->where('business_id', $context->currentId()),
            ],
        ]);

        $role = Role::query()
            ->where('business_id', $context->currentId())
            ->findOrFail($data['role_id']);

        $ownerRole = Role::query()
            ->where('business_id', $context->currentId())
            ->where('slug', config('roles.owner_role', 'owner'))
            ->first();

        if (
            $ownerRole
            && $membership->roles()->whereKey($ownerRole->id)->exists()
            && $role->id !== $ownerRole->id
        ) {
            $otherOwnerCount = BusinessMembership::query()
                ->where('business_id', $context->currentId())
                ->where('id', '!=', $membership->id)
                ->whereHas('roles', fn ($query) => $query->whereKey($ownerRole->id))
                ->count();

            if ($otherOwnerCount === 0) {
                return back()->withErrors(['role_id' => __('system.users.last_owner_required')]);
            }
        }

        $membership->roles()->sync([$role->id]);

        return back()->with('status', __('system.users.role_updated'));
    }

    public function storeRole(Request $request, BusinessContext $context): RedirectResponse
    {
        $businessId = $context->currentId();

        abort_unless($businessId, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::unique('roles', 'slug')->where('business_id', $businessId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        DB::transaction(function () use ($data, $businessId): void {
            $role = Role::create([
                'business_id' => $businessId,
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'is_system' => false,
            ]);

            $permissionIds = Permission::query()
                ->whereIn('name', $data['permissions'] ?? [])
                ->pluck('id');

            $role->permissions()->sync($permissionIds);
        });

        return back()->with('status', __('system.roles.created'));
    }

    public function updateRole(
        Request $request,
        Role $role,
        BusinessContext $context,
    ): RedirectResponse {
        abort_unless($role->business_id === $context->currentId(), 404);
        abort_if($role->is_system, 422, __('system.roles.system_read_only'));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        DB::transaction(function () use ($role, $data): void {
            $role->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);

            $role->permissions()->sync(
                Permission::query()
                    ->whereIn('name', $data['permissions'] ?? [])
                    ->pluck('id'),
            );
        });

        return back()->with('status', __('system.roles.updated'));
    }
}
