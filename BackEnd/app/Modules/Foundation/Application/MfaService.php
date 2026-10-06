<?php

namespace App\Modules\Foundation\Application;

use App\Modules\Foundation\Domain\User;
use App\Shared\Audit\AuditService;
use App\Shared\Outbox\OutboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MfaService
{
    private const PENDING_KEY = 'identity.mfa_setup';
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(
        private readonly DeviceSessionService $deviceSessions,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    public function beginSetup(User $user, Request $request, string $currentPassword): array
    {
        $this->assertPassword($user, $currentPassword);

        return $this->beginVerifiedSetup($user, $request);
    }

    /** Start enrolment after the caller has already completed an approved proof. */
    public function beginVerifiedSetup(User $user, Request $request): array
    {
        if ($user->mfa_enabled_at !== null) {
            throw ValidationException::withMessages([
                'mfa' => ['Multi-factor authentication is already enabled.'],
            ]);
        }

        $secret = $this->base32Encode(random_bytes(20));
        $expiresAt = now()->addMinutes((int) config('qtfoods.identity.mfa_setup_minutes', 10));
        $request->session()->put(self::PENDING_KEY, [
            'user_id' => (string) $user->id,
            'secret' => Crypt::encryptString($secret),
            'expires_at' => $expiresAt->timestamp,
        ]);

        $issuer = (string) config('app.name', 'Q & T Foods ERP');
        $label = $issuer.':'.$user->email;

        return [
            'secret' => $secret,
            'otpauth_uri' => 'otpauth://totp/'.rawurlencode($label).'?'.http_build_query([
                'secret' => $secret,
                'issuer' => $issuer,
                'algorithm' => 'SHA1',
                'digits' => 6,
                'period' => 30,
            ], '', '&', PHP_QUERY_RFC3986),
            'expires_at' => $expiresAt->toISOString(),
        ];
    }

    public function confirm(User $user, Request $request, string $code): array
    {
        $pending = $request->session()->get(self::PENDING_KEY);
        if (! is_array($pending)
            || ($pending['user_id'] ?? null) !== (string) $user->id
            || ! is_int($pending['expires_at'] ?? null)
            || $pending['expires_at'] < now()->timestamp
            || ! is_string($pending['secret'] ?? null)) {
            $request->session()->forget(self::PENDING_KEY);
            throw ValidationException::withMessages([
                'mfa' => ['The MFA setup expired. Start setup again.'],
            ]);
        }

        try {
            $secret = Crypt::decryptString($pending['secret']);
        } catch (\Throwable) {
            $request->session()->forget(self::PENDING_KEY);
            throw ValidationException::withMessages([
                'mfa' => ['The MFA setup could not be verified. Start setup again.'],
            ]);
        }

        if (! $this->verifyTotp($secret, $code)) {
            throw ValidationException::withMessages(['code' => ['Enter a valid six-digit authenticator code.']]);
        }

        $recoveryCodes = $this->newRecoveryCodes();
        DB::transaction(function () use ($user, $secret, $recoveryCodes): void {
            $locked = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            if (! $locked || $locked->mfa_enabled_at !== null) {
                throw ValidationException::withMessages([
                    'mfa' => ['Multi-factor authentication is already enabled.'],
                ]);
            }

            $version = (int) $locked->record_version + 1;
            DB::table('users')->where('id', $user->id)->update([
                'mfa_secret' => Crypt::encryptString($secret),
                'mfa_enabled_at' => now(),
                'record_version' => $version,
                'updated_at' => now(),
            ]);
            DB::table('user_mfa_recovery_codes')->where('user_id', $user->id)->delete();
            $this->insertRecoveryCodes((string) $user->id, $recoveryCodes);
            $this->record($user, 'ENABLE_MFA', 'identity.mfa.enabled', $version, [
                'mfa_enabled' => ['from' => false, 'to' => true],
            ]);
        });

        $request->session()->forget(self::PENDING_KEY);
        $this->deviceSessions->revokeAllForUser(
            (string) $user->id,
            (string) $user->id,
            'MFA_ENABLED',
            $this->deviceSessions->currentId($request)
        );

        return [
            'enabled' => true,
            'recovery_codes' => $recoveryCodes,
        ];
    }

    public function disable(
        User $user,
        Request $request,
        string $currentPassword,
        string $code,
    ): array {
        $this->assertPassword($user, $currentPassword);
        if ($user->mfa_enabled_at === null || ! is_string($user->mfa_secret)) {
            throw ValidationException::withMessages(['mfa' => ['Multi-factor authentication is not enabled.']]);
        }
        if (! $this->verifyForUser($user, $code, true)) {
            throw ValidationException::withMessages(['code' => ['Enter a valid authenticator or recovery code.']]);
        }

        DB::transaction(function () use ($user): void {
            $locked = DB::table('users')->where('id', $user->id)->lockForUpdate()->firstOrFail();
            $version = (int) $locked->record_version + 1;
            DB::table('users')->where('id', $user->id)->update([
                'mfa_secret' => null,
                'mfa_enabled_at' => null,
                'record_version' => $version,
                'updated_at' => now(),
            ]);
            DB::table('user_mfa_recovery_codes')->where('user_id', $user->id)->delete();
            $this->record($user, 'DISABLE_MFA', 'identity.mfa.disabled', $version, [
                'mfa_enabled' => ['from' => true, 'to' => false],
            ]);
        });

        $this->deviceSessions->revokeAllForUser(
            (string) $user->id,
            (string) $user->id,
            'MFA_DISABLED',
            $this->deviceSessions->currentId($request)
        );

        return ['enabled' => false];
    }

    public function regenerateRecoveryCodes(
        User $user,
        string $currentPassword,
        string $code,
    ): array {
        $this->assertPassword($user, $currentPassword);
        if ($user->mfa_enabled_at === null || ! is_string($user->mfa_secret)) {
            throw ValidationException::withMessages(['mfa' => ['Enable multi-factor authentication first.']]);
        }
        if (! $this->verifyTotp($this->decryptSecret($user), $code)) {
            throw ValidationException::withMessages(['code' => ['Enter a valid six-digit authenticator code.']]);
        }

        $codes = $this->newRecoveryCodes();
        DB::transaction(function () use ($user, $codes): void {
            DB::table('user_mfa_recovery_codes')->where('user_id', $user->id)->delete();
            $this->insertRecoveryCodes((string) $user->id, $codes);
            $this->record($user, 'REGENERATE_MFA_RECOVERY_CODES', 'identity.mfa.recovery-codes.regenerated',
                (int) $user->record_version, []);
        });

        return ['recovery_codes' => $codes];
    }

    public function verifyLoginCode(User $user, string $code): ?string
    {
        if ($user->mfa_enabled_at === null || ! is_string($user->mfa_secret)) {
            return null;
        }

        if ($this->verifyTotp($this->decryptSecret($user), $code)) {
            return 'AUTHENTICATOR';
        }

        return $this->consumeRecoveryCode($user, $code) ? 'RECOVERY_CODE' : null;
    }

    public function codeForSecret(string $secret, ?int $timestamp = null): string
    {
        $counter = intdiv($timestamp ?? time(), 30);
        $binaryCounter = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
        $hash = hash_hmac('sha1', $binaryCounter, $this->base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private function verifyForUser(User $user, string $code, bool $allowRecovery): bool
    {
        if ($this->verifyTotp($this->decryptSecret($user), $code)) {
            return true;
        }

        return $allowRecovery && $this->consumeRecoveryCode($user, $code);
    }

    private function verifyTotp(string $secret, string $code): bool
    {
        $normalised = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $normalised)) {
            return false;
        }

        $now = time();
        foreach ([-30, 0, 30] as $offset) {
            if (hash_equals($this->codeForSecret($secret, $now + $offset), $normalised)) {
                return true;
            }
        }

        return false;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $hash = $this->recoveryHash($code);
        if ($hash === null) {
            return false;
        }

        return DB::table('user_mfa_recovery_codes')
            ->where('user_id', $user->id)
            ->where('code_hash', $hash)
            ->whereNull('used_at')
            ->update(['used_at' => now(), 'updated_at' => now()]) === 1;
    }

    private function newRecoveryCodes(): array
    {
        $codes = [];
        while (count($codes) < 8) {
            $plain = '';
            for ($index = 0; $index < 12; $index++) {
                $plain .= self::BASE32_ALPHABET[random_int(0, strlen(self::BASE32_ALPHABET) - 1)];
            }
            $formatted = implode('-', str_split($plain, 4));
            $codes[$formatted] = true;
        }

        return array_keys($codes);
    }

    private function insertRecoveryCodes(string $userId, array $codes): void
    {
        $now = now();
        DB::table('user_mfa_recovery_codes')->insert(array_map(fn (string $code) => [
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'code_hash' => $this->recoveryHash($code),
            'used_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $codes));
    }

    private function recoveryHash(string $code): ?string
    {
        $normalised = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $code) ?? '');
        if (strlen($normalised) !== 12) {
            return null;
        }

        return hash_hmac('sha256', $normalised, (string) config('app.key'));
    }

    private function decryptSecret(User $user): string
    {
        try {
            return Crypt::decryptString((string) $user->mfa_secret);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'mfa' => ['The stored MFA credential cannot be read. Contact an administrator.'],
            ]);
        }
    }

    private function assertPassword(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password_hash)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }
    }

    private function record(
        User $user,
        string $command,
        string $eventType,
        int $version,
        array $safeDiff,
    ): void {
        $scope = $this->scopeForUser((string) $user->id);
        if (! $scope) {
            return;
        }

        $this->audit->record($command, 'user', (string) $user->id, (string) $user->id,
            $scope['company_id'], $scope['plant_id'], 'SUCCESS', [
                'entity_version' => $version,
                'safe_diff' => $safeDiff,
            ]);
        $this->outbox->append($eventType, 'user', (string) $user->id,
            $user->id.':'.$version.':'.$command, [
                'user_id' => (string) $user->id,
                'record_version' => $version,
            ]);
    }

    private function scopeForUser(string $userId): ?array
    {
        $row = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->whereNotNull('company_id')
            ->orderByDesc('is_active')
            ->first(['company_id', 'plant_id']);

        return $row ? [
            'company_id' => (string) $row->company_id,
            'plant_id' => $row->plant_id ? (string) $row->plant_id : null,
        ] : null;
    }

    private function base32Encode(string $binary): string
    {
        $buffer = 0;
        $bits = 0;
        $encoded = '';
        foreach (unpack('C*', $binary) as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::BASE32_ALPHABET[($buffer >> $bits) & 31];
                $buffer &= (1 << $bits) - 1;
            }
        }
        if ($bits > 0) {
            $encoded .= self::BASE32_ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $encoded;
    }

    private function base32Decode(string $encoded): string
    {
        $clean = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $encoded) ?? '');
        $buffer = 0;
        $bits = 0;
        $binary = '';
        foreach (str_split($clean) as $character) {
            $value = strpos(self::BASE32_ALPHABET, $character);
            if ($value === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $binary .= chr(($buffer >> $bits) & 0xff);
                $buffer &= (1 << $bits) - 1;
            }
        }

        return $binary;
    }
}
