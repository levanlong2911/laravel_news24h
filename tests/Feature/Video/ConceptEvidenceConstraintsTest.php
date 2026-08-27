<?php

namespace Tests\Feature\Video;

use App\Services\Video\CreativeProfileResolver;
use App\Video\Concept\ConceptValidator;
use App\Video\Concept\CreativeConceptParser;
use App\Video\Inspiration\ExcludedContext;
use App\Video\Inspiration\InspirationBrief;
use Tests\TestCase;

class ConceptEvidenceConstraintsTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function violationsFor(array $overrides = []): array
    {
        $raw = json_decode(file_get_contents(base_path('tests/Fixtures/Video/concept_v16_before_phase_two.json')), true);
        $concept = (new CreativeConceptParser)->parse(json_encode(array_replace_recursive($raw, $overrides)));

        $profile = (new CreativeProfileResolver)->resolve('yacht');
        $brief = new InspirationBrief(['design_profile'], 'A source.', [], [new ExcludedContext('builder', 'Azimut')]);

        return (new ConceptValidator)->validate($concept, $profile, $brief)->fatalViolations;
    }

    public function test_the_shipped_profile_refuses_the_last_v16_concept_that_violated_phase_two_constraints(): void
    {
        $violations = $this->violationsFor();

        $this->assertContains('design_identity.length_to_beam_ratio must be between 5.5 and 7.5', $violations);
        $this->assertContains('design_identity.bow.stem must be one of plumb, near_plumb', $violations);
        $this->assertContains('design_identity.stern.transom must be one of plumb_full_beam', $violations);
        $this->assertContains('design_identity.stern.platform must be one of recessed_waterline', $violations);
        $this->assertContains('signature_features[1].description uses a forbidden form: cantilever', $violations);
        $this->assertContains('design_identity.stern.transom_face uses a forbidden form: fins', $violations);
    }

    public function test_a_slender_hull_inside_the_evidenced_band_passes_the_ratio_check(): void
    {
        $violations = $this->violationsFor(['design_identity' => ['length_to_beam_ratio' => 6.0]]);

        $this->assertNotContains('design_identity.length_to_beam_ratio must be between 5.5 and 7.5', $violations);
    }

    public function test_a_deck_stack_beyond_the_evidenced_range_is_refused(): void
    {
        $this->assertContains(
            'design_identity.visible_deck_tiers must be between 3 and 6',
            $this->violationsFor(['design_identity' => ['visible_deck_tiers' => 9]]),
        );
    }

    public function test_a_forbidden_form_in_the_thesis_is_a_violation(): void
    {
        $this->assertContains(
            'design_thesis uses a forbidden form: wedding cake',
            $this->violationsFor(['design_thesis' => 'A wedding cake of stepped decks rises above the hull.']),
        );
    }

    public function test_a_forbidden_form_in_a_decision_is_a_violation(): void
    {
        $violations = $this->violationsFor(['decisions' => [0 => [
            'decision' => 'Kept the bulbous bow because the source showed one.',
        ]]]);

        $this->assertContains('decisions[0].decision uses a forbidden form: bulbous', $violations);
    }

    public function test_a_word_that_merely_contains_a_forbidden_term_is_left_alone(): void
    {
        $violations = $this->violationsFor(['design_thesis' => 'A finishing hall defines the finned-free silhouette.']);

        $this->assertNotContains('design_thesis uses a forbidden form: fin', $violations);
        $this->assertNotContains('design_thesis uses a forbidden form: fins', $violations);
    }

    public function test_the_instruction_warns_about_every_forbidden_term_before_the_call(): void
    {
        $profile = (new CreativeProfileResolver)->resolve('yacht');

        $designer = new \App\Video\Concept\ClaudeConceptDesigner(
            new class implements \App\Video\Llm\LlmClient
            {
                public function complete(\App\Video\Llm\LlmRequest $request): \App\Video\Llm\LlmResponse
                {
                    throw new \LogicException('instruction() khong duoc goi model');
                }
            }
        );

        $method = new \ReflectionMethod($designer, 'instruction');
        $method->setAccessible(true);
        $instruction = $method->invoke($designer, $profile);

        $this->assertNotSame([], $profile->conceptForbiddenTerms);

        foreach ($profile->conceptForbiddenTerms as $term) {
            $this->assertStringContainsString($term, $instruction, $term);
        }

        $this->assertStringContainsString('do not', mb_strtolower($instruction));
        $this->assertStringContainsString('restate the same shape in different words', $instruction);
    }
}
