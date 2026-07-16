<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Plan;

/**
 * WHAT a plan item is — the renderer's dispatch key.
 *
 * Each slot has a fixed payload type (see PlanItem::$payload), so a renderer is
 * a total match() over this enum and nothing else. Adding a slot is the only way
 * to add a new kind of content, which keeps every vendor adapter honest: if a
 * renderer does not handle a slot, that is a compile-time-visible gap, not a
 * silently missing sentence.
 */
enum PlanSlot: string
{
    // ── Global ────────────────────────────────────────────────────────────────
    // Subjects and motifs are grouped BY TIER, one item per tier: a tier is what
    // survives or is dropped as a unit, and grouping keeps the renderer a pure
    // match() instead of something that must aggregate items back together.
    case VISUAL_STYLE       = 'visual_style';       // VisualStyle
    case SUBJECT_PRIMARY    = 'subject_primary';    // SubjectDescriptor[]
    case SUBJECT_SECONDARY  = 'subject_secondary';  // SubjectDescriptor[]
    case SUBJECT_BACKGROUND = 'subject_background'; // SubjectDescriptor[]
    case ANATOMY            = 'anatomy';            // SubjectDescriptor[]
    case ENVIRONMENT        = 'environment';        // array<string,string> factKey => value
    case MOTIF_PRIMARY      = 'motif_primary';      // VisualMotif[]
    case MOTIF_SECONDARY    = 'motif_secondary';    // VisualMotif[]

    // ── Per beat ──────────────────────────────────────────────────────────────
    case CAMERA           = 'camera';            // CameraConfiguration
    case IN_FRAME         = 'in_frame';          // SubjectDescriptor[]
    case FOCUS            = 'focus';             // SubjectDescriptor
    case ACTION           = 'action';            // string (authored beat action — data, not wording)
    case EMOTION          = 'emotion';           // CharacterEmotion
    case PERFORMANCE_CUE  = 'performance_cue';   // PerformanceCue
    case MOTION           = 'motion';            // int (energy 0-100)
    case ENDING_FRAME     = 'ending_frame';      // EndingFrame

    // ── Ending ────────────────────────────────────────────────────────────────
    case HERO_MOMENT      = 'hero_moment';       // HeroMoment

    // ── Constraints ───────────────────────────────────────────────────────────
    case CONSTRAINT_ALWAYS = 'constraint_always'; // VisualConstraint
    case CONSTRAINT_NEVER  = 'constraint_never';  // VisualConstraint

    // ── Enrichment ────────────────────────────────────────────────────────────
    // Beat actions own what happens, so these only reinforce it. They earn a
    // place when there is room and are the first thing dropped when there is not.
    case KEY_VISUAL        = 'key_visual';        // KeyVisual (article fact hint)
    case CONFLICT          = 'conflict';          // Conflict (filmable pressure)
}
