<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\IR;

use App\Services\AI\FilmOS\Narrative\Scene\SceneNodeType;
use App\Services\AI\FilmOS\Narrative\Shared\AttributeBag;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectType;

/**
 * A subject that appears in the video, selected by the compiler for the
 * vendor boundary — the answer to "what is being rendered?".
 *
 * Always represents a WorldObject IDENTITY, never a SceneNode: many nodes
 * (hero_node, hero_close_node, hero_reflection_node) that reference the same
 * world object collapse into ONE descriptor. The compiler selects only world
 * objects a scene node actually references — not all of WorldView — so the
 * renderer reasons over a handful of subjects, not the whole world.
 *
 * $type is the key field: it carries WorldObjectType into the IR so adapters
 * derive anatomy constraints from typed knowledge (VEHICLE → no human figures,
 * ANIMAL → animal anatomy, CHARACTER → human anatomy) instead of regex-guessing
 * strings (the Sprint-3 yacht lesson).
 *
 * Immutable.
 */
final class SubjectDescriptor
{
    /** @param array<string, string> $appearance visual-continuity detail from the character (outfit, build…) */
    public function __construct(
        public readonly string          $id,          // WorldObject id
        public readonly WorldObjectType $type,
        public readonly string          $label,
        public readonly AttributeBag    $attributes,
        public readonly bool            $isPrimary,   // a camera focuses this subject in some shot
        public readonly array           $appearance = [],
        /**
         * How this object PARTICIPATES visually (SceneNodeType), as opposed to
         * what it IS ($type). Gives the adapter a real tier — a focused subject,
         * a supporting subject, and background are three different weights —
         * instead of only the primary/not-primary boolean.
         */
        public readonly SceneNodeType   $nodeType = SceneNodeType::SUBJECT,
    ) {}
}
