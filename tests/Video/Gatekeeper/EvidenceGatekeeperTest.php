<?php

namespace Tests\Video\Gatekeeper;

use App\Video\Evidence\EvidenceIndex;
use App\Video\Evidence\EvidenceSource;
use App\Video\Evidence\ProvenanceLevel;
use App\Video\Extraction\CandidateClaim;
use App\Video\Extraction\CandidateEntity;
use App\Video\Extraction\CandidateEvent;
use App\Video\Extraction\CandidateRelation;
use App\Video\Extraction\CandidateWorldGraph;
use App\Video\Gatekeeper\EvidenceGatekeeper;
use App\Video\Gatekeeper\RejectionReason;
use PHPUnit\Framework\TestCase;

/**
 * Bất biến: "Không có bằng chứng → không tồn tại."
 *
 * Các test dưới đây dựng lại đúng những cách LLM sẽ phá hoại: bịa sự thật đúng
 * nhưng bài không nói, bịa câu trích, trích câu có thật rồi gắn giá trị khác,
 * suy quan hệ từ việc hai entity cùng xuất hiện, suy sự kiện từ loại entity.
 */
class EvidenceGatekeeperTest extends TestCase
{
    private EvidenceGatekeeper $gatekeeper;
    private EvidenceIndex $index;

    protected function setUp(): void
    {
        $this->gatekeeper = new EvidenceGatekeeper();

        // Bài báo Moonrise rút gọn. Cố tình KHÔNG nhắc tới Jan Koum hay Feadship
        // ở thân bài — để chứng minh Gatekeeper loại được sự thật ĐÚNG nhưng
        // không có bằng chứng.
        $this->index = (new EvidenceIndex())
            ->add(EvidenceSource::Headline, 'Moonrise sold for €325M')
            ->add(EvidenceSource::Body, 'The grey hull measures 101 metres and carries a vertical bow. '
                . 'She was built in the shipyard and launched last spring. '
                . 'The vessel is the successor to an earlier 99.95 metre yacht.')
            ->add(EvidenceSource::Table, 'Top speed: 19.5 knots')
            ->add(EvidenceSource::Caption, 'Moonrise under way at dusk');
    }

    private function entity(array $claims, string $type = 'vehicle'): CandidateWorldGraph
    {
        return new CandidateWorldGraph([new CandidateEntity('moonrise', $type, $claims)]);
    }

    // ---- Sự thật có bằng chứng thì sống ----

    public function test_accepts_claim_quoted_verbatim(): void
    {
        $report = $this->gatekeeper->verify(
            $this->entity([new CandidateClaim('moonrise', 'hull_color', 'grey', 'grey hull')]),
            $this->index,
        );

        $entity = $report->graph->entity('moonrise');

        $this->assertNotNull($entity);
        $this->assertSame('grey', $entity->value('hull_color'));
        $this->assertSame([], $report->rejections);
    }

    public function test_accepts_measurement_read_out_of_the_quote(): void
    {
        $report = $this->gatekeeper->verify(
            $this->entity([new CandidateClaim('moonrise', 'length_m', 101, '101 metres')]),
            $this->index,
        );

        $entity = $report->graph->entity('moonrise');

        $this->assertSame(101, $entity->value('length_m'));
        $this->assertSame(ProvenanceLevel::NormalizedValue, $entity->attributes['length_m'][0]->level);
    }

    public function test_accepts_evidence_from_table_not_only_body(): void
    {
        $report = $this->gatekeeper->verify(
            $this->entity([new CandidateClaim('moonrise', 'top_speed_knots', 19.5, 'Top speed: 19.5 knots')]),
            $this->index,
        );

        $entity = $report->graph->entity('moonrise');

        $this->assertSame(19.5, $entity->value('top_speed_knots'));
        $this->assertSame(EvidenceSource::Table, $entity->attributes['top_speed_knots'][0]->evidence->source);
    }

    public function test_accepts_currency_scale_written_in_the_quote(): void
    {
        $report = $this->gatekeeper->verify(
            $this->entity([new CandidateClaim('moonrise', 'price_eur', 325000000, '€325M')]),
            $this->index,
        );

        $this->assertSame(325000000, $report->graph->entity('moonrise')->value('price_eur'));
    }

    // ---- LLM phá hoại thì bị chặn ----

