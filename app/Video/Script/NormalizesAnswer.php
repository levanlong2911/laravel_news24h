<?php

declare(strict_types=1);

namespace App\Video\Script;

use App\Video\Superyacht\Spec\ResolvedPhase;

/** An explicit, audited preparation of an answer; the provider response remains the integrity source. */
interface NormalizesAnswer
{
    /** @return array{answer: object, version: ?string, changes: list<array{path: string, action: string, before: mixed}>} */
    public function normalizeAnswer(object $answer, ResolvedPhase $bundle): array;
}
