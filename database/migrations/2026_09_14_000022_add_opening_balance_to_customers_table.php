<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 18: customer opening balance support.
 *
 * The smallest safe additive schema change required by the customer-ledger
 * scope: a DECIMAL(16,4) opening_balance (default 0) plus an optional date so
 * an opening balance can be placed chronologically inside the ledger. Positive
 * values mean money the customer owes the business at the start of the
 * relationship; the value itself is never stored on the ledger — it is the
 * starting point of the read model and remains editable through the customer
 * create/edit workflow (customers.manage).
 *
 * No credit-limit or other accounting fields are added here (Batch 18 is
 * ledger/statement only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('opening_balance', 16, 4)->default(0)->after('notes');
            $table->date('opening_balance_date')->nullable()->after('opening_balance');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['opening_balance', 'opening_balance_date']);
        });
    }
};
