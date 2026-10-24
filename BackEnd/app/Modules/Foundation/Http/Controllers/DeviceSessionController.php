<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Modules\Foundation\Application\DeviceSessionService;
use App\Modules\Foundation\Domain\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class DeviceSessionController
{
    public function __construct(private readonly DeviceSessionService $sessions) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->sessions->listForUser((string) $user->id, $request));
    }

    public function revoke(string $sessionId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $this->sessions->revokeOwn($user, $sessionId, $request);
        if ($result['current']) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['data' => $result]);
    }

    public function revokeOthers(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->sessions->revokeOthers($user, $request)]);
    }
}
