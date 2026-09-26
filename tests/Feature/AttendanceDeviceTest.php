<?php

namespace Tests\Feature;

use App\Models\AttendanceBridge;
use App\Models\AttendanceBridgeJob;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceEmployee;
use App\Models\AttendanceLog;
use App\Models\Business;
use App\Models\Employee;
use App\Models\User;
use App\Services\AttendanceDeviceConnectionService;
use App\Services\AttendanceDeviceDiscoveryService;
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
        $this->assertArrayHasKey('realtime', config('attendance.brands'));
        $this->assertArrayHasKey('bioenable', config('attendance.brands'));
        $this->assertArrayHasKey('matrix', config('attendance.brands'));
        $this->assertArrayHasKey('cpplus', config('attendance.brands'));
        $this->assertArrayHasKey('mantra', config('attendance.brands'));
        $this->assertArrayHasKey('nitgen', config('attendance.brands'));
        $this->assertArrayHasKey('virdi', config('attendance.brands'));
        $this->assertArrayHasKey('idemia', config('attendance.brands'));
        $this->assertSame(4370, config('attendance.connections.zkteco_tcp.default_port'));
        $this->assertSame(5010, config('attendance.connections.anviz_tcp.default_port'));
        $this->assertSame(51211, config('attendance.connections.suprema_device_tcp.default_port'));
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

    public function test_discovery_classifies_common_attendance_protocol_families_without_network_access(): void
    {
        $discovery = app(AttendanceDeviceDiscoveryService::class);

        $zk = $discovery->classify('192.168.1.20', [4370]);
        $this->assertSame('zkteco', $zk['brand']);
        $this->assertSame('zkteco_tcp', $zk['connection_type']);
        $this->assertSame(4370, $zk['port']);
        $this->assertGreaterThanOrEqual(70, $zk['confidence']);

        $anviz = $discovery->classify('192.168.1.21', [5010]);
        $this->assertSame('anviz', $anviz['brand']);
        $this->assertSame('anviz_tcp', $anviz['connection_type']);
        $this->assertSame(5010, $anviz['port']);

        $suprema = $discovery->classify('192.168.1.22', [51211]);
        $this->assertSame('suprema', $suprema['brand']);
        $this->assertSame('suprema_device_tcp', $suprema['connection_type']);
        $this->assertSame(51211, $suprema['port']);

        $hikvision = $discovery->classify('192.168.1.23', [80], [[
            'scheme' => 'http',
            'port' => 80,
            'status' => 401,
            'text' => 'Hikvision ISAPI',
            'isapi' => true,
        ]]);
        $this->assertSame('hikvision', $hikvision['brand']);
        $this->assertSame('isapi', $hikvision['connection_type']);
        $this->assertSame('http://192.168.1.23', $hikvision['base_url']);
        $this->assertGreaterThanOrEqual(90, $hikvision['confidence']);
    }

    public function test_discovery_prefers_exact_http_brand_fingerprint_over_shared_zk_port(): void
    {
        $result = app(AttendanceDeviceDiscoveryService::class)->classify('10.10.10.10', [80, 4370], [[
            'scheme' => 'http',
            'port' => 80,
            'status' => 200,
            'text' => 'eSSL Smart Office biometric attendance',
        ]]);

        $this->assertSame('essl', $result['brand']);
        $this->assertSame('zkteco_tcp', $result['connection_type']);
        $this->assertSame(4370, $result['port']);
        $this->assertGreaterThanOrEqual(90, $result['confidence']);
    }

    public function test_discovery_rejects_loopback_and_link_local_targets_before_network_probe(): void
    {
        $discovery = app(AttendanceDeviceDiscoveryService::class);

        foreach (['127.0.0.1', '169.254.169.254', '::1', 'fe80::1', '::ffff:127.0.0.1'] as $ip) {
            try {
                $discovery->discover($ip);
                $this->fail('Expected invalid device IP: '.$ip);
            } catch (\InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_private_ip_detection_is_routed_through_online_local_bridge(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $bridge = AttendanceBridge::create([
            'name' => 'Main Office Bridge',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        $this->postJson('/settings/attendance-devices/detect', [
            'ip' => '192.168.10.20',
            'bridge_id' => $bridge->id,
        ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'pending' => true,
                'bridge_id' => $bridge->id,
            ]);

        $job = AttendanceBridgeJob::query()->firstOrFail();
        $this->assertSame('detect', $job->type);
        $this->assertSame('192.168.10.20', $job->payload['ip']);

        $headers = ['Authorization' => 'Bearer '.$bridge->token];

        $this->getJson('/api/attendance/bridge/'.$bridge->uuid.'/jobs', $headers)
            ->assertOk()
            ->assertJsonPath('jobs.0.id', $job->uuid);

        $this->postJson('/api/attendance/bridge/'.$bridge->uuid.'/jobs/'.$job->uuid.'/result', [
            'ok' => true,
            'open_ports' => [4370],
            'http_evidence' => [],
        ], $headers)->assertOk();

        $job->refresh();

        $this->assertSame('completed', $job->status);
        $this->assertSame('zkteco', $job->result['brand']);
        $this->assertSame('zkteco_tcp', $job->result['connection_type']);
        $this->assertSame($bridge->id, $job->result['bridge_id']);

        $this->getJson('/settings/attendance-device-discovery/'.$job->uuid)
            ->assertOk()
            ->assertJsonPath('result.brand', 'zkteco');
    }

    public function test_private_ip_detection_requires_online_bridge(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $this->postJson('/settings/attendance-devices/detect', [
            'ip' => '10.20.30.40',
        ])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_bridge_heartbeat_and_device_record_upload_are_authenticated_and_drive_attendance(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        app(BusinessSettings::class)->set('attendance.enabled', true);

        $bridge = AttendanceBridge::create(['name' => 'Warehouse Bridge']);
        $headers = ['Authorization' => 'Bearer '.$bridge->token];

        $this->postJson('/api/attendance/bridge/'.$bridge->uuid.'/heartbeat', [
            'hostname' => 'ATTENDANCE-PC',
            'local_ips' => ['192.168.1.10'],
            'version' => '1.0.0',
        ], $headers)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $bridge->refresh();
        $this->assertTrue($bridge->isOnline());
        $this->assertSame('ATTENDANCE-PC', $bridge->hostname);
        $this->assertSame(['192.168.1.10'], $bridge->local_ips);
        $this->assertNotSame($bridge->token, DB::table('attendance_bridges')->where('id', $bridge->id)->value('token'));

        $employee = Employee::create([
            'employee_code' => 'BR-EMP-1',
            'name' => 'Bridge Employee',
            'payroll_type' => 'monthly',
            'is_active' => true,
        ]);

        $device = AttendanceDevice::create([
            'attendance_bridge_id' => $bridge->id,
            'name' => 'LAN ZK',
            'brand' => 'zkteco',
            'connection_type' => 'zkteco_tcp',
            'host' => '192.168.1.201',
            'port' => 4370,
            'enabled' => true,
        ]);

        AttendanceDeviceEmployee::create([
            'attendance_device_id' => $device->id,
            'employee_id' => $employee->id,
            'device_user_id' => '5',
        ]);

        $this->postJson('/api/attendance/bridge/'.$bridge->uuid.'/devices/'.$device->uuid.'/records', [
            'records' => [[
                'external_id' => 'bridge-punch-1',
                'device_user_id' => '5',
                'timestamp' => '2026-09-26T08:00:00+04:30',
                'punch_type' => '0',
                'verification_type' => 'fingerprint',
            ]],
        ], $headers)
            ->assertOk()
            ->assertJson([
                'accepted' => 1,
                'duplicates' => 0,
                'unmapped' => 0,
            ]);

        $this->assertDatabaseHas('attendance_logs', [
            'business_id' => $business->id,
            'attendance_device_id' => $device->id,
            'employee_id' => $employee->id,
            'external_id' => 'bridge-punch-1',
        ]);
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
