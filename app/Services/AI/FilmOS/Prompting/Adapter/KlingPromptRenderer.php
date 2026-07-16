<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter;

use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceChannel;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceCue;
use App\Services\AI\FilmOS\Narrative\Production\ConflictType;
use App\Services\AI\FilmOS\Narrative\Production\ConstraintMode;
use App\Services\AI\FilmOS\Narrative\Production\MotifImportance;
use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguration;
use App\Services\AI\FilmOS\Narrative\Scene\SceneNodeType;
use App\Services\AI\FilmOS\Prompting\IR\ShotPrompt;
use App\Services\AI\FilmOS\Prompting\IR\StructuredPrompt;
use App\Services\AI\FilmOS\Prompting\IR\SubjectDescriptor;
use App\Services\AI\FilmOS\Prompting\IR\VisualStyle;

/**
 * The Cinematic Renderer for Kling — the ONLY place Kling wording exists.
 *
 * Its job is NOT "semantic -> English". It is to DIRECT: translate the IR into
 * the visual language a video model consumes — labelled storyboard blocks, a
 * subject the eye follows, camera as tags, emotion as facial/body behaviour,
 * energy as motion, continuity as an explicit instruction. Every phrase is a
 * MAPPING of data already in the IR (like TELEPHOTO -> "85mm"); it invents no
 * facts. Where a beat's own data runs out (exact spatial layout, rule-of-thirds
 * composition), that is a future planner's job, not fabrication here.
 *
 * Pass 3 (2026-07-15) — cinematic upgrades, all from existing IR:
 *   - labelled blocks (SUBJECTS / ENVIRONMENT / CONFLICT / beat labels / FINAL
 *     SHOT / VISUAL LANGUAGE / CAMERA / STYLE) — models parse blocks, not prose;
 *   - subject lock from SubjectDescriptor::isPrimary (what the eye follows);
 *   - emotion -> facial/body behaviour (FEAR -> "eyes wide, jaw tight");
 *   - energy -> motion language (100 -> "explosive motion");
 *   - filmable conflicts (PHYSICAL/ENVIRONMENTAL) surface; abstract ones drop;
 *   - performance cues filtered by channel (gross-motor only) and capped;
 *   - emotion kept only on close shots; camera continuity stated explicitly;
 *   - the beat's ending_frame and the hero moment become strong closing images.
 */
final class KlingPromptRenderer implements PromptRenderer
{
    /** Compact, tag-style camera vocabulary — Kling reads tags better than prose. */
    private const SHOT_TYPE = [
        'establishing'     => 'wide establishing shot',
        'wide'             => 'wide shot',
        'medium'           => 'medium shot',
        'close_up'         => 'close-up',
        'extreme_close_up' => 'extreme close-up',
        'two_shot'         => 'two-shot',
        'insert'           => 'insert shot',
    ];

    private const LENS = [
        'wide'      => '24mm',
        'normal'    => '35mm',
        'telephoto' => '85mm',
    ];

