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

    public function test_the_beam_is_a_declared_slot_not_a_number_python_has_to_derive(): void
    {
        $slot = (new CreativeProfileResolver)->resolve('yacht')?->identitySlots['design_beam_m'] ?? [];

        $this->assertSame('number', $slot['type']);
        $this->assertSame(10.0, $slot['min']);
        $this->assertSame(35.0, $slot['max']);
        $this->assertStringContainsString('consistent with design_length_m', $slot['guidance']);
    }

    public function test_the_hull_carries_the_sheer_as_a_closed_value(): void
    {
        $hull = (new CreativeProfileResolver)->resolve('yacht')?->identitySlots['hull'] ?? [];

        $this->assertSame('object', $hull['type']);
        $this->assertSame(['sheer', 'midbody', 'keel'], array_keys($hull['fields']));
        $this->assertContains('continuous_gentle_rise_toward_bow', $hull['fields']['sheer']['values']);
        $this->assertSame(['continuous_central_baseline'], $hull['fields']['keel']['values']);
    }

    public function test_the_hull_does_not_restate_the_material_or_the_bow(): void
    {
        $hull = (new CreativeProfileResolver)->resolve('yacht')?->identitySlots['hull'] ?? [];

        foreach (['type', 'forefoot', 'chine'] as $elsewhere) {
            $this->assertArrayNotHasKey($elsewhere, $hull['fields'], $elsewhere);
        }
    }

    public function test_a_beam_outside_the_declared_band_is_refused(): void
    {
        $this->assertContains(
            'design_identity.design_beam_m must be between 10 and 35',
            $this->violationsFor(['design_identity' => ['design_beam_m' => 44.0]]),
        );
    }

    public function test_a_missing_beam_is_refused(): void
    {
        $this->assertContains('design_identity is missing slot design_beam_m', $this->violationsFor());
    }

    public function test_a_hull_field_outside_its_enum_is_refused(): void
    {
        $this->assertContains(
            'design_identity.hull.keel must be one of continuous_central_baseline',
            $this->violationsFor(['design_identity' => ['hull' => [
                'sheer' => 'continuous_level', 'midbody' => 'full_displacement', 'keel' => 'skeg',
            ]]]),
        );
    }

    public function test_the_instruction_asks_for_the_beam_and_the_hull(): void
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

        $this->assertStringContainsString('- design_beam_m: number, between 10 and 35.', $instruction);
        $this->assertStringContainsString('- hull: an object with exactly these keys:', $instruction);
        $this->assertStringContainsString('  - sheer: exactly one of: continuous_gentle_rise_toward_bow, continuous_level.', $instruction);
        $this->assertStringContainsString('  - keel: always continuous_central_baseline.', $instruction);
    }

    /** @param array<string, mixed> $dimensions */
    private function crossCheckViolationsFor(array $dimensions): array
    {
        return $this->violationsFor(['design_identity' => $dimensions]);
    }

    public function test_a_ratio_that_agrees_with_length_over_beam_passes(): void
    {
        $violations = $this->crossCheckViolationsFor([
            'design_length_m' => 120.0, 'design_beam_m' => 17.65, 'length_to_beam_ratio' => 6.8,
        ]);

        $this->assertNotContains(
            'design_identity.length_to_beam_ratio is inconsistent with design_length_m and design_beam_m',
            $violations,
        );
    }

    public function test_a_ratio_that_disagrees_with_length_over_beam_is_refused(): void
    {
        $violations = $this->crossCheckViolationsFor([
            'design_length_m' => 120.0, 'design_beam_m' => 23.1, 'length_to_beam_ratio' => 6.8,
        ]);

        $this->assertContains(
            'design_identity.length_to_beam_ratio is inconsistent with design_length_m and design_beam_m',
            $violations,
        );
    }

    public function test_a_missing_slot_leaves_the_cross_check_silent(): void
    {
        foreach ($this->violationsFor() as $violation) {
            $this->assertStringNotContainsString('is inconsistent with', $violation);
        }
    }

    public function test_a_cross_check_naming_a_slot_that_is_not_a_number_fails_at_boot(): void
    {
        config(['video.creative_profiles.profiles.luxury_vessel.identity_cross_checks' => [[
            'kind' => 'ratio', 'numerator' => 'design_length_m',
            'denominator' => 'hull_colour', 'equals' => 'length_to_beam_ratio', 'tolerance' => 0.08,
        ]]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('denominator hull_colour must be a number slot');

        (new CreativeProfileResolver)->resolve('yacht');
    }

    /** @dataProvider unusableTolerances */
    public function test_a_tolerance_that_can_never_refuse_anything_fails_at_boot(mixed $tolerance): void
    {
        config(['video.creative_profiles.profiles.luxury_vessel.identity_cross_checks' => [[
            'kind' => 'ratio', 'numerator' => 'design_length_m',
            'denominator' => 'design_beam_m', 'equals' => 'length_to_beam_ratio', 'tolerance' => $tolerance,
        ]]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tolerance must be a positive finite number');

        (new CreativeProfileResolver)->resolve('yacht');
    }

    /** @return array<string, array{mixed}> */
    public static function unusableTolerances(): array
    {
        return [
            'not a number at all' => ['0.08'],
            'nan never compares true' => [NAN],
            'infinity never compares true' => [INF],
            'negative' => [-0.08],
        ];
    }

    public function test_a_cross_check_with_a_zero_tolerance_fails_at_boot(): void
    {
        config(['video.creative_profiles.profiles.luxury_vessel.identity_cross_checks' => [[
            'kind' => 'ratio', 'numerator' => 'design_length_m',
            'denominator' => 'design_beam_m', 'equals' => 'length_to_beam_ratio', 'tolerance' => 0,
        ]]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tolerance must be a positive finite number');

        (new CreativeProfileResolver)->resolve('yacht');
    }
}
