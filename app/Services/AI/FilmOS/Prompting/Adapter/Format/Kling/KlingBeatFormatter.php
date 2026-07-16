<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter\Format\Kling;

use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion;
use App\Services\AI\FilmOS\Prompting\Adapter\Format\SlotFormatter;
use App\Services\AI\FilmOS\Prompting\Plan\PlanSlot;

/**
 * What happens inside a beat: the action, the acting, the energy, the last image.
 *
 * Emotion is said as behaviour, never as a label — a camera can photograph a
 * tight jaw, it cannot photograph "fear". The planner already decided the shot
 * is close enough for a face to read; this only chooses the words.
 */
final class KlingBeatFormatter implements SlotFormatter
{
    use JoinsPhrases;

    /** EmotionalState → what the camera actually sees. */
    private const EMOTION_VISUAL = [
        'neutral'       => 'calm, even expression',
        'joy'           => 'open smile, bright eyes',
        'fear'          => 'eyes wide, jaw tight, shallow breath',
        'anger'         => 'brows drawn down, jaw clenched',
        'sadness'       => 'downcast eyes, heavy brow',
        'determination' => 'narrowed eyes, set jaw, forward lean',
        'surprise'      => 'eyes wide, brows raised, mouth parted',
    ];

    public function slots(): array
    {
        return [
            PlanSlot::ACTION,
            PlanSlot::EMOTION,
            PlanSlot::PERFORMANCE_CUE,
            PlanSlot::MOTION,
            PlanSlot::ENDING_FRAME,
        ];
    }

    public function format(PlanSlot $slot, mixed $payload): string
    {
        return match ($slot) {
            // Authored scenario prose, passed through — not this class's to rewrite.
            PlanSlot::ACTION          => $this->sentence($payload),
            PlanSlot::EMOTION         => $this->emotion($payload),
            PlanSlot::PERFORMANCE_CUE => $this->sentence($payload->description),
            PlanSlot::MOTION          => $this->sentence($this->motionWord($payload)),
            PlanSlot::ENDING_FRAME    => $this->sentence($payload->description),
            default                   => '',
        };
    }

    private function emotion(CharacterEmotion $emotion): string
    {
        return $this->sentence(self::EMOTION_VISUAL[$emotion->state->value] ?? $emotion->state->value);
    }

    /** Energy curve → motion language. The plan gives a number; Kling gets a word. */
    private function motionWord(int $energy): string
    {
        return match (true) {
            $energy >= 85 => 'explosive motion',
            $energy >= 55 => 'urgent motion',
            $energy >= 25 => 'tense, controlled motion',
            default       => 'still, held motion',
        };
    }
}
