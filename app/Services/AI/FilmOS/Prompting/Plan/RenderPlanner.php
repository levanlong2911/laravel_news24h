<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Plan;

use App\Services\AI\FilmOS\Narrative\Performance\PerformanceChannel;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceCue;
use App\Services\AI\FilmOS\Narrative\Production\ConflictType;
use App\Services\AI\FilmOS\Narrative\Production\ConstraintMode;
use App\Services\AI\FilmOS\Narrative\Production\MotifImportance;
use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguration;
use App\Services\AI\FilmOS\Narrative\Scene\SceneNodeType;
use App\Services\AI\FilmOS\Narrative\Scene\ShotType;
use App\Services\AI\FilmOS\Prompting\IR\ShotPrompt;
use App\Services\AI\FilmOS\Prompting\IR\StructuredPrompt;
use App\Services\AI\FilmOS\Prompting\IR\SubjectDescriptor;

/**
 * Decides WHAT is allowed into the prompt. It never words anything.
 *
 *   StructuredPrompt → RenderPlanner → RenderPlan → (vendor) Renderer
 *
 * It knows nothing about Kling, Veo, English, or word budgets. Knowing "200
 * words" would mean knowing a vendor, and that is the dependency this layer
 * exists to prevent. It only says what each item IS, how much it MATTERS, and
 * what ORDER it comes in; the renderer decides how to say it and how much fits.
 *
 * Its four jobs:
 *
 * OWNERSHIP — one source owns each piece of information, so nothing is said
 *   twice. Beat actions own what happens, so the article's fact hints are demoted
 *   to enrichment rather than repeated as their own section; per-beat staging owns
 *   who is present, so the cast list carries identity only. This is why the
 *   prompt stops growing every time knowledge is added: new knowledge must claim
 *   an owner, not append a section. No string comparison, no NLP — ownership is
 *   structural, therefore deterministic and testable.
 *
 * STAGING — a subject is planned into a beat only where the scene places it. A
 *   receiver that enters at the payoff is not in the hook, so the contradiction
 *   never reaches the renderer to be papered over with a negative prompt.
 *
 * TRIAGE — importance says what would hurt least to lose. Content that a camera
 *   cannot read is triaged here rather than in the adapter: emotion is planned
 *   only where the shot is close enough to see a face, and of a beat's acting
 *   cues only the first gross-motor one is planned, because current video models
 *   render body movement and not micro-expression, breath or gaze.
 *
 * ORDERING — sequence within a section. Kept separate from importance: ordering
 *   is dramaturgy, importance is triage, and one integer cannot be both.
 */
final class RenderPlanner
{
    /** Global section order. */
    private const ORDER_STYLE        = 10;
    private const ORDER_PRIMARY      = 20;
    private const ORDER_SECONDARY    = 21;
    private const ORDER_BACKGROUND   = 22;
    private const ORDER_ANATOMY      = 25;
    private const ORDER_ENVIRONMENT  = 30;
    private const ORDER_MOTIF        = 40;
    private const ORDER_CONFLICT     = 49;
    private const ORDER_KEY_VISUAL   = 50;

    /** Per-beat order. */
    private const ORDER_CAMERA   = 10;
    private const ORDER_IN_FRAME = 20;
    private const ORDER_FOCUS    = 30;
    private const ORDER_ACTION   = 40;
    private const ORDER_EMOTION  = 50;
    private const ORDER_CUE      = 60;
    private const ORDER_MOTION   = 70;
    private const ORDER_ENDING   = 80;

    /** Shot sizes where a face is close enough to read. */
    private const CLOSE_SHOTS = [ShotType::CLOSE_UP, ShotType::EXTREME_CLOSE_UP];

    /** Conflicts a camera can actually show; the rest are abstract. */
    private const FILMABLE_CONFLICTS = [ConflictType::PHYSICAL, ConflictType::ENVIRONMENTAL];

    /** Acting channels no current video model renders legibly. */
    private const UNREADABLE_CHANNELS = [
        PerformanceChannel::GAZE,
        PerformanceChannel::FACE,
        PerformanceChannel::BREATH,
        PerformanceChannel::VOICE,
    ];

    public function plan(StructuredPrompt $prompt): RenderPlan
    {
        $subjects = $this->subjectsById($prompt);

        return new RenderPlan(
            global:      $this->planGlobal($prompt),
            beats:       $this->planBeats($prompt, $subjects),
            ending:      $this->planEnding($prompt),
            constraints: $this->planConstraints($prompt),
        );
    }

