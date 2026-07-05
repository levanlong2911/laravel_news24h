<?php

namespace App\Services\AI\AFOS\Compiler\Diagnostics;

enum DiagnosticSeverity: string
{
    case ERROR   = 'error';
    case WARNING = 'warning';
    case HINT    = 'hint';
}
