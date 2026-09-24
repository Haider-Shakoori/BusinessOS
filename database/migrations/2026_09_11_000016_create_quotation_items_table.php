<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quotation line items (Batch 14).
     *
     * Item rows belong exclusively to their parent quotation (quotation_id
     * cascadeOnDelete); there is NO business_id column and NO BelongsToBusiness
     * scope here — tenancy flows through the parent quotation row.
     *
     * product_id is nullable with nullOnDelete so soft-deleting (or even
     * hard-deleting) a product never destroys quotation history; the relation
     * is resolved with withTrashed() for display. The product's name at quote
     * time is copied into `description`, so the item remains meaningful even
     * if the product is later removed.
     *
     * tax_id is a nullable reference to the business tax used at quote time;
     * `tax_rate` is the 4-dp snapshot taken from the Tax row the moment the
     * quotation is written (never client-supplied), so later edits of the tax
     * definition never alter stored totals. tax is optional: while
     * general.tax_enabled is off, lines carry no tax_id and tax_rate stays
     * null (line_tax = 0).
     *
     * line_subtotal / line_tax / line_total are 4-dp exact-decimal snapshots
     * produced by the QuotationCalculator (quantity x unit_price, exclusive
     * tax) — the header subtotal/tax_amount/total are their aggregate. They
     * are never accepted from a request.
     *
     * sort_order preserves the operator's line order for display.
     */
    public function up(): void
    {
        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description', 500);
            $table->decimal('quantity', 16, 4);
            $table->decimal('unit_price', 16, 4);
            $table->foreignId('tax_id')->nullable()->constrained('taxes')->nullOnDelete();
            $table->decimal('tax_rate', 8, 4)->nullable();
            $table->decimal('line_subtotal', 16, 4);
            $table->decimal('line_tax', 16, 4);
            $table->decimal('line_total', 16, 4);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
    }
};
