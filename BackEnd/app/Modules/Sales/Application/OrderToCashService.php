<?php

namespace App\Modules\Sales\Application;

use App\Modules\Inventory\Application\StockPostingService;
use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OrderToCashService
{
    public const LEAD_STATUSES = ['NEW', 'QUALIFIED', 'CONVERTED', 'WON', 'LOST'];
    public const LEAD_SOURCES = ['DIRECT', 'REFERRAL', 'CAMPAIGN', 'DISTRIBUTOR', 'OTHER'];
    public const PRICE_STATUSES = ['DRAFT', 'ACTIVE', 'RETIRED'];
    public const CONTRACT_STATUSES = ['DRAFT', 'ACTIVE', 'CLOSED', 'CANCELLED'];
    public const ORDER_STATUSES = ['DRAFT', 'CONFIRMED', 'ALLOCATED', 'PICKED', 'LOADED', 'DISPATCHED', 'DELIVERED', 'COMPLETED', 'CANCELLED'];
    public const WORK_STATUSES = ['DRAFT', 'RELEASED', 'COMPLETED', 'CANCELLED'];
    public const ALLOCATION_STATUSES = ['RESERVED', 'PICKED', 'DISPATCHED', 'CANCELLED'];
    public const SHIPMENT_STATUSES = ['DRAFT', 'LOADED', 'DISPATCHED', 'DELIVERED', 'FAILED', 'CANCELLED'];
    public const CLAIM_STATUSES = ['OPEN', 'RETURN_REQUIRED', 'RECEIVED', 'RESOLVED', 'REJECTED'];
    public const CLAIM_TYPES = ['DAMAGE', 'SHORTAGE', 'QUALITY', 'RETURN'];
    public const CLAIM_RESOLUTIONS = ['CREDIT', 'REPLACEMENT', 'RETURN_CREDIT', 'REJECT'];

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
        private readonly StockPostingService $stock,
    ) {}

    public function createLead(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'sales.lead.create';
            if ($replay = $this->begin($namespace, $data)) return $replay;
            $this->uniqueNumber('sales_leads', 'lead_number', $data['lead_number'], $data);
            $this->assertCustomer($data['customer_party_id'] ?? null, $data, true);
            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('sales_leads')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'lead_number' => $data['lead_number'], 'customer_party_id' => $data['customer_party_id'] ?? null,
                'company_name' => trim($data['company_name']), 'contact_name' => trim($data['contact_name']),
                'contact_email' => $this->nullable($data['contact_email'] ?? null),
                'contact_phone' => $this->nullable($data['contact_phone'] ?? null),
                'source' => $data['source'], 'enquiry_date' => $data['enquiry_date'],
                'expected_close_date' => $data['expected_close_date'] ?? null,
                'estimated_value' => $this->money($data['estimated_value'] ?? 0), 'currency' => 'INR',
                'status' => 'NEW', 'notes' => $this->nullable($data['notes'] ?? null), 'lost_reason' => null,
                'record_version' => 1, 'created_by' => $data['actor_id'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->leadEvent($id, 'CREATED', $data['notes'] ?? null, $data, $now);
            $result = $this->result('sales_lead', $id, 'NEW', 1);
            $this->record('CREATE_SALES_LEAD', 'sales.lead.created', 'sales_lead', $id, $data, 1, ['created' => ['lead_number' => $data['lead_number']]], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function updateLead(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'sales.lead.update.'.$id;
            if ($replay = $this->begin($namespace, $data + ['lead_id' => $id])) return $replay;
            $lead = $this->find('sales_leads', $id, $data, 'Sales lead', true);
            $this->assertVersion($lead, $data, 'sales lead');
            $this->assertStatus($lead, ['NEW', 'QUALIFIED'], 'Only an open lead can be edited.');
            $this->assertCustomer($data['customer_party_id'] ?? null, $data, true);
            $version = (int) $lead->record_version + 1;
            $values = [
                'customer_party_id' => $data['customer_party_id'] ?? null,
                'company_name' => trim($data['company_name']), 'contact_name' => trim($data['contact_name']),
                'contact_email' => $this->nullable($data['contact_email'] ?? null),
                'contact_phone' => $this->nullable($data['contact_phone'] ?? null),
                'source' => $data['source'], 'enquiry_date' => $data['enquiry_date'],
                'expected_close_date' => $data['expected_close_date'] ?? null,
                'estimated_value' => $this->money($data['estimated_value'] ?? 0),
                'notes' => $this->nullable($data['notes'] ?? null), 'record_version' => $version, 'updated_at' => now(),
            ];
            DB::table('sales_leads')->where('id', $id)->update($values);
            $this->leadEvent($id, 'UPDATED', $data['notes'] ?? null, $data);
            $result = $this->result('sales_lead', $id, $lead->status, $version);
            $this->record('UPDATE_SALES_LEAD', 'sales.lead.updated', 'sales_lead', $id, $data, $version, ['updated' => array_keys($values)], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function qualifyLead(string $id, array $data): array
    {
        return $this->leadTransition($id, 'QUALIFIED', 'QUALIFIED', ['NEW'], $data, []);
    }

    public function convertLead(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $lead = $this->find('sales_leads', $id, $data, 'Sales lead', true);
            $this->assertVersion($lead, $data, 'sales lead');
            $this->assertStatus($lead, ['QUALIFIED'], 'Only a qualified lead can be converted.');
            $customerId = $data['customer_party_id'] ?? null;
            if (($data['conversion_path'] ?? 'EXISTING_CUSTOMER') === 'CREATE_CUSTOMER') {
                $duplicate = DB::table('parties')->where('company_id', $data['company_id'])
                    ->where(fn ($query) => $query->where('code', $data['customer_code'])->orWhereRaw('LOWER(display_name) = ?', [Str::lower(trim($data['customer_name']))]))->first();
                if ($duplicate) throw ValidationException::withMessages(['customer_name' => ['A customer with this code or name already exists. Select the existing customer instead.']]);
                $customerId = (string) Str::uuid(); $now = CarbonImmutable::now();
                DB::table('parties')->insert(['id' => $customerId, 'company_id' => $data['company_id'], 'code' => $data['customer_code'],
                    'display_name' => trim($data['customer_name']), 'legal_name' => trim($data['customer_name']), 'party_kind' => 'ORGANISATION',
                    'notes' => 'Created from sales lead '.$lead->lead_number, 'status' => 'ACTIVE', 'record_version' => 1,
                    'status_reason' => 'Created during governed lead conversion.', 'status_changed_at' => $now, 'status_changed_by' => $data['actor_id'],
                    'created_at' => $now, 'updated_at' => $now]);
                DB::table('party_roles')->insert(['id' => (string) Str::uuid(), 'company_id' => $data['company_id'], 'party_id' => $customerId,
                    'role_code' => 'CUSTOMER', 'created_at' => $now, 'updated_at' => $now]);
                DB::table('party_contacts')->insert(['id' => (string) Str::uuid(), 'company_id' => $data['company_id'], 'party_id' => $customerId,
                    'name' => trim($data['contact_name']), 'job_title' => null, 'department' => null, 'email' => $this->nullable($data['contact_email'] ?? null),
                    'phone' => $this->nullable($data['contact_phone'] ?? null), 'mobile' => null, 'is_primary' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
            $this->assertCustomer($customerId, $data);
            return $this->leadTransition($id, 'CONVERTED', 'CONVERTED', ['QUALIFIED'], $data, ['customer_party_id' => $customerId]);
        }, 3);
    }

    public function closeLead(string $id, string $outcome, ?string $reason, array $data): array
    {
        $outcome = Str::upper($outcome);
        if (! in_array($outcome, ['WON', 'LOST'], true)) {
            throw ValidationException::withMessages(['outcome' => ['Lead outcome must be WON or LOST.']]);
        }
        if ($outcome === 'LOST' && $this->nullable($reason) === null) {
            throw ValidationException::withMessages(['reason' => ['A lost lead requires a reason.']]);
        }
        return $this->leadTransition($id, $outcome, $outcome, $outcome === 'WON' ? ['CONVERTED'] : ['NEW', 'QUALIFIED', 'CONVERTED'], $data, [
            'lost_reason' => $outcome === 'LOST' ? trim((string) $reason) : null,
        ], $reason);
    }

    public function createPriceList(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'sales.price-list.create';
            if ($replay = $this->begin($namespace, $data)) return $replay;
            $this->uniqueNumber('sales_price_lists', 'list_number', $data['list_number'], $data);
            $this->assertDateRange($data['effective_from'], $data['effective_to'] ?? null, 'effective_to');
            $id = (string) Str::uuid(); $now = CarbonImmutable::now();
            DB::table('sales_price_lists')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'list_number' => $data['list_number'], 'name' => trim($data['name']), 'currency' => 'INR',
                'effective_from' => $data['effective_from'], 'effective_to' => $data['effective_to'] ?? null,
                'status' => 'DRAFT', 'notes' => $this->nullable($data['notes'] ?? null), 'record_version' => 1,
                'created_by' => $data['actor_id'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->replacePriceLines($id, $data['lines'], $data, $now);
            $result = $this->result('sales_price_list', $id, 'DRAFT', 1);
            $this->record('CREATE_SALES_PRICE_LIST', 'sales.price-list.created', 'sales_price_list', $id, $data, 1, ['line_count' => count($data['lines'])], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function updatePriceList(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'sales.price-list.update.'.$id;
            if ($replay = $this->begin($namespace, $data + ['price_list_id' => $id])) return $replay;
            $price = $this->find('sales_price_lists', $id, $data, 'Price list', true);
            $this->assertVersion($price, $data, 'price list');
            $this->assertStatus($price, ['DRAFT'], 'Only a draft price list can be edited.');
            $this->assertDateRange($data['effective_from'], $data['effective_to'] ?? null, 'effective_to');
            $version = (int) $price->record_version + 1; $now = CarbonImmutable::now();
            DB::table('sales_price_lists')->where('id', $id)->update([
                'name' => trim($data['name']), 'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null, 'notes' => $this->nullable($data['notes'] ?? null),
                'record_version' => $version, 'updated_at' => $now,
            ]);
            DB::table('sales_price_list_lines')->where('sales_price_list_id', $id)->delete();
            $this->replacePriceLines($id, $data['lines'], $data, $now);
            $result = $this->result('sales_price_list', $id, 'DRAFT', $version);
            $this->record('UPDATE_SALES_PRICE_LIST', 'sales.price-list.updated', 'sales_price_list', $id, $data, $version, ['line_count' => count($data['lines'])], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function activatePriceList(string $id, array $data): array
    {
        return $this->simpleTransition('sales_price_lists', $id, 'Price list', ['DRAFT'], 'ACTIVE', 'sales.price-list.activate', $data, [
            'activated_at' => now(), 'activated_by' => $data['actor_id'],
        ]);
    }

    public function retirePriceList(string $id, string $reason, array $data): array
    {
        return $this->simpleTransition('sales_price_lists', $id, 'Price list', ['ACTIVE'], 'RETIRED', 'sales.price-list.retire', $data, [
            'retired_at' => now(), 'retired_by' => $data['actor_id'], 'retirement_reason' => trim($reason),
        ]);
    }

    public function upsertCreditProfile(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'sales.credit-profile.upsert.'.$data['customer_party_id'];
            if ($replay = $this->begin($namespace, $data)) return $replay;
            $this->assertCustomer($data['customer_party_id'], $data);
            $existing = DB::table('customer_credit_profiles')->where('company_id', $data['company_id'])
                ->where('customer_party_id', $data['customer_party_id'])->lockForUpdate()->first();
            if ($existing && isset($data['record_version']) && (int) $data['record_version'] !== (int) $existing->record_version) {
                throw new ConflictHttpException('The customer credit profile changed. Refresh it before continuing.');
            }
            $hold = (bool) ($data['is_on_hold'] ?? false);
            if ($hold && $this->nullable($data['hold_reason'] ?? null) === null) {
                throw ValidationException::withMessages(['hold_reason' => ['A credit hold requires a reason.']]);
            }
            $id = $existing?->id ?? (string) Str::uuid(); $version = $existing ? (int) $existing->record_version + 1 : 1;
            DB::table('customer_credit_profiles')->updateOrInsert(['id' => $id], [
                'company_id' => $data['company_id'], 'customer_party_id' => $data['customer_party_id'],
                'credit_limit' => $this->money($data['credit_limit']), 'payment_terms_days' => (int) $data['payment_terms_days'],
                'is_on_hold' => $hold, 'hold_reason' => $hold ? trim($data['hold_reason']) : null,
                'record_version' => $version, 'updated_by' => $data['actor_id'],
                'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
            ]);
            $result = $this->result('customer_credit_profile', (string) $id, $hold ? 'ON_HOLD' : 'ACTIVE', $version);
            $this->record('UPSERT_CUSTOMER_CREDIT', 'sales.customer-credit.updated', 'customer_credit_profile', (string) $id, $data, $version, [
                'credit_limit' => $this->money($data['credit_limit']), 'is_on_hold' => $hold,
            ], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function createContract(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'sales.contract.create';
            if ($replay = $this->begin($namespace, $data)) return $replay;
            $this->uniqueNumber('sales_contracts', 'contract_number', $data['contract_number'], $data);
            $this->assertCustomer($data['customer_party_id'], $data);
            $this->assertDateRange($data['effective_from'], $data['effective_to'], 'effective_to');
            if (! empty($data['sales_price_list_id'])) $this->find('sales_price_lists', $data['sales_price_list_id'], $data, 'Price list');
            $id = (string) Str::uuid(); $now = CarbonImmutable::now();
            DB::table('sales_contracts')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'contract_number' => $data['contract_number'], 'customer_party_id' => $data['customer_party_id'],
                'sales_price_list_id' => $data['sales_price_list_id'] ?? null,
                'effective_from' => $data['effective_from'], 'effective_to' => $data['effective_to'],
                'committed_value' => $this->money($data['committed_value'] ?? 0), 'currency' => 'INR',
                'status' => 'DRAFT', 'notes' => $this->nullable($data['notes'] ?? null), 'record_version' => 1,
                'created_by' => $data['actor_id'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->replaceContractLines($id, $data['lines'], $data, $now);
            $result = $this->result('sales_contract', $id, 'DRAFT', 1);
            $this->record('CREATE_SALES_CONTRACT', 'sales.contract.created', 'sales_contract', $id, $data, 1, ['line_count' => count($data['lines'])], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function updateContract(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'sales.contract.update.'.$id;
            if ($replay = $this->begin($namespace, $data + ['contract_id' => $id])) return $replay;
            $contract = $this->find('sales_contracts', $id, $data, 'Sales contract', true);
            $this->assertVersion($contract, $data, 'sales contract');
            $this->assertStatus($contract, ['DRAFT'], 'Only a draft contract can be edited.');
            $this->assertCustomer($data['customer_party_id'], $data);
            $this->assertDateRange($data['effective_from'], $data['effective_to'], 'effective_to');
            $version = (int) $contract->record_version + 1; $now = CarbonImmutable::now();
            DB::table('sales_contracts')->where('id', $id)->update([
                'customer_party_id' => $data['customer_party_id'], 'sales_price_list_id' => $data['sales_price_list_id'] ?? null,
                'effective_from' => $data['effective_from'], 'effective_to' => $data['effective_to'],
                'committed_value' => $this->money($data['committed_value'] ?? 0), 'notes' => $this->nullable($data['notes'] ?? null),
                'record_version' => $version, 'updated_at' => $now,
            ]);
            DB::table('sales_contract_lines')->where('sales_contract_id', $id)->delete();
            $this->replaceContractLines($id, $data['lines'], $data, $now);
            $result = $this->result('sales_contract', $id, 'DRAFT', $version);
            $this->record('UPDATE_SALES_CONTRACT', 'sales.contract.updated', 'sales_contract', $id, $data, $version, ['line_count' => count($data['lines'])], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function activateContract(string $id, array $data): array
    {
        return $this->simpleTransition('sales_contracts', $id, 'Sales contract', ['DRAFT'], 'ACTIVE', 'sales.contract.activate', $data, [
            'activated_at' => now(), 'activated_by' => $data['actor_id'],
        ]);
    }

    public function closeContract(string $id, string $reason, array $data): array
    {
        return $this->simpleTransition('sales_contracts', $id, 'Sales contract', ['DRAFT', 'ACTIVE'], 'CLOSED', 'sales.contract.close', $data, [
            'closed_at' => now(), 'closed_by' => $data['actor_id'], 'closure_reason' => trim($reason),
        ]);
    }

    public function createOrder(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'sales.order.create';
            if ($replay = $this->begin($namespace, $data)) return $replay;
            $this->uniqueNumber('sales_orders', 'order_number', $data['order_number'], $data, 'order_type', 'SALES');
            $this->assertCustomer($data['customer_party_id'], $data);
            $this->assertDateRange($data['order_date'], $data['requested_delivery_date'], 'requested_delivery_date');
            $pricing = $this->priceOrder($data);
            $credit = $this->creditSnapshot($data['customer_party_id'], $data);
            $id = (string) Str::uuid(); $now = CarbonImmutable::now();
            DB::table('sales_orders')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'order_type' => 'SALES',
                'order_number' => $data['order_number'], 'customer_party_id' => $data['customer_party_id'],
                'sales_lead_id' => $data['sales_lead_id'] ?? null, 'sales_contract_id' => $data['sales_contract_id'] ?? null,
                'sales_price_list_id' => $data['sales_price_list_id'] ?? null, 'order_date' => $data['order_date'],
                'requested_delivery_date' => $data['requested_delivery_date'], 'currency' => 'INR',
                'subtotal' => $pricing['subtotal'], 'discount_amount' => $pricing['discount'], 'tax_amount' => $pricing['tax'],
                'total_amount' => $pricing['total'], 'credit_limit_snapshot' => $credit['limit'],
                'credit_exposure_snapshot' => $credit['exposure'], 'notes' => $this->nullable($data['notes'] ?? null),
                'status' => 'DRAFT', 'record_version' => 1, 'created_by' => $data['actor_id'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->insertOrderLines($id, $pricing['lines'], $data, $now);
            $this->orderRevision($id, 1, 'INITIAL', 'Initial sales order', $data, $pricing, $now);
            $result = $this->result('sales_order', $id, 'DRAFT', 1) + ['total_amount' => $pricing['total']];
            $this->record('CREATE_SALES_ORDER', 'sales.order.created', 'sales_order', $id, $data, 1, ['total_amount' => $pricing['total'], 'line_count' => count($pricing['lines'])], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function updateOrder(string $id, array $data): array
    {
        return $this->rewriteOrder($id, $data, false, null);
    }

    public function amendOrder(string $id, string $reason, array $data): array
    {
        return $this->rewriteOrder($id, $data, true, $reason);
    }

    public function confirmOrder(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'sales.order.confirm.'.$id;
            if ($replay = $this->begin($namespace, $data + ['order_id' => $id])) return $replay;
            $order = $this->find('sales_orders', $id, $data, 'Sales order', true);
            $this->assertVersion($order, $data, 'sales order');
            $this->assertStatus($order, ['DRAFT'], 'Only a draft sales order can be confirmed.');
            $credit = $this->creditSnapshot($order->customer_party_id, $data, true);
            if (bccomp(bcadd($credit['exposure'], $this->money($order->total_amount), 6), $credit['limit'], 6) > 0) {
                throw ValidationException::withMessages(['credit' => ['The order would exceed the customer credit limit.']]);
            }
            $lines = DB::table('sales_order_lines')->where('sales_order_id', $id)->lockForUpdate()->get();
            if ($lines->isEmpty()) throw new ConflictHttpException('The sales order has no lines.');
            foreach ($lines as $line) {
                if ($line->sales_contract_line_id) {
                    $contractLine = DB::table('sales_contract_lines')->where('id', $line->sales_contract_line_id)->lockForUpdate()->first();
                    if (! $contractLine || bccomp(bcadd((string) $contractLine->consumed_quantity, (string) $line->ordered_quantity, 6), (string) $contractLine->committed_quantity, 6) > 0) {
                        throw ValidationException::withMessages(['sales_contract_id' => ['Contract quantity is no longer available.']]);
                    }
                    DB::table('sales_contract_lines')->where('id', $contractLine->id)->update([
                        'consumed_quantity' => bcadd((string) $contractLine->consumed_quantity, (string) $line->ordered_quantity, 6), 'updated_at' => now(),
                    ]);
                }
            }
            $version = (int) $order->record_version + 1; $now = CarbonImmutable::now();
            DB::table('sales_orders')->where('id', $id)->update([
                'status' => 'CONFIRMED', 'credit_limit_snapshot' => $credit['limit'],
                'credit_exposure_snapshot' => $credit['exposure'], 'confirmed_at' => $now,
                'confirmed_by' => $data['actor_id'], 'record_version' => $version, 'updated_at' => $now,
            ]);
            if ($order->sales_lead_id) {
                $lead = DB::table('sales_leads')->where('id', $order->sales_lead_id)->lockForUpdate()->first();
                if ($lead && $lead->status === 'CONVERTED') {
                    DB::table('sales_leads')->where('id', $lead->id)->update([
                        'status' => 'WON', 'closed_at' => $now, 'closed_by' => $data['actor_id'],
                        'record_version' => (int) $lead->record_version + 1, 'updated_at' => $now,
                    ]);
                    $this->leadEvent($lead->id, 'WON', 'Won through sales order '.$order->order_number, $data, $now);
                }
            }
            $result = $this->result('sales_order', $id, 'CONFIRMED', $version) + ['credit_exposure' => $credit['exposure']];
            $this->record('CONFIRM_SALES_ORDER', 'sales.order.confirmed', 'sales_order', $id, $data, $version, ['status' => ['from' => 'DRAFT', 'to' => 'CONFIRMED']], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function cancelOrder(string $id, string $reason, array $data): array
    {
        return DB::transaction(function () use ($id, $reason, $data): array {
            $namespace = 'sales.order.cancel.'.$id;
            if ($replay = $this->begin($namespace, $data + ['order_id' => $id, 'reason' => $reason])) return $replay;
            $order = $this->find('sales_orders', $id, $data, 'Sales order', true);
            $this->assertVersion($order, $data, 'sales order');
            $this->assertStatus($order, ['DRAFT', 'CONFIRMED'], 'Only an unallocated sales order can be cancelled.');
            if (DB::table('sales_allocations')->where('sales_order_id', $id)->whereNot('status', 'CANCELLED')->exists()) {
                throw ValidationException::withMessages(['status' => ['Cancel the active allocation before cancelling this sales order.']]);
            }
            if ($order->status === 'CONFIRMED') $this->releaseContractConsumption($id);
            $version = (int) $order->record_version + 1; $now = CarbonImmutable::now();
            DB::table('sales_orders')->where('id', $id)->update([
                'status' => 'CANCELLED', 'cancelled_at' => $now, 'cancelled_by' => $data['actor_id'],
                'cancellation_reason' => trim($reason), 'record_version' => $version, 'updated_at' => $now,
            ]);
            $result = $this->result('sales_order', $id, 'CANCELLED', $version);
            $this->record('CANCEL_SALES_ORDER', 'sales.order.cancelled', 'sales_order', $id, $data, $version, ['reason' => trim($reason)], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function createThirdPartyWork(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'sales.third-party-work.create';
            if ($replay = $this->begin($namespace, $data)) return $replay;
            $this->uniqueNumber('third_party_work_orders', 'work_number', $data['work_number'], $data);
            $this->assertDateRange($data['expected_start_date'], $data['expected_end_date'], 'expected_end_date');
            $this->assertParty($data['provider_party_id'], $data);
            if (! empty($data['sales_order_id'])) $this->find('sales_orders', $data['sales_order_id'], $data, 'Sales order');
            $id = (string) Str::uuid(); $now = CarbonImmutable::now();
            DB::table('third_party_work_orders')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'work_number' => $data['work_number'], 'sales_order_id' => $data['sales_order_id'] ?? null,
                'provider_party_id' => $data['provider_party_id'], 'work_type' => $data['work_type'],
                'description' => trim($data['description']), 'expected_start_date' => $data['expected_start_date'],
                'expected_end_date' => $data['expected_end_date'], 'agreed_cost' => $this->money($data['agreed_cost'] ?? 0),
                'currency' => 'INR', 'status' => 'DRAFT', 'record_version' => 1, 'created_by' => $data['actor_id'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $result = $this->result('third_party_work_order', $id, 'DRAFT', 1);
            $this->record('CREATE_THIRD_PARTY_WORK', 'sales.third-party-work.created', 'third_party_work_order', $id, $data, 1, ['work_number' => $data['work_number']], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function releaseThirdPartyWork(string $id, array $data): array
    {
        return $this->simpleTransition('third_party_work_orders', $id, 'Third-party work order', ['DRAFT'], 'RELEASED', 'sales.third-party-work.release', $data, [
            'released_at' => now(), 'released_by' => $data['actor_id'],
        ]);
    }

    public function completeThirdPartyWork(string $id, mixed $actualCost, array $data): array
    {
        return $this->simpleTransition('third_party_work_orders', $id, 'Third-party work order', ['RELEASED'], 'COMPLETED', 'sales.third-party-work.complete', $data, [
            'completed_at' => now(), 'completed_by' => $data['actor_id'], 'actual_cost' => $this->money($actualCost),
        ]);
    }

    public function cancelThirdPartyWork(string $id, string $reason, array $data): array
    {
        return $this->simpleTransition('third_party_work_orders', $id, 'Third-party work order', ['DRAFT', 'RELEASED'], 'CANCELLED', 'sales.third-party-work.cancel', $data, [
            'cancelled_at' => now(), 'cancelled_by' => $data['actor_id'], 'cancellation_reason' => trim($reason),
        ]);
    }

    public function allocateOrder(string $orderId, array $data): array
    {
        return DB::transaction(function () use ($orderId, $data): array {
            $namespace = 'sales.allocation.create.'.$orderId;
            if ($replay = $this->begin($namespace, $data + ['sales_order_id' => $orderId])) return $replay;
            $order = $this->find('sales_orders', $orderId, $data, 'Sales order', true);
            $this->assertVersion($order, $data, 'sales order');
            $this->assertStatus($order, ['CONFIRMED', 'ALLOCATED'], 'Only a confirmed sales order can be allocated.');
            $this->uniqueNumber('sales_allocations', 'allocation_number', $data['allocation_number'], $data);
            $lines = DB::table('sales_order_lines')->where('sales_order_id', $orderId)->orderBy('line_number')->lockForUpdate()->get();
            $requested = collect($data['lines'] ?? [])->keyBy('sales_order_line_id');
            if ($requested->isNotEmpty()) {
                $unknown = $requested->keys()->diff($lines->pluck('id'));
                if ($unknown->isNotEmpty()) throw ValidationException::withMessages(['lines' => ['An allocation line is not part of this sales order.']]);
            }
            $allocationId = (string) Str::uuid(); $now = CarbonImmutable::now();
            DB::table('sales_allocations')->insert([
                'id' => $allocationId, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'allocation_number' => $data['allocation_number'], 'sales_order_id' => $orderId,
                'status' => 'RESERVED', 'record_version' => 1, 'created_by' => $data['actor_id'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $allocationLineNumber = 0; $allocatedTotal = '0.000000';
            foreach ($lines as $line) {
                $remaining = bcsub((string) $line->ordered_quantity, (string) $line->allocated_quantity, 6);
                if (bccomp($remaining, '0', 6) <= 0) continue;
                if ($requested->isNotEmpty() && ! $requested->has($line->id)) continue;
                $needed = $requested->isNotEmpty()
                    ? $this->positive($requested->get($line->id)['quantity'], 'lines', 'Allocation quantity')
                    : $remaining;
                if (bccomp($needed, $remaining, 6) > 0) {
                    throw ValidationException::withMessages(['lines' => ["Allocation exceeds the remaining quantity on order line {$line->line_number}."]]);
                }
                $positions = DB::table('stock_positions as position')
                    ->join('lots as lot', 'lot.id', '=', 'position.lot_id')
                    ->join('locations as location', 'location.id', '=', 'position.location_id')
                    ->where('position.company_id', $data['company_id'])->where('position.plant_id', $data['plant_id'])
                    ->where('position.item_id', $line->item_id)->where('position.uom_code', $line->uom_code)
                    ->where('position.inventory_owner_id', $data['company_id'])->where('position.quality_status', 'RELEASED')
                    ->where('lot.status', 'ACTIVE')->where('location.status', 'ACTIVE')->where('location.location_type', 'FINISHED_GOODS')
                    ->where(function ($query) use ($now): void { $query->whereNull('lot.expiry_date')->orWhereDate('lot.expiry_date', '>=', $now->toDateString()); })
                    ->orderByRaw('CASE WHEN lot.expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('lot.expiry_date')->orderBy('position.id')
                    ->lockForUpdate()->get(['position.*', 'lot.expiry_date']);
                foreach ($positions as $position) {
                    if (bccomp($needed, '0', 6) <= 0) break;
                    $available = bcsub((string) $position->quantity_base, (string) $position->reserved_quantity_base, 6);
                    if (bccomp($available, '0', 6) <= 0) continue;
                    $take = bccomp($available, $needed, 6) < 0 ? $available : $needed;
                    $allocationLineNumber++;
                    $reservationId = (string) Str::uuid();
                    $reservationNumber = mb_substr($data['allocation_number'].'-'.str_pad((string) $allocationLineNumber, 3, '0', STR_PAD_LEFT), 0, 80);
                    DB::table('stock_reservations')->insert([
                        'id' => $reservationId, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                        'stock_position_id' => $position->id, 'reservation_number' => $reservationNumber,
                        'quantity_base' => $take, 'status' => 'ACTIVE', 'purpose' => 'Sales allocation '.$data['allocation_number'],
                        'record_version' => 1, 'created_by' => $data['actor_id'], 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    DB::table('stock_positions')->where('id', $position->id)->update([
                        'reserved_quantity_base' => bcadd((string) $position->reserved_quantity_base, $take, 6),
                        'record_version' => (int) $position->record_version + 1, 'updated_at' => $now,
                    ]);
                    DB::table('sales_allocation_lines')->insert([
                        'id' => (string) Str::uuid(), 'sales_allocation_id' => $allocationId, 'sales_order_id' => $orderId,
                        'sales_order_line_id' => $line->id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                        'line_number' => $allocationLineNumber, 'item_id' => $line->item_id, 'lot_id' => $position->lot_id,
                        'stock_position_id' => $position->id, 'stock_reservation_id' => $reservationId,
                        'uom_code' => $line->uom_code, 'allocated_quantity' => $take, 'picked_quantity' => 0,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $needed = bcsub($needed, $take, 6); $allocatedTotal = bcadd($allocatedTotal, $take, 6);
                    $position->reserved_quantity_base = bcadd((string) $position->reserved_quantity_base, $take, 6);
                    $position->record_version = (int) $position->record_version + 1;
                }
                if (bccomp($needed, '0', 6) > 0) {
                    throw ValidationException::withMessages(['stock' => ["Insufficient released FEFO stock for order line {$line->line_number}."]]);
                }
                $lineAllocated = $requested->isNotEmpty()
                    ? $this->positive($requested->get($line->id)['quantity'], 'lines', 'Allocation quantity') : $remaining;
                DB::table('sales_order_lines')->where('id', $line->id)->update([
                    'allocated_quantity' => bcadd((string) $line->allocated_quantity, $lineAllocated, 6), 'updated_at' => $now,
                ]);
            }
            if ($allocationLineNumber === 0) throw ValidationException::withMessages(['lines' => ['No remaining order quantity was selected for allocation.']]);
            $version = (int) $order->record_version + 1;
            DB::table('sales_orders')->where('id', $orderId)->update(['status' => 'ALLOCATED', 'record_version' => $version, 'updated_at' => $now]);
            $result = $this->result('sales_allocation', $allocationId, 'RESERVED', 1) + [
                'sales_order_id' => $orderId, 'sales_order_record_version' => $version,
                'allocated_quantity' => $allocatedTotal, 'fefo_break_count' => $allocationLineNumber,
            ];
            $this->record('ALLOCATE_SALES_ORDER', 'sales.allocation.created', 'sales_allocation', $allocationId, $data, 1, [
                'sales_order_id' => $orderId, 'allocated_quantity' => $allocatedTotal, 'fefo_break_count' => $allocationLineNumber,
            ], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function pickAllocation(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'sales.allocation.pick.'.$id;
            if ($replay = $this->begin($namespace, $data + ['allocation_id' => $id])) return $replay;
            $allocation = $this->find('sales_allocations', $id, $data, 'Sales allocation', true);
            $this->assertVersion($allocation, $data, 'sales allocation');
            $this->assertStatus($allocation, ['RESERVED'], 'Only a reserved allocation can be picked.');
            $lines = DB::table('sales_allocation_lines')->where('sales_allocation_id', $id)->lockForUpdate()->get();
            foreach ($lines as $line) {
                $reservation = DB::table('stock_reservations')->where('id', $line->stock_reservation_id)->lockForUpdate()->first();
                if (! $reservation || $reservation->status !== 'ACTIVE' || bccomp((string) $reservation->quantity_base, (string) $line->allocated_quantity, 6) !== 0) {
                    throw new ConflictHttpException('An allocation reservation is no longer intact.');
                }
                DB::table('sales_allocation_lines')->where('id', $line->id)->update(['picked_quantity' => $line->allocated_quantity, 'updated_at' => now()]);
            }
            $now = CarbonImmutable::now(); $version = (int) $allocation->record_version + 1;
            DB::table('sales_allocations')->where('id', $id)->update([
                'status' => 'PICKED', 'picked_at' => $now, 'picked_by' => $data['actor_id'],
                'record_version' => $version, 'updated_at' => $now,
            ]);
            $order = $this->find('sales_orders', $allocation->sales_order_id, $data, 'Sales order', true);
            DB::table('sales_orders')->where('id', $order->id)->update([
                'status' => 'PICKED', 'record_version' => (int) $order->record_version + 1, 'updated_at' => $now,
            ]);
            $result = $this->result('sales_allocation', $id, 'PICKED', $version) + ['sales_order_id' => $order->id];
            $this->record('PICK_SALES_ALLOCATION', 'sales.allocation.picked', 'sales_allocation', $id, $data, $version, ['status' => ['from' => 'RESERVED', 'to' => 'PICKED']], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function cancelAllocation(string $id, string $reason, array $data): array
    {
        return DB::transaction(function () use ($id, $reason, $data): array {
            $namespace = 'sales.allocation.cancel.'.$id;
            if ($replay = $this->begin($namespace, $data + ['allocation_id' => $id, 'reason' => $reason])) return $replay;
            $allocation = $this->find('sales_allocations', $id, $data, 'Sales allocation', true);
            $this->assertVersion($allocation, $data, 'sales allocation');
            $this->assertStatus($allocation, ['RESERVED', 'PICKED'], 'Only an undispatched allocation can be cancelled.');
            $lines = DB::table('sales_allocation_lines')->where('sales_allocation_id', $id)->orderBy('stock_position_id')->lockForUpdate()->get();
            foreach ($lines as $line) {
                $reservation = DB::table('stock_reservations')->where('id', $line->stock_reservation_id)->lockForUpdate()->first();
                $position = DB::table('stock_positions')->where('id', $line->stock_position_id)->lockForUpdate()->first();
                if (! $reservation || ! $position || $reservation->status !== 'ACTIVE'
                    || bccomp((string) $position->reserved_quantity_base, (string) $line->allocated_quantity, 6) < 0) {
                    throw new ConflictHttpException('The allocation reservation projection is inconsistent.');
                }
                DB::table('stock_reservations')->where('id', $reservation->id)->update([
                    'status' => 'RELEASED', 'record_version' => (int) $reservation->record_version + 1,
                    'released_at' => now(), 'released_by' => $data['actor_id'], 'release_reason' => trim($reason), 'updated_at' => now(),
                ]);
                DB::table('stock_positions')->where('id', $position->id)->update([
                    'reserved_quantity_base' => bcsub((string) $position->reserved_quantity_base, (string) $line->allocated_quantity, 6),
                    'record_version' => (int) $position->record_version + 1, 'updated_at' => now(),
                ]);
                DB::table('sales_order_lines')->where('id', $line->sales_order_line_id)->decrement('allocated_quantity', $line->allocated_quantity, ['updated_at' => now()]);
            }
            $now = CarbonImmutable::now(); $version = (int) $allocation->record_version + 1;
            DB::table('sales_allocations')->where('id', $id)->update([
                'status' => 'CANCELLED', 'picked_at' => null, 'picked_by' => null,
                'cancelled_at' => $now, 'cancelled_by' => $data['actor_id'], 'cancellation_reason' => trim($reason),
                'record_version' => $version, 'updated_at' => $now,
            ]);
            $order = $this->find('sales_orders', $allocation->sales_order_id, $data, 'Sales order', true);
            $active = DB::table('sales_allocations')->where('sales_order_id', $order->id)->whereNot('status', 'CANCELLED')->exists();
            $orderStatus = $active ? 'ALLOCATED' : 'CONFIRMED';
            DB::table('sales_orders')->where('id', $order->id)->update(['status' => $orderStatus, 'record_version' => (int) $order->record_version + 1, 'updated_at' => $now]);
            $result = $this->result('sales_allocation', $id, 'CANCELLED', $version) + ['sales_order_id' => $order->id];
            $this->record('CANCEL_SALES_ALLOCATION', 'sales.allocation.cancelled', 'sales_allocation', $id, $data, $version, ['reason' => trim($reason)], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function createShipment(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'sales.shipment.create';
            if ($replay = $this->begin($namespace, $data)) return $replay;
            $this->uniqueNumber('shipments', 'shipment_number', $data['shipment_number'], $data);
            $allocation = $this->find('sales_allocations', $data['sales_allocation_id'], $data, 'Sales allocation', true);
            $this->assertStatus($allocation, ['PICKED'], 'Only a picked allocation can be loaded.');
            if (DB::table('shipments')->where('sales_allocation_id', $allocation->id)->where('shipment_type', 'SALES')->whereNot('status', 'CANCELLED')->exists()) {
                throw ValidationException::withMessages(['sales_allocation_id' => ['This allocation already has an active shipment.']]);
            }
            $order = $this->find('sales_orders', $allocation->sales_order_id, $data, 'Sales order', true);
            $id = (string) Str::uuid(); $now = CarbonImmutable::now();
            DB::table('shipments')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'shipment_type' => 'SALES', 'shipment_number' => $data['shipment_number'], 'party_id' => $order->customer_party_id,
                'sales_order_id' => $order->id, 'sales_allocation_id' => $allocation->id,
                'carrier_name' => $this->nullable($data['carrier_name'] ?? null), 'vehicle_number' => $this->nullable($data['vehicle_number'] ?? null),
                'driver_name' => $this->nullable($data['driver_name'] ?? null), 'notes' => $this->nullable($data['notes'] ?? null),
                'status' => 'DRAFT', 'record_version' => 1, 'created_by' => $data['actor_id'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $result = $this->result('sales_shipment', $id, 'DRAFT', 1) + ['sales_order_id' => $order->id, 'sales_allocation_id' => $allocation->id];
            $this->record('CREATE_SALES_SHIPMENT', 'sales.shipment.created', 'sales_shipment', $id, $data, 1, ['shipment_number' => $data['shipment_number']], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function loadShipment(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'sales.shipment.load.'.$id;
            if ($replay = $this->begin($namespace, $data + ['shipment_id' => $id])) return $replay;
            $shipment = $this->find('shipments', $id, $data, 'Shipment', true);
            $this->assertVersion($shipment, $data, 'shipment');
            $this->assertStatus($shipment, ['DRAFT'], 'Only a draft shipment can be loaded.');
            if (! $shipment->carrier_name || ! $shipment->vehicle_number || ! $shipment->driver_name) {
                throw ValidationException::withMessages(['shipment' => ['Carrier, vehicle, and driver details are required before loading.']]);
            }
            $now = CarbonImmutable::now(); $version = (int) $shipment->record_version + 1;
            DB::table('shipments')->where('id', $id)->update(['status' => 'LOADED', 'loaded_at' => $now, 'loaded_by' => $data['actor_id'], 'record_version' => $version, 'updated_at' => $now]);
            $order = $this->find('sales_orders', $shipment->sales_order_id, $data, 'Sales order', true);
            DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'LOADED', 'record_version' => (int) $order->record_version + 1, 'updated_at' => $now]);
            $result = $this->result('sales_shipment', $id, 'LOADED', $version) + ['sales_order_id' => $order->id];
            $this->record('LOAD_SALES_SHIPMENT', 'sales.shipment.loaded', 'sales_shipment', $id, $data, $version, ['status' => ['from' => 'DRAFT', 'to' => 'LOADED']], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function dispatchShipment(string $id, string $invoiceNumber, array $data): array
    {
        return DB::transaction(function () use ($id, $invoiceNumber, $data): array {
            $namespace = 'sales.shipment.dispatch.'.$id;
            if ($replay = $this->begin($namespace, $data + ['shipment_id' => $id, 'invoice_number' => $invoiceNumber])) return $replay;
            $shipment = $this->find('shipments', $id, $data, 'Shipment', true);
            $this->assertVersion($shipment, $data, 'shipment');
            $this->assertStatus($shipment, ['LOADED'], 'Only a loaded shipment can be dispatched.');
            if (DB::table('sales_invoice_financials')->where('company_id', $data['company_id'])->where('invoice_number', $invoiceNumber)->exists()) {
                throw ValidationException::withMessages(['invoice_number' => ['That customer invoice number already exists.']]);
            }
            $allocation = $this->find('sales_allocations', $shipment->sales_allocation_id, $data, 'Sales allocation', true);
            $this->assertStatus($allocation, ['PICKED'], 'The picked allocation is no longer dispatchable.');
            $order = $this->find('sales_orders', $shipment->sales_order_id, $data, 'Sales order', true);
            $lines = DB::table('sales_allocation_lines as allocation_line')
                ->join('sales_order_lines as order_line', 'order_line.id', '=', 'allocation_line.sales_order_line_id')
                ->where('allocation_line.sales_allocation_id', $allocation->id)
                ->orderBy('allocation_line.stock_position_id')->lockForUpdate()
                ->get(['allocation_line.*', 'order_line.ordered_quantity', 'order_line.unit_price', 'order_line.discount_percent',
                    'order_line.tax_rate', 'order_line.net_amount as order_net_amount', 'order_line.tax_amount as order_tax_amount']);
            if ($lines->isEmpty()) throw new ConflictHttpException('The sales allocation has no picked lines.');
            $net = '0.0000'; $tax = '0.0000'; $now = CarbonImmutable::now();
            foreach ($lines as $index => $line) {
                if (bccomp((string) $line->picked_quantity, (string) $line->allocated_quantity, 6) !== 0) {
                    throw new ConflictHttpException('Every allocation line must be fully picked before dispatch.');
                }
                $reservation = DB::table('stock_reservations')->where('id', $line->stock_reservation_id)->lockForUpdate()->first();
                $position = DB::table('stock_positions')->where('id', $line->stock_position_id)->lockForUpdate()->first();
                if (! $reservation || ! $position || $reservation->status !== 'ACTIVE'
                    || bccomp((string) $position->reserved_quantity_base, (string) $line->allocated_quantity, 6) < 0) {
                    throw new ConflictHttpException('A picked reservation changed before dispatch.');
                }
                DB::table('stock_reservations')->where('id', $reservation->id)->update([
                    'status' => 'RELEASED', 'record_version' => (int) $reservation->record_version + 1,
                    'released_at' => $now, 'released_by' => $data['actor_id'],
                    'release_reason' => 'Consumed by shipment '.$shipment->shipment_number, 'updated_at' => $now,
                ]);
                DB::table('stock_positions')->where('id', $position->id)->update([
                    'reserved_quantity_base' => bcsub((string) $position->reserved_quantity_base, (string) $line->allocated_quantity, 6),
                    'record_version' => (int) $position->record_version + 1, 'updated_at' => $now,
                ]);
                $movement = $this->stock->issue([
                    'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'source_position_id' => $position->id, 'quantity_base' => $line->allocated_quantity,
                    'uom_code' => $line->uom_code, 'expected_item_id' => $line->item_id,
                    'expected_lot_id' => $line->lot_id, 'expected_owner_id' => $data['company_id'],
                    'expected_quality_status' => 'RELEASED', 'movement_type' => 'SALES_DISPATCH',
                    'source_type' => 'sales_shipment', 'source_id' => $shipment->id,
                    'source_version' => (int) $shipment->record_version + 1, 'actor_id' => $data['actor_id'],
                    'reason_code' => 'CUSTOMER_DISPATCH', 'idempotency_key' => $this->childKey($data['idempotency_key'], 'dispatch-'.$index),
                    'correlation_id' => $data['correlation_id'] ?? null, 'event_at' => $now,
                ]);
                $lineNet = bcadd(bcmul(bcdiv((string) $line->order_net_amount, (string) $line->ordered_quantity, 8), (string) $line->allocated_quantity, 8), '0', 4);
                $lineTax = bcadd(bcmul(bcdiv((string) $line->order_tax_amount, (string) $line->ordered_quantity, 8), (string) $line->allocated_quantity, 8), '0', 4);
                $net = bcadd($net, $lineNet, 4); $tax = bcadd($tax, $lineTax, 4);
                DB::table('shipment_lines')->insert([
                    'id' => (string) Str::uuid(), 'shipment_id' => $shipment->id, 'sales_order_id' => $order->id,
                    'sales_order_line_id' => $line->sales_order_line_id, 'sales_allocation_line_id' => $line->id,
                    'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'item_id' => $line->item_id,
                    'fg_lot_id' => $line->lot_id, 'stock_position_id' => $position->id, 'stock_movement_id' => $movement['movement_id'],
                    'shipped_quantity' => $line->allocated_quantity, 'returned_quantity' => 0, 'uom_code' => $line->uom_code,
                    'unit_price' => $line->unit_price, 'tax_rate' => $line->tax_rate, 'record_version' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('sales_allocation_lines')->where('id', $line->id)->update(['stock_movement_id' => $movement['movement_id'], 'updated_at' => $now]);
                DB::table('sales_order_lines')->where('id', $line->sales_order_line_id)->incrementEach([
                    'dispatched_quantity' => $line->allocated_quantity, 'invoiced_quantity' => $line->allocated_quantity,
                ], ['updated_at' => $now]);
            }
            $gross = bcadd($net, $tax, 4); $invoiceId = (string) Str::uuid();
            $terms = (int) (DB::table('customer_credit_profiles')->where('company_id', $data['company_id'])
                ->where('customer_party_id', $order->customer_party_id)->value('payment_terms_days') ?? 30);
            DB::table('invoices')->insert([
                'id' => $invoiceId, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'invoice_type' => 'RECEIVABLE', 'status' => 'POSTED', 'record_version' => 1, 'created_by' => $data['actor_id'],
                'invoice_date' => $now->toDateString(), 'due_date' => $now->addDays($terms)->toDateString(),
                'currency' => 'INR', 'subtotal' => $net, 'tax_amount' => $tax, 'total_amount' => $gross, 'paid_amount' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('sales_invoice_financials')->insert([
                'invoice_id' => $invoiceId, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'party_id' => $order->customer_party_id, 'shipment_id' => $shipment->id, 'sales_order_id' => $order->id,
                'invoice_number' => $invoiceNumber, 'currency' => 'INR', 'net_amount' => $net, 'tax_amount' => $tax,
                'gross_amount' => $gross, 'outstanding_amount' => $gross, 'paid_amount' => 0, 'credited_amount' => 0,
                'issued_at' => $now, 'due_date' => $now->addDays($terms)->toDateString(), 'record_version' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('receivable_transactions')->insert([
                'id' => (string) Str::uuid(), 'invoice_id' => $invoiceId, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'transaction_type' => 'INVOICE', 'reference_number' => $invoiceNumber, 'amount' => $gross,
                'balance_before' => 0, 'balance_after' => $gross, 'actor_id' => $data['actor_id'],
                'posted_at' => $now, 'created_at' => $now,
            ]);
            $shipmentVersion = (int) $shipment->record_version + 1; $orderVersion = (int) $order->record_version + 1;
            DB::table('shipments')->where('id', $id)->update([
                'status' => 'DISPATCHED', 'dispatched_at' => $now, 'dispatched_by' => $data['actor_id'],
                'record_version' => $shipmentVersion, 'updated_at' => $now,
            ]);
            DB::table('sales_allocations')->where('id', $allocation->id)->update([
                'status' => 'DISPATCHED', 'record_version' => (int) $allocation->record_version + 1, 'updated_at' => $now,
            ]);
            DB::table('sales_orders')->where('id', $order->id)->update([
                'status' => 'DISPATCHED', 'record_version' => $orderVersion, 'updated_at' => $now,
            ]);
            $result = $this->result('sales_shipment', $id, 'DISPATCHED', $shipmentVersion) + [
                'sales_order_id' => $order->id, 'sales_order_record_version' => $orderVersion,
                'invoice_id' => $invoiceId, 'invoice_number' => $invoiceNumber, 'invoice_total' => $gross,
            ];
            $this->record('DISPATCH_SALES_SHIPMENT', 'sales.shipment.dispatched', 'sales_shipment', $id, $data, $shipmentVersion, [
                'status' => ['from' => 'LOADED', 'to' => 'DISPATCHED'], 'invoice_number' => $invoiceNumber, 'invoice_total' => $gross,
            ], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function cancelShipment(string $id, string $reason, array $data): array
    {
        return DB::transaction(function () use ($id, $reason, $data): array {
            $namespace = 'sales.shipment.cancel.'.$id;
            if ($replay = $this->begin($namespace, $data + ['shipment_id' => $id, 'reason' => $reason])) return $replay;
            $shipment = $this->find('shipments', $id, $data, 'Shipment', true);
            $this->assertVersion($shipment, $data, 'shipment');
            $this->assertStatus($shipment, ['DRAFT', 'LOADED'], 'Only an undispatched shipment can be cancelled.');
            $now = CarbonImmutable::now(); $version = (int) $shipment->record_version + 1;
            DB::table('shipments')->where('id', $id)->update([
                'status' => 'CANCELLED', 'loaded_at' => null, 'loaded_by' => null, 'cancelled_at' => $now,
                'cancelled_by' => $data['actor_id'], 'cancellation_reason' => trim($reason), 'record_version' => $version, 'updated_at' => $now,
            ]);
            $order = $this->find('sales_orders', $shipment->sales_order_id, $data, 'Sales order', true);
            DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'PICKED', 'record_version' => (int) $order->record_version + 1, 'updated_at' => $now]);
            $result = $this->result('sales_shipment', $id, 'CANCELLED', $version) + ['sales_order_id' => $order->id];
            $this->record('CANCEL_SALES_SHIPMENT', 'sales.shipment.cancelled', 'sales_shipment', $id, $data, $version, ['reason' => trim($reason)], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function completeDelivery(string $shipmentId, array $data): array
    {
        return DB::transaction(function () use ($shipmentId, $data): array {
            $namespace = 'sales.delivery-proof.create.'.$shipmentId;
            if ($replay = $this->begin($namespace, $data + ['shipment_id' => $shipmentId])) return $replay;
            $shipment = $this->find('shipments', $shipmentId, $data, 'Shipment', true);
            $this->assertVersion($shipment, $data, 'shipment');
            $this->assertStatus($shipment, ['DISPATCHED'], 'POD can only be recorded for a dispatched shipment.');
            $this->uniqueNumber('delivery_proofs', 'proof_number', $data['proof_number'], $data);
            if (DB::table('delivery_proofs')->where('shipment_id', $shipmentId)->exists()) {
                throw ValidationException::withMessages(['shipment_id' => ['This shipment already has proof of delivery.']]);
            }
            $outcome = $data['outcome'];
            if ($outcome === 'DELIVERED' && $this->nullable($data['receiver_name'] ?? null) === null) {
                throw ValidationException::withMessages(['receiver_name' => ['A delivered POD requires the receiver name.']]);
            }
            if ($outcome === 'FAILED' && $this->nullable($data['failure_reason'] ?? null) === null) {
                throw ValidationException::withMessages(['failure_reason' => ['A failed POD requires a reason.']]);
            }
            $proofId = (string) Str::uuid(); $now = CarbonImmutable::now();
            DB::table('delivery_proofs')->insert([
                'id' => $proofId, 'shipment_id' => $shipmentId, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'proof_number' => $data['proof_number'], 'outcome' => $outcome,
                'receiver_name' => $outcome === 'DELIVERED' ? trim($data['receiver_name']) : null,
                'event_at' => $data['event_at'], 'failure_reason' => $outcome === 'FAILED' ? trim($data['failure_reason']) : null,
                'notes' => $this->nullable($data['notes'] ?? null), 'created_by' => $data['actor_id'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $shipmentVersion = (int) $shipment->record_version + 1;
            DB::table('shipments')->where('id', $shipmentId)->update([
                'status' => $outcome, 'delivered_at' => $outcome === 'DELIVERED' ? $data['event_at'] : null,
                'delivered_by' => $outcome === 'DELIVERED' ? $data['actor_id'] : null,
                'record_version' => $shipmentVersion, 'updated_at' => $now,
            ]);
            $order = $this->find('sales_orders', $shipment->sales_order_id, $data, 'Sales order', true);
            $orderStatus = $outcome === 'DELIVERED' ? 'COMPLETED' : 'DISPATCHED';
            $orderVersion = (int) $order->record_version + 1;
            DB::table('sales_orders')->where('id', $order->id)->update(['status' => $orderStatus, 'record_version' => $orderVersion, 'updated_at' => $now]);
            $result = $this->result('delivery_proof', $proofId, $outcome, 1) + [
                'shipment_id' => $shipmentId, 'shipment_record_version' => $shipmentVersion,
                'sales_order_id' => $order->id, 'sales_order_status' => $orderStatus,
            ];
            $this->record('RECORD_DELIVERY_PROOF', 'sales.delivery-proof.recorded', 'delivery_proof', $proofId, $data, 1, [
                'shipment_status' => ['from' => 'DISPATCHED', 'to' => $outcome],
            ], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function createClaim(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'sales.customer-claim.create';
            if ($replay = $this->begin($namespace, $data)) return $replay;
            $this->uniqueNumber('customer_claims', 'claim_number', $data['claim_number'], $data);
            $shipment = $this->find('shipments', $data['shipment_id'], $data, 'Shipment', true);
            if (! in_array($shipment->status, ['DELIVERED', 'DISPATCHED'], true)) {
                throw ValidationException::withMessages(['shipment_id' => ['Claims require a dispatched or delivered shipment.']]);
            }
            $invoiceId = DB::table('sales_invoice_financials')->where('shipment_id', $shipment->id)->value('invoice_id');
            $id = (string) Str::uuid(); $now = CarbonImmutable::now();
            $status = in_array($data['requested_resolution'], ['RETURN_CREDIT'], true) || $data['claim_type'] === 'RETURN' ? 'RETURN_REQUIRED' : 'OPEN';
            DB::table('customer_claims')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'claim_number' => $data['claim_number'], 'customer_party_id' => $shipment->party_id,
                'shipment_id' => $shipment->id, 'invoice_id' => $invoiceId,
                'claim_type' => $data['claim_type'], 'requested_resolution' => $data['requested_resolution'],
                'reason' => trim($data['reason']), 'status' => $status, 'record_version' => 1,
                'created_by' => $data['actor_id'], 'credit_amount' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($data['lines'] as $index => $input) {
                $line = DB::table('shipment_lines')->where('id', $input['shipment_line_id'])->where('shipment_id', $shipment->id)->lockForUpdate()->first();
                if (! $line) throw ValidationException::withMessages(['lines' => ['A claim line is not part of this shipment.']]);
                $quantity = $this->positive($input['quantity'], 'lines', 'Claim quantity');
                $available = bcsub((string) $line->shipped_quantity, (string) $line->returned_quantity, 6);
                if (bccomp($quantity, $available, 6) > 0) throw ValidationException::withMessages(['lines' => ['Claim quantity exceeds shipped, unreturned quantity.']]);
                DB::table('customer_claim_lines')->insert([
                    'id' => (string) Str::uuid(), 'customer_claim_id' => $id, 'shipment_id' => $shipment->id,
                    'shipment_line_id' => $line->id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'line_number' => $index + 1, 'item_id' => $line->item_id, 'lot_id' => $line->fg_lot_id,
                    'uom_code' => $line->uom_code, 'claimed_quantity' => $quantity, 'received_quantity' => 0,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $result = $this->result('customer_claim', $id, $status, 1) + ['shipment_id' => $shipment->id, 'invoice_id' => $invoiceId];
            $this->record('CREATE_CUSTOMER_CLAIM', 'sales.customer-claim.created', 'customer_claim', $id, $data, 1, ['line_count' => count($data['lines']), 'requested_resolution' => $data['requested_resolution']], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function receiveClaim(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'sales.customer-claim.receive.'.$id;
            if ($replay = $this->begin($namespace, $data + ['claim_id' => $id])) return $replay;
            $claim = $this->find('customer_claims', $id, $data, 'Customer claim', true);
            $this->assertVersion($claim, $data, 'customer claim');
            $this->assertStatus($claim, ['RETURN_REQUIRED'], 'Only a return-required claim can be received.');
            $location = DB::table('locations')->where('company_id', $data['company_id'])->where('plant_id', $data['plant_id'])
                ->where('location_type', 'RETURN_QUARANTINE')->where('status', 'ACTIVE')->orderBy('code')->first();
            if (! $location) throw ValidationException::withMessages(['location' => ['The selected plant has no active return-quarantine location.']]);
            $lines = DB::table('customer_claim_lines')->where('customer_claim_id', $id)->orderBy('line_number')->lockForUpdate()->get();
            $now = CarbonImmutable::now(); $received = '0.000000';
            foreach ($lines as $index => $line) {
                $position = DB::table('stock_positions')->where('company_id', $data['company_id'])->where('plant_id', $data['plant_id'])
                    ->where('item_id', $line->item_id)->where('lot_id', $line->lot_id)->where('inventory_owner_id', $data['company_id'])
                    ->where('location_id', $location->id)->where('quality_status', 'RETURN_QUARANTINE')->where('uom_code', $line->uom_code)
                    ->lockForUpdate()->first();
                if (! $position) {
                    $positionId = (string) Str::uuid();
                    DB::table('stock_positions')->insert([
                        'id' => $positionId, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                        'item_id' => $line->item_id, 'lot_id' => $line->lot_id, 'owner_party_id' => null,
                        'inventory_owner_id' => $data['company_id'], 'location_id' => $location->id,
                        'quality_status' => 'RETURN_QUARANTINE', 'quantity_base' => 0, 'reserved_quantity_base' => 0,
                        'uom_code' => $line->uom_code, 'record_version' => 1, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $position = DB::table('stock_positions')->where('id', $positionId)->lockForUpdate()->first();
                }
                $movement = $this->stock->receive([
                    'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'target_position_id' => $position->id, 'quantity_base' => $line->claimed_quantity,
                    'uom_code' => $line->uom_code, 'expected_item_id' => $line->item_id,
                    'expected_lot_id' => $line->lot_id, 'expected_owner_id' => $data['company_id'],
                    'expected_quality_status' => 'RETURN_QUARANTINE', 'movement_type' => 'CUSTOMER_RETURN_RECEIPT',
                    'source_type' => 'customer_claim', 'source_id' => $id, 'source_version' => (int) $claim->record_version + 1,
                    'actor_id' => $data['actor_id'], 'reason_code' => $claim->claim_type,
                    'idempotency_key' => $this->childKey($data['idempotency_key'], 'return-'.$index),
                    'correlation_id' => $data['correlation_id'] ?? null, 'event_at' => $now,
                ]);
                DB::table('customer_claim_lines')->where('id', $line->id)->update([
                    'received_quantity' => $line->claimed_quantity, 'return_position_id' => $position->id,
                    'stock_movement_id' => $movement['movement_id'], 'updated_at' => $now,
                ]);
                DB::table('shipment_lines')->where('id', $line->shipment_line_id)->increment('returned_quantity', $line->claimed_quantity, ['updated_at' => $now]);
                $received = bcadd($received, (string) $line->claimed_quantity, 6);
            }
            DB::table('customer_claim_actions')->insert([
                'id' => (string) Str::uuid(), 'customer_claim_id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'action_type' => 'RETURN_RECEIPT', 'notes' => $this->nullable($data['notes'] ?? null),
                'actor_id' => $data['actor_id'], 'created_at' => $now,
            ]);
            $version = (int) $claim->record_version + 1;
            DB::table('customer_claims')->where('id', $id)->update([
                'status' => 'RECEIVED', 'received_at' => $now, 'received_by' => $data['actor_id'],
                'record_version' => $version, 'updated_at' => $now,
            ]);
            $result = $this->result('customer_claim', $id, 'RECEIVED', $version) + ['received_quantity' => $received];
            $this->record('RECEIVE_CUSTOMER_RETURN', 'sales.customer-return.received', 'customer_claim', $id, $data, $version, ['received_quantity' => $received], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function resolveClaim(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'sales.customer-claim.resolve.'.$id;
            if ($replay = $this->begin($namespace, $data + ['claim_id' => $id])) return $replay;
            $claim = $this->find('customer_claims', $id, $data, 'Customer claim', true);
            $this->assertVersion($claim, $data, 'customer claim');
            $resolution = $data['resolution_type'];
            if ($claim->status === 'RETURN_REQUIRED') {
                throw ValidationException::withMessages(['status' => ['Receive the returned goods before resolving this claim.']]);
            }
            $this->assertStatus($claim, ['OPEN', 'RECEIVED'], 'Only an open or received claim can be resolved.');
            if ($claim->requested_resolution === 'RETURN_CREDIT' && $resolution !== 'CREDIT') {
                throw ValidationException::withMessages(['resolution_type' => ['A received return-credit claim must be resolved with a credit.']]);
            }
            $now = CarbonImmutable::now(); $amount = '0.0000'; $replacementOrderId = null;
            if ($resolution === 'CREDIT') {
                if (! $claim->invoice_id) throw ValidationException::withMessages(['invoice' => ['The claim has no linked receivable invoice.']]);
                $invoice = DB::table('sales_invoice_financials')->where('invoice_id', $claim->invoice_id)
                    ->where('company_id', $data['company_id'])->where('plant_id', $data['plant_id'])->lockForUpdate()->first();
                if (! $invoice) throw new NotFoundHttpException('Receivable invoice not found.');
                $amount = $this->money($data['credit_amount'] ?? $invoice->outstanding_amount, 4);
                if (bccomp($amount, '0', 4) <= 0 || bccomp($amount, (string) $invoice->outstanding_amount, 4) > 0) {
                    throw ValidationException::withMessages(['credit_amount' => ['Credit must be positive and no greater than the current invoice balance.']]);
                }
                $after = bcsub((string) $invoice->outstanding_amount, $amount, 4);
                DB::table('sales_invoice_financials')->where('invoice_id', $invoice->invoice_id)->update([
                    'credited_amount' => bcadd((string) $invoice->credited_amount, $amount, 4),
                    'outstanding_amount' => $after, 'record_version' => (int) $invoice->record_version + 1, 'updated_at' => $now,
                ]);
                DB::table('invoices')->where('id', $invoice->invoice_id)->update([
                    'status' => bccomp($after, '0', 4) === 0 ? 'CREDITED' : 'PARTIALLY_PAID',
                    'record_version' => DB::raw('record_version + 1'), 'updated_at' => $now,
                ]);
                DB::table('receivable_transactions')->insert([
                    'id' => (string) Str::uuid(), 'invoice_id' => $invoice->invoice_id,
                    'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'transaction_type' => 'CREDIT',
                    'reference_number' => $claim->claim_number, 'amount' => $amount,
                    'balance_before' => $invoice->outstanding_amount, 'balance_after' => $after,
                    'customer_claim_id' => $claim->id, 'actor_id' => $data['actor_id'], 'posted_at' => $now, 'created_at' => $now,
                ]);
            } elseif ($resolution === 'REPLACEMENT') {
                $replacementOrderId = $this->replacementOrder($claim, $data, $now);
            } elseif ($resolution !== 'REJECT') {
                throw ValidationException::withMessages(['resolution_type' => ['Resolution must be CREDIT, REPLACEMENT, or REJECT.']]);
            }
            $status = $resolution === 'REJECT' ? 'REJECTED' : 'RESOLVED';
            DB::table('customer_claim_actions')->insert([
                'id' => (string) Str::uuid(), 'customer_claim_id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'action_type' => $resolution, 'amount' => $resolution === 'CREDIT' ? $amount : null,
                'invoice_id' => $resolution === 'CREDIT' ? $claim->invoice_id : null,
                'replacement_sales_order_id' => $replacementOrderId, 'notes' => trim($data['notes']),
                'actor_id' => $data['actor_id'], 'created_at' => $now,
            ]);
            $version = (int) $claim->record_version + 1;
            DB::table('customer_claims')->where('id', $id)->update([
                'status' => $status, 'resolved_at' => $now, 'resolved_by' => $data['actor_id'],
                'resolution_type' => $resolution, 'resolution_notes' => trim($data['notes']),
                'replacement_sales_order_id' => $replacementOrderId, 'credit_amount' => $amount,
                'record_version' => $version, 'updated_at' => $now,
            ]);
            $result = $this->result('customer_claim', $id, $status, $version) + [
                'resolution_type' => $resolution, 'credit_amount' => $amount, 'replacement_sales_order_id' => $replacementOrderId,
            ];
            $this->record('RESOLVE_CUSTOMER_CLAIM', 'sales.customer-claim.resolved', 'customer_claim', $id, $data, $version, ['resolution_type' => $resolution, 'credit_amount' => $amount], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function collectReceivable(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'finance.receivable.collect';
            if ($replay = $this->begin($namespace, $data)) return $replay;
            $this->uniqueNumber('customer_receipts', 'receipt_number', $data['receipt_number'], $data);
            if (DB::table('customer_receipts')->where('company_id', $data['company_id'])->where('bank_reference', $data['bank_reference'])->exists()) {
                throw ValidationException::withMessages(['bank_reference' => ['That bank reference was already posted.']]);
            }
            $this->assertCustomer($data['customer_party_id'], $data);
            $total = $this->money($data['total_amount'], 4); $allocated = '0.0000'; $now = CarbonImmutable::now();
            $receiptId = (string) Str::uuid();
            DB::table('customer_receipts')->insert([
                'id' => $receiptId, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'receipt_number' => $data['receipt_number'], 'customer_party_id' => $data['customer_party_id'],
                'receipt_date' => $data['receipt_date'], 'payment_method' => $data['payment_method'],
                'bank_reference' => $data['bank_reference'], 'currency' => 'INR', 'total_amount' => $total,
                'status' => 'POSTED', 'record_version' => 1, 'created_by' => $data['actor_id'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($data['allocations'] as $input) {
                $invoice = DB::table('sales_invoice_financials')->where('invoice_id', $input['invoice_id'])
                    ->where('company_id', $data['company_id'])->where('plant_id', $data['plant_id'])->lockForUpdate()->first();
                if (! $invoice || $invoice->party_id !== $data['customer_party_id']) {
                    throw ValidationException::withMessages(['allocations' => ['An invoice is not an open receivable for this customer and plant.']]);
                }
                $amount = $this->money($input['amount'], 4);
                if (bccomp($amount, '0', 4) <= 0 || bccomp($amount, (string) $invoice->outstanding_amount, 4) > 0) {
                    throw ValidationException::withMessages(['allocations' => ['Allocation must be positive and no greater than the invoice balance.']]);
                }
                $after = bcsub((string) $invoice->outstanding_amount, $amount, 4);
                DB::table('customer_receipt_allocations')->insert([
                    'id' => (string) Str::uuid(), 'customer_receipt_id' => $receiptId, 'invoice_id' => $invoice->invoice_id,
                    'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'allocated_amount' => $amount, 'created_at' => $now,
                ]);
                DB::table('sales_invoice_financials')->where('invoice_id', $invoice->invoice_id)->update([
                    'paid_amount' => bcadd((string) $invoice->paid_amount, $amount, 4), 'outstanding_amount' => $after,
                    'record_version' => (int) $invoice->record_version + 1, 'updated_at' => $now,
                ]);
                DB::table('invoices')->where('id', $invoice->invoice_id)->update([
                    'status' => bccomp($after, '0', 4) === 0 ? 'PAID' : 'PARTIALLY_PAID',
                    'paid_amount' => DB::raw('paid_amount + '.(float) $amount), 'record_version' => DB::raw('record_version + 1'), 'updated_at' => $now,
                ]);
                DB::table('receivable_transactions')->insert([
                    'id' => (string) Str::uuid(), 'invoice_id' => $invoice->invoice_id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'transaction_type' => 'PAYMENT', 'reference_number' => $data['receipt_number'].'-'.$invoice->invoice_number,
                    'amount' => $amount, 'balance_before' => $invoice->outstanding_amount, 'balance_after' => $after,
                    'customer_receipt_id' => $receiptId, 'actor_id' => $data['actor_id'], 'posted_at' => $now, 'created_at' => $now,
                ]);
                $allocated = bcadd($allocated, $amount, 4);
            }
            if (bccomp($allocated, $total, 4) !== 0) {
                throw ValidationException::withMessages(['allocations' => ['Invoice allocations must equal the receipt total.']]);
            }
            $result = $this->result('customer_receipt', $receiptId, 'POSTED', 1) + ['allocated_amount' => $allocated];
            $this->record('POST_CUSTOMER_RECEIPT', 'finance.customer-receipt.posted', 'customer_receipt', $receiptId, $data, 1, ['allocated_amount' => $allocated, 'allocation_count' => count($data['allocations'])], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    private function rewriteOrder(string $id, array $data, bool $amendment, ?string $reason): array
    {
        return DB::transaction(function () use ($id, $data, $amendment, $reason): array {
            $namespace = 'sales.order.'.($amendment ? 'amend.' : 'update.').$id;
            if ($replay = $this->begin($namespace, $data + ['order_id' => $id, 'reason' => $reason])) return $replay;
            $order = $this->find('sales_orders', $id, $data, 'Sales order', true);
            $this->assertVersion($order, $data, 'sales order');
            $this->assertStatus($order, $amendment ? ['CONFIRMED'] : ['DRAFT'], $amendment ? 'Only a confirmed, unallocated order can be amended.' : 'Only a draft order can be edited.');
            if ($amendment && DB::table('sales_allocations')->where('sales_order_id', $id)->whereNot('status', 'CANCELLED')->exists()) {
                throw ValidationException::withMessages(['status' => ['A sales order with an active allocation cannot be amended.']]);
            }
            $this->assertCustomer($data['customer_party_id'], $data);
            $this->assertDateRange($data['order_date'], $data['requested_delivery_date'], 'requested_delivery_date');
            if ($amendment) $this->releaseContractConsumption($id);
            $pricing = $this->priceOrder($data); $credit = $this->creditSnapshot($data['customer_party_id'], $data, $amendment);
            if ($amendment && bccomp(bcadd($credit['exposure'], $pricing['total'], 6), $credit['limit'], 6) > 0) {
                throw ValidationException::withMessages(['credit' => ['The amended order would exceed the customer credit limit.']]);
            }
            $version = (int) $order->record_version + 1; $now = CarbonImmutable::now();
            DB::table('sales_orders')->where('id', $id)->update([
                'customer_party_id' => $data['customer_party_id'], 'sales_lead_id' => $data['sales_lead_id'] ?? null,
                'sales_contract_id' => $data['sales_contract_id'] ?? null, 'sales_price_list_id' => $data['sales_price_list_id'] ?? null,
                'order_date' => $data['order_date'], 'requested_delivery_date' => $data['requested_delivery_date'],
                'subtotal' => $pricing['subtotal'], 'discount_amount' => $pricing['discount'], 'tax_amount' => $pricing['tax'],
                'total_amount' => $pricing['total'], 'credit_limit_snapshot' => $credit['limit'], 'credit_exposure_snapshot' => $credit['exposure'],
                'notes' => $this->nullable($data['notes'] ?? null), 'record_version' => $version, 'updated_at' => $now,
            ]);
            DB::table('sales_order_lines')->where('sales_order_id', $id)->delete();
            $this->insertOrderLines($id, $pricing['lines'], $data, $now);
            $revision = (int) DB::table('sales_order_revisions')->where('sales_order_id', $id)->max('revision_number') + 1;
            $this->orderRevision($id, $revision, $amendment ? 'AMENDMENT' : 'INITIAL', $reason ?? 'Draft update', $data, $pricing, $now);
            if ($amendment) {
                foreach ($pricing['lines'] as $line) {
                    if ($line['sales_contract_line_id']) DB::table('sales_contract_lines')->where('id', $line['sales_contract_line_id'])->increment('consumed_quantity', $line['ordered_quantity'], ['updated_at' => $now]);
                }
            }
            $result = $this->result('sales_order', $id, $order->status, $version) + ['total_amount' => $pricing['total'], 'revision_number' => $revision];
            $this->record($amendment ? 'AMEND_SALES_ORDER' : 'UPDATE_SALES_ORDER', $amendment ? 'sales.order.amended' : 'sales.order.updated', 'sales_order', $id, $data, $version, ['revision_number' => $revision, 'total_amount' => $pricing['total']], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    private function leadTransition(string $id, string $status, string $event, array $from, array $data, array $extra, ?string $notes = null): array
    {
        return DB::transaction(function () use ($id, $status, $event, $from, $data, $extra, $notes): array {
            $namespace = 'sales.lead.'.Str::lower($event).'.'.$id;
            if ($replay = $this->begin($namespace, $data + ['lead_id' => $id, 'status' => $status, 'notes' => $notes])) return $replay;
            $lead = $this->find('sales_leads', $id, $data, 'Sales lead', true);
            $this->assertVersion($lead, $data, 'sales lead');
            $this->assertStatus($lead, $from, 'The lead is not in a valid state for this action.');
            $version = (int) $lead->record_version + 1; $now = CarbonImmutable::now();
            $fields = ['status' => $status, 'record_version' => $version, 'updated_at' => $now] + $extra;
            if ($status === 'QUALIFIED') $fields += ['qualified_at' => $now, 'qualified_by' => $data['actor_id']];
            if ($status === 'CONVERTED') $fields += ['converted_at' => $now, 'converted_by' => $data['actor_id']];
            if (in_array($status, ['WON', 'LOST'], true)) $fields += ['closed_at' => $now, 'closed_by' => $data['actor_id']];
            DB::table('sales_leads')->where('id', $id)->update($fields);
            $this->leadEvent($id, $event, $notes, $data, $now);
            $result = $this->result('sales_lead', $id, $status, $version);
            $this->record($event.'_SALES_LEAD', 'sales.lead.'.Str::lower($event), 'sales_lead', $id, $data, $version, ['status' => ['from' => $lead->status, 'to' => $status]], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    private function simpleTransition(string $table, string $id, string $label, array $from, string $to, string $namespaceBase, array $data, array $extra): array
    {
        return DB::transaction(function () use ($table, $id, $label, $from, $to, $namespaceBase, $data, $extra): array {
            $namespace = $namespaceBase.'.'.$id;
            if ($replay = $this->begin($namespace, $data + ['id' => $id, 'target_status' => $to, 'values' => $extra])) return $replay;
            $record = $this->find($table, $id, $data, $label, true);
            $this->assertVersion($record, $data, Str::lower($label));
            $this->assertStatus($record, $from, "{$label} is not in a valid state for this action.");
            if ($table === 'sales_price_lists' && $to === 'ACTIVE' && ! DB::table('sales_price_list_lines')->where('sales_price_list_id', $id)->exists()) {
                throw ValidationException::withMessages(['lines' => ['A price list requires at least one line before activation.']]);
            }
            if ($table === 'sales_contracts' && $to === 'ACTIVE' && ! DB::table('sales_contract_lines')->where('sales_contract_id', $id)->exists()) {
                throw ValidationException::withMessages(['lines' => ['A sales contract requires at least one line before activation.']]);
            }
            $version = (int) $record->record_version + 1;
            DB::table($table)->where('id', $id)->update(['status' => $to, 'record_version' => $version, 'updated_at' => now()] + $extra);
            $entity = (string) Str::of($table)->singular()->replace('-', '_');
            $result = $this->result($entity, $id, $to, $version);
            $this->record(Str::upper(str_replace('.', '_', $namespaceBase)), $namespaceBase.'d', $entity, $id, $data, $version, ['status' => ['from' => $record->status, 'to' => $to]], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    private function replacePriceLines(string $priceListId, array $lines, array $data, CarbonImmutable $now): void
    {
        $seen = [];
        foreach ($lines as $index => $line) {
            $key = $line['item_id'].'|'.$line['uom_code'].'|'.$this->decimal($line['minimum_quantity']);
            if (isset($seen[$key])) throw ValidationException::withMessages(['lines' => ['Price breaks must be unique by item, UOM, and minimum quantity.']]);
            $seen[$key] = true;
            $this->assertItem($line['item_id'], $line['uom_code'], $data);
            DB::table('sales_price_list_lines')->insert([
                'id' => (string) Str::uuid(), 'sales_price_list_id' => $priceListId,
                'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'line_number' => $index + 1,
                'item_id' => $line['item_id'], 'uom_code' => $line['uom_code'],
                'minimum_quantity' => $this->positive($line['minimum_quantity'], 'lines', 'Minimum quantity'),
                'unit_price' => $this->money($line['unit_price']),
                'maximum_discount_percent' => $this->rate($line['maximum_discount_percent'] ?? 0),
                'tax_rate' => $this->rate($line['tax_rate'] ?? 0), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function replaceContractLines(string $contractId, array $lines, array $data, CarbonImmutable $now): void
    {
        $seen = [];
        foreach ($lines as $index => $line) {
            $key = $line['item_id'].'|'.$line['uom_code'];
            if (isset($seen[$key])) throw ValidationException::withMessages(['lines' => ['Contract items must be unique.']]);
            $seen[$key] = true;
            $this->assertItem($line['item_id'], $line['uom_code'], $data);
            DB::table('sales_contract_lines')->insert([
                'id' => (string) Str::uuid(), 'sales_contract_id' => $contractId,
                'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'line_number' => $index + 1,
                'item_id' => $line['item_id'], 'uom_code' => $line['uom_code'],
                'committed_quantity' => $this->positive($line['committed_quantity'], 'lines', 'Committed quantity'),
                'consumed_quantity' => 0, 'unit_price' => $this->money($line['unit_price']),
                'tax_rate' => $this->rate($line['tax_rate'] ?? 0), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function priceOrder(array $data): array
    {
        $date = $data['order_date'];
        $priceList = null; $contract = null;
        if (! empty($data['sales_price_list_id'])) {
            $priceList = $this->find('sales_price_lists', $data['sales_price_list_id'], $data, 'Price list');
            if ($priceList->status !== 'ACTIVE' || $priceList->effective_from > $date || ($priceList->effective_to && $priceList->effective_to < $date)) {
                throw ValidationException::withMessages(['sales_price_list_id' => ['The price list is not active on the order date.']]);
            }
        }
        if (! empty($data['sales_contract_id'])) {
            $contract = $this->find('sales_contracts', $data['sales_contract_id'], $data, 'Sales contract');
            if ($contract->status !== 'ACTIVE' || $contract->customer_party_id !== $data['customer_party_id']
                || $contract->effective_from > $date || $contract->effective_to < $date) {
                throw ValidationException::withMessages(['sales_contract_id' => ['The contract is not active for this customer and order date.']]);
            }
        }
        if (! $priceList && ! $contract) {
            throw ValidationException::withMessages(['pricing' => ['Select an active price list or sales contract.']]);
        }
        if (! empty($data['sales_lead_id'])) {
            $lead = $this->find('sales_leads', $data['sales_lead_id'], $data, 'Sales lead');
            if (! in_array($lead->status, ['QUALIFIED', 'CONVERTED'], true)) throw ValidationException::withMessages(['sales_lead_id' => ['Only a qualified or converted lead can be linked.']]);
        }
        $seen = []; $priced = []; $subtotal = '0.000000'; $discountTotal = '0.000000'; $taxTotal = '0.000000';
        foreach ($data['lines'] as $index => $input) {
            $key = $input['item_id'].'|'.$input['uom_code'];
            if (isset($seen[$key])) throw ValidationException::withMessages(['lines' => ['Sales-order items must be unique.']]);
            $seen[$key] = true;
            $item = $this->assertItem($input['item_id'], $input['uom_code'], $data);
            $quantity = $this->positive($input['quantity'], 'lines', 'Order quantity');
            $contractLine = $contract ? DB::table('sales_contract_lines')->where('sales_contract_id', $contract->id)
                ->where('item_id', $input['item_id'])->where('uom_code', $input['uom_code'])->first() : null;
            $priceLine = $priceList ? DB::table('sales_price_list_lines')->where('sales_price_list_id', $priceList->id)
                ->where('item_id', $input['item_id'])->where('uom_code', $input['uom_code'])
                ->where('minimum_quantity', '<=', $quantity)->orderByDesc('minimum_quantity')->first() : null;
            $source = $contractLine ?? $priceLine;
            if (! $source) throw ValidationException::withMessages(['lines' => ["No effective price exists for order line ".($index + 1).'.']]);
            if ($contractLine && bccomp(bcadd((string) $contractLine->consumed_quantity, $quantity, 6), (string) $contractLine->committed_quantity, 6) > 0) {
                throw ValidationException::withMessages(['lines' => ["Order line ".($index + 1).' exceeds the remaining contract quantity.']]);
            }
            $discountRate = $this->rate($input['discount_percent'] ?? 0);
            $maximum = $contractLine ? '0.0000' : $this->rate($priceLine->maximum_discount_percent);
            if (bccomp($discountRate, $maximum, 4) > 0) throw ValidationException::withMessages(['lines' => ["Discount on line ".($index + 1).' exceeds the authorised price-list maximum.']]);
            $base = $this->decimal(bcmul($quantity, (string) $source->unit_price, 8));
            $discount = $this->decimal(bcdiv(bcmul($base, $discountRate, 8), '100', 8));
            $net = bcsub($base, $discount, 6);
            $taxRate = $this->rate($source->tax_rate);
            $tax = $this->decimal(bcdiv(bcmul($net, $taxRate, 8), '100', 8));
            $cost = DB::table('batch_costs as cost')->join('production_orders as production', 'production.id', '=', 'cost.production_order_id')
                ->where('cost.company_id', $data['company_id'])->where('cost.plant_id', $data['plant_id'])
                ->where('production.output_sku_id', $input['item_id'])->orderByDesc('cost.calculated_at')->value('cost.cost_per_good_unit');
            // Preserve order capture when costing is not yet finalized. Profitability marks this
            // snapshot as incomplete instead of presenting a false 100% margin.
            $cost ??= 0;
            $priced[] = [
                'line_number' => $index + 1, 'item_id' => $input['item_id'], 'description' => $item->name,
                'uom_code' => $input['uom_code'], 'ordered_quantity' => $quantity, 'unit_price' => $this->decimal($source->unit_price),
                'discount_percent' => $discountRate, 'tax_rate' => $taxRate, 'net_amount' => $net,
                'tax_amount' => $tax, 'gross_amount' => bcadd($net, $tax, 6), 'unit_cost_snapshot' => $this->decimal($cost),
                'sales_contract_id' => $contractLine ? $contract->id : null,
                'sales_contract_line_id' => $contractLine?->id,
            ];
            $subtotal = bcadd($subtotal, $base, 6); $discountTotal = bcadd($discountTotal, $discount, 6); $taxTotal = bcadd($taxTotal, $tax, 6);
        }
        return ['lines' => $priced, 'subtotal' => $subtotal, 'discount' => $discountTotal, 'tax' => $taxTotal,
            'total' => bcadd(bcsub($subtotal, $discountTotal, 6), $taxTotal, 6)];
    }

    private function insertOrderLines(string $orderId, array $lines, array $data, CarbonImmutable $now): void
    {
        foreach ($lines as $line) {
            DB::table('sales_order_lines')->insert([
                'id' => (string) Str::uuid(), 'sales_order_id' => $orderId, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'line_number' => $line['line_number'], 'item_id' => $line['item_id'], 'description' => $line['description'],
                'uom_code' => $line['uom_code'], 'ordered_quantity' => $line['ordered_quantity'],
                'allocated_quantity' => 0, 'dispatched_quantity' => 0, 'invoiced_quantity' => 0,
                'unit_price' => $line['unit_price'], 'discount_percent' => $line['discount_percent'], 'tax_rate' => $line['tax_rate'],
                'net_amount' => $line['net_amount'], 'tax_amount' => $line['tax_amount'], 'gross_amount' => $line['gross_amount'],
                'unit_cost_snapshot' => $line['unit_cost_snapshot'], 'sales_contract_id' => $line['sales_contract_id'],
                'sales_contract_line_id' => $line['sales_contract_line_id'], 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function orderRevision(string $orderId, int $revision, string $type, string $reason, array $data, array $pricing, CarbonImmutable $now): void
    {
        DB::table('sales_order_revisions')->insert([
            'id' => (string) Str::uuid(), 'sales_order_id' => $orderId, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
            'revision_number' => $revision, 'revision_type' => $type, 'reason' => trim($reason),
            'snapshot_json' => json_encode(['customer_party_id' => $data['customer_party_id'], 'order_date' => $data['order_date'],
                'requested_delivery_date' => $data['requested_delivery_date'], 'pricing' => $pricing], JSON_THROW_ON_ERROR),
            'created_by' => $data['actor_id'], 'created_at' => $now,
        ]);
    }

    private function creditSnapshot(string $customerId, array $scope, bool $lock = false): array
    {
        $query = DB::table('customer_credit_profiles')->where('company_id', $scope['company_id'])->where('customer_party_id', $customerId);
        $profile = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $profile) throw ValidationException::withMessages(['customer_party_id' => ['Configure customer credit before creating an order.']]);
        if ($profile->is_on_hold) throw ValidationException::withMessages(['credit' => ['The customer is on credit hold: '.$profile->hold_reason]]);
        $exposure = DB::table('sales_invoice_financials')->where('company_id', $scope['company_id'])->where('party_id', $customerId)->sum('outstanding_amount');
        return ['limit' => $this->decimal($profile->credit_limit), 'exposure' => $this->decimal($exposure), 'terms' => (int) $profile->payment_terms_days];
    }

    private function releaseContractConsumption(string $orderId): void
    {
        $lines = DB::table('sales_order_lines')->where('sales_order_id', $orderId)->whereNotNull('sales_contract_line_id')->lockForUpdate()->get();
        foreach ($lines as $line) {
            $contractLine = DB::table('sales_contract_lines')->where('id', $line->sales_contract_line_id)->lockForUpdate()->first();
            if ($contractLine) DB::table('sales_contract_lines')->where('id', $contractLine->id)->update([
                'consumed_quantity' => bcsub((string) $contractLine->consumed_quantity, (string) $line->ordered_quantity, 6), 'updated_at' => now(),
            ]);
        }
    }

    private function replacementOrder(object $claim, array $data, CarbonImmutable $now): string
    {
        $id = (string) Str::uuid(); $number = mb_substr('REP-'.$claim->claim_number, 0, 80);
        if (DB::table('sales_orders')->where('company_id', $data['company_id'])->where('plant_id', $data['plant_id'])->where('order_number', $number)->exists()) {
            $number = mb_substr($number.'-'.Str::lower(Str::random(6)), 0, 80);
        }
        $sourceOrder = DB::table('shipments')->where('id', $claim->shipment_id)->value('sales_order_id');
        DB::table('sales_orders')->insert([
            'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'order_type' => 'SALES',
            'order_number' => $number, 'customer_party_id' => $claim->customer_party_id, 'order_date' => $now->toDateString(),
            'requested_delivery_date' => $now->addDays(7)->toDateString(), 'currency' => 'INR', 'subtotal' => 0,
            'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'credit_limit_snapshot' => 0,
            'credit_exposure_snapshot' => 0, 'notes' => 'Replacement for claim '.$claim->claim_number,
            'status' => 'CONFIRMED', 'record_version' => 1, 'created_by' => $data['actor_id'],
            'confirmed_at' => $now, 'confirmed_by' => $data['actor_id'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        $lines = DB::table('customer_claim_lines as claim_line')->join('items as item', 'item.id', '=', 'claim_line.item_id')
            ->where('claim_line.customer_claim_id', $claim->id)->orderBy('claim_line.line_number')->get(['claim_line.*', 'item.name']);
        foreach ($lines as $line) {
            DB::table('sales_order_lines')->insert([
                'id' => (string) Str::uuid(), 'sales_order_id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'line_number' => $line->line_number, 'item_id' => $line->item_id, 'description' => 'Replacement - '.$line->name,
                'uom_code' => $line->uom_code, 'ordered_quantity' => $line->claimed_quantity,
                'allocated_quantity' => 0, 'dispatched_quantity' => 0, 'invoiced_quantity' => 0,
                'unit_price' => 0, 'discount_percent' => 0, 'tax_rate' => 0, 'net_amount' => 0, 'tax_amount' => 0,
                'gross_amount' => 0, 'unit_cost_snapshot' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('sales_order_revisions')->insert([
            'id' => (string) Str::uuid(), 'sales_order_id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
            'revision_number' => 1, 'revision_type' => 'INITIAL', 'reason' => 'Replacement for customer claim '.$claim->claim_number,
            'snapshot_json' => json_encode(['claim_id' => $claim->id, 'source_sales_order_id' => $sourceOrder], JSON_THROW_ON_ERROR),
            'created_by' => $data['actor_id'], 'created_at' => $now,
        ]);
        return $id;
    }

    private function leadEvent(string $leadId, string $type, mixed $notes, array $data, ?CarbonImmutable $at = null): void
    {
        DB::table('sales_lead_events')->insert([
            'id' => (string) Str::uuid(), 'sales_lead_id' => $leadId, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
            'event_type' => $type, 'notes' => $this->nullable($notes), 'actor_id' => $data['actor_id'],
            'occurred_at' => $at ?? now(), 'created_at' => $at ?? now(),
        ]);
    }

    private function find(string $table, string $id, array $scope, string $label, bool $lock = false): object
    {
        $query = DB::table($table)->where('id', $id)->where('company_id', $scope['company_id']);
        if (array_key_exists('plant_id', $scope) && $scope['plant_id']) $query->where('plant_id', $scope['plant_id']);
        $record = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $record) throw new NotFoundHttpException($label.' not found.');
        return $record;
    }

    private function assertItem(string $id, string $uom, array $scope): object
    {
        $item = DB::table('items')->where('id', $id)->where('company_id', $scope['company_id'])->where('base_uom', $uom)->where('status', 'ACTIVE')->first();
        if (! $item) throw ValidationException::withMessages(['item_id' => ['Active stock item with the submitted base UOM not found.']]);
        return $item;
    }

    private function assertParty(string $id, array $scope): object
    {
        $party = DB::table('parties')->where('id', $id)->where('company_id', $scope['company_id'])->where('status', 'ACTIVE')->first();
        if (! $party) throw ValidationException::withMessages(['party_id' => ['Active party not found in the selected company.']]);
        return $party;
    }

    private function assertCustomer(?string $id, array $scope, bool $nullable = false): ?object
    {
        if (! $id && $nullable) return null;
        if (! $id) throw ValidationException::withMessages(['customer_party_id' => ['Customer is required.']]);
        $party = $this->assertParty($id, $scope);
        if (! DB::table('party_roles')->where('party_id', $id)->where('company_id', $scope['company_id'])->where('role_code', 'CUSTOMER')->exists()) {
            throw ValidationException::withMessages(['customer_party_id' => ['The selected party is not an active customer.']]);
        }
        return $party;
    }

    private function uniqueNumber(string $table, string $column, string $number, array $scope, ?string $extraColumn = null, ?string $extraValue = null): void
    {
        $query = DB::table($table)->where('company_id', $scope['company_id'])->where($column, $number);
        if (isset($scope['plant_id'])) $query->where('plant_id', $scope['plant_id']);
        if ($extraColumn) $query->where($extraColumn, $extraValue);
        if ($query->exists()) throw ValidationException::withMessages([$column => ['That identifier already exists in the selected scope.']]);
    }

    private function assertVersion(object $record, array $data, string $label): void
    {
        if ((int) $record->record_version !== (int) $data['expected_version']) {
            throw new ConflictHttpException("The {$label} changed from version {$data['expected_version']} to {$record->record_version}. Refresh it before continuing.");
        }
    }

    private function assertStatus(object $record, array $statuses, string $message): void
    {
        if (! in_array($record->status, $statuses, true)) throw ValidationException::withMessages(['status' => [$message]]);
    }

    private function assertDateRange(string $from, ?string $to, string $field): void
    {
        if ($to !== null && $to < $from) throw ValidationException::withMessages([$field => ['The end date must not be before the start date.']]);
    }

    private function result(string $entity, string $id, string $status, int $version): array
    {
        return ['entity_type' => $entity, 'id' => $id, 'status' => $status, 'record_version' => $version];
    }

    private function record(string $command, string $event, string $entity, string $id, array $data, int $version, array $safeDiff, array $result): void
    {
        $this->audit->record($command, $entity, $id, $data['actor_id'], $data['company_id'], $data['plant_id'], 'SUCCESS', [
            'entity_version' => $version, 'correlation_id' => $data['correlation_id'] ?? null, 'safe_diff' => $safeDiff,
        ]);
        $this->outbox->append($event, $entity, $id, $id.':'.$version, $result + ['company_id' => $data['company_id'], 'plant_id' => $data['plant_id']],
            $data['correlation_id'] ?? null, $data['company_id'], $data['plant_id']);
    }

    private function begin(string $namespace, array $data): ?array
    {
        return $this->idempotency->begin($namespace, $data['idempotency_key'], Arr::except($data, ['actor_id', 'permissions', 'idempotency_key', 'correlation_id']));
    }

    private function complete(string $namespace, array $data, array $result): void
    {
        $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
    }

    private function positive(mixed $value, string $field, string $label): string
    {
        $text = (string) $value;
        if (! preg_match('/^\d{1,14}(?:\.\d{1,6})?$/', $text) || bccomp($text, '0', 6) <= 0) {
            throw ValidationException::withMessages([$field => ["{$label} must be positive with at most six decimal places."]]);
        }
        return $this->decimal($text);
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 6);
    }

    private function money(mixed $value, int $scale = 6): string
    {
        $text = (string) ($value ?? 0);
        if (! preg_match('/^\d{1,14}(?:\.\d{1,6})?$/', $text)) throw ValidationException::withMessages(['amount' => ['Amounts must be non-negative with at most six decimal places.']]);
        return bcadd($text, '0', $scale);
    }

    private function rate(mixed $value): string
    {
        $rate = bcadd((string) ($value ?? 0), '0', 4);
        if (bccomp($rate, '0', 4) < 0 || bccomp($rate, '100', 4) > 0) throw ValidationException::withMessages(['rate' => ['Rates must be between 0 and 100.']]);
        return $rate;
    }

    private function nullable(mixed $value): ?string
    {
        $value = $value === null ? '' : trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function childKey(string $parent, string $suffix): string
    {
        return 'p2-'.hash('sha256', $parent.'|'.$suffix);
    }
}
