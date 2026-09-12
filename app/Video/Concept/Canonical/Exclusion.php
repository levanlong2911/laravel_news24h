<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical;

use InvalidArgumentException;

final class Exclusion
{
    public function __construct(
        public readonly string $id,
        public readonly string $targetPath,
        public readonly string $forbid,
    ) {
        if (! preg_match('/^E[0-9]{3}$/', $id)) {
            throw new InvalidArgumentException(
                'Exclusion id must match '
                ."^E[0-9]{3}$: {$id}"
            );
        }

        if (
            trim($targetPath) === ''
            || trim($forbid) === ''
        ) {
            throw new InvalidArgumentException(
                'Exclusion target_path and forbid '
                .'must not be empty.'
            );
        }
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),

            targetPath: (string) ($data['target_path'] ?? ''),

            forbid: (string) ($data['forbid'] ?? ''),
        );
    }

    /**
     * @return array{
     *     id:string,
     *     target_path:string,
     *     forbid:string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'target_path' => $this->targetPath,
            'forbid' => $this->forbid,
        ];
    }
}
