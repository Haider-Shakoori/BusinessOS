<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('sku', 50)->nullable();
            $table->decimal('sale_price', 16, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'sku']);
            $table->index(['business_id', 'product_id', 'is_active'], 'variant_business_product_active_idx');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variants')->restrictOnDelete();
            $table->index(['business_id', 'product_id', 'product_variant_id', 'warehouse_id'], 'stock_variant_balance_idx');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variants')->restrictOnDelete();
        });

        Schema::table('pos_sale_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variants')->restrictOnDelete();
        });

        Schema::table('warehouse_transfer_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variants')->restrictOnDelete();
        });

        Schema::table('inventory_return_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_return_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
        });
        Schema::table('warehouse_transfer_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
        });
        Schema::table('pos_sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
        });
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_variant_balance_idx');
            $table->dropConstrainedForeignId('product_variant_id');
        });
        Schema::dropIfExists('product_variants');
    }
};
