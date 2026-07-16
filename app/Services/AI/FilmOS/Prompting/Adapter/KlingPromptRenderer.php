<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter;

use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion;
use App\Services\AI\FilmOS\Narrative\Production\VisualConstraint;
use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguration;
use App\Services\AI\FilmOS\Prompting\IR\SubjectDescriptor;
use App\Services\AI\FilmOS\Prompting\Plan\BeatPlan;
use App\Services\AI\FilmOS\Prompting\Plan\PlanImportance;
use App\Services\AI\FilmOS\Prompting\Plan\PlanItem;
use App\Services\AI\FilmOS\Prompting\Plan\PlanSlot;
use App\Services\AI\FilmOS\Prompting\Plan\RenderPlan;

/**
 * Says a RenderPlan in Kling's language. Nothing else.
 *
 * It does not decide what belongs in the prompt, what owns what, what is
 * redundant, or what matters — the RenderPlanner already did, without knowing
 * this class exists. This adapter answers only two questions, and both are
 * things only it can know:
 *
 *   HOW to say each slot in Kling's idiom (enum → phrase, tags not prose);
 *   HOW MUCH fits, because only the vendor's own wording has a word cost, and
 *   ~200 words is a fact about Kling, not about the story.
 *
 * Budget rule: CRITICAL is always said. IMPORTANT and OPTIONAL are said while
 * there is room, in the plan's order. Dropping is triage the plan already ranked
 * — this class never re-decides importance, it just stops writing.
 *
 * Adding a vendor = a new class over the same RenderPlan. Adding knowledge =
 * a new PlanSlot, which surfaces here as an unhandled case rather than a
 * silently missing sentence.
 */
final class KlingPromptRenderer implements PromptRenderer
{
    /** Kling degrades on long prompts; this is a Kling fact, hence it lives here. */
    private const WORD_BUDGET = 200;

    /** VisualStyle → the Kling look for that genre. */
    private const STYLE_LOOK = [
        'cinematic'          => 'Hyperrealistic cinematic footage, film grain, shallow depth of field, sharp focus.',
        'sports_documentary' => 'Hyperrealistic broadcast sports footage, long-lens documentary look, natural colour, sharp focus.',
        'nature_documentary' => 'Hyperrealistic wildlife documentary footage, long-lens, natural colour, no grain.',
        'luxury_commercial'  => 'Glossy high-end commercial footage, high contrast, specular highlights, pristine surfaces.',
        'vintage_film'       => 'Vintage 35mm film footage, visible grain, halation, slightly faded colour.',
        'digital_clean'      => 'Clean modern digital footage, crisp detail, neutral colour, no grain.',
        'horror'             => 'Cold desaturated footage, deep shadows, low-key lighting, unsettling stillness.',
        'anime'              => 'Hand-drawn anime animation, cel shading, expressive linework.',
        'comic'              => 'Comic-book illustration style, bold ink outlines, flat graphic colour.',
    ];

    private const SHOT_TYPE = [
        'establishing'     => 'wide establishing shot',
        'wide'             => 'wide shot',
        'medium'           => 'medium shot',
        'close_up'         => 'close-up',
        'extreme_close_up' => 'extreme close-up',
        'two_shot'         => 'two-shot',
        'insert'           => 'insert shot',
    ];

    private const LENS = ['wide' => '24mm', 'normal' => '35mm', 'telephoto' => '85mm'];

    private const ANGLE = [
        'eye_level'     => '',
        'high'          => 'high angle',
        'low'           => 'low angle',
        'dutch'         => 'dutch tilt',
        'birds_eye'     => "bird's-eye view",
        'worms_eye'     => "worm's-eye view",
        'over_shoulder' => 'over-the-shoulder',
    ];

    private const MOVEMENT = [
        'static'   => 'locked-off',
        'pan'      => 'panning',
        'tilt'     => 'tilting up',
        'tracking' => 'tracking',
        'dolly'    => 'dolly move',
        'zoom'     => 'zoom',
        'handheld' => 'handheld',
    ];

    /** Emotion as observable behaviour — what a camera sees, not a label. */
    private const EMOTION_VISUAL = [
        'neutral'       => 'calm, even expression',
        'joy'           => 'open smile, bright eyes',
        'fear'          => 'eyes wide, jaw tight, shallow breath',
        'anger'         => 'brows drawn down, jaw clenched',
        'sadness'       => 'downcast eyes, heavy brow',
        'determination' => 'narrowed eyes, set jaw, forward lean',
        'surprise'      => 'eyes wide, brows raised, mouth parted',
    ];

