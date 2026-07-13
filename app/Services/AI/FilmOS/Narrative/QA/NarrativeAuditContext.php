<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\QA;

use App\Services\AI\FilmOS\Narrative\Character\CharacterView;
use App\Services\AI\FilmOS\Narrative\Scene\SceneView;
use App\Services\AI\FilmOS\Narrative\Timeline\NarrativeState;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\StoryProjection;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticTimeline;
use App\Services\AI\FilmOS\Narrative\World\WorldView;

/**
 * Everything a NarrativeRule may read — and the ONLY thing it receives.
 *
 * Extension point: when future rules need AssetRegistry, CameraCalibration,
 * PlannerMetadata, BenchmarkHints… they are added HERE as new accessors.
 * The NarrativeRule interface signature never changes again.
 *
 * Read-only by construction: exposes no mutating operations. Rules cannot
 * append to the timeline through this context (Single Writer stays
 * TimelineRecorder).
 */
final class NarrativeAuditContext
{
    public function __construct(
        private readonly SemanticTimeline $timeline,
        private readonly NarrativeState   $state,
    ) {}

    public function timeline(): SemanticTimeline
    {
        return $this->timeline;
    }

    public function state(): NarrativeState
    {
        return $this->state;
    }

    // Domain shortcuts — rules read the domain they audit without walking $state

    public function story(): StoryProjection
    {
        return $this->state->story;
    }

    public function characters(): CharacterView
    {
        return $this->state->characters;
    }

    public function world(): WorldView
    {
        return $this->state->world;
    }

    public function scene(): SceneView
    {
        return $this->state->scene;
    }
}
