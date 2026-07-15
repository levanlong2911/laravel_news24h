<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark\Scenario\Preview;

use App\Services\AI\FilmOS\Benchmark\Scenario\ScenarioDocument;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeAuditReport;
use App\Services\AI\FilmOS\Prompting\Adapter\ProviderId;
use App\Services\AI\FilmOS\Prompting\Adapter\RenderedPrompt;

/**
 * Everything a preview needs to display, gathered once by the command so
 * formatters (console / json / …) only present — they never re-run the pipeline.
 *
 * Immutable.
 */
final class ScenarioPreview
{
    /**
     * @param \App\Services\AI\FilmOS\Narrative\Story\StoryBeat[]      $beats    authored beats, cinematic order
     * @param \App\Services\AI\FilmOS\Prompting\IR\SubjectDescriptor[] $subjects primary-first
     */
    public function __construct(
        public readonly ScenarioDocument    $document,
        public readonly ProviderId          $provider,
        public readonly array               $beats,
        public readonly array               $subjects,
        public readonly NarrativeAuditReport $audit,
        public readonly RenderedPrompt      $rendered,
    ) {}
}
