<?php

namespace Tests\Unit\AFOS\Passes\Prompt;

use App\Services\AI\AFOS\Passes\Prompt\StaticPhraseCatalog;
use App\Services\AI\AFOS\Types\Emotion;
use PHPUnit\Framework\TestCase;

final class StaticPhraseCatalogTest extends TestCase
{
    private StaticPhraseCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new StaticPhraseCatalog();
    }

    // ── cinematicPhrase ───────────────────────────────────────────────────────

    public function test_known_entity_returns_mapped_phrase(): void
    {
        $this->assertSame(
            'villa pool and its mirror-perfect reflection',
            $this->catalog->cinematicPhrase('pool_reflection'),
        );
    }

    public function test_entity_lookup_is_case_insensitive(): void
    {
        $this->assertSame(
            $this->catalog->cinematicPhrase('pool_reflection'),
            $this->catalog->cinematicPhrase('POOL_REFLECTION'),
        );
    }

    public function test_unknown_entity_falls_back_to_humanized_id(): void
    {
        $this->assertSame('some unknown entity', $this->catalog->cinematicPhrase('some_unknown_entity'));
    }

    public function test_empty_entity_ref_returns_subject(): void
    {
        $this->assertSame('subject', $this->catalog->cinematicPhrase(''));
        $this->assertSame('subject', $this->catalog->cinematicPhrase('   '));
    }

    public function test_entity_lookup_normalizes_spaces_and_hyphens(): void
    {
        // "POOL Reflection" and "pool-reflection" both resolve to "pool_reflection"
        $expected = $this->catalog->cinematicPhrase('pool_reflection');
        $this->assertSame($expected, $this->catalog->cinematicPhrase('POOL Reflection'));
        $this->assertSame($expected, $this->catalog->cinematicPhrase('pool-reflection'));
        $this->assertSame($expected, $this->catalog->cinematicPhrase('Pool  Reflection'));
    }

    public function test_never_returns_empty_string(): void
    {
        foreach (['pool_reflection', 'beach', 'unknown_xyz', '', '   '] as $ref) {
            $this->assertNotSame('', $this->catalog->cinematicPhrase($ref));
        }
    }

    // ── atmosphereVariant — determinism ───────────────────────────────────────

    public function test_same_shot_id_always_returns_same_variant(): void
    {
        $shotId = 'shot-abc-123';

        $first  = $this->catalog->atmosphereVariant(Emotion::SERENITY, $shotId);
        $second = $this->catalog->atmosphereVariant(Emotion::SERENITY, $shotId);

        $this->assertSame($first, $second);
    }

    public function test_different_shot_ids_can_return_different_variants(): void
    {
        // Generate enough shot IDs that at least two different variants appear.
        $variants = [];
        for ($i = 0; $i < 100; $i++) {
            $variants[] = $this->catalog->atmosphereVariant(Emotion::LUXURY, "shot-{$i}");
        }

        // All 3 variants should be reachable across 100 IDs.
        $unique = array_unique($variants);
        $this->assertGreaterThan(1, count($unique), 'Expected multiple variants across 100 shot IDs');
    }

    public function test_all_emotions_return_non_empty_variant(): void
    {
        $shotId = 'test-shot-001';

        foreach (Emotion::cases() as $emotion) {
            $variant = $this->catalog->atmosphereVariant($emotion, $shotId);
            $this->assertNotEmpty($variant, "Empty variant for emotion {$emotion->value}");
        }
    }

    public function test_variants_are_genuinely_different_prose(): void
    {
        // Collect all 3 variants for SERENITY by finding 3 different indexes.
        $seen = [];
        for ($i = 0; $i < 1000 && count($seen) < 3; $i++) {
            $v = $this->catalog->atmosphereVariant(Emotion::SERENITY, "probe-{$i}");
            $seen[$v] = true;
        }

        // All 3 slots must be reachable and distinct.
        $this->assertCount(3, $seen, 'Expected exactly 3 distinct serenity variants within 1000 probes');
    }
}
