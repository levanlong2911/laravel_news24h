<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoScenePlanner
{
    public const VERSION = 'moto-scenes-plan-v2';

    /**
     * @param  array<string, mixed>  $document  a moto Phase 6 document that passed its schema
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @return array{version: string, total_ms: int, scenes: list<array<string, mixed>>, conflicts: list<array<string, mixed>>, source_conflicts: list<array<string, mixed>>} every scene with the timing, places, plates, characters and build state code derives from its beats and what the answer wrote for it, its work items with the id code gives them (null for an answer written before work existed); every beat keeps its own times, characters, state, configurations, situation, plate uses and unplated parts from the pinned input; the background text's blocking conflicts stay with the beats they name
     */
    public static function plan(array $document, array $context): array
    {
        $data = (array) $document['data'];
        $input = (array) $context['input'];
        $facts = array_column((array) $context['beats'], null, 'id');
        $script = array_column((array) $input['script']['beats'], null, 'id');
        $assigned = array_column((array) $input['locations']['beats'], null, 'beat');
        $plates = array_column((array) $input['locations']['backgrounds'], null, 'id');
        $places = array_column((array) $input['locations']['places'], null, 'id');
        $conflicts = array_values((array) $data['conflicts']);
        $sourceConflicts = array_values((array) ($context['source_blocking'] ?? []));
        $rows = [];

        foreach ((array) $data['scenes'] as $scene) {
            $ids = array_values(array_filter((array) $scene['beats'], static fn (string $id): bool => isset($facts[$id], $script[$id])));
            $beatRows = [];

            foreach ($ids as $id) {
                $fact = $facts[$id];
                $beat = $script[$id];
                $beatRows[] = [
                    'id' => $id,
                    'start_ms' => $fact['start_ms'],
                    'end_ms' => $fact['end_ms'],
                    'duration_ms' => $fact['end_ms'] - $fact['start_ms'],
                    'characters' => $fact['on_screen'],
                    'state' => $fact['state'],
                    'time_jump' => $fact['time_jump'],
                    'reveal' => $beat['reveal'],
                    'purpose' => $beat['purpose'],
                    'visual' => $beat['visual'],
                    'configurations' => array_values((array) $beat['configurations']),
                    'situation' => $beat['situation'],
                    'link' => $beat['link'],
                    'uses' => array_map(static fn (array $use): array => [
                        'part' => $use['part'],
                        'background' => $use['background'],
                        'location' => $plates[$use['background']]['location'],
                        'place' => $places[$plates[$use['background']]['location']]['name'] ?? null,
                        'support' => $plates[$use['background']]['support'],
                        'ground' => $plates[$use['background']]['ground'],
                        'light' => $plates[$use['background']]['light'],
                    ], (array) ($assigned[$id]['uses'] ?? [])),
                    'unplated' => array_values((array) ($assigned[$id]['unplated'] ?? [])),
                ];
            }

            $first = $beatRows[0] ?? null;
            $last = $beatRows === [] ? null : $beatRows[count($beatRows) - 1];
            $states = array_values(array_unique(array_filter(array_column($beatRows, 'state'), 'is_string')));
            $rows[] = [
                'id' => $scene['id'],
                'beats' => array_values((array) $scene['beats']),
                'start_ms' => $first['start_ms'] ?? null,
                'end_ms' => $last['end_ms'] ?? null,
                'duration_ms' => $first === null ? null : $last['end_ms'] - $first['start_ms'],
                'places' => array_values(array_unique(array_merge([], ...array_map(static fn (string $id): array => $facts[$id]['places'], $ids)))),
                'backgrounds' => array_values(array_unique(array_merge([], ...array_map(static fn (string $id): array => $facts[$id]['backgrounds'], $ids)))),
                'state' => count($states) === 1 ? $states[0] : null,
                'characters' => array_values(array_unique(array_merge([], ...array_column($beatRows, 'characters')))),
                'purpose' => $scene['purpose'],
                'set_brief' => $scene['set_brief'],
                'continuity_from' => $scene['continuity_from'],
                'continuity_start' => $scene['continuity_start'],
                'continuity_end' => $scene['continuity_end'],
                'transition_in' => $scene['transition_in'],
                'work' => array_key_exists('work', $scene)
                    ? array_map(static fn (int $index, array $item): array => ['id' => MotoWork::key((string) $scene['id'], $index)] + $item, array_keys(array_values((array) $scene['work'])), array_values((array) $scene['work']))
                    : null,
                'assumptions' => array_values((array) $scene['assumptions']),
                'beat_rows' => $beatRows,
                'conflicts' => array_values(array_filter($conflicts, static fn (array $conflict): bool => in_array($scene['id'], (array) $conflict['scenes'], true) || array_intersect($ids, (array) $conflict['beats']) !== [])),
                'source_conflicts' => array_values(array_filter($sourceConflicts, static fn (array $conflict): bool => array_intersect($ids, (array) $conflict['beats']) !== [])),
            ];
        }

        return [
            'version' => self::VERSION,
            'total_ms' => (int) $context['total_ms'],
            'scenes' => $rows,
            'conflicts' => $conflicts,
            'source_conflicts' => $sourceConflicts,
        ];
    }
}
