<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // On a fresh install PermissionSeeder runs after migrations and creates
        // the complete catalogue in config order. Existing installations
        // already have permissions, so this migration adds the new key and
        // attaches it to their system roles.
        if (! DB::table('permissions')->exists()) {
            return;
        }

        DB::table('permissions')->updateOrInsert(
            ['name' => 'reports.view'],
            [
                'group' => 'reports',
                'description' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        $permissionId = DB::table('permissions')->where('name', 'reports.view')->value('id');

        if ($permissionId === null) {
            return;
        }

        $roleIds = DB::table('roles')
            ->where('is_system', true)
            ->whereIn('slug', ['owner', 'admin', 'viewer'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_permission')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                ['updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'reports.view')->value('id');

        if ($permissionId === null) {
            return;
        }

        DB::table('role_permission')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
