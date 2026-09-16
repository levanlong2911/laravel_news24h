<?php

namespace Tests\Video\Concept\Canonical;

use App\Video\Concept\Claude\CanonicalSchemaProvider;
use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Canonical\Relationships\CountRelationship;
use App\Video\Concept\Canonical\Relationships\OneToOneRelationship;
use App\Video\Concept\Canonical\Relationships\ProportionRelationship;
use App\Video\Concept\Hashing\CanonicalDesignSpecHasher;
use App\Video\Concept\Normalize\CanonicalDesignSpecNormalizer;
use InvalidArgumentException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Tests\TestCase;

class CanonicalDesignSpecTest extends TestCase
{
    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'schema_version' => '1.0',
            'object_type' => 'yacht',
            'design_thesis' => ['text' => 'One shell tapers aft.', 'role' => 'soft_design_guidance'],
            'identity' => ['subject_class' => 'marine_vessel', 'identity_basis' => ['bow_geometry', 'opening_layout'], 'finish_identity_basis' => []],
            'dimensions' => ['length_m' => 120.0, 'beam_m' => 17.5, 'length_to_beam_ratio' => 6.857],
            'permanent_geometry' => [
                'superstructure' => ['enclosed_deck_levels' => 4],
                'openings' => ['superstructure_bands' => 4],
            ],
            'relationships' => [
                ['id' => 'R002', 'type' => 'one_to_one',
                    'source_path' => 'permanent_geometry.superstructure.enclosed_deck_levels',
                    'target_path' => 'permanent_geometry.openings.superstructure_bands'],
                ['id' => 'R001', 'type' => 'count',
                    'subject_path' => 'permanent_geometry.superstructure.enclosed_deck_levels', 'value' => 4],
                ['id' => 'R003', 'type' => 'proportion',
                    'subject_path' => 'dimensions', 'metric' => 'length_to_beam_ratio', 'value' => 6.857, 'tolerance' => 0.08],
            ],
            'form_relationships' => ['governing_line' => 'One sheer runs bow to stern.'],
            'finished_materials' => ['hull' => ['material' => 'aluminium', 'colour' => 'graphite']],
            'exclusions' => [['id' => 'E001', 'target_path' => 'permanent_geometry', 'forbid' => 'cantilever']],
            'invariants' => [[
                'id' => 'I001', 'name' => 'enclosed_deck_level_count',
                'source_path' => 'permanent_geometry.superstructure.enclosed_deck_levels',
                'constraint_type' => 'count', 'severity' => 'hard', 'visual_verification' => true,
            ]],
            'provenance' => [
                ['target_path' => 'permanent_geometry.bow', 'origin' => 'invented', 'source_aspects' => []],
                ['target_path' => 'dimensions', 'origin' => 'inspired', 'source_aspects' => ['size_and_dimensions']],
            ],
        ];
    }

    /**
     * Doc dung ban ma production doc, qua dung lop production dung.
     *
     * @return array<string, mixed>
     */
    private function productionSchema(): array
    {
        return (new CanonicalSchemaProvider(
            (string) config('canonical_concept.schema.path'),
        ))->schema();
    }

    public function test_the_payload_this_suite_is_built_on_satisfies_the_frozen_schema(): void
    {
        $result = (new Validator)->validate(
            json_decode(json_encode($this->payload(), JSON_THROW_ON_ERROR)),
            json_decode(json_encode($this->productionSchema(), JSON_THROW_ON_ERROR)),
        );

        if ($result->hasError()) {
            $this->fail(json_encode((new ErrorFormatter)->format($result->error()), JSON_PRETTY_PRINT));
        }

        $this->addToAssertionCount(1);
    }

    public function test_it_round_trips_through_the_dto_without_losing_a_byte(): void
    {
        $payload = $this->payload();

        $this->assertSame($payload, CanonicalDesignSpec::fromArray($payload)->toArray());
    }

    public function test_each_relationship_becomes_the_class_its_type_names(): void
    {
        $spec = CanonicalDesignSpec::fromArray($this->payload());

        $this->assertInstanceOf(OneToOneRelationship::class, $spec->relationships[0]);
        $this->assertInstanceOf(CountRelationship::class, $spec->relationships[1]);
        $this->assertInstanceOf(ProportionRelationship::class, $spec->relationships[2]);
        $this->assertSame(4, $spec->relationships[1]->value);
        $this->assertSame(0.08, $spec->relationships[2]->tolerance);
    }

    /** Truong tuy chon vang thi phai VANG han, khong duoc hien ra thanh null. */
    public function test_an_absent_optional_field_stays_absent(): void
    {
        $payload = $this->payload();
        unset($payload['relationships'][2]['tolerance']);

        $spec = CanonicalDesignSpec::fromArray($payload);

        $this->assertNull($spec->relationships[2]->tolerance);
        $this->assertArrayNotHasKey('tolerance', $spec->toArray()['relationships'][2]);
    }

    public function test_a_version_the_dto_was_not_written_for_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('schema_version must equal 1.0');

        CanonicalDesignSpec::fromArray(['schema_version' => '2.0'] + $this->payload());
    }

    /**
     * Tu vung dong duoc gac bang enum: `ConstraintPrimitive::from()` nem
     * ValueError, khong phai InvalidArgumentException. Thong diep van goi ten
     * gia tri sai va enum no thuoc ve.
     */
    public function test_a_constraint_type_outside_the_closed_vocabulary_is_refused(): void
    {
        $payload = $this->payload();
        $payload['invariants'][0]['constraint_type'] = 'very_important_thing';

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('"very_important_thing" is not a valid backing value for enum');

        CanonicalDesignSpec::fromArray($payload);
    }

    public function test_normalising_orders_every_collection_by_its_own_key(): void
    {
        $normalised = (new CanonicalDesignSpecNormalizer)->normalize(CanonicalDesignSpec::fromArray($this->payload()));

        $this->assertSame(['R001', 'R002', 'R003'], array_column($normalised->toArray()['relationships'], 'id'));
        $this->assertSame(
            ['dimensions', 'permanent_geometry.bow'],
            array_column($normalised->toArray()['provenance'], 'target_path'),
        );
    }

    /**
     * RANH GIOI CO Y (Phan 4): Normalizer KHONG tinh lai gia tri nao. Tinh ty
     * le la luat cua MOT ho chu de — du thuyen dung dai/rong, may bay dung sai
     * so khac, cai ghe khong co ty le nao. Lam o day thi Normalizer bien thanh
     * semantic repair. Viec do thuoc profile validator.
     */
    public function test_normalising_leaves_every_domain_value_exactly_as_the_model_wrote_it(): void
    {
        $payload = $this->payload();
        $payload['dimensions']['length_to_beam_ratio'] = 9.9;

        $normalised = (new CanonicalDesignSpecNormalizer)->normalize(CanonicalDesignSpec::fromArray($payload));

        $this->assertSame(9.9, $normalised->dimensions['length_to_beam_ratio']);
    }

    /** source_aspects la SET — sap de hash on dinh. */
    public function test_normalising_sorts_the_source_aspects_of_each_provenance_entry(): void
    {
        $payload = $this->payload();
        $payload['provenance'][1]['source_aspects'] = ['spatial_layout', 'size_and_dimensions'];

        $normalised = (new CanonicalDesignSpecNormalizer)->normalize(CanonicalDesignSpec::fromArray($payload));

        $this->assertSame(
            ['size_and_dimensions', 'spatial_layout'],
            $normalised->toArray()['provenance'][0]['source_aspects'],
        );
    }

    /** ORDER la thu tu ngu nghia — sap no la doi thiet ke. */
    public function test_normalising_never_reorders_the_items_of_an_order_relationship(): void
    {
        $payload = $this->payload();
        $payload['relationships'][] = [
            'id' => 'R004', 'type' => 'order',
            'items' => ['tier_4', 'tier_1', 'tier_3'], 'direction' => 'bottom_to_top',
        ];

        $normalised = (new CanonicalDesignSpecNormalizer)->normalize(CanonicalDesignSpec::fromArray($payload));
        $order = end($normalised->toArray()['relationships']);

        $this->assertSame(['tier_4', 'tier_1', 'tier_3'], $order['items']);
    }

    public function test_the_hash_ignores_key_order_but_not_content(): void
    {
        $hasher = new CanonicalDesignSpecHasher;
        $payload = $this->payload();

        $shuffled = array_reverse($payload, preserve_keys: true);
        $changed = $payload;
        $changed['object_type'] = 'aircraft';

        $json = json_encode(CanonicalDesignSpec::fromArray($payload)->toArray(), JSON_THROW_ON_ERROR);
        $shuffledJson = json_encode(CanonicalDesignSpec::fromArray($shuffled)->toArray(), JSON_THROW_ON_ERROR);
        $changedJson = json_encode(CanonicalDesignSpec::fromArray($changed)->toArray(), JSON_THROW_ON_ERROR);

        $this->assertSame(
            $hasher->hash($json),
            $hasher->hash($shuffledJson),
        );
        $this->assertNotSame(
            $hasher->hash($json),
            $hasher->hash($changedJson),
        );
    }
}
