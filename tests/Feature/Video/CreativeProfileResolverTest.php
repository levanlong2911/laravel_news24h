<?php

namespace Tests\Feature\Video;

use App\Services\Video\CreativeProfileResolver;
use App\Video\Concept\ClaudeConceptDesigner;
use App\Video\Concept\ConceptValidator;
use App\Video\Concept\CreativeConcept;
use App\Video\Concept\DesignDecision;
use App\Video\Concept\FormRelationships;
use App\Video\Concept\Provenance;
use App\Video\Concept\SignatureFeature;
use App\Video\Concept\Viewpoint;
use App\Video\Inspiration\InspirationBrief;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Llm\LlmResponse;
use InvalidArgumentException;
use Tests\TestCase;

class CreativeProfileResolverTest extends TestCase
{
    public function test_configured_categories_resolve_the_same_reusable_profile(): void
    {
        $resolver = new CreativeProfileResolver;

        $this->assertSame('luxury_vessel', $resolver->resolve('yacht')?->key);
        $this->assertSame('luxury_vessel', $resolver->resolve('superyacht')?->key);
        $this->assertCount(11, $resolver->resolve('yacht')?->inspectionAspects ?? []);
    }

    public function test_the_shipped_identity_slots_survive_their_own_preflight(): void
    {
        $slots = (new CreativeProfileResolver)->resolve('yacht')?->identitySlots ?? [];

        $this->assertCount(18, $slots);
        $this->assertArrayNotHasKey('hull_vertical_proportions', $slots);

        foreach ($slots as $name => $spec) {
            $this->assertContains($spec['type'], ['text', 'integer', 'number', 'object'], "slot {$name}");
        }
    }

    public function test_the_boot_stripe_is_a_declared_slot_not_a_guess_by_the_image_model(): void
    {
        // Anh dau tien co mot signature feature noi '...separating hull colour from
        // boot stripe', trong khi COLOR PALETTE chi khai hai mau — gpt-image-2 tu
        // bia mau dai nuoc. Khe nay dong lo hong do o dung nguon: concept.
        $slot = (new CreativeProfileResolver)->resolve('yacht')?->identitySlots['boot_stripe_colour'] ?? null;

        $this->assertNotNull($slot);
        $this->assertSame('text', $slot['type']);
        $this->assertStringContainsString('placement', $slot['guidance']);
    }

