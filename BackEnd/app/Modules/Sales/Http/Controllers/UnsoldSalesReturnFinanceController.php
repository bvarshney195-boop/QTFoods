<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Sales\Application\UnsoldSalesReturnFinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UnsoldSalesReturnFinanceController
{
    public function __construct(private readonly UnsoldSalesReturnFinanceService $service) {}

    public function linkInvoice(string $caseId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->response(
            $request,
            $validated,
            fn (array $command) => $this->service->linkInvoice($caseId, $command)
        );
    }

    public function creditNote(string $caseId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_number' => ['required', 'string', 'max:80'],
            'amount' => ['required', 'decimal:0,4', 'gt:0'],
            'currency' => ['required', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $validated['document_number'] = trim($validated['document_number']);
        $validated['currency'] = strtoupper($validated['currency']);

        return $this->response(
            $request,
            $validated,
            fn (array $command) => $this->service->postCreditNote($caseId, $command)
        );
    }

    public function taxAdjustment(string $caseId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_number' => ['required', 'string', 'max:80'],
            'amount' => ['required', 'decimal:0,4', 'gte:0'],
            'currency' => ['required', 'string', 'size:3'],
            'tax_code' => ['required', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $validated['document_number'] = trim($validated['document_number']);
        $validated['currency'] = strtoupper($validated['currency']);
        $validated['tax_code'] = strtoupper(trim($validated['tax_code']));

        return $this->response(
            $request,
            $validated,
            fn (array $command) => $this->service->postTaxAdjustment($caseId, $command)
        );
    }

    public function receivableAdjustment(string $caseId, Request $request): JsonResponse
    {
        return $this->settlement($caseId, 'RECEIVABLE_ADJUSTMENT', $request);
    }

    public function refund(string $caseId, Request $request): JsonResponse
    {
        return $this->settlement($caseId, 'REFUND', $request);
    }

    public function replacement(string $caseId, Request $request): JsonResponse
    {
        return $this->settlement($caseId, 'REPLACEMENT', $request);
    }

    private function settlement(string $caseId, string $actionType, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reference_number' => ['required', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $validated['reference_number'] = trim($validated['reference_number']);

        return $this->response(
            $request,
            $validated,
            fn (array $command) => $this->service->settle($caseId, $actionType, $command)
        );
    }

    private function response(Request $request, array $validated, callable $command): JsonResponse
    {
        $context = $this->selectedContext($request);
        $validated['company_id'] = $context['company_id'];
        $validated['plant_id'] = $context['plant_id'];
        $validated['actor_id'] = (string) $request->user()->id;
        $validated['expected_version'] = $this->expectedVersion($request);
        $validated['idempotency_key'] = $this->idempotencyKey($request);
        $validated['correlation_id'] = $this->correlationId($request);

        return response()->json(['data' => $command($validated)]);
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
                'context' => ['Finance treatment requires an active plant context.'],
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
                'if_match' => ['The If-Match header is required for this finance action.'],
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
