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

        return ['format' => $format] + (self::law($context) === null ? [] : ['version' => self::VERSION_TRACKED]) + self::scored($response, $context, $schema, $format);
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
            $issues[] = 'project_id không khớp lượt chạy.';
        }

        if (($response['prompt_version'] ?? null) !== ($context['prompt_version'] ?? null)) {
            $issues[] = 'prompt_version là "'.($response['prompt_version'] ?? '').'"; lượt chạy này dùng '.($context['prompt_version'] ?? '').'.';
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
            ...self::continuationIssues($scenes, $beats, (array) ($context['states'] ?? [])),
            ...self::conflictIssues($conflicts, $scenes, $beats, $input),
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
            $issues[] = 'Còn xung đột chặn (của bước này hoặc của bộ bối cảnh đã duyệt) nhưng status là ok.';
        }

        if ($status === 'partial' && ! $blocking) {
            $issues[] = 'Status partial nhưng không còn xung đột chặn nào.';
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
                $issues[] = 'Scene thứ '.($index + 1)." là {$scene['id']}; cần {$expected}.";
            }

            $flat = [...$flat, ...(array) $scene['beats']];
        }

        $counts = array_count_values($flat);

        foreach ($counts as $id => $count) {
            if (! isset($beats[$id])) {
                $issues[] = "{$id} không có trong kịch bản đã duyệt.";
            } elseif ($count > 1) {
                $issues[] = "{$id} nằm ở {$count} scene.";
            }
        }

        foreach (array_keys($beats) as $id) {
            if (! isset($counts[$id])) {
                $issues[] = "{$id} không thuộc scene nào.";
            }
        }

        if ($issues === [] && $flat !== array_keys($beats)) {
            $issues[] = 'Các scene không giữ đúng thứ tự beat của kịch bản: '.implode(', ', $flat).'.';
        }

        if ($issues === []) {
            $cursor = 0;

            foreach ($scenes as $scene) {
                $first = $beats[$scene['beats'][0]];
                $last = $beats[$scene['beats'][count($scene['beats']) - 1]];

                if ($first['start_ms'] !== $cursor) {
                    $issues[] = ($context['timing'] ?? null) === MotoScenes::ORDINAL
                        ? "{$scene['id']} không nối tiếp scene trước theo thứ tự beat của kịch bản."
                        : "{$scene['id']} bắt đầu ở {$first['start_ms']} ms; scene trước kết thúc ở {$cursor} ms.";
                }

                $cursor = $last['end_ms'];
            }

            $total = $beats === [] ? 0 : (int) end($beats)['end_ms'];

            if ($cursor !== $total) {
                $issues[] = ($context['timing'] ?? null) === MotoScenes::ORDINAL
                    ? 'Các scene chưa phủ hết các beat của kịch bản.'
                    : "Các scene phủ tới {$cursor} ms; phim dài {$total} ms.";
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
    private static function continuationIssues(array $scenes, array $beats, array $states): array
    {
        $issues = [];
        $before = null;

        foreach ($scenes as $scene) {
            $ids = array_values((array) $scene['beats']);
            $first = $beats[$ids[0] ?? ''] ?? null;
            $established = array_filter(array_map(static fn (string $id): mixed => $beats[$id]['state'] ?? null, $ids), 'is_string') !== [];

            if (is_array($first) && is_array($before) && ! $established && isset($states[(string) $before['state']]) && $first['time_jump'] === $before['time_jump'] && array_intersect((array) $first['places'], (array) $before['places']) !== []) {
                $issues[] = "{$scene['id']}: {$first['id']} không có build state và tiếp nối {$before['id']} cùng nơi, cùng thời gian truyện — phải cùng scene với {$before['id']}.";
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
            $issues[] = "{$id} gộp beat chưa có địa điểm (".implode(', ', array_column($unknown, 'id')).') với beat ở '.implode(', ', array_unique(array_merge(...array_column($known, 'places')))).'.';
        } elseif ($known !== []) {
            $common = array_values(array_intersect(...array_map(static fn (array $beat): array => $beat['places'], $known)));

            if ($common === [] && count($known) > 1) {
                $issues[] = "{$id} gộp beat ở các nơi khác nhau: ".implode('; ', array_map(static fn (array $beat): string => $beat['id'].' '.implode('+', $beat['places']), $known)).'.';
            }
        }

        $states = array_values(array_unique(array_filter(array_column($mine, 'state'), 'is_string')));

        if (count($states) > 1) {
            $issues[] = "{$id} gộp các build state khác nhau: ".implode(', ', $states).'.';
        }

        $times = array_values(array_unique(array_column($mine, 'time_jump')));

        if (count($times) > 1) {
            $issues[] = "{$id} gộp beat khác thời gian truyện: ".implode(', ', array_map(static fn (array $beat): string => "{$beat['id']} {$beat['time_jump']}", $mine)).'.';
        }

        $state = count($states) === 1 ? $states[0] : null;
        $onScreen = array_values(array_unique(array_merge(...array_column($mine, 'on_screen'))));

        foreach (['continuity_start' => 'start', 'continuity_end' => 'end'] as $key => $moment) {
            $issues = [...$issues, ...self::continuityIssues("{$id}.{$key}", (array) $scene[$key], $onScreen, $context)];
        }

        if ($scene['continuity_from'] !== null && ($previous === null || $scene['continuity_from'] !== $previous['id'])) {
            $issues[] = "{$id}.continuity_from là {$scene['continuity_from']}; chỉ được nêu scene ngay trước".($previous === null ? ' — scene đầu không có.' : " ({$previous['id']}).");
        }

        $transition = $scene['transition_in'];

        if ($index === 0) {
            if ($transition !== null) {
                $issues[] = "{$id} là scene đầu — transition_in phải null.";
            }

            return $issues;
        }

        if ($transition === null) {
            return [...$issues, "{$id} thiếu transition_in."];
        }

        foreach ((array) $transition['evidence'] as $evidence) {
            if (($problem = self::evidencePathProblem($evidence, (array) $context['input'])) !== null) {
                $issues[] = "{$id}.transition_in: {$problem}";
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
                    $issues[] = "{$label}.detached_parts nêu {$part} hai lần.";
                }

                $seen[$part] = true;

                if (! isset($context['parts'][$part])) {
                    $issues[] = "{$label}.detached_parts nêu {$part}, không có trong design_states.parts.";
                }
            }
        }

        foreach (is_array($continuity['lights_on'] ?? null) ? $continuity['lights_on'] : [] as $lightId) {
            if (! isset($context['lights'][$lightId])) {
                $issues[] = "{$label}.lights_on nêu {$lightId}, không có trong design_states.lights.";
            }
        }

        $seen = [];

        foreach ((array) $continuity['protection'] as $entry) {
            $character = (string) $entry['character'];

            if (isset($seen[$character])) {
                $issues[] = "{$label}.protection nêu {$character} hai lần.";
            }

            $seen[$character] = true;

            if (! in_array($character, $onScreen, true)) {
                $issues[] = "{$label}.protection nêu {$character}, nhưng không beat nào của scene có {$character} trên màn hình.";
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
            return [['Ngữ cảnh kiểm của lượt này không có dữ liệu thiết kế cho work (part gắn trên part nào, build state đầu tiên trên xe).'], []];
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
                        $issues[] = "{$label}: bằng chứng {$evidence['path']} không thuộc {$item['beat']} — work dựa vào chính beat của nó.";
                    } elseif (($problem = self::evidencePathProblem($evidence, (array) $context['input'])) !== null) {
                        $issues[] = "{$label}: {$problem}";
                    }
                }
            }
        }

        $trace = MotoWork::follow($law, $scenes, array_map(static fn (array $beat): ?string => is_string($beat['state'] ?? null) ? $beat['state'] : null, $beats), (array) $context['states'], $context['work_design']);

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
                $issues[] = "{$label}: bằng chứng {$path} không phải ID sự kiện — ghi script.beats.{$beat}.events.<EVxx>.id với value là chính ID đó.";
            } elseif ($match[1] !== $beat) {
                $issues[] = "{$label}: bằng chứng {$path} thuộc {$match[1]}, không thuộc {$beat} — work dựa vào sự kiện của chính beat của nó.";
            } elseif (! array_key_exists('value', $item) || $item['value'] !== $match[2]) {
                $issues[] = "{$label}: bằng chứng {$path} phải có value là \"{$match[2]}\".";
            } elseif (($problem = self::evidencePathProblem($item, $input)) !== null) {
                $issues[] = "{$label}: {$problem}";
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
                $issues[] = "{$label} không nêu scene hay beat nào.";
            }

            foreach ((array) $conflict['scenes'] as $id) {
                if (! isset($byId[$id])) {
                    $issues[] = "{$label} nêu {$id}, không có trong scenes.";
                }
            }

            foreach ((array) $conflict['beats'] as $id) {
                if (! isset($beats[$id])) {
                    $issues[] = "{$label} nêu {$id}, không có trong kịch bản.";
                } elseif ($conflict['scenes'] !== [] && array_filter((array) $conflict['scenes'], static fn (string $scene): bool => in_array($id, (array) ($byId[$scene]['beats'] ?? []), true)) === []) {
                    $issues[] = "{$label}: {$id} không thuộc ".implode(', ', (array) $conflict['scenes']).'.';
                }
            }

            foreach ((array) $conflict['evidence'] as $evidence) {
                if (($problem = self::evidencePathProblem($evidence, $input)) !== null) {
                    $issues[] = "{$label}: {$problem}";
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
            'issues' => array_values(array_unique($issues)),
            'warnings' => [],
            'judge' => self::JUDGE,
        ];
    }
}
