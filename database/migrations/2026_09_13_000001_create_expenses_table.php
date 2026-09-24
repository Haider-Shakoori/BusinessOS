<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch 17 — expense tracking (financial records).
     *
     * Each expense is a business-owned financial record with an immutable,
     * per-business expense_number allocated by DocumentNumberService
     * (DocumentType::Expense, EXP-000001) inside the same transaction as the
     * insert; the composite unique (business_id, expense_number) mirrors the
     * Batch 13 sequence gate at the database level and numbers are never reused.
     *
     * category_id is a reference into the Batch 11 categories reference data
     * (used as the category/account mapping hook for Phase 6 accounting).
     * nullOnDelete keeps expense history intact when a category is soft-deleted.
     *
     * Money is DECIMAL(16,4) per the approved money-precision decision, never
     * FLOAT. There are deliberately NO currency / exchange-rate / base-amount
     * columns yet: the current financial-document schema has no such convention,
     * and multi-currency handling belongs to the dedicated Batch 19. expense_date
     * is a business date cast to DB date (never a timestamp).
     *
     * payment_method reuses the stable PaymentMethod enum keys (a label on the
     * financial event, no side effects). reference / vendor / notes are free-form
     * context. receipt_path stores ONLY a generated, sanitized storage path
     * (never the client filename — see ExpenseService).
     *
     * Deletion is a soft delete, consistent with financial-record safety:
     * expense rows stay in the table (history + number never reused), normal
     * queries no longer see them, and the receipt file referenced by a soft-
     * deleted row is left intact so deleting does not destroy an audit artifact.
     */
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('expense_number', 50);
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->date('expense_date');
            $table->decimal('amount', 16, 4);
            $table->string('payment_method', 30)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('vendor', 150)->nullable();
            $table->text('notes')->nullable();
            $table->string('receipt_path', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'expense_number']);
            $table->index(['business_id', 'expense_date']);
            $table->index(['business_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
