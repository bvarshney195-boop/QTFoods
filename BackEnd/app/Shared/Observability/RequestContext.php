<?php

namespace App\Shared\Observability;

final class RequestContext
{
    private ?string $requestId = null;
    private ?string $correlationId = null;
    private ?string $traceId = null;
    private ?string $spanId = null;

    public function start(string $requestId, string $correlationId, string $traceId, string $spanId): void
    {
        $this->requestId = $requestId;
        $this->correlationId = $correlationId;
        $this->traceId = $traceId;
        $this->spanId = $spanId;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    public function traceId(): ?string
    {
        return $this->traceId;
    }

    public function spanId(): ?string
    {
        return $this->spanId;
    }

    public function clear(): void
    {
        $this->requestId = null;
        $this->correlationId = null;
        $this->traceId = null;
        $this->spanId = null;
    }
}
