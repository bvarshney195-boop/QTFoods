<?php

namespace App\Modules\Reporting\Application;

use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use App\Shared\Visibility\OperationalVisibilityScope;
use App\Shared\Visibility\TransactionVisibilityPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ReportingService
{
    private const MAX_ROWS = 5000;

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
        private readonly OperationalVisibilityScope $visibility,
    ) {}

    public function generate(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'reporting.run.generate';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }

            if (DB::table('report_runs')->where($this->scope($data))->where('run_number', $data['run_number'])->exists()) {
                throw ValidationException::withMessages([
                    'run_number' => ['That report run number already exists in the selected plant.'],
                ]);
            }

            $plant = DB::table('plants')->where('id', $data['plant_id'])
                ->where('company_id', $data['company_id'])->first();
            if (! $plant) {
                throw ValidationException::withMessages(['context' => ['The selected plant is not active in this company.']]);
            }
            $timezone = is_string($plant->timezone ?? null) ? $plant->timezone : 'UTC';
            $cutoff = CarbonImmutable::createFromFormat('!Y-m-d', $data['as_of_date'], $timezone)->endOfDay();
            $today = CarbonImmutable::now($timezone)->startOfDay();
            if ($cutoff->startOfDay()->greaterThan($today)) {
                throw ValidationException::withMessages(['as_of_date' => ['The report cutoff cannot be in the future.']]);
            }
            if ($data['report_code'] === 'INVENTORY_AVAILABILITY' && ! $cutoff->isSameDay($today)) {
                throw ValidationException::withMessages([
                    'as_of_date' => ['Inventory availability is a current ledger projection and must use today as its cutoff.'],
                ]);
            }

            $definition = ReportDefinitionCatalog::get($data['report_code']);
            $viewClassification = $this->viewClassification($data['report_code'], $data['permissions']);
            $parameters = $this->parameters($definition, $data['parameters'] ?? []);
            [$rows, $totals, $freshness] = match ($data['report_code']) {
                'TRIAL_BALANCE' => $this->trialBalance($cutoff, $parameters, $data),
                'RECEIVABLE_AGING' => $this->receivableAging($cutoff, $parameters, $data),
                'INVENTORY_AVAILABILITY' => $this->inventoryAvailability($cutoff, $parameters, $data),
                'ORDER_FULFILMENT' => $this->orderFulfilment($cutoff, $parameters, $data),
            };
            if (count($rows) > self::MAX_ROWS) {
                throw ValidationException::withMessages([
                    'report_code' => ['The scoped report exceeds 5,000 rows. Narrow the source data before snapshotting.'],
                ]);
            }

            $generatedAt = CarbonImmutable::now('UTC');
            $freshness ??= $generatedAt;
            $snapshot = [
                'definition' => $data['report_code'],
                'view_classification' => $viewClassification,
                'view_label' => $this->viewLabel($viewClassification),
                'includes_restricted_entries' => $viewClassification !== 'PERMISSION_FILTERED_OPERATIONAL',
                'as_of_at' => $cutoff->utc()->toIso8601String(),
                'source_freshness_at' => CarbonImmutable::parse($freshness)->utc()->toIso8601String(),
                'parameters' => $parameters,
                'columns' => $definition['columns'],
                'rows' => $rows,
                'totals' => $totals,
            ];
            $checksum = hash('sha256', $this->canonicalJson($snapshot));
            $runId = (string) Str::uuid();

            DB::table('report_runs')->insert([
                'id' => $runId,
                'company_id' => $data['company_id'],
                'plant_id' => $data['plant_id'],
                'run_number' => $data['run_number'],
                'report_code' => $data['report_code'],
                'report_title' => $definition['title'],
                'view_classification' => $viewClassification,
                'includes_restricted_entries' => $viewClassification !== 'PERMISSION_FILTERED_OPERATIONAL',
                'status' => 'GENERATED',
                'as_of_at' => $cutoff->utc(),
                'source_freshness_at' => CarbonImmutable::parse($freshness)->utc(),
                'parameters_json' => json_encode($parameters, JSON_THROW_ON_ERROR),
                'columns_json' => json_encode($definition['columns'], JSON_THROW_ON_ERROR),
                'row_count' => count($rows),
                'totals_json' => json_encode($totals, JSON_THROW_ON_ERROR),
                'sha256' => $checksum,
                'generated_at' => $generatedAt,
                'record_version' => 1,
                'created_by' => $data['actor_id'],
                'created_at' => $generatedAt,
                'updated_at' => $generatedAt,
            ]);
            foreach ($rows as $index => $row) {
                DB::table('report_run_rows')->insert([
                    'id' => (string) Str::uuid(),
                    'report_run_id' => $runId,
                    'company_id' => $data['company_id'],
                    'plant_id' => $data['plant_id'],
                    'row_number' => $index + 1,
                    'group_key' => $this->groupKey($data['report_code'], $row),
                    'data_json' => json_encode($row, JSON_THROW_ON_ERROR),
                    'created_at' => $generatedAt,
                ]);
            }

            $result = [
                'entity_type' => 'report_run',
                'id' => $runId,
                'status' => 'GENERATED',
                'record_version' => 1,
                'report_code' => $data['report_code'],
                'run_number' => $data['run_number'],
                'row_count' => count($rows),
                'sha256' => $checksum,
                'view_classification' => $viewClassification,
            ];
            $this->record(
                'GENERATE_REPORT', 'reporting.run.generated', 'report_run', $runId, $data, 1,
                ['report_code' => $data['report_code'], 'as_of_date' => $data['as_of_date'], 'row_count' => count($rows), 'sha256' => $checksum],
                $result,
            );
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function createExport(string $runId, string $format, array $data): array
    {
        return DB::transaction(function () use ($runId, $format, $data): array {
            $namespace = 'reporting.export.create.'.$runId;
            $input = $data + ['report_run_id' => $runId, 'format' => $format];
            if ($replay = $this->begin($namespace, $input)) {
                return $replay;
            }

            $run = DB::table('report_runs')->where('id', $runId)->where($this->scope($data))
                ->where('status', 'GENERATED')->lockForUpdate()->first();
            if (! $run) {
                throw new NotFoundHttpException('Report run not found.');
            }
            $this->assertRunAccessible($run, $data['permissions']);
            $columns = $this->json($run->columns_json);
            $rows = DB::table('report_run_rows')->where('report_run_id', $runId)->orderBy('row_number')
                ->pluck('data_json')->map(fn (mixed $value): array => $this->json($value))->all();
            $format = Str::upper($format);
            [$payload, $mime, $extension] = $format === 'CSV'
                ? [$this->csv($columns, $rows, $this->viewLabel((string) $run->view_classification)), 'text/csv; charset=UTF-8', 'csv']
                : [$this->jsonExport($run, $columns, $rows), 'application/json', 'json'];
            $checksum = hash('sha256', $payload);
            $exportId = (string) Str::uuid();
            $fileName = Str::lower((string) $run->run_number).'.'.$extension;
            $createdAt = CarbonImmutable::now('UTC');

            DB::table('report_exports')->insert([
                'id' => $exportId,
                'report_run_id' => $runId,
                'company_id' => $data['company_id'],
                'plant_id' => $data['plant_id'],
                'format' => $format,
                'file_name' => $fileName,
                'mime_type' => $mime,
                'row_count' => count($rows),
                'payload_text' => $payload,
                'size_bytes' => strlen($payload),
                'sha256' => $checksum,
                'created_by' => $data['actor_id'],
                'created_at' => $createdAt,
            ]);

            $result = [
                'entity_type' => 'report_export',
                'id' => $exportId,
                'report_run_id' => $runId,
                'status' => 'READY',
                'record_version' => 1,
                'format' => $format,
                'file_name' => $fileName,
                'row_count' => count($rows),
                'size_bytes' => strlen($payload),
                'sha256' => $checksum,
            ];
            $this->record(
                'CREATE_REPORT_EXPORT', 'reporting.export.created', 'report_export', $exportId, $data, 1,
                ['report_run_id' => $runId, 'format' => $format, 'row_count' => count($rows), 'sha256' => $checksum],
                $result,
            );
            $this->complete($namespace, $input, $result);

            return $result;
        }, 3);
    }

    private function trialBalance(CarbonImmutable $cutoff, array $parameters, array $data): array
    {
        $amounts = DB::table('journal_lines as line')
            ->join('journals as journal', function ($join): void {
                $join->on('journal.id', '=', 'line.journal_id')
                    ->on('journal.company_id', '=', 'line.company_id')
                    ->on('journal.plant_id', '=', 'line.plant_id');
            })
            ->where('journal.company_id', $data['company_id'])
            ->where('journal.plant_id', $data['plant_id'])
            ->whereIn('journal.status', ['POSTED', 'REVERSED'])
            ->whereDate('journal.posting_date', '<=', $cutoff->toDateString())
            ->groupBy('line.account_id')
            ->get([
                'line.account_id', DB::raw('SUM(line.debit_amount) as debit'), DB::raw('SUM(line.credit_amount) as credit'),
            ])->keyBy('account_id');
        $rows = DB::table('chart_accounts')->where('company_id', $data['company_id'])
            ->orderBy('account_code')->get()->map(function (object $account) use ($amounts): array {
                $amount = $amounts->get($account->id);
                $debit = $this->decimal($amount?->debit);
                $credit = $this->decimal($amount?->credit);

                return [
                    'account_code' => $account->account_code,
                    'account_name' => $account->name,
                    'account_type' => $account->account_type,
                    'currency' => 'INR',
                    'debit' => $debit,
                    'credit' => $credit,
                    'balance' => bcsub($debit, $credit, 6),
                ];
            })->filter(fn (array $row): bool => $parameters['include_zero'] || bccomp($row['debit'], $row['credit'], 6) !== 0)->values()->all();
        $totalDebit = $this->sum($rows, 'debit');
        $totalCredit = $this->sum($rows, 'credit');
        $freshness = DB::table('journals')->where($this->scope($data))->whereIn('status', ['POSTED', 'REVERSED'])
            ->whereDate('posting_date', '<=', $cutoff->toDateString())->max('updated_at');

        return [$rows, [
            'currency' => 'INR', 'debit' => $totalDebit, 'credit' => $totalCredit,
            'balance' => bcsub($totalDebit, $totalCredit, 6),
        ], $freshness];
    }

    private function receivableAging(CarbonImmutable $cutoff, array $parameters, array $data): array
    {
        $query = DB::table('sales_invoice_financials as invoice')
            ->join('parties as customer', function ($join): void {
                $join->on('customer.id', '=', 'invoice.party_id')->on('customer.company_id', '=', 'invoice.company_id');
            })
            ->where('invoice.company_id', $data['company_id'])
            ->where('invoice.plant_id', $data['plant_id'])
            ->where('invoice.issued_at', '<=', $cutoff->utc());
        if (! $parameters['include_settled']) {
            $query->where('invoice.outstanding_amount', '>', 0);
        }
        $rows = $query->orderBy('invoice.due_date')->orderBy('invoice.invoice_number')->get([
            'invoice.invoice_number', 'invoice.issued_at', 'invoice.due_date', 'invoice.currency',
            'invoice.gross_amount', 'invoice.paid_amount', 'invoice.credited_amount', 'invoice.outstanding_amount',
            'customer.code as customer_code', 'customer.display_name as customer_name',
        ])->map(function (object $invoice) use ($cutoff): array {
            $due = CarbonImmutable::parse($invoice->due_date, $cutoff->timezone)->startOfDay();
            $days = max(0, (int) floor(($cutoff->startOfDay()->getTimestamp() - $due->getTimestamp()) / 86400));

            return [
                'invoice_number' => $invoice->invoice_number,
                'customer_code' => $invoice->customer_code,
                'customer_name' => $invoice->customer_name,
                'issued_on' => CarbonImmutable::parse($invoice->issued_at)->toDateString(),
                'due_on' => $due->toDateString(),
                'aging_bucket' => $this->agingBucket($days, bccomp((string) $invoice->outstanding_amount, '0', 4) <= 0),
                'currency' => $invoice->currency,
                'gross_amount' => $this->decimal($invoice->gross_amount, 4),
                'paid_amount' => $this->decimal($invoice->paid_amount, 4),
                'credited_amount' => $this->decimal($invoice->credited_amount, 4),
                'outstanding_amount' => $this->decimal($invoice->outstanding_amount, 4),
            ];
        })->all();
        $freshness = DB::table('sales_invoice_financials')->where($this->scope($data))
            ->where('issued_at', '<=', $cutoff->utc())->max('updated_at');

        return [$rows, [
            'currency' => 'INR', 'gross_amount' => $this->sum($rows, 'gross_amount', 4),
            'paid_amount' => $this->sum($rows, 'paid_amount', 4),
            'credited_amount' => $this->sum($rows, 'credited_amount', 4),
            'outstanding_amount' => $this->sum($rows, 'outstanding_amount', 4),
        ], $freshness];
    }

    private function inventoryAvailability(CarbonImmutable $cutoff, array $parameters, array $data): array
    {
        $query = DB::table('stock_positions as position')
            ->join('items as item', function ($join): void {
                $join->on('item.id', '=', 'position.item_id')->on('item.company_id', '=', 'position.company_id');
            })
            ->join('lots as lot', function ($join): void {
                $join->on('lot.id', '=', 'position.lot_id')->on('lot.company_id', '=', 'position.company_id');
            })
            ->join('inventory_owners as owner', function ($join): void {
                $join->on('owner.id', '=', 'position.inventory_owner_id')->on('owner.company_id', '=', 'position.company_id');
            })
            ->join('locations as location', function ($join): void {
                $join->on('location.id', '=', 'position.location_id')
                    ->on('location.company_id', '=', 'position.company_id')->on('location.plant_id', '=', 'position.plant_id');
            })
            ->join('inventory_quality_statuses as quality', 'quality.code', '=', 'position.quality_status')
            ->where('position.company_id', $data['company_id'])->where('position.plant_id', $data['plant_id']);
        $this->visibility->applyInventoryAttribution($query, 'position.lot_id', $data['permissions']);
        if (! $parameters['include_zero']) {
            $query->where('position.quantity_base', '>', 0);
        }
        $rows = $query->orderBy('item.code')->orderBy('lot.expiry_date')->orderBy('location.code')->get([
            'item.code as item_code', 'item.name as item_name', 'lot.internal_lot_code as lot_code',
            'lot.expiry_date', 'owner.code as owner_code', 'location.code as location_code',
            'position.quality_status', 'position.uom_code', 'position.quantity_base',
            'position.reserved_quantity_base', 'quality.is_reservable',
        ])->map(function (object $position) use ($cutoff): array {
            $onHand = $this->decimal($position->quantity_base);
            $reserved = $this->decimal($position->reserved_quantity_base);
            $difference = bcsub($onHand, $reserved, 6);
            $available = $position->is_reservable && bccomp($difference, '0', 6) > 0
                ? $difference
                : '0.000000';

            return [
                'item_code' => $position->item_code,
                'item_name' => $position->item_name,
                'lot_code' => $position->lot_code,
                'owner_code' => $position->owner_code,
                'location_code' => $position->location_code,
                'quality_status' => $position->quality_status,
                'expiry_date' => $position->expiry_date,
                'expiry_risk' => $this->expiryRisk($position->expiry_date, $cutoff),
                'uom_code' => $position->uom_code,
                'on_hand' => $onHand,
                'reserved' => $reserved,
                'available' => $this->decimal($available),
            ];
        })->all();
        $freshness = (clone $query)->max('position.updated_at');

        return [$rows, [
            'on_hand' => $this->sum($rows, 'on_hand'), 'reserved' => $this->sum($rows, 'reserved'),
            'available' => $this->sum($rows, 'available'), 'position_count' => count($rows),
        ], $freshness];
    }

    private function orderFulfilment(CarbonImmutable $cutoff, array $parameters, array $data): array
    {
        $lineTotals = DB::table('sales_order_lines')->where($this->scope($data))->groupBy('sales_order_id')->get([
            'sales_order_id', DB::raw('SUM(ordered_quantity) as ordered_quantity'),
            DB::raw('SUM(allocated_quantity) as allocated_quantity'),
            DB::raw('SUM(dispatched_quantity) as dispatched_quantity'),
            DB::raw('SUM(invoiced_quantity) as invoiced_quantity'),
        ])->keyBy('sales_order_id');
        $query = DB::table('sales_orders as sales_order')
            ->join('parties as customer', function ($join): void {
                $join->on('customer.id', '=', 'sales_order.customer_party_id')
                    ->on('customer.company_id', '=', 'sales_order.company_id');
            })
            ->where('sales_order.company_id', $data['company_id'])->where('sales_order.plant_id', $data['plant_id'])
            ->where('sales_order.order_type', 'SALES')->whereDate('sales_order.order_date', '<=', $cutoff->toDateString());
        $this->visibility->apply($query, 'SALES_ORDER', 'sales_order.id', $data['permissions'], null, 'report_order_visibility', false);
        if (! $parameters['include_closed']) {
            $query->whereNotIn('sales_order.status', ['COMPLETED', 'CANCELLED']);
        }
        $rows = $query->orderBy('sales_order.requested_delivery_date')->orderBy('sales_order.order_number')->get([
            'sales_order.id', 'sales_order.order_number', 'sales_order.order_date', 'sales_order.requested_delivery_date',
            'sales_order.status', 'sales_order.currency', 'sales_order.total_amount',
            'customer.code as customer_code', 'customer.display_name as customer_name',
        ])->map(function (object $order) use ($lineTotals): array {
            $totals = $lineTotals->get($order->id);
            $ordered = $this->decimal($totals?->ordered_quantity);
            $dispatched = $this->decimal($totals?->dispatched_quantity);
            $percent = bccomp($ordered, '0', 6) > 0
                ? bcdiv(bcmul($dispatched, '100', 10), $ordered, 2)
                : '0.00';

            return [
                'order_number' => $order->order_number,
                'customer_code' => $order->customer_code,
                'customer_name' => $order->customer_name,
                'order_date' => $order->order_date,
                'required_by' => $order->requested_delivery_date,
                'status' => $order->status,
                'currency' => $order->currency,
                'order_value' => $this->decimal($order->total_amount),
                'ordered_quantity' => $ordered,
                'allocated_quantity' => $this->decimal($totals?->allocated_quantity),
                'dispatched_quantity' => $dispatched,
                'invoiced_quantity' => $this->decimal($totals?->invoiced_quantity),
                'fulfilment_percent' => $percent,
            ];
        })->all();
        $freshness = (clone $query)->max('sales_order.updated_at');

        return [$rows, [
            'currency' => 'INR', 'order_value' => $this->sum($rows, 'order_value'),
            'ordered_quantity' => $this->sum($rows, 'ordered_quantity'),
            'dispatched_quantity' => $this->sum($rows, 'dispatched_quantity'),
            'invoiced_quantity' => $this->sum($rows, 'invoiced_quantity'),
        ], $freshness];
    }

    private function parameters(array $definition, array $supplied): array
    {
        $allowed = array_column($definition['parameters'], 'key');
        $unexpected = array_values(array_diff(array_keys($supplied), $allowed));
        if ($unexpected !== []) {
            throw ValidationException::withMessages([
                'parameters' => ['Unsupported parameter(s) for this report: '.implode(', ', $unexpected).'.'],
            ]);
        }

        $result = [];
        foreach ($definition['parameters'] as $parameter) {
            $result[$parameter['key']] = (bool) ($supplied[$parameter['key']] ?? $parameter['default']);
        }

        return $result;
    }

    private function groupKey(string $code, array $row): ?string
    {
        return match ($code) {
            'TRIAL_BALANCE' => $row['account_type'],
            'RECEIVABLE_AGING' => $row['aging_bucket'],
            'INVENTORY_AVAILABILITY' => $row['quality_status'],
            'ORDER_FULFILMENT' => $row['status'],
            default => null,
        };
    }

    private function agingBucket(int $days, bool $settled): string
    {
        if ($settled) return 'SETTLED';
        if ($days <= 0) return 'CURRENT';
        if ($days <= 30) return '1-30';
        if ($days <= 60) return '31-60';
        if ($days <= 90) return '61-90';

        return '90+';
    }

    private function expiryRisk(?string $date, CarbonImmutable $cutoff): string
    {
        if (! $date) return 'UNDATED';
        $expiry = CarbonImmutable::parse($date, $cutoff->timezone)->startOfDay();
        $days = (int) floor(($expiry->getTimestamp() - $cutoff->startOfDay()->getTimestamp()) / 86400);
        if ($days < 0) return 'EXPIRED';
        if ($days <= 30) return 'CRITICAL';
        if ($days <= 90) return 'NEAR_TERM';

        return 'HEALTHY';
    }

    private function csv(array $columns, array $rows, string $viewLabel): string
    {
        $lines = [
            $this->csvCell('View').','.$this->csvCell($viewLabel),
            '',
            implode(',', array_map(fn (array $column): string => $this->csvCell($column['label']), $columns)),
        ];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(
                fn (array $column): string => $this->csvCell($row[$column['key']] ?? null),
                $columns,
            ));
        }

        return implode("\r\n", $lines)."\r\n";
    }

    private function csvCell(mixed $value): string
    {
        if (is_bool($value)) $value = $value ? 'true' : 'false';
        if (is_array($value)) $value = $this->canonicalJson($value);

        return '"'.str_replace('"', '""', (string) ($value ?? '')).'"';
    }

    private function jsonExport(object $run, array $columns, array $rows): string
    {
        return $this->canonicalJson([
            'metadata' => [
                'run_number' => $run->run_number,
                'report_code' => $run->report_code,
                'report_title' => $run->report_title,
                'view_classification' => $run->view_classification,
                'view_label' => $this->viewLabel($run->view_classification),
                'includes_restricted_entries' => (bool) $run->includes_restricted_entries,
                'as_of_at' => $run->as_of_at,
                'source_freshness_at' => $run->source_freshness_at,
                'parameters' => $this->json($run->parameters_json),
                'row_count' => (int) $run->row_count,
                'snapshot_sha256' => $run->sha256,
            ],
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $this->json($run->totals_json),
        ])."\n";
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->normalise($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function normalise(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (! array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->normalise($item);

        return $value;
    }

    private function json(mixed $value): array
    {
        if (is_array($value)) return $value;

        return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }

    private function sum(array $rows, string $key, int $scale = 6): string
    {
        return array_reduce(
            $rows,
            fn (string $sum, array $row): string => bcadd($sum, (string) ($row[$key] ?? 0), $scale),
            bcadd('0', '0', $scale),
        );
    }

    private function decimal(mixed $value, int $scale = 6): string
    {
        return bcadd((string) ($value ?? 0), '0', $scale);
    }

    private function scope(array $data): array
    {
        return ['company_id' => $data['company_id'], 'plant_id' => $data['plant_id']];
    }

    private function viewClassification(string $reportCode, array $permissions): string
    {
        if (in_array($reportCode, ['TRIAL_BALANCE', 'RECEIVABLE_AGING'], true)) return 'AUTHORITATIVE';

        return in_array(TransactionVisibilityPolicy::SHOW_PERMISSION, $permissions, true)
            ? 'FULL_OPERATIONAL'
            : 'PERMISSION_FILTERED_OPERATIONAL';
    }

    private function assertRunAccessible(object $run, array $permissions): void
    {
        if (($run->view_classification ?? 'AUTHORITATIVE') === 'FULL_OPERATIONAL'
            && ! in_array(TransactionVisibilityPolicy::SHOW_PERMISSION, $permissions, true)) {
            throw new NotFoundHttpException('Report run not found.');
        }
    }

    private function viewLabel(string $classification): string
    {
        return match ($classification) {
            'PERMISSION_FILTERED_OPERATIONAL' => 'Permission-Filtered Operational View',
            'FULL_OPERATIONAL' => 'Full Operational View',
            default => 'Authoritative Financial View',
        };
    }

    private function begin(string $namespace, array $data): ?array
    {
        return $this->idempotency->begin(
            $namespace,
            $data['idempotency_key'],
            Arr::except($data, ['actor_id', 'permissions', 'idempotency_key', 'correlation_id']),
        );
    }

    private function complete(string $namespace, array $data, array $result): void
    {
        $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
    }

    private function record(
        string $command,
        string $event,
        string $entity,
        string $id,
        array $data,
        int $version,
        array $diff,
        array $result,
    ): void {
        $this->audit->record($command, $entity, $id, $data['actor_id'], $data['company_id'], $data['plant_id'], 'SUCCESS', [
            'entity_version' => $version,
            'correlation_id' => $data['correlation_id'] ?? null,
            'safe_diff' => $diff,
        ]);
        $this->outbox->append(
            $event, $entity, $id, $id.':'.$version, $result + $this->scope($data),
            $data['correlation_id'] ?? null, $data['company_id'], $data['plant_id'],
        );
    }
}
