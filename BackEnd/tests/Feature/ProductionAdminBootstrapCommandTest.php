<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class ProductionAdminBootstrapCommandTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'bvarshney195@gmail.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config()->set([
            'deployment.allow_demo_authentication' => false,
            'qtfoods.identity.preview_links' => true,
            'qtfoods.identity.mfa_required_roles' => ['ERP_ADMIN'],
        ]);
        Mail::fake();
    }

    public function test_authorised_command_creates_a_real_admin_context_and_is_idempotent(): void
    {
        $this->bootstrap()->assertExitCode(0);

        $user = DB::table('users')->where('email', self::EMAIL)->first();
        self::assertNotNull($user);
        self::assertSame('ACTIVE', $user->status);
        self::assertFalse((bool) $user->is_demo);
        self::assertNotNull($user->email_verified_at);
        self::assertNull($user->mfa_enabled_at);
        self::assertFalse(Hash::check('prototype', $user->password_hash));

        $company = DB::table('companies')->where('code', 'QTF-LIVE')->first();
        $plant = DB::table('plants')->where('company_id', $company->id)->where('code', 'HQ')->first();
        self::assertFalse((bool) $company->is_demo);
        self::assertFalse((bool) $plant->is_demo);
        self::assertSame('ACTIVE', $company->status);
        self::assertSame('ACTIVE', $plant->status);

        self::assertTrue(DB::table('role_assignments as assignment')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.user_id', $user->id)
            ->where('assignment.company_id', $company->id)
            ->where('assignment.plant_id', $plant->id)
            ->where('assignment.is_active', true)
            ->where('role.code', 'ERP_ADMIN')
            ->exists());
        self::assertTrue(DB::table('audit_events')
            ->where('command', 'BOOTSTRAP_PRODUCTION_ADMIN')
            ->where('actor_id', $user->id)
            ->where('reason_code', 'AUTHORIZED_OPERATIONAL_BOOTSTRAP')
            ->exists());
        self::assertSame(1, DB::table('identity_tokens')
            ->where('user_id', $user->id)
            ->where('type', 'PASSWORD_RESET')
            ->count());

        $counts = [
            'users' => DB::table('users')->count(),
            'companies' => DB::table('companies')->count(),
            'plants' => DB::table('plants')->count(),
            'assignments' => DB::table('role_assignments')->count(),
            'tokens' => DB::table('identity_tokens')->count(),
        ];
        $this->bootstrap()->assertExitCode(0);
        self::assertSame($counts, [
            'users' => DB::table('users')->count(),
            'companies' => DB::table('companies')->count(),
            'plants' => DB::table('plants')->count(),
            'assignments' => DB::table('role_assignments')->count(),
            'tokens' => DB::table('identity_tokens')->count(),
        ]);
    }

    public function test_bootstrapped_admin_can_start_passwordless_login_but_cannot_bypass_mfa(): void
    {
        $this->bootstrap()->assertExitCode(0);

        $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL,
            'method' => 'password',
            'password' => 'prototype',
        ])->assertUnprocessable()
            ->assertJsonPath('error.fields.email.0', 'The supplied credentials are invalid.');
        $this->assertGuest();

        $primary = $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL,
            'method' => 'email_otp',
        ])->assertStatus(202)
            ->assertJsonPath('data.phase', 'EMAIL_OTP_PRIMARY')
            ->assertJsonPath('data.primary_method', 'EMAIL_OTP');
        $this->assertGuest();

        $this->postJson('/api/v1/auth/challenge', [
            'challenge_id' => $primary->json('data.challenge_id'),
            'action' => 'verify_email_otp',
            'code' => $primary->json('data.delivery.preview_code'),
        ])->assertStatus(202)
            ->assertJsonPath('data.phase', 'TOTP_ENROLLMENT')
            ->assertJsonPath('data.totp_registered', false);
        $this->assertGuest();
    }

    public function test_command_refuses_missing_confirmation_and_synthetic_contexts(): void
    {
        $this->artisan('qt:identity:bootstrap-admin', [
            'email' => self::EMAIL,
        ])->assertExitCode(2);
        self::assertFalse(DB::table('users')->where('email', self::EMAIL)->exists());

        $this->artisan('qt:identity:bootstrap-admin', [
            'email' => self::EMAIL,
            '--company-code' => 'QTF',
            '--confirmation' => 'CREATE_PRODUCTION_ADMIN',
        ])->assertExitCode(2);
        self::assertFalse(DB::table('users')->where('email', self::EMAIL)->exists());
    }

    private function bootstrap()
    {
        return $this->artisan('qt:identity:bootstrap-admin', [
            'email' => self::EMAIL,
            '--name' => 'ERP Administrator',
            '--confirmation' => 'CREATE_PRODUCTION_ADMIN',
        ]);
    }
}
