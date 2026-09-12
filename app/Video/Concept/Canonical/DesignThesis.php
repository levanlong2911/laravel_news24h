<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical;

use InvalidArgumentException;

final class DesignThesis
{
    public const ROLE = 'soft_design_guidance';

    public function __construct(
        public readonly string $text,
        public readonly string $role = self::ROLE,
    ) {
        if (trim($text) === '') {
            throw new InvalidArgumentException(
                'design_thesis.text must not be empty.'
            );
        }

        if ($role !== self::ROLE) {
            throw new InvalidArgumentException(
                'design_thesis.role must equal soft_design_guidance.'
            );
        }
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            text: (string) ($data['text'] ?? ''),
            role: (string) ($data['role'] ?? ''),
        );
    }

    /**
     * @return array{
     *     text:string,
     *     role:string
     * }
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'role' => $this->role,
        ];
    }
}
