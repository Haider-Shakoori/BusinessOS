<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('content');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'user_id', 'created_at'], 'assistant_business_user_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_assistant_messages');
    }
};