    /**
     * Ca kinh điển: Claude BIẾT Moonrise do Feadship đóng. Đó là sự thật.
     * Nhưng bài báo không hề nói — nên nó không tồn tại.
     */
    public function test_rejects_true_fact_that_the_article_never_states(): void
    {
        $report = $this->gatekeeper->verify(
            $this->entity([
                new CandidateClaim('moonrise', 'hull_color', 'grey', 'grey hull'),
                new CandidateClaim('moonrise', 'builder', 'Feadship', 'built by Feadship', 0.99),
            ]),
            $this->index,
        );

        $this->assertNull($report->graph->entity('moonrise')->value('builder'));
        $this->assertCount(1, $report->rejectionsFor(RejectionReason::QuoteNotFound));
    }

    /** Confidence 0.99 không cứu nổi một giả thuyết không có bằng chứng. */
    public function test_high_confidence_does_not_override_missing_evidence(): void
    {
        $report = $this->gatekeeper->verify(
            $this->entity([new CandidateClaim('moonrise', 'owner', 'Jan Koum', 'owned by Jan Koum', 0.99)]),
            $this->index,
        );

        $this->assertTrue($report->graph->isEmpty());
    }

    /**
     * Quote CÓ THẬT trong bài, nhưng không nói lên giá trị được khai báo.
     * Không có bước 2 thì LLM chỉ cần trích một câu có thật rồi gắn số nào cũng được.
     */
    public function test_rejects_real_quote_that_does_not_support_the_value(): void
    {
        $report = $this->gatekeeper->verify(
            $this->entity([new CandidateClaim('moonrise', 'length_m', 140, '101 metres')]),
            $this->index,
        );

        $this->assertTrue($report->graph->isEmpty());
        $this->assertCount(1, $report->rejectionsFor(RejectionReason::ValueNotSupported));
    }

    /** Quy đổi đơn vị cần biết hệ số 3.28084 — tri thức ngoài span. */
    public function test_rejects_unit_conversion_as_external_knowledge(): void
    {
        $report = $this->gatekeeper->verify(
            $this->entity([new CandidateClaim('moonrise', 'length_ft', 331.36, '101 metres')]),
            $this->index,
        );

        $this->assertCount(1, $report->rejectionsFor(RejectionReason::ValueNotSupported));
    }

    /**
     * Containment là bắt buộc ("grey hull" phải chống lưng được cho `grey`),
     * nhưng chính nó mở ra lỗ hổng: "not grey" cũng CHỨA "grey". Không có guard
     * thì Gatekeeper sẽ khẳng định thân tàu màu xám trong khi bài báo nói ngược
     * lại — đúng loại sai mà nó sinh ra để chặn.
     */
    public function test_rejects_value_that_the_quote_negates(): void
    {
        $index = (new EvidenceIndex())->add(EvidenceSource::Body, 'The hull is not grey.');

        $report = $this->gatekeeper->verify(
            $this->entity([new CandidateClaim('moonrise', 'hull_color', 'grey', 'not grey')]),
            $index,
        );

        $this->assertTrue($report->graph->isEmpty());
        $this->assertCount(1, $report->rejectionsFor(RejectionReason::ValueNotSupported));
    }

    /**
     * Sự thật phủ định không đọc thẳng ra từ chữ được: hiểu "integrated
     * receivers INSTEAD OF radomes" ⇒ `domes = false` là suy luận, không phải
     * trích xuất. Nên `domes: false` KHÔNG qua được Gatekeeper — và đó là đúng.
     * Prohibition trong RenderPlan là luật biên tập, không phải fact trích xuất.
     */
    public function test_negative_boolean_facts_do_not_survive_extraction(): void
    {
        $index = (new EvidenceIndex())
            ->add(EvidenceSource::Body, 'She uses integrated satellite receivers instead of radome housings.');

        $report = $this->gatekeeper->verify(
            $this->entity([new CandidateClaim('moonrise', 'domes', false, 'integrated satellite receivers instead of radome housings')]),
            $index,
        );

        $this->assertTrue($report->graph->isEmpty());
        $this->assertCount(1, $report->rejectionsFor(RejectionReason::ValueNotSupported));
    }

    public function test_rejects_entity_type_outside_the_ontology(): void
    {
        $report = $this->gatekeeper->verify(
            $this->entity([new CandidateClaim('moonrise', 'hull_color', 'grey', 'grey hull')], 'superyacht'),
            $this->index,
        );

        $this->assertTrue($report->graph->isEmpty());
        $this->assertCount(1, $report->rejectionsFor(RejectionReason::UnknownEntityType));
    }

    public function test_rejects_relation_the_article_never_asserts(): void
    {
        $graph = new CandidateWorldGraph(
            [
                new CandidateEntity('moonrise',  'vehicle', [new CandidateClaim('moonrise', 'hull_color', 'grey', 'grey hull')]),
                new CandidateEntity('moonrise20', 'vehicle', [new CandidateClaim('moonrise20', 'length_m', 99.95, '99.95 metre')]),
            ],
            [new CandidateRelation('r1', 'moonrise', 'moonrise20', 'successor_of', 'is a newer version of the older boat')],
        );

        $report = $this->gatekeeper->verify($graph, $this->index);

        $this->assertSame([], $report->graph->relations);
        $this->assertCount(1, $report->rejectionsFor(RejectionReason::QuoteNotFound));
    }

