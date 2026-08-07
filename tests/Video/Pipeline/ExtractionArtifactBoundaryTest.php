<?php

namespace Tests\Video\Pipeline;

use App\Video\Article\RawArticle;
use App\Video\Director\ActionSelection;
use App\Video\Director\FakeDirector;
use App\Video\Extraction\CandidateClaim;
use App\Video\Extraction\CandidateEntity;
use App\Video\Extraction\CandidateWorldGraph;
use App\Video\Extraction\FakeExtractor;
use App\Video\Pipeline\PipelineAborted;
use App\Video\Pipeline\VideoPlanningPipeline;
use App\Video\Producer\FakeProducer;
use App\Video\Producer\ProducerOutput;
use App\Video\RenderPlan\RenderPlanMeta;
use PHPUnit\Framework\TestCase;

/**
 * Ranh giới ghi bằng chứng: pipeline phát ra `$world` VÀ `$report` ngay sau
 * Gatekeeper, trước khi Planning chạy.
 *
 * Bốn ca dưới đây đều hỏi CÙNG MỘT câu: "khi mọi thứ phía sau sụp, bằng chứng
 * về việc Truth Layer đã làm gì có còn không?" Nếu móc bắn quá muộn thì câu trả
 * lời là không, và ta quay lại đúng tình trạng của bài "ISA Amarcord 82": tiền
 * đã trả, thông tin đã mất, không ai truy được vì sao.
 *
 * KHÔNG kiểm "có một hàng trong MySQL": bộ Video chạy không DB (`phpunit.xml`
 * để sqlite in-memory ở dạng comment), và câu hỏi kiến trúc ở đây là THỜI ĐIỂM
 * móc bắn, không phải Eloquent có chạy không. Việc ghi thật được chính lượt 🎬
 * kế tiếp thử.
 */
final class ExtractionArtifactBoundaryTest extends TestCase
{
    private function meta(): RenderPlanMeta
    {
        return new RenderPlanMeta('plan-1', 'article-1', 'Tieu de', 'vi', '2026-08-06T00:00:00Z');
    }

    private function director(): FakeDirector
    {
        return new FakeDirector(new ActionSelection('', 0, [], 'calm', 'immediate'));
    }

    /** Entity có claim TRUY ĐƯỢC về bài — sống sót Gatekeeper, dựng được cảnh. */
    private function survivingGraph(): CandidateWorldGraph
    {
        return new CandidateWorldGraph([
            new CandidateEntity(
                'vessel',
                'vehicle',
                [new CandidateClaim('vessel', 'hull_color', 'grey', 'a grey vessel', 0.9)],
                'Vessel',
                'a grey vessel',
                0.9,
            ),
        ]);
    }

    private function article(): RawArticle
    {
        return new RawArticle('article-1', 'Tieu de', '<p>There is a grey vessel in the harbour.</p>');
    }

    public function test_the_hook_hands_over_the_gatekeeper_report_not_only_the_world(): void
    {
        // `rejections` chỉ sống trong scope của `plan()`. Không đưa ra ngoài thì
        // "cổng đã loại cái gì, vì lý do gì" là câu không ai trả lời được.
        $seen = null;

        (new VideoPlanningPipeline(
            new FakeExtractor($this->survivingGraph()),
            new FakeProducer(new ProducerOutput('a', 'b', 'c', [])),
            $this->director(),
        ))->plan(
            $this->article(),
            $this->meta(),
            onWorldVerified: function ($world, $report = null) use (&$seen): void {
                $seen = $report;
            },
        );

        $this->assertNotNull($seen, 'móc phải nhận được GatekeeperReport');
        $this->assertGreaterThan(0, $seen->candidateCount);
    }

