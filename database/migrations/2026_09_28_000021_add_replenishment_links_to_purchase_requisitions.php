<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->foreignId('warehouse_id')
                ->nullable()
                ->after('business_id')
                ->constrained()
                ->restrictOnDelete();
        });

        Schema::table('purchase_requisition_items', function (Blueprint $table) {
            $table->foreignId('inventory_reorder_rule_id')
                ->nullable()
                ->after('purchase_requisition_id')
                ->constrained('inventory_reorder_rules')
                ->nullOnDelete();

            $table->index(
                ['inventory_reorder_rule_id', 'purchase_requisition_id'],
                'purchase_req_item_reorder_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requisition_items', function (Blueprint $table) {
            $table->dropIndex('purchase_req_item_reorder_idx');
            $table->dropConstrainedForeignId('inventory_reorder_rule_id');
        });

        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
        });
    }
};
