<?php

declare(strict_types=1);

namespace App\Video\Concept\Validation;

final class ValidationError
{
    public function __construct(
        public readonly string $code,
        public readonly string $path,
        public readonly string $message,
        public readonly mixed $expected = null,
        public readonly mixed $actual = null,
    ) {
    }

    /**
     * @return array{
     *     code:string,
     *     path:string,
     *     message:string,
     *     expected:mixed,
     *     actual:mixed
     * }
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'path' => $this->path,
            'message' => $this->message,
            'expected' => $this->expected,
            'actual' => $this->actual,
        ];
    }
}
