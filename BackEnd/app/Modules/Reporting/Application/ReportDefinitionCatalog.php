<?php

namespace App\Modules\Reporting\Application;

final class ReportDefinitionCatalog
{
    public const CODES = [
        'TRIAL_BALANCE',
        'RECEIVABLE_AGING',
        'INVENTORY_AVAILABILITY',
        'ORDER_FULFILMENT',
    ];

    public static function all(): array
    {
        return [
            'TRIAL_BALANCE' => [
                'code' => 'TRIAL_BALANCE',
                'title' => 'Trial balance',
                'description' => 'Posted debit, credit, and signed balance by chart account through the selected cutoff.',
                'freshness_source' => 'Posted general-ledger journals',
                'historical_cutoff' => true,
                'parameters' => [
                    ['key' => 'include_zero', 'label' => 'Include zero-balance accounts', 'type' => 'BOOLEAN', 'default' => false],
                ],
                'columns' => [
                    ['key' => 'account_code', 'label' => 'Account', 'type' => 'TEXT'],
                    ['key' => 'account_name', 'label' => 'Account name', 'type' => 'TEXT'],
                    ['key' => 'account_type', 'label' => 'Type', 'type' => 'TEXT'],
                    ['key' => 'currency', 'label' => 'Currency', 'type' => 'TEXT'],
                    ['key' => 'debit', 'label' => 'Debit', 'type' => 'MONEY'],
                    ['key' => 'credit', 'label' => 'Credit', 'type' => 'MONEY'],
                    ['key' => 'balance', 'label' => 'Balance', 'type' => 'MONEY'],
                ],
            ],
            'RECEIVABLE_AGING' => [
                'code' => 'RECEIVABLE_AGING',
                'title' => 'Receivable aging',
                'description' => 'Customer invoice exposure and aging buckets for invoices issued through the selected cutoff.',
                'freshness_source' => 'Receivable invoice and settlement balances',
                'historical_cutoff' => true,
                'parameters' => [
                    ['key' => 'include_settled', 'label' => 'Include settled invoices', 'type' => 'BOOLEAN', 'default' => false],
                ],
                'columns' => [
                    ['key' => 'invoice_number', 'label' => 'Invoice', 'type' => 'TEXT'],
                    ['key' => 'customer_code', 'label' => 'Customer', 'type' => 'TEXT'],
                    ['key' => 'customer_name', 'label' => 'Customer name', 'type' => 'TEXT'],
                    ['key' => 'issued_on', 'label' => 'Issued', 'type' => 'DATE'],
                    ['key' => 'due_on', 'label' => 'Due', 'type' => 'DATE'],
                    ['key' => 'aging_bucket', 'label' => 'Aging', 'type' => 'TEXT'],
                    ['key' => 'currency', 'label' => 'Currency', 'type' => 'TEXT'],
                    ['key' => 'gross_amount', 'label' => 'Gross', 'type' => 'MONEY'],
                    ['key' => 'paid_amount', 'label' => 'Paid', 'type' => 'MONEY'],
                    ['key' => 'credited_amount', 'label' => 'Credited', 'type' => 'MONEY'],
                    ['key' => 'outstanding_amount', 'label' => 'Outstanding', 'type' => 'MONEY'],
                ],
            ],
            'INVENTORY_AVAILABILITY' => [
                'code' => 'INVENTORY_AVAILABILITY',
                'title' => 'Inventory availability',
                'description' => 'Current on-hand, reserved, and quality-derived available stock by item, lot, owner, and location.',
                'freshness_source' => 'Current stock-position ledger projection',
                'historical_cutoff' => false,
                'parameters' => [
                    ['key' => 'include_zero', 'label' => 'Include zero-quantity positions', 'type' => 'BOOLEAN', 'default' => false],
                ],
                'columns' => [
                    ['key' => 'item_code', 'label' => 'Item', 'type' => 'TEXT'],
                    ['key' => 'item_name', 'label' => 'Item name', 'type' => 'TEXT'],
                    ['key' => 'lot_code', 'label' => 'Lot', 'type' => 'TEXT'],
                    ['key' => 'owner_code', 'label' => 'Owner', 'type' => 'TEXT'],
                    ['key' => 'location_code', 'label' => 'Location', 'type' => 'TEXT'],
                    ['key' => 'quality_status', 'label' => 'Quality', 'type' => 'TEXT'],
                    ['key' => 'expiry_date', 'label' => 'Expiry', 'type' => 'DATE'],
                    ['key' => 'expiry_risk', 'label' => 'Expiry risk', 'type' => 'TEXT'],
                    ['key' => 'uom_code', 'label' => 'UOM', 'type' => 'TEXT'],
                    ['key' => 'on_hand', 'label' => 'On hand', 'type' => 'QUANTITY'],
                    ['key' => 'reserved', 'label' => 'Reserved', 'type' => 'QUANTITY'],
                    ['key' => 'available', 'label' => 'Available', 'type' => 'QUANTITY'],
                ],
            ],
            'ORDER_FULFILMENT' => [
                'code' => 'ORDER_FULFILMENT',
                'title' => 'Order fulfilment',
                'description' => 'Sales-order value and quantity progress through allocation, dispatch, and invoicing.',
                'freshness_source' => 'Sales-order headers and lines',
                'historical_cutoff' => true,
                'parameters' => [
                    ['key' => 'include_closed', 'label' => 'Include completed or cancelled orders', 'type' => 'BOOLEAN', 'default' => false],
                ],
                'columns' => [
                    ['key' => 'order_number', 'label' => 'Order', 'type' => 'TEXT'],
                    ['key' => 'customer_code', 'label' => 'Customer', 'type' => 'TEXT'],
                    ['key' => 'customer_name', 'label' => 'Customer name', 'type' => 'TEXT'],
                    ['key' => 'order_date', 'label' => 'Ordered', 'type' => 'DATE'],
                    ['key' => 'required_by', 'label' => 'Required by', 'type' => 'DATE'],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'TEXT'],
                    ['key' => 'currency', 'label' => 'Currency', 'type' => 'TEXT'],
                    ['key' => 'order_value', 'label' => 'Order value', 'type' => 'MONEY'],
                    ['key' => 'ordered_quantity', 'label' => 'Ordered qty', 'type' => 'QUANTITY'],
                    ['key' => 'allocated_quantity', 'label' => 'Allocated qty', 'type' => 'QUANTITY'],
                    ['key' => 'dispatched_quantity', 'label' => 'Dispatched qty', 'type' => 'QUANTITY'],
                    ['key' => 'invoiced_quantity', 'label' => 'Invoiced qty', 'type' => 'QUANTITY'],
                    ['key' => 'fulfilment_percent', 'label' => 'Fulfilment %', 'type' => 'PERCENT'],
                ],
            ],
        ];
    }

    public static function get(string $code): array
    {
        return self::all()[$code];
    }
}
