<?php

namespace App\Services\AI\SceneGraph;

use App\Services\AI\PromptCompiler\DslLexicon;
use App\Services\AI\PromptCompiler\Libraries\SubjectLibrary;
use App\Services\AI\PromptCompiler\Libraries\AssetLibrary;

/**
 * Tracks a visual anchor for each scene and injects it into shots 2+.
 *
 * Scope: per-scene. The anchor is extracted from the first shot's DSL
 * and describes the primary subject + setting so subsequent shots can
 * instruct the AI model to maintain visual consistency.
 *
 * Usage in GraphAssembler:
 *   $engine->record($sceneId, $shotOrder, $dsl);       // always call
 *   $anchor = $engine->anchorFor($sceneId, $shotOrder); // null for shot 1
 */
final class ContinuityEngine
{
    /** sceneId → anchor string (set once from shot 1) */
    private array $anchors = [];

    /**
     * Record first shot DSL. Idempotent: only the first call per scene is stored.
     */
    public function record(string $sceneId, int $shotOrder, array $dsl): void
    {
        if (isset($this->anchors[$sceneId])) {
            return;  // anchor already recorded for this scene
        }

        $anchor = $this->buildAnchor($dsl);
        if ($anchor !== '') {
            $this->anchors[$sceneId] = $anchor;
        }
    }

    /**
     * Return the anchor string for the given shot, or null if this is shot 1
     * (no previous context yet) or the scene has no recordable anchor.
     */
    public function anchorFor(string $sceneId, int $shotOrder): ?string
    {
        if ($shotOrder <= 1) {
            return null;
        }
        return $this->anchors[$sceneId] ?? null;
    }

    // -------------------------------------------------------------------------

    private function buildAnchor(array $dsl): string
    {
        $parts = [];

        $actor = trim($dsl['sub']['actor'] ?? '');
        $obj   = trim($dsl['sub']['obj']   ?? '');
        $env   = trim($dsl['environment']  ?? '');
        $light = $dsl['light'] ?? '';

        if ($actor !== '') {
            $display = SubjectLibrary::displayName($actor);
            $parts[] = $display !== '' ? $display : $actor;
        }

        if ($obj !== '') {
            $display = AssetLibrary::displayName($obj);
            $parts[] = 'with ' . ($display !== '' ? $display : $obj);
        }

        if ($env !== '') {
            // Use the raw environment key as a compact setting label
            $parts[] = 'in ' . str_replace('_', ' ', $env);
        }

        if ($light !== '') {
            $lightPhrase = DslLexicon::light($light);
            if ($lightPhrase !== $light) {  // only if successfully expanded
                $parts[] = $lightPhrase;
            }
        }

        return implode(', ', $parts);
    }
}
