<?php

namespace Tests\Feature\Video;

use App\Video\Prompt\GeometryPromptAuthor;
use Tests\TestCase;

class GeometryReferenceSkillTest extends TestCase
{
    private function skill(): string
    {
        return (string) file_get_contents((string) config('image_prompt.prompt_path'));
    }

    private function instructions(): string
    {
        $skill = $this->skill();

        return mb_substr($skill, 0, (int) mb_strpos($skill, 'SOURCE MATERIAL:'));
    }

    public function test_the_anchor_author_loads_the_v2_skill_and_keeps_v1_for_comparison(): void
    {
        $this->assertStringEndsWith('geometry_reference_v2.txt', (string) config('image_prompt.prompt_path'));
        $this->assertSame('geometry-reference-v2', config('image_prompt.prompt_version'));
        $this->assertFileExists(resource_path('ai/prompts/geometry_reference_v1.txt'));
        $this->assertSame(1, substr_count($this->skill(), 'SOURCE MATERIAL:'));
    }

    public function test_a_new_skill_hash_keeps_an_old_anchor_prompt_from_being_reused(): void
    {
        $author = app(GeometryPromptAuthor::class);

        $this->assertSame(hash('sha256', $this->skill()), $author->skillHash());
        $this->assertNotSame(
            hash('sha256', (string) file_get_contents(resource_path('ai/prompts/geometry_reference_v1.txt'))),
            $author->skillHash(),
        );
    }

    public function test_the_skill_keeps_source_qualifiers_and_never_hardens_what_it_designs(): void
    {
        $text = $this->instructions();

        foreach ([
            'Carry it unchanged; never replace it with its opposite',
            'do not resolve them by invention and do not describe that aspect as settled',
            'anything you design is rendering support, not identity',
            'Exact figures, counts and materials come only from the source.',
            'keep it as open as the source leaves it rather than resolving it by invention',
            'Exact counts come only from the source.',
            'Do not state inferred secondary dimensions as figures',
            'bare unpainted metal (the source\'s material when it names one)',
            'No water, planting, loose items or applied finish',
            'Describe each connecting element by its endpoints. Any axis term must use the object\'s coordinate system and agree with those endpoints.',
            'CAMERA, FRAMING and LIGHTING must not require a feature to be visible when the chosen view would naturally occlude it.',
            'do not distort or relocate geometry to expose hidden features',
            'Integrated structure is described as integrated',
            'Do not introduce identity-defining position, extent or symmetry constraints unless explicitly stated or unambiguously implied by the source',
            '13. Are exact figures, counts and materials traceable to the source, and are identity-defining position, extent and symmetry constraints explicitly stated or unambiguously implied by it?',
            '14. Do CAMERA, FRAMING or LIGHTING require a feature that the chosen view would naturally occlude?',
            'The typical example is what the image model already produces without you.',
            'This is not a snapshot of a particular construction milestone',
            'every permanent structural component the source describes is present',
            'resolve contradictions your own prompt introduces. Do not reconcile conflicts that exist in the source',
            'Superyacht: overall length is ALWAYS between 100 and 180 metres.',
        ] as $rule) {
            $this->assertStringContainsString($rule, $text, $rule);
        }
    }

    public function test_the_rules_that_invited_invention_are_gone(): void
    {
        $text = $this->instructions();

        foreach ([
            'full authority to design the missing geometry',
            'could actually be built',
            'unless a count is necessary for stable identity',
            'If a secondary dimension is inferred, mark it as approximate',
            'Resolve ambiguity into one coherent canonical configuration',
            'mill-finish steel',
            'distinguish exact supplied constraints from reasonable derived or approximate constraints',
            'Does the camera expose the geometry being constrained?',
            'at the end of rough construction',
            'Resolve contradictions before output.',
            'require to be visible only what the chosen view can physically see',
            'A feature facing away from the camera stays described as present',
            '13. Is every exact figure, count and material traceable to the source?',
            '14. Does any sentence ask the chosen view to show a feature that faces away from it?',
        ] as $gone) {
            $this->assertStringNotContainsString($gone, $text, $gone);
        }
    }
}
