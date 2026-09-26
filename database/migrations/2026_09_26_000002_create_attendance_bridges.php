<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_bridges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('token');
            $table->string('hostname')->nullable();
            $table->json('local_ips')->nullable();
            $table->string('version', 50)->nullable();
            $table->string('status', 30)->default('offline');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'status'], 'att_bridge_business_status_idx');
        });

        Schema::create('attendance_bridge_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_bridge_id')->constrained('attendance_bridges')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('status', 30)->default('pending');
            $table->json('payload');
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['attendance_bridge_id', 'status'], 'att_bridge_jobs_status_idx');
            $table->index(['business_id', 'created_at'], 'att_bridge_jobs_business_idx');
        });

        Schema::table('attendance_devices', function (Blueprint $table) {
            $table->foreignId('attendance_bridge_id')
                ->nullable()
                ->after('business_id')
                ->constrained('attendance_bridges')
                ->nullOnDelete();

            $table->index(['business_id', 'attendance_bridge_id'], 'att_device_bridge_idx');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_devices', function (Blueprint $table) {
            $table->dropForeign(['attendance_bridge_id']);
            $table->dropIndex('att_device_bridge_idx');
            $table->dropColumn('attendance_bridge_id');
        });

        Schema::dropIfExists('attendance_bridge_jobs');
        Schema::dropIfExists('attendance_bridges');
    }
};
