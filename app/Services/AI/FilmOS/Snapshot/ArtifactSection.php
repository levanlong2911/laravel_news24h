<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Phase F snapshot section — artifact bundle hash.
 *
 * Captures sha256 of all rendered artifacts (sorted by taskId) so that
 * any change in what was actually produced changes the canonical hash.
 *
 * For dry-run / golden scenario runs, artifactBundleHash is deterministic
 * because mock video URLs are fixed inputs. For live provider runs, the
 * hash captures the actual rendered content.
 *
 * Produced by ArtifactLayerBuilder and consumed by SnapshotComposer.
 */
final class ArtifactSection implements SnapshotSection
{
    public function __construct(
        public readonly string $artifactBundleHash,
    ) {}

    public static function name(): string { return 'artifact'; }

    public static function requiredFields(): array { return ['artifactBundleHash']; }

    public static function optionalFields(): array { return []; }

    public function fields(): array
    {
        return ['artifactBundleHash' => $this->artifactBundleHash];
    }
}
