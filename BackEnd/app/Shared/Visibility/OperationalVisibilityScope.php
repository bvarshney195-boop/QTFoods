<?php

namespace App\Shared\Visibility;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

final class OperationalVisibilityScope
{
    public const FILTERS = ['ALL', 'NORMAL', 'RESTRICTED'];

    private const SCREEN_TRANSACTION_TYPES = [
        'PUR-PO' => 'PURCHASE_ORDER',
        'INB-GATE' => 'GATE_ENTRY',
        'INB-GRN' => 'GOODS_RECEIPT',
        'QC-IN' => 'INCOMING_QUALITY_TASK',
        'INB-RETURN' => 'SUPPLIER_RETURN',
        'INV-ISS' => 'INVENTORY_OPERATION',
        'INV-TRF' => 'INVENTORY_OPERATION',
        'INV-COUNT' => 'INVENTORY_OPERATION',
        'INV-EXP' => 'INVENTORY_OPERATION',
        'CRM-ORDER' => 'SALES_ORDER',
        'DSP-LOAD' => 'SHIPMENT',
        'DSP-POD' => 'SHIPMENT',
        'RET-CASE' => 'CUSTOMER_CLAIM',
        'RET-UNSOLD' => 'UNSOLD_RETURN',
        'FIN-AP' => 'PAYABLE_INVOICE',
        'FIN-EXP' => 'EXPENSE',
    ];

    public function __construct(private readonly TransactionVisibilityPolicy $policy) {}

    public function apply(
        Builder $query,
        string $transactionType,
        string $qualifiedId,
        array $permissions,
        ?string $requestedFilter = null,
        ?string $alias = null,
        bool $selectMetadata = true,
    ): Builder {
        $type = $this->policy->normaliseType($transactionType);
        $alias ??= 'visibility_'.strtolower($type);
        $query->leftJoin("transaction_visibilities as {$alias}", function (JoinClause $join) use ($alias, $qualifiedId, $type): void {
            $join->on("{$alias}.transaction_id", '=', $qualifiedId)
                ->where("{$alias}.transaction_type", '=', $type);
        });

        $filter = strtoupper((string) ($requestedFilter ?: 'ALL'));
        $filter = in_array($filter, self::FILTERS, true) ? $filter : 'ALL';
        $showRestricted = $this->policy->canShowRestricted($permissions);
        if (! $showRestricted || $filter === 'NORMAL') {
            $query->where(fn (Builder $nested) => $nested->whereNull("{$alias}.id")
                ->orWhere("{$alias}.visibility_status", 'NORMAL'));
        } elseif ($filter === 'RESTRICTED') {
            $query->where("{$alias}.visibility_status", 'RESTRICTED');
        }

        if ($selectMetadata) {
            $query->addSelect([
                "{$alias}.visibility_status as _visibility_status",
                "{$alias}.record_version as _visibility_version",
                "{$alias}.restricted_by as _restricted_by",
                "{$alias}.restricted_at as _restricted_at",
                "{$alias}.restriction_reason as _restriction_reason",
            ]);
        }

        return $query;
    }

    public function applyPolymorphicSource(
        Builder $query,
        string $sourceTypeColumn,
        string $sourceIdColumn,
        array $sourceTypes,
        array $permissions,
    ): Builder {
        if ($this->policy->canShowRestricted($permissions)) {
            return $query;
        }

        return $query->whereNotExists(function (Builder $visibility) use (
            $sourceTypeColumn,
            $sourceIdColumn,
            $sourceTypes,
        ): void {
            $visibility->from('transaction_visibilities as source_visibility')
                ->whereColumn('source_visibility.transaction_id', $sourceIdColumn)
                ->where('source_visibility.visibility_status', 'RESTRICTED')
                ->where(function (Builder $mapping) use ($sourceTypeColumn, $sourceTypes): void {
                    foreach ($sourceTypes as $sourceType => $transactionType) {
                        $mapping->orWhere(function (Builder $pair) use ($sourceTypeColumn, $sourceType, $transactionType): void {
                            $pair->where($sourceTypeColumn, $sourceType)
                                ->where('source_visibility.transaction_type', $transactionType);
                        });
                    }
                });
        });
    }