    public function test_accepts_relation_the_article_does_assert(): void
    {
        $graph = new CandidateWorldGraph(
            [
                new CandidateEntity('moonrise',  'vehicle', [new CandidateClaim('moonrise', 'hull_color', 'grey', 'grey hull')]),
                new CandidateEntity('moonrise20', 'vehicle', [new CandidateClaim('moonrise20', 'length_m', 99.95, '99.95 metre')]),
            ],
            [new CandidateRelation('r1', 'moonrise', 'moonrise20', 'successor_of', 'the successor to an earlier 99.95 metre yacht')],
        );

        $report = $this->gatekeeper->verify($graph, $this->index);

        $this->assertCount(1, $report->graph->relations);
        $this->assertSame('successor_of', $report->graph->relations[0]->type);
    }

    /** Event không được sinh ra chỉ vì entity là `vehicle`. */
    public function test_rejects_event_inferred_from_entity_type(): void
    {
        $graph = new CandidateWorldGraph(
            [new CandidateEntity('moonrise', 'vehicle', [new CandidateClaim('moonrise', 'hull_color', 'grey', 'grey hull')])],
            [],
            [new CandidateEvent('e_sea', 'sea_trial', 'moonrise', 'underwent sea trials')],
        );

        $report = $this->gatekeeper->verify($graph, $this->index);

        $this->assertSame([], $report->graph->events);
    }

    public function test_accepts_event_the_article_states(): void
    {
        $graph = new CandidateWorldGraph(
            [new CandidateEntity('moonrise', 'vehicle', [new CandidateClaim('moonrise', 'hull_color', 'grey', 'grey hull')])],
            [],
            [new CandidateEvent('e1', 'construction', 'moonrise', 'built in the shipyard')],
        );

        $report = $this->gatekeeper->verify($graph, $this->index);

        $this->assertCount(1, $report->graph->events);
        $this->assertSame('construction', $report->graph->events[0]->type);
    }

    public function test_rejects_relation_pointing_at_an_unverified_entity(): void
    {
        $graph = new CandidateWorldGraph(
            [new CandidateEntity('moonrise', 'vehicle', [new CandidateClaim('moonrise', 'hull_color', 'grey', 'grey hull')])],
            [new CandidateRelation('r1', 'moonrise', 'ghost', 'successor_of', 'the successor to an earlier 99.95 metre yacht')],
        );

        $report = $this->gatekeeper->verify($graph, $this->index);

        $this->assertSame([], $report->graph->relations);
        $this->assertCount(1, $report->rejectionsFor(RejectionReason::DanglingReference));
    }

    // ---- Lỗi do cú gọi Claude thật (2026-07-17) phơi ra ----

    /**
     * Feadship có tên trong bài và neo quan hệ "built_by", nhưng bài KHÔNG mô tả
     * thuộc tính nào của chính Feadship. Luật cũ ("hết claim thì loại") đã ném
     * mất nó cùng De Voogt và Remi Tessier, kéo theo 5 quan hệ thành dangling —
     * trong đó có sự thật quan trọng nhất bài báo.
     *
     * Entity tồn tại ≠ Entity có thứ để render.
     */
    public function test_entity_with_a_name_but_no_attributes_survives(): void
    {
        $index = (new EvidenceIndex())
            ->add(EvidenceSource::Body, 'The grey hull was built by Feadship in the shipyard.');

        $graph = new CandidateWorldGraph(
            [
                new CandidateEntity('moonrise', 'vehicle', [new CandidateClaim('moonrise', 'hull_color', 'grey', 'grey hull')]),
                new CandidateEntity('feadship', 'building', [], 'Feadship', 'Feadship'),
            ],
            [new CandidateRelation('r1', 'moonrise', 'feadship', 'built_by', 'built by Feadship')],
        );

        $report = $this->gatekeeper->verify($graph, $index);
        $feadship = $report->graph->entity('feadship');

        $this->assertNotNull($feadship, 'Entity chỉ-có-tên vẫn là node hợp lệ của World Graph');
        $this->assertTrue($feadship->isAnchorOnly());
        $this->assertCount(1, $report->graph->relations, 'quan hệ built_by phải sống sót cùng nó');
    }

