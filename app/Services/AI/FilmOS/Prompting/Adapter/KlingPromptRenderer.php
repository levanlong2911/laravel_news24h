<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter;

use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion;
use App\Services\AI\FilmOS\Narrative\Production\ConstraintMode;
use App\Services\AI\FilmOS\Narrative\Production\MotifImportance;
use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguration;
use App\Services\AI\FilmOS\Prompting\IR\ShotPrompt;
use App\Services\AI\FilmOS\Prompting\IR\StructuredPrompt;
use App\Services\AI\FilmOS\Prompting\IR\SubjectDescriptor;

/**
 * Renders a StructuredPrompt into a Kling prompt (positive + negative + knobs).
 *
 * This is the ONLY place Kling wording exists. Every enum → phrase mapping,
 * the anatomy guard from SubjectDescriptor::$type, the beat-by-beat structure,
 * and NEVER/ALWAYS constraint phrasing live here — the IR stays semantic.
 */
final class KlingPromptRenderer implements PromptRenderer
{
    private const SHOT_TYPE = [
        'establishing'     => 'wide establishing shot',
        'wide'             => 'wide shot',
        'medium'           => 'medium shot',
        'close_up'         => 'close-up',
        'extreme_close_up' => 'extreme close-up',
        'two_shot'         => 'two-shot',
        'insert'           => 'insert shot',
    ];

    private const ANGLE = [
        'eye_level'     => 'at eye level',
        'high'          => 'from a high angle',
        'low'           => 'from a low angle',
        'dutch'         => 'with a dutch tilt',
        'birds_eye'     => "from a bird's-eye view",
        'worms_eye'     => "from a worm's-eye view",
        'over_shoulder' => 'over the shoulder',
    ];

    private const MOVEMENT = [
        'static'   => 'locked-off camera',
        'pan'      => 'panning',
        'tilt'     => 'tilting to follow the action',
        'tracking' => 'tracking the subject',
        'dolly'    => 'a smooth dolly move',
        'zoom'     => 'zooming',
        'handheld' => 'urgent handheld motion',
    ];

    private const LENS = [
        'wide'      => '24mm wide lens',
        'normal'    => '35mm natural lens',
        'telephoto' => '85mm telephoto compression',
    ];

    private const EMOTION = [
        'neutral'       => 'neutral',
        'joy'           => 'joyful',
        'fear'          => 'fearful',
        'anger'         => 'angry',
        'sadness'       => 'sorrowful',
        'determination' => 'determined',
        'surprise'      => 'startled',
    ];

    private const INTENSITY = [
        'subtle'   => 'faintly',
        'moderate' => '',
        'intense'  => 'intensely',
    ];

    /** Anatomy constraint keyed by WorldObjectType — the yacht-lesson guard. */
    private const ANATOMY = [
        'character' => 'natural human anatomy, correct limb count, realistic hands',
        'animal'    => 'correct animal anatomy, natural coat, no human features',
        'vehicle'   => 'accurate mechanical detail, no human figures, no floating limbs',
    ];

    public function provider(): ProviderId
    {
        return ProviderId::KLING;
    }

    public function render(StructuredPrompt $prompt): RenderedPrompt
    {
        $sections = array_filter([
            $this->anatomy($prompt->subjects()),
            $this->subjectsLine($prompt->subjects()),
            $this->environment($prompt),
            $this->beats($prompt),
            $this->mustShow($prompt),
            $this->style($prompt),
        ], static fn(string $s): bool => $s !== '');

        return new RenderedPrompt(
            positive: implode("\n\n", $sections),
            negative: $this->negative($prompt),
            metadata: $this->metadata($prompt),
        );
    }

    // ── Sections ──────────────────────────────────────────────────────────────

    /** @param SubjectDescriptor[] $subjects */
    private function anatomy(array $subjects): string
    {
        $lines = [];
        foreach ($subjects as $s) {
            $guard = self::ANATOMY[$s->type->value] ?? null;
            if ($guard !== null) {
                $lines[$guard] = true;   // dedupe identical guards (two vehicles → one line)
            }
        }
        return $lines === [] ? '' : 'Hyperrealistic. ' . ucfirst(implode('. ', array_keys($lines))) . '.';
    }

