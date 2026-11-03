<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoContract
{
    public const WORKFLOW = 'harley_custom_v1';

    public const ARTICLE_INPUT = 'phase1-article-v1';

    public const EVIDENCE_FORMAT = 'moto-evidence-v1';

    public const EVIDENCE_EXECUTION = 'db-moto-phase-1-v1';

    public static function is(?string $workflow): bool
    {
        return $workflow === self::WORKFLOW;
    }
}
