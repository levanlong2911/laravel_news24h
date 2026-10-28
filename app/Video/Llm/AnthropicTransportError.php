<?php

declare(strict_types=1);

namespace App\Video\Llm;

use RuntimeException;

final class AnthropicTransportError extends RuntimeException
{
    public const NO_HEADERS = 'no_headers';

    public const HTTP = 'http';

    public const OUT_OF_TIME = 'out_of_time';

    public const CANCELLED = 'cancelled';

    public function __construct(
        string $message,
        public readonly string $kind,
        public readonly int $attempts,
        public readonly float $waitedSeconds,
        public readonly ?int $status = null,
        public readonly ?string $errorType = null,
        public readonly ?string $providerMessage = null,
        public readonly ?string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
