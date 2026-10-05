<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Sales\Application\UnsoldSalesReturnQuery;
use App\Modules\Sales\Application\UnsoldSalesReturnLookupQuery;
use App\Modules\Sales\Application\UnsoldSalesReturnReferenceValidator;
use App\Modules\Sales\Application\UnsoldSalesReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class UnsoldSalesReturnController
{
    public function __construct(
        private readonly UnsoldSalesReturnService $service,
        private readonly UnsoldSalesReturnQuery $query,
        private readonly UnsoldSalesReturnLookupQuery $lookups,
        private readonly UnsoldSalesReturnReferenceValidator $references,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'status' => ['sometimes', 'string', Rule::in(UnsoldSalesReturnQuery::STATUSES)],
            'party_id' => ['sometimes', 'uuid'],
            'shipment_id' => ['sometimes', 'uuid'],
            'created_from' => ['sometimes', 'date'],
            'created_to' => ['sometimes', 'date', 'after_or_equal:created_from'],
            'expected_from' => ['sometimes', 'date'],
            'expected_to' => ['sometimes', 'date', 'after_or_equal:expected_from'],
            'q' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', 'string', Rule::in([
                'created_at',
                '-created_at',
                'expected_return_date',
                '-expected_return_date',
                'status',
                '-status',
            ])],
        ]);

        return response()->json($this->query->paginate($this->selectedContext($request), $filters));
    }

    public function show(string $caseId, Request $request): JsonResponse
    {
        $returnCase = $this->query->find($caseId, $this->selectedContext($request));

        abort_if(! $returnCase, 404, 'Unsold return case not found.');

        return response()->json(['data' => $returnCase]);
    }

    public function lookups(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'party_id' => ['sometimes', 'uuid'],
            'shipment_id' => ['sometimes', 'uuid'],
            'sku_id' => ['sometimes', 'uuid'],
            'lot_id' => ['sometimes', 'uuid'],
            'shipment_line_id' => ['sometimes', 'uuid'],
            'inventory_owner_id' => ['sometimes', 'uuid'],
            'uom_code' => ['sometimes', 'string', 'max:16'],
            'q' => ['sometimes', 'string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        return response()->json([
            'data' => $this->lookups->get($this->selectedContext($request), $filters),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['sometimes', 'uuid'],
            'plant_id' => ['sometimes', 'uuid'],
            'party_id' => ['required', 'uuid'],
            'sales_order_id' => ['nullable', 'uuid'],
            'shipment_id' => ['required', 'uuid'],
            'invoice_id' => ['nullable', 'uuid'],
            'reason_code' => ['required', 'string', 'max:64'],
            'expected_return_date' => ['nullable', 'date'],
            'sales_note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.shipment_line_id' => ['required', 'uuid'],
            'lines.*.sku_id' => ['required', 'uuid'],
            'lines.*.fg_lot_id' => ['nullable', 'uuid'],
            'lines.*.requested_quantity' => ['required', 'decimal:0,6', 'gt:0'],
            'lines.*.uom_code' => ['required', 'string', 'max:16'],
        ]);

        $this->applySelectedContext($request, $validated);
        $this->references->validateCreate($validated);

        $validated['actor_id'] = (string) $request->user()->id;
        $validated['idempotency_key'] = $this->idempotencyKey($request);

        return response()->json(['data' => $this->service->createRequest($validated)], 201);
    }

    public function receive(string $caseId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.received_quantity' => ['required', 'decimal:0,6', 'gt:0'],
            'lines.*.return_position_id' => ['nullable', 'uuid', 'required_without:lines.*.return_location_id'],
            'lines.*.return_location_id' => ['nullable', 'uuid', 'required_without:lines.*.return_position_id'],
        ]);
        $this->applySelectedContext($request, $validated);
        $validated['actor_id'] = (string) $request->user()->id;
        $validated['expected_version'] = $this->expectedVersion($request);
        $validated['idempotency_key'] = $this->idempotencyKey($request);
        $validated['correlation_id'] = $this->correlationId($request);

        return response()->json(['data' => $this->service->receive($caseId, $validated)]);
    }

    public function disposition(string $caseId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.restock_quantity' => ['nullable', 'decimal:0,6', 'gte:0'],
            'lines.*.repack_quantity' => ['nullable', 'decimal:0,6', 'gte:0'],
            'lines.*.rework_quantity' => ['nullable', 'decimal:0,6', 'gte:0'],
            'lines.*.destroy_quantity' => ['nullable', 'decimal:0,6', 'gte:0'],
            'lines.*.quality_reason_code' => ['nullable', 'string', 'max:64'],
        ]);
        $this->applySelectedContext($request, $validated);
        $validated['actor_id'] = (string) $request->user()->id;
        $validated['expected_version'] = $this->expectedVersion($request);
        $validated['idempotency_key'] = $this->idempotencyKey($request);
        $validated['correlation_id'] = $this->correlationId($request);

        return response()->json(['data' => $this->service->disposition($caseId, $validated)]);
    }

    public function postLoss(string $caseId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'uom_code' => ['required', 'string', 'max:16'],
            'cost_amount' => ['nullable', 'decimal:0,4', 'gte:0'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);
        $this->applySelectedContext($request, $validated);
        $validated['actor_id'] = (string) $request->user()->id;
        $validated['expected_version'] = $this->expectedVersion($request);
        $validated['idempotency_key'] = $this->idempotencyKey($request);
        $validated['correlation_id'] = $this->correlationId($request);

        return response()->json(['data' => $this->service->postLossAfterApproval($caseId, $validated)]);
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

    private function expectedVersion(Request $request): int
    {
        $value = trim((string) $request->header('If-Match'));

        if ($value === '') {
            throw ValidationException::withMessages([
                'if_match' => ['The If-Match header is required for this state-changing command.'],
            ]);
        }

        if (preg_match('/^(?:W\/)?"(\d+)"$/', $value, $matches)) {
            $value = $matches[1];
        }

        if (! ctype_digit($value) || (int) $value < 1) {
            throw ValidationException::withMessages([
                'if_match' => ['The If-Match header must contain a positive record version.'],
            ]);
        }

        return (int) $value;
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

    private function applySelectedContext(Request $request, array &$validated): void
    {
        $context = $this->selectedContext($request, true);

        if (
            (isset($validated['company_id']) && $validated['company_id'] !== $context['company_id'])
            || (isset($validated['plant_id']) && $validated['plant_id'] !== $context['plant_id'])
        ) {
            throw ValidationException::withMessages([
                'context' => ['The request company and plant must match the active ERP context.'],
            ]);
        }

        $validated['company_id'] = $context['company_id'];
        $validated['plant_id'] = $context['plant_id'];
    }

    private function selectedContext(Request $request, bool $requirePlant = false): array
    {
        $context = $request->attributes->get('erp.context');

        if (
            ! is_array($context)
            || ! is_string($context['company_id'] ?? null)
            || ($requirePlant && ! is_string($context['plant_id'] ?? null))
        ) {
            throw ValidationException::withMessages([
                'context' => ['This workflow requires an active plant context.'],
            ]);
        }

        return [
            'company_id' => $context['company_id'],
            'plant_id' => is_string($context['plant_id'] ?? null) ? $context['plant_id'] : null,
        ];
    }
}
