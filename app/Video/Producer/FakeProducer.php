<?php

namespace App\Video\Producer;

use App\Video\Article\RawArticle;
use App\Video\World\VerifiedWorldGraph;

/**
 * Output co dinh. Cho unit test va CI. 100% deterministic, khong mang, khong tien.
 */
final class FakeProducer implements ProducerInterface
{
    public function __construct(
        private readonly ProducerOutput $output,
    ) {
    }

    public function produce(RawArticle $article, VerifiedWorldGraph $world): ProducerOutput
    {
        return $this->output;
    }
}
