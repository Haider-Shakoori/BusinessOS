<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('number', 80);
            $table->string('status', 30)->default('draft');
            $table->date('transfer_date');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status', 'transfer_date'], 'warehouse_transfer_business_status_idx');
        });

        Schema::create('warehouse_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 16, 4);
            $table->decimal('unit_cost', 16, 4)->nullable();
            $table->timestamps();

            $table->unique(['warehouse_transfer_id', 'product_id'], 'warehouse_transfer_product_unique');
        });

        Schema::create('inventory_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('processed_by')->constrained('users')->restrictOnDelete();
            $table->string('number', 80);
            $table->string('type', 30);
            $table->string('source_type', 100);
            $table->unsignedBigInteger('source_id');
            $table->string('status', 30)->default('completed');
            $table->decimal('subtotal', 16, 4)->default(0);
            $table->decimal('tax_amount', 16, 4)->default(0);
            $table->decimal('total', 16, 4)->default(0);
            $table->text('reason');
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'type', 'processed_at'], 'inventory_return_business_type_idx');
            $table->index(['source_type', 'source_id'], 'inventory_return_source_idx');
        });

        Schema::create('inventory_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('source_item_type', 100);
            $table->unsignedBigInteger('source_item_id');
            $table->decimal('quantity', 16, 4);
            $table->decimal('unit_amount', 16, 4);
            $table->decimal('unit_cost', 16, 4)->nullable();
            $table->decimal('line_subtotal', 16, 4);
            $table->decimal('tax_amount', 16, 4)->default(0);
            $table->decimal('discount_amount', 16, 4)->default(0);
            $table->decimal('line_total', 16, 4);
            $table->timestamps();

            $table->index(['source_item_type', 'source_item_id'], 'inventory_return_item_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_return_items');
        Schema::dropIfExists('inventory_returns');
        Schema::dropIfExists('warehouse_transfer_items');
        Schema::dropIfExists('warehouse_transfers');
    }
};
