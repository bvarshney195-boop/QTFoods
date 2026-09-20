<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FoundationAdministrationEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const TRAINING_PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const FINANCE_PLANT_ID = '00000000-0000-4000-8000-000000000102';
    private const SALES_USER_ID = '00000000-0000-4000-8000-000000000201';
    private const FINANCE_USER_ID = '00000000-0000-4000-8000-000000000203';
    private const ADMIN_USER_ID = '00000000-0000-4000-8000-000000000204';
    private const ERP_ADMIN_ROLE_ID = '00000000-0000-4000-8000-000000000304';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->signIn(self::ADMIN_USER_ID, self::TRAINING_PLANT_ID);
    }

    public function test_admin_reads_live_scoped_organisation_location_user_role_and_permission_data(): void
    {
        $this->getJson('/api/v1/admin/organisation')
            ->assertOk()
            ->assertJsonPath('data.company.code', 'QTF')
            ->assertJsonPath('data.summary.plant_count', 2)
            ->assertJsonPath('data.summary.active_users', 6)
            ->assertJsonPath('data.plants.0.allowed_actions.0', 'UPDATE');

        $this->getJson('/api/v1/admin/locations')
            ->assertOk()
            ->assertJsonPath('summary.total', 8)
            ->assertJsonPath('summary.active', 8)
            ->assertJsonPath('data.0.record_version', 1);

        $this->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonPath('summary.total', 6)
            ->assertJsonPath('summary.active_assignments', 6)
            ->assertJsonFragment(['email' => 'finance.user@qtfoods.local'])
            ->assertJsonFragment(['email' => 'bi.user@qtfoods.local']);

        $roles = $this->getJson('/api/v1/admin/roles')
            ->assertOk()
            ->assertJsonPath('summary.roles', 6)
            ->assertJsonPath('summary.custom_roles', 0)
            ->assertJsonFragment(['code' => 'ERP_ADMIN'])
            ->assertJsonFragment(['code' => 'BI_ANALYST']);
        $this->assertGreaterThan(70, $roles->json('summary.permissions'));

        $permissionId = DB::table('permissions')->where('code', 'SCREEN:ADM-ROLE:VIEW')->value('id');
        $this->getJson("/api/v1/admin/permissions/{$permissionId}")
            ->assertOk()
            ->assertJsonPath('data.is_system', true)
            ->assertJsonPath('data.allowed_actions', []);
    }

    public function test_company_creation_is_idempotent_and_grants_the_creator_a_new_context(): void
    {
        $payload = [
            'code' => 'qtn',
            'legal_name' => 'Q & T Nutrition Private Limited',
            'display_name' => 'Q & T Nutrition',
            'plant_code' => 'north',
            'plant_name' => 'North Plant',
            'timezone' => 'Asia/Kolkata',
        ];
        $key = (string) Str::uuid();
        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/admin/companies', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.record_version', 1);
        $companyId = $first->json('data.company_id');
        $plantId = $first->json('data.plant_id');

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/admin/companies', $payload)
            ->assertCreated()
            ->assertExactJson($first->json());

        $this->assertDatabaseHas('companies', ['id' => $companyId, 'code' => 'QTN']);
        $this->assertDatabaseHas('plants', ['id' => $plantId, 'company_id' => $companyId, 'code' => 'NORTH']);
        $this->assertDatabaseHas('role_assignments', [
            'user_id' => self::ADMIN_USER_ID,
            'role_id' => self::ERP_ADMIN_ROLE_ID,
            'company_id' => $companyId,
            'plant_id' => $plantId,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_events', ['command' => 'CREATE_COMPANY', 'entity_id' => $companyId]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'foundation.company.created', 'aggregate_id' => $companyId]);

        $contexts = $this->getJson('/api/v1/me')->assertOk()->json('data.contexts');
        $this->assertTrue(collect($contexts)->contains(fn (array $context) =>
            $context['company_id'] === $companyId && $context['plant_id'] === $plantId));
    }

    public function test_plant_commands_are_versioned_audited_and_scope_checked(): void
    {
        $created = $this->command()->postJson('/api/v1/admin/plants', [
            'code' => 'PILOT',
            'name' => 'Pilot Plant',
            'timezone' => 'Asia/Kolkata',
            'status' => 'ACTIVE',
        ])->assertCreated();
        $plantId = $created->json('data.id');

        $this->withHeaders($this->commandHeaders(1))->postJson("/api/v1/admin/plants/{$plantId}", [
            'name' => 'Pilot Manufacturing Plant',
            'timezone' => 'UTC',
            'status' => 'ACTIVE',
        ])->assertOk()->assertJsonPath('data.record_version', 2);

        $this->withHeaders($this->commandHeaders(1))->postJson("/api/v1/admin/plants/{$plantId}", [
            'name' => 'Stale update',
            'timezone' => 'Asia/Kolkata',
            'status' => 'ACTIVE',
        ])->assertConflict()->assertJsonPath('error.code', 'CONFLICT');

        $this->assertDatabaseHas('plants', ['id' => $plantId, 'name' => 'Pilot Manufacturing Plant', 'record_version' => 2]);
        $this->assertDatabaseHas('audit_events', ['command' => 'UPDATE_PLANT', 'entity_id' => $plantId]);
        $this->getJson("/api/v1/admin/plants/{$plantId}")
            ->assertOk()->assertJsonPath('data.active_user_count', 1);

        $this->signIn(self::ADMIN_USER_ID, self::FINANCE_PLANT_ID);
        $this->getJson("/api/v1/admin/plants/{$plantId}")->assertOk();
    }

    public function test_location_hierarchy_rejects_cycles_and_uses_lifecycle_deactivation(): void
    {
        $parentId = $this->createLocation('WH-E2E', 'Warehouse', null);
        $childId = $this->createLocation('ZONE-E2E', 'Pick zone', $parentId);

        $this->withHeaders($this->commandHeaders(1))->postJson("/api/v1/admin/locations/{$parentId}", [
            'name' => 'Warehouse',
            'description' => null,
            'location_type' => 'WAREHOUSE',
            'parent_location_id' => $childId,
            'status' => 'ACTIVE',
        ])->assertUnprocessable()
            ->assertJsonPath('error.fields.parent_location_id.0', 'That parent would create a location hierarchy cycle.');

        $this->withHeaders($this->commandHeaders(1))->postJson("/api/v1/admin/locations/{$childId}", [
            'name' => 'Picking Zone',
            'description' => 'Controlled picking area',
            'location_type' => 'ZONE',
            'parent_location_id' => $parentId,
            'status' => 'INACTIVE',
        ])->assertOk()->assertJsonPath('data.record_version', 2);

        $this->getJson("/api/v1/admin/locations/{$childId}")
            ->assertOk()
            ->assertJsonPath('data.parent.id', $parentId)
            ->assertJsonPath('data.status', 'INACTIVE');

        $foreignLocation = DB::table('locations')->where('plant_id', self::FINANCE_PLANT_ID)->value('id');
        $this->getJson("/api/v1/admin/locations/{$foreignLocation}")
            ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_user_and_role_assignment_commands_hash_credentials_and_enforce_versions(): void
    {
        $created = $this->command()->postJson('/api/v1/admin/users', [
            'email' => ' NEW.ADMIN@EXAMPLE.LOCAL ',
            'name' => 'New Plant Administrator',
            'temporary_password' => 'TemporaryPass123',
            'role_id' => self::ERP_ADMIN_ROLE_ID,
            'effective_from' => null,
            'effective_to' => null,
        ])->assertCreated()->assertJsonPath('data.record_version', 1);
        $userId = $created->json('data.id');
        $assignmentId = $created->json('data.role_assignment_id');

        $user = DB::table('users')->where('id', $userId)->first();
        $this->assertSame('new.admin@example.local', $user->email);
        $this->assertTrue(Hash::check('TemporaryPass123', $user->password_hash));
        $this->assertStringNotContainsString('TemporaryPass123', json_encode($created->json()));

        $this->withHeaders($this->commandHeaders(1))->postJson("/api/v1/admin/users/{$userId}", [
            'email' => 'new.admin@example.local',
            'name' => 'Updated Plant Administrator',
            'status' => 'ACTIVE',
        ])->assertOk()->assertJsonPath('data.record_version', 2);

        $this->withHeaders($this->commandHeaders(1))->postJson("/api/v1/admin/role-assignments/{$assignmentId}", [
            'role_id' => self::ERP_ADMIN_ROLE_ID,
            'is_active' => false,
            'effective_from' => null,
            'effective_to' => null,
        ])->assertOk()->assertJsonPath('data.status', 'INACTIVE');

        $this->assertDatabaseHas('users', ['id' => $userId, 'record_version' => 2]);
        $this->assertDatabaseHas('role_assignments', ['id' => $assignmentId, 'record_version' => 2, 'is_active' => false]);
        $this->assertDatabaseHas('audit_events', ['command' => 'CREATE_USER', 'entity_id' => $userId]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'foundation.role-assignment.updated', 'aggregate_id' => $assignmentId]);
    }

    public function test_custom_permission_role_and_assignment_change_effective_access(): void
    {
        $permission = $this->command()->postJson('/api/v1/admin/permissions', [
            'code' => 'ACTION:ADM-LOC:EXPORT',
            'name' => 'Export locations',
            'description' => 'Allows a controlled location export.',
        ])->assertCreated();
        $permissionId = $permission->json('data.id');

        $role = $this->command()->postJson('/api/v1/admin/roles', [
            'code' => 'LOCATION_AUDITOR',
            'name' => 'Location Auditor',
            'description' => 'Read and export plant locations.',
        ])->assertCreated();
        $roleId = $role->json('data.id');
        $screenPermissionId = DB::table('permissions')->where('code', 'SCREEN:ADM-LOC:VIEW')->value('id');

        $this->withHeaders($this->commandHeaders(1))->postJson("/api/v1/admin/roles/{$roleId}/permissions", [
            'permission_ids' => [$screenPermissionId, $permissionId],
        ])->assertOk()->assertJsonPath('data.record_version', 2);

        $createdUser = $this->command()->postJson('/api/v1/admin/users', [
            'email' => 'location.auditor@example.local',
            'name' => 'Location Auditor',
            'temporary_password' => 'TemporaryPass123',
            'role_id' => $roleId,
            'effective_from' => null,
            'effective_to' => null,
        ])->assertCreated();
        $userId = $createdUser->json('data.id');

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $session = $this->postJson('/api/v1/auth/login', [
            'email' => 'location.auditor@example.local',
            'password' => 'TemporaryPass123',
        ])->assertOk();
        $this->assertContains('ADM-LOC', $session->json('data.allowed_screens'));
        $this->assertContains('ACTION:ADM-LOC:EXPORT', $session->json('data.allowed_actions'));

        $this->actingAs(User::query()->findOrFail($userId))->withSession([
            'erp.company_id' => self::COMPANY_ID,
            'erp.plant_id' => self::TRAINING_PLANT_ID,
        ])->getJson('/api/v1/admin/locations')->assertOk();
        $this->postJson('/api/v1/admin/locations', [])->assertForbidden();
    }

    public function test_non_admin_and_out_of_scope_commands_are_denied(): void
    {
        $this->signIn(self::SALES_USER_ID, self::TRAINING_PLANT_ID);
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->command()->postJson('/api/v1/admin/plants', [])->assertForbidden();

        $this->signIn(self::ADMIN_USER_ID, self::TRAINING_PLANT_ID);
        $this->withHeaders($this->commandHeaders(1))->postJson('/api/v1/admin/locations/00000000-0000-4000-8000-000000000802', [
            'name' => 'Foreign update',
            'description' => null,
            'location_type' => 'RETURN_QUARANTINE',
            'parent_location_id' => null,
            'status' => 'ACTIVE',
        ])->assertNotFound();
    }

    private function createLocation(string $code, string $name, ?string $parentId): string
    {
        return $this->command()->postJson('/api/v1/admin/locations', [
            'code' => $code,
            'name' => $name,
            'description' => null,
            'location_type' => $parentId === null ? 'WAREHOUSE' : 'ZONE',
            'parent_location_id' => $parentId,
            'status' => 'ACTIVE',
        ])->assertCreated()->json('data.id');
    }

    private function signIn(string $userId, string $plantId): void
    {
        $this->actingAs(User::query()->findOrFail($userId))->withSession([
            'erp.company_id' => self::COMPANY_ID,
            'erp.plant_id' => $plantId,
        ]);
    }

    private function command(): static
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid());
    }

    private function commandHeaders(?int $version = null): array
    {
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        if ($version !== null) {
            $headers['If-Match'] = (string) $version;
        }

        return $headers;
    }
}
