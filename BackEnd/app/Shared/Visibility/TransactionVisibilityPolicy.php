<?php

namespace App\Shared\Visibility;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TransactionVisibilityPolicy
{
    public const RESTRICT_PERMISSION = 'DidntShowCheckbox';
    public const SHOW_PERMISSION = 'ShowEntries';

    /**
     * Restrictable transaction roots. Related ledgers are deliberately absent:
     * visibility metadata never changes authoritative accounting or stock rows.
     *
     * @var array<string, array{table:string,module:string,screen:string,edit_permissions:list<string>,conditions?:array<string,string>}>
     */
    private const DEFINITIONS = [
        'PURCHASE_ORDER' => ['table' => 'purchase_orders', 'module' => 'PROCUREMENT', 'screen' => 'PUR-PO', 'edit_permissions' => ['ACTION:PUR-PO:CREATE', 'ACTION:PUR-PO:AMEND']],
        'GATE_ENTRY' => ['table' => 'gate_entries', 'module' => 'PROCUREMENT', 'screen' => 'INB-GATE', 'edit_permissions' => ['ACTION:INB-GATE:CREATE', 'ACTION:INB-GATE:UPDATE']],
        'GOODS_RECEIPT' => ['table' => 'receipts', 'module' => 'PROCUREMENT', 'screen' => 'INB-GRN', 'edit_permissions' => ['ACTION:INB-GRN:CREATE', 'ACTION:INB-GRN:UPDATE']],
        'INCOMING_QUALITY_TASK' => ['table' => 'quality_tasks', 'module' => 'QUALITY', 'screen' => 'QC-IN', 'edit_permissions' => ['ACTION:QC-IN:COMPLETE']],
        'SUPPLIER_RETURN' => ['table' => 'supplier_returns', 'module' => 'PROCUREMENT', 'screen' => 'INB-RETURN', 'edit_permissions' => ['ACTION:INB-RETURN:CREATE', 'ACTION:INB-RETURN:UPDATE']],
        'INVENTORY_OPERATION' => ['table' => 'inventory_operations', 'module' => 'INVENTORY', 'screen' => 'INV-STK', 'edit_permissions' => []],
        'SALES_ORDER' => ['table' => 'sales_orders', 'module' => 'SALES', 'screen' => 'CRM-ORDER', 'edit_permissions' => ['ACTION:CRM-ORDER:CREATE', 'ACTION:CRM-ORDER:UPDATE', 'ACTION:CRM-ORDER:AMEND'], 'conditions' => ['order_type' => 'SALES']],
        'SHIPMENT' => ['table' => 'shipments', 'module' => 'DISPATCH', 'screen' => 'DSP-LOAD', 'edit_permissions' => ['ACTION:DSP-LOAD:CREATE', 'ACTION:DSP-LOAD:LOAD', 'ACTION:DSP-LOAD:DISPATCH'], 'conditions' => ['shipment_type' => 'SALES']],
        'CUSTOMER_CLAIM' => ['table' => 'customer_claims', 'module' => 'SALES', 'screen' => 'RET-CASE', 'edit_permissions' => ['ACTION:RET-CASE:CREATE', 'ACTION:RET-CASE:RECEIVE', 'ACTION:RET-CASE:RESOLVE']],
        'UNSOLD_RETURN' => ['table' => 'unsold_return_cases', 'module' => 'SALES', 'screen' => 'RET-UNSOLD', 'edit_permissions' => ['ACTION:RET-UNSOLD:CREATE', 'ACTION:RET-UNSOLD:RECEIVE', 'ACTION:RET-UNSOLD:DISPOSITION']],
        'PAYABLE_INVOICE' => ['table' => 'invoices', 'module' => 'FINANCE', 'screen' => 'FIN-AP', 'edit_permissions' => ['ACTION:FIN-AP:INVOICE-CREATE', 'ACTION:FIN-AP:INVOICE-UPDATE'], 'conditions' => ['invoice_type' => 'PAYABLE']],
        'EXPENSE' => ['table' => 'expense_claims', 'module' => 'FINANCE', 'screen' => 'FIN-EXP', 'edit_permissions' => ['ACTION:FIN-EXP:CREATE', 'ACTION:FIN-EXP:UPDATE']],
    ];

