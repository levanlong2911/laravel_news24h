<?php

namespace App\Video\Producer;

/**
 * Creative direction cap PHIM (Producer role) — dung field dung ten schema
 * `contracts/renderplan/v1.0/schema.json#/properties/producer`. Validated
 * bang render 2026-07-19.
 *
 * BAT BIEN: chi narrative. KHONG duoc chua camera/lighting/composition/action
 * — do la viec cua IntentPlanner/EditorialInterpreter/Director, khong phai
 * Producer. Producer khong lam thay doi StoryGraph (StoryPlanner van chi doc
 * VerifiedWorldGraph, xem StoryPlanner.php dong 11-14) — day la metadata song
 * song, khong phai input cua ranking.
 */
final class ProducerOutput
{
    /**
     * @param list<string> $emotionalCurve
     */
    public function __construct(
        public readonly string $targetAudience,
        public readonly string $coreConflict,
        public readonly string $visualPromise,
        public readonly array $emotionalCurve,
    ) {
    }

    public function toArray(): array
    {
        return [
            'target_audience' => $this->targetAudience,
            'core_conflict'   => $this->coreConflict,
            'visual_promise'  => $this->visualPromise,
            'emotional_curve' => $this->emotionalCurve,
        ];
    }
}
