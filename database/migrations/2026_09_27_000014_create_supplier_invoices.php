<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('number', 80);
            $table->string('supplier_invoice_number', 120);
            $table->string('status', 30)->default('draft');
            $table->string('match_status', 30)->default('matched');
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('total', 20, 4)->default(0);
            $table->decimal('po_basis_total', 20, 4)->default(0);
            $table->decimal('price_variance_total', 20, 4)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('match_override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('match_override_at')->nullable();
            $table->text('match_override_reason')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->unique(['business_id', 'supplier_id', 'supplier_invoice_number'], 'supplier_invoice_supplier_ref_unique');
            $table->index(['business_id', 'status', 'invoice_date'], 'supplier_invoice_business_status_date_idx');
            $table->index(['business_id', 'purchase_order_id'], 'supplier_invoice_business_po_idx');
        });

        Schema::create('supplier_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 20, 4);
            $table->decimal('received_quantity_snapshot', 20, 4);
            $table->decimal('available_quantity_snapshot', 20, 4);
            $table->decimal('unit_cost', 20, 4);
            $table->decimal('line_total', 20, 4);
            $table->decimal('po_unit_cost', 20, 4);
            $table->decimal('po_basis_total', 20, 4);
            $table->decimal('price_variance', 20, 4)->default(0);
            $table->timestamps();

            $table->unique(['supplier_invoice_id', 'purchase_order_item_id'], 'supplier_invoice_po_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_items');
        Schema::dropIfExists('supplier_invoices');
    }
};