    private const ANGLE = [
        'eye_level'     => '',            // default framing — omit to save tokens
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

    /** Emotion rendered as observable facial/body behaviour — what a camera sees. */
    private const EMOTION_VISUAL = [
        'neutral'       => 'calm, even expression',
        'joy'           => 'open smile, bright eyes',
        'fear'          => 'eyes wide, jaw tight, shallow breath',
        'anger'         => 'brows drawn down, jaw clenched',
        'sadness'       => 'downcast eyes, heavy brow',
        'determination' => 'narrowed eyes, set jaw, forward lean',
        'surprise'      => 'eyes wide, brows raised, mouth parted',
    ];

    /** Anatomy constraint keyed by WorldObjectType — the yacht-lesson guard. */
    private const ANATOMY = [
        'character' => 'natural human anatomy, correct limb count, realistic hands',
        'animal'    => 'correct animal anatomy, natural coat, no human features',
        'vehicle'   => 'accurate mechanical detail, no human figures, no floating limbs',
    ];

    /** VisualStyle → the Kling look for that genre. One entry per enum case. */
    private const STYLE_LOOK = [
        'cinematic'          => 'Hyperrealistic cinematic footage, film grain, shallow depth of field, sharp focus.',
        'sports_documentary' => 'Hyperrealistic broadcast sports footage, long-lens documentary look, natural colour, motion-tracked, sharp focus.',
        'nature_documentary' => 'Hyperrealistic wildlife documentary footage, long-lens, natural colour, no grain, patient observational framing.',
        'luxury_commercial'  => 'Glossy high-end commercial footage, high contrast, specular highlights, pristine surfaces, controlled lighting.',
        'vintage_film'       => 'Vintage 35mm film footage, visible grain, halation, slightly faded colour.',
        'digital_clean'      => 'Clean modern digital footage, crisp detail, neutral colour, no grain.',
        'horror'             => 'Cold desaturated footage, deep shadows, low-key lighting, unsettling stillness.',
        'anime'              => 'Hand-drawn anime animation, cel shading, expressive linework.',
        'comic'              => 'Comic-book illustration style, bold ink outlines, flat graphic colour.',
    ];

    /** Shot types close enough for facial emotion to read on screen. */
    private const CLOSE_SHOTS = ['close_up', 'extreme_close_up'];

    /** Conflicts a camera can actually show; the rest are abstract and drop. */
    private const FILMABLE_CONFLICTS = [ConflictType::PHYSICAL, ConflictType::ENVIRONMENTAL];

    /**
     * Channels Kling cannot render legibly: micro-expression (eyes, face),
     * breath, and voice (the video is silent). Cues on these channels drop.
     */
    private const UNRENDERABLE_CHANNELS = [
        PerformanceChannel::GAZE,
        PerformanceChannel::FACE,
        PerformanceChannel::BREATH,
        PerformanceChannel::VOICE,
    ];

    public function provider(): ProviderId
    {
        return ProviderId::KLING;
    }

    public function render(StructuredPrompt $prompt): RenderedPrompt
    {
        $blocks = [
            $this->medium($prompt),
            $this->subjectsBlock($prompt),
            $this->environmentBlock($prompt),
            $this->keyVisualsBlock($prompt),
        ];
        $labels = $this->subjectLabelsById($prompt);
        foreach ($this->orderedShots($prompt) as $shot) {
            $blocks[] = $this->beatBlock($shot, $labels);
        }
        $blocks[] = $this->finalShotBlock($prompt);
        $blocks[] = $this->visualLanguageBlock($prompt);
        $blocks[] = $this->cameraBlock($prompt);
        $blocks[] = $this->styleBlock($prompt);

        $blocks = array_filter($blocks, static fn(string $b): bool => $b !== '');

        return new RenderedPrompt(
            positive: implode("\n\n", $blocks),
            negative: $this->negative($prompt),
            metadata: $this->metadata($prompt),
        );
    }

    // ── Blocks ────────────────────────────────────────────────────────────────

    /**
     * The look, from the authored VisualStyle — NOT hardcoded, because an NFL
     * play, a wildlife documentary and a car commercial must not come out as
     * the same footage. Each style maps to Kling's own wording here.
     */
    private function medium(StructuredPrompt $prompt): string
    {
        $style = $prompt->visualStyle() ?? VisualStyle::CINEMATIC;
        return self::STYLE_LOOK[$style->value];
    }

    /** Who the eye follows — PRIMARY from SubjectDescriptor::isPrimary, plus anatomy. */
    private function subjectsBlock(StructuredPrompt $prompt): string
    {
        // Three weights, so the model knows what the eye follows: a focused
        // subject, a supporting subject, and background.
        $primary = [];
        $secondary = [];
        $background = [];
        foreach ($prompt->subjects() as $s) {
            if ($s->isPrimary) {
                $primary[] = $this->subjectLabel($s);
            } elseif ($s->nodeType === SceneNodeType::BACKGROUND) {
                $background[] = $this->subjectLabel($s);
            } else {
                $secondary[] = $this->subjectLabel($s);
            }
        }

        $lines = [];
        if ($primary !== []) {
            $lines[] = 'Primary: ' . $this->join($primary) . '.';
        }
        if ($secondary !== []) {
            $lines[] = 'Secondary: ' . $this->join($secondary) . '.';
        }
        if ($background !== []) {
            $lines[] = 'Background: ' . $this->join($background) . '.';
        }
        if (($anatomy = $this->anatomy($prompt->subjects())) !== '') {
            $lines[] = $anatomy;
        }
        return $this->block('SUBJECTS', $lines);
    }

    private function subjectLabel(SubjectDescriptor $s): string
    {
        // Prefer the character's authored appearance (outfit, build) over bare
        // world-object attributes — richer, article-accurate identity.
        $detail = $s->appearance !== [] ? array_values($s->appearance) : array_values($s->attributes->all());
        return $detail === [] ? $s->label : $s->label . ' (' . implode(', ', $detail) . ')';
    }

    /**
     * The concrete things the frame must contain — one consolidated visual-facts
     * block. Article visuals (facts[].visual_hint, already ranked by relevance)
     * come first, then any filmable conflict (PHYSICAL/ENVIRONMENTAL) not already
     * stated; abstract conflicts (a clock, inner doubt, crowd noise) have no
     * image and drop. Merging conflicts here avoids a second, overlapping block.
     */
    private function keyVisualsBlock(StructuredPrompt $prompt): string
    {
        $lines = [];
        $seen  = [];

        foreach ($prompt->keyVisuals() as $visual) {
            $this->addVisual($visual->hint, $lines, $seen);
        }
        foreach ($prompt->conflicts() as $conflict) {
            if (in_array($conflict->type, self::FILMABLE_CONFLICTS, true)) {
                $this->addVisual($conflict->description, $lines, $seen);
            }
        }

        return $this->block('KEY VISUALS', $lines);
    }

    /**
     * @param string[]            $lines
     * @param array<string, true> $seen
     */
    private function addVisual(string $text, array &$lines, array &$seen): void
    {
        $key = strtolower(trim($text));
        if ($key === '' || isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $lines[]    = ucfirst(rtrim($text, '.')) . '.';
    }

    /** @param SubjectDescriptor[] $subjects */
    private function anatomy(array $subjects): string
    {
        $lines = [];
        foreach ($subjects as $s) {
            $guard = self::ANATOMY[$s->type->value] ?? null;
            if ($guard !== null) {
                $lines[$guard] = true;   // dedupe identical guards
            }
        }
        return $lines === [] ? '' : ucfirst(implode('. ', array_keys($lines))) . '.';
    }

    /**
     * Setting as a visual line. Uses the world-fact KEY to give each value a
     * concrete noun (crowd/light) — phrasing, adapter territory. Only visual
     * world state reaches here (world_facts is scoped upstream).
     */
    private function environmentBlock(StructuredPrompt $prompt): string
    {
        $details = [];
        foreach ($prompt->shots() as $shot) {
            foreach ($shot->environment->details as $key => $value) {
                $details[$this->envPhrase((string) $key, (string) $value)] = true;
            }
        }
        return $details === [] ? '' : $this->block('ENVIRONMENT', [ucfirst(implode(', ', array_keys($details))) . '.']);
    }

    private function envPhrase(string $key, string $value): string
    {
        return match ($key) {
            'crowd' => "{$value} crowd",
            'light' => "{$value} light",
            default => $value,
        };
    }

    /** @return ShotPrompt[] ordered by ordinal */
    private function orderedShots(StructuredPrompt $prompt): array
    {
        $shots = $prompt->shots();
        ksort($shots);
        return array_values($shots);
    }

    /**
     * @param array<string, string> $labels worldObjectId => plain label
     */
    private function beatBlock(ShotPrompt $shot, array $labels): string
    {
        $lines = [];
        if ($shot->camera !== null) {
            $lines[] = ucfirst($this->cameraPhrase($shot->camera)) . '.';
        }
        // Staging for THIS beat — who is actually in frame here. Being in the
        // cast is not being in every shot; this is what keeps a subject out of
        // a beat it must not appear in.
        $inFrame = [];
        foreach ($shot->visibleSubjectIds as $id) {
            if (isset($labels[$id])) {
                $inFrame[] = $labels[$id];
            }
        }
        if ($inFrame !== []) {
            $lines[] = 'In frame: ' . $this->join($inFrame) . '.';
        }
        // Attention for THIS beat — what the eye follows here, not globally.
        if ($shot->focusSubjectId !== null && isset($labels[$shot->focusSubjectId])) {
            $lines[] = 'Focus: ' . $labels[$shot->focusSubjectId] . '.';
        }
        $lines[] = rtrim($shot->action, '.') . '.';
        if ($this->isCloseShot($shot->camera)) {
            foreach ($shot->emotions as $emotion) {
                $lines[] = ucfirst($this->emotionVisual($emotion)) . '.';
            }
        }
        if (($cue = $this->leadCue($shot)) !== null) {
            $lines[] = ucfirst(rtrim($cue, '.')) . '.';
        }
        if (($motion = $this->motionWord($shot->energy)) !== '') {
            $lines[] = ucfirst($motion) . '.';
        }
        if ($shot->endingFrame !== null) {
            $lines[] = ucfirst(rtrim($shot->endingFrame->description, '.')) . '.';   // authored closing image
        }

        return $this->block($this->beatLabel($shot), $lines);
    }

    private function beatLabel(ShotPrompt $shot): string
    {
        return $shot->beat !== null ? strtoupper($shot->beat->value) : 'SHOT ' . ($shot->ordinal + 1);
    }

    /** The hero moment as a held, isolated climax — its own emphatic block. */
    private function finalShotBlock(StructuredPrompt $prompt): string
    {
        $hero = $prompt->heroMoment();
        if ($hero === null) {
            return '';
        }
        return $this->block('FINAL SHOT', [
            'Freeze the frame, everything goes still.',
            ucfirst(rtrim($hero->description, '.')) . '.',
        ]);
    }

    /** Motifs as directorial guidance, ranked PRIMARY vs secondary. */
    private function visualLanguageBlock(StructuredPrompt $prompt): string
    {
        $primary = [];
        $secondary = [];
        foreach ($prompt->motifs() as $motif) {
            if ($motif->importance === MotifImportance::PRIMARY) {
                $primary[] = $motif->label;
            } else {
                $secondary[] = $motif->label;
            }
        }
        $lines = [];
        if ($primary !== []) {
            $lines[] = 'Primary motif: ' . $this->join($primary) . '.';
        }
        if ($secondary !== []) {
            $lines[] = 'Secondary: ' . $this->join($secondary) . '.';
        }
        return $this->block('VISUAL LANGUAGE', $lines);
    }

    /**
     * Explicit continuity — Kling resets between beats unless told to hold the
     * shot. It names no subject: attention is per-beat (see each beat's Focus),
     * so a global "locked on X" would contradict the beat that focuses something else.
     */
    private function cameraBlock(StructuredPrompt $prompt): string
    {
        return $this->block('CAMERA', [
            'One continuous cinematic shot, never cutting. Hold each beat\'s focus subject in frame through the whole beat.',
        ]);
    }

    /** @return array<string, string> worldObjectId => plain label, for per-beat focus */
    private function subjectLabelsById(StructuredPrompt $prompt): array
    {
        $labels = [];
        foreach ($prompt->subjects() as $s) {
            $labels[$s->id] = $s->label;
        }
        return $labels;
    }

    /** Style baseline plus ALWAYS constraints, phrased as strong persistence. */
    private function styleBlock(StructuredPrompt $prompt): string
    {
        // "no text" must forbid OVERLAYS, not physical signage: a scoreboard or
        // a jersey number is part of the world and may legitimately be in frame.
        // Saying it precisely avoids contradicting the scene's own key visuals.
        $lines = ['Sharp focus. No overlaid text, no subtitles, no watermark, no captions.'];
        foreach ($prompt->constraints() as $c) {
            if ($c->mode === ConstraintMode::ALWAYS) {
                $lines[] = ucfirst("keep the {$c->target} {$c->rule} in every frame; never lose the {$c->target}") . '.';
            }
        }
        return $this->block('STYLE', $lines);
    }

    private function negative(StructuredPrompt $prompt): ?string
    {
        // "text overlay" not bare "text" — bare "text" also suppresses legitimate
        // in-world signage (scoreboard, jersey numbers) the scene explicitly wants.
        $terms = ['extra limbs', 'deformed hands', 'warping', 'text overlay', 'subtitles', 'watermark'];

        foreach ($prompt->constraints() as $c) {
            if ($c->mode === ConstraintMode::NEVER) {
                $terms[] = trim("{$c->target} {$c->rule}");
            }
        }

        return $terms === [] ? null : implode(', ', $terms);
    }

    /** @return array<string, mixed> */
    private function metadata(StructuredPrompt $prompt): array
    {
        $duration = 0.0;
        $energyPeak = null;
        foreach ($prompt->shots() as $shot) {
            $duration += $shot->durationSeconds ?? 0.0;
            if ($shot->energy !== null) {
                $energyPeak = max($energyPeak ?? 0, $shot->energy);
            }
        }

        $meta = ['provider' => ProviderId::KLING->value];
        if ($duration > 0.0) {
            $meta['duration_seconds'] = $duration;
        }
        if ($energyPeak !== null) {
            $meta['energy_peak'] = $energyPeak;
        }
        return $meta;
    }

    // ── Phrase helpers ────────────────────────────────────────────────────────

    /** A labelled storyboard block: "LABEL\nline.\nline." — empty if no lines. */
    private function block(string $label, array $lines): string
    {
        $lines = array_values(array_filter($lines, static fn(string $l): bool => $l !== ''));
        return $lines === [] ? '' : $label . "\n" . implode("\n", $lines);
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

    private function isCloseShot(?CameraConfiguration $cam): bool
    {
        return $cam !== null && in_array($cam->shotType->value, self::CLOSE_SHOTS, true);
    }

    private function emotionVisual(CharacterEmotion $emotion): string
    {
        return self::EMOTION_VISUAL[$emotion->state->value] ?? $emotion->state->value;
    }

    /** Energy (0–100) → motion intensity language. Copied signal, not invented. */
    private function motionWord(?int $energy): string
    {
        if ($energy === null) {
            return '';
        }
        return match (true) {
            $energy >= 85 => 'explosive motion',
            $energy >= 55 => 'urgent motion',
            $energy >= 25 => 'tense, controlled motion',
            default       => 'still, held motion',
        };
    }

    /**
     * The single, most legible performance cue for a beat — the first cue on a
     * gross-motor channel (HANDS/POSTURE) or no channel. Micro-expression, breath,
     * and voice cues are skipped because Kling cannot render them.
     */
    private function leadCue(ShotPrompt $shot): ?string
    {
        foreach ($shot->performances as $performance) {
            foreach ($performance->cues as $cue) {
                if ($this->isRenderable($cue)) {
                    return $cue->description;
                }
            }
        }
        return null;
    }

    private function isRenderable(PerformanceCue $cue): bool
    {
        return !in_array($cue->channel, self::UNRENDERABLE_CHANNELS, true);
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
