<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Modules\Foundation\Application\DeviceSessionService;
use App\Modules\Foundation\Application\LoginOtpService;
use App\Modules\Foundation\Application\MfaService;
use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Domain\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AuthController
{
    private const MFA_KEY = 'identity.mfa_challenge';

    public function __construct(
        private readonly SessionService $sessions,
        private readonly DeviceSessionService $deviceSessions,
        private readonly MfaService $mfa,
        private readonly LoginOtpService $loginOtp,
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

        $user = $this->passwordUser($credentials['email'], $credentials['password']);
        if ($this->requiresSecondFactor($user)) {
            return $this->issueMfaChallenge($user, $request, 'PASSWORD');
        }

        return $this->completeLogin($user, $request, 'PASSWORD');
    }

    public function totpLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
        ]);

        $user = $this->passwordUser($validated['email'], $validated['password']);
        if (! $this->mfa->verifyAuthenticatorCode($user, $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => ['Enter a valid six-digit Google Authenticator code.'],
            ]);
        }

        return $this->completeLogin($user, $request, 'AUTHENTICATOR');
    }

    public function requestEmailOtp(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);
        $result = $this->loginOtp->issue(
            mb_strtolower(trim($validated['email'])),
            LoginOtpService::PRIMARY,
            $request->ip(),
        );

        return response()->json(['data' => $result], 202);
    }

    public function verifyEmailOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'digits:6'],
        ]);

        $user = $this->loginOtp->verify(
            $validated['email'],
            $validated['challenge_id'],
            $validated['code'],
            LoginOtpService::PRIMARY,
        );

        if ($this->requiresPrivilegedMfa($user)) {
            if ($user->mfa_enabled_at === null) {
                return response()->json(['error' => [
                    'code' => 'MFA_ENROLLMENT_REQUIRED',
                    'message' => 'This privileged account requires two-factor authentication. Use password sign-in with email OTP, then enrol Google Authenticator from Account security.',
                ]], 403);
            }

            return $this->issueMfaChallenge($user, $request, 'EMAIL_OTP', ['AUTHENTICATOR']);
        }

        return $this->completeLogin($user, $request, 'EMAIL_OTP');
    }

    public function requestMfaEmailOtp(Request $request): JsonResponse
    {
        $validated = $request->validate(['challenge_id' => ['required', 'uuid']]);
        $challenge = $this->validMfaChallenge($request, $validated['challenge_id']);
        if (! in_array('EMAIL_OTP', $challenge['available_methods'] ?? [], true)) {
            throw ValidationException::withMessages([
                'method' => ['Email OTP is not available for this sign-in challenge.'],
            ]);
        }

        $user = User::query()->where('id', $challenge['user_id'])->where('status', 'ACTIVE')->firstOrFail();
        $issued = $this->loginOtp->issue($user->email, LoginOtpService::MFA, $request->ip());
        $challenge['email_otp_challenge_id'] = $issued['challenge_id'];
        $request->session()->put(self::MFA_KEY, $challenge);

        return response()->json(['data' => [
            'challenge_id' => $validated['challenge_id'],
            'expires_at' => $issued['expires_at'],
            'message' => $issued['message'],
            'delivery' => $issued['delivery'] ?? ['channel' => 'EMAIL', 'status' => 'UNKNOWN'],
            ...(config('qtfoods.identity.preview_links', false) && isset($issued['preview_code'])
                ? ['preview_code' => $issued['preview_code']]
                : []),
        ]], 202);
    }

    public function mfaChallenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'method' => ['required', 'in:AUTHENTICATOR,EMAIL_OTP,RECOVERY_CODE'],
            'code' => ['required', 'string', 'max:32'],
        ]);
        $challenge = $this->validMfaChallenge($request, $validated['challenge_id']);
        $method = $validated['method'];

        if (! in_array($method === 'RECOVERY_CODE' ? 'AUTHENTICATOR' : $method, $challenge['available_methods'] ?? [], true)) {
            throw ValidationException::withMessages(['method' => ['That second-factor method is not available.']]);
        }

        $user = User::query()
            ->where('id', $challenge['user_id'])
            ->where('status', 'ACTIVE')
            ->whereNotNull('email_verified_at')
            ->first();

        $verifiedMethod = null;
        if ($user && $method === 'EMAIL_OTP' && isset($challenge['email_otp_challenge_id'])) {
            $this->loginOtp->verify(
                $user->email,
                (string) $challenge['email_otp_challenge_id'],
                $validated['code'],
                LoginOtpService::MFA,
            );
            $verifiedMethod = 'EMAIL_OTP';
        } elseif ($user && $method === 'AUTHENTICATOR' && $this->mfa->verifyAuthenticatorCode($user, $validated['code'])) {
            $verifiedMethod = 'AUTHENTICATOR';
        } elseif ($user && $method === 'RECOVERY_CODE' && $this->mfa->verifyLoginCode($user, $validated['code']) === 'RECOVERY_CODE') {
            $verifiedMethod = 'RECOVERY_CODE';
        }

        if (! $user || ! $verifiedMethod) {
            $this->registerMfaFailure($request, $challenge);
            throw ValidationException::withMessages([
                'code' => ['Enter a valid code for the selected second factor.'],
            ]);
        }

        $request->session()->forget(self::MFA_KEY);

        return $this->completeLogin($user, $request, $verifiedMethod);
    }

    private function passwordUser(string $email, string $password): User
    {
        $email = mb_strtolower(trim($email));
        if (! (bool) config('qtfoods.identity.allow_demo_login', false)
            && in_array($email, (array) config('qtfoods.identity.demo_emails', []), true)) {
            throw ValidationException::withMessages(['email' => ['The supplied credentials are invalid.']]);
        }

        $user = User::query()->where('email', $email)->where('status', 'ACTIVE')->first();
        if (! $user || ! Hash::check($password, $user->password_hash)) {
            throw ValidationException::withMessages(['email' => ['The supplied credentials are invalid.']]);
        }
        if ($user->email_verified_at === null) {
            throw new HttpResponseException(response()->json(['error' => [
                'code' => 'EMAIL_VERIFICATION_REQUIRED',
                'message' => 'Verify this email address before signing in.',
            ]], 403));
        }

        return $user;
    }

    private function requiresSecondFactor(User $user): bool
    {
        return $user->mfa_enabled_at !== null || $this->requiresPrivilegedMfa($user);
    }

    private function requiresPrivilegedMfa(User $user): bool
    {
        $required = (array) config('qtfoods.identity.mfa_required_roles', []);
        if ($required === []) return false;

        $now = now();
        return DB::table('role_assignments as assignment')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.user_id', $user->id)
            ->where('assignment.is_active', true)
            ->where('role.status', 'ACTIVE')
            ->whereIn('role.code', $required)
            ->where(fn ($q) => $q->whereNull('assignment.effective_from')->orWhere('assignment.effective_from', '<=', $now))
            ->where(fn ($q) => $q->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>', $now))
            ->exists();
    }

    private function issueMfaChallenge(User $user, Request $request, string $primaryMethod, ?array $methods = null): JsonResponse
    {
        Auth::logout();
        $challengeId = (string) Str::uuid();
        $expiresAt = now()->addMinutes(max(1, (int) config('qtfoods.identity.mfa_challenge_minutes', 5)));
        $methods ??= array_values(array_filter([
            $user->mfa_enabled_at !== null ? 'AUTHENTICATOR' : null,
            'EMAIL_OTP',
        ]));
        $request->session()->put(self::MFA_KEY, [
            'id' => $challengeId,
            'user_id' => (string) $user->id,
            'expires_at' => $expiresAt->timestamp,
            'attempts' => 0,
            'primary_method' => $primaryMethod,
            'available_methods' => $methods,
        ]);

        return response()->json(['data' => [
            'mfa_required' => true,
            'challenge_id' => $challengeId,
            'expires_at' => $expiresAt->toISOString(),
            'primary_method' => $primaryMethod,
            'available_methods' => $methods,
        ]], 202);
    }

    private function validMfaChallenge(Request $request, string $challengeId): array
    {
        $challenge = $request->session()->get(self::MFA_KEY);
        if (! is_array($challenge)
            || ($challenge['id'] ?? null) !== $challengeId
            || ! is_int($challenge['expires_at'] ?? null)
            || $challenge['expires_at'] < now()->timestamp
            || (int) ($challenge['attempts'] ?? 0) >= 5) {
            $request->session()->forget(self::MFA_KEY);
            throw ValidationException::withMessages([
                'code' => ['The sign-in challenge is invalid or expired. Start again.'],
            ]);
        }

        return $challenge;
    }

    private function registerMfaFailure(Request $request, array $challenge): void
    {
        $attempts = (int) ($challenge['attempts'] ?? 0) + 1;
        if ($attempts >= 5) {
            $request->session()->forget(self::MFA_KEY);
            return;
        }
        $challenge['attempts'] = $attempts;
        $request->session()->put(self::MFA_KEY, $challenge);
    }

    private function completeLogin(User $user, Request $request, ?string $mfaMethod = null): JsonResponse
    {
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget(['erp.company_id', 'erp.plant_id', self::MFA_KEY]);
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
