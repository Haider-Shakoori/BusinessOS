<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 120);
            $table->string('route_name', 160)->nullable();
            $table->string('method', 10);
            $table->string('path', 500);
            $table->string('subject_type', 120)->nullable();
            $table->string('subject_id', 120)->nullable();
            $table->text('description')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['business_id', 'created_at'], 'activity_business_date_idx');
            $table->index(['business_id', 'user_id', 'created_at'], 'activity_business_user_date_idx');
            $table->index(['business_id', 'action'], 'activity_business_action_idx');
        });

        Schema::create('business_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('dedupe_key', 191);
            $table->string('type', 80);
            $table->string('level', 20)->default('info');
            $table->string('title');
            $table->text('message');
            $table->string('action_url', 1000)->nullable();
            $table->json('data')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'dedupe_key']);
            $table->index(['business_id', 'is_active', 'read_at'], 'notification_business_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_notifications');
        Schema::dropIfExists('activity_logs');
    }
};
