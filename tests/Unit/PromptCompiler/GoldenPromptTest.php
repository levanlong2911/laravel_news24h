<?php

namespace Tests\Unit\PromptCompiler;

use App\Services\AI\PromptCompiler\Compiler;
use App\Services\AI\ProviderResolver;
use PHPUnit\Framework\TestCase;

/**
 * Snapshot tests: golden fixture JSON → expected prompt string.
 *
 * To update a golden file after an intentional compiler change:
 *   1. Run the test to see the new actual output
 *   2. Verify it is correct
 *   3. Replace the .txt file content with the new output
 *
 * Golden files live in tests/Golden/{provider}/
 */
class GoldenPromptTest extends TestCase
{
    private Compiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compiler = new Compiler();
    }

    /** @dataProvider goldenFixtures */
    public function test_golden_prompt_matches_fixture(
        string $fixtureDir,
        string $name,
        string $expectedProvider,
    ): void {
        $basePath = dirname(__DIR__, 2) . '/Golden/' . $fixtureDir;
        $dsl      = json_decode(file_get_contents("{$basePath}/{$name}.json"), true);
        $expected = trim(file_get_contents("{$basePath}/{$name}.txt"));

        // ProviderResolver must pick the expected provider from DSL
        $resolved = ProviderResolver::resolveFromDsl($dsl);
        $this->assertSame(
            $expectedProvider,
            $resolved,
            "ProviderResolver resolved '{$resolved}', expected '{$expectedProvider}' for {$name}",
        );

        $actual = $this->compiler->compile($dsl, $resolved);
        $this->assertSame(
            $expected,
            $actual,
            "Golden prompt mismatch for {$name}. If intentional, update tests/Golden/{$fixtureDir}/{$name}.txt",
        );
    }

    public static function goldenFixtures(): array
    {
        return [
            'flux/macro_bike_seat'         => ['flux',  'macro_bike_seat',      'flux'],
            'kling/tracking_high_motion'   => ['kling', 'tracking_high_motion', 'kling'],
        ];
    }
}