    public function test_a_two_part_slot_gets_a_budget_wide_enough_for_both_parts(): void
    {
        // Ngan sach tu tu suy tu max_length: intdiv(max_length, 7). Khe nay doi
        // MAU va VI TRI, nen 60 (8 tu) la vua khit khong con cho xoay — cac khe
        // hai-viec khac deu 120.
        $slot = (new CreativeProfileResolver)->resolve('yacht')?->identitySlots['boot_stripe_colour'] ?? [];

        $this->assertGreaterThanOrEqual(11, intdiv((int) $slot['max_length'], 7));
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
        $slots = (new CreativeProfileResolver)->resolve('yacht')?->identitySlots ?? [];
        $slot = $slots['design_length_m'];

        $this->assertSame('number', $slot['type']);
        $this->assertSame(100.0, $slot['min']);
        $this->assertSame(180.0, $slot['max']);
        $this->assertStringContainsString('scale', $slot['guidance']);
        $this->assertStringContainsString('100 m', $slot['guidance']);
        $this->assertSame(
            ['design_length_m', 'design_beam_m', 'length_to_beam_ratio'],
            array_slice(array_keys($slots), 0, 3),
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
        $this->assertNull((new CreativeProfileResolver)->resolve('unconfigured-category'));
    }

    public function test_the_shipped_profile_names_the_forms_that_already_failed(): void
    {
        $profile = (new CreativeProfileResolver)->resolve('yacht');

        $this->assertContains(
            'independent horizontal slabs stacked like a wedding cake',
            $profile->conceptAntipatterns,
        );
        $this->assertCount(4, $profile->conceptAntipatterns);
    }

    public function test_the_counted_slot_says_what_its_number_is_for(): void
    {
        $profile = (new CreativeProfileResolver)->resolve('yacht');

        $this->assertStringContainsString(
            'verification count',
            $profile->identitySlots['visible_deck_tiers']['guidance'],
        );
    }

    public function test_the_instruction_sent_to_sonnet_is_locked_to_its_version(): void
    {
        $method = new \ReflectionMethod(ClaudeConceptDesigner::class, 'instruction');
        $method->setAccessible(true);

        $instruction = $method->invoke(
            new ClaudeConceptDesigner(new class implements LlmClient
            {
                public function complete(LlmRequest $request): LlmResponse
                {
                    throw new \LogicException('instruction() khong duoc goi model');
                }
            }),
            (new CreativeProfileResolver)->resolve('yacht'),
        );

        $this->assertSame('concept-v19', ClaudeConceptDesigner::INSTRUCTION_VERSION);
        $this->assertSame(
            '660dd77c03bff9a51557db484660ad4a10b4e705e8cd59de3f93cec672bc0363',
            hash('sha256', $instruction),
            'Instruction doi ma version khong doi.',
        );
    }

    public function test_a_mistyped_forbidden_form_is_caught_while_building_the_profile(): void
    {
        config(['video.creative_profiles.profiles.luxury_vessel.concept_antipatterns' => ['ok', null]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain only non-empty strings');

        (new CreativeProfileResolver)->resolve('yacht');
    }

    public function test_the_same_forbidden_form_twice_is_refused(): void
    {
        // In hai lan lam model tuong day la hai rang buoc khac nhau.
        config(['video.creative_profiles.profiles.luxury_vessel.concept_antipatterns' => ['x', 'x']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be unique');

        (new CreativeProfileResolver)->resolve('yacht');
    }

    public function test_a_category_that_forbids_nothing_still_resolves(): void
    {
        config(['video.creative_profiles.profiles.luxury_vessel.concept_antipatterns' => []]);

        $this->assertSame([], (new CreativeProfileResolver)->resolve('yacht')->conceptAntipatterns);
    }

    /** @dataProvider lengthsAroundTheFloor */
    public function test_the_floor_is_a_hard_gate_not_a_hint(float $length, bool $refused): void
    {
        // Guidance day model ve dung phia. Validator van phai TU CHOI neu no
        // khong nghe — tuyet doi khong lang le keo 99.9 len 100, vi lam vay la
        // sua cau tra loi cua model roi gia vo no tra loi dung.
        $profile = (new CreativeProfileResolver)->resolve('yacht');

        $identity = [];
        foreach ($profile->identitySlots as $name => $spec) {
            $identity[$name] = match ($spec['type']) {
                'integer' => (int) $spec['min'],
                'number' => (float) $spec['min'],
                default => 'a plain description',
            };
        }
        $identity['design_length_m'] = $length;

        $violations = (new ConceptValidator)->validate(
            new CreativeConcept(
                'One line unifies the whole vessel.',
                $identity,
                [new SignatureFeature('a recessed stern pool', [Viewpoint::RearThreeQuarter])],
                array_map(
                    fn (string $aspect) => new DesignDecision($aspect, Provenance::Invented, 'Decided independently.'),
                    $profile->inspectionAspects,
                ),
                new FormRelationships('One line.', 'Volumes taper.', 'Features grow from the form.'),
            ),
            $profile,
            new InspirationBrief(['design_profile'], 'A focus.', [], []),
        )->fatalViolations;

        $offending = array_filter($violations, fn (string $v) => str_starts_with($v, 'design_identity.design_length_m'));

        $this->assertSame($refused, $offending !== [], implode('; ', $violations));
    }

    /** @return array<string, array{float, bool}> */
    public static function lengthsAroundTheFloor(): array
    {
        return [
            'just under the floor' => [99.9, true],
            'exactly on the floor' => [100.0, false],
            'the source article value' => [36.6, true],
            'the value the old floor allowed' => [78.0, true],
        ];
    }
}
