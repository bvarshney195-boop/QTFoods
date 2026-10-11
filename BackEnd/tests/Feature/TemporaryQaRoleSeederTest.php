<?php

namespace Tests\Feature;

use Database\Seeders\TemporaryQaRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class TemporaryQaRoleSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_creates_an_idempotent_read_only_role_only_for_the_synthetic_training_company(): void
    {
        $seeder = new TemporaryQaRoleSeeder();
        $seeder->run();
        $seeder->run();

        $role = DB::table('roles')->where('code', TemporaryQaRoleSeeder::ROLE_CODE)->sole();
        $company = DB::table('companies')->where('id', $role->company_id)->sole();

        $this->assertTrue((bool) $company->is_demo);
        $this->assertFalse((bool) $role->is_system);
        $this->assertSame('ACTIVE', $role->status);
        $this->assertSame(1, DB::table('roles')->where('code', TemporaryQaRoleSeeder::ROLE_CODE)->count());

        $permissionCodes = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $role->id)
            ->pluck('permissions.code');

        $this->assertGreaterThanOrEqual(69, $permissionCodes->count());
        $this->assertTrue($permissionCodes->every(
            fn (string $code): bool => str_starts_with($code, 'SCREEN:') && str_ends_with($code, ':VIEW')
        ));
        $this->assertSame(0, DB::table('role_assignments')->where('role_id', $role->id)->count());
    }

    public function test_refuses_to_provision_if_demo_seeding_is_disabled(): void
    {
        config()->set('deployment.allow_demo_seeders', false);

        $this->expectException(LogicException::class);
        (new TemporaryQaRoleSeeder())->run();
    }

    public function test_refuses_an_existing_role_with_the_same_code_in_another_company_scope(): void
    {
        DB::table('roles')->insert([
            'id' => '00000000-0000-4000-8000-000000009999',
            'company_id' => null,
            'code' => TemporaryQaRoleSeeder::ROLE_CODE,
            'name' => 'Conflicting role',
            'description' => null,
            'status' => 'ACTIVE',
            'is_system' => true,
            'record_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        (new TemporaryQaRoleSeeder())->run();
    }
}