    /** WorldObjectType → anatomy guard (the yacht lesson). */
    private const ANATOMY = [
        'character' => 'natural human anatomy, correct limb count, realistic hands',
        'animal'    => 'correct animal anatomy, natural coat, no human features',
        'vehicle'   => 'accurate mechanical detail, no human figures, no floating limbs',
    ];

    /** Which labelled block each global/ending/constraint slot belongs to. */
    private const BLOCK = [
        'subject_primary'    => 'SUBJECTS',
        'subject_secondary'  => 'SUBJECTS',
        'subject_background' => 'SUBJECTS',
        'anatomy'            => 'SUBJECTS',
        'environment'        => 'ENVIRONMENT',
        'motif_primary'      => 'VISUAL LANGUAGE',
        'motif_secondary'    => 'VISUAL LANGUAGE',
        'conflict'           => 'KEY VISUALS',
        'key_visual'         => 'KEY VISUALS',
        'hero_moment'        => 'FINAL SHOT',
        'constraint_always'  => 'STYLE',
    ];

    public function provider(): ProviderId
    {
        return ProviderId::KLING;
    }

    public function render(RenderPlan $plan): RenderedPrompt
    {
        $kept = $this->withinBudget($plan);

        return new RenderedPrompt(
            positive: $this->assemble($plan, $kept),
            negative: $this->negative($plan),
            metadata: $this->metadata($plan),
        );
    }

    // ── Budget ────────────────────────────────────────────────────────────────

    /**
     * Say everything CRITICAL, then keep saying while there is room. The plan's
     * order is obeyed inside each tier; importance is never re-judged here.
     *
     * @return array<int, true> keyed by spl_object_id of the surviving items
     */
    private function withinBudget(RenderPlan $plan): array
    {
        $kept  = [];
        $words = 0;

        $all = $this->positiveItems($plan);

        foreach ([PlanImportance::CRITICAL, PlanImportance::IMPORTANT, PlanImportance::OPTIONAL] as $tier) {
            // Within a tier, obey the plan's ORDER across the whole plan, not the
            // order beats happen to be listed in. Otherwise the first beat eats the
            // budget and the last one starves — losing the payoff's focus while an
            // earlier beat keeps its motion word. Order cuts the same slot from
            // every beat at once, which is what makes the drop fair.
            $tierItems = array_values(array_filter($all, static fn(PlanItem $i): bool => $i->importance === $tier));
            usort($tierItems, static fn(PlanItem $a, PlanItem $b): int => $a->order <=> $b->order);

            foreach ($tierItems as $item) {
                $line = $this->line($item);
                if ($line === '') {
                    continue;
                }
                $cost = str_word_count($line);
                if ($tier !== PlanImportance::CRITICAL && $words + $cost > self::WORD_BUDGET) {
                    continue;   // no room — the plan already said this is what to lose
                }
                $kept[spl_object_id($item)] = true;
                $words += $cost;
            }
        }

        return $kept;
    }

    /**
     * Everything that competes for the positive prompt's budget. NEVER
     * constraints are excluded: they are a separate field with no word pressure.
     *
     * @return PlanItem[]
     */
    private function positiveItems(RenderPlan $plan): array
    {
        $items = $plan->global;
        foreach ($plan->beats as $beat) {
            foreach ($beat->items as $item) {
                $items[] = $item;
            }
        }
        foreach ([...$plan->ending, ...$plan->constraints] as $item) {
            if ($item->slot !== PlanSlot::CONSTRAINT_NEVER) {
                $items[] = $item;
            }
        }
        return $items;
    }

    // ── Assembly ──────────────────────────────────────────────────────────────

    /** @param array<int, true> $kept */
    private function assemble(RenderPlan $plan, array $kept): string
    {
        $blocks = [];

        // The look opens the prompt with no label — it is the medium, not a section.
        foreach ($plan->global as $item) {
            if ($item->slot === PlanSlot::VISUAL_STYLE && isset($kept[spl_object_id($item)])) {
                $blocks[] = $this->line($item);
            }
        }

        $blocks = [...$blocks, ...$this->labelledBlocks($plan->global, $kept)];

        foreach ($plan->beats as $beat) {
            $lines = $this->lines($beat->items, $kept);
            if ($lines !== []) {
                $blocks[] = $this->beatLabel($beat) . "\n" . implode("\n", $lines);
            }
        }

        $blocks = [
            ...$blocks,
            ...$this->labelledBlocks($plan->ending, $kept),
            $this->styleBlock($plan, $kept),
        ];

        return implode("\n\n", array_filter($blocks, static fn(string $b): bool => $b !== ''));
    }

