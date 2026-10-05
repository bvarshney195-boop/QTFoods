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
use Illuminate\Validation\ValidationException;

final class AuthController
{
    private const DEMO_EMAILS = [
        'demo.user@qtfoods.local',
        'operations.user@qtfoods.local',
        'finance.user@qtfoods.local',
        'admin.user@qtfoods.local',
        'partner.user@qtfoods.local',
        'bi.user@qtfoods.local',
    ];

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
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = mb_strtolower(trim($credentials['email']));
        $this->assertDemoLoginAllowed($email);
        $user = $this->eligibleUser($email);
        if (! $user || ! Hash::check($credentials['password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'email' => ['The supplied credentials are invalid.'],
            ]);
        }

        return $this->afterPrimaryAuthentication($user, $request, 'PASSWORD');
    }

    public function requestEmailOtp(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $email = mb_strtolower(trim($validated['email']));
        $this->assertDemoLoginAllowed($email);

        return response()->json(['data' => $this->emailOtp->requestPrimary($email, $request->ip())], 202);
    }

    public function verifyEmailOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'code' => ['required', 'regex:/^\d{6}$/'],
        ]);
        $email = mb_strtolower(trim($validated['email']));
        $this->assertDemoLoginAllowed($email);
        $user = $this->emailOtp->verifyPrimary($email, $validated['code']);

        return $this->afterPrimaryAuthentication($user, $request, 'EMAIL_OTP');
    }

    public function totpLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'code' => ['required', 'regex:/^\d{6}$/'],
        ]);
        $email = mb_strtolower(trim($validated['email']));
        $this->assertDemoLoginAllowed($email);
        $user = $this->eligibleUser($email);
        if (! $user || ! $this->mfa->verifyAuthenticatorCode($user, $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => ['The authenticator code is invalid or the account has not enrolled an authenticator.'],
            ]);
        }

        return $this->afterPrimaryAuthentication($user, $request, 'TOTP');
    }

    public function requestMfaEmailOtp(Request $request): JsonResponse
    {
        $validated = $request->validate(['challenge_id' => ['required', 'uuid']]);
        $challenge = $this->validChallenge($request, $validated['challenge_id']);
        if (! in_array('EMAIL_OTP', (array) ($challenge['allowed_methods'] ?? []), true)) {
            throw ValidationException::withMessages([
                'method' => ['Email OTP is not available for this challenge.'],
            ]);
        }

        $user = $this->eligibleUserById((string) ($challenge['user_id'] ?? ''));
        if (! $user) {
            throw ValidationException::withMessages([
                'code' => ['The MFA challenge is invalid or expired. Sign in again.'],
            ]);
        }

        return response()->json(['data' => $this->emailOtp->requestSecondFactor(
            $user,
            $validated['challenge_id'],
            $request->ip(),
        )], 202);
    }

    public function mfaChallenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'method' => ['nullable', 'in:TOTP,EMAIL_OTP'],
            'code' => ['required', 'string', 'max:32'],
        ]);
        $challenge = $this->validChallenge($request, $validated['challenge_id']);
        $method = $validated['method'] ?? 'TOTP';
        if (! in_array($method, (array) ($challenge['allowed_methods'] ?? []), true)) {
            throw ValidationException::withMessages([
                'method' => ['The selected second factor is not available for this challenge.'],
            ]);
        }

        $user = $this->eligibleUserById((string) ($challenge['user_id'] ?? ''));
        $verifiedMethod = null;
        if ($user) {
            if ($method === 'EMAIL_OTP') {
                $verifiedMethod = $this->emailOtp->verifySecondFactor(
                    $user,
                    $validated['challenge_id'],
                    $validated['code'],
                ) ? 'EMAIL_OTP' : null;
            } else {
                $verifiedMethod = $this->mfa->verifyLoginCode($user, $validated['code']);
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
                'code' => ['Enter a valid code for the selected second factor.'],
            ]);
        }

        $request->session()->forget('identity.mfa_challenge');

        return $this->completeLogin(
            $user,
            $request,
            (string) ($challenge['primary_method'] ?? 'PASSWORD'),
            $verifiedMethod,
        );
    }

    private function afterPrimaryAuthentication(User $user, Request $request, string $primaryMethod): JsonResponse
    {
        if ($user->email_verified_at === null) {
            return response()->json(['error' => [
                'code' => 'EMAIL_VERIFICATION_REQUIRED',
                'message' => 'Verify this email address before signing in.',
            ]], 403);
        }

        $requiresSecondFactor = $user->mfa_enabled_at !== null || $this->requiresPrivilegedMfa($user);
        if (! $requiresSecondFactor) {
            return $this->completeLogin($user, $request, $primaryMethod, null);
        }

        $allowedMethods = [];
        if ($primaryMethod !== 'EMAIL_OTP') {
            $allowedMethods[] = 'EMAIL_OTP';
        }
        if ($user->mfa_enabled_at !== null && $primaryMethod !== 'TOTP') {
            $allowedMethods[] = 'TOTP';
        }

        if ($allowedMethods === []) {
            return response()->json(['error' => [
                'code' => 'MFA_ENROLLMENT_REQUIRED',
                'message' => 'This privileged account requires a second authentication factor. Sign in with your password and verify the email OTP, or enrol Google Authenticator before using OTP-only sign-in.',
            ]], 403);
        }

        Auth::logout();
        $challengeId = (string) Str::uuid();
        $expiresAt = now()->addMinutes(max(1, (int) config('qtfoods.identity.mfa_challenge_minutes', 5)));
        $request->session()->put('identity.mfa_challenge', [
            'id' => $challengeId,
            'user_id' => (string) $user->id,
            'expires_at' => $expiresAt->timestamp,
            'attempts' => 0,
            'primary_method' => $primaryMethod,
            'allowed_methods' => array_values(array_unique($allowedMethods)),
        ]);

        return response()->json(['data' => [
            'mfa_required' => true,
            'challenge_id' => $challengeId,
            'expires_at' => $expiresAt->toISOString(),
            'primary_method' => $primaryMethod,
            'allowed_methods' => array_values(array_unique($allowedMethods)),
        ]], 202);
    }

    private function completeLogin(
        User $user,
        Request $request,
        string $primaryMethod = 'PASSWORD',
        ?string $mfaMethod = null,
    ): JsonResponse {
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget(['erp.company_id', 'erp.plant_id']);
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
                'code' => ['The MFA challenge is invalid or expired. Sign in again.'],
            ]);
        }

        return $challenge;
    }

    private function eligibleUser(string $email): ?User
    {
        return User::query()->where('email', $email)->where('status', 'ACTIVE')->first();
    }

    private function eligibleUserById(string $id): ?User
    {
        return User::query()
            ->where('id', $id)
            ->where('status', 'ACTIVE')
            ->whereNotNull('email_verified_at')
            ->first();
    }

    private function requiresPrivilegedMfa(User $user): bool
    {
        $required = array_values(array_filter((array) config('qtfoods.identity.mfa_required_roles', [])));
        if ($required === []) {
            return false;
        }

        return DB::table('role_assignments as assignment')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.user_id', $user->id)
            ->where('assignment.is_active', true)
            ->where('role.status', 'ACTIVE')
            ->whereIn('role.code', $required)
            ->where(fn ($query) => $query->whereNull('assignment.effective_from')->orWhere('assignment.effective_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>', now()))
            ->exists();
    }

    private function assertDemoLoginAllowed(string $email): void
    {
        if (! config('deployment.allow_demo_login', false) && in_array($email, self::DEMO_EMAILS, true)) {
            throw ValidationException::withMessages([
                'email' => ['Demo identities are disabled in this environment.'],
            ]);
        }
    }
}
