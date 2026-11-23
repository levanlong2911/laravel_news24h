<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoScenePlanner
{
    public const VERSION = 'moto-scenes-plan-v2';

    /** @var string the plan of a run whose context pins a work law: it names that law and the release, derives the detached parts of every scene from the work and gives every work item the events its evidence names. Work that contradicts itself fails nothing: the parts it concerns are derived as of unknown place in their build state, every other part keeps what the work established, and the contradictions are listed for the reviewer */
    public const VERSION_TRACKED = 'moto-scenes-plan-v3';

    /** @var string the plan of a run whose context pins the staging contract (MotoScenes::STAGING): the tracked plan, and every scene keeps the pace and the emotion the answer wrote for it, so the shot step is handed both. A run whose context pins no staging contract is planned exactly as before: its scenes carry neither key */
    public const VERSION_STAGED = 'moto-scenes-plan-v4';

    /** @var string the plan of a run whose context pins the second staging contract (MotoScenes::STAGING_V2): the staged plan, and a scene that closes on ending beats with no checkpoint of their own closes on the checkpoint of the physical stage it stays in (endingCheckpoint). A run of the first contract is planned exactly as before */
    public const VERSION_MARKED = 'moto-scenes-plan-v5';

    /** @var list<string> the situations of a beat that shows a drawing or a model, never the real motorcycle */
    private const DRAWN = ['representation', 'mockup'];

    /**
     * @param  array<string, mixed>  $document  a moto Phase 6 document that passed its schema
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @return array{version: string, total_ms: ?int, scenes: list<array<string, mixed>>, conflicts: list<array<string, mixed>>, source_conflicts: list<array<string, mixed>>} every scene with the timing, places, plates, characters and build state code derives from its beats and what the answer wrote for it, its work items with the id code gives them (null for an answer written before work existed); every beat keeps its own times, characters, state, configurations, situation, plate uses and unplated parts from the pinned input; the background text's blocking conflicts stay with the beats they name
     *
     * @throws \RuntimeException when the run pinned a staging contract code does not read (MotoScenesCheck::unreadStaging): it is never planned by the rules of another contract
     */
    public static function plan(array $document, array $context): array
    {
        if (($unread = MotoScenesCheck::unreadStaging($context)) !== null) {
            throw new \RuntimeException($unread);
        }

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
        $pinned = is_string($context['staging_version'] ?? null) ? $context['staging_version'] : null;
        $staged = $law !== null && in_array($pinned, MotoScenes::STAGINGS, true);
        $marked = $staged && $pinned === MotoScenes::STAGING_V2;
        // A design of seven stages: each beat keeps the checkpoints it runs through, and the work reads them; a legacy design reads each beat at its build state.
        $layout = MotoDesignStates::ofFacts($context);
        $trace = $law === null || ! is_array($context['work_design'] ?? null) ? null : MotoWork::follow(
            $law,
            array_values((array) $data['scenes']),
            array_map(static fn (array $beat): ?string => is_string($beat['state'] ?? null) ? $beat['state'] : null, $facts),
            (array) $context['states'],
            $context['work_design'],
            $layout->workSpans($facts),
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
                ] + ($layout->checkpointed() ? ['checkpoint_start' => $fact['checkpoint_start'] ?? null, 'checkpoint_end' => $fact['checkpoint_end'] ?? null] : []) + [
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
            // A scene of a v3 design opens on the checkpoint its first beat opens on and ends on the one its last beat ends on.
            $opens = $layout->checkpointed() ? ($first['checkpoint_start'] ?? null) : null;
            $closes = $layout->checkpointed() ? ($last['checkpoint_end'] ?? null) : null;
            $closes = $marked && $layout->checkpointed() && $closes === null ? self::endingCheckpoint($beatRows, $script, array_values((array) ($scene['work'] ?? [])), $states, $layout) : $closes;
            $momentFacts = $layout->checkpointed()
                ? ['start' => count($states) === 1 && is_string($opens) ? ($context['states'][$opens] ?? null) : null, 'end' => count($states) === 1 && is_string($closes) ? ($context['states'][$closes] ?? null) : null]
                : ['start' => $stateFacts, 'end' => $stateFacts];
            $derived = [];

            foreach ($law === null ? [] : ['continuity_start' => 'start', 'continuity_end' => 'end'] as $key => $moment) {
                $held = $trace['scenes'][$scene['id']][$moment] ?? null;
                $stateFacts = $momentFacts[$moment];
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
            ] + ($layout->checkpointed() ? ['checkpoint_start' => $opens, 'checkpoint_end' => $closes] : []) + [
                'characters' => array_values(array_unique(array_merge([], ...array_column($beatRows, 'characters')))),
                'purpose' => $scene['purpose'],
                'set_brief' => $scene['set_brief'],
            ] + ($staged ? ['pace' => $scene['pace'] ?? null, 'emotion' => ['start' => $scene['emotion']['start'] ?? null, 'end' => $scene['emotion']['end'] ?? null]] : []) + ($timed ? [] : [
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
            'version' => match (true) {
                $marked => self::VERSION_MARKED,
                $staged => self::VERSION_STAGED,
                default => self::VERSION_TRACKED,
            },
            'release' => $context['release'] ?? null,
            'input_format' => $context['input_format'] ?? null,
            'work_tracking_version' => $law,
            'work_tracking_sound' => $trace !== null && $trace['contradictions'] === [],
            'work_contradictions' => $trace === null ? [] : array_values(array_map('strval', $trace['contradictions'])),
        ]) + ($staged ? ['staging_version' => $pinned] : []) + [
            'total_ms' => $timed ? (int) $context['total_ms'] : null,
            'scenes' => $rows,
            'conflicts' => $conflicts,
            'source_conflicts' => $sourceConflicts,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows  the beat rows of a scene, in order
     * @param  array<string, array<string, mixed>>  $script  the pinned script beats by id
     * @param  list<mixed>  $work  the work items of the scene as the answer wrote them
     * @param  list<string>  $states  the build states the beats of the scene name
     * @return ?string the checkpoint a scene closes on when its last beats are ending beats with no checkpoint of their own: the checkpoint the last beat before them ends on — only while the scene stays in one physical stage and that checkpoint belongs to it, every trailing beat is an ending beat that names no build state, no checkpoint and no drawing or model, and no work item stands on a trailing beat; null otherwise. Only the checkpoint of the scene is read this way: the beats keep their own checkpoints, and no operating state, configuration or support of the earlier beat is carried over
     */
    private static function endingCheckpoint(array $rows, array $script, array $work, array $states, MotoDesignStates $layout): ?string
    {
        if (count($states) !== 1 || ! $layout->physicalStage($states[0])) {
            return null;
        }

        $trailing = [];

        for ($index = count($rows) - 1; $index >= 0 && ($rows[$index]['checkpoint_end'] ?? null) === null; $index--) {
            $row = $rows[$index];
            $source = (array) ($script[$row['id']] ?? []);

            if (($source['act'] ?? null) !== 'ending' || ($row['state'] ?? null) !== null || ($row['checkpoint_start'] ?? null) !== null || in_array($source['situation']['mode'] ?? null, self::DRAWN, true)) {
                return null;
            }

            $trailing[] = (string) $row['id'];
        }

        $anchor = $index >= 0 ? ($rows[$index]['checkpoint_end'] ?? null) : null;
        $worked = array_filter($work, static fn (mixed $item): bool => is_array($item) && in_array($item['beat'] ?? null, $trailing, true));

        return is_string($anchor) && $trailing !== [] && $worked === [] && $layout->stageOf($anchor) === $states[0] ? $anchor : null;
    }
}
