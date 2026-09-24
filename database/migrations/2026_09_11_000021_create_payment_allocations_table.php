<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch 16 — payment allocations.
     *
     * Each row allocates a payment's amount to one invoice (Batch 16: exactly
     * one row per payment). The table deliberately has NO business_id: tenancy
     * is enforced through the owning payment (and the invoice it references),
     * both of which are business-owned — payment_allocations is a join table
     * between two already-scoped documents.
     *
     * cascadeOnDelete on both sides is safe in practice: payment rows are
     * never deleted (only marked reversed) and invoice rows are soft-deleted
     * (never cascade-removed), so financial history always survives. The
     * allocation is the authoritative source driving PaymentService's
     * active-aggregate reconciliation of invoices.amount_paid.
     */
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->decimal('amount', 16, 4);
            $table->timestamps();

            $table->index('payment_id');
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
