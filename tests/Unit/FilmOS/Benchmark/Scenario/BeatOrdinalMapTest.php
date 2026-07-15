<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Benchmark\Scenario;

use App\Services\AI\FilmOS\Narrative\Story\StoryBeat;
use App\Services\AI\FilmOS\Planning\BeatOrdinalMap;
use PHPUnit\Framework\TestCase;

final class BeatOrdinalMapTest extends TestCase
{
    public function test_full_arc_gets_sequential_ordinals(): void
    {
        $map = BeatOrdinalMap::fromBeats([
            StoryBeat::HOOK, StoryBeat::ESCALATION, StoryBeat::REVEAL, StoryBeat::PAYOFF,
        ]);

        $this->assertSame(0, $map->ordinalOf(StoryBeat::HOOK));
        $this->assertSame(1, $map->ordinalOf(StoryBeat::ESCALATION));
        $this->assertSame(2, $map->ordinalOf(StoryBeat::REVEAL));
        $this->assertSame(3, $map->ordinalOf(StoryBeat::PAYOFF));
    }

    public function test_present_beats_pack_compactly_in_cinematic_order(): void
    {
        // Only hook + payoff present → compact 0,1 (NOT 0,3), still cinematic order.
        $map = BeatOrdinalMap::fromBeats([StoryBeat::PAYOFF, StoryBeat::HOOK]);

        $this->assertSame(0, $map->ordinalOf(StoryBeat::HOOK));
        $this->assertSame(1, $map->ordinalOf(StoryBeat::PAYOFF));
        $this->assertSame([StoryBeat::HOOK, StoryBeat::PAYOFF], $map->orderedBeats());
    }

    public function test_input_order_does_not_affect_result(): void
    {
        $a = BeatOrdinalMap::fromBeats([StoryBeat::PAYOFF, StoryBeat::ESCALATION, StoryBeat::HOOK]);

        $this->assertSame(['hook' => 0, 'escalation' => 1, 'payoff' => 2], $a->all());
    }

    public function test_has_and_absent_lookup(): void
    {
        $map = BeatOrdinalMap::fromBeats([StoryBeat::HOOK]);

        $this->assertTrue($map->has(StoryBeat::HOOK));
        $this->assertFalse($map->has(StoryBeat::REVEAL));
        $this->expectException(\OutOfBoundsException::class);
        $map->ordinalOf(StoryBeat::REVEAL);
    }

    public function test_empty(): void
    {
        $map = BeatOrdinalMap::fromBeats([]);

        $this->assertSame([], $map->orderedBeats());
        $this->assertSame([], $map->all());
    }
}
