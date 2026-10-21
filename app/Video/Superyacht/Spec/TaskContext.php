<?php

namespace App\Video\Superyacht\Spec;

use App\Video\Superyacht\Support\CanonicalJson;
use RuntimeException;

final class TaskContext
{
    public function __construct(
        public readonly string $projectId,
        public readonly string $inputKey,
        public readonly string $inputId,
        public readonly int $inputRevision,
        public readonly string $inputSha256,
    ) {
        if ($projectId === '' || $inputKey === '' || $inputId === '' || $inputRevision < 1 || ! CanonicalJson::isValidHash($inputSha256)) {
            throw new RuntimeException('A task context needs a project, an input key and id, a revision of at least 1 and a sha256:<64 hex> hash.');
        }
    }

    /** @param array<string, mixed> $context */
    public static function fromArray(array $context): self
    {
        foreach (['project_id', 'input_key', 'input_id', 'input_revision', 'input_sha256'] as $key) {
            if (! array_key_exists($key, $context)) {
                throw new RuntimeException("A task context is missing {$key}.");
            }
        }

        if (! is_string($context['project_id']) || ! is_string($context['input_key']) || ! is_string($context['input_id'])
            || ! is_int($context['input_revision']) || ! is_string($context['input_sha256'])) {
            throw new RuntimeException('A task context has a field of the wrong type.');
        }

        return new self($context['project_id'], $context['input_key'], $context['input_id'], $context['input_revision'], $context['input_sha256']);
    }

    /** @return array{project_id: string, input_key: string, input_id: string, input_revision: int, input_sha256: string} */
    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'input_key' => $this->inputKey,
            'input_id' => $this->inputId,
            'input_revision' => $this->inputRevision,
            'input_sha256' => $this->inputSha256,
        ];
    }
}
