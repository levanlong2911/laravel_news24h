<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Plan;

use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguration;
use App\Services\AI\FilmOS\Prompting\IR\SubjectDescriptor;

/**
 * A camera setup and what it is aimed at — one fact, not two.
 *
 * Attention used to be its own PlanSlot, which meant the prompt said the same
 * thing twice: "Wide shot, 24mm, low angle, tilting up." and then "Focus:
 * Football." A camera setup INCLUDES what it points at — CameraConfiguration has
 * carried $focusNodeId all along — so splitting them was an ownership break of
 * exactly the kind the planner exists to prevent, and it cost a model the
 * strongest reading of the shot: "tilting up to follow the football" is a
 * camera move with a target, while two separate lines are a move and a wish.
 *
 * The planner resolves the focus NODE to the world object it bridges to, because
 * the IR speaks in subjects and a node is only plumbing.
 *
 * Immutable.
 */
final class CameraDirection
{
    public function __construct(
        public readonly CameraConfiguration $camera,
        /** null when this shot aims at nothing in particular. */
        public readonly ?SubjectDescriptor  $focus = null,
    ) {}
}
