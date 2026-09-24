<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Seed the system-defined, globally shared permission keys (Batch 7).
     *
     * Idempotent by design (updateOrCreate on the unique `name`), so it can be
     * re-run alongside new module permissions in later batches.
     */
    public function run(): void
    {
        foreach (config('permissions.groups', []) as $group => $keys) {
            foreach ($keys as $key) {
                Permission::updateOrCreate(
                    ['name' => $key],
                    ['group' => $group, 'description' => null],
                );
            }
        }
    }
}
