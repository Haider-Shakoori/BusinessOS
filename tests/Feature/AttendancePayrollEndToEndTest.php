<?php

namespace Tests\Feature;

use App\Models\AttendanceBridge;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceEmployee;
use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\Employee;
use App\Models\User;
use App\Services\BusinessSettings;
use App\Services\PayrollAttendanceService;
use App\Services\PayrollService;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendancePayrollEndToEndTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
    }

    public function test_bridge_punches_flow_through_attendance_into_finalized_payroll(): void
    {
        $user = User::create([
            'name' => 'Payroll QA Owner',
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create(['name' => 'Payroll QA Business']);
        $roles = $business->provisionDefaultRoles();
        $business->provisionDefaultModules();
        $membership = $user->memberships()->create([
            'business_id' => $business->id,
        ]);
        $membership->assignRole($roles['owner']);

        BusinessModule::updateOrCreate(
            ['business_id' => $business->id, 'module_key' => 'hr'],
            ['enabled' => true],
        );

        $this->flushSession();
        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();

        app(BusinessSettings::class)->set('attendance.enabled', true);
        app(BusinessSettings::class)->set('regional.timezone', 'Asia/Kabul');

        $employee = Employee::create([
            'employee_code' => 'E2E-001',
            'name' => 'Attendance Payroll Employee',
            'department' => 'Operations',
            'job_title' => 'Operator',
            'payroll_type' => 'hourly',
            'payroll_rate' => '100.0000',
            'standard_daily_minutes' => 480,
            'overtime_rate' => '150.0000',
            'working_days' => [
                CarbonImmutable::parse('2026-09-26')->dayOfWeekIso,
            ],
            'is_active' => true,
        ]);

        $bridge = AttendanceBridge::create([
            'name' => 'Payroll QA Bridge',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $device = AttendanceDevice::create([
            'attendance_bridge_id' => $bridge->id,
            'name' => 'Payroll QA Hikvision',
            'brand' => 'hikvision',
            'connection_type' => 'isapi',
            'host' => '192.168.50.20',
            'port' => 80,
            'timezone' => 'Asia/Kabul',
            'enabled' => true,
        ]);

        AttendanceDeviceEmployee::create([
            'attendance_device_id' => $device->id,
            'employee_id' => $employee->id,
            'device_user_id' => 'E2E-001',
        ]);

        $headers = ['Authorization' => 'Bearer '.$bridge->token];

        $this->postJson(
            '/api/attendance/bridge/'.$bridge->uuid.'/devices/'.$device->uuid.'/records',
            [
                'records' => [
                    [
                        'external_id' => 'hik-e2e-in',
                        'device_user_id' => 'E2E-001',
                        'timestamp' => '2026-09-26T08:00:00+04:30',
                        'punch_type' => 'checkIn',
                        'verification_type' => 'face',
                    ],
                    [
                        'external_id' => 'hik-e2e-out',
                        'device_user_id' => 'E2E-001',
                        'timestamp' => '2026-09-26T17:00:00+04:30',
                        'punch_type' => 'checkOut',
                        'verification_type' => 'face',
                    ],
                ],
            ],
            $headers,
        )
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'accepted' => 2,
                'duplicates' => 0,
                'unmapped' => 0,
            ]);

        $summary = app(PayrollAttendanceService::class)->summary(
            $employee,
            '2026-09-26',
            '2026-09-26',
        );

        $this->assertSame(1, $summary['attended_days']);
        $this->assertSame(2, $summary['punch_count']);
        $this->assertSame(540, $summary['worked_minutes']);
        $this->assertSame(0, $summary['missing_checkout_days']);

        $run = app(PayrollService::class)->generate(
            '2026-09-26',
            '2026-09-26',
        );
        $line = $run->lines()
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $this->assertSame(480, $line->regular_minutes);
        $this->assertSame(60, $line->overtime_minutes);
        $this->assertSame('800.0000', $line->base_pay);
        $this->assertSame('150.0000', $line->overtime_pay);
        $this->assertSame('950.0000', $line->gross_pay);
        $this->assertSame('950.0000', $line->net_pay);

        $finalized = app(PayrollService::class)->finalize(
            $run,
            $user->id,
        );

        $this->assertSame('finalized', $finalized->status);
        $this->assertNotNull($finalized->journal_entry_id);
        $this->assertDatabaseHas('accounts', [
            'business_id' => $business->id,
            'code' => 'PAYROLL-EXP',
        ]);
        $this->assertDatabaseHas('accounts', [
            'business_id' => $business->id,
            'code' => 'PAYROLL-PAYABLE',
        ]);

        $journal = $finalized->journalEntry()
            ->with('lines')
            ->firstOrFail();

        $this->assertEqualsWithDelta(
            (float) $journal->lines->sum('debit'),
            (float) $journal->lines->sum('credit'),
            0.0001,
        );
    }
}