    // ── Sections ──────────────────────────────────────────────────────────────

    /** @return PlanItem[] */
    private function planGlobal(StructuredPrompt $prompt): array
    {
        $items = [];

        if ($prompt->visualStyle() !== null) {
            $items[] = new PlanItem(PlanSlot::VISUAL_STYLE, PlanImportance::CRITICAL, self::ORDER_STYLE, $prompt->visualStyle());
        }

        // Identity, grouped by tier. Only the subjects the camera follows are
        // critical — the rest are named per beat by staging, so the cast list is
        // enrichment and a whole tier drops as one unit.
        $primary = $secondary = $background = [];
        foreach ($prompt->subjects() as $subject) {
            if ($subject->isPrimary) {
                $primary[] = $subject;
            } elseif ($subject->nodeType === SceneNodeType::BACKGROUND) {
                $background[] = $subject;
            } else {
                $secondary[] = $subject;
            }
        }
        if ($primary !== []) {
            $items[] = new PlanItem(PlanSlot::SUBJECT_PRIMARY, PlanImportance::CRITICAL, self::ORDER_PRIMARY, $primary);
        }
        if ($secondary !== []) {
            $items[] = new PlanItem(PlanSlot::SUBJECT_SECONDARY, PlanImportance::OPTIONAL, self::ORDER_SECONDARY, $secondary);
        }
        if ($background !== []) {
            $items[] = new PlanItem(PlanSlot::SUBJECT_BACKGROUND, PlanImportance::OPTIONAL, self::ORDER_BACKGROUND, $background);
        }

        // The anatomy guard is never expendable: a deformed subject fails the shot.
        if ($prompt->subjects() !== []) {
            $items[] = new PlanItem(PlanSlot::ANATOMY, PlanImportance::CRITICAL, self::ORDER_ANATOMY, $prompt->subjects());
        }

        $environment = $this->environment($prompt);
        if ($environment !== []) {
            $items[] = new PlanItem(PlanSlot::ENVIRONMENT, PlanImportance::CRITICAL, self::ORDER_ENVIRONMENT, $environment);
        }

        $primaryMotifs = $secondaryMotifs = [];
        foreach ($prompt->motifs() as $motif) {
            if ($motif->importance === MotifImportance::PRIMARY) {
                $primaryMotifs[] = $motif;
            } else {
                $secondaryMotifs[] = $motif;
            }
        }
        if ($primaryMotifs !== []) {
            $items[] = new PlanItem(PlanSlot::MOTIF_PRIMARY, PlanImportance::OPTIONAL, self::ORDER_MOTIF, $primaryMotifs);
        }
        if ($secondaryMotifs !== []) {
            $items[] = new PlanItem(PlanSlot::MOTIF_SECONDARY, PlanImportance::OPTIONAL, self::ORDER_MOTIF + 1, $secondaryMotifs);
        }

        // OWNERSHIP: beat actions own what happens. A fact hint or a conflict
        // that merely restates a beat would be the same event said twice, so both
        // ride along as enrichment and are the first thing dropped.
        // Abstract conflicts (a running clock, inner doubt, crowd noise) have no
        // camera image at all and are never planned.
        foreach ($prompt->conflicts() as $conflict) {
            if (in_array($conflict->type, self::FILMABLE_CONFLICTS, true)) {
                $items[] = new PlanItem(PlanSlot::CONFLICT, PlanImportance::OPTIONAL, self::ORDER_CONFLICT, $conflict);
            }
        }
        foreach ($prompt->keyVisuals() as $visual) {
            $items[] = new PlanItem(PlanSlot::KEY_VISUAL, PlanImportance::OPTIONAL, self::ORDER_KEY_VISUAL, $visual);
        }

        return $this->ordered($items);
    }

    /**
     * @param array<string, SubjectDescriptor> $subjects
     * @return BeatPlan[]
     */
    private function planBeats(StructuredPrompt $prompt, array $subjects): array
    {
        $shots = $prompt->shots();
        ksort($shots);

        $beats = [];
        foreach ($shots as $shot) {
            $beats[] = new BeatPlan($shot->ordinal, $shot->beat, $this->ordered($this->planBeatItems($shot, $subjects)));
        }
        return $beats;
    }

