<?php

declare(strict_types=1);

namespace App\Video\Screenplay;

final class CreativeInspirationBuilder
{
    /**
     * @param  array<string, mixed>  $brief
     * @return array{0: ?array<string, mixed>, 1: list<string>}
     */
    public function build(array $brief): array
    {
        $insights = $brief['source_insights'] ?? [];

        if (! is_array($insights) || $insights === []) {
            return [null, ['brief carries no source insights']];
        }

        $ideas = [];

        foreach ($insights as $insight) {
            $summary = trim((string) ($insight['summary'] ?? ''));

            if ($summary === '') {
                continue;
            }

            $ideas[] = [
                'aspect' => (string) ($insight['aspect'] ?? ''),
                'idea' => $summary,
            ];
        }

        return $ideas === []
            ? [null, ['brief carries no usable ideas']]
            : [['ideas' => $ideas], []];
    }
}
