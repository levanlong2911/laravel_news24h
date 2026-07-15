<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Prompting;

use App\Services\AI\FilmOS\Benchmark\Scenario\ScenarioBootstrapper;
use App\Services\AI\FilmOS\Benchmark\Scenario\ScenarioLoader;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeAuditReport;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectType;
use App\Services\AI\FilmOS\Prompting\Compiler\NarrativePromptCompiler;
use App\Services\AI\FilmOS\Prompting\IR\StructuredPrompt;
use PHPUnit\Framework\TestCase;

/**
 * The compiler selects subjects for the vendor boundary: WorldObjects that a
 * scene node references (never all of WorldView), deduped by world-object id,
 * primary first then first-appearance order.
 */
final class SubjectSelectionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/filmos_subjects_' . uniqid();
        mkdir($this->dir, recursive: true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
    }

    public function test_selects_only_referenced_objects_primary_first(): void
    {
        // World has 4 objects; only hero_obj + villain_obj are referenced by scene nodes.
        // hero_obj is a camera focus → primary. stadium_obj/ball_obj are NOT referenced → excluded.
        $ir = $this->compile('sel', $this->scenario(
            worldObjects: [
                ['id' => 'hero_obj',    'type' => 'character', 'label' => 'Hero',    'attributes' => []],
                ['id' => 'villain_obj', 'type' => 'character', 'label' => 'Villain', 'attributes' => []],
                ['id' => 'stadium_obj', 'type' => 'location',  'label' => 'Stadium', 'attributes' => []],  // never in a scene node
                ['id' => 'ball_obj',    'type' => 'prop',      'label' => 'Ball',    'attributes' => []],  // never in a scene node
            ],
            sceneNodesByBeat: [
                // villain appears first (hook), hero appears second (payoff) but is primary via focus
                'hook'   => [['id' => 'villain_node', 'type' => 'subject', 'label' => 'Villain', 'world_object_ref' => 'villain_obj']],
                'payoff' => [['id' => 'hero_node',    'type' => 'subject', 'label' => 'Hero',    'world_object_ref' => 'hero_obj']],
            ],
            focusByBeat: ['payoff' => 'hero_node'],   // hero is the focus → primary
        ));

        $subjects = $ir->subjects();
        $this->assertCount(2, $subjects, 'only referenced world objects become subjects');

        // primary (hero) first, despite appearing later than villain
        $this->assertSame('hero_obj', $subjects[0]->id);
        $this->assertTrue($subjects[0]->isPrimary);
        $this->assertSame('villain_obj', $subjects[1]->id);
        $this->assertFalse($subjects[1]->isPrimary);
    }

    public function test_many_nodes_of_same_object_collapse_to_one_subject(): void
    {
        // Three nodes all reference hero_obj → exactly ONE SubjectDescriptor.
        $ir = $this->compile('collapse', $this->scenario(
            worldObjects: [['id' => 'hero_obj', 'type' => 'character', 'label' => 'Hero', 'attributes' => []]],
            sceneNodesByBeat: [
                'hook'   => [['id' => 'hero_node',       'type' => 'subject',    'label' => 'Hero', 'world_object_ref' => 'hero_obj']],
                'payoff' => [
                    ['id' => 'hero_close_node',      'type' => 'subject',    'label' => 'Hero', 'world_object_ref' => 'hero_obj'],
                    ['id' => 'hero_reflection_node', 'type' => 'background', 'label' => 'Hero', 'world_object_ref' => 'hero_obj'],
                ],
            ],
            focusByBeat: ['hook' => 'hero_node'],
        ));

        $this->assertCount(1, $ir->subjects());
        $this->assertSame('hero_obj', $ir->subjects()[0]->id);
        $this->assertSame(WorldObjectType::CHARACTER, $ir->subjects()[0]->type);
    }

    public function test_type_flows_into_subject_for_anatomy(): void
    {
        // A vehicle subject carries WorldObjectType::VEHICLE into the IR.
        $ir = $this->compile('vehicle', $this->scenario(
            worldObjects: [['id' => 'car_obj', 'type' => 'vehicle', 'label' => 'Supercar', 'attributes' => []]],
            sceneNodesByBeat: ['hook' => [['id' => 'car_node', 'type' => 'subject', 'label' => 'Supercar', 'world_object_ref' => 'car_obj']]],
            focusByBeat: ['hook' => 'car_node'],
        ));

        $this->assertSame(WorldObjectType::VEHICLE, $ir->subjects()[0]->type);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function compile(string $id, array $scenario): StructuredPrompt
    {
        $scenario['id'] = $id;   // id field must equal filename
        file_put_contents("{$this->dir}/{$id}.json", json_encode($scenario, JSON_THROW_ON_ERROR));
        $state = (new ScenarioBootstrapper())->assemble((new ScenarioLoader($this->dir))->fromId($id))->state;

        return (new NarrativePromptCompiler())->compile(
            $state->story, $state->characters, $state->scene, $state->world,
            $state->production, $state->performance, new NarrativeAuditReport([]),
        );
    }

    private function scenario(array $worldObjects, array $sceneNodesByBeat, array $focusByBeat): array
    {
        $shots = [];
        foreach (array_keys($sceneNodesByBeat) as $beat) {
            $camera = ['shot_type' => 'medium', 'angle' => 'eye_level', 'movement' => 'static', 'lens' => 'normal'];
            if (isset($focusByBeat[$beat])) {
                $camera['focus_node'] = $focusByBeat[$beat];
            }
            $shots[$beat] = ['importance' => 'required', 'action' => "Beat {$beat}", 'camera' => $camera, 'ending_frame' => 'end'];
        }

        return [
            'schema_version' => 1,
            'id'             => 'placeholder',   // overwritten by compile() filename
            'suite'          => 'camera',
            'level'          => 'A',
            'difficulty'     => 'medium',
            'duration_seconds' => 8,
            'primary_learning_dimension'    => 'camera_tracking',
            'secondary_learning_dimensions' => [],
            'stress_dimensions'             => [],
            'goal'          => 'x',
            'facts'         => [],
            'world_objects' => $worldObjects,
            'world_facts'   => [],
            'characters'    => [],
            'emotion_arc'   => [],
            'shots'         => $shots,
            'scene_nodes'   => $sceneNodesByBeat,
        ];
    }
}
