<?php

namespace Tests\Video\Extraction;

use App\Video\Article\RawArticle;
use App\Video\Evidence\EvidenceIndex;
use App\Video\Evidence\EvidenceSource;
use App\Video\Extraction\CandidateGraphParser;
use App\Video\Extraction\ClaudeExtractor;
use App\Video\Extraction\ExtractionResult;
use App\Video\Extraction\MalformedExtraction;
use App\Video\Extraction\RecordedExtractor;
use App\Video\Extraction\RecordingMissing;
use App\Video\Gatekeeper\EvidenceGatekeeper;
use App\Video\Gatekeeper\RejectionReason;
use App\Video\Llm\ApprovalGate;
use App\Video\Llm\ApprovalRequired;
use App\Video\Llm\DenyByDefaultGate;
use App\Video\Llm\GatedLlmClient;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Llm\LlmResponse;
use PHPUnit\Framework\TestCase;

class ExtractionTest extends TestCase
{
    private const RESPONSE = <<<'JSON'
    {
      "entities": [
        {
          "id": "moonrise", "type": "vehicle", "name": "Moonrise", "name_quote": "Moonrise",
          "confidence": 0.95,
          "claims": [
            { "attribute": "hull_color", "value": "grey", "evidence_quote": "grey hull", "confidence": 0.9 },
            { "attribute": "length_m", "value": 101, "evidence_quote": "101 metres", "confidence": 0.95 },
            { "attribute": "builder", "value": "Feadship", "evidence_quote": "built by Feadship", "confidence": 0.99 }
          ]
        }
      ],
      "relations": [],
      "events": [
        { "id": "e1", "type": "construction", "entity_id": "moonrise",
          "evidence_quote": "built in the shipyard", "confidence": 0.9 }
      ]
    }
    JSON;

    private EvidenceIndex $index;
    private RawArticle $article;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->index = (new EvidenceIndex())
            ->add(EvidenceSource::Headline, 'Moonrise sold for €325M')
            ->add(EvidenceSource::Body, 'The grey hull measures 101 metres. She was built in the shipyard.');

