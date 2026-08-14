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
    ) {}

    /** Thứ tự canonical do Laravel áp sau khi parse — không bắt model xếp đúng rồi hỏi lại. */
    public function canonicalised(CategoryCreativeProfile $profile): self
    {
        $byAspect = [];

        foreach ($this->decisions as $decision) {
            $byAspect[$decision->aspect] ??= $decision;
        }

        $ordered = [];

        foreach ($profile->inspectionAspects as $aspect) {
            if (isset($byAspect[$aspect])) {
                $ordered[] = $byAspect[$aspect];
                unset($byAspect[$aspect]);
            }
        }

        return new self(
            $this->designThesis,
            $this->designIdentity,
            $this->signatureFeatures,
            [...$ordered, ...array_values($byAspect)],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'design_thesis' => $this->designThesis,
            'design_identity' => $this->designIdentity,
            'signature_features' => array_map(fn (SignatureFeature $f) => $f->toArray(), $this->signatureFeatures),
            'decisions' => array_map(fn (DesignDecision $d) => $d->toArray(), $this->decisions),
        ];
    }
}
