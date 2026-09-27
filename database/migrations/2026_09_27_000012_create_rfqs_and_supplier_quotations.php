<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_rfqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_requisition_id')->constrained()->restrictOnDelete();
            $table->string('number', 80);
            $table->string('status', 30)->default('draft');
            $table->date('issue_date');
            $table->date('response_due_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('awarded_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status', 'issue_date'], 'rfq_business_status_date_idx');
            $table->index(['business_id', 'purchase_requisition_id'], 'rfq_business_req_idx');
        });

        Schema::create('purchase_rfq_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['purchase_rfq_id', 'supplier_id'], 'rfq_supplier_unique');
        });

        Schema::create('supplier_quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('number', 80);
            $table->string('supplier_reference', 120)->nullable();
            $table->string('status', 30)->default('received');
            $table->date('quote_date');
            $table->date('valid_until')->nullable();
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('total', 20, 4)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('selected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('selected_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->unique(['purchase_rfq_id', 'supplier_id'], 'rfq_supplier_quote_unique');
            $table->index(['business_id', 'status', 'quote_date'], 'supplier_quote_business_status_date_idx');
        });

        Schema::create('supplier_quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_requisition_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 20, 4);
            $table->decimal('unit_cost', 20, 4);
            $table->decimal('line_total', 20, 4);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['supplier_quotation_id', 'purchase_requisition_item_id'], 'supplier_quote_req_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_quotation_items');
        Schema::dropIfExists('supplier_quotations');
        Schema::dropIfExists('purchase_rfq_suppliers');
        Schema::dropIfExists('purchase_rfqs');
    }
};
