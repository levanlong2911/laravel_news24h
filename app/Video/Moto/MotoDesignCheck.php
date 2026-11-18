<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Video\Superyacht\Support\CanonicalJson;

final class MotoDesignCheck
{
    public const VERSION = 'moto-design-check-v3';

    /** @var string the words only the length problem of a model-sheet prompt carries */
    public const LENGTH_ISSUE = 'vượt max_chars';

    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNSCORED = 'UNSCORED';

    /** @var list<string> the physical build states, in order */
    public const PHYSICAL_STATES = ['BS06', 'BS07', 'BS08', 'BS09', 'BS10', 'BS11', 'BS12'];

    /** @var list<string> the facings the eight panels need described */
    private const FACINGS = ['left', 'right', 'front', 'rear', 'top'];

    /** @var list<string> the views whose camera must equal the pinned camera field by field */
    private const FIXED_VIEWS = ['MS1', 'MS2', 'MS3', 'MS4', 'MS5', 'MS6', 'MS7'];

    private const PROMPT_IDS = '/\b(?:P\d{2,3}(?:-[KL]\d{2})?|S\d{2}(?:-W\d{2})?|J\d{2,3}|F[1-3](?:-M\d{2})?|L\d{2,3}|M\d{1,2}|BS\d{2}|C[1-9]\d*|SI\d{2,3}|MS[1-8]|D[1-5])\b/';

    private const PLACEHOLDER = '/[{}<>]|\bTODO\b|\bTBD\b/';

    /**
     * @param  ?array<string, mixed>  $response  the decoded answer
     * @param  array<string, mixed>  $dna  the DNA the run sent
     * @param  array<string, mixed>  $sourceIdentity  the source identity the run sent
     * @param  array<string, mixed>  $modelSheet  the pinned model-sheet presentation the run sent
     * @param  list<string>  $forbidden  the forbidden words of the pinned prompt
     * @param  list<string>  $schemaErrors  the answer's violations of the pinned schema
     * @param  string  $format  the answer format the run pinned
     * @return array{version: string, format: string, verdict: string, status: ?string, issues: list<string>, warnings: list<string>, error: ?string, design_hash: ?string, parts_by_state: array<string, list<string>>}
     */
    public static function evaluate(?array $response, string $projectId, string $promptVersion, array $dna, array $sourceIdentity, array $modelSheet, array $forbidden, array $schemaErrors, string $format = MotoContract::DESIGN_FORMAT): array
    {
        return ['format' => $format] + self::scored($response, $projectId, $promptVersion, $dna, $sourceIdentity, $modelSheet, $forbidden, $schemaErrors, $format);
    }

