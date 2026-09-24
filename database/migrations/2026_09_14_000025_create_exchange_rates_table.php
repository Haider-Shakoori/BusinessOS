<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch 19 — per-business exchange rates.
     *
     * Tenancy is enforced by BusinessContext through the BelongsToBusiness
     * trait: every row is owned by exactly one business and the global scope
     * keeps cross-business rates invisible. A rate is the number of BASE
     * currency units that buys ONE unit of the transaction currency
     * (e.g. 1 USD = 71.50000000 AFN), so the base currency implicitly has rate
     * 1 and never needs a row here.
     *
     * Rates carry an effective_date (defaults to "today") so a document can be
     * priced with the rate that was in effect on ITS date, and later edits to
     * the rate never rewrite a previously recorded document (B19 snapshot
     * semantics). Resolution picks the newest effective branch at/before the
     * document date. A positive rate is enforced at the request/service level.
     * Rate precision is DECIMAL(16,8); the converted base_amount stays
     * DECIMAL(16,4) per the global money convention.
     */
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('currency_code', 3);
            $table->decimal('rate', 16, 8);
            $table->date('effective_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'currency_code', 'effective_date']);
            $table->index(['business_id', 'currency_code', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
