<?php

declare(strict_types=1);

namespace App\Video\Moto;

/**
 * The ids a moto design record defines and what the refs of a build state may name.
 *
 * One registry and one policy serve the design check, the diagnosis of a stored run and the hand
 * revision of a run, so the three never keep two sets of rules. An id is read by its form first. A
 * form of a kind the refs do not take is a wrong kind even when the id exists elsewhere in the
 * record; an id of a kind they take must exist in the list of that kind. A form no kind has is never
 * read as an identity rule, and a part that exists is not asked to be on the motorcycle: a stage may
 * name a part the design leaves out when it tells a decision.
 */
final class MotoDesignRefs
{
    public const VERSION = 'moto-design-refs-v1';

    /** @var list<string> the kinds the refs of a build state name in the themed record (MotoDesign 2.x story_refs) */
    public const STORY_REF_KINDS = ['part', 'system', 'line', 'connection', 'feature', 'configuration', 'light', 'rule'];

    /** @var array<string, string> every kind of id by the form that names it, in the order the forms are tried: the longer forms of a part and a system before them, an identity rule last */
    private const FORMS = [
        'configuration' => '/^P\d{2,3}-K\d{2}$/',
        'light' => '/^P\d{2,3}-L\d{2}$/',
        'part' => '/^P\d{2,3}$/',
        'line' => '/^S\d{2}-W\d{2}$/',
        'system' => '/^S\d{2}$/',
        'connection' => '/^J\d{2,3}$/',
        'mode' => '/^F[1-3]-M\d{2}$/',
        'feature' => '/^F[1-3]$/',
        'limit' => '/^L\d{2,3}$/',
        'moment' => '/^M\d+$/',
        'color' => '/^C[1-9]\d*$/',
        'role' => '/^ROLE\d{2}$/',
        'state' => '/^BS(?:0[1-9]|1[0-2])$/',
        'source_issue' => '/^SI\d{2,3}$/',
        'view' => '/^MS[1-8]$/',
        'departure' => '/^D[1-5]$/',
        'rule' => '/^[A-Z0-9]+-[A-Z0-9-]{1,40}$/',
    ];

    /** @var array<string, string> the name of each kind, as the design panel writes it */
    private const LABELS = [
        'part' => 'bộ phận',
        'configuration' => 'cấu hình bộ phận',
        'light' => 'nguồn sáng',
        'system' => 'hệ thống',
        'line' => 'đường dây/ống',
        'connection' => 'kết nối',
        'feature' => 'tính năng',
        'mode' => 'mode của tính năng',
        'limit' => 'giới hạn vận hành',
        'moment' => 'khoảnh khắc',
        'color' => 'màu',
        'role' => 'vai trò',
        'state' => 'build state',
        'source_issue' => 'source issue',
        'view' => 'view model sheet',
        'departure' => 'departure',
        'rule' => 'quy tắc nhận dạng',
    ];

    /** @var array<string, string> where the record keeps the ids of each kind */
    private const HOMES = [
        'part' => 'parts',
        'configuration' => 'parts[*].configurations',
        'light' => 'parts[*].lighting',
        'system' => 'systems',
        'line' => 'systems[*].lines',
        'connection' => 'connections',
        'feature' => 'features',
        'mode' => 'features[*].modes',
        'limit' => 'operational_limits',
        'moment' => 'motorcycle.signature_moments',
        'color' => 'motorcycle.palette',
        'role' => 'participant_roles',
        'state' => 'build_states',
        'source_issue' => 'source_issues',
        'view' => 'model_sheet.views',
        'departure' => 'motorcycle.departures',
        'rule' => 'identity_rules',
    ];

    /** @return ?string the kind whose form the id has; null for a form no kind has */
    public static function kindOf(string $id): ?string
    {
        foreach (self::FORMS as $kind => $form) {
            if (preg_match($form, $id) === 1) {
                return $kind;
            }
        }

        return null;
    }

    public static function label(string $kind): string
    {
        return self::LABELS[$kind] ?? $kind;
    }

    public static function home(string $kind): string
    {
        return self::HOMES[$kind] ?? $kind;
    }

