<?php

namespace Tests\Feature;

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceEmployee;
use App\Models\AttendanceLog;
use App\Models\Business;
use App\Models\Employee;
use App\Models\User;
use App\Services\AttendanceDeviceConnectionService;
use App\Services\BusinessSettings;
use App\Services\PayrollAttendanceService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceDeviceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Attendance Owner',
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);
    }

    private function business(User $user, string $name = 'Attendance Co.'): Business
    {
        $business = Business::create(['name' => $name]);
        $roles = $business->provisionDefaultRoles();
        $business->provisionDefaultModules();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        return $business;
    }

    private function actIn(User $user, Business $business): void
    {
        $this->flushSession();
        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();
    }

    public function test_attendance_foundation_schema_and_brand_presets_exist(): void
    {
        foreach (['employees', 'attendance_devices', 'attendance_device_employees', 'attendance_logs'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $this->assertArrayHasKey('zkteco', config('attendance.brands'));
        $this->assertArrayHasKey('suprema', config('attendance.brands'));
        $this->assertArrayHasKey('hikvision', config('attendance.brands'));
        $this->assertArrayHasKey('anviz', config('attendance.brands'));
        $this->assertArrayHasKey('dahua', config('attendance.brands'));
        $this->assertArrayHasKey('essl', config('attendance.brands'));
        $this->assertSame(4370, config('attendance.connections.zkteco_tcp.default_port'));
        $this->assertFalse(config('settings.definitions.attendance.enabled.default'));
        $this->assertSame('attendance', config('settings.definitions.attendance.payroll_source.default'));
    }

    public function test_owner_can_configure_encrypted_attendance_device_and_settings_page_lists_brands(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $this->get('/settings')
            ->assertOk()
            ->assertSee('Attendance &amp; Payroll', false)
            ->assertSee('ZKTeco')
            ->assertSee('Suprema')
            ->assertSee('Hikvision');

        $this->post('/settings/attendance-devices', [
            'name' => 'Main Gate',
            'brand' => 'zkteco',
            'model' => 'SpeedFace',
            'connection_type' => 'zkteco_tcp',
            'host' => '10.0.0.50',
            'port' => 4370,
            'username' => 'admin',
            'password' => 'device-secret',
            'timezone' => 'Asia/Kabul',
            'tls_verify' => '1',
            'timeout_seconds' => 8,
            'enabled' => '1',
        ])->assertRedirect();

        $device = AttendanceDevice::query()->firstOrFail();

        $this->assertSame('Main Gate', $device->name);
        $this->assertSame('device-secret', $device->password);
        $this->assertNotSame('device-secret', DB::table('attendance_devices')->where('id', $device->id)->value('password'));
        $this->assertNotEmpty($device->push_token);
        $this->assertSame($business->id, $device->business_id);
    }

    public function test_push_attendance_maps_employee_deduplicates_and_drives_payroll_metrics(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        app(BusinessSettings::class)->set('attendance.enabled', true);
        app(BusinessSettings::class)->set('regional.timezone', 'Asia/Kabul');

        $device = AttendanceDevice::create([
            'name' => 'Push Device',
            'brand' => 'zkteco',
            'connection_type' => 'adms_push',
            'serial_number' => 'SN-001',
            'timezone' => 'Asia/Kabul',
            'enabled' => true,
        ]);

        $employee = Employee::create([
            'employee_code' => 'EMP-001',
            'name' => 'Employee One',
            'payroll_type' => 'monthly',
            'payroll_rate' => '25000.0000',
            'is_active' => true,
        ]);

        AttendanceDeviceEmployee::create([
            'attendance_device_id' => $device->id,
            'employee_id' => $employee->id,
            'device_user_id' => '17',
        ]);

        $payload = [
            'records' => [
                [
                    'external_id' => 'punch-1',
                    'device_user_id' => '17',
                    'timestamp' => '2026-09-10T08:00:00+04:30',
                    'punch_type' => 'check_in',
                    'verification_type' => 'fingerprint',
                ],
                [
                    'external_id' => 'punch-2',
                    'device_user_id' => '17',
                    'timestamp' => '2026-09-10T17:00:00+04:30',
                    'punch_type' => 'check_out',
                    'verification_type' => 'fingerprint',
                ],
                [
                    'external_id' => 'punch-3',
                    'device_user_id' => '17',
                    'timestamp' => '2026-09-11T08:00:00+04:30',
                    'punch_type' => 'check_in',
                    'verification_type' => 'face',
                ],
            ],
        ];

        $headers = ['Authorization' => 'Bearer '.$device->push_token];

        $this->postJson('/api/attendance/push/'.$device->uuid, $payload, $headers)
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'accepted' => 3,
                'duplicates' => 0,
                'unmapped' => 0,
            ]);

        $this->postJson('/api/attendance/push/'.$device->uuid, $payload, $headers)
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'accepted' => 0,
                'duplicates' => 3,
                'unmapped' => 0,
            ]);

        $this->assertSame(3, AttendanceLog::withoutGlobalScope('business')->count());
        $this->assertDatabaseHas('attendance_logs', [
            'business_id' => $business->id,
            'employee_id' => $employee->id,
            'device_user_id' => '17',
            'external_id' => 'punch-1',
        ]);

        $this->actIn($user, $business);
        $summary = app(PayrollAttendanceService::class)->summary($employee, '2026-09-01', '2026-09-30');

        $this->assertSame(2, $summary['attended_days']);
        $this->assertSame(3, $summary['punch_count']);
        $this->assertSame(540, $summary['worked_minutes']);
        $this->assertSame(1, $summary['missing_checkout_days']);
    }

    public function test_push_is_rejected_until_business_attendance_is_enabled(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $device = AttendanceDevice::create([
            'name' => 'Disabled Business Device',
            'brand' => 'generic',
            'connection_type' => 'push_webhook',
            'serial_number' => 'PUSH-1',
            'enabled' => true,
        ]);

        $this->postJson('/api/attendance/push/'.$device->uuid, [
            'records' => [[
                'device_user_id' => '1',
                'timestamp' => '2026-09-10T08:00:00Z',
            ]],
        ], ['Authorization' => 'Bearer '.$device->push_token])->assertNotFound();
    }

    public function test_push_connection_type_reports_ready_without_contacting_external_network(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $device = AttendanceDevice::create([
            'name' => 'Bridge Device',
            'brand' => 'generic',
            'connection_type' => 'push_webhook',
            'serial_number' => 'BRIDGE-1',
            'enabled' => true,
        ]);

        $result = app(AttendanceDeviceConnectionService::class)->test($device);

        $this->assertTrue($result['ok']);
        $this->assertSame('waiting_for_push', $result['status']);
    }
}
