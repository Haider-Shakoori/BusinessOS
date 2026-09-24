<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch 19 — per-business enabled currencies.
     *
     * The bridge between the global currency registry and a tenant: a row here
     * means the business has chosen to USE a currency in addition to its base
     * currency (which is configured via the regional.currency setting and is
     * always implicitly enabled). business_id is the tenant key; the row never
     * stores amounts. Deleting a row only removes the currency from future
     * pickers — historical documents keep their own snapshots.
     *
     * The (business_id, currency_code) unique pair backstops double-enable at
     * the database level.
     */
    public function up(): void
    {
        Schema::create('business_currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('currency_code', 3);
            $table->timestamps();

            $table->unique(['business_id', 'currency_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_currencies');
    }
};
