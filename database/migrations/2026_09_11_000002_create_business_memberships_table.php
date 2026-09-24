<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Many-to-many membership between users and businesses.
     *
     * One business may have many users; one user may belong to many
     * businesses. Batch 6 stores NO role or permission data here — that is
     * Batch 7. The unique constraint prevents duplicate memberships and the
     * cascades keep the pivot consistent when a user or business is removed.
     */
    public function up(): void
    {
        Schema::create('business_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_memberships');
    }
};
