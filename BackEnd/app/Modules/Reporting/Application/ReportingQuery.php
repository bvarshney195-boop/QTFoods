<?php

namespace App\Modules\Reporting\Application;

use App\Shared\Visibility\TransactionVisibilityPolicy;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ReportingQuery
{
    public function workspace(array $scope, array $permissions, array $filters = []): array
    {
        $query = DB::table('report_runs as run')
            ->leftJoin('users as creator', 'creator.id', '=', 'run.created_by')
            ->where('run.company_id', $scope['company_id'])
            ->where('run.plant_id', $scope['plant_id'])
            ->where('run.status', 'GENERATED');
        $this->applyRunAccess($query, $permissions);
        $summaryBase = clone $query;

        if (! empty($filters['report_code'])) {
            $query->where('run.report_code', $filters['report_code']);
        }
        if (! empty($filters['q'])) {
            $search = '%'.$filters['q'].'%';
            $query->where(function ($nested) use ($search): void {
                $nested->where('run.run_number', 'like', $search)
                    ->orWhere('run.report_title', 'like', $search);
            });
        }

        $runs = $query->orderByDesc('run.generated_at')->limit(150)->get([
            'run.*', 'creator.name as created_by_name',
        ])->map(fn (object $row): array => $this->runPayload($row, false))->all();

        return [
            'data' => $runs,
            'meta' => ['total' => count($runs)],
            'summary' => [
                'total_runs' => (clone $summaryBase)->count(),
                'rows_snapshotted' => (int) (clone $summaryBase)->sum('run.row_count'),
                'exports_created' => DB::table('report_exports as export')->join('report_runs as run', 'run.id', '=', 'export.report_run_id')
                    ->where('export.company_id', $scope['company_id'])->where('export.plant_id', $scope['plant_id'])
                    ->when(! in_array(TransactionVisibilityPolicy::SHOW_PERMISSION, $permissions, true), fn (Builder $builder) => $builder->where('run.view_classification', '<>', 'FULL_OPERATIONAL'))->count(),
                'latest_freshness_at' => (clone $summaryBase)->max('run.source_freshness_at'),
            ],
            'definitions' => array_values(ReportDefinitionCatalog::all()),
            'allowed_actions' => $this->screenActions($permissions),
        ];
    }

    public function detail(string $id, array $scope, array $permissions): array
    {
        $run = DB::table('report_runs as run')
            ->leftJoin('users as creator', 'creator.id', '=', 'run.created_by')
            ->where('run.id', $id)
            ->where('run.company_id', $scope['company_id'])
            ->where('run.plant_id', $scope['plant_id'])
            ->where('run.status', 'GENERATED');
        $this->applyRunAccess($run, $permissions);
        $run = $run->first(['run.*', 'creator.name as created_by_name']);
        if (! $run) {
            throw new NotFoundHttpException('Report run not found.');
        }

        $payload = $this->runPayload($run, true);
        $payload['rows'] = DB::table('report_run_rows')
            ->where('report_run_id', $id)
            ->orderBy('row_number')
            ->get(['id', 'row_number', 'group_key', 'data_json'])
            ->map(fn (object $row): array => [
                'id' => $row->id,
                'row_number' => (int) $row->row_number,
                'group_key' => $row->group_key,
                'data' => $this->json($row->data_json, []),
            ])->all();
        $payload['exports'] = DB::table('report_exports as export')
            ->leftJoin('users as creator', 'creator.id', '=', 'export.created_by')
            ->where('export.report_run_id', $id)
            ->orderByDesc('export.created_at')
            ->get([
                'export.id', 'export.format', 'export.file_name', 'export.mime_type', 'export.row_count',
                'export.size_bytes', 'export.sha256', 'export.created_at', 'export.created_by',
                'creator.name as created_by_name',
            ])->map(fn (object $row): array => [
                'id' => $row->id,
                'format' => $row->format,
                'file_name' => $row->file_name,
                'mime_type' => $row->mime_type,
                'row_count' => (int) $row->row_count,
                'size_bytes' => (int) $row->size_bytes,
                'sha256' => $row->sha256,
                'created_at' => $row->created_at,
                'created_by' => ['id' => $row->created_by, 'name' => $row->created_by_name],
            ])->all();
        $payload['allowed_actions'] = array_values(array_filter([
            in_array('ACTION:BI-REP:EXPORT', $permissions, true) ? 'EXPORT' : null,
        ]));

        return $payload;
    }

    public function export(string $id, array $scope, array $permissions): object
    {
        $query = DB::table('report_exports as export')->join('report_runs as run', 'run.id', '=', 'export.report_run_id')
            ->where('export.id', $id)
            ->where('export.company_id', $scope['company_id'])->where('export.plant_id', $scope['plant_id'])
            ->select('export.*');
        $this->applyRunAccess($query, $permissions);
        $export = $query->first();
        if (! $export) {
            throw new NotFoundHttpException('Report export not found.');
        }

        return $export;
    }

    private function runPayload(object $run, bool $includeDefinition): array
    {
        $payload = [
            'id' => $run->id,
            'run_number' => $run->run_number,
            'report_code' => $run->report_code,
            'report_title' => $run->report_title,
            'view_classification' => $run->view_classification,
            'view_label' => match ($run->view_classification) {
                'PERMISSION_FILTERED_OPERATIONAL' => 'Permission-Filtered Operational View',
                'FULL_OPERATIONAL' => 'Full Operational View',
                default => 'Authoritative Financial View',
            },
            'includes_restricted_entries' => (bool) $run->includes_restricted_entries,
            'status' => $run->status,
            'as_of_at' => $run->as_of_at,
            'source_freshness_at' => $run->source_freshness_at,
            'parameters' => $this->json($run->parameters_json, []),
            'columns' => $this->json($run->columns_json, []),
            'row_count' => (int) $run->row_count,
            'totals' => $this->json($run->totals_json, []),
            'sha256' => $run->sha256,
            'record_version' => (int) $run->record_version,
            'generated_at' => $run->generated_at,
            'created_at' => $run->created_at,
            'created_by' => ['id' => $run->created_by, 'name' => $run->created_by_name],
        ];
        if ($includeDefinition) {
            $payload['definition'] = ReportDefinitionCatalog::get((string) $run->report_code);
        }

        return $payload;
    }

    private function applyRunAccess(Builder $query, array $permissions): void
    {
        if (! in_array(TransactionVisibilityPolicy::SHOW_PERMISSION, $permissions, true)) {
            $query->where('run.view_classification', '<>', 'FULL_OPERATIONAL');
        }
    }

    private function screenActions(array $permissions): array
    {
        $prefix = 'ACTION:BI-REP:';

        return collect($permissions)->filter(fn (string $permission): bool => str_starts_with($permission, $prefix))
            ->map(fn (string $permission): string => substr($permission, strlen($prefix)))->values()->all();
    }

    private function json(mixed $value, array $fallback): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return $fallback;
        }

        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }
}