    public function test_entity_with_neither_name_nor_attributes_is_rejected(): void
    {
        $graph = new CandidateWorldGraph([new CandidateEntity('ghost', 'vehicle', [])]);

        $report = $this->gatekeeper->verify($graph, $this->index);

        $this->assertTrue($report->graph->isEmpty());
        $this->assertCount(1, $report->rejectionsFor(RejectionReason::NoVerifiedClaims));
    }

    /**
     * Một con tàu có beach club VÀ spa VÀ helipad. Đó không phải mâu thuẫn.
     * Code cũ gọi đó là CONFLICT và vứt hết trừ cái đầu.
     */
    public function test_one_attribute_can_hold_several_values(): void
    {
        $index = (new EvidenceIndex())
            ->add(EvidenceSource::Body, 'A beach club, a helipad, and a spa area are some of the amenities.');

        $report = $this->gatekeeper->verify(
            $this->entity([
                new CandidateClaim('moonrise', 'onboard_amenity', 'beach club', 'A beach club'),
                new CandidateClaim('moonrise', 'onboard_amenity', 'helipad', 'a helipad'),
                new CandidateClaim('moonrise', 'onboard_amenity', 'spa area', 'a spa area'),
            ]),
            $index,
        );

        $entity = $report->graph->entity('moonrise');

        $this->assertSame(['beach club', 'helipad', 'spa area'], $entity->values('onboard_amenity'));
        $this->assertSame([], $report->rejections);
        $this->assertSame(
            ['beach club', 'helipad', 'spa area'],
            $entity->renderableAttributes()['onboard_amenity'],
            'nhiều giá trị → list; một giá trị → scalar',
        );
    }

    /** Claude hay nhắc lại cùng một sự thật ở hai chỗ trong bài. */
    public function test_identical_repeated_claims_are_deduplicated(): void
    {
        $report = $this->gatekeeper->verify(
            $this->entity([
                new CandidateClaim('moonrise', 'hull_color', 'grey', 'grey hull'),
                new CandidateClaim('moonrise', 'hull_color', 'grey', 'The grey hull measures 101 metres'),
            ]),
            $this->index,
        );

        $this->assertSame(['grey'], $report->graph->entity('moonrise')->values('hull_color'));
    }

    // ---- Tính chất của chính Gatekeeper ----

    public function test_is_deterministic_across_runs(): void
    {
        $graph = $this->entity([
            new CandidateClaim('moonrise', 'length_m', 101, '101 metres'),
            new CandidateClaim('moonrise', 'builder', 'Feadship', 'built by Feadship'),
        ]);

        $first  = $this->gatekeeper->verify($graph, $this->index);
        $second = $this->gatekeeper->verify($graph, $this->index);

        $this->assertSame($first->summary(), $second->summary());
        $this->assertSame(
            array_map(fn ($r) => $r->describe(), $first->rejections),
            array_map(fn ($r) => $r->describe(), $second->rejections),
        );
    }

    public function test_report_explains_what_was_dropped_and_why(): void
    {
        $report = $this->gatekeeper->verify(
            $this->entity([
                new CandidateClaim('moonrise', 'hull_color', 'grey', 'grey hull'),
                new CandidateClaim('moonrise', 'builder', 'Feadship', 'built by Feadship'),
            ]),
            $this->index,
        );

        // Reject phải ồn ào: một Gatekeeper âm thầm bỏ nửa bài báo trông y hệt
        // một Gatekeeper hoạt động tốt.
        $this->assertStringContainsString('moonrise.builder', $report->rejections[0]->describe());
        $this->assertStringContainsString('bịa quote', $report->rejections[0]->describe());
        $this->assertGreaterThan(0.0, $report->survivalRate());
        $this->assertLessThan(1.0, $report->survivalRate());
    }

    public function test_identity_visual_referent_is_semantic_not_model_knowledge(): void
    {
        $graph = new CandidateWorldGraph([
            new CandidateEntity('moonrise', 'vehicle', [new CandidateClaim('moonrise', 'hull_color', 'grey', 'grey hull')], 'Moonrise', 'Moonrise'),
            new CandidateEntity('koum', 'human', [new CandidateClaim('koum', 'role', 'buyer', 'buyer')], 'Jan Koum', 'Jan Koum'),
        ]);

        $report = $this->gatekeeper->verify($graph, $this->index);

        // Con tàu: tên ghim một hình dạng cụ thể → visual_referent = true.
        // Laravel dừng ở đó. Việc Flux có vẽ ra mặt trăng khi thấy chữ
        // "Moonrise" hay không là chuyện của ProviderPass allowlist bên Python.
        $this->assertTrue($report->graph->entity('moonrise')->identity->visualReferent);
    }
}
