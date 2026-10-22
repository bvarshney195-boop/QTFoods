<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExperienceNotificationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const TRAINING_PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const FINANCE_PLANT_ID = '00000000-0000-4000-8000-000000000102';
    private const OPERATIONS_USER_ID = '00000000-0000-4000-8000-000000000202';
    private const ADMIN_USER_ID = '00000000-0000-4000-8000-000000000204';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->signIn(self::OPERATIONS_USER_ID);
    }

    public function test_notification_state_actions_reject_an_item_outside_the_selected_plant(): void
    {
        $workItemId = $this->createWorkItem([
            'plant_id' => self::FINANCE_PLANT_ID,
            'title' => 'Finance-plant confidential task',
        ]);

        foreach (['read', 'dismiss'] as $action) {
            $this->postJson("/api/v1/experience/notifications/{$workItemId}/{$action}")
                ->assertNotFound();
        }

        $this->assertDatabaseMissing('user_notification_states', [
            'user_id' => self::OPERATIONS_USER_ID,
            'work_item_id' => $workItemId,
        ]);
    }

    public function test_notification_state_actions_match_required_permission_and_target_visibility_filters(): void
    {
        $permissionProtectedId = $this->createWorkItem([
            'title' => 'Permission-protected task',
            'required_permission' => 'ShowEntries',
        ]);
        $restrictedTargetId = (string) Str::uuid();
        $restrictedWorkItemId = $this->createWorkItem([
            'title' => 'Restricted purchase-order task',
            'target_screen_code' => 'PUR-PO',
            'target_record_id' => $restrictedTargetId,
        ]);
        DB::table('transaction_visibilities')->insert([
            'id' => (string) Str::uuid(),
            'transaction_type' => 'PURCHASE_ORDER',
            'transaction_id' => $restrictedTargetId,
            'module' => 'PROCUREMENT',
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::TRAINING_PLANT_ID,
            'visibility_status' => 'RESTRICTED',
            'restricted_by' => self::ADMIN_USER_ID,
            'restricted_at' => now(),
            'restriction_reason' => 'Commercially confidential acceptance fixture.',
            'record_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/experience/notifications')
            ->assertOk()
            ->assertJsonMissing(['id' => $permissionProtectedId])
            ->assertJsonMissing(['id' => $restrictedWorkItemId]);
        $this->postJson('/api/v1/experience/notifications/'.$permissionProtectedId.'/read')
            ->assertNotFound();
        $this->postJson('/api/v1/experience/notifications/'.$restrictedWorkItemId.'/dismiss')
            ->assertNotFound();
        $this->assertDatabaseMissing('user_notification_states', [
            'user_id' => self::OPERATIONS_USER_ID,
            'work_item_id' => $permissionProtectedId,
        ]);
        $this->assertDatabaseMissing('user_notification_states', [
            'user_id' => self::OPERATIONS_USER_ID,
            'work_item_id' => $restrictedWorkItemId,
        ]);

        $this->grantPermission(self::OPERATIONS_USER_ID, 'ShowEntries');

        $this->getJson('/api/v1/experience/notifications')
            ->assertOk()
            ->assertJsonFragment(['id' => $permissionProtectedId])
            ->assertJsonFragment(['id' => $restrictedWorkItemId]);
        $this->postJson('/api/v1/experience/notifications/'.$permissionProtectedId.'/read')
            ->assertOk()
            ->assertJsonPath('data.read', true)
            ->assertJsonPath('data.dismissed', false);
        $this->postJson('/api/v1/experience/notifications/'.$restrictedWorkItemId.'/dismiss')
            ->assertOk()
            ->assertJsonPath('data.dismissed', true);
    }

    public function test_valid_same_plant_and_company_wide_updates_remain_per_user(): void
    {
        $samePlantId = $this->createWorkItem(['title' => 'Training-plant task']);
        $companyWideId = $this->createWorkItem([
            'plant_id' => null,
            'title' => 'Company-wide task',
        ]);

        $this->postJson('/api/v1/experience/notifications/'.$samePlantId.'/read')
            ->assertOk()
            ->assertJsonPath('data.id', $samePlantId)
            ->assertJsonPath('data.read', true)
            ->assertJsonPath('data.dismissed', false);
        $this->postJson('/api/v1/experience/notifications/'.$companyWideId.'/dismiss')
            ->assertOk()
            ->assertJsonPath('data.id', $companyWideId)
            ->assertJsonPath('data.read', true)
            ->assertJsonPath('data.dismissed', true);

        $samePlantState = DB::table('user_notification_states')
            ->where('user_id', self::OPERATIONS_USER_ID)
            ->where('work_item_id', $samePlantId)
            ->first();
        $companyWideState = DB::table('user_notification_states')
            ->where('user_id', self::OPERATIONS_USER_ID)
            ->where('work_item_id', $companyWideId)
            ->first();
        $this->assertNotNull($samePlantState?->read_at);
        $this->assertNull($samePlantState?->dismissed_at);
        $this->assertNotNull($companyWideState?->read_at);
        $this->assertNotNull($companyWideState?->dismissed_at);

        $this->signIn(self::ADMIN_USER_ID);
        $this->getJson('/api/v1/experience/notifications')
            ->assertOk()
            ->assertJsonFragment(['id' => $samePlantId])
            ->assertJsonFragment(['id' => $companyWideId]);
        $this->assertDatabaseMissing('user_notification_states', [
            'user_id' => self::ADMIN_USER_ID,
            'work_item_id' => $samePlantId,
        ]);
        $this->assertDatabaseMissing('user_notification_states', [
            'user_id' => self::ADMIN_USER_ID,
            'work_item_id' => $companyWideId,
        ]);
    }

    private function createWorkItem(array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::table('work_items')->insert(array_merge([
            'id' => $id,
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::TRAINING_PLANT_ID,
            'kind' => 'TASK',
            'title' => 'Notification authorization fixture',
            'description' => 'Exercises notification state authorization.',
            'priority' => 'HIGH',
            'status' => 'OPEN',
            'assigned_user_id' => null,
            'required_permission' => null,
            'source_type' => null,
            'source_id' => null,
            'target_screen_code' => null,
            'target_record_id' => null,
            'due_at' => null,
            'completed_at' => null,
            'completed_by' => null,
            'completion_note' => null,
            'created_by' => self::ADMIN_USER_ID,
            'record_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    private function grantPermission(string $userId, string $permissionCode): void
    {
        $roleId = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where('company_id', self::COMPANY_ID)
            ->where('plant_id', self::TRAINING_PLANT_ID)
            ->where('is_active', true)
            ->value('role_id');
        $permissionId = DB::table('permissions')->where('code', $permissionCode)->value('id');
        $this->assertNotNull($roleId);
        $this->assertNotNull($permissionId);
        DB::table('role_permissions')->insertOrIgnore([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);
    }

    private function signIn(string $userId): void
    {
        $this->actingAs(User::query()->findOrFail($userId))->withSession([
            'erp.company_id' => self::COMPANY_ID,
            'erp.plant_id' => self::TRAINING_PLANT_ID,
        ]);
    }
}
