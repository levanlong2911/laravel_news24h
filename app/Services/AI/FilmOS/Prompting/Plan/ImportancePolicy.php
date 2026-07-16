<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Plan;

/**
 * What each kind of content is worth losing, answered by one question:
 *
 *     If this line disappears, what does the model get WRONG?
 *
 * That question separates two things the old table had merged. Some content is
 * SEMANTIC — without it the scene is misunderstood (who, what happens). Other
 * content is RENDER CONTROL — the scene is understood perfectly and still shot
 * wrong (which lens, what the camera follows, who is in frame). Both are fatal,
 * so both are CRITICAL, and neither is "more important" than the other; they are
 * different kinds of wrong.
 *
 * What that reveals is that fine writing is not the same as necessary writing.
 * The hero moment and the ending frames are flourish: "the ball disappears into
 * the stadium lights" has already ended the beat, and "freeze the frame, ball
 * overhead, stadium frozen" is how a human would like it retold. When the budget
 * is short, that is what should go — not the camera.
 *
 * Calibrated against a real measurement, not taste: with ending frames CRITICAL,
 * the CRITICAL tier alone spent the whole 200-word budget and every camera, focus
 * and staging line was dropped. Twelve words of camera direction were being
 * traded away for thirty-five words of closing prose.
 *
 * Stateless — a table, deliberately not a pipeline pass. Importance is a property
 * of a slot, so it needs no state and no rebuild of anything.
 */
final class ImportancePolicy
{
    /**
     * @var array<string, PlanImportance> keyed by PlanSlot value
     *
     * CRITICAL   — losing it means a wrong scene or a wrong shot.
     * IMPORTANT  — the shot still reads; it is poorer.
     * OPTIONAL   — enrichment; first to go.
     */
    private const TABLE = [
        // Semantic: who and what.
        'subject_primary'    => PlanImportance::CRITICAL,   // lose the character
        'action'             => PlanImportance::CRITICAL,   // lose what happens
        'anatomy'            => PlanImportance::CRITICAL,   // a deformed subject fails the shot
        'visual_style'       => PlanImportance::CRITICAL,   // the wrong genre entirely

        // Render control: understood correctly, still shot wrong.
        'camera'             => PlanImportance::CRITICAL,   // wrong shot size / lens
        'focus'              => PlanImportance::CRITICAL,   // camera follows the wrong thing
        'in_frame'           => PlanImportance::CRITICAL,   // wrong staging; subjects appear that must not
        'constraint_always'  => PlanImportance::CRITICAL,   // a hard rule; losing it loses the ball
        'constraint_never'   => PlanImportance::CRITICAL,   // negative prompt — unbudgeted anyway

        // Poorer without, not wrong without.
        'environment'        => PlanImportance::IMPORTANT,  // lose the setting
        'emotion'            => PlanImportance::IMPORTANT,  // weaker performance
        'performance_cue'    => PlanImportance::IMPORTANT,  // less nuance
        'ending_frame'       => PlanImportance::IMPORTANT,  // flourish
        'hero_moment'        => PlanImportance::IMPORTANT,  // flourish on the climax

        // Enrichment.
        'motion'             => PlanImportance::OPTIONAL,
        'subject_secondary'  => PlanImportance::OPTIONAL,
        'subject_background' => PlanImportance::OPTIONAL,
        'motif_primary'      => PlanImportance::OPTIONAL,
        'motif_secondary'    => PlanImportance::OPTIONAL,
        'conflict'           => PlanImportance::OPTIONAL,   // beat actions already own the event
        'key_visual'         => PlanImportance::OPTIONAL,   // beat actions already own the event
    ];

    public function for(PlanSlot $slot): PlanImportance
    {
        // An unlisted slot is enrichment until someone decides otherwise: a new
        // kind of content must earn its way up, never arrive privileged.
        return self::TABLE[$slot->value] ?? PlanImportance::OPTIONAL;
    }
}
