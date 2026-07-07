<?php

namespace App\Services\AI\Provider\Kling\Dto;

final class ApiError
{
    public function __construct(
        public readonly int    $code,
        public readonly string $message,
        public readonly string $requestId,
        public readonly int    $httpStatus,
    ) {}

    public function __toString(): string
    {
        $rid = $this->requestId !== '' ? " (request_id: {$this->requestId})" : '';
        return "[HTTP {$this->httpStatus} / code {$this->code}] {$this->message}{$rid}";
    }
}
