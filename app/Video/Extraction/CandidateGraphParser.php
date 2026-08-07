<?php

namespace App\Video\Extraction;

/**
 * JSON của LLM → CandidateWorldGraph.
 *
 * Nguyên tắc: **khoan dung với hình thức, tuyệt đối không bù nội dung.**
 *
 * LLM sẽ trả thiếu trường, sai kiểu, bọc JSON trong ```json. Parser dọn những
 * thứ đó. Nhưng nó KHÔNG BAO GIỜ điền giá trị mặc định cho `evidence_quote`:
 * thiếu quote thì để rỗng và Gatekeeper loại với lý do NoEvidence. Parser mà tự
 * bịa quote thì nó đã trở thành kẻ nói dối thay cho LLM.
 *
 * Cũng KHÔNG lọc theo ontology hay confidence — đó là việc của Gatekeeper. Một
 * parser lọc bớt sẽ khiến GatekeeperReport nói dối về tỷ lệ sống sót.
 *
 * NHƯNG BỎ THÌ PHẢI ĐẾM (2026-08-06). Trước đây mọi `continue` ở đây đều im
 * lặng, nên "LLM không sinh gì" và "parser nuốt hết" cho ra CÙNG một triệu
 * chứng: 0 claim, không lỗi, không log. Bài "ISA Amarcord 82" ra 0 fact và
 * không ai phân biệt được năm nguyên nhân — xem `ParserDiagnostics`.
 */
final class CandidateGraphParser
{
    /**
     * @param  ParserDiagnostics|null  $diagnostics  THAM CHIẾU, tuỳ chọn — chỗ
     *                                               gọi muốn biết đã mất gì thì truyền một biến vào, không muốn thì bỏ qua.
     *
     *   Chọn tham số tham chiếu thay vì thêm `parseWithDiagnostics()` + một DTO
     *   cặp: bốn test và `RecordedExtractor` đang gọi `parse($json)` một tham
     *   số, và cách này KHÔNG đụng tới chúng. Khuôn cũng đã có sẵn ngay trong
     *   file này — `preg_match($pattern, $text, $m)`.
     *
     *   HÀNH VI KHÔNG ĐỔI: cùng một JSON hợp lệ vẫn cho ra cùng một graph. Đây
     *   là bước ĐO, chưa phải bước sửa (Sprint 0: instrument trước, optimize sau).
     */
    public function parse(string $json, ?ParserDiagnostics &$diagnostics = null): CandidateWorldGraph
    {
        $d = new ParserDiagnostics;

        $data = json_decode($this->unwrap($json, $d), true);

        if (! is_array($data)) {
            $diagnostics = $d;
            throw new MalformedExtraction('LLM không trả về JSON hợp lệ: '.mb_strimwidth($json, 0, 200, '…'));
        }

        $graph = new CandidateWorldGraph(
            $this->entities($data['entities'] ?? [], $d),
            $this->relations($data['relations'] ?? [], $d),
            $this->events($data['events'] ?? [], $d),
        );

        $diagnostics = $d;

        return $graph;
    }

