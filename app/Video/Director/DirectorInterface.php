<?php

namespace App\Video\Director;

use App\Video\Producer\ProducerOutput;
use App\Video\World\VerifiedWorldGraph;

/**
 * Director: "nguoi xem nen cam thay gi o canh nay" — CHI chon trong candidates
 * (da sinh boi EditorialInterpreter::candidatesFor(), deterministic), KHONG
 * tu viet hanh dong, KHONG quyet camera. Subjective duy nhat: hero nao dang
 * chu y nhat, emotion/reveal. Xem ARCHITECTURE.md SS18.4/SS18.7.
 */
interface DirectorInterface
{
    /**
     * @param array{hero_candidates: list<string>, action_candidates: list<\App\Video\Editorial\ActionCandidate>} $candidates
     */
    public function select(array $candidates, VerifiedWorldGraph $world, ?ProducerOutput $producer): ActionSelection;
}
