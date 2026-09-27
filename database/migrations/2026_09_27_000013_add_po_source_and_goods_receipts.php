<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('source_supplier_quotation_id')->nullable()->after('supplier_id')->constrained('supplier_quotations')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable()->after('issued_by');
            $table->unique('source_supplier_quotation_id', 'purchase_order_source_quote_unique');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('received_quantity', 20, 4)->default(0)->after('quantity');
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('number', 80);
            $table->date('receipt_date');
            $table->string('status', 30)->default('posted');
            $table->decimal('total', 20, 4)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'purchase_order_id', 'receipt_date'], 'grn_business_po_date_idx');
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

            $table->unique(['goods_receipt_id', 'purchase_order_item_id'], 'grn_po_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('received_quantity');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique('purchase_order_source_quote_unique');
            $table->dropConstrainedForeignId('source_supplier_quotation_id');
            $table->dropConstrainedForeignId('issued_by');
            $table->dropColumn('issued_at');
        });
    }
};