    public function applyWorkItemTargets(
        Builder $query,
        string $targetScreenColumn,
        string $targetIdColumn,
        array $permissions,
    ): Builder {
        if ($this->policy->canShowRestricted($permissions)) {
            return $query;
        }

        return $query->whereNotExists(function (Builder $visibility) use ($targetScreenColumn, $targetIdColumn): void {
            $visibility->from('transaction_visibilities as work_visibility')
                ->whereColumn('work_visibility.transaction_id', $targetIdColumn)
                ->where('work_visibility.visibility_status', 'RESTRICTED')
                ->where(function (Builder $mapping) use ($targetScreenColumn): void {
                    foreach (self::SCREEN_TRANSACTION_TYPES as $screen => $transactionType) {
                        $mapping->orWhere(function (Builder $pair) use ($targetScreenColumn, $screen, $transactionType): void {
                            $pair->where($targetScreenColumn, $screen)
                                ->where('work_visibility.transaction_type', $transactionType);
                        });
                    }
                });
        });
    }

    public function applyStockMovementSources(Builder $query, array $permissions): Builder
    {
        $this->applyPolymorphicSource($query, 'movement.source_type', 'movement.source_id', [
            'INVENTORY_OPERATION' => 'INVENTORY_OPERATION',
            'sales_shipment' => 'SHIPMENT',
            'customer_claim' => 'CUSTOMER_CLAIM',
            'UNSOLD_RETURN' => 'UNSOLD_RETURN',
        ], $permissions);
        if ($this->policy->canShowRestricted($permissions)) return $query;

        $query->whereNotExists(function (Builder $hidden): void {
            $hidden->from('receipt_lines as movement_receipt_line')
                ->join('receipts as movement_receipt', 'movement_receipt.id', '=', 'movement_receipt_line.receipt_id')
                ->where('movement.source_type', 'goods_receipt_line')
                ->whereColumn('movement_receipt_line.id', 'movement.source_id')
                ->where(function (Builder $restricted): void {
                    $restricted->whereExists(fn (Builder $visibility) => $visibility
                        ->from('transaction_visibilities as movement_receipt_visibility')
                        ->whereColumn('movement_receipt_visibility.transaction_id', 'movement_receipt.id')
                        ->where('movement_receipt_visibility.transaction_type', 'GOODS_RECEIPT')
                        ->where('movement_receipt_visibility.visibility_status', 'RESTRICTED'))
                        ->orWhereExists(fn (Builder $visibility) => $visibility
                            ->from('transaction_visibilities as movement_receipt_po_visibility')
                            ->whereColumn('movement_receipt_po_visibility.transaction_id', 'movement_receipt.purchase_order_id')
                            ->where('movement_receipt_po_visibility.transaction_type', 'PURCHASE_ORDER')
                            ->where('movement_receipt_po_visibility.visibility_status', 'RESTRICTED'));
                });
        })->whereNotExists(function (Builder $hidden): void {
            $hidden->from('incoming_quality_lines as movement_quality_line')
                ->join('receipts as movement_quality_receipt', 'movement_quality_receipt.id', '=', 'movement_quality_line.receipt_id')
                ->where('movement.source_type', 'incoming_quality_line')
                ->whereColumn('movement_quality_line.id', 'movement.source_id')
                ->where(function (Builder $restricted): void {
                    foreach ([
                        ['INCOMING_QUALITY_TASK', 'movement_quality_line.quality_task_id'],
                        ['GOODS_RECEIPT', 'movement_quality_receipt.id'],
                        ['PURCHASE_ORDER', 'movement_quality_receipt.purchase_order_id'],
                    ] as [$type, $column]) {
                        $restricted->orWhereExists(fn (Builder $visibility) => $visibility
                            ->from('transaction_visibilities as movement_quality_source_visibility')
                            ->whereColumn('movement_quality_source_visibility.transaction_id', $column)
                            ->where('movement_quality_source_visibility.transaction_type', $type)
                            ->where('movement_quality_source_visibility.visibility_status', 'RESTRICTED'));
                    }
                });
        })->whereNotExists(function (Builder $hidden): void {
            $hidden->from('supplier_return_lines as movement_return_line')
                ->join('incoming_quality_lines as movement_return_quality_line', 'movement_return_quality_line.id', '=', 'movement_return_line.incoming_quality_line_id')
                ->join('receipts as movement_return_receipt', 'movement_return_receipt.id', '=', 'movement_return_quality_line.receipt_id')
                ->where('movement.source_type', 'supplier_return_line')
                ->whereColumn('movement_return_line.id', 'movement.source_id')
                ->where(function (Builder $restricted): void {
                    foreach ([
                        ['SUPPLIER_RETURN', 'movement_return_line.supplier_return_id'],
                        ['INCOMING_QUALITY_TASK', 'movement_return_quality_line.quality_task_id'],
                        ['GOODS_RECEIPT', 'movement_return_receipt.id'],
                        ['PURCHASE_ORDER', 'movement_return_receipt.purchase_order_id'],
                    ] as [$type, $column]) {
                        $restricted->orWhereExists(fn (Builder $visibility) => $visibility
                            ->from('transaction_visibilities as movement_return_source_visibility')
                            ->whereColumn('movement_return_source_visibility.transaction_id', $column)
                            ->where('movement_return_source_visibility.transaction_type', $type)
                            ->where('movement_return_source_visibility.visibility_status', 'RESTRICTED'));
                    }
                });
        });

        return $query;
    }

