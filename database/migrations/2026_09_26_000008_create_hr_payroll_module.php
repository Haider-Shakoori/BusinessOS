<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('department')->nullable()->after('name');
            $table->string('job_title')->nullable()->after('department');
            $table->string('email')->nullable()->after('job_title');
            $table->string('phone', 100)->nullable()->after('email');
            $table->date('hire_date')->nullable()->after('phone');
            $table->unsignedSmallInteger('standard_daily_minutes')->default(480)->after('payroll_rate');
            $table->decimal('overtime_rate', 16, 4)->nullable()->after('standard_daily_minutes');
            $table->json('working_days')->nullable()->after('overtime_rate');
        });

        Schema::create('hr_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('leave_type', 50)->default('annual');
            $table->boolean('is_paid')->default(true);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('approved');
            $table->text('note')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'employee_id', 'start_date'], 'hr_leave_emp_date_idx');
            $table->index(['business_id', 'status'], 'hr_leave_status_idx');
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 80);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('draft');
            $table->decimal('total_gross', 16, 4)->default(0);
            $table->decimal('total_deductions', 16, 4)->default(0);
            $table->decimal('total_net', 16, 4)->default(0);
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'period_start', 'period_end'], 'payroll_run_period_idx');
        });

        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('effective_date');
            $table->string('type', 20);
            $table->decimal('amount', 16, 4);
            $table->string('label');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'employee_id', 'effective_date'], 'payroll_adj_emp_date_idx');
        });

        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('employee_code', 80);
            $table->string('employee_name');
            $table->string('payroll_type', 20);
            $table->decimal('payroll_rate', 16, 4)->default(0);
            $table->unsignedInteger('scheduled_days')->default(0);
            $table->unsignedInteger('attended_days')->default(0);
            $table->unsignedInteger('paid_leave_days')->default(0);
            $table->unsignedInteger('unpaid_leave_days')->default(0);
            $table->unsignedInteger('absent_days')->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('regular_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->unsignedInteger('missing_checkout_days')->default(0);
            $table->decimal('base_pay', 16, 4)->default(0);
            $table->decimal('overtime_pay', 16, 4)->default(0);
            $table->decimal('other_earnings', 16, 4)->default(0);
            $table->decimal('absence_deduction', 16, 4)->default(0);
            $table->decimal('other_deductions', 16, 4)->default(0);
            $table->decimal('gross_pay', 16, 4)->default(0);
            $table->decimal('net_pay', 16, 4)->default(0);
            $table->json('attendance_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_adjustments');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('hr_leaves');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'department',
                'job_title',
                'email',
                'phone',
                'hire_date',
                'standard_daily_minutes',
                'overtime_rate',
                'working_days',
            ]);
        });
    }
};
