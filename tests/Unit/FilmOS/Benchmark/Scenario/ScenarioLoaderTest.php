<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Benchmark\Scenario;

use App\Services\AI\FilmOS\Benchmark\Scenario\Difficulty;
use App\Services\AI\FilmOS\Benchmark\Scenario\ScenarioLoader;
use App\Services\AI\FilmOS\Benchmark\Scenario\ScenarioSchemaException;
use App\Services\AI\FilmOS\Benchmark\Scenario\Suite;
use PHPUnit\Framework\TestCase;

final class ScenarioLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/filmos_scenario_loader_' . uniqid();
        mkdir($this->dir, recursive: true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
    }

    // ── Valid load ────────────────────────────────────────────────────────────

    public function test_loads_valid_v1_scenario(): void
    {
        $doc = $this->load('demo', $this->baseScenario());

        $this->assertSame('demo', $doc->id);
        $this->assertSame(Suite::CAMERA, $doc->suite);
        $this->assertSame(Difficulty::MEDIUM, $doc->difficulty);
        $this->assertSame(1, $doc->schemaVersion);
        $this->assertFalse($doc->hasProduction());
        $this->assertFalse($doc->hasPerformance());
        $this->assertSame(['hook', 'payoff'], array_keys($doc->shots));
    }

    public function test_loads_valid_v2_scenario_with_production_and_performance(): void
    {
        $data = $this->baseScenario();
        $data['schema_version'] = 2;
        $data['production'] = [
            'director_intent' => 'audience believes all is lost',
            'conflicts'   => [['description' => 'clock', 'type' => 'time']],
            'motifs'      => [['label' => 'spiral', 'importance' => 'primary']],
            'constraints' => [['target' => 'ball', 'rule' => 'visible', 'mode' => 'always']],
            'hero_moment' => ['at' => 'payoff', 'description' => 'ball overhead'],
            'energy_curve'=> [['at' => 'hook', 'value' => 30], ['at' => 'payoff', 'value' => 100, 'reason' => 'release']],
            'timings'     => [['at' => 'hook', 'duration_seconds' => 2.0]],
        ];
        $data['performance'] = [
            'payoff' => ['hero' => ['intent' => 'total commitment', 'cues' => [['description' => 'holds breath', 'channel' => 'breath']]]],
        ];

        $doc = $this->load('demo', $data);

        $this->assertSame(2, $doc->schemaVersion);
        $this->assertTrue($doc->hasProduction());
        $this->assertTrue($doc->hasPerformance());
    }

    // ── Rule 8 ────────────────────────────────────────────────────────────────

    public function test_rule8_v1_declaring_production_is_rejected(): void
    {
        $data = $this->baseScenario();               // schema_version 1
        $data['production'] = ['director_intent' => 'x'];

        $this->expectExceptionMessageMatches('/schema_version must be 2/');
        $this->load('demo', $data);
    }

    public function test_rule8_v2_without_v2_fields_is_rejected(): void
    {
        $data = $this->baseScenario();
        $data['schema_version'] = 2;                  // but no production/performance

        $this->expectExceptionMessageMatches('/schema_version must be 1/');
        $this->load('demo', $data);
    }

    // ── Identity + enums ──────────────────────────────────────────────────────

    public function test_id_field_must_equal_filename(): void
    {
        $data = $this->baseScenario();
        $data['id'] = 'something_else';

        $this->expectExceptionMessageMatches('/must equal the filename/');
        $this->load('demo', $data);
    }

    public function test_bad_camera_enum_is_rejected(): void
    {
        $data = $this->baseScenario();
        $data['shots']['hook']['camera']['shot_type'] = 'not_a_shot';

        $this->expectExceptionMessageMatches('/camera\.shot_type/');
        $this->load('demo', $data);
    }

    // ── Referential integrity ─────────────────────────────────────────────────

    public function test_dangling_focus_node_is_rejected(): void
    {
        $data = $this->baseScenario();
        $data['shots']['hook']['camera']['focus_node'] = 'ghost_node';

        $this->expectExceptionMessageMatches('/focus_node .*is not a scene node/');
        $this->load('demo', $data);
    }

    public function test_dangling_world_object_ref_in_scene_node_is_rejected(): void
    {
        $data = $this->baseScenario();
        $data['scene_nodes']['hook'][0]['world_object_ref'] = 'ghost_obj';

        $this->expectExceptionMessageMatches('/unknown world_object_ref/');
        $this->load('demo', $data);
    }

    public function test_emotion_arc_unknown_character_is_rejected(): void
    {
        $data = $this->baseScenario();
        $data['emotion_arc'] = ['ghost' => [['at' => 'hook', 'state' => 'fear', 'intensity' => 'moderate']]];

        $this->expectExceptionMessageMatches('/unknown character/');
        $this->load('demo', $data);
    }

    public function test_performance_unknown_character_is_rejected(): void
    {
        $data = $this->baseScenario();
        $data['schema_version'] = 2;
        $data['performance'] = ['hook' => ['ghost' => ['intent' => 'x', 'cues' => []]]];

        $this->expectExceptionMessageMatches('/unknown character/');
        $this->load('demo', $data);
    }

    public function test_missing_file_is_rejected(): void
    {
        $this->expectException(ScenarioSchemaException::class);
        (new ScenarioLoader($this->dir))->fromId('does_not_exist');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    private function load(string $id, array $data): \App\Services\AI\FilmOS\Benchmark\Scenario\ScenarioDocument
    {
        file_put_contents("{$this->dir}/{$id}.json", json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return (new ScenarioLoader($this->dir))->fromId($id);
    }

    /** A minimal valid v1 scenario: hero character, hook+payoff, one world object. */
    private function baseScenario(): array
    {
        return [
            'schema_version' => 1,
            'id'             => 'demo',
            'suite'          => 'camera',
            'level'          => 'A',
            'difficulty'     => 'medium',
            'duration_seconds' => 8,
            'primary_learning_dimension'   => 'camera_tracking',
            'secondary_learning_dimensions'=> ['emotion_hold'],
            'stress_dimensions'            => ['motion_complexity'],
            'goal'           => 'The audience should feel tension release.',
            'facts'          => [],
            'world_objects'  => [
                ['id' => 'hero_obj', 'type' => 'character', 'label' => 'Hero', 'attributes' => []],
            ],
            'world_facts'    => ['weather' => 'cold'],
            'characters'     => [
                ['id' => 'hero', 'label' => 'Hero', 'world_object_ref' => 'hero_obj', 'appearance' => ['outfit' => 'red']],
            ],
            'emotion_arc'    => [
                'hero' => [
                    ['at' => 'hook',   'state' => 'fear',          'intensity' => 'moderate'],
                    ['at' => 'payoff', 'state' => 'determination', 'intensity' => 'intense', 'cause' => 'one chance'],
                ],
            ],
            'shots' => [
                'hook' => [
                    'importance' => 'required',
                    'action'     => 'Hero reads the danger',
                    'camera'     => ['shot_type' => 'close_up', 'angle' => 'eye_level', 'movement' => 'handheld', 'lens' => 'telephoto', 'focus_node' => 'hero_node'],
                    'ending_frame' => 'Eyes lock forward',
                ],
                'payoff' => [
                    'importance' => 'required',
                    'action'     => 'Hero commits',
                    'camera'     => ['shot_type' => 'wide', 'angle' => 'low', 'movement' => 'tilt', 'lens' => 'wide'],
                    'ending_frame' => 'The moment releases',
                ],
            ],
            'scene_nodes' => [
                'hook'   => [['id' => 'hero_node', 'type' => 'subject', 'label' => 'Hero', 'world_object_ref' => 'hero_obj']],
                'payoff' => [['id' => 'hero_node', 'type' => 'subject', 'label' => 'Hero', 'world_object_ref' => 'hero_obj']],
            ],
        ];
    }
}
