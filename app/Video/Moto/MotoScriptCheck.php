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
        $format = ($context['format'] ?? null) === MotoContract::SCRIPT_FORMAT_V2 ? MotoContract::SCRIPT_FORMAT_V2 : MotoContract::SCRIPT_FORMAT;

        return ['format' => $format] + self::scored($response, $context, $schema, $policy, $format);
    }

    /**
     * @param  ?array<string, mixed>  $response
     * @param  array<string, mixed>  $context
     * @param  list<array{code: string, path: string, message: string}>  $schema
     * @param  array<string, mixed>  $policy
     * @param  string  $format  the answer format the run pinned
     * @return array{version: string, format: string, verdict: string, status: ?string, error: ?string, schema: list<string>, issues: list<string>, warnings: list<string>, judge: string}
     */
    private static function scored(?array $response, array $context, array $schema, array $policy, string $format): array
    {
        $eventful = $format === MotoContract::SCRIPT_FORMAT_V2;

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

        $total = $eventful ? 0.0 : array_sum(array_map(static fn (array $beat): float => (float) $beat['duration_s'], $beats));
        $length = (float) ($context['video_length'] ?? 0);
        $advisory = [
            ...(! $eventful && abs($total - $length) > (float) ($policy['length_tolerance_sec'] ?? 0.1) + self::EPSILON ? ['Các beat dài tổng '.self::s($total)." s; phim phải dài {$length} s."] : []),
            ...self::progressIssues($beats),
            ...self::heroIssues($data, $beats, $byId, $eventful),
            ...self::designIssues($beats, $design),
            ...($eventful ? self::eventCardIssues((array) $data['text_cards'], $beats, $byId, $design) : self::cardIssues((array) $data['text_cards'], $beats, $byId, $design, $context, $policy, $total)),
        ];
        $issues = [...$issues, ...self::referenceIssues($data, $byId), ...($eventful ? self::eventIssues($data, $beats, $byId, $design, (bool) ($policy['stationary_requires_support'] ?? false)) : [])];

        if ((bool) ($policy['cover_build_states'] ?? false)) {
            $issues = [...$issues, ...self::coverageIssues($beats, $design)];
            $warnings = [...$advisory, ...self::heroMomentWarnings($data, $beats, $byId, $design)];
        } else {
            $issues = [...$issues, ...$advisory];
        }

        foreach ($beats as $index => $beat) {
            if (! $eventful && (float) $beat['duration_s'] < (float) ($policy['warn']['beat_min_sec'] ?? 0.8)) {
                $warnings[] = "{$beat['id']} chỉ dài ".self::s((float) $beat['duration_s']).' s; khó đọc kịp một hành động.';
            }

            if ($index > 0 && $beat['link'] === null) {
                $warnings[] = "{$beat['id']} không có link nối với beat trước.";
            }
        }

        $hero = $byId[$data['story']['hero_beat']] ?? null;

        if (! $eventful && $hero !== null && (float) $beats[$hero]['duration_s'] < (float) ($policy['warn']['hero_min_sec'] ?? 2)) {
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

        $story = isset($design['participant_roles']) ? [
            'roles' => array_values(array_column((array) $design['participant_roles'], 'id')),
            'lights' => array_values(array_merge([], ...array_map(static fn (array $part): array => array_column((array) ($part['lighting'] ?? []), 'id'), (array) ($design['parts'] ?? [])))),
            'stage_roles' => array_map(static fn (array $state): array => array_values((array) ($state['participant_roles'] ?? [])), array_column((array) ($design['build_states'] ?? []), null, 'id')),
            'temporary' => array_map(static fn (array $state): array => array_values((array) ($state['temporary'] ?? [])), array_column((array) ($design['build_states'] ?? []), null, 'id')),
        ] : [];

        return $story + [
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
     * @param  bool  $earlyWhole  whether a beat before the hero may show the whole motorcycle
     * @return list<string>
     */
    private static function heroIssues(array $data, array $beats, array $byId, bool $earlyWhole = false): array
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

        foreach ($earlyWhole ? [] : $beats as $index => $beat) {
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
     * @param  array<string, mixed>  $beat  a beat with its situation and configurations and, in an eventful script, its events
     * @param  ?array<string, mixed>  $before  the beat before it in the script
     * @param  array<string, string>  $stand  the configurations of the design's side stand, each with the position it is: down or up
     * @param  ?int  $at  the operating state to judge at, counted through the beat's events as start, end, start, end; null takes the last state that holds the configuration, or the last state when none does
     * @param  list<array<string, mixed>>  $evidence  the records of the confirmation of procedures made for the limit judged (evidenceFor()): each a procedure with the kind of evidence that establishes it (EVIDENCE_KINDS); a procedure no record names is established only as described below
     * @return ?array<string, mixed> the situation a limit of that configuration is judged in. The situation a beat states describes the state its last event ends in: it is the answer when no moment is named and when the named moment is that very last state, by its place in the beat and never because an earlier state has the same values. At any earlier moment of the beat, and in a beat that states none — the schema keeps the ending without one — the answer is read only from what the source holds up to that moment, the operating state at that moment: whether the motorcycle stands still, what its engine does and where its stand is; and the procedures are only those the states of the beat up to that moment show being carried out — a stop, the stand lowered, the engine switched off — and still in force. A procedure no change of state can show, such as releasing a latch, is established only by a situation the source states, so a limit that needs it is unmet at such a moment unless the beat before established it. A beat that states a situation lends its road to its every moment and nothing else. The road, the lean and the procedures of the situation of the beat before are taken over only while nothing broke the link to it: no time jump, the same place and build state, and from the end of that beat to this moment the motorcycle stood still on the same supports with the stand in the same position and the configuration held throughout. Nothing is assumed beyond those sources: what they do not tell stays unknown, so a limit that needs it stays unmet. Null when the beat has no events to read
     */
    public static function situationFor(array $beat, ?array $before, string $configurationId, array $stand = [], ?int $at = null, array $evidence = []): ?array
    {
        $own = is_array($beat['situation'] ?? null) ? $beat['situation'] : null;
        $states = self::operatingStates($beat);
        $named = $at !== null && isset($states[$at]);

        if ($own !== null && (! $named || $at === count($states) - 1)) {
            return $own;
        }

        if ($states === []) {
            return null;
        }

        if (! $named) {
            $at = count($states) - 1;

            foreach ($states as $index => $state) {
                $at = in_array($configurationId, (array) ($state['configurations'] ?? []), true) ? $index : $at;
            }

            $at = in_array($configurationId, (array) ($states[$at]['configurations'] ?? []), true) ? $at : count($states) - 1;
        }

        $now = $states[$at];
        $still = ($now['vehicle_motion'] ?? null) === 'stationary';
        $shown = self::proceduresShown(array_slice($states, 0, $at + 1), $stand);
        $earlier = is_array($before) ? self::operatingStates($before) : [];
        $kept = is_array($before['situation'] ?? null) && $earlier !== []
            && ($beat['time_jump'] ?? null) === 'none'
            && ($beat['location'] ?? null) === ($before['location'] ?? null)
            && ($beat['state'] ?? null) === ($before['state'] ?? null);
        $chain = [$earlier === [] ? [] : $earlier[count($earlier) - 1], ...array_slice($states, 0, $at + 1)];

        foreach ($kept ? $chain : [] as $state) {
            $kept = $kept
                && ($state['vehicle_motion'] ?? null) === 'stationary'
                && in_array($configurationId, (array) ($state['configurations'] ?? []), true)
                && self::sorted((array) ($state['support_refs'] ?? [])) === self::sorted((array) ($chain[0]['support_refs'] ?? []))
                && self::standAt($state, $stand) === self::standAt($chain[0], $stand);
        }

        $carried = $kept ? $before['situation'] : [];

        return [
            'mode' => $still ? 'parked' : 'riding',
            'engine' => self::engineAt($now),
            'side_stand' => self::standAt($now, $stand),
            'speed_kmh' => $still ? 0 : null,
            'lean_angle_deg' => $carried['lean_angle_deg'] ?? null,
            'dry_road' => $carried['dry_road'] ?? ($own['dry_road'] ?? null),
            'procedures' => array_values(array_unique([...array_values((array) ($carried['procedures'] ?? [])), ...$shown, ...self::proceduresConfirmed($evidence, $beat, array_slice($states, 0, $at + 1), $configurationId, $stand)])),
        ];
    }

    /** @var string the evidence of a procedure that is a state holding at the moment judged: the motorcycle stands still */
    public const BY_STATIONARY = 'stationary';

    /** @var string the engine is off at the moment judged */
    public const BY_ENGINE_OFF = 'engine_off';

    /** @var string the side stand is down at the moment judged */
    public const BY_STAND_DOWN = 'side_stand_down';

    /** @var string the evidence of a procedure that is the act of moving the part into the governed position: an event of the beat moved it there before the moment judged and it stayed there */
    public const BY_ENTERING = 'enters_configuration';

    /** @var list<string> every kind of evidence a confirmation of procedures may name */
    public const EVIDENCE_KINDS = [self::BY_STATIONARY, self::BY_ENGINE_OFF, self::BY_STAND_DOWN, self::BY_ENTERING];

    /**
     * @param  list<array<string, mixed>>  $evidence  the records a person confirmed for the limit judged: each a procedure and the kind of evidence that establishes it
     * @param  array<string, mixed>  $beat  the beat judged
     * @param  list<array<string, mixed>>  $states  the operating states of the beat in order, up to the moment judged
     * @param  array<string, string>  $stand
     * @return list<string> the confirmed procedures whose evidence holds at the last of those states: a state the record names, read from that state alone; or, for a record of the act of entering the position, exactly the transition it names — in this beat, the event it names opens on the configuration it leaves and ends on the one it enters, which is the configuration judged and is held in every state from there to the moment judged. A procedure no record names, and a transition other than the one recorded, is never in this list
     */
    private static function proceduresConfirmed(array $evidence, array $beat, array $states, string $configurationId, array $stand): array
    {
        if ($evidence === [] || $states === []) {
            return [];
        }

        $at = count($states) - 1;
        $now = $states[$at];
        $has = static fn (array $state, mixed $id): bool => is_string($id) && in_array($id, (array) ($state['configurations'] ?? []), true);
        $events = array_column(array_values((array) ($beat['events'] ?? [])), 'id');
        $confirmed = [];

        foreach ($evidence as $record) {
            if (! is_array($record)) {
                continue;
            }

            $met = match ($record['kind'] ?? null) {
                self::BY_STATIONARY => ($now['vehicle_motion'] ?? null) === 'stationary',
                self::BY_ENGINE_OFF => self::engineAt($now) === 'off',
                self::BY_STAND_DOWN => self::standAt($now, $stand) === 'down',
                self::BY_ENTERING => (static function () use ($record, $beat, $states, $events, $configurationId, $has, $at): bool {
                    $event = array_search($record['event'] ?? null, $events, true);

                    if (($record['to'] ?? null) !== $configurationId || ($record['beat'] ?? null) !== ($beat['id'] ?? null) || $event === false || $at < $event * 2 + 1) {
                        return false;
                    }

                    if (! $has($states[$event * 2], $record['from'] ?? null) || $has($states[$event * 2], $configurationId)) {
                        return false;
                    }

                    foreach (array_slice($states, $event * 2 + 1) as $state) {
                        if (! $has($state, $configurationId)) {
                            return false;
                        }
                    }

                    return true;
                })(),
                default => false,
            };

            if ($met && is_string($record['procedure'] ?? null)) {
                $confirmed[] = $record['procedure'];
            }
        }

        return array_values(array_unique($confirmed));
    }

    /**
     * @param  mixed  $evidence  what a run or a project holds as its confirmation of procedures
     * @return ?string why it is not a list of records code can read: each names the limit it is for, the procedure and a kind of evidence, and a record of the act of entering a position also names the part, the configuration it leaves, the one it enters, and the beat and the event that enters it; null when it is
     */
    public static function evidenceProblem(mixed $evidence): ?string
    {
        if (! is_array($evidence) || ! array_is_list($evidence)) {
            return 'bản xác nhận thủ tục không phải danh sách bản ghi';
        }

        foreach ($evidence as $index => $record) {
            $keys = ['limit', 'procedure', 'kind', ...(($record['kind'] ?? null) === self::BY_ENTERING ? ['part', 'from', 'to', 'beat', 'event'] : [])];

            if (! is_array($record) || ! in_array($record['kind'] ?? null, self::EVIDENCE_KINDS, true)) {
                return "bản ghi {$index} không nêu loại bằng chứng code biết (".implode(', ', self::EVIDENCE_KINDS).')';
            }

            foreach ($keys as $key) {
                if (! is_string($record[$key] ?? null) || $record[$key] === '') {
                    return "bản ghi {$index} ({$record['kind']}) thiếu {$key}";
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $evidence  the records of a confirmation of procedures
     * @return list<array<string, mixed>> those made for that limit
     */
    public static function evidenceFor(array $evidence, string $limitId): array
    {
        return array_values(array_filter($evidence, static fn (mixed $record): bool => is_array($record) && ($record['limit'] ?? null) === $limitId));
    }

    /** @var array<string, string> the procedures a change of operating state shows being carried out, each with the change that shows it; any other procedure is established only by a situation the source states */
    private const SHOWN_BY = ['stop' => 'stopped', 'lower_stand' => 'stand_lowered', 'switch_engine_off' => 'engine_stopped'];

    /**
     * @param  list<array<string, mixed>>  $states  the operating states of a beat in order, up to the moment judged
     * @param  array<string, string>  $stand  the configurations of the side stand with the position each is
     * @return list<string> the procedures those states show being carried out and still in force at the last of them: stop when the motorcycle went from moving to standing still and stood since, lower_stand when the stand went from up to down and stayed down, switch_engine_off when the engine went from running to off and stayed off. A procedure no change of state can show is never in this list
     */
    private static function proceduresShown(array $states, array $stand): array
    {
        $reads = [
            'stopped' => static fn (array $state): ?bool => match ($state['vehicle_motion'] ?? null) {
                'stationary' => true,
                'moving' => false,
                default => null,
            },
            'stand_lowered' => static fn (array $state): ?bool => match (self::standAt($state, $stand)) {
                'down' => true,
                'up' => false,
                default => null,
            },
            'engine_stopped' => static fn (array $state): ?bool => match (self::engineAt($state)) {
                'off' => true,
                'running' => false,
                default => null,
            },
        ];
        $shown = [];

        foreach (self::SHOWN_BY as $procedure => $change) {
            $done = false;

            foreach ($states as $index => $state) {
                $holds = $reads[$change]($state);
                $done = match (true) {
                    $holds !== true => false,
                    $index > 0 && $reads[$change]($states[$index - 1]) === false => true,
                    default => $done,
                };
            }

            if ($done) {
                $shown[] = $procedure;
            }
        }

        return $shown;
    }

    /**
     * @param  array<string, mixed>  $state  an operating state
     * @return ?string what the engine does in that state as a situation says it: off or running; null when the state shows no engine that can run or one only starting
     */
    private static function engineAt(array $state): ?string
    {
        return match (is_array($state['engine'] ?? null) ? ($state['engine']['state'] ?? null) : null) {
            'off' => 'off',
            'running', 'idling' => 'running',
            default => null,
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $parts  the design's parts by id, each with its kind and configurations
     * @return array<string, string> the configurations of the side stand, each with the position it is: down for the one the design names down, up for the others
     */
    public static function standPositions(array $parts): array
    {
        $positions = [];

        foreach ($parts as $part) {
            foreach (($part['kind'] ?? null) === 'side_stand' ? (array) ($part['configurations'] ?? []) : [] as $configuration) {
                $positions[(string) $configuration['id']] = ($configuration['name'] ?? null) === 'down' ? 'down' : 'up';
            }
        }

        return $positions;
    }

    /**
     * @param  array<string, mixed>  $beat
     * @param  list<string>  $eventIds  the events of the beat a shot covers
     * @return ?int the operating state a shot opens on or closes on, as situationFor() counts them: the start of the first of those events, or the end of the last; null when none of them is an event of the beat
     */
    public static function momentOf(array $beat, array $eventIds, bool $closing): ?int
    {
        $own = array_column((array) ($beat['events'] ?? []), 'id');
        $found = array_values(array_filter(array_map(static fn (string $id): int|false => array_search($id, $own, true), array_map('strval', $eventIds)), static fn (int|false $index): bool => $index !== false));

        if ($found === []) {
            return null;
        }

        return $closing ? max($found) * 2 + 1 : min($found) * 2;
    }

    /**
     * @param  array<string, mixed>  $beat
     * @return list<array<string, mixed>> the operating states of the beat in order: the start and the end of each event; empty when the beat carries no events
     */
    private static function operatingStates(array $beat): array
    {
        $states = [];

        foreach (array_values((array) ($beat['events'] ?? [])) as $event) {
            if (! is_array($event['operating_start'] ?? null) || ! is_array($event['operating_end'] ?? null)) {
                return [];
            }

            $states[] = $event['operating_start'];
            $states[] = $event['operating_end'];
        }

        return $states;
    }

    /**
     * @param  array<string, mixed>  $state  an operating state
     * @param  array<string, string>  $stand
     * @return ?string the position of the side stand in that state; null when the state names no configuration of it
     */
    private static function standAt(array $state, array $stand): ?string
    {
        foreach ((array) ($state['configurations'] ?? []) as $configurationId) {
            if (isset($stand[$configurationId])) {
                return $stand[$configurationId];
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        $values = array_map('strval', $values);
        sort($values);

        return $values;
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
     * @param  list<array<string, mixed>>  $cards
     * @param  list<array<string, mixed>>  $beats
     * @param  array<string, int>  $byId
     * @param  array<string, mixed>  $design
     * @return list<string> every card whose id is out of sequence, whose beat or event the script does not hold, whose event belongs to another beat or whose source points at nothing, and a missing DISCLAIMER or END card
     */
    private static function eventCardIssues(array $cards, array $beats, array $byId, array $design): array
    {
        $issues = [];
        $types = array_count_values(array_column($cards, 'type'));

        foreach ($cards as $index => $card) {
            if ($card['id'] !== sprintf('CARD%02d', $index + 1)) {
                $issues[] = 'Card thứ '.($index + 1).' phải là '.sprintf('CARD%02d', $index + 1).", không phải {$card['id']}.";
            }

            $beat = $byId[$card['beat']] ?? null;

            if ($beat === null) {
                $issues[] = "{$card['id']} ở {$card['beat']}, không có trong beats.";

                continue;
            }

            if ($card['event'] !== null && ! in_array($card['event'], array_column((array) $beats[$beat]['events'], 'id'), true)) {
                $issues[] = "{$card['id']} gắn {$card['event']}, không phải sự kiện của {$card['beat']}.";
            }

            $issues = [...$issues, ...self::sourceIssues($card, $design)];
        }

        foreach (['DISCLAIMER', 'END'] as $type) {
            if (($types[$type] ?? 0) === 0) {
                $issues[] = "Thiếu card {$type}.";
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $beats
     * @param  array<string, int>  $byId
     * @param  array<string, mixed>  $design  the design facts of facts()
     * @param  bool  $heldWhenStill  the policy the run pinned: a motorcycle that stands still names what holds it. A run pinned without it is checked as before
     * @return list<string> what breaks the links of characters, events, operating states and shot directives: an id out of sequence or unknown, a role nobody plays, a character or a design id an event names that its beat does not, an operating state that names what the design does not hold or two configurations of one part, a motorcycle standing still with nothing named to hold it when the policy asks, an event that does not start where the one before ended, a beat that does not end where its last event ends, and a directive whose anchors are unknown, out of order or outside its beats
     */
    private static function eventIssues(array $data, array $beats, array $byId, array $design, bool $heldWhenStill = false): array
    {
        $issues = [];
        $roles = array_fill_keys((array) ($design['roles'] ?? []), false);
        $characters = [];

        foreach (array_values((array) $data['characters']) as $index => $character) {
            if ($character['id'] !== sprintf('CH%02d', $index + 1)) {
                $issues[] = 'Nhân vật thứ '.($index + 1).' phải là '.sprintf('CH%02d', $index + 1).", không phải {$character['id']}.";
            }

            $characters[$character['id']] = array_values((array) $character['role_ids']);

            foreach ((array) $character['role_ids'] as $role) {
                if (! array_key_exists($role, $roles)) {
                    $issues[] = "{$character['id']}.role_ids: {$role} không có trong participant_roles của thiết kế.";
                } else {
                    $roles[$role] = true;
                }
            }
        }

        foreach (array_keys(array_filter($roles, static fn (bool $played): bool => ! $played)) as $role) {
            $issues[] = "Vai {$role} của thiết kế chưa có nhân vật nào đảm nhiệm.";
        }

        $number = 0;
        $eventBeat = [];

        foreach ($beats as $beat) {
            $events = array_values((array) $beat['events']);
            $shown = [];
            $previous = null;

            foreach ($events as $event) {
                $number++;

                if ($event['id'] !== sprintf('EV%02d', $number)) {
                    $issues[] = "Sự kiện thứ {$number} phải là ".sprintf('EV%02d', $number).", không phải {$event['id']}.";
                }

                $eventBeat[$event['id']] = $beat['id'];

                foreach ((array) $event['character_ids'] as $id) {
                    $shown[$id] = true;

                    if (! isset($characters[$id])) {
                        $issues[] = "{$event['id']}.character_ids: {$id} không có trong characters.";
                    }
                }

                foreach (array_diff((array) $event['design_refs'], (array) $beat['design_refs']) as $ref) {
                    $issues[] = "{$event['id']}.design_refs: {$ref} không nằm trong design_refs của {$beat['id']}.";
                }

                foreach (['operating_start', 'operating_end'] as $moment) {
                    $issues = [...$issues, ...self::operatingIssues("{$event['id']}.{$moment}", (array) $event[$moment], $design, array_keys($characters), (array) ($design['temporary'][(string) ($beat['state'] ?? '')] ?? []))];

                    if ($heldWhenStill && ($event[$moment]['vehicle_motion'] ?? null) === 'stationary' && (array) ($event[$moment]['support_refs'] ?? []) === []) {
                        $issues[] = "{$event['id']}.{$moment}.support_refs: xe đứng yên nhưng chưa khai người hoặc thiết bị giữ xe.";
                    }
                }

                if ($previous !== null && self::canonical((array) $event['operating_start']) !== self::canonical((array) $previous['operating_end'])) {
                    $issues[] = "{$event['id']}.operating_start khác operating_end của {$previous['id']} — sự kiện sau phải bắt đầu ở trạng thái sự kiện trước kết thúc.";
                }

                $previous = $event;
            }

            $allowed = is_string($beat['state'] ?? null) ? ($design['stage_roles'][$beat['state']] ?? null) : null;

            foreach (is_array($allowed) ? array_keys($shown) : [] as $id) {
                if (isset($characters[$id]) && array_intersect($characters[$id], $allowed) === []) {
                    $issues[] = "{$beat['id']}: {$id} (".implode(', ', $characters[$id]).") xuất hiện ở {$beat['state']}, giai đoạn chỉ có vai ".(implode(', ', $allowed) ?: 'không vai nào').'.';
                }
            }

            $onScreen = (array) $beat['on_screen'];
            sort($onScreen);
            $named = array_keys($shown);
            sort($named);

            if ($onScreen !== $named) {
                $issues[] = "{$beat['id']}.on_screen phải đúng bằng các nhân vật mà sự kiện của beat nêu: ".($named === [] ? 'không ai' : implode(', ', $named)).'.';
            }

            $ending = (array) ($previous['operating_end']['configurations'] ?? []);
            $held = (array) $beat['configurations'];
            sort($ending);
            sort($held);

            if ($ending !== $held) {
                $issues[] = "{$beat['id']}.configurations phải đúng bằng configurations ở operating_end của sự kiện cuối ({$previous['id']}).";
            }
        }

        foreach (array_values((array) $data['shot_directives']) as $index => $directive) {
            if ($directive['id'] !== sprintf('DIR%02d', $index + 1)) {
                $issues[] = 'Chỉ dẫn thứ '.($index + 1).' phải là '.sprintf('DIR%02d', $index + 1).", không phải {$directive['id']}.";
            }

            foreach (array_filter((array) $directive['beat_ids'], static fn (string $id): bool => ! isset($byId[$id])) as $id) {
                $issues[] = "{$directive['id']}.beat_ids: {$id} không có trong beats.";
            }

            $cursor = 0;

            foreach ((array) $directive['event_anchors'] as $anchor) {
                $at = "{$directive['id']} {$anchor['event_id']}";

                if (! in_array($eventBeat[$anchor['event_id']] ?? null, (array) $directive['beat_ids'], true)) {
                    $issues[] = "{$at}: sự kiện không thuộc beat nào của chỉ dẫn.";
                }

                if ($anchor['start_ms'] >= $anchor['end_ms']) {
                    $issues[] = "{$at}: start_ms phải nhỏ hơn end_ms.";
                }

                if ($anchor['start_ms'] < $cursor) {
                    $issues[] = "{$at}: bắt đầu ở {$anchor['start_ms']} ms, trước khi mốc trước kết thúc ({$cursor} ms).";
                }

                $cursor = max($cursor, (int) $anchor['end_ms']);
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $state  one operating state
     * @param  array<string, mixed>  $design  the design facts of facts()
     * @param  list<string>  $characters  the ids of the characters of the script
     * @param  list<string>  $temporary  the temporary items the build state of the beat or scene lists
     * @return list<string> the engine part, configurations and lights the design does not hold, two configurations of one part, and a support that is a part the design does not have, a character the script does not have or a temporary item the build state does not list
     */
    public static function operatingIssues(string $at, array $state, array $design, array $characters = [], array $temporary = []): array
    {
        $issues = [];

        foreach ((array) ($state['support_refs'] ?? []) as $support) {
            $problem = match (true) {
                preg_match('/^P\d{2,3}$/', (string) $support) === 1 => isset($design['parts'][$support]) ? null : 'không có trong parts của thiết kế',
                preg_match('/^CH\d{2}$/', (string) $support) === 1 => in_array($support, $characters, true) ? null : 'không có trong characters',
                default => in_array($support, $temporary, true) ? null : 'không có trong temporary của build state này',
            };

            if ($problem !== null) {
                $issues[] = "{$at}.support_refs: {$support} {$problem}.";
            }
        }

        if (is_array($state['engine']) && ! isset($design['parts'][$state['engine']['part_id']])) {
            $issues[] = "{$at}.engine: {$state['engine']['part_id']} không có trong thiết kế.";
        }

        $parts = [];

        foreach ((array) $state['configurations'] as $configuration) {
            $part = $design['configurations'][$configuration] ?? null;

            if ($part === null) {
                $issues[] = "{$at}.configurations: {$configuration} không có trong thiết kế.";
            } elseif (isset($parts[$part])) {
                $issues[] = "{$at}.configurations: {$part} có hai vị trí cùng lúc ({$parts[$part]}, {$configuration}).";
            } else {
                $parts[$part] = $configuration;
            }
        }

        foreach ((array) $state['lights'] as $light) {
            if (! in_array($light['light_id'], (array) ($design['lights'] ?? []), true)) {
                $issues[] = "{$at}.lights: {$light['light_id']} không có trong thiết kế.";
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $state  one operating state
     * @return string the state with its lists in a fixed order, so two states that say the same compare equal
     */
    public static function canonical(array $state): string
    {
        foreach (['configurations', 'support_refs'] as $list) {
            $items = (array) ($state[$list] ?? []);
            sort($items);
            $state[$list] = $items;
        }

        $lights = (array) ($state['lights'] ?? []);
        usort($lights, static fn (array $a, array $b): int => $a['light_id'] <=> $b['light_id']);
        $state['lights'] = $lights;
        ksort($state);

        return (string) json_encode($state);
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
