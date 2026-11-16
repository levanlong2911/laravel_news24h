<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoShotsCheck
{
    public const VERSION = 'moto-shots-check-v6';

    /** @var string the timing a run pinned from MotoShots@1.2.0 on: code sets the screen time of whole and closing parts and of the shot */
    public const TIMING = 'moto-shots-timing-v1';

    /** @var string the timing a run pinned from MotoShots@2.0.0 on: every shot renders and keeps SHOT_MS, and the shot plan gives the instants of its action steps, camera path and cards */
    public const TIMING_FIXED = 'moto-shots-timing-v2';

    /** @var string the grouping policy a run pins as shot_grouping_policy: a shot that covers more than one beat stands on a background all of them have, crosses no time jump and joins operating states that connect. A run that pins none is checked as before */
    public const GROUPING = 'scene-continuous-v1';

    /** @var string the editing policy a run pins as shot_editing_policy: the plan is a selection within shot_budget.max shots, and every event and work item of the source is either shown by a shot or listed in data.omissions, never both. A run that pins none must show every event and every work item */
    public const EDITING = 'editorial-20-v1';

    /** @var string the pattern of a design reference that is a custom feature or a signature moment, which an edited film must show somewhere */
    private const IDENTITY_REF = '/^[FM]\d+$/';

    /** @var int the least milliseconds a text card stays on screen */
    private const CARD_MIN_MS = 1500;

    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNSCORED = 'UNSCORED';

    public const JUDGE = 'not_run';

    /** @var list<string> the targets that are not the motorcycle or a part on it */
    private const OFF_MOTORCYCLE = ['person', 'place', 'drawing', 'model', 'screen', 'tool'];

    /** @var list<string> the scales at which a camera on the motorcycle may frame all of it */
    private const WHOLE_SCALES = ['extreme_wide', 'wide'];

    /** @var array<string, list<string>> for each reveal of a beat, the reveals a shot of it may declare */
    private const REVEALS = [
        'none' => ['none'],
        'fragment' => ['none', 'fragment'],
        'detail' => ['none', 'fragment', 'detail'],
        'shape' => ['none', 'fragment', 'shape'],
        'whole' => ['none', 'fragment', 'shape', 'detail', 'whole'],
    ];

    /**
     * @param  ?array<string, mixed>  $response
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @param  list<array{code: string, path: string, message: string}>  $schema
     * @return array{version: string, format: string, verdict: string, status: ?string, error: ?string, schema: list<string>, issues: list<string>, warnings: list<string>, judge: string}
     */
    public static function evaluate(?array $response, array $context, array $schema): array
    {
        $format = ($context['timing'] ?? null) === self::TIMING_FIXED ? MotoContract::SHOTS_FORMAT_V2 : MotoContract::SHOTS_FORMAT;

        return ['format' => $format] + self::scored($response, $context, $schema, $format);
    }

    /**
     * @param  array<string, mixed>  $shot  a shot of a fixed-length plan
     * @return array<string, mixed> the shot with the fields the checks and prompts shared with the earlier contract read, derived from what it states: each coverage as a whole beat with no result moment, the camera's first move, each person's view from their framing and face, and the action as its steps in order
     */
    public static function legacy(array $shot): array
    {
        if (! isset($shot['action_timeline'])) {
            return $shot;
        }

        $path = array_values((array) $shot['camera']['path']);

        return array_replace($shot, [
            'coverage' => array_map(static fn (array $item): array => $item + ['part' => 'whole', 'used_duration_ms' => null, 'result_at_ms' => null], array_values((array) $shot['coverage'])),
            'camera' => $shot['camera'] + ['move' => $path[0]['move'] ?? null, 'move_value' => null, 'move_unit' => null],
            'people' => array_map(static fn (array $person): array => $person + ['view' => match (true) {
                $person['framing'] === 'hands' => 'hands',
                $person['face_visibility'] === 'visible' => 'face_visible',
                default => 'face_hidden',
            }], array_values((array) $shot['people'])),
            'action' => implode(' ', array_map(static fn (array $step): string => $step['start_ms'].'–'.$step['end_ms'].' ms: '.rtrim((string) $step['action'], '.').'.', array_values((array) $shot['action_timeline']))),
        ]);
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
        $fixed = ($context['timing'] ?? null) === self::TIMING_FIXED;

        if ($response === null) {
            return self::result(self::UNSCORED, null, 'Phản hồi không đọc được JSON.');
        }

        if (($response['format'] ?? null) !== $format) {
            return self::result(self::UNSCORED, null, 'Phản hồi không theo định dạng '.$format.'.');
        }

        if (($context['timing'] ?? null) !== null && ! in_array($context['timing'], [self::TIMING, self::TIMING_FIXED], true)) {
            return self::result(self::UNSCORED, null, 'Lượt chạy ghim timing '.json_encode($context['timing']).'; code chỉ tính '.self::TIMING.', '.self::TIMING_FIXED.' hoặc lượt không ghim timing.');
        }

        if (($context['shot_grouping_policy'] ?? null) !== null && $context['shot_grouping_policy'] !== self::GROUPING) {
            return self::result(self::UNSCORED, null, 'Lượt chạy ghim shot_grouping_policy '.json_encode($context['shot_grouping_policy']).'; code chỉ tính '.self::GROUPING.' hoặc lượt không ghim chính sách gom.');
        }

        $editing = ($context['shot_editing_policy'] ?? null) !== null;

        if ($editing && $context['shot_editing_policy'] !== self::EDITING) {
            return self::result(self::UNSCORED, null, 'Lượt chạy ghim shot_editing_policy '.json_encode($context['shot_editing_policy']).'; code chỉ tính '.self::EDITING.' hoặc lượt không ghim chính sách biên tập.');
        }

        $evidence = $context['procedure_evidence'] ?? [];

        if (($problem = MotoScriptCheck::evidenceProblem($evidence)) !== null) {
            return self::result(self::UNSCORED, null, "Lượt chạy dùng bản xác nhận thủ tục code không đọc được: {$problem}.");
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
        $written = array_values((array) $data['shots']);
        $shots = array_map(self::legacy(...), $written);
        $conflicts = array_values((array) $data['conflicts']);
        $beats = array_column((array) $context['beats'], null, 'id');
        $scenes = array_column((array) $context['scenes'], null, 'id');
        $order = array_column($shots, 'id');
        $timing = self::timing($shots, $beats, $context['timing'] ?? null);
        $omissions = $editing ? array_values((array) ($data['omissions'] ?? [])) : [];
        $issues = [...$issues, ...self::idIssues($shots, $scenes), ...$timing['issues'], ...($fixed
            ? [...self::eventCoverageIssues($written, $beats, $scenes, $editing ? array_merge([], ...array_column($omissions, 'event_ids')) : null), ...self::timelineIssues($written, $beats, $context)]
            : self::coverageIssues($shots, $beats, $scenes, (int) $context['total_ms'], $timing['shots']))];

        if ($editing) {
            $max = $context['shot_budget']['max'] ?? null;

            if (! is_int($max)) {
                return self::result(self::UNSCORED, null, 'Lượt chạy ghim chính sách biên tập nhưng không ghim shot_budget.max — không kiểm được ngân sách shot.');
            }

            if (count($shots) > $max) {
                $issues[] = 'Kế hoạch có '.count($shots)." shot, vượt ngân sách {$max} shot của phim.";
            }

            $issues = [...$issues, ...self::omissionIssues($omissions, $written, $beats, $scenes, $context)];
        }

        $warnings = [];
        $seen = [];
        $placed = is_array($context['work'] ?? null) ? MotoWork::shotPlaces(self::workShots($shots, $omissions), $context['work']) : null;
        $issues = [...$issues, ...($placed['issues'] ?? [])];

        foreach ($shots as $index => $shot) {
            $scene = $scenes[$shot['scene']] ?? null;
            $state = is_array($scene) ? $scene['state'] : null;
            $covered = array_values(array_filter(array_map(static fn (array $item): ?array => $beats[$item['beat']] ?? null, (array) $shot['coverage'])));
            $issues = [...$issues, ...self::shotIssues($shot, array_slice($order, 0, $index), $state, $covered, $context, $placed === null ? null : ($placed['shots'][$shot['id']] ?? []))];
            $warnings = [...$warnings, ...self::shotWarnings($shot, $state, $seen), ...self::surfaceWarnings($shot, $state, $context)];
            $seen[] = ['id' => $shot['id'], 'scene' => $shot['scene'], 'fingerprint' => MotoShotPlanner::fingerprint($shot, $state)];
        }

        foreach ($fixed ? $written : [] as $shot) {
            $images = count((array) $shot['reference_needs']) + count((array) $shot['people']);

            if ($images > (int) $context['reference_limit']) {
                $warnings[] = "{$shot['id']}: ".count((array) $shot['reference_needs']).' nhu cầu tham chiếu cùng '.count((array) $shot['people'])." người có thể cần hơn {$context['reference_limit']} ảnh của một clip — kiểm ở màn Reference trước khi render.";
            }

            if (count((array) $shot['people']) > 2) {
                $warnings[] = "{$shot['id']}: ".count((array) $shot['people']).' người trong một shot — model video chỉ giữ nhận diện tốt cho hai người.';
            }
        }

        if (is_array($context['work'] ?? null)) {
            $shown = array_merge([], ...array_map(static fn (array $shot): array => (array) ($shot['work'] ?? []), $shots));
            $left = array_merge([], ...array_column($omissions, 'work_ids'));

            foreach ((array) $context['work']['items'] as $key => $item) {
                $label = "{$key} ({$item['beat']} {$item['part']} {$item['operation']})";

                if (in_array($key, $shown, true) && in_array($key, $left, true)) {
                    $issues[] = "{$label} vừa có shot thể hiện vừa nằm trong omissions — mỗi công việc chỉ ở một nơi.";
                } elseif (! in_array($key, $shown, true) && ! in_array($key, $left, true)) {
                    $issues[] = $editing ? "{$label} không có shot nào thể hiện và cũng không nằm trong omissions." : "{$label} không có shot nào thể hiện.";
                }
            }
        }

        if (($context['cover_design_refs'] ?? false) === true) {
            $issues = [...$issues, ...self::designRefIssues($shots, $beats, $scenes, $context, (array) ($placed['shots'] ?? []), $editing)];
        }

        $issues = [...$issues, ...self::conflictIssues($conflicts, $order, $beats, (array) $context['input'])];
        $blocking = array_filter($conflicts, static fn (array $conflict): bool => (bool) $conflict['blocking']) !== [];

        if ($status === 'ok' && $blocking) {
            $issues[] = 'Còn xung đột chặn nhưng status là ok.';
        }

        if ($status === 'partial' && ! $blocking) {
            $issues[] = 'Status partial nhưng không còn xung đột chặn nào.';
        }

        if ($evidence !== []) {
            $warnings[] = 'Thủ tục của giới hạn vận hành được chấm theo bản xác nhận '.($context['procedure_evidence_hash'] ?? 'không ghi mã băm').': '.implode('; ', array_map(
                static fn (array $record): string => "{$record['limit']} {$record['procedure']} = {$record['kind']}".($record['kind'] === MotoScriptCheck::BY_ENTERING ? " ({$record['part']} {$record['from']} → {$record['to']} ở {$record['beat']} {$record['event']})" : ''),
                $evidence,
            )).'.';
        }

        return self::result($issues === [] ? self::PASS : self::FAIL, $status, null, [], $issues, $warnings)
            + ($evidence === [] ? [] : ['procedure_evidence' => ['hash' => $context['procedure_evidence_hash'] ?? null, 'records' => $evidence]]);
    }

    /**
     * @param  list<array<string, mixed>>  $shots
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, array<string, mixed>>  $scenes
     * @param  array<string, mixed>  $context
     * @param  array<string, array<string, array{location: string}>>  $placed  where the work holds each part at the first frame of each shot, MotoWork::shotPlaces()
     * @return list<string> by beat over the shots that cover it: a part its design_refs names that is neither in frame nor recorded out of frame, a feature, system or moment it names with none of its parts in frame; and an out-of-frame part the design does not have, that is also in frame, that no covered beat names or that is not on the motorcycle at the shot's first frame: where the work holds it, otherwise as its build state has it
     */
    private static function designRefIssues(array $shots, array $beats, array $scenes, array $context, array $placed, bool $editing = false): array
    {
        $issues = [];
        $seen = [];
        $inFrame = [];
        $film = [];

        foreach ($shots as $shot) {
            $id = (string) $shot['id'];
            $parts = array_column((array) $shot['parts'], 'part');
            $away = array_column((array) ($shot['off_frame'] ?? []), 'part');
            $covered = array_values(array_unique(array_column((array) $shot['coverage'], 'beat')));
            $present = (array) ($context['states'][(string) ($scenes[$shot['scene']]['state'] ?? '')]['present'] ?? []);

            $film = [...$film, ...$parts];

            foreach ($covered as $beat) {
                $seen[$beat] = [...($seen[$beat] ?? []), ...$parts, ...$away];
                $inFrame[$beat] = [...($inFrame[$beat] ?? []), ...$parts];
            }

            foreach ($away as $part) {
                $named = array_merge([], ...array_map(static fn (string $beat): array => (array) ($beats[$beat]['design_refs'] ?? []), $covered));
                $held = $placed[$id][$part]['location'] ?? null;
                $problem = match (true) {
                    ! isset($context['parts'][$part]) => 'không có trong parts của thiết kế',
                    in_array($part, $parts, true) => 'vừa ở trong khung vừa ở ngoài khung',
                    ! in_array($part, $named, true) => 'không có trong design_refs của beat nào shot phủ',
                    ! ($held === null ? in_array($part, $present, true) : $held === MotoWork::ON) => 'không ở trên xe lúc mở shot',
                    default => null,
                };

                if ($problem !== null) {
                    $issues[] = "{$id}: off_frame {$part} {$problem}.";
                }
            }
        }

        if ($editing) {
            $named = array_values(array_unique(array_merge([], ...array_map(static fn (array $beat): array => (array) ($beat['design_refs'] ?? []), array_values($beats)))));

            foreach ($named as $ref) {
                $group = $context['ref_parts'][$ref] ?? null;

                if (is_array($group) && preg_match(self::IDENTITY_REF, (string) $ref) === 1 && array_intersect($group, $film) === []) {
                    $issues[] = "Kịch bản nêu {$ref} nhưng không shot nào của phim có part của {$ref} trong khung — tính năng và khoảnh khắc đặc trưng phải lên hình ít nhất một lần.";
                }
            }

            return $issues;
        }

        foreach ($beats as $id => $beat) {
            foreach ((array) ($beat['design_refs'] ?? []) as $ref) {
                $group = $context['ref_parts'][$ref] ?? null;

                if (is_array($group) && array_intersect($group, $inFrame[$id] ?? []) === []) {
                    $issues[] = "{$id} nêu {$ref} nhưng không shot nào phủ beat này có part của {$ref} trong khung.";
                } elseif (isset($context['parts'][$ref]) && ! in_array($ref, $seen[$id] ?? [], true)) {
                    $issues[] = "{$id} nêu {$ref} nhưng không shot nào phủ beat này ghi {$ref} trong parts hoặc off_frame.";
                }
            }
        }

        return $issues;
    }

    /**
     * @param  list<array<string, mixed>>  $shots
     * @param  array<string, array<string, mixed>>  $scenes
     * @return list<string> a shot of an unknown scene or out of scene order, a scene without a shot and shot ids that do not run SH01, SH02, ... within their scene
     */
    private static function idIssues(array $shots, array $scenes): array
    {
        $issues = [];
        $position = array_flip(array_keys($scenes));
        $last = -1;
        $numbers = [];

        foreach ($shots as $shot) {
            $scene = (string) $shot['scene'];

            if (! isset($position[$scene])) {
                $issues[] = "{$shot['id']} thuộc {$scene}, không có trong bản chia scene.";

                continue;
            }

            if ($position[$scene] < $last) {
                $issues[] = "{$shot['id']} đứng sau shot của scene muộn hơn — shot phải theo thứ tự scene.";
            }

            $last = max($last, $position[$scene]);
            $numbers[$scene] = ($numbers[$scene] ?? 0) + 1;
            $expected = sprintf('%s_SH%02d', $scene, $numbers[$scene]);

            if ($shot['id'] !== $expected) {
                $issues[] = "Shot thứ {$numbers[$scene]} của {$scene} là {$shot['id']}; cần {$expected}.";
            }
        }

        foreach (array_keys($scenes) as $scene) {
            if (! isset($numbers[$scene])) {
                $issues[] = "{$scene} không có shot nào.";
            }
        }

        return $issues;
    }

    /**
     * @param  list<array<string, mixed>>  $shots
     * @param  array<string, array<string, mixed>>  $beats  the beats with their start_ms and end_ms
     * @param  ?string  $timing  the timing the run pinned, TIMING; null for a run pinned before code set any screen time
     * @return array{shots: array<string, array{used_duration_ms: int, coverage: list<int>}>, issues: list<string>} the screen time of every coverage part and every shot: under the pinned timing a whole part takes its beat's duration, an opening or middle part the time the shot gives it, a closing part what those leave of its beat and a shot the sum of its parts; before it, the time the shot gives each; with a closing part left no time
     *
     * @throws \InvalidArgumentException for a timing code does not know
     */
    public static function timing(array $shots, array $beats, ?string $timing): array
    {
        if ($timing === self::TIMING_FIXED) {
            return ['issues' => [], 'shots' => array_column(array_map(static fn (array $shot): array => ['id' => (string) $shot['id'], 'used_duration_ms' => MotoContract::SHOT_MS, 'coverage' => array_fill(0, count((array) $shot['coverage']), 0)], $shots), null, 'id')];
        }

        if ($timing !== null && $timing !== self::TIMING) {
            throw new \InvalidArgumentException("Timing {$timing} không được hỗ trợ.");
        }

        $resolved = [];
        $issues = [];
        $given = [];

        foreach ($shots as $shot) {
            $lengths = [];

            foreach ((array) $shot['coverage'] as $item) {
                $beat = (string) $item['beat'];
                $duration = isset($beats[$beat]) ? (int) $beats[$beat]['end_ms'] - (int) $beats[$beat]['start_ms'] : 0;
                $length = match (true) {
                    $timing === null, in_array($item['part'], ['opening', 'middle'], true) => (int) $item['used_duration_ms'],
                    $item['part'] === 'closing' => $duration - ($given[$beat] ?? 0),
                    default => $duration,
                };

                if ($timing !== null && in_array($item['part'], ['opening', 'middle'], true)) {
                    $given[$beat] = ($given[$beat] ?? 0) + $length;
                }

                if ($timing !== null && $item['part'] === 'closing' && isset($beats[$beat]) && $length <= 0) {
                    $issues[] = "{$shot['id']}: phần mở/giữa của {$beat} đã chiếm ".($given[$beat] ?? 0)." ms trong {$duration} ms của beat — không còn thời gian cho phần đóng.";
                    $length = 0;
                }

                $lengths[] = $length;
            }

            $resolved[(string) $shot['id']] = ['used_duration_ms' => $timing === null ? (int) $shot['used_duration_ms'] : array_sum($lengths), 'coverage' => $lengths];
        }

        return ['shots' => $resolved, 'issues' => $issues];
    }

    /**
     * @param  list<array<string, mixed>>  $shots
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, array<string, mixed>>  $scenes
     * @param  array<string, array{used_duration_ms: int, coverage: list<int>}>  $resolved  timing()
     * @return list<string> coverage that leaves out, repeats, reorders or splits a beat wrongly or crosses its scene, screen time that does not add up per shot, per beat or for the film, a kept interval outside its clip and a result outside its coverage part
     */
    private static function coverageIssues(array $shots, array $beats, array $scenes, int $total, array $resolved): array
    {
        $issues = [];
        $groups = [];
        $timeOf = [];
        $sum = 0;

        foreach ($shots as $shot) {
            $at = (int) $shot['used_start_ms'];
            $used = 0;
            $kept = $resolved[(string) $shot['id']];

            foreach (array_values((array) $shot['coverage']) as $index => $item) {
                $beat = (string) $item['beat'];
                $length = $kept['coverage'][$index];

                if (! isset($beats[$beat])) {
                    $issues[] = "{$shot['id']} phủ {$beat}, không có trong kịch bản.";
                } elseif ($beats[$beat]['scene'] !== $shot['scene']) {
                    $issues[] = "{$shot['id']} phủ {$beat} của {$beats[$beat]['scene']} — shot không vượt ranh giới scene.";
                }

                if ($item['result_at_ms'] !== null && ($item['result_at_ms'] < $at || $item['result_at_ms'] > $at + $length)) {
                    $issues[] = "{$shot['id']}: kết quả của {$beat} ở {$item['result_at_ms']} ms, ngoài phần {$at}–".($at + $length).' ms của nó trong clip.';
                }

                $group = $groups[$shot['scene']] ?? [];
                $last = $group === [] ? null : $group[count($group) - 1];

                if ($last !== null && $last['beat'] === $beat) {
                    $groups[$shot['scene']][count($groups[$shot['scene']]) - 1]['parts'][] = $item['part'];
                } else {
                    $groups[$shot['scene']][] = ['beat' => $beat, 'parts' => [$item['part']]];
                }

                $timeOf[$beat] = ($timeOf[$beat] ?? 0) + $length;
                $at += $length;
                $used += $length;
            }

            if ($used !== $kept['used_duration_ms']) {
                $issues[] = "{$shot['id']}: coverage cộng lại {$used} ms; shot giữ {$kept['used_duration_ms']} ms.";
            }

            if ((int) $shot['used_start_ms'] + $kept['used_duration_ms'] > (int) $shot['render_duration_ms']) {
                $issues[] = "{$shot['id']}: đoạn giữ {$shot['used_start_ms']}–".((int) $shot['used_start_ms'] + $kept['used_duration_ms'])." ms vượt clip {$shot['render_duration_ms']} ms.";
            }

            $sum += $kept['used_duration_ms'];
        }

        foreach ($scenes as $id => $scene) {
            $covered = array_column($groups[$id] ?? [], 'beat');

            if ($covered !== array_values((array) $scene['beats'])) {
                $issues[] = "{$id} phủ beat theo thứ tự ".(implode(', ', $covered) ?: '—').'; cần '.implode(', ', (array) $scene['beats']).', mỗi beat đúng một lần.';
            }

            foreach ($groups[$id] ?? [] as $group) {
                $parts = $group['parts'];
                $valid = $parts === ['whole']
                    || (count($parts) >= 2 && $parts[0] === 'opening' && $parts[count($parts) - 1] === 'closing' && array_diff(array_slice($parts, 1, -1), ['middle']) === []);

                if (! $valid) {
                    $issues[] = "{$group['beat']} được phủ theo ".implode(' → ', $parts).'; cần whole, hoặc opening → middle… → closing.';
                }
            }
        }

        foreach ($beats as $id => $beat) {
            $length = (int) $beat['end_ms'] - (int) $beat['start_ms'];

            if (isset($timeOf[$id]) && $timeOf[$id] !== $length) {
                $issues[] = "{$id} chiếm {$timeOf[$id]} ms trên màn hình; beat đã duyệt dài {$length} ms.";
            }
        }

        if ($issues === [] && $sum !== $total) {
            $issues[] = "Các shot giữ tổng {$sum} ms; phim dài {$total} ms.";
        }

        return $issues;
    }

    /**
     * @param  list<array<string, mixed>>  $shots  the shots as written
     * @param  array<string, array<string, mixed>>  $beats  the beats with their events
     * @param  array<string, array<string, mixed>>  $scenes
     * @return list<string> coverage of a beat the script does not have or of another scene, an event that is not of its beat, a scene whose beats are not each covered once in order, an event no shot plays and events played out of the script's order
     */
    private static function eventCoverageIssues(array $shots, array $beats, array $scenes, ?array $omitted = null): array
    {
        $issues = [];
        $groups = [];
        $played = [];
        $reached = [];

        foreach ($shots as $shot) {
            foreach ((array) $shot['coverage'] as $item) {
                $beat = (string) $item['beat'];

                if (! isset($beats[$beat])) {
                    $issues[] = "{$shot['id']} phủ {$beat}, không có trong kịch bản.";

                    continue;
                }

                if ($beats[$beat]['scene'] !== $shot['scene']) {
                    $issues[] = "{$shot['id']} phủ {$beat} của {$beats[$beat]['scene']} — shot không vượt ranh giới scene.";
                }

                $own = array_column((array) ($beats[$beat]['events'] ?? []), 'id');

                foreach ((array) $item['event_ids'] as $event) {
                    $index = array_search($event, $own, true);

                    if ($index === false) {
                        $issues[] = "{$shot['id']}: {$event} không phải sự kiện của {$beat}.";

                        continue;
                    }

                    if ($index < ($reached[$beat] ?? 0)) {
                        $issues[] = "{$shot['id']}: {$event} của {$beat} được diễn sau một sự kiện muộn hơn — giữ thứ tự sự kiện của kịch bản.";
                    }

                    $reached[$beat] = max($reached[$beat] ?? 0, $index);
                    $played[$event] = true;
                }

                $group = $groups[$shot['scene']] ?? [];

                if ($group === [] || $group[count($group) - 1] !== $beat) {
                    $groups[$shot['scene']][] = $beat;
                }
            }
        }

        foreach ($scenes as $id => $scene) {
            if (($groups[$id] ?? []) !== array_values((array) $scene['beats'])) {
                $issues[] = "{$id} phủ beat theo thứ tự ".(implode(', ', $groups[$id] ?? []) ?: '—').'; cần '.implode(', ', (array) $scene['beats']).', mỗi beat một lượt liền nhau.';
            }
        }

        foreach ($beats as $id => $beat) {
            foreach (array_column((array) ($beat['events'] ?? []), 'id') as $event) {
                $left = $omitted !== null && in_array($event, $omitted, true);

                if (isset($played[$event]) && $left) {
                    $issues[] = "{$event} của {$id} vừa có shot diễn vừa nằm trong omissions — mỗi sự kiện chỉ ở một nơi.";
                } elseif (! isset($played[$event]) && ! $left) {
                    $issues[] = $omitted === null ? "{$event} của {$id} chưa có shot nào diễn." : "{$event} của {$id} không có shot nào diễn và cũng không nằm trong omissions — không sự kiện nào của nguồn được mất dấu.";
                }
            }
        }

        return $issues;
    }

    /**
     * @param  list<array{id?: string, start_ms: int, end_ms: int}>  $spans  spans in the order written
     * @return ?string why the spans do not run from 0 ms to the end of the shot without a gap or an overlap, null when they do
     */
    private static function spanProblem(array $spans): ?string
    {
        $cursor = 0;

        foreach ($spans as $span) {
            if ((int) $span['start_ms'] !== $cursor) {
                return 'bắt đầu ở '.$span['start_ms']." ms, cần {$cursor} ms";
            }

            if ((int) $span['end_ms'] <= (int) $span['start_ms']) {
                return 'kết thúc ở '.$span['end_ms'].' ms, không sau lúc bắt đầu';
            }

            $cursor = (int) $span['end_ms'];
        }

        return $cursor === MotoContract::SHOT_MS ? null : "kết thúc ở {$cursor} ms, cần ".MotoContract::SHOT_MS.' ms';
    }

    /**
     * @param  list<array<string, mixed>>  $shots  the shots as written
     * @param  array<string, array<string, mixed>>  $beats  the beats with their events
     * @param  array<string, mixed>  $context
     * @return list<string> by shot: action steps or camera segments whose ids do not run in sequence or whose spans do not cover 0–3000 ms once, a step that plays an event the shot does not cover or names an actor not among its people, a covered event no step plays, an operating state that names what the design does not have, puts a part in two positions, leaves out a position the shot shows or differs from the event the shot opens or completes, and a card that is unknown, of a beat or event the shot does not cover, too short, outside the shot or over another; then every script card not placed exactly once and every directive anchor no step keeps
     */
    private static function timelineIssues(array $shots, array $beats, array $context): array
    {
        $issues = [];
        $events = [];
        $first = [];
        $last = [];
        $placed = [];
        $steps = [];
        $sceneStates = array_column((array) ($context['scenes'] ?? []), 'state', 'id');

        foreach ($beats as $beat) {
            foreach ((array) ($beat['events'] ?? []) as $event) {
                $events[$event['id']] = $event;
            }
        }

        foreach ($shots as $shot) {
            foreach (array_merge([], ...array_column((array) $shot['coverage'], 'event_ids')) as $event) {
                $first[$event] ??= $shot['id'];
                $last[$event] = $shot['id'];
            }
        }

        foreach ($shots as $shot) {
            $id = (string) $shot['id'];
            $covered = array_merge([], ...array_column((array) $shot['coverage'], 'event_ids'));
            $coveredBeats = array_column((array) $shot['coverage'], 'beat');
            $people = array_column((array) $shot['people'], 'character');
            $timeline = array_values((array) $shot['action_timeline']);
            $path = array_values((array) $shot['camera']['path']);
            $stepped = [];
            $reached = 0;

            foreach ($timeline as $index => $step) {
                if ($step['id'] !== sprintf('STEP%02d', $index + 1)) {
                    $issues[] = "{$id}: nhịp thứ ".($index + 1).' phải là '.sprintf('STEP%02d', $index + 1).", không phải {$step['id']}.";
                }

                foreach ((array) $step['event_ids'] as $event) {
                    $stepped[$event] = true;
                    $steps[$event][] = ['start_ms' => (int) $step['start_ms'], 'end_ms' => (int) $step['end_ms']];
                    $place = array_search($event, $covered, true);

                    if ($place === false) {
                        $issues[] = "{$id} {$step['id']}: {$event} không nằm trong coverage của shot.";

                        continue;
                    }

                    if ($place < $reached) {
                        $issues[] = "{$id} {$step['id']}: diễn {$event} sau khi {$covered[$reached]} đã bắt đầu — các nhịp phải theo thứ tự sự kiện của coverage.";
                    }

                    $reached = max($reached, $place);
                }

                foreach (array_diff((array) $step['actor_ids'], $people) as $actor) {
                    $issues[] = "{$id} {$step['id']}: {$actor} không có trong people của shot.";
                }
            }

            foreach (array_diff($covered, array_keys($stepped)) as $event) {
                $issues[] = "{$id}: {$event} có trong coverage nhưng không nhịp nào của action_timeline diễn.";
            }

            foreach ($path as $index => $segment) {
                if ($segment['id'] !== sprintf('SEG%02d', $index + 1)) {
                    $issues[] = "{$id}: đoạn máy quay thứ ".($index + 1).' phải là '.sprintf('SEG%02d', $index + 1).", không phải {$segment['id']}.";
                }
            }

            foreach (['action_timeline' => $timeline, 'camera.path' => $path] as $label => $spans) {
                if (($problem = self::spanProblem($spans)) !== null) {
                    $issues[] = "{$id}: {$label} {$problem} — các khoảng phải nối liền từ 0 đến ".MotoContract::SHOT_MS.' ms.';
                }
            }

            foreach (['start' => 'operating_start', 'end' => 'operating_end'] as $moment => $key) {
                $state = (array) $shot[$key];
                $byPart = [];

                foreach ((array) $state['configurations'] as $configuration) {
                    $part = $context['configurations'][$configuration]['part'] ?? null;

                    if ($part === null) {
                        $issues[] = "{$id}.{$key}: cấu hình {$configuration} không có trong thiết kế.";
                    } elseif (isset($byPart[$part])) {
                        $issues[] = "{$id}.{$key}: {$part} có hai vị trí cùng lúc ({$byPart[$part]}, {$configuration}).";
                    } else {
                        $byPart[$part] = $configuration;
                    }
                }

                if (is_array($state['engine']) && ! isset($context['parts'][$state['engine']['part_id']])) {
                    $issues[] = "{$id}.{$key}.engine: {$state['engine']['part_id']} không có trong thiết kế.";
                }

                foreach ((array) $state['support_refs'] as $support) {
                    $problem = match (true) {
                        preg_match('/^P\d{2,3}$/', (string) $support) === 1 => isset($context['parts'][$support]) ? null : 'không có trong parts của thiết kế',
                        preg_match('/^CH\d{2}$/', (string) $support) === 1 => in_array($support, $people, true) ? null : 'không có trong people của shot',
                        default => in_array($support, (array) ($context['temporary'][(string) ($sceneStates[$shot['scene']] ?? '')] ?? []), true) ? null : 'không có trong temporary của build state của scene',
                    };

                    if ($problem !== null) {
                        $issues[] = "{$id}.{$key}.support_refs: {$support} {$problem}.";
                    }
                }

                foreach (array_diff((array) $shot['configurations'][$moment], (array) $state['configurations']) as $configuration) {
                    $issues[] = "{$id}: configurations.{$moment} nêu {$configuration} nhưng {$key} không có vị trí này.";
                }
            }

            $opening = $covered[0] ?? null;
            $closing = $covered === [] ? null : $covered[count($covered) - 1];

            if ($opening !== null && isset($events[$opening]) && $first[$opening] === $id && MotoScriptCheck::canonical((array) $shot['operating_start']) !== MotoScriptCheck::canonical((array) $events[$opening]['operating_start'])) {
                $issues[] = "{$id}.operating_start khác operating_start của {$opening}, sự kiện shot mở đầu.";
            }

            if ($closing !== null && isset($events[$closing]) && $last[$closing] === $id && MotoScriptCheck::canonical((array) $shot['operating_end']) !== MotoScriptCheck::canonical((array) $events[$closing]['operating_end'])) {
                $issues[] = "{$id}.operating_end khác operating_end của {$closing}, sự kiện shot hoàn tất.";
            }

            $shown = array_values((array) $shot['cards']);
            usort($shown, static fn (array $a, array $b): int => $a['start_ms'] <=> $b['start_ms']);
            $until = 0;

            foreach ($shown as $card) {
                $record = $context['cards'][$card['card']] ?? null;
                $placed[$card['card']][] = $id;

                if ($record === null) {
                    $issues[] = "{$id}: {$card['card']} không có trong text_cards của kịch bản.";

                    continue;
                }

                if (! in_array($record['beat'], $coveredBeats, true)) {
                    $issues[] = "{$id}: {$card['card']} thuộc {$record['beat']}, shot không phủ beat này.";
                } elseif ($record['event'] !== null && ! in_array($record['event'], $covered, true)) {
                    $issues[] = "{$id}: {$card['card']} gắn {$record['event']}, shot không diễn sự kiện này.";
                }

                $fps = is_int($context['card_fps'] ?? null) ? $context['card_fps'] : null;

                if ($fps === null) {
                    $issues[] = "{$id}: lượt chạy không ghim số khung hình mỗi giây của phim — không kiểm được mốc của {$card['card']}.";
                } elseif ((((int) $card['start_ms'] * $fps) % 1000 !== 0 || ((int) $card['end_ms'] * $fps) % 1000 !== 0)) {
                    $issues[] = "{$id}: {$card['card']} {$card['start_ms']}–{$card['end_ms']} ms không rơi đúng khung hình ở {$fps} fps của phim — dùng mốc là bội số của ".MotoShots::frameStepMs($fps).' ms.';
                }

                if ((int) $card['end_ms'] - (int) $card['start_ms'] < self::CARD_MIN_MS) {
                    $issues[] = "{$id}: {$card['card']} hiện ".((int) $card['end_ms'] - (int) $card['start_ms']).' ms; cần ít nhất '.self::CARD_MIN_MS.' ms.';
                }

                if ((int) $card['start_ms'] < $until) {
                    $issues[] = "{$id}: {$card['card']} chồng lên card trước trong shot.";
                }

                $until = max($until, (int) $card['end_ms']);
            }
        }

        foreach (array_keys((array) ($context['cards'] ?? [])) as $card) {
            $count = count($placed[$card] ?? []);

            if ($count !== 1) {
                $issues[] = $count === 0 ? "{$card} của kịch bản chưa được đặt vào shot nào." : "{$card} được đặt vào {$count} shot (".implode(', ', $placed[$card]).'); mỗi card một shot.';
            }
        }

        foreach ((array) ($context['directives'] ?? []) as $directive) {
            foreach ((array) $directive['event_anchors'] as $anchor) {
                $span = ['start_ms' => (int) $anchor['start_ms'], 'end_ms' => (int) $anchor['end_ms']];

                if (! in_array($span, $steps[$anchor['event_id']] ?? [], true)) {
                    $issues[] = "{$directive['id']}: {$anchor['event_id']} phải diễn đúng {$span['start_ms']}–{$span['end_ms']} ms như người dùng đã chốt; không nhịp nào giữ mốc này.";
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  list<string>  $earlier  the ids of the shots before it
     * @param  list<array<string, mixed>>  $covered  the beats the shot covers
     * @param  array<string, mixed>  $context
     * @param  ?array<string, array{location: string, status: string, item: string, moment: string}>  $held  where the work holds each part at the shot's first frame, MotoWork::shotPlaces(); null for a run pinned before work existed
     * @return list<string> a render length or mode code cannot render, a camera that does not fit its target, a work item of the scene plan that does not exist, lies outside the covered beats or works on a part out of frame, parts shown in a way the work or the build state does not give them, a person the beats do not show, an environment the beats do not have, references beyond the keyframe's limit or pointing nowhere, and links to a later shot or an unknown motif
     */
    private static function shotIssues(array $shot, array $earlier, ?string $state, array $covered, array $context, ?array $held): array
    {
        $id = (string) $shot['id'];
        $issues = [];
        $capability = collect((array) $context['capabilities'])->firstWhere('duration_ms', (int) $shot['render_duration_ms']);

        if ($capability === null) {
            $issues[] = "{$id}: không có công cụ clip nào render {$shot['render_duration_ms']} ms cho khung ".($context['aspect_ratio'] ?? '—').'.';
        } elseif (! in_array($shot['mode'], (array) $capability['modes'], true)) {
            $issues[] = "{$id}: không có công cụ nào render {$shot['mode']} ở {$shot['render_duration_ms']} ms.";
        }

        $facts = $state === null ? ['physical' => false] : ((array) ($context['states'][$state] ?? ['physical' => false]));
        $camera = (array) $shot['camera'];
        $parts = array_column((array) $shot['parts'], 'part');

        if ($camera['target'] === 'motorcycle' && ! $facts['physical']) {
            $issues[] = "{$id}: scene ở ".($state ?? 'state null').' không có xe thật — target phải là bản vẽ hoặc mô hình.';
        }

        if (in_array($camera['target'], self::OFF_MOTORCYCLE, true) && $camera['azimuth_deg'] !== null) {
            $issues[] = "{$id}: target {$camera['target']} không ở trên xe — azimuth phải null.";
        }

        if ($camera['target'] === 'motorcycle' && $camera['azimuth_deg'] === null) {
            $issues[] = "{$id}: target là xe — cần azimuth quanh xe.";
        }

        if ($camera['target'] === 'part' && ! in_array($camera['target_part'], $parts, true)) {
            $issues[] = "{$id}: target_part {$camera['target_part']} không có trong parts của shot.";
        }

        foreach ((array) ($shot['work'] ?? []) as $key) {
            $record = $context['work']['items'][$key] ?? null;

            if ($record === null) {
                $issues[] = "{$id}: work {$key} không có trong bản chia scene.";
            } elseif (! in_array($record['beat'], array_column($covered, 'id'), true)) {
                $issues[] = "{$id}: work {$key} thuộc {$record['beat']}, shot không phủ beat này.";
            } elseif (! in_array($record['part'], $parts, true)) {
                $issues[] = "{$id}: work {$key} làm trên {$record['part']} — cần {$record['part']} trong parts của shot.";
            }
        }

        foreach ((array) $shot['parts'] as $item) {
            $part = (string) $item['part'];

            if (! isset($context['parts'][$part])) {
                $issues[] = "{$id} nêu {$part}, không có trong parts của thiết kế.";

                continue;
            }

            if (! $facts['physical']) {
                $handedOver = $item['presentation'] === 'awaiting_install'
                    && ($context['state_records'][(string) $state]['kind'] ?? null) === 'handover'
                    && ($context['part_records'][$part]['origin'] ?? null) === 'base'
                    && in_array($part, array_merge([], ...array_map(static fn (array $beat): array => (array) ($beat['design_refs'] ?? []), $covered)), true);

                if (! $handedOver && ! in_array($item['presentation'], ['drawing', 'model'], true)) {
                    $issues[] = "{$id}: ".($state ?? 'state null')." chưa có xe thật — {$part} chỉ thể hiện bằng bản vẽ hoặc mô hình".(($context['state_records'][(string) $state]['kind'] ?? null) === 'handover' ? '; riêng linh kiện nền (origin base) mà beat của shot nêu được ghi awaiting_install.' : '.');
                }

                continue;
            }

            if (isset($held[$part])) {
                if ($item['presentation'] !== $held[$part]['status']) {
                    $issues[] = "{$id}: {$part} lúc mở shot là {$held[$part]['status']} (".($held[$part]['moment'] === 'start' ? 'đầu' : 'cuối')." {$held[$part]['item']}) — không ghi {$item['presentation']}.";
                }

                continue;
            }

            $listed = static fn (string $key): bool => in_array($part, (array) $facts[$key], true);
            $problem = match ($item['presentation']) {
                'installed' => $listed('present') ? null : "{$part} không có trên xe ở {$state} — không ghi installed.",
                'being_made' => $listed('in_progress') ? null : "{$part} không có trong in_progress của {$state} — không ghi being_made.",
                'awaiting_install' => $listed('previously_in_progress') && $listed('installed_later') ? null : "{$part} không có trong cả previously_in_progress và installed_later của {$state} — không ghi awaiting_install.",
                'removed' => $listed('previously_present') ? null : "{$part} không có trong previously_present của {$state} — không ghi removed.",
                default => null,
            };

            if ($problem !== null) {
                $issues[] = "{$id}: {$problem}";
            }
        }

        $sets = array_map(static fn (array $beat): array => self::REVEALS[$beat['reveal']] ?? [], $covered);
        $allowed = $sets === [] ? [] : array_values(array_intersect(...$sets));

        if (! in_array($shot['reveal'], $allowed, true)) {
            $issues[] = "{$id} khai reveal {$shot['reveal']}; ".implode(', ', array_map(static fn (array $beat): string => "{$beat['id']} ({$beat['reveal']})", $covered)).' chỉ cho '.(implode(', ', $allowed) ?: 'không gì').'.';
        }

        $issues = [...$issues, ...self::configurationIssues($shot, $parts, $covered, $facts, $context), ...self::groupingIssues($shot, $covered, $context)];
        $onScreen = array_values(array_unique(array_merge([], ...array_column($covered, 'on_screen'))));

        foreach ((array) $shot['people'] as $person) {
            if (! in_array($person['character'], $onScreen, true)) {
                $issues[] = "{$id}: {$person['character']} không có trên màn hình ở beat nào shot phủ.";
            }
        }

        $environment = (array) $shot['environment'];
        $backgrounds = array_values(array_unique(array_merge([], ...array_column($covered, 'backgrounds'))));

        if ($environment['mode'] === 'background' && ! in_array($environment['background'], $backgrounds, true)) {
            $issues[] = "{$id}: {$environment['background']} không phải nền của beat nào shot phủ (".(implode(', ', $backgrounds) ?: 'không có nền').').';
        }

        if ($environment['mode'] === 'unplated' && array_filter($covered, static fn (array $beat): bool => $beat['unplated'] !== []) === []) {
            $issues[] = "{$id}: không beat nào shot phủ là unplated — chọn nền của beat hoặc yêu cầu nền bổ sung.";
        }

        if (count((array) $shot['reference_needs']) > (int) $context['reference_limit']) {
            $issues[] = "{$id}: ".count((array) $shot['reference_needs'])." nhu cầu tham chiếu, vượt giới hạn {$context['reference_limit']} ảnh của keyframe.";
        }

        $scenes = array_column((array) $context['scenes'], null, 'id');
        $order = array_keys($scenes);
        $position = array_search($shot['scene'], $order, true);
        $before = is_int($position) && $position > 0 ? $order[$position - 1] : null;
        $chain = array_values(array_filter([(string) $shot['scene'], $before !== null && ($scenes[$shot['scene']]['continuity_from'] ?? null) === $before ? $before : null]));

        foreach ((array) $shot['reference_needs'] as $need) {
            $problem = match ($need['kind']) {
                'identity' => $need['target'] === null ? null : 'nhu cầu identity nói về cả chiếc xe — target phải null.',
                'design' => in_array($need['target'], $parts, true) ? null : "nhu cầu design trỏ {$need['target']}, không có trong parts của shot.",
                'environment' => $need['target'] === ($environment['mode'] === 'background' ? $environment['background'] : null) ? null : 'nhu cầu environment phải trỏ đúng nền của shot (null khi yêu cầu nền bổ sung).',
                'continuity' => match (true) {
                    ! in_array($need['target'], $earlier, true) => "nhu cầu continuity trỏ {$need['target']}, không phải shot đứng trước.",
                    ! in_array(substr((string) $need['target'], 0, 4), $chain, true) => "nhu cầu continuity trỏ {$need['target']}, không cùng chuỗi hành động liên tục (cùng scene, hoặc scene trước khi bản chia scene ghi continuity_from).",
                    default => null,
                },
                default => null,
            };

            if ($problem !== null) {
                $issues[] = "{$id}: {$problem}";
            }
        }

        $links = (array) $shot['links'];

        if ($links['match_from'] !== null && ! in_array($links['match_from'], $earlier, true)) {
            $issues[] = "{$id}: match_from {$links['match_from']} không phải shot đứng trước.";
        }

        if ($links['motif'] !== null && ! in_array($links['motif'], (array) $context['loops'], true)) {
            $issues[] = "{$id}: motif \"{$links['motif']}\" không phải motif nào của kịch bản.";
        }

        return $issues;
    }

    /**
     * @param  list<array<string, mixed>>  $shots
     * @param  list<array<string, mixed>>  $omissions  data.omissions of a plan that leaves work between its shots
     * @return list<array{id: string, work: list<string>, beats: list<string>, done_before: list<string>, done_after: list<string>}> each shot with the work items it shows and the beats it covers and the work items the omissions say were done outside the picture before it and, for the last shot of its scene, after it, as MotoWork::shotPlaces() reads them
     */
    public static function workShots(array $shots, array $omissions = []): array
    {
        $before = [];
        $after = [];
        $last = array_column($shots, 'id', 'scene');

        foreach ($omissions as $omission) {
            $ids = array_values(array_map('strval', (array) ($omission['work_ids'] ?? [])));
            $at = $omission['before_shot'] ?? null;

            if (is_string($at)) {
                $before[$at] = [...($before[$at] ?? []), ...$ids];
            } elseif (isset($last[$omission['scene'] ?? ''])) {
                $after[$last[$omission['scene']]] = [...($after[$last[$omission['scene']]] ?? []), ...$ids];
            }
        }

        return array_map(static fn (array $shot): array => [
            'id' => (string) $shot['id'],
            'work' => array_values(array_map('strval', (array) ($shot['work'] ?? []))),
            'beats' => array_values(array_map('strval', array_column((array) $shot['coverage'], 'beat'))),
            'done_before' => $before[(string) $shot['id']] ?? [],
            'done_after' => $after[(string) $shot['id']] ?? [],
        ], $shots);
    }

    /**
     * @param  list<array<string, mixed>>  $omissions  data.omissions
     * @param  list<array<string, mixed>>  $shots  the shots as the model wrote them
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, array<string, mixed>>  $scenes
     * @param  array<string, mixed>  $context
     * @return list<string> an omission of a scene the plan does not have, placed before a shot that is not a shot of that scene, naming an event or a work item the source does not have or that belongs to another scene, naming one twice, omitting an event a text card or a shot directive is tied to, lying outside the gap it is placed in by the script's order, or giving evidence that does not resolve
     */
    private static function omissionIssues(array $omissions, array $shots, array $beats, array $scenes, array $context): array
    {
        $issues = [];
        $index = [];
        $sceneOf = [];
        $number = 0;

        foreach ($beats as $beat) {
            foreach (array_column((array) ($beat['events'] ?? []), 'id') as $event) {
                $index[$event] = $number++;
                $sceneOf[$event] = (string) $beat['scene'];
            }
        }

        $tied = [];

        foreach ((array) ($context['cards'] ?? []) as $id => $card) {
            if (($card['event'] ?? null) !== null) {
                $tied[$card['event']] = "text card {$id}";
            }
        }

        foreach ((array) ($context['directives'] ?? []) as $directive) {
            foreach ((array) ($directive['event_anchors'] ?? []) as $anchor) {
                $tied[$anchor['event_id']] = "chỉ dẫn {$directive['id']}";
            }
        }

        $byScene = [];

        foreach ($shots as $shot) {
            $byScene[(string) $shot['scene']][] = ['id' => (string) $shot['id'], 'events' => array_values(array_filter(array_merge([], ...array_column((array) $shot['coverage'], 'event_ids')), static fn (string $event): bool => isset($index[$event])))];
        }

        $seen = ['event' => [], 'work' => []];

        foreach ($omissions as $position => $omission) {
            $label = "omissions[{$position}]";
            $scene = (string) $omission['scene'];

            if (! isset($scenes[$scene])) {
                $issues[] = "{$label} nêu {$scene}, không có trong bản chia scene.";

                continue;
            }

            $rows = $byScene[$scene] ?? [];
            $at = $omission['before_shot'] === null ? count($rows) : array_search($omission['before_shot'], array_column($rows, 'id'), true);

            if ($at === false) {
                $issues[] = "{$label}: before_shot {$omission['before_shot']} không phải shot của {$scene}.";

                continue;
            }

            $earlier = array_merge([], ...array_column(array_slice($rows, 0, $at), 'events'));
            $own = $rows[$at]['events'] ?? [];
            $after = array_map(static fn (string $event): int => $index[$event], array_diff($earlier, $own));
            $ahead = array_map(static fn (string $event): int => $index[$event], array_diff($own, $earlier) ?: $own);
            $from = $after === [] ? -1 : max($after);
            $until = $ahead === [] ? PHP_INT_MAX : min($ahead);

            foreach ((array) $omission['event_ids'] as $event) {
                $problem = match (true) {
                    ! isset($index[$event]) => 'không có trong kịch bản',
                    $sceneOf[$event] !== $scene => "thuộc {$sceneOf[$event]}, không phải {$scene}",
                    isset($seen['event'][$event]) => 'đã nằm trong một mục omissions khác',
                    isset($tied[$event]) => "gắn với {$tied[$event]} — không được lược",
                    $index[$event] <= $from => 'đứng trước sự kiện mà shot trước chỗ lược đã diễn — sai thứ tự kịch bản',
                    $index[$event] >= $until => $omission['before_shot'] === null ? 'sai thứ tự kịch bản' : "đứng sau sự kiện mà {$omission['before_shot']} diễn — phần lược phải xong trước 0 ms của shot đó",
                    default => null,
                };

                if ($problem !== null) {
                    $issues[] = "{$label}: {$event} {$problem}.";
                }

                $seen['event'][$event] = true;
            }

            foreach ((array) $omission['work_ids'] as $key) {
                $item = $context['work']['items'][$key] ?? null;
                $problem = match (true) {
                    $item === null => 'không có trong bản chia scene',
                    ($beats[$item['beat']]['scene'] ?? null) !== $scene => "thuộc {$item['beat']}, không phải beat của {$scene}",
                    isset($seen['work'][$key]) => 'đã nằm trong một mục omissions khác',
                    default => null,
                };

                if ($problem !== null) {
                    $issues[] = "{$label}: {$key} {$problem}.";
                }

                $seen['work'][$key] = true;
            }

            foreach ((array) $omission['evidence'] as $evidence) {
                if (($problem = MotoScenesCheck::evidenceProblem($evidence, (array) $context['input'])) !== null) {
                    $issues[] = "{$label}: {$problem}";
                }
            }

            $listed = array_values(array_filter((array) $omission['event_ids'], static fn (string $event): bool => isset($index[$event])));
            $inOrder = $listed;
            usort($inOrder, static fn (string $a, string $b): int => $index[$a] <=> $index[$b]);

            if ($listed !== $inOrder) {
                $issues[] = "{$label}: event_ids ghi ".implode(', ', $listed).'; thứ tự của kịch bản là '.implode(', ', $inOrder).'.';
            }

            $workOrder = array_flip(array_keys((array) ($context['work']['items'] ?? [])));
            $done = array_values(array_filter((array) $omission['work_ids'], static fn (string $key): bool => isset($workOrder[$key])));
            $doneInOrder = $done;
            usort($doneInOrder, static fn (string $a, string $b): int => $workOrder[$a] <=> $workOrder[$b]);

            if ($done !== $doneInOrder) {
                $issues[] = "{$label}: work_ids ghi ".implode(', ', $done).'; thứ tự của bản chia scene là '.implode(', ', $doneInOrder).'.';
            }
        }

        return [...$issues, ...self::workLinkIssues($omissions, $shots, $context)];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, list<string>> for every work item of the scene plan, by the id code gives it, the script events its evidence points at (script.beats.Bx.events.EVxx.…); empty for an item whose evidence names no event
     */
    public static function workEvents(array $context): array
    {
        $links = [];

        foreach ((array) ($context['scenes'] ?? []) as $scene) {
            foreach (array_values((array) ($scene['work'] ?? [])) as $position => $item) {
                $events = [];

                foreach ((array) ($item['evidence'] ?? []) as $evidence) {
                    if (preg_match('/\.events\.(EV\d+)(\.|$)/', (string) ($evidence['path'] ?? ''), $match) === 1) {
                        $events[] = $match[1];
                    }
                }

                $links[MotoWork::key((string) $scene['id'], $position)] = array_values(array_unique($events));
            }
        }

        return $links;
    }

    /**
     * @param  list<array<string, mixed>>  $omissions  data.omissions
     * @param  list<array<string, mixed>>  $shots  the shots as the model wrote them
     * @param  array<string, mixed>  $context
     * @return list<string> where the work and the events of the plan disagree, read from the event each work item of the scene plan points at: work listed as done outside the picture whose event a shot plays, or whose event is not left in the same gap, or that points at no event, so that code cannot tell which moment it belongs to; an event left in a gap whose work is not left with it; and work a shot shows without playing its event
     */
    private static function workLinkIssues(array $omissions, array $shots, array $context): array
    {
        $links = self::workEvents($context);
        $issues = [];
        $playedBy = [];

        foreach ($shots as $shot) {
            foreach (array_merge([], ...array_column((array) $shot['coverage'], 'event_ids')) as $event) {
                $playedBy[$event] ??= (string) $shot['id'];
            }
        }

        foreach ($omissions as $position => $omission) {
            $label = "omissions[{$position}]";
            $events = array_values((array) $omission['event_ids']);
            $work = array_values((array) $omission['work_ids']);

            foreach ($work as $key) {
                if (! isset($links[$key])) {
                    continue;
                }

                if ($links[$key] === []) {
                    $issues[] = "{$label}: {$key} không trỏ tới sự kiện nào của kịch bản — không xác định được nó thuộc thời điểm nào nên không lược được; quay công việc này hoặc ghi xung đột.";
                }

                foreach ($links[$key] as $event) {
                    if (isset($playedBy[$event])) {
                        $issues[] = "{$label}: {$key} khai làm ngoài hình nhưng sự kiện {$event} của nó được {$playedBy[$event]} diễn — một công việc không thể vừa xong ngoài hình vừa diễn trong shot.";
                    } elseif (! in_array($event, $events, true)) {
                        $issues[] = "{$label}: {$key} khai làm ngoài hình nhưng sự kiện {$event} của nó không nằm trong cùng mục lược này.";
                    }
                }
            }

            foreach ($links as $key => $linked) {
                if (array_intersect($linked, $events) !== [] && ! in_array($key, $work, true)) {
                    $issues[] = "{$label}: ".implode(', ', array_values(array_intersect($linked, $events)))." bị lược nhưng công việc {$key} của nó không nằm trong cùng mục lược này.";
                }
            }
        }

        foreach ($shots as $shot) {
            $own = array_merge([], ...array_column((array) $shot['coverage'], 'event_ids'));

            foreach ((array) ($shot['work'] ?? []) as $key) {
                if (($links[$key] ?? []) !== [] && array_intersect($links[$key], $own) === []) {
                    $issues[] = "{$shot['id']}: thể hiện {$key} nhưng không diễn sự kiện ".implode(', ', $links[$key]).' của nó.';
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  list<array<string, mixed>>  $covered  the beats the shot covers, in the order it covers them
     * @param  array<string, mixed>  $context
     * @return list<string> under the grouping policy the run pinned, what stops a shot from running through more than one beat: an environment that is not a background every one of those beats has, a time jump at a beat it runs into, and a beat whose first event does not begin in the operating state the last event of the beat before it ends in. Empty for a shot of one beat and for a run that pinned no policy
     */
    private static function groupingIssues(array $shot, array $covered, array $context): array
    {
        $beats = [];

        foreach ($covered as $beat) {
            if ($beats === [] || $beats[count($beats) - 1]['id'] !== $beat['id']) {
                $beats[] = $beat;
            }
        }

        if (($context['shot_grouping_policy'] ?? null) !== self::GROUPING || count($beats) < 2) {
            return [];
        }

        $id = (string) $shot['id'];
        $names = implode(', ', array_column($beats, 'id'));
        $shared = array_values(array_intersect(...array_map(static fn (array $beat): array => array_values((array) $beat['backgrounds']), $beats)));
        $environment = (array) $shot['environment'];
        $issues = [];

        if (($environment['mode'] ?? null) !== 'background') {
            $issues[] = "{$id} phủ {$names} nhưng environment.mode là ".json_encode($environment['mode'] ?? null).' — shot chạy qua nhiều beat phải đứng trên một nền chung của các beat đó; nếu không có thì tách shot ở ranh giới beat.';
        } elseif (! in_array($environment['background'] ?? null, $shared, true)) {
            $issues[] = "{$id} phủ {$names} nhưng {$environment['background']} không phải nền chung của các beat đó (".(implode(', ', $shared) ?: 'không có nền chung').') — chọn nền chung hoặc tách shot ở ranh giới beat.';
        }

        foreach (array_slice($beats, 1) as $index => $beat) {
            $earlier = $beats[$index];

            if (($beat['time_jump'] ?? null) !== 'none') {
                $issues[] = "{$id} chạy từ {$earlier['id']} sang {$beat['id']} nhưng {$beat['id']} khai time_jump ".json_encode($beat['time_jump'] ?? null).' — một cảnh quay liên tục không vượt bước nhảy thời gian.';
            }

            $before = array_values((array) ($earlier['events'] ?? []));
            $after = array_values((array) ($beat['events'] ?? []));
            $last = $before === [] ? null : $before[count($before) - 1];
            $first = $after[0] ?? null;

            if (! is_array($last['operating_end'] ?? null) || ! is_array($first['operating_start'] ?? null)) {
                $issues[] = "{$id} chạy từ {$earlier['id']} sang {$beat['id']} nhưng nguồn không có trạng thái vận hành ở điểm nối để đối chiếu.";

                continue;
            }

            $fields = array_values(array_filter(
                array_values(array_unique([...array_keys($last['operating_end']), ...array_keys($first['operating_start'])])),
                static fn (string $field): bool => MotoScriptCheck::canonical([$field => $last['operating_end'][$field] ?? null]) !== MotoScriptCheck::canonical([$field => $first['operating_start'][$field] ?? null]),
            ));

            if (MotoScriptCheck::canonical($last['operating_end']) !== MotoScriptCheck::canonical($first['operating_start'])) {
                $issues[] = "{$id} chạy từ {$earlier['id']} sang {$beat['id']} nhưng operating_end của {$last['id']} khác operating_start của {$first['id']} ở ".implode(', ', $fields).' — trạng thái không nối liền, tách shot ở ranh giới beat.';
            }
        }

        return $issues;
    }

    /**
     * @param  list<string>  $parts  the parts in frame
     * @param  list<array<string, mixed>>  $covered  the beats the shot covers, in order
     * @param  array<string, list<string>>  $shown  the events of each covered beat the shot plays
     * @param  array<string, string>  $stand  the configurations of the side stand with the position each is
     * @param  array<string, mixed>  $context
     * @return list<string> the moments between the first and the last instant of the shot at which a part in frame is in a position whose operational limit is not met: every operating state the events the shot plays pass through, other than the one it opens on and the one it closes on, which the shot states itself. A take that opens and closes within the limits does not hide what it shows in between
     */
    private static function passageIssues(string $id, array $parts, array $covered, array $shown, array $stand, array $context): array
    {
        $issues = [];
        $beats = [];

        foreach ($covered as $beat) {
            if ($beats === [] || $beats[count($beats) - 1]['id'] !== $beat['id']) {
                $beats[] = $beat;
            }
        }

        foreach ($beats as $position => $beat) {
            $events = array_values((array) ($beat['events'] ?? []));
            $own = array_column($events, 'id');
            $played = array_values(array_filter(array_map(static fn (string $event): int|false => array_search($event, $own, true), array_map('strval', $shown[$beat['id']] ?? [])), static fn (int|false $index): bool => $index !== false));

            if ($played === []) {
                continue;
            }

            $opens = $position === 0 ? min($played) * 2 : null;
            $closes = $position === count($beats) - 1 ? max($played) * 2 + 1 : null;

            foreach ($played as $index) {
                foreach (['operating_start' => $index * 2, 'operating_end' => $index * 2 + 1] as $key => $at) {
                    if ($at === $opens || $at === $closes) {
                        continue;
                    }

                    foreach ((array) ($events[$index][$key]['configurations'] ?? []) as $configurationId) {
                        if (! in_array($context['configurations'][$configurationId]['part'] ?? null, $parts, true)) {
                            continue;
                        }

                        foreach ((array) ($context['limits'] ?? []) as $limit) {
                            if (! in_array($configurationId, (array) $limit['governs'], true)) {
                                continue;
                            }

                            $unmet = MotoScriptCheck::unmet($limit, MotoScriptCheck::situationFor($beat, self::beatBefore($beat, $context), (string) $configurationId, $stand, $at, MotoScriptCheck::evidenceFor((array) ($context['procedure_evidence'] ?? []), (string) $limit['id'])));

                            if ($unmet !== []) {
                                $issues[] = "{$id}: giữa shot, {$configurationId} ở ".($key === 'operating_start' ? 'đầu' : 'cuối')." {$events[$index]['id']} chịu {$limit['id']} nhưng tình huống của {$beat['id']} lúc đó chưa đạt: ".implode(', ', $unmet).'.';
                            }
                        }
                    }
                }
            }
        }

        return array_values(array_unique($issues));
    }

    /**
     * @param  array<string, mixed>  $beat
     * @param  array<string, mixed>  $context
     * @return ?array<string, mixed> the beat that comes before it in the script; null for the first beat
     */
    private static function beatBefore(array $beat, array $context): ?array
    {
        $beats = array_values((array) ($context['beats'] ?? []));
        $at = array_search($beat['id'] ?? null, array_column($beats, 'id'), true);

        return $at === false || $at === 0 ? null : $beats[$at - 1];
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  list<string>  $parts  the parts in frame
     * @param  list<array<string, mixed>>  $covered  the beats the shot covers
     * @param  array<string, mixed>  $facts  the facts of the scene's build state
     * @param  array<string, mixed>  $context
     * @return list<string> a configuration the design does not have, of a part out of frame or given twice, a part on the motorcycle whose position the beats or the build state set but the shot leaves out, an end configuration the beats do not set, a configuration other than the one the build state's exception holds, and a configuration whose operational limit the situation of the beat it is shown in does not meet
     */
    private static function configurationIssues(array $shot, array $parts, array $covered, array $facts, array $context): array
    {
        $partOf = static fn (string $configurationId): ?string => $context['configurations'][$configurationId]['part'] ?? null;
        $issues = [];
        $id = (string) $shot['id'];
        $listed = array_values(array_unique(array_merge([], ...array_column($covered, 'configurations'))));
        $byPart = [];

        foreach (['start', 'end'] as $moment) {
            foreach ((array) $shot['configurations'][$moment] as $configurationId) {
                $configuration = $context['configurations'][$configurationId] ?? null;

                if ($configuration === null) {
                    $issues[] = "{$id}: cấu hình {$configurationId} không có trong thiết kế.";

                    continue;
                }

                $part = (string) $configuration['part'];

                if (! in_array($part, $parts, true)) {
                    $issues[] = "{$id}: cấu hình {$configurationId} thuộc {$part}, không có trong parts của shot.";
                }

                if (isset($byPart[$moment][$part])) {
                    $issues[] = "{$id}: {$part} có hai cấu hình ở {$moment}.";
                }

                $byPart[$moment][$part] = $configurationId;
            }
        }

        $installed = array_column(array_filter((array) $shot['parts'], static fn (array $item): bool => $item['presentation'] === 'installed'), 'part');

        foreach ($installed as $part) {
            $set = array_filter($listed, static fn (string $listedId): bool => $partOf($listedId) === $part);
            $pin = $facts['pinned'][$part] ?? null;

            if (($set !== [] || $pin !== null) && (! isset($byPart['start'][$part]) || ! isset($byPart['end'][$part]))) {
                $issues[] = "{$id}: vị trí của {$part} do beat hoặc build state xác lập (".implode(', ', array_values(array_unique([...$set, ...array_filter([$pin], 'is_string')]))).') — khai cấu hình của nó ở cả start và end.';
            }
        }

        foreach (isset($shot['action_timeline']) ? [] : ($byPart['end'] ?? []) as $part => $configurationId) {
            $set = array_values(array_filter($listed, static fn (string $listedId): bool => $partOf($listedId) === $part));

            if ($set !== [] && ! in_array($configurationId, $set, true)) {
                $issues[] = "{$id}: cuối shot {$part} ở {$configurationId}; beat chỉ nêu ".implode(', ', $set).'.';
            }
        }

        $moments = ['start' => $covered[0] ?? null, 'end' => $covered === [] ? null : $covered[count($covered) - 1]];
        $stand = MotoScriptCheck::standPositions((array) ($context['part_records'] ?? []));
        $shown = [];

        foreach ((array) $shot['coverage'] as $item) {
            $shown[(string) ($item['beat'] ?? '')] = array_values((array) ($item['event_ids'] ?? []));
        }

        foreach ($moments as $moment => $beat) {
            $at = is_array($beat) ? MotoScriptCheck::momentOf($beat,$shown[$beat['id']] ?? [], $moment === 'end') : null;

            foreach ($byPart[$moment] ?? [] as $configurationId) {
                foreach ((array) ($context['limits'] ?? []) as $limit) {
                    if (! in_array($configurationId, (array) $limit['governs'], true)) {
                        continue;
                    }

                    $unmet = MotoScriptCheck::unmet($limit, is_array($beat) ? MotoScriptCheck::situationFor($beat, self::beatBefore($beat, $context), (string) $configurationId, $stand, $at, MotoScriptCheck::evidenceFor((array) ($context['procedure_evidence'] ?? []), (string) $limit['id'])) : null);

                    if ($unmet !== []) {
                        $issues[] = "{$id}: {$configurationId} ở {$moment} chịu {$limit['id']} nhưng tình huống của ".(is_array($beat) ? $beat['id'] : 'beat')." chưa đạt: ".implode(', ', $unmet).'.';
                    }
                }
            }
        }

        if (isset($shot['action_timeline'])) {
            $issues = [...$issues, ...self::passageIssues($id, $parts, $covered, $shown, $stand, $context)];
        }

        foreach ((array) ($facts['pinned'] ?? []) as $part => $configurationId) {
            $set = array_filter($listed, static fn (string $listedId): bool => ($context['configurations'][$listedId]['part'] ?? null) === $part);

            foreach (['start', 'end'] as $moment) {
                $given = $byPart[$moment][$part] ?? null;

                if ($set === [] && $given !== null && $given !== $configurationId) {
                    $issues[] = "{$id}: ngoại lệ của build state giữ {$part} ở {$configurationId}; shot ghi {$given} ở {$moment}.";
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  array<string, mixed>  $context
     * @return list<string> at a build state of an unfinished motorcycle, a state with no surface of its own; at any build state, the parts in frame whose finish at the first frame the sources do not establish, for the reviewer to check before a keyframe. What a part's prose says about its finish is left to the reviewer
     */
    private static function surfaceWarnings(array $shot, ?string $state, array $context): array
    {
        $warnings = [];

        if (! MotoShotPrompt::finishShown($state, $context)) {
            $facts = (array) $context['states'][$state];

            if (! is_string($facts['surface'] ?? null) && array_filter((array) ($facts['finishes'] ?? []), 'is_string') === []) {
                $warnings[] = "{$shot['id']}: {$state} không có mô tả bề mặt của giai đoạn (surface, surfaces) — prompt ảnh không nói được bề mặt hiện tại.";
            }
        }

        $unknown = array_values(array_filter((array) $shot['parts'], static fn (array $item): bool => in_array(MotoReferenceSurface::UNKNOWN, array_column(MotoShotPrompt::surfaces($item, $state, (string) $shot['first_frame'], $context), 'status'), true)));

        if ($unknown !== []) {
            $warnings[] = "{$shot['id']}: chưa đủ căn cứ về bề mặt ở khung đầu của ".implode(', ', array_column($unknown, 'part')).' — kiểm trước khi làm keyframe.';
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  list<array{id: string, scene: string, fingerprint: string}>  $seen  the shots before it
     * @return list<string> a wide camera on the motorcycle in a shot that declares less than the whole of it, and an angle repeated from the shot before or an earlier shot of the scene without a link that explains it
     */
    private static function shotWarnings(array $shot, ?string $state, array $seen): array
    {
        $warnings = [];
        $camera = (array) $shot['camera'];

        if ($camera['target'] === 'motorcycle' && in_array($camera['scale'], self::WHOLE_SCALES, true) && $shot['reveal'] !== 'whole') {
            $warnings[] = "{$shot['id']}: camera nhắm vào xe ở cỡ {$camera['scale']} nhưng shot khai reveal {$shot['reveal']} — kiểm khung hình có lộ toàn xe không.";
        }

        $links = (array) $shot['links'];
        $fingerprint = MotoShotPlanner::fingerprint($shot, $state);
        $previous = $seen === [] ? null : $seen[count($seen) - 1];
        $repeat = array_values(array_filter($seen, static fn (array $row): bool => $row['fingerprint'] === $fingerprint && ($row['scene'] === $shot['scene'] || $row === $previous)));

        if ($repeat !== [] && $links['match_from'] === null && $links['motif'] === null && $links['repeat_reason'] === null) {
            $warnings[] = "{$shot['id']} lặp góc của ".implode(', ', array_column($repeat, 'id')).' mà không nêu match_from, motif hay repeat_reason.';
        }

        return $warnings;
    }

    /**
     * @param  list<array<string, mixed>>  $conflicts
     * @param  list<string>  $shots  the shot ids in order
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, mixed>  $input
     * @return list<string> conflicts naming an unknown shot or beat, and evidence not found in the pinned input
     */
    private static function conflictIssues(array $conflicts, array $shots, array $beats, array $input): array
    {
        $issues = [];

        foreach ($conflicts as $index => $conflict) {
            $label = 'conflicts['.$index.']';

            foreach ((array) $conflict['shots'] as $id) {
                if (! in_array($id, $shots, true)) {
                    $issues[] = "{$label} nêu {$id}, không có trong shots.";
                }
            }

            foreach ((array) $conflict['beats'] as $id) {
                if (! isset($beats[$id])) {
                    $issues[] = "{$label} nêu {$id}, không có trong kịch bản.";
                }
            }

            foreach ((array) $conflict['evidence'] as $evidence) {
                if (($problem = MotoScenesCheck::evidenceProblem($evidence, $input)) !== null) {
                    $issues[] = "{$label}: {$problem}";
                }
            }
        }

        return $issues;
    }

    /**
     * @param  list<string>  $schema
     * @param  list<string>  $issues
     * @param  list<string>  $warnings
     * @return array{version: string, format: string, verdict: string, status: ?string, error: ?string, schema: list<string>, issues: list<string>, warnings: list<string>, judge: string}
     */
    private static function result(string $verdict, ?string $status, ?string $error, array $schema = [], array $issues = [], array $warnings = []): array
    {
        return [
            'version' => self::VERSION,
            'format' => MotoContract::SHOTS_FORMAT,
            'verdict' => $verdict,
            'status' => $status,
            'error' => $error,
            'schema' => $schema,
            'issues' => array_values(array_unique($issues)),
            'warnings' => array_values(array_unique($warnings)),
            'judge' => self::JUDGE,
        ];
    }
}
