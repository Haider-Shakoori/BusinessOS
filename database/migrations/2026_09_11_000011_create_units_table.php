<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business-owned reference data: units of measure (Batch 11).
     *
     * A unit is a lookup label (name + optional short symbol) assigned to
     * products (Batch 12). There is deliberately NO conversion hierarchy
     * (base unit, factor, etc.) — unit conversion is designed by the inventory
     * batches and built on top of this plain reference row if ever needed; it
     * is not part of this batch.
     *
     * business_id is the tenancy boundary, assigned from the current
     * BusinessContext (BelongsToBusiness), never from a request.
     *
     * deleted_at = soft delete (roadmap Batch 11) so future products keep valid
     * foreign keys. The business FK uses cascadeOnDelete for the tenant
     * boundary; units themselves are never hard-deleted by application code.
     *
     * Name uniqueness is intentionally NOT a database unique constraint: it is
     * business-scoped and must respect soft deletes. Enforced at validation.
     */
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('short_name', 20)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