    public function test_a_one_argument_callback_still_works(): void
    {
        // BenchmarkRunner khai `function ($verified)`. Thêm tham số thứ hai
        // KHÔNG được làm vỡ nó — nếu vỡ thì đây là breaking change trá hình.
        $world = null;

        (new VideoPlanningPipeline(
            new FakeExtractor($this->survivingGraph()),
            new FakeProducer(new ProducerOutput('a', 'b', 'c', [])),
            $this->director(),
        ))->plan(
            $this->article(),
            $this->meta(),
            onWorldVerified: function ($verified) use (&$world): void {
                $world = $verified;
            },
        );

        $this->assertNotNull($world);
    }

    public function test_evidence_survives_when_nothing_passes_the_gate(): void
    {
        // GUARD 2 ném NGAY SAU Gatekeeper. Đây chính là ca cần bằng chứng nhất:
        // tiền đã trả cho Extractor, và câu hỏi "vì sao không gì sống sót" chỉ
        // trả lời được bằng `rejections`.
        $fired = false;

        $pipeline = new VideoPlanningPipeline(
            // `name_quote` không có trong bài ⇒ Gatekeeper loại ⇒ world rỗng.
            new FakeExtractor(new CandidateWorldGraph([
                new CandidateEntity('ghost', 'vehicle', [], 'Ghost', 'a ship that was never mentioned', 0.9),
            ])),
            new FakeProducer(new ProducerOutput('a', 'b', 'c', [])),
            $this->director(),
        );

        try {
            $pipeline->plan(
                $this->article(),
                $this->meta(),
                onWorldVerified: function ($world, $report = null) use (&$fired): void {
                    $fired = true;
                },
            );
            $this->fail('phải ném PipelineAborted');
        } catch (PipelineAborted) {
            $this->assertTrue($fired, 'móc phải bắn TRƯỚC khi guard ném');
        }
    }

    public function test_evidence_survives_when_planning_cannot_build_a_scene(): void
    {
        // GUARD 3 ném sau Story/Scene. Móc bắn ở Gatekeeper nên vẫn phải xảy ra
        // trước — nếu ai đó dời lời ghi xuống cuối `plan()`, test này đỏ.
        $fired = false;

        $pipeline = new VideoPlanningPipeline(
            // Entity qua được cổng (tên truy được về bài) nhưng KHÔNG thuộc tính
            // nào ⇒ anchor-only ⇒ không dựng nổi cảnh nào.
            new FakeExtractor(new CandidateWorldGraph([
                new CandidateEntity('vessel', 'vehicle', [], 'vessel', 'a grey vessel', 0.9),
            ])),
            new FakeProducer(new ProducerOutput('a', 'b', 'c', [])),
            $this->director(),
        );

        try {
            $pipeline->plan(
                $this->article(),
                $this->meta(),
                onWorldVerified: function ($world, $report = null) use (&$fired): void {
                    $fired = true;
                },
            );
            $this->fail('phải ném PipelineAborted');
        } catch (PipelineAborted) {
            $this->assertTrue($fired, 'móc phải bắn TRƯỚC Planning');
        }
    }

    public function test_a_failing_recorder_does_not_bring_down_the_pipeline(): void
    {
        // Dụng cụ đo làm sập thứ nó đo là nghịch lý. Bảo đảm này nằm ở CHỖ GỌI
        // (`VideoRenderPlanService`), nên ở đây ta dựng lại đúng hình dạng đó:
        // callback tự bọc try/catch quanh lời ghi.
        $plan = (new VideoPlanningPipeline(
            new FakeExtractor($this->survivingGraph()),
            new FakeProducer(new ProducerOutput('a', 'b', 'c', [])),
            $this->director(),
        ))->plan(
            $this->article(),
            $this->meta(),
            onWorldVerified: function ($world, $report = null): void {
                try {
                    throw new \RuntimeException('DB đầy');
                } catch (\Throwable) {
                    // nuốt có tiếng — chỗ thật thì Log::error ở đây
                }
            },
        );

        $this->assertSame('1.0', $plan['plan_version'], 'pipeline vẫn phải về đích');
    }
}
