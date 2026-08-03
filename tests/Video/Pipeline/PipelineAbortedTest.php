<?php

namespace Tests\Video\Pipeline;

use App\Video\Article\RawArticle;
use App\Video\Director\ActionSelection;
use App\Video\Director\FakeDirector;
use App\Video\Evidence\EvidenceIndex;
use App\Video\Extraction\CandidateClaim;
use App\Video\Extraction\CandidateEntity;
use App\Video\Extraction\CandidateWorldGraph;
use App\Video\Extraction\ExtractionResult;
use App\Video\Extraction\Extractor;
use App\Video\Extraction\FakeExtractor;
use App\Video\Pipeline\PipelineAborted;
use App\Video\Pipeline\VideoPlanningPipeline;
use App\Video\Producer\FakeProducer;
use App\Video\Producer\ProducerInterface;
use App\Video\Producer\ProducerOutput;
use App\Video\RenderPlan\RenderPlanMeta;
use App\Video\World\VerifiedWorldGraph;
use PHPUnit\Framework\TestCase;

/**
 * Chốt chặn chi phí (2026-07-30).
 *
 * Mỗi cú gọi Claude đều mất tiền dù kết quả có dùng được hay không — bằng chứng
 * thật: một cú Extractor bị `dd()` chặn ngay sau đó vẫn bị tính $0.0179. Nên
 * pipeline phải DỪNG ngay trước cú gọi tốn tiền kế tiếp khi biết chắc dữ liệu
 * không đủ.
 *
 * Test quan trọng nhất ở đây KHÔNG phải "có ném exception không" mà là
 * **Extractor/Producer có bị GỌI không** — đó mới là thứ mất tiền.
 */
class PipelineAbortedTest extends TestCase
{
    /** Extractor đếm số lần bị gọi — đây là thứ tương ứng với tiền. */
    private function countingExtractor(int &$calls, ?ExtractionResult $result = null): Extractor
    {
        return new class($calls, $result) implements Extractor
        {
            public function __construct(private int &$calls, private ?ExtractionResult $result) {}

            public function extract(RawArticle $article, EvidenceIndex $index): ExtractionResult
            {
                $this->calls++;

                return $this->result ?? new ExtractionResult(new CandidateWorldGraph, 'fake', 'v0');
            }
        };
    }

    private function meta(): RenderPlanMeta
    {
        return new RenderPlanMeta('plan-1', 'article-1', 'x', 'en', '2026-07-30T00:00:00+00:00');
    }

    /** Producer đếm số lần bị gọi — FakeProducer là `final` nên implement thẳng interface. */
    private function producer(int &$calls): ProducerInterface
    {
        return new class($calls) implements ProducerInterface
        {
            public function __construct(private int &$calls) {}

            public function produce(RawArticle $article, VerifiedWorldGraph $world): ProducerOutput
            {
                $this->calls++;

                return new ProducerOutput('a', 'b', 'c', []);
            }
        };
    }

    // ---- GUARD 1: bài rỗng — chốt DUY NHẤT chặn khi CHƯA tốn đồng nào ----

    public function test_empty_article_never_calls_the_extractor(): void
    {
        $extractorCalls = 0;
        $producerCalls = 0;

        $pipeline = new VideoPlanningPipeline(
            $this->countingExtractor($extractorCalls),
            $this->producer($producerCalls),
            new FakeDirector(new ActionSelection('', 0, [], 'calm', 'immediate')),
        );

        try {
            $pipeline->plan(new RawArticle('article-1', '', ''), $this->meta());
            $this->fail('phải ném PipelineAborted');
        } catch (PipelineAborted $e) {
            $this->assertSame(PipelineAborted::STAGE_NORMALIZE, $e->stage);
            $this->assertFalse($e->spentMoney, 'guard 1 phải dừng TRƯỚC mọi cú gọi tốn tiền');
        }

        // ĐÂY mới là assertion đáng giá — không phải chuyện exception.
        $this->assertSame(0, $extractorCalls, 'Extractor KHÔNG được gọi khi bài rỗng');
        $this->assertSame(0, $producerCalls, 'Producer KHÔNG được gọi khi bài rỗng');
    }

    public function test_article_with_only_markup_and_no_text_is_also_stopped(): void
    {
        // HTML chỉ có thẻ, không chữ — normalizer rút ra 0 đoạn.
        $extractorCalls = 0;
        $producerCalls = 0;

        $pipeline = new VideoPlanningPipeline(
            $this->countingExtractor($extractorCalls),
            $this->producer($producerCalls),
            new FakeDirector(new ActionSelection('', 0, [], 'calm', 'immediate')),
        );

        $this->expectException(PipelineAborted::class);

        try {
            $pipeline->plan(new RawArticle('article-1', '', '<div><span></span></div>'), $this->meta());
        } finally {
            $this->assertSame(0, $extractorCalls);
            $this->assertSame(0, $producerCalls);
        }
    }

