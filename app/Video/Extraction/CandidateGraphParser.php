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
 */
final class CandidateGraphParser
{
    public function parse(string $json): CandidateWorldGraph
    {
        $data = json_decode($this->unwrap($json), true);

        if (! is_array($data)) {
            throw new MalformedExtraction('LLM không trả về JSON hợp lệ: ' . mb_strimwidth($json, 0, 200, '…'));
        }

        return new CandidateWorldGraph(
            $this->entities($data['entities'] ?? []),
            $this->relations($data['relations'] ?? []),
            $this->events($data['events'] ?? []),
        );
    }

    /** LLM rất hay bọc JSON trong ```json … ``` dù được bảo đừng. */
    private function unwrap(string $text): string
    {
        $text = trim($text);

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $text, $m)) {
            return trim($m[1]);
        }

        return $text;
    }

    /**
     * @return list<CandidateEntity>
     */
    private function entities(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $entities = [];

        foreach ($raw as $item) {
            if (! is_array($item) || ! isset($item['id'], $item['type'])) {
                continue; // không định danh được thì không có gì để gác
            }

            $entities[] = new CandidateEntity(
                (string) $item['id'],
                (string) $item['type'],
                $this->claims($item['claims'] ?? [], (string) $item['id']),
                isset($item['name']) ? (string) $item['name'] : null,
                (string) ($item['name_quote'] ?? ''),
                (float) ($item['confidence'] ?? 0.0),
                // B1 (2026-07-22): parse song song claims thường — CHƯA nơi
                // nào tiêu thụ ngoài đo precision (xem SemanticClaimPrecisionAnalyzer).
                $this->claims($item['semantic_claims'] ?? [], (string) $item['id']),
            );
        }

        return $entities;
    }

    /**
     * @return list<CandidateClaim>
     */
    private function claims(mixed $raw, string $entityId): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $claims = [];

        foreach ($raw as $item) {
            if (! is_array($item) || ! isset($item['attribute'])) {
                continue;
            }

            $claims[] = new CandidateClaim(
                $entityId,
                (string) $item['attribute'],
                $item['value'] ?? null,
                // Thiếu quote → để RỖNG. Gatekeeper sẽ loại với lý do NoEvidence.
                // Tuyệt đối không bịa ra một quote mặc định.
                (string) ($item['evidence_quote'] ?? ''),
                (float) ($item['confidence'] ?? 0.0),
                (string) ($item['confidence_reason'] ?? ''),
            );
        }

        return $claims;
    }

    /**
     * @return list<CandidateRelation>
     */
    private function relations(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $relations = [];

        foreach ($raw as $i => $item) {
            if (! is_array($item) || ! isset($item['from'], $item['to'], $item['type'])) {
                continue;
            }

            $relations[] = new CandidateRelation(
                (string) ($item['id'] ?? 'r' . ($i + 1)),
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
    private function events(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $events = [];

        foreach ($raw as $i => $item) {
            if (! is_array($item) || ! isset($item['type'], $item['entity_id'])) {
                continue;
            }

            $events[] = new CandidateEvent(
                (string) ($item['id'] ?? 'e' . ($i + 1)),
                (string) $item['type'],
                (string) $item['entity_id'],
                (string) ($item['evidence_quote'] ?? ''),
                (float) ($item['confidence'] ?? 0.0),
            );
        }

        return $events;
    }
}
