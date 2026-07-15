<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark\Scenario\Preview;

use App\Services\AI\FilmOS\Narrative\QA\NarrativeFinding;
use App\Services\AI\FilmOS\Narrative\Story\StoryBeat;
use App\Services\AI\FilmOS\Prompting\IR\SubjectDescriptor;

/**
 * Machine-readable preview — pipe to a file or diff across providers.
 * The "compiler explorer" JSON view.
 */
final class JsonPreviewFormatter implements PreviewFormatter
{
    public function format(ScenarioPreview $p): string
    {
        return (string) json_encode([
            'scenario' => [
                'id'             => $p->document->id,
                'suite'          => $p->document->suite->value,
                'difficulty'     => $p->document->difficulty->value,
                'schema_version' => $p->document->schemaVersion,
            ],
            'provider' => $p->provider->value,
            'beats'    => array_map(static fn(StoryBeat $b) => $b->value, $p->beats),
            'subjects' => array_map(
                static fn(SubjectDescriptor $s) => [
                    'id'         => $s->id,
                    'type'       => $s->type->value,
                    'label'      => $s->label,
                    'is_primary' => $s->isPrimary,
                ],
                $p->subjects,
            ),
            'qa' => [
                'clean'    => $p->audit->isClean(),
                'blocking' => array_map($this->finding(...), $p->audit->blocking()),
                'errors'   => array_map($this->finding(...), $p->audit->errors()),
                'warnings' => array_map($this->finding(...), $p->audit->warnings()),
            ],
            'rendered' => [
                'positive' => $p->rendered->positive,
                'negative' => $p->rendered->negative,
                'metadata' => $p->rendered->metadata,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    private function finding(NarrativeFinding $f): array
    {
        return [
            'code'      => $f->code,
            'category'  => $f->category->value,
            'blocking'  => $f->blocking,
            'subjectId' => $f->subjectId,
            'ordinal'   => $f->ordinal,
            'message'   => $f->message,
        ];
    }
}
