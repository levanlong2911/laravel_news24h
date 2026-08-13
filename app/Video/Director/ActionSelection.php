<?php

namespace App\Video\Director;

use App\Video\Editorial\ActionCandidate;
use App\Video\Editorial\FeatureCandidate;
use InvalidArgumentException;

/**
 * Output cua Director — CHI la lua chon (index/entity id), khong phai object
 * da resolve. Director khong copy/mutate/serialize ActionCandidate — no chi
 * chon trong tap hop da co san. Xem ARCHITECTURE.md SS18.4.
 */
final class ActionSelection
{
    /**
     * @param list<int> $secondaryCandidateIndices
     * @param list<int> $featureCandidateIndices
     */
    public function __construct(
        public readonly string $heroEntity,
        /** null = cảnh không có hành động nào, chỉ có feature. */
        public readonly ?int $primaryCandidateIndex,
        public readonly array $secondaryCandidateIndices,
        public readonly string $emotion,
        public readonly string $reveal,
        /**
         * Câu dàn cảnh (2026-07-23) — Director ĐƯỢC quyền mô tả tiền cảnh/hậu
         * cảnh/cái gì nổi bật, nhưng CHỈ ghép từ hero/primary/secondary/
         * attribute ĐÃ CÓ trong candidates đưa cho nó (xem instruction() của
         * ClaudeDirector) — vẫn evidence-gated, không được bịa chi tiết mới.
         * KHÔNG đi qua resolve() (không cần candidates để giải mã, giống
         * $emotion/$reveal) — VideoPlanningPipeline gắn thẳng vào director_notes.
         */
        public readonly string $compositionNote = '',
        /** Cảnh này nói điều gì mà các cảnh trước chưa nói. */
        public readonly string $newInformation = '',
        /** Thuộc tính Director đã chọn để nhấn mạnh — CHỈ khi capability bật. */
        public readonly array $featureCandidateIndices = [],
    ) {
        if ($primaryCandidateIndex === null && $featureCandidateIndices === []) {
            throw new InvalidArgumentException(
                'ActionSelection phải có ít nhất một hành động hoặc một feature — không có gì để kể thì không phải một lựa chọn',
            );
        }
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
     * `primary` VẮNG HẲN khi không có hành động nào — schema $defs/action đòi
     * `type`+`actor`, nên emit object rỗng là phá contract.
     *
     * @param list<ActionCandidate> $candidates cung danh sach da dua Director chon
     * @param list<FeatureCandidate> $featureCandidates cung danh sach feature da dua Director chon
     * @return array<string, mixed>
     */
    public function resolve(array $candidates, array $featureCandidates = []): array
    {
        $doc = [];

        if ($this->heroEntity !== '') {
            $doc['hero'] = $this->heroEntity;
        }

        if ($this->primaryCandidateIndex !== null) {
            $doc['primary'] = $candidates[$this->primaryCandidateIndex]->toArray();
        }

        $doc['secondary'] = array_map(
            fn (int $i) => $candidates[$i]->toArray(),
            $this->secondaryCandidateIndices,
        );

        if ($this->featureCandidateIndices !== []) {
            $doc['features'] = array_map(
                fn (int $i) => $featureCandidates[$i]->toArray(),
                $this->featureCandidateIndices,
            );
        }

        return $doc;
    }
}
