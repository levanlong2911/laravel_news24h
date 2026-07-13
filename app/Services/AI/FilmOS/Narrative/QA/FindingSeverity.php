<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\QA;

enum FindingSeverity: string
{
    case ERROR   = 'error';    // PromptCompiler cannot produce a correct shot from this state
    case WARNING = 'warning';  // suspicious but compilable — a human or planner should look
    case INFO    = 'info';     // observation only
}
