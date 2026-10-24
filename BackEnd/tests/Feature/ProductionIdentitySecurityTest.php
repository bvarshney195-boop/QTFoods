<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use App\Shared\Deployment\ProductionIdentityVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductionIdentitySecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config()->set([
            'deployment.allow_demo_authentication' => false,
            'deployment.allow_demo_seeders' => false,
        ]);
    }

    public function test_release_gate_detects_then_accepts_quarantined_demo_credentials_and_sessions(): void
    {
        $demoId = (string) DB::table('users')->where('email', 'demo.user@qtfoods.local')->value('id');
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $demoId,
            'user_agent' => 'Audit fixture',
            'ip_address' => '127.0.0.1',
            'last_seen_at' => now(),
            'expires_at' => now()->addHour(),
            'revoked_at' => null,
            'revoked_by_user_id' => null,
            'revoke_reason' => null,
            'record_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unsafe = app(ProductionIdentityVerifier::class)->verify();
        self::assertSame('fail', $unsafe['status']);
        self::assertContains('identity.demo_principal_active', collect($unsafe['issues'])->pluck('code')->all());
        self::assertContains('identity.demo_session_active', collect($unsafe['issues'])->pluck('code')->all());
        self::assertContains('identity.preset_credential_present', collect($unsafe['issues'])->pluck('code')->all());

        $demoIds = DB::table('users')->where('is_demo', true)->pluck('id');
        DB::table('users')->whereIn('id', $demoIds)->update([
            'status' => 'INACTIVE',
            'password_hash' => Hash::make(Str::random(64)),
            'password_changed_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_sessions')->whereIn('user_id', $demoIds)->whereNull('revoked_at')->update([
            'revoked_at' => now(),
            'revoke_reason' => 'DEMO_IDENTITY_QUARANTINED',
            'updated_at' => now(),
        ]);

        $safe = app(ProductionIdentityVerifier::class)->verify();
        self::assertSame('pass', $safe['status']);
        self::assertSame(0, $safe['metrics']['active_demo_principals']);
        self::assertSame(0, $safe['metrics']['active_demo_sessions']);
        self::assertSame(0, $safe['metrics']['preset_credentials']);
        $this->artisan('qt:security:verify-identities')->assertExitCode(0);
    }

    public function test_non_demo_users_cannot_receive_or_select_a_synthetic_context(): void
    {
        DB::table('users')->where('email', 'finance.user@qtfoods.local')->update([
            'email' => 'real.finance@qtfoods.test',
            'name' => 'Real Finance Manager',
            'password_hash' => Hash::make(Str::random(64)),
            'is_demo' => false,
            'status' => 'ACTIVE',
            'updated_at' => now(),
        ]);
        $user = User::query()->where('email', 'real.finance@qtfoods.test')->firstOrFail();

        $result = app(ProductionIdentityVerifier::class)->verify();
        self::assertSame('fail', $result['status']);
        self::assertContains('identity.synthetic_context_assignment_leak', collect($result['issues'])->pluck('code')->all());

        $this->actingAs($user)->getJson('/api/v1/contexts')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('selected_context', null);
        $this->actingAs($user)->postJson('/api/v1/contexts/select', [
            'company_id' => '00000000-0000-4000-8000-000000000001',
            'plant_id' => '00000000-0000-4000-8000-000000000102',
        ])->assertUnprocessable();
    }
}
