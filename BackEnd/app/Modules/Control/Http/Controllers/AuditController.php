<?php

namespace App\Modules\Control\Http\Controllers;

use App\Modules\Control\Application\AuditQuery;
use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Domain\User;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AuditController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly AuditQuery $query,
        private readonly SessionService $sessions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'q' => ['sometimes', 'string', 'max:100'],
            'command' => ['sometimes', 'string', 'max:128'],
            'entity_type' => ['sometimes', 'string', 'max:80'],
            'outcome' => ['sometimes', 'string', Rule::in(AuditQuery::OUTCOMES)],
            'actor_id' => ['sometimes', 'uuid'],
            'entity_id' => ['sometimes', 'uuid'],
            'request_id' => ['sometimes', 'uuid'],
            'correlation_id' => ['sometimes', 'uuid'],
            'trace_id' => ['sometimes', 'regex:/^[0-9a-f]{32}$/'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'sort' => ['sometimes', 'string', Rule::in(AuditQuery::SORTS)],
        ]);

        return response()->json($this->query->search(
            $this->selectedScope($request, true),
            $filters,
            $this->currentPermissions($request),
        ));
    }

    public function show(string $auditId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->detail(
            $auditId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request),
        )]);
    }

    public function evidence(
        string $auditId,
        string $evidenceId,
        Request $request,
    ): StreamedResponse {
        /** @var User $user */
        $user = $request->user();
        $evidence = $this->query->evidence(
            $auditId,
            $evidenceId,
            $this->selectedScope($request, true),
            (string) $user->id,
            $this->correlationId($request),
        );
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return Storage::disk($evidence['storage_disk'])->response(
            $evidence['storage_path'],
            $evidence['original_name'],
            [
                'Content-Type' => $evidence['mime_type'],
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; img-src 'self' blob: data:; style-src 'unsafe-inline'; sandbox",
            ],
            $disposition,
        );
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }

    private function correlationId(Request $request): ?string
    {
        $value = trim((string) $request->header('X-Correlation-ID'));
        if ($value !== '' && ! Str::isUuid($value)) {
            throw ValidationException::withMessages([
                'correlation_id' => ['The X-Correlation-ID header must be a UUID.'],
            ]);
        }

        return $value !== '' ? $value : null;
    }
}
