<?php

return [
    'retention_days' => (int) env('QT_BACKUP_RETENTION_DAYS', 35),
    'rpo_minutes' => (int) env('QT_RECOVERY_RPO_MINUTES', 1440),
    'rto_minutes' => (int) env('QT_RECOVERY_RTO_MINUTES', 240),
    'object_verification_limit' => (int) env('QT_RECOVERY_OBJECT_LIMIT', 100000),
    'object_sources' => [
        [
            'table' => 'unsold_return_evidence',
            'disk_column' => 'storage_disk',
            'path_column' => 'storage_path',
            'checksum_column' => 'sha256',
            'size_column' => 'size_bytes',
        ],
        [
            'table' => 'bill_archive_documents',
            'disk_column' => 'storage_disk',
            'path_column' => 'storage_path',
            'checksum_column' => 'sha256_checksum',
            'size_column' => 'size_bytes',
        ],
        [
            'table' => 'partner_documents',
            'disk_column' => 'storage_disk',
            'path_column' => 'storage_path',
            'checksum_column' => 'sha256_checksum',
            'size_column' => 'size_bytes',
        ],
    ],
];
