<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch 16 — payments (polymorphic + allocation model).
     *
     * Approved schema (DECISIONS.md "Payment Architecture — Polymorphic with
     * Allocation Table"): a payment is a business-owned financial event that
     * points at exactly one invoice through BOTH a polymorphic morph
     * (paymentable_type/id) and a dedicated allocation table. Batch 16
     * restricts practice to one invoice per payment, so each payment carries a
     * single payment_allocations row; the allocation table exists so future
     * batches can split a payment across invoices without schema churn.
     *
     * party_type/party_id record the payer ('customer' + the invoice's
     * customer_id at recording time) for at-a-glance reporting; the customer
     * is ALWAYS inferred through the invoice — a request can never substitute
     * it. The number is a business-scoped, never-reused identifier allocated by
     * DocumentNumberService (DocumentType::Payment) inside the recording
     * transaction; the composite unique (business_id, payment_number)
     * constraint mirrors the Batch 13 sequence gate at the database level.
     *
     * Money is DECIMAL(16,4). payment_date is a business-date cast to DB date
     * (never a timestamp). Reversal is the only destructor of financial
     * effect: reversed_at/reversed_by/reversal_reason mark the row, the
     * allocation is excluded from invoice aggregates, and the row itself is
     * NEVER deleted (audit trail, number never reusable). created_by records
     * the authenticated author (nullOnDelete preserves history).
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('payment_number', 50);
            $table->string('paymentable_type');
            $table->unsignedBigInteger('paymentable_id');
            $table->string('party_type', 20)->nullable();
            $table->unsignedBigInteger('party_id')->nullable();
            $table->date('payment_date');
            $table->decimal('amount', 16, 4);
            $table->string('payment_method', 30);
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'payment_number']);
            $table->index(['paymentable_type', 'paymentable_id']);
            $table->index(['business_id', 'party_type', 'party_id']);
            $table->index(['business_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
