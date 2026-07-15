<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark\Scenario\Preview;

use App\Services\AI\FilmOS\Narrative\QA\NarrativeFinding;
use App\Services\AI\FilmOS\Prompting\IR\SubjectDescriptor;

/**
 * Human-readable preview. QA is shown FIRST — when authoring a benchmark the
 * first thing to see is whether the narrative is sound, before the prompt.
 * ASCII only, to stay legible on any console.
 */
final class ConsolePreviewFormatter implements PreviewFormatter
{
    public function format(ScenarioPreview $p): string
    {
        $doc = $p->document;

        $lines = [];
        $lines[] = "SCENARIO  {$doc->id}  [{$doc->suite->value} - {$doc->difficulty->value} - v{$doc->schemaVersion}]";
        $lines[] = 'BEATS     ' . $this->beats($p);
        $lines[] = '';
        $lines[] = 'QA';
        $lines[] = '----------------------------------------';
        foreach ($this->qa($p->audit) as $l) {
            $lines[] = $l;
        }
        $lines[] = '';
        $lines[] = 'SUBJECTS  ' . $this->subjects($p->subjects);
        $lines[] = '';
        $lines[] = str_pad("---------- {$p->provider->value} ", 40, '-');
        $lines[] = 'POSITIVE';
        $lines[] = $p->rendered->positive;
        $lines[] = '';
        $lines[] = 'NEGATIVE';
        $lines[] = $p->rendered->negative ?? '(none)';
        $lines[] = '';
        $lines[] = 'METADATA';
        $lines[] = (string) json_encode($p->rendered->metadata, JSON_UNESCAPED_UNICODE);

        return implode("\n", $lines) . "\n";
    }

    private function beats(ScenarioPreview $p): string
    {
        $parts = [];
        foreach ($p->beats as $ordinal => $beat) {
            $parts[] = "{$beat->value}({$ordinal})";
        }
        return $parts === [] ? '(none)' : implode(' -> ', $parts);
    }

    /** @param SubjectDescriptor[] $subjects */
    private function subjects(array $subjects): string
    {
        if ($subjects === []) {
            return '(none)';
        }
        $parts = array_map(
            static fn(SubjectDescriptor $s): string
                => "{$s->label} ({$s->type->value}" . ($s->isPrimary ? ', primary' : '') . ')',
            $subjects,
        );
        return implode(', ', $parts);
    }

    /** @return string[] */
    private function qa(\App\Services\AI\FilmOS\Narrative\QA\NarrativeAuditReport $audit): array
    {
        if ($audit->isClean()) {
            return ['clean'];
        }

        $summary = sprintf(
            '%d blocking, %d error(s), %d warning(s)',
            count($audit->blocking()),
            $audit->errorCount(),
            $audit->warningCount(),
        );
        $lines = [$summary];

        foreach ($audit->blocking() as $f) {
            $lines[] = '  BLOCKING  ' . $this->finding($f);
        }
        foreach ($audit->errors() as $f) {
            if (!$f->blocking) {
                $lines[] = '  ERROR     ' . $this->finding($f);
            }
        }
        foreach ($audit->warnings() as $f) {
            $lines[] = '  WARNING   ' . $this->finding($f);
        }
        return $lines;
    }

    private function finding(NarrativeFinding $f): string
    {
        $where = [];
        if ($f->subjectId !== null) {
            $where[] = $f->subjectId;
        }
        if ($f->ordinal !== null) {
            $where[] = "ordinal {$f->ordinal}";
        }
        $suffix = $where === [] ? '' : ' (' . implode(', ', $where) . ')';
        return "{$f->code}{$suffix} - {$f->message}";
    }
}
