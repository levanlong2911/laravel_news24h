<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Narrative\Character;

use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion;
use App\Services\AI\FilmOS\Narrative\Character\CharacterMemory;
use App\Services\AI\FilmOS\Narrative\Character\CharacterProfile;
use App\Services\AI\FilmOS\Narrative\Character\EmotionalState;
use App\Services\AI\FilmOS\Narrative\Character\EmotionIntensity;
use App\Services\AI\FilmOS\Narrative\Shared\AttributeBag;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineOrdinal;
use PHPUnit\Framework\TestCase;

final class CharacterMemoryTest extends TestCase
{
    // ── Core invariant: emotionAt() = last known state at or before ordinal ──

    public function test_emotion_at_returns_exact_ordinal_match(): void
    {
        $fear   = $this->emotion(EmotionalState::FEAR, EmotionIntensity::MODERATE);
        $memory = $this->memory(emotionTimeline: [1 => $fear]);

        $this->assertSame($fear, $memory->emotionAt(1));
    }

    public function test_emotion_persists_across_shots_without_events(): void
    {
        // Shot 1: FEAR. Shots 2, 3: no events. Shot 4: JOY.
        $fear = $this->emotion(EmotionalState::FEAR, EmotionIntensity::MODERATE);
        $joy  = $this->emotion(EmotionalState::JOY,  EmotionIntensity::SUBTLE);

        $memory = $this->memory(emotionTimeline: [1 => $fear, 4 => $joy]);

        // Persistence semantics: hero is still frightened at shots 2 and 3
        $this->assertSame($fear, $memory->emotionAt(2), 'emotion must persist at shot 2');
        $this->assertSame($fear, $memory->emotionAt(3), 'emotion must persist at shot 3');
        $this->assertSame($joy,  $memory->emotionAt(4));
        $this->assertSame($joy,  $memory->emotionAt(99), 'latest emotion persists indefinitely');
    }

    public function test_emotion_at_returns_null_before_first_emotion(): void
    {
        $fear   = $this->emotion(EmotionalState::FEAR, EmotionIntensity::INTENSE);
        $memory = $this->memory(emotionTimeline: [2 => $fear]);

        $this->assertNull($memory->emotionAt(0), 'no emotion known before first recorded entry');
        $this->assertNull($memory->emotionAt(1));
    }

    public function test_emotion_at_with_empty_timeline_returns_null(): void
    {
        $memory = $this->memory();

        $this->assertNull($memory->emotionAt(0));
        $this->assertNull($memory->emotionAt(100));
    }

    public function test_emotion_at_supports_baseline_ordinal(): void
    {
        // Emotion set at BASELINE (-1) — character enters the story already sad
        $sad    = $this->emotion(EmotionalState::SADNESS, EmotionIntensity::MODERATE);
        $memory = $this->memory(emotionTimeline: [TimelineOrdinal::BASELINE => $sad]);

        $this->assertSame($sad, $memory->emotionAt(TimelineOrdinal::BASELINE));
        $this->assertSame($sad, $memory->emotionAt(0), 'baseline emotion persists into shot 0');
    }

    // ── latestEmotion convenience API ─────────────────────────────────────────

    public function test_latest_emotion_returns_highest_ordinal_entry(): void
    {
        $fear = $this->emotion(EmotionalState::FEAR, EmotionIntensity::MODERATE);
        $joy  = $this->emotion(EmotionalState::JOY,  EmotionIntensity::INTENSE);

        $memory = $this->memory(emotionTimeline: [1 => $fear, 4 => $joy]);

        $this->assertSame($joy, $memory->latestEmotion());
    }

    public function test_latest_emotion_with_empty_timeline_returns_null(): void
    {
        $this->assertNull($this->memory()->latestEmotion());
    }

    public function test_latest_emotion_ignores_insertion_order(): void
    {
        // Timeline keys inserted out of order — max ordinal must still win
        $fear = $this->emotion(EmotionalState::FEAR, EmotionIntensity::MODERATE);
        $joy  = $this->emotion(EmotionalState::JOY,  EmotionIntensity::SUBTLE);

        $memory = $this->memory(emotionTimeline: [5 => $joy, 2 => $fear]);

        $this->assertSame($joy, $memory->latestEmotion());
    }

    // ── CharacterEmotion cause extension point ────────────────────────────────

    public function test_emotion_cause_is_preserved(): void
    {
        $emotion = new CharacterEmotion(
            state:     EmotionalState::FEAR,
            intensity: EmotionIntensity::INTENSE,
            cause:     'gunshot',
        );

        $this->assertSame('gunshot', $emotion->cause);
    }

    public function test_emotion_cause_defaults_to_null(): void
    {
        $emotion = $this->emotion(EmotionalState::NEUTRAL, EmotionIntensity::SUBTLE);

        $this->assertNull($emotion->cause);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function memory(array $emotionTimeline = []): CharacterMemory
    {
        return new CharacterMemory(
            profile: new CharacterProfile(
                id:         'hero',
                label:      'Hero',
                appearance: AttributeBag::empty(),
            ),
            introducedAt:    TimelineOrdinal::BASELINE,
            emotionTimeline: $emotionTimeline,
        );
    }

    private function emotion(EmotionalState $state, EmotionIntensity $intensity): CharacterEmotion
    {
        return new CharacterEmotion(state: $state, intensity: $intensity);
    }
}
