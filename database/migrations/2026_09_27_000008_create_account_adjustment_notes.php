<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_adjustment_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 80)->nullable();
            $table->string('type', 30);
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('note_date');
            $table->decimal('amount', 16, 4);
            $table->decimal('base_amount', 16, 4);
            $table->string('currency_code', 3);
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('posted');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'type', 'note_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_adjustment_notes');
    }
};
