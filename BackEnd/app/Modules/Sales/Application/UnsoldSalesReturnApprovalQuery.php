<?php

namespace App\Modules\Sales\Application;

use App\Shared\Visibility\OperationalVisibilityScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UnsoldSalesReturnApprovalQuery
{
    public const STATUSES = ['PENDING', 'APPROVED', 'REJECTED'];

    public function __construct(private readonly OperationalVisibilityScope $visibility) {}

    public function paginate(array $scope, array $filters, string $reviewerId, array $permissions): array
    {
        $query = $this->base($scope, $permissions);
        $this->applyFilters($query, $filters);

        [$sortColumn, $sortDirection] = $this->sort($filters['sort'] ?? '-created_at');
        $query->orderBy($sortColumn, $sortDirection)->orderBy('approval.id', $sortDirection);

        $paginator = $query->paginate(
            (int) ($filters['per_page'] ?? 25),
            ['*'],
            'page',
            (int) ($filters['page'] ?? 1)
        );
        $paginator->appends(Arr::except($filters, ['page']));

        return [
            'data' => collect($paginator->items())
                ->map(fn (object $approval) => $this->item($approval, $reviewerId, $permissions))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ];
    }

    public function find(string $approvalId, array $scope, string $reviewerId, array $permissions): ?array
    {
        $approval = $this->base($scope, $permissions)->where('approval.id', $approvalId)->first();
        if (! $approval) {
            return null;
        }

        $lines = DB::table('unsold_return_lines as line')
            ->leftJoin('items as sku', function (JoinClause $join) use ($scope) {
                $join
                    ->on('sku.id', '=', 'line.sku_id')
                    ->where('sku.company_id', $scope['company_id']);
            })
            ->leftJoin('lots as lot', function (JoinClause $join) use ($scope) {
                $join
                    ->on('lot.id', '=', 'line.fg_lot_id')
                    ->where('lot.company_id', $scope['company_id']);
            })
            ->where('line.return_case_id', $approval->entity_id)
            ->orderBy('line.created_at')
            ->orderBy('line.id')
            ->get([
                'line.id',
                'line.sku_id',
                'sku.code as sku_code',
                'sku.name as sku_name',
                'line.fg_lot_id',
                'lot.internal_lot_code as lot_code',
                'line.received_quantity',
                'line.restock_quantity',
                'line.repack_quantity',
                'line.rework_quantity',
                'line.destroy_quantity',
                'line.uom_code',
                'line.quality_reason_code',
            ])
            ->map(fn (object $line) => [
                'id' => (string) $line->id,
                'sku' => $this->reference($line->sku_id, $line->sku_code, $line->sku_name),
                'fg_lot' => $line->fg_lot_id
                    ? $this->reference($line->fg_lot_id, $line->lot_code, null)
                    : null,
                'received_quantity' => (string) $line->received_quantity,
                'restock_quantity' => (string) $line->restock_quantity,
                'repack_quantity' => (string) $line->repack_quantity,
                'rework_quantity' => (string) $line->rework_quantity,
                'destroy_quantity' => (string) $line->destroy_quantity,
                'uom_code' => $line->uom_code,
                'quality_reason_code' => $line->quality_reason_code,
            ])
            ->values()
            ->all();

        $decisions = DB::table('approval_decisions as decision')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'decision.reviewer_id')
            ->where('decision.approval_request_id', $approvalId)
            ->orderBy('decision.created_at')
            ->orderBy('decision.id')
            ->get([
                'decision.id',
                'decision.reviewer_id',
                'reviewer.name as reviewer_name',
                'decision.decision',
                'decision.reason',
                'decision.authority_source',
                'decision.authority_permission',
                'decision.delegation_id',
                'decision.created_at',
            ])
            ->map(fn (object $decision) => [
                'id' => (string) $decision->id,
                'reviewer' => $this->reference($decision->reviewer_id, null, $decision->reviewer_name),
                'decision' => $decision->decision,
                'reason' => $decision->reason,
                'authority_source' => $decision->authority_source,
                'authority_permission' => $decision->authority_permission,
                'delegation_id' => $decision->delegation_id === null
                    ? null
                    : (string) $decision->delegation_id,
                'decided_at' => $this->timestamp($decision->created_at),
            ])
            ->values()
            ->all();

        return [
            ...$this->item($approval, $reviewerId, $permissions),
            'summary' => $this->json($approval->summary_json),
            'lines' => $lines,
            'decisions' => $decisions,
        ];
    }

    private function base(array $scope, array $permissions): Builder
    {
        $query = DB::table('approval_requests as approval')
            ->join('unsold_return_cases as return_case', function (JoinClause $join) {
                $join
                    ->on('return_case.id', '=', 'approval.entity_id')
                    ->on('return_case.company_id', '=', 'approval.company_id')
                    ->on('return_case.plant_id', '=', 'approval.plant_id');
            })
            ->leftJoin('parties as party', function (JoinClause $join) {
                $join
                    ->on('party.id', '=', 'return_case.party_id')
                    ->on('party.company_id', '=', 'return_case.company_id');
            })
            ->leftJoin('users as maker', 'maker.id', '=', 'approval.maker_id')
            ->where('approval.entity_type', 'unsold_return_loss')
            ->where('approval.rule_code', 'UNSOLD_RETURN_LOSS_APPROVAL')
            ->where('approval.company_id', $scope['company_id'])
            ->when(
                $scope['plant_id'] ?? null,
                fn (Builder $query, string $plantId) => $query->where('approval.plant_id', $plantId)
            )
            ->select([
                'approval.id',
                'approval.entity_type',
                'approval.entity_id',
                'approval.entity_version',
                'approval.record_version',
                'approval.maker_id',
                'maker.name as maker_name',
                'approval.rule_code',
                'approval.status',
                'approval.summary_json',
                'approval.approval_rule_id',
                'approval.approval_rule_version',
                'approval.approval_rule_band_id',
                'approval.rule_name_snapshot',
                'approval.band_name_snapshot',
                'approval.authority_value',
                'approval.authority_uom',
                'approval.required_permission',
                'approval.escalation_permission',
                'approval.due_at',
                'approval.escalate_at',
                'approval.escalated_at',
                'approval.escalation_count',
                'approval.resubmission_of_id',
                'approval.submission_number',
                'approval.created_at',
                'approval.updated_at',
                'return_case.status as case_status',
                'return_case.record_version as case_record_version',
                'return_case.party_id',
                'party.code as party_code',
                'party.display_name as party_name',
                'return_case.reason_code',
                'return_case.expected_return_date',
            ])
            ->selectSub(function (Builder $query) {
                $query
                    ->from('unsold_return_lines as totals')
                    ->selectRaw('COALESCE(SUM(totals.destroy_quantity), 0)')
                    ->whereColumn('totals.return_case_id', 'approval.entity_id');
            }, 'destroy_quantity');
        $this->visibility->apply($query, 'UNSOLD_RETURN', 'return_case.id', $permissions, null, 'return_approval_visibility', false);

        return $query;
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $query->when(
            $filters['status'] ?? null,
            fn (Builder $query, string $status) => $query->where('approval.status', $status)
        );

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $query) use ($search) {
            $pattern = '%'.strtolower($search).'%';
            $query
                ->whereRaw('LOWER(return_case.reason_code) LIKE ?', [$pattern])
                ->orWhereRaw('LOWER(party.code) LIKE ?', [$pattern])
                ->orWhereRaw('LOWER(party.display_name) LIKE ?', [$pattern]);

            if (Str::isUuid($search)) {
                $query->orWhere('approval.id', $search)->orWhere('approval.entity_id', $search);
            }
        });
    }

    private function sort(string $sort): array
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $field = ltrim($sort, '-');
        $columns = [
            'created_at' => 'approval.created_at',
            'status' => 'approval.status',
        ];

        return [$columns[$field], $direction];
    }

    private function item(object $approval, string $reviewerId, array $permissions): array
    {
        $requiredPermission = $approval->required_permission
            ?: 'ACTION:RET-UNSOLD:APPROVE';

        return [
            'id' => (string) $approval->id,
            'entity_type' => $approval->entity_type,
            'entity_version' => (int) $approval->entity_version,
            'record_version' => (int) $approval->record_version,
            'rule_code' => $approval->rule_code,
            'status' => $approval->status,
            'maker' => $this->reference($approval->maker_id, null, $approval->maker_name),
            'return_case' => [
                'id' => (string) $approval->entity_id,
                'status' => $approval->case_status,
                'record_version' => (int) $approval->case_record_version,
                'party' => $this->reference(
                    $approval->party_id,
                    $approval->party_code,
                    $approval->party_name
                ),
                'reason_code' => $approval->reason_code,
                'expected_return_date' => $approval->expected_return_date,
            ],
            'destroy_quantity' => (string) $approval->destroy_quantity,
            'authority' => [
                'rule_id' => $approval->approval_rule_id === null
                    ? null
                    : (string) $approval->approval_rule_id,
                'rule_version' => $approval->approval_rule_version === null
                    ? null
                    : (int) $approval->approval_rule_version,
                'rule_name' => $approval->rule_name_snapshot,
                'band_id' => $approval->approval_rule_band_id === null
                    ? null
                    : (string) $approval->approval_rule_band_id,
                'band_name' => $approval->band_name_snapshot,
                'value' => $approval->authority_value === null ? null : (string) $approval->authority_value,
                'uom' => $approval->authority_uom,
                'required_permission' => $requiredPermission,
                'escalation_permission' => $approval->escalation_permission,
            ],
            'submission_number' => (int) $approval->submission_number,
            'resubmission_of_id' => $approval->resubmission_of_id === null
                ? null
                : (string) $approval->resubmission_of_id,
            'due_at' => $this->timestamp($approval->due_at),
            'escalate_at' => $this->timestamp($approval->escalate_at),
            'escalated_at' => $this->timestamp($approval->escalated_at),
            'escalation_count' => (int) $approval->escalation_count,
            'can_decide' => $approval->status === 'PENDING'
                && (string) $approval->maker_id !== $reviewerId
                && $approval->case_status === 'DISPOSITION_REVIEW'
                && (int) $approval->case_record_version === (int) $approval->entity_version
                && in_array($requiredPermission, $permissions, true),
            'created_at' => $this->timestamp($approval->created_at),
            'updated_at' => $this->timestamp($approval->updated_at),
        ];
    }

    private function reference(mixed $id, ?string $code, ?string $name): array
    {
        return ['id' => (string) $id, 'code' => $code, 'name' => $name];
    }

    private function json(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }
}
