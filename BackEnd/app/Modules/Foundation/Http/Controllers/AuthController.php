<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Modules\Foundation\Application\DeviceSessionService;
use App\Modules\Foundation\Application\IdentityNotificationService;
use App\Modules\Foundation\Application\MfaService;
use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Domain\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AuthController
{
    private const MFA_CHALLENGE_KEY = 'identity.mfa_challenge';
    private const EMAIL_LOGIN_KEY = 'identity.email_login_challenge';

    public function __construct(
        private readonly SessionService $sessions,
        private readonly DeviceSessionService $deviceSessions,
        private readonly MfaService $mfa,
        private readonly IdentityNotificationService $notifications,
    ) {}

    public function csrf(Request $request): JsonResponse
    {
        $request->session()->regenerateToken();

        return response()->json(['data' => ['csrf_token' => csrf_token()]]);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = $this->activeVerifiedUser((string) $credentials['email']);
        if (! $user || ! Hash::check($credentials['password'], $user->password_hash)) {
            $this->invalidPrimaryCredentials();
        }
        $this->assertDemoPrincipalAllowed($user);

        if ($this->secondFactorRequired($user)) {
            return $this->startSecondFactorChallenge($user, $request, 'PASSWORD');
        }

        return $this->completeLogin($user, $request, 'PASSWORD');
    }

    public function requestEmailOtp(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);
        $email = mb_strtolower(trim((string) $validated['email']));
        $user = $this->activeVerifiedUser($email);
        if ($user) {
            $this->assertDemoPrincipalAllowed($user);
        }

        $challengeId = (string) Str::uuid();
        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(max(1, (int) config('qtfoods.identity.email_otp_minutes', 10)));
        $request->session()->put(self::EMAIL_LOGIN_KEY, [
            'id' => $challengeId,
            'user_id' => $user ? (string) $user->id : null,
            'code_hash' => $this->otpHash($challengeId, $code),
            'expires_at' => $expiresAt->timestamp,
            'attempts' => 0,
        ]);

        $delivery = $user
            ? $this->notifications->loginOtp($user->email, $user->name, $code, $expiresAt->toISOString())
            : ['channel' => 'EMAIL', 'status' => 'ACCEPTED'];

        return response()->json(['data' => [
            'challenge_id' => $challengeId,
            'expires_at' => $expiresAt->toISOString(),
            'message' => 'If an eligible account exists, a one-time sign-in code has been sent.',
            'delivery' => $delivery,
        ]], 202);
    }

    public function emailOtpLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'digits:6'],
        ]);
        $challenge = $request->session()->get(self::EMAIL_LOGIN_KEY);
        $user = $this->verifyEmailOtpChallenge($request, $challenge, $validated['challenge_id'], $validated['code']);
        $request->session()->forget(self::EMAIL_LOGIN_KEY);
        $this->assertDemoPrincipalAllowed($user);

        if ($this->secondFactorRequired($user)) {
            return $this->startSecondFactorChallenge($user, $request, 'EMAIL_OTP');
        }

        return $this->completeLogin($user, $request, 'EMAIL_OTP');
    }

    public function totpLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
        ]);
        $user = $this->activeVerifiedUser((string) $validated['email']);
        if (! $user || ! $this->mfa->verifyAuthenticatorCode($user, (string) $validated['code'])) {
            $this->invalidPrimaryCredentials();
        }
        $this->assertDemoPrincipalAllowed($user);

        if ($this->privilegedMfaRequired($user)) {
            return $this->startSecondFactorChallenge($user, $request, 'AUTHENTICATOR');
        }

        return $this->completeLogin($user, $request, 'AUTHENTICATOR');
    }

    public function requestMfaEmailOtp(Request $request): JsonResponse
    {
        $validated = $request->validate(['challenge_id' => ['required', 'uuid']]);
        $challenge = $this->validSecondFactorChallenge($request, (string) $validated['challenge_id']);
        if (! in_array('EMAIL_OTP', $challenge['allowed_methods'], true)) {
            throw ValidationException::withMessages([
                'method' => ['Email OTP is not available for this challenge.'],
            ]);
        }

        $user = User::query()->where('id', $challenge['user_id'])->where('status', 'ACTIVE')->firstOrFail();
        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(max(1, (int) config('qtfoods.identity.email_otp_minutes', 10)));
        $challenge['email_otp'] = [
            'code_hash' => $this->otpHash((string) $challenge['id'], $code),
            'expires_at' => $expiresAt->timestamp,
            'attempts' => 0,
        ];
        $request->session()->put(self::MFA_CHALLENGE_KEY, $challenge);
        $delivery = $this->notifications->loginOtp($user->email, $user->name, $code, $expiresAt->toISOString());

        return response()->json(['data' => [
            'challenge_id' => $challenge['id'],
            'expires_at' => $expiresAt->toISOString(),
            'message' => 'A one-time verification code has been sent to your verified email.',
            'delivery' => $delivery,
        ]], 202);
    }

    public function mfaChallenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'method' => ['required', 'in:TOTP,EMAIL_OTP,RECOVERY_CODE'],
            'code' => ['required', 'string', 'max:32'],
        ]);
        $challenge = $this->validSecondFactorChallenge($request, (string) $validated['challenge_id']);
        $method = (string) $validated['method'];
        if (! in_array($method, $challenge['allowed_methods'], true)) {
            throw ValidationException::withMessages([
                'method' => ['The selected verification method is not allowed for this sign-in.'],
            ]);
        }

        $user = User::query()
            ->where('id', $challenge['user_id'])
            ->where('status', 'ACTIVE')
            ->whereNotNull('email_verified_at')
            ->first();

        $verified = false;
        $recordedMethod = $method;
        if ($user && $method === 'TOTP') {
            $verified = $this->mfa->verifyAuthenticatorCode($user, (string) $validated['code']);
            $recordedMethod = 'AUTHENTICATOR';
        } elseif ($user && $method === 'RECOVERY_CODE') {
            $recordedMethod = $this->mfa->verifyLoginCode($user, (string) $validated['code']) ?? 'RECOVERY_CODE';
            $verified = $recordedMethod === 'RECOVERY_CODE';
        } elseif ($user && $method === 'EMAIL_OTP') {
            $emailOtp = is_array($challenge['email_otp'] ?? null) ? $challenge['email_otp'] : null;
            $verified = $emailOtp
                && is_int($emailOtp['expires_at'] ?? null)
                && $emailOtp['expires_at'] >= now()->timestamp
                && is_string($emailOtp['code_hash'] ?? null)
                && hash_equals($emailOtp['code_hash'], $this->otpHash((string) $challenge['id'], (string) $validated['code']));
        }

        if (! $user || ! $verified) {
            $this->incrementSecondFactorAttempts($request, $challenge);
            throw ValidationException::withMessages([
                'code' => ['Enter a valid verification code for the selected method.'],
            ]);
        }

        $request->session()->forget(self::MFA_CHALLENGE_KEY);

        return $this->completeLogin($user, $request, (string) $challenge['primary_method'], $recordedMethod);
    }

    private function startSecondFactorChallenge(User $user, Request $request, string $primaryMethod): JsonResponse
    {
        $allowed = match ($primaryMethod) {
            'EMAIL_OTP' => $user->mfa_enabled_at !== null ? ['TOTP', 'RECOVERY_CODE'] : [],
            'AUTHENTICATOR' => ['EMAIL_OTP'],
            default => $user->mfa_enabled_at !== null ? ['TOTP', 'RECOVERY_CODE', 'EMAIL_OTP'] : ['EMAIL_OTP'],
        };

        if ($allowed === []) {
            return response()->json(['error' => [
                'code' => 'MFA_ENROLLMENT_REQUIRED',
                'message' => 'This privileged account must enrol Google Authenticator before email-OTP sign-in can be used.',
            ]], 403);
        }

        Auth::logout();
        $challengeId = (string) Str::uuid();
        $expiresAt = now()->addMinutes(max(1, (int) config('qtfoods.identity.mfa_challenge_minutes', 5)));
        $challenge = [
            'id' => $challengeId,
            'user_id' => (string) $user->id,
            'expires_at' => $expiresAt->timestamp,
            'attempts' => 0,
            'primary_method' => $primaryMethod,
            'allowed_methods' => $allowed,
        ];
        $request->session()->put(self::MFA_CHALLENGE_KEY, $challenge);

        return response()->json(['data' => [
            'mfa_required' => true,
            'challenge_id' => $challengeId,
            'expires_at' => $expiresAt->toISOString(),
            'primary_method' => $primaryMethod,
            'allowed_methods' => $allowed,
        ]], 202);
    }

    private function validSecondFactorChallenge(Request $request, string $challengeId): array
    {
        $challenge = $request->session()->get(self::MFA_CHALLENGE_KEY);
        if (! is_array($challenge)
            || ($challenge['id'] ?? null) !== $challengeId
            || ! is_int($challenge['expires_at'] ?? null)
            || $challenge['expires_at'] < now()->timestamp
            || (int) ($challenge['attempts'] ?? 0) >= 5
            || ! is_array($challenge['allowed_methods'] ?? null)) {
            $request->session()->forget(self::MFA_CHALLENGE_KEY);
            throw ValidationException::withMessages([
                'code' => ['The verification challenge is invalid or expired. Sign in again.'],
            ]);
        }

        return $challenge;
    }

    private function incrementSecondFactorAttempts(Request $request, array $challenge): void
    {
        $attempts = (int) ($challenge['attempts'] ?? 0) + 1;
        if ($attempts >= 5) {
            $request->session()->forget(self::MFA_CHALLENGE_KEY);
            return;
        }

        $challenge['attempts'] = $attempts;
        $request->session()->put(self::MFA_CHALLENGE_KEY, $challenge);
    }

    private function verifyEmailOtpChallenge(Request $request, mixed $challenge, string $challengeId, string $code): User
    {
        if (! is_array($challenge)
            || ($challenge['id'] ?? null) !== $challengeId
            || ! is_int($challenge['expires_at'] ?? null)
            || $challenge['expires_at'] < now()->timestamp
            || (int) ($challenge['attempts'] ?? 0) >= 5
            || ! is_string($challenge['code_hash'] ?? null)
            || ! hash_equals($challenge['code_hash'], $this->otpHash($challengeId, $code))) {
            if (is_array($challenge)) {
                $challenge['attempts'] = (int) ($challenge['attempts'] ?? 0) + 1;
                $request->session()->put(self::EMAIL_LOGIN_KEY, $challenge);
            }
            throw ValidationException::withMessages([
                'code' => ['The email code is invalid or expired. Request a new code.'],
            ]);
        }

        $user = User::query()
            ->where('id', $challenge['user_id'] ?? null)
            ->where('status', 'ACTIVE')
            ->whereNotNull('email_verified_at')
            ->first();
        if (! $user) {
            $this->invalidPrimaryCredentials();
        }

        return $user;
    }

    private function activeVerifiedUser(string $email): ?User
    {
        return User::query()
            ->where('email', mb_strtolower(trim($email)))
            ->where('status', 'ACTIVE')
            ->whereNotNull('email_verified_at')
            ->first();
    }

    private function secondFactorRequired(User $user): bool
    {
        return $user->mfa_enabled_at !== null || $this->privilegedMfaRequired($user);
    }

    private function privilegedMfaRequired(User $user): bool
    {
        if (! (bool) config('qtfoods.identity.enforce_privileged_mfa', false)) {
            return false;
        }

        $requiredRoles = (array) config('qtfoods.identity.mfa_required_roles', ['ERP_ADMIN']);
        if ($requiredRoles === []) {
            return false;
        }

        return DB::table('role_assignments as assignment')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.user_id', $user->id)
            ->where('assignment.is_active', true)
            ->where('role.status', 'ACTIVE')
            ->whereIn('role.code', $requiredRoles)
            ->where(fn ($query) => $query->whereNull('assignment.effective_from')->orWhere('assignment.effective_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>', now()))
            ->exists();
    }

    private function assertDemoPrincipalAllowed(User $user): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $demoPrincipals = array_map(
            static fn (mixed $email): string => mb_strtolower(trim((string) $email)),
            (array) config('qtfoods.identity.demo_principals', []),
        );
        if (in_array(mb_strtolower((string) $user->email), $demoPrincipals, true)) {
            throw ValidationException::withMessages([
                'email' => ['Demo identities are disabled in production.'],
            ]);
        }
    }

    private function otpHash(string $challengeId, string $code): string
    {
        return hash_hmac('sha256', $challengeId.':'.$code, (string) config('app.key'));
    }

    private function invalidPrimaryCredentials(): never
    {
        throw ValidationException::withMessages([
            'email' => ['The supplied credentials or verification code are invalid.'],
        ]);
    }

    private function completeLogin(
        User $user,
        Request $request,
        string $primaryMethod,
        ?string $mfaMethod = null,
    ): JsonResponse {
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget(['erp.company_id', 'erp.plant_id', self::MFA_CHALLENGE_KEY, self::EMAIL_LOGIN_KEY]);
        $deviceId = $this->deviceSessions->start($user, $request);
        DB::table('users')->where('id', $user->id)->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'updated_at' => now(),
        ]);
        $user->refresh();

        $payload = $this->sessions->payload($user, $request);
        $payload['authentication'] = [
            'device_session_id' => $deviceId,
            'primary_method' => $primaryMethod,
            'mfa_method' => $mfaMethod,
        ];

        return response()->json(['data' => $payload]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->sessions->payload($user, $request)]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->deviceSessions->revokeCurrent($user, $request);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['logged_out' => true]]);
    }
}
