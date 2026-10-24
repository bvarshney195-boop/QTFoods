<?php

namespace App\Modules\Procurement\Application;

use App\Modules\Work\Application\WorkItemService;
use App\Shared\Approval\ApprovalAuthorityService;
use App\Shared\Approval\ApprovalService;
use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PurchaseRequisitionService
{
    public const STATUSES = ['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED', 'CANCELLED'];
    public const RULE_CODE = 'PURCHASE_REQUISITION_APPROVAL';

    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ApprovalAuthorityService $authority,
        private readonly WorkItemService $workItems,
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $namespace = 'procurement.requisition.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }

            if (DB::table('requisitions')
                ->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])
                ->where('requisition_number', $data['requisition_number'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'requisition_number' => ['That requisition number already exists in the selected plant.'],
                ]);
            }

            $this->assertDates($data['requested_date'], $data['required_by_date']);
            [$lines, $total] = $this->prepareLines($data['lines'], $data);
            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('requisitions')->insert([
                'id' => $id,
                'company_id' => $data['company_id'],
                'plant_id' => $data['plant_id'],
                'requisition_number' => $data['requisition_number'],
                'status' => 'DRAFT',
                'record_version' => 1,
                'created_by' => $data['actor_id'],
                'requested_by' => $data['actor_id'],
                'department' => trim($data['department']),
                'purpose' => trim($data['purpose']),
                'requested_date' => $data['requested_date'],
                'required_by_date' => $data['required_by_date'],
                'currency' => $data['currency'],
                'estimated_total' => $total,
                'approval_request_id' => null,
                'submitted_at' => null,
                'submitted_by' => null,
                'approved_at' => null,
                'approved_by' => null,
                'rejected_at' => null,
                'rejected_by' => null,
                'rejection_reason' => null,
                'cancelled_at' => null,
                'cancelled_by' => null,
                'cancellation_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->insertLines($id, $lines, $data, $now);

            $result = $this->result($id, 'DRAFT', 1, count($lines), $total);
            $this->record(
                'CREATE_PURCHASE_REQUISITION',
                'procurement.requisition.created',
                $id,
                $data,
                1,
                ['created' => [
                    'requisition_number' => $data['requisition_number'],
                    'line_count' => count($lines),
                    'estimated_total' => $total,
                    'currency' => $data['currency'],
                ]],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function update(string $requisitionId, array $data): array
    {
        return DB::transaction(function () use ($requisitionId, $data) {
            $namespace = 'procurement.requisition.update.'.$requisitionId;
            $payload = $data + ['requisition_id' => $requisitionId];
            if ($replay = $this->begin($namespace, $payload)) {
                return $replay;
            }

            $requisition = $this->findLocked($requisitionId, $data);
            $this->assertVersion($requisition, $data['expected_version']);
            if (! in_array($requisition->status, ['DRAFT', 'REJECTED'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only draft or rejected requisitions can be edited.'],
                ]);
            }

            $this->assertDates($data['requested_date'], $data['required_by_date']);
            [$lines, $total] = $this->prepareLines($data['lines'], $data);
            $version = (int) $requisition->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('requisitions')->where('id', $requisitionId)->update([
                'department' => trim($data['department']),
                'purpose' => trim($data['purpose']),
                'requested_date' => $data['requested_date'],
                'required_by_date' => $data['required_by_date'],
                'currency' => $data['currency'],
                'estimated_total' => $total,
                'record_version' => $version,
                'updated_at' => $now,
            ]);
            DB::table('requisition_lines')->where('requisition_id', $requisitionId)->delete();
            $this->insertLines($requisitionId, $lines, $data, $now);

            $result = $this->result(
                $requisitionId,
                (string) $requisition->status,
                $version,
                count($lines),
                $total,
                $requisition->approval_request_id,
            );
            $this->record(
                'UPDATE_PURCHASE_REQUISITION',
                'procurement.requisition.updated',
                $requisitionId,
                $data,
                $version,
                [
                    'required_by_date' => ['from' => $requisition->required_by_date, 'to' => $data['required_by_date']],
                    'estimated_total' => ['from' => $this->decimal($requisition->estimated_total), 'to' => $total],
                    'line_count' => count($lines),
                ],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function submit(string $requisitionId, array $data): array
    {
        return DB::transaction(function () use ($requisitionId, $data) {
            $namespace = 'procurement.requisition.submit.'.$requisitionId;
            $payload = $data + ['requisition_id' => $requisitionId];
            if ($replay = $this->begin($namespace, $payload)) {
                return $replay;
            }

            $requisition = $this->findLocked($requisitionId, $data);
            $this->assertVersion($requisition, $data['expected_version']);
            if (! in_array($requisition->status, ['DRAFT', 'REJECTED'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only a draft or rejected requisition can be submitted for approval.'],
                ]);
            }

            $lineCount = DB::table('requisition_lines')->where('requisition_id', $requisitionId)->count();
            if ($lineCount === 0) {
                throw new ConflictHttpException('The requisition has no lines to approve.');
            }

            $version = (int) $requisition->record_version + 1;
            $approvalId = $this->approvals->request(
                'purchase_requisition',
                $requisitionId,
                $version,
                $data['actor_id'],
                $data['company_id'],
                $data['plant_id'],
                self::RULE_CODE,
                [
                    'requisition_number' => $requisition->requisition_number,
                    'department' => $requisition->department,
                    'purpose' => $requisition->purpose,
                    'line_count' => $lineCount,
                    'estimated_total' => $this->decimal($requisition->estimated_total),
                    'currency' => $requisition->currency,
                    'authority_value' => $this->decimal($requisition->estimated_total),
                ],
            );
            $now = CarbonImmutable::now();
            DB::table('requisitions')->where('id', $requisitionId)->update([
                'status' => 'SUBMITTED',
                'approval_request_id' => $approvalId,
                'submitted_at' => $now,
                'submitted_by' => $data['actor_id'],
                'approved_at' => null,
                'approved_by' => null,
                'rejected_at' => null,
                'rejected_by' => null,
                'rejection_reason' => null,
                'record_version' => $version,
                'updated_at' => $now,
            ]);

            $total = $this->decimal($requisition->estimated_total);
            $result = $this->result(
                $requisitionId,
                'SUBMITTED',
                $version,
                $lineCount,
                $total,
                $approvalId,
            );
            $this->record(
                'SUBMIT_PURCHASE_REQUISITION',
                'procurement.requisition.submitted',
                $requisitionId,
                $data,
                $version,
                [
                    'status' => ['from' => $requisition->status, 'to' => 'SUBMITTED'],
                    'approval_request_id' => $approvalId,
                    'estimated_total' => $total,
                ],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function decide(string $approvalId, string $decision, array $data): array
    {
        return DB::transaction(function () use ($approvalId, $decision, $data) {
            $namespace = 'procurement.requisition.approval.'.$approvalId;
            $payload = $data + ['approval_id' => $approvalId, 'decision' => $decision];
            if ($replay = $this->begin($namespace, $payload)) {
                return $replay;
            }

            $approval = DB::table('approval_requests')
                ->where('id', $approvalId)
                ->where('entity_type', 'purchase_requisition')
                ->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])
                ->lockForUpdate()->first();
            if (! $approval) {
                throw new NotFoundHttpException('Purchase requisition approval request not found.');
            }
            if ((int) $approval->record_version !== $data['expected_version']) {
                throw new ConflictHttpException(
                    "The approval changed from version {$data['expected_version']} to {$approval->record_version}. Refresh it before retrying."
                );
            }
            if ($approval->status !== 'PENDING') {
                throw ValidationException::withMessages([
                    'approval' => ['Approval request is no longer pending.'],
                ]);
            }
            if ((string) $approval->maker_id === $data['actor_id']) {
                throw ValidationException::withMessages([
                    'approval' => ['Maker cannot approve or reject the same purchase requisition.'],
                ]);
            }

            $authority = $this->authority->resolve(
                $data['actor_id'],
                (string) $approval->required_permission,
                $data['company_id'],
                $data['plant_id'],
            );
            if ($authority === null) {
                throw ValidationException::withMessages([
                    'approval' => ["This approval requires {$approval->required_permission} authority in the selected plant."],
                ]);
            }

            $requisition = $this->findLocked((string) $approval->entity_id, $data);
            if ($requisition->status !== 'SUBMITTED'
                || (string) $requisition->approval_request_id !== $approvalId
                || (int) $requisition->record_version !== (int) $approval->entity_version) {
                throw new ConflictHttpException(
                    'The purchase requisition no longer matches the version submitted for approval.'
                );
            }

            $now = CarbonImmutable::now();
            $approvalStatus = $decision === 'APPROVE' ? 'APPROVED' : 'REJECTED';
            $approvalVersion = (int) $approval->record_version + 1;
            $requisitionVersion = (int) $requisition->record_version + 1;
            DB::table('approval_decisions')->insert([
                'id' => (string) Str::uuid(),
                'approval_request_id' => $approvalId,
                'reviewer_id' => $data['actor_id'],
                'decision' => $decision,
                'reason' => $data['reason'] ?? null,
                'authority_source' => $authority['source'],
                'authority_permission' => $authority['permission'],
                'delegation_id' => $authority['delegation_id'],
                'created_at' => $now,
            ]);
            DB::table('approval_requests')->where('id', $approvalId)->update([
                'status' => $approvalStatus,
                'record_version' => $approvalVersion,
                'updated_at' => $now,
            ]);
            DB::table('requisitions')->where('id', $requisition->id)->update([
                'status' => $approvalStatus,
                'record_version' => $requisitionVersion,
                'approved_at' => $decision === 'APPROVE' ? $now : null,
                'approved_by' => $decision === 'APPROVE' ? $data['actor_id'] : null,
                'rejected_at' => $decision === 'REJECT' ? $now : null,
                'rejected_by' => $decision === 'REJECT' ? $data['actor_id'] : null,
                'rejection_reason' => $decision === 'REJECT' ? trim((string) $data['reason']) : null,
                'updated_at' => $now,
            ]);
            $this->workItems->resolveApproval($approvalId, $data['actor_id'], $decision);

            $lineCount = DB::table('requisition_lines')->where('requisition_id', $requisition->id)->count();
            $result = $this->result(
                (string) $requisition->id,
                $approvalStatus,
                $requisitionVersion,
                $lineCount,
                $this->decimal($requisition->estimated_total),
                $approvalId,
            ) + [
                'approval_status' => $approvalStatus,
                'approval_record_version' => $approvalVersion,
                'authority_source' => $authority['source'],
            ];
            $this->record(
                $decision === 'APPROVE' ? 'APPROVE_PURCHASE_REQUISITION' : 'REJECT_PURCHASE_REQUISITION',
                $decision === 'APPROVE'
                    ? 'procurement.requisition.approved'
                    : 'procurement.requisition.rejected',
                (string) $requisition->id,
                $data,
                $requisitionVersion,
                [
                    'status' => ['from' => 'SUBMITTED', 'to' => $approvalStatus],
                    'approval_request_id' => $approvalId,
                    'authority_source' => $authority['source'],
                    'reason' => $data['reason'] ?? null,
                ],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function cancel(string $requisitionId, string $reason, array $data): array
    {
        return DB::transaction(function () use ($requisitionId, $reason, $data) {
            $namespace = 'procurement.requisition.cancel.'.$requisitionId;
            $payload = $data + ['requisition_id' => $requisitionId, 'reason' => $reason];
            if ($replay = $this->begin($namespace, $payload)) {
                return $replay;
            }

            $requisition = $this->findLocked($requisitionId, $data);
            $this->assertVersion($requisition, $data['expected_version']);
            if (! in_array($requisition->status, ['DRAFT', 'REJECTED', 'APPROVED'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only a draft, rejected, or approved requisition can be cancelled.'],
                ]);
            }
            if (DB::table('requests_for_quotation')->where('requisition_id', $requisitionId)
                ->where('status', '<>', 'CANCELLED')->exists()) {
                throw ValidationException::withMessages([
                    'status' => ['Cancel the active RFQ or purchase order before cancelling this requisition.'],
                ]);
            }

            $version = (int) $requisition->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('requisitions')->where('id', $requisitionId)->update([
                'status' => 'CANCELLED',
                'record_version' => $version,
                'cancelled_at' => $now,
                'cancelled_by' => $data['actor_id'],
                'cancellation_reason' => trim($reason),
                'updated_at' => $now,
            ]);
            $lineCount = DB::table('requisition_lines')->where('requisition_id', $requisitionId)->count();
            $result = $this->result(
                $requisitionId,
                'CANCELLED',
                $version,
                $lineCount,
                $this->decimal($requisition->estimated_total),
                $requisition->approval_request_id,
            );
            $this->record(
                'CANCEL_PURCHASE_REQUISITION',
                'procurement.requisition.cancelled',
                $requisitionId,
                $data,
                $version,
                ['status' => ['from' => $requisition->status, 'to' => 'CANCELLED'], 'reason' => $reason],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    private function prepareLines(array $input, array $scope): array
    {
        $itemIds = collect($input)->pluck('item_id')->filter()->unique()->values()->all();
        $items = DB::table('items')->where('company_id', $scope['company_id'])
            ->where('status', 'ACTIVE')->whereIn('id', $itemIds)->get()->keyBy('id');
        $seen = [];
        $lines = [];
        $total = '0.000000';

        foreach (array_values($input) as $index => $line) {
            $itemId = (string) ($line['item_id'] ?? '');
            $item = $items->get($itemId);
            if (! $item) {
                throw ValidationException::withMessages([
                    "lines.{$index}.item_id" => ['Select an active item from the current company.'],
                ]);
            }
            if (isset($seen[$itemId])) {
                throw ValidationException::withMessages([
                    "lines.{$index}.item_id" => ['An item may appear only once in a requisition.'],
                ]);
            }
            $seen[$itemId] = true;
            $quantity = $this->positive($line['quantity'] ?? null, "lines.{$index}.quantity", 'Quantity');
            $unitCost = $this->nonNegative(
                $line['estimated_unit_cost'] ?? null,
                "lines.{$index}.estimated_unit_cost",
                'Estimated unit cost',
            );
            $lineTotal = bcround(bcmul($quantity, $unitCost, 12), 6);
            $total = bcadd($total, $lineTotal, 6);
            if (bccomp($total, '99999999999999.999999', 6) > 0) {
                throw ValidationException::withMessages([
                    'lines' => ['The requisition estimated total exceeds the supported amount.'],
                ]);
            }
            $lines[] = [
                'line_number' => $index + 1,
                'item_id' => $itemId,
                'description' => (string) $item->name,
                'quantity' => $quantity,
                'uom_code' => (string) $item->base_uom,
                'estimated_unit_cost' => $unitCost,
                'estimated_line_total' => $lineTotal,
                'notes' => $this->nullable($line['notes'] ?? null),
            ];
        }

        return [$lines, $total];
    }

    private function insertLines(
        string $requisitionId,
        array $lines,
        array $data,
        CarbonImmutable $now,
    ): void {
        foreach ($lines as $line) {
            DB::table('requisition_lines')->insert([
                'id' => (string) Str::uuid(),
                'requisition_id' => $requisitionId,
                'company_id' => $data['company_id'],
                'plant_id' => $data['plant_id'],
                ...$line,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function findLocked(string $requisitionId, array $scope): object
    {
        $requisition = DB::table('requisitions')->where('id', $requisitionId)
            ->where('company_id', $scope['company_id'])
            ->where('plant_id', $scope['plant_id'])
            ->lockForUpdate()->first();
        if (! $requisition) {
            throw new NotFoundHttpException('Purchase requisition not found.');
        }

        return $requisition;
    }

    private function assertVersion(object $requisition, int $expected): void
    {
        if ((int) $requisition->record_version !== $expected) {
            throw new ConflictHttpException(
                "The requisition changed from version {$expected} to {$requisition->record_version}. Refresh it before continuing."
            );
        }
    }

    private function assertDates(string $requestedDate, string $requiredByDate): void
    {
        if (CarbonImmutable::parse($requiredByDate)->isBefore(CarbonImmutable::parse($requestedDate))) {
            throw ValidationException::withMessages([
                'required_by_date' => ['Required-by date cannot be before the requested date.'],
            ]);
        }
    }

    private function result(
        string $id,
        string $status,
        int $version,
        int $lineCount,
        string $total,
        ?string $approvalId = null,
    ): array {
        return [
            'entity_type' => 'purchase_requisition',
            'id' => $id,
            'status' => $status,
            'record_version' => $version,
            'line_count' => $lineCount,
            'estimated_total' => $total,
            'approval_request_id' => $approvalId,
        ];
    }

    private function record(
        string $command,
        string $event,
        string $id,
        array $data,
        int $version,
        array $safeDiff,
        array $result,
    ): void {
        $this->audit->record(
            $command,
            'purchase_requisition',
            $id,
            $data['actor_id'],
            $data['company_id'],
            $data['plant_id'],
            'SUCCESS',
            [
                'entity_version' => $version,
                'correlation_id' => $data['correlation_id'] ?? null,
                'reason_code' => $command === 'CANCEL_PURCHASE_REQUISITION' ? 'CANCELLED' : null,
                'safe_diff' => $safeDiff,
            ],
        );
        $this->outbox->append(
            $event,
            'purchase_requisition',
            $id,
            $id.':'.$version,
            $result + ['company_id' => $data['company_id'], 'plant_id' => $data['plant_id']],
            $data['correlation_id'] ?? null,
            $data['company_id'],
            $data['plant_id'],
        );
    }

    private function begin(string $namespace, array $data): ?array
    {
        return $this->idempotency->begin(
            $namespace,
            $data['idempotency_key'],
            Arr::except($data, ['actor_id', 'permissions', 'idempotency_key', 'correlation_id']),
        );
    }

    private function positive(mixed $value, string $field, string $label): string
    {
        $decimal = $this->validatedDecimal($value, $field, $label);
        if (bccomp($decimal, '0', 6) <= 0) {
            throw ValidationException::withMessages([$field => ["{$label} must be greater than zero."]]);
        }

        return $decimal;
    }

    private function nonNegative(mixed $value, string $field, string $label): string
    {
        $decimal = $this->validatedDecimal($value, $field, $label);
        if (bccomp($decimal, '0', 6) < 0) {
            throw ValidationException::withMessages([$field => ["{$label} cannot be negative."]]);
        }

        return $decimal;
    }

    private function validatedDecimal(mixed $value, string $field, string $label): string
    {
        $value = (string) $value;
        if (! preg_match('/^\d{1,14}(?:\.\d{1,6})?$/', $value)) {
            throw ValidationException::withMessages([
                $field => ["{$label} must use at most 14 whole digits and 6 decimal places."],
            ]);
        }

        return $this->decimal($value);
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 6);
    }

    private function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
