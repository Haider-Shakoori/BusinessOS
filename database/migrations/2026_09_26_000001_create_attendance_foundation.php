<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('employee_code', 80);
            $table->string('name');
            $table->string('payroll_type', 20)->default('monthly');
            $table->decimal('payroll_rate', 16, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'employee_code']);
            $table->index(['business_id', 'is_active']);
        });

        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('brand', 40);
            $table->string('model')->nullable();
            $table->string('connection_type', 40);
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('base_url', 1000)->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->text('api_key')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('timezone')->nullable();
            $table->boolean('tls_verify')->default(true);
            $table->unsignedSmallInteger('timeout_seconds')->default(8);
            $table->text('push_token')->nullable();
            $table->json('connection_config')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('last_status', 30)->nullable();
            $table->text('last_message')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'enabled']);
            $table->index(['business_id', 'brand']);
            $table->index(['business_id', 'serial_number']);
        });

        Schema::create('attendance_device_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('device_user_id', 100);
            $table->timestamps();

            $table->unique(['attendance_device_id', 'device_user_id'], 'att_dev_user_unique');
            $table->unique(['attendance_device_id', 'employee_id'], 'att_dev_employee_unique');
            $table->index(['business_id', 'employee_id']);
        });

        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_user_id', 100);
            $table->string('external_id', 191);
            $table->timestamp('occurred_at');
            $table->string('punch_type', 30)->nullable();
            $table->string('verification_type', 30)->default('unknown');
            $table->json('raw_payload')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();

            $table->unique(['business_id', 'attendance_device_id', 'external_id'], 'attendance_logs_unique_event');
            $table->index(['business_id', 'employee_id', 'occurred_at']);
            $table->index(['business_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
        Schema::dropIfExists('attendance_device_employees');
        Schema::dropIfExists('attendance_devices');
        Schema::dropIfExists('employees');
    }
};
