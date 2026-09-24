<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BusinessOS multi-business foundation.
     *
     * Kept deliberately minimal for Batch 6: only what the tenancy foundation
     * requires. Subscription, status, slug, currency, tax, branding, settings
     * and module fields belong to later batches and are NOT added here.
     */
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
