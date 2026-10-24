<?php

namespace App\Modules\Partner\Http\Controllers;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Domain\User;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use App\Modules\Partner\Application\PartnerPortalQuery;
use App\Modules\Partner\Application\PartnerPortalService;
use App\Modules\Sales\Application\OrderToCashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PartnerPortalController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly PartnerPortalQuery $query,
        private readonly PartnerPortalService $service,
        private readonly SessionService $sessions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:24'],
            'direction' => ['nullable', Rule::in(['INBOUND', 'OUTBOUND'])],
            'party_id' => ['nullable', 'uuid'],
        ]);
        $viewer = $this->viewer($request);

        return response()->json($this->query->workspace(
            $this->scopeOnly($viewer),
            $viewer['actor_id'],
            $viewer['permissions'],
            $filters,
        ));
    }

    public function grant(string $grantId, Request $request): JsonResponse
    {
        return $this->show('grant', $grantId, $request);
    }

    public function order(string $orderId, Request $request): JsonResponse
    {
        return $this->show('order', $orderId, $request);
    }

    public function shipment(string $shipmentId, Request $request): JsonResponse
    {
        return $this->show('shipment', $shipmentId, $request);
    }

    public function invoice(string $invoiceId, Request $request): JsonResponse
    {
        return $this->show('invoice', $invoiceId, $request);
    }

    public function claim(string $claimId, Request $request): JsonResponse
    {
        return $this->show('claim', $claimId, $request);
    }

    public function document(string $documentId, Request $request): JsonResponse
    {
        return $this->show('document', $documentId, $request);
    }

    public function createGrant(Request $request): JsonResponse
    {
        $validated = $request->validate($this->grantRules());

        return $this->created($this->service->createGrant($validated + $this->commandContext($request, false)));
    }

    public function updateGrant(string $grantId, Request $request): JsonResponse
    {
        $validated = $request->validate($this->grantRules(false));

        return $this->ok($this->service->updateGrant($grantId, $validated + $this->commandContext($request, true)));
    }

    public function revokeGrant(string $grantId, Request $request): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        return $this->ok($this->service->revokeGrant(
            $grantId,
            trim($validated['reason']),
            $this->commandContext($request, true),
        ));
    }

    public function publishDocument(Request $request): JsonResponse
    {
        $this->normalise($request, ['document_number', 'document_type']);
        $validated = $request->validate(['party_id' => ['required', 'uuid']] + $this->documentRules());

        return $this->created($this->service->publishDocument(
            $request->file('file'),
            $validated + $this->commandContext($request, false),
        ));
    }

    public function uploadDocument(Request $request): JsonResponse
    {
        $this->normalise($request, ['document_number', 'document_type']);
        $validated = $request->validate(['party_id' => ['prohibited']] + $this->documentRules());

        return $this->created($this->service->uploadDocument(
            $request->file('file'),
            $validated + $this->commandContext($request, false),
        ));
    }

    public function acknowledgeDocument(string $documentId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'acknowledgement_reference' => ['required', 'string', 'min:3', 'max:160'],
        ]);

        return $this->ok($this->service->acknowledgeDocument(
            $documentId,
            trim($validated['acknowledgement_reference']),
            $this->commandContext($request, true),
        ));
    }

    public function withdrawDocument(string $documentId, Request $request): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        return $this->ok($this->service->withdrawDocument(
            $documentId,
            trim($validated['reason']),
            $this->commandContext($request, true),
        ));
    }

    public function downloadDocument(string $documentId, Request $request): StreamedResponse
    {
        $viewer = $this->viewer($request);
        $document = $this->query->downloadableDocument(
            $documentId,
            $this->scopeOnly($viewer),
            $viewer['actor_id'],
            $viewer['permissions'],
        );

        $disk = (string) $document->storage_disk === 'private'
            ? (string) config('qtfoods.private_document_disk', 'private')
            : (string) $document->storage_disk;

        return Storage::disk($disk)->download(
            (string) $document->storage_path,
            (string) $document->original_name,
            [
                'Content-Type' => (string) $document->mime_type,
                'X-Content-SHA256' => (string) $document->sha256_checksum,
                'X-Partner-Direction' => (string) $document->direction,
            ],
        );
    }

    public function createClaim(Request $request): JsonResponse
    {
        $this->normalise($request, ['claim_number', 'claim_type', 'requested_resolution']);
        $validated = $request->validate([
            'claim_number' => $this->code(),
            'shipment_id' => ['required', 'uuid'],
            'claim_type' => ['required', Rule::in(OrderToCashService::CLAIM_TYPES)],
            'requested_resolution' => ['required', Rule::in(OrderToCashService::CLAIM_RESOLUTIONS)],
            'reason' => ['required', 'string', 'min:3', 'max:4000'],
            'lines' => ['required', 'array', 'between:1,100'],
            'lines.*.shipment_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999', 'decimal:0,6'],
        ]);

        return $this->created($this->service->createClaim($validated + $this->commandContext($request, false)));
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }

    private function show(string $resource, string $id, Request $request): JsonResponse
    {
        $viewer = $this->viewer($request);

        return response()->json(['data' => $this->query->detail(
            $resource,
            $id,
            $this->scopeOnly($viewer),
            $viewer['actor_id'],
            $viewer['permissions'],
        )]);
    }

    private function viewer(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        return $this->selectedScope($request, true) + [
            'actor_id' => (string) $user->id,
            'permissions' => $this->currentPermissions($request),
        ];
    }

    private function scopeOnly(array $viewer): array
    {
        return [
            'company_id' => $viewer['company_id'],
            'plant_id' => $viewer['plant_id'],
        ];
    }

    private function grantRules(bool $creating = true): array
    {
        return [
            'user_id' => $creating ? ['required', 'uuid'] : ['prohibited'],
            'party_id' => $creating ? ['required', 'uuid'] : ['prohibited'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'entitlements' => ['required', 'array', 'between:1,'.count(PartnerPortalService::ENTITLEMENTS)],
            'entitlements.*' => ['required', 'string', 'distinct', Rule::in(PartnerPortalService::ENTITLEMENTS)],
        ];
    }

    private function documentRules(): array
    {
        return [
            'document_number' => $this->code(),
            'document_type' => ['required', Rule::in(PartnerPortalService::DOCUMENT_TYPES)],
            'title' => ['required', 'string', 'min:3', 'max:200'],
            'description' => ['nullable', 'string', 'max:4000'],
            'sales_order_id' => ['nullable', 'uuid'],
            'shipment_id' => ['nullable', 'uuid'],
            'invoice_id' => ['nullable', 'uuid'],
            'customer_claim_id' => ['nullable', 'uuid'],
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,png,jpg,jpeg,csv,txt'],
        ];
    }

    private function code(): array
    {
        return ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'];
    }

    private function normalise(Request $request, array $upper): void
    {
        $input = $request->all();
        foreach ($upper as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = Str::upper(trim($input[$field]));
            }
        }
        foreach (['title', 'description', 'reason'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim($input[$field]);
            }
        }
        $request->merge($input);
    }

    private function created(array $data): JsonResponse
    {
        return response()->json(['data' => $data], 201);
    }

    private function ok(array $data): JsonResponse
    {
        return response()->json(['data' => $data]);
    }
}