    /**
     * Environment rendered ONCE (world facts are baseline/global, not per-beat).
     * NOTE: emits every world-fact value — including narrative ones (score,
     * quarter). Filtering visual vs narrative facts is an open design decision.
     */
    private function environment(StructuredPrompt $prompt): string
    {
        $details = [];
        foreach ($prompt->shots() as $shot) {
            foreach ($shot->environment->details as $value) {
                $details[$value] = true;   // dedupe across beats
            }
        }
        return $details === [] ? '' : 'ENVIRONMENT: ' . implode(', ', array_keys($details)) . '.';
    }

    /** @param SubjectDescriptor[] $subjects */
    private function subjectsLine(array $subjects): string
    {
        if ($subjects === []) {
            return '';
        }
        $parts = array_map(
            static fn(SubjectDescriptor $s): string => $s->label,
            $subjects,
        );
        return 'Featuring ' . $this->join($parts) . '.';
    }

    private function beats(StructuredPrompt $prompt): string
    {
        $shots = $prompt->shots();
        ksort($shots);   // ordinal order

        $segments = [];
        foreach ($shots as $shot) {
            $segments[] = $this->beatSegment($shot);
        }
        return implode(' ', $segments);
    }

    private function beatSegment(ShotPrompt $shot): string
    {
        $clauses = [rtrim($shot->action, '.')];

        if ($shot->camera !== null) {
            $clauses[] = $this->cameraPhrase($shot->camera);
        }
        foreach ($shot->emotions as $emotion) {
            $clauses[] = $this->emotionPhrase($emotion);
        }
        foreach ($shot->performances as $performance) {
            $cues = array_map(static fn($c) => $c->description, $performance->cues);
            if ($cues !== []) {
                $clauses[] = $this->join($cues);
            }
        }

        return implode(', ', array_filter($clauses)) . '.';
    }

    private function style(StructuredPrompt $prompt): string
    {
        $bits = ['cinematic, sharp focus, no text overlays'];

        // Motifs — PRIMARY repeated more emphatically.
        foreach ($prompt->motifs() as $motif) {
            $bits[] = $motif->importance === MotifImportance::PRIMARY
                ? "recurring {$motif->label} motif throughout"
                : $motif->label;
        }

        if (($hero = $prompt->heroMoment()) !== null) {
            $bits[] = "hero frame: {$hero->description}";
        }

        return 'STYLE: ' . implode('; ', $bits) . '.';
    }

    /** ALWAYS constraints reinforce the positive prompt (NEVER go to the negative). */
    private function mustShow(StructuredPrompt $prompt): string
    {
        $rules = [];
        foreach ($prompt->constraints() as $c) {
            if ($c->mode === ConstraintMode::ALWAYS) {
                $rules[] = "keep the {$c->target} {$c->rule}";
            }
        }
        return $rules === [] ? '' : 'ALWAYS: ' . implode('; ', $rules) . '.';
    }

    private function negative(StructuredPrompt $prompt): ?string
    {
        $terms = ['extra limbs', 'deformed hands', 'warping', 'text', 'watermark'];

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

    private function cameraPhrase(CameraConfiguration $cam): string
    {
        return implode(' ', array_filter([
            self::SHOT_TYPE[$cam->shotType->value] ?? $cam->shotType->value,
            self::ANGLE[$cam->angle->value]        ?? '',
            'with ' . (self::MOVEMENT[$cam->movement->value] ?? $cam->movement->value),
            '(' . (self::LENS[$cam->lens->value] ?? $cam->lens->value) . ')',
        ]));
    }

    private function emotionPhrase(CharacterEmotion $emotion): string
    {
        $state     = self::EMOTION[$emotion->state->value] ?? $emotion->state->value;
        $modifier  = self::INTENSITY[$emotion->intensity->value] ?? '';
        $adjective = trim("{$modifier} {$state}");
        $because   = $emotion->cause !== null ? " because of {$emotion->cause}" : '';
        return "{$adjective} expression{$because}";
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
