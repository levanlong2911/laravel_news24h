<?php

namespace Tests\Video\RenderPlan;

use App\Video\Concept\CreativeConcept;
use App\Video\Concept\FormRelationships;
use App\Video\RenderPlan\CreativeRenderPlanBuilder;
use App\Video\RenderPlan\RenderPlanMeta;
use PHPUnit\Framework\TestCase;

class CreativeRenderPlanBuilderTest extends TestCase
{
    public function test_it_builds_the_plan_directly_from_the_concept_and_dynamic_phases(): void
    {
        $concept = new CreativeConcept(
            'One line governs the form.',
            ['length' => 80.0],
            [],
            [],
            new FormRelationships('one line', 'measured volumes', 'integrated features'),
        );
        $phase = [
            'purpose' => 'ESTABLISH',
            'objective' => 'Show the design.',
            'motion_intent' => 'NONE',
            'camera' => ['framing' => 'WIDE', 'movement' => 'STATIC', 'speed' => 'SLOW'],
            'aesthetic' => ['emotion' => 'CALM', 'composition' => 'CENTERED', 'light_intensity' => 'SOFT', 'light_grade' => 'NEUTRAL'],
            'composition_note' => 'The design fills the frame.',
            'micro_physics' => [],
        ];
        $phases = ['01_design' => $phase, '02_construction' => $phase, '03_completion' => $phase, '04_operation' => $phase];
        $meta = new RenderPlanMeta(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            'A new object',
            'en',
            '2026-08-15T00:00:00+00:00',
            'yacht',
        );

        $plan = (new CreativeRenderPlanBuilder)->build($meta, $concept, $phases);

        $this->assertCount(4, $plan['scenes']);
        $this->assertSame('NONE', $plan['scenes'][0]['motion_intent']);
        $this->assertSame($concept->toArray(), $plan['creative_concept']);
        $this->assertSame('creative_subject', $plan['world']['entities'][0]['id']);
        $this->assertArrayNotHasKey('producer', $plan);
    }
}
