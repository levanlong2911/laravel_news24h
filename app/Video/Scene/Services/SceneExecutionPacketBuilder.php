<?php

declare(strict_types=1);

namespace App\Video\Scene\Services;

use App\Models\VideoRenderPlan;
use App\Models\VideoShot;
use App\Video\Identity\Models\IdentityLock;
use App\Video\Scene\DTO\FrozenScene;
use App\Video\Scene\DTO\SceneExecutionPacket;
use RuntimeException;

final class SceneExecutionPacketBuilder
{
    public const VERSION = 'scene-execution-packet-v1';

    public function build(VideoRenderPlan $plan, VideoShot $shot, IdentityLock $identityLock): SceneExecutionPacket
    {
        $planJson = $plan->plan_json ?? [];
        $stateGraph = $planJson['scene_state_graph'] ?? null;
        $stateGraphHash = $planJson['scene_state_graph_hash'] ?? null;

        if (! is_array($stateGraph) || ! is_string($stateGraphHash) || $stateGraphHash === '') {
            throw new RuntimeException('Render plan has no frozen scene state graph.');
        }

        if (($planJson['identity_lock_id'] ?? null) !== $identityLock->id) {
            throw new RuntimeException('Render plan identity lock id mismatch.');
        }

        if (($planJson['identity_lock_hash'] ?? null) !== $identityLock->identity_lock_hash) {
            throw new RuntimeException('Render plan identity lock hash mismatch.');
        }

        $scene = $this->sceneFromShot($shot);

        return new SceneExecutionPacket(
            version: self::VERSION,
            renderPlanId: $plan->id,
            renderPlanHash: (string) ($plan->plan_hash ?? $planJson['render_plan_hash'] ?? ''),
            canonicalRevisionId: (string) $identityLock->canonical_concept_revision_id,
            canonicalHash: $identityLock->canonical_hash,
            identityLockId: $identityLock->id,
            identityLockHash: $identityLock->identity_lock_hash,
            identityLockJson: $identityLock->manifest_json,
            sceneStateGraphJson: $stateGraph,
            sceneStateGraphHash: $stateGraphHash,
            scene: $scene,
            previousSceneArtifactId: $shot->spec_json['previous_scene_artifact_id'] ?? null,
            previousSceneArtifactHash: $shot->spec_json['previous_scene_artifact_hash'] ?? null,
        );
    }

    private function sceneFromShot(VideoShot $shot): FrozenScene
    {
        $spec = $shot->spec_json ?? [];

        return new FrozenScene(
            sceneId: (string) ($shot->scene_id ?? $shot->shot_code),
            sequenceIndex: (int) ($shot->scene_sequence_index ?? $shot->beat),
            title: (string) ($spec['title'] ?? $shot->shot_code),
            visualGoal: (string) ($spec['visual_goal'] ?? $shot->shot_type ?? ''),
            fromStateId: $shot->from_state_id,
            toStateId: (string) $shot->to_state_id,
            transitionId: $shot->transition_id,
            continuityMode: (string) ($spec['continuity_mode'] ?? 'independent'),
            camera: $spec['camera'] ?? [],
            motion: $spec['motion'] ?? [],
            durationSeconds: (float) ($spec['duration_seconds'] ?? 0),
            metadata: $spec['metadata'] ?? null,
        );
    }
}
