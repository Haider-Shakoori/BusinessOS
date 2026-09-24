<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch 16 — synchronized payment balance on invoices.
     *
     * amount_paid / amount_due are DERIVED caches, never write targets: the
     * authoritative financial source is the set of active (non-reversed)
     * payment_allocations pointing at the invoice. Whenever a payment is
     * recorded or reversed, PaymentService recomputes both columns from that
     * aggregate (exact decimal math via App\Support\Decimal) and keeps the
     * status (draft / sent / partially_paid / paid) in sync. A request can
     * never write these fields directly; money columns stay DECIMAL(16,4) per
     * the approved money-precision decision. Invariant: active-allocations sum
     * == amount_paid and amount_paid + amount_due == total.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('amount_paid', 16, 4)->default(0)->after('total');
            $table->decimal('amount_due', 16, 4)->default(0)->after('amount_paid');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['amount_paid', 'amount_due']);
        });
    }
};