    /**
     * Removes only fully traceable on-hand lots whose receipt sources are all restricted.
     * Mixed-source lots stay authoritative and are separately reported as a traceability limitation.
     */
    public function applyInventoryAttribution(Builder $query, string $qualifiedLotId, array $permissions): Builder
    {
        if ($this->policy->canShowRestricted($permissions)) {
            return $query;
        }

        return $query->where(function (Builder $attribution) use ($qualifiedLotId): void {
            $attribution->whereNotExists(fn (Builder $restricted) => $this->receiptSource(
                $restricted,
                $qualifiedLotId,
                true,
                'restricted_receipt_source',
            ))->orWhereExists(fn (Builder $normal) => $this->receiptSource(
                $normal,
                $qualifiedLotId,
                false,
                'normal_receipt_source',
            ));
        });
    }

    public function applyMixedInventoryAttribution(Builder $query, string $qualifiedLotId): Builder
    {
        return $query
            ->whereExists(fn (Builder $restricted) => $this->receiptSource(
                $restricted,
                $qualifiedLotId,
                true,
                'mixed_restricted_source',
            ))
            ->whereExists(fn (Builder $normal) => $this->receiptSource(
                $normal,
                $qualifiedLotId,
                false,
                'mixed_normal_source',
            ));
    }

    public function excludeRestrictedSupplierReturnSources(
        Builder $query,
        string $qualifiedReturnId,
        array $permissions,
    ): Builder {
        if ($this->policy->canShowRestricted($permissions)) {
            return $query;
        }

        return $query->whereNotExists(function (Builder $source) use ($qualifiedReturnId): void {
            $source->from('supplier_return_lines as visibility_return_line')
                ->join('incoming_quality_lines as visibility_quality_line', 'visibility_quality_line.id', '=', 'visibility_return_line.incoming_quality_line_id')
                ->join('quality_tasks as visibility_quality_task', 'visibility_quality_task.id', '=', 'visibility_quality_line.quality_task_id')
                ->join('receipts as visibility_receipt', 'visibility_receipt.id', '=', 'visibility_quality_line.receipt_id')
                ->whereColumn('visibility_return_line.supplier_return_id', $qualifiedReturnId)
                ->where(function (Builder $restricted): void {
                    foreach ([
                        ['INCOMING_QUALITY_TASK', 'visibility_quality_task.id'],
                        ['GOODS_RECEIPT', 'visibility_receipt.id'],
                        ['GATE_ENTRY', 'visibility_receipt.gate_entry_id'],
                        ['PURCHASE_ORDER', 'visibility_receipt.purchase_order_id'],
                    ] as [$type, $column]) {
                        $restricted->orWhereExists(fn (Builder $visibility) => $visibility
                            ->from('transaction_visibilities as supplier_return_source_visibility')
                            ->whereColumn('supplier_return_source_visibility.transaction_id', $column)
                            ->where('supplier_return_source_visibility.transaction_type', $type)
                            ->where('supplier_return_source_visibility.visibility_status', 'RESTRICTED'));
                    }
                });
        });
    }

