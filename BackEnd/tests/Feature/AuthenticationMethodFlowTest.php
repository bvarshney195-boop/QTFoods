<?php

namespace Tests\Feature;

use App\Modules\Foundation\Application\MfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AuthenticationMethodFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config()->set([
            'deployment.allow_demo_authentication' => true,
            'qtfoods.identity.preview_links' => true,
            'qtfoods.identity.mfa_required_roles' => ['ERP_ADMIN'],
        ]);
    }

    public function test_email_otp_is_a_complete_primary_method_for_a_standard_user(): void
    {
        DB::table('users')->where('email', 'demo.user@qtfoods.local')->update([
            'mfa_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
            'mfa_enabled_at' => now(),
        ]);

        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => 'demo.user@qtfoods.local',
            'method' => 'email_otp',
        ])->assertStatus(202)
            ->assertJsonPath('data.phase', 'EMAIL_OTP_PRIMARY')
            ->assertJsonPath('data.primary_method', 'EMAIL_OTP');

        $this->assertGuest();
        $this->postJson('/api/v1/auth/challenge', [
            'challenge_id' => $challenge->json('data.challenge_id'),
            'action' => 'verify_email_otp',
            'code' => $challenge->json('data.delivery.preview_code'),
        ])->assertOk()
            ->assertJsonPath('data.authentication.mfa_method', 'EMAIL_OTP');
        $this->assertAuthenticated();
    }

    public function test_password_is_a_complete_primary_method_for_an_enrolled_standard_user(): void
    {
        DB::table('users')->where('email', 'demo.user@qtfoods.local')->update([
            'mfa_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
            'mfa_enabled_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'demo.user@qtfoods.local',
            'method' => 'password',
            'password' => 'prototype',
        ])->assertOk()
            ->assertJsonPath('data.authentication.mfa_method', 'PASSWORD');
        $this->assertAuthenticated();
    }

    public function test_admin_managed_mfa_policy_requires_two_independent_factors_for_a_standard_user(): void
    {
        DB::table('users')->where('email', 'demo.user@qtfoods.local')->update([
            'mfa_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
            'mfa_enabled_at' => now(),
            'mfa_required_by_admin' => true,
        ]);

        $password = $this->postJson('/api/v1/auth/login', [
            'email' => 'demo.user@qtfoods.local',
            'method' => 'password',
            'password' => 'prototype',
        ])->assertStatus(202)
            ->assertJsonPath('data.phase', 'SELECT_SECOND_FACTOR');
        $this->assertGuest();

        $this->postJson('/api/v1/auth/challenge', [
            'challenge_id' => $password->json('data.challenge_id'),
            'action' => 'select_totp',
        ])->assertStatus(202)->assertJsonPath('data.phase', 'TOTP_SECOND');

        $this->withSession([]);
        $totp = $this->postJson('/api/v1/auth/login', [
            'email' => 'demo.user@qtfoods.local',
            'method' => 'totp',
        ])->assertStatus(202)->assertJsonPath('data.phase', 'TOTP_PRIMARY');
        $this->postJson('/api/v1/auth/challenge', [
            'challenge_id' => $totp->json('data.challenge_id'),
            'action' => 'verify_totp',
            'code' => app(MfaService::class)->codeForSecret('JBSWY3DPEHPK3PXP'),
        ])->assertStatus(202)->assertJsonPath('data.phase', 'PASSWORD_SECOND_AFTER_TOTP');
        $this->assertGuest();
    }

    public function test_email_otp_fails_closed_when_the_mailer_only_logs_messages(): void
    {
        config()->set([
            'mail.default' => 'log',
            'qtfoods.identity.preview_links' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'demo.user@qtfoods.local',
            'method' => 'email_otp',
        ])->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'EMAIL_OTP_DELIVERY_FAILED')
            ->assertJsonPath(
                'error.message',
                'We could not send a sign-in code. Try again or contact your organisation administrator.',
            );

        $this->assertGuest();
        self::assertNull(session('identity.login_challenge'));
    }

    public function test_unregistered_passwordless_email_has_an_actionable_message_without_issuing_a_challenge(): void
    {
        foreach (['email_otp', 'totp'] as $method) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'unknown@qtfoods.test',
                'method' => $method,
            ])->assertUnprocessable()
                ->assertJsonPath(
                    'error.fields.email.0',
                    'This email is not registered. Contact your organisation administrator to register it.',
                );
            $this->assertGuest();
        }
    }

    public function test_registered_totp_can_be_selected_directly_by_a_standard_user(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        DB::table('users')->where('email', 'demo.user@qtfoods.local')->update([
            'mfa_secret' => Crypt::encryptString($secret),
            'mfa_enabled_at' => now(),
        ]);

        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => 'demo.user@qtfoods.local',
            'method' => 'totp',
        ])->assertStatus(202)
            ->assertJsonPath('data.phase', 'TOTP_PRIMARY')
            ->assertJsonPath('data.totp_registered', true)
            ->assertJsonMissingPath('data.setup');

        $this->assertGuest();
        $this->postJson('/api/v1/auth/challenge', [
            'challenge_id' => $challenge->json('data.challenge_id'),
            'action' => 'verify_totp',
            'code' => app(MfaService::class)->codeForSecret($secret),
        ])->assertOk()
            ->assertJsonPath('data.authentication.mfa_method', 'AUTHENTICATOR');
        $this->assertAuthenticated();
    }

    public function test_totp_enrolment_qr_is_released_only_after_password_proof(): void
    {
        $proof = $this->postJson('/api/v1/auth/login', [
            'email' => 'demo.user@qtfoods.local',
            'method' => 'totp',
        ])->assertStatus(202)
            ->assertJsonPath('data.phase', 'PASSWORD_PROOF_FOR_TOTP_SETUP')
            ->assertJsonPath('data.totp_registered', false)
            ->assertJsonMissingPath('data.setup')
            ->assertJsonMissingPath('data.delivery');

        $this->postJson('/api/v1/auth/challenge', [
            'challenge_id' => $proof->json('data.challenge_id'),
            'action' => 'verify_password',
            'password' => 'not-the-password',
        ])->assertUnprocessable()
            ->assertJsonPath('error.fields.password.0', 'The supplied password is invalid.');
        $this->assertGuest();

        $enrolment = $this->postJson('/api/v1/auth/challenge', [
            'challenge_id' => $proof->json('data.challenge_id'),
            'action' => 'verify_password',
            'password' => 'prototype',
        ])->assertStatus(202)
            ->assertJsonPath('data.phase', 'TOTP_ENROLLMENT');

        $secret = $enrolment->json('data.setup.secret');
        self::assertIsString($secret);
        self::assertStringStartsWith('otpauth://totp/', $enrolment->json('data.setup.otpauth_uri'));
        $this->assertGuest();

        $this->postJson('/api/v1/auth/challenge', [
            'challenge_id' => $enrolment->json('data.challenge_id'),
            'action' => 'confirm_totp_setup',
            'code' => app(MfaService::class)->codeForSecret($secret),
        ])->assertOk()
            ->assertJsonPath('data.authentication.mfa_method', 'PASSWORD+AUTHENTICATOR')
            ->assertJsonPath('data.authentication.mfa_enrolment.enabled', true);
        $this->assertAuthenticated();
    }

    public function test_privileged_password_can_enrol_totp_as_the_required_second_factor(): void
    {
        $primary = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.user@qtfoods.local',
            'method' => 'password',
            'password' => 'prototype',
        ])->assertStatus(202)
            ->assertJsonPath('data.phase', 'SELECT_SECOND_FACTOR')
            ->assertJsonPath('data.available_methods.1', 'totp');

        $enrolment = $this->postJson('/api/v1/auth/challenge', [
            'challenge_id' => $primary->json('data.challenge_id'),
            'action' => 'select_totp',
        ])->assertStatus(202)
            ->assertJsonPath('data.phase', 'TOTP_ENROLLMENT')
            ->assertJsonMissingPath('data.delivery');
        $this->assertGuest();

        $secret = $enrolment->json('data.setup.secret');
        $this->postJson('/api/v1/auth/challenge', [
            'challenge_id' => $enrolment->json('data.challenge_id'),
            'action' => 'confirm_totp_setup',
            'code' => app(MfaService::class)->codeForSecret($secret),
        ])->assertOk()
            ->assertJsonPath('data.authentication.mfa_method', 'PASSWORD+AUTHENTICATOR')
            ->assertJsonPath('data.authentication.mfa_enrolment.enabled', true);
        $this->assertAuthenticated();
    }

    public function test_privileged_password_cannot_create_a_session_until_an_approved_second_factor_passes(): void
    {
        $primary = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.user@qtfoods.local',
            'method' => 'password',
            'password' => 'prototype',
        ])->assertStatus(202)
            ->assertJsonPath('data.phase', 'SELECT_SECOND_FACTOR')
            ->assertJsonPath('data.available_methods.0', 'email_otp');
        $this->assertGuest();

        $second = $this->postJson('/api/v1/auth/challenge', [
            'challenge_id' => $primary->json('data.challenge_id'),
            'action' => 'select_email_otp',
        ])->assertStatus(202)
            ->assertJsonPath('data.phase', 'EMAIL_OTP_SECOND');
        $this->assertGuest();

        $this->postJson('/api/v1/auth/challenge', [
            'challenge_id' => $second->json('data.challenge_id'),
            'action' => 'verify_email_otp',
            'code' => $second->json('data.delivery.preview_code'),
        ])->assertOk()
            ->assertJsonPath('data.authentication.mfa_method', 'PASSWORD+EMAIL_OTP');
        $this->assertAuthenticated();
    }

    public function test_privileged_totp_primary_still_requires_an_independent_password_factor(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        DB::table('users')->where('email', 'admin.user@qtfoods.local')->update([
            'mfa_secret' => Crypt::encryptString($secret),
            'mfa_enabled_at' => now(),
        ]);

        $primary = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.user@qtfoods.local',
            'method' => 'totp',
        ])->assertStatus(202)
            ->assertJsonPath('data.phase', 'TOTP_PRIMARY');

        $second = $this->postJson('/api/v1/auth/challenge', [
            'challenge_id' => $primary->json('data.challenge_id'),
            'action' => 'verify_totp',
            'code' => app(MfaService::class)->codeForSecret($secret),
        ])->assertStatus(202)
            ->assertJsonPath('data.phase', 'PASSWORD_SECOND_AFTER_TOTP')
            ->assertJsonMissingPath('data.delivery');
        $this->assertGuest();

        $this->postJson('/api/v1/auth/challenge', [
            'challenge_id' => $second->json('data.challenge_id'),
            'action' => 'verify_password',
            'password' => 'prototype',
        ])->assertOk()
            ->assertJsonPath('data.authentication.mfa_method', 'AUTHENTICATOR+PASSWORD');
        $this->assertAuthenticated();
    }

    public function test_demo_principals_are_not_login_eligible_when_production_policy_disables_them(): void
    {
        config()->set('deployment.allow_demo_authentication', false);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'demo.user@qtfoods.local',
            'method' => 'password',
            'password' => 'prototype',
        ])->assertUnprocessable()
            ->assertJsonPath('error.fields.email.0', 'The supplied credentials are invalid.');
        $this->assertGuest();
    }
}
