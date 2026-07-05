<?php

namespace App\Services\AI\AFOS\Compiler\Diagnostics;

/**
 * Diagnostic — a single compiler message with severity, code, location, and message.
 *
 * Formatted output:
 *   ERROR   AFOS1001 [ShotGoalIRValidator].durationSec: Duration must be positive
 *   WARNING AFOS1102 [CameraIRValidator].focalLengthMm: 200mm exceeds Kling max (85mm)
 *   HINT    AFOS1005 [compiler]: goalTarget empty — EntityExtractor fallback used
 */
final class Diagnostic
{
    public function __construct(
        public readonly DiagnosticSeverity $severity,
        public readonly string             $message,
        public readonly ?DiagnosticCode    $code  = null,
        public readonly ?string            $pass  = null,
        public readonly ?string            $field = null,
    ) {}

    public function format(): string
    {
        $code     = $this->code ? " {$this->code->value}" : '';
        $location = $this->pass ? "[{$this->pass}]" : '[compiler]';
        $field    = $this->field ? ".{$this->field}" : '';
        return sprintf('%-8s%s %s%s: %s',
            strtoupper($this->severity->value),
            $code,
            $location,
            $field,
            $this->message
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'severity' => $this->severity->value,
            'code'     => $this->code?->value,
            'message'  => $this->message,
            'pass'     => $this->pass,
            'field'    => $this->field,
        ], fn($v) => $v !== null);
    }
}
