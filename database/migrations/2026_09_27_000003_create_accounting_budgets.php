<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('draft');
            $table->timestamps();
            $table->index(['business_id', 'start_date', 'end_date']);
        });

        Schema::create('accounting_budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accounting_budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 20, 4);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['accounting_budget_id', 'account_id', 'cost_center_id'], 'budget_line_dimension_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_budget_lines');
        Schema::dropIfExists('accounting_budgets');
    }
};
