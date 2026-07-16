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
use App\Services\AI\FilmOS\Prompting\IR\VisualStyle;

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
 * TRIAGE — content a camera cannot read is dropped here rather than in the
 *   adapter: emotion is planned only where the shot is close enough to see a
 *   face, and of a beat's acting cues only the first gross-motor one, because
 *   current video models render body movement and not micro-expression, breath
 *   or gaze. What each slot is worth losing lives in ImportancePolicy.
 *
 * ORDERING — sequence, from OrderPolicy. Kept separate from importance: ordering
 *   is dramaturgy, importance is triage, and one integer cannot be both.
 *
 * Importance and order are stateless tables rather than pipeline passes: they are
 * properties of a slot, so a pass that rebuilt every immutable item just to stamp
 * one field on it would be plumbing, not design.
 */
final class RenderPlanner
{
    public function __construct(
        private readonly ImportancePolicy $importance = new ImportancePolicy(),
        private readonly OrderPolicy      $order      = new OrderPolicy(),
    ) {}

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

        // Always planned. An unauthored scenario still needs a look: emitting
        // nothing left the prompt with no medium at all, which is worse than the
        // hardcoded line this replaced. CINEMATIC is the neutral default, and it
        // is a SEMANTIC choice ("an unspecified piece is a generic cinematic
        // piece"), so it belongs here rather than hidden in a vendor's fallback.
        $items[] = new PlanItem(
            PlanSlot::VISUAL_STYLE,
            $this->importance->for(PlanSlot::VISUAL_STYLE),
            $this->order->for(PlanSlot::VISUAL_STYLE),
            $prompt->visualStyle() ?? VisualStyle::CINEMATIC,
        );

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
            $items[] = new PlanItem(PlanSlot::SUBJECT_PRIMARY, $this->importance->for(PlanSlot::SUBJECT_PRIMARY), $this->order->for(PlanSlot::SUBJECT_PRIMARY), $primary);
        }
        if ($secondary !== []) {
            $items[] = new PlanItem(PlanSlot::SUBJECT_SECONDARY, $this->importance->for(PlanSlot::SUBJECT_SECONDARY), $this->order->for(PlanSlot::SUBJECT_SECONDARY), $secondary);
        }
        if ($background !== []) {
            $items[] = new PlanItem(PlanSlot::SUBJECT_BACKGROUND, $this->importance->for(PlanSlot::SUBJECT_BACKGROUND), $this->order->for(PlanSlot::SUBJECT_BACKGROUND), $background);
        }

        // The anatomy guard is never expendable: a deformed subject fails the shot.
        if ($prompt->subjects() !== []) {
            $items[] = new PlanItem(PlanSlot::ANATOMY, $this->importance->for(PlanSlot::ANATOMY), $this->order->for(PlanSlot::ANATOMY), $prompt->subjects());
        }

        $environment = $this->environment($prompt);
        if ($environment !== []) {
            $items[] = new PlanItem(PlanSlot::ENVIRONMENT, $this->importance->for(PlanSlot::ENVIRONMENT), $this->order->for(PlanSlot::ENVIRONMENT), $environment);
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
            $items[] = new PlanItem(PlanSlot::MOTIF_PRIMARY, $this->importance->for(PlanSlot::MOTIF_PRIMARY), $this->order->for(PlanSlot::MOTIF_PRIMARY), $primaryMotifs);
        }
        if ($secondaryMotifs !== []) {
            $items[] = new PlanItem(PlanSlot::MOTIF_SECONDARY, $this->importance->for(PlanSlot::MOTIF_SECONDARY), $this->order->for(PlanSlot::MOTIF_SECONDARY), $secondaryMotifs);
        }

        // OWNERSHIP: beat actions own what happens. A fact hint or a conflict
        // that merely restates a beat would be the same event said twice, so both
        // ride along as enrichment and are the first thing dropped.
        // Abstract conflicts (a running clock, inner doubt, crowd noise) have no
        // camera image at all and are never planned.
        foreach ($prompt->conflicts() as $conflict) {
            if (in_array($conflict->type, self::FILMABLE_CONFLICTS, true)) {
                $items[] = new PlanItem(PlanSlot::CONFLICT, $this->importance->for(PlanSlot::CONFLICT), $this->order->for(PlanSlot::CONFLICT), $conflict);
            }
        }
        foreach ($prompt->keyVisuals() as $visual) {
            $items[] = new PlanItem(PlanSlot::KEY_VISUAL, $this->importance->for(PlanSlot::KEY_VISUAL), $this->order->for(PlanSlot::KEY_VISUAL), $visual);
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
            // The camera and its aim are one fact: a setup includes what it points at.
            $direction = new CameraDirection(
                $shot->camera,
                $shot->focusSubjectId !== null ? ($subjects[$shot->focusSubjectId] ?? null) : null,
            );
            $items[] = new PlanItem(PlanSlot::CAMERA, $this->importance->for(PlanSlot::CAMERA), $this->order->for(PlanSlot::CAMERA), $direction);
        }

        // STAGING: only what the scene actually places in this beat.
        $inFrame = [];
        foreach ($shot->visibleSubjectIds as $id) {
            if (isset($subjects[$id])) {
                $inFrame[] = $subjects[$id];
            }
        }
        if ($inFrame !== []) {
            $items[] = new PlanItem(PlanSlot::IN_FRAME, $this->importance->for(PlanSlot::IN_FRAME), $this->order->for(PlanSlot::IN_FRAME), $inFrame);
        }


        // What happens is never expendable.
        $items[] = new PlanItem(PlanSlot::ACTION, $this->importance->for(PlanSlot::ACTION), $this->order->for(PlanSlot::ACTION), $shot->action);

        // TRIAGE: a face only reads when the camera is close to it.
        if ($this->isCloseShot($shot->camera)) {
            foreach ($shot->emotions as $emotion) {
                $items[] = new PlanItem(PlanSlot::EMOTION, $this->importance->for(PlanSlot::EMOTION), $this->order->for(PlanSlot::EMOTION), $emotion);
            }
        }

        if (($cue = $this->readableCue($shot)) !== null) {
            $items[] = new PlanItem(PlanSlot::PERFORMANCE_CUE, $this->importance->for(PlanSlot::PERFORMANCE_CUE), $this->order->for(PlanSlot::PERFORMANCE_CUE), $cue);
        }

        if ($shot->energy !== null) {
            $items[] = new PlanItem(PlanSlot::MOTION, $this->importance->for(PlanSlot::MOTION), $this->order->for(PlanSlot::MOTION), $shot->energy);
        }

        if ($shot->endingFrame !== null) {
            $items[] = new PlanItem(PlanSlot::ENDING_FRAME, $this->importance->for(PlanSlot::ENDING_FRAME), $this->order->for(PlanSlot::ENDING_FRAME), $shot->endingFrame);
        }

        return $items;
    }

    /** @return PlanItem[] */
    private function planEnding(StructuredPrompt $prompt): array
    {
        $hero = $prompt->heroMoment();
        return $hero === null ? [] : [new PlanItem(PlanSlot::HERO_MOMENT, $this->importance->for(PlanSlot::HERO_MOMENT), $this->order->for(PlanSlot::HERO_MOMENT), $hero)];
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
