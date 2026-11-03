<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Video\Superyacht\Support\CanonicalJson;

final class MotoDesignCheck
{
    public const VERSION = 'moto-design-check-v2';

    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNSCORED = 'UNSCORED';

    /** @var list<string> the physical build states, in order */
    public const PHYSICAL_STATES = ['BS06', 'BS07', 'BS08', 'BS09', 'BS10', 'BS11', 'BS12'];

    /** @var array<string, int> nominal displacement in cc of each Harley-Davidson engine family the prompt lists with one value */
    private const NOMINAL_CC = [
        'Milwaukee-Eight 107' => 1746, 'Milwaukee-Eight 114' => 1868, 'Milwaukee-Eight 117' => 1923, 'Milwaukee-Eight 121' => 1977,
        'Revolution Max 975' => 975, 'Revolution Max 1250' => 1252,
        'Twin Cam 88' => 1450, 'Twin Cam 96' => 1584, 'Twin Cam 103' => 1690, 'Twin Cam 110' => 1802, 'Evolution' => 1340,
    ];

    /** @var list<string> part kinds that may rise above the profile curve */
    private const ABOVE_PROFILE = ['handlebar', 'hand_controls', 'mirror', 'turn_signal', 'instruments'];

    /** @var list<string> part kinds the ground clearance leaves out: the wheels stand on the ground and the side stand folds */
    private const CLEARANCE_LEFT_OUT = ['front_wheel', 'rear_wheel', 'side_stand'];

    /** @var int millimetres a wheel box may start above the ground; a wheel includes its tyre and stands on the ground (fails beyond) */
    private const WHEEL_GROUND_MM = 10;

    /** @var int millimetres the front axle may sit from x 0, the origin of the coordinate system (fails beyond) */
    private const FRONT_AXLE_MM = 15;

    /** @var int millimetres the axle distance may differ from specs.wheelbase, or WHEELBASE_SHARE of it when larger (fails beyond) */
    private const WHEELBASE_MM = 15;

    /** @var float the share of specs.wheelbase the axle distance may differ by, when larger than WHEELBASE_MM */
    private const WHEELBASE_SHARE = 0.01;

    /** @var int millimetres the seating point may differ from specs.seat_height, the same point in the reference pose (fails beyond) */
    private const SEAT_HEIGHT_MM = 40;

    /** @var array{0: int, 1: int} millimetres a wheel box usually adds to its rim diameter for the tyre, least and most (warns outside) */
    private const TYRE_ADDS_MM = [80, 400];

    /** @var int millimetres the ends of the profile curve may differ from the frontmost and rearmost part boxes (warns beyond) */
    private const PROFILE_END_MM = 50;

    /** @var int millimetres a part box may rise above the profile curve over its length (warns beyond) */
    private const PROFILE_TOP_MM = 60;

    /** @var int millimetres a point may lie outside the box of its part (warns beyond) */
    private const POINT_BOX_MM = 30;

    /** @var int millimetres a mounting point may lie outside the box of each part it joins (warns beyond) */
    private const MOUNT_BOX_MM = 80;

    /** @var array{0: int, 1: int} millimetres the lowest measured box may lie below and above specs.ground_clearance (warns outside) */
    private const CLEARANCE_MM = [60, 20];

    /** @var list<string> the facings the eight panels need described */
    private const FACINGS = ['left', 'right', 'front', 'rear', 'top'];

    /** @var list<string> the views whose camera must equal the pinned camera field by field */
    private const FIXED_VIEWS = ['MS1', 'MS2', 'MS3', 'MS4', 'MS5', 'MS6', 'MS7'];

    private const PROMPT_IDS = '/\b(?:P\d{2,3}(?:-[KL]\d{2})?|S\d{2}(?:-W\d{2})?|J\d{2,3}|F[1-3](?:-M\d{2})?|L\d{2,3}|M\d{1,2}|BS\d{2}|C[1-3]|SI\d{2,3}|MS[1-8]|D[1-5])\b/';

    private const PLACEHOLDER = '/[{}<>]|\bTODO\b|\bTBD\b/';

