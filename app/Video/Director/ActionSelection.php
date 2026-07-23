<?php

namespace App\Video\Director;

use App\Video\Editorial\ActionCandidate;

/**
 * Output cua Director — CHI la lua chon (index/entity id), khong phai object
 * da resolve. Director khong copy/mutate/serialize ActionCandidate — no chi
 * chon trong tap hop da co san. Xem ARCHITECTURE.md SS18.4.
 */
final class ActionSelection
{
    /**
     * @param list<int> $secondaryCandidateIndices
     */
    public function __construct(
        public readonly string $heroEntity,
        public readonly int $primaryCandidateIndex,
        public readonly array $secondaryCandidateIndices,
        public readonly string $emotion,
        public readonly string $reveal,
    ) {
    }

    /**
     * Resolve index -> object. Pure function, khong IO, khong AI — day la
     * "assembly" (tra loi "cai nay la gi") chu khong phai "planning" (tra loi
     * "nen chon cai nao"), nen KHONG dat trong RenderPlanAssembler (giu dung
     * Assembler la tang projection thuan tuy).
     *
     * heroEntity RONG -> BO han key 'hero' (khong emit '' — schema slug doi
     * minLength 1). Bug that bat 2026-07-22: scene co action hop le (actor la
     * entity anchor-only, vd "Don Julio Tequila" chi co identity.name, khong
     * attribute) nhung KHONG co hero_candidates hop le (hero_candidates loc
     * anchor-only) — Director khong co gi de chon, KHONG duoc bia hero gia.
     *
     * @param list<ActionCandidate> $candidates cung danh sach da dua Director chon
     * @return array{primary: array, secondary: list<array>}
     */
    public function resolve(array $candidates): array
    {
        $doc = [];

        if ($this->heroEntity !== '') {
            $doc['hero'] = $this->heroEntity;
        }

        $doc['primary'] = $candidates[$this->primaryCandidateIndex]->toArray();
        $doc['secondary'] = array_map(
            fn (int $i) => $candidates[$i]->toArray(),
            $this->secondaryCandidateIndices,
        );

        return $doc;
    }
}
