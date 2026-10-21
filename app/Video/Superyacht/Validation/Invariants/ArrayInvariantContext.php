<?php

namespace App\Video\Superyacht\Validation\Invariants;

/**
 * In-memory context. Every key is optional:
 *   canonical, shot_specs[shot_id], locked_revisions[type][] , cut_grid,
 *   constraints[id], frozen_shots["SC01_SH01@1"] => hash, files[path] => hash,
 *   tool_profiles[model_id], artifacts["id@rev"] => hash, humans[]
 */
final class ArrayInvariantContext implements InvariantContext
{
    public function __construct(private array $data = [])
    {
    }

    public function with(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->data[$key] = $value;

        return $clone;
    }

    public function lockedCanonical(): ?array
    {
        return $this->data['canonical'] ?? null;
    }

    public function shotSpec(string $shotId): ?array
    {
        return $this->data['shot_specs'][$shotId] ?? null;
    }

    public function isLockedRevision(string $type, int $revision, ?string $key = null): bool
    {
        return in_array($revision, $this->data['locked_revisions'][$type] ?? [], true);
    }

    public function cutGrid(): ?array
    {
        return $this->data['cut_grid'] ?? null;
    }

    public function constraints(): array
    {
        return $this->data['constraints'] ?? [];
    }

    public function frozenCompiledShotHash(string $shotId, int $revision): ?string
    {
        return $this->data['frozen_shots']["{$shotId}@{$revision}"] ?? null;
    }

    public function storedFileHash(string $path): ?string
    {
        return $this->data['files'][$path] ?? null;
    }

    public function toolProfile(string $modelId): ?array
    {
        return $this->data['tool_profiles'][$modelId] ?? null;
    }

    public function artifactRevisionExists(string $artifactId, int $revision, string $hash): bool
    {
        return ($this->data['artifacts']["{$artifactId}@{$revision}"] ?? null) === $hash;
    }

    public function isHumanUser(string $decidedBy): bool
    {
        return in_array($decidedBy, $this->data['humans'] ?? [], true);
    }
}
