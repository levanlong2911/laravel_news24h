<?php

declare(strict_types=1);

namespace App\Video\Render\Enums;

enum QaDecision: string
{
    case PASS = 'pass';
    case FAIL = 'fail';
    case REVIEW = 'review';
}
