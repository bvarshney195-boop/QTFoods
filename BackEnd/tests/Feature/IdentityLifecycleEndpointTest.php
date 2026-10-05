<?php

namespace Tests\Feature;

use App\Modules\Foundation\Application\MfaService;
use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IdentityLifecycleEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const ADMIN_ROLE_ID = '00000000-0000-4000-8000-000000000304';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
        config(['qtfoods.identity.preview_links' => true]);
    }

    public function test_administrator_can_invite_and_user_accepts_a_one_time_hashed_link(): void
    {
        $admin = $this->user('admin.user@qtfoods.local');
        $response = $this->actingAs($admin)->withSession($this->context())
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/user-invitations', [
                'email' => 'INVITED.USER@EXAMPLE.LOCAL',
                'name' => 'Invited User',
                'role_id' => self::ADMIN_ROLE_ID,
                'effective_from' => null,
                'effective_to' => null,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.delivery.status', 'SENT');

        $token = $this->tokenFromPreview($response->json('data.delivery.preview_url'));
        $userId = $response->json('data.user_id');
        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => 'invited.user@example.local',
            'status' => 'INVITED',
            'email_verified_at' => null,
        ]);
        $this->assertDatabaseHas('identity_invitations', [
            'id' => $response->json('data.id'),
            'token_hash' => hash('sha256', $token),
        ]);
        $this->assertDatabaseMissing('identity_invitations', ['token_hash' => $token]);

        $this->getJson('/api/v1/auth/invitations/'.$token)
            ->assertOk()
            ->assertJsonPath('data.email', 'invited.user@example.local')
            ->assertJsonPath('data.plant_name', 'Training Plant');

        $this->postJson('/api/v1/auth/invitations/accept', [
            'token' => $token,
            'password' => 'InvitationPass123',
            'password_confirmation' => 'InvitationPass123',
        ])
            ->assertOk()
            ->assertJsonPath('data.accepted', true);

        $invited = $this->user('invited.user@example.local');
        self::assertSame('ACTIVE', $invited->status);
        self::assertNotNull($invited->email_verified_at);
        self::assertTrue(Hash::check('InvitationPass123', $invited->password_hash));
        $this->assertDatabaseHas('audit_events', [
            'command' => 'ACCEPT_USER_INVITATION',
            'entity_id' => $response->json('data.id'),
        ]);

        $this->postJson('/api/v1/auth/invitations/accept', [
            'token' => $token,
            'password' => 'AnotherPassword123',
            'password_confirmation' => 'AnotherPassword123',
        ])->assertUnprocessable();

        $primary = $this->postJson('/api/v1/auth/login', [
            'email' => 'invited.user@example.local',
            'password' => 'InvitationPass123',
        ])->assertStatus(202)->assertJsonPath('data.phase', 'SELECT_SECOND_FACTOR');
        $this->assertGuest();
        $second = $this->postJson('/api/v1/auth/challenge', [
            'challenge_id' => $primary->json('data.challenge_id'),
            'action' => 'select_email_otp',
        ])->assertStatus(202)->assertJsonPath('data.phase', 'EMAIL_OTP_SECOND');
        $this->postJson('/api/v1/auth/challenge', [
            'challenge_id' => $second->json('data.challenge_id'),
            'action' => 'verify_email_otp',
            'code' => $second->json('data.delivery.preview_code'),
        ])->assertOk()->assertJsonPath('data.user.id', $userId);
    }

    public function test_invitation_resend_rotates_the_token_and_revocation_disables_the_invited_account(): void
    {
        $admin = $this->user('admin.user@qtfoods.local');
        $created = $this->actingAs($admin)->withSession($this->context())
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/user-invitations', [
                'email' => 'rotated.invite@example.local',
                'name' => 'Rotated Invite',
                'role_id' => self::ADMIN_ROLE_ID,
            ])->assertCreated();
        $invitationId = $created->json('data.id');
        $firstToken = $this->tokenFromPreview($created->json('data.delivery.preview_url'));

        $resent = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
            'If-Match' => '1',
        ])->postJson("/api/v1/admin/user-invitations/{$invitationId}/resend", [])
            ->assertOk()
            ->assertJsonPath('data.record_version', 2);
        $secondToken = $this->tokenFromPreview($resent->json('data.delivery.preview_url'));
        self::assertNotSame($firstToken, $secondToken);
        $this->getJson('/api/v1/auth/invitations/'.$firstToken)->assertNotFound();
        $this->getJson('/api/v1/auth/invitations/'.$secondToken)->assertOk();

        $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
            'If-Match' => '2',
        ])->postJson("/api/v1/admin/user-invitations/{$invitationId}/revoke", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'REVOKED');
        $this->getJson('/api/v1/auth/invitations/'.$secondToken)->assertNotFound();
        $this->assertDatabaseHas('users', [
            'email' => 'rotated.invite@example.local',
            'status' => 'INACTIVE',
        ]);
    }

    public function test_password_reset_is_non_enumerating_one_time_and_revokes_sessions(): void
    {
        config(['qtfoods.identity.preview_links' => false]);
        $unknown = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'missing@example.local',
        ])->assertAccepted()->json('data');
        $known = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'demo.user@qtfoods.local',
        ])->assertAccepted()->json('data');
        self::assertSame($unknown, $known);

        config(['qtfoods.identity.preview_links' => true]);
        $issued = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'demo.user@qtfoods.local',
        ])->assertAccepted();
        $token = $this->tokenFromPreview($issued->json('data.delivery.preview_url'));

        $this->postJson('/api/v1/auth/login', [
            'email' => 'demo.user@qtfoods.local',
            'password' => 'prototype',
        ])->assertOk();
        $sessionId = DB::table('user_sessions')->where('user_id', $this->user('demo.user@qtfoods.local')->id)
            ->whereNull('revoked_at')->value('id');

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'DEMO.USER@QTFOODS.LOCAL',
            'token' => $token,
            'password' => 'ResetPassword123',
            'password_confirmation' => 'ResetPassword123',
        ])->assertOk()->assertJsonPath('data.reset', true);
        $this->assertDatabaseHas('user_sessions', [
            'id' => $sessionId,
            'revoke_reason' => 'PASSWORD_RESET',
        ]);

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'demo.user@qtfoods.local',
            'token' => $token,
            'password' => 'ResetAgain123',
            'password_confirmation' => 'ResetAgain123',
        ])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', [
            'email' => 'demo.user@qtfoods.local', 'password' => 'prototype',
        ])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', [
            'email' => 'demo.user@qtfoods.local', 'password' => 'ResetPassword123',
        ])->assertOk();
    }

    public function test_unverified_email_is_blocked_until_a_one_time_verification_link_is_consumed(): void
    {
        DB::table('users')->where('email', 'demo.user@qtfoods.local')->update(['email_verified_at' => null]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'demo.user@qtfoods.local', 'password' => 'prototype',
        ])->assertForbidden()->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED');

        $issued = $this->postJson('/api/v1/auth/email/verification/request', [
            'email' => 'demo.user@qtfoods.local',
        ])->assertAccepted();
        $token = $this->tokenFromPreview($issued->json('data.delivery.preview_url'));
        $this->postJson('/api/v1/auth/email/verify', [
            'email' => 'demo.user@qtfoods.local',
            'token' => $token,
        ])->assertOk()->assertJsonPath('data.verified', true);
        $this->postJson('/api/v1/auth/email/verify', [
            'email' => 'demo.user@qtfoods.local',
            'token' => $token,
        ])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', [
            'email' => 'demo.user@qtfoods.local', 'password' => 'prototype',
        ])->assertOk();
    }

    public function test_totp_and_one_time_recovery_codes_complete_a_two_step_login(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'demo.user@qtfoods.local', 'password' => 'prototype',
        ])->assertOk();

        $setup = $this->postJson('/api/v1/auth/mfa/setup', [
            'current_password' => 'prototype',
        ])->assertOk();
        $secret = $setup->json('data.secret');
        $code = app(MfaService::class)->codeForSecret($secret);
        $confirmed = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.enabled', true);
        $recoveryCodes = $confirmed->json('data.recovery_codes');
        self::assertCount(8, $recoveryCodes);
        self::assertNotSame($secret, $this->user('demo.user@qtfoods.local')->mfa_secret);
        self::assertSame(8, DB::table('user_mfa_recovery_codes')->count());

        $this->postJson('/api/v1/auth/logout', [])->assertOk();
        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => 'demo.user@qtfoods.local', 'password' => 'prototype',
        ])->assertStatus(202)->assertJsonPath('data.mfa_required', true);
        $this->postJson('/api/v1/auth/mfa/challenge', [
            'challenge_id' => $challenge->json('data.challenge_id'),
            'code' => app(MfaService::class)->codeForSecret($secret),
        ])->assertOk()->assertJsonPath('data.authentication.mfa_method', 'PASSWORD+AUTHENTICATOR');

        $this->postJson('/api/v1/auth/logout', [])->assertOk();
        $recoveryChallenge = $this->postJson('/api/v1/auth/login', [
            'email' => 'demo.user@qtfoods.local', 'password' => 'prototype',
        ])->assertStatus(202);
        $this->postJson('/api/v1/auth/mfa/challenge', [
            'challenge_id' => $recoveryChallenge->json('data.challenge_id'),
            'code' => $recoveryCodes[0],
        ])->assertOk()->assertJsonPath('data.authentication.mfa_method', 'PASSWORD+RECOVERY_CODE');
        self::assertSame(1, DB::table('user_mfa_recovery_codes')->whereNotNull('used_at')->count());

        self::assertSame('287082', app(MfaService::class)->codeForSecret(
            'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            59
        ));
    }

    public function test_user_can_review_and_revoke_device_sessions_and_permissions_protect_admin_controls(): void
    {
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'demo.user@qtfoods.local', 'password' => 'prototype',
        ])->assertOk();
        $user = $this->user('demo.user@qtfoods.local');
        $currentId = $login->json('data.security.current_session_id');
        $otherId = (string) Str::uuid();
        DB::table('user_sessions')->insert([
            'id' => $otherId,
            'user_id' => $user->id,
            'ip_address' => '10.0.0.2',
            'user_agent' => 'Test secondary browser',
            'last_seen_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
            'revoked_at' => null,
            'revoked_by_user_id' => null,
            'revoke_reason' => null,
            'record_version' => 1,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->assertJsonPath('summary.active', 2)
            ->assertJsonFragment(['id' => $currentId, 'current' => true]);
        $this->postJson('/api/v1/auth/sessions/revoke-others', [])
            ->assertOk()
            ->assertJsonPath('data.revoked_count', 1);
        $this->assertDatabaseHas('user_sessions', [
            'id' => $otherId,
            'revoke_reason' => 'USER_REVOKED_OTHERS',
        ]);
        $this->postJson("/api/v1/auth/sessions/{$currentId}/revoke", [])
            ->assertOk()
            ->assertJsonPath('data.current', true);
        $this->getJson('/api/v1/me')->assertUnauthorized();

        $sales = $this->user('demo.user@qtfoods.local');
        $this->actingAs($sales)->withSession($this->context())
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/user-invitations', [
                'email' => 'forbidden@example.local',
                'name' => 'Forbidden Invite',
                'role_id' => self::ADMIN_ROLE_ID,
            ])->assertForbidden();
    }

    public function test_authenticated_password_change_keeps_current_device_and_revokes_other_devices(): void
    {
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'finance.user@qtfoods.local', 'password' => 'prototype',
        ])->assertOk();
        $user = $this->user('finance.user@qtfoods.local');
        $currentId = $login->json('data.security.current_session_id');
        $otherId = (string) Str::uuid();
        DB::table('user_sessions')->insert([
            'id' => $otherId,
            'user_id' => $user->id,
            'ip_address' => '10.1.1.8',
            'user_agent' => 'Finance tablet',
            'last_seen_at' => now(),
            'expires_at' => now()->addHour(),
            'revoked_at' => null,
            'revoked_by_user_id' => null,
            'revoke_reason' => null,
            'record_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/password/change', [
            'current_password' => 'prototype',
            'password' => 'ChangedPassword123',
            'password_confirmation' => 'ChangedPassword123',
        ])->assertOk()->assertJsonPath('data.other_sessions_revoked', 1);
        $this->assertDatabaseHas('user_sessions', [
            'id' => $currentId,
            'revoked_at' => null,
        ]);
        $this->assertDatabaseHas('user_sessions', [
            'id' => $otherId,
            'revoke_reason' => 'PASSWORD_CHANGED',
        ]);
        $this->getJson('/api/v1/me')->assertOk();
    }

    public function test_administrator_can_review_and_revoke_a_scoped_users_device(): void
    {
        $target = $this->user('operations.user@qtfoods.local');
        $targetSessionId = (string) Str::uuid();
        DB::table('user_sessions')->insert([
            'id' => $targetSessionId,
            'user_id' => $target->id,
            'ip_address' => '10.9.8.7',
            'user_agent' => 'Warehouse terminal',
            'last_seen_at' => now(),
            'expires_at' => now()->addHour(),
            'revoked_at' => null,
            'revoked_by_user_id' => null,
            'revoke_reason' => null,
            'record_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $admin = $this->user('admin.user@qtfoods.local');
        $this->actingAs($admin)->withSession($this->context())
            ->getJson("/api/v1/admin/users/{$target->id}/sessions")
            ->assertOk()
            ->assertJsonFragment(['id' => $targetSessionId, 'status' => 'ACTIVE']);

        $this->postJson("/api/v1/admin/users/{$target->id}/sessions/{$targetSessionId}/revoke", [])
            ->assertOk()
            ->assertJsonPath('data.revoked', true);
        $this->assertDatabaseHas('user_sessions', [
            'id' => $targetSessionId,
            'revoke_reason' => 'ADMIN_REVOKED',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'command' => 'REVOKE_USER_SESSION',
            'entity_id' => $targetSessionId,
        ]);
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function context(): array
    {
        return [
            'erp.company_id' => self::COMPANY_ID,
            'erp.plant_id' => self::PLANT_ID,
        ];
    }

    private function tokenFromPreview(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertArrayHasKey('token', $query);

        return (string) $query['token'];
    }
}