    public function metadata(object $row): array
    {
        $status = (string) ($row->_visibility_status ?? 'NORMAL');

        return [
            'status' => $status,
            'is_restricted' => $status === 'RESTRICTED',
            'record_version' => isset($row->_visibility_version) ? (int) $row->_visibility_version : 0,
            'restricted_by' => $row->_restricted_by ?? null,
            'restricted_at' => $row->_restricted_at ?? null,
            'restriction_reason' => $row->_restriction_reason ?? null,
        ];
    }

    public function viewDescriptor(array $permissions): array
    {
        $full = $this->policy->canShowRestricted($permissions);

        return [
            'label' => $full ? 'Full Operational View' : 'Permission-Filtered Operational View',
            'includes_restricted_entries' => $full,
            'permission_filtered' => ! $full,
        ];
    }

    public function filterOptions(array $permissions): array
    {
        return $this->policy->canShowRestricted($permissions) ? self::FILTERS : ['NORMAL'];
    }

    private function receiptSource(
        Builder $query,
        string $qualifiedLotId,
        bool $restricted,
        string $prefix,
    ): void {
        $line = "{$prefix}_line";
        $receipt = "{$prefix}_receipt";
        $visibility = "{$prefix}_visibility";
        $query->from("receipt_lines as {$line}")
            ->join("receipts as {$receipt}", "{$receipt}.id", '=', "{$line}.receipt_id")
            ->whereColumn("{$line}.lot_id", $qualifiedLotId)
            ->whereNotNull("{$receipt}.receipt_number");

        if ($restricted) {
            $query->where(function (Builder $source) use ($receipt, $visibility): void {
                $source->whereExists(function (Builder $hidden) use ($receipt, $visibility): void {
                    $hidden->from("transaction_visibilities as {$visibility}_receipt")
                        ->whereColumn("{$visibility}_receipt.transaction_id", "{$receipt}.id")
                        ->where("{$visibility}_receipt.transaction_type", 'GOODS_RECEIPT')
                        ->where("{$visibility}_receipt.visibility_status", 'RESTRICTED');
                })->orWhereExists(function (Builder $hidden) use ($receipt, $visibility): void {
                    $hidden->from("transaction_visibilities as {$visibility}_po")
                        ->whereColumn("{$visibility}_po.transaction_id", "{$receipt}.purchase_order_id")
                        ->where("{$visibility}_po.transaction_type", 'PURCHASE_ORDER')
                        ->where("{$visibility}_po.visibility_status", 'RESTRICTED');
                });
            });
        } else {
            $query->whereNotExists(function (Builder $hidden) use ($receipt, $visibility): void {
                $hidden->from("transaction_visibilities as {$visibility}_receipt")
                    ->whereColumn("{$visibility}_receipt.transaction_id", "{$receipt}.id")
                    ->where("{$visibility}_receipt.transaction_type", 'GOODS_RECEIPT')
                    ->where("{$visibility}_receipt.visibility_status", 'RESTRICTED');
            })->whereNotExists(function (Builder $hidden) use ($receipt, $visibility): void {
                $hidden->from("transaction_visibilities as {$visibility}_po")
                    ->whereColumn("{$visibility}_po.transaction_id", "{$receipt}.purchase_order_id")
                    ->where("{$visibility}_po.transaction_type", 'PURCHASE_ORDER')
                    ->where("{$visibility}_po.visibility_status", 'RESTRICTED');
            });
        }
    }
}