    /**
     * Kling boilerplate plus the plan's ALWAYS constraints. The boilerplate is
     * this vendor's standing instructions — Kling resets framing between beats
     * unless told to hold, and its "no text" must be narrowed to overlays so it
     * stops erasing in-world signage the scene asked for. Same status as the
     * negative prompt's standard terms: vendor constants, not story content.
     *
     * @param array<int, true> $kept
     */
    private function styleBlock(RenderPlan $plan, array $kept): string
    {
        $lines = [
            'Sharp focus. No overlaid text, no subtitles, no watermark, no captions.',
            "One continuous cinematic shot, never cutting. Hold each beat's focus subject in frame throughout.",
        ];
        foreach ($plan->constraints as $item) {
            if ($item->slot === PlanSlot::CONSTRAINT_ALWAYS && isset($kept[spl_object_id($item)])) {
                $lines[] = $this->line($item);
            }
        }

        return "STYLE\n" . implode("\n", $lines);
    }

    /**
     * Group items into their labelled blocks, preserving plan order.
     *
     * @param PlanItem[]       $items
     * @param array<int, true> $kept
     * @return string[]
     */
    private function labelledBlocks(array $items, array $kept): array
    {
        $byBlock = [];
        foreach ($items as $item) {
            $label = self::BLOCK[$item->slot->value] ?? null;
            if ($label === null || !isset($kept[spl_object_id($item)])) {
                continue;
            }
            $line = $this->line($item);
            if ($line !== '') {
                $byBlock[$label][] = $line;
            }
        }

        $blocks = [];
        foreach ($byBlock as $label => $lines) {
            $blocks[] = $label . "\n" . implode("\n", $lines);
        }
        return $blocks;
    }