    /**
     * @param array<string, SubjectDescriptor> $subjects
     * @return PlanItem[]
     */
    private function planBeatItems(ShotPrompt $shot, array $subjects): array
    {
        $items = [];

        if ($shot->camera !== null) {
            $items[] = new PlanItem(PlanSlot::CAMERA, PlanImportance::IMPORTANT, self::ORDER_CAMERA, $shot->camera);
        }

        // STAGING: only what the scene actually places in this beat.
        $inFrame = [];
        foreach ($shot->visibleSubjectIds as $id) {
            if (isset($subjects[$id])) {
                $inFrame[] = $subjects[$id];
            }
        }
        if ($inFrame !== []) {
            $items[] = new PlanItem(PlanSlot::IN_FRAME, PlanImportance::IMPORTANT, self::ORDER_IN_FRAME, $inFrame);
        }

        if ($shot->focusSubjectId !== null && isset($subjects[$shot->focusSubjectId])) {
            $items[] = new PlanItem(PlanSlot::FOCUS, PlanImportance::IMPORTANT, self::ORDER_FOCUS, $subjects[$shot->focusSubjectId]);
        }

        // What happens is never expendable.
        $items[] = new PlanItem(PlanSlot::ACTION, PlanImportance::CRITICAL, self::ORDER_ACTION, $shot->action);

        // TRIAGE: a face only reads when the camera is close to it.
        if ($this->isCloseShot($shot->camera)) {
            foreach ($shot->emotions as $emotion) {
                $items[] = new PlanItem(PlanSlot::EMOTION, PlanImportance::IMPORTANT, self::ORDER_EMOTION, $emotion);
            }
        }

        if (($cue = $this->readableCue($shot)) !== null) {
            $items[] = new PlanItem(PlanSlot::PERFORMANCE_CUE, PlanImportance::IMPORTANT, self::ORDER_CUE, $cue);
        }

        if ($shot->energy !== null) {
            $items[] = new PlanItem(PlanSlot::MOTION, PlanImportance::IMPORTANT, self::ORDER_MOTION, $shot->energy);
        }

        if ($shot->endingFrame !== null) {
            $items[] = new PlanItem(PlanSlot::ENDING_FRAME, PlanImportance::CRITICAL, self::ORDER_ENDING, $shot->endingFrame);
        }

        return $items;
    }

    /** @return PlanItem[] */
    private function planEnding(StructuredPrompt $prompt): array
    {
        $hero = $prompt->heroMoment();
        return $hero === null ? [] : [new PlanItem(PlanSlot::HERO_MOMENT, PlanImportance::CRITICAL, 10, $hero)];
    }

    /** @return PlanItem[] */
    private function planConstraints(StructuredPrompt $prompt): array
    {
        $items = [];
        foreach ($prompt->constraints() as $constraint) {
            $slot = $constraint->mode === ConstraintMode::ALWAYS
                ? PlanSlot::CONSTRAINT_ALWAYS
                : PlanSlot::CONSTRAINT_NEVER;
            // Constraints are the director's hard rules — never expendable.
            $items[] = new PlanItem($slot, PlanImportance::CRITICAL, $slot === PlanSlot::CONSTRAINT_ALWAYS ? 10 : 20, $constraint);
        }
        return $this->ordered($items);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<string, string> factKey => value, deduped across beats */
    private function environment(StructuredPrompt $prompt): array
    {
        $details = [];
        foreach ($prompt->shots() as $shot) {
            foreach ($shot->environment->details as $key => $value) {
                $details[(string) $key] = (string) $value;
            }
        }
        return $details;
    }

    /** @return array<string, SubjectDescriptor> keyed by world-object id */
    private function subjectsById(StructuredPrompt $prompt): array
    {
        $byId = [];
        foreach ($prompt->subjects() as $subject) {
            $byId[$subject->id] = $subject;
        }
        return $byId;
    }

    private function isCloseShot(?CameraConfiguration $camera): bool
    {
        return $camera !== null && in_array($camera->shotType, self::CLOSE_SHOTS, true);
    }

    /**
     * The one acting cue worth planning for this beat: the first on a channel a
     * camera can actually read. Gaze, face, breath and voice are dropped here
     * rather than in an adapter — it is a fact about what film can show, not
     * about how one vendor phrases things.
     */
    private function readableCue(ShotPrompt $shot): ?PerformanceCue
    {
        foreach ($shot->performances as $performance) {
            foreach ($performance->cues as $cue) {
                if (!in_array($cue->channel, self::UNREADABLE_CHANNELS, true)) {
                    return $cue;
                }
            }
        }
        return null;
    }

    /**
     * @param PlanItem[] $items
     * @return PlanItem[]
     */
    private function ordered(array $items): array
    {
        usort($items, static fn(PlanItem $a, PlanItem $b): int => $a->order <=> $b->order);
        return $items;
    }
}
