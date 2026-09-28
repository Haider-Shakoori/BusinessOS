<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_reorder_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->string('stock_key', 80);
            $table->decimal('reorder_point', 20, 4);
            $table->decimal('target_stock', 20, 4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'warehouse_id', 'stock_key'], 'reorder_rule_business_wh_stock_unique');
            $table->index(['business_id', 'warehouse_id', 'is_active'], 'reorder_rule_business_wh_active_idx');
            $table->index(['business_id', 'product_id', 'product_variant_id'], 'reorder_rule_business_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reorder_rules');
    }
};
