<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_year_closes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('net_income', 20, 4);
            $table->foreignId('retained_earnings_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('closed_at');
            $table->foreignId('closed_by')->constrained('users')->restrictOnDelete();
            $table->text('close_note')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'start_date', 'end_date']);
            $table->index(['business_id', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_year_closes');
    }
};
