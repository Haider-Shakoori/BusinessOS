<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('permissions')->count() === 0) {
            return;
        }

        $permissions = [
            'suppliers.view' => 'suppliers',
            'suppliers.manage' => 'suppliers',
        ];

        foreach ($permissions as $name => $group) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                [
                    'group' => $group,
                    'description' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_keys($permissions))
            ->pluck('id', 'name');

        $grants = [
            'owner' => array_keys($permissions),
            'admin' => array_keys($permissions),
            'viewer' => ['suppliers.view'],
        ];

        foreach ($grants as $roleSlug => $names) {
            $roleIds = DB::table('roles')
                ->where('slug', $roleSlug)
                ->where('is_system', true)
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                foreach ($names as $name) {
                    $permissionId = $permissionIds[$name] ?? null;

                    if ($permissionId === null) {
                        continue;
                    }

                    DB::table('role_permission')->updateOrInsert(
                        [
                            'role_id' => $roleId,
                            'permission_id' => $permissionId,
                        ],
                        [
                            'updated_at' => now(),
                            'created_at' => now(),
                        ],
                    );
                }
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', ['suppliers.view', 'suppliers.manage'])
            ->pluck('id');

        DB::table('role_permission')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
