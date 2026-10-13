<?php

namespace Tests\Feature;

use App\Shared\Deployment\SystemDefinitionCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Tests\TestCase;

final class SystemDefinitionInitializationCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('deployment.allow_demo_seeders', false);
    }

    public function test_command_installs_the_complete_global_catalogue_without_demo_or_business_data(): void
    {
        $this->artisan('qt:system:initialize', [
            '--confirmation' => 'INITIALIZE_SYSTEM_DEFINITIONS',
        ])->expectsOutputToContain('"status": "initialized"')
            ->assertExitCode(0);

        $expectedRoles = SystemDefinitionCatalogue::roles();
        self::assertSame(
            array_keys($expectedRoles),
            DB::table('roles')->orderBy('id')->pluck('code')->all(),
        );
        foreach ($expectedRoles as $code => $definition) {
            $role = DB::table('roles')->where('code', $code)->first();
            self::assertNotNull($role);
            self::assertNull($role->company_id);
            self::assertTrue((bool) $role->is_system);
            self::assertSame('ACTIVE', $role->status);
            self::assertSame($definition['name'], $role->name);
            self::assertSame($definition['description'], $role->description);
        }

        $expectedPermissions = array_keys(SystemDefinitionCatalogue::permissions());
        $actualPermissions = DB::table('permissions')->orderBy('code')->pluck('code')->all();
        self::assertSame($expectedPermissions, $actualPermissions);
        self::assertSame(count($expectedPermissions), DB::table('permissions')->whereNull('company_id')
            ->where('is_system', true)->where('status', 'ACTIVE')->count());

        foreach (SystemDefinitionCatalogue::permissionsByRole() as $roleCode => $permissionCodes) {
            sort($permissionCodes);
            $actual = DB::table('role_permissions as role_permission')
                ->join('roles as role', 'role.id', '=', 'role_permission.role_id')
                ->join('permissions as permission', 'permission.id', '=', 'role_permission.permission_id')
                ->where('role.code', $roleCode)
                ->orderBy('permission.code')
                ->pluck('permission.code')
                ->all();
            self::assertSame($permissionCodes, $actual, $roleCode);
        }

        foreach ([
            'companies', 'plants', 'users', 'role_assignments', 'identity_tokens',
            'audit_events', 'outbox_events', 'parties', 'shipments', 'invoices',
            'stock_positions', 'requisitions', 'sales_orders',
        ] as $table) {
            self::assertSame(0, DB::table($table)->count(), "{$table} must remain empty.");
        }
        self::assertFalse((bool) config('deployment.allow_demo_seeders'));
    }

    public function test_repeated_execution_is_a_database_no_op(): void
    {
        $this->initialize();
        $before = $this->systemSnapshot();

        $this->artisan('qt:system:initialize', [
            '--confirmation' => 'INITIALIZE_SYSTEM_DEFINITIONS',
        ])->expectsOutputToContain('"status": "already_current"')
            ->assertExitCode(0);

        self::assertSame($before, $this->systemSnapshot());
    }

    public function test_command_requires_confirmation_and_demo_seeding_remains_blocked(): void
    {
        $this->artisan('qt:system:initialize')->assertExitCode(2);
        self::assertFalse(DB::table('roles')->where('code', 'ERP_ADMIN')->exists());

        try {
            $this->seed();
            self::fail('The demo seeder unexpectedly ran with production-safe configuration.');
        } catch (LogicException $exception) {
            self::assertSame(
                'DatabaseSeeder contains development/UAT fixtures and is disabled in this environment.',
                $exception->getMessage(),
            );
        }

        self::assertSame(0, DB::table('companies')->count());
        self::assertSame(0, DB::table('users')->count());
    }

    public function test_production_catalogue_matches_the_reviewed_demo_seed_role_matrix(): void
    {
        $this->initialize();
        $productionDefinitions = $this->permissionCodesByRole();

        config()->set('deployment.allow_demo_seeders', true);
        $this->seed();

        self::assertSame($productionDefinitions, $this->permissionCodesByRole());
    }

    public function test_fresh_database_can_bootstrap_an_administrator_after_initialization(): void
    {
        Mail::fake();
        config()->set('qtfoods.identity.preview_links', true);

        $this->initialize();
        $this->artisan('qt:identity:bootstrap-admin', [
            'email' => 'railway.admin@example.com',
            '--name' => 'Railway Administrator',
            '--company-code' => 'QTF-LIVE',
            '--company-name' => 'Q & T FOODS LTD',
            '--plant-code' => 'HQ',
            '--plant-name' => 'Head Office',
            '--timezone' => 'Asia/Kolkata',
            '--confirmation' => 'CREATE_PRODUCTION_ADMIN',
        ])->expectsOutputToContain('"status": "created"')
            ->assertExitCode(0);

        $role = DB::table('roles')->where('code', 'ERP_ADMIN')->first();
        $userId = DB::table('users')->where('email', 'railway.admin@example.com')->value('id');

        self::assertNotNull($role);
        self::assertNull($role->company_id);
        self::assertTrue((bool) $role->is_system);
        self::assertSame(347, DB::table('role_permissions')->where('role_id', $role->id)->count());
        self::assertTrue(DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where('role_id', $role->id)
            ->where('is_active', true)
            ->exists());
    }

    private function initialize(): void
    {
        $this->artisan('qt:system:initialize', [
            '--confirmation' => 'INITIALIZE_SYSTEM_DEFINITIONS',
        ])->assertExitCode(0);
    }

    private function permissionCodesByRole(): array
    {
        $matrix = [];
        foreach (array_keys(SystemDefinitionCatalogue::roles()) as $roleCode) {
            $matrix[$roleCode] = DB::table('role_permissions as role_permission')
                ->join('roles as role', 'role.id', '=', 'role_permission.role_id')
                ->join('permissions as permission', 'permission.id', '=', 'role_permission.permission_id')
                ->where('role.code', $roleCode)
                ->orderBy('permission.code')
                ->pluck('permission.code')
                ->all();
        }

        return $matrix;
    }

    private function systemSnapshot(): array
    {
        return [
            'roles' => DB::table('roles')->orderBy('code')->get()->map(fn (object $row): array => (array) $row)->all(),
            'permissions' => DB::table('permissions')->orderBy('code')->get()->map(fn (object $row): array => (array) $row)->all(),
            'assignments' => DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')
                ->get()->map(fn (object $row): array => (array) $row)->all(),
        ];
    }
}
