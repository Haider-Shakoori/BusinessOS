<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_active');
            $table->index(['business_id', 'is_default'], 'warehouse_business_default_idx');
        });

        $businessIds = DB::table('warehouses')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->distinct()
            ->pluck('business_id');

        foreach ($businessIds as $businessId) {
            $warehouseId = DB::table('warehouses')
                ->where('business_id', $businessId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->orderBy('id')
                ->value('id');

            if ($warehouseId !== null) {
                DB::table('warehouses')->where('id', $warehouseId)->update(['is_default' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropIndex('warehouse_business_default_idx');
            $table->dropColumn('is_default');
        });
    }
};
