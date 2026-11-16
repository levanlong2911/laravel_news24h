<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoScenePlanner
{
    public const VERSION = 'moto-scenes-plan-v2';

    /** @var string the plan of a run whose context pins a work law: it names that law and the release, derives the detached parts of every scene from the work and gives every work item the events its evidence names. Work that contradicts itself fails nothing: the parts it concerns are derived as of unknown place in their build state, every other part keeps what the work established, and the contradictions are listed for the reviewer */
    public const VERSION_TRACKED = 'moto-scenes-plan-v3';

    /**
     * @param  array<string, mixed>  $document  a moto Phase 6 document that passed its schema
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @return array{version: string, total_ms: ?int, scenes: list<array<string, mixed>>, conflicts: list<array<string, mixed>>, source_conflicts: list<array<string, mixed>>} every scene with the timing, places, plates, characters and build state code derives from its beats and what the answer wrote for it, its work items with the id code gives them (null for an answer written before work existed); every beat keeps its own times, characters, state, configurations, situation, plate uses and unplated parts from the pinned input; the background text's blocking conflicts stay with the beats they name
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
        $timed = ($context['timing'] ?? null) !== MotoScenes::ORDINAL;
        $law = is_string($context['work_tracking_version'] ?? null) ? $context['work_tracking_version'] : null;
        $trace = $law === null || ! is_array($context['work_design'] ?? null) ? null : MotoWork::follow(
            $law,
            array_values((array) $data['scenes']),
            array_map(static fn (array $beat): ?string => is_string($beat['state'] ?? null) ? $beat['state'] : null, $facts),
            (array) $context['states'],
            $context['work_design'],
        );
        $events = [];

        foreach ($law === null ? [] : $script as $beatId => $beat) {
            foreach ((array) ($beat['events'] ?? []) as $event) {
                $events[$beatId][(string) $event['id']] = ['id' => (string) $event['id'], 'action' => $event['action'] ?? null, 'result' => $event['result'] ?? null];
            }
        }

        $rows = [];

        foreach ((array) $data['scenes'] as $scene) {
            $ids = array_values(array_filter((array) $scene['beats'], static fn (string $id): bool => isset($facts[$id], $script[$id])));
            $beatRows = [];

            foreach ($ids as $id) {
                $fact = $facts[$id];
                $beat = $script[$id];
                $beatRows[] = [
                    'id' => $id,
                    'start_ms' => $timed ? $fact['start_ms'] : null,
                    'end_ms' => $timed ? $fact['end_ms'] : null,
                    'duration_ms' => $timed ? $fact['end_ms'] - $fact['start_ms'] : null,
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
                ] + ($timed ? [] : ['events' => array_values((array) ($beat['events'] ?? []))]);
            }

            $first = $beatRows[0] ?? null;
            $last = $beatRows === [] ? null : $beatRows[count($beatRows) - 1];
            $states = array_values(array_unique(array_filter(array_column($beatRows, 'state'), 'is_string')));
            $stateFacts = count($states) === 1 ? ($context['states'][$states[0]] ?? null) : null;
            $derived = [];

            foreach ($law === null ? [] : ['continuity_start' => 'start', 'continuity_end' => 'end'] as $key => $moment) {
                $held = $trace['scenes'][$scene['id']][$moment] ?? null;
                $sound = is_array($stateFacts) && is_array($held);
                $doubted = $sound ? array_values(array_intersect(array_map('strval', array_keys($held)), (array) ($trace['doubt'][$states[0]] ?? []))) : [];
                $established = $sound ? array_diff_key($held, array_flip($doubted)) : [];
                $derived[$key] = (array) $scene[$key] + [
                    'detached_parts' => $sound ? MotoWork::detached($established) : null,
                    'unknown_location_parts' => $sound ? array_values(array_unique([...MotoWork::unplaced($stateFacts, $held), ...$doubted])) : null,
                ];
            }

            $rows[] = [
                'id' => $scene['id'],
                'beats' => array_values((array) $scene['beats']),
                'start_ms' => $first['start_ms'] ?? null,
                'end_ms' => $last['end_ms'] ?? null,
                'duration_ms' => $first === null || ! $timed ? null : $last['end_ms'] - $first['start_ms'],
                'places' => array_values(array_unique(array_merge([], ...array_map(static fn (string $id): array => $facts[$id]['places'], $ids)))),
                'backgrounds' => array_values(array_unique(array_merge([], ...array_map(static fn (string $id): array => $facts[$id]['backgrounds'], $ids)))),
                'state' => count($states) === 1 ? $states[0] : null,
                'characters' => array_values(array_unique(array_merge([], ...array_column($beatRows, 'characters')))),
                'purpose' => $scene['purpose'],
                'set_brief' => $scene['set_brief'],
            ] + ($timed ? [] : [
                'blocking' => $scene['blocking'] ?? null,
                'event_ids' => array_values(array_merge([], ...array_map(static fn (string $id): array => (array) ($facts[$id]['events'] ?? []), $ids))),
            ]) + [
                'continuity_from' => $scene['continuity_from'],
                'continuity_start' => $derived['continuity_start'] ?? $scene['continuity_start'],
                'continuity_end' => $derived['continuity_end'] ?? $scene['continuity_end'],
                'transition_in' => $scene['transition_in'],
                'work' => array_key_exists('work', $scene)
                    ? array_map(static fn (int $index, array $item): array => ['id' => MotoWork::key((string) $scene['id'], $index)] + $item + ($law === null ? [] : [
                        'events' => array_values(array_filter(array_map(
                            static fn (array $evidence): ?array => preg_match('/\.events\.(EV\d+)\.id$/', (string) ($evidence['path'] ?? ''), $match) === 1 ? ($events[$item['beat']][$match[1]] ?? null) : null,
                            (array) ($item['evidence'] ?? []),
                        ))),
                    ]), array_keys(array_values((array) $scene['work'])), array_values((array) $scene['work']))
                    : null,
                'assumptions' => array_values((array) $scene['assumptions']),
                'beat_rows' => $beatRows,
                'conflicts' => array_values(array_filter($conflicts, static fn (array $conflict): bool => in_array($scene['id'], (array) $conflict['scenes'], true) || array_intersect($ids, (array) $conflict['beats']) !== [])),
                'source_conflicts' => array_values(array_filter($sourceConflicts, static fn (array $conflict): bool => array_intersect($ids, (array) $conflict['beats']) !== [])),
            ];
        }

        return ($law === null ? ['version' => self::VERSION] : [
            'version' => self::VERSION_TRACKED,
            'release' => $context['release'] ?? null,
            'input_format' => $context['input_format'] ?? null,
            'work_tracking_version' => $law,
            'work_tracking_sound' => $trace !== null && $trace['contradictions'] === [],
            'work_contradictions' => $trace === null ? [] : array_values($trace['contradictions']),
        ]) + [
            'total_ms' => $timed ? (int) $context['total_ms'] : null,
            'scenes' => $rows,
            'conflicts' => $conflicts,
            'source_conflicts' => $sourceConflicts,
        ];
    }
}