    /** LLM rất hay bọc JSON trong ```json … ``` dù được bảo đừng. */
    private function unwrap(string $text, ParserDiagnostics $d): string
    {
        $text = trim($text);

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $text, $m)) {
            $d->wrappedInCodeFence = true;

            return trim($m[1]);
        }

        return $text;
    }

    /**
     * @return list<CandidateEntity>
     */
    private function entities(mixed $raw, ParserDiagnostics $d): array
    {
        if (! is_array($raw)) {
            // Mất NGUYÊN khối, một lần — nguy hiểm hơn từng item hỏng, và bản
            // cũ trả `[]` không để lại dấu vết nào.
            $d->markSectionMalformed('entities');

            return [];
        }

        $entities = [];

        foreach ($raw as $item) {
            $d->entitiesSeen++;

            if (! is_array($item) || ! isset($item['id'], $item['type'])) {
                $d->entitiesDroppedInvalidShape++;

                continue; // không định danh được thì không có gì để gác
            }

            $entities[] = new CandidateEntity(
                (string) $item['id'],
                (string) $item['type'],
                $this->claims($item['claims'] ?? [], (string) $item['id'], $d),
                isset($item['name']) ? (string) $item['name'] : null,
                (string) ($item['name_quote'] ?? ''),
                (float) ($item['confidence'] ?? 0.0),
                // B1 (2026-07-22): parse song song claims thường — CHƯA nơi
                // nào tiêu thụ ngoài đo precision (xem SemanticClaimPrecisionAnalyzer).
                $this->claims($item['semantic_claims'] ?? [], (string) $item['id'], $d),
            );
            $d->entitiesAccepted++;
        }

        return $entities;
    }

    /**
     * @return list<CandidateClaim>
     */
    private function claims(mixed $raw, string $entityId, ParserDiagnostics $d): array
    {
        if (! is_array($raw)) {
            $d->markSectionMalformed("claims[{$entityId}]");

            return [];
        }

        $claims = [];

        foreach ($raw as $item) {
            $d->claimsSeen++;

            // ĐÂY LÀ CHỖ NGHI NHẤT của bài ISA: nếu model trả claim LỒNG NHAU
            // (`{"hull": {"color": …}}`) thì không có khoá `attribute`, và toàn
            // bộ claim rơi vào nhánh này — trước đây rơi trong im lặng.
            if (! is_array($item) || ! isset($item['attribute'])) {
                $d->claimsDroppedInvalidShape++;

                continue;
            }

            $quote = (string) ($item['evidence_quote'] ?? '');
            if (trim($quote) === '') {
                // KHÔNG bỏ — chỉ đếm. Claim vẫn đi tiếp để Gatekeeper loại với
                // lý do NoEvidence, và con số này báo trước điều đó.
                $d->claimsMissingQuote++;
            }

            $claims[] = new CandidateClaim(
                $entityId,
                (string) $item['attribute'],
                $item['value'] ?? null,
                // Thiếu quote → để RỖNG. Gatekeeper sẽ loại với lý do NoEvidence.
                // Tuyệt đối không bịa ra một quote mặc định.
                $quote,
                (float) ($item['confidence'] ?? 0.0),
                (string) ($item['confidence_reason'] ?? ''),
            );
            $d->claimsAccepted++;
        }

        return $claims;
    }

    /**
     * @return list<CandidateRelation>
     */
    private function relations(mixed $raw, ParserDiagnostics $d): array
    {
        if (! is_array($raw)) {
            $d->markSectionMalformed('relations');

            return [];
        }

        $relations = [];

        foreach ($raw as $i => $item) {
            $d->relationsSeen++;

            if (! is_array($item) || ! isset($item['from'], $item['to'], $item['type'])) {
                $d->relationsDroppedInvalidShape++;

                continue;
            }

            $d->relationsAccepted++;
            $relations[] = new CandidateRelation(
                (string) ($item['id'] ?? 'r'.($i + 1)),
                (string) $item['from'],
                (string) $item['to'],
                (string) $item['type'],
                (string) ($item['evidence_quote'] ?? ''),
                (float) ($item['confidence'] ?? 0.0),
            );
        }

        return $relations;
    }

    /**
     * @return list<CandidateEvent>
     */
    private function events(mixed $raw, ParserDiagnostics $d): array
    {
        if (! is_array($raw)) {
            $d->markSectionMalformed('events');

            return [];
        }

        $events = [];

        foreach ($raw as $i => $item) {
            $d->eventsSeen++;

            if (! is_array($item) || ! isset($item['type'], $item['entity_id'])) {
                $d->eventsDroppedInvalidShape++;

                continue;
            }

            $d->eventsAccepted++;
            $events[] = new CandidateEvent(
                (string) ($item['id'] ?? 'e'.($i + 1)),
                (string) $item['type'],
                (string) $item['entity_id'],
                (string) ($item['evidence_quote'] ?? ''),
                (float) ($item['confidence'] ?? 0.0),
            );
        }

        return $events;
    }
}
