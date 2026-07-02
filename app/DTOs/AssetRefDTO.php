<?php

namespace App\DTOs;

final class AssetRefDTO
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $type,        // component|vehicle|person|location|prop|texture|audio
        public readonly bool    $reuse = false,
        public readonly string  $variation = '',
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id:        $data['id'],
            type:      $data['type'],
            reuse:     (bool) ($data['reuse'] ?? false),
            variation: $data['variation'] ?? '',
        );
    }

    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'type'      => $this->type,
            'reuse'     => $this->reuse,
            'variation' => $this->variation,
        ];
    }
}
