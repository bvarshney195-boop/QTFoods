<?php

namespace App\Modules\Foundation\Application;

use App\Modules\Foundation\Domain\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LoginOtpService
{
    private const PRIMARY_KEY = 'identity.login_email_otp';
    private const SECONDARY_KEY = 'identity.mfa_email_otp';

    public function __construct(private readonly IdentityNotificationService $notifications) {}

    public function issuePrimary(Request $request, ?User $user, string $requestedEmail): array
    {
        $challengeId = (string) Str::uuid();

        return $this->issue($request, self::PRIMARY_KEY, $challengeId, $user, $requestedEmail, 'PRIMARY');
    }

    public function issueSecondFactor(Request $request, string $challengeId, User $user): array
    {
        return $this->issue($request, self::SECONDARY_KEY, $challengeId, $user, $user->email, 'SECONDARY');
    }

    public function verifyPrimary(Request $request, string $challengeId, string $code): ?string
    {
        return $this->verify($request, self::PRIMARY_KEY, $challengeId, $code, 'PRIMARY');
    }

    public function verifySecondFactor(Request $request, string $challengeId, string $code, string $userId): bool
    {
        return $this->verify($request, self::SECONDARY_KEY, $challengeId, $code, 'SECONDARY', $userId) === $userId;
    }

    private function issue(
        Request $request,
        string $key,
        string $challengeId,
        ?User $user,
        string $requestedEmail,
        string $purpose,
    ): array {
        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(max(1, (int) config('qtfoods.identity.email_otp_minutes', 10)));
        $request->session()->put($key, [
            'id' => $challengeId,
            'purpose' => $purpose,
            'user_id' => $user ? (string) $user->id : null,
            'email_fingerprint' => hash_hmac('sha256', mb_strtolower(trim($requestedEmail)), (string) config('app.key')),
            'code_hash' => hash_hmac('sha256', $challengeId.':'.$code, (string) config('app.key')),
            'expires_at' => $expiresAt->timestamp,
            'attempts' => 0,
        ]);

        $delivery = ['channel' => 'EMAIL', 'status' => 'ACCEPTED'];
        if ($user !== null) {
            $delivery = $this->notifications->loginOtp($user->email, $user->name, $code, $expiresAt->toISOString());
        }

        $result = [
            'challenge_id' => $challengeId,
            'expires_at' => $expiresAt->toISOString(),
            'delivery' => $delivery,
        ];
        if (config('qtfoods.identity.preview_links', false) && $user !== null) {
            $result['preview_code'] = $code;
        }

        return $result;
    }

    private function verify(
        Request $request,
        string $key,
        string $challengeId,
        string $code,
        string $purpose,
        ?string $expectedUserId = null,
    ): ?string {
        $pending = $request->session()->get($key);
        $maxAttempts = max(1, (int) config('qtfoods.identity.email_otp_attempts', 5));
        $normalised = preg_replace('/\D+/', '', $code) ?? '';

        if (! is_array($pending)
            || ($pending['id'] ?? null) !== $challengeId
            || ($pending['purpose'] ?? null) !== $purpose
            || ! is_int($pending['expires_at'] ?? null)
            || $pending['expires_at'] < now()->timestamp
            || (int) ($pending['attempts'] ?? 0) >= $maxAttempts
            || ($expectedUserId !== null && ($pending['user_id'] ?? null) !== $expectedUserId)
            || ! preg_match('/^\d{6}$/', $normalised)
            || ! hash_equals((string) ($pending['code_hash'] ?? ''), hash_hmac('sha256', $challengeId.':'.$normalised, (string) config('app.key')))) {
            if (is_array($pending)) {
                $attempts = (int) ($pending['attempts'] ?? 0) + 1;
                if ($attempts >= $maxAttempts || (($pending['expires_at'] ?? 0) < now()->timestamp)) {
                    $request->session()->forget($key);
                } else {
                    $pending['attempts'] = $attempts;
                    $request->session()->put($key, $pending);
                }
            }
            throw ValidationException::withMessages([
                'code' => ['Enter a valid, unexpired six-digit email code.'],
            ]);
        }

        $request->session()->forget($key);

        return is_string($pending['user_id'] ?? null) ? $pending['user_id'] : null;
    }
}
