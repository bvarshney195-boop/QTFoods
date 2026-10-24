<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Domain\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContextController
{
    public function __construct(private readonly SessionService $sessions) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $payload = $this->sessions->payload($user, $request);

        return response()->json([
            'data' => $payload['contexts'],
            'selected_context' => $payload['selected_context'],
        ]);
    }

    public function select(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'uuid'],
            'plant_id' => ['nullable', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->sessions->selectContext(
            $user,
            $request,
            $validated['company_id'],
            $validated['plant_id'] ?? null,
        )]);
    }
}
