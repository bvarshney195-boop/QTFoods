<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AuthenticationMethodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'qtfoods.identity.preview_links' => true,
            'qtfoods.identity.privileged_mfa_required' => true,
            'qtfoods.identity.mfa_required_roles' => ['ERP_ADMIN'],
        ]);
        $this->seed();
    }

    public function test_login_screen_methods_have_server_backing_and_email_otp_can_sign_in_standard_user(): void
    {
        $issued = $this->postJson('/api/v1/auth/email-otp/request', [
            'email' => 'demo.user@qtfoods.local',
        ])->assertAccepted()
            ->assertJsonPath('data.delivery.channel', 'EMAIL');

        $this->assertGuest();

        $this->postJson('/api/v1/auth/email-otp/verify', [
            'challenge_id' => $issued->json('data.challenge_id'),
            'code' => $issued->json('data.preview_code'),
        ])->assertOk()
            ->assertJsonPath('data.user.email', 'demo.user@qtfoods.local')
            ->assertJsonPath('data.authentication.primary_method', 'EMAIL_OTP')
            ->assertJsonPath('data.authentication.mfa_satisfied', false);

        $this->assertAuthenticated();
    }

    public function test_privileged_password_authentication_cannot_bypass_required_second_factor(): void
    {
        $primary = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.user@qtfoods.local',
            'method' => 'PASSWORD',
            'password' => 'prototype',
        ])->assertAccepted()
            ->assertJsonPath('data.mfa_required', true)
            ->assertJsonPath('data.primary_method', 'PASSWORD');

        $this->assertGuest();
        self::assertContains('EMAIL_OTP', $primary->json('data.methods'));

        $secondary = $this->postJson('/api/v1/auth/mfa/email-otp/request', [
            'challenge_id' => $primary->json('data.challenge_id'),
        ])->assertAccepted();

        $this->postJson('/api/v1/auth/mfa/challenge', [
            'challenge_id' => $primary->json('data.challenge_id'),
            'method' => 'EMAIL_OTP',
            'code' => $secondary->json('data.preview_code'),
        ])->assertOk()
            ->assertJsonPath('data.user.email', 'admin.user@qtfoods.local')
            ->assertJsonPath('data.authentication.primary_method', 'PASSWORD')
            ->assertJsonPath('data.authentication.second_factor', 'EMAIL_OTP')
            ->assertJsonPath('data.authentication.mfa_satisfied', true);

        $this->assertAuthenticated();
    }

    public function test_primary_email_otp_cannot_be_the_only_factor_for_privileged_user_without_totp(): void
    {
        $issued = $this->postJson('/api/v1/auth/email-otp/request', [
            'email' => 'admin.user@qtfoods.local',
        ])->assertAccepted();

        $this->postJson('/api/v1/auth/email-otp/verify', [
            'challenge_id' => $issued->json('data.challenge_id'),
            'code' => $issued->json('data.preview_code'),
        ])->assertForbidden()
            ->assertJsonPath('error.code', 'MFA_ENROLLMENT_REQUIRED');

        $this->assertGuest();
    }

    public function test_google_authenticator_can_be_selected_as_primary_method_but_privileged_user_still_steps_up(): void
    {
        /** @var User $admin */
        $admin = User::query()->where('email', 'admin.user@qtfoods.local')->firstOrFail();
        // Reuse the governed setup service through its public API after an authenticated bootstrap.
        $this->actingAs($admin);
        $setup = $this->postJson('/api/v1/auth/mfa/setup', [
            'password' => 'prototype',
        ])->assertOk();
        $secret = $setup->json('data.secret');
        $code = $this->totp($secret);
        $this->postJson('/api/v1/auth/mfa/confirm', [
            'code' => $code,
        ])->assertOk();
        auth()->logout();
        session()->flush();

        $primary = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.user@qtfoods.local',
            'method' => 'TOTP',
            'code' => $this->totp($secret),
        ])->assertAccepted()
            ->assertJsonPath('data.mfa_required', true)
            ->assertJsonPath('data.primary_method', 'TOTP');

        self::assertSame(['EMAIL_OTP'], $primary->json('data.methods'));
        $this->assertGuest();
    }

    public function test_unknown_email_otp_request_is_non_enumerating_and_never_authenticates(): void
    {
        $issued = $this->postJson('/api/v1/auth/email-otp/request', [
            'email' => 'unknown@example.invalid',
        ])->assertAccepted();

        $this->assertDatabaseHas('auth_email_otp_challenges', [
            'id' => $issued->json('data.challenge_id'),
            'user_id' => null,
        ]);
        $this->assertGuest();
    }

    private function totp(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bits = 0;
        $binary = '';
        foreach (str_split(strtoupper($secret)) as $character) {
            $value = strpos($alphabet, $character);
            if ($value === false) continue;
            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $binary .= chr(($buffer >> $bits) & 0xff);
            }
        }
        $counter = intdiv(time(), 30);
        $high = intdiv($counter, 4294967296);
        $low = $counter % 4294967296;
        $hash = hash_hmac('sha1', pack('N2', $high, $low), $binary, true);
        $offset = ord($hash[19]) & 0x0f;
        $binaryCode = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($binaryCode % 1000000), 6, '0', STR_PAD_LEFT);
    }
}
