<?php

namespace App\Video\Superyacht\Validation;

/**
 * One broken cross-field invariant (INV-…) or schema error.
 */
final class Violation
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly string $path = '',
    ) {
    }

    public function __toString(): string
    {
        return $this->path === ''
            ? "[{$this->code}] {$this->message}"
            : "[{$this->code}] {$this->path}: {$this->message}";
    }

    /** @return array{code:string,message:string,path:string} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'message' => $this->message, 'path' => $this->path];
    }
}
