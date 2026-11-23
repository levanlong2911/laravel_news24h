<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoScenesCheck
{
    public const VERSION = 'moto-scenes-check-v8';

    /** @var string the check of a scene plan whose context pins MotoWork::LAW_V2: the detached parts are derived and not checked against an answer, work and connection changes are evidenced by the id of an event of their beat, and the work is followed by MotoWork::traceConnected() */
    public const VERSION_TRACKED = 'moto-scenes-check-v9';

    /** @var string the only evidence a work item or a connection change of a tracked plan gives: the id of an event of the beat */
    private const EVENT_EVIDENCE = '/^script\.beats\.(B[1-9][0-9]*)\.events\.(EV[0-9]+)\.id$/';

    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNSCORED = 'UNSCORED';

    public const JUDGE = 'not_run';

    /**
     * @param  ?array<string, mixed>  $response
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @param  list<array{code: string, path: string, message: string}>  $schema
     * @return array{version: string, format: string, verdict: string, status: ?string, error: ?string, schema: list<string>, issues: list<string>, warnings: list<string>, judge: string}
     */
    public static function evaluate(?array $response, array $context, array $schema): array
    {
        $format = in_array($context['format'] ?? null, [MotoContract::SCENES_FORMAT_V2, MotoContract::SCENES_FORMAT_V3], true) ? $context['format'] : MotoContract::SCENES_FORMAT;

        return ['format' => $format] + self::label($context) + (($unread = self::unreadStaging($context)) === null ? self::scored($response, $context, $schema, $format) : self::result(self::UNSCORED, null, $unread));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return ?string the law the run pinned for its work, null for a run made before laws were named: such a run is checked as it was made
     */
    private static function law(array $context): ?string
    {
        return is_string($context['work_tracking_version'] ?? null) ? $context['work_tracking_version'] : null;
    }

    /**
     * @param  ?array<string, mixed>  $response
     * @param  array<string, mixed>  $context
     * @param  list<array{code: string, path: string, message: string}>  $schema
     * @param  string  $format  the answer format the run pinned
     * @return array{version: string, format: string, verdict: string, status: ?string, error: ?string, schema: list<string>, issues: list<string>, warnings: list<string>, judge: string}
     */
    private static function scored(?array $response, array $context, array $schema, string $format): array
    {
        if ($response === null) {
            return self::result(self::UNSCORED, null, 'Phản hồi không đọc được JSON.');
        }

        if (($response['format'] ?? null) !== $format) {
            return self::result(self::UNSCORED, null, 'Phản hồi không theo định dạng '.$format.'.');
        }

        $status = is_string($response['status'] ?? null) ? $response['status'] : null;
        $schemaLines = array_map(static fn (array $v): string => trim($v['path'].' '.$v['message']), $schema);

        if ($schemaLines !== []) {
            return self::result(self::FAIL, $status, null, $schemaLines);
        }

        if ($status === 'fail') {
            return self::result(self::FAIL, $status, 'Model trả status fail: '.implode('; ', (array) ($response['flags'] ?? [])));
        }

        $issues = [];

        if (($response['project_id'] ?? null) !== ($context['project_id'] ?? null)) {
            $issues[] = Finding::blocking('MotoScenesCheck.scored.1', 'project_id không khớp lượt chạy.');
        }

        if (($response['prompt_version'] ?? null) !== ($context['prompt_version'] ?? null)) {
            $issues[] = Finding::blocking('MotoScenesCheck.scored.2', 'prompt_version là "'.($response['prompt_version'] ?? '').'"; lượt chạy này dùng '.($context['prompt_version'] ?? '').'.');
        }

        $data = (array) $response['data'];
        $scenes = array_values((array) $data['scenes']);
        $conflicts = array_values((array) $data['conflicts']);
        $beats = array_column((array) $context['beats'], null, 'id');
        $input = (array) $context['input'];
        $named = array_merge([], ...array_map(static fn (array $conflict): array => $conflict['blocking'] ? (array) $conflict['beats'] : [], $conflicts));

        $issues = [
            ...$issues,
            ...self::orderIssues($scenes, $beats),
            ...self::continuationIssues($scenes, $beats, (array) ($context['states'] ?? []), self::checkpointed($context)),
            ...self::conflictIssues($conflicts, $scenes, $beats, $input), ...self::stagingIssues($scenes, $context),
        ];
        $held = [];

        if ($scenes !== [] && array_filter($scenes, static fn (array $scene): bool => array_key_exists('work', $scene)) !== []) {
            [$workIssues, $held] = self::workIssues($scenes, $beats, $context);
            $issues = [...$issues, ...$workIssues];
        }

        $previous = null;

        foreach ($scenes as $index => $scene) {
            $issues = [...$issues, ...self::sceneIssues($scene, $index, $previous, $beats, $named, $context, $held[$scene['id']] ?? ['start' => [], 'end' => []])];
            $previous = $scene;
        }

        $blocking = array_filter($conflicts, static fn (array $conflict): bool => (bool) $conflict['blocking']) !== [] || (array) ($context['source_blocking'] ?? []) !== [];

        if ($status === 'ok' && $blocking) {
            $issues[] = Finding::requirement('MotoScenesCheck.scored.3', 'Còn xung đột chặn (của bước này hoặc của bộ bối cảnh đã duyệt) nhưng status là ok.');
        }

        if ($status === 'partial' && ! $blocking) {
            $issues[] = Finding::note('MotoScenesCheck.scored.4', 'Status partial nhưng không còn xung đột chặn nào.');
        }

        return self::result($issues === [] ? self::PASS : self::FAIL, $status, null, [], $issues);
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @param  array<string, array<string, mixed>>  $beats
     * @return list<string> scene ids out of sequence, beats unknown, repeated or missing, beats out of the script's order, and scene timing that does not cover the whole film
     */
    private static function orderIssues(array $scenes, array $beats): array
    {
        $issues = [];
        $flat = [];

        foreach ($scenes as $index => $scene) {
            $expected = sprintf('SC%02d', $index + 1);

            if ($scene['id'] !== $expected) {
                $issues[] = Finding::requirement('MotoScenesCheck.orderIssues.1', 'Scene thứ '.($index + 1)." là {$scene['id']}; cần {$expected}.");
            }

            $flat = [...$flat, ...(array) $scene['beats']];
        }

        $counts = array_count_values($flat);

        foreach ($counts as $id => $count) {
            if (! isset($beats[$id])) {
                $issues[] = Finding::requirement('MotoScenesCheck.orderIssues.2', "{$id} không có trong kịch bản đã duyệt.");
            } elseif ($count > 1) {
                $issues[] = Finding::requirement('MotoScenesCheck.orderIssues.3', "{$id} nằm ở {$count} scene.");
            }
        }

        foreach (array_keys($beats) as $id) {
            if (! isset($counts[$id])) {
                $issues[] = Finding::requirement('MotoScenesCheck.orderIssues.4', "{$id} không thuộc scene nào.");
            }
        }

        if ($issues === [] && $flat !== array_keys($beats)) {
            $issues[] = Finding::requirement('MotoScenesCheck.orderIssues.5', 'Các scene không giữ đúng thứ tự beat của kịch bản: '.implode(', ', $flat).'.');
        }

        if ($issues === []) {
            $cursor = 0;

            foreach ($scenes as $scene) {
                $first = $beats[$scene['beats'][0]];
                $last = $beats[$scene['beats'][count($scene['beats']) - 1]];

                if ($first['start_ms'] !== $cursor) {
                    $issues[] = Finding::requirement('MotoScenesCheck.orderIssues.6', ($context['timing'] ?? null) === MotoScenes::ORDINAL
                        ? "{$scene['id']} không nối tiếp scene trước theo thứ tự beat của kịch bản."
                        : "{$scene['id']} bắt đầu ở {$first['start_ms']} ms; scene trước kết thúc ở {$cursor} ms.");
                }

                $cursor = $last['end_ms'];
            }

            $total = $beats === [] ? 0 : (int) end($beats)['end_ms'];

            if ($cursor !== $total) {
                $issues[] = Finding::requirement('MotoScenesCheck.orderIssues.7', ($context['timing'] ?? null) === MotoScenes::ORDINAL
                    ? 'Các scene chưa phủ hết các beat của kịch bản.'
                    : "Các scene phủ tới {$cursor} ms; phim dài {$total} ms.");
            }
        }

        return $issues;
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, mixed>  $states  the physical build states the beats use
     * @return list<string> a scene none of whose beats establishes a build state, opening on the place and story time of a beat with the physical motorcycle: alone it would have no build state, and the shot step reads that as no motorcycle yet
     */
    private static function continuationIssues(array $scenes, array $beats, array $states, bool $checkpointed = false): array
    {
        $issues = [];
        $before = null;

        foreach ($scenes as $scene) {
            $ids = array_values((array) $scene['beats']);
            $first = $beats[$ids[0] ?? ''] ?? null;
            $established = array_filter(array_map(static fn (string $id): mixed => $beats[$id]['state'] ?? null, $ids), 'is_string') !== [];
            // The beat before shows the physical motorcycle: its state is a physical one, or in a v3 design it ends on a checkpoint.
            $physical = is_array($before) && ($checkpointed ? isset($states[(string) ($before['checkpoint_end'] ?? '')]) : isset($states[(string) $before['state']]));

            if (is_array($first) && is_array($before) && ! $established && $physical && $first['time_jump'] === $before['time_jump'] && array_intersect((array) $first['places'], (array) $before['places']) !== []) {
                $issues[] = Finding::note('MotoScenesCheck.continuationIssues.1', "{$scene['id']}: {$first['id']} không có build state và tiếp nối {$before['id']} cùng nơi, cùng thời gian truyện — phải cùng scene với {$before['id']}.");
            }

            $before = $beats[$ids[count($ids) - 1] ?? ''] ?? null;
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $scene
     * @param  ?array<string, mixed>  $previous  the scene before it
     * @param  array<string, array<string, mixed>>  $beats
     * @param  list<string>  $named  the beats a blocking conflict of the answer names
     * @param  array<string, mixed>  $context
     * @param  array{start: array<string, array{location: string, status: string}>, end: array<string, array{location: string, status: string}>}  $held  where the work holds each part it tracks at the scene's start and end
     * @return list<string> the scene's beats in more than one place, established build state or story time, its continuity references at its build state and the parts its work holds, its continuity link to the scene before it and its incoming transition
     */
    private static function sceneIssues(array $scene, int $index, ?array $previous, array $beats, array $named, array $context, array $held): array
    {
        $id = (string) $scene['id'];
        $mine = array_values(array_filter(array_map(static fn (string $beat): ?array => $beats[$beat] ?? null, (array) $scene['beats'])));
        $issues = [];

        if ($mine === []) {
            return $issues;
        }

        $known = array_values(array_filter($mine, static fn (array $beat): bool => $beat['places'] !== []));
        $unknown = array_values(array_filter($mine, static fn (array $beat): bool => $beat['places'] === []));

        if ($known !== [] && $unknown !== []) {
            $issues[] = Finding::note('MotoScenesCheck.sceneIssues.1', "{$id} gộp beat chưa có địa điểm (".implode(', ', array_column($unknown, 'id')).') với beat ở '.implode(', ', array_unique(array_merge(...array_column($known, 'places')))).'.');
        } elseif ($known !== []) {
            $common = array_values(array_intersect(...array_map(static fn (array $beat): array => $beat['places'], $known)));

            if ($common === [] && count($known) > 1) {
                $issues[] = Finding::note('MotoScenesCheck.sceneIssues.2', "{$id} gộp beat ở các nơi khác nhau: ".implode('; ', array_map(static fn (array $beat): string => $beat['id'].' '.implode('+', $beat['places']), $known)).'.');
            }
        }

        $states = array_values(array_unique(array_filter(array_column($mine, 'state'), 'is_string')));

        if (count($states) > 1) {
            $issues[] = Finding::requirement('MotoScenesCheck.sceneIssues.3', "{$id} gộp các build state khác nhau: ".implode(', ', $states).'.');
        }

        $times = array_values(array_unique(array_column($mine, 'time_jump')));

        if (count($times) > 1) {
            $issues[] = Finding::note('MotoScenesCheck.sceneIssues.4', "{$id} gộp beat khác thời gian truyện: ".implode(', ', array_map(static fn (array $beat): string => "{$beat['id']} {$beat['time_jump']}", $mine)).'.');
        }

        $state = count($states) === 1 ? $states[0] : null;
        $onScreen = array_values(array_unique(array_merge(...array_column($mine, 'on_screen'))));

        foreach (['continuity_start' => 'start', 'continuity_end' => 'end'] as $key => $moment) {
            $issues = [...$issues, ...self::continuityIssues("{$id}.{$key}", (array) $scene[$key], $onScreen, $context)];
        }

        if ($scene['continuity_from'] !== null && ($previous === null || $scene['continuity_from'] !== $previous['id'])) {
            $issues[] = Finding::requirement('MotoScenesCheck.sceneIssues.5', "{$id}.continuity_from là {$scene['continuity_from']}; chỉ được nêu scene ngay trước".($previous === null ? ' — scene đầu không có.' : " ({$previous['id']})."));
        }

        $transition = $scene['transition_in'];

        if ($index === 0) {
            if ($transition !== null) {
                $issues[] = Finding::note('MotoScenesCheck.sceneIssues.6', "{$id} là scene đầu — transition_in phải null.");
            }

            return $issues;
        }

        if ($transition === null) {
            // The film plan joins this scene to the one before by its transition_in.
            return [...$issues, Finding::requirement('MotoScenesCheck.sceneIssues.transition_missing', "{$id} thiếu transition_in.")];
        }

        foreach ((array) $transition['evidence'] as $evidence) {
            if (($problem = self::evidencePathProblem($evidence, (array) $context['input'])) !== null) {
                $issues[] = Finding::note('MotoScenesCheck.sceneIssues.7', "{$id}.transition_in: {$problem}");
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $continuity
     * @param  list<string>  $onScreen  the characters the scene's beats show
     * @param  array<string, mixed>  $context
     * @return list<string> a detached part or a light the design does not have or names twice, and protection for a character the scene does not show or named twice. Whether a part is detached, a light can be on or a scene continues the one before it is content the model writes and the reviewer judges
     */
    private static function continuityIssues(string $label, array $continuity, array $onScreen, array $context): array
    {
        $issues = [];

        if (is_array($continuity['detached_parts'] ?? null)) {
            $seen = [];

            foreach ($continuity['detached_parts'] as $item) {
                $part = (string) $item['part'];

                if (isset($seen[$part])) {
                    $issues[] = Finding::note('MotoScenesCheck.continuityIssues.1', "{$label}.detached_parts nêu {$part} hai lần.");
                }

                $seen[$part] = true;

                if (! isset($context['parts'][$part])) {
                    $issues[] = Finding::requirement('MotoScenesCheck.continuityIssues.2', "{$label}.detached_parts nêu {$part}, không có trong design_states.parts.");
                }
            }
        }

        foreach (is_array($continuity['lights_on'] ?? null) ? $continuity['lights_on'] : [] as $lightId) {
            if (! isset($context['lights'][$lightId])) {
                $issues[] = Finding::requirement('MotoScenesCheck.continuityIssues.3', "{$label}.lights_on nêu {$lightId}, không có trong design_states.lights.");
            }
        }

        $seen = [];

        foreach ((array) $continuity['protection'] as $entry) {
            $character = (string) $entry['character'];

            if (isset($seen[$character])) {
                $issues[] = Finding::note('MotoScenesCheck.continuityIssues.4', "{$label}.protection nêu {$character} hai lần.");
            }

            $seen[$character] = true;

            if (! in_array($character, $onScreen, true)) {
                $issues[] = Finding::note('MotoScenesCheck.continuityIssues.5', "{$label}.protection nêu {$character}, nhưng không beat nào của scene có {$character} trên màn hình.");
            }
        }

        return $issues;
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, mixed>  $context
     * @return array{0: list<string>, 1: array<string, array{start: array<string, array{location: string, status: string}>, end: array<string, array{location: string, status: string}>}>} the work that does not fit the build states, the design or its own continuity, or whose evidence is not in its beat, and where the work holds each part at the start and end of every scene
     */
    private static function workIssues(array $scenes, array $beats, array $context): array
    {
        if (! is_array($context['work_design'] ?? null)) {
            // The run was made without what the work is checked against: the run, not the answer, cannot be used.
            return [[Finding::blocking('MotoScenesCheck.workIssues.context', 'Ngữ cảnh kiểm của lượt này không có dữ liệu thiết kế cho work (part gắn trên part nào, build state đầu tiên trên xe).')], []];
        }

        $issues = [];
        $law = self::law($context);

        foreach ($scenes as $scene) {
            foreach (array_values((array) ($scene['work'] ?? [])) as $index => $item) {
                $label = MotoWork::key((string) $scene['id'], $index);

                if ($law !== null) {
                    $issues = [...$issues, ...self::eventEvidenceIssues($label, (string) $item['beat'], (array) $item['evidence'], (array) $context['input'])];

                    foreach (array_values((array) ($item['connection_changes'] ?? [])) as $change) {
                        $issues = [...$issues, ...self::eventEvidenceIssues("{$label} connection_changes {$change['change']} {$change['part']} — {$change['parent']}", (string) $item['beat'], (array) $change['evidence'], (array) $context['input'])];
                    }

                    continue;
                }

                foreach ((array) $item['evidence'] as $evidence) {
                    if (! str_starts_with((string) $evidence['path'], "script.beats.{$item['beat']}.")) {
                        $issues[] = Finding::requirement('MotoScenesCheck.workIssues.1', "{$label}: bằng chứng {$evidence['path']} không thuộc {$item['beat']} — work dựa vào chính beat của nó.");
                    } elseif (($problem = self::evidencePathProblem($evidence, (array) $context['input'])) !== null) {
                        $issues[] = Finding::requirement('MotoScenesCheck.workIssues.2', "{$label}: {$problem}");
                    }
                }
            }
        }

        // The work keeps a part where it left it within a build state; in a v3 design it reads each beat at the checkpoints it runs through.
        $trace = MotoWork::traced($law, $scenes, array_map(static fn (array $beat): ?string => is_string($beat['state'] ?? null) ? $beat['state'] : null, $beats), (array) $context['states'], $context['work_design'], MotoDesignStates::ofFacts($context)->workSpans($beats));

        return [[...$issues, ...$trace['issues']], $trace['scenes']];
    }

    /**
     * @param  string  $beat  the beat of the work item
     * @param  list<array{path: string, value?: mixed, quote?: string}>  $evidence
     * @param  array<string, mixed>  $input  the pinned input of the run
     * @return list<string> evidence that is not the id of an event, that names an event of another beat, whose value is not that id, or whose event the pinned script does not have in that beat
     */
    private static function eventEvidenceIssues(string $label, string $beat, array $evidence, array $input): array
    {
        $issues = [];

        foreach ($evidence as $item) {
            $path = (string) ($item['path'] ?? '');

            if (preg_match(self::EVENT_EVIDENCE, $path, $match) !== 1) {
                $issues[] = Finding::requirement('MotoScenesCheck.eventEvidenceIssues.1', "{$label}: bằng chứng {$path} không phải ID sự kiện — ghi script.beats.{$beat}.events.<EVxx>.id với value là chính ID đó.");
            } elseif ($match[1] !== $beat) {
                $issues[] = Finding::requirement('MotoScenesCheck.eventEvidenceIssues.2', "{$label}: bằng chứng {$path} thuộc {$match[1]}, không thuộc {$beat} — work dựa vào sự kiện của chính beat của nó.");
            } elseif (! array_key_exists('value', $item) || $item['value'] !== $match[2]) {
                $issues[] = Finding::requirement('MotoScenesCheck.eventEvidenceIssues.3', "{$label}: bằng chứng {$path} phải có value là \"{$match[2]}\".");
            } elseif (($problem = self::evidencePathProblem($item, $input)) !== null) {
                $issues[] = Finding::requirement('MotoScenesCheck.eventEvidenceIssues.4', "{$label}: {$problem}");
            }
        }

        return $issues;
    }

    /**
     * @param  list<array<string, mixed>>  $conflicts
     * @param  list<array<string, mixed>>  $scenes
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, mixed>  $input
     * @return list<string> conflicts naming nothing, an unknown scene or beat, a beat outside the scenes they name, or evidence not found in the pinned input
     */
    private static function conflictIssues(array $conflicts, array $scenes, array $beats, array $input): array
    {
        $issues = [];
        $byId = array_column($scenes, null, 'id');

        foreach ($conflicts as $index => $conflict) {
            $label = 'conflicts['.$index.']';

            if ($conflict['scenes'] === [] && $conflict['beats'] === []) {
                $issues[] = Finding::note('MotoScenesCheck.conflictIssues.1', "{$label} không nêu scene hay beat nào.");
            }

            foreach ((array) $conflict['scenes'] as $id) {
                if (! isset($byId[$id])) {
                    $issues[] = Finding::note('MotoScenesCheck.conflictIssues.2', "{$label} nêu {$id}, không có trong scenes.");
                }
            }

            foreach ((array) $conflict['beats'] as $id) {
                if (! isset($beats[$id])) {
                    $issues[] = Finding::note('MotoScenesCheck.conflictIssues.3', "{$label} nêu {$id}, không có trong kịch bản.");
                } elseif ($conflict['scenes'] !== [] && array_filter((array) $conflict['scenes'], static fn (string $scene): bool => in_array($id, (array) ($byId[$scene]['beats'] ?? []), true)) === []) {
                    $issues[] = Finding::note('MotoScenesCheck.conflictIssues.4', "{$label}: {$id} không thuộc ".implode(', ', (array) $conflict['scenes']).'.');
                }
            }

            foreach ((array) $conflict['evidence'] as $evidence) {
                if (($problem = self::evidencePathProblem($evidence, $input)) !== null) {
                    $issues[] = Finding::note('MotoScenesCheck.conflictIssues.5', "{$label}: {$problem}");
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array{path: string, quote?: string, value?: mixed}  $evidence
     * @param  array<string, mixed>  $input  the pinned input of the run
     * @return ?string why the path of the evidence names nothing in the pinned input; what the evidence quotes or gives as value is not read
     */
    public static function evidencePathProblem(array $evidence, array $input): ?string
    {
        return self::resolve((string) $evidence['path'], $input)[0] ? null : "{$evidence['path']} không có trong đầu vào đã ghim.";
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: bool, 1: mixed} whether the path names a value of the pinned input, and that value: a list item is named by its id, its beat or its index
     */
    private static function resolve(string $path, array $input): array
    {
        $node = $input;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($node)) {
                return [false, null];
            }

            if (array_is_list($node)) {
                if (ctype_digit($segment) && array_key_exists((int) $segment, $node)) {
                    $node = $node[(int) $segment];

                    continue;
                }

                foreach ($node as $item) {
                    if (is_array($item) && (($item['id'] ?? null) === $segment || ($item['beat'] ?? null) === $segment)) {
                        $node = $item;

                        continue 2;
                    }
                }

                return [false, null];
            }

            if (! array_key_exists($segment, $node)) {
                return [false, null];
            }

            $node = $node[$segment];
        }

        return [true, $node];
    }

    /**
     * @param  list<string>  $schema
     * @param  list<string>  $issues
     * @return array{version: string, format: string, verdict: string, status: ?string, error: ?string, schema: list<string>, issues: list<string>, warnings: list<string>, judge: string}
     */
    private static function result(string $verdict, ?string $status, ?string $error, array $schema = [], array $issues = []): array
    {
        return [
            'version' => self::VERSION,
            'format' => MotoContract::SCENES_FORMAT,
            'verdict' => $verdict,
            'status' => $status,
            'error' => $error,
            'schema' => $schema,
            ...Finding::fields($issues, []),
            'judge' => self::JUDGE,
        ];
    }

    /** @param  array<string, mixed>  $context  the check context pinned with the run */
    private static function checkpointed(array $context): bool
    {
        return ($context[MotoDesignStates::LAYOUT_KEY] ?? null) === MotoDesignStates::CHECKPOINTS;
    }

    /** @var string the check of a scene plan whose context pins the staging contract (MotoScenes::STAGING): the check of a tracked plan, and the pace of every scene is one of MotoScenes::PACES — a fixed list read as a list, never a judgement of the pace chosen — and the emotion of every scene holds a word at its opening and at its end, whatever that word is. A plan whose context pins no staging contract is checked as it was made */
    public const VERSION_STAGED = 'moto-scenes-check-v10';

    /** @var string the check of a scene plan whose context pins the second staging contract (MotoScenes::STAGING_V2): the staged check, and every event of a scene is marked once in its blocking, by its id in brackets, in script order — the marks are read as ids and compared with the pinned events, never the words beside them */
    public const VERSION_MARKED = 'moto-scenes-check-v11';

    /** @var string an event mark in a blocking: an event id alone in brackets, written as the source writes it; any other form is no mark */
    private const EVENT_MARK = '/\((EV[0-9]{2,3})\)/';

    /**
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @return array{version?: string} the version the check of the run is told by: the marked check for a run that pinned the second staging contract, the staged check for one that pinned the first, the tracked check for one that pinned a work law and no staging contract, none for a run made before either, which keeps the version of the first check, and none for a run that pinned a staging contract code does not read (unreadStaging), which is never scored
     */
    private static function label(array $context): array
    {
        return match (true) {
            self::marked($context) => ['version' => self::VERSION_MARKED],
            self::staged($context) => ['version' => self::VERSION_STAGED],
            self::law($context) !== null && ($context['staging_version'] ?? null) === null => ['version' => self::VERSION_TRACKED],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @return bool whether the run pinned a staging contract code reads (MotoScenes::STAGINGS) with a work law, as MotoScenePlanner reads it: its scenes state their pace and the emotion they open and end on
     */
    private static function staged(array $context): bool
    {
        return self::law($context) !== null && in_array($context['staging_version'] ?? null, MotoScenes::STAGINGS, true);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return bool whether the run pinned the second staging contract with a work law: its scenes also mark every event in their blocking
     */
    private static function marked(array $context): bool
    {
        return self::law($context) !== null && ($context['staging_version'] ?? null) === MotoScenes::STAGING_V2;
    }

    /**
     * @param  array<string, mixed>  $scene  one scene of the answer
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @return list<string> one finding when the event marks of the blocking are not the events of the scene's beats, each once, in script order: the events are read from the pinned beats in their order, the marks from the blocking alone, both as written. Whether the words beside a mark stage that event is never asked
     */
    private static function markIssues(array $scene, array $context): array
    {
        $ids = array_map('strval', (array) ($scene['beats'] ?? []));
        $expected = [];

        foreach ((array) ($context['beats'] ?? []) as $beat) {
            if (in_array($beat['id'] ?? null, $ids, true)) {
                $expected = [...$expected, ...array_map('strval', (array) ($beat['events'] ?? []))];
            }
        }

        $found = preg_match_all(self::EVENT_MARK, is_string($scene['blocking'] ?? null) ? $scene['blocking'] : '', $marks) > 0 ? $marks[1] : [];

        if ($found === $expected) {
            return [];
        }

        $counts = array_count_values($found);
        $faults = array_filter([
            'thiếu' => array_values(array_diff($expected, $found)),
            'trùng' => array_keys(array_filter($counts, static fn (int $count): bool => $count > 1)),
            'ngoài scene' => array_values(array_unique(array_diff($found, $expected))),
        ]);
        $why = $faults === [] ? 'sai thứ tự' : implode('; ', array_map(static fn (string $kind, array $events): string => $kind.' '.implode(', ', $events), array_keys($faults), $faults));

        return [Finding::requirement('MotoScenesCheck.stagingIssues.3', "{$scene['id']}.blocking đánh dấu ".($found === [] ? 'không sự kiện nào' : implode(', ', $found)).'; cần '.implode(', ', $expected)." — {$why}. Mỗi sự kiện của scene một dấu (EVxx) cạnh phần dàn cảnh của nó, đúng thứ tự kịch bản; dấu đúng thứ tự không chứng minh câu bên cạnh tả đúng sự kiện.")];
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @return list<string> for a run that pinned a staging contract, every scene whose pace is not one of the paces of that contract and every emotion, at the opening or the end, that holds no word — the structure check has already held the answer to the schema, which reads neither an enumeration nor whether a text is blank — and, for a run of the second contract, every scene whose blocking does not mark its events (markIssues). Whether a pace, an emotion or a blocking suits the scene is never asked; nothing for any other run
     */
    private static function stagingIssues(array $scenes, array $context): array
    {
        $issues = [];

        foreach (self::staged($context) ? $scenes : [] as $scene) {
            if (! in_array($scene['pace'] ?? null, MotoScenes::PACES, true)) {
                $issues[] = Finding::requirement('MotoScenesCheck.stagingIssues.1', "{$scene['id']}.pace là ".json_encode($scene['pace'] ?? null, JSON_UNESCAPED_UNICODE).'; chỉ nhận '.implode(', ', MotoScenes::PACES).'.');
            }

            foreach (['start', 'end'] as $moment) {
                $emotion = is_array($scene['emotion'] ?? null) ? ($scene['emotion'][$moment] ?? null) : null;

                if (! is_string($emotion) || trim($emotion) === '') {
                    $issues[] = Finding::requirement('MotoScenesCheck.stagingIssues.2', "{$scene['id']}.emotion.{$moment} để trống — hợp đồng dàn dựng cần cảm xúc lúc mở và lúc kết của mỗi scene.");
                }
            }

            if (self::marked($context)) {
                $issues = [...$issues, ...self::markIssues($scene, $context)];
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @return ?string why the staging contract the run pinned is read by no rule of this code — a contract not in MotoScenes::STAGINGS, or one pinned without a work law, which MotoScenes never builds — null for a run that pinned none, read as it was made, and for one that pinned a contract code reads. Such a run is never checked nor planned by the rules of another contract: the check leaves it unscored with this reason and MotoScenePlanner::plan throws it
     */
    public static function unreadStaging(array $context): ?string
    {
        $pinned = $context['staging_version'] ?? null;

        if ($pinned === null || self::staged($context)) {
            return null;
        }

        return 'Lượt chia scene này ghim hợp đồng dàn dựng '.(is_string($pinned) ? $pinned : json_encode($pinned, JSON_UNESCAPED_UNICODE)).(self::law($context) === null ? ' mà không ghim luật work' : '').'; code chỉ đọc '.implode(', ', MotoScenes::STAGINGS).' kèm luật work — không chấm và không lập kế hoạch scene theo hợp đồng khác. Chia lại scene theo bản phát hành hiện hành.';
    }
}
