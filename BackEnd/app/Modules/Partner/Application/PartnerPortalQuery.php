<?php

namespace App\Modules\Partner\Application;

use App\Modules\Sales\Application\OrderToCashService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PartnerPortalQuery
{
    public function __construct(private readonly PartnerAccessResolver $access) {}

    public function workspace(array $scope, string $actorId, array $permissions, array $filters = []): array
    {
        $internal = $this->access->isInternal($permissions);
        $grant = $internal ? null : $this->access->requireGrant($scope, $actorId);
        $entitlements = $internal ? [] : $grant->entitlements;
        $partyId = $internal ? ($filters['party_id'] ?? null) : (string) $grant->party_id;

        $grants = $internal ? $this->grants($scope, $permissions, $filters) : [];
        $documents = ($internal || in_array('DOCUMENTS_VIEW', $entitlements, true))
            ? $this->documents($scope, $partyId, $internal, $entitlements, $permissions, $filters)
            : [];
        $orders = $partyId !== null && ($internal || in_array('ORDERS_VIEW', $entitlements, true))
            ? $this->orders($scope, $partyId, $filters)
            : [];
        $shipments = $partyId !== null && ($internal || in_array('SHIPMENTS_VIEW', $entitlements, true))
            ? $this->shipments($scope, $partyId, $filters)
            : [];
        $invoices = $partyId !== null && ($internal || in_array('INVOICES_VIEW', $entitlements, true))
            ? $this->invoices($scope, $partyId, $filters)
            : [];
        $claims = $partyId !== null && ($internal || in_array('CLAIMS_VIEW', $entitlements, true))
            ? $this->claims($scope, $partyId, $filters)
            : [];

        return [
            'data' => $internal ? $grants : $documents,
            'mode' => $internal ? 'INTERNAL' : 'PARTNER',
            'identity' => $internal ? [
                'user_id' => $actorId,
                'access_boundary' => 'INTERNAL_PORTAL_ADMINISTRATION',
            ] : [
                'user_id' => $actorId,
                'grant_id' => (string) $grant->id,
                'party_id' => (string) $grant->party_id,
                'party_code' => (string) $grant->party_code,
                'party_name' => (string) $grant->party_name,
                'access_boundary' => 'EXACT_PARTY_TENANT',
            ],
            'entitlements' => $entitlements,
            'access_grants' => $grants,
            'documents' => $documents,
            'orders' => $orders,
            'shipments' => $shipments,
            'invoices' => $invoices,
            'claims' => $claims,
            'summary' => $internal
                ? [
                    'active_grants' => collect($grants)->where('status', 'ACTIVE')->count(),
                    'partner_tenants' => collect($grants)->where('status', 'ACTIVE')->pluck('party_id')->unique()->count(),
                    'available_documents' => collect($documents)->where('status', 'AVAILABLE')->count(),
                    'awaiting_acknowledgement' => collect($documents)->where('direction', 'OUTBOUND')->where('status', 'AVAILABLE')->count(),
                ]
                : [
                    'orders' => count($orders),
                    'shipments' => count($shipments),
                    'open_invoices' => collect($invoices)->where('outstanding_amount', '>', 0)->count(),
                    'open_claims' => collect($claims)->whereIn('status', ['OPEN', 'RETURN_REQUIRED', 'RECEIVED'])->count(),
                    'available_documents' => collect($documents)->where('status', 'AVAILABLE')->count(),
                ],
            'lookups' => $this->lookups($scope, $partyId, $internal),
            'allowed_actions' => $this->workspaceActions($internal, $entitlements, $permissions),
            'scope' => $scope + ['party_id' => $partyId],
            'meta' => [
                'total' => $internal ? count($grants) : count($documents),
                'tenant_isolation' => $internal ? 'SCOPE_ADMINISTERED' : 'PARTY_ENFORCED',
            ],
        ];
    }

    public function detail(
        string $resource,
        string $id,
        array $scope,
        string $actorId,
        array $permissions,
    ): array {
        [$internal, $partyId, $entitlements] = $this->viewer($scope, $actorId, $permissions);

        return match ($resource) {
            'grant' => $this->grantDetail($id, $scope, $permissions, $internal),
            'order' => $this->orderDetail($id, $scope, $partyId, $internal, $entitlements),
            'shipment' => $this->shipmentDetail($id, $scope, $partyId, $internal, $entitlements),
            'invoice' => $this->invoiceDetail($id, $scope, $partyId, $internal, $entitlements),
            'claim' => $this->claimDetail($id, $scope, $partyId, $internal, $entitlements),
            'document' => $this->documentDetail($id, $scope, $partyId, $internal, $entitlements, $permissions),
            default => throw new NotFoundHttpException('Partner portal resource not found.'),
        };
    }

    public function downloadableDocument(
        string $id,
        array $scope,
        string $actorId,
        array $permissions,
    ): object {
        [$internal, $partyId, $entitlements] = $this->viewer($scope, $actorId, $permissions);
        if (! $this->access->hasPermission($permissions, 'DOCUMENT-DOWNLOAD')) {
            throw new AccessDeniedHttpException('Document download permission is required.');
        }
        if (! $internal && (! in_array('DOCUMENTS_VIEW', $entitlements, true) || ! in_array('DOCUMENT_DOWNLOAD', $entitlements, true))) {
            throw new AccessDeniedHttpException('The partner access grant does not include document download.');
        }
        $query = DB::table('partner_documents')
            ->where('id', $id)
            ->where('company_id', $scope['company_id'])
            ->where('plant_id', $scope['plant_id']);
        if (! $internal) {
            $query->where('party_id', $partyId)->where('status', '<>', 'WITHDRAWN');
        }
        $document = $query->first();
        if (! $document) {
            throw new NotFoundHttpException('Partner document not found.');
        }

        return $document;
    }

    private function grants(array $scope, array $permissions, array $filters): array
    {
        return DB::table('partner_access_grants as grant')
            ->join('users as portal_user', 'portal_user.id', '=', 'grant.user_id')
            ->join('parties as party', 'party.id', '=', 'grant.party_id')
            ->join('role_assignments as assignment', 'assignment.id', '=', 'grant.role_assignment_id')
            ->where('grant.company_id', $scope['company_id'])
            ->where('grant.plant_id', $scope['plant_id'])
            ->when($filters['party_id'] ?? null, fn (Builder $query, string $partyId) => $query->where('grant.party_id', $partyId))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('grant.status', $status))
            ->when($filters['q'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('party.display_name', 'like', '%'.$search.'%')
                        ->orWhere('party.code', 'like', '%'.$search.'%')
                        ->orWhere('portal_user.email', 'like', '%'.$search.'%')
                        ->orWhere('portal_user.name', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('grant.updated_at')
            ->limit(200)
            ->get([
                'grant.*',
                'portal_user.name as user_name',
                'portal_user.email as user_email',
                'party.code as party_code',
                'party.display_name as party_name',
                'assignment.is_active as assignment_active',
            ])
            ->map(function (object $row) use ($permissions): array {
                $payload = $this->row($row);
                $payload['entitlements'] = $this->access->entitlements((string) $row->id);
                $payload['effective_status'] = $this->effectiveStatus($row);
                $payload['allowed_actions'] = $row->status === 'ACTIVE' ? array_values(array_filter([
                    $this->access->hasPermission($permissions, 'ACCESS-UPDATE') ? 'UPDATE' : null,
                    $this->access->hasPermission($permissions, 'ACCESS-REVOKE') ? 'REVOKE' : null,
                ])) : [];

                return $payload;
            })->all();
    }

    private function documents(
        array $scope,
        ?string $partyId,
        bool $internal,
        array $entitlements,
        array $permissions,
        array $filters,
    ): array {
        return DB::table('partner_documents as document')
            ->join('parties as party', 'party.id', '=', 'document.party_id')
            ->join('users as creator', 'creator.id', '=', 'document.created_by')
            ->where('document.company_id', $scope['company_id'])
            ->where('document.plant_id', $scope['plant_id'])
            ->when($partyId !== null, fn (Builder $query) => $query->where('document.party_id', $partyId))
            ->when(! $internal, fn (Builder $query) => $query->where('document.status', '<>', 'WITHDRAWN'))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('document.status', $status))
            ->when($filters['direction'] ?? null, fn (Builder $query, string $direction) => $query->where('document.direction', $direction))
            ->when($filters['q'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('document.document_number', 'like', '%'.$search.'%')
                        ->orWhere('document.title', 'like', '%'.$search.'%')
                        ->orWhere('document.original_name', 'like', '%'.$search.'%')
                        ->orWhere('party.display_name', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('document.available_at')
            ->limit(200)
            ->get([
                'document.id', 'document.company_id', 'document.plant_id', 'document.party_id',
                'document.document_number', 'document.direction', 'document.document_type', 'document.title',
                'document.description', 'document.sales_order_id', 'document.shipment_id', 'document.invoice_id',
                'document.customer_claim_id', 'document.original_name', 'document.mime_type', 'document.size_bytes',
                'document.sha256_checksum', 'document.status', 'document.record_version', 'document.available_at',
                'document.acknowledged_at', 'document.acknowledgement_reference', 'document.withdrawn_at',
                'document.withdrawal_reason', 'document.created_at', 'document.updated_at',
                'party.code as party_code', 'party.display_name as party_name', 'creator.name as created_by_name',
            ])
            ->map(fn (object $row): array => $this->documentPayload($row, $internal, $entitlements, $permissions))
            ->all();
    }

    private function orders(array $scope, string $partyId, array $filters): array
    {
        return DB::table('sales_orders as sales_order')
            ->where('sales_order.company_id', $scope['company_id'])
            ->where('sales_order.plant_id', $scope['plant_id'])
            ->where('sales_order.customer_party_id', $partyId)
            ->where('sales_order.order_type', 'SALES')
            ->when($filters['q'] ?? null, fn (Builder $query, string $search) => $query->where('sales_order.order_number', 'like', '%'.$search.'%'))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('sales_order.status', $status))
            ->orderByDesc('sales_order.order_date')
            ->limit(150)
            ->get([
                'sales_order.id', 'sales_order.order_number', 'sales_order.order_date',
                'sales_order.requested_delivery_date', 'sales_order.currency', 'sales_order.total_amount',
                'sales_order.status', 'sales_order.record_version', 'sales_order.confirmed_at', 'sales_order.updated_at',
            ])->map(fn (object $row): array => $this->row($row))->all();
    }

    private function shipments(array $scope, string $partyId, array $filters): array
    {
        return DB::table('shipments as shipment')
            ->leftJoin('sales_orders as sales_order', 'sales_order.id', '=', 'shipment.sales_order_id')
            ->leftJoin('sales_invoice_financials as invoice', 'invoice.shipment_id', '=', 'shipment.id')
            ->where('shipment.company_id', $scope['company_id'])
            ->where('shipment.plant_id', $scope['plant_id'])
            ->where('shipment.party_id', $partyId)
            ->when($filters['q'] ?? null, function (Builder $query, string $search): void {
                $query->where(fn (Builder $nested) => $nested->where('shipment.shipment_number', 'like', '%'.$search.'%')
                    ->orWhere('sales_order.order_number', 'like', '%'.$search.'%')
                    ->orWhere('invoice.invoice_number', 'like', '%'.$search.'%'));
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('shipment.status', $status))
            ->orderByDesc('shipment.updated_at')
            ->limit(150)
            ->get([
                'shipment.id', 'shipment.shipment_number', 'shipment.shipment_type', 'shipment.sales_order_id',
                'shipment.carrier_name', 'shipment.vehicle_number', 'shipment.status', 'shipment.record_version',
                'shipment.dispatched_at', 'shipment.delivered_at', 'shipment.updated_at',
                'sales_order.order_number', 'invoice.invoice_id', 'invoice.invoice_number',
            ])->map(fn (object $row): array => $this->row($row))->all();
    }

    private function invoices(array $scope, string $partyId, array $filters): array
    {
        return DB::table('sales_invoice_financials as invoice')
            ->join('invoices as invoice_record', 'invoice_record.id', '=', 'invoice.invoice_id')
            ->leftJoin('shipments as shipment', 'shipment.id', '=', 'invoice.shipment_id')
            ->leftJoin('sales_orders as sales_order', 'sales_order.id', '=', 'invoice.sales_order_id')
            ->where('invoice.company_id', $scope['company_id'])
            ->where('invoice.plant_id', $scope['plant_id'])
            ->where('invoice.party_id', $partyId)
            ->when($filters['q'] ?? null, function (Builder $query, string $search): void {
                $query->where(fn (Builder $nested) => $nested->where('invoice.invoice_number', 'like', '%'.$search.'%')
                    ->orWhere('shipment.shipment_number', 'like', '%'.$search.'%')
                    ->orWhere('sales_order.order_number', 'like', '%'.$search.'%'));
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('invoice_record.status', $status))
            ->orderByDesc('invoice.issued_at')
            ->limit(150)
            ->get([
                'invoice.invoice_id as id', 'invoice.invoice_number', 'invoice.shipment_id', 'invoice.sales_order_id',
                'invoice.currency', 'invoice.net_amount', 'invoice.tax_amount', 'invoice.gross_amount',
                'invoice.outstanding_amount', 'invoice.paid_amount', 'invoice.credited_amount', 'invoice.issued_at',
                'invoice.due_date', 'invoice.record_version', 'invoice.updated_at', 'invoice_record.status',
                'shipment.shipment_number', 'sales_order.order_number',
            ])->map(fn (object $row): array => $this->moneyRow($row))->all();
    }

    private function claims(array $scope, string $partyId, array $filters): array
    {
        return DB::table('customer_claims as claim')
            ->join('shipments as shipment', 'shipment.id', '=', 'claim.shipment_id')
            ->leftJoin('sales_invoice_financials as invoice', 'invoice.invoice_id', '=', 'claim.invoice_id')
            ->where('claim.company_id', $scope['company_id'])
            ->where('claim.plant_id', $scope['plant_id'])
            ->where('claim.customer_party_id', $partyId)
            ->when($filters['q'] ?? null, function (Builder $query, string $search): void {
                $query->where(fn (Builder $nested) => $nested->where('claim.claim_number', 'like', '%'.$search.'%')
                    ->orWhere('shipment.shipment_number', 'like', '%'.$search.'%')
                    ->orWhere('invoice.invoice_number', 'like', '%'.$search.'%'));
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('claim.status', $status))
            ->orderByDesc('claim.updated_at')
            ->limit(150)
            ->get([
                'claim.id', 'claim.claim_number', 'claim.shipment_id', 'claim.invoice_id', 'claim.claim_type',
                'claim.requested_resolution', 'claim.reason', 'claim.status', 'claim.record_version',
                'claim.resolution_type', 'claim.credit_amount', 'claim.created_at', 'claim.updated_at',
                'shipment.shipment_number', 'invoice.invoice_number',
            ])->map(fn (object $row): array => $this->moneyRow($row))->all();
    }

    private function grantDetail(string $id, array $scope, array $permissions, bool $internal): array
    {
        if (! $internal) {
            throw new NotFoundHttpException('Partner access grant not found.');
        }
        $records = $this->grants($scope, $permissions, []);
        $grant = collect($records)->firstWhere('id', $id);
        if (! $grant) {
            throw new NotFoundHttpException('Partner access grant not found.');
        }

        return $grant;
    }

    private function orderDetail(string $id, array $scope, ?string $partyId, bool $internal, array $entitlements): array
    {
        $this->assertEntitled($internal, $entitlements, 'ORDERS_VIEW');
        $query = DB::table('sales_orders')->where('id', $id)->where($scope)->where('order_type', 'SALES');
        if (! $internal) {
            $query->where('customer_party_id', $partyId);
        }
        $row = $query->first();
        if (! $row) {
            throw new NotFoundHttpException('Sales order not found.');
        }
        $payload = $this->moneyRow($row);
        $payload['lines'] = DB::table('sales_order_lines as line')->join('items as item', 'item.id', '=', 'line.item_id')
            ->where('line.sales_order_id', $id)->orderBy('line.line_number')
            ->get(['line.*', 'item.code as item_code', 'item.name as item_name'])->map(fn (object $line): array => $this->moneyRow($line))->all();

        return $internal ? $payload : $this->externalOrderPayload($payload);
    }

    private function shipmentDetail(string $id, array $scope, ?string $partyId, bool $internal, array $entitlements): array
    {
        $this->assertEntitled($internal, $entitlements, 'SHIPMENTS_VIEW');
        $query = DB::table('shipments')->where('id', $id)->where($scope);
        if (! $internal) {
            $query->where('party_id', $partyId);
        }
        $row = $query->first();
        if (! $row) {
            throw new NotFoundHttpException('Shipment not found.');
        }
        $payload = $this->row($row);
        $payload['lines'] = DB::table('shipment_lines as line')->join('items as item', 'item.id', '=', 'line.item_id')
            ->leftJoin('lots as lot', 'lot.id', '=', 'line.fg_lot_id')->where('line.shipment_id', $id)
            ->orderBy('line.created_at')->get(['line.*', 'item.code as item_code', 'item.name as item_name', 'lot.internal_lot_code'])
            ->map(fn (object $line): array => $this->moneyRow($line))->all();
        $payload['proof'] = $this->rowOrNull(DB::table('delivery_proofs')->where('shipment_id', $id)->first());
        $payload['invoice'] = $this->rowOrNull(DB::table('sales_invoice_financials')->where('shipment_id', $id)->first(), true);

        return $internal ? $payload : $this->externalShipmentPayload($payload);
    }

    private function invoiceDetail(string $id, array $scope, ?string $partyId, bool $internal, array $entitlements): array
    {
        $this->assertEntitled($internal, $entitlements, 'INVOICES_VIEW');
        $query = DB::table('sales_invoice_financials as invoice')->join('invoices as invoice_record', 'invoice_record.id', '=', 'invoice.invoice_id')
            ->where('invoice.invoice_id', $id)->where('invoice.company_id', $scope['company_id'])->where('invoice.plant_id', $scope['plant_id']);
        if (! $internal) {
            $query->where('invoice.party_id', $partyId);
        }
        $row = $query->first(['invoice.*', 'invoice_record.status']);
        if (! $row) {
            throw new NotFoundHttpException('Invoice not found.');
        }
        $payload = $this->moneyRow($row);
        $payload['transactions'] = DB::table('receivable_transactions')->where('invoice_id', $id)->orderBy('posted_at')->get()
            ->map(fn (object $transaction): array => $this->moneyRow($transaction))->all();

        return $internal ? $payload : $this->externalInvoicePayload($payload);
    }

    private function claimDetail(string $id, array $scope, ?string $partyId, bool $internal, array $entitlements): array
    {
        $this->assertEntitled($internal, $entitlements, 'CLAIMS_VIEW');
        $query = DB::table('customer_claims')->where('id', $id)->where($scope);
        if (! $internal) {
            $query->where('customer_party_id', $partyId);
        }
        $row = $query->first();
        if (! $row) {
            throw new NotFoundHttpException('Customer claim not found.');
        }
        $payload = $this->moneyRow($row);
        $payload['lines'] = DB::table('customer_claim_lines as line')->join('items as item', 'item.id', '=', 'line.item_id')
            ->leftJoin('lots as lot', 'lot.id', '=', 'line.lot_id')->where('line.customer_claim_id', $id)
            ->orderBy('line.line_number')->get(['line.*', 'item.code as item_code', 'item.name as item_name', 'lot.internal_lot_code'])
            ->map(fn (object $line): array => $this->moneyRow($line))->all();

        return $internal ? $payload : $this->externalClaimPayload($payload);
    }

    private function documentDetail(
        string $id,
        array $scope,
        ?string $partyId,
        bool $internal,
        array $entitlements,
        array $permissions,
    ): array {
        $this->assertEntitled($internal, $entitlements, 'DOCUMENTS_VIEW');
        $query = DB::table('partner_documents as document')
            ->join('parties as party', 'party.id', '=', 'document.party_id')
            ->join('users as creator', 'creator.id', '=', 'document.created_by')
            ->where('document.id', $id)
            ->where('document.company_id', $scope['company_id'])
            ->where('document.plant_id', $scope['plant_id']);
        if (! $internal) {
            $query->where('document.party_id', $partyId)->where('document.status', '<>', 'WITHDRAWN');
        }
        $row = $query->first([
            'document.id', 'document.company_id', 'document.plant_id', 'document.party_id',
            'document.document_number', 'document.direction', 'document.document_type', 'document.title',
            'document.description', 'document.sales_order_id', 'document.shipment_id', 'document.invoice_id',
            'document.customer_claim_id', 'document.original_name', 'document.mime_type', 'document.size_bytes',
            'document.sha256_checksum', 'document.status', 'document.record_version', 'document.available_at',
            'document.acknowledged_at', 'document.acknowledgement_reference', 'document.withdrawn_at',
            'document.withdrawal_reason', 'document.created_at', 'document.updated_at',
            'party.code as party_code', 'party.display_name as party_name', 'creator.name as created_by_name',
        ]);
        if (! $row) {
            throw new NotFoundHttpException('Partner document not found.');
        }
        $payload = $this->documentPayload($row, $internal, $entitlements, $permissions);
        $payload['events'] = DB::table('partner_document_events')
            ->where('partner_document_id', $id)->orderBy('occurred_at')
            ->get(['id', 'event_type', 'reference', 'metadata_json', 'occurred_at'])
            ->map(fn (object $event): array => $this->row($event))->all();

        return $payload;
    }

    private function externalOrderPayload(array $payload): array
    {
        $safe = $this->only($payload, [
            'id', 'order_number', 'order_date', 'requested_delivery_date', 'currency', 'subtotal',
            'discount_amount', 'tax_amount', 'total_amount', 'status', 'confirmed_at', 'cancelled_at',
            'cancellation_reason', 'record_version', 'updated_at',
        ]);
        $safe['lines'] = collect($payload['lines'] ?? [])->map(fn (array $line): array => $this->only($line, [
            'id', 'line_number', 'item_code', 'item_name', 'uom_code', 'ordered_quantity',
            'allocated_quantity', 'dispatched_quantity', 'invoiced_quantity', 'unit_price',
            'discount_percent', 'tax_rate', 'net_amount', 'tax_amount', 'gross_amount',
        ]))->all();

        return $safe;
    }

    private function externalShipmentPayload(array $payload): array
    {
        $safe = $this->only($payload, [
            'id', 'shipment_number', 'sales_order_id', 'carrier_name', 'vehicle_number', 'driver_name',
            'status', 'record_version', 'dispatched_at', 'delivered_at', 'updated_at',
        ]);
        $safe['lines'] = collect($payload['lines'] ?? [])->map(fn (array $line): array => $this->only($line, [
            'id', 'line_number', 'item_code', 'item_name', 'internal_lot_code', 'uom_code',
            'shipped_quantity', 'returned_quantity',
        ]))->all();
        if (is_array($payload['proof'] ?? null)) {
            $safe['proof'] = $this->only($payload['proof'], [
                'proof_number', 'outcome', 'receiver_name', 'event_at', 'failure_reason',
            ]);
        }
        if (is_array($payload['invoice'] ?? null)) {
            $safe['invoice'] = $this->only($payload['invoice'], [
                'invoice_id', 'invoice_number', 'currency', 'net_amount', 'tax_amount', 'gross_amount',
                'outstanding_amount', 'paid_amount', 'credited_amount', 'issued_at', 'due_date',
            ]);
        }

        return $safe;
    }

    private function externalInvoicePayload(array $payload): array
    {
        $safe = $this->only($payload, [
            'id', 'invoice_number', 'shipment_id', 'sales_order_id', 'currency', 'net_amount',
            'tax_amount', 'gross_amount', 'outstanding_amount', 'paid_amount', 'credited_amount',
            'issued_at', 'due_date', 'status', 'record_version', 'updated_at',
        ]);
        $safe['transactions'] = collect($payload['transactions'] ?? [])->map(fn (array $transaction): array => $this->only($transaction, [
            'transaction_type', 'reference_number', 'amount', 'currency', 'posted_at',
        ]))->all();

        return $safe;
    }

    private function externalClaimPayload(array $payload): array
    {
        $safe = $this->only($payload, [
            'id', 'claim_number', 'shipment_id', 'invoice_id', 'claim_type', 'requested_resolution',
            'reason', 'status', 'resolution_type', 'credit_amount', 'record_version', 'created_at', 'updated_at',
        ]);
        $safe['lines'] = collect($payload['lines'] ?? [])->map(fn (array $line): array => $this->only($line, [
            'id', 'line_number', 'item_code', 'item_name', 'internal_lot_code', 'uom_code',
            'claimed_quantity', 'received_quantity',
        ]))->all();

        return $safe;
    }

    private function only(array $payload, array $keys): array
    {
        return array_intersect_key($payload, array_fill_keys($keys, true));
    }

    private function lookups(array $scope, ?string $partyId, bool $internal): array
    {
        $customers = DB::table('parties as party')->join('party_roles as party_role', function ($join): void {
            $join->on('party_role.party_id', '=', 'party.id')->where('party_role.role_code', 'CUSTOMER');
        })->where('party.company_id', $scope['company_id'])->where('party.status', 'ACTIVE')
            ->orderBy('party.display_name')->get(['party.id', 'party.code', 'party.display_name as name'])->map(fn (object $row): array => $this->row($row))->all();

        $lookups = [
            'document_types' => PartnerPortalService::DOCUMENT_TYPES,
            'entitlements' => PartnerPortalService::ENTITLEMENTS,
            'claim_types' => OrderToCashService::CLAIM_TYPES,
            'claim_resolutions' => OrderToCashService::CLAIM_RESOLUTIONS,
            'customers' => $customers,
        ];
        if ($internal) {
            $users = DB::table('users')->where('status', 'ACTIVE')->orderBy('name')->get(['id', 'name', 'email'])->map(function (object $user) use ($scope): array {
                $hasInternalRole = DB::table('role_assignments as assignment')->join('roles as role', 'role.id', '=', 'assignment.role_id')
                    ->where('assignment.user_id', $user->id)->where('assignment.is_active', true)->where('role.code', '<>', 'PARTNER_PORTAL')
                    ->where(fn ($query) => $query->whereNull('assignment.company_id')->orWhere('assignment.company_id', $scope['company_id']))
                    ->where(fn ($query) => $query->whereNull('assignment.plant_id')->orWhere('assignment.plant_id', $scope['plant_id']))->exists();
                return $this->row($user) + ['portal_eligible' => ! $hasInternalRole];
            })->all();
            $lookups['users'] = $users;
        }
        if ($partyId !== null) {
            $lookups['claimable_shipments'] = DB::table('shipments as shipment')
                ->where('shipment.company_id', $scope['company_id'])->where('shipment.plant_id', $scope['plant_id'])
                ->where('shipment.party_id', $partyId)->whereIn('shipment.status', ['DISPATCHED', 'DELIVERED'])
                ->orderByDesc('shipment.updated_at')->get(['shipment.id', 'shipment.shipment_number', 'shipment.status'])
                ->map(function (object $shipment): array {
                    $payload = $this->row($shipment);
                    $payload['lines'] = DB::table('shipment_lines as line')->join('items as item', 'item.id', '=', 'line.item_id')
                        ->where('line.shipment_id', $shipment->id)->orderBy('line.created_at')
                        ->get(['line.id', 'line.shipped_quantity', 'line.returned_quantity', 'line.uom_code', 'item.code as item_code', 'item.name as item_name'])
                        ->map(fn (object $line): array => $this->row($line))->all();
                    return $payload;
                })->all();
        }

        return $lookups;
    }

    private function workspaceActions(bool $internal, array $entitlements, array $permissions): array
    {
        $actions = [];
        if ($internal) {
            foreach (['ACCESS-GRANT', 'ACCESS-UPDATE', 'ACCESS-REVOKE', 'DOCUMENT-PUBLISH', 'DOCUMENT-DOWNLOAD', 'DOCUMENT-WITHDRAW'] as $action) {
                if ($this->access->hasPermission($permissions, $action)) {
                    $actions[] = $action;
                }
            }
            return $actions;
        }
        foreach ([
            'CLAIM-CREATE' => ['CLAIMS_CREATE'],
            'DOCUMENT-UPLOAD' => ['DOCUMENT_UPLOAD'],
            'DOCUMENT-DOWNLOAD' => ['DOCUMENT_DOWNLOAD'],
            'DOCUMENT-ACKNOWLEDGE' => ['DOCUMENT_ACKNOWLEDGE'],
        ] as $action => $required) {
            if ($this->access->hasPermission($permissions, $action) && array_diff($required, $entitlements) === []) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    private function documentPayload(object $row, bool $internal, array $entitlements, array $permissions): array
    {
        $payload = $this->row($row);
        $actions = [];
        if ($row->status !== 'WITHDRAWN' && $this->access->hasPermission($permissions, 'DOCUMENT-DOWNLOAD')
            && ($internal || in_array('DOCUMENT_DOWNLOAD', $entitlements, true))) {
            $actions[] = 'DOWNLOAD';
        }
        if ($internal && $row->direction === 'OUTBOUND' && $row->status === 'AVAILABLE'
            && $this->access->hasPermission($permissions, 'DOCUMENT-WITHDRAW')) {
            $actions[] = 'WITHDRAW';
        }
        if (! $internal && $row->direction === 'OUTBOUND' && $row->status === 'AVAILABLE'
            && in_array('DOCUMENT_ACKNOWLEDGE', $entitlements, true)
            && $this->access->hasPermission($permissions, 'DOCUMENT-ACKNOWLEDGE')) {
            $actions[] = 'ACKNOWLEDGE';
        }
        $payload['allowed_actions'] = $actions;

        return $payload;
    }

    private function viewer(array $scope, string $actorId, array $permissions): array
    {
        if ($this->access->isInternal($permissions)) {
            return [true, null, []];
        }
        $grant = $this->access->requireGrant($scope, $actorId);

        return [false, (string) $grant->party_id, $grant->entitlements];
    }

    private function assertEntitled(bool $internal, array $entitlements, string $entitlement): void
    {
        if (! $internal && ! in_array($entitlement, $entitlements, true)) {
            throw new NotFoundHttpException('Partner portal resource not found.');
        }
    }

    private function effectiveStatus(object $grant): string
    {
        if ($grant->status !== 'ACTIVE' || ! (bool) $grant->assignment_active) {
            return 'REVOKED';
        }
        $now = time();
        if ($grant->effective_from !== null && strtotime((string) $grant->effective_from) > $now) {
            return 'SCHEDULED';
        }
        if ($grant->effective_to !== null && strtotime((string) $grant->effective_to) <= $now) {
            return 'EXPIRED';
        }

        return 'ACTIVE';
    }

    private function rowOrNull(?object $row, bool $money = false): ?array
    {
        return $row === null ? null : ($money ? $this->moneyRow($row) : $this->row($row));
    }

    private function moneyRow(object $row): array
    {
        $payload = $this->row($row);
        foreach ($payload as $key => $value) {
            if (is_numeric($value) && (str_ends_with($key, '_amount') || str_ends_with($key, '_price'))) {
                $payload[$key] = bcadd((string) $value, '0', 4);
            }
        }

        return $payload;
    }

    private function row(object $row): array
    {
        $payload = (array) $row;
        foreach ($payload as $key => $value) {
            if (is_string($value) && str_ends_with($key, '_json')) {
                try {
                    $payload[$key] = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                }
            }
        }

        return $payload;
    }
}
