<?php

namespace App\Services\AI\AFOS\Compiler\Diagnostics;

/**
 * DiagnosticBag — mutable collector for compiler diagnostics.
 *
 * Flows through the full compilation pipeline. Each pass appends diagnostics.
 * AfosPassManager surfaces the bag in PromptIRSnapshot so benchmark and CLI
 * can display structured compiler output.
 *
 * Example output:
 *   ERROR   AFOS1001 [ShotGoalIRValidator].durationSec: Duration must be positive
 *   WARNING AFOS1003 [ShotGoalIRValidator].durationSec: 12s exceeds Kling limit (10s)
 *   HINT    AFOS1005 [compiler]: goalTarget empty — EntityExtractor fallback used
 */
final class DiagnosticBag
{
    /** @var Diagnostic[] */
    private array $diagnostics = [];

    public function error(
        string          $message,
        ?DiagnosticCode $code  = null,
        ?string         $pass  = null,
        ?string         $field = null,
    ): void {
        $this->diagnostics[] = new Diagnostic(DiagnosticSeverity::ERROR, $message, $code, $pass, $field);
    }

    public function warn(
        string          $message,
        ?DiagnosticCode $code  = null,
        ?string         $pass  = null,
        ?string         $field = null,
    ): void {
        $this->diagnostics[] = new Diagnostic(DiagnosticSeverity::WARNING, $message, $code, $pass, $field);
    }

    public function hint(
        string          $message,
        ?DiagnosticCode $code  = null,
        ?string         $pass  = null,
        ?string         $field = null,
    ): void {
        $this->diagnostics[] = new Diagnostic(DiagnosticSeverity::HINT, $message, $code, $pass, $field);
    }

    public function hasErrors(): bool
    {
        foreach ($this->diagnostics as $d) {
            if ($d->severity === DiagnosticSeverity::ERROR) {
                return true;
            }
        }
        return false;
    }

    public function isEmpty(): bool
    {
        return empty($this->diagnostics);
    }

    /** @return Diagnostic[] */
    public function all(): array { return $this->diagnostics; }

    /** @return Diagnostic[] */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->diagnostics,
            fn($d) => $d->severity === DiagnosticSeverity::ERROR
        ));
    }

    /** @return Diagnostic[] */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->diagnostics,
            fn($d) => $d->severity === DiagnosticSeverity::WARNING
        ));
    }

    /** @return Diagnostic[] */
    public function hints(): array
    {
        return array_values(array_filter(
            $this->diagnostics,
            fn($d) => $d->severity === DiagnosticSeverity::HINT
        ));
    }

    public function merge(self $other): void
    {
        foreach ($other->all() as $d) {
            $this->diagnostics[] = $d;
        }
    }

    public function format(): string
    {
        return implode("\n", array_map(fn($d) => $d->format(), $this->diagnostics));
    }

    public function toArray(): array
    {
        return array_map(fn($d) => $d->toArray(), $this->diagnostics);
    }
}
