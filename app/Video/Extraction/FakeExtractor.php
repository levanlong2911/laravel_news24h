<?php

namespace App\Video\Extraction;

use App\Video\Article\RawArticle;
use App\Video\Evidence\EvidenceIndex;

/**
 * Output cố định. Cho unit test và CI. 100% deterministic, không mạng, không tiền.
 */
final class FakeExtractor implements Extractor
{
    public function __construct(
        private readonly CandidateWorldGraph $candidates,
        private readonly string $model = 'fake',
    ) {
    }

    public function extract(RawArticle $article, EvidenceIndex $index): ExtractionResult
    {
        return new ExtractionResult($this->candidates, $this->model, 'fake-v0');
    }
}