    public function normaliseType(string $transactionType): string
    {
        return strtoupper(str_replace('-', '_', trim($transactionType)));
    }

    public function definition(string $transactionType): array
    {
        $type = $this->normaliseType($transactionType);
        $definition = self::DEFINITIONS[$type] ?? null;
        if (! $definition) {
            throw ValidationException::withMessages([
                'transaction_type' => ['That transaction type does not support operational visibility controls.'],
            ]);
        }

        return $definition + ['transaction_type' => $type];
    }

    public function scopedRecord(
        string $transactionType,
        string $transactionId,
        array $scope,
        bool $lock = false,
    ): object {
        $definition = $this->definition($transactionType);
        $query = DB::table($definition['table'])
            ->where('id', $transactionId)
            ->where('company_id', $scope['company_id']);

        if (array_key_exists('plant_id', $scope) && $scope['plant_id'] !== null) {
            $query->where('plant_id', $scope['plant_id']);
        }
        foreach ($definition['conditions'] ?? [] as $column => $value) {
            $query->where($column, $value);
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        $record = $query->first();
        if (! $record) {
            throw new NotFoundHttpException('Transaction not found in the selected company and plant.');
        }

        return $record;
    }

    public function assertCanView(array $permissions, string $transactionType, string $visibilityStatus): void
    {
        $definition = $this->definition($transactionType);
        if (! in_array("SCREEN:{$definition['screen']}:VIEW", $permissions, true)) {
            throw new AuthorizationException('You are not authorised to view this transaction type.');
        }
        if ($visibilityStatus === 'RESTRICTED' && ! $this->canShowRestricted($permissions)) {
            throw new NotFoundHttpException('Transaction not found.');
        }
    }

    public function assertOperationallyVisible(
        array $permissions,
        string $transactionType,
        string $transactionId,
        array $scope,
    ): void {
        $type = $this->normaliseType($transactionType);
        $derivedSources = $this->derivedSources($type, $transactionId, $scope);
        if ($derivedSources !== null) {
            if (! $this->canShowRestricted($permissions)) {
                foreach ($derivedSources as [$sourceType, $sourceId]) {
                    if ($sourceId !== '' && $this->isRestricted($sourceType, $sourceId)) {
                        throw new NotFoundHttpException('Transaction not found.');
                    }
                }
            }

            return;
        }

        // The unsold-return workflow also uses legacy shipments. Those rows are
        // valid relationship sources, but remain ineligible for changing their
        // own visibility because scopedRecord() still enforces shipment_type
        // when the visibility endpoint is used.
        $record = $type === 'SHIPMENT'
            ? $this->scopedShipmentRecord($transactionId, $scope)
            : $this->scopedRecord($type, $transactionId, $scope);
        if ($this->canShowRestricted($permissions)) {
            return;
        }

        $sources = [[$type, $transactionId]];
        if ($type === 'GATE_ENTRY') {
            $sources[] = ['PURCHASE_ORDER', (string) $record->purchase_order_id];
        } elseif ($type === 'GOODS_RECEIPT') {
            $sources[] = ['GATE_ENTRY', (string) $record->gate_entry_id];
            $sources[] = ['PURCHASE_ORDER', (string) $record->purchase_order_id];
        } elseif ($type === 'INCOMING_QUALITY_TASK') {
            $sources = [...$sources, ...$this->receiptSources((string) $record->receipt_id)];
        } elseif ($type === 'SUPPLIER_RETURN') {
            $sources = [...$sources, ...$this->supplierReturnSources($transactionId)];
        } elseif ($type === 'SHIPMENT') {
            $sources[] = ['SALES_ORDER', (string) $record->sales_order_id];
        } elseif ($type === 'CUSTOMER_CLAIM') {
            $sources = [...$sources, ...$this->shipmentSources((string) $record->shipment_id)];
        } elseif ($type === 'UNSOLD_RETURN') {
            $sources[] = ['SHIPMENT', (string) $record->shipment_id];
            if ($record->sales_order_id) $sources[] = ['SALES_ORDER', (string) $record->sales_order_id];
        } elseif ($type === 'PAYABLE_INVOICE') {
            $sources[] = ['PURCHASE_ORDER', (string) $record->purchase_order_id];
        }

        foreach ($sources as [$sourceType, $sourceId]) {
            if ($sourceId !== '' && $this->isRestricted($sourceType, $sourceId)) {
                throw new NotFoundHttpException('Transaction not found.');
            }
        }
    }

    public function assertCanChange(array $permissions, string $transactionType, string $currentStatus): void
    {
        if (! in_array(self::RESTRICT_PERMISSION, $permissions, true)) {
            throw new AuthorizationException('You are not authorised to change transaction visibility.');
        }

        $definition = $this->definition($transactionType);
        $editPermissions = $this->editPermissions($definition, $transactionType);
        if (! collect($editPermissions)->contains(fn (string $permission): bool => in_array($permission, $permissions, true))) {
            throw new AuthorizationException('Normal edit access is required before transaction visibility can be changed.');
        }

        // Once a user without ShowEntries hides a transaction it disappears from
        // every normal API. Recovery is intentionally limited to a user holding both permissions.
        if ($currentStatus === 'RESTRICTED' && ! $this->canShowRestricted($permissions)) {
            throw new NotFoundHttpException('Transaction not found.');
        }
    }

    public function canShowRestricted(array $permissions): bool
    {
        return in_array(self::SHOW_PERMISSION, $permissions, true);
    }

    private function editPermissions(array $definition, string $transactionType): array
    {
        if ($this->normaliseType($transactionType) !== 'INVENTORY_OPERATION') {
            return $definition['edit_permissions'];
        }

        return [
            'ACTION:INV-ISS:CREATE',
            'ACTION:INV-ISS:UPDATE',
            'ACTION:INV-TRF:CREATE',
            'ACTION:INV-TRF:UPDATE',
            'ACTION:INV-COUNT:CREATE',
            'ACTION:INV-COUNT:UPDATE',
            'ACTION:INV-EXP:CREATE',
            'ACTION:INV-EXP:UPDATE',
        ];
    }

    private function receiptSources(string $receiptId): array
    {
        $receipt = DB::table('receipts')->where('id', $receiptId)->first(['id', 'gate_entry_id', 'purchase_order_id']);
        if (! $receipt) return [];

        return [
            ['GOODS_RECEIPT', (string) $receipt->id],
            ['GATE_ENTRY', (string) $receipt->gate_entry_id],
            ['PURCHASE_ORDER', (string) $receipt->purchase_order_id],
        ];
    }

    private function shipmentSources(string $shipmentId): array
    {
        $shipment = DB::table('shipments')->where('id', $shipmentId)->first(['id', 'sales_order_id']);
        if (! $shipment) return [];

        return [
            ['SHIPMENT', (string) $shipment->id],
            ['SALES_ORDER', (string) $shipment->sales_order_id],
        ];
    }

    private function scopedShipmentRecord(string $shipmentId, array $scope): object
    {
        $query = DB::table('shipments')
            ->where('id', $shipmentId)
            ->where('company_id', $scope['company_id']);

        if (array_key_exists('plant_id', $scope) && $scope['plant_id'] !== null) {
            $query->where('plant_id', $scope['plant_id']);
        }

        $record = $query->first();
        if (! $record) {
            throw new NotFoundHttpException('Transaction not found in the selected company and plant.');
        }

        return $record;
    }

    private function supplierReturnSources(string $supplierReturnId): array
    {
        $rows = DB::table('supplier_return_lines as return_line')
            ->join('incoming_quality_lines as quality_line', 'quality_line.id', '=', 'return_line.incoming_quality_line_id')
            ->join('quality_tasks as task', 'task.id', '=', 'quality_line.quality_task_id')
            ->join('receipts as receipt', 'receipt.id', '=', 'quality_line.receipt_id')
            ->where('return_line.supplier_return_id', $supplierReturnId)
            ->get(['task.id as task_id', 'receipt.id as receipt_id', 'receipt.gate_entry_id', 'receipt.purchase_order_id']);
        $sources = [];
        foreach ($rows as $row) {
            $sources[] = ['INCOMING_QUALITY_TASK', (string) $row->task_id];
            $sources[] = ['GOODS_RECEIPT', (string) $row->receipt_id];
            $sources[] = ['GATE_ENTRY', (string) $row->gate_entry_id];
            $sources[] = ['PURCHASE_ORDER', (string) $row->purchase_order_id];
        }

        return $sources;
    }

    private function isRestricted(string $transactionType, string $transactionId): bool
    {
        return DB::table('transaction_visibilities')
            ->where('transaction_type', $transactionType)
            ->where('transaction_id', $transactionId)
            ->where('visibility_status', 'RESTRICTED')
            ->exists();
    }

    /**
     * Operational children are not independently restrictable. They inherit
     * access only from their explicit commercial roots.
     *
     * @return list<array{string,string}>|null
     */
    private function derivedSources(string $type, string $id, array $scope): ?array
    {
        if ($type === 'THIRD_PARTY_WORK') {
            $record = $this->derivedRecord('third_party_work_orders', $id, $scope, ['sales_order_id']);

            return $record->sales_order_id ? [['SALES_ORDER', (string) $record->sales_order_id]] : [];
        }
        if ($type === 'SALES_ALLOCATION') {
            $record = $this->derivedRecord('sales_allocations', $id, $scope, ['sales_order_id']);

            return [['SALES_ORDER', (string) $record->sales_order_id]];
        }
        if ($type === 'PAYMENT_PROPOSAL') {
            $this->derivedRecord('payment_proposals', $id, $scope, ['id']);

            return DB::table('payment_proposal_lines as line')
                ->join('invoices as invoice', 'invoice.id', '=', 'line.invoice_id')
                ->where('line.payment_proposal_id', $id)
                ->get(['invoice.id', 'invoice.purchase_order_id'])
                ->flatMap(fn (object $invoice): array => [
                    ['PAYABLE_INVOICE', (string) $invoice->id],
                    ['PURCHASE_ORDER', (string) $invoice->purchase_order_id],
                ])->all();
        }
        if ($type === 'SUPPLIER_PAYMENT') {
            $payment = $this->derivedRecord('supplier_payments', $id, $scope, ['payment_proposal_id']);

            return $this->derivedSources('PAYMENT_PROPOSAL', (string) $payment->payment_proposal_id, $scope);
        }
        if ($type === 'RECEIVABLE_INVOICE') {
            $invoice = DB::table('sales_invoice_financials')->where('invoice_id', $id)
                ->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])
                ->first(['shipment_id', 'sales_order_id']);
            if (! $invoice) throw new NotFoundHttpException('Transaction not found.');

            return [
                ['SHIPMENT', (string) $invoice->shipment_id],
                ['SALES_ORDER', (string) $invoice->sales_order_id],
            ];
        }
        if ($type === 'UNSOLD_RETURN_APPROVAL') {
            $approval = $this->derivedRecord('approval_requests', $id, $scope, ['entity_type', 'entity_id'], false);
            if (strtoupper((string) $approval->entity_type) !== 'UNSOLD_RETURN_LOSS') {
                throw new NotFoundHttpException('Transaction not found.');
            }

            return [['UNSOLD_RETURN', (string) $approval->entity_id]];
        }

        return null;
    }

    private function derivedRecord(
        string $table,
        string $id,
        array $scope,
        array $columns,
        bool $plantRequired = true,
    ): object {
        $query = DB::table($table)->where('id', $id)->where('company_id', $scope['company_id']);
        if ($plantRequired || ($scope['plant_id'] ?? null) !== null) {
            $query->where('plant_id', $scope['plant_id']);
        }
        $record = $query->first($columns);
        if (! $record) throw new NotFoundHttpException('Transaction not found.');

        return $record;
    }
}
