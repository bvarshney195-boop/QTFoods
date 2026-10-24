<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Modules\Foundation\Application\DeviceSessionService;
use App\Modules\Foundation\Application\IdentityLifecycleService;
use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Domain\User;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class IdentityAdminController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly IdentityLifecycleService $identity,
        private readonly DeviceSessionService $deviceSessions,
        private readonly SessionService $sessions,
    ) {}

    public function invite(Request $request): JsonResponse
    {
        $this->normaliseEmail($request);
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'role_id' => ['required', 'uuid'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
        ]);

        return response()->json(['data' => $this->identity->createInvitation(
            $validated + $this->commandContext($request, false)
        )], 201);
    }

    public function resend(string $invitationId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->identity->resendInvitation(
            $invitationId, $this->commandContext($request, true)
        )]);
    }

    public function revoke(string $invitationId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->identity->revokeInvitation(
            $invitationId, $this->commandContext($request, true)
        )]);
    }

    public function sendVerification(string $userId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->identity->requestEmailVerificationAsAdmin(
            $userId, $this->commandContext($request, false), $request->ip()
        )], 202);
    }

    public function sessions(string $userId, Request $request): JsonResponse
    {
        return response()->json($this->deviceSessions->listForAdmin(
            $userId, $this->selectedScope($request, true), $request
        ));
    }

    public function revokeSession(string $userId, string $sessionId, Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $result = $this->deviceSessions->revokeAsAdmin(
            $actor,
            $userId,
            $sessionId,
            $this->selectedScope($request, true),
            $request
        );
        if ($result['current']) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['data' => $result]);
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }

    private function normaliseEmail(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        }
    }
}
