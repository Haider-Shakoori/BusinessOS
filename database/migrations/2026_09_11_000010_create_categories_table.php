<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business-owned reference data: product/expense categories (Batch 11).
     *
     * Only the display name and an optional description are present — there is
     * deliberately NO parent_id hierarchy, nowhere to store product counts, and
     * no accounting/warehouse/inventory fields. A category is a lookup label
     * assigned to products (Batch 12) and expenses (later); any aggregates are
     * computed by those modules, never stored here.
     *
     * business_id is the tenancy boundary, assigned from the current
     * BusinessContext (BelongsToBusiness), never from a request.
     *
     * deleted_at = soft delete (roadmap Batch 11). A soft-deleted category row
     * stays in place so future products/expenses referencing the category keep
     * valid foreign keys. The business FK uses cascadeOnDelete for the tenant
     * boundary; categories themselves are never hard-deleted by application
     * code.
     *
     * Name uniqueness is intentionally NOT a database unique constraint: it is
     * business-scoped and must respect soft deletes (a deleted row must not
     * block re-creating the same name), which a composite unique index on
     * (business_id, name) cannot express. Enforced instead at validation time.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