    /**
     * @param  ?array<string, mixed>  $response  the decoded answer
     * @param  array<string, mixed>  $dna  the DNA the run sent
     * @param  array<string, mixed>  $sourceIdentity  the source identity the run sent
     * @param  array<string, mixed>  $modelSheet  the pinned model-sheet presentation the run sent
     * @param  list<string>  $forbidden  the forbidden words of the pinned prompt
     * @param  list<string>  $schemaErrors  the answer's violations of the pinned schema
     * @return array{version: string, format: string, verdict: string, status: ?string, issues: list<string>, warnings: list<string>, error: ?string, design_hash: ?string, parts_by_state: array<string, list<string>>}
     */
    public static function evaluate(?array $response, string $projectId, string $promptVersion, array $dna, array $sourceIdentity, array $modelSheet, array $forbidden, array $schemaErrors): array
    {
        if ($response === null) {
            return self::result(self::UNSCORED, null, [], 'Phản hồi không đọc được JSON.');
        }

        if (($response['format'] ?? null) !== MotoContract::DESIGN_FORMAT) {
            return self::result(self::UNSCORED, null, [], 'Lượt này ghim hợp đồng '.MotoContract::DESIGN_FORMAT.' nhưng phản hồi khai format '.json_encode($response['format'] ?? null).' — sai hợp đồng, không dùng tiếp.');
        }

        $status = is_string($response['status'] ?? null) ? $response['status'] : null;
        $issues = array_map(static fn (string $error): string => "Schema: {$error}", $schemaErrors);

        if ($status === 'fail') {
            $reasons = array_values(array_filter(array_map(static fn (mixed $flag): string => is_string($flag) ? rtrim(trim($flag), '.') : '', (array) ($response['flags'] ?? []))));

            return self::result(self::FAIL, $status, ['AI báo status fail — không tạo được hồ sơ thiết kế dùng được: '.($reasons !== [] ? implode('; ', $reasons) : 'không nêu lý do').'.', ...$issues]);
        }

        if (($response['project_id'] ?? null) !== $projectId) {
            $issues[] = 'project_id không phải dự án này.';
        }

        if (($response['prompt_version'] ?? null) !== $promptVersion) {
            $issues[] = 'prompt_version là '.json_encode($response['prompt_version'] ?? null, JSON_UNESCAPED_UNICODE)."; lượt chạy này dùng {$promptVersion}.";
        }

        if ($schemaErrors !== []) {
            return self::result(self::FAIL, $status, $issues);
        }

        $data = $response['data'];
        $ids = self::ids($data);
        $figures = self::figureIssues($data, $ids);
        $geometry = self::geometryIssues($data, $ids);
        $warnings = [...$figures['warnings'], ...$geometry['warnings']];
        $issues = [
            ...$issues,
            ...$ids['issues'],
            ...self::referenceIssues($data, $ids),
            ...self::dnaIssues($data, $dna),
            ...$figures['issues'],
            ...$geometry['issues'],
            ...self::configurationIssues($data, $ids),
            ...self::stateIssues($data, $ids),
            ...self::viewIssues($data, $ids, (array) ($modelSheet['cameras'] ?? [])),
            ...self::promptIssues($data, $modelSheet),
            ...self::wordIssues($data, $forbidden, $sourceIdentity),
        ];

        if ($status === 'partial') {
            $blocking = array_values(array_filter($data['source_issues'], static fn (array $issue): bool => $issue['blocking'] === true));
            $issues[] = 'AI báo status partial — hồ sơ được lưu nhưng không render model sheet: '.implode('; ', array_map(static fn (array $issue): string => "{$issue['id']} {$issue['problem']}", $blocking)).'.';
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
                $issues[] = "{$kind} {$id} khai trùng ID.";
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
                    $issues[] = "line {$line['id']} không thuộc hệ {$system['id']} theo tiền tố ID.";
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
                    $issues[] = "mode {$mode['id']} không thuộc {$feature['id']} theo tiền tố ID.";
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
                $issues[] = "{$id} khai ở {$partId} nhưng tiền tố ID là bộ phận khác.";
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
     * @return list<string>
     */
    private static function referenceIssues(array $data, array $ids): array
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
            preg_match('/^C[1-3]$/', $id) === 1 => 'color',
            preg_match('/^SI\d{2,3}$/', $id) === 1 => 'source issue',
            default => 'rule',
        };
        $check = static function (string $path, mixed $refs, bool $present = false) use (&$issues, $known, $kindOf, $ids): void {
            foreach (is_array($refs) ? $refs : [$refs] as $ref) {
                if (! is_string($ref)) {
                    continue;
                }

                if (! isset($known[$kindOf($ref)][$ref])) {
                    $issues[] = "{$path}: {$ref} không có trong hồ sơ.";
                } elseif ($present && isset($ids['parts'][$ref]) && ! $ids['parts'][$ref]['present']) {
                    $issues[] = "{$path}: {$ref} là bộ phận không có trên xe (present false).";
                }
            }
        };

        foreach ($data['parts'] as $part) {
            $check("{$part['id']}.parent", $part['parent']);
            $check("{$part['id']}.relations", array_column($part['relations'], 'target'));
            $check("{$part['id']}.surfaces", array_column($part['surfaces'], 'color_id'));

            if ($part['parent'] === $part['id']) {
                $issues[] = "{$part['id']}.parent: bộ phận không thể là cha của chính nó.";
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
                $issues[] = "{$connection['id']}.between: nối một bộ phận với chính nó.";
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

        foreach ($data['build_states'] as $state) {
            $check("{$state['id']}.refs", $state['refs'] ?? []);
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
            $issues[] = "features có {$id}, không phải signature feature của DNA (".implode(', ', array_keys($wanted)).').';
        }

        foreach (array_diff_key($wanted, $found) as $id => $feature) {
            $issues[] = "features thiếu {$id} của DNA.";
        }

        foreach (array_intersect_key($found, $wanted) as $id => $feature) {
            foreach (['kind', 'visible_at_rest'] as $field) {
                if ($feature[$field] !== ($wanted[$id][$field] ?? null)) {
                    $issues[] = "{$id}.{$field} là ".json_encode($feature[$field]).'; DNA khai '.json_encode($wanted[$id][$field] ?? null).'.';
                }
            }
        }

        $departures = array_column((array) ($dna['departures'] ?? []), 'id');
        $covered = array_column($data['motorcycle']['departures'], 'departure');

        foreach (array_diff($covered, $departures) as $id) {
            $issues[] = "motorcycle.departures có {$id}, không phải departure của DNA.";
        }

        foreach (array_diff($departures, $covered) as $id) {
            $issues[] = "motorcycle.departures chưa nói {$id} của DNA nằm ở đâu trong thiết kế.";
        }

        $platform = (array) ($dna['brief']['platform'] ?? []);

        foreach (['family', 'model', 'era', 'engine_family'] as $field) {
            if (array_key_exists($field, $platform) && $data['motorcycle']['base'][$field] !== $platform[$field]) {
                $issues[] = "motorcycle.base.{$field} là ".json_encode($data['motorcycle']['base'][$field], JSON_UNESCAPED_UNICODE).'; phải chép đúng dna.brief.platform '.json_encode($platform[$field], JSON_UNESCAPED_UNICODE).'.';
            }
        }

        foreach (['height_cm', 'build'] as $field) {
            $given = $dna['brief']['rider'][$field] ?? null;

            if ($given !== null && $data['rider_interface']['rider'][$field] !== $given) {
                $issues[] = "rider_interface.rider.{$field} là ".json_encode($data['rider_interface']['rider'][$field], JSON_UNESCAPED_UNICODE).'; brief của DNA là '.json_encode($given, JSON_UNESCAPED_UNICODE).'.';
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $ids  the result of ids()
     * @return array{issues: list<string>, warnings: list<string>} broken conventions, and coarse box comparisons that only warn
     */
    private static function figureIssues(array $data, array $ids): array
    {
        $issues = [];
        $warnings = [];
        $specs = $data['motorcycle']['specs'];
        $present = array_filter($ids['parts'], static fn (array $part): bool => $part['present']);
        $axles = [];

        foreach (['front' => 'front_wheel', 'rear' => 'rear_wheel'] as $end => $kind) {
            foreach (array_filter($present, static fn (array $part): bool => $part['kind'] === $kind) as $wheel) {
                $box = $wheel['extent'];

                if (abs($box['z_min']) > self::WHEEL_GROUND_MM) {
                    $issues[] = "{$wheel['id']} ({$kind}): hộp bắt đầu ở z {$box['z_min']} mm — bánh xe gồm lốp và phải chạm mặt đất (lệch tối đa ".self::WHEEL_GROUND_MM.' mm).';
                }

                $axles[$end] = $wheel['points']['axle']['x'] ?? ($box['x_from'] + $box['x_to']) / 2;
                $inches = $specs["{$end}_wheel_diameter"]['value'];

                if (is_int($inches) || is_float($inches)) {
                    $height = $box['z_max'] - $box['z_min'];
                    [$least, $most] = self::TYRE_ADDS_MM;

                    if ($height < $inches * 25.4 + $least || $height > $inches * 25.4 + $most) {
                        $warnings[] = "{$wheel['id']}: hộp cao {$height} mm; vành {$inches} inch cộng lốp thường cao thêm {$least}–{$most} mm.";
                    }
                }
            }
        }

        if (isset($axles['front']) && abs($axles['front']) > self::FRONT_AXLE_MM) {
            $issues[] = "Trục bánh trước ở x {$axles['front']} — gốc toạ độ là tâm trục bánh trước (lệch tối đa ".self::FRONT_AXLE_MM.' mm).';
        }

        $wheelbase = $specs['wheelbase']['value'];

        if (isset($axles['front'], $axles['rear']) && (is_int($wheelbase) || is_float($wheelbase))
            && abs($axles['rear'] - $axles['front'] - $wheelbase) > max(self::WHEELBASE_MM, $wheelbase * self::WHEELBASE_SHARE)) {
            $issues[] = 'Hai trục bánh cách nhau '.($axles['rear'] - $axles['front'])." mm, specs.wheelbase là {$wheelbase} mm.";
        }

        $seatHeight = $specs['seat_height']['value'];

        if ((is_int($seatHeight) || is_float($seatHeight)) && abs($data['rider_interface']['seat_point']['z'] - $seatHeight) > self::SEAT_HEIGHT_MM) {
            $issues[] = "rider_interface.seat_point ở z {$data['rider_interface']['seat_point']['z']}, specs.seat_height là {$seatHeight} mm — cùng một điểm ngồi ở tư thế tham chiếu (lệch tối đa ".self::SEAT_HEIGHT_MM.' mm).';
        }

        $clearance = $specs['ground_clearance']['value'];
        $measured = array_filter($present, static fn (array $part): bool => ! in_array($part['kind'], self::CLEARANCE_LEFT_OUT, true));

        if ((is_int($clearance) || is_float($clearance)) && $measured !== []) {
            $lowest = min(array_map(static fn (array $part): float|int => $part['extent']['z_min'], $measured));
            [$below, $above] = self::CLEARANCE_MM;

            if ($lowest < $clearance - $below || $lowest > $clearance + $above) {
                $warnings[] = "Hộp thấp nhất (trừ bánh và chân chống) ở z {$lowest}, specs.ground_clearance là {$clearance} mm.";
            }
        }

        $displacement = $specs['displacement'];
        $nominal = self::NOMINAL_CC[$data['motorcycle']['base']['engine_family']] ?? null;

        if ($displacement['basis'] === 'BASE_NOMINAL' && $nominal !== null && abs($displacement['value'] - $nominal) > $nominal * 0.02) {
            $issues[] = "specs.displacement {$displacement['value']} cc (BASE_NOMINAL) không phải dung tích danh định {$nominal} cc của {$data['motorcycle']['base']['engine_family']}.";
        }

        return ['issues' => $issues, 'warnings' => $warnings];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $ids  the result of ids()
     * @return array{issues: list<string>, warnings: list<string>} broken structure, and coarse box comparisons that only warn
     */
    private static function geometryIssues(array $data, array $ids): array
    {
        $issues = [];
        $warnings = [];
        $curve = $data['motorcycle']['profile_curve'];
        $present = array_filter($ids['parts'], static fn (array $part): bool => $part['present']);

        for ($i = 1; $i < count($curve); $i++) {
            if ($curve[$i][0] <= $curve[$i - 1][0]) {
                $issues[] = 'profile_curve: điểm '.($i + 1).' không lùi về sau so với điểm trước.';
            }
        }

        if ($present !== []) {
            $minX = min(array_map(static fn (array $part): float|int => $part['extent']['x_from'], $present));
            $maxX = max(array_map(static fn (array $part): float|int => $part['extent']['x_to'], $present));
            $first = $curve[0][0];
            $last = $curve[count($curve) - 1][0];

            if (abs($first - $minX) > self::PROFILE_END_MM || abs($last - $maxX) > self::PROFILE_END_MM) {
                $warnings[] = "profile_curve chạy {$first}..{$last}, các hộp bộ phận chạy {$minX}..{$maxX} — đường bao nên đi từ điểm trước nhất tới điểm sau nhất của xe.";
            }
        }

        foreach ($present as $id => $part) {
            $box = $part['extent'];

            if ($box['x_from'] >= $box['x_to'] || $box['y_min'] >= $box['y_max'] || $box['z_min'] >= $box['z_max']) {
                $issues[] = "{$id}.extent rỗng hoặc đảo cận (cận dưới không nhỏ hơn cận trên).";
            }

            if (! in_array($part['kind'], self::ABOVE_PROFILE, true)) {
                $top = self::profileTop($curve, (float) $box['x_from'], (float) $box['x_to']);

                if ($top !== null && $box['z_max'] > $top + self::PROFILE_TOP_MM) {
                    $warnings[] = "{$id}.extent lên tới z {$box['z_max']}, cao hơn profile_curve ({$top}) trên đoạn của nó.";
                }
            }

            foreach ($part['points'] as $name => $point) {
                if (! self::inBox($point, $box, self::POINT_BOX_MM)) {
                    $warnings[] = "{$id}.points.{$name} nằm ngoài hộp của chính bộ phận.";
                }
            }
        }

        foreach ($data['connections'] as $connection) {
            foreach ($connection['between'] as $id) {
                if (isset($present[$id]) && ! self::inBox($connection['point'], $present[$id]['extent'], self::MOUNT_BOX_MM)) {
                    $warnings[] = "{$connection['id']}.point nằm xa hộp của {$id}.";
                }
            }
        }

        $rider = $data['rider_interface'];
        $holders = static fn (array $kinds): array => array_filter($present, static fn (array $part): bool => in_array($part['kind'], $kinds, true));
        $seats = $holders(['seat']);

        if ($seats !== [] && array_filter($seats, static fn (array $seat): bool => self::inBox($rider['seat_point'], $seat['extent'], self::POINT_BOX_MM)) === []) {
            $warnings[] = 'rider_interface.seat_point nằm ngoài hộp mọi bộ phận seat.';
        }

        foreach (['grip_points' => ['handlebar', 'hand_controls'], 'peg_points' => ['foot_controls']] as $group => $kinds) {
            foreach (['left' => 1, 'right' => -1] as $side => $sign) {
                $point = $rider[$group][$side];
                $y = $point['y'] ?? null;

                if (! is_int($y) && ! is_float($y)) {
                    $issues[] = "rider_interface.{$group}.{$side} thiếu y — không xác định được bên trái/phải của người lái.";
                } elseif ($y * $sign <= 0) {
                    $issues[] = "rider_interface.{$group}.{$side}: y = {$y} nằm sai bên (dương là bên trái người lái).";
                }

                if (array_filter($holders($kinds), static fn (array $part): bool => self::inBox($point, $part['extent'], self::POINT_BOX_MM)) === []) {
                    $warnings[] = "rider_interface.{$group}.{$side} nằm ngoài hộp mọi bộ phận ".implode('/', $kinds).'.';
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
            $issues[] = "Không bộ phận nào tả mặt {$facing} — các panel model sheet cần mặt này.";
        }

        return ['issues' => $issues, 'warnings' => $warnings];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $ids  the result of ids()
     * @return list<string>
     */
    private static function configurationIssues(array $data, array $ids): array
    {
        $issues = [];
        $governing = [];

        foreach ($ids['limits'] as $limit) {
            foreach ($limit['governs'] as $id) {
                $governing[$id][] = $limit;
            }
        }

        foreach ($ids['limits'] as $limit) {
            if (array_intersect($limit['governs'], $limit['otherwise_configurations']) !== []) {
                $issues[] = "{$limit['id']}: một cấu hình vừa nằm trong governs vừa là otherwise_configurations.";
            }

            $owners = array_values(array_unique(array_filter(array_map(static fn (string $id): ?string => $ids['configurations'][$id] ?? null, $limit['governs']))));

            foreach ($limit['otherwise_configurations'] as $id) {
                $owner = $ids['configurations'][$id] ?? null;

                if ($owner !== null && $owners !== [] && ! in_array($owner, $owners, true)) {
                    $issues[] = "{$limit['id']}: vị trí dự phòng {$id} thuộc {$owner}, không phải bộ phận mà giới hạn này kiểm soát (".implode(', ', $owners).').';
                }

                if (isset($governing[$id])) {
                    $issues[] = "{$limit['id']}: vị trí dự phòng {$id} lại bị ".implode(', ', array_column($governing[$id], 'id')).' kiểm soát — vị trí dự phòng phải luôn được phép.';
                }
            }

            if ($limit['applies_to']['kind'] === 'part') {
                foreach ($limit['governs'] as $id) {
                    if (($ids['configurations'][$id] ?? null) !== $limit['applies_to']['id']) {
                        $issues[] = "{$limit['id']} áp cho bộ phận {$limit['applies_to']['id']} nhưng governs {$id} của bộ phận khác.";
                    }
                }
            }
        }

        $display = $data['model_sheet']['display_configuration'];

        foreach ($ids['parts'] as $id => $part) {
            foreach ($part['configurations'] as $configuration) {
                if ($configuration['default'] && isset($governing[$configuration['id']])) {
                    $issues[] = "Cấu hình mặc định {$configuration['id']} bị ".implode(', ', array_column($governing[$configuration['id']], 'id')).' kiểm soát — mặc định không cần điều kiện.';
                }
            }

            if ($part['present'] && $part['configurations'] !== []) {
                $shown = array_filter($display, static fn (string $configuration): bool => ($ids['configurations'][$configuration] ?? null) === $id);

                if (count($shown) !== 1) {
                    $issues[] = 'model_sheet.display_configuration chọn '.count($shown)." vị trí của {$id}, cần đúng một.";
                }
            }
        }

        foreach ($display as $id) {
            $partId = $ids['configurations'][$id] ?? null;

            if ($partId !== null && ! $ids['parts'][$partId]['present']) {
                $issues[] = "model_sheet.display_configuration: {$id} thuộc {$partId}, bộ phận không có trên xe.";
            }

            foreach ($governing[$id] ?? [] as $limit) {
                if (! $limit['static_display']) {
                    $issues[] = "model_sheet.display_configuration: {$id} bị {$limit['id']} kiểm soát và giới hạn này không cho trưng bày tĩnh.";
                }
            }
        }

        $onePerPart = static function (string $where, array $configurations) use (&$issues, $ids): void {
            $owners = array_map(static fn (string $id): string => $ids['configurations'][$id] ?? $id, $configurations);

            if (count($owners) !== count(array_unique($owners))) {
                $issues[] = "{$where} đặt hai vị trí cho cùng một bộ phận.";
            }
        };
        $standDown = null;

        foreach ($ids['parts'] as $part) {
            foreach ($part['kind'] === 'side_stand' && $part['present'] ? $part['configurations'] : [] as $configuration) {
                $standDown = $configuration['name'] === 'down' ? $configuration['id'] : $standDown;
            }
        }

        $situation = static function (string $where, array $configurations, array $limitIds, bool $whole) use (&$issues, $ids, $governing, $standDown): void {
            $limits = [];

            foreach ($configurations as $id) {
                foreach ($governing[$id] ?? [] as $limit) {
                    $limits[$limit['id']] = $limit;
                }
            }

            foreach ($limitIds as $id) {
                if (isset($ids['limits'][$id])) {
                    $limits[$id] = $ids['limits'][$id];
                }
            }

            $needs = static fn (string $situation): array => array_keys(array_filter($limits, static fn (array $limit): bool => in_array($situation, $limit['requires'], true)));

            if ($needs('engine_off') !== [] && $needs('engine_running') !== []) {
                $issues[] = "{$where}: ".implode(', ', $needs('engine_off')).' cần tắt máy nhưng '.implode(', ', $needs('engine_running')).' cần nổ máy — các vị trí dùng cùng lúc không thể cùng đạt điều kiện.';
            }

            if ($whole && $needs('side_stand_down') !== [] && ($standDown === null || ! in_array($standDown, $configurations, true))) {
                $issues[] = "{$where}: ".implode(', ', $needs('side_stand_down')).' cần chân chống hạ nhưng tổ hợp không có vị trí down của chân chống.';
            }
        };

        $situation('model_sheet.display_configuration', $display, [], true);

        foreach ($ids['modes'] as $id => $mode) {
            $situation($id, $mode['configurations'], [], false);
        }

        foreach ($data['motorcycle']['signature_moments'] as $moment) {
            $situation($moment['id'], $moment['configurations'], $moment['limits'], true);
            $onePerPart($moment['id'], $moment['configurations']);

            foreach ($moment['configurations'] as $id) {
                foreach ($governing[$id] ?? [] as $limit) {
                    if (! in_array($limit['id'], $moment['limits'], true)) {
                        $issues[] = "{$moment['id']} dùng {$id} nhưng không khai giới hạn {$limit['id']} của nó.";
                    }
                }
            }
        }

        foreach ($ids['modes'] as $id => $mode) {
            $onePerPart($id, $mode['configurations']);
        }

        foreach ($data['motorcycle']['markings'] as $marking) {
            if ($marking['shown_in_model_sheet']) {
                $issues[] = "motorcycle.markings: {$marking['part']} {$marking['kind']} khai hiện trong model sheet nhưng trình bày model sheet cấm logo, chữ và huy hiệu.";
            }
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
                $issues[] = "{$id}: until_state {$part['until_state']} đứng trước from_state.";
            }

            foreach ($part['lighting'] as $light) {
                if ($order[$light['operable']] < $order[$light['installed']]) {
                    $issues[] = "{$light['id']}: operable {$light['operable']} đứng trước installed {$light['installed']}.";
                }

                if ($part['from_state'] !== null && $order[$light['installed']] < $order[$part['from_state']]) {
                    $issues[] = "{$light['id']}: lắp ở {$light['installed']} trước khi {$id} tồn tại ({$part['from_state']}).";
                }
            }
        }

        foreach ($ids['features'] as $feature) {
            $states = array_values(array_filter(array_map(static fn (string $partId): ?int => isset($ids['parts'][$partId]['from_state']) ? $order[$ids['parts'][$partId]['from_state']] : null, $feature['parts']), 'is_int'));

            if ($feature['from_state'] !== null && ($states === [] || $order[$feature['from_state']] < min($states))) {
                $issues[] = "{$feature['id']}.from_state {$feature['from_state']} sớm hơn mọi bộ phận của nó.";
            }
        }

        foreach ($data['build_states'] as $state) {
            if (! isset($order[$state['id']])) {
                continue;
            }

            foreach ($state['in_progress'] as $item) {
                $part = $ids['parts'][$item['part']] ?? null;

                if ($part !== null && self::exists($part, $state['id']) && $order[$part['from_state']] < $order[$state['id']]) {
                    $issues[] = "{$state['id']}: đang làm {$item['part']} nhưng bộ phận đã hoàn chỉnh từ {$part['from_state']}.";
                }
            }

            foreach ($state['exceptions'] as $exception) {
                $part = $ids['parts'][$exception['part']] ?? null;

                if ($part === null) {
                    continue;
                }

                if ($exception['presence'] === 'removed' && ! self::exists($part, $state['id'])) {
                    $issues[] = "{$state['id']}: ngoại lệ removed cho {$exception['part']} nhưng bộ phận chưa tồn tại ở giai đoạn này.";
                }

                if ($exception['presence'] === 'present' && self::exists($part, $state['id']) && $exception['configuration'] === null) {
                    $issues[] = "{$state['id']}: ngoại lệ present cho {$exception['part']} không khác gì trạng thái bình thường.";
                }

                if (is_string($exception['configuration']) && ($ids['configurations'][$exception['configuration']] ?? null) !== $exception['part']) {
                    $issues[] = "{$state['id']}: cấu hình {$exception['configuration']} không thuộc {$exception['part']}.";
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
                $issues[] = "{$view['id']}: không có camera đã ghim để đối chiếu.";
            } else {
                $fields = in_array($view['id'], self::FIXED_VIEWS, true)
                    ? array_keys(array_diff_key($pinned, ['target' => true]) + $view['camera'])
                    : ['projection', 'lens_mm'];

                foreach ($fields as $field) {
                    $written = $view['camera'][$field] ?? null;
                    $expected = $pinned[$field] ?? null;
                    $same = is_int($written) || is_float($written) ? (is_int($expected) || is_float($expected)) && (float) $written === (float) $expected : $written === $expected;

                    if (! $same) {
                        $issues[] = "{$view['id']}.camera.{$field} là ".json_encode($written, JSON_UNESCAPED_UNICODE).'; camera đã ghim là '.json_encode($expected, JSON_UNESCAPED_UNICODE).'.';
                    }
                }
            }

            $listed = [...$view['visible'], ...$view['partial'], ...$view['uncertain']];

            if (count($listed) !== count(array_unique($listed))) {
                $issues[] = "{$view['id']}: một ID nằm ở hai danh sách visible/partial/uncertain.";
            }

            $seen = [...$seen, ...$view['visible'], ...$view['partial']];
        }

        foreach ($ids['features'] as $id => $feature) {
            if ($feature['visible_at_rest'] && ! in_array($id, $seen, true) && array_intersect($feature['parts'], $seen) === []) {
                $issues[] = "{$id} nhìn thấy khi xe đứng yên nhưng không panel nào cho thấy.";
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $modelSheet  the pinned presentation
     * @return list<string>
     */
    private static function promptIssues(array $data, array $modelSheet): array
    {
        $issues = [];
        $prompt = $data['model_sheet']['final_prompt'];
        $max = (int) ($modelSheet['max_chars'] ?? 0);

        if ($max > 0 && mb_strlen($prompt) > $max) {
            $issues[] = 'final_prompt dài '.mb_strlen($prompt)." ký tự, vượt max_chars {$max}.";
        }

        $position = -1;

        foreach ((array) ($modelSheet['panels'] ?? []) as $index => $line) {
            $at = strpos($prompt, (string) $line);

            if ($at === false) {
                $issues[] = 'final_prompt thiếu nguyên văn dòng panel '.($index + 1).": \"{$line}\".";
            } elseif ($at < $position) {
                $issues[] = 'final_prompt đặt dòng panel '.($index + 1).' sai thứ tự.';
            } else {
                $position = $at;
            }
        }

        $base = $data['motorcycle']['base'];
        $names = [$base['make'], 'Harley', 'Davidson', $base['family'], $base['model'], $base['engine_family'], trim((string) preg_replace('/\s*\d.*$/u', '', $base['engine_family']))];

        foreach (array_unique(array_filter($names, static fn (mixed $name): bool => is_string($name) && mb_strlen(trim($name)) >= 3)) as $name) {
            if (MotoContract::mentions($prompt, trim($name))) {
                $issues[] = "final_prompt gọi tên hãng, đời xe hoặc dòng máy \"{$name}\" — model sheet tả bằng hình dáng để không vẽ chữ hay huy hiệu.";
            }
        }

        if (preg_match_all(self::PROMPT_IDS, $prompt, $match) > 0) {
            $issues[] = 'final_prompt chứa ID: '.implode(', ', array_unique($match[0])).' — prompt ảnh không được có ID.';
        }

        $fields = array_values(array_filter(self::keys($data), static fn (string $key): bool => str_contains($key, '_') && str_contains($prompt, $key)));

        if ($fields !== []) {
            $issues[] = 'final_prompt chứa tên trường: '.implode(', ', $fields).'.';
        }

        if (preg_match(self::PLACEHOLDER, $prompt, $match) === 1) {
            $issues[] = "final_prompt còn chỗ trống \"{$match[0]}\".";
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $forbidden
     * @param  array<string, mixed>  $sourceIdentity
     * @return list<string>
     */
    private static function wordIssues(array $data, array $forbidden, array $sourceIdentity): array
    {
        $issues = [];
        $names = [];

        foreach (['name', 'builder', 'designers'] as $field) {
            foreach ((array) (is_array($sourceIdentity[$field] ?? null) ? ($sourceIdentity[$field]['value'] ?? null) : ($sourceIdentity[$field] ?? null)) as $value) {
                if (is_string($value)) {
                    $names[] = $value;
                }
            }
        }

        $sourceNames = MotoContract::blockedForms($names, [$data['motorcycle']['base']['family'], $data['motorcycle']['base']['model']]);

        foreach (self::texts($data, '') as $path => $text) {
            foreach ($forbidden as $word) {
                if (MotoContract::mentions($text, $word)) {
                    $issues[] = "{$path}: dùng từ cấm \"{$word}\".";
                }
            }

            foreach ($sourceNames as $name) {
                if (MotoContract::mentions($text, $name)) {
                    $issues[] = "{$path}: dùng tên nguồn \"{$name}\".";
                }
            }

            if (! str_starts_with($path, 'motorcycle.base.') && (MotoContract::mentions($text, 'Harley') || MotoContract::mentions($text, 'Davidson'))) {
                $issues[] = "{$path}: gọi tên Harley-Davidson ngoài motorcycle.base.";
            }
        }

        return $issues;
    }

    /** @param  array<string, mixed>  $part */
    private static function exists(array $part, string $state): bool
    {
        $order = array_flip(self::PHYSICAL_STATES);

        return $part['present'] && $part['from_state'] !== null && $order[$part['from_state']] <= $order[$state]
            && ($part['until_state'] === null || $order[$state] <= $order[$part['until_state']]);
    }

    /**
     * @param  array<string, mixed>  $point
     * @param  array<string, mixed>  $box
     */
    private static function inBox(array $point, array $box, float $tolerance): bool
    {
        $y = $point['y'] ?? 0;

        return $point['x'] >= $box['x_from'] - $tolerance && $point['x'] <= $box['x_to'] + $tolerance
            && $y >= $box['y_min'] - $tolerance && $y <= $box['y_max'] + $tolerance
            && $point['z'] >= $box['z_min'] - $tolerance && $point['z'] <= $box['z_max'] + $tolerance;
    }

    /** @param  list<array{0: float|int, 1: float|int}>  $curve */
    private static function profileTop(array $curve, float $from, float $to): ?float
    {
        $top = null;

        foreach ($curve as [$x, $z]) {
            if ($x >= $from && $x <= $to) {
                $top = max($top ?? (float) $z, (float) $z);
            }
        }

        foreach ([$from, $to] as $x) {
            for ($i = 0; $i < count($curve) - 1; $i++) {
                [$x1, $z1] = $curve[$i];
                [$x2, $z2] = $curve[$i + 1];

                if ($x >= $x1 && $x <= $x2) {
                    $z = $x2 == $x1 ? max($z1, $z2) : $z1 + ($z2 - $z1) * ($x - $x1) / ($x2 - $x1);
                    $top = max($top ?? (float) $z, (float) $z);

                    break;
                }
            }
        }

        return $top;
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

    /** @return array<string, string> every string of a value by its dotted path */
    private static function texts(mixed $value, string $path): array
    {
        if (is_string($value)) {
            return [$path => $value];
        }

        if (! is_array($value)) {
            return [];
        }

        $texts = [];

        foreach ($value as $key => $item) {
            $id = is_array($item) && is_string($item['id'] ?? null) ? $item['id'] : (string) $key;
            $texts += self::texts($item, $path === '' ? $id : "{$path}.{$id}");
        }

        return $texts;
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
            'issues' => array_values($issues),
            'warnings' => array_values($warnings),
            'error' => $error,
            'design_hash' => $hash,
            'parts_by_state' => $partsByState,
        ];
    }
}
