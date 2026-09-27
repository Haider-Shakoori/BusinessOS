<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\FinancialCloseReadinessService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialCloseReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
    }

    public function test_closed_period_coverage_and_balanced_journals_can_be_ready(): void
    {
        $this->signInOwner();
        FiscalPeriod::create([
            'name' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        $assessment = app(FinancialCloseReadinessService::class)->assess('2026-01-01', '2026-12-31');

        $this->assertTrue($assessment['ready']);
        $this->assertSame(0, $assessment['blockers']);
        $this->assertSame('pass', collect($assessment['checks'])->firstWhere('id', 'period_coverage')['severity']);
        $this->assertSame('pass', collect($assessment['checks'])->firstWhere('id', 'journal_integrity')['severity']);
    }

    public function test_open_period_is_a_hard_close_blocker(): void
    {
        $this->signInOwner();
        FiscalPeriod::create([
            'name' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);

        $assessment = app(FinancialCloseReadinessService::class)->assess('2026-01-01', '2026-12-31');

        $this->assertFalse($assessment['ready']);
        $this->assertGreaterThan(0, $assessment['blockers']);
        $this->assertSame('blocker', collect($assessment['checks'])->firstWhere('id', 'period_coverage')['severity']);
    }

    public function test_empty_posted_journal_is_a_hard_close_blocker(): void
    {
        $this->signInOwner();
        FiscalPeriod::create([
            'name' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'closed',
            'closed_at' => now(),
        ]);
        JournalEntry::create([
            'number' => 'BROKEN-001',
            'entry_date' => '2026-06-01',
            'status' => 'posted',
            'description' => 'Broken empty journal',
        ]);

        $assessment = app(FinancialCloseReadinessService::class)->assess('2026-01-01', '2026-12-31');

        $this->assertFalse($assessment['ready']);
        $this->assertSame('blocker', collect($assessment['checks'])->firstWhere('id', 'journal_integrity')['severity']);
    }

    public function test_readiness_dashboard_is_available_to_accounting_viewer(): void
    {
        $user = $this->signInOwner();

        $response = $this->get(route('accounting.close-readiness', [
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]));

        $response->assertOk();
        $response->assertSee('Close readiness');
        $this->assertAuthenticatedAs($user);
    }

    private function signInOwner(): User
    {
        $user = User::create([
            'name' => 'Owner',
            'email' => Str::random(12).'@test.local',
            'password' => Hash::make('password'),
        ]);
        $business = Business::create(['name' => 'Readiness Business']);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();

        return $user;
    }
}
