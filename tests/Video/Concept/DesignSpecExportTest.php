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
    /**
     * Truong DesignSpec xuat ra ma phuong_an_1.md khong khai. Ghim lai: neu de
     * "thua" tu do thi ai them truong moi cung khong ai biet.
     */
    private const EXTRA_BEYOND_THE_CONTRACT = [
        'finished_materials.boot_stripe',
        'finished_materials.boot_stripe.colour',
        'permanent_geometry.openings.vertical_extent',
        'permanent_geometry.stern.transom_face',
        'permanent_geometry.superstructure.profile_note',
    ];

    private const INVARIANTS = [
        'stern_geometry', 'continuous_sheer', 'opening_layout', 'bow_geometry', 'superstructure_envelope',
    ];

    private function profile(): CategoryCreativeProfile
    {
        return (new CreativeProfileResolver)->resolve('yacht');
    }

    private function concept(): CreativeConcept
    {
        $profile = $this->profile();

        // Dung tu chinh profile: them mot khe vao hop dong thi fixture tu co,
        // khong phai nho sua tay va khong bao gio loi thoi.
        $slotValue = function (array $spec) use (&$slotValue) {
            return match ($spec['type']) {
                'integer' => (int) $spec['min'],
                'number' => (float) $spec['min'],
                'enum' => $spec['values'][0],
                'boolean' => true,
                'object' => array_map($slotValue, $spec['fields']),
                default => 'compact technical description',
            };
        };

        $identity = array_map($slotValue, $profile->identitySlots);
        $identity['design_length_m'] = 120.0;
        $identity['design_beam_m'] = 17.5;
        $identity['length_to_beam_ratio'] = 6.9;
        $identity['visible_deck_tiers'] = 4;
        $identity['openings']['aperture_bands'] = 4;
        // derivedHullType() doc chu kim loai o day.
        $identity['hull_material'] = 'welded marine grade steel';

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

        $concept->toDesignSpec($this->profile(), 'yacht');

        $this->assertSame($before, $concept->toArray());
        $this->assertArrayNotHasKey('schema_version', $concept->toArray());
        $this->assertSame(6.9, $concept->toArray()['design_identity']['length_to_beam_ratio']);
    }

    public function test_laravel_recomputes_the_ratio_instead_of_trusting_the_model(): void
    {
        $spec = $this->concept()->toDesignSpec($this->profile(), 'yacht');

        $this->assertSame(6.857, $spec['dimensions']['length_to_beam_ratio']);
    }

    public function test_the_export_renames_enum_values_the_profile_declares(): void
    {
        $spec = $this->concept()->toDesignSpec($this->profile(), 'yacht');
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
        ))->toDesignSpec($this->profile(), 'yacht');

        $this->assertArrayNotHasKey('boot_stripe', $spec['finished_materials']);
        $this->assertArrayNotHasKey('draft_m', $spec['dimensions']);
        $this->assertNotContains(null, $spec['dimensions'], 'DesignSpec must not carry null placeholders.');
    }

    public function test_the_envelope_comes_from_the_profile_and_the_article_not_from_the_model(): void
    {
        $spec = $this->concept()->toDesignSpec($this->profile(), 'yacht');

        $this->assertSame('1.0', $spec['schema_version']);
        $this->assertSame('yacht', $spec['object_type']);
        $this->assertContains('enclosed_deck_level_count', $spec['invariants']);
        $this->assertCount(7, $spec['invariants']);
    }

    /**
     * Hop dong that nam trong docs/video/phuong_an_1.md. Doi chieu voi no chu
     * khong voi mot danh sach khoa viet tay o day — danh sach viet tay troi
     * cung luc voi code, con tai lieu thi khong.
     */
    public function test_the_export_covers_every_path_the_contract_document_names(): void
    {
        $contract = $this->contractPaths();
        $exported = $this->pathsOf($this->concept()->toDesignSpec($this->profile(), 'yacht'));

        $this->assertSame([], array_values(array_diff($contract, $exported)),
            'DesignSpec thieu duong dan ma phuong_an_1.md khai.');

        $this->assertSame(self::EXTRA_BEYOND_THE_CONTRACT, array_values(array_diff($exported, $contract)),
            'DesignSpec xuat them duong dan chua duoc ghim — them truong phai la viec co y.');
    }

    /** @return list<string> */
    private function contractPaths(): array
    {
        $document = base_path('docs/video/phuong_an_1.md');
        $this->assertFileExists($document);

        $found = preg_match('/## JSON contract\s*
\s*```json
(.*?)
```/s', (string) file_get_contents($document), $match);

        $this->assertSame(1, $found,
            'Khong tim thay khoi "## JSON contract" trong phuong_an_1.md — tai lieu doi dinh dang, khong phai code sai.');

        return $this->pathsOf(json_decode($match[1], true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<string>
     */
    private function pathsOf(array $value, string $prefix = ''): array
    {
        $paths = [];

        foreach ($value as $key => $inner) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $paths[] = $path;

            if (is_array($inner) && ! array_is_list($inner)) {
                $paths = [...$paths, ...$this->pathsOf($inner, $path)];
            }
        }

        sort($paths);

        return $paths;
    }
}
