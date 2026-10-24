<?php

namespace App\Modules\Procurement\Application;

use App\Shared\Approval\ApprovalAuthorityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PurchaseRequisitionQuery
{
    public const SORTS = ['NEWEST', 'OLDEST', 'NUMBER', 'REQUIRED_DATE', 'STATUS', 'VALUE'];
    public const CURRENCIES = ['INR'];

    public function __construct(private readonly ApprovalAuthorityService $authority) {}

    public function workspace(
        array $scope,
        array $filters,
        array $permissions,
        string $actorId,
    ): array {
        $base = $this->base($scope);
        $query = clone $base;
        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters['sort'] ?? 'NEWEST');
        $requisitions = $query->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($requisitions->items())
                ->map(fn (object $row) => $this->payload($row, $permissions, $actorId, false))
                ->all(),
            'meta' => $this->meta($requisitions),
            'summary' => [
                'total' => (clone $base)->count(),
                'draft' => (clone $base)->where('requisition.status', 'DRAFT')->count(),
                'submitted' => (clone $base)->where('requisition.status', 'SUBMITTED')->count(),
                'approved' => (clone $base)->where('requisition.status', 'APPROVED')->count(),
                'rejected' => (clone $base)->where('requisition.status', 'REJECTED')->count(),
                'cancelled' => (clone $base)->where('requisition.status', 'CANCELLED')->count(),
                'estimated_total' => $this->decimal(
                    DB::table('requisitions')->where('company_id', $scope['company_id'])
                        ->where('plant_id', $scope['plant_id'])
                        ->whereNotIn('status', ['REJECTED', 'CANCELLED'])
                        ->sum('estimated_total')
                ),
            ],
            'lookups' => [
                'statuses' => PurchaseRequisitionService::STATUSES,
                'sorts' => self::SORTS,
                'currencies' => self::CURRENCIES,
                'items' => $this->items($scope),
            ],
            'approvals' => $this->pendingApprovals($scope, $actorId),
            'allowed_actions' => $this->can($permissions, 'ACTION:PUR-REQ:CREATE') ? ['CREATE'] : [],
        ];
    }

    public function detail(
        string $requisitionId,
        array $scope,
        array $permissions,
        string $actorId,
    ): array {
        $requisition = $this->base($scope)->where('requisition.id', $requisitionId)->first();
        if (! $requisition) {
            throw new NotFoundHttpException('Purchase requisition not found.');
        }

        $payload = $this->payload($requisition, $permissions, $actorId, true);
        $payload['lines'] = DB::table('requisition_lines as line')
            ->join('items as item', 'item.id', '=', 'line.item_id')
            ->where('line.requisition_id', $requisitionId)
            ->orderBy('line.line_number')
            ->get([
                'line.*', 'item.code as item_code', 'item.name as item_name',
                'item.status as item_status', 'item.item_type',
            ])->map(fn (object $line) => [
                'id' => (string) $line->id,
                'line_number' => (int) $line->line_number,
                'item' => [
                    'id' => (string) $line->item_id,
                    'code' => $line->item_code,
                    'name' => $line->item_name,
                    'status' => $line->item_status,
                    'item_type' => $line->item_type,
                    'uom_code' => $line->uom_code,
                ],
                'description' => $line->description,
                'quantity' => $this->decimal($line->quantity),
                'uom_code' => $line->uom_code,
                'estimated_unit_cost' => $this->decimal($line->estimated_unit_cost),
                'estimated_line_total' => $this->decimal($line->estimated_line_total),
                'notes' => $line->notes,
            ])->all();

        return $payload;
    }

    private function base(array $scope): Builder
    {
        return DB::table('requisitions as requisition')
            ->join('users as requester', 'requester.id', '=', 'requisition.requested_by')
            ->leftJoin('users as submitter', 'submitter.id', '=', 'requisition.submitted_by')
            ->leftJoin('users as approver', 'approver.id', '=', 'requisition.approved_by')
            ->leftJoin('users as rejector', 'rejector.id', '=', 'requisition.rejected_by')
            ->leftJoin('users as canceller', 'canceller.id', '=', 'requisition.cancelled_by')
            ->leftJoin('approval_requests as approval', 'approval.id', '=', 'requisition.approval_request_id')
            ->where('requisition.company_id', $scope['company_id'])
            ->where('requisition.plant_id', $scope['plant_id'])
            ->select([
                'requisition.*', 'requester.name as requester_name', 'submitter.name as submitter_name',
                'approver.name as approver_name', 'rejector.name as rejector_name',
                'canceller.name as canceller_name',
                'approval.status as approval_status', 'approval.record_version as approval_record_version',
                'approval.required_permission', 'approval.rule_name_snapshot', 'approval.band_name_snapshot',
                'approval.authority_value', 'approval.authority_uom', 'approval.due_at',
                'approval.submission_number', 'approval.maker_id as approval_maker_id',
            ])->selectSub(
                fn (Builder $line) => $line->from('requisition_lines as line')
                    ->whereColumn('line.requisition_id', 'requisition.id')->selectRaw('COUNT(*)'),
                'line_count',
            )->selectSub(
                fn (Builder $rfq) => $rfq->from('requests_for_quotation as sourcing')
                    ->whereColumn('sourcing.requisition_id', 'requisition.id')
                    ->where('sourcing.status', '<>', 'CANCELLED')->selectRaw('COUNT(*)'),
                'active_rfq_count',
            );
    }

    private function payload(object $row, array $permissions, string $actorId, bool $includeDecision): array
    {
        $approval = $row->approval_request_id ? [
            'id' => (string) $row->approval_request_id,
            'status' => $row->approval_status,
            'record_version' => (int) $row->approval_record_version,
            'rule_name' => $row->rule_name_snapshot,
            'band_name' => $row->band_name_snapshot,
            'authority_value' => $this->decimal($row->authority_value),
            'authority_uom' => $row->authority_uom,
            'required_permission' => $row->required_permission,
            'due_at' => $this->timestamp($row->due_at),
            'submission_number' => (int) $row->submission_number,
            'allowed_actions' => $this->approvalActions($row, $actorId),
        ] : null;
        if ($approval !== null && $includeDecision) {
            $decision = DB::table('approval_decisions as decision')
                ->join('users as reviewer', 'reviewer.id', '=', 'decision.reviewer_id')
                ->where('decision.approval_request_id', $row->approval_request_id)
                ->first([
                    'decision.decision', 'decision.reason', 'decision.authority_source',
                    'decision.authority_permission', 'decision.created_at',
                    'reviewer.id as reviewer_id', 'reviewer.name as reviewer_name',
                ]);
            $approval['decision'] = $decision ? [
                'decision' => $decision->decision,
                'reason' => $decision->reason,
                'authority_source' => $decision->authority_source,
                'authority_permission' => $decision->authority_permission,
                'reviewer' => ['id' => (string) $decision->reviewer_id, 'name' => $decision->reviewer_name],
                'decided_at' => $this->timestamp($decision->created_at),
            ] : null;
        }

        return [
            'id' => (string) $row->id,
            'company_id' => (string) $row->company_id,
            'plant_id' => (string) $row->plant_id,
            'requisition_number' => $row->requisition_number,
            'status' => $row->status,
            'record_version' => (int) $row->record_version,
            'requested_by' => ['id' => (string) $row->requested_by, 'name' => $row->requester_name],
            'department' => $row->department,
            'purpose' => $row->purpose,
            'requested_date' => (string) $row->requested_date,
            'required_by_date' => (string) $row->required_by_date,
            'currency' => $row->currency,
            'estimated_total' => $this->decimal($row->estimated_total),
            'line_count' => (int) $row->line_count,
            'approval' => $approval,
            'submitted_at' => $this->timestamp($row->submitted_at),
            'submitted_by' => $row->submitted_by ? [
                'id' => (string) $row->submitted_by, 'name' => $row->submitter_name,
            ] : null,
            'approved_at' => $this->timestamp($row->approved_at),
            'approved_by' => $row->approved_by ? [
                'id' => (string) $row->approved_by, 'name' => $row->approver_name,
            ] : null,
            'rejected_at' => $this->timestamp($row->rejected_at),
            'rejected_by' => $row->rejected_by ? [
                'id' => (string) $row->rejected_by, 'name' => $row->rejector_name,
            ] : null,
            'rejection_reason' => $row->rejection_reason,
            'cancelled_at' => $this->timestamp($row->cancelled_at),
            'cancelled_by' => $row->cancelled_by ? [
                'id' => (string) $row->cancelled_by, 'name' => $row->canceller_name,
            ] : null,
            'cancellation_reason' => $row->cancellation_reason,
            'has_active_sourcing' => (int) $row->active_rfq_count > 0,
            'allowed_actions' => $this->allowedActions($row, $permissions),
            'created_at' => $this->timestamp($row->created_at),
            'updated_at' => $this->timestamp($row->updated_at),
        ];
    }

    private function allowedActions(object $row, array $permissions): array
    {
        $actions = [];
        if (in_array($row->status, ['DRAFT', 'REJECTED'], true)
            && $this->can($permissions, 'ACTION:PUR-REQ:UPDATE')) {
            $actions[] = 'UPDATE';
        }
        if (in_array($row->status, ['DRAFT', 'REJECTED'], true)
            && $this->can($permissions, 'ACTION:PUR-REQ:SUBMIT')) {
            $actions[] = 'SUBMIT';
        }
        if (in_array($row->status, ['DRAFT', 'REJECTED', 'APPROVED'], true)
            && (int) $row->active_rfq_count === 0
            && $this->can($permissions, 'ACTION:PUR-REQ:CANCEL')) {
            $actions[] = 'CANCEL';
        }

        return $actions;
    }

    private function approvalActions(object $row, string $actorId): array
    {
        if ($row->approval_status !== 'PENDING'
            || (string) $row->approval_maker_id === $actorId
            || ! is_string($row->required_permission)) {
            return [];
        }

        return $this->authority->resolve(
            $actorId,
            $row->required_permission,
            (string) $row->company_id,
            (string) $row->plant_id,
        ) ? ['APPROVE', 'REJECT'] : [];
    }

    private function pendingApprovals(array $scope, string $actorId): array
    {
        return $this->base($scope)
            ->where('requisition.status', 'SUBMITTED')
            ->where('approval.status', 'PENDING')
            ->orderBy('approval.due_at')
            ->get()
            ->filter(fn (object $row) => $this->approvalActions($row, $actorId) !== [])
            ->map(fn (object $row) => $this->payload($row, [], $actorId, false))
            ->values()->all();
    }

    private function items(array $scope): array
    {
        return DB::table('items')->where('company_id', $scope['company_id'])
            ->where('status', 'ACTIVE')->orderBy('code')
            ->get(['id', 'code', 'name', 'item_type', 'base_uom'])
            ->map(fn (object $item) => [
                'id' => (string) $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'item_type' => $item->item_type,
                'uom_code' => $item->base_uom,
            ])->all();
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $query) use ($needle): void {
                foreach ([
                    'requisition.requisition_number', 'requisition.department',
                    'requisition.purpose', 'requester.name',
                ] as $column) {
                    $query->orWhereRaw("LOWER(COALESCE({$column}, '')) LIKE ?", [$needle]);
                }
            });
        }
        if (! empty($filters['status'])) {
            $query->where('requisition.status', $filters['status']);
        }
        if (! empty($filters['requested_by'])) {
            $query->where('requisition.requested_by', $filters['requested_by']);
        }
        if (! empty($filters['required_from'])) {
            $query->whereDate('requisition.required_by_date', '>=', $filters['required_from']);
        }
        if (! empty($filters['required_to'])) {
            $query->whereDate('requisition.required_by_date', '<=', $filters['required_to']);
        }
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'OLDEST' => $query->orderBy('requisition.created_at')->orderBy('requisition.id'),
            'NUMBER' => $query->orderBy('requisition.requisition_number')->orderBy('requisition.id'),
            'REQUIRED_DATE' => $query->orderBy('requisition.required_by_date')->orderBy('requisition.id'),
            'STATUS' => $query->orderBy('requisition.status')->orderByDesc('requisition.updated_at'),
            'VALUE' => $query->orderByDesc('requisition.estimated_total')->orderBy('requisition.id'),
            default => $query->orderByDesc('requisition.updated_at')->orderByDesc('requisition.id'),
        };
    }

    private function meta(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
    }

    private function can(array $permissions, string $permission): bool
    {
        return in_array($permission, $permissions, true);
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 6);
    }
}
