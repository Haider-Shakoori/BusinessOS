<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('ap_recognition', 20)
                ->default('receipt')
                ->after('status');
            $table->index(['business_id', 'ap_recognition'], 'purchase_orders_business_ap_recognition_idx');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex('purchase_orders_business_ap_recognition_idx');
            $table->dropColumn('ap_recognition');
        });
    }
};
