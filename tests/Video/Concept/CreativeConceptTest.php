<?php

namespace Tests\Video\Concept;

use App\Video\Concept\ConceptValidator;
use App\Video\Concept\CreativeConcept;
use App\Video\Concept\CreativeConceptParser;
use App\Video\Concept\DesignDecision;
use App\Video\Concept\FormRelationships;
use App\Video\Concept\InvalidCreativeConcept;
use App\Video\Concept\Provenance;
use App\Video\Concept\SignatureFeature;
use App\Video\Concept\Viewpoint;
use App\Video\Inspiration\CategoryCreativeProfile;
use App\Video\Inspiration\ExcludedContext;
use App\Video\Inspiration\InspirationBrief;
use App\Video\Inspiration\SourceInsight;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CreativeConceptTest extends TestCase
{
    private function profile(): CategoryCreativeProfile
    {
        return new CategoryCreativeProfile(
            'test_profile',
            'Design something new.',
            ['design_profile'],
            ['size', 'materials'],
            ['owner', 'product_name'],
            [
                'ratio' => ['type' => 'number', 'min' => 2.0, 'max' => 12.0],
                'tiers' => ['type' => 'integer', 'min' => 1, 'max' => 10],
                'bow' => ['type' => 'text', 'max_length' => 30],
            ],
            'Design something that has never existed.',
        );
    }

    /** @param list<SourceInsight> $insights */
    private function brief(array $insights = [], array $excluded = []): InspirationBrief
    {
        return new InspirationBrief(['design_profile'], 'A focus.', $insights, $excluded);
    }

    /** @param array<string, mixed> $identity */
    private function concept(array $identity = [], ?array $decisions = null, ?array $features = null): CreativeConcept
    {
        return new CreativeConcept(
            'A quiet vessel organised around one level.',
            $identity ?: ['ratio' => 6.0, 'tiers' => 4, 'bow' => 'near-vertical'],
            $features ?? [new SignatureFeature('a terraced stern', [Viewpoint::Side])],
            $decisions ?? [
                new DesignDecision('size', Provenance::Invented, 'Longer than the source.'),
                new DesignDecision('materials', Provenance::Invented, 'Steel and oak.'),
            ],
            new FormRelationships(
                'One line connects the object from front to rear.',
                'Volumes taper progressively rather than stacking independently.',
                'Signature features grow from the main structure.',
            ),
        );
    }

    // ---- Preflight cấu hình: nổ TRƯỚC khi có cú gọi model nào ----

    public function test_a_slot_type_outside_the_closed_list_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CategoryCreativeProfile('p', 'm', ['x'], ['size'], ['owner'], ['bow' => ['type' => 'boolean']]);
    }

    public function test_a_text_slot_without_a_positive_max_length_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CategoryCreativeProfile('p', 'm', ['x'], ['size'], ['owner'], ['bow' => ['type' => 'text', 'max_length' => 0]]);
    }

    public function test_a_numeric_slot_with_min_above_max_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CategoryCreativeProfile('p', 'm', ['x'], ['size'], ['owner'], ['t' => ['type' => 'integer', 'min' => 9, 'max' => 2]]);
    }

    public function test_a_slot_name_that_is_not_a_slug_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CategoryCreativeProfile('p', 'm', ['x'], ['size'], ['owner'], ['Bow Profile' => ['type' => 'text', 'max_length' => 10]]);
    }

    /** @dataProvider nonFiniteBounds */
    public function test_a_non_finite_bound_is_refused(float $min, float $max): void
    {
        // NAN lọt qua thì `min > max` luôn false — cấu hình vô nghiệm mà im lặng.
        $this->expectException(InvalidArgumentException::class);

        new CategoryCreativeProfile('p', 'm', ['x'], ['size'], ['owner'], ['t' => ['type' => 'number', 'min' => $min, 'max' => $max]]);
    }

    public static function nonFiniteBounds(): array
    {
        return [[NAN, 10.0], [1.0, INF], [-INF, 10.0]];
    }

    public function test_a_mistyped_spec_key_is_refused_instead_of_ignored(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CategoryCreativeProfile('p', 'm', ['x'], ['size'], ['owner'], ['t' => ['type' => 'integer', 'min' => 1, 'max' => 10, 'maximum' => 20]]);
    }

    public function test_a_text_slot_carrying_numeric_bounds_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CategoryCreativeProfile('p', 'm', ['x'], ['size'], ['owner'], ['t' => ['type' => 'text', 'max_length' => 10, 'min' => 1]]);
    }

    public function test_a_profile_with_no_identity_slot_cannot_validate_a_concept(): void
    {
        // Không có khe nào thì `design_identity: {}` hợp lệ — concept rỗng danh
        // tính vẫn đi tiếp tới ảnh neo.
        $profile = new CategoryCreativeProfile('p', 'm', ['x'], ['size', 'materials'], ['owner']);

        $this->expectException(InvalidArgumentException::class);

        (new ConceptValidator)->violations($this->concept(), $profile, $this->brief());
    }

    public function test_a_profile_without_a_concept_mission_cannot_validate_a_concept(): void
    {
        // `mission` của Inspiration bảo model soạn brief — dùng lại nó sẽ mâu
        // thuẫn với phần còn lại của instruction.
        $profile = new CategoryCreativeProfile('p', 'm', ['x'], ['size', 'materials'], ['owner'],
            ['bow' => ['type' => 'text', 'max_length' => 30]]);

        $this->expectException(InvalidArgumentException::class);

        (new ConceptValidator)->violations($this->concept(), $profile, $this->brief());
    }

    // ---- Parser ----

    public function test_the_parser_rejects_a_concept_id_the_model_must_not_invent(): void
    {
        $this->expectException(InvalidCreativeConcept::class);

        (new CreativeConceptParser)->parse('{"concept_id":"x","design_thesis":"a","design_identity":{},"signature_features":[],"decisions":[]}');
    }

    public function test_the_parser_rejects_an_unknown_viewpoint(): void
    {
        $this->expectException(InvalidCreativeConcept::class);

        (new CreativeConceptParser)->parse(<<<'JSON'
        {"design_thesis":"a","design_identity":{},
         "signature_features":[{"description":"x","visible_from":["from_above"]}],
         "decisions":[]}
        JSON);
    }

    public function test_the_parser_unwraps_fences_and_keeps_the_supplied_values(): void
    {
        $concept = (new CreativeConceptParser)->parse(<<<'JSON'
        ```json
        {"design_thesis":"A quiet vessel.",
         "design_identity":{"ratio":6.0,"tiers":4,"bow":"near-vertical"},
         "form_relationships":{"governing_line":"one line","massing_rhythm":"progressive taper","feature_integration":"features grow from the structure"},
         "signature_features":[{"description":"a terraced stern","visible_from":["side","rear_three_quarter"]}],
         "decisions":[{"aspect":"size","provenance":"invented","decision":"Longer."}]}
        ```
        JSON);

        $this->assertSame(6.0, $concept->designIdentity['ratio']);
        $this->assertSame(Provenance::Invented, $concept->decisions[0]->provenance);
        $this->assertTrue($concept->signatureFeatures[0]->isVisibleFrom(Viewpoint::Side));
        $this->assertFalse($concept->signatureFeatures[0]->isVisibleFrom(Viewpoint::FrontThreeQuarter));
    }

    // ---- Validator: khe danh tính ----

    public function test_a_complete_concept_passes(): void
    {
        $this->assertSame([], (new ConceptValidator)->violations($this->concept(), $this->profile(), $this->brief()));
    }

    public function test_a_missing_and_an_unknown_slot_are_both_named(): void
    {
        // Thiếu một thừa một vẫn cùng số lượng — phải so TẬP KHOÁ.
        $concept = $this->concept(['ratio' => 6.0, 'tiers' => 4, 'stern' => 'terraced']);

        $violations = (new ConceptValidator)->violations($concept, $this->profile(), $this->brief());

        $this->assertContains('design_identity is missing slot bow', $violations);
        $this->assertContains('design_identity contains unknown slot stern', $violations);
    }

    /** @dataProvider notIntegers */
    public function test_an_integer_slot_refuses_anything_that_is_not_an_int(mixed $value): void
    {
        $concept = $this->concept(['ratio' => 6.0, 'tiers' => $value, 'bow' => 'near-vertical']);

        $this->assertContains(
            'design_identity.tiers must be an integer',
            (new ConceptValidator)->violations($concept, $this->profile(), $this->brief()),
        );
    }

    public static function notIntegers(): array
    {
        return [[true], [4.0], ['4'], [null], [[4]]];
    }

    /** @dataProvider notNumbers */
    public function test_a_number_slot_refuses_booleans_and_non_finite_values(mixed $value): void
    {
        $concept = $this->concept(['ratio' => $value, 'tiers' => 4, 'bow' => 'near-vertical']);

        $this->assertContains(
            'design_identity.ratio must be a finite number',
            (new ConceptValidator)->violations($concept, $this->profile(), $this->brief()),
        );
    }

    public static function notNumbers(): array
    {
        return [[true], [NAN], [INF], ['6.0'], [null]];
    }

    public function test_a_number_slot_accepts_an_int_within_range(): void
    {
        $concept = $this->concept(['ratio' => 6, 'tiers' => 4, 'bow' => 'near-vertical']);

        $this->assertSame([], (new ConceptValidator)->violations($concept, $this->profile(), $this->brief()));
    }

    public function test_a_slot_outside_its_own_bounds_is_rejected(): void
    {
        // Ngưỡng là luật của KHE, không phải của mọi integer.
        $concept = $this->concept(['ratio' => 6.0, 'tiers' => 11, 'bow' => 'near-vertical']);

        $this->assertContains(
            'design_identity.tiers must be between 1 and 10',
            (new ConceptValidator)->violations($concept, $this->profile(), $this->brief()),
        );
    }

    public function test_a_text_slot_keeps_its_original_content_but_must_not_be_blank(): void
    {
        $concept = $this->concept(['ratio' => 6.0, 'tiers' => 4, 'bow' => '   ']);

        $this->assertContains(
            'design_identity.bow must be a non-empty string',
            (new ConceptValidator)->violations($concept, $this->profile(), $this->brief()),
        );
    }

    // ---- Validator: decisions ----

    public function test_every_inspection_aspect_must_carry_exactly_one_decision(): void
    {
        $concept = $this->concept([], [new DesignDecision('size', Provenance::Invented, 'Longer.')]);

        $this->assertContains(
            'decisions is missing aspect materials',
            (new ConceptValidator)->violations($concept, $this->profile(), $this->brief()),
        );
    }

    public function test_two_decisions_for_the_same_aspect_are_rejected(): void
    {
        $concept = $this->concept([], [
            new DesignDecision('size', Provenance::Invented, 'Longer.'),
            new DesignDecision('size', Provenance::Invented, 'Also shorter.'),
            new DesignDecision('materials', Provenance::Invented, 'Steel.'),
        ]);

        $this->assertContains(
            'decisions holds more than one entry for aspect size',
            (new ConceptValidator)->violations($concept, $this->profile(), $this->brief()),
        );
    }

    public function test_inspired_requires_the_brief_to_have_covered_that_aspect(): void
    {
        $concept = $this->concept([], [
            new DesignDecision('size', Provenance::Inspired, 'Borrowed the proportion.'),
            new DesignDecision('materials', Provenance::Invented, 'Steel.'),
        ]);

        $this->assertContains(
            'decisions[0] claims inspiration for size, which the brief did not cover',
            (new ConceptValidator)->violations($concept, $this->profile(), $this->brief()),
        );
    }

    public function test_invented_is_allowed_even_where_the_source_had_material(): void
    {
        // Nguồn có nói KHÔNG có nghĩa là buộc phải dùng — đây chính là chỗ
        // Sonnet được phép thiết kế lại.
        $brief = $this->brief([new SourceInsight('materials', 'The hull is steel.', ['steel hull'])]);
        $concept = $this->concept([], [
            new DesignDecision('size', Provenance::Invented, 'Longer.'),
            new DesignDecision('materials', Provenance::Invented, 'Something else entirely.'),
        ]);

        $this->assertSame([], (new ConceptValidator)->violations($concept, $this->profile(), $brief));
    }

    // ---- Validator: identity đã khai ----

    public function test_an_excluded_identity_is_caught_in_every_creative_field(): void
    {
        $brief = $this->brief([], [new ExcludedContext('owner', 'Jane Doe')]);

        $concept = new CreativeConcept(
            'A vessel echoing Jane Doe.',
            ['ratio' => 6.0, 'tiers' => 4, 'bow' => 'the Jane Doe bow'],
            [new SignatureFeature('a stern Jane Doe liked', [Viewpoint::Side])],
            [
                new DesignDecision('size', Provenance::Invented, 'As long as Jane Doe wanted.'),
                new DesignDecision('materials', Provenance::Invented, 'Steel.'),
            ],
            new FormRelationships('A line Jane Doe drew.', 'b', 'c'),
        );

        $violations = (new ConceptValidator)->violations($concept, $this->profile(), $brief);

        $this->assertContains('design_thesis contains excluded identity context', $violations);
        $this->assertContains('design_identity.bow contains excluded identity context', $violations);
        $this->assertContains('signature_features[0].description contains excluded identity context', $violations);
        $this->assertContains('decisions[0].decision contains excluded identity context', $violations);
        $this->assertContains('form_relationships.governing_line contains excluded identity context', $violations);
    }

    /**
     * GIỚI HẠN ĐÃ BIẾT, KHÔNG PHẢI HÀNH VI MONG MUỐN — cùng lỗ với
     * InspirationBriefValidator. Validator chỉ so được với những gì Haiku đã
     * khai trong excluded_context; một thương hiệu không ai khai thì lọt.
     */
    public function test_the_validator_cannot_see_a_brand_nobody_declared(): void
    {
        $concept = $this->concept(['ratio' => 6.0, 'tiers' => 4, 'bow' => 'a Caterpillar edge']);

        $this->assertSame([], (new ConceptValidator)->violations($concept, $this->profile(), $this->brief()));
    }

    // ---- Biên độ dài ----

    /**
     * 260 đo từ lượt Sonnet đã trả tiền: 12 decision dài 156→229 ký tự, nên trần
     * 200 cũ cắt ngang vùng tự nhiên và retry chỉ dồn chi tiết sang chỗ khác.
     *
     * @dataProvider decisionLengths
     */
    public function test_a_decision_is_measured_against_the_260_character_boundary(int $length, bool $accepted): void
    {
        $concept = $this->concept([], [
            new DesignDecision('size', Provenance::Invented, str_repeat('a', $length)),
            new DesignDecision('materials', Provenance::Invented, 'Steel.'),
        ]);

        $violations = (new ConceptValidator)->violations($concept, $this->profile(), $this->brief());

        $accepted
            ? $this->assertSame([], $violations)
            : $this->assertContains('decisions[0].decision exceeds 260 characters', $violations);
    }

    public static function decisionLengths(): array
    {
        return [
            'boundary accepted' => [260, true],
            'one over rejected' => [261, false],
            'observed longest' => [229, true],
        ];
    }

    // ---- Nội dung rỗng dựng thẳng từ DTO, không qua parser ----

    public function test_empty_creative_text_is_rejected_even_when_the_parser_was_bypassed(): void
    {
        $concept = new CreativeConcept(
            '',
            ['ratio' => 6.0, 'tiers' => 4, 'bow' => 'near-vertical'],
            [new SignatureFeature('', [Viewpoint::Side])],
            [
                new DesignDecision('size', Provenance::Invented, ''),
                new DesignDecision('materials', Provenance::Invented, 'Steel.'),
            ],
            new FormRelationships('', 'b', 'c'),
        );

        $violations = (new ConceptValidator)->violations($concept, $this->profile(), $this->brief());

        $this->assertContains('design_thesis must not be empty', $violations);
        $this->assertContains('signature_features[0].description must not be empty', $violations);
        $this->assertContains('decisions[0].decision must not be empty', $violations);
    }

    // ---- Canonical order ----

    public function test_laravel_orders_decisions_by_profile_rather_than_asking_again(): void
    {
        $concept = $this->concept([], [
            new DesignDecision('materials', Provenance::Invented, 'Steel.'),
            new DesignDecision('size', Provenance::Invented, 'Longer.'),
        ]);

        $ordered = $concept->canonicalised($this->profile());

        $this->assertSame(['size', 'materials'], array_map(
            fn (DesignDecision $d) => $d->aspect,
            $ordered->decisions,
        ));
    }

    public function test_canonicalising_keeps_duplicates_so_the_guard_still_fires(): void
    {
        // Nếu canonicalise gộp trùng, thứ tự gọi API sẽ quyết định concept sai
        // có hợp lệ hay không.
        $concept = $this->concept([], [
            new DesignDecision('size', Provenance::Invented, 'One.'),
            new DesignDecision('size', Provenance::Invented, 'Two.'),
            new DesignDecision('materials', Provenance::Invented, 'Steel.'),
        ]);

        $ordered = $concept->canonicalised($this->profile());

        $this->assertSame(['One.', 'Two.', 'Steel.'], array_map(
            fn (DesignDecision $d) => $d->decision,
            $ordered->decisions,
        ));

        $this->assertContains(
            'decisions holds more than one entry for aspect size',
            (new ConceptValidator)->violations($ordered, $this->profile(), $this->brief()),
        );
    }

    public function test_an_aspect_outside_the_profile_sorts_last_without_being_dropped(): void
    {
        $concept = $this->concept([], [
            new DesignDecision('rigging', Provenance::Invented, 'Unknown aspect.'),
            new DesignDecision('materials', Provenance::Invented, 'Steel.'),
            new DesignDecision('size', Provenance::Invented, 'Longer.'),
        ]);

        $ordered = $concept->canonicalised($this->profile());

        $this->assertSame(['size', 'materials', 'rigging'], array_map(
            fn (DesignDecision $d) => $d->aspect,
            $ordered->decisions,
        ));

        $this->assertContains(
            'decisions[2] aspect rigging is not an inspection aspect',
            (new ConceptValidator)->violations($ordered, $this->profile(), $this->brief()),
        );
    }
}
