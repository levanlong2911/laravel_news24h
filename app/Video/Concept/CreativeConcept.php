<?php

namespace App\Video\Concept;

use App\Video\Inspiration\CategoryCreativeProfile;

final class CreativeConcept
{
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
}
