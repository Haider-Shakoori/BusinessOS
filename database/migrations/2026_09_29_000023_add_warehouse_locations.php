<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->string('type', 30)->default('bin');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['warehouse_id', 'code']);
            $table->index(['business_id', 'warehouse_id', 'is_active'], 'warehouse_location_business_wh_idx');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('warehouse_id')
                ->constrained('warehouse_locations')
                ->restrictOnDelete();
            $table->index(['business_id', 'warehouse_id', 'location_id', 'product_id'], 'stock_business_location_product_idx');
        });

        Schema::table('inventory_counts', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('warehouse_id')
                ->constrained('warehouse_locations')
                ->restrictOnDelete();
        });

        Schema::table('warehouse_transfer_items', function (Blueprint $table) {
            $table->foreignId('source_location_id')
                ->nullable()
                ->after('product_variant_id')
                ->constrained('warehouse_locations')
                ->restrictOnDelete();
            $table->foreignId('destination_location_id')
                ->nullable()
                ->after('source_location_id')
                ->constrained('warehouse_locations')
                ->restrictOnDelete();
        });

        $now = now();

        foreach (DB::table('warehouses')->orderBy('id')->get(['id', 'business_id']) as $warehouse) {
            $locationId = DB::table('warehouse_locations')->insertGetId([
                'business_id' => $warehouse->business_id,
                'warehouse_id' => $warehouse->id,
                'parent_id' => null,
                'code' => 'MAIN',
                'name' => 'Main Location',
                'type' => 'bin',
                'is_active' => true,
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('stock_movements')
                ->where('warehouse_id', $warehouse->id)
                ->whereNull('location_id')
                ->update(['location_id' => $locationId]);

            DB::table('inventory_counts')
                ->where('warehouse_id', $warehouse->id)
                ->whereNull('location_id')
                ->update(['location_id' => $locationId]);

            DB::table('warehouse_transfer_items')
                ->whereNull('source_location_id')
                ->whereIn('warehouse_transfer_id', function ($query) use ($warehouse): void {
                    $query->select('id')
                        ->from('warehouse_transfers')
                        ->where('source_warehouse_id', $warehouse->id);
                })
                ->update(['source_location_id' => $locationId]);

            DB::table('warehouse_transfer_items')
                ->whereNull('destination_location_id')
                ->whereIn('warehouse_transfer_id', function ($query) use ($warehouse): void {
                    $query->select('id')
                        ->from('warehouse_transfers')
                        ->where('destination_warehouse_id', $warehouse->id);
                })
                ->update(['destination_location_id' => $locationId]);
        }
    }

    public function down(): void
    {
        Schema::table('warehouse_transfer_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('destination_location_id');
            $table->dropConstrainedForeignId('source_location_id');
        });

        Schema::table('inventory_counts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_business_location_product_idx');
            $table->dropConstrainedForeignId('location_id');
        });

        Schema::dropIfExists('warehouse_locations');
    }
};
