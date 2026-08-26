<?php

namespace App\Video\Llm;

final class LlmUnavailable extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?LlmResponse $billed = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
