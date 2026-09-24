<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business-owned quotation header (Batch 14).
     *
     * Every quotation belongs to exactly one business (business_id is the
     * tenancy boundary, auto-assigned by BelongsToBusiness). The number is a
     * business-scoped, never-reused identifier allocated by the
     * DocumentNumberService inside the same transaction as the insert; the
     * composite unique constraint on (business_id, quotation_number) is the
     * database-level guarantee that mirrors the Batch 13 sequence gate.
     *
     * customer_id is nullable with nullOnDelete so a soft-deleted customer
     * (and even a hard-deleted one) never destroys quotation history; the
     * relation is resolved with withTrashed() for historical display.
     *
     * Money columns are DECIMAL(16,4) per the approved money-precision
     * decision. subtotal/discount_type/discount_amount/tax_amount/total follow
     * the approved source field set; the discount is document-level
     * (percentage or fixed), and all arithmetic is server-side exact decimal
     * math (App\Support\Decimal). Header totals derive from the item lines via
     * the QuotationCalculator and are never accepted from a request.
     *
     * status holds a QuotationStatus enum value; draft quotations are
     * editable/deletable, any later status is immutable in Batch 14.
     *
     * created_by records the authenticated user who authored the quotation and
     * is nullable (nullOnDelete) so hard-deleting a user keeps the document.
     */
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('quotation_number', 50);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->date('date');
            $table->date('expiry_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->decimal('subtotal', 16, 4)->default(0);
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_amount', 16, 4)->default(0);
            $table->decimal('tax_amount', 16, 4)->default(0);
            $table->decimal('total', 16, 4)->default(0);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['business_id', 'quotation_number']);
            $table->index(['business_id', 'customer_id']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
