<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Compiler;

use App\Services\AI\FilmOS\Narrative\Character\CharacterView;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceView;
use App\Services\AI\FilmOS\Narrative\Production\ProductionView;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeAuditReport;
use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguration;
use App\Services\AI\FilmOS\Narrative\Scene\SceneNodeType;
use App\Services\AI\FilmOS\Narrative\Scene\SceneView;
use App\Services\AI\FilmOS\Narrative\Story\StoryView;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectType;
use App\Services\AI\FilmOS\Narrative\World\WorldView;
use App\Services\AI\FilmOS\Prompting\IR\KeyVisual;
use App\Services\AI\FilmOS\Prompting\IR\PromptEnvironment;
use App\Services\AI\FilmOS\Prompting\IR\ShotPrompt;
use App\Services\AI\FilmOS\Prompting\IR\StructuredPrompt;
use App\Services\AI\FilmOS\Prompting\IR\SubjectDescriptor;
use App\Services\AI\FilmOS\Prompting\IR\VisualStyle;

/**
 * The bridge from Knowledge Layer to Prompt Layer.
 *
 *   NarrativeState (4 Views) + NarrativeAuditReport
 *       → NarrativePromptCompiler → StructuredPrompt → PromptRenderer → RenderedPrompt
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
 * BOUNDARY (same as KnowledgeExtractor): reads only the six View interfaces
 * and NarrativeAuditReport. Knows nothing of Timeline internals, Projection
 * classes, MeaningGraph, or GoalGraph.
 *
 * PRODUCTION RULE (frozen 2026-07-13): the compiler COPIES production
 * knowledge into the IR — it never converts it into render decisions.
 * energy=90 is copied as energy=90; "strong camera shake" is an adapter
 * decision. ProductionView → Prompt IR, never ProductionView → Render Decision.
 *
 * BLOCKING GATE: shots carrying a blocking QA finding (e.g. D4.NO_CAMERA)
 * are excluded from the IR — not policy, physical incapability: a shot with
 * no camera cannot be correctly compiled (the definition of blocking).
 * Non-blocking findings are ignored here; acting on them is the consumer's
 * decision. The compiler never throws and never mutates state.
 */
final class NarrativePromptCompiler
{
    /**
     * @param KeyVisual[] $keyVisuals article-derived visual details (facts[].visual_hint),
     *                                organized here by relevance; the article's own priority.
     */
    public function compile(
        StoryView            $story,
        CharacterView        $characters,
        SceneView            $scene,
        WorldView            $world,
        ProductionView       $production,
        PerformanceView      $performance,
        NarrativeAuditReport $audit,
        array                $keyVisuals = [],
        ?VisualStyle         $visualStyle = null,
    ): StructuredPrompt {
        $blockedOrdinals = $this->blockedOrdinals($audit);
        $environment     = $this->flattenEnvironment($world);

        $shots = [];
        foreach ($story->allShots() as $shot) {
            if (isset($blockedOrdinals[$shot->ordinal])) {
                continue;
            }

            $camera = $scene->getCamera($shot->ordinal);

            $shots[$shot->ordinal] = new ShotPrompt(
                ordinal:         $shot->ordinal,
                beat:            $shot->beat,
                action:          $shot->description,
                emotions:        $this->emotionsAt($characters, $shot->ordinal),
                camera:          $camera,
                environment:     $environment,
                endingFrame:     $shot->endingFrame,
                durationSeconds: $production->durationAt($shot->ordinal),
                energy:          $production->energyAt($shot->ordinal),
                performances:    $performance->performancesAt($shot->ordinal),
                focusSubjectId:  $this->focusSubjectId($scene, $camera),
                visibleSubjectIds: $this->visibleSubjectIds($scene, $shot->ordinal),
            );
        }

        return new StructuredPrompt(
            shots:          $shots,
            subjects:       $this->selectSubjects($scene, $world, $this->appearanceByObject($characters)),
            motifs:         $production->motifs(),
            constraints:    $production->constraints(),
            heroMoment:     $production->heroMoment(),
            conflicts:      $production->conflictPlan()?->conflicts ?? [],
            keyVisuals:     $this->rankKeyVisuals($keyVisuals),
            visualStyle:    $visualStyle,
        );
    }

