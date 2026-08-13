<?php

namespace Tests\Video\Editorial;

use App\Video\Editorial\EditorialInterpreter;
use App\Video\Editorial\FeatureCandidate;
use App\Video\Evidence\Evidence;
use App\Video\Evidence\EvidenceSource;
use App\Video\Evidence\ProvenanceLevel;
use App\Video\Scene\ScenePurpose;
use App\Video\Scene\SemanticScene;
use App\Video\World\Entity;
use App\Video\World\EntityType;
use App\Video\World\VerifiedAttribute;
use App\Video\World\VerifiedWorldGraph;
use PHPUnit\Framework\TestCase;

/**
 * Đường đứt đã đo trên dữ liệu thật: 3/5 session yacht có hero 9-17 thuộc tính
 * nhưng 0-1 event/relation. StoryPlanner xếp hạng entity theo số thuộc tính
 * (:241) và ScenePlanner sinh hẳn scene Detail cho entity đủ giàu (:94), nhưng
 * trước lớp này Editorial không sinh nội dung nào cho scene đó nên Director bị
 * bỏ qua và toàn bộ thuộc tính đã trả tiền để trích xuất bị vứt đi.
 */
class FeatureCandidateTest extends TestCase
{
    private function ev(): Evidence
    {
        return new Evidence('x', EvidenceSource::Body, 0, ProvenanceLevel::Direct);
    }

    /** @param  array<string, list<mixed>>  $attributes  tên => danh sách giá trị thô */
    private function entity(string $id, array $attributes): Entity
    {
        $verified = [];
        foreach ($attributes as $name => $values) {
            $verified[$name] = array_map(
                fn ($v) => new VerifiedAttribute($name, $v, $this->ev(), ProvenanceLevel::Direct),
                $values,
            );
        }

        return new Entity($id, EntityType::Vehicle, $verified);
    }

    /** @param  list<string>  $subjectIds */
    private function scene(array $subjectIds, ScenePurpose $purpose = ScenePurpose::Detail): SemanticScene
    {
        return new SemanticScene('sc_1', 'act_1', 1, $purpose, $subjectIds);
    }

    /** @return list<FeatureCandidate> */
    private function features(SemanticScene $scene, Entity ...$entities): array
    {
        $world = new VerifiedWorldGraph($entities, [], []);

        return (new EditorialInterpreter)->candidatesFor($scene, $world)['feature_candidates'];
    }

    /** @return list<array{0: string, 1: string}> */
    private function keysOf(array $features): array
    {
        return array_map(fn (FeatureCandidate $c) => [$c->entityId, $c->attribute], $features);
    }

    public function test_to_array_preserves_all_supported_scalar_values(): void
    {
        $candidate = new FeatureCandidate('amadea_yacht', 'amenity', ['beach club', 5, 76.5, true]);

        $this->assertSame([
            'entity' => 'amadea_yacht',
            'attribute' => 'amenity',
            'values' => ['beach club', 5, 76.5, true],
        ], $candidate->toArray());
    }

    public function test_a_detail_scene_gets_features_from_its_own_subject(): void
    {
        $features = $this->features(
            $this->scene(['yacht']),
            $this->entity('yacht', ['pool_feature' => ['infinity pool with a swim-up bar']]),
        );

        $this->assertCount(1, $features);
        $this->assertSame('yacht', $features[0]->entityId);
        $this->assertSame('pool_feature', $features[0]->attribute);
        $this->assertSame(['infinity pool with a swim-up bar'], $features[0]->values);
    }

    public function test_only_subjects_of_the_scene_are_read(): void
    {
        // Bài Amadea có cả khách sạn Shangri-La và sân golf Trump trong world —
        // scene về con tàu KHÔNG được nhìn thấy thuộc tính của chúng.
        $features = $this->features(
            $this->scene(['yacht']),
            $this->entity('yacht', ['pool_feature' => ['infinity pool']]),
            $this->entity('hotel', ['room_count' => [300]]),
        );

        $this->assertSame(['yacht'], array_values(array_unique(array_column($features, 'entityId'))));
    }

    public function test_no_purpose_other_than_detail_gets_features(): void
    {
        foreach (ScenePurpose::cases() as $purpose) {
            if ($purpose === ScenePurpose::Detail) {
                continue;
            }

            $features = $this->features(
                $this->scene(['yacht'], $purpose),
                $this->entity('yacht', ['pool_feature' => ['infinity pool']]),
            );

            $this->assertSame([], $features, "purpose {$purpose->value} chua duoc cap feature o Phase nay");
        }
    }

    public function test_multi_value_attributes_are_kept_whole(): void
    {
        $features = $this->features(
            $this->scene(['yacht']),
            $this->entity('yacht', ['amenity' => ['beach club', 'spa', 'helipad']]),
        );

        $this->assertSame(['beach club', 'spa', 'helipad'], $features[0]->values);
    }

    public function test_every_supported_scalar_type_survives(): void
    {
        $features = $this->features(
            $this->scene(['yacht']),
            $this->entity('yacht', ['mixed_attr' => ['text', 5, 76.5, true, false]]),
        );

        $this->assertSame(['text', 5, 76.5, true, false], $features[0]->values);
    }

    /** @dataProvider unusableValues */
    public function test_values_that_cannot_be_represented_are_dropped(mixed $value): void
    {
        $features = $this->features(
            $this->scene(['yacht']),
            $this->entity('yacht', ['attr' => [$value]]),
        );

        $this->assertSame([], $features, 'gia tri khong bieu dien duoc thi KHONG tao candidate rong');
    }

    public static function unusableValues(): array
    {
        return [
            'null' => [null],
            'array' => [['spa', 'helipad']],
            'object' => [new \stdClass],
            'chuoi rong' => [''],
            'chi khoang trang' => ['   '],
            'NAN' => [NAN],
            'INF' => [INF],
        ];
    }

