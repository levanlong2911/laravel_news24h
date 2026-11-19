<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoScriptCheck
{
    public const VERSION = 'moto-script-check-v3';

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
        $format = in_array($context['format'] ?? null, [MotoContract::SCRIPT_FORMAT_V2, MotoContract::SCRIPT_FORMAT_V3], true) ? (string) $context['format'] : MotoContract::SCRIPT_FORMAT;

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
        $eventful = $format !== MotoContract::SCRIPT_FORMAT;

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
            $issues[] = Finding::blocking('MotoScriptCheck.scored.1', 'project_id không khớp lượt chạy.');
        }

        if (($response['prompt_version'] ?? null) !== ($context['prompt_version'] ?? null)) {
            $issues[] = Finding::blocking('MotoScriptCheck.scored.2', 'prompt_version là "'.($response['prompt_version'] ?? '').'"; lượt chạy này dùng '.($context['prompt_version'] ?? '').'.');
        }

        $data = (array) $response['data'];
        $design = (array) ($context['design'] ?? []);
        $beats = array_values((array) $data['beats']);
        $byId = [];

        foreach ($beats as $index => $beat) {
            $expected = 'B'.($index + 1);

            if ($beat['id'] !== $expected) {
                $issues[] = Finding::requirement('MotoScriptCheck.scored.3', "Beat thứ ".($index + 1)." phải là {$expected}, không phải {$beat['id']}.");
            }

            $byId[$beat['id']] = $index;
        }

        if ($format === MotoContract::SCRIPT_FORMAT_V3) {
            // The checkpoints a beat opens and ends on are ids of its own stage in the pinned design.
            $layout = MotoDesignStates::ofFacts($design);

            foreach ($beats as $beat) {
                $issues = [...$issues, ...$layout->rowProblems($beat, (string) $beat['id'])];
            }
        }

        // The policy the run pinned: under moto-validation-v2 every later rule reads the beats with the refs code projects from their events and in_use.
        $validation = $eventful ? MotoScriptValidation::of($context) : null;
        $projects = $validation !== null && $validation['references'] !== null;

        if ($projects) {
            $projected = MotoScriptReferences::project($beats, $design);
            $beats = $projected['beats'];
            $data['beats'] = $beats;
            $issues = [...$issues, ...$projected['issues']];
        }

        $total = $eventful ? 0.0 : array_sum(array_map(static fn (array $beat): float => (float) $beat['duration_s'], $beats));
        $length = (float) ($context['video_length'] ?? 0);
        $advisory = [
            ...(! $eventful && abs($total - $length) > (float) ($policy['length_tolerance_sec'] ?? 0.1) + self::EPSILON ? [Finding::requirement('MotoScriptCheck.length', 'Các beat dài tổng '.self::s($total)." s; phim phải dài {$length} s.")] : []),
            ...self::heroIssues($data, $byId),
            ...self::designIssues($beats, $design, $projects),
            ...($eventful ? self::eventCardIssues((array) $data['text_cards'], $beats, $byId, $design) : self::cardIssues((array) $data['text_cards'], $beats, $byId, $design, $context, $policy, $total)),
        ];
        $issues = [...$issues, ...self::referenceIssues($data, $byId), ...($eventful ? self::eventIssues($data, $beats, $byId, $design, (bool) ($policy['stationary_requires_support'] ?? false), $projects, $validation !== null && $validation['support'] === MotoScriptValidation::SUPPORT_BY_DESIGN) : [])];

        if ((bool) ($policy['cover_build_states'] ?? false)) {
            $issues = [...$issues, ...self::coverageIssues($beats, $design)];
            $warnings = $advisory;
        } else {
            $issues = [...$issues, ...$advisory];
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
        $layout = MotoDesignStates::of($design);
        // A legacy design places its states by their stage number, a v3 design by the order of its checkpoints.
        $order = $layout->checkpointed() ? $layout->order() : null;

        if ($order === null) {
            for ($index = self::FIRST_BUILT_STATE; $index <= self::LAST_STATE; $index++) {
                $states[sprintf('BS%02d', $index)] = [
                    'present' => array_fill_keys(array_keys(array_filter($parts, static fn (array $part): bool => self::lifespan($part, $index))), true),
                    'in_progress' => [],
                    'pinned' => [],
                ];
            }
        } else {
            foreach ($order as $id => $index) {
                $states[$id] = [
                    'present' => array_fill_keys(array_keys(array_filter($parts, static fn (array $part): bool => self::lifespan($part, $index, $order))), true),
                    'in_progress' => [],
                    'pinned' => [],
                ];
            }
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

        // A beat names its stage, so the roles of a v3 design are read by stage; temporary items stay by physical state, which is a checkpoint there.
        $told = $order === null ? (array) ($design['build_states'] ?? []) : $layout->stages();
        $story = isset($design['participant_roles']) ? [
            'roles' => array_values(array_column((array) $design['participant_roles'], 'id')),
            'lights' => array_values(array_merge([], ...array_map(static fn (array $part): array => array_column((array) ($part['lighting'] ?? []), 'id'), (array) ($design['parts'] ?? [])))),
            'stage_roles' => array_map(static fn (array $state): array => array_values((array) ($state['participant_roles'] ?? [])), array_column($told, null, 'id')),
            'temporary' => array_map(static fn (array $state): array => array_values((array) ($state['temporary'] ?? [])), array_column((array) ($design['build_states'] ?? []), null, 'id')),
        ] : [];
        return $story + $layout->summary() + [
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
            'build_states' => array_map(static fn (array $state): array => ['id' => $state['id'], 'refs' => array_values((array) ($state['refs'] ?? []))], $told),
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
     * @param  array<string, mixed>  $design  the design facts of facts() for a design of moto-design-v3
     * @return list<string> the parts the beats of a physical stage must name together: those being made at any of its checkpoints, or, when none is, those that first appear at one of them; [] for a design stage
     */
    public static function stageMustName(array $design, string $stage): array
    {
        $checkpoints = MotoDesignStates::ofFacts($design)->checkpointsOf($stage);
        $making = array_values(array_unique(array_merge([], ...array_map(static fn (string $id): array => (array) $design['states'][$id]['in_progress'], $checkpoints))));

        if ($making !== []) {
            return $making;
        }

        return array_values(array_unique(array_merge([], ...array_map(static fn (string $id): array => self::mustName($design, $id), $checkpoints))));
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
        $layout = MotoDesignStates::ofFacts($design);
        $order = $layout->checkpointed() ? $layout->order() : null;

        foreach ((array) $design['states'] as $id => $state) {
            $index = $order === null ? (int) self::stateIndex($id) : (int) ($order[$id] ?? 0);
            $states[$id] = [
                'on_motorcycle' => $state['present'],
                'in_progress' => $state['in_progress'],
                'must_name' => self::mustName($design, (string) $id),
                'pinned' => $state['pinned'],
                'features_complete' => array_keys(array_filter((array) $design['features'], static fn (array $feature): bool => self::featureComplete($feature, $index, $order))),
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

        $stages = $order === null ? [] : ['stages' => array_column(array_map(static fn (array $stage): array => [
            'id' => (string) $stage['id'],
            'kind' => $stage['kind'],
            'checkpoints' => array_values((array) $stage['checkpoints']),
            'must_name' => self::stageMustName($design, (string) $stage['id']),
        ], $layout->stages()), null, 'id')];

        return [
            'acts' => $order === null ? array_map(static fn (array $range): array => [sprintf('BS%02d', $range[0]), sprintf('BS%02d', $range[1])], self::ACT_STATES) : $layout->acts(),
            ...$stages,
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
                $issues[] = Finding::note('MotoScriptCheck.coverageIssues.1', "Phim chưa có beat ở mạch chính cho {$id}.");

                continue;
            }

            $named = array_values(array_unique(array_merge(...array_map(static fn (array $beat): array => (array) $beat['design_refs'], $main))));
            $layout = MotoDesignStates::ofFacts($design);

            if ($layout->checkpointed()) {
                $required = $layout->physicalStage($id) ? self::stageMustName($design, $id) : (array) $state['refs'];
            } else {
                $required = isset($design['states'][$id]) ? self::mustName($design, $id) : (array) $state['refs'];
            }

            $missing = array_values(array_diff($required, $named));
            if ($missing !== []) {
                $issues[] = Finding::note('MotoScriptCheck.coverageIssues.2', "{$id} thiếu ".implode(', ', $missing).'.');
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, int>  $byId
     * @return list<string> a hero beat that is no beat of the script. Which act it belongs to, how much it reveals and what the beats before it show are content the model writes and the reviewer judges
     */
    private static function heroIssues(array $data, array $byId): array
    {
        $heroId = $data['story']['hero_beat'];

        return isset($byId[$heroId]) ? [] : [Finding::requirement('MotoScriptCheck.heroIssues.beat', "story.hero_beat {$heroId} không có trong beats.")];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, int>  $byId
     * @return list<string> a loop, an obstacle or a music hit that names a beat the script does not have; the order of the beats they name is content
     */
    private static function referenceIssues(array $data, array $byId): array
    {
        $issues = [];

        foreach ((array) $data['story']['loops'] as $loop) {
            if (! isset($byId[$loop['plant']], $byId[$loop['payoff']])) {
                $issues[] = Finding::requirement('MotoScriptCheck.referenceIssues.1', "Loop \"{$loop['motif']}\" nhắc beat không có trong beats.");
            }
        }

        foreach ((array) $data['story']['obstacles'] as $obstacle) {
            if (! isset($byId[$obstacle['problem_beat']], $byId[$obstacle['solution_beat']])) {
                $issues[] = Finding::requirement('MotoScriptCheck.referenceIssues.2', "Trở ngại {$obstacle['id']} nhắc beat không có trong beats.");
            }
        }

        foreach ((array) $data['music_brief']['hits'] as $hit) {
            if (! isset($byId[$hit['beat']])) {
                $issues[] = Finding::requirement('MotoScriptCheck.referenceIssues.3', "Điểm nhấn nhạc {$hit['moment']} nhắc {$hit['beat']}, không có trong beats.");
            }
        }

        return $issues;
    }

    /**
     * @param  list<array<string, mixed>>  $beats
     * @param  array<string, mixed>  $design
     * @param  bool  $projected  the refs of every beat were projected: each in_use id the design holds is in them, and one it does not hold already failed at its path
     * @return list<string>
     */
    private static function designIssues(array $beats, array $design, bool $projected = false): array
    {
        $issues = [];
        $records = [
            'P' => (array) ($design['parts'] ?? []),
            'S' => (array) ($design['systems'] ?? []),
            'F' => (array) ($design['features'] ?? []),
            'M' => (array) ($design['moments'] ?? []),
        ];
        $layout = MotoDesignStates::ofFacts($design);

        foreach ($beats as $beat) {
            if ($layout->checkpointed()) {
                // A beat of a v3 design runs from the checkpoint it opens on to the one it ends on: a part may be named where any of them has it.
                $span = array_values(array_filter(array_map(static fn (string $id): ?array => $design['states'][$id] ?? null, $layout->span($beat))));
                $at = $span === [] ? null : [
                    'present' => array_values(array_unique(array_merge([], ...array_column($span, 'present')))),
                    'in_progress' => array_values(array_unique(array_merge([], ...array_column($span, 'in_progress')))),
                ];
            } else {
                $state = self::stateIndex($beat['state']);
                $at = $state !== null && $state >= self::FIRST_BUILT_STATE ? ($design['states'][$beat['state']] ?? null) : null;
            }

            $present = static fn (string $part): bool => $at === null || in_array($part, $at['present'], true);

            foreach ($beat['design_refs'] as $ref) {
                $record = $records[$ref[0]][$ref] ?? null;

                if ($record === null) {
                    if (! MotoScriptReferences::known($ref, $design)) {
                        $issues[] = Finding::requirement('MotoScriptCheck.designIssues.1', "{$beat['id']} nhắc {$ref}, không có trong thiết kế.");
                    }
                } elseif ($ref[0] === 'P' && $at !== null && ! $present($ref) && ! in_array($ref, $at['in_progress'], true)) {
                    $issues[] = Finding::requirement('MotoScriptCheck.designIssues.2', "{$beat['id']} dùng {$ref} ở {$beat['state']}, khi bộ phận này chưa có, đã tháo hoặc không đang làm.");
                }
            }

            foreach ($projected ? [] : $beat['in_use'] as $ref) {
                if (! in_array($ref, $beat['design_refs'], true)) {
                    $issues[] = Finding::requirement('MotoScriptCheck.designIssues.3', "{$beat['id']}: {$ref} trong in_use phải có trong design_refs.");
                }
            }

            $owners = [];

            foreach ($beat['configurations'] as $configuration) {
                $owner = $design['configurations'][$configuration] ?? null;

                if ($owner === null) {
                    $issues[] = Finding::requirement('MotoScriptCheck.designIssues.4', "{$beat['id']} dùng cấu hình {$configuration}, không có trong thiết kế.");

                    continue;
                }

                if (isset($owners[$owner])) {
                    $issues[] = Finding::requirement('MotoScriptCheck.designIssues.5', "{$beat['id']} đặt hai vị trí cho {$owner}.");
                }

                $owners[$owner] = $configuration;

                if (! $present($owner)) {
                    $issues[] = Finding::requirement('MotoScriptCheck.designIssues.6', "{$beat['id']} dùng cấu hình {$configuration} ở {$beat['state']}, khi {$owner} chưa có hoặc đã tháo.");
                }
            }
        }

        return $issues;
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
                $issues[] = Finding::requirement('MotoScriptCheck.cardIssues.1', "Card \"{$card['text']}\" ở {$card['beat']}, không có trong beats.");

                continue;
            }

            if ((float) $card['offset_s'] + (float) $card['sec'] > (float) $beats[$index]['duration_s'] + self::EPSILON) {
                $issues[] = Finding::requirement('MotoScriptCheck.cardIssues.2', "Card \"{$card['text']}\" vượt ra ngoài {$card['beat']}.");
            }

            $issues = [...$issues, ...self::sourceIssues($card, $design)];
            $byBeat[$card['beat']][] = $card;
        }

        foreach ($byBeat as $beat => $list) {
            usort($list, static fn (array $a, array $b): int => $a['offset_s'] <=> $b['offset_s']);

            for ($i = 1; $i < count($list); $i++) {
                if ((float) $list[$i - 1]['offset_s'] + (float) $list[$i - 1]['sec'] > (float) $list[$i]['offset_s'] + self::EPSILON) {
                    $issues[] = Finding::requirement('MotoScriptCheck.cardIssues.3', "Hai card chồng lên nhau ở {$beat}.");
                }
            }
        }

        foreach (['DISCLAIMER', 'END'] as $type) {
            if (($types[$type] ?? 0) === 0) {
                $issues[] = Finding::note('MotoScriptCheck.cardIssues.4', "Thiếu card {$type}.");
            }
        }

        if (($types['BASE'] ?? 0) > 0 && ! (bool) ($context['base_card'] ?? false)) {
            $issues[] = Finding::note('MotoScriptCheck.cardIssues.5', 'Brief không cho card BASE.');
        }

        if (($types['BASE'] ?? 0) > 1) {
            $issues[] = Finding::note('MotoScriptCheck.cardIssues.6', 'Card BASE chỉ được xuất hiện một lần.');
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
                $issues[] = Finding::requirement('MotoScriptCheck.eventCardIssues.1', 'Card thứ '.($index + 1).' phải là '.sprintf('CARD%02d', $index + 1).", không phải {$card['id']}.");
            }

            $beat = $byId[$card['beat']] ?? null;

            if ($beat === null) {
                $issues[] = Finding::requirement('MotoScriptCheck.eventCardIssues.2', "{$card['id']} ở {$card['beat']}, không có trong beats.");

                continue;
            }

            if ($card['event'] !== null && ! in_array($card['event'], array_column((array) $beats[$beat]['events'], 'id'), true)) {
                $issues[] = Finding::requirement('MotoScriptCheck.eventCardIssues.3', "{$card['id']} gắn {$card['event']}, không phải sự kiện của {$card['beat']}.");
            }

            $issues = [...$issues, ...self::sourceIssues($card, $design)];
        }

        foreach (['DISCLAIMER', 'END'] as $type) {
            if (($types[$type] ?? 0) === 0) {
                $issues[] = Finding::note('MotoScriptCheck.eventCardIssues.4', "Thiếu card {$type}.");
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
    private static function eventIssues(array $data, array $beats, array $byId, array $design, bool $heldWhenStill = false, bool $projected = false, bool $supportByDesign = false): array
    {
        $issues = [];
        $layout = MotoDesignStates::ofFacts($design);
        $roles = array_fill_keys((array) ($design['roles'] ?? []), false);
        $characters = [];

        foreach (array_values((array) $data['characters']) as $index => $character) {
            if ($character['id'] !== sprintf('CH%02d', $index + 1)) {
                $issues[] = Finding::requirement('MotoScriptCheck.eventIssues.1', 'Nhân vật thứ '.($index + 1).' phải là '.sprintf('CH%02d', $index + 1).", không phải {$character['id']}.");
            }

            $characters[$character['id']] = array_values((array) $character['role_ids']);

            foreach ((array) $character['role_ids'] as $role) {
                if (! array_key_exists($role, $roles)) {
                    $issues[] = Finding::requirement('MotoScriptCheck.eventIssues.2', "{$character['id']}.role_ids: {$role} không có trong participant_roles của thiết kế.");
                } else {
                    $roles[$role] = true;
                }
            }
        }

        foreach (array_keys(array_filter($roles, static fn (bool $played): bool => ! $played)) as $role) {
            $issues[] = Finding::note('MotoScriptCheck.eventIssues.3', "Vai {$role} của thiết kế chưa có nhân vật nào đảm nhiệm.");
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
                    $issues[] = Finding::requirement('MotoScriptCheck.eventIssues.4', "Sự kiện thứ {$number} phải là ".sprintf('EV%02d', $number).", không phải {$event['id']}.");
                }

                $eventBeat[$event['id']] = $beat['id'];

                foreach ((array) $event['character_ids'] as $id) {
                    $shown[$id] = true;

                    if (! isset($characters[$id])) {
                        $issues[] = Finding::requirement('MotoScriptCheck.eventIssues.5', "{$event['id']}.character_ids: {$id} không có trong characters.");
                    }
                }

                // Projected beats already hold every known ref of their events; an unknown one was reported at its own path.
                if (! $projected) {
                    foreach (array_diff((array) $event['design_refs'], (array) $beat['design_refs']) as $ref) {
                        $issues[] = Finding::requirement('MotoScriptCheck.eventIssues.6', "{$event['id']}.design_refs: {$ref} không nằm trong design_refs của {$beat['id']}.");
                    }
                }

                $temporary = $layout->checkpointed() && ! $supportByDesign
                    ? array_values(array_unique(array_merge([], ...array_map(static fn (string $id): array => MotoSupportReferences::allowed((array) ($design['temporary'] ?? []), $id, false), $layout->span($beat)))))
                    : MotoSupportReferences::allowed((array) ($design['temporary'] ?? []), is_string($beat['state'] ?? null) ? $beat['state'] : null, $supportByDesign);

                foreach (['operating_start', 'operating_end'] as $moment) {
                    $issues = [...$issues, ...self::operatingIssues("{$event['id']}.{$moment}", (array) $event[$moment], $design, array_keys($characters), $temporary, $supportByDesign ? 'temporary của thiết kế' : 'temporary của build state này')];
                }

                $previous = $event;
            }

            $onScreen = (array) $beat['on_screen'];
            sort($onScreen);
            $named = array_keys($shown);
            sort($named);

            if ($onScreen !== $named) {
                $issues[] = Finding::requirement('MotoScriptCheck.eventIssues.7', "{$beat['id']}.on_screen phải đúng bằng các nhân vật mà sự kiện của beat nêu: ".($named === [] ? 'không ai' : implode(', ', $named)).'.');
            }

            $ending = (array) ($previous['operating_end']['configurations'] ?? []);
            $held = (array) $beat['configurations'];
            sort($ending);
            sort($held);

            if ($ending !== $held) {
                $issues[] = Finding::requirement('MotoScriptCheck.eventIssues.8', "{$beat['id']}.configurations phải đúng bằng configurations ở operating_end của sự kiện cuối ({$previous['id']}).");
            }
        }

        foreach (array_values((array) $data['shot_directives']) as $index => $directive) {
            if ($directive['id'] !== sprintf('DIR%02d', $index + 1)) {
                $issues[] = Finding::requirement('MotoScriptCheck.eventIssues.9', 'Chỉ dẫn thứ '.($index + 1).' phải là '.sprintf('DIR%02d', $index + 1).", không phải {$directive['id']}.");
            }

            foreach (array_filter((array) $directive['beat_ids'], static fn (string $id): bool => ! isset($byId[$id])) as $id) {
                $issues[] = Finding::requirement('MotoScriptCheck.eventIssues.10', "{$directive['id']}.beat_ids: {$id} không có trong beats.");
            }

            $cursor = 0;

            foreach ((array) $directive['event_anchors'] as $anchor) {
                $at = "{$directive['id']} {$anchor['event_id']}";

                if (! in_array($eventBeat[$anchor['event_id']] ?? null, (array) $directive['beat_ids'], true)) {
                    $issues[] = Finding::requirement('MotoScriptCheck.eventIssues.11', "{$at}: sự kiện không thuộc beat nào của chỉ dẫn.");
                }

                if ($anchor['start_ms'] >= $anchor['end_ms']) {
                    $issues[] = Finding::requirement('MotoScriptCheck.eventIssues.12', "{$at}: start_ms phải nhỏ hơn end_ms.");
                }

                if ($anchor['start_ms'] < $cursor) {
                    $issues[] = Finding::requirement('MotoScriptCheck.eventIssues.13', "{$at}: bắt đầu ở {$anchor['start_ms']} ms, trước khi mốc trước kết thúc ({$cursor} ms).");
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
     * @param  list<string>  $temporary  the temporary items a support may name there (MotoSupportReferences::allowed)
     * @param  string  $temporarySource  where those items come from, for the message
     * @return list<string> the engine part, configurations and lights the design does not hold, two configurations of one part, and a support that is a part the design does not have, a character the script does not have or a temporary item it may not name
     */
    public static function operatingIssues(string $at, array $state, array $design, array $characters = [], array $temporary = [], string $temporarySource = 'temporary của build state này'): array
    {
        $issues = [];

        foreach ((array) ($state['support_refs'] ?? []) as $support) {
            $problem = MotoSupportReferences::problem($support, (array) ($design['parts'] ?? []), $characters, $temporary, 'characters', $temporarySource);

            if ($problem !== null) {
                $issues[] = Finding::requirement('MotoScriptCheck.operatingIssues.1', "{$at}.support_refs: {$support} {$problem}.");
            }
        }

        if (is_array($state['engine']) && ! isset($design['parts'][$state['engine']['part_id']])) {
            $issues[] = Finding::requirement('MotoScriptCheck.operatingIssues.2', "{$at}.engine: {$state['engine']['part_id']} không có trong thiết kế.");
        }

        $parts = [];

        foreach ((array) $state['configurations'] as $configuration) {
            $part = $design['configurations'][$configuration] ?? null;

            if ($part === null) {
                $issues[] = Finding::requirement('MotoScriptCheck.operatingIssues.3', "{$at}.configurations: {$configuration} không có trong thiết kế.");
            } elseif (isset($parts[$part])) {
                $issues[] = Finding::requirement('MotoScriptCheck.operatingIssues.4', "{$at}.configurations: {$part} có hai vị trí cùng lúc ({$parts[$part]}, {$configuration}).");
            } else {
                $parts[$part] = $configuration;
            }
        }

        foreach ((array) $state['lights'] as $light) {
            if (! in_array($light['light_id'], (array) ($design['lights'] ?? []), true)) {
                $issues[] = Finding::requirement('MotoScriptCheck.operatingIssues.5', "{$at}.lights: {$light['light_id']} không có trong thiết kế.");
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

            return $spec === null || $spec['value'] === null ? [Finding::requirement('MotoScriptCheck.cardRef.spec', "Card SPEC \"{$card['text']}\" trỏ {$ref}, không có giá trị trong thiết kế.")] : [];
        }

        if ($card['type'] === 'BASE') {
            $value = ($path[1] ?? null) === 'base' && in_array($path[2] ?? null, ['family', 'model'], true) ? ($design['base'][$path[2]] ?? null) : null;

            return is_string($value) ? [] : [Finding::requirement('MotoScriptCheck.cardRef.base', "Card BASE \"{$card['text']}\" trỏ {$ref}, không phải family hoặc model của xe nền.")];
        }

        return [];
    }

    /**
     * @param  array{present: bool, from: ?string, until: ?string}  $part
     * @param  ?array<string, int>  $order  the place of each physical state of a v3 design; null for a legacy design, placed by stage number
     */
    private static function lifespan(array $part, int $state, ?array $order = null): bool
    {
        $from = self::place($part['from'], $order);
        $until = self::place($part['until'], $order);

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

    /**
     * @param  array{from: ?string}  $feature
     * @param  ?array<string, int>  $order  as lifespan()
     */
    private static function featureComplete(array $feature, int $state, ?array $order = null): bool
    {
        return ! (self::place($feature['from'], $order) > $state);
    }

    /**
     * @param  ?array<string, int>  $order  the place of each physical state of a v3 design; null for a legacy design
     * @return ?int the place of a state in the build: its stage number in a legacy design, its checkpoint order in a v3 design
     */
    private static function place(mixed $state, ?array $order): ?int
    {
        return $order === null ? self::stateIndex($state) : (is_string($state) ? ($order[$state] ?? null) : null);
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
            ...Finding::fields($issues, $warnings),
            'judge' => self::JUDGE,
        ];
    }
}
