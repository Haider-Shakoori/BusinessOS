<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business-owned product/service registry (Batch 12).
     *
     * Products and services share this single table — `type` (the
     * ProductType enum value) is the only discriminator. Both kinds support the
     * same catalog fields: a name, an optional business-scoped SKU, an
     * optional description, a sale price and optional category/unit/tax
     * references.
     *
     * `sale_price` is DECIMAL(16,4) per the approved money-precision decision
     * — never FLOAT/DOUBLE, so no binary floating-point error can reach a
     * price. Laravel stores it as an exact decimal string and re-casts it on
     * read with a `decimal:4` cast.
     *
     * business_id is the tenancy boundary, assigned from the current
     * BusinessContext (BelongsToBusiness), never from a request. It is indexed
     * together with `type` because the index page filters by kind within one
     * business.
     *
     * category_id / unit_id / tax_id are nullable references into the catalog
     * reference data (Batch 11); nullOnDelete means soft-deleting a category,
     * unit or tax never touches the products that referenced it. Validation
     * restricts these to ACTIVE records of the CURRENT business only.
     *
     * SKU uniqueness is intentionally NOT a database unique constraint: it is
     * business-scoped and must respect soft deletes. Enforced at validation.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('product');
            $table->string('name', 100);
            $table->string('sku', 50)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('tax_id')->nullable()->constrained('taxes')->nullOnDelete();
            $table->decimal('sale_price', 16, 4);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['business_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
