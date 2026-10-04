<?php

namespace App\Modules\Foundation\Application;

use App\Modules\Foundation\Domain\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LoginOtpService
{
    public const PRIMARY = 'LOGIN_EMAIL_OTP';
    public const MFA = 'MFA_EMAIL_OTP';

    public function __construct(private readonly IdentityNotificationService $notifications) {}

    public function issue(string $email, string $type, ?string $ip): array
    {
        $challengeId = (string) Str::uuid();
        $expiresAt = now()->addMinutes(max(1, (int) config('qtfoods.identity.login_otp_minutes', 5)));
        $result = [
            'challenge_id' => $challengeId,
            'expires_at' => $expiresAt->toISOString(),
            'message' => 'If the account is eligible, a one-time code has been sent.',
        ];

        $user = User::query()
            ->where('email', mb_strtolower(trim($email)))
            ->where('status', 'ACTIVE')
            ->whereNotNull('email_verified_at')
            ->first();

        if (! $user) {
            return $result;
        }

        $code = (string) random_int(100000, 999999);
        $scope = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->first(['company_id', 'plant_id']);

        DB::transaction(function () use ($user, $type, $challengeId, $code, $expiresAt, $ip, $scope): void {
            DB::table('identity_tokens')
                ->where('user_id', $user->id)
                ->where('type', $type)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            DB::table('identity_tokens')->insert([
                'id' => $challengeId,
                'user_id' => (string) $user->id,
                'company_id' => $scope?->company_id,
                'plant_id' => $scope?->plant_id,
                'type' => $type,
                'token_hash' => $this->hash($challengeId, $code),
                'requested_ip' => $ip,
                'expires_at' => $expiresAt,
                'used_at' => null,
                'revoked_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $delivery = $this->notifications->loginOtp($user->email, $user->name, $code, $expiresAt->toISOString());
        if (config('qtfoods.identity.preview_links', false)) {
            $result['preview_code'] = $code;
        }
        $result['delivery'] = ['channel' => $delivery['channel'], 'status' => $delivery['status']];

        return $result;
    }

    public function verify(string $email, string $challengeId, string $code, string $type): User
    {
        $user = User::query()
            ->where('email', mb_strtolower(trim($email)))
            ->where('status', 'ACTIVE')
            ->whereNotNull('email_verified_at')
            ->first();

        $token = $user ? DB::table('identity_tokens')
            ->where('id', $challengeId)
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->first() : null;

        $valid = $token
            && strtotime((string) $token->expires_at) >= now()->timestamp
            && hash_equals((string) $token->token_hash, $this->hash($challengeId, preg_replace('/\D+/', '', $code) ?? ''));

        if (! $user || ! $valid) {
            throw ValidationException::withMessages([
                'code' => ['The email one-time code is invalid or expired. Request a new code.'],
            ]);
        }

        DB::table('identity_tokens')->where('id', $challengeId)->update([
            'used_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function hash(string $challengeId, string $code): string
    {
        return hash_hmac('sha256', $challengeId.'|'.$code, (string) config('app.key'));
    }
}
