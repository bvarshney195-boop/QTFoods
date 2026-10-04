<?php

namespace Tests\Feature;

use App\Modules\Foundation\Application\MfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class LoginMethodsEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
        config([
            'qtfoods.identity.preview_links' => true,
            'qtfoods.identity.allow_demo_login' => true,
            'qtfoods.identity.mfa_required_roles' => [],
        ]);
    }

    public function test_email_otp_can_be_selected_as_primary_authentication_for_standard_user(): void
    {
        $issued = $this->postJson('/api/v1/auth/email-otp/request', [
            'email' => 'demo.user@qtfoods.local',
        ])->assertAccepted()
            ->assertJsonPath('data.delivery.channel', 'EMAIL');

        $this->postJson('/api/v1/auth/email-otp/verify', [
            'email' => 'demo.user@qtfoods.local',
            'challenge_id' => $issued->json('data.challenge_id'),
            'code' => $issued->json('data.preview_code'),
        ])->assertOk()
            ->assertJsonPath('data.authentication.mfa_method', 'EMAIL_OTP')
            ->assertJsonPath('data.user.email', 'demo.user@qtfoods.local');

        $this->postJson('/api/v1/auth/email-otp/verify', [
            'email' => 'demo.user@qtfoods.local',
            'challenge_id' => $issued->json('data.challenge_id'),
            'code' => $issued->json('data.preview_code'),
        ])->assertUnprocessable();
    }

    public function test_privileged_password_authentication_cannot_bypass_required_second_factor(): void
    {
        config(['qtfoods.identity.mfa_required_roles' => ['ERP_ADMIN']]);

        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.user@qtfoods.local',
            'password' => 'prototype',
        ])->assertStatus(202)
            ->assertJsonPath('data.mfa_required', true)
            ->assertJsonPath('data.primary_method', 'PASSWORD');

        $this->getJson('/api/v1/me')->assertUnauthorized();

        $issued = $this->postJson('/api/v1/auth/mfa/email-otp/request', [
            'challenge_id' => $challenge->json('data.challenge_id'),
        ])->assertAccepted();

        $this->postJson('/api/v1/auth/mfa/challenge', [
            'challenge_id' => $challenge->json('data.challenge_id'),
            'method' => 'EMAIL_OTP',
            'code' => $issued->json('data.preview_code'),
        ])->assertOk()
            ->assertJsonPath('data.authentication.mfa_method', 'EMAIL_OTP')
            ->assertJsonPath('data.user.email', 'admin.user@qtfoods.local');
    }

    public function test_primary_email_otp_alone_does_not_bypass_privileged_mfa(): void
    {
        config(['qtfoods.identity.mfa_required_roles' => ['ERP_ADMIN']]);

        $issued = $this->postJson('/api/v1/auth/email-otp/request', [
            'email' => 'admin.user@qtfoods.local',
        ])->assertAccepted();

        $this->postJson('/api/v1/auth/email-otp/verify', [
            'email' => 'admin.user@qtfoods.local',
            'challenge_id' => $issued->json('data.challenge_id'),
            'code' => $issued->json('data.preview_code'),
        ])->assertForbidden()
            ->assertJsonPath('error.code', 'MFA_ENROLLMENT_REQUIRED');

        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_google_authenticator_can_be_selected_from_login_after_enrolment(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.user@qtfoods.local',
            'password' => 'prototype',
        ])->assertOk();

        $setup = $this->postJson('/api/v1/auth/mfa/setup', [
            'current_password' => 'prototype',
        ])->assertOk();
        $secret = $setup->json('data.secret');
        $this->postJson('/api/v1/auth/mfa/confirm', [
            'code' => app(MfaService::class)->codeForSecret($secret),
        ])->assertOk();
        $this->postJson('/api/v1/auth/logout', [])->assertOk();

        config(['qtfoods.identity.mfa_required_roles' => ['ERP_ADMIN']]);

        $this->postJson('/api/v1/auth/totp/login', [
            'email' => 'admin.user@qtfoods.local',
            'password' => 'prototype',
            'code' => app(MfaService::class)->codeForSecret($secret),
        ])->assertOk()
            ->assertJsonPath('data.authentication.mfa_method', 'AUTHENTICATOR')
            ->assertJsonPath('data.user.email', 'admin.user@qtfoods.local');
    }

    public function test_demo_principals_are_rejected_when_demo_login_is_disabled(): void
    {
        config(['qtfoods.identity.allow_demo_login' => false]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.user@qtfoods.local',
            'password' => 'prototype',
        ])->assertUnprocessable();

        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
