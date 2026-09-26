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
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 80);
            $table->string('route_name')->nullable();
            $table->string('method', 10);
            $table->text('path');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 1000)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['business_id', 'occurred_at']);
            $table->index(['business_id', 'user_id', 'occurred_at'], 'activity_business_user_date_idx');
            $table->index(['business_id', 'event', 'occurred_at'], 'activity_business_event_date_idx');
        });

        Schema::create('business_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 80)->default('system');
            $table->string('title');
            $table->text('message');
            $table->string('action_url')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->index(['business_id', 'user_id', 'read_at'], 'notification_business_user_read_idx');
            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_notifications');
        Schema::dropIfExists('activity_logs');
    }
};
