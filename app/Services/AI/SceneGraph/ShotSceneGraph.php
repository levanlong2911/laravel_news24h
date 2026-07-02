<?php

namespace App\Services\AI\SceneGraph;

use App\Services\AI\SceneGraph\Nodes\CameraNode;
use App\Services\AI\SceneGraph\Nodes\ContinuityNode;
use App\Services\AI\SceneGraph\Nodes\CompositionNode;
use App\Services\AI\SceneGraph\Nodes\DirectorNode;
use App\Services\AI\SceneGraph\Nodes\EnvironmentNode;
use App\Services\AI\SceneGraph\Nodes\PhysicsNode;
use App\Services\AI\SceneGraph\Nodes\SemanticNode;
use App\Services\AI\SceneGraph\Nodes\SubjectNode;
use App\Services\AI\SceneGraph\Nodes\TimelineNode;

/**
 * The resolved, validated representation of a single shot.
 *
 * This is the output of SceneGraphBuilder (after SceneGraphValidator confirms
 * it is internally consistent). Renderers receive ONLY this object — they never
 * see raw planner artifacts, DSL arrays, or ScenePlanningResult.
 *
 * Pipeline position:
 *   DSL → ScenePlanner → ScenePlanningResult
 *       → SceneGraphBuilder → SceneGraphValidator → ShotSceneGraph → Renderer
 *
 * Each property is a typed domain Node:
 *   camera      — resolved movement, lens, height, stabilization
 *   subject     — actor, action type, object in hand
 *   physics     — atmosphere, interaction, background, micro_motion layers
 *   composition — foreground/midground/background, subject position, leading lines
 *   director    — pacing, framing, motion blur, rack focus
 *   environment — weather, time, palette, field condition
 *   semantic    — model-neutral intent summary for cross-renderer use
 *   timeline    — event-driven phases with camera + environment per segment
 *   continuity  — identity lock + dynamic state chain for cross-shot consistency
 */
final class ShotSceneGraph
{
    public function __construct(
        public readonly string       $shotId,
        public readonly string       $sceneId,
        public readonly int          $shotOrder,
        /** Shot duration in seconds — needed by renderers for timestamp generation */
        public readonly float        $dur,
        /** DSL light code: W1, W2, G1, N1, N2, D1, S1, S2, C1, C2 */
        public readonly string       $lightCode,
        /** Scene title from DSL — used by PromptBlockAssembler for the SCENE block */
        public readonly string       $sceneTitle,
        public readonly CameraNode      $camera,
        public readonly SubjectNode     $subject,
        public readonly PhysicsNode     $physics,
        public readonly CompositionNode $composition,
        public readonly DirectorNode    $director,
        public readonly EnvironmentNode $environment,
        public readonly SemanticNode    $semantic,
        public readonly TimelineNode    $timeline,
        public readonly ContinuityNode  $continuity,
    ) {}

    /**
     * Serialize to array for JSON storage or backward-compat passes.
     * Renderers should prefer typed property access over this method.
     */
    public function toArray(): array
    {
        return [
            'shot_id'    => $this->shotId,
            'scene_id'   => $this->sceneId,
            'shot_order' => $this->shotOrder,
            'dur'        => $this->dur,
            'light_code'  => $this->lightCode,
            'scene_title' => $this->sceneTitle,
            'camera'     => $this->camera->toArray(),
            'subject'     => $this->subject->toArray(),
            'physics'     => $this->physics->toArray(),
            'composition' => $this->composition->toArray(),
            'director'    => $this->director->toArray(),
            'environment' => $this->environment->toArray(),
            'semantic'    => $this->semantic->toArray(),
            'timeline'    => $this->timeline->toArray(),
            'continuity'  => $this->continuity->toArray(),
        ];
    }
}