    /**
     * Selects the video's subjects for the vendor boundary: the WorldObjects
     * that at least one scene node references — never all of WorldView.
     *
     * A SceneNode is only a bridge; the descriptor represents the WorldObject
     * identity, so many nodes referencing the same object collapse into one.
     * A subject isPrimary when a camera focuses (in any shot) a node that
     * references it. Order is deterministic: primary first, then first
     * appearance in scene-node placement order — stable across vendors and
     * snapshot tests.
     *
     * @param array<string, array<string, string>> $appearanceByObject worldObjectId => appearance
     * @return SubjectDescriptor[]
     */
    private function selectSubjects(SceneView $scene, WorldView $world, array $appearanceByObject): array
    {
        // World objects a camera focuses somewhere → primary.
        $primary = [];
        foreach ($scene->allCameras() as $camera) {
            $focus = $camera->focusNodeId;
            if ($focus === null) {
                continue;
            }
            $node = $scene->allNodes()[$focus] ?? null;
            if ($node?->worldObjectRef !== null) {
                $primary[$node->worldObjectRef] = true;
            }
        }

        // How each world object participates visually. A node may appear as a
        // SUBJECT in one shot and BACKGROUND in another — the strongest wins,
        // so an object that is ever a subject is never demoted to background.
        $nodeTypes = [];
        foreach ($scene->allNodes() as $node) {
            $ref = $node->worldObjectRef;
            if ($ref === null) {
                continue;
            }
            if (!isset($nodeTypes[$ref]) || $node->type === SceneNodeType::SUBJECT) {
                $nodeTypes[$ref] = $node->type;
            }
        }

        // One descriptor per referenced world object, in first-appearance order.
        $subjects = [];
        $order    = [];
        $index    = 0;
        foreach ($scene->allNodes() as $node) {
            $ref = $node->worldObjectRef;
            if ($ref === null || isset($subjects[$ref])) {
                continue;
            }
            $object = $world->allObjects()[$ref] ?? null;
            if ($object === null) {
                continue;   // dangling ref — D4.DANGLING_WORLD_REF is a QA concern, not the compiler's
            }
            $subjects[$ref] = new SubjectDescriptor(
                id:         $object->id,
                type:       $object->type,
                label:      $object->label,
                attributes: $object->attributes,
                isPrimary:  isset($primary[$ref]),
                appearance: $appearanceByObject[$ref] ?? [],
                nodeType:   $nodeTypes[$ref] ?? SceneNodeType::SUBJECT,
            );
            $order[$ref] = $index++;
        }

        $list = array_values($subjects);
        usort($list, static function (SubjectDescriptor $a, SubjectDescriptor $b) use ($order): int {
            if ($a->isPrimary !== $b->isPrimary) {
                return $a->isPrimary ? -1 : 1;   // primary first
            }
            return $order[$a->id] <=> $order[$b->id];   // then first appearance
        });

        return $list;
    }

    /**
     * The world objects in frame for THIS shot — staging, from the nodes the
     * shot placed. Deduped (many nodes may bridge to one object) and ordered by
     * placement so the adapter reads them in the order the scene declares them.
     *
     * @return string[] world-object ids
     */
    private function visibleSubjectIds(SceneView $scene, int $ordinal): array
    {
        $ids = [];
        foreach ($scene->nodesAt($ordinal) as $node) {
            if ($node->worldObjectRef !== null) {
                $ids[$node->worldObjectRef] = true;
            }
        }
        return array_keys($ids);
    }

    /**
     * The world object this shot's camera focuses — resolved from the camera's
     * focus NODE, because a node is only a bridge and the IR speaks in world
     * objects. Keeps attention per-beat instead of flattening it to a global flag.
     */
    private function focusSubjectId(SceneView $scene, ?CameraConfiguration $camera): ?string
    {
        if ($camera?->focusNodeId === null) {
            return null;
        }
        return ($scene->allNodes()[$camera->focusNodeId] ?? null)?->worldObjectRef;
    }

    /**
     * Appearance (visual-continuity detail) per world object, taken from the
     * character that references it — so the renderer can identify the subject
     * by outfit/build (article-authored), not just world-object attributes.
     *
     * @return array<string, array<string, string>> worldObjectId => appearance
     */
    private function appearanceByObject(CharacterView $characters): array
    {
        $map = [];
        foreach ($characters->allCharacters() as $memory) {
            $ref = $memory->profile->worldObjectRef;
            if ($ref !== null) {
                $map[$ref] = $memory->profile->appearance->all();
            }
        }
        return $map;
    }

    /**
     * Organize key visuals by the article's own priority (visual_relevance) —
     * most-relevant first. Semantic organization (ordering typed data), never
     * phrasing: the renderer decides how to word them.
     *
     * @param KeyVisual[] $keyVisuals
     * @return KeyVisual[]
     */
    private function rankKeyVisuals(array $keyVisuals): array
    {
        usort(
            $keyVisuals,
            static fn(KeyVisual $a, KeyVisual $b): int => $b->relevance->rank() <=> $a->relevance->rank(),
        );
        return $keyVisuals;
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

        // Location / environment world objects ARE the visual setting — surface
        // them (keyed by id) even when no scene node references them, so the
        // stadium/room/field the story happens in reaches the prompt. Setting
        // first, then atmospheric facts (crowd/weather/light).
        foreach ($world->allObjects() as $object) {
            if ($object->type === WorldObjectType::LOCATION || $object->type === WorldObjectType::ENVIRONMENT) {
                $details[$object->id] = $object->label;
            }
        }
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
