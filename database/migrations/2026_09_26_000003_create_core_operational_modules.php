<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['business_id', 'code']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->decimal('quantity', 16, 4);
            $table->decimal('unit_cost', 16, 4)->nullable();
            $table->string('reference_type', 80)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
            $table->index(['business_id', 'product_id', 'warehouse_id'], 'stock_business_product_wh_idx');
            $table->index(['business_id', 'occurred_at'], 'stock_business_date_idx');
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['business_id', 'name']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('number', 80);
            $table->string('status', 30)->default('draft');
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->decimal('subtotal', 16, 4)->default(0);
            $table->decimal('total', 16, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status', 'order_date'], 'po_business_status_date_idx');
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('description')->nullable();
            $table->decimal('quantity', 16, 4);
            $table->decimal('unit_cost', 16, 4);
            $table->decimal('line_total', 16, 4);
            $table->timestamps();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('type', 30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'code']);
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 80);
            $table->date('entry_date');
            $table->string('status', 20)->default('posted');
            $table->text('description')->nullable();
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'entry_date']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->decimal('debit', 16, 4)->default(0);
            $table->decimal('credit', 16, 4)->default(0);
            $table->string('memo')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('status', 30)->default('new');
            $table->string('source', 80)->nullable();
            $table->decimal('estimated_value', 16, 4)->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['business_id', 'status']);
        });

        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->string('type', 40);
            $table->text('note');
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('boms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('code', 80);
            $table->string('version', 30)->default('1');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'code']);
        });

        Schema::create('bom_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 16, 4);
            $table->decimal('wastage_percent', 8, 4)->default(0);
            $table->timestamps();
            $table->unique(['bom_id', 'material_product_id']);
        });

        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bom_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('number', 80);
            $table->string('status', 30)->default('planned');
            $table->decimal('planned_quantity', 16, 4);
            $table->decimal('actual_quantity', 16, 4)->nullable();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_orders');
        Schema::dropIfExists('bom_items');
        Schema::dropIfExists('boms');
        Schema::dropIfExists('crm_activities');
        Schema::dropIfExists('crm_leads');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('warehouses');
    }
};
