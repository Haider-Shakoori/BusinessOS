<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('purchase_requisition_id')->nullable()->after('supplier_id')->constrained()->nullOnDelete();
            $table->foreignId('purchase_rfq_id')->nullable()->after('purchase_requisition_id')->constrained('purchase_rfqs')->nullOnDelete();
            $table->foreignId('supplier_quotation_id')->nullable()->after('purchase_rfq_id')->constrained()->nullOnDelete();
            $table->foreignId('converted_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            $table->timestamp('converted_at')->nullable()->after('converted_by');

            $table->unique('supplier_quotation_id', 'purchase_orders_supplier_quote_unique');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('supplier_quotation_item_id')->nullable()->after('purchase_order_id')->constrained()->nullOnDelete();
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('number', 80);
            $table->string('status', 30)->default('posted');
            $table->date('receipt_date');
            $table->decimal('total', 20, 4)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'purchase_order_id', 'receipt_date'], 'goods_receipt_business_po_date_idx');
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 20, 4);
            $table->decimal('unit_cost', 20, 4);
            $table->decimal('line_total', 20, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_quotation_item_id');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique('purchase_orders_supplier_quote_unique');
            $table->dropConstrainedForeignId('converted_by');
            $table->dropColumn('converted_at');
            $table->dropConstrainedForeignId('supplier_quotation_id');
            $table->dropConstrainedForeignId('purchase_rfq_id');
            $table->dropConstrainedForeignId('purchase_requisition_id');
        });
    }
};
