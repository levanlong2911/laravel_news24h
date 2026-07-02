<?php

namespace App\Services\AI\SceneGraph;

/**
 * Resolves a shot's asset_ref into the asset entry for SceneGraph.
 *
 * Sprint 1: passthrough + cache_key generation.
 * cache_key intentionally excludes project_id — same asset rendered the same
 * way (same provider, same prompt) across any project should share one entry.
 * This is the foundation for Phase C Asset Memory (avoid re-generating the
 * same motorcycle seat across 100 projects).
 *
 * cache_key = sha256(asset_id:variation:provider:prompt_hash)
 */
final class AssetResolver
{
    public static function resolve(array $assetRef, string $provider, string $promptHash): array
    {
        if (empty($assetRef) || empty($assetRef['id'] ?? '')) {
            return ['id' => '', 'type' => 'prop', 'reuse' => false, 'variation' => '', 'asset_key' => ''];
        }

        $id        = $assetRef['id'];
        $variation = $assetRef['variation'] ?? '';

        return [
            'id'        => $id,
            'type'      => $assetRef['type']  ?? 'prop',
            'reuse'     => (bool) ($assetRef['reuse'] ?? false),
            'variation' => $variation,
            'asset_key' => hash('sha256', implode(':', [$id, $variation, $provider, $promptHash])),
        ];
    }
}
