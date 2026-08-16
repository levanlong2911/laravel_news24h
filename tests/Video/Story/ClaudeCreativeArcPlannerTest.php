<?php

namespace Tests\Video\Story;

use App\Video\Concept\CreativeConcept;
use App\Video\Concept\FormRelationships;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Llm\LlmResponse;
use App\Video\Story\ClaudeCreativeArcPlanner;
use App\Video\Story\InvalidCreativeArc;
use PHPUnit\Framework\TestCase;

class ClaudeCreativeArcPlannerTest extends TestCase
{
    private function concept(): CreativeConcept
    {
        return new CreativeConcept(
            'One continuous line governs the object.',
            [],
            [],
            [],
            new FormRelationships('one line', 'measured volumes', 'integrated features'),
        );
    }

    /** @param list<array<string, mixed>> $scenes */
    private function planner(array $scenes, ?array &$requests = null): ClaudeCreativeArcPlanner
    {
        $requests = [];
        $llm = new class($scenes, $requests) implements LlmClient
        {
            public function __construct(private array $scenes, private array &$requests) {}

            public function complete(LlmRequest $request): LlmResponse
            {
                $this->requests[] = $request;

                return new LlmResponse(json_encode(['scenes' => $this->scenes], JSON_THROW_ON_ERROR), $request->model);
            }
        };

        return new ClaudeCreativeArcPlanner($llm);
    }

    /** @return array<string, mixed> */
    private function scene(string $stage, string $purpose): array
    {
        return [
            'stage' => $stage,
            'purpose' => $purpose,
            'objective' => "Show {$stage}.",
            'motion_intent' => 'LOW',
            'camera' => ['framing' => 'WIDE', 'movement' => 'STATIC', 'speed' => 'SLOW'],
            'aesthetic' => ['emotion' => 'CALM', 'composition' => 'CENTERED', 'light_intensity' => 'SOFT', 'light_grade' => 'NEUTRAL'],
            'composition_note' => "The {$stage} state fills the frame.",
            'micro_physics' => [],
        ];
    }

    public function test_sonnet_decides_the_scene_count_and_returns_creation_arc_phases(): void
    {
        $scenes = [
            $this->scene('design', 'ESTABLISH'),
            $this->scene('construction', 'PROCESS'),
            $this->scene('construction', 'DETAIL'),
            $this->scene('completion', 'REVEAL'),
            $this->scene('operation', 'RESOLUTION'),
        ];

        $phases = $this->planner($scenes, $requests)->plan($this->concept());

        $this->assertCount(5, $phases);
        $this->assertSame('sonnet', $requests[0]->model);
        $this->assertSame('creative-arc-v1', $requests[0]->instructionVersion);
        $this->assertSame('LOW', $phases['01_design']['motion_intent']);
    }

    public function test_an_arc_that_moves_backwards_is_rejected(): void
    {
        $this->expectException(InvalidCreativeArc::class);

        $this->planner([
            $this->scene('design', 'ESTABLISH'),
            $this->scene('construction', 'PROCESS'),
            $this->scene('completion', 'REVEAL'),
            $this->scene('construction', 'PROCESS'),
            $this->scene('operation', 'RESOLUTION'),
        ])->plan($this->concept());
    }

    public function test_object_key_order_does_not_change_the_contract(): void
    {
        $scenes = [
            $this->scene('design', 'ESTABLISH'),
            $this->scene('construction', 'PROCESS'),
            $this->scene('completion', 'REVEAL'),
            $this->scene('operation', 'RESOLUTION'),
        ];
        foreach ($scenes as &$scene) {
            $scene['camera'] = ['speed' => 'SLOW', 'framing' => 'WIDE', 'movement' => 'STATIC'];
            $scene['aesthetic'] = [
                'light_grade' => 'NEUTRAL',
                'emotion' => 'CALM',
                'light_intensity' => 'SOFT',
                'composition' => 'CENTERED',
            ];
        }

        $this->assertCount(4, $this->planner($scenes)->plan($this->concept()));
    }

    public function test_an_empty_scene_list_fails_as_a_structured_error(): void
    {
        $this->expectException(InvalidCreativeArc::class);

        $this->planner([])->plan($this->concept());
    }
}