    /**
     * @param  array<string, mixed>  $data  the data of a design record, read as far as it goes: a list the record lacks gives no id
     * @return array<string, array<string, true>> every id the record defines, by kind
     */
    public static function registry(array $data): array
    {
        $ids = array_fill_keys(array_keys(self::FORMS), []);
        $add = static function (string $kind, mixed $id) use (&$ids): void {
            if (is_string($id) && $id !== '') {
                $ids[$kind][$id] = true;
            }
        };
        $each = static fn (mixed $list): array => array_values(array_filter(is_array($list) ? $list : [], 'is_array'));

        foreach ($each($data['parts'] ?? null) as $part) {
            $add('part', $part['id'] ?? null);

            foreach ($each($part['configurations'] ?? null) as $configuration) {
                $add('configuration', $configuration['id'] ?? null);
            }

            foreach ($each($part['lighting'] ?? null) as $light) {
                $add('light', $light['id'] ?? null);
            }
        }

        foreach ($each($data['systems'] ?? null) as $system) {
            $add('system', $system['id'] ?? null);

            foreach ($each($system['lines'] ?? null) as $line) {
                $add('line', $line['id'] ?? null);
            }
        }

        foreach ($each($data['features'] ?? null) as $feature) {
            $add('feature', $feature['id'] ?? null);

            foreach ($each($feature['modes'] ?? null) as $mode) {
                $add('mode', $mode['id'] ?? null);
            }
        }

        $motorcycle = is_array($data['motorcycle'] ?? null) ? $data['motorcycle'] : [];

        foreach ([
            'connection' => $data['connections'] ?? null,
            'limit' => $data['operational_limits'] ?? null,
            'moment' => $motorcycle['signature_moments'] ?? null,
            'color' => $motorcycle['palette'] ?? null,
            'role' => $data['participant_roles'] ?? null,
            'state' => $data['build_states'] ?? null,
            'source_issue' => $data['source_issues'] ?? null,
            'view' => $data['model_sheet']['views'] ?? null,
            'rule' => $data['identity_rules'] ?? null,
        ] as $kind => $list) {
            foreach ($each($list) as $record) {
                $add($kind, $record['id'] ?? null);
            }
        }

        foreach ($each($motorcycle['departures'] ?? null) as $departure) {
            $add('departure', $departure['departure'] ?? null);
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $data  the data of a themed design record
     * @return list<array{state: string, index: int, ref: mixed, code: string, kind: ?string, exists: bool, candidate: ?string, message: string}> every ref of a build state that is a duplicate, not a text, of no kind, of a kind the refs do not take, or of a kind they take but missing from the record; in the order of the states and their refs
     */
    public static function storyRefProblems(array $data): array
    {
        $registry = self::registry($data);
        $problems = [];

        foreach ((array) ($data['build_states'] ?? []) as $state) {
            if (! is_array($state) || ! is_string($state['id'] ?? null)) {
                continue;
            }

            $seen = [];

            foreach (array_values((array) ($state['refs'] ?? [])) as $index => $ref) {
                $problem = self::refProblem($ref, $registry, self::STORY_REF_KINDS, $seen);

                if (is_string($ref)) {
                    $seen[$ref] = true;
                }

                if ($problem !== null) {
                    $problem = ['state' => $state['id'], 'index' => $index, 'ref' => $ref] + $problem;
                    $problems[] = $problem + ['message' => self::describe($problem)];
                }
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, array<string, true>>  $registry  the result of registry()
     * @param  list<string>  $allowed  the kinds the field takes
     * @param  array<string, true>  $seen  the refs before this one in the same field
     * @return ?array{code: string, kind: ?string, exists: bool, candidate: ?string} null for a ref the field takes and the record defines
     */
    public static function refProblem(mixed $ref, array $registry, array $allowed, array $seen = []): ?array
    {
        if (! is_string($ref)) {
            return ['code' => 'not_text', 'kind' => null, 'exists' => false, 'candidate' => null];
        }

        if (isset($seen[$ref])) {
            return ['code' => 'duplicate', 'kind' => self::kindOf($ref), 'exists' => true, 'candidate' => null];
        }

        $kind = self::kindOf($ref);

        if ($kind === null) {
            return ['code' => 'unknown_kind', 'kind' => null, 'exists' => false, 'candidate' => self::candidate($ref, $registry, $allowed)];
        }

        if (! in_array($kind, $allowed, true)) {
            return ['code' => 'wrong_kind', 'kind' => $kind, 'exists' => isset($registry[$kind][$ref]), 'candidate' => null];
        }

        if (! isset($registry[$kind][$ref])) {
            return ['code' => 'missing', 'kind' => $kind, 'exists' => false, 'candidate' => self::candidate($ref, $registry, $allowed)];
        }

        return null;
    }

    /**
     * @param  array{state: string, index: int, ref: mixed, code: string, kind: ?string, exists: bool, candidate: ?string}  $problem
     * @return string the problem with the ref as it is written, its place and the reason, in the words of the design panel
     */
    public static function describe(array $problem): string
    {
        $kind = $problem['kind'];
        $label = $kind === null ? '' : self::label($kind);
        $candidate = $problem['candidate'] === null ? '' : '; có id gần giống là '.self::display($problem['candidate']).' — chỉ thay khi đó đúng là ý của bản thiết kế';
        $reason = match ($problem['code']) {
            'not_text' => 'refs chỉ chứa chuỗi id.',
            'duplicate' => 'id này đã có ở phía trước trong cùng refs.',
            'unknown_kind' => 'không phải dạng id của loại nào trong hồ sơ — refs của build state chỉ nhận id '.self::allowedText().$candidate.'.',
            'wrong_kind' => "là id {$label} — refs của build state không nhận loại này, chỉ nhận id ".self::allowedText()
                .($problem['exists'] ? "; {$label} này đã được ghi ở ".self::home((string) $kind) : "; hồ sơ cũng không có {$label} nào mang id này").'.',
            default => "không có {$label} nào mang id này trong ".self::home((string) $kind).$candidate.'.',
        };

        return "{$problem['state']}.refs[{$problem['index']}] = ".self::display($problem['ref']).": {$reason}";
    }

    /**
     * @param  list<array{code: string, path: string, value: mixed, message: string, branches: list<string>, schema: ?string}>  $diagnostics  PhaseOneEvidence::schemaDiagnostics() of the answer
     * @param  array<string, mixed>  $data  the data of that answer, for the ids a ref is compared with
     * @param  bool  $themed  the answer is a themed record, whose build states take the refs STORY_REF_KINDS names
     * @return list<string> one line for each violation with the schema it breaks, its path and the value the answer holds there; a ref of a build state of a themed record is named by its state and told by the refs policy
     */
    public static function schemaIssues(array $diagnostics, array $data, bool $themed): array
    {
        $registry = $themed ? self::registry($data) : [];
        $states = array_values((array) ($data['build_states'] ?? []));

        return array_map(static function (array $diagnostic) use ($registry, $states, $themed): string {
            $schema = $diagnostic['schema'] === null ? 'Schema' : (string) preg_replace('/^[^\/]*\//', '', $diagnostic['schema']);
            $path = $diagnostic['path'] === '' ? '(gốc)' : $diagnostic['path'];

            if ($themed && $diagnostic['code'] === 'no_branch' && preg_match('#^/data/build_states/(\d+)/refs/(\d+)$#', $diagnostic['path'], $at) === 1) {
                $state = $states[(int) $at[1]] ?? null;
                $problem = self::refProblem($diagnostic['value'], $registry, self::STORY_REF_KINDS);

                if (is_array($state) && is_string($state['id'] ?? null) && $problem !== null) {
                    return "{$schema} {$path} — ".self::describe(['state' => $state['id'], 'index' => (int) $at[2], 'ref' => $diagnostic['value']] + $problem);
                }
            }

            $branches = $diagnostic['branches'] === [] ? '' : ' Các dạng: '.implode(' | ', $diagnostic['branches']).'.';

            return "{$schema} {$path} = ".self::summary($diagnostic['value']).": {$diagnostic['message']}.{$branches}";
        }, $diagnostics);
    }

    /** @return string a value short enough for one line: a scalar as JSON, a list by its size, an object by its keys */
    private static function summary(mixed $value): string
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
            $keys = array_keys($value);

            return $keys === [] ? '{}' : 'đối tượng {'.implode(', ', array_slice($keys, 0, 8)).(count($keys) > 8 ? ', …' : '').'}';
        }

        if (is_array($value)) {
            return array_is_list($value) ? 'mảng '.count($value).' phần tử' : 'đối tượng {'.implode(', ', array_slice(array_keys($value), 0, 8)).'}';
        }

        return self::display($value);
    }

    /** @return string the value as JSON, with every invisible or unusual character escaped so P23 and "P23 " never read alike */
    public static function display(mixed $value): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
        $plain = json_encode($value, $flags | JSON_UNESCAPED_UNICODE);

        if (! is_string($plain)) {
            return '(không đọc được giá trị)';
        }

        return preg_match('/[\p{C}\p{Zl}\p{Zp}\x{00A0}\x{2000}-\x{200F}\x{2028}-\x{202F}\x{205F}-\x{206F}\x{FEFF}]/u', $plain) === 1
            ? (string) json_encode($value, $flags)
            : $plain;
    }

    private static function allowedText(): string
    {
        $names = array_map(static fn (string $kind): string => $kind === 'rule' ? 'identity rule' : $kind, self::STORY_REF_KINDS);

        return implode(', ', array_slice($names, 0, -1)).' hoặc '.end($names);
    }

    /**
     * @param  array<string, array<string, true>>  $registry
     * @param  list<string>  $allowed
     * @return ?string the one id of a kind the field takes that the ref names once its case and spaces are set aside; null when there is none or more than one
     */
    private static function candidate(string $ref, array $registry, array $allowed): ?string
    {
        $normal = strtoupper((string) preg_replace('/\s+/u', '', $ref));
        $found = [];

        foreach ($allowed as $kind) {
            foreach (array_keys($registry[$kind] ?? []) as $id) {
                if ($id !== $ref && strtoupper((string) $id) === $normal) {
                    $found[] = (string) $id;
                }
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }
}
