<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business-owned reference data: tax definitions (Batch 11).
     *
     * A tax is a named rate (VAT 5%, etc.) that products and later documents
     * reference. Only `name` and `rate` are present — there are deliberately NO
     * tax groups, compound/jurisdiction rules, exemption logic, or filing
     * fields. Tax calculations belong to the invoice/quotation batches.
     *
     * `rate` is DECIMAL(8,4) per the approved money-precision decision
     * (DECIMAL(8,4) for tax rates) — never FLOAT/DOUBLE, so no binary
     * floating-point error can reach a tax amount. Laravel stores it as an
     * exact decimal string and re-casts it on read with a `decimal:4` cast.
     *
     * business_id is the tenancy boundary, assigned from the current
     * BusinessContext (BelongsToBusiness), never from a request.
     *
     * deleted_at = soft delete (roadmap Batch 11). Disabling the optional tax
     * feature NEVER touches tax rows (see docs/DECISIONS.md); a soft delete
     * preserves foreign keys for any document that referenced a tax rate.
     *
     * Name uniqueness is intentionally NOT a database unique constraint: it is
     * business-scoped and must respect soft deletes. Enforced at validation.
     */
    public function up(): void
    {
        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('rate', 8, 4);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxes');
    }
};
