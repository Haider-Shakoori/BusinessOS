<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_revaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->date('revaluation_date');
            $table->string('currency_code', 3);
            $table->decimal('closing_rate', 20, 8);
            $table->decimal('foreign_balance', 20, 4);
            $table->decimal('historical_base_balance', 20, 4);
            $table->decimal('revalued_base_balance', 20, 4);
            $table->decimal('adjustment', 20, 4);
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['invoice_id', 'revaluation_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_revaluations');
    }
};
