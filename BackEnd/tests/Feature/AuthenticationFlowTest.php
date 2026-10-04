<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class AuthenticationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_named_user_can_sign_in_and_receives_role_scoped_navigation(): void
    {
        $this->seed();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'demo.user@qtfoods.local',
            'password' => 'prototype',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Demo Sales Manager')
            ->assertJsonPath('data.roles.0', 'SALES_MANAGER')
            ->assertJsonPath('data.selected_context', null)
            ->assertJsonFragment(['CRM-ORDER'])
            ->assertJsonFragment(['ACTION:RET-UNSOLD:CREATE'])
            ->assertJsonMissing(['ACTION:RET-UNSOLD:RECEIVE'])
            ->assertJsonMissing(['ADM-USER']);

        $this->assertAuthenticated();
    }


    public function test_privileged_password_login_cannot_bypass_required_second_factor(): void
    {
        $this->seed();
        Mail::fake();
        config([
            'qtfoods.identity.mfa_required_roles' => 'ERP_ADMIN,FINANCE_REVIEWER',
            'qtfoods.identity.preview_links' => true,
        ]);

        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.user@qtfoods.local',
            'password' => 'prototype',
        ])->assertStatus(202)
            ->assertJsonPath('data.mfa_required', true)
            ->assertJsonPath('data.primary_method', 'PASSWORD');

        $this->assertGuest();
        $challengeId = (string) $challenge->json('data.challenge_id');
        self::assertContains('EMAIL_OTP', $challenge->json('data.methods'));

        $emailFactor = $this->postJson('/api/v1/auth/mfa/email-otp', [
            'challenge_id' => $challengeId,
        ])->assertStatus(202);
        $code = (string) $emailFactor->json('data.preview_code');
        self::assertMatchesRegularExpression('/^\d{6}$/', $code);

        $this->postJson('/api/v1/auth/mfa/challenge', [
            'challenge_id' => $challengeId,
            'method' => 'EMAIL_OTP',
            'code' => $code,
        ])->assertOk()->assertJsonPath('data.roles.0', 'ERP_ADMIN');

        $this->assertAuthenticated();
    }

    public function test_email_otp_is_a_selectable_passwordless_login_for_non_privileged_user(): void
    {
        $this->seed();
        Mail::fake();
        config([
            'qtfoods.identity.mfa_required_roles' => 'ERP_ADMIN,FINANCE_REVIEWER',
            'qtfoods.identity.preview_links' => true,
        ]);

        $request = $this->postJson('/api/v1/auth/email-otp/request', [
            'email' => 'demo.user@qtfoods.local',
        ])->assertStatus(202);
        $challengeId = (string) $request->json('data.challenge_id');
        $code = (string) $request->json('data.preview_code');

        $this->postJson('/api/v1/auth/email-otp/verify', [
            'challenge_id' => $challengeId,
            'code' => $code,
        ])->assertOk()
            ->assertJsonPath('data.roles.0', 'SALES_MANAGER')
            ->assertJsonPath('data.authentication.method', 'EMAIL_OTP');

        $this->assertAuthenticated();
    }

    public function test_privileged_email_otp_primary_login_requires_an_independent_authenticator_factor(): void
    {
        $this->seed();
        Mail::fake();
        config([
            'qtfoods.identity.mfa_required_roles' => 'ERP_ADMIN',
            'qtfoods.identity.preview_links' => true,
        ]);

        $request = $this->postJson('/api/v1/auth/email-otp/request', [
            'email' => 'admin.user@qtfoods.local',
        ])->assertStatus(202);

        $this->postJson('/api/v1/auth/email-otp/verify', [
            'challenge_id' => $request->json('data.challenge_id'),
            'code' => $request->json('data.preview_code'),
        ])->assertForbidden()
            ->assertJsonPath('error.code', 'MFA_ENROLMENT_REQUIRED');

        $this->assertGuest();
    }

    public function test_context_and_screen_permissions_are_enforced_server_side(): void
    {
        $this->seed();
        $user = User::query()->where('email', 'demo.user@qtfoods.local')->firstOrFail();

        $this->actingAs($user)->withSession([
            'erp.company_id' => '00000000-0000-4000-8000-000000000001',
            'erp.plant_id' => '00000000-0000-4000-8000-000000000101',
        ]);

        $this->getJson('/api/v1/sales/orders')->assertOk();
        $this->getJson('/api/v1/admin/users')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_seeded_manager_roles_receive_only_their_approved_workspaces(): void
    {
        $this->seed();
        $context = [
            'erp.company_id' => '00000000-0000-4000-8000-000000000001',
            'erp.plant_id' => '00000000-0000-4000-8000-000000000101',
        ];
        $contracts = [
            'operations.user@qtfoods.local' => [
                'role' => 'OPERATIONS_MANAGER',
                'screen_count' => 42,
                'visible' => ['WRK-HOME', 'PUR-REQ', 'INV-STK', 'PRO-ORDER', 'QC-SAFE', 'DSP-PICK'],
                'hidden' => ['ADM-USER', 'FIN-GL', 'HR-PAY', 'CRM-ORDER', 'PORTAL-EXT'],
                'denied_action' => 'ACTION:FIN-GL:JOURNAL-POST',
            ],
            'finance.user@qtfoods.local' => [
                'role' => 'FINANCE_REVIEWER',
                'screen_count' => 24,
                'visible' => ['WRK-HOME', 'PUR-REQ', 'FIN-AP', 'FIN-GL', 'BI-REP', 'OPT-PLAN'],
                'hidden' => ['ADM-USER', 'INV-STK', 'PRO-ORDER', 'QC-SAFE', 'CRM-ORDER', 'PORTAL-EXT'],
                'denied_action' => 'ACTION:PRO-ORDER:RELEASE',
            ],
        ];

        foreach ($contracts as $email => $contract) {
            $user = User::query()->where('email', $email)->firstOrFail();
            $payload = $this->actingAs($user)->withSession($context)
                ->getJson('/api/v1/me')
                ->assertOk()
                ->json('data');

            self::assertSame([$contract['role']], $payload['roles'], $email);
            self::assertCount($contract['screen_count'], $payload['allowed_screens'], $email);
            foreach ($contract['visible'] as $screen) {
                self::assertContains($screen, $payload['allowed_screens'], "{$email} should see {$screen}.");
            }
            foreach ($contract['hidden'] as $screen) {
                self::assertNotContains($screen, $payload['allowed_screens'], "{$email} should not see {$screen}.");
            }
            self::assertNotContains($contract['denied_action'], $payload['allowed_actions'], $email);
        }
    }

    public function test_business_routes_require_context_selection(): void
    {
        $this->seed();
        $user = User::query()->where('email', 'demo.user@qtfoods.local')->firstOrFail();

        $this->actingAs($user)
            ->getJson('/api/v1/work/tasks')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONTEXT_REQUIRED');
    }

    public function test_user_cannot_select_an_unassigned_plant(): void
    {
        $this->seed();
        $user = User::query()->where('email', 'demo.user@qtfoods.local')->firstOrFail();

        $this->actingAs($user)
            ->postJson('/api/v1/contexts/select', [
                'company_id' => '00000000-0000-4000-8000-000000000001',
                'plant_id' => '00000000-0000-4000-8000-000000000102',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_return_workflow_actions_are_separated_by_role(): void
    {
        $this->seed();
        $context = [
            'erp.company_id' => '00000000-0000-4000-8000-000000000001',
            'erp.plant_id' => '00000000-0000-4000-8000-000000000101',
        ];
        $caseId = '00000000-0000-4000-8000-000000009999';

        $sales = User::query()->where('email', 'demo.user@qtfoods.local')->firstOrFail();
        $this->actingAs($sales)->withSession($context)
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/receive", [])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $operations = User::query()->where('email', 'operations.user@qtfoods.local')->firstOrFail();
        $this->actingAs($operations)->withSession($context)
            ->postJson('/api/v1/sales/unsold-returns', [])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $finance = User::query()->where('email', 'finance.user@qtfoods.local')->firstOrFail();
        $this->actingAs($finance)->withSession($context)
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/disposition", [])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }
}
