<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Application\FinanceSupplementQuery;
use App\Modules\Finance\Application\FinanceSupplementService;
use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FinanceSupplementController
{
    use BuildsAdminContext;

    public function __construct(private readonly FinanceSupplementQuery $query, private readonly FinanceSupplementService $service, private readonly SessionService $sessions) {}

    public function simulations(Request $request): JsonResponse { return $this->workspace('simulations', $request); }
    public function adjustments(Request $request): JsonResponse { return $this->workspace('adjustments', $request); }
    public function legacyImports(Request $request): JsonResponse { return $this->workspace('legacy', $request); }
    public function archiveDocuments(Request $request): JsonResponse { return $this->workspace('archive', $request); }
    public function openingBalances(Request $request): JsonResponse { return $this->workspace('opening', $request); }
    public function supportCases(Request $request): JsonResponse { return $this->workspace('support', $request); }
    public function simulation(string $simulationId, Request $request): JsonResponse { return $this->show('simulation', $simulationId, $request); }
    public function adjustment(string $adjustmentId, Request $request): JsonResponse { return $this->show('adjustment', $adjustmentId, $request); }
    public function legacyImport(string $batchId, Request $request): JsonResponse { return $this->show('legacy', $batchId, $request); }
    public function archiveDocument(string $documentId, Request $request): JsonResponse { return $this->show('archive', $documentId, $request); }
    public function openingBalance(string $batchId, Request $request): JsonResponse { return $this->show('opening', $batchId, $request); }
    public function supportCase(string $caseId, Request $request): JsonResponse { return $this->show('support', $caseId, $request); }

    public function createSimulation(Request $request): JsonResponse { $this->normalise($request, ['simulation_number']); $v = $request->validate(['simulation_number' => $this->code(), 'name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:4000'], 'as_of_date' => ['required', 'date_format:Y-m-d']] + $this->lineRules(true)); return $this->created($this->service->createSimulation($v + $this->commandContext($request, false))); }
    public function runSimulation(string $simulationId, Request $request): JsonResponse { return $this->ok($this->service->runSimulation($simulationId, $this->commandContext($request, true))); }

    public function createAdjustment(Request $request): JsonResponse { $this->normalise($request, ['adjustment_number', 'adjustment_type']); $v = $request->validate(['adjustment_number' => $this->code(), 'adjustment_date' => ['required', 'date_format:Y-m-d'], 'adjustment_type' => ['required', Rule::in(FinanceSupplementService::ADJUSTMENT_TYPES)], 'reason' => ['required', 'string', 'min:3', 'max:4000']] + $this->lineRules(false)); return $this->created($this->service->createAdjustment($v + $this->commandContext($request, false))); }
    public function submitAdjustment(string $adjustmentId, Request $request): JsonResponse { return $this->ok($this->service->transitionAdjustment($adjustmentId, 'SUBMIT', null, $this->commandContext($request, true))); }
    public function approveAdjustment(string $adjustmentId, Request $request): JsonResponse { return $this->ok($this->service->transitionAdjustment($adjustmentId, 'APPROVE', null, $this->commandContext($request, true))); }
    public function postAdjustment(string $adjustmentId, Request $request): JsonResponse { return $this->ok($this->service->transitionAdjustment($adjustmentId, 'POST', null, $this->commandContext($request, true))); }
    public function cancelAdjustment(string $adjustmentId, Request $request): JsonResponse { $v = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]); return $this->ok($this->service->transitionAdjustment($adjustmentId, 'CANCEL', $v['reason'], $this->commandContext($request, true))); }

    public function createLegacyImport(Request $request): JsonResponse { $this->normalise($request, ['batch_number']); $v = $request->validate(['batch_number' => $this->code(), 'source_system' => ['required', 'string', 'max:120'], 'posting_date' => ['required', 'date_format:Y-m-d'], 'rows' => ['required', 'array', 'between:1,1000'], 'rows.*.legacy_account_code' => ['required', 'string', 'max:80'], 'rows.*.account_id' => ['nullable', 'uuid'], 'rows.*.description' => ['required', 'string', 'max:255'], 'rows.*.debit_amount' => $this->amount(), 'rows.*.credit_amount' => $this->amount(), 'rows.*.source' => ['nullable', 'array']]); return $this->created($this->service->createLegacyImport($v + $this->commandContext($request, false))); }
    public function validateLegacyImport(string $batchId, Request $request): JsonResponse { return $this->ok($this->service->validateLegacyImport($batchId, $this->commandContext($request, true))); }
    public function postLegacyImport(string $batchId, Request $request): JsonResponse { return $this->ok($this->service->postLegacyImport($batchId, $this->commandContext($request, true))); }

    public function uploadArchive(Request $request): JsonResponse { $this->normalise($request, ['document_number', 'document_type']); $v = $request->validate(['document_number' => $this->code(), 'invoice_id' => ['nullable', 'uuid'], 'document_type' => ['required', Rule::in(FinanceSupplementService::DOCUMENT_TYPES)], 'document_date' => ['required', 'date_format:Y-m-d'], 'retain_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:document_date'], 'notes' => ['nullable', 'string', 'max:4000'], 'file' => ['required', 'file', 'max:20480', 'mimes:pdf,png,jpg,jpeg,csv,txt']]); return $this->created($this->service->archive($request->file('file'), $v + $this->commandContext($request, false))); }
    public function downloadArchive(string $documentId, Request $request): StreamedResponse { $document = $this->query->archiveDocument($documentId, $this->selectedScope($request, true)); $disk = (string) $document->storage_disk === 'private' ? (string) config('qtfoods.private_document_disk', 'private') : (string) $document->storage_disk; return Storage::disk($disk)->download((string) $document->storage_path, (string) $document->original_name, ['Content-Type' => (string) $document->mime_type, 'X-Content-SHA256' => (string) $document->sha256_checksum]); }

    public function createOpeningBalance(Request $request): JsonResponse { $this->normalise($request, ['batch_number']); $v = $request->validate(['batch_number' => $this->code(), 'opening_date' => ['required', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:4000']] + $this->lineRules(false)); return $this->created($this->service->createOpeningBalance($v + $this->commandContext($request, false))); }
    public function reconcileOpeningBalance(string $batchId, Request $request): JsonResponse { $v = $request->validate(['lines' => ['required', 'array', 'between:2,500'], 'lines.*.line_id' => ['required', 'uuid', 'distinct'], 'lines.*.reconciled_amount' => $this->amount()]); return $this->ok($this->service->reconcileOpeningBalance($batchId, $v['lines'], $this->commandContext($request, true))); }
    public function postOpeningBalance(string $batchId, Request $request): JsonResponse { return $this->ok($this->service->postOpeningBalance($batchId, $this->commandContext($request, true))); }

    public function createSupportCase(Request $request): JsonResponse { $this->normalise($request, ['case_number', 'category', 'severity']); $v = $request->validate(['case_number' => $this->code(), 'category' => ['required', Rule::in(FinanceSupplementService::SUPPORT_CATEGORIES)], 'severity' => ['required', Rule::in(FinanceSupplementService::SEVERITIES)], 'subject' => ['required', 'string', 'max:200'], 'description' => ['required', 'string', 'max:4000']]); return $this->created($this->service->createSupportCase($v + $this->commandContext($request, false))); }
    public function diagnoseSupportCase(string $caseId, Request $request): JsonResponse { $v = $request->validate(['notes' => ['required', 'string', 'min:3', 'max:4000']]); return $this->ok($this->service->diagnoseSupportCase($caseId, $v['notes'], $this->commandContext($request, true))); }
    public function closeSupportCase(string $caseId, Request $request): JsonResponse { $v = $request->validate(['resolution_notes' => ['required', 'string', 'min:3', 'max:4000']]); return $this->ok($this->service->closeSupportCase($caseId, $v['resolution_notes'], $this->commandContext($request, true))); }

    protected function sessionService(): SessionService { return $this->sessions; }
    private function workspace(string $area, Request $request): JsonResponse { $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'string', 'max:24']]); return response()->json($this->query->workspace($area, $this->selectedScope($request, true), $this->currentPermissions($request), $filters)); }
    private function show(string $area, string $id, Request $request): JsonResponse { return response()->json(['data' => $this->query->detail($area, $id, $this->selectedScope($request, true), $this->currentPermissions($request))]); }
    private function created(array $data): JsonResponse { return response()->json(['data' => $data], 201); }
    private function ok(array $data): JsonResponse { return response()->json(['data' => $data]); }
    private function code(): array { return ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/']; }
    private function amount(): array { return ['required', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4']; }
    private function lineRules(bool $assumption): array { $rules = ['lines' => ['required', 'array', 'between:2,500'], 'lines.*' => ['array'], 'lines.*.account_id' => ['required', 'uuid'], 'lines.*.party_id' => ['nullable', 'uuid'], 'lines.*.description' => ['required', 'string', 'max:255'], 'lines.*.debit_amount' => $this->amount(), 'lines.*.credit_amount' => $this->amount()]; if ($assumption) $rules['lines.*.assumption'] = ['nullable', 'string', 'max:500']; return $rules; }
    private function normalise(Request $request, array $upper): void { $input = $request->all(); foreach ($upper as $field) if (is_string($input[$field] ?? null)) $input[$field] = Str::upper(trim($input[$field])); $request->replace($input); }
}
