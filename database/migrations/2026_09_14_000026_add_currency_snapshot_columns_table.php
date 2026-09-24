<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch 19 — currency snapshots on financial documents.
     *
     * Every authoritative financial amount now carries a permanent currency
     * snapshot: the transaction currency (currency_code), the exchange rate
     * that was in effect at the document date (exchange_rate), and the amount
     * expressed in the business base currency (base_amount). Snapshots make
     * later rate edits safe: they never rewrite a previously recorded document.
     *
     * - invoices.base_amount  = invoice total   in base currency
     * - quotations.base_amount = quotation total in base currency
     * - expenses.base_amount  = expense amount  in base currency
     * - payments.base_amount  = payment amount  in base currency
     *
     * All money stays DECIMAL(16,4); only the exchange rate column is
     * DECIMAL(16,8) because a rate needs more precision than a price.
     *
     * Backfill: rows written before Batch 19 were implicitly in the business
     * base currency at rate 1, so existing documents are seeded with the
     * default base currency (see config/settings.php regional.currency).
     */
    public function up(): void
    {
        $default = config('settings.definitions.regional.currency.default', 'AFN');

        foreach (['invoices', 'quotations', 'expenses', 'payments'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('currency_code', 3)->nullable()->after('id');
                $t->decimal('exchange_rate', 16, 8)->nullable()->after('currency_code');
                $t->decimal('base_amount', 16, 4)->nullable()->after('exchange_rate');
            });
        }

        // Existing rows were recorded in the base currency at rate 1.
        DB::table('invoices')->whereNull('currency_code')->update([
            'currency_code' => $default,
            'exchange_rate' => 1,
            'base_amount' => DB::raw('total'),
        ]);

        DB::table('quotations')->whereNull('currency_code')->update([
            'currency_code' => $default,
            'exchange_rate' => 1,
            'base_amount' => DB::raw('total'),
        ]);

        DB::table('expenses')->whereNull('currency_code')->update([
            'currency_code' => $default,
            'exchange_rate' => 1,
            'base_amount' => DB::raw('amount'),
        ]);

        DB::table('payments')->whereNull('currency_code')->update([
            'currency_code' => $default,
            'exchange_rate' => 1,
            'base_amount' => DB::raw('amount'),
        ]);
    }

    public function down(): void
    {
        foreach (['invoices', 'quotations', 'expenses', 'payments'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['currency_code', 'exchange_rate', 'base_amount']);
            });
        }
    }
};
