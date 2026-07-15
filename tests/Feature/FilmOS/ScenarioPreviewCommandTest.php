<?php

declare(strict_types=1);

namespace Tests\Feature\FilmOS;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Feature test for `filmos:scenario` — asserts ORCHESTRATION (the pipeline runs
 * and every section is present), never wording (which evolves in the renderer).
 */
final class ScenarioPreviewCommandTest extends TestCase
{
    public function test_preview_runs_the_full_pipeline_and_shows_every_section(): void
    {
        $code   = Artisan::call('filmos:scenario', ['id' => 'nfl_last_second_bomb']);
        $output = Artisan::output();

        $this->assertSame(0, $code);
        foreach (['SCENARIO', 'BEATS', 'QA', 'SUBJECTS', 'POSITIVE', 'NEGATIVE', 'METADATA'] as $section) {
            $this->assertStringContainsString($section, $output, "preview must show the {$section} section");
        }
        $this->assertStringContainsString('nfl_last_second_bomb', $output);
    }

    public function test_json_output_is_valid_and_structured(): void
    {
        Artisan::call('filmos:scenario', ['id' => 'nfl_last_second_bomb', '--json' => true]);
        $decoded = json_decode(Artisan::output(), associative: true);

        $this->assertIsArray($decoded);
        $this->assertSame('nfl_last_second_bomb', $decoded['scenario']['id']);
        $this->assertSame('kling', $decoded['provider']);
        $this->assertArrayHasKey('positive', $decoded['rendered']);
        $this->assertArrayHasKey('qa', $decoded);
        $this->assertNotEmpty($decoded['subjects']);
    }

    public function test_unknown_provider_fails(): void
    {
        $code = Artisan::call('filmos:scenario', ['id' => 'nfl_last_second_bomb', '--provider' => 'nope']);

        $this->assertSame(1, $code);
    }

    public function test_missing_scenario_fails_with_message(): void
    {
        $code = Artisan::call('filmos:scenario', ['id' => 'does_not_exist']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('does_not_exist', Artisan::output());
    }
}
