<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Modules\Foundation\Application\MfaService;
use App\Modules\Foundation\Domain\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MfaController
{
    public function __construct(private readonly MfaService $mfa) {}

    public function setup(Request $request): JsonResponse
    {
        $validated = $request->validate(['current_password' => ['required', 'string']]);
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->mfa->beginSetup(
            $user, $request, $validated['current_password']
        )]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:16']]);
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->mfa->confirm($user, $request, $validated['code'])]);
    }

    public function disable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:32'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->mfa->disable(
            $user, $request, $validated['current_password'], $validated['code']
        )]);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:16'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->mfa->regenerateRecoveryCodes(
            $user, $validated['current_password'], $validated['code']
        )]);
    }
}
