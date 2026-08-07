<?php

namespace Tests\Video\Extraction;

use App\Video\Extraction\CandidateGraphParser;
use App\Video\Extraction\MalformedExtraction;
use App\Video\Extraction\ParserDiagnostics;
use PHPUnit\Framework\TestCase;

/**
 * Parser vẫn BỎ dữ liệu hỏng như cũ — nhưng không còn bỏ trong im lặng.
 *
 * Sprint 0 (2026-08-06) có một ràng buộc cứng: KHÔNG đổi ngữ nghĩa. Cùng một
 * JSON hợp lệ phải cho ra cùng một graph như trước. Vì vậy mỗi test ở đây kiểm
 * HAI điều: graph đúng như cũ, VÀ con số đếm được đúng.
 *
 * Lý do tồn tại: bài "ISA Amarcord 82" ra 0 fact, và năm nguyên nhân khác nhau
 * đều cho cùng một triệu chứng. Sau nhóm test này, năm nguyên nhân đó đọc được
 * từ số — không phải đoán.
 */
final class ParserDiagnosticsTest extends TestCase
{
    private function parse(string $json, ?ParserDiagnostics &$d = null): mixed
    {
        return (new CandidateGraphParser)->parse($json, $d);
    }

    public function test_existing_callers_may_still_omit_the_diagnostics_argument(): void
    {
        // Bốn test cũ và RecordedExtractor gọi parse() MỘT tham số. Nếu dòng
        // này gãy thì Sprint 0 đã phá hợp đồng nó hứa giữ nguyên.
        $graph = (new CandidateGraphParser)->parse(
            '{"entities":[{"id":"x","type":"vehicle","claims":[{"attribute":"hull_color","value":"grey","evidence_quote":"grey hull"}]}]}'
        );

        $this->assertCount(1, $graph->entities);
        $this->assertCount(1, $graph->entities[0]->claims);
    }

    public function test_a_clean_extraction_reports_clean(): void
    {
        $graph = $this->parse(
            '{"entities":[{"id":"x","type":"vehicle","claims":[{"attribute":"hull_color","value":"grey","evidence_quote":"grey hull"}]}],'
            .'"relations":[{"id":"r1","from":"x","to":"y","type":"docked_at","evidence_quote":"docked at the pier"}],'
            .'"events":[{"id":"e1","type":"launched","entity_id":"x","evidence_quote":"was launched"}]}',
            $d,
        );

        $this->assertTrue($d->isClean());
        $this->assertSame(1, $d->entitiesSeen);
        $this->assertSame(1, $d->entitiesAccepted);
        $this->assertSame(1, $d->claimsSeen);
        $this->assertSame(1, $d->claimsAccepted);
        $this->assertSame(0, $d->claimsMissingQuote);
        $this->assertSame(1, $d->relationsAccepted);
        $this->assertSame(1, $d->eventsAccepted);
        $this->assertCount(1, $graph->entities);
    }

    public function test_nested_claims_are_counted_not_swallowed(): void
    {
        // GIẢ THUYẾT HÀNG ĐẦU cho bài ISA: model trả claim LỒNG NHAU thay vì
        // phẳng. Trước Sprint 0, ca này cho ra "0 claim" y hệt ca "model không
        // tìm thấy gì" — hai bệnh, một triệu chứng.
        $graph = $this->parse(
            '{"entities":[{"id":"yacht","type":"vehicle","claims":[{"hull":{"color":"white"}},{"exterior":{"pool":"glass"}}]}]}',
            $d,
        );

        $this->assertCount(1, $graph->entities);
        $this->assertSame([], $graph->entities[0]->claims, 'hành vi cũ: claim hỏng vẫn bị bỏ');

        $this->assertSame(2, $d->claimsSeen, 'nhưng nay ĐẾM được là đã thấy 2');
        $this->assertSame(2, $d->claimsDroppedInvalidShape);
        $this->assertSame(0, $d->claimsAccepted);
        $this->assertFalse($d->isClean());
    }

    public function test_a_claim_without_a_quote_is_kept_but_flagged(): void
    {
        // KHÔNG bỏ — Gatekeeper mới là nơi loại, với lý do NoEvidence. Con số
        // này BÁO TRƯỚC điều đó, nên khi Gatekeeper loại nhiều ta biết ngay
        // nguyên nhân là LLM quên quote, không phải verifier khắt khe.
        $graph = $this->parse(
            '{"entities":[{"id":"x","type":"vehicle","claims":[{"attribute":"pool","value":"swimming pool"}]}]}',
            $d,
        );

        $this->assertCount(1, $graph->entities[0]->claims, 'vẫn đi tiếp tới Gatekeeper');
        $this->assertSame('', $graph->entities[0]->claims[0]->evidenceQuote);
        $this->assertSame(1, $d->claimsAccepted);
        $this->assertSame(1, $d->claimsMissingQuote);
    }

    public function test_an_entity_without_id_or_type_is_counted(): void
    {
        $graph = $this->parse(
            '{"entities":[{"id":"ok","type":"vehicle"},{"type":"vehicle"},{"id":"no_type"}]}',
            $d,
        );

        $this->assertCount(1, $graph->entities);
        $this->assertSame(3, $d->entitiesSeen);
        $this->assertSame(1, $d->entitiesAccepted);
        $this->assertSame(2, $d->entitiesDroppedInvalidShape);
    }

    public function test_a_whole_malformed_section_is_recorded(): void
    {
        // Nguy hiểm hơn từng item hỏng: mất NGUYÊN khối một lần. Bản cũ trả []
        // và không để lại dấu vết nào.
        $this->parse('{"entities":"none","relations":"none","events":"none"}', $d);

        $this->assertSame(['entities', 'relations', 'events'], $d->sectionsMalformed);
        $this->assertFalse($d->isClean());
    }

    public function test_claims_of_the_wrong_type_are_recorded_per_entity(): void
    {
        $this->parse('{"entities":[{"id":"yacht","type":"vehicle","claims":"unknown"}]}', $d);

        $this->assertContains('claims[yacht]', $d->sectionsMalformed);
    }

    public function test_a_code_fence_is_flagged_but_still_parsed(): void
    {
        $graph = $this->parse(
            "```json\n{\"entities\":[{\"id\":\"x\",\"type\":\"vehicle\"}]}\n```",
            $d,
        );

        $this->assertCount(1, $graph->entities, 'vẫn parse được như trước');
        $this->assertTrue($d->wrappedInCodeFence);
    }

    public function test_diagnostics_survive_a_malformed_json_throw(): void
    {
        // Ném rồi thì chỗ gọi vẫn phải đọc được diagnostics — nếu không, ca
        // "JSON hỏng hoàn toàn" lại thành một ô trống nữa.
        $d = null;

        try {
            $this->parse('I could not find any entities, sorry!', $d);
            $this->fail('phải ném MalformedExtraction');
        } catch (MalformedExtraction) {
            $this->assertInstanceOf(ParserDiagnostics::class, $d);
        }
    }

    public function test_diagnostics_serialise_to_one_json_column(): void
    {
        $this->parse('{"entities":[{"id":"x","type":"vehicle","claims":[{"foo":1}]}]}', $d);
        $out = $d->toArray();

        $this->assertSame(1, $out['claims_seen']);
        $this->assertSame(1, $out['claims_dropped_invalid_shape']);
        $this->assertArrayHasKey('sections_malformed', $out);
    }
}
