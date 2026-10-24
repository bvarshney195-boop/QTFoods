<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Modules\Foundation\Application\IdentityLifecycleService;
use App\Modules\Foundation\Domain\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

final class IdentityController
{
    public function __construct(private readonly IdentityLifecycleService $identity) {}

    public function invitation(string $token): JsonResponse
    {
        return response()->json(['data' => $this->identity->invitation($token)]);
    }

    public function acceptInvitation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()->mixedCase()],
        ]);

        return response()->json(['data' => $this->identity->acceptInvitation(
            $validated['token'], $validated['password']
        )]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $this->normaliseEmail($request);
        $validated = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);

        return response()->json(['data' => $this->identity->requestPasswordReset(
            $validated['email'], $request->ip()
        )], 202);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $this->normaliseEmail($request);
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()->mixedCase()],
        ]);
        if (Auth::check()) {
            Auth::logout();
        }
        $result = $this->identity->resetPassword(
            $validated['email'], $validated['token'], $validated['password']
        );
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => $result]);
    }

    public function requestVerification(Request $request): JsonResponse
    {
        $this->normaliseEmail($request);
        $validated = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);

        return response()->json(['data' => $this->identity->requestEmailVerification(
            $validated['email'], $request->ip()
        )], 202);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $this->normaliseEmail($request);
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'token' => ['required', 'string', 'size:64'],
        ]);

        return response()->json(['data' => $this->identity->verifyEmail(
            $validated['email'], $validated['token']
        )]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()->mixedCase()],
        ]);
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->identity->changePassword(
            $user, $request, $validated['current_password'], $validated['password']
        )]);
    }

    private function normaliseEmail(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        }
    }
}
