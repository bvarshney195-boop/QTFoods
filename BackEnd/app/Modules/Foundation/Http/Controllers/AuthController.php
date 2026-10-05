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
    private const CHALLENGE_KEY = 'identity.login_challenge';
    private const MAX_ATTEMPTS = 5;

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

    /**
     * Begin one of the three explicit sign-in methods. No authenticated Laravel
     * session is created here unless the selected method satisfies the user's
     * complete authentication policy.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'method' => ['nullable', 'in:password,email_otp,totp'],
            'password' => ['nullable', 'string'],
        ]);
        $method = (string) ($validated['method'] ?? 'password');
        $email = mb_strtolower(trim((string) $validated['email']));
        $user = $this->eligibleUser($email);

        if (! $user) {
            if ($method === 'password') {
                $this->invalidCredentials();
            }

            throw ValidationException::withMessages([
                'email' => ['This email is not registered. Contact your organisation administrator to register it.'],
            ]);
        }

        if ($user->email_verified_at === null) {
            return response()->json(['error' => [
                'code' => 'EMAIL_VERIFICATION_REQUIRED',
                'message' => 'Verify this email address before signing in.',
            ]], 403);
        }

        Auth::logout();
        $request->session()->forget([self::CHALLENGE_KEY, 'identity.mfa_setup']);

        if ($method === 'password') {
            if (! isset($validated['password']) || ! Hash::check($validated['password'], $user->password_hash)) {
                $this->invalidCredentials();
            }

            if (! $this->requiresSecondFactor($user)) {
                return $this->completeLogin($user, $request, 'PASSWORD');
            }

            return $this->storeChallenge($request, $user, [
                'primary_method' => 'PASSWORD',
                'phase' => 'SELECT_SECOND_FACTOR',
            ]);
        }

        if ($method === 'email_otp') {
            return $this->storeChallenge($request, $user, [
                'primary_method' => 'EMAIL_OTP',
                'phase' => 'EMAIL_OTP_PRIMARY',
            ], true);
        }

        if ($user->mfa_enabled_at !== null) {
            return $this->storeChallenge($request, $user, [
                'primary_method' => 'TOTP',
                'phase' => 'TOTP_PRIMARY',
            ]);
        }

        // A QR secret is never disclosed merely because someone knows an email.
        // Registered-email ownership must be proved before enrolment can begin.
        return $this->storeChallenge($request, $user, [
            'primary_method' => 'TOTP',
            'phase' => 'EMAIL_PROOF_FOR_TOTP_SETUP',
        ], true);
    }

    public function authenticationChallenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'action' => ['required', 'in:select_email_otp,select_totp,verify_email_otp,verify_totp,resend_email_otp,confirm_totp_setup'],
            'code' => ['nullable', 'string', 'max:32'],
        ]);
        $challenge = $this->loadChallenge($request, (string) $validated['challenge_id']);
        $user = $this->eligibleUserById((string) $challenge['user_id']);
        if (! $user || $user->email_verified_at === null) {
            $request->session()->forget(self::CHALLENGE_KEY);
            throw ValidationException::withMessages([
                'challenge_id' => ['The sign-in challenge is no longer valid. Start again.'],
            ]);
        }

        $action = (string) $validated['action'];
        $phase = (string) $challenge['phase'];

        if ($phase === 'SELECT_SECOND_FACTOR') {
            if ($action === 'select_email_otp') {
                $challenge['phase'] = 'EMAIL_OTP_SECOND';

                return $this->saveChallengeWithEmailCode($request, $user, $challenge);
            }
            if ($action === 'select_totp' && $user->mfa_enabled_at !== null) {
                $challenge['phase'] = 'TOTP_SECOND';
                $this->saveChallenge($request, $challenge);

                return $this->challengeResponse($user, $challenge);
            }
            // Existing mobile/web clients post their authenticator code directly
            // to /auth/mfa-challenge after password verification. Treat that as
            // an explicit TOTP selection without weakening the second-factor gate.
            if ($action === 'verify_totp' && $user->mfa_enabled_at !== null) {
                $phase = 'TOTP_SECOND';
                $challenge['phase'] = $phase;
                $this->saveChallenge($request, $challenge);
            } else {
                $this->invalidAction();
            }
        }

        if ($action === 'resend_email_otp' && in_array($phase, [
            'EMAIL_OTP_PRIMARY', 'EMAIL_OTP_SECOND', 'EMAIL_OTP_SECOND_AFTER_TOTP',
            'EMAIL_PROOF_FOR_TOTP_SETUP',
        ], true)) {
            return $this->saveChallengeWithEmailCode($request, $user, $challenge);
        }

        if ($action === 'verify_email_otp' && in_array($phase, [
            'EMAIL_OTP_PRIMARY', 'EMAIL_OTP_SECOND', 'EMAIL_OTP_SECOND_AFTER_TOTP',
            'EMAIL_PROOF_FOR_TOTP_SETUP',
        ], true)) {
            $this->verifyEmailCode($request, $challenge, (string) ($validated['code'] ?? ''));

            if ($phase === 'EMAIL_OTP_SECOND') {
                return $this->completeChallengeLogin($request, $user, $challenge, 'PASSWORD+EMAIL_OTP');
            }
            if ($phase === 'EMAIL_OTP_SECOND_AFTER_TOTP') {
                return $this->completeChallengeLogin($request, $user, $challenge, 'TOTP+EMAIL_OTP');
            }
            if ($phase === 'EMAIL_OTP_PRIMARY' && ! $this->requiresSecondFactor($user)) {
                return $this->completeChallengeLogin($request, $user, $challenge, 'EMAIL_OTP');
            }
            if ($phase === 'EMAIL_OTP_PRIMARY' && $user->mfa_enabled_at !== null) {
                $challenge['phase'] = 'TOTP_SECOND';
                $challenge['attempts'] = 0;
                unset($challenge['email_code_hash'], $challenge['email_code_expires_at']);
                $this->saveChallenge($request, $challenge);

                return $this->challengeResponse($user, $challenge);
            }

            $setup = $this->mfa->beginVerifiedSetup($user, $request);
            $challenge['phase'] = 'TOTP_ENROLLMENT';
            $challenge['attempts'] = 0;
            $challenge['setup'] = $setup;
            unset($challenge['email_code_hash'], $challenge['email_code_expires_at']);
            $this->saveChallenge($request, $challenge);

            return $this->challengeResponse($user, $challenge);
        }

        if ($action === 'verify_totp' && in_array($phase, ['TOTP_PRIMARY', 'TOTP_SECOND'], true)) {
            $method = $this->mfa->verifyLoginCode($user, (string) ($validated['code'] ?? ''));
            if (! $method) {
                $this->recordFailedAttempt($request, $challenge, 'code', 'Enter a valid six-digit Google Authenticator or unused recovery code.');
            }

            if ($phase === 'TOTP_SECOND') {
                $primary = (string) ($challenge['primary_method'] ?? 'PASSWORD');

                return $this->completeChallengeLogin($request, $user, $challenge, $primary.'+'.$method);
            }
            // Selecting an enrolled authenticator is itself a possession-based
            // primary ceremony. Only roles governed by mandatory MFA need an
            // additional, independent email factor after it. Password sign-in
            // still honours an enrolled user's MFA setting via the earlier gate.
            if (! $this->requiresRoleSecondFactor($user)) {
                return $this->completeChallengeLogin($request, $user, $challenge, $method);
            }

            $challenge['phase'] = 'EMAIL_OTP_SECOND_AFTER_TOTP';
            $challenge['attempts'] = 0;

            return $this->saveChallengeWithEmailCode($request, $user, $challenge);
        }

        if ($action === 'confirm_totp_setup' && $phase === 'TOTP_ENROLLMENT') {
            try {
                $setupResult = $this->mfa->confirm($user, $request, (string) ($validated['code'] ?? ''));
            } catch (ValidationException $exception) {
                $this->recordFailedAttempt($request, $challenge, 'code',
                    $exception->errors()['code'][0] ?? 'Enter a valid six-digit Google Authenticator code.');
            }

            return $this->completeChallengeLogin(
                $request,
                $user->fresh(),
                $challenge,
                'EMAIL_OTP+AUTHENTICATOR',
                ['mfa_enrolment' => $setupResult],
            );
        }

        $this->invalidAction();
    }

    /** Backwards-compatible endpoint for clients already on the former MFA URL. */
    public function mfaChallenge(Request $request): JsonResponse
    {
        $request->merge(['action' => 'verify_totp']);

        return $this->authenticationChallenge($request);
    }

    private function storeChallenge(
        Request $request,
        User $user,
        array $attributes,
        bool $sendEmailCode = false,
    ): JsonResponse {
        $expiresAt = now()->addMinutes(max(1, (int) config('qtfoods.identity.authentication_challenge_minutes', 10)));
        $challenge = array_merge([
            'id' => (string) Str::uuid(),
            'user_id' => (string) $user->id,
            'expires_at' => $expiresAt->timestamp,
            'attempts' => 0,
        ], $attributes);

        if ($sendEmailCode) {
            return $this->saveChallengeWithEmailCode($request, $user, $challenge);
        }

        $this->saveChallenge($request, $challenge);

        return $this->challengeResponse($user, $challenge);
    }

    private function saveChallengeWithEmailCode(Request $request, User $user, array $challenge): JsonResponse
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpExpiresAt = now()->addMinutes(max(1, (int) config('qtfoods.identity.login_otp_minutes', 5)));
        $challenge['email_code_hash'] = $this->emailCodeHash((string) $challenge['id'], $code);
        $challenge['email_code_expires_at'] = $otpExpiresAt->timestamp;
        $challenge['attempts'] = 0;
        $delivery = $this->notifications->loginOtp(
            (string) $user->email,
            (string) $user->name,
            $code,
            $otpExpiresAt->toISOString(),
        );
        if (($delivery['status'] ?? null) !== 'SENT') {
            $request->session()->forget(self::CHALLENGE_KEY);

            return response()->json(['error' => [
                'code' => 'EMAIL_OTP_DELIVERY_FAILED',
                'message' => 'We could not send a sign-in code. Try again or contact your organisation administrator.',
            ]], 503);
        }
        $challenge['delivery'] = $delivery;
        $this->saveChallenge($request, $challenge);

        return $this->challengeResponse($user, $challenge);
    }

    private function loadChallenge(Request $request, string $challengeId): array
    {
        $challenge = $request->session()->get(self::CHALLENGE_KEY);
        if (! is_array($challenge)
            || ($challenge['id'] ?? null) !== $challengeId
            || ! is_int($challenge['expires_at'] ?? null)
            || $challenge['expires_at'] < now()->timestamp
            || (int) ($challenge['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            $request->session()->forget([self::CHALLENGE_KEY, 'identity.mfa_setup']);
            throw ValidationException::withMessages([
                'challenge_id' => ['The sign-in challenge is invalid or expired. Start again.'],
            ]);
        }

        return $challenge;
    }

    private function verifyEmailCode(Request $request, array $challenge, string $code): void
    {
        $normalised = preg_replace('/\D+/', '', $code) ?? '';
        $valid = preg_match('/^\d{6}$/', $normalised) === 1
            && is_string($challenge['email_code_hash'] ?? null)
            && is_int($challenge['email_code_expires_at'] ?? null)
            && $challenge['email_code_expires_at'] >= now()->timestamp
            && hash_equals($challenge['email_code_hash'], $this->emailCodeHash((string) $challenge['id'], $normalised));
        if (! $valid) {
            $this->recordFailedAttempt($request, $challenge, 'code', 'Enter the valid six-digit code sent to your registered email.');
        }
    }

    private function recordFailedAttempt(Request $request, array $challenge, string $field, string $message): never
    {
        $challenge['attempts'] = (int) ($challenge['attempts'] ?? 0) + 1;
        if ($challenge['attempts'] >= self::MAX_ATTEMPTS) {
            $request->session()->forget([self::CHALLENGE_KEY, 'identity.mfa_setup']);
            $message = 'Too many unsuccessful attempts. Start sign-in again.';
        } else {
            $this->saveChallenge($request, $challenge);
        }

        throw ValidationException::withMessages([$field => [$message]]);
    }

    private function challengeResponse(User $user, array $challenge): JsonResponse
    {
        $phase = (string) $challenge['phase'];
        $data = [
            'authentication_required' => true,
            'mfa_required' => true,
            'challenge_id' => $challenge['id'],
            'phase' => $phase,
            'primary_method' => $challenge['primary_method'],
            'expires_at' => now()->setTimestamp((int) $challenge['expires_at'])->toISOString(),
            'email_hint' => $this->maskEmail((string) $user->email),
            'totp_registered' => $user->mfa_enabled_at !== null,
            'available_methods' => $phase === 'SELECT_SECOND_FACTOR'
                ? array_values(array_filter(['email_otp', $user->mfa_enabled_at !== null ? 'totp' : null]))
                : [],
        ];
        if (isset($challenge['setup']) && is_array($challenge['setup'])) {
            $data['setup'] = $challenge['setup'];
        }
        if (isset($challenge['delivery']) && is_array($challenge['delivery'])) {
            $data['delivery'] = $challenge['delivery'];
        }

        return response()->json(['data' => $data], 202);
    }

    private function completeChallengeLogin(
        Request $request,
        User $user,
        array $challenge,
        string $authenticationMethod,
        array $extra = [],
    ): JsonResponse {
        $request->session()->forget([self::CHALLENGE_KEY, 'identity.mfa_setup']);

        return $this->completeLogin($user, $request, $authenticationMethod, $extra);
    }

    private function saveChallenge(Request $request, array $challenge): void
    {
        $request->session()->put(self::CHALLENGE_KEY, $challenge);
    }

    private function eligibleUser(string $email): ?User
    {
        return User::query()
            ->where('email', $email)
            ->where('status', 'ACTIVE')
            ->when(! config('deployment.allow_demo_authentication', false),
                fn ($query) => $query->where('is_demo', false))
            ->first();
    }

    private function eligibleUserById(string $userId): ?User
    {
        return User::query()
            ->where('id', $userId)
            ->where('status', 'ACTIVE')
            ->when(! config('deployment.allow_demo_authentication', false),
                fn ($query) => $query->where('is_demo', false))
            ->first();
    }

    private function requiresSecondFactor(User $user): bool
    {
        if ($user->mfa_enabled_at !== null) {
            return true;
        }

        return $this->requiresRoleSecondFactor($user);
    }

    private function requiresRoleSecondFactor(User $user): bool
    {
        $roles = (array) config('qtfoods.identity.mfa_required_roles', ['ERP_ADMIN']);
        if ($roles === []) {
            return false;
        }

        return DB::table('role_assignments as assignment')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.user_id', $user->id)
            ->where('assignment.is_active', true)
            ->where('role.status', 'ACTIVE')
            ->whereIn('role.code', $roles)
            ->where(fn ($query) => $query->whereNull('assignment.effective_from')
                ->orWhere('assignment.effective_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('assignment.effective_to')
                ->orWhere('assignment.effective_to', '>', now()))
            ->exists();
    }

    private function emailCodeHash(string $challengeId, string $code): string
    {
        return hash_hmac('sha256', $challengeId.'|'.$code, (string) config('app.key'));
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible.str_repeat('•', max(2, mb_strlen($local) - mb_strlen($visible))).'@'.$domain;
    }

    private function invalidCredentials(): never
    {
        throw ValidationException::withMessages([
            'email' => ['The supplied credentials are invalid.'],
        ]);
    }

    private function invalidAction(): never
    {
        throw ValidationException::withMessages([
            'action' => ['That action is not available for the current sign-in step.'],
        ]);
    }

    private function completeLogin(
        User $user,
        Request $request,
        ?string $mfaMethod = null,
        array $extra = [],
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
        $payload['authentication'] = array_merge([
            'device_session_id' => $deviceId,
            'mfa_method' => $mfaMethod,
        ], $extra);

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
