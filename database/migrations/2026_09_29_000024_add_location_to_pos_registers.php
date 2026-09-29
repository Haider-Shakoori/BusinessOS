<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_registers', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('warehouse_id')
                ->constrained('warehouse_locations')
                ->restrictOnDelete();
            $table->index(['business_id', 'warehouse_id', 'location_id'], 'pos_register_business_location_idx');
        });

        foreach (DB::table('pos_registers')->orderBy('id')->get(['id', 'warehouse_id']) as $register) {
            $locationId = DB::table('warehouse_locations')
                ->where('warehouse_id', $register->warehouse_id)
                ->where('is_default', true)
                ->where('is_active', true)
                ->value('id');

            $locationId ??= DB::table('warehouse_locations')
                ->where('warehouse_id', $register->warehouse_id)
                ->where('is_active', true)
                ->orderBy('id')
                ->value('id');

            if ($locationId !== null) {
                DB::table('pos_registers')
                    ->where('id', $register->id)
                    ->update(['location_id' => $locationId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('pos_registers', function (Blueprint $table) {
            $table->dropIndex('pos_register_business_location_idx');
            $table->dropConstrainedForeignId('location_id');
        });
    }
};
