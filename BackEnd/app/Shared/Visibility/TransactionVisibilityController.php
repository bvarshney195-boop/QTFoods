<?php

namespace App\Shared\Visibility;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Domain\User;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TransactionVisibilityController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly TransactionVisibilityService $visibility,
        private readonly SessionService $sessions,
    ) {}

    public function update(string $transactionType, string $transactionId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visibility_status' => ['required', 'string', Rule::in(TransactionVisibilityService::STATUSES)],
            'restriction_reason' => ['nullable', 'string', 'max:2000'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $scope = $this->selectedScope($request, true);

        return response()->json(['data' => $this->visibility->change(
            $transactionType,
            $transactionId,
            $validated['visibility_status'],
            $validated['restriction_reason'] ?? null,
            $scope + [
                'actor_id' => (string) $user->id,
                'permissions' => $this->currentPermissions($request),
                'expected_version' => $this->expectedVisibilityVersion($request),
                'change_source' => 'OPERATIONAL_API',
                'correlation_id' => $this->correlationId($request),
            ],
        )]);
    }

    public function history(string $transactionType, string $transactionId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->visibility->history(
            $transactionType,
            $transactionId,
            $this->selectedScope($request, true) + ['permissions' => $this->currentPermissions($request)],
        )]);
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }

    private function expectedVisibilityVersion(Request $request): int
    {
        $value = trim((string) $request->header('If-Match'));
        if (preg_match('/^(?:W\/)?"(\d+)"$/', $value, $matches)) {
            $value = $matches[1];
        }
        if ($value === '' || ! ctype_digit($value)) {
            throw ValidationException::withMessages([
                'if_match' => ['The If-Match header must contain the current non-negative visibility version.'],
            ]);
        }

        return (int) $value;
    }

    private function correlationId(Request $request): ?string
    {
        $value = trim((string) $request->header('X-Correlation-ID'));

        if ($value !== '' && ! Str::isUuid($value)) {
            throw ValidationException::withMessages([
                'x_correlation_id' => ['The X-Correlation-ID header must be a valid UUID.'],
            ]);
        }

        return $value === '' ? null : $value;
    }
}
