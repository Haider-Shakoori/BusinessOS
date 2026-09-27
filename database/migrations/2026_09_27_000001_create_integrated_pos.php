<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('open');
            $table->decimal('opening_cash', 16, 4)->default(0);
            $table->decimal('closing_cash', 16, 4)->nullable();
            $table->decimal('expected_cash', 16, 4)->nullable();
            $table->decimal('cash_difference', 16, 4)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->text('closing_notes')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'user_id', 'opened_at']);
        });

        Schema::create('pos_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sale_number', 80);
            $table->string('status', 20)->default('completed');
            $table->string('payment_method', 30);
            $table->decimal('subtotal', 16, 4);
            $table->decimal('discount_amount', 16, 4)->default(0);
            $table->decimal('total', 16, 4);
            $table->decimal('tendered_amount', 16, 4)->nullable();
            $table->decimal('change_amount', 16, 4)->default(0);
            $table->timestamp('sold_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'sale_number']);
            $table->index(['business_id', 'sold_at']);
            $table->index(['business_id', 'pos_shift_id']);
        });

        Schema::create('pos_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku')->nullable();
            $table->string('description');
            $table->decimal('quantity', 16, 4);
            $table->decimal('unit_price', 16, 4);
            $table->decimal('unit_cost', 16, 4)->nullable();
            $table->decimal('line_total', 16, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sale_items');
        Schema::dropIfExists('pos_sales');
        Schema::dropIfExists('pos_shifts');
    }
};
