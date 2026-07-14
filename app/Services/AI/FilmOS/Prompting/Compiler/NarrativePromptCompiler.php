<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Compiler;

use App\Services\AI\FilmOS\Narrative\Character\CharacterView;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeAuditReport;
use App\Services\AI\FilmOS\Narrative\Scene\SceneView;
use App\Services\AI\FilmOS\Narrative\Story\StoryView;
use App\Services\AI\FilmOS\Narrative\World\WorldView;
use App\Services\AI\FilmOS\Prompting\IR\PromptEnvironment;
use App\Services\AI\FilmOS\Prompting\IR\ShotPrompt;
use App\Services\AI\FilmOS\Prompting\IR\StructuredPrompt;

/**
 * The bridge from Knowledge Layer to Prompt Layer.
 *
 *   NarrativeState (4 Views) + NarrativeAuditReport
 *       → NarrativePromptCompiler → StructuredPrompt → PromptRendererAdapter → vendor string
 *
 * SPRINT-3 PREVENTION RULE (frozen 2026-07-13):
 * "Compiler organizes prompt knowledge into Prompt IR. It never performs
 *  vendor wording, stylistic expansion, or natural-language rendering."
 * "Prompt IR is semantic, never stylistic."
 * Organizing WorldFact into PromptEnvironment ('weather' => 'cold') is
 * semantic organization — allowed. Turning weather=cold into "visible
 * breath vapor" is stylistic rendering — adapter territory, always.
 * No "cold" → "breath vapor", no TELEPHOTO → "85mm compression",
 * no FEAR → "terrified". All vendor phrasing lives in adapters. If prose
 * appears in this class, the Prompt IR layer has failed its purpose.
 *
 * BOUNDARY (same as KnowledgeExtractor): reads only the four View interfaces
 * and NarrativeAuditReport. Knows nothing of Timeline internals, Projection
 * classes, MeaningGraph, or GoalGraph.
 *
 * BLOCKING GATE: shots carrying a blocking QA finding (e.g. D4.NO_CAMERA)
 * are excluded from the IR — not policy, physical incapability: a shot with
 * no camera cannot be correctly compiled (the definition of blocking).
 * Non-blocking findings are ignored here; acting on them is the consumer's
 * decision. The compiler never throws and never mutates state.
 */
final class NarrativePromptCompiler
{
    public function compile(
        StoryView            $story,
        CharacterView        $characters,
        SceneView            $scene,
        WorldView            $world,
        NarrativeAuditReport $audit,
    ): StructuredPrompt {
        $blockedOrdinals = $this->blockedOrdinals($audit);
        $environment     = $this->flattenEnvironment($world);

        $shots = [];
        foreach ($story->allShots() as $shot) {
            if (isset($blockedOrdinals[$shot->ordinal])) {
                continue;
            }

            $shots[$shot->ordinal] = new ShotPrompt(
                ordinal:     $shot->ordinal,
                beat:        $shot->beat,
                action:      $shot->description,
                emotions:    $this->emotionsAt($characters, $shot->ordinal),
                camera:      $scene->getCamera($shot->ordinal),
                environment: $environment,
                endingFrame: $shot->endingFrame,
            );
        }

        return new StructuredPrompt($shots);
    }

    /** @return array<int, true> ordinals that cannot be compiled */
    private function blockedOrdinals(NarrativeAuditReport $audit): array
    {
        $blocked = [];
        foreach ($audit->blocking() as $finding) {
            if ($finding->ordinal !== null) {
                $blocked[$finding->ordinal] = true;
            }
        }

        return $blocked;
    }

    /**
     * Flattens World knowledge into plain detail strings — the domain-decoupling
     * step. SEMANTIC only ('weather' => 'cold'); phrasing is adapter territory.
     */
    private function flattenEnvironment(WorldView $world): PromptEnvironment
    {
        $details = [];
        foreach ($world->allFacts() as $key => $fact) {
            $details[$key] = $fact->value;
        }

        return new PromptEnvironment($details);
    }

    /**
     * Emotion of every character with a KNOWN emotion at this ordinal
     * (D2 persistence semantics via emotionAt — contract, not inference).
     *
     * @return array<string, \App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion>
     */
    private function emotionsAt(CharacterView $characters, int $ordinal): array
    {
        $emotions = [];
        foreach (array_keys($characters->allCharacters()) as $characterId) {
            $emotion = $characters->emotionAt($characterId, $ordinal);
            if ($emotion !== null) {
                $emotions[$characterId] = $emotion;
            }
        }

        return $emotions;
    }
}
