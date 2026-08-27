<?php

namespace Tests\Video\Concept;

use App\Video\Concept\ConceptValidator;
use App\Video\Concept\CreativeConcept;
use App\Video\Concept\FormRelationships;
use App\Video\Inspiration\ExcludedContext;
use App\Video\Inspiration\InspirationBrief;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class NestedExcludedContextTest extends TestCase
{
    /** @param array<string, mixed> $identity */
    private function violationsFor(array $identity): array
    {
        $concept = new CreativeConcept(
            'One mass carries the whole silhouette.',
            $identity,
            [],
            [],
            new FormRelationships(
                'A single sheer runs bow to stern.',
                'The volume swells amidships.',
                'Features are recessed into the envelope.',
            ),
        );

        $brief = new InspirationBrief(
            ['design_profile'],
            'A source.',
            [],
            [new ExcludedContext('builder', 'Azimut')],
        );

        $validator = new ConceptValidator;
        $method = new ReflectionMethod($validator, 'checkExcludedIdentity');
        $method->setAccessible(true);

        $violations = [];
        $method->invokeArgs($validator, [$concept, $brief, &$violations]);

        return $violations;
    }

    public function test_a_nested_identity_field_is_searched_for_excluded_context(): void
    {
        $this->assertSame(
            ['design_identity.bow.stem contains excluded identity context'],
            $this->violationsFor(['bow' => ['stem' => 'Azimut-style plumb bow']]),
        );
    }

    public function test_a_flat_identity_field_is_still_searched(): void
    {
        $this->assertSame(
            ['design_identity.hull_colour contains excluded identity context'],
            $this->violationsFor(['hull_colour' => 'Azimut graphite']),
        );
    }

    public function test_a_clean_nested_field_raises_nothing(): void
    {
        $this->assertSame([], $this->violationsFor(['bow' => ['stem' => 'near_plumb']]));
    }

    public function test_a_non_string_nested_value_is_skipped_without_crashing(): void
    {
        $this->assertSame([], $this->violationsFor(['bow' => ['rake_degrees' => 12.0, 'stem' => 'near_plumb']]));
    }
}
