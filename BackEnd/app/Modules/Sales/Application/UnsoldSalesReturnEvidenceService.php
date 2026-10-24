<?php

namespace App\Modules\Sales\Application;

use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class UnsoldSalesReturnEvidenceService
{
    public const CATEGORIES = [
        'RETURN_CONFIRMATION',
        'RECEIPT_PHOTO',
        'QUALITY_REPORT',
        'FINANCE_DOCUMENT',
        'OTHER',
    ];

    public const MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'text/plain' => 'txt',
    ];

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    public function upload(string $caseId, UploadedFile $file, array $data): array
    {
        if (! in_array($data['category'] ?? null, self::CATEGORIES, true)) {
            throw ValidationException::withMessages([
                'category' => ['Select a supported evidence category.'],
            ]);
        }

        $metadata = $this->fileMetadata($file);
        $disk = trim((string) config('qtfoods.evidence_disk', 'evidence'));
        if ($disk === '') {
            throw new RuntimeException('The evidence storage disk is not configured.');
        }

        $evidenceId = (string) Str::uuid();
        $directory = implode('/', [
            'unsold-returns',
            $data['company_id'],
            $data['plant_id'],
            $caseId,
        ]);
        $storagePath = "{$directory}/{$evidenceId}.{$metadata['extension']}";
        $uploadedAt = CarbonImmutable::now();
        $retentionYears = max(1, (int) config('qtfoods.evidence_retention_years', 7));
        $retentionPolicy = trim((string) config(
            'qtfoods.evidence_retention_policy',
            'UNSOLD_RETURN_7Y'
        ));
        $retentionPolicy = $retentionPolicy !== '' ? $retentionPolicy : 'UNSOLD_RETURN_7Y';
        $retentionUntil = $uploadedAt->addYears($retentionYears)->toDateString();
        $notes = trim((string) ($data['notes'] ?? '')) ?: null;
        // The case UUID is globally unique, so it scopes replay protection without
        // exceeding the idempotency table's portable 120-character namespace limit.
        $namespace = "sales.unsold-return.evidence.{$caseId}";
        $key = $data['idempotency_key'];
        $payload = [
            'return_case_id' => $caseId,
            'company_id' => $data['company_id'],
            'plant_id' => $data['plant_id'],
            'actor_id' => $data['actor_id'],
            'expected_version' => $data['expected_version'],
            'category' => $data['category'],
            'notes' => $notes,
            'original_name' => $metadata['original_name'],
            'mime_type' => $metadata['mime_type'],
            'size_bytes' => $metadata['size_bytes'],
            'sha256' => $metadata['sha256'],
        ];
        $storedPath = null;

        try {
            return DB::transaction(function () use (
                $caseId,
                $file,
                $data,
                $metadata,
                $disk,
                $directory,
                $storagePath,
                $evidenceId,
                $uploadedAt,
                $retentionPolicy,
                $retentionUntil,
                $notes,
                $namespace,
                $key,
                $payload,
                &$storedPath,
            ) {
                if ($existing = $this->idempotency->begin($namespace, $key, $payload)) {
                    return $existing;
                }

                $case = DB::table('unsold_return_cases')
                    ->where('id', $caseId)
                    ->where('company_id', $data['company_id'])
                    ->where('plant_id', $data['plant_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $case) {
                    throw new NotFoundHttpException('Unsold return case not found.');
                }

                if ((int) $case->record_version !== (int) $data['expected_version']) {
                    throw new ConflictHttpException(
                        "The return case changed from version {$data['expected_version']} to {$case->record_version}. Refresh it before retrying."
                    );
                }

                $storedPath = Storage::disk($disk)->putFileAs(
                    $directory,
                    $file,
                    basename($storagePath),
                    ['visibility' => 'private']
                );
                if (! is_string($storedPath) || $storedPath === '') {
                    throw new RuntimeException('The evidence file could not be stored.');
                }

                $version = (int) $case->record_version + 1;
                DB::table('unsold_return_evidence')->insert([
                    'id' => $evidenceId,
                    'return_case_id' => $caseId,
                    'company_id' => $case->company_id,
                    'plant_id' => $case->plant_id,
                    'category' => $data['category'],
                    'case_record_version' => $version,
                    'original_name' => $metadata['original_name'],
                    'storage_disk' => $disk,
                    'storage_path' => $storedPath,
                    'mime_type' => $metadata['mime_type'],
                    'size_bytes' => $metadata['size_bytes'],
                    'sha256' => $metadata['sha256'],
                    'notes' => $notes,
                    'retention_policy' => $retentionPolicy,
                    'retention_until' => $retentionUntil,
                    'legal_hold' => false,
                    'uploaded_by' => $data['actor_id'],
                    'upload_audit_event_id' => null,
                    'idempotency_key' => $key,
                    'uploaded_at' => $uploadedAt,
                    'created_at' => $uploadedAt,
                    'updated_at' => $uploadedAt,
                ]);
                DB::table('unsold_return_cases')->where('id', $caseId)->update([
                    'record_version' => $version,
                    'updated_at' => $uploadedAt,
                ]);

                $auditId = $this->audit->record(
                    'UPLOAD_UNSOLD_RETURN_EVIDENCE',
                    'unsold_return_evidence',
                    $evidenceId,
                    $data['actor_id'],
                    $case->company_id,
                    $case->plant_id,
                    'SUCCESS',
                    [
                        'entity_version' => 1,
                        'correlation_id' => $data['correlation_id'] ?? null,
                        'reason_code' => $data['category'],
                        'safe_diff' => [
                            'return_case_id' => $caseId,
                            'case_record_version' => $version,
                            'category' => $data['category'],
                            'original_name' => $metadata['original_name'],
                            'mime_type' => $metadata['mime_type'],
                            'size_bytes' => $metadata['size_bytes'],
                            'sha256' => $metadata['sha256'],
                            'retention_policy' => $retentionPolicy,
                            'retention_until' => $retentionUntil,
                        ],
                    ]
                );
                DB::table('unsold_return_evidence')->where('id', $evidenceId)->update([
                    'upload_audit_event_id' => $auditId,
                ]);

                $result = [
                    'return_case_id' => $caseId,
                    'evidence_id' => $evidenceId,
                    'category' => $data['category'],
                    'original_name' => $metadata['original_name'],
                    'mime_type' => $metadata['mime_type'],
                    'size_bytes' => $metadata['size_bytes'],
                    'sha256' => $metadata['sha256'],
                    'notes' => $notes,
                    'case_record_version' => $version,
                    'record_version' => $version,
                    'retention_policy' => $retentionPolicy,
                    'retention_until' => $retentionUntil,
                    'legal_hold' => false,
                    'audit_event_id' => $auditId,
                    'uploaded_at' => $uploadedAt->toISOString(),
                ];

                $this->outbox->append(
                    'sales.unsold_return.evidence_uploaded',
                    'unsold_return_case',
                    $caseId,
                    $evidenceId,
                    $result,
                    $data['correlation_id'] ?? null
                );
                $this->idempotency->complete($namespace, $key, $result);

                return $result;
            }, 3);
        } catch (Throwable $exception) {
            if (is_string($storedPath) && $storedPath !== '') {
                try {
                    Storage::disk($disk)->delete($storedPath);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }
    }

    public function download(string $caseId, string $evidenceId, array $data): array
    {
        return DB::transaction(function () use ($caseId, $evidenceId, $data) {
            $evidence = DB::table('unsold_return_evidence as evidence')
                ->join('unsold_return_cases as c', function ($join) {
                    $join
                        ->on('c.id', '=', 'evidence.return_case_id')
                        ->on('c.company_id', '=', 'evidence.company_id')
                        ->on('c.plant_id', '=', 'evidence.plant_id');
                })
                ->where('evidence.id', $evidenceId)
                ->where('evidence.return_case_id', $caseId)
                ->where('evidence.company_id', $data['company_id'])
                ->where('evidence.plant_id', $data['plant_id'])
                ->first([
                    'evidence.*',
                ]);

            if (! $evidence || ! Storage::disk($evidence->storage_disk)->exists($evidence->storage_path)) {
                throw new NotFoundHttpException('Unsold return evidence not found.');
            }

            $this->audit->record(
                'DOWNLOAD_UNSOLD_RETURN_EVIDENCE',
                'unsold_return_evidence',
                $evidenceId,
                $data['actor_id'],
                $evidence->company_id,
                $evidence->plant_id,
                'SUCCESS',
                [
                    'entity_version' => 1,
                    'correlation_id' => $data['correlation_id'] ?? null,
                    'reason_code' => $evidence->category,
                    'safe_diff' => [
                        'return_case_id' => $caseId,
                        'sha256' => $evidence->sha256,
                    ],
                ]
            );

            return [
                'storage_disk' => (string) $evidence->storage_disk,
                'storage_path' => (string) $evidence->storage_path,
                'original_name' => (string) $evidence->original_name,
                'mime_type' => (string) $evidence->mime_type,
            ];
        });
    }

    private function fileMetadata(UploadedFile $file): array
    {
        $mimeType = (string) ($file->getMimeType() ?: 'application/octet-stream');
        if (! isset(self::MIME_TYPES[$mimeType])) {
            throw ValidationException::withMessages([
                'file' => ['Evidence must be a PDF, JPEG, PNG, WebP, or plain-text file.'],
            ]);
        }

        $size = (int) $file->getSize();
        $maxBytes = max(1, (int) config('qtfoods.evidence_max_upload_kilobytes', 10240)) * 1024;
        if (! $file->isValid() || $size < 1 || $size > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => ['The evidence file is invalid or exceeds the configured size limit.'],
            ]);
        }

        $originalName = basename(str_replace('\\', '/', trim($file->getClientOriginalName())));
        $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '_', $originalName) ?: 'evidence';
        if (mb_strlen($originalName) > 255) {
            throw ValidationException::withMessages([
                'file' => ['The evidence file name may not exceed 255 characters.'],
            ]);
        }

        $realPath = $file->getRealPath();
        $sha256 = is_string($realPath) ? hash_file('sha256', $realPath) : false;
        if (! is_string($sha256)) {
            throw ValidationException::withMessages([
                'file' => ['The evidence file could not be read.'],
            ]);
        }

        return [
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'extension' => self::MIME_TYPES[$mimeType],
            'size_bytes' => $size,
            'sha256' => $sha256,
        ];
    }
}
