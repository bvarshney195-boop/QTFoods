<?php

namespace App\Modules\Foundation\Application;

use App\Modules\Foundation\Domain\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LoginOtpService
{
    public const PRIMARY = 'PRIMARY_LOGIN';
    public const SECOND_FACTOR = 'SECOND_FACTOR';

    public function __construct(private readonly IdentityNotificationService $notifications) {}

    public function requestPrimary(string $email, ?string $ip): array
    {
        $email = mb_strtolower(trim($email));
        $user = User::query()
            ->where('email', $email)
            ->where('status', 'ACTIVE')
            ->whereNotNull('email_verified_at')
            ->first();

        $delivery = null;
        if ($user) {
            [$code, $expiresAt] = $this->issue($user, self::PRIMARY, null, $ip);
            $delivery = $this->notifications->loginOtp(
                $user->email,
                $user->name,
                $code,
                $expiresAt->toISOString(),
            );
        }

        $response = [
            'accepted' => true,
            'message' => 'If an eligible account matches that email, a one-time sign-in code has been sent.',
        ];
        if ($delivery && config('qtfoods.identity.preview_links', false)) {
            $response['delivery'] = $delivery;
        }

        return $response;
    }

    public function requestSecondFactor(User $user, string $challengeId, ?string $ip): array
    {
        [$code, $expiresAt] = $this->issue($user, self::SECOND_FACTOR, $challengeId, $ip);
        $delivery = $this->notifications->loginOtp(
            $user->email,
            $user->name,
            $code,
            $expiresAt->toISOString(),
        );

        $response = [
            'accepted' => true,
            'message' => 'A one-time verification code has been sent to your verified email address.',
            'expires_at' => $expiresAt->toISOString(),
        ];
        if (config('qtfoods.identity.preview_links', false)) {
            $response['delivery'] = $delivery;
        }

        return $response;
    }

    public function verifyPrimary(string $email, string $code): User
    {
        $user = User::query()
            ->where('email', mb_strtolower(trim($email)))
            ->where('status', 'ACTIVE')
            ->whereNotNull('email_verified_at')
            ->first();

        if (! $user || ! $this->consume($user, self::PRIMARY, null, $code)) {
            throw ValidationException::withMessages([
                'code' => ['The one-time code is invalid or expired. Request a new code.'],
            ]);
        }

        return $user;
    }

    public function verifySecondFactor(User $user, string $challengeId, string $code): bool
    {
        return $this->consume($user, self::SECOND_FACTOR, $challengeId, $code);
    }

    private function issue(User $user, string $purpose, ?string $challengeId, ?string $ip): array
    {
        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(max(1, (int) config('qtfoods.identity.email_otp_minutes', 10)));
        $now = now();

        DB::transaction(function () use ($user, $purpose, $challengeId, $ip, $code, $expiresAt, $now): void {
            DB::table('identity_login_otps')
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->when($challengeId === null,
                    fn ($q) => $q->whereNull('challenge_id'),
                    fn ($q) => $q->where('challenge_id', $challengeId))
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'updated_at' => $now]);

            DB::table('identity_login_otps')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => (string) $user->id,
                'challenge_id' => $challengeId,
                'purpose' => $purpose,
                'code_hash' => $this->hash($code),
                'requested_ip' => $ip,
                'attempts' => 0,
                'expires_at' => $expiresAt,
                'used_at' => null,
                'revoked_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return [$code, $expiresAt];
    }

    private function consume(User $user, string $purpose, ?string $challengeId, string $code): bool
    {
        $normalised = preg_replace('/\D+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $normalised)) {
            return false;
        }

        return DB::transaction(function () use ($user, $purpose, $challengeId, $normalised): bool {
            $query = DB::table('identity_login_otps')
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->when($challengeId === null,
                    fn ($q) => $q->whereNull('challenge_id'),
                    fn ($q) => $q->where('challenge_id', $challengeId))
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at');

            $record = $query->lockForUpdate()->first();
            if (! $record || (int) $record->attempts >= 5) {
                return false;
            }

            if (! hash_equals((string) $record->code_hash, $this->hash($normalised))) {
                $attempts = (int) $record->attempts + 1;
                DB::table('identity_login_otps')->where('id', $record->id)->update([
                    'attempts' => $attempts,
                    'revoked_at' => $attempts >= 5 ? now() : null,
                    'updated_at' => now(),
                ]);
                return false;
            }

            DB::table('identity_login_otps')->where('id', $record->id)->update([
                'used_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        });
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