    /**
     * @param PlanItem[]       $items
     * @param array<int, true> $kept
     * @return string[]
     */
    private function lines(array $items, array $kept): array
    {
        $lines = [];
        foreach ($items as $item) {
            if (!isset($kept[spl_object_id($item)])) {
                continue;
            }
            $line = $this->line($item);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    private function beatLabel(BeatPlan $beat): string
    {
        return $beat->beat !== null ? strtoupper($beat->beat->value) : 'SHOT ' . ($beat->ordinal + 1);
    }

    // ── Wording: one slot, one phrase ─────────────────────────────────────────

    private function line(PlanItem $item): string
    {
        return match ($item->slot) {
            PlanSlot::VISUAL_STYLE       => self::STYLE_LOOK[$item->payload->value] ?? self::STYLE_LOOK['cinematic'],
            PlanSlot::SUBJECT_PRIMARY    => 'Primary: ' . $this->subjectList($item->payload) . '.',
            PlanSlot::SUBJECT_SECONDARY  => 'Secondary: ' . $this->subjectList($item->payload) . '.',
            PlanSlot::SUBJECT_BACKGROUND => 'Background: ' . $this->subjectList($item->payload) . '.',
            PlanSlot::ANATOMY            => $this->anatomy($item->payload),
            PlanSlot::ENVIRONMENT        => $this->environment($item->payload),
            PlanSlot::MOTIF_PRIMARY      => 'Primary motif: ' . $this->motifList($item->payload) . '.',
            PlanSlot::MOTIF_SECONDARY    => 'Secondary: ' . $this->motifList($item->payload) . '.',
            PlanSlot::KEY_VISUAL         => ucfirst(rtrim($item->payload->hint, '.')) . '.',
            PlanSlot::CONFLICT           => ucfirst(rtrim($item->payload->description, '.')) . '.',
            PlanSlot::CAMERA             => ucfirst($this->cameraPhrase($item->payload)) . '.',
            PlanSlot::IN_FRAME           => 'In frame: ' . $this->join($this->labels($item->payload)) . '.',
            PlanSlot::FOCUS              => 'Focus: ' . $item->payload->label . '.',
            PlanSlot::ACTION             => rtrim($item->payload, '.') . '.',
            PlanSlot::EMOTION            => ucfirst($this->emotionVisual($item->payload)) . '.',
            PlanSlot::PERFORMANCE_CUE    => ucfirst(rtrim($item->payload->description, '.')) . '.',
            PlanSlot::MOTION             => ucfirst($this->motionWord($item->payload)) . '.',
            PlanSlot::ENDING_FRAME       => ucfirst(rtrim($item->payload->description, '.')) . '.',
            PlanSlot::HERO_MOMENT        => "Freeze the frame, everything goes still.\n"
                                            . ucfirst(rtrim($item->payload->description, '.')) . '.',
            PlanSlot::CONSTRAINT_ALWAYS  => $this->alwaysPhrase($item->payload),
            PlanSlot::CONSTRAINT_NEVER   => '',   // negative prompt only
        };
    }

    /** @param SubjectDescriptor[] $subjects */
    private function subjectList(array $subjects): string
    {
        return $this->join(array_map(fn(SubjectDescriptor $s): string => $this->subjectLabel($s), $subjects));
    }

    private function subjectLabel(SubjectDescriptor $s): string
    {
        // Authored appearance beats bare world-object attributes: richer identity.
        $detail = $s->appearance !== [] ? array_values($s->appearance) : array_values($s->attributes->all());
        return $detail === [] ? $s->label : $s->label . ' (' . implode(', ', $detail) . ')';
    }

    /** @param SubjectDescriptor[] $subjects @return string[] */
    private function labels(array $subjects): array
    {
        return array_values(array_unique(array_map(static fn(SubjectDescriptor $s): string => $s->label, $subjects)));
    }

    /** @param SubjectDescriptor[] $subjects */
    private function anatomy(array $subjects): string
    {
        $guards = [];
        foreach ($subjects as $s) {
            $guard = self::ANATOMY[$s->type->value] ?? null;
            if ($guard !== null) {
                $guards[$guard] = true;   // two vehicles → one guard
            }
        }
        return $guards === [] ? '' : ucfirst(implode('. ', array_keys($guards))) . '.';
    }

    /** @param array<string, string> $details factKey => value */
    private function environment(array $details): string
    {
        $phrases = [];
        foreach ($details as $key => $value) {
            $phrases[$this->envPhrase($key, $value)] = true;
        }
        return $phrases === [] ? '' : ucfirst(implode(', ', array_keys($phrases))) . '.';
    }

    /** The fact KEY gives the value a concrete noun — phrasing, hence adapter work. */
    private function envPhrase(string $key, string $value): string
    {
        return match ($key) {
            'crowd' => "{$value} crowd",
            'light' => "{$value} light",
            default => $value,
        };
    }

    /** @param \App\Services\AI\FilmOS\Narrative\Production\VisualMotif[] $motifs */
    private function motifList(array $motifs): string
    {
        return $this->join(array_map(static fn($m): string => $m->label, $motifs));
    }

    private function alwaysPhrase(VisualConstraint $c): string
    {
        return ucfirst("keep the {$c->target} {$c->rule} in every frame; never lose the {$c->target}") . '.';
    }

    /** Compact tags, not prose: "close-up, 85mm, low angle, tracking". */
    private function cameraPhrase(CameraConfiguration $cam): string
    {
        return implode(', ', array_filter([
            self::SHOT_TYPE[$cam->shotType->value] ?? $cam->shotType->value,
            self::LENS[$cam->lens->value]          ?? $cam->lens->value,
            self::ANGLE[$cam->angle->value]        ?? '',
            self::MOVEMENT[$cam->movement->value]  ?? $cam->movement->value,
        ]));
    }

    private function emotionVisual(CharacterEmotion $emotion): string
    {
        return self::EMOTION_VISUAL[$emotion->state->value] ?? $emotion->state->value;
    }

    /** Energy → motion intensity. The plan supplies the number; Kling gets the word. */
    private function motionWord(int $energy): string
    {
        return match (true) {
            $energy >= 85 => 'explosive motion',
            $energy >= 55 => 'urgent motion',
            $energy >= 25 => 'tense, controlled motion',
            default       => 'still, held motion',
        };
    }

    private function negative(RenderPlan $plan): ?string
    {
        // "text overlay" not bare "text": bare "text" also suppresses in-world
        // signage (a scoreboard, a jersey number) the scene explicitly asks for.
        $terms = ['extra limbs', 'deformed hands', 'warping', 'text overlay', 'subtitles', 'watermark'];

        foreach ($plan->constraints as $item) {
            if ($item->slot === PlanSlot::CONSTRAINT_NEVER) {
                $terms[] = trim("{$item->payload->target} {$item->payload->rule}");
            }
        }

        return implode(', ', $terms);
    }

    /**
     * Clip length is NOT reported here any more: it is the scenario's authored
     * duration, which the render command reads straight from the document. It
     * was only ever a sum of beat timings that happened to pass through the
     * prompt, and the plan has no reason to carry it.
     *
     * @return array<string, mixed>
     */
    private function metadata(RenderPlan $plan): array
    {
        $energyPeak = null;
        foreach ($plan->beats as $beat) {
            foreach ($beat->items as $item) {
                if ($item->slot === PlanSlot::MOTION) {
                    $energyPeak = max($energyPeak ?? 0, (int) $item->payload);
                }
            }
        }

        $meta = ['provider' => ProviderId::KLING->value];
        if ($energyPeak !== null) {
            $meta['energy_peak'] = $energyPeak;
        }
        return $meta;
    }

    /** @param string[] $items */
    private function join(array $items): string
    {
        if (count($items) <= 1) {
            return implode('', $items);
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }
}
