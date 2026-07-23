<?php

namespace Tests\Video\Analysis;

use App\Video\Analysis\SemanticClaimBenchmarkRunner;
use App\Video\Article\RawArticle;
use App\Video\Extraction\CandidateClaim;
use App\Video\Extraction\CandidateEntity;
use App\Video\Extraction\CandidateWorldGraph;
use App\Video\Extraction\Extractor;
use App\Video\Extraction\FakeExtractor;
use PHPUnit\Framework\TestCase;

class SemanticClaimBenchmarkRunnerTest extends TestCase
{
    private function article(): RawArticle
    {
        return new RawArticle('art_1', 'Test', 'The 100m yacht was built by Example Yard Co at their site.');
    }

    private function extractorWith(CandidateClaim ...$semanticClaims): Extractor
    {
        return new FakeExtractor(new CandidateWorldGraph([
            new CandidateEntity('vessel', 'vehicle', [], semanticClaims: $semanticClaims),
        ]));
    }

    public function test_success_reports_precision_and_zero_cost_for_fake(): void
    {
        $claim = new CandidateClaim('vessel', 'builder', 'Example Yard Co', 'built by Example Yard Co');
        $runner = new SemanticClaimBenchmarkRunner($this->extractorWith($claim));

        $result = $runner->runOne($this->article(), 'yacht');

        $this->assertSame('SUCCESS', $result->status);
        $this->assertSame('art_1', $result->articleId);
        $this->assertSame('yacht', $result->domain);
        $this->assertSame(1, $result->total);
        $this->assertSame(1, $result->verified);
        $this->assertSame(1.0, $result->precision);
        $this->assertSame([], $result->failures);
        $this->assertSame(0, $result->callCount, 'FakeExtractor không gọi LLM — call_count phải là 0');
    }

    public function test_failure_reasons_are_preserved(): void
    {
        $claim = new CandidateClaim('vessel', 'builder', 'Example Yard Co', 'a quote not in the article');
        $runner = new SemanticClaimBenchmarkRunner($this->extractorWith($claim));

        $result = $runner->runOne($this->article());

        $this->assertSame(0, $result->verified);
        $this->assertSame('quote_not_found', $result->failures[0]['reason']);
        $this->assertSame('vessel', $result->failures[0]['entity_id']);
        $this->assertSame('builder', $result->failures[0]['attribute']);
    }

    public function test_extractor_exception_becomes_error_status_not_crash(): void
    {
        $extractor = new class implements Extractor {
            public function extract(RawArticle $article, \App\Video\Evidence\EvidenceIndex $index): \App\Video\Extraction\ExtractionResult
            {
                throw new \RuntimeException('llm timeout');
            }
        };
        $runner = new SemanticClaimBenchmarkRunner($extractor);

        $result = $runner->runOne($this->article());

        $this->assertSame('ERROR', $result->status);
        $this->assertSame('llm timeout', $result->error);
    }
}
