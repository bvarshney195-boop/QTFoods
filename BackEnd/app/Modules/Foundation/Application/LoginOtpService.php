<?php

namespace App\Modules\Foundation\Application;

use App\Modules\Foundation\Domain\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LoginOtpService
{
    public function __construct(private readonly IdentityNotificationService $notifications) {}

    public function issue(?User $user, Request $request, string $purpose): array
    {
        $challengeId = (string) Str::uuid();
        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(max(1, (int) config('qtfoods.identity.email_otp_minutes', 5)));

        DB::table('auth_email_otp_challenges')->insert([
            'id' => $challengeId,
            'user_id' => $user?->id,
            'code_hash' => $this->hash($challengeId, $code),
            'purpose' => $purpose,
            'attempts' => 0,
            'expires_at' => $expiresAt,
            'consumed_at' => null,
            'requested_ip' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $delivery = null;
        if ($user && $user->status === 'ACTIVE' && $user->email_verified_at !== null) {
            $delivery = $this->notifications->loginOtp(
                (string) $user->email,
                (string) $user->name,
                $code,
                $expiresAt->toISOString(),
                $purpose,
            );
        }

        $result = [
            'challenge_id' => $challengeId,
            'expires_at' => $expiresAt->toISOString(),
            'delivery' => [
                'channel' => 'EMAIL',
                'status' => $delivery['status'] ?? 'ACCEPTED',
            ],
        ];

        if (config('qtfoods.identity.preview_links', false) && $user) {
            $result['preview_code'] = $code;
        }

        return $result;
    }

    public function verify(string $challengeId, string $code, string $purpose): ?User
    {
        return DB::transaction(function () use ($challengeId, $code, $purpose): ?User {
            $challenge = DB::table('auth_email_otp_challenges')
                ->where('id', $challengeId)
                ->where('purpose', $purpose)
                ->lockForUpdate()
                ->first();

            if (! $challenge
                || $challenge->consumed_at !== null
                || now()->greaterThanOrEqualTo($challenge->expires_at)
                || (int) $challenge->attempts >= 5) {
                throw ValidationException::withMessages([
                    'code' => ['The email verification code is invalid or expired. Request a new code.'],
                ]);
            }

            if (! hash_equals((string) $challenge->code_hash, $this->hash($challengeId, $code))) {
                DB::table('auth_email_otp_challenges')->where('id', $challengeId)->update([
                    'attempts' => (int) $challenge->attempts + 1,
                    'updated_at' => now(),
                ]);
                throw ValidationException::withMessages([
                    'code' => ['The email verification code is invalid or expired. Request a new code.'],
                ]);
            }

            DB::table('auth_email_otp_challenges')->where('id', $challengeId)->update([
                'consumed_at' => now(),
                'updated_at' => now(),
            ]);

            if (! $challenge->user_id) {
                return null;
            }

            return User::query()
                ->where('id', $challenge->user_id)
                ->where('status', 'ACTIVE')
                ->whereNotNull('email_verified_at')
                ->first();
        });
    }

    private function hash(string $challengeId, string $code): string
    {
        $normalised = preg_replace('/\D+/', '', $code) ?? '';

        return hash_hmac('sha256', $challengeId.'|'.$normalised, (string) config('app.key'));
    }
}
