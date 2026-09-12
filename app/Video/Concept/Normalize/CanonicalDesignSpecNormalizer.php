<?php

declare(strict_types=1);

namespace App\Video\Concept\Normalize;

use App\Video\Concept\Canonical\CanonicalDesignSpec;

final class CanonicalDesignSpecNormalizer
{
    public const VERSION = 'canonical-normalizer-v1';

    public function normalize(
        CanonicalDesignSpec $spec
    ): CanonicalDesignSpec {
        $data = $spec->toArray();

        /*
         * relationships la semantic set o root.
         * Thu tu xuat hien khong mang meaning.
         *
         * Do do sort theo stable id de:
         *
         * cung mot semantic spec
         * luon tao cung canonical representation.
         *
         * QUAN TRONG:
         * chi sort danh sach relationship o root.
         * KHONG sort OrderRelationship.items.
         */
        usort(
            $data['relationships'],
            static fn (
                array $left,
                array $right
            ): int => strcmp(
                (string) $left['id'],
                (string) $right['id']
            )
        );

        /*
         * exclusions cung la root-level set.
         */
        usort(
            $data['exclusions'],
            static fn (
                array $left,
                array $right
            ): int => strcmp(
                (string) $left['id'],
                (string) $right['id']
            )
        );

        /*
         * invariants cung dung stable id.
         */
        usort(
            $data['invariants'],
            static fn (
                array $left,
                array $right
            ): int => strcmp(
                (string) $left['id'],
                (string) $right['id']
            )
        );

        /*
         * provenance khong co id.
         *
         * V1 policy:
         * moi target_path chi co mot provenance entry,
         * nen target_path la stable sort key.
         */
        usort(
            $data['provenance'],
            static fn (
                array $left,
                array $right
            ): int => strcmp(
                (string) $left['target_path'],
                (string) $right['target_path']
            )
        );

        /*
         * identity_basis duoc schema khai bao
         * uniqueItems=true.
         *
         * Khong sort list nay o V1.
         *
         * Ly do:
         * du hien tai identity_basis duoc xem
         * gan giong semantic set, schema khong noi
         * order hoan toan vo nghia.
         *
         * Giu order dau vao an toan hon.
         */
        $data['identity']['identity_basis'] =
            array_values(
                $data['identity']['identity_basis']
            );

        /*
         * source_aspects theo semantic policy la set.
         *
         * Thu tu:
         * [
         *   "size_and_dimensions",
         *   "spatial_layout"
         * ]
         *
         * khong mang meaning.
         *
         * Vi vay sort de hash deterministic.
         */
        foreach (
            $data['provenance'] as &$entry
        ) {
            $sourceAspects =
                $entry['source_aspects'];

            sort(
                $sourceAspects,
                SORT_STRING
            );

            $entry['source_aspects'] =
                array_values(
                    $sourceAspects
                );
        }

        unset($entry);

        /*
         * Khong normalize:
         *
         * dimensions
         * permanent_geometry
         * form_relationships
         * finished_materials
         *
         * bang domain logic o day.
         *
         * Core Normalizer khong duoc:
         *
         * - tinh ratio
         * - sua dimension
         * - doi geometry
         * - them missing field
         * - suy dien semantic
         *
         * Vi do se tro thanh semantic repair.
         */

        return CanonicalDesignSpec::fromArray(
            $data
        );
    }
}
