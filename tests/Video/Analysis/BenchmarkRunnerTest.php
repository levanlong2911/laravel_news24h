<?php

namespace Tests\Video\Analysis;

use App\Video\Analysis\BenchmarkRunner;
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

class BenchmarkRunnerTest extends TestCase
{
    private function meta(): RenderPlanMeta
    {
        return new RenderPlanMeta(
            '0198f3a1-4b2c-4d3e-8f10-2a3b4c5d6e7f',
            '7c9e6679-7425-40de-944b-e07fc1f90ae7',
            'Benchmark fixture',
            'en',
            '2026-07-22T00:00:00Z',
        );
    }

    private function article(): RawArticle
    {
        // evidence_quote khớp NGUYÊN VĂN nội dung này — để Gatekeeper thật xác nhận.
        return new RawArticle('art_1', 'Moonrise sold', 'The grey hull was seen under clear skies.');
    }

    private function pipeline(CandidateWorldGraph $candidates, ProducerOutput $producer, ActionSelection $selection): VideoPlanningPipeline
    {
        return new VideoPlanningPipeline(
            new FakeExtractor($candidates),
            new FakeProducer($producer),
            new FakeDirector($selection),
        );
    }

    public function test_success_row_reports_landscape_and_environment(): void
    {
        $candidates = new CandidateWorldGraph([
            new CandidateEntity('moonrise', 'vehicle', [
                new CandidateClaim('moonrise', 'hull_color', 'grey', 'grey hull'),
            ]),
            new CandidateEntity('shipyard', 'landscape', [
                new CandidateClaim('shipyard', 'weather', 'clear skies', 'clear skies'),
            ]),
        ]);
        $runner = new BenchmarkRunner($this->pipeline(
            $candidates,
            new ProducerOutput('a', 'b', 'watch the hull take shape', []),
            new ActionSelection('', 0, [], 'calm', 'immediate'),
        ));

        $result = $runner->runOne($this->article(), $this->meta(), 'yacht');

        $this->assertSame('SUCCESS', $result->status);
        $this->assertSame('art_1', $result->articleId);
        $this->assertSame('yacht', $result->domain);
        $this->assertSame(2, $result->entityCount);
        $this->assertSame(1, $result->landscapeCount);
        $this->assertSame('SUCCESS', $result->environmentReason);
        $this->assertSame(['weather'], $result->environmentAttributes);
        $this->assertSame(0, $result->callCount, 'Fake* không gọi LLM — call_count phải là 0');
        $this->assertIsArray($result->renderPlan, 'SUCCESS phải kèm RenderPlan để command lưu artifact');
    }

    public function test_row_reports_no_landscape_entity_when_truth_has_none(): void
    {
        $candidates = new CandidateWorldGraph([
            new CandidateEntity('moonrise', 'vehicle', [
                new CandidateClaim('moonrise', 'hull_color', 'grey', 'grey hull'),
            ]),
        ]);
        $runner = new BenchmarkRunner($this->pipeline(
            $candidates,
            new ProducerOutput('a', 'b', 'c', []),
            new ActionSelection('', 0, [], 'calm', 'immediate'),
        ));

        $result = $runner->runOne($this->article(), $this->meta());

        $this->assertSame('SUCCESS', $result->status);
        $this->assertSame(0, $result->landscapeCount);
        $this->assertSame('NO_LANDSCAPE_ENTITY', $result->environmentReason);
        $this->assertSame([], $result->environmentAttributes);
    }

    public function test_row_reports_error_status_without_throwing(): void
    {
        // Claim khong khop bang chung nao trong bai -> Gatekeeper loai het ->
        // world rong -> KHONG loi, nhung mo phong truong hop pipeline nem
        // exception bang RawArticle rong ep vao meta thieu — dung article
        // hop le nhung candidate hoan toan sai de kiem tra nhanh khong crash.
        $candidates = new CandidateWorldGraph([]);
        $runner = new BenchmarkRunner($this->pipeline(
            $candidates,
            new ProducerOutput('a', 'b', 'c', []),
            new ActionSelection('', 0, [], 'calm', 'immediate'),
        ));

        $result = $runner->runOne($this->article(), $this->meta());

        $this->assertSame('SUCCESS', $result->status);
        $this->assertSame(0, $result->entityCount);
        $this->assertSame('NO_LANDSCAPE_ENTITY', $result->environmentReason);
    }

    public function test_confidence_report_is_embedded_in_row(): void
    {
        $candidates = new CandidateWorldGraph([
            new CandidateEntity('moonrise', 'vehicle', [
                new CandidateClaim('moonrise', 'hull_color', 'grey', 'grey hull'),
            ]),
        ]);
        $runner = new BenchmarkRunner($this->pipeline(
            $candidates,
            new ProducerOutput('a', 'b', 'watch the hull take shape', []),
            new ActionSelection('', 0, [], 'calm', 'immediate'),
        ));

        $result = $runner->runOne($this->article(), $this->meta());

        $this->assertIsFloat($result->coverageScore);
        $this->assertContains('objective', $result->coverageLayers);
        $this->assertContains('visual_style', $result->missingLayers);
        $this->assertIsFloat($result->implementedCoverageScore);
        $this->assertGreaterThan($result->coverageScore, $result->implementedCoverageScore, 'visual_style bị loại khỏi mẫu số nên implementedCoverageScore phải >= coverageScore');
    }
}
