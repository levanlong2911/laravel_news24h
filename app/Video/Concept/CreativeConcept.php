<?php

namespace App\Video\Concept;

use App\Video\Inspiration\CategoryCreativeProfile;

final class CreativeConcept
{
    private const SPEC_MATERIALS = [
        'hull' => ['hull_material' => 'material', 'hull_colour' => 'colour'],
        'superstructure' => ['superstructure_material' => 'material', 'superstructure_colour' => 'colour'],
        'boot_stripe' => ['boot_stripe_colour' => 'colour'],
        'glazing' => ['glazing_type' => 'type'],
    ];

    private const SPEC_GEOMETRY = ['bow', 'hull', 'stern', 'superstructure', 'openings'];

    /**
     * @param  array<string, mixed>  $designIdentity  khoá đúng bằng profile.identity_slots
     * @param  list<SignatureFeature>  $signatureFeatures
     * @param  list<DesignDecision>  $decisions
     */
    public function __construct(
        public readonly string $designThesis,
        public readonly array $designIdentity,
        public readonly array $signatureFeatures,
        public readonly array $decisions,
        public readonly FormRelationships $formRelationships,
    ) {}

    /**
     * Thứ tự canonical do Laravel áp sau khi parse — không bắt model xếp đúng
     * rồi hỏi lại. Sắp xếp ổn định và KHÔNG gộp: hai decision cùng aspect phải
     * sống sót qua đây, nếu không thứ tự gọi API sẽ quyết định concept sai có
     * hợp lệ hay không.
     */
    public function canonicalised(CategoryCreativeProfile $profile): self
    {
        $rank = array_flip($profile->inspectionAspects);
        $position = fn (DesignDecision $decision) => $rank[$decision->aspect] ?? PHP_INT_MAX;

        $decisions = $this->decisions;
        usort($decisions, fn (DesignDecision $a, DesignDecision $b) => $position($a) <=> $position($b));

        return new self(
            $this->designThesis,
            $this->designIdentity,
            $this->signatureFeatures,
            $decisions,
            $this->formRelationships,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'design_thesis' => $this->designThesis,
            'design_identity' => $this->designIdentity,
            'form_relationships' => $this->formRelationships->toArray(),
            'signature_features' => array_map(fn (SignatureFeature $f) => $f->toArray(), $this->signatureFeatures),
            'decisions' => array_map(fn (DesignDecision $d) => $d->toArray(), $this->decisions),
        ];
    }

    /**
     * Ban XUAT, doi lap voi toArray(): doi ten khoa, tinh lai ty le, ap alias,
     * chen sieu du lieu Laravel biet chac. toArray() phai giu nguyen ban sao
     * trung thuc cua cau tra loi da tra tien — dung tron hai cai.
     *
     * Khe nao model chua khai thi VANG MAT, khong xuat null: DesignSpec la du
     * lieu that dang co, khong phai hinh dang ly tuong.
     *
     * @return array<string, mixed>
     */
    public function toDesignSpec(CategoryCreativeProfile $profile, string $objectType): array
    {
        $spec = [
            'schema_version' => (string) ($profile->designSpecExport['schema_version'] ?? ''),
            'object_type' => $objectType,
            'design_thesis' => $this->designThesis,
        ];

        foreach ([
            'dimensions' => $this->specDimensions(),
            'permanent_geometry' => $this->specGeometry($profile),
            'form_relationships' => $this->formRelationships->toArray(),
            'finished_materials' => $this->specMaterials(),
        ] as $group => $value) {
            if ($value !== []) {
                $spec[$group] = $value;
            }
        }

        $invariants = $profile->designSpecExport['invariants'] ?? [];

        if ($invariants !== []) {
            $spec['invariants'] = array_values($invariants);
        }

        return $spec;
    }

    /** @return array<string, mixed> */
    private function specDimensions(): array
    {
        $length = $this->designIdentity['design_length_m'] ?? null;
        $beam = $this->designIdentity['design_beam_m'] ?? null;

        $dimensions = [];

        foreach ([
            'length_m' => $length,
            'beam_m' => $beam,
            // Laravel tinh, khong lay so model tu khai: rev 4 khai 6.9 trong khi
            // 120/17.5 = 6.857.
            'length_to_beam_ratio' => is_numeric($length) && is_numeric($beam) && (float) $beam !== 0.0
                ? round((float) $length / (float) $beam, 3)
                : null,
            'draft_m' => $this->designIdentity['design_draft_m'] ?? null,
            'freeboard_midships_m' => $this->designIdentity['visible_freeboard_at_midships_m'] ?? null,
            'deck_to_deck_height_m' => $this->designIdentity['typical_deck_to_deck_height_m'] ?? null,
        ] as $name => $value) {
            if ($value !== null) {
                $dimensions[$name] = $value;
            }
        }

        return $dimensions;
    }

    /** @return array<string, mixed> */
    private function specGeometry(CategoryCreativeProfile $profile): array
    {
        $geometry = [];

        foreach (self::SPEC_GEOMETRY as $slot) {
            $value = $this->designIdentity[$slot] ?? null;

            if (! is_array($value) || $value === []) {
                continue;
            }

            $fields = [];

            foreach ($value as $field => $inner) {
                $fields[$profile->exportKeyName($slot, $field)] = $profile->exportAlias("{$slot}.{$field}", $inner);
            }

            if ($slot === 'hull') {
                $type = $this->derivedHullType();
                // Phuong an 1 dat `type` dau khoi hull.
                $fields = $type === null ? $fields : ['type' => $type] + $fields;
            }

            $geometry[$slot] = $fields;
        }

        $tiers = $this->designIdentity['visible_deck_tiers'] ?? null;

        if ($tiers !== null && isset($geometry['superstructure'])) {
            $geometry['superstructure']['enclosed_deck_levels'] = $tiers;
        }

        return $geometry;
    }

    /**
     * `hull.type` cua DesignSpec KHONG phai mot khe Sonnet khai — no gop hai
     * su that da co (kim loai o hull_material, ho than o hull.midbody). Khai
     * rieng se thanh nguon su that thu ba va ba cho se troi khoi nhau.
     *
     * Vat lieu nhac ca hai kim loai, hoac khong nhac cai nao, thi tra null:
     * mot ca nhap nhang phai HIEN RA chu khong duoc doan thanh cai khop truoc.
     */
    private function derivedHullType(): ?string
    {
        if (($this->designIdentity['hull']['midbody'] ?? null) === null) {
            return null;
        }

        $material = mb_strtolower((string) ($this->designIdentity['hull_material'] ?? ''));
        $steel = str_contains($material, 'steel');
        $aluminium = str_contains($material, 'aluminium') || str_contains($material, 'aluminum');

        return match (true) {
            $steel && ! $aluminium => 'steel_displacement',
            $aluminium && ! $steel => 'aluminium_displacement',
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function specMaterials(): array
    {
        $materials = [];

        foreach (self::SPEC_MATERIALS as $group => $slots) {
            $entry = [];

            foreach ($slots as $slot => $name) {
                if (isset($this->designIdentity[$slot])) {
                    $entry[$name] = $this->designIdentity[$slot];
                }
            }

            if ($entry !== []) {
                $materials[$group] = $entry;
            }
        }

        return $materials;
    }
}
