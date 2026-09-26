<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 100);
            $table->unsignedBigInteger('source_id');
            $table->string('event_key', 80);
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['business_id', 'source_type', 'source_id', 'event_key'],
                'accounting_posting_source_event_unique'
            );
            $table->index(
                ['business_id', 'source_type', 'source_id', 'reversed_at'],
                'accounting_posting_active_source_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_postings');
    }
};
