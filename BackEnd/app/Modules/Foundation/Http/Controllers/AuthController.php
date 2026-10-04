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
            'method' => ['nullable', 'in:PASSWORD,TOTP'],
            'code' => ['nullable', 'string', 'max:32'],
        ]);

        $user = $this->activeVerifiedUser($credentials['email']);
        if (! $user || ! Hash::check($credentials['password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'email' => ['The supplied credentials are invalid.'],
            ]);
        }

        $method = $credentials['method'] ?? 'PASSWORD';
        if ($method === 'TOTP') {
            if ($user->mfa_enabled_at === null || ! isset($credentials['code'])) {
                throw ValidationException::withMessages([
                    'code' => ['Google Authenticator is not available for this account.'],
                ]);
            }
            $verified = $this->mfa->verifyLoginCode($user, $credentials['code']);
            if ($verified !== 'AUTHENTICATOR') {
                throw ValidationException::withMessages([
                    'code' => ['Enter a valid Google Authenticator code.'],
                ]);
            }

            return $this->completeLogin($user, $request, 'AUTHENTICATOR');
        }

        if ($this->requiresSecondFactor($user)) {
            return $this->issueMfaChallenge($user, $request, 'PASSWORD');
        }

        return $this->completeLogin($user, $request, 'PASSWORD');
    }

    public function requestEmailOtp(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);
        $user = $this->activeVerifiedUser($validated['email'], false);
        $response = [
            'accepted' => true,
            'message' => 'If an eligible account matches that email, a sign-in code has been sent.',
        ];

        if (! $user || $user->email_verified_at === null) {
            return response()->json(['data' => $response], 202);
        }

        $challengeId = (string) Str::uuid();
        $expiresAt = now()->addMinutes(max(1, (int) config('qtfoods.identity.login_otp_minutes', 5)));
        $code = (string) random_int(100000, 999999);
        $request->session()->put('identity.email_login_otp', [
            'id' => $challengeId,
            'user_id' => (string) $user->id,
            'code_hash' => hash('sha256', $code),
            'expires_at' => $expiresAt->timestamp,
            'attempts' => 0,
        ]);
        $delivery = $this->notifications->loginOtp($user->email, $user->name, $code, $expiresAt->toISOString());

        $response['challenge_id'] = $challengeId;
        $response['expires_at'] = $expiresAt->toISOString();
        $response['delivery'] = $delivery;
        if (config('qtfoods.identity.preview_links', false)) {
            $response['preview_code'] = $code;
        }

        return response()->json(['data' => $response], 202);
    }

    public function verifyEmailOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
        ]);
        $challenge = $request->session()->get('identity.email_login_otp');
        if (! $this->validOtpChallenge($challenge, $validated['challenge_id'])) {
            $request->session()->forget('identity.email_login_otp');
            throw ValidationException::withMessages([
                'code' => ['The email sign-in code is invalid or expired. Request a new code.'],
            ]);
        }

        $user = $this->activeVerifiedUser($validated['email'], false);
        $validUser = $user && $user->email_verified_at !== null && $user && (string) $user->id === (string) ($challenge['user_id'] ?? '');
        $validCode = hash_equals((string) ($challenge['code_hash'] ?? ''), hash('sha256', $validated['code']));
        if (! $validUser || ! $validCode) {
            $this->incrementOtpAttempts($request, 'identity.email_login_otp', $challenge);
            throw ValidationException::withMessages([
                'code' => ['The email sign-in code is invalid or expired. Request a new code.'],
            ]);
        }

        $request->session()->forget('identity.email_login_otp');

        if ($this->requiresSecondFactor($user)) {
            if ($user->mfa_enabled_at === null) {
                return response()->json(['error' => [
                    'code' => 'MFA_ENROLLMENT_REQUIRED',
                    'message' => 'This privileged account requires a second factor. Enrol Google Authenticator before using passwordless email sign-in.',
                ]], 403);
            }

            return $this->issueMfaChallenge($user, $request, 'EMAIL_OTP', false);
        }

        return $this->completeLogin($user, $request, 'EMAIL_OTP');
    }

    public function mfaChallenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'method' => ['nullable', 'in:EMAIL_OTP,TOTP,RECOVERY_CODE'],
            'code' => ['required', 'string', 'max:32'],
        ]);
        $challenge = $request->session()->get('identity.mfa_challenge');
        if (! is_array($challenge)
            || ($challenge['id'] ?? null) !== $validated['challenge_id']
            || ! is_int($challenge['expires_at'] ?? null)
            || $challenge['expires_at'] < now()->timestamp
            || (int) ($challenge['attempts'] ?? 0) >= 5) {
            $request->session()->forget('identity.mfa_challenge');
            throw ValidationException::withMessages([
                'code' => ['The MFA challenge is invalid or expired. Sign in again.'],
            ]);
        }

        $user = User::query()
            ->where('id', $challenge['user_id'] ?? null)
            ->where('status', 'ACTIVE')
            ->whereNotNull('email_verified_at')
            ->first();
        $method = $validated['method'] ?? 'TOTP';
        $verifiedMethod = null;

        if ($user && $method === 'EMAIL_OTP' && in_array('EMAIL_OTP', $challenge['available_methods'] ?? [], true)) {
            $emailHash = (string) ($challenge['email_otp_hash'] ?? '');
            if ($emailHash !== '' && hash_equals($emailHash, hash('sha256', $validated['code']))) {
                $verifiedMethod = 'EMAIL_OTP';
            }
        } elseif ($user && in_array($method, ['TOTP', 'RECOVERY_CODE'], true) && $user->mfa_enabled_at !== null) {
            $candidate = $this->mfa->verifyLoginCode($user, $validated['code']);
            if (($method === 'TOTP' && $candidate === 'AUTHENTICATOR') || ($method === 'RECOVERY_CODE' && $candidate === 'RECOVERY_CODE')) {
                $verifiedMethod = $candidate;
            }
        }

        if (! $user || ! $verifiedMethod) {
            $attempts = (int) ($challenge['attempts'] ?? 0) + 1;
            if ($attempts >= 5) {
                $request->session()->forget('identity.mfa_challenge');
            } else {
                $challenge['attempts'] = $attempts;
                $request->session()->put('identity.mfa_challenge', $challenge);
            }
            throw ValidationException::withMessages([
                'code' => ['Enter a valid approved second-factor code.'],
            ]);
        }

        $request->session()->forget('identity.mfa_challenge');

        return $this->completeLogin($user, $request, $verifiedMethod);
    }

    private function issueMfaChallenge(User $user, Request $request, string $primaryMethod, bool $allowEmailOtp = true): JsonResponse
    {
        Auth::logout();
        $challengeId = (string) Str::uuid();
        $expiresAt = now()->addMinutes(max(1, (int) config('qtfoods.identity.mfa_challenge_minutes', 5)));
        $available = [];
        $emailOtpHash = null;
        $delivery = null;
        $previewCode = null;

        if ($allowEmailOtp && $primaryMethod !== 'EMAIL_OTP') {
            $code = (string) random_int(100000, 999999);
            $emailOtpHash = hash('sha256', $code);
            $delivery = $this->notifications->loginOtp($user->email, $user->name, $code, $expiresAt->toISOString());
            $available[] = 'EMAIL_OTP';
            if (config('qtfoods.identity.preview_links', false)) {
                $previewCode = $code;
            }
        }
        if ($user->mfa_enabled_at !== null) {
            $available[] = 'TOTP';
            $available[] = 'RECOVERY_CODE';
        }

        if ($available === []) {
            return response()->json(['error' => [
                'code' => 'MFA_ENROLLMENT_REQUIRED',
                'message' => 'This account requires multi-factor authentication. Enrol Google Authenticator before signing in.',
            ]], 403);
        }

        $request->session()->put('identity.mfa_challenge', [
            'id' => $challengeId,
            'user_id' => (string) $user->id,
            'primary_method' => $primaryMethod,
            'available_methods' => $available,
            'email_otp_hash' => $emailOtpHash,
            'expires_at' => $expiresAt->timestamp,
            'attempts' => 0,
        ]);

        $data = [
            'mfa_required' => true,
            'challenge_id' => $challengeId,
            'expires_at' => $expiresAt->toISOString(),
            'primary_method' => $primaryMethod,
            'available_methods' => $available,
        ];
        if ($delivery !== null) {
            $data['email_delivery'] = $delivery;
        }
        if ($previewCode !== null) {
            $data['preview_code'] = $previewCode;
        }

        return response()->json(['data' => $data], 202);
    }

    private function requiresSecondFactor(User $user): bool
    {
        if ($user->mfa_enabled_at !== null) {
            return true;
        }

        $requiredRoles = array_values(array_filter((array) config('qtfoods.identity.mfa_required_roles', [])));
        if ($requiredRoles === []) {
            return false;
        }

        $now = now();

        return DB::table('role_assignments as assignment')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.user_id', $user->id)
            ->where('assignment.is_active', true)
            ->where('role.status', 'ACTIVE')
            ->whereIn('role.code', $requiredRoles)
            ->where(fn ($query) => $query->whereNull('assignment.effective_from')->orWhere('assignment.effective_from', '<=', $now))
            ->where(fn ($query) => $query->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>', $now))
            ->exists();
    }

    private function activeVerifiedUser(string $email, bool $enforceVerified = true): ?User
    {
        $user = User::query()
            ->where('email', mb_strtolower(trim($email)))
            ->where('status', 'ACTIVE')
            ->first();

        if (! $user) {
            return null;
        }
        if ($enforceVerified && $user->email_verified_at === null) {
            abort(response()->json(['error' => [
                'code' => 'EMAIL_VERIFICATION_REQUIRED',
                'message' => 'Verify this email address before signing in.',
            ]], 403));
        }

        return $user;
    }

    private function validOtpChallenge(mixed $challenge, string $challengeId): bool
    {
        return is_array($challenge)
            && ($challenge['id'] ?? null) === $challengeId
            && is_int($challenge['expires_at'] ?? null)
            && $challenge['expires_at'] >= now()->timestamp
            && (int) ($challenge['attempts'] ?? 0) < 5;
    }

    private function incrementOtpAttempts(Request $request, string $key, array $challenge): void
    {
        $challenge['attempts'] = (int) ($challenge['attempts'] ?? 0) + 1;
        if ($challenge['attempts'] >= 5) {
            $request->session()->forget($key);
        } else {
            $request->session()->put($key, $challenge);
        }
    }

    private function completeLogin(User $user, Request $request, ?string $mfaMethod = null): JsonResponse
    {
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget(['erp.company_id', 'erp.plant_id', 'identity.email_login_otp', 'identity.mfa_challenge']);
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
