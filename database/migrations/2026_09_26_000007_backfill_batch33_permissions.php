<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fresh installations are seeded immediately after migrations, which
        // preserves the canonical config order used by the authorization
        // catalogue. This migration exists only to upgrade installations that
        // already have a permission catalogue from earlier batches.
        if (DB::table('permissions')->count() === 0) {
            return;
        }

        $permissions = [
            'assistant.use' => 'assistant',
            'businesses.view' => 'businesses',
            'businesses.manage' => 'businesses',
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
            'owner' => ['assistant.use', 'businesses.view', 'businesses.manage'],
            'admin' => ['assistant.use', 'businesses.view', 'businesses.manage'],
            'viewer' => ['assistant.use', 'businesses.view'],
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
            ->whereIn('name', ['assistant.use', 'businesses.view', 'businesses.manage'])
            ->pluck('id');

        DB::table('role_permission')
            ->whereIn('permission_id', $permissionIds)
            ->delete();

        DB::table('permissions')
            ->whereIn('id', $permissionIds)
            ->delete();
    }
};
