<?php

namespace Tests\Unit\Pipeline;

use App\Services\AI\ProviderResolver;
use PHPUnit\Framework\TestCase;

/**
 * Resolver Matrix Tests — 20 cases covering the full decision surface.
 *
 * Columns: motion_level × realism × has_human → expected provider
 *
 * Resolution priority (from ProviderResolver):
 *   1. motion=none       → kenburns
 *   2. motion=high       → kling
 *   3. has_human=true    → kling
 *   4. realism=photoreal → flux
 *   5. realism=high      → flux
 *   6. fallback          → kenburns
 */
class ProviderResolverTest extends TestCase
{
    /** @dataProvider resolutionMatrix */
    public function test_resolver_returns_correct_provider(
        string $motionLevel,
        string $realism,
        bool   $hasHuman,
        string $expected,
    ): void {
        $actual = ProviderResolver::resolve($motionLevel, $realism, $hasHuman);
        $this->assertSame(
            $expected,
            $actual,
            "motion={$motionLevel} realism={$realism} human=" . ($hasHuman ? 'true' : 'false')
            . " → expected={$expected} got={$actual}",
        );
    }

    public static function resolutionMatrix(): array
    {
        return [
            // Rule 1: motion=none → always kenburns regardless of other dims
            'none/low/false'      => ['none', 'low',       false, 'kenburns'],
            'none/high/false'     => ['none', 'high',      false, 'kenburns'],
            'none/photoreal/false'=> ['none', 'photoreal', false, 'kenburns'],
            'none/high/true'      => ['none', 'high',      true,  'kenburns'],

            // Rule 2: motion=high → kling regardless of realism or human
            'high/low/false'      => ['high', 'low',       false, 'kling'],
            'high/medium/false'   => ['high', 'medium',    false, 'kling'],
            'high/high/false'     => ['high', 'high',      false, 'kling'],
            'high/photoreal/false'=> ['high', 'photoreal', false, 'kling'],
            'high/high/true'      => ['high', 'high',      true,  'kling'],

            // Rule 3: has_human=true (non-zero motion) → kling
            'low/low/true'        => ['low',    'low',    true, 'kling'],
            'low/high/true'       => ['low',    'high',   true, 'kling'],
            'medium/low/true'     => ['medium', 'low',    true, 'kling'],
            'medium/high/true'    => ['medium', 'high',   true, 'kling'],

            // Rule 4: realism=photoreal, no human, non-high motion → flux
            'low/photoreal/false'   => ['low',    'photoreal', false, 'flux'],
            'medium/photoreal/false'=> ['medium', 'photoreal', false, 'flux'],

            // Rule 5: realism=high, no human, non-high motion → flux
            'low/high/false'        => ['low',    'high', false, 'flux'],
            'medium/high/false'     => ['medium', 'high', false, 'flux'],

            // Rule 6: fallback → kenburns (low/medium motion, low/medium realism, no human)
            'low/low/false'         => ['low',    'low',    false, 'kenburns'],
            'low/medium/false'      => ['low',    'medium', false, 'kenburns'],
            'medium/medium/false'   => ['medium', 'medium', false, 'kenburns'],
        ];
    }

    public function test_resolve_from_dsl_convenience_overload(): void
    {
        $dsl = ['motion_level' => 'high', 'realism' => 'photoreal', 'has_human' => false];
        // Rule 2 (motion=high) wins over Rule 4 (photoreal)
        $this->assertSame('kling', ProviderResolver::resolveFromDsl($dsl));
    }

    public function test_resolve_from_dsl_defaults_when_keys_missing(): void
    {
        // Missing keys → defaults: motion_level=none, realism=medium, has_human=false
        $this->assertSame('kenburns', ProviderResolver::resolveFromDsl([]));
    }
}
