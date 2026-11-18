<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoShotsCheck
{
    public const VERSION = 'moto-shots-check-v7';

    /** @var string the timing a run pinned from MotoShots@1.2.0 on: code sets the screen time of whole and closing parts and of the shot */
    public const TIMING = 'moto-shots-timing-v1';

    /** @var string the timing a run pinned from MotoShots@2.0.0 on: every shot renders and keeps SHOT_MS, and the shot plan gives the instants of its action steps, camera path and cards */
    public const TIMING_FIXED = 'moto-shots-timing-v2';

    /** @var string the grouping policy a run pins as shot_grouping_policy: a shot that covers more than one beat stands on a background all of them have, crosses no time jump and joins operating states that connect. A run that pins none is checked as before */
    public const GROUPING = 'scene-continuous-v1';

    /** @var string the editing policy a run pins as shot_editing_policy: the plan is a selection within shot_budget.max shots, and every event and work item of the source is either shown by a shot or listed in data.omissions, never both. A run that pins none must show every event and every work item */
    public const EDITING = 'editorial-20-v1';

    /** @var string the policy a run pins as shot_editing_policy from MotoShots@2.2.0 on: the plan stays within shot_budget.max shots and plays every event of the source — data.omissions is empty, an event may run on through neighbouring shots as its event_progress says, and the work on a part a shot completes is derived from the events it completes, never written by the model */
    public const COVERAGE = 'full-coverage-budget-v1';

    /** @var list<string> the editing policies code can check */
    private const EDITING_POLICIES = [self::EDITING, self::COVERAGE];

    /** @var list<string> the phases of event_progress that complete an event */
    private const COMPLETES = ['whole', 'finish'];

    /** @var string the pattern of a design reference that is a custom feature or a signature moment, which an edited film must show somewhere */
    private const IDENTITY_REF = '/^[FM]\d+$/';

    /** @var int the least milliseconds a text card stays on screen */
    private const CARD_MIN_MS = 1500;

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

        if ($editing && ! in_array($context['shot_editing_policy'], self::EDITING_POLICIES, true)) {
            return self::result(self::UNSCORED, null, 'Lượt chạy ghim shot_editing_policy '.json_encode($context['shot_editing_policy']).'; code chỉ tính '.implode(', ', self::EDITING_POLICIES).' hoặc lượt không ghim chính sách biên tập.');
        }

        $full = $editing && $context['shot_editing_policy'] === self::COVERAGE;

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
            $issues[] = Finding::blocking('MotoShotsCheck.scored.1', 'project_id không khớp lượt chạy.');
        }

        if (($response['prompt_version'] ?? null) !== ($context['prompt_version'] ?? null)) {
            $issues[] = Finding::blocking('MotoShotsCheck.scored.2', 'prompt_version là "'.($response['prompt_version'] ?? '').'"; lượt chạy này dùng '.($context['prompt_version'] ?? '').'.');
        }

        $data = (array) $response['data'];
        $written = array_values((array) $data['shots']);
        $written = $full ? self::withCompletedWork($written, (array) $context['scenes']) : $written;
        $shots = array_map(self::legacy(...), $written);
        $conflicts = array_values((array) $data['conflicts']);
        $beats = array_column((array) $context['beats'], null, 'id');
        $scenes = array_column((array) $context['scenes'], null, 'id');
        $order = array_column($shots, 'id');
        $timing = self::timing($shots, $beats, $context['timing'] ?? null);
        $omissions = $editing && ! $full ? array_values((array) ($data['omissions'] ?? [])) : [];
        $issues = [...$issues, ...self::idIssues($shots, $scenes), ...$timing['issues'], ...($fixed
            ? [...self::eventCoverageIssues($written, $beats, $scenes, $editing && ! $full ? array_merge([], ...array_column($omissions, 'event_ids')) : null), ...self::timelineIssues($written, $beats, $context)]
            : self::coverageIssues($shots, $beats, $scenes, (int) $context['total_ms'], $timing['shots']))];

        if ($full) {
            if ((array) ($data['omissions'] ?? []) !== []) {
                $issues[] = Finding::note('MotoShotsCheck.scored.3', 'data.omissions phải rỗng: chính sách '.self::COVERAGE.' không để sự kiện hay công việc nào của nguồn ngoài hình.');
            }

            $issues = [...$issues, ...self::progressIssues($written), ...self::unlinkedWorkIssues((array) $context['scenes'])];
        }

        if ($editing) {
            $max = $context['shot_budget']['max'] ?? null;

            if (! is_int($max)) {
                return self::result(self::UNSCORED, null, 'Lượt chạy ghim chính sách biên tập nhưng không ghim shot_budget.max — không kiểm được ngân sách shot.');
            }

            if (count($shots) > $max) {
                $issues[] = Finding::requirement('MotoShotsCheck.scored.4', 'Kế hoạch có '.count($shots)." shot, vượt ngân sách {$max} shot của phim.");
            }

            if (! $full) {
                $issues = [...$issues, ...self::omissionIssues($omissions, $written, $beats, $scenes, $context)];
            }
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
        }

        foreach ($fixed ? $written : [] as $shot) {
            $images = count((array) $shot['reference_needs']) + count((array) $shot['people']);

            if ($images > (int) $context['reference_limit']) {
                $warnings[] = "{$shot['id']}: ".count((array) $shot['reference_needs']).' nhu cầu tham chiếu cùng '.count((array) $shot['people'])." người có thể cần hơn {$context['reference_limit']} ảnh của một clip — kiểm ở màn Reference trước khi render.";
            }

        }

        if (is_array($context['work'] ?? null)) {
            $shown = array_merge([], ...array_map(static fn (array $shot): array => (array) ($shot['work'] ?? []), $shots));
            $left = array_merge([], ...array_column($omissions, 'work_ids'));

            foreach ((array) $context['work']['items'] as $key => $item) {
                $label = "{$key} ({$item['beat']} {$item['part']} {$item['operation']})";

                if (in_array($key, $shown, true) && in_array($key, $left, true)) {
                    $issues[] = Finding::note('MotoShotsCheck.scored.5', "{$label} vừa có shot thể hiện vừa nằm trong omissions — mỗi công việc chỉ ở một nơi.");
                } elseif (! in_array($key, $shown, true) && ! in_array($key, $left, true)) {
                    $issues[] = Finding::note('MotoShotsCheck.scored.6', match (true) {
                        $full => "{$label} chưa hoàn tất ở shot nào: các sự kiện gắn với công việc này chưa được shot nào diễn tới whole hoặc finish.",
                        $editing => "{$label} không có shot nào thể hiện và cũng không nằm trong omissions.",
                        default => "{$label} không có shot nào thể hiện.",
                    });
                }
            }
        }

        if (($context['cover_design_refs'] ?? false) === true) {
            $issues = [...$issues, ...self::designRefIssues($shots, $beats, $scenes, $context, (array) ($placed['shots'] ?? []), $editing)];
        }

        $issues = [...$issues, ...self::conflictIssues($conflicts, $order, $beats, (array) $context['input'])];
        $blocking = array_filter($conflicts, static fn (array $conflict): bool => (bool) $conflict['blocking']) !== [];

        if ($status === 'ok' && $blocking) {
            $issues[] = Finding::requirement('MotoShotsCheck.scored.7', 'Còn xung đột chặn nhưng status là ok.');
        }

        if ($status === 'partial' && ! $blocking) {
            $issues[] = Finding::note('MotoShotsCheck.scored.8', 'Status partial nhưng không còn xung đột chặn nào.');
        }

        return self::result($issues === [] ? self::PASS : self::FAIL, $status, null, [], $issues, $warnings);
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
                    default => null,
                };

                if ($problem !== null) {
                    $issues[] = Finding::requirement('MotoShotsCheck.designRefIssues.1', "{$id}: off_frame {$part} {$problem}.");
                }
            }
        }

        if ($editing) {
            $named = array_values(array_unique(array_merge([], ...array_map(static fn (array $beat): array => (array) ($beat['design_refs'] ?? []), array_values($beats)))));

            foreach ($named as $ref) {
                $group = $context['ref_parts'][$ref] ?? null;

                if (is_array($group) && preg_match(self::IDENTITY_REF, (string) $ref) === 1 && array_intersect($group, $film) === []) {
                    $issues[] = Finding::note('MotoShotsCheck.designRefIssues.2', "Kịch bản nêu {$ref} nhưng không shot nào của phim có part của {$ref} trong khung — tính năng và khoảnh khắc đặc trưng phải lên hình ít nhất một lần.");
                }
            }

            return $issues;
        }

        foreach ($beats as $id => $beat) {
            foreach ((array) ($beat['design_refs'] ?? []) as $ref) {
                $group = $context['ref_parts'][$ref] ?? null;

                if (is_array($group) && array_intersect($group, $inFrame[$id] ?? []) === []) {
                    $issues[] = Finding::note('MotoShotsCheck.designRefIssues.3', "{$id} nêu {$ref} nhưng không shot nào phủ beat này có part của {$ref} trong khung.");
                } elseif (isset($context['parts'][$ref]) && ! in_array($ref, $seen[$id] ?? [], true)) {
                    $issues[] = Finding::note('MotoShotsCheck.designRefIssues.4', "{$id} nêu {$ref} nhưng không shot nào phủ beat này ghi {$ref} trong parts hoặc off_frame.");
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
                $issues[] = Finding::requirement('MotoShotsCheck.idIssues.1', "{$shot['id']} thuộc {$scene}, không có trong bản chia scene.");

                continue;
            }

            if ($position[$scene] < $last) {
                $issues[] = Finding::requirement('MotoShotsCheck.idIssues.2', "{$shot['id']} đứng sau shot của scene muộn hơn — shot phải theo thứ tự scene.");
            }

            $last = max($last, $position[$scene]);
            $numbers[$scene] = ($numbers[$scene] ?? 0) + 1;
            $expected = sprintf('%s_SH%02d', $scene, $numbers[$scene]);

            if ($shot['id'] !== $expected) {
                $issues[] = Finding::requirement('MotoShotsCheck.idIssues.3', "Shot thứ {$numbers[$scene]} của {$scene} là {$shot['id']}; cần {$expected}.");
            }
        }

        foreach (array_keys($scenes) as $scene) {
            if (! isset($numbers[$scene])) {
                $issues[] = Finding::note('MotoShotsCheck.idIssues.4', "{$scene} không có shot nào.");
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
                    $issues[] = Finding::requirement('MotoShotsCheck.timing.1', "{$shot['id']}: phần mở/giữa của {$beat} đã chiếm ".($given[$beat] ?? 0)." ms trong {$duration} ms của beat — không còn thời gian cho phần đóng.");
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
                    $issues[] = Finding::requirement('MotoShotsCheck.coverageIssues.1', "{$shot['id']} phủ {$beat}, không có trong kịch bản.");
                } elseif ($beats[$beat]['scene'] !== $shot['scene']) {
                    $issues[] = Finding::requirement('MotoShotsCheck.coverageIssues.2', "{$shot['id']} phủ {$beat} của {$beats[$beat]['scene']} — shot không vượt ranh giới scene.");
                }

                if ($item['result_at_ms'] !== null && ($item['result_at_ms'] < $at || $item['result_at_ms'] > $at + $length)) {
                    $issues[] = Finding::requirement('MotoShotsCheck.coverageIssues.3', "{$shot['id']}: kết quả của {$beat} ở {$item['result_at_ms']} ms, ngoài phần {$at}–".($at + $length).' ms của nó trong clip.');
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
                $issues[] = Finding::requirement('MotoShotsCheck.coverageIssues.4', "{$shot['id']}: coverage cộng lại {$used} ms; shot giữ {$kept['used_duration_ms']} ms.");
            }

            if ((int) $shot['used_start_ms'] + $kept['used_duration_ms'] > (int) $shot['render_duration_ms']) {
                $issues[] = Finding::requirement('MotoShotsCheck.coverageIssues.5', "{$shot['id']}: đoạn giữ {$shot['used_start_ms']}–".((int) $shot['used_start_ms'] + $kept['used_duration_ms'])." ms vượt clip {$shot['render_duration_ms']} ms.");
            }

            $sum += $kept['used_duration_ms'];
        }

        foreach ($scenes as $id => $scene) {
            $covered = array_column($groups[$id] ?? [], 'beat');

            if ($covered !== array_values((array) $scene['beats'])) {
                $issues[] = Finding::requirement('MotoShotsCheck.coverageIssues.6', "{$id} phủ beat theo thứ tự ".(implode(', ', $covered) ?: '—').'; cần '.implode(', ', (array) $scene['beats']).', mỗi beat đúng một lần.');
            }

            foreach ($groups[$id] ?? [] as $group) {
                $parts = $group['parts'];
                $valid = $parts === ['whole']
                    || (count($parts) >= 2 && $parts[0] === 'opening' && $parts[count($parts) - 1] === 'closing' && array_diff(array_slice($parts, 1, -1), ['middle']) === []);

                if (! $valid) {
                    $issues[] = Finding::requirement('MotoShotsCheck.coverageIssues.7', "{$group['beat']} được phủ theo ".implode(' → ', $parts).'; cần whole, hoặc opening → middle… → closing.');
                }
            }
        }

        foreach ($beats as $id => $beat) {
            $length = (int) $beat['end_ms'] - (int) $beat['start_ms'];

            if (isset($timeOf[$id]) && $timeOf[$id] !== $length) {
                $issues[] = Finding::requirement('MotoShotsCheck.coverageIssues.8', "{$id} chiếm {$timeOf[$id]} ms trên màn hình; beat đã duyệt dài {$length} ms.");
            }
        }

        if ($issues === [] && $sum !== $total) {
            $issues[] = Finding::requirement('MotoShotsCheck.coverageIssues.9', "Các shot giữ tổng {$sum} ms; phim dài {$total} ms.");
        }

        return $issues;
    }

    /**
     * @param  list<array<string, mixed>>  $shots  the shots as written under COVERAGE
     * @param  list<array<string, mixed>>  $scenes  the scenes of the pinned scene plan, each work item with its id and the events its evidence names
     * @return array<string, list<string>> by shot id, the work items of the scene plan the shot completes, in the scene plan's order: a work item is completed in the shot that completes the last of its events (event_progress whole or finish), and by no shot while one of its events is not completed. A work item with no event is completed by none
     */
    public static function completedWork(array $shots, array $scenes): array
    {
        $doneAt = [];

        foreach (array_values($shots) as $index => $shot) {
            foreach ((array) ($shot['event_progress'] ?? []) as $entry) {
                if (in_array($entry['phase'] ?? null, self::COMPLETES, true)) {
                    $doneAt[(string) $entry['event_id']] ??= $index;
                }
            }
        }

        $ids = array_column(array_values($shots), 'id');
        $completed = [];

        foreach ($scenes as $scene) {
            foreach ((array) ($scene['work'] ?? []) as $item) {
                $events = array_column((array) ($item['events'] ?? []), 'id');
                $at = array_map(static fn (string $event): ?int => $doneAt[$event] ?? null, array_map('strval', $events));

                if ($events !== [] && ! in_array(null, $at, true)) {
                    $completed[(string) $ids[max($at)]][] = (string) $item['id'];
                }
            }
        }

        return $completed;
    }

    /**
     * @param  list<array<string, mixed>>  $shots  the shots of a plan written under COVERAGE, as written or as derived
     * @param  array<string, mixed>  $context  the check context the run pinned: scenes with their beats and work, beats with their events
     * @return array{scenes: list<array<string, mixed>>, shot_count: int, duration_ms: int, problems: list<string>} for every scene of the pinned plan: the events of its beats in the script's order with the shots that show each and its chain of progress, the events missing, played out of order or completed more than once, the events the shots name that the scene does not have, every work item with the events tied to it and the shot that completes it, and the number and length of its shots; it reads only, the verdict of the plan stays with evaluate()
     */
    public static function coverageReport(array $shots, array $context): array
    {
        $shots = array_values($shots);
        $events = [];

        foreach ((array) ($context['beats'] ?? []) as $beat) {
            $events[(string) $beat['id']] = array_map('strval', array_column((array) ($beat['events'] ?? []), 'id'));
        }

        $completed = self::completedWork($shots, array_values((array) ($context['scenes'] ?? [])));
        $doneIn = [];

        foreach ($completed as $shotId => $items) {
            $doneIn += array_fill_keys($items, $shotId);
        }

        $scenes = [];
        $problems = [];

        foreach ((array) ($context['scenes'] ?? []) as $scene) {
            $id = (string) $scene['id'];
            $own = array_values(array_filter($shots, static fn (array $shot): bool => ($shot['scene'] ?? null) === $id));
            $source = array_merge([], ...array_map(static fn (string $beat): array => $events[$beat] ?? [], array_map('strval', (array) $scene['beats'])));
            $chains = [];
            $played = [];

            foreach ($own as $shot) {
                foreach ((array) ($shot['event_progress'] ?? []) as $entry) {
                    $event = (string) $entry['event_id'];
                    $chains[$event][] = ['shot' => (string) $shot['id'], 'phase' => (string) $entry['phase'], 'step_ids' => array_values((array) $entry['step_ids'])];

                    if (! in_array($event, $played, true)) {
                        $played[] = $event;
                    }
                }
            }

            $rows = [];
            $missing = [];
            $repeated = [];

            foreach ($source as $event) {
                $chain = $chains[$event] ?? [];
                $done = count(array_filter($chain, static fn (array $link): bool => in_array($link['phase'], self::COMPLETES, true)));
                $rows[] = ['event' => $event, 'shots' => array_column($chain, 'shot'), 'progress' => $chain, 'completed' => $done];

                if ($done === 0) {
                    $missing[] = $event;
                } elseif ($done > 1) {
                    $repeated[] = $event;
                }
            }

            $extra = array_values(array_diff($played, $source));
            $expected = array_values(array_intersect($source, $played));
            $shown = array_values(array_intersect($played, $source));
            $disordered = $shown === $expected ? [] : $shown;
            $work = array_map(static fn (array $item): array => [
                'id' => (string) $item['id'],
                'beat' => $item['beat'] ?? null,
                'part' => $item['part'] ?? null,
                'operation' => $item['operation'] ?? null,
                'events' => array_map('strval', array_column((array) ($item['events'] ?? []), 'id')),
                'completed_in' => $doneIn[(string) $item['id']] ?? null,
            ], array_values((array) ($scene['work'] ?? [])));

            foreach ($missing as $event) {
                $problems[] = "{$id}: {$event} chưa hoàn tất ở shot nào.";
            }

            foreach ($repeated as $event) {
                $problems[] = "{$id}: {$event} hoàn tất hơn một lần.";
            }

            foreach ($extra as $event) {
                $problems[] = "{$id}: {$event} không phải sự kiện của scene.";
            }

            if ($disordered !== []) {
                $problems[] = "{$id}: sự kiện diễn theo thứ tự ".implode(', ', $disordered).'; nguồn là '.implode(', ', $expected).'.';
            }

            foreach ($work as $item) {
                if ($item['events'] === []) {
                    $problems[] = "{$id}: công việc {$item['id']} không gắn với sự kiện nào.";
                } elseif ($item['completed_in'] === null) {
                    $problems[] = "{$id}: công việc {$item['id']} chưa hoàn tất ở shot nào.";
                }
            }

            $scenes[] = [
                'scene' => $id,
                'events' => $rows,
                'missing' => $missing,
                'extra' => $extra,
                'out_of_order' => $disordered,
                'completed_twice' => $repeated,
                'work' => $work,
                'shots' => array_column($own, 'id'),
                'shot_count' => count($own),
                'duration_ms' => array_sum(array_map(static fn (array $shot): int => (int) ($shot['used_duration_ms'] ?? $shot['render_duration_ms'] ?? 0), $own)),
            ];
        }

        return [
            'scenes' => $scenes,
            'shot_count' => count($shots),
            'duration_ms' => array_sum(array_column($scenes, 'duration_ms')),
            'problems' => $problems,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $shots  the shots as written under COVERAGE
     * @param  list<array<string, mixed>>  $scenes
     * @return list<array<string, mixed>> the shots, each with what code states under this policy and the model does not write: `work` set to the work items it completes, and the one take the policy allows — MotoContract::SHOT_MS rendered from reference images, all of it used
     */
    public static function withCompletedWork(array $shots, array $scenes): array
    {
        $completed = self::completedWork($shots, $scenes);
        $take = ['render_duration_ms' => MotoContract::SHOT_MS, 'mode' => MotoContract::REFERENCE_TO_VIDEO, 'used_start_ms' => 0, 'used_duration_ms' => MotoContract::SHOT_MS];

        return array_map(static fn (array $shot): array => ['work' => $completed[(string) $shot['id']] ?? []] + $take + $shot, array_values($shots));
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @return list<string> a work item of the scene plan whose evidence names no event: its completion cannot be derived, and it is never guessed from its words
     */
    public static function unlinkedWorkIssues(array $scenes): array
    {
        $issues = [];

        foreach ($scenes as $scene) {
            foreach ((array) ($scene['work'] ?? []) as $item) {
                if ((array) ($item['events'] ?? []) === []) {
                    $issues[] = Finding::requirement('MotoShotsCheck.unlinkedWorkIssues.1', ($item['id'] ?? '?')." ({$item['beat']} {$item['part']} {$item['operation']}) không gắn với sự kiện nào của kịch bản — không suy ra được shot hoàn tất công việc này.");
                }
            }
        }

        return $issues;
    }

    /**
     * @param  list<array<string, mixed>>  $shots  the shots as written under COVERAGE
     * @return list<string> by shot: an entry of event_progress for an event the shot does not cover or named twice, a covered event with no entry, steps the entry names that the timeline does not have or that do not play that event, a step of the event the entry leaves out; and by event across the shots: a chain that is not whole, or start, any continue, then finish, in shots that follow one another in one scene
     */
    private static function progressIssues(array $shots): array
    {
        $issues = [];
        $chains = [];

        foreach (array_values($shots) as $index => $shot) {
            $id = (string) $shot['id'];
            $covered = array_map('strval', array_merge([], ...array_column((array) $shot['coverage'], 'event_ids')));
            $steps = array_column(array_values((array) $shot['action_timeline']), null, 'id');
            $entered = [];

            foreach (array_values((array) ($shot['event_progress'] ?? [])) as $entry) {
                $event = (string) $entry['event_id'];

                if (! in_array($event, $covered, true)) {
                    $issues[] = Finding::requirement('MotoShotsCheck.progressIssues.1', "{$id}: event_progress nêu {$event}, không nằm trong coverage của shot.");

                    continue;
                }

                if (isset($entered[$event])) {
                    $issues[] = Finding::requirement('MotoShotsCheck.progressIssues.2', "{$id}: event_progress nêu {$event} hai lần — mỗi sự kiện một mục trong một shot.");

                    continue;
                }

                $entered[$event] = true;
                $named = array_map('strval', (array) $entry['step_ids']);

                foreach ($named as $step) {
                    if (! isset($steps[$step])) {
                        $issues[] = Finding::requirement('MotoShotsCheck.progressIssues.3', "{$id}: event_progress của {$event} nêu {$step}, không có trong action_timeline.");
                    } elseif (array_map('strval', (array) $steps[$step]['event_ids']) !== [$event]) {
                        $issues[] = Finding::requirement('MotoShotsCheck.progressIssues.4', "{$id}: {$step} không diễn {$event} nhưng event_progress của {$event} nêu nó.");
                    }
                }

                foreach ($steps as $stepId => $step) {
                    if (in_array($event, array_map('strval', (array) $step['event_ids']), true) && ! in_array((string) $stepId, $named, true)) {
                        $issues[] = Finding::requirement('MotoShotsCheck.progressIssues.5', "{$id}: {$stepId} diễn {$event} nhưng event_progress của {$event} không nêu nó.");
                    }
                }

                $chains[$event][] = ['index' => $index, 'shot' => $id, 'scene' => (string) $shot['scene'], 'phase' => (string) $entry['phase']];
            }

            foreach (array_diff($covered, array_keys($entered)) as $event) {
                $issues[] = Finding::requirement('MotoShotsCheck.progressIssues.6', "{$id}: {$event} có trong coverage nhưng không có mục event_progress.");
            }
        }

        foreach ($chains as $event => $chain) {
            $phases = array_column($chain, 'phase');
            $shown = implode(' → ', array_map(static fn (array $link): string => "{$link['shot']} {$link['phase']}", $chain));
            $valid = $phases === ['whole']
                || (count($phases) >= 2 && $phases[0] === 'start' && $phases[count($phases) - 1] === 'finish' && array_diff(array_slice($phases, 1, -1), ['continue']) === []);

            if (! $valid) {
                $issues[] = Finding::requirement('MotoShotsCheck.progressIssues.7', "{$event}: tiến trình {$shown} không hợp lệ — một sự kiện là whole trong một shot, hoặc start, các continue rồi finish.");

                continue;
            }

            foreach (array_slice($chain, 1) as $at => $link) {
                if ($link['index'] !== $chain[$at]['index'] + 1 || $link['scene'] !== $chain[$at]['scene']) {
                    $issues[] = Finding::requirement('MotoShotsCheck.progressIssues.8', "{$event}: tiến trình {$shown} đi qua các shot không liền nhau hoặc khác scene.");

                    break;
                }
            }
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
                    $issues[] = Finding::requirement('MotoShotsCheck.eventCoverageIssues.1', "{$shot['id']} phủ {$beat}, không có trong kịch bản.");

                    continue;
                }

                if ($beats[$beat]['scene'] !== $shot['scene']) {
                    $issues[] = Finding::requirement('MotoShotsCheck.eventCoverageIssues.2', "{$shot['id']} phủ {$beat} của {$beats[$beat]['scene']} — shot không vượt ranh giới scene.");
                }

                $own = array_column((array) ($beats[$beat]['events'] ?? []), 'id');

                foreach ((array) $item['event_ids'] as $event) {
                    $index = array_search($event, $own, true);

                    if ($index === false) {
                        $issues[] = Finding::requirement('MotoShotsCheck.eventCoverageIssues.3', "{$shot['id']}: {$event} không phải sự kiện của {$beat}.");

                        continue;
                    }

                    if ($index < ($reached[$beat] ?? 0)) {
                        $issues[] = Finding::note('MotoShotsCheck.eventCoverageIssues.4', "{$shot['id']}: {$event} của {$beat} được diễn sau một sự kiện muộn hơn — giữ thứ tự sự kiện của kịch bản.");
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
                $issues[] = Finding::requirement('MotoShotsCheck.eventCoverageIssues.5', "{$id} phủ beat theo thứ tự ".(implode(', ', $groups[$id] ?? []) ?: '—').'; cần '.implode(', ', (array) $scene['beats']).', mỗi beat một lượt liền nhau.');
            }
        }

        foreach ($beats as $id => $beat) {
            foreach (array_column((array) ($beat['events'] ?? []), 'id') as $event) {
                $left = $omitted !== null && in_array($event, $omitted, true);

                if (isset($played[$event]) && $left) {
                    $issues[] = Finding::note('MotoShotsCheck.eventCoverageIssues.6', "{$event} của {$id} vừa có shot diễn vừa nằm trong omissions — mỗi sự kiện chỉ ở một nơi.");
                } elseif (! isset($played[$event]) && ! $left) {
                    $issues[] = Finding::note('MotoShotsCheck.eventCoverageIssues.7', $omitted === null ? "{$event} của {$id} chưa có shot nào diễn." : "{$event} của {$id} không có shot nào diễn và cũng không nằm trong omissions — không sự kiện nào của nguồn được mất dấu.");
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
     * @param  list<mixed>  $supports  the support_refs of one operating state of a shot
     * @param  array<string, mixed>  $context  the context the shot run pinned
     * @param  ?string  $state  the build state of the shot's scene
     * @param  list<string>  $people  the characters the shot shows
     * @return list<string> every support that is a part the design does not have, a person the shot does not show or a temporary item the pinned policy does not allow: any the design lists under moto-validation-v2, the items of the scene's build state otherwise
     */
    public static function supportIssues(string $at, array $supports, array $context, ?string $state, array $people): array
    {
        $byDesign = MotoScriptValidation::supportsByDesign($context);
        $temporary = MotoSupportReferences::allowed((array) ($context['temporary'] ?? []), $state, $byDesign);
        $issues = [];

        foreach ($supports as $support) {
            $problem = MotoSupportReferences::problem($support, (array) ($context['parts'] ?? []), $people, $temporary, 'people của shot', $byDesign ? 'temporary của thiết kế' : 'temporary của build state của scene');

            if ($problem !== null) {
                $issues[] = Finding::requirement('MotoShotsCheck.supportIssues.1', "{$at}.support_refs: ".(is_scalar($support) ? $support : json_encode($support))." {$problem}.");
            }
        }

        return $issues;
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
                    $issues[] = Finding::requirement('MotoShotsCheck.timelineIssues.1', "{$id}: nhịp thứ ".($index + 1).' phải là '.sprintf('STEP%02d', $index + 1).", không phải {$step['id']}.");
                }

                foreach ((array) $step['event_ids'] as $event) {
                    $stepped[$event] = true;
                    $steps[$event][] = ['start_ms' => (int) $step['start_ms'], 'end_ms' => (int) $step['end_ms']];
                    $place = array_search($event, $covered, true);

                    if ($place === false) {
                        $issues[] = Finding::requirement('MotoShotsCheck.timelineIssues.2', "{$id} {$step['id']}: {$event} không nằm trong coverage của shot.");

                        continue;
                    }

                    if ($place < $reached) {
                        $issues[] = Finding::note('MotoShotsCheck.timelineIssues.3', "{$id} {$step['id']}: diễn {$event} sau khi {$covered[$reached]} đã bắt đầu — các nhịp phải theo thứ tự sự kiện của coverage.");
                    }

                    $reached = max($reached, $place);
                }

                foreach (array_diff((array) $step['actor_ids'], $people) as $actor) {
                    $issues[] = Finding::requirement('MotoShotsCheck.timelineIssues.4', "{$id} {$step['id']}: {$actor} không có trong people của shot.");
                }
            }

            foreach (array_diff($covered, array_keys($stepped)) as $event) {
                $issues[] = Finding::requirement('MotoShotsCheck.timelineIssues.5', "{$id}: {$event} có trong coverage nhưng không nhịp nào của action_timeline diễn.");
            }

            foreach ($path as $index => $segment) {
                if ($segment['id'] !== sprintf('SEG%02d', $index + 1)) {
                    $issues[] = Finding::requirement('MotoShotsCheck.timelineIssues.6', "{$id}: đoạn máy quay thứ ".($index + 1).' phải là '.sprintf('SEG%02d', $index + 1).", không phải {$segment['id']}.");
                }
            }

            foreach (['action_timeline' => $timeline, 'camera.path' => $path] as $label => $spans) {
                if (($problem = self::spanProblem($spans)) !== null) {
                    $issues[] = Finding::requirement('MotoShotsCheck.timelineIssues.7', "{$id}: {$label} {$problem} — các khoảng phải nối liền từ 0 đến ".MotoContract::SHOT_MS.' ms.');
                }
            }

            foreach (['start' => 'operating_start', 'end' => 'operating_end'] as $moment => $key) {
                $state = (array) $shot[$key];
                $byPart = [];

                foreach ((array) $state['configurations'] as $configuration) {
                    $part = $context['configurations'][$configuration]['part'] ?? null;

                    if ($part === null) {
                        $issues[] = Finding::requirement('MotoShotsCheck.timelineIssues.8', "{$id}.{$key}: cấu hình {$configuration} không có trong thiết kế.");
                    } elseif (isset($byPart[$part])) {
                        $issues[] = Finding::requirement('MotoShotsCheck.timelineIssues.9', "{$id}.{$key}: {$part} có hai vị trí cùng lúc ({$byPart[$part]}, {$configuration}).");
                    } else {
                        $byPart[$part] = $configuration;
                    }
                }

                if (is_array($state['engine']) && ! isset($context['parts'][$state['engine']['part_id']])) {
                    $issues[] = Finding::requirement('MotoShotsCheck.timelineIssues.10', "{$id}.{$key}.engine: {$state['engine']['part_id']} không có trong thiết kế.");
                }

                $issues = [...$issues, ...self::supportIssues("{$id}.{$key}", (array) $state['support_refs'], $context, is_string($sceneStates[$shot['scene']] ?? null) ? $sceneStates[$shot['scene']] : null, $people)];

                foreach (array_diff((array) $shot['configurations'][$moment], (array) $state['configurations']) as $configuration) {
                    $issues[] = Finding::requirement('MotoShotsCheck.timelineIssues.11', "{$id}: configurations.{$moment} nêu {$configuration} nhưng {$key} không có vị trí này.");
                }
            }

            $shown = array_values((array) $shot['cards']);
            usort($shown, static fn (array $a, array $b): int => $a['start_ms'] <=> $b['start_ms']);
            $until = 0;

            foreach ($shown as $card) {
                $record = $context['cards'][$card['card']] ?? null;
                $placed[$card['card']][] = $id;

                if ($record === null) {
                    $issues[] = Finding::requirement('MotoShotsCheck.timelineIssues.12', "{$id}: {$card['card']} không có trong text_cards của kịch bản.");

                    continue;
                }

                if (! in_array($record['beat'], $coveredBeats, true)) {
                    $issues[] = Finding::requirement('MotoShotsCheck.timelineIssues.13', "{$id}: {$card['card']} thuộc {$record['beat']}, shot không phủ beat này.");
                } elseif ($record['event'] !== null && ! in_array($record['event'], $covered, true)) {
                    $issues[] = Finding::requirement('MotoShotsCheck.timelineIssues.14', "{$id}: {$card['card']} gắn {$record['event']}, shot không diễn sự kiện này.");
                }

                $fps = is_int($context['card_fps'] ?? null) ? $context['card_fps'] : null;

                if ($fps === null) {
                    $issues[] = Finding::requirement('MotoShotsCheck.timelineIssues.15', "{$id}: lượt chạy không ghim số khung hình mỗi giây của phim — không kiểm được mốc của {$card['card']}.");
                } elseif ((((int) $card['start_ms'] * $fps) % 1000 !== 0 || ((int) $card['end_ms'] * $fps) % 1000 !== 0)) {
                    $issues[] = Finding::requirement('MotoShotsCheck.timelineIssues.16', "{$id}: {$card['card']} {$card['start_ms']}–{$card['end_ms']} ms không rơi đúng khung hình ở {$fps} fps của phim — dùng mốc là bội số của ".MotoShots::frameStepMs($fps).' ms.');
                }

                if ((int) $card['end_ms'] - (int) $card['start_ms'] < self::CARD_MIN_MS) {
                    $issues[] = Finding::requirement('MotoShotsCheck.timelineIssues.17', "{$id}: {$card['card']} hiện ".((int) $card['end_ms'] - (int) $card['start_ms']).' ms; cần ít nhất '.self::CARD_MIN_MS.' ms.');
                }

                if ((int) $card['start_ms'] < $until) {
                    $issues[] = Finding::requirement('MotoShotsCheck.timelineIssues.18', "{$id}: {$card['card']} chồng lên card trước trong shot.");
                }

                $until = max($until, (int) $card['end_ms']);
            }
        }

        foreach (array_keys((array) ($context['cards'] ?? [])) as $card) {
            $count = count($placed[$card] ?? []);

            if ($count !== 1) {
                // A card no shot shows is coverage of the content; a card shown twice cannot be rendered once.
                $issues[] = $count === 0
                    ? Finding::note('MotoShotsCheck.timelineIssues.card_unplaced', "{$card} của kịch bản chưa được đặt vào shot nào.")
                    : Finding::requirement('MotoShotsCheck.timelineIssues.card_twice', "{$card} được đặt vào {$count} shot (".implode(', ', $placed[$card]).'); mỗi card một shot.');
            }
        }

        foreach ((array) ($context['directives'] ?? []) as $directive) {
            foreach ((array) $directive['event_anchors'] as $anchor) {
                $span = ['start_ms' => (int) $anchor['start_ms'], 'end_ms' => (int) $anchor['end_ms']];

                if (! in_array($span, $steps[$anchor['event_id']] ?? [], true)) {
                    $issues[] = Finding::requirement('MotoShotsCheck.timelineIssues.20', "{$directive['id']}: {$anchor['event_id']} phải diễn đúng {$span['start_ms']}–{$span['end_ms']} ms như người dùng đã chốt; không nhịp nào giữ mốc này.");
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
            $issues[] = Finding::requirement('MotoShotsCheck.shotIssues.1', "{$id}: không có công cụ clip nào render {$shot['render_duration_ms']} ms cho khung ".($context['aspect_ratio'] ?? '—').'.');
        } elseif (! in_array($shot['mode'], (array) $capability['modes'], true)) {
            $issues[] = Finding::requirement('MotoShotsCheck.shotIssues.2', "{$id}: không có công cụ nào render {$shot['mode']} ở {$shot['render_duration_ms']} ms.");
        }

        $facts = $state === null ? ['physical' => false] : ((array) ($context['states'][$state] ?? ['physical' => false]));
        $camera = (array) $shot['camera'];
        $parts = array_column((array) $shot['parts'], 'part');

        if ($camera['target'] === 'part' && ! in_array($camera['target_part'], $parts, true)) {
            $issues[] = Finding::requirement('MotoShotsCheck.shotIssues.3', "{$id}: target_part {$camera['target_part']} không có trong parts của shot.");
        }

        foreach ((array) ($shot['work'] ?? []) as $key) {
            $record = $context['work']['items'][$key] ?? null;

            if ($record === null) {
                $issues[] = Finding::requirement('MotoShotsCheck.shotIssues.4', "{$id}: work {$key} không có trong bản chia scene.");
            } elseif (! in_array($record['beat'], array_column($covered, 'id'), true)) {
                $issues[] = Finding::requirement('MotoShotsCheck.shotIssues.5', "{$id}: work {$key} thuộc {$record['beat']}, shot không phủ beat này.");
            } elseif (! in_array($record['part'], $parts, true)) {
                $issues[] = Finding::requirement('MotoShotsCheck.shotIssues.6', "{$id}: work {$key} làm trên {$record['part']} — cần {$record['part']} trong parts của shot.");
            }
        }

        foreach ((array) $shot['parts'] as $item) {
            $part = (string) $item['part'];

            if (! isset($context['parts'][$part])) {
                $issues[] = Finding::requirement('MotoShotsCheck.shotIssues.7', "{$id} nêu {$part}, không có trong parts của thiết kế.");
            }
        }

        $issues = [...$issues, ...self::configurationIssues($shot, $parts, $covered, $facts, $context), ...self::groupingIssues($shot, $covered, $context)];
        $onScreen = array_values(array_unique(array_merge([], ...array_column($covered, 'on_screen'))));

        foreach ((array) $shot['people'] as $person) {
            if (! in_array($person['character'], $onScreen, true)) {
                $issues[] = Finding::note('MotoShotsCheck.shotIssues.8', "{$id}: {$person['character']} không có trên màn hình ở beat nào shot phủ.");
            }
        }

        $environment = (array) $shot['environment'];
        $backgrounds = array_values(array_unique(array_merge([], ...array_column($covered, 'backgrounds'))));

        if ($environment['mode'] === 'background' && ! in_array($environment['background'], $backgrounds, true)) {
            $issues[] = Finding::requirement('MotoShotsCheck.shotIssues.9', "{$id}: {$environment['background']} không phải nền của beat nào shot phủ (".(implode(', ', $backgrounds) ?: 'không có nền').').');
        }

        if ($environment['mode'] === 'unplated' && array_filter($covered, static fn (array $beat): bool => $beat['unplated'] !== []) === []) {
            $issues[] = Finding::requirement('MotoShotsCheck.shotIssues.10', "{$id}: không beat nào shot phủ là unplated — chọn nền của beat hoặc yêu cầu nền bổ sung.");
        }

        if (count((array) $shot['reference_needs']) > (int) $context['reference_limit']) {
            $issues[] = Finding::requirement('MotoShotsCheck.shotIssues.11', "{$id}: ".count((array) $shot['reference_needs'])." nhu cầu tham chiếu, vượt giới hạn {$context['reference_limit']} ảnh của keyframe.");
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
                $issues[] = Finding::requirement('MotoShotsCheck.shotIssues.12', "{$id}: {$problem}");
            }
        }

        $links = (array) $shot['links'];

        if ($links['match_from'] !== null && ! in_array($links['match_from'], $earlier, true)) {
            $issues[] = Finding::requirement('MotoShotsCheck.shotIssues.13', "{$id}: match_from {$links['match_from']} không phải shot đứng trước.");
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
                $issues[] = Finding::note('MotoShotsCheck.omissionIssues.1', "{$label} nêu {$scene}, không có trong bản chia scene.");

                continue;
            }

            $rows = $byScene[$scene] ?? [];
            $at = $omission['before_shot'] === null ? count($rows) : array_search($omission['before_shot'], array_column($rows, 'id'), true);

            if ($at === false) {
                $issues[] = Finding::note('MotoShotsCheck.omissionIssues.2', "{$label}: before_shot {$omission['before_shot']} không phải shot của {$scene}.");

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
                    $issues[] = Finding::note('MotoShotsCheck.omissionIssues.3', "{$label}: {$event} {$problem}.");
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
                    $issues[] = Finding::note('MotoShotsCheck.omissionIssues.4', "{$label}: {$key} {$problem}.");
                }

                $seen['work'][$key] = true;
            }

            foreach ((array) $omission['evidence'] as $evidence) {
                if (($problem = MotoScenesCheck::evidencePathProblem($evidence, (array) $context['input'])) !== null) {
                    $issues[] = Finding::note('MotoShotsCheck.omissionIssues.5', "{$label}: {$problem}");
                }
            }

            $listed = array_values(array_filter((array) $omission['event_ids'], static fn (string $event): bool => isset($index[$event])));
            $inOrder = $listed;
            usort($inOrder, static fn (string $a, string $b): int => $index[$a] <=> $index[$b]);

            if ($listed !== $inOrder) {
                $issues[] = Finding::note('MotoShotsCheck.omissionIssues.6', "{$label}: event_ids ghi ".implode(', ', $listed).'; thứ tự của kịch bản là '.implode(', ', $inOrder).'.');
            }

            $workOrder = array_flip(array_keys((array) ($context['work']['items'] ?? [])));
            $done = array_values(array_filter((array) $omission['work_ids'], static fn (string $key): bool => isset($workOrder[$key])));
            $doneInOrder = $done;
            usort($doneInOrder, static fn (string $a, string $b): int => $workOrder[$a] <=> $workOrder[$b]);

            if ($done !== $doneInOrder) {
                $issues[] = Finding::note('MotoShotsCheck.omissionIssues.7', "{$label}: work_ids ghi ".implode(', ', $done).'; thứ tự của bản chia scene là '.implode(', ', $doneInOrder).'.');
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
                    $issues[] = Finding::requirement('MotoShotsCheck.workLinkIssues.1', "{$label}: {$key} không trỏ tới sự kiện nào của kịch bản — không xác định được nó thuộc thời điểm nào nên không lược được; quay công việc này hoặc ghi xung đột.");
                }

                foreach ($links[$key] as $event) {
                    if (isset($playedBy[$event])) {
                        $issues[] = Finding::requirement('MotoShotsCheck.workLinkIssues.2', "{$label}: {$key} khai làm ngoài hình nhưng sự kiện {$event} của nó được {$playedBy[$event]} diễn — một công việc không thể vừa xong ngoài hình vừa diễn trong shot.");
                    } elseif (! in_array($event, $events, true)) {
                        $issues[] = Finding::requirement('MotoShotsCheck.workLinkIssues.3', "{$label}: {$key} khai làm ngoài hình nhưng sự kiện {$event} của nó không nằm trong cùng mục lược này.");
                    }
                }
            }

            foreach ($links as $key => $linked) {
                if (array_intersect($linked, $events) !== [] && ! in_array($key, $work, true)) {
                    $issues[] = Finding::requirement('MotoShotsCheck.workLinkIssues.4', "{$label}: ".implode(', ', array_values(array_intersect($linked, $events)))." bị lược nhưng công việc {$key} của nó không nằm trong cùng mục lược này.");
                }
            }
        }

        foreach ($shots as $shot) {
            $own = array_merge([], ...array_column((array) $shot['coverage'], 'event_ids'));

            foreach ((array) ($shot['work'] ?? []) as $key) {
                if (($links[$key] ?? []) !== [] && array_intersect($links[$key], $own) === []) {
                    $issues[] = Finding::requirement('MotoShotsCheck.workLinkIssues.5', "{$shot['id']}: thể hiện {$key} nhưng không diễn sự kiện ".implode(', ', $links[$key]).' của nó.');
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
            $issues[] = Finding::requirement('MotoShotsCheck.groupingIssues.1', "{$id} phủ {$names} nhưng environment.mode là ".json_encode($environment['mode'] ?? null).' — shot chạy qua nhiều beat phải đứng trên một nền chung của các beat đó; nếu không có thì tách shot ở ranh giới beat.');
        } elseif (! in_array($environment['background'] ?? null, $shared, true)) {
            $issues[] = Finding::requirement('MotoShotsCheck.groupingIssues.2', "{$id} phủ {$names} nhưng {$environment['background']} không phải nền chung của các beat đó (".(implode(', ', $shared) ?: 'không có nền chung').') — chọn nền chung hoặc tách shot ở ranh giới beat.');
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  list<string>  $parts  the parts in frame
     * @param  list<array<string, mixed>>  $covered  the beats the shot covers
     * @param  array<string, mixed>  $facts  the facts of the scene's build state
     * @param  array<string, mixed>  $context
     * @return list<string> a configuration the design does not have, of a part out of frame or given twice, a part on the motorcycle whose position the beats or the build state set but the shot leaves out, an end configuration the beats do not set, and a configuration other than the one the build state's exception holds. Whether the situation of a beat meets an operational limit is content the reviewer judges: it is not checked here
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
                    $issues[] = Finding::requirement('MotoShotsCheck.configurationIssues.1', "{$id}: cấu hình {$configurationId} không có trong thiết kế.");

                    continue;
                }

                $part = (string) $configuration['part'];

                if (! in_array($part, $parts, true)) {
                    $issues[] = Finding::requirement('MotoShotsCheck.configurationIssues.2', "{$id}: cấu hình {$configurationId} thuộc {$part}, không có trong parts của shot.");
                }

                if (isset($byPart[$moment][$part])) {
                    $issues[] = Finding::requirement('MotoShotsCheck.configurationIssues.3', "{$id}: {$part} có hai cấu hình ở {$moment}.");
                }

                $byPart[$moment][$part] = $configurationId;
            }
        }

        return $issues;
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
                    $issues[] = Finding::note('MotoShotsCheck.conflictIssues.1', "{$label} nêu {$id}, không có trong shots.");
                }
            }

            foreach ((array) $conflict['beats'] as $id) {
                if (! isset($beats[$id])) {
                    $issues[] = Finding::note('MotoShotsCheck.conflictIssues.2', "{$label} nêu {$id}, không có trong kịch bản.");
                }
            }

            foreach ((array) $conflict['evidence'] as $evidence) {
                if (($problem = MotoScenesCheck::evidencePathProblem($evidence, $input)) !== null) {
                    $issues[] = Finding::note('MotoShotsCheck.conflictIssues.3', "{$label}: {$problem}");
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
            ...Finding::fields($issues, $warnings),
            'judge' => self::JUDGE,
        ];
    }
}
