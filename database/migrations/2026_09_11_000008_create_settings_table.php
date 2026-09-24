<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business-scoped settings store (Batch 9).
     *
     * Rows are sparse overrides over config('settings.definitions'): a row
     * only exists when a business deviates from the config default. The
     * composite unique key (business_id, group, key) makes writes idempotent
     * and prevents duplicate per-business settings.
     *
     * business_id is NOT NULL because Batch 9 ships business settings only;
     * system-level settings belong to a separate, later system_settings table
     * and never reuse a null business scope here.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('group', 50);
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->timestamps();

            $table->unique(['business_id', 'group', 'key'], 'business_group_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
