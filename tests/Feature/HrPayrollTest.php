<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AttendanceDevice;
use App\Models\AttendanceLog;
use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\Employee;
use App\Models\HrLeave;
use App\Models\PayrollAdjustment;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\BusinessSettings;
use App\Services\PayrollAttendanceService;
use App\Services\PayrollService;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class HrPayrollTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
    }

    private function user(string $name = 'Payroll Owner'): User
    {
        return User::create([
            'name' => $name,
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);
    }

    private function business(User $user, string $name = 'Payroll Co'): Business
    {
        $business = Business::create(['name' => $name]);
        $roles = $business->provisionDefaultRoles();
        $business->provisionDefaultModules();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        BusinessModule::updateOrCreate(
            ['business_id' => $business->id, 'module_key' => 'hr'],
            ['enabled' => true],
        );

        return $business;
    }

    private function actIn(User $user, Business $business): void
    {
        $this->flushSession();
        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();
    }

    private function employee(string $code = 'EMP-001'): Employee
    {
        return Employee::create([
            'employee_code' => $code,
            'name' => 'Payroll Employee',
            'department' => 'Operations',
            'job_title' => 'Operator',
            'payroll_type' => 'hourly',
            'payroll_rate' => '100.0000',
            'standard_daily_minutes' => 480,
            'overtime_rate' => '150.0000',
            'working_days' => [CarbonImmutable::parse('2026-09-01')->dayOfWeekIso],
            'is_active' => true,
        ]);
    }

    private function device(): AttendanceDevice
    {
        return AttendanceDevice::create([
            'name' => 'Payroll Attendance Device',
            'brand' => 'generic',
            'connection_type' => 'push_webhook',
            'enabled' => true,
        ]);
    }

    private function punch(AttendanceDevice $device, Employee $employee, string $id, string $time): void
    {
        AttendanceLog::create([
            'attendance_device_id' => $device->id,
            'employee_id' => $employee->id,
            'device_user_id' => $employee->employee_code,
            'external_id' => $id,
            'occurred_at' => $time,
            'verification_type' => 'fingerprint',
            'received_at' => now(),
        ]);
    }

    public function test_hr_payroll_schema_module_and_permissions_exist(): void
    {
        foreach (['hr_leaves', 'payroll_runs', 'payroll_adjustments', 'payroll_lines'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        foreach (['department', 'job_title', 'hire_date', 'standard_daily_minutes', 'overtime_rate', 'working_days'] as $column) {
            $this->assertTrue(Schema::hasColumn('employees', $column));
        }

        $this->assertArrayHasKey('hr', config('modules.registry'));
        $this->assertContains('hr.view', config('permissions.groups.hr'));
        $this->assertContains('payroll.finalize', config('permissions.groups.payroll'));
        $this->assertSame('PRL', config('numbering.prefixes.payroll_run'));
    }

    public function test_attendance_pairs_multiple_in_out_punches_without_counting_breaks(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);
        app(BusinessSettings::class)->set('regional.timezone', 'UTC');

        $employee = $this->employee();
        $device = $this->device();

        $this->punch($device, $employee, 'a', '2026-09-01 08:00:00');
        $this->punch($device, $employee, 'b', '2026-09-01 12:00:00');
        $this->punch($device, $employee, 'c', '2026-09-01 13:00:00');
        $this->punch($device, $employee, 'd', '2026-09-01 18:00:00');

        $summary = app(PayrollAttendanceService::class)->summary($employee, '2026-09-01', '2026-09-01');

        $this->assertSame(540, $summary['worked_minutes']);
        $this->assertSame(540, $summary['daily_minutes']['2026-09-01']);
        $this->assertSame(0, $summary['missing_checkout_days']);
        $this->assertSame(['2026-09-01'], $summary['attended_dates']);
    }

    public function test_payroll_generation_uses_attendance_overtime_and_adjustments_then_posts_accounting(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);
        app(BusinessSettings::class)->set('regional.timezone', 'UTC');

        $employee = $this->employee();
        $device = $this->device();

        $this->punch($device, $employee, 'a', '2026-09-01 08:00:00');
        $this->punch($device, $employee, 'b', '2026-09-01 12:00:00');
        $this->punch($device, $employee, 'c', '2026-09-01 13:00:00');
        $this->punch($device, $employee, 'd', '2026-09-01 18:00:00');

        PayrollAdjustment::create([
            'employee_id' => $employee->id,
            'effective_date' => '2026-09-01',
            'type' => 'earning',
            'amount' => '500.0000',
            'label' => 'Performance bonus',
        ]);

        PayrollAdjustment::create([
            'employee_id' => $employee->id,
            'effective_date' => '2026-09-01',
            'type' => 'deduction',
            'amount' => '200.0000',
            'label' => 'Advance recovery',
        ]);

        $run = app(PayrollService::class)->generate('2026-09-01', '2026-09-01');
        $line = $run->lines()->firstOrFail();

        $this->assertSame('PRL-000001', $run->number);
        $this->assertSame(480, $line->regular_minutes);
        $this->assertSame(60, $line->overtime_minutes);
        $this->assertSame('800.0000', $line->base_pay);
        $this->assertSame('150.0000', $line->overtime_pay);
        $this->assertSame('500.0000', $line->other_earnings);
        $this->assertSame('200.0000', $line->other_deductions);
        $this->assertSame('1450.0000', $line->gross_pay);
        $this->assertSame('1250.0000', $line->net_pay);

        $finalized = app(PayrollService::class)->finalize($run, $user->id);

        $this->assertSame('finalized', $finalized->status);
        $this->assertNotNull($finalized->journal_entry_id);
        $this->assertDatabaseHas('accounts', ['business_id' => $business->id, 'code' => 'PAYROLL-EXP']);
        $this->assertDatabaseHas('accounts', ['business_id' => $business->id, 'code' => 'PAYROLL-PAYABLE']);
        $this->assertDatabaseHas('accounts', ['business_id' => $business->id, 'code' => 'PAYROLL-DEDUCT']);

        $journal = $finalized->journalEntry()->with('lines')->firstOrFail();
        $debits = (float) $journal->lines->sum('debit');
        $credits = (float) $journal->lines->sum('credit');

        $this->assertEqualsWithDelta(1450, $debits, 0.0001);
        $this->assertEqualsWithDelta(1450, $credits, 0.0001);
    }

    public function test_paid_leave_is_paid_without_fake_attendance(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $day = CarbonImmutable::parse('2026-09-01')->dayOfWeekIso;

        $employee = Employee::create([
            'employee_code' => 'EMP-DAY',
            'name' => 'Daily Employee',
            'payroll_type' => 'daily',
            'payroll_rate' => '1000.0000',
            'standard_daily_minutes' => 480,
            'working_days' => [$day],
            'is_active' => true,
        ]);

        HrLeave::create([
            'employee_id' => $employee->id,
            'leave_type' => 'annual',
            'is_paid' => true,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-01',
            'status' => 'approved',
            'approved_by' => $user->id,
        ]);

        $run = app(PayrollService::class)->generate('2026-09-01', '2026-09-01');
        $line = $run->lines()->where('employee_id', $employee->id)->firstOrFail();

        $this->assertSame(0, $line->attended_days);
        $this->assertSame(1, $line->paid_leave_days);
        $this->assertSame(0, $line->absent_days);
        $this->assertSame('1000.0000', $line->base_pay);
        $this->assertSame('1000.0000', $line->net_pay);
    }

    public function test_owner_can_open_hr_workspace_generate_run_and_view_payslip(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $employee = $this->employee();

        $this->get('/hr')->assertOk()->assertSee('HR &amp; Payroll', false);

        $this->post('/hr/payroll-runs', [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-01',
        ])->assertRedirect();

        $run = PayrollRun::firstOrFail();

        $this->get('/hr/payroll-runs/'.$run->id)
            ->assertOk()
            ->assertSee($run->number);

        $this->get('/hr/payroll-runs/'.$run->id.'/payslip/'.$employee->id)
            ->assertOk()
            ->assertSee('Payroll Employee');
    }

    public function test_hr_employee_reference_is_tenant_scoped(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $other = $this->user('Other Owner');
        $otherBusiness = $this->business($other, 'Other Co');
        $this->actIn($other, $otherBusiness);
        $foreignEmployee = $this->employee('FOREIGN');

        $this->actIn($user, $business);

        $this->post('/hr/leaves', [
            'employee_id' => $foreignEmployee->id,
            'leave_type' => 'annual',
            'is_paid' => 1,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-01',
        ])->assertSessionHasErrors('employee_id');

        $this->assertSame(0, HrLeave::count());
    }
}
