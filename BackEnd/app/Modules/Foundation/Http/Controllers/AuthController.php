<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Modules\Foundation\Application\DeviceSessionService;
use App\Modules\Foundation\Application\LoginOtpService;
use App\Modules\Foundation\Application\MfaService;
use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Domain\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AuthController
{
    private const MFA_CHALLENGE_KEY = 'identity.mfa_challenge';

    public function __construct(
        private readonly SessionService $sessions,
        private readonly DeviceSessionService $deviceSessions,
        private readonly MfaService $mfa,
        private readonly LoginOtpService $emailOtp,
    ) {}

    public function csrf(Request $request): JsonResponse
    {
        $request->session()->regenerateToken();

        return response()->json(['data' => ['csrf_token' => csrf_token()]]);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'method' => ['sometimes', 'string', Rule::in(['PASSWORD', 'TOTP'])],
            'password' => ['nullable', 'string'],
            'code' => ['nullable', 'string', 'max:32'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $method = strtoupper((string) ($validated['method'] ?? 'PASSWORD'));
        $user = $this->eligibleUser($email);

        if ($method === 'PASSWORD') {
            if (! is_string($validated['password'] ?? null)
                || ! $user
                || ! Hash::check((string) $validated['password'], $user->password_hash)) {
                $this->invalidCredentials();
            }
        } else {
            if (! is_string($validated['code'] ?? null)
                || ! $user
                || ! $this->mfa->verifyAuthenticatorCode($user, (string) $validated['code'])) {
                $this->invalidCredentials();
            }
        }

        return $this->afterPrimaryAuthentication($user, $request, $method);
    }

    public function requestEmailOtp(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $email = mb_strtolower(trim($validated['email']));
        $user = $this->eligibleUser($email);

        $result = $this->emailOtp->issue($user, $request, 'PRIMARY');

        return response()->json(['data' => $result + [
            'message' => 'If this account is eligible, a sign-in code has been sent to its verified email address.',
        ]], 202);
    }

    public function emailOtpLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:16'],
        ]);

        $user = $this->emailOtp->verify($validated['challenge_id'], $validated['code'], 'PRIMARY');
        if (! $user) {
            $this->invalidCredentials();
        }

        return $this->afterPrimaryAuthentication($user, $request, 'EMAIL_OTP');
    }

    public function requestMfaEmailOtp(Request $request): JsonResponse
    {
        $validated = $request->validate(['challenge_id' => ['required', 'uuid']]);
        $challenge = $this->currentMfaChallenge($request, $validated['challenge_id']);

        if (! in_array('EMAIL_OTP', $challenge['methods'], true)) {
            throw ValidationException::withMessages([
                'method' => ['Email OTP is not available for this two-step challenge.'],
            ]);
        }

        $user = $this->eligibleUserById((string) $challenge['user_id']);
        if (! $user) {
            $request->session()->forget(self::MFA_CHALLENGE_KEY);
            $this->invalidCredentials();
        }

        $result = $this->emailOtp->issue($user, $request, 'SECOND_FACTOR');
        $challenge['email_otp_challenge_id'] = $result['challenge_id'];
        $request->session()->put(self::MFA_CHALLENGE_KEY, $challenge);

        return response()->json(['data' => $result + [
            'message' => 'A two-step verification code has been sent to the verified email address.',
        ]], 202);
    }

    public function mfaChallenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'method' => ['sometimes', 'string', Rule::in(['TOTP', 'EMAIL_OTP', 'RECOVERY_CODE'])],
            'code' => ['required', 'string', 'max:32'],
        ]);
        $challenge = $this->currentMfaChallenge($request, $validated['challenge_id']);
        $method = strtoupper((string) ($validated['method'] ?? 'TOTP'));

        if (! in_array($method, $challenge['methods'], true)) {
            throw ValidationException::withMessages([
                'method' => ['The selected verification method is not available for this challenge.'],
            ]);
        }

        $user = $this->eligibleUserById((string) $challenge['user_id']);
        $verifiedMethod = null;

        if ($user && $method === 'EMAIL_OTP' && is_string($challenge['email_otp_challenge_id'] ?? null)) {
            $otpUser = $this->emailOtp->verify(
                (string) $challenge['email_otp_challenge_id'],
                $validated['code'],
                'SECOND_FACTOR'
            );
            if ($otpUser && (string) $otpUser->id === (string) $user->id) {
                $verifiedMethod = 'EMAIL_OTP';
            }
        } elseif ($user && $method === 'TOTP' && $this->mfa->verifyAuthenticatorCode($user, $validated['code'])) {
            $verifiedMethod = 'TOTP';
        } elseif ($user && $method === 'RECOVERY_CODE') {
            $legacy = $this->mfa->verifyLoginCode($user, $validated['code']);
            if ($legacy === 'RECOVERY_CODE') {
                $verifiedMethod = 'RECOVERY_CODE';
            }
        }

        if (! $user || ! $verifiedMethod) {
            $attempts = (int) ($challenge['attempts'] ?? 0) + 1;
            if ($attempts >= 5) {
                $request->session()->forget(self::MFA_CHALLENGE_KEY);
            } else {
                $challenge['attempts'] = $attempts;
                $request->session()->put(self::MFA_CHALLENGE_KEY, $challenge);
            }

            throw ValidationException::withMessages([
                'code' => ['Enter a valid verification code.'],
            ]);
        }

        $request->session()->forget(self::MFA_CHALLENGE_KEY);

        return $this->completeLogin(
            $user,
            $request,
            (string) $challenge['primary_method'],
            $verifiedMethod,
        );
    }

    private function afterPrimaryAuthentication(User $user, Request $request, string $primaryMethod): JsonResponse
    {
        if (! $this->requiresSecondFactor($user)) {
            return $this->completeLogin($user, $request, $primaryMethod);
        }

        $methods = match ($primaryMethod) {
            'PASSWORD' => array_values(array_filter([
                'EMAIL_OTP',
                $user->mfa_enabled_at !== null ? 'TOTP' : null,
                $user->mfa_enabled_at !== null ? 'RECOVERY_CODE' : null,
            ])),
            'EMAIL_OTP' => $user->mfa_enabled_at !== null ? ['TOTP', 'RECOVERY_CODE'] : [],
            'TOTP' => ['EMAIL_OTP'],
            default => [],
        };

        if ($methods === []) {
            return response()->json(['error' => [
                'code' => 'MFA_ENROLLMENT_REQUIRED',
                'message' => 'This privileged account requires an independent second factor. Sign in with your password and use Email OTP, or enrol Google Authenticator.',
            ]], 403);
        }

        Auth::logout();
        $challengeId = (string) Str::uuid();
        $expiresAt = now()->addMinutes(max(1, (int) config('qtfoods.identity.mfa_challenge_minutes', 5)));
        $request->session()->put(self::MFA_CHALLENGE_KEY, [
            'id' => $challengeId,
            'user_id' => (string) $user->id,
            'expires_at' => $expiresAt->timestamp,
            'attempts' => 0,
            'primary_method' => $primaryMethod,
            'methods' => $methods,
            'email_otp_challenge_id' => null,
        ]);

        return response()->json(['data' => [
            'mfa_required' => true,
            'challenge_id' => $challengeId,
            'expires_at' => $expiresAt->toISOString(),
            'primary_method' => $primaryMethod,
            'methods' => $methods,
        ]], 202);
    }

    private function requiresSecondFactor(User $user): bool
    {
        if ($user->mfa_enabled_at !== null) {
            return true;
        }

        if (! (bool) config('qtfoods.identity.privileged_mfa_required', false)) {
            return false;
        }

        $required = array_values(array_filter(array_map(
            'trim',
            (array) config('qtfoods.identity.mfa_required_roles', ['ERP_ADMIN'])
        )));

        if ($required === []) {
            return false;
        }

        return DB::table('role_assignments as ra')
            ->join('roles as r', 'r.id', '=', 'ra.role_id')
            ->where('ra.user_id', $user->id)
            ->where('ra.is_active', true)
            ->where('r.status', 'ACTIVE')
            ->whereIn('r.code', $required)
            ->where(fn ($query) => $query->whereNull('ra.effective_from')->orWhere('ra.effective_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ra.effective_to')->orWhere('ra.effective_to', '>', now()))
            ->exists();
    }

    private function currentMfaChallenge(Request $request, string $challengeId): array
    {
        $challenge = $request->session()->get(self::MFA_CHALLENGE_KEY);
        if (! is_array($challenge)
            || ($challenge['id'] ?? null) !== $challengeId
            || ! is_int($challenge['expires_at'] ?? null)
            || $challenge['expires_at'] < now()->timestamp
            || (int) ($challenge['attempts'] ?? 0) >= 5
            || ! is_array($challenge['methods'] ?? null)) {
            $request->session()->forget(self::MFA_CHALLENGE_KEY);
            throw ValidationException::withMessages([
                'code' => ['The two-step challenge is invalid or expired. Sign in again.'],
            ]);
        }

        return $challenge;
    }

    private function eligibleUser(string $email): ?User
    {
        return User::query()
            ->where('email', $email)
            ->where('status', 'ACTIVE')
            ->whereNotNull('email_verified_at')
            ->first();
    }

    private function eligibleUserById(string $id): ?User
    {
        return User::query()
            ->where('id', $id)
            ->where('status', 'ACTIVE')
            ->whereNotNull('email_verified_at')
            ->first();
    }

    private function invalidCredentials(): never
    {
        throw ValidationException::withMessages([
            'email' => ['The supplied credentials or verification code are invalid.'],
        ]);
    }

    private function completeLogin(
        User $user,
        Request $request,
        string $primaryMethod,
        ?string $secondFactor = null,
    ): JsonResponse {
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget(['erp.company_id', 'erp.plant_id', self::MFA_CHALLENGE_KEY]);
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
            'second_factor' => $secondFactor,
            'mfa_satisfied' => $secondFactor !== null,
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
