<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-business document number sequences (Batch 13).
     *
     * One row per business + document_type holding the last number allocated
     * by DocumentNumberService. `last_number` is BIGINT UNSIGNED — an
     * effectively limitless monotonic counter, never reused and never reset.
     *
     * The composite unique constraint on (business_id, document_type) is the
     * real concurrency gate: two writers cannot seed the same sequence, and
     * the service either locks an existing row or wins the insert and re-reads
     * the loser's row under its lock. business_id is supplied by the service
     * from BusinessContext and cascades away with the business.
     *
     * Deliberately no extra index: every access path is the unique lookup.
     */
    public function up(): void
    {
        Schema::create('document_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 50);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['business_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_number_sequences');
    }
};