    // ---- GUARD 2: qua Extractor (đã tốn 1 cú) nhưng không sự thật nào sống ----

    public function test_when_nothing_survives_verification_the_producer_is_never_called(): void
    {
        $extractorCalls = 0;
        $producerCalls = 0;

        // Candidate có id/type nhưng quote KHÔNG có trong bài ⇒ Gatekeeper loại hết.
        $candidates = new CandidateWorldGraph([
            new CandidateEntity('ghost', 'vehicle', [], 'Ghost', 'câu trích không hề có trong bài', 0.9),
        ]);

        $pipeline = new VideoPlanningPipeline(
            $this->countingExtractor($extractorCalls, new ExtractionResult($candidates, 'fake', 'v0')),
            $this->producer($producerCalls),
            new FakeDirector(new ActionSelection('', 0, [], 'calm', 'immediate')),
        );

        try {
            $pipeline->plan(new RawArticle('article-1', 'Tiêu đề', '<p>Một đoạn văn có thật.</p>'), $this->meta());
            $this->fail('phải ném PipelineAborted');
        } catch (PipelineAborted $e) {
            $this->assertSame(PipelineAborted::STAGE_GATEKEEPER, $e->stage);
            $this->assertTrue($e->spentMoney, 'đã gọi Extractor rồi nên phải báo là ĐÃ tốn phí');
        }

        $this->assertSame(1, $extractorCalls, 'Extractor gọi đúng 1 lần');
        $this->assertSame(0, $producerCalls, 'Producer + Director KHÔNG được gọi khi thế giới rỗng');
    }

    // ---- Đường bình thường: có dữ liệu thì KHÔNG được chặn nhầm ----

    public function test_a_normal_article_is_not_aborted(): void
    {
        // Guard chặn nhầm còn tệ hơn không có guard — khoá lại đường xanh.
        // Entity phải có claim VERIFY ĐƯỢC (quote nằm thật trong bài), không
        // thì nó là anchor-only, StoryPlanner ra 0 act và GUARD 3 bắn ĐÚNG.
        $pipeline = new VideoPlanningPipeline(
            new FakeExtractor(new CandidateWorldGraph([
                new CandidateEntity(
                    'vessel',
                    'vehicle',
                    [new CandidateClaim('vessel', 'hull_color', 'grey', 'a grey vessel', 0.9)],
                    'Vessel',
                    'a grey vessel',
                    0.9,
                ),
            ])),
            new FakeProducer(new ProducerOutput('a', 'b', 'c', [])),
            new FakeDirector(new ActionSelection('', 0, [], 'calm', 'immediate')),
        );

        $plan = $pipeline->plan(
            new RawArticle('article-1', 'Tieu de', '<p>There is a grey vessel in the harbour.</p>'),
            $this->meta(),
        );

        $this->assertSame('1.0', $plan['plan_version']);
        $this->assertNotSame([], $plan['world']['entities']);
        $this->assertNotSame([], $plan['scenes']);
    }

    public function test_entities_without_any_attribute_are_stopped_before_the_producer(): void
    {
        // GUARD 3: bài chỉ NÊU TÊN, không mô tả gì — entity qua được Gatekeeper
        // (có tên truy được về bài) nhưng anchor-only, không dựng nổi cảnh nào.
        $extractorCalls = 0;
        $producerCalls = 0;

        $pipeline = new VideoPlanningPipeline(
            $this->countingExtractor($extractorCalls, new ExtractionResult(
                new CandidateWorldGraph([new CandidateEntity('vessel', 'vehicle', [], 'Vessel', 'the Vessel', 0.9)]),
                'fake',
                'v0',
            )),
            $this->producer($producerCalls),
            new FakeDirector(new ActionSelection('', 0, [], 'calm', 'immediate')),
        );

        try {
            $pipeline->plan(new RawArticle('article-1', 'Tieu de', '<p>Someone mentioned the Vessel once.</p>'), $this->meta());
            $this->fail('phải ném PipelineAborted');
        } catch (PipelineAborted $e) {
            $this->assertSame(PipelineAborted::STAGE_SCENE_PLANNING, $e->stage);
        }

        $this->assertSame(1, $extractorCalls);
        $this->assertSame(0, $producerCalls, 'Producer + Director KHÔNG được gọi khi không có cảnh nào');
    }

    // ---- Thông điệp phải dùng được cho người vận hành ----

    // ---- GUARD 4 (§18.23): người gọi từ chối creative ⇒ bỏ Producer + Director ----

