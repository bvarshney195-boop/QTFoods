<?php

namespace App\Modules\Sales\Application;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UnsoldSalesReturnReferenceValidator
{
    public function validateCreate(array $data): void
    {
        $errors = [];

        $partyExists = DB::table('parties')
            ->where('id', $data['party_id'])
            ->where('company_id', $data['company_id'])
            ->where('status', 'ACTIVE')
            ->whereExists(fn ($role) => $role->from('party_roles as party_role')
                ->whereColumn('party_role.party_id', 'parties.id')
                ->where('party_role.role_code', 'CUSTOMER'))
            ->exists();

        if (! $partyExists) {
            $errors['party_id'][] = 'Select an active customer in the current company.';
        }

        $shipment = DB::table('shipments')
            ->where('id', $data['shipment_id'])
            ->where('company_id', $data['company_id'])
            ->where('plant_id', $data['plant_id'])
            ->where('party_id', $data['party_id'])
            ->whereNotIn('status', ['DRAFT', 'CANCELLED'])
            ->first(['id']);

        if (! $shipment) {
            $errors['shipment_id'][] = 'Select an eligible shipment for this customer and plant.';
        }

        if (isset($data['sales_order_id'])) {
            $salesOrderExists = DB::table('sales_orders')
                ->where('id', $data['sales_order_id'])
                ->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])
                ->whereNotIn('status', ['DRAFT', 'CANCELLED'])
                ->exists();

            if (! $salesOrderExists) {
                $errors['sales_order_id'][] = 'Select an eligible sales order in the current plant.';
            }
        }

        if (isset($data['invoice_id'])) {
            $invoiceExists = DB::table('sales_invoice_financials as financial')
                ->join('invoices as invoice', function (JoinClause $join) {
                    $join
                        ->on('invoice.id', '=', 'financial.invoice_id')
                        ->on('invoice.company_id', '=', 'financial.company_id')
                        ->on('invoice.plant_id', '=', 'financial.plant_id');
                })
                ->where('financial.invoice_id', $data['invoice_id'])
                ->where('financial.company_id', $data['company_id'])
                ->where('financial.plant_id', $data['plant_id'])
                ->where('financial.party_id', $data['party_id'])
                ->where('financial.shipment_id', $data['shipment_id'])
                ->whereIn('invoice.status', ['POSTED', 'PAID'])
                ->exists();

            if (! $invoiceExists) {
                $errors['invoice_id'][] = 'Select an eligible invoice for this customer and shipment.';
            }
        }

        $requestedByShipmentLine = [];
        foreach ($data['lines'] as $index => $lineInput) {
            $lineId = $lineInput['shipment_line_id'];
            $requestedByShipmentLine[$lineId] = bcadd(
                $requestedByShipmentLine[$lineId] ?? '0',
                (string) $lineInput['requested_quantity'],
                6
            );

            $shipmentLine = DB::table('shipment_lines')
                ->where('id', $lineId)
                ->where('shipment_id', $data['shipment_id'])
                ->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])
                ->first(['item_id', 'fg_lot_id', 'shipped_quantity', 'returned_quantity', 'uom_code']);

            if (! $shipment || ! $shipmentLine) {
                $errors["lines.{$index}.shipment_line_id"][] = 'Select a line from the eligible shipment.';
                continue;
            }

            if ((string) $shipmentLine->item_id !== $lineInput['sku_id']) {
                $errors["lines.{$index}.sku_id"][] = 'The SKU must match the selected shipment line.';
            }

            if ((string) ($shipmentLine->fg_lot_id ?? '') !== (string) ($lineInput['fg_lot_id'] ?? '')) {
                $errors["lines.{$index}.fg_lot_id"][] = 'The finished-goods lot must match the selected shipment line.';
            }

            if ($shipmentLine->uom_code !== $lineInput['uom_code']) {
                $errors["lines.{$index}.uom_code"][] = 'The UOM must match the selected shipment line.';
            }

            $skuExists = DB::table('items')
                ->where('id', $lineInput['sku_id'])
                ->where('company_id', $data['company_id'])
                ->where('item_type', 'FINISHED_GOOD')
                ->where('status', 'ACTIVE')
                ->exists();
            if (! $skuExists) {
                $errors["lines.{$index}.sku_id"][] = 'Select an active finished-goods SKU in the current company.';
            }

            if (isset($lineInput['fg_lot_id'])) {
                $lotExists = DB::table('lots')
                    ->where('id', $lineInput['fg_lot_id'])
                    ->where('company_id', $data['company_id'])
                    ->where('item_id', $lineInput['sku_id'])
                    ->exists();
                if (! $lotExists) {
                    $errors["lines.{$index}.fg_lot_id"][] = 'Select a finished-goods lot for the chosen SKU.';
                }
            }

            $available = bcsub(
                (string) $shipmentLine->shipped_quantity,
                (string) $shipmentLine->returned_quantity,
                6
            );
            if (bccomp($requestedByShipmentLine[$lineId], $available, 6) > 0) {
                $errors["lines.{$index}.requested_quantity"][] = 'Requested return quantity exceeds the shipment line quantity available to return.';
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
