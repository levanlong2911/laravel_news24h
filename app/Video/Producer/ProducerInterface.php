<?php

namespace App\Video\Producer;

use App\Video\Article\RawArticle;
use App\Video\World\VerifiedWorldGraph;

/**
 * Producer: "Bai nay ke chuyen gi? Vi sao nguoi ta xem?" — KHONG chia scene,
 * KHONG quyet camera. Doc VerifiedWorldGraph (§1: Decision KHONG duoc mau
 * thuan Truth) de narrative bam vao entity/event co that, nhung ban than
 * narrative la Decision — khong can Evidence, khong qua Gatekeeper.
 */
interface ProducerInterface
{
    public function produce(RawArticle $article, VerifiedWorldGraph $world): ProducerOutput;
}
