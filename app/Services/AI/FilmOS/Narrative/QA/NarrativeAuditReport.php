<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\QA;

/**
 * STABLE CONTRACT — consumed by PromptCompiler gating, benchmark (C.8A) and UI.
 * Extend by adding methods; never change the meaning of existing ones.
 *
 * Finding order is deterministic: rule order (as configured in the Auditor)
 * then each rule's own emission order. Same input → same report.
 */
final class NarrativeAuditReport
{
    /** @param NarrativeFinding[] $findings */
    public function __construct(
        private readonly array $findings = [],
    ) {}

    /** @return NarrativeFinding[] */
    public function findings(): array
    {
        return $this->findings;
    }

    /** True when the audit produced no findings at all — not even INFO. */
    public function isClean(): bool
    {
        return $this->findings === [];
    }

    public function hasErrors(): bool
    {
        return $this->errorCount() > 0;
    }

    public function hasWarnings(): bool
    {
        return $this->warningCount() > 0;
    }

    public function errorCount(): int
    {
        return count($this->bySeverity(FindingSeverity::ERROR));
    }

    public function warningCount(): int
    {
        return count($this->bySeverity(FindingSeverity::WARNING));
    }

    /** @return NarrativeFinding[] */
    public function errors(): array
    {
        return $this->bySeverity(FindingSeverity::ERROR);
    }

    /**
     * Findings whose state makes a correct compile physically impossible.
     * Whether to stop the pipeline on these is the CONSUMER's decision —
     * the report only describes impact.
     *
     * @return NarrativeFinding[]
     */
    public function blocking(): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn(NarrativeFinding $f) => $f->blocking,
        ));
    }

    public function hasBlocking(): bool
    {
        return $this->blocking() !== [];
    }

    /** @return NarrativeFinding[] */
    public function warnings(): array
    {
        return $this->bySeverity(FindingSeverity::WARNING);
    }

    /** @return NarrativeFinding[] */
    public function infos(): array
    {
        return $this->bySeverity(FindingSeverity::INFO);
    }

    /** @return NarrativeFinding[] */
    public function bySeverity(FindingSeverity $severity): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn(NarrativeFinding $f) => $f->severity === $severity,
        ));
    }

    /** @return NarrativeFinding[] */
    public function byCategory(FindingCategory $category): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn(NarrativeFinding $f) => $f->category === $category,
        ));
    }

    /** @return NarrativeFinding[] */
    public function byCode(string $code): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn(NarrativeFinding $f) => $f->code === $code,
        ));
    }
}
