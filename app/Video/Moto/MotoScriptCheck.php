<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoScriptCheck
{
    public const VERSION = 'moto-script-check-v2';

    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNSCORED = 'UNSCORED';

    public const JUDGE = 'not_run';

    /** @var array<string, int> */
    private const ACTS = ['hook' => 0, 'idea' => 1, 'fabrication' => 2, 'finish' => 3, 'experience' => 4, 'hero' => 5, 'ending' => 6];

    /** @var array<string, array{0: int, 1: int}> the build states each act of the main progress may show */
    private const ACT_STATES = ['idea' => [1, 5], 'fabrication' => [6, 10], 'finish' => [9, 11], 'experience' => [11, 12], 'hero' => [11, 12]];

    /** @var list<string> */
    private const DENSITY_TYPES = ['STAGE', 'SPEC', 'NAME', 'BASE'];

    /** @var array<string, list<string>> each design unit with the ways a card may write it */
    private const UNITS = [
        'mm' => ['mm'],
        'deg' => ['°', 'deg', 'degree', 'degrees'],
        'kg' => ['kg'],
        'cc' => ['cc'],
        'l' => ['l', 'litre', 'litres', 'liter', 'liters'],
        'in' => ['in', 'inch', 'inches', '″', '"'],
        'hp' => ['hp'],
        'nm' => ['nm', 'n·m', 'n-m'],
    ];

    private const FIRST_BUILT_STATE = 6;

    private const LAST_STATE = 12;

    private const EPSILON = 0.0001;

    /**
     * @param  ?array<string, mixed>  $response
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @param  list<array{code: string, path: string, message: string}>  $schema
     * @param  array<string, mixed>  $policy  video.moto.script.check
     * @return array{version: string, format: string, verdict: string, status: ?string, error: ?string, schema: list<string>, issues: list<string>, warnings: list<string>, judge: string}
     */
    public static function evaluate(?array $response, array $context, array $schema, array $policy): array
    {
        if ($response === null) {
            return self::result(self::UNSCORED, null, 'Phản hồi không đọc được JSON.');
        }

        if (($response['format'] ?? null) !== MotoContract::SCRIPT_FORMAT) {
            return self::result(self::UNSCORED, null, 'Phản hồi không theo định dạng '.MotoContract::SCRIPT_FORMAT.'.');
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
        $warnings = [];

        if (($response['project_id'] ?? null) !== ($context['project_id'] ?? null)) {
            $issues[] = 'project_id không khớp lượt chạy.';
        }

        if (($response['prompt_version'] ?? null) !== ($context['prompt_version'] ?? null)) {
            $issues[] = 'prompt_version là "'.($response['prompt_version'] ?? '').'"; lượt chạy này dùng '.($context['prompt_version'] ?? '').'.';
        }

        $data = (array) $response['data'];
        $design = (array) ($context['design'] ?? []);
        $beats = array_values((array) $data['beats']);
        $byId = [];

        foreach ($beats as $index => $beat) {
            $expected = 'B'.($index + 1);

            if ($beat['id'] !== $expected) {
                $issues[] = "Beat thứ ".($index + 1)." phải là {$expected}, không phải {$beat['id']}.";
            }

            $byId[$beat['id']] = $index;
        }

        $total = array_sum(array_map(static fn (array $beat): float => (float) $beat['duration_s'], $beats));
        $length = (float) ($context['video_length'] ?? 0);
        $advisory = [
            ...(abs($total - $length) > (float) ($policy['length_tolerance_sec'] ?? 0.1) + self::EPSILON ? ['Các beat dài tổng '.self::s($total)." s; phim phải dài {$length} s."] : []),
            ...self::progressIssues($beats),
            ...self::heroIssues($data, $beats, $byId),
            ...self::designIssues($beats, $design),
            ...self::cardIssues((array) $data['text_cards'], $beats, $byId, $design, $context, $policy, $total),
        ];
        $issues = [...$issues, ...self::referenceIssues($data, $byId)];

        if ((bool) ($policy['cover_build_states'] ?? false)) {
            $issues = [...$issues, ...self::coverageIssues($beats, $design)];
            $warnings = [...$advisory, ...self::heroMomentWarnings($data, $beats, $byId, $design)];
        } else {
            $issues = [...$issues, ...$advisory];
        }

        foreach ($beats as $index => $beat) {
            if ((float) $beat['duration_s'] < (float) ($policy['warn']['beat_min_sec'] ?? 0.8)) {
                $warnings[] = "{$beat['id']} chỉ dài ".self::s((float) $beat['duration_s']).' s; khó đọc kịp một hành động.';
            }

            if ($index > 0 && $beat['link'] === null) {
                $warnings[] = "{$beat['id']} không có link nối với beat trước.";
            }
        }

        $hero = $byId[$data['story']['hero_beat']] ?? null;

        if ($hero !== null && (float) $beats[$hero]['duration_s'] < (float) ($policy['warn']['hero_min_sec'] ?? 2)) {
            $warnings[] = 'Beat hero chỉ dài '.self::s((float) $beats[$hero]['duration_s']).' s.';
        }

        return self::result($issues === [] ? self::PASS : self::FAIL, $status, null, [], $issues, $warnings);
    }

    /**
     * @param  array<string, mixed>  $design  the design selected for the film
     * @return array<string, mixed> the design facts the check reads, pinned with the run
     */
    public static function facts(array $design): array
    {
        $parts = [];
        $configurations = [];

        foreach ((array) ($design['parts'] ?? []) as $part) {
            $parts[$part['id']] = ['present' => (bool) $part['present'], 'from' => $part['from_state'] ?? null, 'until' => $part['until_state'] ?? null];

            foreach ((array) ($part['configurations'] ?? []) as $configuration) {
                $configurations[$configuration['id']] = $part['id'];
            }
        }

        $states = [];

        for ($index = self::FIRST_BUILT_STATE; $index <= self::LAST_STATE; $index++) {
            $states[sprintf('BS%02d', $index)] = [
                'present' => array_fill_keys(array_keys(array_filter($parts, static fn (array $part): bool => self::lifespan($part, $index))), true),
                'in_progress' => [],
                'pinned' => [],
            ];
        }

        foreach ((array) ($design['build_states'] ?? []) as $buildState) {
            if (! isset($states[$buildState['id']])) {
                continue;
            }

            $states[$buildState['id']]['in_progress'] = array_values(array_column((array) ($buildState['in_progress'] ?? []), 'part'));

            foreach ((array) ($buildState['exceptions'] ?? []) as $exception) {
                if ($exception['presence'] === 'removed') {
                    unset($states[$buildState['id']]['present'][$exception['part']]);
                } else {
                    $states[$buildState['id']]['present'][$exception['part']] = true;
                }

                if (is_string($exception['configuration'] ?? null)) {
                    $states[$buildState['id']]['pinned'][$exception['part']] = $exception['configuration'];
                }
            }
        }

        $specs = [];

        foreach ((array) ($design['motorcycle']['specs'] ?? []) as $key => $spec) {
            if (is_array($spec) && array_key_exists('value', $spec)) {
                $specs[$key] = ['value' => $spec['value'], 'unit' => $spec['unit'] ?? null, 'basis' => $spec['basis'] ?? null];
            }
        }

        return [
            'name' => $design['motorcycle']['name'] ?? null,
            'base' => ['family' => $design['motorcycle']['base']['family'] ?? null, 'model' => $design['motorcycle']['base']['model'] ?? null],
            'specs' => $specs,
            'parts' => $parts,
            'configurations' => $configurations,
            'states' => array_map(static fn (array $state): array => ['present' => array_keys($state['present'])] + $state, $states),
            'systems' => array_map(static fn (array $system): array => ['parts' => (array) $system['parts']], array_column((array) ($design['systems'] ?? []), null, 'id')),
            'features' => array_map(static fn (array $feature): array => [
                'from' => $feature['from_state'] ?? null,
                'parts' => (array) $feature['parts'],
                'modes' => array_values(array_map(static fn (array $mode): array => (array) $mode['configurations'], (array) ($feature['modes'] ?? []))),
            ], array_column((array) ($design['features'] ?? []), null, 'id')),
            'moments' => array_map(static fn (array $moment): array => [
                'hero' => (bool) ($moment['hero'] ?? false),
                'parts' => (array) $moment['parts'],
                'configurations' => (array) $moment['configurations'],
                'limits' => (array) $moment['limits'],
            ], array_column((array) ($design['motorcycle']['signature_moments'] ?? []), null, 'id')),
            'limits' => array_map(static fn (array $limit): array => [
                'id' => $limit['id'],
                'applies_to' => (array) ($limit['applies_to'] ?? []),
                'governs' => (array) ($limit['governs'] ?? []),
                'requires' => (array) ($limit['requires'] ?? []),
                'conditions' => (array) ($limit['conditions'] ?? []),
                'procedures' => (array) ($limit['procedures'] ?? []),
            ], (array) ($design['operational_limits'] ?? [])),
            'build_states' => array_map(static fn (array $state): array => ['id' => $state['id'], 'refs' => array_values((array) ($state['refs'] ?? []))], (array) ($design['build_states'] ?? [])),
            'other_refs' => array_values(array_column([
                ...(array) ($design['connections'] ?? []),
                ...array_merge([], ...array_map(static fn (array $system): array => (array) ($system['lines'] ?? []), (array) ($design['systems'] ?? []))),
                ...(array) ($design['identity_rules'] ?? []),
            ], 'id')),
        ];
    }

    /**
     * @param  array<string, mixed>  $design  the design facts of facts()
     * @return list<string> the parts the beats of a physical build state must name: those being made, or those that first appear there when none is being made
     */
    public static function mustName(array $design, string $state): array
    {
        $making = (array) $design['states'][$state]['in_progress'];

        if ($making !== []) {
            return $making;
        }

        return array_values(array_filter((array) $design['states'][$state]['present'], static fn (string $part): bool => ($design['parts'][$part]['from'] ?? null) === $state));
    }

    /**
     * @param  array<string, mixed>  $design  the design facts of facts()
     * @return array{acts: array<string, array{0: string, 1: string}>, states: array<string, array{on_motorcycle: list<string>, in_progress: list<string>, must_name: list<string>, pinned: array<string, string>, features_complete: list<string>, moments_with_parts: list<string>}>, limits: array<string, array{requires: list<string>, conditions: list<array<string, mixed>>, procedures: list<string>}>, configuration_limits: array<string, list<string>>, in_use_limits: array<string, list<string>>, moments: array<string, array{parts: list<string>, configurations: list<string>, limits: list<string>}>, spec_cards: array<string, array{source_ref: string, value: int|float|string, unit_forms: list<string>, must_say: string}>} the facts a beat needs, worked out by the rules this check applies
     */
    public static function filmFacts(array $design): array
    {
        [$governing, $subjects] = self::limitIndex((array) $design['limits']);
        $limits = [];

        foreach ((array) $design['limits'] as $limit) {
            $limits[$limit['id']] = ['requires' => $limit['requires'], 'conditions' => $limit['conditions'], 'procedures' => $limit['procedures']];
        }

        $states = [];

        foreach ((array) $design['states'] as $id => $state) {
            $index = (int) self::stateIndex($id);
            $states[$id] = [
                'on_motorcycle' => $state['present'],
                'in_progress' => $state['in_progress'],
                'must_name' => self::mustName($design, (string) $id),
                'pinned' => $state['pinned'],
                'features_complete' => array_keys(array_filter((array) $design['features'], static fn (array $feature): bool => self::featureComplete($feature, $index))),
                'moments_with_parts' => array_keys(array_filter((array) $design['moments'], static fn (array $moment): bool => array_diff($moment['parts'], $state['present']) === [])),
            ];
        }

        $records = [
            ...array_fill_keys(array_keys((array) $design['parts']), []),
            ...array_map(static fn (array $system): array => $system['parts'], (array) $design['systems']),
            ...array_map(static fn (array $feature): array => $feature['parts'], (array) $design['features']),
        ];
        $inUse = [];

        foreach ($records as $id => $parts) {
            $ids = array_keys(self::useSubjects((string) $id, $parts, $subjects));

            if ($ids !== []) {
                $inUse[$id] = $ids;
            }
        }

        $moments = [];

        foreach ((array) $design['moments'] as $id => $moment) {
            $moments[$id] = [
                'parts' => $moment['parts'],
                'configurations' => $moment['configurations'],
                'limits' => array_values(array_unique([...$moment['limits'], ...array_merge([], ...array_map(static fn (string $configuration): array => $governing[$configuration] ?? [], $moment['configurations']))])),
            ];
        }

        $cards = [];

        foreach ((array) $design['specs'] as $key => $spec) {
            $forms = is_string($spec['value']) ? [] : self::unitForms($spec['unit']);

            if ($spec['value'] === null || (! is_string($spec['value']) && $forms === [])) {
                continue;
            }

            $cards[$key] = ['source_ref' => "motorcycle.specs.{$key}", 'value' => $spec['value'], 'unit_forms' => $forms, 'must_say' => self::marker($spec)];
        }

        return [
            'acts' => array_map(static fn (array $range): array => [sprintf('BS%02d', $range[0]), sprintf('BS%02d', $range[1])], self::ACT_STATES),
            'states' => $states,
            'limits' => $limits,
            'configuration_limits' => $governing,
            'in_use_limits' => $inUse,
            'moments' => $moments,
            'spec_cards' => $cards,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $beats
     * @param  array<string, mixed>  $design  the design facts of facts()
     * @return list<string> the build states without a beat in the main progress and the ids their beats leave out or may not name: exactly the refs of a design state; every id a physical state must name, and others only as that state allows
     */
    private static function coverageIssues(array $beats, array $design): array
    {
        $issues = [];

        foreach ((array) ($design['build_states'] ?? []) as $state) {
            $id = (string) $state['id'];
            $main = array_values(array_filter($beats, static fn (array $beat): bool => $beat['state'] === $id && $beat['time_jump'] === 'none' && ! in_array($beat['act'], ['hook', 'ending'], true)));

            if ($main === []) {
                $issues[] = "Phim chưa có beat ở mạch chính cho {$id}.";

                continue;
            }

            $named = array_values(array_unique(array_merge(...array_map(static fn (array $beat): array => (array) $beat['design_refs'], $main))));
            $physical = isset($design['states'][$id]);
            $required = $physical ? self::mustName($design, $id) : (array) $state['refs'];
            $missing = array_values(array_diff($required, $named));
            $extra = array_values(array_filter($named, static fn (string $ref): bool => $physical ? ! self::namableAt($design, $id, $ref) : ! in_array($ref, $required, true)));

            if ($missing !== [] || $extra !== []) {
                $issues[] = "{$id} ".implode('; ', array_filter([
                    $missing === [] ? null : 'thiếu '.implode(', ', $missing),
                    $extra === [] ? null : 'thừa '.implode(', ', $extra),
                ])).'.';
            }
        }

        return $issues;
    }

    /** @param  array<string, mixed>  $design  the design facts of facts() */
    private static function namableAt(array $design, string $state, string $ref): bool
    {
        $at = $design['states'][$state];
        $allPresent = static fn (array $parts): bool => array_diff($parts, (array) $at['present']) === [];

        return match ($ref[0]) {
            'P' => in_array($ref, (array) $at['present'], true) || in_array($ref, (array) $at['in_progress'], true),
            'F' => isset($design['features'][$ref]) && self::featureComplete($design['features'][$ref], (int) self::stateIndex($state)),
            'M' => isset($design['moments'][$ref]) && $allPresent((array) $design['moments'][$ref]['parts']),
            'S' => isset($design['systems'][$ref]) && $allPresent((array) $design['systems'][$ref]['parts']),
            default => false,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $beats
     * @return list<string>
     */
    private static function progressIssues(array $beats): array
    {
        $issues = [];
        $act = -1;
        $progress = 0;

        foreach ($beats as $beat) {
            $order = self::ACTS[$beat['act']];

            if ($order < $act) {
                $issues[] = "{$beat['id']} thuộc hồi {$beat['act']}, đứng sau một hồi muộn hơn.";
            }

            $act = max($act, $order);
            $state = self::stateIndex($beat['state']);

            if ($state === null) {
                continue;
            }

            if ($beat['time_jump'] === 'none') {
                $range = self::ACT_STATES[$beat['act']] ?? null;

                if ($range !== null && ($state < $range[0] || $state > $range[1])) {
                    $issues[] = "{$beat['id']} ở hồi {$beat['act']} nhưng dùng {$beat['state']}; hồi này dùng BS".sprintf('%02d', $range[0]).'–BS'.sprintf('%02d', $range[1]).'.';
                }

                if ($state < $progress) {
                    $issues[] = "{$beat['id']} lùi về {$beat['state']} mà không đánh dấu time_jump.";
                }

                $progress = max($progress, $state);
            } elseif ($beat['time_jump'] === 'flash_forward' && $state < $progress) {
                $issues[] = "{$beat['id']} đánh dấu flash_forward nhưng {$beat['state']} không ở sau tiến trình hiện tại.";
            } elseif ($beat['time_jump'] === 'flashback' && $state > $progress) {
                $issues[] = "{$beat['id']} đánh dấu flashback nhưng {$beat['state']} chưa diễn ra.";
            }
        }

        if (! in_array('hero', array_column($beats, 'act'), true)) {
            $issues[] = 'Phim không có beat nào thuộc hồi hero.';
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $beats
     * @param  array<string, int>  $byId
     * @return list<string>
     */
    private static function heroIssues(array $data, array $beats, array $byId): array
    {
        $heroId = $data['story']['hero_beat'];
        $hero = $byId[$heroId] ?? null;

        if ($hero === null) {
            return ["story.hero_beat {$heroId} không có trong beats."];
        }

        $issues = [];

        if ($beats[$hero]['act'] !== 'hero' || $beats[$hero]['reveal'] !== 'whole') {
            $issues[] = "story.hero_beat {$heroId} phải thuộc hồi hero và có reveal whole.";
        }

        foreach ($beats as $index => $beat) {
            if ($index < $hero && $beat['reveal'] === 'whole') {
                $issues[] = "{$beat['id']} đã lộ toàn xe trước beat hero {$heroId}.";
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $beats
     * @param  array<string, int>  $byId
     * @param  array<string, mixed>  $design  the design facts of facts()
     * @return list<string> the hero beat that does not name the moment the design marks as its hero
     */
    private static function heroMomentWarnings(array $data, array $beats, array $byId, array $design): array
    {
        $heroId = $data['story']['hero_beat'];
        $hero = $byId[$heroId] ?? null;
        $moments = array_keys(array_filter((array) ($design['moments'] ?? []), static fn (array $moment): bool => (bool) ($moment['hero'] ?? false)));

        if ($hero === null || $moments === [] || array_intersect($moments, (array) $beats[$hero]['design_refs']) !== []) {
            return [];
        }

        return ["story.hero_beat {$heroId} không diễn moment hero ".implode(', ', $moments).' của thiết kế.'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, int>  $byId
     * @return list<string>
     */
    private static function referenceIssues(array $data, array $byId): array
    {
        $issues = [];

        foreach ((array) $data['story']['loops'] as $loop) {
            if (! isset($byId[$loop['plant']], $byId[$loop['payoff']])) {
                $issues[] = "Loop \"{$loop['motif']}\" nhắc beat không có trong beats.";
            } elseif ($byId[$loop['plant']] >= $byId[$loop['payoff']]) {
                $issues[] = "Loop \"{$loop['motif']}\": payoff {$loop['payoff']} phải đến sau plant {$loop['plant']}.";
            }
        }

        foreach ((array) $data['story']['obstacles'] as $obstacle) {
            if (! isset($byId[$obstacle['problem_beat']], $byId[$obstacle['solution_beat']])) {
                $issues[] = "Trở ngại {$obstacle['id']} nhắc beat không có trong beats.";
            } elseif ($byId[$obstacle['problem_beat']] > $byId[$obstacle['solution_beat']]) {
                $issues[] = "Trở ngại {$obstacle['id']}: giải pháp {$obstacle['solution_beat']} đến trước vấn đề {$obstacle['problem_beat']}.";
            }
        }

        foreach ((array) $data['music_brief']['hits'] as $hit) {
            if (! isset($byId[$hit['beat']])) {
                $issues[] = "Điểm nhấn nhạc {$hit['moment']} nhắc {$hit['beat']}, không có trong beats.";
            }
        }

        return $issues;
    }

    /**
     * @param  list<array<string, mixed>>  $beats
     * @param  array<string, mixed>  $design
     * @return list<string>
     */
    private static function designIssues(array $beats, array $design): array
    {
        $issues = [];
        $records = [
            'P' => (array) ($design['parts'] ?? []),
            'S' => (array) ($design['systems'] ?? []),
            'F' => (array) ($design['features'] ?? []),
            'M' => (array) ($design['moments'] ?? []),
        ];
        $limits = array_column((array) ($design['limits'] ?? []), null, 'id');
        [$governing, $subjects] = self::limitIndex(array_values($limits));

        foreach ($beats as $beat) {
            $state = self::stateIndex($beat['state']);
            $at = $state !== null && $state >= self::FIRST_BUILT_STATE ? ($design['states'][$beat['state']] ?? null) : null;
            $present = static fn (string $part): bool => $at === null || in_array($part, $at['present'], true);
            $missing = static fn (array $list): array => array_values(array_filter($list, static fn (string $part): bool => ! $present($part)));
            $checks = [];

            foreach ($beat['design_refs'] as $ref) {
                $record = $records[$ref[0]][$ref] ?? null;

                if ($record === null) {
                    if (! in_array($ref, (array) ($design['other_refs'] ?? []), true)) {
                        $issues[] = "{$beat['id']} nhắc {$ref}, không có trong thiết kế.";
                    }
                } elseif ($ref[0] === 'P' && $at !== null && ! $present($ref) && ! in_array($ref, $at['in_progress'], true)) {
                    $issues[] = "{$beat['id']} dùng {$ref} ở {$beat['state']}, khi bộ phận này chưa có, đã tháo hoặc không đang làm.";
                } elseif ($ref[0] === 'F' && $at !== null && ! self::featureComplete($record, (int) $state)) {
                    $issues[] = "{$beat['id']} dùng {$ref} ở {$beat['state']}, trước khi tính năng hoàn thiện ở {$record['from']}.";
                } elseif ($ref[0] === 'M') {
                    if (($gone = $missing($record['parts'])) !== []) {
                        $issues[] = "{$beat['id']} diễn {$ref} ở {$beat['state']} nhưng thiếu ".implode(', ', $gone).'.';
                    }

                    $checks += array_fill_keys($record['limits'], $ref);
                }
            }

            foreach ($beat['in_use'] as $ref) {
                $record = $records[$ref[0]][$ref] ?? null;

                if (! in_array($ref, $beat['design_refs'], true)) {
                    $issues[] = "{$beat['id']}: {$ref} trong in_use phải có trong design_refs.";

                    continue;
                }

                if ($record === null) {
                    continue;
                }

                if (($gone = $missing($ref[0] === 'P' ? [$ref] : $record['parts'])) !== []) {
                    $issues[] = "{$beat['id']} cho {$ref} hoạt động ở {$beat['state']} nhưng thiếu ".implode(', ', $gone).'.';
                }

                $modes = $ref[0] === 'F' ? $record['modes'] : [];

                if ($modes !== [] && array_filter($modes, static fn (array $mode): bool => array_diff($mode, $beat['configurations']) === []) === []) {
                    $issues[] = "{$beat['id']} cho {$ref} hoạt động nhưng không đặt đủ cấu hình của một chế độ nào của nó.";
                }

                foreach (self::useSubjects($ref, $ref[0] === 'P' ? [] : $record['parts'], $subjects) as $id => $subject) {
                    $checks += [$id => $subject === $ref ? $ref : "{$ref} ({$subject})"];
                }
            }

            $owners = [];

            foreach ($beat['configurations'] as $configuration) {
                $owner = $design['configurations'][$configuration] ?? null;

                if ($owner === null) {
                    $issues[] = "{$beat['id']} dùng cấu hình {$configuration}, không có trong thiết kế.";

                    continue;
                }

                if (isset($owners[$owner])) {
                    $issues[] = "{$beat['id']} đặt hai vị trí cho {$owner}.";
                }

                $owners[$owner] = $configuration;
                $pinned = $at['pinned'][$owner] ?? null;

                if (! $present($owner)) {
                    $issues[] = "{$beat['id']} dùng cấu hình {$configuration} ở {$beat['state']}, khi {$owner} chưa có hoặc đã tháo.";
                } elseif ($pinned !== null && $pinned !== $configuration) {
                    $issues[] = "{$beat['id']} đặt {$configuration} ở {$beat['state']}, khi giai đoạn này giữ {$owner} ở {$pinned}.";
                }

                $checks = array_fill_keys($governing[$configuration] ?? [], $configuration) + $checks;
            }

            foreach ($beat['design_refs'] as $ref) {
                foreach ($ref[0] === 'M' ? ($records['M'][$ref]['configurations'] ?? []) : [] as $configuration) {
                    if (in_array($configuration, $beat['configurations'], true)) {
                        continue;
                    }

                    $owner = $design['configurations'][$configuration] ?? null;
                    $issues[] = $owner !== null && isset($owners[$owner])
                        ? "{$beat['id']} diễn {$ref} nhưng đặt {$owners[$owner]} thay cho {$configuration}."
                        : "{$beat['id']} diễn {$ref} nhưng không đặt cấu hình {$configuration} của nó.";
                }
            }

            foreach ($checks as $id => $use) {
                foreach (isset($limits[$id]) ? self::unmet($limits[$id], $beat['situation']) : [] as $condition) {
                    $issues[] = "{$beat['id']} dùng {$use} nhưng tình huống chưa đạt {$id}: {$condition}.";
                }
            }

            $situation = $beat['situation'];

            if (is_array($situation) && $situation['mode'] === 'riding' && ($situation['engine'] !== 'running' || $situation['side_stand'] === 'down')) {
                $issues[] = "{$beat['id']} đang chạy xe nhưng máy không nổ hoặc chân chống đang hạ.";
            }
        }

        return $issues;
    }

    /**
     * @param  array{requires: list<string>, conditions: list<array{quantity: string, comparator: string, value: int|float}>, procedures: list<string>}  $limit
     * @param  ?array<string, mixed>  $situation
     * @return list<string> the conditions the situation does not establish as met
     */
    public static function unmet(array $limit, ?array $situation): array
    {
        if ($situation === null) {
            return ['beat không khai tình huống'];
        }

        $unmet = [];
        $met = [
            'stationary' => ($situation['speed_kmh'] ?? null) !== null && (float) $situation['speed_kmh'] === 0.0,
            'engine_off' => ($situation['engine'] ?? null) === 'off',
            'engine_running' => ($situation['engine'] ?? null) === 'running',
            'side_stand_down' => ($situation['side_stand'] ?? null) === 'down',
            'dry_road' => ($situation['dry_road'] ?? null) === true,
        ];

        foreach ($limit['requires'] as $requirement) {
            if (! ($met[$requirement] ?? false)) {
                $unmet[] = $requirement;
            }
        }

        foreach ($limit['conditions'] as $condition) {
            $value = $situation[$condition['quantity'] === 'speed' ? 'speed_kmh' : 'lean_angle_deg'] ?? null;
            $holds = $value !== null && ($condition['comparator'] === '<' ? (float) $value < (float) $condition['value'] : (float) $value <= (float) $condition['value']);

            if (! $holds) {
                $unmet[] = "{$condition['quantity']} {$condition['comparator']} {$condition['value']}";
            }
        }

        foreach ($limit['procedures'] as $procedure) {
            if (! in_array($procedure, (array) ($situation['procedures'] ?? []), true)) {
                $unmet[] = $procedure;
            }
        }

        return $unmet;
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @param  list<array<string, mixed>>  $beats
     * @param  array<string, int>  $byId
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $policy
     * @return list<string>
     */
    private static function cardIssues(array $cards, array $beats, array $byId, array $design, array $context, array $policy, float $total): array
    {
        $issues = [];
        $byBeat = [];
        $types = array_count_values(array_column($cards, 'type'));

        foreach ($cards as $card) {
            $index = $byId[$card['beat']] ?? null;

            if ($index === null) {
                $issues[] = "Card \"{$card['text']}\" ở {$card['beat']}, không có trong beats.";

                continue;
            }

            if ((float) $card['offset_s'] + (float) $card['sec'] > (float) $beats[$index]['duration_s'] + self::EPSILON) {
                $issues[] = "Card \"{$card['text']}\" vượt ra ngoài {$card['beat']}.";
            }

            $issues = [...$issues, ...self::sourceIssues($card, $design)];
            $byBeat[$card['beat']][] = $card;
        }

        foreach ($byBeat as $beat => $list) {
            usort($list, static fn (array $a, array $b): int => $a['offset_s'] <=> $b['offset_s']);

            for ($i = 1; $i < count($list); $i++) {
                if ((float) $list[$i - 1]['offset_s'] + (float) $list[$i - 1]['sec'] > (float) $list[$i]['offset_s'] + self::EPSILON) {
                    $issues[] = "Hai card chồng lên nhau ở {$beat}.";
                }
            }
        }

        $counted = array_sum(array_map(static fn (string $type): int => $types[$type] ?? 0, self::DENSITY_TYPES));
        $allowed = max(1, (int) floor($total / max(1, (int) ($policy['card_seconds_per_card'] ?? 10))));

        if ($counted > $allowed) {
            $issues[] = "{$counted} card STAGE/SPEC/NAME/BASE cho phim ".self::s($total)." s; tối đa {$allowed}.";
        }

        foreach (['DISCLAIMER', 'END'] as $type) {
            if (($types[$type] ?? 0) === 0) {
                $issues[] = "Thiếu card {$type}.";
            }
        }

        if (($types['BASE'] ?? 0) > 0 && ! (bool) ($context['base_card'] ?? false)) {
            $issues[] = 'Brief không cho card BASE.';
        }

        if (($types['BASE'] ?? 0) > 1) {
            $issues[] = 'Card BASE chỉ được xuất hiện một lần.';
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $design
     * @return list<string> a SPEC or BASE card whose source_ref points at nothing in the design
     */
    private static function sourceIssues(array $card, array $design): array
    {
        $ref = $card['source_ref'];

        if ($ref === null) {
            return [];
        }

        $path = explode('.', (string) $ref);

        if ($card['type'] === 'SPEC') {
            $spec = ($path[1] ?? null) === 'specs' && isset($path[2]) ? ($design['specs'][$path[2]] ?? null) : null;

            return $spec === null || $spec['value'] === null ? ["Card SPEC \"{$card['text']}\" trỏ {$ref}, không có giá trị trong thiết kế."] : [];
        }

        if ($card['type'] === 'BASE') {
            $value = ($path[1] ?? null) === 'base' && in_array($path[2] ?? null, ['family', 'model'], true) ? ($design['base'][$path[2]] ?? null) : null;

            return is_string($value) ? [] : ["Card BASE \"{$card['text']}\" trỏ {$ref}, không phải family hoặc model của xe nền."];
        }

        return [];
    }

    /** @param array{present: bool, from: ?string, until: ?string} $part */
    private static function lifespan(array $part, int $state): bool
    {
        $from = self::stateIndex($part['from']);
        $until = self::stateIndex($part['until']);

        return $part['present'] && $from !== null && $from <= $state && ($until === null || $state <= $until);
    }

    /**
     * @param  list<array{id: string, applies_to: array<string, mixed>, governs: list<string>}>  $limits
     * @return array{0: array<string, list<string>>, 1: array<string, list<string>>} the limits governing each configuration, and the limits that govern no configuration by the id they apply to
     */
    private static function limitIndex(array $limits): array
    {
        $governing = [];
        $subjects = [];

        foreach ($limits as $limit) {
            foreach ($limit['governs'] as $configuration) {
                $governing[$configuration][] = $limit['id'];
            }

            if ($limit['governs'] === [] && is_string($limit['applies_to']['id'] ?? null)) {
                $subjects[$limit['applies_to']['id']][] = $limit['id'];
            }
        }

        return [$governing, $subjects];
    }

    /**
     * @param  list<string>  $parts  the parts a system or feature works through; [] for a part
     * @param  array<string, list<string>>  $subjects  the limits by the id they apply to
     * @return array<string, string> each limit an id in use brings, with the id it applies to
     */
    private static function useSubjects(string $ref, array $parts, array $subjects): array
    {
        $found = [];

        foreach ([$ref, ...$parts] as $subject) {
            $found += array_fill_keys($subjects[$subject] ?? [], $subject);
        }

        return $found;
    }

    /** @param array{from: ?string} $feature */
    private static function featureComplete(array $feature, int $state): bool
    {
        return ! (self::stateIndex($feature['from']) > $state);
    }

    /** @param array{basis: ?string} $spec */
    private static function marker(array $spec): string
    {
        return $spec['basis'] === 'BASE_NOMINAL' ? 'base' : 'concept';
    }

    /** @return list<string> the ways a card may write the unit; [] for a unit no card can show */
    private static function unitForms(?string $unit): array
    {
        return self::UNITS[$unit ?? ''] ?? [];
    }

    private static function stateIndex(mixed $state): ?int
    {
        return is_string($state) && preg_match('/^BS(\d{2})$/', $state, $match) === 1 ? (int) $match[1] : null;
    }

    private static function s(float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 2, '.', ''), '0'), '.');
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
            'format' => MotoContract::SCRIPT_FORMAT,
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
