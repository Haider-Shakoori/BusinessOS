<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * System-defined, globally shared permissions (Batch 7).
     *
     * Permissions are NOT business-scoped. They are stable application
     * capability keys that every business reuses, so a single row like
     * `settings.manage` powers the same permission across all businesses.
     * Role-to-permission mapping happens per business via the role's own rows.
     */
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('group', 50)->default('other');
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique('name');
            $table->index('group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
