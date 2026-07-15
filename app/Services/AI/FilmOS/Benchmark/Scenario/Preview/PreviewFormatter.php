<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark\Scenario\Preview;

/**
 * Turns a ScenarioPreview into an output string. Presentation lives here, not
 * in the command — console today, --json/--markdown/--html by adding formatters.
 */
interface PreviewFormatter
{
    public function format(ScenarioPreview $preview): string;
}
