<?php

namespace Tests\Video\Pipeline;

use App\Models\Article;
use App\Services\Video\ExtractionArtifactRecorder;
use App\Video\Article\RawArticle;
use App\Video\Director\ActionSelection;
use App\Video\Director\FakeDirector;
use App\Video\Extraction\CandidateClaim;
use App\Video\Extraction\CandidateEntity;
use App\Video\Extraction\CandidateWorldGraph;
use App\Video\Extraction\FakeExtractor;
use App\Video\Pipeline\VideoPlanningPipeline;
use App\Video\Producer\FakeProducer;
use App\Video\Producer\ProducerOutput;
use App\Video\RenderPlan\RenderPlanMeta;
use PHPUnit\Framework\TestCase;

/**
 * Phép ánh xạ DTO → hàng artifact.
 *
 * Kiểm `payloadFor()` chứ không kiểm `record()`: bộ Video chạy không DB, và
 * phần dễ sai không phải Eloquent mà là phép ánh xạ — gõ nhầm một tên trường
 * thì cột đó lặng lẽ rỗng, và ta chỉ phát hiện lúc đang cần nó nhất.
 *
 * Dữ liệu vào lấy từ một lượt pipeline THẬT (fake extractor, gatekeeper thật)
 * thay vì dựng tay `GatekeeperReport` — dựng tay thì test sẽ khoá đúng cái hình
 * dạng tác giả tưởng tượng, không phải hình dạng pipeline thật sự phát ra.
 */
final class ExtractionArtifactRecorderTest extends TestCase
{
    /** @return array{0: \App\Video\Extraction\ExtractionResult, 1: \App\Video\Gatekeeper\GatekeeperReport} */
    private function runPipeline(): array
    {
        $extraction = null;
        $report = null;

        (new VideoPlanningPipeline(
            new FakeExtractor(new CandidateWorldGraph([
                new CandidateEntity(
                    'vessel',
                    'vehicle',
                    [new CandidateClaim('vessel', 'hull_color', 'grey', 'a grey vessel', 0.9)],
                    'Vessel',
                    'a grey vessel',
                    0.9,
                ),
                // Entity thứ hai KHÔNG truy được về bài → Gatekeeper loại → cho
                // ta một `rejection` thật để kiểm, thay vì một mảng rỗng.
                new CandidateEntity('ghost', 'vehicle', [], 'Ghost', 'never written anywhere', 0.9),
            ])),
            new FakeProducer(new ProducerOutput('a', 'b', 'c', [])),
            new FakeDirector(new ActionSelection('', 0, [], 'calm', 'immediate')),
        ))->plan(
            new RawArticle('article-1', 'Tieu de', '<p>There is a grey vessel in the harbour.</p>'),
            new RenderPlanMeta('plan-1', 'article-1', 'Tieu de', 'vi', '2026-08-06T00:00:00Z'),
            onWorldVerified: function ($world, $r = null) use (&$report): void {
                $report = $r;
            },
            onExtracted: function ($e) use (&$extraction): void {
                $extraction = $e;
            },
        );

        return [$extraction, $report];
    }

    private function article(): Article
    {
        $article = new Article;
        $article->id = 'article-1';
        $article->category_id = 7;

        return $article;
    }

    public function test_provenance_records_what_was_used_not_what_was_configured(): void
    {
        // Bài học từ bug ClaudeProducer: khai `haiku` suốt sáu ngày mà chạy
        // Sonnet. Cột này phải mang giá trị API TRẢ VỀ.
        [$extraction, $report] = $this->runPipeline();

        $payload = (new ExtractionArtifactRecorder)->payloadFor($this->article(), $extraction, $report);

        $this->assertSame($extraction->model, $payload['model']);
        $this->assertSame($extraction->instructionVersion, $payload['instruction_version']);
        $this->assertSame('article-1', $payload['article_id']);
        $this->assertSame('7', $payload['category']);
    }

    public function test_profile_version_stays_null_until_a_profile_exists(): void
    {
        // Sprint 1 mới có `yacht.yaml`. Nhét 'v1' hay 'unknown' vào đây là bịa
        // một giá trị provenance — tệ hơn để trống.
        [$extraction, $report] = $this->runPipeline();

        $payload = (new ExtractionArtifactRecorder)->payloadFor($this->article(), $extraction, $report);

        $this->assertNull($payload['profile_version']);
    }

    public function test_the_candidate_graph_is_stored_before_the_gate_not_after(): void
    {
        // Lưu bản đã verify thì mất đúng phần cần để phân biệt "LLM không tìm
        // ra" với "cổng đã loại". Entity `ghost` bị loại nhưng PHẢI còn ở đây.
        [$extraction, $report] = $this->runPipeline();

        $payload = (new ExtractionArtifactRecorder)->payloadFor($this->article(), $extraction, $report);
        $ids = array_column($payload['candidate_graph']['entities'], 'id');

        $this->assertContains('vessel', $ids);
        $this->assertContains('ghost', $ids, 'entity bị cổng loại vẫn phải nằm trong candidate_graph');
    }

    public function test_every_rejection_keeps_its_own_reason(): void
    {
        // "Loại 30 claim" không sửa được gì. "Loại vì quote không chứng minh
        // được giá trị, ở `moonrise.hull_color`" thì sửa được.
        [$extraction, $report] = $this->runPipeline();

        $payload = (new ExtractionArtifactRecorder)->payloadFor($this->article(), $extraction, $report);
        $rejections = $payload['gatekeeper_report']['rejections'];

        $this->assertNotEmpty($rejections);
        $this->assertArrayHasKey('subject', $rejections[0]);
        $this->assertArrayHasKey('reason', $rejections[0]);
        $this->assertIsString($rejections[0]['reason'], 'enum phải được hạ xuống chuỗi để lưu JSON');
    }

    public function test_claims_keep_the_quote_that_was_offered_for_them(): void
    {
        [$extraction, $report] = $this->runPipeline();

        $payload = (new ExtractionArtifactRecorder)->payloadFor($this->article(), $extraction, $report);
        $vessel = array_values(array_filter(
            $payload['candidate_graph']['entities'],
            fn (array $e) => $e['id'] === 'vessel',
        ))[0];

        $this->assertSame('hull_color', $vessel['claims'][0]['attribute']);
        $this->assertSame('a grey vessel', $vessel['claims'][0]['evidence_quote']);
    }
}
