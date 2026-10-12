<?php

namespace App\Jobs;

use App\Shared\Outbox\OutboxProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessOutboxBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly ?int $limit = null)
    {
        $this->onQueue('outbox');
    }

    public function handle(OutboxProcessor $processor): void
    {
        $result = $processor->process($this->limit);
        Log::info('outbox_batch_processed', [
            'event' => 'outbox_batch_processed',
            'claimed' => $result['claimed'],
            'delivered' => $result['delivered'],
            'retry_scheduled' => $result['retry_scheduled'],
            'quarantined' => $result['quarantined'],
            'worker_id' => $result['worker_id'],
            'transport' => $result['transport'],
            'delivery_enabled' => $result['delivery_enabled'],
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::critical('outbox_batch_job_failed', [
            'event' => 'outbox_batch_job_failed',
            'error_type' => $exception ? class_basename($exception) : null,
        ]);
    }
}
