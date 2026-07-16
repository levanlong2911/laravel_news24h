<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Benchmark\Scenario;

use App\Services\AI\FilmOS\Benchmark\Scenario\FactVisuals;
use App\Services\AI\FilmOS\Prompting\IR\VisualRelevance;
use PHPUnit\Framework\TestCase;

/**
 * FactVisuals is the wire carrying article visual truth (facts[].visual_hint +
 * visual_relevance) into the neutral KeyVisual[] the compiler consumes.
 */
final class FactVisualsTest extends TestCase
{
    public function test_maps_only_facts_that_carry_a_visual_hint(): void
    {
        $visuals = FactVisuals::fromFacts([
            ['id' => 'F1', 'visual_hint' => 'two defenders converging', 'visual_relevance' => 'HIGH'],
            ['id' => 'F2', 'text' => 'team trails by four points'],        // no visual_hint → skipped
            ['id' => 'F3', 'visual_hint' => 'lone figure downfield', 'visual_relevance' => 'MEDIUM'],
        ]);

        $this->assertCount(2, $visuals);
        $this->assertSame('two defenders converging', $visuals[0]->hint);
        $this->assertSame(VisualRelevance::HIGH, $visuals[0]->relevance);
        $this->assertSame(VisualRelevance::MEDIUM, $visuals[1]->relevance);
    }

    public function test_relevance_defaults_to_medium_when_missing_or_invalid(): void
    {
        $visuals = FactVisuals::fromFacts([
            ['visual_hint' => 'x', 'visual_relevance' => 'NONSENSE'],
            ['visual_hint' => 'y'],
        ]);

        $this->assertSame(VisualRelevance::MEDIUM, $visuals[0]->relevance);
        $this->assertSame(VisualRelevance::MEDIUM, $visuals[1]->relevance);
    }

    public function test_empty_facts_produce_no_visuals(): void
    {
        $this->assertSame([], FactVisuals::fromFacts([]));
    }
}
