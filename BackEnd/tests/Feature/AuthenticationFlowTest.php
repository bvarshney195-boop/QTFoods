<?php

namespace Tests\Feature;

use App\Modules\Foundation\Application\MfaService;
use App\Modules\Foundation\Domain\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_email_otp_can_be_used_as_primary_authentication_for_non_privileged_user(): void
    {
        $this->seed();
        Mail::fake();
        config()->set('qtfoods.identity.preview_links', true);

        $challenge = $this->postJson('/api/v1/auth/email-otp/request', [
            'email' => 'demo.user@qtfoods.local',
        ])->assertAccepted()
            ->assertJsonPath('data.delivery.channel', 'EMAIL')
            ->json('data');

        self::assertMatchesRegularExpression('/^\d{6}$/', (string) $challenge['delivery']['preview_code']);

        $this->postJson('/api/v1/auth/email-otp/login', [
            'challenge_id' => $challenge['challenge_id'],
            'code' => $challenge['delivery']['preview_code'],
        ])->assertOk()
            ->assertJsonPath('data.user.email', 'demo.user@qtfoods.local')
            ->assertJsonPath('data.authentication.primary_method', 'EMAIL_OTP')
            ->assertJsonPath('data.authentication.mfa_method', null);

        $this->assertAuthenticated();
    }

    public function test_totp_can_be_used_as_primary_authentication_for_non_privileged_user(): void
    {
        $this->seed();
        $user = User::query()->where('email', 'demo.user@qtfoods.local')->firstOrFail();
        $secret = 'JBSWY3DPEHPK3PXP';
        $user->forceFill([
            'mfa_secret' => Crypt::encryptString($secret),
            'mfa_enabled_at' => now(),
        ])->save();

        $code = app(MfaService::class)->codeForSecret($secret);
        $this->postJson('/api/v1/auth/totp/login', [
            'email' => $user->email,
            'code' => $code,
        ])->assertAccepted()
            ->assertJsonPath('data.mfa_required', true)
            ->assertJsonPath('data.primary_method', 'AUTHENTICATOR')
            ->assertJsonPath('data.allowed_methods.0', 'EMAIL_OTP');

        // A user with MFA enabled cannot use the authenticator as both factors.
        $this->assertGuest();
    }

    public function test_privileged_password_authentication_cannot_bypass_second_factor(): void
    {
        $this->seed();
        config()->set('qtfoods.identity.enforce_privileged_mfa', true);
        config()->set('qtfoods.identity.mfa_required_roles', ['ERP_ADMIN', 'FINANCE_REVIEWER']);
        config()->set('qtfoods.identity.preview_links', true);
        Mail::fake();

        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.user@qtfoods.local',
            'password' => 'prototype',
        ])->assertAccepted()
            ->assertJsonPath('data.mfa_required', true)
            ->assertJsonPath('data.primary_method', 'PASSWORD')
            ->json('data');

        $this->assertGuest();

        $delivery = $this->postJson('/api/v1/auth/mfa/email-otp/request', [
            'challenge_id' => $challenge['challenge_id'],
        ])->assertAccepted()->json('data');

        $this->postJson('/api/v1/auth/mfa/challenge', [
            'challenge_id' => $challenge['challenge_id'],
            'method' => 'EMAIL_OTP',
            'code' => $delivery['delivery']['preview_code'],
        ])->assertOk()
            ->assertJsonPath('data.user.email', 'admin.user@qtfoods.local')
            ->assertJsonPath('data.authentication.primary_method', 'PASSWORD')
            ->assertJsonPath('data.authentication.mfa_method', 'EMAIL_OTP');

        $this->assertAuthenticated();
    }

    public function test_privileged_email_otp_primary_requires_distinct_totp_second_factor(): void
    {
        $this->seed();
        config()->set('qtfoods.identity.enforce_privileged_mfa', true);
        config()->set('qtfoods.identity.mfa_required_roles', ['ERP_ADMIN']);
        config()->set('qtfoods.identity.preview_links', true);
        Mail::fake();

        $user = User::query()->where('email', 'admin.user@qtfoods.local')->firstOrFail();
        $secret = 'JBSWY3DPEHPK3PXP';
        $user->forceFill([
            'mfa_secret' => Crypt::encryptString($secret),
            'mfa_enabled_at' => now(),
        ])->save();

        $primary = $this->postJson('/api/v1/auth/email-otp/request', [
            'email' => $user->email,
        ])->assertAccepted()->json('data');

        $second = $this->postJson('/api/v1/auth/email-otp/login', [
            'challenge_id' => $primary['challenge_id'],
            'code' => $primary['delivery']['preview_code'],
        ])->assertAccepted()
            ->assertJsonPath('data.primary_method', 'EMAIL_OTP')
            ->assertJsonMissing(['EMAIL_OTP'])
            ->json('data');

        $this->assertGuest();

        $this->postJson('/api/v1/auth/mfa/challenge', [
            'challenge_id' => $second['challenge_id'],
            'method' => 'TOTP',
            'code' => app(MfaService::class)->codeForSecret($secret),
        ])->assertOk()
            ->assertJsonPath('data.authentication.primary_method', 'EMAIL_OTP')
            ->assertJsonPath('data.authentication.mfa_method', 'AUTHENTICATOR');

        $this->assertAuthenticated();
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
