<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Analysis;

use App\Services\AI\FilmOS\Narrative\Character\CharacterView;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeAuditReport;
use App\Services\AI\FilmOS\Narrative\Scene\SceneView;
use App\Services\AI\FilmOS\Narrative\Story\StoryView;
use App\Services\AI\FilmOS\Narrative\World\WorldView;

/**
 * Translates View contracts into ShotKnowledge — the analysis boundary crossing.
 *
 * BOUNDARY INVARIANT (frozen with C.8A):
 * This is the ONLY class in the Analysis layer permitted to read
 * StoryView / SceneView / CharacterView / WorldView / NarrativeAuditReport.
 * OutcomeJoiner and KnowledgeAnalyzer never see a View
 * (same separation as D5: Auditor produces Findings, consumers read Findings).
 *
 * NEVER-INFER INVARIANT (frozen with C.8A):
 * The extractor must never infer missing knowledge. If a View contract does
 * not expose a value, it records null (or an empty collection) — it does not
 * synthesize, guess, or interpret. Missing data is a signal to extend a View
 * contract through review, never to add a heuristic here.
 *
 * Pure and stateless. Knows nothing about BenchmarkResult.
 */
final class KnowledgeExtractor
{
    /**
     * @param WorldView $world reserved for world-fact dimensions — no v1 usage,
     *                         part of the frozen signature so adding a world
     *                         dimension later never breaks this contract
     * @return array<int, ShotKnowledge> keyed by ordinal (the join identity)
     */
    public function extract(
        StoryView            $story,
        SceneView            $scene,
        CharacterView        $characters,
        WorldView            $world,
        NarrativeAuditReport $audit,
    ): array {
        $findingCodesByOrdinal = $this->groupFindingCodes($audit);

        $knowledge = [];
        foreach ($story->allShots() as $shot) {
            $knowledge[$shot->ordinal] = new ShotKnowledge(
                ordinal:             $shot->ordinal,
                shotId:              $shot->shotId,
                beat:                $shot->beat,
                camera:              $scene->getCamera($shot->ordinal),
                emotionsByCharacter: $this->emotionsAt($characters, $shot->ordinal),
                findingCodes:        $findingCodesByOrdinal[$shot->ordinal] ?? [],
            );
        }

        return $knowledge;
    }

    /**
     * Emotion of every character with a KNOWN emotion at this ordinal.
     * Characters whose emotion is unknown are simply absent — never defaulted.
     *
     * @return array<string, \App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion>
     */
    private function emotionsAt(CharacterView $characters, int $ordinal): array
    {
        $emotions = [];
        foreach ($characters->allCharacters() as $characterId => $memory) {
            $emotion = $characters->emotionAt($characterId, $ordinal);
            if ($emotion !== null) {
                $emotions[$characterId] = $emotion;
            }
        }

        return $emotions;
    }

    /** @return array<int, string[]> ordinal → finding codes (findings without ordinal are not shot-scoped) */
    private function groupFindingCodes(NarrativeAuditReport $audit): array
    {
        $byOrdinal = [];
        foreach ($audit->findings() as $finding) {
            if ($finding->ordinal !== null) {
                $byOrdinal[$finding->ordinal][] = $finding->code;
            }
        }

        return $byOrdinal;
    }
}
