<?php

namespace App\Modules\Experience\Http\Controllers;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Domain\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExperienceController
{
    public function __construct(private readonly SessionService $sessions) {}

    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);
        [$user, $context] = $this->actor($request);
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($query)).'%';
        $results = [];

        if ($this->sessions->canViewScreen($user, $request, 'MD-PARTY')) {
            $rows = DB::table('parties as p')
                ->where('p.company_id', $context['company_id'])
                ->where(fn (Builder $q) => $q->whereRaw('LOWER(p.display_name) LIKE ?', [$like])->orWhereRaw('LOWER(p.code) LIKE ?', [$like])->orWhereRaw('LOWER(COALESCE(p.legal_name, ?)) LIKE ?', ['', $like]))
                ->select(['p.id', 'p.code', 'p.display_name', 'p.status', 'p.party_kind'])->limit(8)->get();
            foreach ($rows as $row) $results[] = $this->result('PARTY', $row->id, $row->display_name, $row->code, $row->party_kind, $row->status, 'MD-PARTY');
        }

        if ($this->sessions->canViewScreen($user, $request, 'MD-SKU')) {
            $rows = DB::table('items')->where('company_id', $context['company_id'])
                ->where(fn (Builder $q) => $q->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(code) LIKE ?', [$like]))
                ->select(['id', 'code', 'name', 'item_type', 'status'])->limit(8)->get();
            foreach ($rows as $row) $results[] = $this->result('SKU', $row->id, $row->name, $row->code, $row->item_type, $row->status, 'MD-SKU');
        }

        $this->searchTransactions($results, $user, $request, $context, $like);

        return response()->json(['data' => array_slice($results, 0, 30), 'meta' => ['query' => $query, 'count' => min(count($results), 30)]]);
    }

    public function workspace(Request $request): JsonResponse
    {
        [$user, $context] = $this->actor($request);
        $settings = DB::table('user_workspace_settings')->where($this->scope($user, $context))->get()->mapWithKeys(fn ($row) => [$row->setting_key => json_decode($row->setting_value, true)])->all();
        $views = DB::table('user_saved_views')->where($this->scope($user, $context))->orderBy('name')->get()->map(fn ($row) => [
            'id' => (string) $row->id, 'screen_code' => $row->screen_code, 'name' => $row->name, 'filters' => json_decode($row->filters_json, true),
        ])->values()->all();
        return response()->json(['settings' => $settings, 'saved_views' => $views]);
    }

    public function saveSetting(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:80'], 'value' => ['present']]);
        [$user, $context] = $this->actor($request); $scope = $this->scope($user, $context);
        $existing = DB::table('user_workspace_settings')->where($scope)->where('setting_key', $data['key'])->first();
        $values = ['setting_value' => json_encode($data['value'], JSON_THROW_ON_ERROR), 'updated_at' => now()];
        if ($existing) DB::table('user_workspace_settings')->where('id', $existing->id)->update($values + ['record_version' => ((int) $existing->record_version) + 1]);
        else DB::table('user_workspace_settings')->insert($scope + $values + ['id' => (string) Str::uuid(), 'setting_key' => $data['key'], 'record_version' => 1, 'created_at' => now()]);
        return response()->json(['data' => ['key' => $data['key'], 'value' => $data['value']]]);
    }

    public function createView(Request $request): JsonResponse
    {
        $data = $request->validate(['screen_code' => ['required', 'string', 'max:32'], 'name' => ['required', 'string', 'max:80'], 'filters' => ['required', 'array']]);
        [$user, $context] = $this->actor($request); $id = (string) Str::uuid();
        DB::table('user_saved_views')->updateOrInsert($this->scope($user, $context) + ['screen_code' => $data['screen_code'], 'name' => $data['name']], ['id' => $id, 'filters_json' => json_encode($data['filters'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('user_saved_views')->where($this->scope($user, $context))->where('screen_code', $data['screen_code'])->where('name', $data['name'])->first();
        return response()->json(['data' => ['id' => $row->id, 'screen_code' => $row->screen_code, 'name' => $row->name, 'filters' => json_decode($row->filters_json, true)]], 201);
    }

    public function deleteView(string $viewId, Request $request): JsonResponse
    {
        [$user, $context] = $this->actor($request);
        DB::table('user_saved_views')->where($this->scope($user, $context))->where('id', $viewId)->delete();
        return response()->json(['data' => ['deleted' => true]]);
    }

    public function notifications(Request $request): JsonResponse
    {
        [$user, $context] = $this->actor($request); $preferences = $this->notificationPreferences($user, $context);
        if (! $preferences['urgent'] && ! $preferences['high'] && ! $preferences['overdue']) return response()->json(['data' => [], 'summary' => ['unread' => 0, 'total' => 0], 'preferences' => $preferences]);
        $permissions = $this->sessions->permissions($user, $request);
        $query = DB::table('work_items as w')->leftJoin('user_notification_states as ns', fn ($join) => $join->on('ns.work_item_id', '=', 'w.id')->where('ns.user_id', '=', $user->id))
            ->leftJoin('users as assignee', 'assignee.id', '=', 'w.assigned_user_id')->where('w.company_id', $context['company_id'])
            ->when($context['plant_id'], fn (Builder $q) => $q->where(fn (Builder $p) => $p->whereNull('w.plant_id')->orWhere('w.plant_id', $context['plant_id'])))
            ->where(fn (Builder $q) => $q->whereNull('w.required_permission')->orWhereIn('w.required_permission', $permissions))
            ->whereNull('ns.dismissed_at')->whereIn('w.status', $preferences['include_completed'] ? ['OPEN', 'COMPLETED'] : ['OPEN'])
            ->where(function (Builder $q) use ($preferences): void {
                if ($preferences['urgent']) $q->orWhere('w.priority', 'URGENT');
                if ($preferences['high']) $q->orWhere('w.priority', 'HIGH');
                if ($preferences['overdue']) $q->orWhere('w.due_at', '<', now());
            })
            ->select(['w.*', 'assignee.name as assignee_name', 'ns.read_at'])->orderByRaw('CASE WHEN ns.read_at IS NULL THEN 0 ELSE 1 END')->orderBy('w.due_at')->limit(50)->get();
        $data = $query->map(fn ($row) => ['id' => (string) $row->id, 'title' => $row->title, 'description' => $row->description, 'priority' => $row->priority, 'status' => $row->status, 'is_overdue' => $row->due_at !== null && CarbonImmutable::parse($row->due_at)->isPast() && $row->status === 'OPEN', 'read_at' => $row->read_at, 'assignee_name' => $row->assignee_name, 'target' => $row->target_screen_code ? ['screen_code' => $row->target_screen_code, 'href' => $row->target_screen_code.($row->target_record_id ? '?id='.$row->target_record_id : '')] : null, 'created_at' => $row->created_at])->all();
        return response()->json(['data' => $data, 'summary' => ['unread' => collect($data)->whereNull('read_at')->count(), 'total' => count($data)], 'preferences' => $preferences]);
    }

    public function readNotification(string $workItemId, Request $request): JsonResponse
    {
        return $this->notificationState($workItemId, $request, false);
    }

    public function dismissNotification(string $workItemId, Request $request): JsonResponse
    {
        return $this->notificationState($workItemId, $request, true);
    }

    public function saveNotificationPreferences(Request $request): JsonResponse
    {
        $value = $request->validate(['urgent' => ['required', 'boolean'], 'high' => ['required', 'boolean'], 'overdue' => ['required', 'boolean'], 'include_completed' => ['required', 'boolean']]);
        $request->merge(['key' => 'notification_preferences', 'value' => $value]);
        return $this->saveSetting($request);
    }

    public function analytics(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => ['sometimes', Rule::in(['7d', '30d', '90d', '365d'])]]); [$user, $context] = $this->actor($request);
        $days = (int) rtrim($data['period'] ?? '30d', 'd'); $end = CarbonImmutable::now(); $start = $end->subDays($days); $priorStart = $start->subDays($days);
        $sales = fn (CarbonImmutable $from, CarbonImmutable $to) => (float) DB::table('sales_orders')->where('company_id', $context['company_id'])->when($context['plant_id'], fn (Builder $q) => $q->where('plant_id', $context['plant_id']))->whereBetween('order_date', [$from->toDateString(), $to->toDateString()])->sum('total_amount');
        $orders = fn (CarbonImmutable $from, CarbonImmutable $to) => DB::table('sales_orders')->where('company_id', $context['company_id'])->when($context['plant_id'], fn (Builder $q) => $q->where('plant_id', $context['plant_id']))->whereBetween('order_date', [$from->toDateString(), $to->toDateString()])->count();
        $currentSales = $sales($start, $end); $priorSales = $sales($priorStart, $start); $currentOrders = $orders($start, $end); $priorOrders = $orders($priorStart, $start);
        $targets = DB::table('analytics_targets')->where($this->scope($user, $context))->pluck('target_value', 'metric_code')->map(fn ($v) => (float) $v)->all();
        return response()->json(['period' => $data['period'] ?? '30d', 'range' => ['from' => $start->toDateString(), 'to' => $end->toDateString()], 'metrics' => [
            ['code' => 'SALES_VALUE', 'label' => 'Sales value', 'current' => $currentSales, 'previous' => $priorSales, 'change_percent' => $this->change($currentSales, $priorSales), 'target' => $targets['SALES_VALUE'] ?? null, 'format' => 'currency'],
            ['code' => 'SALES_ORDERS', 'label' => 'Sales orders', 'current' => $currentOrders, 'previous' => $priorOrders, 'change_percent' => $this->change($currentOrders, $priorOrders), 'target' => $targets['SALES_ORDERS'] ?? null, 'format' => 'number'],
        ]]);
    }

    public function saveTarget(Request $request): JsonResponse
    {
        $data = $request->validate(['metric_code' => ['required', Rule::in(['SALES_VALUE', 'SALES_ORDERS'])], 'target_value' => ['required', 'numeric', 'min:0']]); [$user, $context] = $this->actor($request);
        DB::table('analytics_targets')->updateOrInsert($this->scope($user, $context) + ['metric_code' => $data['metric_code']], ['id' => (string) Str::uuid(), 'target_value' => $data['target_value'], 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => $data]);
    }

    public function previewImport(Request $request): JsonResponse
    {
        $data = $request->validate(['entity_type' => ['required', Rule::in(['PARTIES', 'ITEMS'])], 'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]); [$user, $context] = $this->actor($request);
        $this->authoriseImport($user, $request, $data['entity_type'], false);
        $handle = fopen($data['file']->getRealPath(), 'rb'); $headers = array_map(fn ($v) => Str::snake(trim((string) $v)), fgetcsv($handle) ?: []); $rows = []; $errors = []; $line = 1;
        while (($values = fgetcsv($handle)) !== false && count($rows) < 5000) { $line++; $row = array_combine($headers, array_pad($values, count($headers), '')) ?: []; $rowErrors = $this->validateImportRow($data['entity_type'], $row, $context); $rows[] = $row; foreach ($rowErrors as $error) $errors[] = ['row' => $line, 'field' => $error[0], 'message' => $error[1], 'value' => $row[$error[0]] ?? null]; }
        fclose($handle); $id = (string) Str::uuid();
        DB::table('bulk_import_batches')->insert($this->scope($user, $context) + ['id' => $id, 'entity_type' => $data['entity_type'], 'file_name' => $data['file']->getClientOriginalName(), 'status' => count($errors) ? 'INVALID' : 'VALID', 'row_count' => count($rows), 'error_count' => count($errors), 'rows_json' => json_encode($rows, JSON_THROW_ON_ERROR), 'errors_json' => json_encode($errors, JSON_THROW_ON_ERROR), 'created_entities_json' => null, 'record_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => ['id' => $id, 'status' => count($errors) ? 'INVALID' : 'VALID', 'row_count' => count($rows), 'error_count' => count($errors), 'preview' => array_slice($rows, 0, 20), 'errors' => array_slice($errors, 0, 100)]], 201);
    }

    public function commitImport(string $batchId, Request $request): JsonResponse
    {
        [$user, $context] = $this->actor($request); $batch = $this->batch($batchId, $user, $context);
        $this->authoriseImport($user, $request, $batch->entity_type, true);
        if ($batch->status !== 'VALID') throw ValidationException::withMessages(['batch' => ['Only a validated import batch can be committed.']]);
        $rows = json_decode($batch->rows_json, true); $created = [];
        DB::transaction(function () use ($batch, $rows, $user, $context, &$created): void {
            foreach ($rows as $row) { $id = (string) Str::uuid();
                if ($batch->entity_type === 'PARTIES') { DB::table('parties')->insert(['id' => $id, 'company_id' => $context['company_id'], 'code' => trim($row['code']), 'display_name' => trim($row['display_name']), 'legal_name' => trim($row['legal_name'] ?: $row['display_name']), 'party_kind' => strtoupper(trim($row['party_kind'] ?: 'ORGANISATION')), 'notes' => $row['notes'] ?: null, 'status' => 'DRAFT', 'record_version' => 1, 'created_at' => now(), 'updated_at' => now()]); $table = 'parties'; }
                else { DB::table('items')->insert(['id' => $id, 'company_id' => $context['company_id'], 'code' => trim($row['code']), 'name' => trim($row['name']), 'item_type' => strtoupper(trim($row['item_type'])), 'base_uom' => strtoupper(trim($row['base_uom'])), 'status' => 'DRAFT', 'record_version' => 1, 'created_at' => now(), 'updated_at' => now()]); $table = 'items'; }
                $created[] = ['table' => $table, 'id' => $id];
            }
            DB::table('bulk_import_batches')->where('id', $batch->id)->update(['status' => 'COMMITTED', 'created_entities_json' => json_encode($created, JSON_THROW_ON_ERROR), 'committed_at' => now(), 'record_version' => ((int) $batch->record_version) + 1, 'updated_at' => now()]);
        });
        return response()->json(['data' => ['id' => $batchId, 'status' => 'COMMITTED', 'created_count' => count($created)]]);
    }

    public function rollbackImport(string $batchId, Request $request): JsonResponse
    {
        [$user, $context] = $this->actor($request); $batch = $this->batch($batchId, $user, $context);
        $this->authoriseImport($user, $request, $batch->entity_type, true);
        if ($batch->status !== 'COMMITTED') throw ValidationException::withMessages(['batch' => ['Only a committed import batch can be rolled back.']]);
        $created = json_decode($batch->created_entities_json ?: '[]', true);
        DB::transaction(function () use ($created, $batch): void { foreach (array_reverse($created) as $entity) DB::table($entity['table'])->where('id', $entity['id'])->delete(); DB::table('bulk_import_batches')->where('id', $batch->id)->update(['status' => 'ROLLED_BACK', 'rolled_back_at' => now(), 'record_version' => ((int) $batch->record_version) + 1, 'updated_at' => now()]); });
        return response()->json(['data' => ['id' => $batchId, 'status' => 'ROLLED_BACK', 'deleted_count' => count($created)]]);
    }

    public function importErrors(string $batchId, Request $request): StreamedResponse
    {
        [$user, $context] = $this->actor($request); $batch = $this->batch($batchId, $user, $context); $errors = json_decode($batch->errors_json, true);
        return response()->streamDownload(function () use ($errors): void { $out = fopen('php://output', 'wb'); fputcsv($out, ['row', 'field', 'message', 'value']); foreach ($errors as $error) fputcsv($out, [$error['row'], $error['field'], $error['message'], $error['value']]); fclose($out); }, 'import-errors-'.$batchId.'.csv', ['Content-Type' => 'text/csv']);
    }

    private function searchTransactions(array &$results, User $user, Request $request, array $context, string $like): void
    {
        $specs = [
            ['CRM-ORDER', 'sales_orders', 'SALES_ORDER', 'order_number', 'Sales order', 'status'], ['PUR-PO', 'purchase_orders', 'PURCHASE_ORDER', 'po_number', 'Purchase order', 'status'], ['PRO-ORDER', 'production_orders', 'PRODUCTION_BATCH', 'order_number', 'Production order / batch', 'status'], ['FIN-AR', 'receivable_transactions', 'INVOICE', 'reference_number', 'Invoice / receivable', 'transaction_type'],
        ];
        foreach ($specs as [$screen, $table, $type, $number, $subtitle, $status]) {
            if (! $this->sessions->canViewScreen($user, $request, $screen) || ! Schema::hasColumn($table, $number)) continue;
            $rows = DB::table($table)->where('company_id', $context['company_id'])->when($context['plant_id'] && Schema::hasColumn($table, 'plant_id'), fn (Builder $q) => $q->where('plant_id', $context['plant_id']))->whereRaw('LOWER(COALESCE('.$number.', ?)) LIKE ?', ['', $like])->select(['id', $number, $status])->limit(6)->get();
            foreach ($rows as $row) $results[] = $this->result($type, $row->id, $row->{$number} ?: $subtitle, $row->{$number} ?: '', $subtitle, $row->{$status}, $screen);
        }
        if ($this->sessions->canViewScreen($user, $request, 'PRO-ORDER')) {
            $rows = DB::table('production_orders')->where('company_id', $context['company_id'])->when($context['plant_id'], fn (Builder $q) => $q->where('plant_id', $context['plant_id']))->whereRaw('LOWER(COALESCE(batch_number, ?)) LIKE ?', ['', $like])->select(['id', 'batch_number', 'status'])->limit(6)->get();
            foreach ($rows as $row) $results[] = $this->result('BATCH', $row->id, $row->batch_number, $row->batch_number, 'Production batch', $row->status, 'PRO-ORDER');
        }
    }

    private function result(string $type, mixed $id, mixed $title, mixed $code, mixed $subtitle, mixed $status, string $screen): array { return ['type' => $type, 'id' => (string) $id, 'title' => (string) $title, 'code' => (string) $code, 'subtitle' => (string) $subtitle, 'status' => (string) $status, 'screen_code' => $screen, 'href' => $screen.'?id='.$id]; }
    private function actor(Request $request): array { /** @var User $user */ $user = $request->user(); $context = $request->attributes->get('erp.context'); if (! is_array($context)) throw ValidationException::withMessages(['context' => ['An active company context is required.']]); return [$user, ['company_id' => $context['company_id'], 'plant_id' => $context['plant_id']]]; }
    private function scope(User $user, array $context): array { return ['user_id' => (string) $user->id, 'company_id' => $context['company_id'], 'plant_id' => $context['plant_id']]; }
    private function notificationPreferences(User $user, array $context): array { $row = DB::table('user_workspace_settings')->where($this->scope($user, $context))->where('setting_key', 'notification_preferences')->first(); return array_merge(['urgent' => true, 'high' => true, 'overdue' => true, 'include_completed' => true], $row ? json_decode($row->setting_value, true) : []); }
    private function notificationState(string $id, Request $request, bool $dismiss): JsonResponse { [$user, $context] = $this->actor($request); abort_unless(DB::table('work_items')->where('id', $id)->where('company_id', $context['company_id'])->exists(), 404); DB::table('user_notification_states')->updateOrInsert(['user_id' => $user->id, 'work_item_id' => $id], ['id' => (string) Str::uuid(), 'read_at' => now(), 'dismissed_at' => $dismiss ? now() : null, 'created_at' => now(), 'updated_at' => now()]); return response()->json(['data' => ['id' => $id, 'read' => true, 'dismissed' => $dismiss]]); }
    private function change(float|int $current, float|int $previous): ?float { return $previous == 0 ? ($current == 0 ? 0.0 : null) : round((($current - $previous) / abs($previous)) * 100, 1); }
    private function validateImportRow(string $type, array $row, array $context): array { $errors = []; foreach ($type === 'PARTIES' ? ['code', 'display_name'] : ['code', 'name', 'item_type', 'base_uom'] as $field) if (trim((string) ($row[$field] ?? '')) === '') $errors[] = [$field, 'Required value is missing.']; $table = $type === 'PARTIES' ? 'parties' : 'items'; if (($row['code'] ?? '') !== '' && DB::table($table)->where('company_id', $context['company_id'])->where('code', trim($row['code']))->exists()) $errors[] = ['code', 'Code already exists in this company.']; if ($type === 'ITEMS' && ($row['base_uom'] ?? '') !== '' && ! DB::table('uoms')->where('code', strtoupper(trim($row['base_uom'])))->exists()) $errors[] = ['base_uom', 'Unit of measure does not exist.']; return $errors; }
    private function batch(string $id, User $user, array $context): object { $row = DB::table('bulk_import_batches')->where($this->scope($user, $context))->where('id', $id)->first(); abort_unless($row, 404); return $row; }
    private function authoriseImport(User $user, Request $request, string $type, bool $write): void { $screen = $type === 'PARTIES' ? 'MD-PARTY' : 'MD-ITEM'; $allowed = $write ? $this->sessions->can($user, $request, 'ACTION:'.$screen.':CREATE') : $this->sessions->canViewScreen($user, $request, $screen); abort_unless($allowed, 403); }
}
