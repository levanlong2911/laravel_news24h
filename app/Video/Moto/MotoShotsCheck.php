<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoShotsCheck
{
    public const VERSION = 'moto-shots-check-v4';

    /** @var string the timing a run pinned from MotoShots@1.2.0 on: code sets the screen time of whole and closing parts and of the shot */
    public const TIMING = 'moto-shots-timing-v1';

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
        if ($response === null) {
            return self::result(self::UNSCORED, null, 'Phản hồi không đọc được JSON.');
        }

        if (($response['format'] ?? null) !== MotoContract::SHOTS_FORMAT) {
            return self::result(self::UNSCORED, null, 'Phản hồi không theo định dạng '.MotoContract::SHOTS_FORMAT.'.');
        }

        if (($context['timing'] ?? null) !== null && $context['timing'] !== self::TIMING) {
            return self::result(self::UNSCORED, null, 'Lượt chạy ghim timing '.json_encode($context['timing']).'; code chỉ tính '.self::TIMING.' hoặc lượt không ghim timing.');
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
        $shots = array_values((array) $data['shots']);
        $conflicts = array_values((array) $data['conflicts']);
        $beats = array_column((array) $context['beats'], null, 'id');
        $scenes = array_column((array) $context['scenes'], null, 'id');
        $order = array_column($shots, 'id');
        $timing = self::timing($shots, $beats, $context['timing'] ?? null);
        $issues = [...$issues, ...self::idIssues($shots, $scenes), ...$timing['issues'], ...self::coverageIssues($shots, $beats, $scenes, (int) $context['total_ms'], $timing['shots'])];
        $warnings = [];
        $seen = [];
        $placed = is_array($context['work'] ?? null) ? MotoWork::shotPlaces(self::workShots($shots), $context['work']) : null;
        $issues = [...$issues, ...($placed['issues'] ?? [])];

        foreach ($shots as $index => $shot) {
            $scene = $scenes[$shot['scene']] ?? null;
            $state = is_array($scene) ? $scene['state'] : null;
            $covered = array_values(array_filter(array_map(static fn (array $item): ?array => $beats[$item['beat']] ?? null, (array) $shot['coverage'])));
            $issues = [...$issues, ...self::shotIssues($shot, array_slice($order, 0, $index), $state, $covered, $context, $placed === null ? null : ($placed['shots'][$shot['id']] ?? []))];
            $warnings = [...$warnings, ...self::shotWarnings($shot, $state, $seen), ...self::surfaceWarnings($shot, $state, $context)];
            $seen[] = ['id' => $shot['id'], 'scene' => $shot['scene'], 'fingerprint' => MotoShotPlanner::fingerprint($shot, $state)];
        }

        if (is_array($context['work'] ?? null)) {
            $shown = array_merge([], ...array_map(static fn (array $shot): array => (array) ($shot['work'] ?? []), $shots));

            foreach ((array) $context['work']['items'] as $key => $item) {
                if (! in_array($key, $shown, true)) {
                    $issues[] = "{$key} ({$item['beat']} {$item['part']} {$item['operation']}) không có shot nào thể hiện.";
                }
            }
        }

        $issues = [...$issues, ...self::conflictIssues($conflicts, $order, $beats, (array) $context['input'])];
        $blocking = array_filter($conflicts, static fn (array $conflict): bool => (bool) $conflict['blocking']) !== [];

        if ($status === 'ok' && $blocking) {
            $issues[] = 'Còn xung đột chặn nhưng status là ok.';
        }

        if ($status === 'partial' && ! $blocking) {
            $issues[] = 'Status partial nhưng không còn xung đột chặn nào.';
        }

        return self::result($issues === [] ? self::PASS : self::FAIL, $status, null, [], $issues, $warnings);
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
                if (! in_array($item['presentation'], ['drawing', 'model'], true)) {
                    $issues[] = "{$id}: ".($state ?? 'state null')." chưa có xe thật — {$part} chỉ thể hiện bằng bản vẽ hoặc mô hình.";
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

        $issues = [...$issues, ...self::configurationIssues($shot, $parts, $covered, $facts, $context)];
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
     * @return list<array{id: string, work: list<string>, beats: list<string>}> each shot with the work items it shows and the beats it covers, as MotoWork::shotPlaces() reads them
     */
    public static function workShots(array $shots): array
    {
        return array_map(static fn (array $shot): array => [
            'id' => (string) $shot['id'],
            'work' => array_values(array_map('strval', (array) ($shot['work'] ?? []))),
            'beats' => array_values(array_map('strval', array_column((array) $shot['coverage'], 'beat'))),
        ], $shots);
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

        foreach ($byPart['end'] ?? [] as $part => $configurationId) {
            $set = array_values(array_filter($listed, static fn (string $listedId): bool => $partOf($listedId) === $part));

            if ($set !== [] && ! in_array($configurationId, $set, true)) {
                $issues[] = "{$id}: cuối shot {$part} ở {$configurationId}; beat chỉ nêu ".implode(', ', $set).'.';
            }
        }

        $moments = ['start' => $covered[0] ?? null, 'end' => $covered === [] ? null : $covered[count($covered) - 1]];

        foreach ($moments as $moment => $beat) {
            foreach ($byPart[$moment] ?? [] as $configurationId) {
                foreach ((array) ($context['limits'] ?? []) as $limit) {
                    if (! in_array($configurationId, (array) $limit['governs'], true)) {
                        continue;
                    }

                    $unmet = MotoScriptCheck::unmet($limit, is_array($beat) ? $beat['situation'] : null);

                    if ($unmet !== []) {
                        $issues[] = "{$id}: {$configurationId} ở {$moment} chịu {$limit['id']} nhưng tình huống của ".(is_array($beat) ? $beat['id'] : 'beat')." chưa đạt: ".implode(', ', $unmet).'.';
                    }
                }
            }
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
     * @return list<string> at a build state of an unfinished motorcycle, where the keyframe prompt sends materials instead of finishes: a state with no surface of its own, and a part in frame whose description or faces name, word for word, a finish or a colour its design gives the finished part; the prompt still sends them verbatim, for the reviewer to weigh. Only word-for-word matches are found: not every mismatch between a part's text and its stage, and not a part whose own surface the state's groups (frame, bodywork, engine, wheels) leave unsaid
     */
    private static function surfaceWarnings(array $shot, ?string $state, array $context): array
    {
        if (MotoShotPrompt::finishShown($state, $context)) {
            return [];
        }

        $facts = (array) $context['states'][$state];
        $warnings = [];

        if (! is_string($facts['surface'] ?? null) && array_filter((array) ($facts['finishes'] ?? []), 'is_string') === []) {
            $warnings[] = "{$shot['id']}: {$state} không có mô tả bề mặt của giai đoạn (surface, surfaces) — prompt ảnh không nói được bề mặt hiện tại.";
        }

        foreach ((array) $shot['parts'] as $item) {
            $record = (array) ($context['part_records'][$item['part']] ?? []);
            $text = implode(' ', [(string) ($record['description'] ?? ''), ...array_map(static fn (array $face): string => (string) $face['description'], (array) ($record['faces'] ?? []))]);
            $finishes = [];

            foreach ((array) ($record['surfaces'] ?? []) as $surface) {
                $colour = $context['palette'][(string) ($surface['color_id'] ?? '')] ?? null;
                $finishes = [...$finishes, (string) $surface['finish'], ...(is_array($colour) ? [(string) $colour['name']] : [])];
            }

            $named = array_values(array_unique(array_filter($finishes, static fn (string $finish): bool => trim($finish) !== '' && mb_stripos($text, $finish) !== false)));

            if ($named !== []) {
                $warnings[] = "{$shot['id']}: {$item['part']} — description/faces nêu lớp hoàn thiện cuối \"".implode('", "', $named)."\" ở {$state}, khi xe chưa hoàn thiện; prompt gửi nguyên văn, người duyệt xem lại.";
            }
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