    /**
     * @param  ?array<string, mixed>  $response
     * @param  array<string, mixed>  $dna
     * @param  array<string, mixed>  $sourceIdentity
     * @param  array<string, mixed>  $modelSheet
     * @param  list<string>  $forbidden
     * @param  list<string>  $schemaErrors
     * @return array{version: string, format: string, verdict: string, status: ?string, issues: list<string>, warnings: list<string>, error: ?string, design_hash: ?string, parts_by_state: array<string, list<string>>}
     */
    private static function scored(?array $response, string $projectId, string $promptVersion, array $dna, array $sourceIdentity, array $modelSheet, array $forbidden, array $schemaErrors, string $format): array
    {
        $themed = $format === MotoContract::DESIGN_FORMAT_V2;

        if ($response === null) {
            return self::result(self::UNSCORED, null, [], 'Phản hồi không đọc được JSON.');
        }

        if (($response['format'] ?? null) !== $format) {
            return self::result(self::UNSCORED, null, [], 'Lượt này ghim hợp đồng '.$format.' nhưng phản hồi khai format '.json_encode($response['format'] ?? null).' — sai hợp đồng, không dùng tiếp.');
        }

        $status = is_string($response['status'] ?? null) ? $response['status'] : null;
        // A schema error is an error of the JSON structure: a RULE failure.
        $issues = array_map(static fn (string $error): Finding => Finding::blocking('MotoDesignCheck.schema', "Schema: {$error}"), $schemaErrors);

        if ($status === 'fail') {
            $reasons = array_values(array_filter(array_map(static fn (mixed $flag): string => is_string($flag) ? rtrim(trim($flag), '.') : '', (array) ($response['flags'] ?? []))));

            return self::result(self::FAIL, $status, ['AI báo status fail — không tạo được hồ sơ thiết kế dùng được: '.($reasons !== [] ? implode('; ', $reasons) : 'không nêu lý do').'.', ...$issues]);
        }

        if (($response['project_id'] ?? null) !== $projectId) {
            $issues[] = Finding::blocking('MotoDesignCheck.scored.1', 'project_id không phải dự án này.');
        }

        if (($response['prompt_version'] ?? null) !== $promptVersion) {
            $issues[] = Finding::blocking('MotoDesignCheck.scored.2', 'prompt_version là '.json_encode($response['prompt_version'] ?? null, JSON_UNESCAPED_UNICODE)."; lượt chạy này dùng {$promptVersion}.");
        }

        if ($schemaErrors !== []) {
            return self::result(self::FAIL, $status, $issues);
        }

        $data = $response['data'];
        $ids = self::ids($data);
        $geometry = self::geometryIssues($data, $ids);
        $warnings = $geometry['warnings'];
        $issues = [
            ...$issues,
            ...$ids['issues'],
            ...self::referenceIssues($data, $ids, $themed),
            ...self::dnaIssues($data, $dna),
            ...$geometry['issues'],
            ...self::configurationIssues($data, $ids, $themed),
            ...($themed ? self::storyIssues($data, $ids) : []),
            ...self::stateIssues($data, $ids),
            ...self::viewIssues($data, $ids, (array) ($modelSheet['cameras'] ?? [])),
            ...(isset($data['model_sheet']['final_prompt']) ? self::promptIssues($data, $modelSheet) : MotoSheetPrompt::composedProblems($data)),
        ];

        if ($status === 'partial') {
            $blocking = array_values(array_filter($data['source_issues'], static fn (array $issue): bool => $issue['blocking'] === true));
            $issues[] = Finding::requirement('MotoDesignCheck.scored.3', 'AI báo status partial — hồ sơ được lưu nhưng không render model sheet: '.implode('; ', array_map(static fn (array $issue): string => "{$issue['id']} {$issue['problem']}", $blocking)).'.');
        }

        return self::result($issues === [] ? self::PASS : self::FAIL, $status, $issues, null, CanonicalJson::hash($data), self::partsByState($data), $warnings);
    }

