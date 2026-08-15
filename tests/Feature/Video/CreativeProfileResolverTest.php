<?php

namespace Tests\Feature\Video;

use App\Services\Video\CreativeProfileResolver;
use App\Video\Concept\ConceptValidator;
use App\Video\Concept\CreativeConcept;
use App\Video\Concept\DesignDecision;
use App\Video\Concept\FormRelationships;
use App\Video\Concept\Provenance;
use App\Video\Concept\SignatureFeature;
use App\Video\Concept\Viewpoint;
use App\Video\Inspiration\InspirationBrief;
use InvalidArgumentException;
use Tests\TestCase;

class CreativeProfileResolverTest extends TestCase
{
    public function test_configured_categories_resolve_the_same_reusable_profile(): void
    {
        $resolver = new CreativeProfileResolver;

        $this->assertSame('luxury_vessel', $resolver->resolve('yacht')?->key);
        $this->assertSame('luxury_vessel', $resolver->resolve('superyacht')?->key);
        $this->assertCount(12, $resolver->resolve('yacht')?->inspectionAspects ?? []);
    }

    public function test_the_shipped_identity_slots_survive_their_own_preflight(): void
    {
        $slots = (new CreativeProfileResolver)->resolve('yacht')?->identitySlots ?? [];

        $this->assertCount(14, $slots);
        $this->assertArrayNotHasKey('hull_vertical_proportions', $slots);

        foreach ($slots as $name => $spec) {
            $this->assertContains($spec['type'], ['text', 'integer', 'number'], "slot {$name}");
        }
    }

    /** @dataProvider verticalSlots */
    public function test_the_vertical_geometry_is_numeric_and_bounded(string $slot, float $min, float $max): void
    {
        // Chieu cao TUNG tang, khong phai tong: mot cau prose tung khai "1.9m
        // freeboard, superstructure height equals exposed hull depth" canh 4
        // tang — 0.475m moi tang. Khai per-deck thi cau do khong noi duoc.
        $slots = (new CreativeProfileResolver)->resolve('yacht')?->identitySlots ?? [];

        $this->assertSame(['type' => 'number', 'min' => $min, 'max' => $max], $slots[$slot]);
    }

    public static function verticalSlots(): array
    {
        return [
            ['design_draft_m', 2.0, 6.0],
            ['visible_freeboard_at_midships_m', 1.5, 6.0],
            ['typical_deck_to_deck_height_m', 2.6, 3.5],
        ];
    }

    public function test_the_profile_declares_an_editorial_length_floor(): void
    {
        // Ba concept truoc ra 74m/72m/72m tu bai nguon 70m. San 75m la quyet
        // dinh bien tap cua ho so nay, khong phai chan gia tri phi ly.
        $slots = (new CreativeProfileResolver)->resolve('yacht')?->identitySlots ?? [];

        $this->assertSame(['type' => 'number', 'min' => 75.0, 'max' => 180.0], $slots['design_length_m']);
        $this->assertSame(
            ['design_length_m', 'length_to_beam_ratio'],
            array_slice(array_keys($slots), 0, 2),
        );
    }

    public function test_a_concept_written_against_the_old_prose_slot_is_refused(): void
    {
        $profile = (new CreativeProfileResolver)->resolve('yacht');

        $identity = [];
        foreach ($profile->identitySlots as $name => $spec) {
            $identity[$name] = match ($spec['type']) {
                'integer' => (int) $spec['min'],
                'number' => (float) $spec['min'],
                default => 'a plain description',
            };
        }

        unset($identity['design_length_m'], $identity['design_draft_m'],
            $identity['visible_freeboard_at_midships_m'], $identity['typical_deck_to_deck_height_m']);
        $identity['hull_vertical_proportions'] = 'low freeboard with balanced hull and superstructure height';

        $concept = new CreativeConcept(
            'One line unifies the whole vessel.',
            $identity,
            [new SignatureFeature('a recessed stern pool', [Viewpoint::RearThreeQuarter])],
            array_map(
                fn (string $aspect) => new DesignDecision($aspect, Provenance::Invented, 'Decided independently.'),
                $profile->inspectionAspects,
            ),
            new FormRelationships('One line.', 'Volumes taper.', 'Features grow from the form.'),
        );

        $violations = (new ConceptValidator)->validate(
            $concept, $profile, new InspirationBrief(['design_profile'], 'A focus.', [], []),
        )->fatalViolations;

        $this->assertContains('design_identity is missing slot design_length_m', $violations);
        $this->assertContains('design_identity is missing slot design_draft_m', $violations);
        $this->assertContains('design_identity is missing slot visible_freeboard_at_midships_m', $violations);
        $this->assertContains('design_identity is missing slot typical_deck_to_deck_height_m', $violations);
        $this->assertContains('design_identity contains unknown slot hull_vertical_proportions', $violations);
    }

    public function test_every_viewpoint_tells_the_designer_where_the_camera_stands(): void
    {
        $profile = (new CreativeProfileResolver)->resolve('yacht');

        $this->assertSame(Viewpoint::values(), array_keys($profile->viewpointGuidance));

        foreach ($profile->viewpointGuidance as $name => $text) {
            $this->assertStringContainsString('waterline', $text, $name);
        }
    }

    public function test_a_profile_without_viewpoint_guidance_is_caught_before_any_model_call(): void
    {
        config(['video.creative_profiles.profiles.luxury_vessel.viewpoint_guidance' => []]);

        $this->expectException(InvalidArgumentException::class);

        (new CreativeProfileResolver)->resolve('yacht')->assertConceptReady();
    }

    public function test_the_shipped_profile_is_ready_for_a_concept_run(): void
    {
        $profile = (new CreativeProfileResolver)->resolve('yacht');

        $profile->assertConceptReady();

        // Invariant là "khác mission của Inspiration", không phải "vắng một từ
        // cụ thể" — khoá câu chữ thì mission hợp lệ sau này vẫn đỏ oan.
        $this->assertNotSame('', trim($profile->conceptMission));
        $this->assertNotSame($profile->mission, $profile->conceptMission);
    }

    public function test_a_profile_missing_its_concept_mission_is_caught_before_any_model_call(): void
    {
        config(['video.creative_profiles.profiles.luxury_vessel.concept_mission' => '   ']);

        $this->expectException(InvalidArgumentException::class);

        (new CreativeProfileResolver)->resolve('yacht')->assertConceptReady();
    }

    public function test_an_unsatisfiable_slot_refuses_to_resolve_before_any_model_call(): void
    {
        config(['video.creative_profiles.profiles.luxury_vessel.identity_slots' => [
            'visible_deck_tiers' => ['type' => 'integer', 'min' => 10, 'max' => 1],
        ]]);

        $this->expectException(InvalidArgumentException::class);

        (new CreativeProfileResolver)->resolve('yacht');
    }

    public function test_unconfigured_category_resolves_to_null(): void
    {
        // null CHƯA phải fail-closed: nó trao cho người gọi đúng cái
        // `$profile !== null ? creative() : evidenceBound()`. Ba mode
        // Creative/EvidenceBound/Disabled chưa được cài — chưa có chỗ gọi nên
        // đổi kiểu trả về sau vẫn không phá gì.
        $this->assertNull((new CreativeProfileResolver)->resolve('unconfigured-category'));
    }
}