    public function test_a_bad_value_does_not_drop_the_good_ones(): void
    {
        $features = $this->features(
            $this->scene(['yacht']),
            $this->entity('yacht', ['amenity' => ['spa', null, ['nested'], 'helipad']]),
        );

        $this->assertSame(['spa', 'helipad'], $features[0]->values);
    }

    public function test_duplicate_values_are_removed_with_strict_types(): void
    {
        $features = $this->features(
            $this->scene(['yacht']),
            $this->entity('yacht', ['attr' => ['5', 5, '5', 5.0]]),
        );

        // '5' (chuoi), 5 (int), 5.0 (float) la BA su that khac nhau — khu trung
        // long leo se gop nham chung lam mot.
        $this->assertSame(['5', 5, 5.0], $features[0]->values);
    }

    public function test_a_value_longer_than_the_cap_is_dropped(): void
    {
        $features = $this->features(
            $this->scene(['yacht']),
            $this->entity('yacht', ['attr' => [str_repeat('a', 301), 'ngan gon']]),
        );

        $this->assertSame(['ngan gon'], $features[0]->values);
    }

    public function test_a_value_at_the_cap_is_kept(): void
    {
        $atCap = str_repeat('a', 300);

        $features = $this->features(
            $this->scene(['yacht']),
            $this->entity('yacht', ['attr' => [$atCap]]),
        );

        $this->assertSame([$atCap], $features[0]->values);
    }

    /** @dataProvider unusableAttributeNames */
    public function test_an_attribute_name_that_is_not_a_slug_is_dropped(string $name): void
    {
        $features = $this->features(
            $this->scene(['yacht']),
            $this->entity('yacht', [$name => ['gia tri hop le']]),
        );

        $this->assertSame([], $features);
    }

    public static function unusableAttributeNames(): array
    {
        return [
            'viet hoa' => ['Pool_Feature'],
            'co khoang trang' => ['pool feature'],
            'co xuong dong' => ["pool\nfeature"],
            'bat dau bang so' => ['1st_pool'],
            'qua dai' => ['a'.str_repeat('b', 64)],
        ];
    }

    public function test_an_attribute_name_at_the_cap_is_kept(): void
    {
        $atCap = 'a'.str_repeat('b', 63);

        $features = $this->features(
            $this->scene(['yacht']),
            $this->entity('yacht', [$atCap => ['gia tri']]),
        );

        $this->assertSame([['yacht', $atCap]], $this->keysOf($features));
    }

    public function test_values_are_capped_keeping_the_first_ones_in_order(): void
    {
        $features = $this->features(
            $this->scene(['yacht']),
            $this->entity('yacht', ['attr' => array_map(fn (int $i) => "value_{$i}", range(1, 25))]),
        );

        // Dem thoi thi bug "giu 20 phan tu CUOI" hoac dao thu tu van xanh.
        $this->assertSame(
            array_map(fn (int $i) => "value_{$i}", range(1, 20)),
            $features[0]->values,
        );
    }

    public function test_exactly_twenty_values_are_kept_untouched(): void
    {
        $values = array_map(fn (int $i) => "value_{$i}", range(1, 20));

        $features = $this->features(
            $this->scene(['yacht']),
            $this->entity('yacht', ['attr' => $values]),
        );

        $this->assertSame($values, $features[0]->values);
    }

    public function test_candidates_are_capped_keeping_the_first_ones_after_canonical_sort(): void
    {
        $attributes = [];
        foreach (range(1, 25) as $i) {
            $attributes[sprintf('attr_%02d', $i)] = ["value {$i}"];
        }

        $features = $this->features($this->scene(['yacht']), $this->entity('yacht', $attributes));

        $expected = array_map(
            fn (int $i) => ['yacht', sprintf('attr_%02d', $i)],
            range(1, 20),
        );
        $this->assertSame($expected, $this->keysOf($features));
    }

    public function test_exactly_twenty_candidates_are_kept_untouched(): void
    {
        $attributes = [];
        foreach (range(1, 20) as $i) {
            $attributes[sprintf('attr_%02d', $i)] = ["value {$i}"];
        }

        $features = $this->features($this->scene(['yacht']), $this->entity('yacht', $attributes));

        $this->assertCount(20, $features);
        $this->assertSame(['yacht', 'attr_20'], $this->keysOf($features)[19]);
    }

    public function test_candidate_order_is_deterministic_regardless_of_input_order(): void
    {
        $entityA = $this->entity('yacht_a', ['zulu' => ['z'], 'alpha' => ['a']]);
        $entityB = $this->entity('yacht_b', ['mike' => ['m']]);

        $first = $this->features($this->scene(['yacht_b', 'yacht_a']), $entityA, $entityB);
        $second = $this->features($this->scene(['yacht_a', 'yacht_b']), $entityB, $entityA);

        $this->assertSame($this->keysOf($first), $this->keysOf($second));
        $this->assertSame(
            [['yacht_a', 'alpha'], ['yacht_a', 'zulu'], ['yacht_b', 'mike']],
            $this->keysOf($first),
        );
    }

    public function test_a_duplicated_subject_id_does_not_duplicate_candidates(): void
    {
        $features = $this->features(
            $this->scene(['yacht', 'yacht']),
            $this->entity('yacht', ['attr' => ['value']]),
        );

        $this->assertCount(1, $features);
    }

    public function test_an_entity_missing_from_the_world_is_skipped(): void
    {
        $features = $this->features(
            $this->scene(['khong_ton_tai']),
            $this->entity('yacht', ['attr' => ['value']]),
        );

        $this->assertSame([], $features);
    }
}
