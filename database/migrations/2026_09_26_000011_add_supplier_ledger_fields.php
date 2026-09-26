<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->after('business_id');
            $table->decimal('opening_balance', 16, 4)->default(0)->after('address');
            $table->date('opening_balance_date')->nullable()->after('opening_balance');
            $table->text('notes')->nullable()->after('opening_balance_date');

            $table->unique(['business_id', 'code'], 'suppliers_business_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropUnique('suppliers_business_code_unique');
            $table->dropColumn(['code', 'opening_balance', 'opening_balance_date', 'notes']);
        });
    }
};
