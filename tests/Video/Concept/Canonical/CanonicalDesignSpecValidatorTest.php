<?php

namespace Tests\Video\Concept\Canonical;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Validation\CanonicalCrossFieldValidator;
use App\Video\Concept\Validation\CanonicalDesignSpecValidator;
use App\Video\Concept\Validation\CanonicalPathValidator;
use App\Video\Concept\Validation\CanonicalProvenanceValidator;
use App\Video\Inspiration\ExcludedContext;
use App\Video\Inspiration\InspirationBrief;
use App\Video\Inspiration\SourceInsight;
use App\Video\Profiles\CategoryCreativeProfile;
use App\Video\Profiles\CategoryCreativeProfileRegistry;
use App\Video\Profiles\CategoryCreativeProfileResolver;
use App\Video\Profiles\Validation\CategorySemanticValidatorRegistry;
use App\Video\Profiles\Validation\MarineVesselSemanticValidator;
use App\Video\Profiles\Validation\ProfileCompatibilityValidator;
use Tests\TestCase;

class CanonicalDesignSpecValidatorTest extends TestCase
{
    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'schema_version' => '1.0',
            'object_type' => 'yacht',
            'design_thesis' => ['text' => 'One shell tapers aft.', 'role' => 'soft_design_guidance'],
            'identity' => ['subject_class' => 'marine_vessel', 'identity_basis' => ['opening_layout'], 'finish_identity_basis' => []],
            'dimensions' => ['length_m' => 120.0, 'beam_m' => 17.5, 'length_to_beam_ratio' => 6.857],
            'permanent_geometry' => [
                'hull' => ['type' => 'displacement'],
                'bow' => ['stem' => 'near_plumb'],
                'stern' => ['type' => 'plumb_transom'],
                'superstructure' => ['enclosed_deck_levels' => 4, 'primary_tier_count' => 4],
                'openings' => ['superstructure_bands' => 4],
            ],
            'relationships' => [
                ['id' => 'R001', 'type' => 'count',
                    'subject_path' => 'permanent_geometry.superstructure.enclosed_deck_levels', 'value' => 4],
                ['id' => 'R002', 'type' => 'one_to_one',
                    'source_path' => 'permanent_geometry.superstructure.enclosed_deck_levels',
                    'target_path' => 'permanent_geometry.openings.superstructure_bands'],
            ],
            'form_relationships' => ['governing_line' => 'One sheer runs bow to stern.'],
            'finished_materials' => ['hull' => ['material' => 'aluminium']],
            'exclusions' => [['id' => 'E001', 'target_path' => 'permanent_geometry', 'forbid' => 'cantilever']],
            'invariants' => [[
                'id' => 'I001', 'name' => 'enclosed_deck_level_count',
                'source_path' => 'permanent_geometry.superstructure.enclosed_deck_levels',
                'constraint_type' => 'count', 'severity' => 'hard', 'visual_verification' => true,
            ]],
            'provenance' => [
                ['target_path' => 'dimensions', 'origin' => 'inspired', 'source_aspects' => ['size_and_dimensions']],
                ['target_path' => 'permanent_geometry', 'origin' => 'invented', 'source_aspects' => []],
            ],
        ];
    }

    private function brief(): InspirationBrief
    {
        return new InspirationBrief(
            ['design_profile'],
            'A design profile.',
            [new SourceInsight('size_and_dimensions', 'It is long.', ['120 metres'])],
            [new ExcludedContext('owner', 'Jane Doe')],
        );
    }

    private function validator(): CanonicalDesignSpecValidator
    {
        return new CanonicalDesignSpecValidator(
            new CanonicalCrossFieldValidator(new CanonicalPathValidator),
            new CanonicalProvenanceValidator(new CanonicalPathValidator),
            new ProfileCompatibilityValidator(
                new CategoryCreativeProfileResolver(
                    new CategoryCreativeProfileRegistry([$this->profile()]),
                    ['yacht' => 'marine_vessel'],
                    'marine_vessel',
                )
            ),
            new CategorySemanticValidatorRegistry([
                new MarineVesselSemanticValidator,
            ]),
        );
    }

    private function profile(): CategoryCreativeProfile
    {
        return new CategoryCreativeProfile(
            'marine_vessel',
            '1.0',
            __FILE__,
            ['size_and_dimensions'],
        );
    }

    private function input(): ConceptInput
    {
        return new ConceptInput(
            'yacht',
            $this->brief(),
            $this->profile(),
        );
    }

    /** @param array<string, mixed> $payload */
    private function codesFor(array $payload): array
    {
        $result = $this->validator()->validate(
            CanonicalDesignSpec::fromArray($payload),
            $this->input(),
        );

        return array_column($result->toArray(), 'code');
    }

    public function test_a_coherent_spec_raises_nothing(): void
    {
        $result = $this->validator()->validate(
            CanonicalDesignSpec::fromArray($this->payload()),
            $this->input(),
        );

        $this->assertTrue($result->passes(), json_encode($result->toArray(), JSON_PRETTY_PRINT));
    }

    /**
     * Schema chi biet `source_path` la chuoi. Rang buoc "no phai tro vao mot
     * truong CO THAT trong chinh spec" khong dien dat duoc bang JSON Schema —
     * day la ly do tang nay ton tai.
     */
    public function test_an_invariant_pointing_nowhere_is_caught(): void
    {
        $payload = $this->payload();
        $payload['invariants'][0]['source_path'] = 'permanent_geometry.superstructure.no_such_field';

        $this->assertContains('invariant_source_path_missing', $this->codesFor($payload));
    }

    public function test_a_count_that_disagrees_with_the_value_it_points_at_is_caught(): void
    {
        $payload = $this->payload();
        $payload['relationships'][0]['value'] = 7;

        $this->assertContains('count_relationship_mismatch', $this->codesFor($payload));
    }

    /**
     * RANH GIOI CO Y (Phan 3, muc 13): Core KHONG so count(source) voi
     * count(target). Duong dan co the tro toi mot object semantic chu khong
     * phai mot collection, nen doan o day la doan sai. Viec do thuoc profile
     * validator, noi biet hai duong dan ay co phai collection hay khong.
     */
    public function test_core_does_not_compare_the_two_sides_of_a_one_to_one(): void
    {
        $payload = $this->payload();
        $payload['permanent_geometry']['openings']['superstructure_bands'] = 5;

        $this->assertNotContains('one_to_one_mismatch', $this->codesFor($payload));
    }

    public function test_a_relationship_path_that_does_not_resolve_is_caught(): void
    {
        $payload = $this->payload();
        $payload['relationships'][1]['target_path'] = 'permanent_geometry.openings.ghost';

        $this->assertContains('relationship_path_missing', $this->codesFor($payload));
    }

    /**
     * Ca doi so voi v19: `DesignDecision.aspect` la mot NHAN phan loai, khong
     * tro vao dau nen khong the sai. `target_path` tro vao du lieu that, nen
     * no sai duoc — va mot provenance tro vao hu khong thi khong chung minh
     * duoc nguon goc cua bat cu thu gi.
     */
    public function test_a_provenance_target_that_resolves_nowhere_is_caught(): void
    {
        $payload = $this->payload();
        $payload['provenance'][0]['target_path'] = 'nowhere.at.all';

        $this->assertContains('provenance_target_path_missing', $this->codesFor($payload));
    }

    /**
     * Thu v19 khong lam duoc: `SignatureFeature` chi mang mot cau mo ta nen
     * khong co gi de doi chieu. `Invariant` khai constraint_type may doc duoc,
     * nen mot rang buoc DEM tro vao mot cai khong dem duoc phai bi bat.
     */
    public function test_a_count_invariant_pointing_at_something_uncountable_is_caught(): void
    {
        $payload = $this->payload();
        $payload['invariants'][0]['source_path'] = 'permanent_geometry.hull.type';

        $this->assertContains('invariant_constraint_type_mismatch', $this->codesFor($payload));
    }

    /**
     * RANH GIOI CO Y, cung luat voi validateCountRelationships(): node la
     * array/object thi KHONG doan. "Bon boong" co the la so 4, cung co the la
     * list bon phan tu — chon ho mot nghia la dung luat cua rieng mot nganh.
     */
    public function test_core_does_not_judge_a_constraint_type_against_an_object(): void
    {
        $payload = $this->payload();
        $payload['invariants'][0]['source_path'] = 'permanent_geometry.superstructure';

        $this->assertNotContains('invariant_constraint_type_mismatch', $this->codesFor($payload));
    }

    public function test_two_provenance_entries_for_one_target_are_caught(): void
    {
        $payload = $this->payload();
        $payload['provenance'][1]['target_path'] = 'dimensions';

        $this->assertContains('duplicate_provenance_target', $this->codesFor($payload));
    }

    public function test_a_duplicate_relationship_id_is_caught(): void
    {
        $payload = $this->payload();
        $payload['relationships'][1]['id'] = 'R001';

        $this->assertContains('duplicate_relationship_id', $this->codesFor($payload));
    }

    public function test_an_exclusion_target_that_resolves_nowhere_is_caught(): void
    {
        $payload = $this->payload();
        $payload['exclusions'][0]['target_path'] = 'permanent_geometry.ghost';

        $this->assertContains('exclusion_target_path_missing', $this->codesFor($payload));
    }

    public function test_an_inspired_decision_that_names_no_source_is_caught(): void
    {
        $payload = $this->payload();
        $payload['provenance'][0]['source_aspects'] = [];

        $this->assertContains('inspired_provenance_missing_sources', $this->codesFor($payload));
    }

    public function test_an_invented_decision_that_claims_a_source_is_caught(): void
    {
        $payload = $this->payload();
        $payload['provenance'][1]['source_aspects'] = ['size_and_dimensions'];

        $this->assertContains('invented_provenance_has_sources', $this->codesFor($payload));
    }

    /** Aspect phai co that trong brief — khong thi lineage chi la mot chuoi tu do. */
    public function test_a_source_aspect_the_brief_never_carried_is_caught(): void
    {
        $payload = $this->payload();
        $payload['provenance'][0]['source_aspects'] = ['aspect_that_never_existed'];

        $this->assertContains('unknown_source_aspect', $this->codesFor($payload));
    }
}