    /**
     * @param  array<string, mixed>  $data  a design record that matches the schema
     * @return array<string, list<string>> each physical build state => the ids of the parts present in it
     */
    public static function partsByState(array $data): array
    {
        $parts = array_column($data['parts'], null, 'id');
        $present = [];

        foreach (self::PHYSICAL_STATES as $state) {
            $present[$state] = array_fill_keys(array_keys(array_filter($parts, static fn (array $part): bool => self::exists($part, $state))), true);
        }

        foreach ($data['build_states'] as $buildState) {
            if (! isset($present[$buildState['id']])) {
                continue;
            }

            foreach ($buildState['exceptions'] as $exception) {
                if ($exception['presence'] === 'removed') {
                    unset($present[$buildState['id']][$exception['part']]);
                } else {
                    $present[$buildState['id']][$exception['part']] = true;
                }
            }
        }

        return array_map(static fn (array $ids): array => array_keys($ids), $present);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{issues: list<string>, parts: array<string, array<string, mixed>>, configurations: array<string, string>, lights: array<string, string>, systems: array<string, true>, lines: array<string, true>, connections: array<string, true>, features: array<string, array<string, mixed>>, modes: array<string, array<string, mixed>>, limits: array<string, array<string, mixed>>, colors: array<string, true>, rules: array<string, true>, source_issues: array<string, true>}
     */
    private static function ids(array $data): array
    {
        $issues = [];
        $seen = [];
        $add = static function (string $kind, string $id) use (&$seen, &$issues): void {
            if (isset($seen[$kind][$id])) {
                $issues[] = Finding::requirement('MotoDesignCheck.ids.1', "{$kind} {$id} khai trùng ID.");
            }

            $seen[$kind][$id] = true;
        };
        $parts = [];
        $configurations = [];
        $lights = [];

        foreach ($data['parts'] as $part) {
            $add('part', $part['id']);
            $parts[$part['id']] = $part;

            foreach ($part['configurations'] as $configuration) {
                $add('configuration', $configuration['id']);
                $configurations[$configuration['id']] = $part['id'];
            }

            foreach ($part['lighting'] as $light) {
                $add('light', $light['id']);
                $lights[$light['id']] = $part['id'];
            }
        }

        $lines = [];

        foreach ($data['systems'] as $system) {
            $add('system', $system['id']);

            foreach ($system['lines'] as $line) {
                $add('line', $line['id']);
                $lines[$line['id']] = true;

                if (! str_starts_with($line['id'], $system['id'].'-')) {
                    $issues[] = Finding::requirement('MotoDesignCheck.ids.2', "line {$line['id']} không thuộc hệ {$system['id']} theo tiền tố ID.");
                }
            }
        }

        foreach ($data['connections'] as $connection) {
            $add('connection', $connection['id']);
        }

        $limits = [];

        foreach ($data['operational_limits'] as $limit) {
            $add('limit', $limit['id']);
            $limits[$limit['id']] = $limit;
        }

        $features = [];
        $modes = [];

        foreach ($data['features'] as $feature) {
            $add('feature', $feature['id']);
            $features[$feature['id']] = $feature;

            foreach ($feature['modes'] as $mode) {
                $add('mode', $mode['id']);
                $modes[$mode['id']] = $mode;

                if (! str_starts_with($mode['id'], $feature['id'].'-')) {
                    $issues[] = Finding::requirement('MotoDesignCheck.ids.3', "mode {$mode['id']} không thuộc {$feature['id']} theo tiền tố ID.");
                }
            }
        }

        foreach ($data['motorcycle']['signature_moments'] as $moment) {
            $add('moment', $moment['id']);
        }

        foreach ($data['motorcycle']['palette'] as $color) {
            $add('color', $color['id']);
        }

        foreach ($data['identity_rules'] as $rule) {
            $add('rule', $rule['id']);
        }

        foreach ($data['source_issues'] as $issue) {
            $add('source issue', $issue['id']);
        }

        foreach ($configurations + $lights as $id => $partId) {
            if (! str_starts_with($id, $partId.'-')) {
                $issues[] = Finding::requirement('MotoDesignCheck.ids.4', "{$id} khai ở {$partId} nhưng tiền tố ID là bộ phận khác.");
            }
        }

        return [
            'issues' => $issues,
            'parts' => $parts,
            'configurations' => $configurations,
            'lights' => $lights,
            'systems' => $seen['system'] ?? [],
            'lines' => $lines,
            'connections' => $seen['connection'] ?? [],
            'features' => $features,
            'modes' => $modes,
            'limits' => $limits,
            'colors' => $seen['color'] ?? [],
            'rules' => $seen['rule'] ?? [],
            'source_issues' => $seen['source issue'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $ids  the result of ids()
     * @param  bool  $themed  the record is themed: the refs of its build states follow MotoDesignRefs, by kind and by the list of that kind
     * @return list<string>
     */
    private static function referenceIssues(array $data, array $ids, bool $themed = false): array
    {
        $issues = [];
        $known = [
            'part' => $ids['parts'],
            'configuration' => $ids['configurations'],
            'light' => $ids['lights'],
            'system' => $ids['systems'],
            'line' => $ids['lines'],
            'connection' => $ids['connections'],
            'feature' => $ids['features'],
            'limit' => $ids['limits'],
            'color' => $ids['colors'],
            'rule' => $ids['rules'],
            'source issue' => $ids['source_issues'],
        ];
        $kindOf = static fn (string $id): string => match (true) {
            preg_match('/^P\d{2,3}-K\d{2}$/', $id) === 1 => 'configuration',
            preg_match('/^P\d{2,3}-L\d{2}$/', $id) === 1 => 'light',
            preg_match('/^P\d{2,3}$/', $id) === 1 => 'part',
            preg_match('/^S\d{2}-W\d{2}$/', $id) === 1 => 'line',
            preg_match('/^S\d{2}$/', $id) === 1 => 'system',
            preg_match('/^J\d{2,3}$/', $id) === 1 => 'connection',
            preg_match('/^F[1-3]$/', $id) === 1 => 'feature',
            preg_match('/^L\d{2,3}$/', $id) === 1 => 'limit',
            preg_match('/^C[1-9]\d*$/', $id) === 1 => 'color',
            preg_match('/^SI\d{2,3}$/', $id) === 1 => 'source issue',
            default => 'rule',
        };
        $check = static function (string $path, mixed $refs, bool $present = false) use (&$issues, $known, $kindOf, $ids): void {
            foreach (is_array($refs) ? $refs : [$refs] as $ref) {
                if (! is_string($ref)) {
                    continue;
                }

                if (! isset($known[$kindOf($ref)][$ref])) {
                    $issues[] = Finding::requirement('MotoDesignCheck.referenceIssues.1', "{$path}: {$ref} không có trong hồ sơ.");
                } elseif ($present && isset($ids['parts'][$ref]) && ! $ids['parts'][$ref]['present']) {
                    $issues[] = Finding::requirement('MotoDesignCheck.referenceIssues.2', "{$path}: {$ref} là bộ phận không có trên xe (present false).");
                }
            }
        };

        foreach ($data['parts'] as $part) {
            $check("{$part['id']}.parent", $part['parent']);
            $check("{$part['id']}.relations", array_column($part['relations'], 'target'));
            $check("{$part['id']}.surfaces", array_column($part['surfaces'], 'color_id'));

            if ($part['parent'] === $part['id']) {
                $issues[] = Finding::requirement('MotoDesignCheck.referenceIssues.3', "{$part['id']}.parent: bộ phận không thể là cha của chính nó.");
            }
        }

        foreach ($data['systems'] as $system) {
            $check("{$system['id']}.parts", $system['parts'], true);

            foreach ($system['lines'] as $line) {
                $check("{$line['id']}.from", $line['from']);
                $check("{$line['id']}.to", $line['to']);
            }
        }

        foreach ($data['connections'] as $connection) {
            $check("{$connection['id']}.between", $connection['between'], true);

            if ($connection['between'][0] === $connection['between'][1]) {
                $issues[] = Finding::requirement('MotoDesignCheck.referenceIssues.4', "{$connection['id']}.between: nối một bộ phận với chính nó.");
            }
        }

        foreach ($data['features'] as $feature) {
            $check("{$feature['id']}.parts", $feature['parts']);

            foreach ($feature['modes'] as $mode) {
                $check("{$mode['id']}.configurations", $mode['configurations']);
            }
        }

        foreach ($data['operational_limits'] as $limit) {
            $check("{$limit['id']}.applies_to", $limit['applies_to']['id']);
            $check("{$limit['id']}.governs", $limit['governs']);
            $check("{$limit['id']}.otherwise_configurations", $limit['otherwise_configurations']);
        }

        $motorcycle = $data['motorcycle'];

        foreach ($motorcycle['departures'] as $departure) {
            $check("motorcycle.departures.{$departure['departure']}", $departure['refs']);
        }

        foreach ($motorcycle['signature_moments'] as $moment) {
            $check("{$moment['id']}.parts", $moment['parts'], true);
            $check("{$moment['id']}.configurations", $moment['configurations']);
            $check("{$moment['id']}.limits", $moment['limits']);
        }

        $check('motorcycle.lighting_signature.sources', $motorcycle['lighting_signature']['sources']);
        $check('motorcycle.markings', array_column($motorcycle['markings'], 'part'));
        $check('rider_interface.contact_points', array_column($data['rider_interface']['contact_points'], 'part'));
        $check('rider_interface.passenger.parts', $data['rider_interface']['passenger']['parts']);

        if ($themed) {
            // A story ref is an id the next steps look up in the design.
            $issues = [...$issues, ...Finding::each(MotoCheckRules::USE, 'MotoDesignRefs.storyRefProblems', array_column(MotoDesignRefs::storyRefProblems($data), 'message'))];
        }

        foreach ($data['build_states'] as $state) {
            if (! $themed) {
                $check("{$state['id']}.refs", $state['refs'] ?? []);
            }

            $check("{$state['id']}.in_progress", array_column($state['in_progress'] ?? [], 'part'));
            $check("{$state['id']}.exceptions", array_column($state['exceptions'] ?? [], 'part'));
            $check("{$state['id']}.exceptions.configuration", array_column($state['exceptions'] ?? [], 'configuration'));
        }

        foreach ($data['identity_rules'] as $rule) {
            $check("{$rule['id']}.scope", array_values(array_diff($rule['scope'], ['MOTORCYCLE'])));
            $check("{$rule['id']}.refs", $rule['refs']);
        }

        $sheet = $data['model_sheet'];
        $check('model_sheet.display_configuration', $sheet['display_configuration']);

        foreach ($sheet['views'] as $view) {
            $check("{$view['id']}.visible", [...$view['visible'], ...$view['partial'], ...$view['uncertain']], true);
            $check("{$view['id']}.issues", $view['issues']);
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $dna
     * @return list<string>
     */
    private static function dnaIssues(array $data, array $dna): array
    {
        $issues = [];
        $wanted = array_column((array) ($dna['features'] ?? []), null, 'id');
        $found = array_column($data['features'], null, 'id');

        foreach (array_diff_key($found, $wanted) as $id => $feature) {
            $issues[] = Finding::note('MotoDesignCheck.dnaIssues.1', "features có {$id}, không phải signature feature của DNA (".implode(', ', array_keys($wanted)).').');
        }

        foreach (array_diff_key($wanted, $found) as $id => $feature) {
            $issues[] = Finding::note('MotoDesignCheck.dnaIssues.2', "features thiếu {$id} của DNA.");
        }

        $departures = array_column((array) ($dna['departures'] ?? []), 'id');
        $covered = array_column($data['motorcycle']['departures'], 'departure');

        foreach (array_diff($covered, $departures) as $id) {
            $issues[] = Finding::note('MotoDesignCheck.dnaIssues.3', "motorcycle.departures có {$id}, không phải departure của DNA.");
        }

        foreach (array_diff($departures, $covered) as $id) {
            $issues[] = Finding::note('MotoDesignCheck.dnaIssues.4', "motorcycle.departures chưa nói {$id} của DNA nằm ở đâu trong thiết kế.");
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $ids  the result of ids()
     * @return array{issues: list<string>, warnings: list<string>} the numbers code computes with: a part box with no extent, a rider point with no side, and a facing no part describes that the model-sheet panels are composed from. Whether the shape, the proportions or the positions are plausible is content the model designs and the reviewer judges: no box is compared with another
     */
    private static function geometryIssues(array $data, array $ids): array
    {
        $issues = [];
        $warnings = [];
        $present = array_filter($ids['parts'], static fn (array $part): bool => $part['present']);

        foreach ($present as $id => $part) {
            $box = $part['extent'];

            if ($box['x_from'] >= $box['x_to'] || $box['y_min'] >= $box['y_max'] || $box['z_min'] >= $box['z_max']) {
                $issues[] = Finding::requirement('MotoDesignCheck.geometryIssues.1', "{$id}.extent rỗng hoặc đảo cận (cận dưới không nhỏ hơn cận trên).");
            }
        }

        foreach (['grip_points', 'peg_points'] as $group) {
            foreach (['left', 'right'] as $side) {
                $y = $data['rider_interface'][$group][$side]['y'] ?? null;

                if (! is_int($y) && ! is_float($y)) {
                    $issues[] = Finding::requirement('MotoDesignCheck.geometryIssues.2', "rider_interface.{$group}.{$side} thiếu y — không xác định được bên trái/phải của người lái.");
                }
            }
        }

        $facings = [];

        foreach ($present as $part) {
            foreach ($part['faces'] as $face) {
                $facings[$face['facing']] = true;

                if ($face['facing'] === 'both_sides') {
                    $facings['left'] = $facings['right'] = true;
                }
            }
        }

        foreach (array_diff(self::FACINGS, array_keys($facings)) as $facing) {
            $issues[] = Finding::requirement('MotoDesignCheck.geometryIssues.3', "Không bộ phận nào tả mặt {$facing} — các panel model sheet cần mặt này.");
        }

        return ['issues' => $issues, 'warnings' => $warnings];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $ids  the result of ids()
     * @return list<string> every role a stage names that the design does not list, every listed role no stage names, every role id out of sequence, and every marking whose colour is not in the palette or whose part is not on the motorcycle at the state it appears from
     */
    private static function storyIssues(array $data, array $ids): array
    {
        $issues = [];
        $roles = array_column($data['participant_roles'], 'id');
        $used = [];

        foreach ($roles as $index => $id) {
            if ($id !== sprintf('ROLE%02d', $index + 1)) {
                $issues[] = Finding::requirement('MotoDesignCheck.storyIssues.1', "participant_roles[{$index}] có ID {$id}; ID phải đánh số liên tiếp từ ROLE01.");
            }
        }

        foreach ($data['build_states'] as $state) {
            foreach ($state['participant_roles'] as $role) {
                $used[$role] = true;

                if (! in_array($role, $roles, true)) {
                    $issues[] = Finding::requirement('MotoDesignCheck.storyIssues.2', "{$state['id']}.participant_roles: {$role} không có trong danh mục participant_roles.");
                }
            }
        }

        foreach (array_diff($roles, array_keys($used)) as $role) {
            $issues[] = Finding::note('MotoDesignCheck.storyIssues.3', "participant_roles: {$role} không được giai đoạn nào dùng.");
        }

        foreach ($data['motorcycle']['markings'] as $index => $marking) {
            foreach ($marking['color_ids'] as $color) {
                if (! isset($ids['colors'][$color])) {
                    $issues[] = Finding::requirement('MotoDesignCheck.storyIssues.4', "motorcycle.markings[{$index}].color_ids: {$color} không có trong palette.");
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $ids  the result of ids()
     * @param  bool  $markingsShown  whether the contract lets the model sheet show a marking
     * @return list<string>
     */
    private static function configurationIssues(array $data, array $ids, bool $markingsShown = false): array
    {
        $issues = [];
        foreach ($ids['limits'] as $limit) {
            $owners = array_values(array_unique(array_filter(array_map(static fn (string $id): ?string => $ids['configurations'][$id] ?? null, $limit['governs']))));

            foreach ($limit['otherwise_configurations'] as $id) {
                $owner = $ids['configurations'][$id] ?? null;

                if ($owner !== null && $owners !== [] && ! in_array($owner, $owners, true)) {
                    $issues[] = Finding::requirement('MotoDesignCheck.configurationIssues.1', "{$limit['id']}: vị trí dự phòng {$id} thuộc {$owner}, không phải bộ phận mà giới hạn này kiểm soát (".implode(', ', $owners).').');
                }
            }

            if ($limit['applies_to']['kind'] === 'part') {
                foreach ($limit['governs'] as $id) {
                    if (($ids['configurations'][$id] ?? null) !== $limit['applies_to']['id']) {
                        $issues[] = Finding::requirement('MotoDesignCheck.configurationIssues.2', "{$limit['id']} áp cho bộ phận {$limit['applies_to']['id']} nhưng governs {$id} của bộ phận khác.");
                    }
                }
            }
        }

        $display = $data['model_sheet']['display_configuration'];

        foreach ($ids['parts'] as $id => $part) {
            if ($part['present'] && $part['configurations'] !== []) {
                $shown = array_filter($display, static fn (string $configuration): bool => ($ids['configurations'][$configuration] ?? null) === $id);

                if (count($shown) !== 1) {
                    $issues[] = Finding::requirement('MotoDesignCheck.configurationIssues.3', 'model_sheet.display_configuration chọn '.count($shown)." vị trí của {$id}, cần đúng một.");
                }
            }
        }

        foreach ($display as $id) {
            $partId = $ids['configurations'][$id] ?? null;

            if ($partId !== null && ! $ids['parts'][$partId]['present']) {
                $issues[] = Finding::requirement('MotoDesignCheck.configurationIssues.4', "model_sheet.display_configuration: {$id} thuộc {$partId}, bộ phận không có trên xe.");
            }
        }

        $onePerPart = static function (string $where, array $configurations) use (&$issues, $ids): void {
            $owners = array_map(static fn (string $id): string => $ids['configurations'][$id] ?? $id, $configurations);

            if (count($owners) !== count(array_unique($owners))) {
                $issues[] = Finding::requirement('MotoDesignCheck.configurationIssues.5', "{$where} đặt hai vị trí cho cùng một bộ phận.");
            }
        };

        foreach ($data['motorcycle']['signature_moments'] as $moment) {
            $onePerPart($moment['id'], $moment['configurations']);
        }

        foreach ($ids['modes'] as $id => $mode) {
            $onePerPart($id, $mode['configurations']);
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $ids  the result of ids()
     * @return list<string>
     */
    private static function stateIssues(array $data, array $ids): array
    {
        $issues = [];
        $order = array_flip(self::PHYSICAL_STATES);

        foreach ($ids['parts'] as $id => $part) {
            if ($part['until_state'] !== null && ($part['from_state'] === null || $order[$part['until_state']] < $order[$part['from_state']])) {
                $issues[] = Finding::requirement('MotoDesignCheck.stateIssues.1', "{$id}: until_state {$part['until_state']} đứng trước from_state.");
            }
        }

        foreach ($data['build_states'] as $state) {
            if (! isset($order[$state['id']])) {
                continue;
            }

            foreach ($state['exceptions'] as $exception) {
                if (! isset($ids['parts'][$exception['part']])) {
                    continue;
                }

                if (is_string($exception['configuration']) && ($ids['configurations'][$exception['configuration']] ?? null) !== $exception['part']) {
                    $issues[] = Finding::requirement('MotoDesignCheck.stateIssues.2', "{$state['id']}: cấu hình {$exception['configuration']} không thuộc {$exception['part']}.");
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $ids  the result of ids()
     * @param  array<string, array<string, mixed>>  $cameras  the pinned cameras
     * @return list<string>
     */
    private static function viewIssues(array $data, array $ids, array $cameras): array
    {
        $issues = [];
        $seen = [];

        foreach ($data['model_sheet']['views'] as $view) {
            $pinned = $cameras[$view['id']] ?? null;

            if (! is_array($pinned)) {
                $issues[] = Finding::requirement('MotoDesignCheck.viewIssues.1', "{$view['id']}: không có camera đã ghim để đối chiếu.");
            } else {
                $fields = in_array($view['id'], self::FIXED_VIEWS, true)
                    ? array_keys(array_diff_key($pinned, ['target' => true]) + $view['camera'])
                    : ['projection', 'lens_mm'];

                foreach ($fields as $field) {
                    $written = $view['camera'][$field] ?? null;
                    $expected = $pinned[$field] ?? null;
                    $same = is_int($written) || is_float($written) ? (is_int($expected) || is_float($expected)) && (float) $written === (float) $expected : $written === $expected;

                    if (! $same) {
                        $issues[] = Finding::requirement('MotoDesignCheck.viewIssues.2', "{$view['id']}.camera.{$field} là ".json_encode($written, JSON_UNESCAPED_UNICODE).'; camera đã ghim là '.json_encode($expected, JSON_UNESCAPED_UNICODE).'.');
                    }
                }
            }

            $listed = [...$view['visible'], ...$view['partial'], ...$view['uncertain']];

            if (count($listed) !== count(array_unique($listed))) {
                $issues[] = Finding::requirement('MotoDesignCheck.viewIssues.3', "{$view['id']}: một ID nằm ở hai danh sách visible/partial/uncertain.");
            }

        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $modelSheet  the pinned presentation
     * @param  ?string  $prompt  the model-sheet prompt to check; the one the design wrote when null
     * @param  array<string, string>  $sources  the design texts the composer wrote into that prompt, by the path each was taken from
     * @param  array<string, int>  $blocks  the longest blocks of that prompt, by title, with their characters
     * @return list<string>
     */
    public static function promptIssues(array $data, array $modelSheet, ?string $prompt = null, array $sources = [], array $blocks = []): array
    {
        $issues = [];
        $prompt ??= (string) $data['model_sheet']['final_prompt'];
        $max = (int) ($modelSheet['max_chars'] ?? 0);

        if ($max > 0 && mb_strlen($prompt) > $max) {
            $issues[] = Finding::requirement('MotoDesignCheck.promptIssues.1', 'final_prompt dài '.mb_strlen($prompt).' ký tự, '.self::LENGTH_ISSUE." {$max}."
                .($blocks === [] ? '' : ' Các khối dài nhất: '.implode(', ', array_map(static fn (string $title, int $chars): string => "{$title} ({$chars})", array_keys($blocks), $blocks)).'.'));
        }

        $position = -1;

        foreach ((array) ($modelSheet['panels'] ?? []) as $index => $line) {
            $at = strpos($prompt, (string) $line);

            if ($at === false) {
                $issues[] = Finding::requirement('MotoDesignCheck.promptIssues.2', 'final_prompt thiếu nguyên văn dòng panel '.($index + 1).": \"{$line}\".");
            } elseif ($at < $position) {
                $issues[] = Finding::requirement('MotoDesignCheck.promptIssues.3', 'final_prompt đặt dòng panel '.($index + 1).' sai thứ tự.');
            } else {
                $position = $at;
            }
        }

        if (preg_match_all(self::PROMPT_IDS, $prompt, $match) > 0) {
            $issues[] = Finding::note('MotoDesignCheck.promptIssues.4', 'final_prompt chứa ID: '.implode(', ', array_unique($match[0])).' — prompt ảnh không được có ID.');
        }

        $fields = array_values(array_filter(self::keys($data), static fn (string $key): bool => str_contains($key, '_') && str_contains($prompt, $key)));

        if ($fields !== []) {
            $issues[] = Finding::note('MotoDesignCheck.promptIssues.5', 'final_prompt chứa tên trường: '.implode(', ', $fields).'.');
        }

        if (preg_match(self::PLACEHOLDER, $prompt, $match) === 1) {
            $issues[] = Finding::requirement('MotoDesignCheck.promptIssues.6', "final_prompt còn chỗ trống \"{$match[0]}\".");
        }

        return $issues;
    }

    /** @return list<string> every id of a design record a text holds, each once, as the model-sheet prompt check finds them */
    public static function promptIds(string $text): array
    {
        return preg_match_all(self::PROMPT_IDS, $text, $match) > 0 ? array_values(array_unique($match[0])) : [];
    }

    /** @param  array<string, mixed>  $part */
    private static function exists(array $part, string $state): bool
    {
        $order = array_flip(self::PHYSICAL_STATES);

        return $part['present'] && $part['from_state'] !== null && $order[$part['from_state']] <= $order[$state]
            && ($part['until_state'] === null || $order[$state] <= $order[$part['until_state']]);
    }

    /** @return list<string> every object key used anywhere in a value */
    private static function keys(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $keys = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $keys[$key] = true;
            }

            foreach (self::keys($item) as $inner) {
                $keys[$inner] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * @param  list<string>  $issues
     * @param  array<string, list<string>>  $partsByState
     * @param  list<string>  $warnings  coarse box comparisons shown to the user; they never fail the design
     * @return array{version: string, format: string, verdict: string, status: ?string, issues: list<string>, warnings: list<string>, error: ?string, design_hash: ?string, parts_by_state: array<string, list<string>>}
     */
    private static function result(string $verdict, ?string $status, array $issues, ?string $error = null, ?string $hash = null, array $partsByState = [], array $warnings = []): array
    {
        return [
            'version' => self::VERSION,
            'format' => MotoContract::DESIGN_FORMAT,
            'verdict' => $verdict,
            'status' => $status,
            ...Finding::fields($issues, $warnings, false),
            'error' => $error,
            'design_hash' => $hash,
            'parts_by_state' => $partsByState,
        ];
    }
}
