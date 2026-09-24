<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business-owned invoice header (Batch 15).
     *
     * Every invoice belongs to exactly one business (business_id is the
     * tenancy boundary, auto-assigned by BelongsToBusiness). The number is a
     * business-scoped, never-reused identifier allocated by the
     * DocumentNumberService (DocumentType::Invoice) inside the same transaction
     * as the insert; the composite unique constraint on
     * (business_id, invoice_number) is the database-level guarantee that
     * mirrors the Batch 13 sequence gate.
     *
     * customer_id is nullable with nullOnDelete so a soft-deleted customer
     * (and even a hard-deleted one) never destroys invoice history; the
     * relation is resolved with withTrashed() for historical display. Direct
     * invoices carry customer_id only; converted invoices copy the quotation's
     * customer — a request can never substitute another customer's id.
     *
     * quotation_id is the optional one-to-one traceability reference: an
     * invoice created by converting a quotation stores that quotation's id, a
     * directly-created invoice stores NULL. The UNIQUE constraint enforces the
     * strict "one quotation -> maximum one invoice" business rule at the
     * database level (SQLite/MySQL both allow multiple NULLs), so a quotation
     * can never be converted twice even under concurrency. nullOnDelete means
     * soft-deleting (or even hard-deleting) the source quotation never
     * cascades to or breaks the invoice.
     *
     * Money columns are DECIMAL(16,4) per the approved money-precision
     * decision. subtotal/discount_type/discount_amount/tax_amount/total follow
     * the quotation source field set (Batch 15 keeps only the discount
     * behaviour already approved for quotations); all arithmetic is server-side
     * exact decimal math (App\Support\Decimal) recomputed by the
     * QuotationCalculator — totals are never accepted from a request.
     *
     * status holds an InvoiceStatus enum value. Batch 15 lifecycle is minimal
     * and stable: `draft` invoices are editable/deletable, `sent` invoices are
     * finalized and immutable. Payment-derived statuses (paid / partially
     * paid / overdue) and cancellation belong to later batches that own their
     * consumers; there is NO amount_paid / amount_due / paid / balance column
     * in Batch 15.
     *
     * created_by records the authenticated user who authored the invoice and is
     * nullable (nullOnDelete) so hard-deleting a user keeps the document.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_number', 50);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained('quotations')->nullOnDelete();
            $table->date('date');
            $table->string('status', 20)->default('draft');
            $table->decimal('subtotal', 16, 4)->default(0);
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_amount', 16, 4)->default(0);
            $table->decimal('tax_amount', 16, 4)->default(0);
            $table->decimal('total', 16, 4)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['business_id', 'invoice_number']);
            $table->unique('quotation_id');
            $table->index(['business_id', 'customer_id']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
