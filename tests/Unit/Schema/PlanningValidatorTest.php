<?php

namespace Tests\Unit\Schema;

use App\Services\AI\PlanningValidator;
use PHPUnit\Framework\TestCase;

/**
 * PlanningValidator tests — validates our hybrid JSON Schema subset implementation.
 * Uses contracts/v1/*.schema.json as real fixtures (contract-first approach).
 */
class PlanningValidatorTest extends TestCase
{
    private PlanningValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new PlanningValidator(
            contractsPath: dirname(__DIR__, 3) . '/contracts/v1',
        );
    }

    // -------------------------------------------------------------------------
    // Story schema
    // -------------------------------------------------------------------------

    private function validBeat(array $overrides = []): array
    {
        return array_merge([
            'beat_number'      => 1,
            'goal'             => 'Arrest attention',
            'viewer_question'  => 'What is this?',
            'information_type' => 'EMOTION',
            'visual_priority'  => 'HIGH',
            'emotion'          => 'anticipation',
            'duration'         => 3.0,
            'transition'       => 'cut',
            'narrative_intent' => 'Open with impact',
        ], $overrides);
    }

    public function test_valid_story_passes(): void
    {
        $result = $this->validator->validate([
            'total_duration' => 15.0,
            'beats'          => [
                $this->validBeat(['beat_number' => 1, 'duration' => 7.0]),
                $this->validBeat(['beat_number' => 2, 'emotion' => 'awe', 'information_type' => 'DETAIL', 'duration' => 8.0]),
            ],
        ], 'Story');

        $this->assertTrue($result->passed);
        $this->assertEmpty($result->errors);
    }

    public function test_story_missing_required_field_fails(): void
    {
        $result = $this->validator->validate([
            'beats' => [$this->validBeat()],
            // missing 'total_duration'
        ], 'Story');

        $this->assertFalse($result->passed);
        $this->assertContainsField('$.total_duration', $result->errors);
    }

    public function test_story_beat_missing_required_field_fails(): void
    {
        $beat = $this->validBeat();
        unset($beat['narrative_intent']); // missing required field

        $result = $this->validator->validate([
            'total_duration' => 15.0,
            'beats'          => [$beat],
        ], 'Story');

        $this->assertFalse($result->passed);
        $this->assertContainsField('$.beats[0].narrative_intent', $result->errors);
    }

    public function test_story_duration_zero_fails_exclusive_minimum(): void
    {
        $result = $this->validator->validate([
            'total_duration' => 15.0,
            'beats'          => [$this->validBeat(['duration' => 0])],
        ], 'Story');

        $this->assertFalse($result->passed);
        $this->assertContainsField('$.beats[0].duration', $result->errors);
    }

    public function test_story_beat_invalid_information_type_fails(): void
    {
        $result = $this->validator->validate([
            'total_duration' => 15.0,
            'beats'          => [$this->validBeat(['information_type' => 'VIBE'])],
        ], 'Story');

        $this->assertFalse($result->passed);
        $this->assertContainsField('$.beats[0].information_type', $result->errors);
    }

    public function test_story_beat_invalid_visual_priority_fails(): void
    {
        $result = $this->validator->validate([
            'total_duration' => 15.0,
            'beats'          => [$this->validBeat(['visual_priority' => 'ULTRA'])],
        ], 'Story');

        $this->assertFalse($result->passed);
        $this->assertContainsField('$.beats[0].visual_priority', $result->errors);
    }

    public function test_story_empty_beats_array_fails_min_items(): void
    {
        $result = $this->validator->validate([
            'total_duration' => 15.0,
            'beats'          => [],
        ], 'Story');

        $this->assertFalse($result->passed);
        $this->assertContainsField('$.beats', $result->errors);
    }

    // -------------------------------------------------------------------------
    // Transformation schema
    // -------------------------------------------------------------------------

    public function test_valid_transformation_passes(): void
    {
        $result = $this->validator->validate([
            'theme'         => 'motorcycle customization',
            'style'         => 'cinematic',
            'duration'      => 15,
            'emotion_arc'   => ['hook', 'craftsmanship', 'power', 'reveal'],
            'color_palette' => 'warm',
            'pacing'        => 'dynamic',
        ], 'Transformation');

        $this->assertTrue($result->passed);
    }

    public function test_transformation_invalid_style_enum_fails(): void
    {
        $result = $this->validator->validate([
            'theme'         => 'test',
            'style'         => 'happyyyyy',
            'duration'      => 15,
            'emotion_arc'   => ['hook', 'reveal'],
            'color_palette' => 'warm',
            'pacing'        => 'medium',
        ], 'Transformation');

        $this->assertFalse($result->passed);
        $field = $this->findField('$.style', $result->errors);
        $this->assertNotNull($field, '$.style error not found in errors');
        $this->assertStringContainsString('enum:', $field['expected']);
        $this->assertSame('happyyyyy', $field['actual']);
    }

    public function test_transformation_duration_below_minimum_fails(): void
    {
        $result = $this->validator->validate([
            'theme'         => 'test',
            'style'         => 'cinematic',
            'duration'      => 3,
            'emotion_arc'   => ['hook', 'reveal'],
            'color_palette' => 'warm',
            'paging'        => 'medium',
        ], 'Transformation');

        $this->assertFalse($result->passed);
        $this->assertContainsField('$.duration', $result->errors);
    }

    // -------------------------------------------------------------------------
    // SceneShot schema — enum and nested validation
    // -------------------------------------------------------------------------

    public function test_valid_scene_shot_passes(): void
    {
        $result = $this->validator->validate([
            'scenes' => [
                [
                    'scene_number' => 1,
                    'title'        => 'Hook',
                    'emotion'      => 'anticipation',
                    'duration'     => 3.0,
                    'shots'        => [
                        [
                            'shot_order'   => 1,
                            'cam'          => 'MACRO',
                            'lens'         => '85',
                            'light'        => 'W1',
                            'move'         => 'P1',
                            'emo'          => 'CRAFT',
                            'dur'          => 1.5,
                            'motion_level' => 'low',
                            'realism'      => 'high',
                            'has_human'    => false,
                        ],
                    ],
                ],
            ],
        ], 'SceneShot');

        $this->assertTrue($result->passed, implode('; ', array_column($result->errors, 'field')));
    }

    public function test_scene_shot_invalid_cam_enum_fails(): void
    {
        $result = $this->validator->validate([
            'scenes' => [
                [
                    'scene_number' => 1,
                    'title'        => 'Hook',
                    'emotion'      => 'anticipation',
                    'duration'     => 3.0,
                    'shots'        => [
                        [
                            'shot_order'   => 1,
                            'cam'          => 'DRONE',
                            'lens'         => '85',
                            'light'        => 'W1',
                            'move'         => 'P1',
                            'emo'          => 'CRAFT',
                            'dur'          => 1.5,
                            'motion_level' => 'low',
                            'realism'      => 'high',
                            'has_human'    => false,
                        ],
                    ],
                ],
            ],
        ], 'SceneShot');

        $this->assertFalse($result->passed);
        $this->assertContainsField('$.scenes[0].shots[0].cam', $result->errors);
    }

    public function test_shot_duration_below_minimum_fails(): void
    {
        $result = $this->validator->validate([
            'scenes' => [
                [
                    'scene_number' => 1,
                    'title'        => 'Hook',
                    'emotion'      => 'anticipation',
                    'duration'     => 3.0,
                    'shots'        => [
                        [
                            'shot_order'   => 1,
                            'cam'          => 'MACRO',
                            'lens'         => '85',
                            'light'        => 'W1',
                            'move'         => 'P1',
                            'emo'          => 'CRAFT',
                            'dur'          => 0.1,
                            'motion_level' => 'low',
                            'realism'      => 'high',
                            'has_human'    => false,
                        ],
                    ],
                ],
            ],
        ], 'SceneShot');

        $this->assertFalse($result->passed);
        $this->assertContainsField('$.scenes[0].shots[0].dur', $result->errors);
    }

    // -------------------------------------------------------------------------
    // SceneGraph schema
    // -------------------------------------------------------------------------

    private function validSceneGraph(array $overrides = []): array
    {
        $shot = [
            'shot_id'         => 'beat-1-sh1',
            'shot_order'      => 1,
            'compiled_prompt' => 'Close-up of leather stitching.',
            'hint'            => 'flux',
            'dur'             => 2.0,
            'start_ms'        => 0,
            'end_ms'          => 2000,
            'sequence_id'     => 'S01_SH01',
            'asset'           => [],
        ];
        $base = [
            'graph_version'    => '1.0',
            'project_id'       => '00000000-0000-0000-0000-000000000001',
            'article_id'       => '00000000-0000-0000-0000-000000000002',
            'theme'            => 'motorcycle',
            'style'            => 'cinematic',
            'duration'         => 15,
            'contract_version' => '1.0',
            'planner_version'  => '1.0',
            'compiler_version' => '1.0',
            'workflow_version' => '1.0',
            'narration_text'   => 'Motorcycle story.',
            'total_scenes'     => 1,
            'total_shots'      => 1,
            'total_duration_ms'=> 2000,
            'estimated_cost'   => ['flux' => 0.01, 'kling' => 0.0, 'kenburns' => 0.0, 'voice' => 0.0, 'total' => 0.01],
            'scenes'           => [[
                'scene_id'     => 'beat-1',
                'scene_number' => 1,
                'title'        => 'Hook',
                'emotion'      => 'anticipation',
                'duration'     => 2.0,
                'shots'        => [$shot],
            ]],
        ];
        return array_merge($base, $overrides);
    }

    public function test_valid_scene_graph_passes(): void
    {
        $result = $this->validator->validate($this->validSceneGraph(), 'SceneGraph');
        $this->assertTrue($result->passed, implode('; ', array_column($result->errors, 'field')));
    }

    public function test_scene_graph_missing_graph_version_fails(): void
    {
        $graph = $this->validSceneGraph();
        unset($graph['graph_version']);

        $result = $this->validator->validate($graph, 'SceneGraph');
        $this->assertFalse($result->passed);
        $this->assertContainsField('$.graph_version', $result->errors);
    }

    public function test_scene_graph_missing_total_shots_fails(): void
    {
        $graph = $this->validSceneGraph();
        unset($graph['total_shots']);

        $result = $this->validator->validate($graph, 'SceneGraph');
        $this->assertFalse($result->passed);
        $this->assertContainsField('$.total_shots', $result->errors);
    }

    public function test_scene_graph_missing_estimated_cost_fails(): void
    {
        $graph = $this->validSceneGraph();
        unset($graph['estimated_cost']);

        $result = $this->validator->validate($graph, 'SceneGraph');
        $this->assertFalse($result->passed);
        $this->assertContainsField('$.estimated_cost', $result->errors);
    }

    public function test_scene_graph_shot_missing_start_ms_fails(): void
    {
        $graph = $this->validSceneGraph();
        unset($graph['scenes'][0]['shots'][0]['start_ms']);

        $result = $this->validator->validate($graph, 'SceneGraph');
        $this->assertFalse($result->passed);
        $this->assertContainsField('$.scenes[0].shots[0].start_ms', $result->errors);
    }

    public function test_scene_graph_shot_invalid_sequence_id_fails(): void
    {
        $graph = $this->validSceneGraph();
        $graph['scenes'][0]['shots'][0]['sequence_id'] = 'bad-format';

        $result = $this->validator->validate($graph, 'SceneGraph');
        $this->assertFalse($result->passed);
        $this->assertContainsField('$.scenes[0].shots[0].sequence_id', $result->errors);
    }

    // -------------------------------------------------------------------------
    // Schema file not found
    // -------------------------------------------------------------------------

    public function test_unknown_schema_returns_failure(): void
    {
        $result = $this->validator->validate([], 'DoesNotExist');
        $this->assertFalse($result->passed);
        $this->assertContainsField('$schema', $result->errors);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function assertContainsField(string $field, array $errors): void
    {
        $fields = array_column($errors, 'field');
        $this->assertContains($field, $fields, "Expected error for field '{$field}', got: " . implode(', ', $fields));
    }

    private function findField(string $field, array $errors): ?array
    {
        foreach ($errors as $error) {
            if ($error['field'] === $field) {
                return $error;
            }
        }
        return null;
    }
}
