<?php

namespace Tests\Video\Concept\Canonical;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\ConceptInput;
use App\Video\Inspiration\InspirationBrief;
use App\Video\Profiles\CategoryCreativeProfile;
use App\Video\Profiles\CategoryCreativeProfileRegistry;
use App\Video\Profiles\CategoryCreativeProfileResolver;
use App\Video\Profiles\ProfileSystemIntegrityChecker;
use App\Video\Profiles\Validation\CategorySemanticValidatorRegistry;
use App\Video\Profiles\Validation\GenericPhysicalObjectSemanticValidator;
use App\Video\Profiles\Validation\MarineVesselSemanticValidator;
use App\Video\Profiles\Validation\ProfileCompatibilityValidator;
use RuntimeException;
use Tests\TestCase;

class CategorySemanticValidationTest extends TestCase
{
    private function profile(string $key): CategoryCreativeProfile
    {
        return new CategoryCreativeProfile(
            $key,
            '1.0',
            __FILE__,
            ['size_and_dimensions'],
        );
    }

    private function brief(): InspirationBrief
    {
        return new InspirationBrief(
            ['design_profile'],
            'A design profile.',
            [],
            [],
        );
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function spec(array $overrides = []): CanonicalDesignSpec
    {
        return CanonicalDesignSpec::fromArray(array_replace_recursive([
            'schema_version' => '1.0',
            'object_type' => 'superyacht',
            'design_thesis' => ['text' => 'One shell tapers aft.', 'role' => 'soft_design_guidance'],
            'identity' => ['subject_class' => 'marine_vessel', 'identity_basis' => ['opening_layout']],
            'dimensions' => ['length_m' => 120.0, 'beam_m' => 20.0, 'length_to_beam_ratio' => 6.0],
            'permanent_geometry' => [
                'hull' => ['type' => 'displacement', 'sheer' => 'continuous'],
                'bow' => ['stem' => 'near_plumb', 'waterline_entry' => 'fine'],
                'stern' => ['type' => 'plumb_transom'],
                'superstructure' => ['primary_tier_count' => 4, 'massing' => 'central_aft'],
            ],
            'relationships' => [[
                'id' => 'R001',
                'type' => 'count',
                'subject_path' => 'permanent_geometry.superstructure.primary_tier_count',
                'value' => 4,
            ]],
            'form_relationships' => ['hull_to_superstructure' => 'One sheer runs bow to stern.'],
            'finished_materials' => ['hull' => 'aluminium'],
            'exclusions' => [['id' => 'E001', 'target_path' => 'permanent_geometry', 'forbid' => 'cantilever']],
            'invariants' => [[
                'id' => 'I001',
                'name' => 'tier_count',
                'source_path' => 'permanent_geometry.superstructure.primary_tier_count',
                'constraint_type' => 'count',
                'severity' => 'hard',
                'visual_verification' => true,
            ]],
            'provenance' => [[
                'target_path' => 'dimensions',
                'origin' => 'invented',
                'source_aspects' => [],
            ]],
        ], $overrides));
    }

    private function marineInput(?CategoryCreativeProfile $profile = null): ConceptInput
    {
        return new ConceptInput(
            'superyacht',
            $this->brief(),
            $profile ?? $this->profile('marine_vessel'),
        );
    }

    public function test_marine_validator_accepts_consistent_length_to_beam_ratio(): void
    {
        $result = (new MarineVesselSemanticValidator)
            ->validate(
                $this->spec(),
                $this->marineInput(),
            );

        $this->assertTrue($result->passes());
    }

    public function test_marine_validator_rejects_inconsistent_length_to_beam_ratio(): void
    {
        $result = (new MarineVesselSemanticValidator)
            ->validate(
                $this->spec([
                    'dimensions' => [
                        'length_m' => 120.0,
                        'beam_m' => 20.0,
                        'length_to_beam_ratio' => 5.1,
                    ],
                ]),
                $this->marineInput(),
            );

        $this->assertTrue($result->fails());
        $this->assertSame('marine.dimensions.length_to_beam_ratio_mismatch', $result->errors[0]->code);
        $this->assertSame(6.0, $result->errors[0]->expected);
        $this->assertSame(5.1, $result->errors[0]->actual);
    }

    public function test_profile_compatibility_rejects_a_mismatched_profile(): void
    {
        $resolver = new CategoryCreativeProfileResolver(
            new CategoryCreativeProfileRegistry([
                $this->profile('generic_physical_object'),
                $this->profile('marine_vessel'),
                $this->profile('aircraft'),
            ]),
            ['superyacht' => 'marine_vessel'],
            'generic_physical_object',
        );

        $result = (new ProfileCompatibilityValidator($resolver))
            ->validate(
                $this->marineInput($this->profile('aircraft'))
            );

        $this->assertTrue($result->fails());
        $this->assertSame('profile.object_type_mismatch', $result->errors[0]->code);
        $this->assertSame('marine_vessel', $result->errors[0]->expected);
        $this->assertSame('aircraft', $result->errors[0]->actual);
    }

    public function test_registry_does_not_fallback_when_a_profile_validator_is_missing(): void
    {
        $registry = new CategorySemanticValidatorRegistry([
            new GenericPhysicalObjectSemanticValidator,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No semantic validator registered');

        $registry->forProfile($this->profile('marine_vessel'));
    }

    public function test_profile_system_integrity_requires_a_validator_for_every_profile(): void
    {
        $checker = new ProfileSystemIntegrityChecker(
            new CategoryCreativeProfileRegistry([
                $this->profile('generic_physical_object'),
                $this->profile('marine_vessel'),
            ]),
            new CategorySemanticValidatorRegistry([
                new GenericPhysicalObjectSemanticValidator,
            ]),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('marine_vessel@1.0');

        $checker->assertValid();
    }
}
