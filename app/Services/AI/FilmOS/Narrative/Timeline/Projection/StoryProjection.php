<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline\Projection;

use App\Services\AI\FilmOS\Narrative\Story\StoryBeat;
use App\Services\AI\FilmOS\Narrative\Story\StoryShot;
use App\Services\AI\FilmOS\Narrative\Story\StoryView;

/**
 * Snapshot of story state at a given timeline point.
 *
 * $shots is ACCUMULATED HISTORY keyed by shot ordinal — one StoryShot per
 * planned shot, in ordinal order (same contract as SceneProjection::$cameras).
 *
 * PromptCompiler, D5 QA and C.8 MUST depend on StoryView, not this class.
 */
final class StoryProjection implements StoryView
{
    /**
     * @param array<int, StoryShot> $shots keyed by shot ordinal
     */
    public function __construct(
        public readonly array $shots = [],
    ) {}

    public function hasShot(int $ordinal): bool
    {
        return isset($this->shots[$ordinal]);
    }

    public function shotAt(int $ordinal): ?StoryShot
    {
        return $this->shots[$ordinal] ?? null;
    }

    public function latestShot(): ?StoryShot
    {
        if ($this->shots === []) {
            return null;
        }

        return $this->shots[max(array_keys($this->shots))];
    }

    public function beatOf(int $ordinal): ?StoryBeat
    {
        return $this->shotAt($ordinal)?->beat;
    }

    /** @return array<int, StoryShot> */
    public function allShots(): array
    {
        return $this->shots;
    }
}
