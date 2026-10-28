<?php

declare(strict_types=1);

namespace App\Video\Concept\Exceptions;

use RuntimeException;

final class AnthropicRequestException extends RuntimeException
{
    /** @param ?\App\Video\Concept\Claude\AnthropicStructuredOutputResponse $response what was received before the call broke: usage, request id and partial text */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?\App\Video\Concept\Claude\AnthropicStructuredOutputResponse $response = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
