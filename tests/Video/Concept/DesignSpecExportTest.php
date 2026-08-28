<?php

namespace Tests\Video\Concept;

use App\Services\Video\CreativeProfileResolver;
use App\Video\Concept\CreativeConcept;
use App\Video\Concept\CreativeConceptParser;
use App\Video\Inspiration\CategoryCreativeProfile;
use InvalidArgumentException;
use Tests\TestCase;

class DesignSpecExportTest extends TestCase
{
    private const INVARIANTS = [
        'stern_geometry', 'continuous_sheer', 'opening_layout', 'bow_geometry', 'superstructure_envelope',
    ];

    private function profile(): CategoryCreativeProfile
    {
        return (new CreativeProfileResolver)->resolve('superyacht');
    }

    private function concept(): CreativeConcept
    {
        $identity = [
            'design_length_m' => 120,
            'design_beam_m' => 17.5,
            'length_to_beam_ratio' => 6.9,
            'design_draft_m' => 4.2,
            'visible_freeboard_at_midships_m' => 3.4,
            'typical_deck_to_deck_height_m' => 3.1,
            'visible_deck_tiers' => 4,
            'bow' => [
                'stem' => 'near_plumb', 'rake_degrees' => 8, 'waterline_entry' => 'fine',
                'forefoot' => 'continuous_convex', 'chine' => 'hard_to_midships',
            ],
            'hull' => [
                'sheer' => 'continuous_gentle_rise_toward_bow',
                'midbody' => 'slender_displacement', 'keel' => 'continuous_central_baseline',
            ],
            'stern' => [
                'transom' => 'plumb_full_beam', 'platform' => 'recessed_waterline',
                'transom_face' => 'vertical glazed panel',
            ],
            'superstructure' => [
                'envelope' => 'faceted_continuous_shell', 'massing_position' => 'central_aft',
                'external_read' => 'single_integrated_mass', 'profile_note' => 'shell narrows aft',
            ],
            'openings' => [
                'aperture_bands' => 4, 'distribution' => 'horizontal_ribbon',
                'vertical_extent' => 'mixed_by_zone', 'surface_relationship' => 'flush_recessed',
                'hull_openings' => 'minimal_service',
            ],
            'hull_material' => 'painted steel',
            'superstructure_material' => 'aluminium alloy',
            'hull_colour' => 'graphite grey satin',
            'boot_stripe_colour' => 'narrow platinum stripe',
            'superstructure_colour' => 'pale titanium white',
        ];

        return (new CreativeConceptParser)->parse(json_encode([
            'design_thesis' => 'One faceted shell tapers continuously from bow to transom.',
            'design_identity' => $identity,
            'form_relationships' => [
                'governing_line' => 'A single sheer line ties hull and superstructure.',
                'massing_rhythm' => 'The envelope swells amidships and narrows aft.',
                'feature_integration' => 'Features are recessed into the shared shell.',
            ],
            'signature_features' => [
                ['description' => 'Continuous glazed ribbon', 'visible_from' => ['side']],
            ],
            'decisions' => [
                ['aspect' => 'size_and_dimensions', 'provenance' => 'inspired', 'decision' => 'Retained 120 metres.'],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    public function test_the_faithful_serialiser_still_round_trips_through_the_parser(): void
    {
        $concept = $this->concept();

        $reparsed = (new CreativeConceptParser)->parse(json_encode($concept->toArray(), JSON_THROW_ON_ERROR));

        $this->assertSame($concept->toArray(), $reparsed->toArray());
    }

    public function test_exporting_a_design_spec_leaves_the_faithful_serialiser_untouched(): void
    {
        $concept = $this->concept();
        $before = $concept->toArray();

        $concept->toDesignSpec($this->profile(), 'superyacht');

        $this->assertSame($before, $concept->toArray());
        $this->assertArrayNotHasKey('schema_version', $concept->toArray());
        $this->assertSame(6.9, $concept->toArray()['design_identity']['length_to_beam_ratio']);
    }

    public function test_laravel_recomputes_the_ratio_instead_of_trusting_the_model(): void
    {
        $spec = $this->concept()->toDesignSpec($this->profile(), 'superyacht');

        $this->assertSame(6.857, $spec['dimensions']['length_to_beam_ratio']);
    }

    public function test_the_export_renames_enum_values_the_profile_declares(): void
    {
        $spec = $this->concept()->toDesignSpec($this->profile(), 'superyacht');
        $geometry = $spec['permanent_geometry'];

        $this->assertSame('continuous_convex_transition', $geometry['bow']['forefoot']);
        $this->assertSame('hard_chine_to_midships', $geometry['bow']['chine']);
        $this->assertSame('plumb_full_beam_transom', $geometry['stern']['type']);
        $this->assertSame('integrated_recessed_waterline_platform', $geometry['stern']['platform']);
        $this->assertSame('horizontal_flush_ribbon_apertures', $geometry['openings']['language']);
        $this->assertSame('flush_recessed', $geometry['openings']['configuration']);
        $this->assertSame(4, $geometry['openings']['superstructure_bands']);
        $this->assertSame(4, $geometry['superstructure']['enclosed_deck_levels']);
    }

    public function test_an_alias_naming_a_value_outside_its_enum_stops_the_profile_from_being_built(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maps raked_full_beam, which is not one of its enum values');

        $this->profileWithExport([
            'schema_version' => '1.0',
            'invariants' => self::INVARIANTS,
            'export_aliases' => ['stern.transom' => ['raked_full_beam' => 'raked_full_beam_transom']],
        ]);
    }

    public function test_a_profile_that_pins_too_few_invariants_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must declare at least 5 invariants');

        $this->profileWithExport([
            'schema_version' => '1.0',
            'invariants' => ['stern_geometry', 'continuous_sheer', 'opening_layout', 'bow_geometry'],
            'export_aliases' => [],
        ]);
    }

    public function test_the_shipped_profile_pins_enough_invariants(): void
    {
        $this->assertGreaterThanOrEqual(5, count($this->profile()->designSpecExport['invariants']));
    }

    /** @param array<string, mixed> $export */
    private function profileWithExport(array $export): CategoryCreativeProfile
    {
        return new CategoryCreativeProfile(
            'test_profile', 'Design something new.', ['design_profile'], ['size'], ['owner'],
            ['stern' => ['type' => 'object', 'fields' => [
                'transom' => ['type' => 'enum', 'values' => ['plumb_full_beam']],
            ]]],
            'Mission.', [], [], [], [], [], [],
            $export,
        );
    }

    public function test_a_slot_the_model_did_not_fill_is_absent_rather_than_null(): void
    {
        $source = $this->concept();
        $identity = $source->designIdentity;
        unset($identity['boot_stripe_colour'], $identity['design_draft_m']);

        $spec = (new CreativeConcept(
            'A thesis.', $identity, [], [], $source->formRelationships,
        ))->toDesignSpec($this->profile(), 'superyacht');

        $this->assertArrayNotHasKey('boot_stripe', $spec['finished_materials']);
        $this->assertArrayNotHasKey('draft_m', $spec['dimensions']);
        $this->assertNotContains(null, $spec['dimensions'], 'DesignSpec must not carry null placeholders.');
    }

    public function test_the_envelope_comes_from_the_profile_and_the_article_not_from_the_model(): void
    {
        $spec = $this->concept()->toDesignSpec($this->profile(), 'superyacht');

        $this->assertSame('1.0', $spec['schema_version']);
        $this->assertSame('superyacht', $spec['object_type']);
        $this->assertContains('enclosed_deck_level_count', $spec['invariants']);
        $this->assertCount(7, $spec['invariants']);
    }
}
