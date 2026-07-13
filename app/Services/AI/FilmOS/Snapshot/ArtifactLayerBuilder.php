<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Builds Phase F snapshot hashes from render results.
 *
 * Data source: render output map — FilmOS task ID → provider/mock output.
 * Each output must contain a 'videoUrl' key (or 'imageUrl' / 'audioUrl'
 * for future artifact types).
 *
 * Hash contract (Phase F):
 *
 *   artifactBundleHash
 *     Input: (taskId, artifactType, contentHash) per artifact, sorted by taskId.
 *     contentHash = sha256(videoUrl) for mock/dry-run runs (URL is deterministic).
 *     For live runs, callers should pre-hash actual content bytes and pass them
 *     via the $contentHashOverrides parameter.
 *     Two identical dry-run pipelines MUST produce the same artifactBundleHash.
 *
 * Excluded from canonical data:
 *   prompt        — already captured in PlanningSection::promptHash
 *   shotId        — UI label, not a determinism input
 *   taskId (Kling) — provider-assigned, non-deterministic in live mode
 */
final class ArtifactLayerBuilder
{
    public function __construct(
        private readonly HashSerializer $serializer = new JsonHashSerializer(),
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $renderResults
     *         Key: FilmOS task ID (e.g., 'render_shot_002_cockroach_closeup').
     *         Value: render output; must have 'videoUrl' for video artifacts.
     * @param  array<string, string>  $contentHashOverrides
     *         Optional pre-computed content hashes (taskId → sha256).
     *         Use this for live runs where you hash actual downloaded bytes.
     *         When provided, overrides the URL-based hash for that task.
     */
    public function build(array $renderResults, array $contentHashOverrides = []): ArtifactSection
    {
        ksort($renderResults);

        $entries = [];
        foreach ($renderResults as $taskId => $output) {
            if (isset($contentHashOverrides[$taskId])) {
                $contentHash = $contentHashOverrides[$taskId];
            } else {
                $url         = (string) ($output['videoUrl'] ?? $output['imageUrl'] ?? $output['audioUrl'] ?? '');
                $contentHash = hash('sha256', $url);
            }

            $type    = isset($output['imageUrl']) ? 'image' : (isset($output['audioUrl']) ? 'audio' : 'video');
            $entries[] = (new ArtifactEntry($taskId, $type, $contentHash))->toArray();
        }

        return new ArtifactSection(
            artifactBundleHash: $this->serializer->sha256($entries),
        );
    }
}
