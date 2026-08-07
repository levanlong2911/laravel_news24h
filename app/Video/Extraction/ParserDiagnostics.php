<?php

namespace App\Video\Extraction;

/**
 * Đếm những gì parser BỎ ĐI — để "không có sự thật nào" không còn là một câu
 * đoán.
 *
 * VÌ SAO CẦN, bằng chứng thật 2026-08-06 (bài "ISA Amarcord 82"): bài dài 2.875
 * ký tự, có nguyên văn "the aft edge of the pool is glass, as is the bottom",
 * "helipad", "fold-down platforms", "sporty curves". Kết quả: 12 entity, TOÀN
 * SỐ ĐO, 0 fact. Và không ai phân biệt được năm nguyên nhân dưới đây, vì cả năm
 * cùng cho ra một triệu chứng — im lặng:
 *
 *   A. LLM không sinh claim nào          → claimsSeen = 0
 *   B. LLM sinh, parser nuốt vì sai dạng → claimsSeen > 0, claimsDropped… > 0
 *   C. LLM sinh, parser nhận, Gatekeeper loại → xem GatekeeperReport
 *   D. LLM quên quote                    → claimsMissingQuote > 0
 *   E. JSON hỏng cả khối                 → sectionsMalformed
 *
 * Trước lớp này, cả năm đều hiện ra là "bài báo nghèo thông tin" — một kết luận
 * SAI mà không có cách nào bác bỏ.
 *
 * MUTABLE, CÓ CHỦ Ý — khác spec ban đầu ghi `readonly`. Đây là BỘ ĐẾM: cộng dồn
 * suốt một lượt parse. Bản readonly sẽ cần một method `with…()` cho mỗi trường
 * chỉ để tăng một số. Đổi lại, nó được TẠO MỚI ở đầu mỗi lượt `parse()` và
 * không bao giờ dùng chung — nên không có state ẩn nào rò rỉ giữa hai lượt,
 * kể cả khi `CandidateGraphParser` được dựng một lần rồi tái sử dụng.
 *
 * KHÔNG được dùng để QUYẾT ĐỊNH. Cùng luật với `confidence` của Gatekeeper: đây
 * là quan sát, không phải đầu vào. Parser thấy `claimsDroppedInvalidShape = 30`
 * thì vẫn parse y như cũ — nó chỉ ghi lại.
 */
final class ParserDiagnostics
{
    public int $entitiesSeen = 0;

    public int $entitiesAccepted = 0;

    public int $entitiesDroppedInvalidShape = 0;

    public int $claimsSeen = 0;

    public int $claimsAccepted = 0;

    public int $claimsDroppedInvalidShape = 0;

    /**
     * Claim ĐƯỢC NHẬN nhưng không có quote — parser cố ý giữ lại để Gatekeeper
     * loại với lý do rõ ràng (xem docstring `CandidateGraphParser`). Đếm ở đây
     * vì nó BÁO TRƯỚC số claim sắp chết ở cổng: `claimsMissingQuote` cao mà
     * Gatekeeper loại nhiều thì nguyên nhân là LLM quên quote, không phải
     * verifier khắt khe.
     */
    public int $claimsMissingQuote = 0;

    public int $relationsSeen = 0;

    public int $relationsAccepted = 0;

    public int $relationsDroppedInvalidShape = 0;

    public int $eventsSeen = 0;

    public int $eventsAccepted = 0;

    public int $eventsDroppedInvalidShape = 0;

    /**
     * Khối cấp cao nhất (`entities`/`relations`/`events`) tồn tại nhưng KHÔNG
     * phải mảng — vd LLM trả `"claims": "none"`. Nguy hiểm hơn từng item hỏng:
     * mất NGUYÊN khối một lần, và code cũ trả `[]` không một dấu vết.
     *
     * @var list<string>
     */
    public array $sectionsMalformed = [];

    /** LLM bọc JSON trong ```json dù đã bảo đừng — vô hại, nhưng đáng theo dõi. */
    public bool $wrappedInCodeFence = false;

    public function markSectionMalformed(string $section): void
    {
        $this->sectionsMalformed[] = $section;
    }

    /** Không mất gì và không có gì bất thường. */
    public function isClean(): bool
    {
        return $this->entitiesDroppedInvalidShape === 0
            && $this->claimsDroppedInvalidShape === 0
            && $this->relationsDroppedInvalidShape === 0
            && $this->eventsDroppedInvalidShape === 0
            && $this->sectionsMalformed === [];
    }

    /**
     * Lưu vào cột JSON của artifact — MỘT cột, không phải mười lăm cột. Đây là
     * instrumentation: hình dạng của nó sẽ đổi khi ta học thêm, và mỗi lần đổi
     * mà phải viết migration thì sẽ không ai thêm số đo mới nữa.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'entities_seen' => $this->entitiesSeen,
            'entities_accepted' => $this->entitiesAccepted,
            'entities_dropped_invalid_shape' => $this->entitiesDroppedInvalidShape,
            'claims_seen' => $this->claimsSeen,
            'claims_accepted' => $this->claimsAccepted,
            'claims_dropped_invalid_shape' => $this->claimsDroppedInvalidShape,
            'claims_missing_quote' => $this->claimsMissingQuote,
            'relations_seen' => $this->relationsSeen,
            'relations_accepted' => $this->relationsAccepted,
            'relations_dropped_invalid_shape' => $this->relationsDroppedInvalidShape,
            'events_seen' => $this->eventsSeen,
            'events_accepted' => $this->eventsAccepted,
            'events_dropped_invalid_shape' => $this->eventsDroppedInvalidShape,
            'sections_malformed' => $this->sectionsMalformed,
            'wrapped_in_code_fence' => $this->wrappedInCodeFence,
        ];
    }
}
