<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Benchmark\Scenario;

use App\Services\AI\FilmOS\Benchmark\Scenario\ScenarioBootstrapper;
use App\Services\AI\FilmOS\Benchmark\Scenario\ScenarioLoader;
use App\Services\AI\FilmOS\Narrative\Character\EmotionalState;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceChannel;
use App\Services\AI\FilmOS\Narrative\Production\ConstraintMode;
use App\Services\AI\FilmOS\Narrative\Scene\ShotType;
use App\Services\AI\FilmOS\Narrative\Story\StoryBeat;
use PHPUnit\Framework\TestCase;

/**
 * ScenarioDocument → NarrativeState across all six Knowledge domains,
 * with authored beats resolved to ordinals by BeatOrdinalMap.
 */
final class ScenarioBootstrapperTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/filmos_scenario_boot_' . uniqid();
        mkdir($this->dir, recursive: true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
    }

    // ── Full v2 scenario populates all six domains ────────────────────────────

    public function test_assembles_all_six_domains(): void
    {
        $state = $this->assemble('full', $this->fullV2Scenario());

        // Story (D0/D1) — beats hook=0, payoff=1 (compact cinematic order)
        $this->assertCount(2, $state->story->allShots());
        $this->assertSame(StoryBeat::HOOK,   $state->story->beatOf(0));
        $this->assertSame(StoryBeat::PAYOFF, $state->story->beatOf(1));
        $this->assertSame('Ball overhead', $state->story->shotAt(1)?->endingFrame?->description);

        // World (D3)
        $this->assertSame('cold', $state->world->getFact('weather')?->value);
        $this->assertTrue($state->world->hasObject('hero_obj'));

        // Character (D2) — emotion resolved to correct ordinal, persistence
        $this->assertSame(EmotionalState::FEAR, $state->characters->emotionAt('hero', 0)?->state);
        $this->assertSame(EmotionalState::DETERMINATION, $state->characters->emotionAt('hero', 1)?->state);

        // Scene (D4) — camera per ordinal
        $this->assertSame(ShotType::CLOSE_UP, $state->scene->getCamera(0)?->shotType);
        $this->assertSame(ShotType::WIDE,     $state->scene->getCamera(1)?->shotType);

        // Production — beat-anchored fields resolved to ordinals
        $this->assertSame('audience believes all is lost', $state->production->intent()?->objective);
        $this->assertSame(1, $state->production->heroMoment()?->ordinal);          // 'payoff' → 1
        $this->assertSame(100, $state->production->energyAt(1));                   // energy at payoff
        $this->assertSame('release', $state->production->energyCurve()[1]->reason);
        $this->assertSame(2.0, $state->production->durationAt(0));
        $this->assertSame(ConstraintMode::ALWAYS, $state->production->constraints()[0]->mode);

        // Performance — beat 'payoff' → ordinal 1, cue order + channel preserved
        $perf = $state->performance->performanceOf('hero', 1);
        $this->assertSame('total commitment', $perf?->intent?->intent);
        $this->assertSame('holds breath', $perf?->cues[0]->description);
        $this->assertSame(PerformanceChannel::BREATH, $perf?->cues[0]->channel);
    }

    // ── v1 scenario: production / performance views are empty ────────────────

    public function test_v1_scenario_leaves_production_and_performance_empty(): void
    {
        $data = $this->fullV2Scenario();
        $data['schema_version'] = 1;
        unset($data['production'], $data['performance']);

        $state = $this->assemble('bare', $data);

        $this->assertNull($state->production->intent());
        $this->assertSame([], $state->production->energyCurve());
        $this->assertSame([], $state->performance->allPerformances());
        // Other four domains still assembled
        $this->assertCount(2, $state->story->allShots());
        $this->assertSame(EmotionalState::FEAR, $state->characters->emotionAt('hero', 0)?->state);
    }

    // ── Character-less scenario (product/world) assembles cleanly ────────────

    public function test_character_less_scenario_assembles(): void
    {
        $data = $this->fullV2Scenario();
        $data['schema_version'] = 1;
        unset($data['production'], $data['performance']);
        $data['characters']  = [];
        $data['emotion_arc'] = [];
        // scene nodes reference a prop instead of a character
        $data['world_objects'] = [['id' => 'bottle_obj', 'type' => 'prop', 'label' => 'Bottle', 'attributes' => []]];
        foreach (['hook', 'payoff'] as $b) {
            $data['scene_nodes'][$b] = [['id' => 'hero_node', 'type' => 'subject', 'label' => 'Bottle', 'world_object_ref' => 'bottle_obj']];
        }

        $state = $this->assemble('product', $data);

        $this->assertCount(2, $state->story->allShots());
        $this->assertSame([], $state->characters->allCharacters());
        $this->assertSame(ShotType::CLOSE_UP, $state->scene->getCamera(0)?->shotType);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function assemble(string $id, array $data): \App\Services\AI\FilmOS\Narrative\Timeline\NarrativeState
    {
        $data['id'] = $id;   // id field must equal filename
        file_put_contents("{$this->dir}/{$id}.json", json_encode($data, JSON_THROW_ON_ERROR));
        $doc = (new ScenarioLoader($this->dir))->fromId($id);
        return (new ScenarioBootstrapper())->assemble($doc)->state;
    }

    private function fullV2Scenario(): array
    {
        return [
            'schema_version' => 2,
            'id'             => 'full',
            'suite'          => 'camera',
            'level'          => 'A',
            'difficulty'     => 'medium',
            'duration_seconds' => 8,
            'primary_learning_dimension'   => 'camera_tracking',
            'secondary_learning_dimensions'=> [],
            'stress_dimensions'            => [],
            'goal'           => 'The audience should feel tension release.',
            'facts'          => [],
            'world_objects'  => [['id' => 'hero_obj', 'type' => 'character', 'label' => 'Hero', 'attributes' => []]],
            'world_facts'    => ['weather' => 'cold'],
            'characters'     => [['id' => 'hero', 'label' => 'Hero', 'world_object_ref' => 'hero_obj', 'appearance' => ['outfit' => 'red']]],
            'emotion_arc'    => [
                'hero' => [
                    ['at' => 'hook',   'state' => 'fear',          'intensity' => 'moderate'],
                    ['at' => 'payoff', 'state' => 'determination', 'intensity' => 'intense'],
                ],
            ],
            'shots' => [
                'hook' => [
                    'importance' => 'required', 'action' => 'Hero reads the danger',
                    'camera' => ['shot_type' => 'close_up', 'angle' => 'eye_level', 'movement' => 'handheld', 'lens' => 'telephoto', 'focus_node' => 'hero_node'],
                    'ending_frame' => 'Eyes lock forward',
                ],
                'payoff' => [
                    'importance' => 'required', 'action' => 'Hero commits',
                    'camera' => ['shot_type' => 'wide', 'angle' => 'low', 'movement' => 'tilt', 'lens' => 'wide'],
                    'ending_frame' => 'Ball overhead',
                ],
            ],
            'scene_nodes' => [
                'hook'   => [['id' => 'hero_node', 'type' => 'subject', 'label' => 'Hero', 'world_object_ref' => 'hero_obj']],
                'payoff' => [['id' => 'hero_node', 'type' => 'subject', 'label' => 'Hero', 'world_object_ref' => 'hero_obj']],
            ],
            'production' => [
                'director_intent' => 'audience believes all is lost',
                'conflicts'   => [['description' => 'clock', 'type' => 'time']],
                'motifs'      => [['label' => 'spiral', 'importance' => 'primary']],
                'constraints' => [['target' => 'ball', 'rule' => 'visible', 'mode' => 'always']],
                'hero_moment' => ['at' => 'payoff', 'description' => 'ball overhead'],
                'energy_curve'=> [['at' => 'hook', 'value' => 30], ['at' => 'payoff', 'value' => 100, 'reason' => 'release']],
                'timings'     => [['at' => 'hook', 'duration_seconds' => 2.0], ['at' => 'payoff', 'duration_seconds' => 2.5]],
            ],
            'performance' => [
                'payoff' => [
                    'hero' => [
                        'intent' => 'total commitment',
                        'motivation' => 'nothing left to protect',
                        'cues' => [
                            ['description' => 'holds breath', 'channel' => 'breath'],
                            ['description' => 'commits'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