    /** @return array{0: VideoPlanningPipeline, 1: callable} pipeline + đóng gói bộ đếm */
    private function pipelineCountingProducer(int &$producerCalls, ?callable $creativeNeededFor): VideoPlanningPipeline
    {
        $extractorCalls = 0;

        return new VideoPlanningPipeline(
            $this->countingExtractor($extractorCalls, new ExtractionResult(
                new CandidateWorldGraph([
                    new CandidateEntity(
                        'vessel',
                        'vehicle',
                        [new CandidateClaim('vessel', 'hull_color', 'grey', 'a grey vessel', 0.9)],
                        'Vessel',
                        'a grey vessel',
                        0.9,
                    ),
                ]),
                'fake',
                'v0',
            )),
            $this->producer($producerCalls),
            new FakeDirector(new ActionSelection('', 0, [], 'calm', 'immediate')),
        );
    }

    public function test_producer_is_skipped_when_the_caller_says_creative_is_not_needed(): void
    {
        // ĐÂY là assertion đáng giá: không phải "plan có chạy không" mà là
        // "Producer có bị GỌI không" — đó mới là tiền. Đo thật trên bài Sixth
        // Sense: 9/10 cú gọi bị vứt vì Creation Arc thay sạch scene (§18.22).
        $producerCalls = 0;
        $pipeline = $this->pipelineCountingProducer($producerCalls, fn () => false);

        $plan = $pipeline->plan(
            new RawArticle('article-1', 'Tieu de', '<p>There is a grey vessel in the harbour.</p>'),
            $this->meta(),
            creativeNeededFor: fn () => false,
        );

        $this->assertSame(0, $producerCalls, 'Producer KHÔNG được gọi khi người gọi từ chối creative');
        $this->assertArrayNotHasKey('producer', $plan, 'không có ProducerOutput thì không emit khối producer');
        $this->assertNotSame([], $plan['scenes'], 'vẫn phải ra plan đầy đủ — chỉ thiếu phần creative');
    }

    public function test_producer_still_runs_when_the_predicate_says_creative_is_needed(): void
    {
        // Guard chặn nhầm còn tệ hơn không có guard.
        $producerCalls = 0;
        $pipeline = $this->pipelineCountingProducer($producerCalls, fn () => true);

        $plan = $pipeline->plan(
            new RawArticle('article-1', 'Tieu de', '<p>There is a grey vessel in the harbour.</p>'),
            $this->meta(),
            creativeNeededFor: fn () => true,
        );

        $this->assertSame(1, $producerCalls);
        $this->assertArrayHasKey('producer', $plan);
    }

    public function test_default_behaviour_is_unchanged_when_no_predicate_is_given(): void
    {
        // Mọi caller cũ (benchmark, test) không truyền gì — phải chạy y như trước.
        $producerCalls = 0;
        $pipeline = $this->pipelineCountingProducer($producerCalls, null);

        $pipeline->plan(
            new RawArticle('article-1', 'Tieu de', '<p>There is a grey vessel in the harbour.</p>'),
            $this->meta(),
        );

        $this->assertSame(1, $producerCalls);
    }

    public function test_the_predicate_receives_the_verified_world_not_the_raw_article(): void
    {
        // Pipeline phải MÙ CHỦ ĐỀ (§1): predicate chỉ được thấy World đã verify,
        // không thấy Article/category. Khoá lại để không ai "tiện tay" truyền
        // thêm Article vào cho dễ.
        $producerCalls = 0;
        $seen = null;
        $pipeline = $this->pipelineCountingProducer($producerCalls, null);

        $pipeline->plan(
            new RawArticle('article-1', 'Tieu de', '<p>There is a grey vessel in the harbour.</p>'),
            $this->meta(),
            creativeNeededFor: function ($world) use (&$seen) {
                $seen = $world;

                return false;
            },
        );

        $this->assertInstanceOf(VerifiedWorldGraph::class, $seen);
        $this->assertNotSame([], $seen->entities(), 'predicate phải chạy SAU Gatekeeper, không phải trước');
    }

    public function test_message_says_whether_money_was_already_spent(): void
    {
        $free = PipelineAborted::articleHasNoText('article-1', 0);
        $paid = PipelineAborted::nothingSurvivedVerification(12, 12);

        $this->assertStringContainsString('chưa tốn phí', $free->getMessage());
        $this->assertFalse($free->spentMoney);

        $this->assertStringContainsString('12', $paid->getMessage());
        $this->assertTrue($paid->spentMoney);
    }

    public function test_describe_names_the_stage_for_logs(): void
    {
        $this->assertStringContainsString('normalize', PipelineAborted::articleHasNoText('a', 0)->describe());
        $this->assertStringContainsString('gatekeeper', PipelineAborted::nothingSurvivedVerification(3, 3)->describe());
        $this->assertStringContainsString('scene_planning', PipelineAborted::noSceneCouldBePlanned(2, 0)->describe());
    }
}
