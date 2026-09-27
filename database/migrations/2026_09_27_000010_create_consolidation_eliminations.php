<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consolidation_eliminations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 80);
            $table->date('effective_date');
            $table->string('description');
            $table->string('status', 20)->default('posted');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'reference']);
            $table->index(['business_id', 'effective_date', 'status']);
        });

        Schema::create('consolidation_elimination_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consolidation_elimination_id')->constrained()->cascadeOnDelete();
            $table->string('statement_type', 20);
            $table->decimal('debit', 20, 4)->default(0);
            $table->decimal('credit', 20, 4)->default(0);
            $table->string('memo')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consolidation_elimination_lines');
        Schema::dropIfExists('consolidation_eliminations');
    }
};
