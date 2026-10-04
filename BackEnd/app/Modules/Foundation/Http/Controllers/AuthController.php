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

    /**
     * Password is a primary factor. A role policy or enrolled MFA can still
     * require a separate Email OTP / Authenticator factor before a session exists.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = $this->activeVerifiedUser((string) $credentials['email']);
        if (! $user || ! Hash::check($credentials['password'], $user->password_hash)) {
            $this->invalidCredentials();
        }

        if ($this->requiresSecondFactor($user)) {
            return $this->secondFactorChallenge($user, $request, 'PASSWORD');
        }

        return $this->completeLogin($user, $request, 'PASSWORD');
    }

    /**
     * Passwordless Email OTP primary authentication. The response remains
     * anti-enumerating: an unknown/ineligible email still receives a challenge id.
     */
    public function requestEmailOtp(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);
        $email = mb_strtolower(trim((string) $validated['email']));
        $user = $this->activeVerifiedUser($email);

        $result = $this->emailOtp->issuePrimary($request, $user, $email);

        return response()->json(['data' => [
            'challenge_id' => $result['challenge_id'],
            'expires_at' => $result['expires_at'],
            'delivery' => ['channel' => 'EMAIL', 'status' => 'ACCEPTED'],
            'preview_code' => $result['preview_code'] ?? null,
            'message' => 'If an eligible account exists, a six-digit sign-in code has been sent.',
        ]], 202);
    }

    public function verifyEmailOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:12'],
        ]);

        $userId = $this->emailOtp->verifyPrimary(
            $request,
            (string) $validated['challenge_id'],
            (string) $validated['code'],
        );
        $user = $userId ? User::query()->whereKey($userId)->where('status', 'ACTIVE')
            ->whereNotNull('email_verified_at')->first() : null;
        if (! $user) {
            $this->invalidCredentials('The email code is invalid or expired.');
        }

        if ($this->mfaPolicyApplies($user)) {
            if ($user->mfa_enabled_at === null) {
                return response()->json(['error' => [
                    'code' => 'MFA_ENROLMENT_REQUIRED',
                    'message' => 'This privileged account must enrol Google Authenticator before Email OTP can be used as its primary sign-in method.',
                ]], 403);
            }

            return $this->secondFactorChallenge($user, $request, 'EMAIL_OTP', ['TOTP', 'RECOVERY_CODE']);
        }

        return $this->completeLogin($user, $request, 'EMAIL_OTP');
    }

    /**
     * Passwordless Google Authenticator primary authentication. Privileged roles
     * still receive an independent Email OTP challenge as their second factor.
     */
    public function totpLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'max:12'],
        ]);
        $user = $this->activeVerifiedUser((string) $validated['email']);
        if (! $user || ! $this->mfa->verifyAuthenticatorCode($user, (string) $validated['code'])) {
            $this->invalidCredentials('The authenticator code is invalid or the account is not enrolled.');
        }

        if ($this->mfaPolicyApplies($user)) {
            $response = $this->secondFactorChallenge($user, $request, 'TOTP', ['EMAIL_OTP']);
            $challenge = $request->session()->get('identity.mfa_challenge');
            if (is_array($challenge)) {
                $delivery = $this->emailOtp->issueSecondFactor($request, (string) $challenge['id'], $user);
                $payload = $response->getData(true);
                $payload['data']['email_otp_sent'] = true;
                $payload['data']['preview_code'] = $delivery['preview_code'] ?? null;

                return response()->json($payload, 202);
            }

            return $response;
        }

        return $this->completeLogin($user, $request, 'TOTP');
    }

    public function requestMfaEmailOtp(Request $request): JsonResponse
    {
        $validated = $request->validate(['challenge_id' => ['required', 'uuid']]);
        $challenge = $this->validChallenge($request, (string) $validated['challenge_id']);
        if (! in_array('EMAIL_OTP', (array) ($challenge['methods'] ?? []), true)) {
            throw ValidationException::withMessages(['method' => ['Email OTP is not allowed for this challenge.']]);
        }

        $user = User::query()->whereKey($challenge['user_id'] ?? null)->where('status', 'ACTIVE')
            ->whereNotNull('email_verified_at')->firstOrFail();
        $result = $this->emailOtp->issueSecondFactor($request, (string) $challenge['id'], $user);

        return response()->json(['data' => [
            'challenge_id' => (string) $challenge['id'],
            'expires_at' => $result['expires_at'],
            'delivery' => ['channel' => 'EMAIL', 'status' => 'ACCEPTED'],
            'preview_code' => $result['preview_code'] ?? null,
            'message' => 'A six-digit verification code has been sent to the verified email address.',
        ]], 202);
    }

    public function mfaChallenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:32'],
            'method' => ['nullable', Rule::in(['EMAIL_OTP', 'TOTP', 'RECOVERY_CODE'])],
        ]);
        $challenge = $this->validChallenge($request, (string) $validated['challenge_id']);
        $user = User::query()
            ->whereKey($challenge['user_id'] ?? null)
            ->where('status', 'ACTIVE')
            ->whereNotNull('email_verified_at')
            ->first();
        if (! $user) {
            $request->session()->forget('identity.mfa_challenge');
            $this->invalidCredentials('The verification challenge is invalid or expired.');
        }

        $allowed = (array) ($challenge['methods'] ?? []);
        $method = (string) ($validated['method'] ?? '');
        $verifiedMethod = null;

        if ($method === '') {
            // Backward compatibility for pre-existing clients: authenticator/recovery
            // first, then Email OTP when it is the only available factor.
            if (in_array('TOTP', $allowed, true) || in_array('RECOVERY_CODE', $allowed, true)) {
                $legacy = $this->mfa->verifyLoginCode($user, (string) $validated['code']);
                if ($legacy === 'AUTHENTICATOR' && in_array('TOTP', $allowed, true)) {
                    $verifiedMethod = 'TOTP';
                } elseif ($legacy === 'RECOVERY_CODE' && in_array('RECOVERY_CODE', $allowed, true)) {
                    $verifiedMethod = 'RECOVERY_CODE';
                }
            }
            if ($verifiedMethod === null && in_array('EMAIL_OTP', $allowed, true)
                && $this->emailOtp->verifySecondFactor($request, (string) $challenge['id'], (string) $validated['code'], (string) $user->id)) {
                $verifiedMethod = 'EMAIL_OTP';
            }
        } elseif (! in_array($method, $allowed, true)) {
            throw ValidationException::withMessages(['method' => ['The selected second factor is not allowed for this sign-in.']]);
        } elseif ($method === 'EMAIL_OTP') {
            if ($this->emailOtp->verifySecondFactor($request, (string) $challenge['id'], (string) $validated['code'], (string) $user->id)) {
                $verifiedMethod = 'EMAIL_OTP';
            }
        } elseif ($method === 'TOTP' && $this->mfa->verifyAuthenticatorCode($user, (string) $validated['code'])) {
            $verifiedMethod = 'TOTP';
        } elseif ($method === 'RECOVERY_CODE' && $this->mfa->verifyLoginCode($user, (string) $validated['code']) === 'RECOVERY_CODE') {
            $verifiedMethod = 'RECOVERY_CODE';
        }

        if ($verifiedMethod === null) {
            $this->bumpChallengeAttempt($request, $challenge);
            throw ValidationException::withMessages([
                'code' => ['Enter a valid approved second-factor code.'],
            ]);
        }

        $request->session()->forget('identity.mfa_challenge');

        return $this->completeLogin(
            $user,
            $request,
            (string) ($challenge['primary_method'] ?? 'PASSWORD').'+'.$verifiedMethod,
        );
    }

    private function secondFactorChallenge(
        User $user,
        Request $request,
        string $primaryMethod,
        ?array $methods = null,
    ): JsonResponse {
        Auth::logout();
        $methods ??= array_values(array_filter([
            'EMAIL_OTP',
            $user->mfa_enabled_at !== null ? 'TOTP' : null,
            $user->mfa_enabled_at !== null ? 'RECOVERY_CODE' : null,
        ]));
        if ($methods === []) {
            return response()->json(['error' => [
                'code' => 'MFA_ENROLMENT_REQUIRED',
                'message' => 'This account requires an approved second factor before access can be granted.',
            ]], 403);
        }

        $challengeId = (string) Str::uuid();
        $expiresAt = now()->addMinutes(max(1, (int) config('qtfoods.identity.mfa_challenge_minutes', 5)));
        $request->session()->put('identity.mfa_challenge', [
            'id' => $challengeId,
            'user_id' => (string) $user->id,
            'primary_method' => $primaryMethod,
            'methods' => $methods,
            'expires_at' => $expiresAt->timestamp,
            'attempts' => 0,
        ]);

        return response()->json(['data' => [
            'mfa_required' => true,
            'challenge_id' => $challengeId,
            'primary_method' => $primaryMethod,
            'methods' => $methods,
            'expires_at' => $expiresAt->toISOString(),
        ]], 202);
    }

    private function validChallenge(Request $request, string $challengeId): array
    {
        $challenge = $request->session()->get('identity.mfa_challenge');
        if (! is_array($challenge)
            || ($challenge['id'] ?? null) !== $challengeId
            || ! is_int($challenge['expires_at'] ?? null)
            || $challenge['expires_at'] < now()->timestamp
            || (int) ($challenge['attempts'] ?? 0) >= 5) {
            $request->session()->forget('identity.mfa_challenge');
            throw ValidationException::withMessages([
                'code' => ['The verification challenge is invalid or expired. Sign in again.'],
            ]);
        }

        return $challenge;
    }

    private function bumpChallengeAttempt(Request $request, array $challenge): void
    {
        $attempts = (int) ($challenge['attempts'] ?? 0) + 1;
        if ($attempts >= 5) {
            $request->session()->forget('identity.mfa_challenge');
            return;
        }
        $challenge['attempts'] = $attempts;
        $request->session()->put('identity.mfa_challenge', $challenge);
    }

    private function activeVerifiedUser(string $email): ?User
    {
        $email = mb_strtolower(trim($email));
        if (config('qtfoods.identity.block_demo_principals', false) && str_ends_with($email, '@qtfoods.local')) {
            return null;
        }

        $user = User::query()->where('email', $email)->where('status', 'ACTIVE')->first();
        if ($user && $user->email_verified_at === null) {
            throw ValidationException::withMessages([
                'email' => ['Verify this email address before signing in.'],
            ]);
        }

        return $user;
    }

    private function requiresSecondFactor(User $user): bool
    {
        return $user->mfa_enabled_at !== null || $this->mfaPolicyApplies($user);
    }

    private function mfaPolicyApplies(User $user): bool
    {
        $required = array_values(array_filter(array_map(
            static fn (string $role): string => strtoupper(trim($role)),
            explode(',', (string) config('qtfoods.identity.mfa_required_roles', ''))
        )));
        if ($required === []) {
            return false;
        }

        $now = now();
        return DB::table('role_assignments as assignment')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.user_id', $user->id)
            ->where('assignment.is_active', true)
            ->where('role.status', 'ACTIVE')
            ->whereIn('role.code', $required)
            ->where(fn ($query) => $query->whereNull('assignment.effective_from')->orWhere('assignment.effective_from', '<=', $now))
            ->where(fn ($query) => $query->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>=', $now))
            ->exists();
    }

    private function invalidCredentials(string $message = 'The supplied credentials are invalid.'): never
    {
        throw ValidationException::withMessages(['email' => [$message]]);
    }

    private function completeLogin(User $user, Request $request, string $authenticationMethod): JsonResponse
    {
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget([
            'erp.company_id',
            'erp.plant_id',
            'identity.mfa_challenge',
            'identity.login_email_otp',
            'identity.mfa_email_otp',
        ]);
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
            'method' => $authenticationMethod,
            'mfa_method' => str_contains($authenticationMethod, '+')
                ? substr($authenticationMethod, strpos($authenticationMethod, '+') + 1)
                : null,
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