        $this->article = new RawArticle('moonrise-test', 'Moonrise sold for €325M', '<p>x</p>');
        $this->tmpDir = sys_get_temp_dir() . '/video-extraction-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            array_map('unlink', glob($this->tmpDir . '/*.json') ?: []);
            rmdir($this->tmpDir);
        }
    }

    private function llmReturning(string $text): LlmClient
    {
        return new class($text) implements LlmClient {
            public function __construct(private readonly string $text)
            {
            }

            public function complete(LlmRequest $request): LlmResponse
            {
                return new LlmResponse($this->text, 'claude-opus-4-8', 1200, 400, 850, 0.012, $this->text);
            }
        };
    }

    private function allowAll(): ApprovalGate
    {
        return new class() implements ApprovalGate {
            public function allows(LlmRequest $request, float $estimatedCostUsd): bool
            {
                return true;
            }
        };
    }

    // ---- Parser: khoan dung hình thức, không bù nội dung ----

    public function test_parser_unwraps_markdown_fences(): void
    {
        // LLM rất hay bọc JSON trong ```json dù được bảo đừng.
        $graph = (new CandidateGraphParser())->parse("```json\n" . self::RESPONSE . "\n```");

        $this->assertCount(1, $graph->entities);
    }

    public function test_parser_never_invents_a_missing_quote(): void
    {
        $graph = (new CandidateGraphParser())->parse('{"entities":[{"id":"x","type":"vehicle","claims":[{"attribute":"hull_color","value":"grey"}]}]}');

        // Để rỗng, KHÔNG bịa. Gatekeeper sẽ loại với lý do NoEvidence.
        $this->assertSame('', $graph->entities[0]->claims[0]->evidenceQuote);
    }

    public function test_parser_does_not_filter_by_ontology_or_confidence(): void
    {
        // Lọc ở parser sẽ khiến GatekeeperReport nói dối về tỷ lệ sống sót.
        $graph = (new CandidateGraphParser())->parse('{"entities":[{"id":"x","type":"superyacht","confidence":0.01,"claims":[]}]}');

        $this->assertSame('superyacht', $graph->entities[0]->type);
    }

    public function test_parser_fails_fast_on_garbage(): void
    {
        $this->expectException(MalformedExtraction::class);

        (new CandidateGraphParser())->parse('I could not find any entities, sorry!');
    }

    // ---- Cổng duyệt ----

    public function test_paid_call_is_denied_by_default(): void
    {
        $client = new GatedLlmClient($this->llmReturning(self::RESPONSE));

        $this->expectException(ApprovalRequired::class);

        (new ClaudeExtractor($client))->extract($this->article, $this->index);
    }

    public function test_approval_error_states_the_estimated_cost(): void
    {
        $client = new GatedLlmClient($this->llmReturning(self::RESPONSE), new DenyByDefaultGate());

        try {
            (new ClaudeExtractor($client))->extract($this->article, $this->index);
            $this->fail('Đáng lẽ phải ném ApprovalRequired');
        } catch (ApprovalRequired $e) {
            $this->assertGreaterThan(0, $e->estimatedTokens);
            $this->assertStringContainsString('cần duyệt trước', $e->getMessage());
        }
    }

    public function test_explicit_approval_lets_the_call_through(): void
    {
        $client = new GatedLlmClient($this->llmReturning(self::RESPONSE), $this->allowAll());

        $result = (new ClaudeExtractor($client))->extract($this->article, $this->index);

        $this->assertCount(1, $result->candidates->entities);
    }

    // ---- AI provenance ----

    public function test_result_carries_full_ai_provenance(): void
    {
        $client = new GatedLlmClient($this->llmReturning(self::RESPONSE), $this->allowAll());

        $result = (new ClaudeExtractor($client))->extract($this->article, $this->index);

        // Sáu tháng sau, khi một sự thật sai lọt lưới, đây là thứ duy nhất cho
        // biết lúc đó chạy model nào và Claude thật sự đã nói gì.
        $this->assertSame('claude-opus-4-8', $result->model);
        $this->assertSame(ClaudeExtractor::INSTRUCTION_VERSION, $result->instructionVersion);
        $this->assertSame(1200, $result->tokensIn);
        $this->assertSame(850, $result->latencyMs);
        $this->assertStringContainsString('Feadship', $result->raw);
    }

    // ---- Recorded: regression không tốn tiền ----

    public function test_recorded_replays_without_network_or_cost(): void
    {
        $recorded = new RecordedExtractor($this->tmpDir);
        $client   = new GatedLlmClient($this->llmReturning(self::RESPONSE), $this->allowAll());

        $live = (new ClaudeExtractor($client))->extract($this->article, $this->index);
        $recorded->record($this->article, $live);

        $replayed = $recorded->extract($this->article, $this->index);

        $this->assertSame($live->candidateCount(), $replayed->candidateCount());
        $this->assertSame($live->raw, $replayed->raw);
        $this->assertSame(0.0, $replayed->costUsd, 'Phát lại không tốn tiền — báo $0 mới là sự thật');
    }

    public function test_recorded_never_falls_back_to_a_live_call(): void
    {
        // Fallback ngầm sẽ biến CI thành thứ đốt tiền và cần mạng.
        $this->expectException(RecordingMissing::class);

        (new RecordedExtractor($this->tmpDir))->extract($this->article, $this->index);
    }

    // ---- Gatekeeper vẫn gác đúng trên output thật của LLM ----

    public function test_gatekeeper_drops_the_unevidenced_claim_from_a_real_response(): void
    {
        $client = new GatedLlmClient($this->llmReturning(self::RESPONSE), $this->allowAll());
        $result = (new ClaudeExtractor($client))->extract($this->article, $this->index);

        $report = (new EvidenceGatekeeper())->verify($result->candidates, $this->index);
        $entity = $report->graph->entity('moonrise');

        // Bài nói "grey hull" và "101 metres" → sống.
        $this->assertSame('grey', $entity->value('hull_color'));
        $this->assertSame(101, $entity->value('length_m'));

        // Claude khẳng định builder=Feadship với confidence 0.99. Đó là SỰ THẬT.
        // Nhưng bài báo này không nói → không tồn tại.
        $this->assertNull($entity->value('builder'));
        $this->assertCount(1, $report->rejectionsFor(RejectionReason::QuoteNotFound));
    }

    public function test_extractor_output_is_only_hypotheses_never_verified_truth(): void
    {
        $client = new GatedLlmClient($this->llmReturning(self::RESPONSE), $this->allowAll());

        $result = (new ClaudeExtractor($client))->extract($this->article, $this->index);

        // Extractor chỉ đưa giả thuyết. Không có đường nào nó tự tạo được
        // VerifiedWorldGraph — chỉ Gatekeeper mới có quyền đó.
        $this->assertInstanceOf(ExtractionResult::class, $result);
        $this->assertSame(\App\Video\Extraction\CandidateWorldGraph::class, $result->candidates::class);
    }

    public function test_candidate_entity_semantic_claims_defaults_empty_b0_contract_only(): void
    {
        // B0 (2026-07-22): field CHỈ tồn tại trong contract, CHƯA nơi nào sinh
        // hay đọc nó (ClaudeExtractor chưa sinh, Gatekeeper chưa verify). Test
        // này canh default không đổi ngoài ý muốn khi B1/B2 nối dây sau này.
        $entity = new \App\Video\Extraction\CandidateEntity('moonrise', 'vehicle');

        $this->assertSame([], $entity->semanticClaims);
    }
}
