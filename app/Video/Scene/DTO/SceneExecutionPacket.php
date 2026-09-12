<?php

declare(strict_types=1);

namespace App\Video\Scene\DTO;

final readonly class SceneExecutionPacket
{
    /** @param array<string, mixed> $identityLockJson @param array<string, mixed> $sceneStateGraphJson */
    public function __construct(
        public string $version,
        public string $renderPlanId,
        public string $renderPlanHash,
        public string $canonicalRevisionId,
        public string $canonicalHash,
        public string $identityLockId,
        public string $identityLockHash,
        public array $identityLockJson,
        public array $sceneStateGraphJson,
        public string $sceneStateGraphHash,
        public FrozenScene $scene,
        public ?string $previousSceneArtifactId = null,
        public ?string $previousSceneArtifactHash = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'render_plan_id' => $this->renderPlanId,
            'render_plan_hash' => $this->renderPlanHash,
            'canonical_revision_id' => $this->canonicalRevisionId,
            'canonical_hash' => $this->canonicalHash,
            'identity_lock_id' => $this->identityLockId,
            'identity_lock_hash' => $this->identityLockHash,
            'identity_lock_json' => $this->identityLockJson,
            'scene_state_graph_json' => $this->sceneStateGraphJson,
            'scene_state_graph_hash' => $this->sceneStateGraphHash,
            'scene' => $this->scene->toArray(),
            'previous_scene_artifact_id' => $this->previousSceneArtifactId,
            'previous_scene_artifact_hash' => $this->previousSceneArtifactHash,
        ];
    }
}
