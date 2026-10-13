<?php

namespace App\Modules\Partner\Application;

use App\Modules\Sales\Application\OrderToCashService;
use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use App\Shared\Visibility\TransactionVisibilityPolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PartnerPortalService
{
    public const ENTITLEMENTS = [
        'ORDERS_VIEW',
        'SHIPMENTS_VIEW',
        'INVOICES_VIEW',
        'CLAIMS_VIEW',
        'CLAIMS_CREATE',
        'DOCUMENTS_VIEW',
        'DOCUMENT_UPLOAD',
        'DOCUMENT_DOWNLOAD',
        'DOCUMENT_ACKNOWLEDGE',
    ];

    public const DOCUMENT_TYPES = [
        'ORDER_CONFIRMATION',
        'SHIPPING_DOCUMENT',
        'INVOICE',
        'CLAIM_EVIDENCE',
        'QUALITY_CERTIFICATE',
        'GENERAL',
    ];

    public function __construct(
        private readonly PartnerAccessResolver $access,
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
        private readonly OrderToCashService $orderToCash,
        private readonly TransactionVisibilityPolicy $visibility,
    ) {}

    public function createGrant(array $data): array
    {
        $this->assertInternal($data, 'ACCESS-GRANT');
        $entitlements = $this->normaliseEntitlements($data['entitlements']);

        return DB::transaction(function () use ($data, $entitlements): array {
            $namespace = 'partner.access-grant.create';
            if ($replay = $this->begin($namespace, $data + ['entitlements' => $entitlements])) {
                return $replay;
            }

            $user = DB::table('users')->where('id', $data['user_id'])->where('status', 'ACTIVE')->lockForUpdate()->first();
            if (! $user) {
                throw ValidationException::withMessages(['user_id' => ['An active portal identity is required.']]);
            }
            $party = $this->customerParty($data['party_id'], $data, true);
            $role = DB::table('roles')->where('code', 'PARTNER_PORTAL')->where('status', 'ACTIVE')->first();
            if (! $role) {
                throw ValidationException::withMessages(['role' => ['The PARTNER_PORTAL role is not configured.']]);
            }

            $internalAssignment = DB::table('role_assignments as assignment')
                ->join('roles as role', 'role.id', '=', 'assignment.role_id')
                ->where('assignment.user_id', $data['user_id'])
                ->where('assignment.is_active', true)
                ->where('role.code', '<>', 'PARTNER_PORTAL')
                ->where(fn ($query) => $query->whereNull('assignment.company_id')->orWhere('assignment.company_id', $data['company_id']))
                ->where(fn ($query) => $query->whereNull('assignment.plant_id')->orWhere('assignment.plant_id', $data['plant_id']))
                ->exists();
            if ($internalAssignment) {
                throw ValidationException::withMessages([
                    'user_id' => ['Portal identities cannot share internal ERP role assignments in the selected scope.'],
                ]);
            }

            $activeGrant = DB::table('partner_access_grants')
                ->where('user_id', $data['user_id'])
                ->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->exists();
            if ($activeGrant) {
                throw ValidationException::withMessages([
                    'user_id' => ['This identity already has an active partner tenant in the selected context.'],
                ]);
            }

            $this->assertEffectivePeriod($data['effective_from'] ?? null, $data['effective_to'] ?? null);
            $assignmentId = (string) Str::uuid();
            $grantId = (string) Str::uuid();
            $now = now();
            DB::table('role_assignments')->insert([
                'id' => $assignmentId,
                'user_id' => $data['user_id'],
                'role_id' => $role->id,
                'company_id' => $data['company_id'],
                'plant_id' => $data['plant_id'],
                'party_id' => $party->id,
                'is_active' => true,
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'record_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('partner_access_grants')->insert([
                'id' => $grantId,
                'company_id' => $data['company_id'],
                'plant_id' => $data['plant_id'],
                'party_id' => $party->id,
                'user_id' => $user->id,
                'role_assignment_id' => $assignmentId,
                'status' => 'ACTIVE',
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'record_version' => 1,
                'created_by' => $data['actor_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->replaceEntitlements($grantId, (string) $party->id, $entitlements, $data);

            $result = $this->result('partner_access_grant', $grantId, 'ACTIVE', 1, [
                'party_id' => (string) $party->id,
                'party_name' => (string) $party->display_name,
                'user_id' => (string) $user->id,
                'entitlements' => $entitlements,
            ]);
            $this->record(
                'GRANT_PARTNER_ACCESS', 'partner.access-grant.created', 'partner_access_grant',
                $grantId, $data, 1, ['party_id' => $party->id, 'user_id' => $user->id, 'entitlements' => $entitlements], $result,
            );
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function updateGrant(string $grantId, array $data): array
    {
        $this->assertInternal($data, 'ACCESS-UPDATE');
        $entitlements = $this->normaliseEntitlements($data['entitlements']);

        return DB::transaction(function () use ($grantId, $data, $entitlements): array {
            $namespace = 'partner.access-grant.update.'.$grantId;
            if ($replay = $this->begin($namespace, $data + ['grant_id' => $grantId, 'entitlements' => $entitlements])) {
                return $replay;
            }
            $grant = $this->grantLocked($grantId, $data);
            $this->assertVersion($grant, $data['expected_version'], 'partner access grant');
            if ($grant->status !== 'ACTIVE') {
                throw ValidationException::withMessages(['status' => ['Only an active partner access grant can be updated.']]);
            }
            $this->assertEffectivePeriod($data['effective_from'] ?? null, $data['effective_to'] ?? null);

            $version = (int) $grant->record_version + 1;
            DB::table('partner_access_grants')->where('id', $grantId)->update([
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'record_version' => $version,
                'updated_at' => now(),
            ]);
            DB::table('role_assignments')->where('id', $grant->role_assignment_id)->update([
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'record_version' => DB::raw('record_version + 1'),
                'updated_at' => now(),
            ]);
            $this->replaceEntitlements($grantId, (string) $grant->party_id, $entitlements, $data);

            $result = $this->result('partner_access_grant', $grantId, 'ACTIVE', $version, [
                'party_id' => (string) $grant->party_id,
                'user_id' => (string) $grant->user_id,
                'entitlements' => $entitlements,
            ]);
            $this->record(
                'UPDATE_PARTNER_ACCESS', 'partner.access-grant.updated', 'partner_access_grant',
                $grantId, $data, $version, ['entitlements' => $entitlements, 'effective_to' => $data['effective_to'] ?? null], $result,
            );
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function revokeGrant(string $grantId, string $reason, array $data): array
    {
        $this->assertInternal($data, 'ACCESS-REVOKE');

        return DB::transaction(function () use ($grantId, $reason, $data): array {
            $namespace = 'partner.access-grant.revoke.'.$grantId;
            if ($replay = $this->begin($namespace, $data + ['grant_id' => $grantId, 'reason' => $reason])) {
                return $replay;
            }
            $grant = $this->grantLocked($grantId, $data);
            $this->assertVersion($grant, $data['expected_version'], 'partner access grant');
            if ($grant->status !== 'ACTIVE') {
                throw ValidationException::withMessages(['status' => ['The partner access grant is already revoked.']]);
            }

            $version = (int) $grant->record_version + 1;
            $now = now();
            DB::table('partner_access_grants')->where('id', $grantId)->update([
                'status' => 'REVOKED',
                'record_version' => $version,
                'revoked_at' => $now,
                'revoked_by' => $data['actor_id'],
                'revocation_reason' => $reason,
                'updated_at' => $now,
            ]);
            DB::table('role_assignments')->where('id', $grant->role_assignment_id)->update([
                'is_active' => false,
                'record_version' => DB::raw('record_version + 1'),
                'updated_at' => $now,
            ]);

            $result = $this->result('partner_access_grant', $grantId, 'REVOKED', $version, [
                'party_id' => (string) $grant->party_id,
                'user_id' => (string) $grant->user_id,
            ]);
            $this->record(
                'REVOKE_PARTNER_ACCESS', 'partner.access-grant.revoked', 'partner_access_grant',
                $grantId, $data, $version, ['status' => ['from' => 'ACTIVE', 'to' => 'REVOKED'], 'reason' => $reason], $result,
            );
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function publishDocument(UploadedFile $file, array $data): array
    {
        $this->assertInternal($data, 'DOCUMENT-PUBLISH');
        $party = $this->customerParty($data['party_id'], $data);

        return $this->storeDocument($file, 'OUTBOUND', (string) $party->id, $data);
    }

    public function uploadDocument(UploadedFile $file, array $data): array
    {
        $grant = $this->access->requireGrant(
            $data,
            $data['actor_id'],
            ['DOCUMENTS_VIEW', 'DOCUMENT_UPLOAD'],
        );

        return $this->storeDocument($file, 'INBOUND', (string) $grant->party_id, $data);
    }

    public function acknowledgeDocument(string $documentId, string $reference, array $data): array
    {
        $grant = $this->access->requireGrant(
            $data,
            $data['actor_id'],
            ['DOCUMENTS_VIEW', 'DOCUMENT_ACKNOWLEDGE'],
        );

        return DB::transaction(function () use ($documentId, $reference, $data, $grant): array {
            $namespace = 'partner.document.acknowledge.'.$documentId;
            if ($replay = $this->begin($namespace, $data + ['document_id' => $documentId, 'reference' => $reference])) {
                return $replay;
            }
            $document = $this->documentLocked($documentId, $data, (string) $grant->party_id);
            $this->assertDocumentOperationallyVisible($document, $data);
            $this->assertVersion($document, $data['expected_version'], 'partner document');
            if ($document->direction !== 'OUTBOUND' || $document->status !== 'AVAILABLE') {
                throw ValidationException::withMessages([
                    'status' => ['Only an available outbound document can be acknowledged.'],
                ]);
            }

            $version = (int) $document->record_version + 1;
            $now = now();
            DB::table('partner_documents')->where('id', $documentId)->update([
                'status' => 'ACKNOWLEDGED',
                'record_version' => $version,
                'acknowledged_at' => $now,
                'acknowledged_by' => $data['actor_id'],
                'acknowledgement_reference' => $reference,
                'updated_at' => $now,
            ]);
            $this->documentEvent($document, 'ACKNOWLEDGED', $data['actor_id'], $reference, [
                'effect' => 'RECEIPT_ONLY',
            ]);

            $result = $this->result('partner_document', $documentId, 'ACKNOWLEDGED', $version, [
                'party_id' => (string) $grant->party_id,
                'direction' => 'OUTBOUND',
                'acknowledgement_reference' => $reference,
                'business_effect' => 'RECEIPT_ONLY',
            ]);
            $this->record(
                'ACKNOWLEDGE_PARTNER_DOCUMENT', 'partner.document.acknowledged', 'partner_document',
                $documentId, $data, $version, ['status' => ['from' => 'AVAILABLE', 'to' => 'ACKNOWLEDGED'], 'business_effect' => 'RECEIPT_ONLY'], $result,
            );
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function withdrawDocument(string $documentId, string $reason, array $data): array
    {
        $this->assertInternal($data, 'DOCUMENT-WITHDRAW');

        return DB::transaction(function () use ($documentId, $reason, $data): array {
            $namespace = 'partner.document.withdraw.'.$documentId;
            if ($replay = $this->begin($namespace, $data + ['document_id' => $documentId, 'reason' => $reason])) {
                return $replay;
            }
            $document = $this->documentLocked($documentId, $data);
            $this->assertDocumentOperationallyVisible($document, $data);
            $this->assertVersion($document, $data['expected_version'], 'partner document');
            if ($document->direction !== 'OUTBOUND' || $document->status !== 'AVAILABLE') {
                throw ValidationException::withMessages([
                    'status' => ['Only an unacknowledged outbound document can be withdrawn.'],
                ]);
            }

            $version = (int) $document->record_version + 1;
            $now = now();
            DB::table('partner_documents')->where('id', $documentId)->update([
                'status' => 'WITHDRAWN',
                'record_version' => $version,
                'withdrawn_at' => $now,
                'withdrawn_by' => $data['actor_id'],
                'withdrawal_reason' => $reason,
                'updated_at' => $now,
            ]);
            $this->documentEvent($document, 'WITHDRAWN', $data['actor_id'], null, ['reason' => $reason]);

            $result = $this->result('partner_document', $documentId, 'WITHDRAWN', $version, [
                'party_id' => (string) $document->party_id,
                'direction' => 'OUTBOUND',
            ]);
            $this->record(
                'WITHDRAW_PARTNER_DOCUMENT', 'partner.document.withdrawn', 'partner_document',
                $documentId, $data, $version, ['status' => ['from' => 'AVAILABLE', 'to' => 'WITHDRAWN'], 'reason' => $reason], $result,
            );
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function createClaim(array $data): array
    {
        $grant = $this->access->requireGrant(
            $data,
            $data['actor_id'],
            ['SHIPMENTS_VIEW', 'CLAIMS_VIEW', 'CLAIMS_CREATE'],
        );
        $shipment = DB::table('shipments')
            ->where('id', $data['shipment_id'])
            ->where('company_id', $data['company_id'])
            ->where('plant_id', $data['plant_id'])
            ->where('party_id', $grant->party_id)
            ->first();
        if (! $shipment) {
            throw new NotFoundHttpException('Shipment not found.');
        }

        $result = $this->orderToCash->createClaim($data + [
            'portal_access_grant_id' => (string) $grant->id,
            'submission_channel' => 'PARTNER_PORTAL',
        ]);

        return $result + ['submitted_via' => 'PARTNER_PORTAL'];
    }

    private function storeDocument(UploadedFile $file, string $direction, string $partyId, array $data): array
    {
        $contents = file_get_contents($file->getRealPath());
        if (! is_string($contents) || $contents === '') {
            throw ValidationException::withMessages(['file' => ['The uploaded document is empty or unreadable.']]);
        }
        $checksum = hash('sha256', $contents);
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $path = 'partner/'.$data['company_id'].'/'.$data['plant_id'].'/'.$partyId.'/'.strtolower($direction).'/'.Str::uuid().'.'.$extension;
        $disk = (string) config('qtfoods.private_document_disk', 'private');
        $stored = false;

        try {
            return DB::transaction(function () use ($file, $direction, $partyId, $data, $contents, $checksum, $path, $disk, &$stored): array {
                $namespace = $direction === 'OUTBOUND' ? 'partner.document.publish' : 'partner.document.upload';
                $idempotentData = $data + [
                    'direction' => $direction,
                    'party_id' => $partyId,
                    'sha256_checksum' => $checksum,
                    'original_name' => $file->getClientOriginalName(),
                ];
                if ($replay = $this->begin($namespace, $idempotentData)) {
                    return $replay;
                }

                if (DB::table('partner_documents')->where([
                    'company_id' => $data['company_id'],
                    'plant_id' => $data['plant_id'],
                    'party_id' => $partyId,
                    'document_number' => $data['document_number'],
                ])->exists()) {
                    throw ValidationException::withMessages([
                        'document_number' => ['That partner document number already exists for this tenant.'],
                    ]);
                }
                $this->validateDocumentLinks($data, $partyId);

                Storage::disk($disk)->put($path, $contents);
                $stored = true;
                $documentId = (string) Str::uuid();
                $now = now();
                DB::table('partner_documents')->insert([
                    'id' => $documentId,
                    'company_id' => $data['company_id'],
                    'plant_id' => $data['plant_id'],
                    'party_id' => $partyId,
                    'document_number' => $data['document_number'],
                    'direction' => $direction,
                    'document_type' => $data['document_type'],
                    'title' => trim($data['title']),
                    'description' => $this->nullable($data['description'] ?? null),
                    'sales_order_id' => $data['sales_order_id'] ?? null,
                    'shipment_id' => $data['shipment_id'] ?? null,
                    'invoice_id' => $data['invoice_id'] ?? null,
                    'customer_claim_id' => $data['customer_claim_id'] ?? null,
                    'storage_disk' => 'private',
                    'storage_path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size_bytes' => strlen($contents),
                    'sha256_checksum' => $checksum,
                    'status' => 'AVAILABLE',
                    'record_version' => 1,
                    'created_by' => $data['actor_id'],
                    'available_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $document = (object) [
                    'id' => $documentId,
                    'company_id' => $data['company_id'],
                    'plant_id' => $data['plant_id'],
                    'party_id' => $partyId,
                ];
                $event = $direction === 'OUTBOUND' ? 'PUBLISHED' : 'UPLOADED';
                $this->documentEvent($document, $event, $data['actor_id'], null, [
                    'sha256_checksum' => $checksum,
                    'size_bytes' => strlen($contents),
                ]);

                $result = $this->result('partner_document', $documentId, 'AVAILABLE', 1, [
                    'party_id' => $partyId,
                    'direction' => $direction,
                    'sha256_checksum' => $checksum,
                    'size_bytes' => strlen($contents),
                ]);
                $command = $direction === 'OUTBOUND' ? 'PUBLISH_PARTNER_DOCUMENT' : 'UPLOAD_PARTNER_DOCUMENT';
                $eventType = $direction === 'OUTBOUND' ? 'partner.document.published' : 'partner.document.uploaded';
                $this->record(
                    $command, $eventType, 'partner_document', $documentId, $data, 1,
                    ['party_id' => $partyId, 'direction' => $direction, 'document_type' => $data['document_type'], 'sha256_checksum' => $checksum],
                    $result,
                );
                $this->complete($namespace, $idempotentData, $result);

                return $result;
            }, 3);
        } catch (\Throwable $exception) {
            if ($stored) {
                Storage::disk($disk)->delete($path);
            }
            throw $exception;
        }
    }

    private function validateDocumentLinks(array $data, string $partyId): void
    {
        $links = array_filter([
            'sales_order_id' => $data['sales_order_id'] ?? null,
            'shipment_id' => $data['shipment_id'] ?? null,
            'invoice_id' => $data['invoice_id'] ?? null,
            'customer_claim_id' => $data['customer_claim_id'] ?? null,
        ], fn (mixed $value): bool => is_string($value) && $value !== '');
        if (count($links) > 1) {
            throw ValidationException::withMessages([
                'links' => ['A partner document can reference at most one business record.'],
            ]);
        }
        if (isset($links['sales_order_id']) && ! DB::table('sales_orders')->where([
            'id' => $links['sales_order_id'], 'company_id' => $data['company_id'],
            'plant_id' => $data['plant_id'], 'customer_party_id' => $partyId, 'order_type' => 'SALES',
        ])->exists()) {
            throw ValidationException::withMessages(['sales_order_id' => ['Sales order not found for this partner tenant.']]);
        }
        if (isset($links['shipment_id']) && ! DB::table('shipments')->where([
            'id' => $links['shipment_id'], 'company_id' => $data['company_id'],
            'plant_id' => $data['plant_id'], 'party_id' => $partyId,
        ])->exists()) {
            throw ValidationException::withMessages(['shipment_id' => ['Shipment not found for this partner tenant.']]);
        }
        if (isset($links['invoice_id']) && ! DB::table('sales_invoice_financials')->where([
            'invoice_id' => $links['invoice_id'], 'company_id' => $data['company_id'],
            'plant_id' => $data['plant_id'], 'party_id' => $partyId,
        ])->exists()) {
            throw ValidationException::withMessages(['invoice_id' => ['Invoice not found for this partner tenant.']]);
        }
        if (isset($links['customer_claim_id']) && ! DB::table('customer_claims')->where([
            'id' => $links['customer_claim_id'], 'company_id' => $data['company_id'],
            'plant_id' => $data['plant_id'], 'customer_party_id' => $partyId,
        ])->exists()) {
            throw ValidationException::withMessages(['customer_claim_id' => ['Claim not found for this partner tenant.']]);
        }

        foreach ([
            'sales_order_id' => 'SALES_ORDER',
            'shipment_id' => 'SHIPMENT',
            'invoice_id' => 'RECEIVABLE_INVOICE',
            'customer_claim_id' => 'CUSTOMER_CLAIM',
        ] as $field => $transactionType) {
            if (isset($links[$field])) {
                $this->visibility->assertOperationallyVisible(
                    $data['permissions'],
                    $transactionType,
                    $links[$field],
                    $data,
                );
            }
        }
    }

    private function assertDocumentOperationallyVisible(object $document, array $data): void
    {
        foreach ([
            'sales_order_id' => 'SALES_ORDER',
            'shipment_id' => 'SHIPMENT',
            'invoice_id' => 'RECEIVABLE_INVOICE',
            'customer_claim_id' => 'CUSTOMER_CLAIM',
        ] as $field => $transactionType) {
            $transactionId = $document->{$field} ?? null;
            if (is_string($transactionId) && $transactionId !== '') {
                $this->visibility->assertOperationallyVisible(
                    $data['permissions'],
                    $transactionType,
                    $transactionId,
                    $data,
                );

                return;
            }
        }
    }

    private function customerParty(string $partyId, array $data, bool $lock = false): object
    {
        $query = DB::table('parties as party')
            ->join('party_roles as party_role', function ($join): void {
                $join->on('party_role.party_id', '=', 'party.id')->where('party_role.role_code', 'CUSTOMER');
            })
            ->where('party.id', $partyId)
            ->where('party.company_id', $data['company_id'])
            ->where('party.status', 'ACTIVE')
            ->select('party.*');
        $party = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $party) {
            throw ValidationException::withMessages(['party_id' => ['An active customer party is required in the selected company.']]);
        }

        return $party;
    }

    private function grantLocked(string $grantId, array $data): object
    {
        $grant = DB::table('partner_access_grants')
            ->where('id', $grantId)
            ->where('company_id', $data['company_id'])
            ->where('plant_id', $data['plant_id'])
            ->lockForUpdate()
            ->first();
        if (! $grant) {
            throw new NotFoundHttpException('Partner access grant not found.');
        }

        return $grant;
    }

    private function documentLocked(string $documentId, array $data, ?string $partyId = null): object
    {
        $query = DB::table('partner_documents')
            ->where('id', $documentId)
            ->where('company_id', $data['company_id'])
            ->where('plant_id', $data['plant_id']);
        if ($partyId !== null) {
            $query->where('party_id', $partyId);
        }
        $document = $query->lockForUpdate()->first();
        if (! $document) {
            throw new NotFoundHttpException('Partner document not found.');
        }

        return $document;
    }

    private function replaceEntitlements(string $grantId, string $partyId, array $entitlements, array $data): void
    {
        DB::table('partner_access_entitlements')->where('partner_access_grant_id', $grantId)->delete();
        $now = now();
        DB::table('partner_access_entitlements')->insert(array_map(fn (string $code): array => [
            'id' => (string) Str::uuid(),
            'partner_access_grant_id' => $grantId,
            'company_id' => $data['company_id'],
            'plant_id' => $data['plant_id'],
            'party_id' => $partyId,
            'entitlement_code' => $code,
            'created_at' => $now,
            'updated_at' => $now,
        ], $entitlements));
    }

    private function normaliseEntitlements(array $entitlements): array
    {
        $values = collect($entitlements)->map(fn (mixed $value): string => Str::upper(trim((string) $value)))
            ->unique()->sort()->values()->all();
        $invalid = array_values(array_diff($values, self::ENTITLEMENTS));
        if ($invalid !== []) {
            throw ValidationException::withMessages(['entitlements' => ['Unsupported entitlements: '.implode(', ', $invalid).'.']]);
        }
        foreach ([
            'CLAIMS_CREATE' => ['CLAIMS_VIEW', 'SHIPMENTS_VIEW'],
            'DOCUMENT_UPLOAD' => ['DOCUMENTS_VIEW'],
            'DOCUMENT_DOWNLOAD' => ['DOCUMENTS_VIEW'],
            'DOCUMENT_ACKNOWLEDGE' => ['DOCUMENTS_VIEW', 'DOCUMENT_DOWNLOAD'],
        ] as $entitlement => $dependencies) {
            if (in_array($entitlement, $values, true) && array_diff($dependencies, $values) !== []) {
                throw ValidationException::withMessages([
                    'entitlements' => [$entitlement.' requires '.implode(' and ', $dependencies).'.'],
                ]);
            }
        }

        return $values;
    }

    private function assertEffectivePeriod(?string $from, ?string $to): void
    {
        if ($from !== null && $to !== null && strtotime($to) <= strtotime($from)) {
            throw ValidationException::withMessages(['effective_to' => ['The access end must follow its start.']]);
        }
    }

    private function assertInternal(array $data, string $action): void
    {
        if (! $this->access->isInternal($data['permissions']) || ! $this->access->hasPermission($data['permissions'], $action)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Internal partner administration authority is required.');
        }
    }

    private function assertVersion(object $record, int $expected, string $label): void
    {
        if ((int) $record->record_version !== $expected) {
            throw new ConflictHttpException('The '.$label.' changed. Refresh it before continuing.');
        }
    }

    private function documentEvent(object $document, string $type, string $actorId, ?string $reference, ?array $metadata): void
    {
        $now = now();
        DB::table('partner_document_events')->insert([
            'id' => (string) Str::uuid(),
            'partner_document_id' => $document->id,
            'company_id' => $document->company_id,
            'plant_id' => $document->plant_id,
            'party_id' => $document->party_id,
            'event_type' => $type,
            'actor_id' => $actorId,
            'reference' => $reference,
            'metadata_json' => $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
        ]);
    }

    private function begin(string $namespace, array $data): ?array
    {
        return $this->idempotency->begin(
            $namespace,
            $data['idempotency_key'],
            Arr::except($data, ['actor_id', 'permissions', 'idempotency_key', 'correlation_id', 'file']),
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
        $this->audit->record(
            $command,
            $entity,
            $id,
            $data['actor_id'],
            $data['company_id'],
            $data['plant_id'],
            'SUCCESS',
            ['entity_version' => $version, 'correlation_id' => $data['correlation_id'] ?? null, 'safe_diff' => $diff],
        );
        $this->outbox->append(
            $event,
            $entity,
            $id,
            $id.':'.$version,
            $result + ['company_id' => $data['company_id'], 'plant_id' => $data['plant_id']],
            $data['correlation_id'] ?? null,
            $data['company_id'],
            $data['plant_id'],
        );
    }

    private function result(string $entity, string $id, string $status, int $version, array $extra = []): array
    {
        return ['entity_type' => $entity, 'id' => $id, 'status' => $status, 'record_version' => $version] + $extra;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
