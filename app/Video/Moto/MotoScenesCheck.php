<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoScenesCheck
{
    public const VERSION = 'moto-scenes-check-v4';

    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNSCORED = 'UNSCORED';

    public const JUDGE = 'not_run';

    /** @var list<string> the input fields whose value is an id or an enumerated value, given as value and never quoted */
    private const VALUE_KEYS = ['id', 'beat', 'background', 'backgrounds', 'beats', 'hero_beat', 'plant', 'payoff', 'act', 'state', 'time_jump', 'reveal', 'on_screen', 'design_refs', 'in_use', 'configurations', 'mode', 'engine', 'side_stand', 'kind', 'support', 'ground', 'presence', 'temporary', 'installed', 'operable', 'default', 'blocking', 'configuration'];

    /** @var list<string> the fields of continuity compared across a continuity link */
    private const LINKED = ['detached_parts', 'lights_on', 'surface_condition'];

    /**
     * @param  ?array<string, mixed>  $response
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @param  list<array{code: string, path: string, message: string}>  $schema
     * @return array{version: string, format: string, verdict: string, status: ?string, error: ?string, schema: list<string>, issues: list<string>, warnings: list<string>, judge: string}
     */
    public static function evaluate(?array $response, array $context, array $schema): array
    {
        if ($response === null) {
            return self::result(self::UNSCORED, null, 'Phản hồi không đọc được JSON.');
        }

        if (($response['format'] ?? null) !== MotoContract::SCENES_FORMAT) {
            return self::result(self::UNSCORED, null, 'Phản hồi không theo định dạng '.MotoContract::SCENES_FORMAT.'.');
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
                    $issues[] = "{$scene['id']} bắt đầu ở {$first['start_ms']} ms; scene trước kết thúc ở {$cursor} ms.";
                }

                $cursor = $last['end_ms'];
            }

            $total = $beats === [] ? 0 : (int) end($beats)['end_ms'];

            if ($cursor !== $total) {
                $issues[] = "Các scene phủ tới {$cursor} ms; phim dài {$total} ms.";
            }
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
            } else {
                foreach ($known as $beat) {
                    if (count($beat['places']) > 1 && ! in_array($beat['id'], $named, true)) {
                        $issues[] = "{$beat['id']} dùng nhiều nơi (".implode(', ', $beat['places']).') — một scene chỉ ở một nơi; cần conflict chặn nêu beat này.';
                    }
                }
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
            $issues = [...$issues, ...self::continuityIssues("{$id}.{$key}", (array) $scene[$key], $state, $onScreen, $context, $held[$moment])];

            if ($state !== null && isset($context['states'][$state])) {
                $issues = [...$issues, ...MotoWork::detachedIssues("{$id}.{$key}", $scene[$key]['detached_parts'], $held[$moment])];
            }
        }

        $first = $mine[0]['id'];
        $before = $previous === null ? [] : array_values(array_filter(array_map(static fn (string $beat): ?array => $beats[$beat] ?? null, (array) $previous['beats'])));
        $last = $before === [] ? null : $before[count($before) - 1]['id'];

        if ($scene['continuity_from'] !== null) {
            if ($previous === null || $scene['continuity_from'] !== $previous['id']) {
                $issues[] = "{$id}.continuity_from là {$scene['continuity_from']}; chỉ được nêu scene ngay trước".($previous === null ? ' — scene đầu không có.' : " ({$previous['id']}).");
            } else {
                $issues = [...$issues, ...self::linkIssues($id, $scene, $previous, $state, $before, $mine)];
            }
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
            if (($problem = self::evidenceProblem($evidence, (array) $context['input'])) !== null) {
                $issues[] = "{$id}.transition_in: {$problem}";
            }
        }

        $boundary = array_values(array_filter([$last, $first], 'is_string'));
        $pattern = static fn (string $fields): string => '/^script\.beats\.('.implode('|', $boundary).')\.('.$fields.')(\.\d+)?$/';

        if ($transition['kind'] === 'match_cut' && array_filter((array) $transition['evidence'], static fn (array $evidence): bool => preg_match($pattern('link|visual|sfx'), $evidence['path']) === 1) === []) {
            $issues[] = "{$id}: match_cut phải dựa vào link, visual hoặc sfx của ".implode(' hoặc ', $boundary).'.';
        }

        if ($transition['kind'] === 'time_jump') {
            $declared = array_filter((array) $transition['evidence'], static fn (array $evidence): bool => preg_match($pattern('time_jump'), $evidence['path']) === 1 && array_key_exists('value', $evidence) && $evidence['value'] !== 'none');

            if ($declared === []) {
                $issues[] = "{$id}: time_jump phải dựa vào time_jump khác none của ".implode(' hoặc ', $boundary).' — link hình ảnh chỉ làm căn cứ cho match_cut.';
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $continuity
     * @param  ?string  $state  the build state the scene establishes
     * @param  list<string>  $onScreen  the characters the scene's beats show
     * @param  array<string, mixed>  $context
     * @param  array<string, array{location: string, status: string}>  $held  the parts the work holds at that moment, whose status the work decides
     * @return list<string> detached parts and lights that do not exist or do not fit the build state, and protection for a character the scene does not show or named twice. What the protection items mean is prose, left to the reviewer
     */
    private static function continuityIssues(string $label, array $continuity, ?string $state, array $onScreen, array $context, array $held): array
    {
        $issues = [];
        $states = (array) $context['states'];
        $at = $state === null ? null : ($states[$state] ?? null);
        $index = $state === null ? null : (int) substr($state, 2);

        foreach (['detached_parts', 'lights_on'] as $key) {
            if ($at === null && $continuity[$key] !== null) {
                $issues[] = "{$label}.{$key} phải null: scene không có xe ở một build state vật lý.";
            }
        }

        if ($at !== null && is_array($continuity['detached_parts'])) {
            $seen = [];

            foreach ($continuity['detached_parts'] as $item) {
                $part = (string) $item['part'];

                if (isset($seen[$part])) {
                    $issues[] = "{$label}.detached_parts nêu {$part} hai lần.";
                }

                $seen[$part] = true;

                if (! isset($context['parts'][$part])) {
                    $issues[] = "{$label}.detached_parts nêu {$part}, không có trong design_states.parts.";

                    continue;
                }

                if (isset($held[$part])) {
                    continue;
                }

                $present = in_array($part, $at['present'], true);
                $listed = static fn (string $key): bool => in_array($part, $at[$key], true);
                $problem = match ($item['status']) {
                    'removed' => match (true) {
                        $present => "{$part} có mặt trên xe ở {$state} — không phải removed.",
                        ! $listed('previously_present') => "{$part} không có trong previously_present của {$state} — không ghi removed; thiếu căn cứ thì nêu conflict.",
                        default => null,
                    },
                    'being_made' => $listed('in_progress') ? null : "{$part} không có trong in_progress của {$state} — không ghi being_made.",
                    'awaiting_install' => match (true) {
                        $present => "{$part} đã lắp ở {$state} — không còn chờ lắp.",
                        ! $listed('previously_in_progress') => "{$part} không có trong previously_in_progress của {$state} — nguồn không xác lập đã làm xong theo quy ước của workflow; không ghi awaiting_install, để null hoặc nêu conflict.",
                        ! $listed('installed_later') => "{$part} không có trong installed_later của {$state} — không ghi awaiting_install.",
                        default => null,
                    },
                    default => null,
                };

                if ($problem !== null) {
                    $issues[] = "{$label}.detached_parts: {$problem}";
                }
            }
        }

        if ($at !== null && is_array($continuity['lights_on'])) {
            foreach ($continuity['lights_on'] as $lightId) {
                $light = $context['lights'][$lightId] ?? null;

                if ($light === null) {
                    $issues[] = "{$label}.lights_on nêu {$lightId}, không có trong design_states.lights.";
                } elseif (! in_array($light['part'], $at['present'], true)) {
                    $issues[] = "{$label}.lights_on: {$lightId} thuộc {$light['part']}, không có mặt ở {$state}.";
                } elseif ((int) substr((string) $light['installed'], 2) > $index) {
                    $issues[] = "{$label}.lights_on: {$lightId} lắp từ {$light['installed']}, chưa có ở {$state}.";
                } elseif ((int) substr((string) $light['operable'], 2) > $index) {
                    $issues[] = "{$label}.lights_on: {$lightId} hoạt động từ {$light['operable']}, chưa bật được ở {$state}.";
                }
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
     * @param  array<string, mixed>  $scene
     * @param  array<string, mixed>  $previous
     * @param  ?string  $state  the build state the scene establishes
     * @param  list<array<string, mixed>>  $before  the beats of the scene before it
     * @param  list<array<string, mixed>>  $mine  the beats of the scene
     * @return list<string> a continuity link across a change of build state or story time or into a time jump, and a known structured state at the start that differs from the known end of the scene before it
     */
    private static function linkIssues(string $id, array $scene, array $previous, ?string $state, array $before, array $mine): array
    {
        $issues = [];
        $prior = array_values(array_unique(array_filter(array_column($before, 'state'), 'is_string')));

        if ($state !== null && count($prior) === 1 && $prior[0] !== $state) {
            $issues[] = "{$id}.continuity_from: {$previous['id']} ở {$prior[0]}, {$id} ở {$state} — đổi build state thì không liền mạch.";
        }

        if ($before !== [] && $before[count($before) - 1]['time_jump'] !== $mine[0]['time_jump']) {
            $issues[] = "{$id}.continuity_from: thời gian truyện đổi giữa {$before[count($before) - 1]['id']} và {$mine[0]['id']}.";
        }

        if (($scene['transition_in']['kind'] ?? null) === 'time_jump') {
            $issues[] = "{$id} vừa nêu continuity_from vừa vào bằng time_jump.";
        }

        foreach (self::LINKED as $key) {
            $end = $previous['continuity_end'][$key];
            $start = $scene['continuity_start'][$key];

            if ($end === null || $start === null) {
                continue;
            }

            if (self::known($end) !== self::known($start)) {
                $issues[] = "{$id} bắt đầu khác cuối {$previous['id']} ở {$key}: ".json_encode(self::known($end), JSON_UNESCAPED_UNICODE).' → '.json_encode(self::known($start), JSON_UNESCAPED_UNICODE).'.';
            }
        }

        return $issues;
    }

    /** @return mixed a known continuity value in a form compared regardless of order: a detached part as part:status, a list sorted */
    private static function known(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $items = array_map(static fn (mixed $item): string => is_array($item) ? $item['part'].':'.$item['status'] : (string) $item, $value);
        sort($items);

        return $items;
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

        foreach ($scenes as $scene) {
            foreach (array_values((array) ($scene['work'] ?? [])) as $index => $item) {
                $label = MotoWork::key((string) $scene['id'], $index);

                foreach ((array) $item['evidence'] as $evidence) {
                    if (! str_starts_with((string) $evidence['path'], "script.beats.{$item['beat']}.")) {
                        $issues[] = "{$label}: bằng chứng {$evidence['path']} không thuộc {$item['beat']} — work dựa vào chính beat của nó.";
                    } elseif (($problem = self::evidenceProblem($evidence, (array) $context['input'])) !== null) {
                        $issues[] = "{$label}: {$problem}";
                    }
                }
            }
        }

        $trace = MotoWork::trace($scenes, array_map(static fn (array $beat): ?string => is_string($beat['state'] ?? null) ? $beat['state'] : null, $beats), (array) $context['states'], $context['work_design']);

        return [[...$issues, ...$trace['issues']], $trace['scenes']];
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
                if (($problem = self::evidenceProblem($evidence, $input)) !== null) {
                    $issues[] = "{$label}: {$problem}";
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array{path: string, quote?: string, value?: mixed}  $evidence
     * @param  array<string, mixed>  $input  the pinned input of the run
     * @return ?string why the evidence is not found in the pinned input: a text is quoted verbatim, an id or enumerated value is given as value, a value matches in value and type
     */
    public static function evidenceProblem(array $evidence, array $input): ?string
    {
        [$found, $value] = self::resolve($evidence['path'], $input);

        if (! $found) {
            return "{$evidence['path']} không có trong đầu vào đã ghim.";
        }

        if (array_key_exists('quote', $evidence)) {
            $segments = array_values(array_filter(explode('.', $evidence['path']), static fn (string $segment): bool => ! ctype_digit($segment)));
            $texts = is_string($value) ? [$value] : (is_array($value) && array_is_list($value) && $value !== [] && array_filter($value, 'is_string') === $value ? $value : null);

            if (in_array(end($segments), self::VALUE_KEYS, true)) {
                return "{$evidence['path']} là ID hoặc giá trị liệt kê — ghi value, không trích quote.";
            }

            if ($texts === null) {
                return "{$evidence['path']} không phải văn bản — ghi value đúng giá trị và kiểu.";
            }

            return array_filter($texts, static fn (string $text): bool => str_contains($text, (string) $evidence['quote'])) === []
                ? "\"{$evidence['quote']}\" không có nguyên văn trong {$evidence['path']}."
                : null;
        }

        $list = is_array($value) && array_is_list($value) ? $value : null;
        $matches = MotoLocationsCheck::same($value, $evidence['value']) || ($list !== null && array_filter($list, static fn (mixed $item): bool => MotoLocationsCheck::same($item, $evidence['value'])) !== []);

        return $matches ? null : "{$evidence['path']} không khớp giá trị ".json_encode($evidence['value'], JSON_UNESCAPED_UNICODE).'.';
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
