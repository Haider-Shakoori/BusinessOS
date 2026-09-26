<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'is_active']);
        });

        Schema::create('pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_register_id')->constrained('pos_registers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('open');
            $table->timestamp('opened_at');
            $table->decimal('opening_cash', 16, 4)->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->decimal('closing_cash', 16, 4)->nullable();
            $table->decimal('expected_cash', 16, 4)->nullable();
            $table->decimal('cash_variance', 16, 4)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status'], 'pos_shift_business_status_idx');
            $table->index(['pos_register_id', 'status'], 'pos_shift_register_status_idx');
        });

        Schema::create('pos_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_register_id')->constrained('pos_registers')->restrictOnDelete();
            $table->foreignId('pos_shift_id')->constrained('pos_shifts')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->string('sale_number', 80);
            $table->string('status', 20)->default('completed');
            $table->string('payment_method', 20);
            $table->decimal('subtotal', 16, 4);
            $table->decimal('discount_amount', 16, 4)->default(0);
            $table->decimal('tax_amount', 16, 4)->default(0);
            $table->decimal('total', 16, 4);
            $table->decimal('amount_tendered', 16, 4)->default(0);
            $table->decimal('change_due', 16, 4)->default(0);
            $table->string('currency_code', 3);
            $table->timestamp('completed_at');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'sale_number']);
            $table->index(['business_id', 'completed_at'], 'pos_sale_business_date_idx');
            $table->index(['pos_shift_id', 'status'], 'pos_sale_shift_status_idx');
        });

        Schema::create('pos_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_sale_id')->constrained('pos_sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->string('sku')->nullable();
            $table->decimal('quantity', 16, 4);
            $table->decimal('unit_price', 16, 4);
            $table->decimal('unit_cost', 16, 4)->default(0);
            $table->decimal('cost_total', 16, 4)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 16, 4)->default(0);
            $table->decimal('line_total', 16, 4);
            $table->timestamps();

            $table->index(['pos_sale_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sale_items');
        Schema::dropIfExists('pos_sales');
        Schema::dropIfExists('pos_shifts');
        Schema::dropIfExists('pos_registers');
    }
};
