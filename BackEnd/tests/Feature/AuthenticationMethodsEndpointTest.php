<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuthenticationMethodsEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('qtfoods.identity.preview_links', true);
        config()->set('mail.default', 'log');
        $this->seed();
    }

    public function test_email_otp_can_be_used_as_primary_authentication_for_standard_user(): void
    {
        $request = $this->postJson('/api/v1/auth/email-otp/request', [
            'email' => 'demo.user@qtfoods.local',
        ])->assertAccepted()
          ->assertJsonPath('data.accepted', true);

        $challengeId = (string) $request->json('data.challenge_id');
        $code = (string) $request->json('data.preview_code');

        $this->postJson('/api/v1/auth/email-otp/verify', [
            'challenge_id' => $challengeId,
            'email' => 'demo.user@qtfoods.local',
            'code' => $code,
        ])->assertOk()
          ->assertJsonPath('data.user.email', 'demo.user@qtfoods.local')
          ->assertJsonPath('data.authentication.mfa_method', 'EMAIL_OTP');

        $this->assertAuthenticated();
    }

    public function test_privileged_password_cannot_bypass_configured_second_factor(): void
    {
        config()->set('qtfoods.identity.mfa_required_roles', ['ERP_ADMIN']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.user@qtfoods.local',
            'password' => 'prototype',
            'method' => 'PASSWORD',
        ])->assertAccepted()
          ->assertJsonPath('data.mfa_required', true)
          ->assertJsonPath('data.primary_method', 'PASSWORD');

        $this->assertGuest();
        self::assertContains('EMAIL_OTP', $response->json('data.available_methods'));

        $this->postJson('/api/v1/auth/mfa/challenge', [
            'challenge_id' => $response->json('data.challenge_id'),
            'method' => 'EMAIL_OTP',
            'code' => $response->json('data.preview_code'),
        ])->assertOk()
          ->assertJsonPath('data.user.email', 'admin.user@qtfoods.local')
          ->assertJsonPath('data.authentication.mfa_method', 'EMAIL_OTP');

        $this->assertAuthenticated();
    }

    public function test_email_otp_primary_cannot_be_reused_as_privileged_second_factor(): void
    {
        config()->set('qtfoods.identity.mfa_required_roles', ['ERP_ADMIN']);

        $request = $this->postJson('/api/v1/auth/email-otp/request', [
            'email' => 'admin.user@qtfoods.local',
        ])->assertAccepted();

        $this->postJson('/api/v1/auth/email-otp/verify', [
            'challenge_id' => $request->json('data.challenge_id'),
            'email' => 'admin.user@qtfoods.local',
            'code' => $request->json('data.preview_code'),
        ])->assertForbidden()
          ->assertJsonPath('error.code', 'MFA_ENROLLMENT_REQUIRED');

        $this->assertGuest();
    }

    public function test_email_otp_request_does_not_enumerate_unknown_or_unverified_accounts(): void
    {
        $unknown = $this->postJson('/api/v1/auth/email-otp/request', [
            'email' => 'nobody@qtfoods.local',
        ])->assertAccepted()->json('data');

        DB()->table('users')->where('email', 'demo.user@qtfoods.local')->update(['email_verified_at' => null]);
        $unverified = $this->postJson('/api/v1/auth/email-otp/request', [
            'email' => 'demo.user@qtfoods.local',
        ])->assertAccepted()->json('data');

        self::assertSame($unknown['message'], $unverified['message']);
        self::assertArrayNotHasKey('challenge_id', $unknown);
        self::assertArrayNotHasKey('challenge_id', $unverified);
    }
}
