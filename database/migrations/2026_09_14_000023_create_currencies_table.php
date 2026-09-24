<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch 19 — global currency reference.
     *
     * A public, read-mostly catalogue of recognised ISO 4217 currencies. This
     * table is deliberately NOT business-scoped (no business_id): the same
     * registry is shared by every tenant and seeded with the supported codes.
     * Tenancy enters through business_currencies (which businesses ENABLE a
     * currency) and exchange_rates (who has a rate for it); the base currency
     * itself lives in the business settings (regional.currency).
     *
     * is_active gates which codes may be enabled/used; inactive codes are kept
     * so historical documents that snapshot them remain renderable.
     */
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique();
            $table->string('name', 100);
            $table->string('symbol', 10)->nullable();
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
