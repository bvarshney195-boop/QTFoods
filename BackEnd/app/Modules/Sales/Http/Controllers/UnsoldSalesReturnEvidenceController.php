<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Sales\Application\UnsoldSalesReturnEvidenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class UnsoldSalesReturnEvidenceController
{
    public function __construct(private readonly UnsoldSalesReturnEvidenceService $service) {}

    public function store(string $caseId, Request $request): JsonResponse
    {
        $maxKilobytes = max(1, (int) config('qtfoods.evidence_max_upload_kilobytes', 10240));
        $validated = $request->validate([
            'category' => ['required', 'string', Rule::in(UnsoldSalesReturnEvidenceService::CATEGORIES)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'file' => [
                'required',
                'file',
                'min:1',
                "max:{$maxKilobytes}",
                'mimetypes:'.implode(',', array_keys(UnsoldSalesReturnEvidenceService::MIME_TYPES)),
            ],
        ]);
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'file' => ['Select an evidence file to upload.'],
            ]);
        }

        $context = $this->selectedContext($request);
        $validated['company_id'] = $context['company_id'];
        $validated['plant_id'] = $context['plant_id'];
        $validated['actor_id'] = (string) $request->user()->id;
        $validated['expected_version'] = $this->expectedVersion($request);
        $validated['idempotency_key'] = $this->idempotencyKey($request);
        $validated['correlation_id'] = $this->correlationId($request);

        return response()->json([
            'data' => $this->service->upload($caseId, $file, $validated),
        ], 201);
    }

    public function download(string $caseId, string $evidenceId, Request $request): StreamedResponse
    {
        $context = $this->selectedContext($request);
        $evidence = $this->service->download($caseId, $evidenceId, [
            'company_id' => $context['company_id'],
            'plant_id' => $context['plant_id'],
            'actor_id' => (string) $request->user()->id,
            'correlation_id' => $this->correlationId($request),
        ]);

        return Storage::disk($evidence['storage_disk'])->download(
            $evidence['storage_path'],
            $evidence['original_name'],
            [
                'Content-Type' => $evidence['mime_type'],
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    private function selectedContext(Request $request): array
    {
        $context = $request->attributes->get('erp.context');
        if (
            ! is_array($context)
            || ! is_string($context['company_id'] ?? null)
            || ! is_string($context['plant_id'] ?? null)
        ) {
            throw ValidationException::withMessages([
                'context' => ['Evidence handling requires an active plant context.'],
            ]);
        }

        return [
            'company_id' => $context['company_id'],
            'plant_id' => $context['plant_id'],
        ];
    }

    private function expectedVersion(Request $request): int
    {
        $value = trim((string) $request->header('If-Match'));
        if ($value === '') {
            throw ValidationException::withMessages([
                'if_match' => ['The If-Match header is required for an evidence upload.'],
            ]);
        }

        if (preg_match('/^(?:W\/)?"(\d+)"$/', $value, $matches)) {
            $value = $matches[1];
        }

        if (! ctype_digit($value) || (int) $value < 1) {
            throw ValidationException::withMessages([
                'if_match' => ['The If-Match header must contain a positive return-case record version.'],
            ]);
        }

        return (int) $value;
    }

    private function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' && config('qtfoods.require_idempotency', true)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['The Idempotency-Key header is required.'],
            ]);
        }

        if (strlen($key) > 160) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['The Idempotency-Key header may not exceed 160 characters.'],
            ]);
        }

        return $key !== '' ? $key : (string) Str::uuid();
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
