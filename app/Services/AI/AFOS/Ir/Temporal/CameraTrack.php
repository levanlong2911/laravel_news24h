<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

/**
 * CameraTrack — time-coded camera arc for a single shot.
 *
 * Written by CameraArcStage; read by TemporalAssemblyStage and the serializer.
 *
 * Each keyframe is a point event: the camera state AT that timestamp.
 * The serializer interpolates between consecutive keyframes to produce the
 * CAMERA section of the structured prompt.
 */
final class CameraTrack extends TimelineTrack
{
    /** Domain track identity — used in NodeRef and TemporalGraph's track index. */
    public const ID = 'camera';

    /**
     * @param CameraKeyframe[] $events
     */
    public function __construct(array $events)
    {
        usort($events, fn(TimelineEvent $a, TimelineEvent $b) => $a->startSec <=> $b->startSec);
        parent::__construct($events);
    }

    /**
     * @return CameraKeyframe[] Keyframes in ascending atSec order.
     */
    public function keyframes(): array
    {
        return $this->events;
    }
}
