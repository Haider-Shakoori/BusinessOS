<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->decimal('amount_paid', 20, 4)->default(0)->after('price_variance_total');
            $table->decimal('credit_total', 20, 4)->default(0)->after('amount_paid');
            $table->decimal('debit_total', 20, 4)->default(0)->after('credit_total');
            $table->decimal('amount_due', 20, 4)->default(0)->after('debit_total');
            $table->string('settlement_status', 30)->default('unpaid')->after('amount_due');
            $table->index(
                ['business_id', 'supplier_id', 'settlement_status'],
                'supplier_invoice_business_supplier_settlement_idx',
            );
        });

        DB::table('supplier_invoices')
            ->where('status', 'approved')
            ->update([
                'amount_due' => DB::raw('total'),
                'settlement_status' => 'unpaid',
            ]);

        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->timestamps();

            $table->unique(['payment_id', 'supplier_invoice_id'], 'supplier_payment_invoice_unique');
            $table->index('supplier_invoice_id');
        });

        Schema::create('supplier_invoice_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('number', 80);
            $table->string('type', 20);
            $table->date('note_date');
            $table->decimal('amount', 20, 4);
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(
                ['business_id', 'supplier_invoice_id', 'type'],
                'supplier_invoice_adjustment_invoice_type_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_adjustments');
        Schema::dropIfExists('supplier_payment_allocations');

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropIndex('supplier_invoice_business_supplier_settlement_idx');
            $table->dropColumn([
                'amount_paid',
                'credit_total',
                'debit_total',
                'amount_due',
                'settlement_status',
            ]);
        });
    }
};
