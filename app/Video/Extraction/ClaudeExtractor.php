<?php

namespace App\Video\Extraction;

use App\Video\Article\RawArticle;
use App\Video\Evidence\EvidenceIndex;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;

/**
 * Hypothesis Generator chạy trên một mô hình thật.
 *
 * Chỉ dùng cho integration test và lần ghi đầu tiên (có duyệt). KHÔNG BAO GIỜ
 * chạy trong CI — CI dùng RecordedExtractor.
 *
 * Nó không biết `app/Services/AI` tồn tại; nó chỉ biết LlmClient. Mai CMS sửa
 * AIService thì chỉ adapter chịu, Truth Layer không đổi.
 */
final class ClaudeExtractor implements Extractor
{
    /**
     * Version hoá có chủ ý. Sáu tháng sau, khi truy một hallucination, biết được
     * lúc đó dùng instruction nào là khác biệt giữa "sửa được" và "đoán mò".
     */
    public const INSTRUCTION_VERSION = 'extract-v1';

    /**
     * @param string $model Nhãn model để ghi vào provenance. Việc chọn model
     *                      THẬT nằm ở LlmClient — Extractor không được quyết,
     *                      vì nó không biết client nào phục vụ model nào.
     */
    public function __construct(
        private readonly LlmClient $llm,
        private readonly string $model = 'sonnet',
        private readonly CandidateGraphParser $parser = new CandidateGraphParser(),
    ) {
    }

    public function extract(RawArticle $article, EvidenceIndex $index): ExtractionResult
    {
        $request = new LlmRequest(
            $this->instruction(),
            $this->renderArticle($article, $index),
            self::INSTRUCTION_VERSION,
            $this->model,
        );

        $response = $this->llm->complete($request);

        return new ExtractionResult(
            $this->parser->parse($response->text),
            $response->model,
            self::INSTRUCTION_VERSION,
            $response->tokensIn,
            $response->tokensOut,
            $response->latencyMs,
            $response->costUsd,
            $response->raw !== '' ? $response->raw : $response->text,
        );
    }

    /**
     * Cho mô hình xem ĐÚNG văn bản mà Gatekeeper sẽ đi tìm.
     *
     * Nếu cho xem HTML thô, quote trả về sẽ mang theo markup hoặc khoảng trắng
     * khác, `EvidenceIndex::find()` sẽ trượt, và mọi claim bị loại oan — một
     * Gatekeeper hoạt động hoàn hảo mà không sự thật nào qua nổi.
     */
    private function renderArticle(RawArticle $article, EvidenceIndex $index): string
    {
        $lines = ["ARTICLE ID: {$article->id}", ''];

        foreach ($index->rawSegments() as $segment) {
            $lines[] = sprintf('[%s] %s', strtoupper($segment['source']->value), $segment['raw']);
        }

        return implode("\n", $lines);
    }

    /**
     * Chú ý những gì instruction này KHÔNG yêu cầu:
     *   - không xin offset  → mô hình đếm ký tự rất tệ và sẽ bịa số trông hợp lý
     *   - không xin scene/act/camera → Extractor chỉ đưa giả thuyết, không giúp Planner
     *   - không xin suy luận → nói thẳng: thà bỏ sót còn hơn suy diễn
     */
    private function instruction(): string
    {
        return <<<'TEXT'
        You extract HYPOTHESES about the world from a news article. You do not decide what is true.
        A separate deterministic verifier checks every hypothesis against the article text and
        silently discards anything it cannot confirm. Your job is only to propose.

        Return ONLY raw JSON. No markdown fences, no commentary.

        {
          "entities": [
            {
              "id": "snake_case_id",
              "type": "human|living_object|vehicle|building|landscape|physical_object|event|effect",
              "name": "proper name, if the article gives one",
              "name_quote": "exact text from the article containing that name",
              "confidence": 0.0-1.0,
              "claims": [
                {
                  "attribute": "snake_case_name",
                  "value": 101,
                  "evidence_quote": "exact text from the article that states this",
                  "confidence": 0.0-1.0
                }
              ]
            }
          ],
          "relations": [
            { "id": "r1", "from": "entity_id", "to": "entity_id", "type": "snake_case",
              "evidence_quote": "exact text stating the relation", "confidence": 0.0-1.0 }
          ],
          "events": [
            { "id": "e1", "type": "snake_case", "entity_id": "entity_id",
              "evidence_quote": "exact text stating the event happened", "confidence": 0.0-1.0 }
          ]
        }

        RULES — the verifier enforces these; breaking them only wastes your output:

        1. evidence_quote MUST be copied CHARACTER-FOR-CHARACTER from the article text above.
           Never paraphrase, never reconstruct from memory. If you cannot copy an exact span
           that states the claim, omit the claim entirely.

        2. Never state anything you know from outside the article. If you happen to know who
           built or owns something and the article does not say it, that fact does not exist here.

        3. Quote NARROWLY — the shortest span that states the fact. "grey hull" not the whole
           paragraph. Long spans get rejected when they contain negations elsewhere.

        4. Do not infer. Do not convert units. Do not compute. "101 metres" is a fact;
           "331 feet" is not. "very large" does not mean a length.

        5. Negative facts ("no radomes", "not grey") cannot be verified and will be dropped.
           Do not propose them. State only what IS, quoted directly.

        6. entity.type must be one of the eight listed above — never invent a new one.
           A more specific kind of thing is not a type; it belongs in claims.

        7. Relations and events need their OWN evidence. Two things appearing in the same
           article does not make a relation. An object being a vehicle does not make a
           construction event. The article must say it happened.

        8. Omitting is free. Guessing is not. When unsure, leave it out.
        TEXT;
    }
}
