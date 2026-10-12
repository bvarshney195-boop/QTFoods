<?php

namespace App\Shared\Outbox;

interface OutboxTransport
{
    public function name(): string;

    public function deliveryEnabled(): bool;

    /**
     * @return array{acknowledgement_id:string,response:array<string,mixed>}
     */
    public function deliver(array $event): array;
}
