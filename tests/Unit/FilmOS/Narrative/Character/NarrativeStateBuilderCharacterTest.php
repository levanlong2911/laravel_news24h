<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Narrative\Character;

use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion;
use App\Services\AI\FilmOS\Narrative\Character\CharacterProfile;
use App\Services\AI\FilmOS\Narrative\Character\EmotionalState;
use App\Services\AI\FilmOS\Narrative\Character\EmotionIntensity;
use App\Services\AI\FilmOS\Narrative\Shared\AttributeBag;
use App\Services\AI\FilmOS\Narrative\Timeline\NarrativeState;
use App\Services\AI\FilmOS\Narrative\Timeline\NarrativeStateBuilder;
use App\Services\AI\FilmOS\Narrative\Timeline\ProjectionMetadata;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineOrdinal;
use PHPUnit\Framework\TestCase;

final class NarrativeStateBuilderCharacterTest extends TestCase
{
    // ── introduceCharacter ────────────────────────────────────────────────────

    public function test_introduce_character_creates_memory(): void
    {
        $builder = new NarrativeStateBuilder();
        $hero    = $this->profile('hero');

        $builder->introduceCharacter($hero, TimelineOrdinal::BASELINE);
        $state = $this->build($builder);

        $this->assertTrue($state->characters->hasCharacter('hero'));
        $this->assertSame($hero, $state->characters->memoryOf('hero')?->profile);
        $this->assertSame(TimelineOrdinal::BASELINE, $state->characters->memoryOf('hero')?->introducedAt);
    }

    public function test_duplicate_introduction_is_last_write_wins(): void
    {
        $builder = new NarrativeStateBuilder();
        $v1      = $this->profile('hero', ['outfit' => 'black suit']);
        $v2      = $this->profile('hero', ['outfit' => 'white suit']);

        $builder->introduceCharacter($v1, TimelineOrdinal::BASELINE);
        $builder->introduceCharacter($v2, 2);
        $state = $this->build($builder);

        // Projection tolerates the anomaly (QA flags it later) — last write wins
        $this->assertSame($v2, $state->characters->memoryOf('hero')?->profile);
        $this->assertSame(2, $state->characters->memoryOf('hero')?->introducedAt);
        $this->assertCount(1, $state->characters->allCharacters());
    }

    // ── recordEmotion ─────────────────────────────────────────────────────────

    public function test_record_emotion_appears_in_memory_timeline(): void
    {
        $builder = new NarrativeStateBuilder();
        $fear    = $this->emotion(EmotionalState::FEAR, EmotionIntensity::MODERATE);

        $builder->introduceCharacter($this->profile('hero'), TimelineOrdinal::BASELINE);
        $builder->recordEmotion('hero', 1, $fear);
        $state = $this->build($builder);

        $this->assertSame($fear, $state->characters->emotionAt('hero', 1));
    }

    public function test_record_emotion_same_ordinal_last_write_wins(): void
    {
        $builder = new NarrativeStateBuilder();
        $v1      = $this->emotion(EmotionalState::FEAR, EmotionIntensity::SUBTLE);
        $v2      = $this->emotion(EmotionalState::FEAR, EmotionIntensity::INTENSE);

        $builder->introduceCharacter($this->profile('hero'), TimelineOrdinal::BASELINE);
        $builder->recordEmotion('hero', 1, $v1);
        $builder->recordEmotion('hero', 1, $v2);
        $state = $this->build($builder);

        $this->assertSame($v2, $state->characters->emotionAt('hero', 1));
        $this->assertCount(1, $state->characters->memoryOf('hero')?->emotionTimeline ?? []);
    }

    public function test_orphan_emotion_for_unknown_character_is_dropped(): void
    {
        $builder = new NarrativeStateBuilder();

        // Emotion recorded for a character that was never introduced
        $builder->recordEmotion('ghost', 1, $this->emotion(EmotionalState::FEAR, EmotionIntensity::INTENSE));
        $state = $this->build($builder);

        $this->assertFalse($state->characters->hasCharacter('ghost'));
        $this->assertNull($state->characters->emotionAt('ghost', 1));
        $this->assertEmpty($state->characters->allCharacters());
    }

    // ── D2 does not bleed into other domains ─────────────────────────────────

    public function test_character_operations_do_not_affect_world_or_scene(): void
    {
        $builder = new NarrativeStateBuilder();
        $builder->introduceCharacter($this->profile('hero'), TimelineOrdinal::BASELINE);
        $state = $this->build($builder);

        $this->assertEmpty($state->world->allObjects(), 'characters must not leak into world domain');
        $this->assertEmpty($state->scene->allNodes(),   'characters must not leak into scene domain');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function profile(string $id, array $appearance = []): CharacterProfile
    {
        return new CharacterProfile(
            id:         $id,
            label:      ucfirst($id),
            appearance: AttributeBag::from($appearance),
        );
    }

    private function emotion(EmotionalState $state, EmotionIntensity $intensity): CharacterEmotion
    {
        return new CharacterEmotion(state: $state, intensity: $intensity);
    }

    private function build(NarrativeStateBuilder $builder): NarrativeState
    {
        return $builder->build(
            NarrativeState::SCHEMA_VERSION,
            new ProjectionMetadata(projectionTimeMs: 0, eventCount: 0, lastOrdinal: -1, generatedAt: time()),
        );
    }
}
