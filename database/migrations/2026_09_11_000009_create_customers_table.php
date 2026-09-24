<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business-owned customer registry (Batch 10).
     *
     * Only the fields supported by the approved plan are present: a display
     * name, an optional company name, and standard contact details. There are
     * deliberately NO financial aggregates (balance, credit, invoice/payment
     * totals, ledger columns) — money and ledger data belong to later batches
     * (invoices, payments, customer ledger) and are computed there, never
     * stored on the registry row.
     *
     * business_id is the tenancy boundary: it is assigned from the current
     * BusinessContext (BelongsToBusiness), never from a request.
     *
     * deleted_at = soft delete (roadmap Batch 10). A soft-deleted customer row
     * stays in place so future financial records (invoices, payments) that
     * reference the customer keep valid foreign keys. The business FK uses
     * cascadeOnDelete for the tenant boundary; customers themselves are never
     * hard-deleted by application code.
     *
     * The business_id FK provides the only index required for the list query;
     * text columns (name, email, phone) are intentionally not indexed because
     * the search is a LIKE pattern that a btree index cannot serve.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('company_name', 100)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
