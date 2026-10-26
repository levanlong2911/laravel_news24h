<?php

declare(strict_types=1);

namespace App\Video\Proxy;

/**
 * Bien dich nguon dong bang cua master da duyet (YachtProxySource) + mapping hinh hoc do nguoi
 * dung xac nhan thanh ProxySpec: hop dong duy nhat cua bo dung proxy.
 *
 * - So lieu doc qua bang facts cua bo model sheet (da chuan hoa met/feet, phan LOA), khong doc
 *   lai van xuoi. Hinh khoi co so (than, boong, khoang rong boong, terrace) theo quy tac tu du
 *   lieu co cau truc; chi tiet nhan dang theo mapping.
 * - He noi bo: X met tinh tu mui ve duoi, y met duong man trai, z met tren duong nuoc thiet ke.
 * - Thieu du lieu thi ghi gap, khong dien mac dinh: thieu y khong thanh truc giua, hai man khong
 *   tu doi xung, so trich dan "approximately 2 by 3 m" phai chi ro chieu va duoc xac nhan.
 *
 * Logic thuan, khong doc DB, khong goi model.
 */
final class ProxySpecCompiler
{
    public const VERSION = 'proxy-spec-v1';

    /** Phep dung: tham so bat buoc / tuy chon; `instances` = dat tai tung moc. */
    public const OPS = [
        'cut' => ['required' => ['from_x', 'to_x', 'width', 'floor_z'], 'optional' => ['radius']],
        'recess' => ['required' => ['from_x', 'to_x', 'width', 'depth'], 'optional' => ['radius']],
        'basin' => ['required' => ['center_x', 'length', 'width', 'rim_z', 'depth'], 'optional' => []],
        'channel' => ['required' => ['from_x', 'from_z', 'to_x', 'to_z', 'width'], 'optional' => []],
        'pocket' => ['required' => ['from_x', 'to_x', 'floor_z', 'wall'], 'optional' => []],
        'bridge' => ['required' => ['center_x', 'top_z', 'thickness', 'length'], 'optional' => []],
        'ribs' => ['required' => ['from_x', 'to_x', 'turn_x', 'turn_y', 'pitch', 'depth', 'half_turn'], 'optional' => []],
        'relief' => ['required' => ['pitch', 'depth'], 'optional' => []],
        'steps' => ['required' => ['width', 'tread'], 'optional' => [], 'instances' => true],
        'disc' => ['required' => ['diameter', 'tilt_deg', 'pedestal'], 'optional' => [], 'instances' => true],
        // size_x / size_y theo truc cua mat (faceBasis o bo dung): up = doc tau / ngang tau; side =
        // doc tau / cao; forward, aft = ngang tau / cao. tilt_deg nghieng mat quanh canh size_x.
        'panel' => ['required' => ['size_x', 'size_y'], 'optional' => ['recess', 'tilt_deg'], 'instances' => true],
        'opening' => ['required' => ['width', 'height'], 'optional' => ['depth'], 'instances' => true],
        // depth: cua theo moc = do lom toi da cho phep; cua theo dai boong = do lui cua kinh.
        'window' => ['required' => ['width', 'height', 'depth'], 'optional' => ['from_x', 'to_x', 'spacing', 'count', 'tilt_deg', 'stagger', 'facets', 'facet_step', 'spread'], 'instances' => true],
        // Phang mat vo (vd. nap dong, vay thu phang): khong doi hinh hoc, chi danh dau vi tri + trang thai.
        'marking' => ['required' => [], 'optional' => [], 'instances' => true],
        'bulwark' => ['required' => ['from_x', 'to_x', 'height', 'thickness'], 'optional' => []],
        'coaming' => ['required' => ['rim', 'height'], 'optional' => []],
        'edge' => ['required' => ['width'], 'optional' => []],
        'rails' => ['required' => ['spacing', 'rail', 'depth', 'marker'], 'optional' => []],
        'keel_blocks' => ['required' => ['spacing', 'height', 'width'], 'optional' => ['from_x', 'to_x']],
        'scaffold' => ['required' => ['offset', 'height'], 'optional' => ['from_x', 'to_x']],
        'omit' => ['required' => [], 'optional' => []],
    ];

    /** Ti le mo hinh BS05 theo spec Phase 3P, dung khi thiet ke khong ghi (gia dinh can xac nhan). */
    public const SCALE_MODEL_RATIO = 25.0;

    /** Loai anh huong cua gia dinh can xac nhan rieng truoc khi duyet. */
    private const CONFIRM = ['identity', 'shape', 'position'];

    /** Phep gan vao bo phan khac qua `of`. */
    private const ATTACHED = ['relief', 'coaming', 'edge', 'rails'];

    public const FACINGS = ['side', 'forward', 'aft', 'up'];

    public const IMPORTANCE = ['identity', 'silhouette', 'detail'];

    /** Loai dai luong cua tham so: x vi tri doc tau, y lech man, z cao do, len chieu dai. */
    private const KINDS = [
        'from_x' => 'x', 'to_x' => 'x', 'center_x' => 'x', 'turn_x' => 'x',
        'floor_z' => 'z', 'from_z' => 'z', 'to_z' => 'z', 'top_z' => 'z', 'rim_z' => 'z',
        'turn_y' => 'y',
        'tilt_deg' => 'deg',
        'count' => 'count', 'facets' => 'count',
        'stagger' => 'ratio',
    ];

    /** Bo phan co so dung theo quy tac (khong can mapping) va ma build state cua chung. */
    private const BASE = [
        'hull' => ['registry' => 'hull_shell', 'label' => 'Thân vỏ (loft từ profile_curve, plan_outline)'],
        'deck_closure' => ['registry' => 'main_deck', 'label' => 'Mặt boong chính khép vỏ'],
        'envelope' => ['registry' => 'superstructure', 'label' => 'Khối thượng tầng theo boong và đường bao'],
    ];

    private const LENGTH_UNITS = ['m' => 1.0, 'mm' => 0.001, 'cm' => 0.01];

    /** @var array<string, array<string, mixed>> */
    private array $facts = [];

    /** @var array<string, array<string, mixed>> */
    private array $landmarks = [];

    /** @var array<string, array<string, mixed>> */
    private array $assumptions = [];

    /** @var list<array<string, string>> */
    private array $gaps = [];

    /** @var array<string, true> */
    private array $used = [];

    /** @var array<string, array<string, mixed>> doi moi (N:) va doi tuong co pham vi (R:) */
    private array $ranges = [];

    private float $loa = 0.0;

    /**
     * @param  array<string, mixed>  $source  YachtProxySource::resolve()
     * @param  array{assumptions: array<string, mixed>, frames: array<string, mixed>, registry: list<string>, tolerances: array<string, mixed>}  $config
     * @param  array<string, mixed>  $mapping  {parts: list, unused: {moc: ly do}, confirmed: {id: gia tri}}
     * @return array<string, mixed>
     */
    public function compile(array $source, array $config, array $mapping): array
    {
        $this->facts = [];
        $this->landmarks = [];
        $this->assumptions = [];
        $this->gaps = [];
        $this->used = [];
        $this->ranges = [];
        $canonical = (array) $source['canonical'];
        $snapshot = (array) $source['facts'];

        foreach ((array) ($snapshot['facts'] ?? []) as $fact) {
            $this->facts[(string) $fact['path']] = $fact;
        }

        if (! $source['eligible']) {
            foreach ((array) $source['problems'] as $problem) {
                $this->gap('blocking', 'source', 'nguồn', (string) $problem);
            }
        }

        if (is_string($snapshot['coordinate_problem'] ?? null)) {
            $this->gap('blocking', 'coordinates', 'coordinate_system', (string) $snapshot['coordinate_problem']);
        }

        $loa = $this->fact('data.canonical.dimensions.loa.value', 'len');
        $this->loa = is_float($loa) && $loa > 0 ? $loa : 0.0;

        if ($this->loa <= 0) {
            $this->gap('blocking', 'hull', 'dimensions.loa', 'Thiếu LOA bằng mét — không quy đổi được vị trí dọc tàu.');
        }

        foreach ((array) ($snapshot['entities'] ?? []) as $entity) {
            if (($entity['kind'] ?? null) === 'landmark') {
                $name = substr((string) $entity['id'], 2);
                $this->landmarks[$name] = [
                    'id' => (string) $entity['id'],
                    'name' => $name,
                    'X' => is_numeric($entity['from'] ?? null) ? round((float) $entity['from'] * $this->loa, 4) : null,
                    'y' => is_numeric($entity['y'] ?? null) ? (float) $entity['y'] : null,
                    'z' => is_numeric($entity['z'] ?? null) ? (float) $entity['z'] : null,
                    'side' => (string) ($entity['side'] ?? 'unknown'),
                    'pair' => $entity['pair'] ?? null,
                    'above_profile' => ($canonical['landmarks'][$name]['above_profile'] ?? null) === true,
                    'role' => null,
                    'source' => (string) $entity['path'],
                ];
            } elseif (in_array($entity['kind'] ?? null, ['innovation', 'ranged'], true)) {
                $this->ranges[(string) $entity['id']] = $entity;
            }
        }

        $innovations = array_values(array_filter((array) ($snapshot['entities'] ?? []), static fn (array $e): bool => ($e['kind'] ?? null) === 'innovation'));
        $hull = $this->hull($canonical, $config, $mapping);

        // Moc tren truc giua nam dung tren profile_curve: moc kiem duong bao (do o buoc kiem),
        // khong phai bo phan can dung.
        foreach ($this->landmarks as $name => $mark) {
            if ($mark['y'] === null && $mark['X'] !== null && $mark['z'] !== null
                && abs($mark['z'] - self::interpolate($hull['profile'], $mark['X'])) <= (float) ($config['tolerances']['profile_m'] ?? 0.05)) {
                $this->landmarks[$name]['role'] = 'profile';
                $this->used[$name] = true;
            }
        }

        $decks = $this->decks($canonical, $hull, (array) $config['tolerances']);
        $parts = $this->baseParts($canonical, $config, $mapping);
        $qa = (array) ($source['qa'] ?? []);
        $states = array_column((array) ($qa['display_state'] ?? []), null, 'part_id');

        foreach (array_values((array) ($mapping['parts'] ?? [])) as $index => $item) {
            $part = $this->part(is_array($item) ? $item : [], $index, $config, $mapping, $innovations, $canonical, $states);

            if ($part !== null) {
                if (isset($parts[$part['id']])) {
                    $this->gap('blocking', 'mapping', $part['id'], 'Trùng mã bộ phận.');

                    continue;
                }

                $parts[$part['id']] = $part;
            }
        }

        $this->relations($parts);
        $this->coverage($parts, $innovations, $mapping, $qa, $states);
        $buildStates = $this->buildStates($canonical, $parts, (array) $config['registry'], $mapping);
        $cameras = $this->cameras($source, $config, $qa);
        $pending = array_values(array_filter($this->assumptions, static fn (array $a): bool => $a['requires_confirmation'] && ! $a['confirmed']));

        foreach ($pending as $assumption) {
            $this->gap('blocking', 'assumption', $assumption['id'], 'Giả định chưa được xác nhận: '.$assumption['label'].'.');
        }

        $spec = [
            'version' => self::VERSION,
            'source' => $source['ids'] + ['source_sha256' => $source['source_sha256'], 'eligible' => $source['eligible']],
            'coordinates' => [
                'design' => $canonical['coordinate_system'] ?? null,
                'internal' => ['X' => 'm từ mũi, dương về đuôi', 'y' => 'm từ trục giữa, dương mạn trái', 'z' => 'm trên đường nước thiết kế'],
                'loa_m' => $this->loa,
                // (X, y, z) -> glTF (X, z, y): he thiet ke la tay trai (X ve duoi, y man trai,
                // z len); glTF tay phai +Y len, +Z man trai. Doi cho y/z la phep doi tay, khong lat man.
                'to_gltf' => [[1, 0, 0, 0], [0, 0, 1, 0], [0, 1, 0, 0], [0, 0, 0, 1]],
            ],
            'geometry' => [
                'hull' => $hull,
                'decks' => $decks,
                'deck_count' => count($decks),
                'deck_count_convention' => $canonical['deck_count_convention'] ?? null,
                'landmarks' => array_values($this->landmarks),
                'parts' => array_values($parts),
            ],
            'display_state' => array_values($states),
            'build_states' => $buildStates,
            'cameras' => $cameras,
            // Danh sach doi moi cua thiet ke: validator kiem lai moi doi moi co bo phan dung that.
            'innovations' => array_values(array_map(static fn (array $e): array => ['id' => substr((string) $e['id'], 2), 'name' => (string) ($e['name'] ?? '')], $innovations)),
            'identity' => array_values(array_map(static fn (array $f): array => ['id' => (string) ($f['id'] ?? ''), 'description' => (string) ($f['description'] ?? ''), 'from_state' => $f['from_state'] ?? null], array_filter((array) ($canonical['identity_features'] ?? []), 'is_array'))),
            'assumptions' => array_values($this->assumptions),
            'gaps' => $this->gaps,
            'versions' => ['compiler' => self::VERSION],
        ];
        $spec['ready'] = $source['eligible'] && ! in_array('blocking', array_column($this->gaps, 'severity'), true);
        $spec['spec_sha256'] = self::sha256($spec);

        return $spec;
    }

    /** @param array<string, mixed> $spec */
    public static function sha256(array $spec): string
    {
        unset($spec['spec_sha256']);

        return hash('sha256', (string) json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Than tau: profile [X, z] va mep port [X, y] tu facts. Hai man chi coi la doi xung khi
     * plan_outline co ca man phai va khop man trai; chi co man trai thi la gia dinh can xac nhan.
     *
     * @param  array<string, mixed>  $canonical
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $mapping
     * @return array<string, mixed>
     */
    private function hull(array $canonical, array $config, array $mapping): array
    {
        $profile = $this->points('profile_curve', count((array) ($canonical['profile_curve'] ?? [])), 'z');
        $plan = $this->points('plan_outline', count((array) ($canonical['plan_outline'] ?? [])), 'y');
        $port = array_values(array_filter($plan, static fn (array $p): bool => $p[1] >= 0));
        $starboard = array_values(array_filter($plan, static fn (array $p): bool => $p[1] < 0));
        usort($profile, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        usort($port, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        if (count($profile) < 3) {
            $this->gap('blocking', 'hull', 'profile_curve', 'profile_curve cần ít nhất 3 điểm [x, z] hợp lệ.');
        }

        if (count($port) < 3) {
            $this->gap('blocking', 'hull', 'plan_outline', 'plan_outline cần ít nhất 3 điểm mạn trái [x, y].');
        }

        $tolerance = (float) ($config['tolerances']['plan_m'] ?? 0.05);
        $symmetry = 'design';

        if ($starboard === []) {
            $symmetry = 'assumed';
            $this->assume('hull.symmetry', 'Thân tàu đối xứng qua trục giữa (plan_outline chỉ có mạn trái)', true, 'shape', true, 'hull', $mapping);
        } else {
            foreach ($starboard as [$x, $y]) {
                if (abs(self::interpolate($port, $x) + $y) > $tolerance) {
                    $symmetry = 'asymmetric';
                }
            }

            if ($symmetry === 'asymmetric') {
                $this->gap('blocking', 'hull', 'plan_outline', 'plan_outline hai mạn không đối xứng (lệch quá '.$tolerance.' m) — bộ dựng hiện chỉ loft thân đối xứng.');
            }
        }

        $shape = [];

        foreach (['G1', 'G2', 'G3', 'G11'] as $code) {
            $shape[$code] = $this->code($code, 'hull', $config, $mapping, 'shape');
        }

        return [
            'loa' => $this->loa,
            'beam' => $this->fact('data.canonical.dimensions.beam.value', 'len'),
            'draft' => $this->fact('data.canonical.dimensions.draft.value', 'len'),
            'profile' => $profile,
            'plan_port' => $port,
            'symmetry' => $symmetry,
            'shape' => $shape,
            'sources' => ['profile' => 'data.canonical.profile_curve', 'plan' => 'data.canonical.plan_outline'],
            'provenance' => $canonical['curve_provenance'] ?? null,
        ];
    }

    /**
     * Boong: cao do va pham vi x tu facts. Doan kin lay `enclosed_segments` neu co, khong thi
     * cac dong `deck_outlines` cung ten, khong thi [start_x, end_x]. Du lieu chi la khoang x
     * (`outline` = x_range), chua phai duong bao 2D. Kiem tran boong trong duong bao chi tren
     * cac doan kin: khoang giua hai doan la khoang rong (khe, notch), khong tinh la vuot vo.
     *
     * @param  array<string, mixed>  $canonical
     * @param  array<string, mixed>  $hull
     * @param  array<string, mixed>  $tolerances
     * @return list<array<string, mixed>>
     */
    private function decks(array $canonical, array $hull, array $tolerances): array
    {
        $outlines = [];

        foreach (array_values((array) ($canonical['deck_outlines'] ?? [])) as $i => $row) {
            if (is_array($row) && is_string($row['deck'] ?? null)) {
                $outlines[$row['deck']][] = $i;
            }
        }

        $decks = [];

        foreach (array_values((array) ($canonical['decks'] ?? [])) as $i => $deck) {
            $base = "data.canonical.decks.{$i}";
            $name = is_array($deck) ? (string) ($deck['name'] ?? '') : '';
            $floor = $this->fact("{$base}.floor_z_m", 'z');
            $ceiling = $this->fact("{$base}.ceiling_z_m", 'z');

            if ($name === '' || $floor === null || $ceiling === null || $ceiling <= $floor) {
                $this->gap('blocking', 'decks', $base, 'Boong thiếu tên, cao độ sàn hoặc trần hợp lệ.');

                continue;
            }

            $segments = [];
            $kind = 'deck_range';

            if (is_array($deck['enclosed_segments'] ?? null) && $deck['enclosed_segments'] !== []) {
                $kind = 'enclosed_segments';

                foreach (array_keys($deck['enclosed_segments']) as $k) {
                    $segments[] = [$this->fact("{$base}.enclosed_segments.{$k}.start_x", 'x'), $this->fact("{$base}.enclosed_segments.{$k}.end_x", 'x')];
                }
            } elseif (count($outlines[$name] ?? []) > 1) {
                $kind = 'deck_outlines';

                foreach ($outlines[$name] as $k) {
                    $segments[] = [$this->fact("data.canonical.deck_outlines.{$k}.start_x", 'x'), $this->fact("data.canonical.deck_outlines.{$k}.end_x", 'x')];
                }
            } else {
                $segments[] = [$this->fact("{$base}.start_x", 'x'), $this->fact("{$base}.end_x", 'x')];
            }

            $segments = array_values(array_filter($segments, static fn (array $s): bool => $s[0] !== null && $s[1] !== null && $s[1] > $s[0]));

            if ($segments === []) {
                $this->gap('blocking', 'decks', $base, "Boong {$name} không có đoạn x hợp lệ.");

                continue;
            }

            $setback = $this->fact("{$base}.setback_m", 'len') ?? 0.0;
            $conflicts = [];

            foreach ($segments as [$from, $to]) {
                for ($k = 0; $k <= 20; $k++) {
                    $X = $from + ($to - $from) * $k / 20;
                    $top = self::interpolate($hull['profile'], $X);

                    if (is_finite($top) && $ceiling > $top + (float) ($tolerances['deck_m'] ?? 0.3)) {
                        $conflicts[] = ['X' => round($X, 2), 'ceiling' => $ceiling, 'profile' => round($top, 2)];
                    }
                }
            }

            if ($conflicts !== []) {
                $worst = $conflicts[array_search(max(array_map(static fn (array $c): float => $c['ceiling'] - $c['profile'], $conflicts)), array_map(static fn (array $c): float => $c['ceiling'] - $c['profile'], $conflicts), true)];
                // Tran boong kin cao hon duong bao tren truc giua la mau thuan da xac dinh (setback
                // chi thu hep mat bang, khong ha duong bao): chan, khong tu sua.
                $this->gap('blocking', 'design_conflict', $base, "Trần boong {$name} ({$ceiling} m) cao hơn đường bao profile trong đoạn kín (tệ nhất X {$worst['X']} m: profile {$worst['profile']} m) — mâu thuẫn thiết kế, không tự sửa"
                    .($kind === 'deck_range' ? '; nếu đoạn đó là khoảng rỗng thì thiết kế phải khai enclosed_segments.' : '.'));
            }

            $decks[] = [
                'name' => $name,
                'number' => is_int($deck['number'] ?? null) ? $deck['number'] : $i + 1,
                'floor' => $floor,
                'ceiling' => $ceiling,
                'setback' => $setback,
                'segments' => array_map(static fn (array $s): array => [round($s[0], 4), round($s[1], 4)], $segments),
                'segments_source' => $kind,
                'outline' => 'x_range',
                'envelope_conflicts' => count($conflicts),
                'source' => $base,
            ];
        }

        usort($decks, static fn (array $a, array $b): int => $a['floor'] <=> $b['floor']);

        return $decks;
    }

    /**
     * Bo phan co so va terrace hoc tu `open_terraces` (du lieu co cau truc: x, cao do san).
     *
     * @param  array<string, mixed>  $canonical
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $mapping
     * @return array<string, array<string, mixed>>
     */
    private function baseParts(array $canonical, array $config, array $mapping): array
    {
        $parts = [];

        foreach (self::BASE as $id => $base) {
            $parts[$id] = ['id' => $id, 'op' => 'base', 'label' => $base['label'], 'registry' => $base['registry'], 'origin' => 'rule', 'importance' => 'silhouette', 'params' => [], 'instances' => []];
        }

        foreach (array_values((array) ($canonical['open_terraces'] ?? [])) as $i => $terrace) {
            $base = "data.canonical.open_terraces.{$i}";
            $id = 'terrace_'.($i + 1);
            $params = [
                'from_x' => $this->param($this->fact("{$base}.start_x", 'x'), 'design', "{$base}.start_x"),
                'to_x' => $this->param($this->fact("{$base}.end_x", 'x'), 'design', "{$base}.end_x"),
                'floor_z' => $this->param($this->fact("{$base}.floor_z_m", 'z'), 'design', "{$base}.floor_z_m"),
                'wall' => $this->code('G8', $id, $config, $mapping, 'dimension', 'len'),
            ];

            if (in_array(null, array_column($params, 'value'), true)) {
                $this->gap('blocking', 'mapping', $base, 'Terrace thiếu start_x, end_x hoặc floor_z_m.');

                continue;
            }

            $parts[$id] = ['id' => $id, 'op' => 'pocket', 'label' => (string) (is_array($terrace) ? ($terrace['name'] ?? $id) : $id), 'registry' => 'terraces', 'origin' => 'rule', 'importance' => 'identity', 'params' => $params, 'instances' => [], 'source' => $base];
        }

        return $parts;
    }

    /**
     * Mot dong mapping -> bo phan da giai so lieu. Loi cua dong ghi gap, bo phan van tra ve
     * (tham so loi = null) de nguoi dung thay het cho can sua.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $mapping
     * @param  list<array<string, mixed>>  $innovations
     * @param  array<string, mixed>  $canonical
     * @param  array<string, array<string, mixed>>  $states
     * @return array<string, mixed>|null
     */
    private function part(array $item, int $index, array $config, array $mapping, array $innovations, array $canonical, array $states): ?array
    {
        $id = is_string($item['id'] ?? null) && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $item['id']) === 1 ? $item['id'] : null;
        $op = is_string($item['op'] ?? null) && isset(self::OPS[$item['op']]) ? $item['op'] : null;

        if ($id === null || $op === null) {
            $this->gap('blocking', 'mapping', "parts.{$index}", 'Dòng mapping thiếu mã hợp lệ hoặc phép dựng không hỗ trợ ('.json_encode($item['op'] ?? null).').');

            return null;
        }

        $spec = self::OPS[$op];
        $innovation = $item['innovation'] ?? null;
        $registry = $item['registry'] ?? null;

        if ($innovation !== null && ! in_array('N:'.$innovation, array_column($innovations, 'id'), true)) {
            $this->gap('blocking', 'mapping', $id, "Đổi mới {$innovation} không có trong thiết kế.");
        }

        if ($op !== 'omit' && ! in_array($registry, (array) $config['registry'], true)) {
            $this->gap('blocking', 'mapping', $id, 'Mã build state (registry) '.json_encode($registry).' không thuộc danh sách bộ phận thi công.');
        }

        $allowed = $innovation === null ? null : ($canonical['feature_geometry'][$innovation]['part_mapping'] ?? null);

        if (is_array($allowed) && $op !== 'omit' && ! in_array($registry, $allowed, true)) {
            $this->gap('blocking', 'mapping', $id, "Registry {$registry} không thuộc part_mapping của {$innovation} trong thiết kế (".implode(', ', $allowed).').');
        }

        if ($op === 'omit' && trim((string) ($item['reason'] ?? '')) === '') {
            $this->gap('blocking', 'mapping', $id, 'Bỏ qua bộ phận phải ghi lý do.');
        }

        // Ly do bang chu khong du de bo cau truc nhan dang: omit chi danh cho chi tiet nho,
        // khong gan voi doi moi, khong mang muc nhan dang/silhouette.
        if ($op === 'omit' && ($innovation !== null || in_array($item['importance'] ?? null, ['identity', 'silhouette'], true))) {
            $this->gap('blocking', 'mapping', $id, 'Không được bỏ (omit) bộ phận của đổi mới hoặc bộ phận nhận dạng/silhouette — phải có phép dựng.');
        }

        $params = [];

        foreach ([...$spec['required'], ...$spec['optional']] as $name) {
            if (! array_key_exists($name, (array) ($item['params'] ?? []))) {
                if (in_array($name, $spec['required'], true)) {
                    $this->gap('blocking', 'mapping', "{$id}.{$name}", 'Thiếu tham số bắt buộc.');
                    $params[$name] = $this->param(null, 'missing', null);
                }

                continue;
            }

            $params[$name] = $this->resolve((array) $item['params'][$name], self::KINDS[$name] ?? 'len', $id, $name, $config, $mapping, $item['importance'] ?? 'detail');
        }

        foreach (array_keys((array) ($item['params'] ?? [])) as $name) {
            if (! in_array($name, [...$spec['required'], ...$spec['optional']], true)) {
                $this->gap('blocking', 'mapping', "{$id}.{$name}", "Phép {$op} không có tham số này.");
            }
        }

        $instances = [];

        if (($spec['instances'] ?? false) === true) {
            foreach ((array) ($item['instances'] ?? []) as $name) {
                $instances[] = $this->instance((string) $name, $id, $item, $mapping);
            }

            // Doi xung sang man kia chi khi mapping khai `mirror` kem ly do va nguoi dung xac nhan;
            // moc da co cap that trong danh sach thi khong nhan ban.
            if (($item['mirror'] ?? false) === true) {
                $note = trim((string) ($item['mirror_note'] ?? ''));

                if ($note === '') {
                    $this->gap('blocking', 'mapping', $id, 'Đối xứng sang mạn kia phải ghi lý do (mirror_note).');
                }

                $names = array_column($instances, 'landmark');

                foreach ($instances as $instance) {
                    $pair = $this->landmarks[$instance['landmark']]['pair'] ?? null;

                    if ($instance['y'] !== null && abs($instance['y']) > 1e-9 && ! in_array(is_string($pair) ? substr($pair, 2) : '', $names, true)) {
                        $instances[] = ['landmark' => $instance['landmark'], 'X' => $instance['X'], 'y' => -$instance['y'], 'z' => $instance['z'], 'side' => $instance['side'] === 'port' ? 'starboard' : 'port', 'mirrored' => true];
                    }
                }

                $this->assume("{$id}.mirror", "{$id} nhân sang mạn đối diện — {$note}", true, 'position', true, $id, $mapping);
            }

            $band = $op === 'window' && isset($params['from_x'], $params['to_x']);

            if ($instances === [] && ! $band) {
                $this->gap('blocking', 'mapping', $id, "Phép {$op} cần danh sách mốc đặt (instances).");
            }
        }

        $facing = $item['facing'] ?? null;

        if (in_array($op, ['opening', 'window', 'panel'], true) && ! in_array($facing, self::FACINGS, true)) {
            $this->gap('blocking', 'mapping', $id, 'Phép '.$op.' cần hướng mặt (facing: '.implode('/', self::FACINGS).').');
        }

        $sides = array_values(array_intersect((array) ($item['sides'] ?? []), ['port', 'starboard']));

        if ($op === 'window' && $instances === [] && $sides === []) {
            $this->gap('blocking', 'mapping', $id, 'Dải cửa theo boong phải ghi mạn (sides) — không tự đối xứng hai mạn.');
        }

        if (isset($item['display_state']) && ! isset($states[$item['display_state']])) {
            $this->gap('blocking', 'mapping', $id, 'display_state '.json_encode($item['display_state']).' không có trong QA của master.');
        }

        $importance = in_array($item['importance'] ?? null, self::IMPORTANCE, true) ? $item['importance'] : 'detail';
        $simplify = trim((string) ($item['simplify'] ?? ''));

        if ($simplify !== '') {
            $this->assume("{$id}.simplify", "Đơn giản hoá {$id}: {$simplify}", true, $importance === 'detail' ? 'appearance' : 'identity', $simplify, $id, $mapping);
        }

        return [
            'id' => $id,
            'op' => $op,
            'label' => (string) ($item['label'] ?? $id),
            'innovation' => $innovation,
            'registry' => $registry,
            'origin' => 'mapping',
            'importance' => $importance,
            'facing' => $facing,
            'sides' => $sides,
            'deck' => $item['deck'] ?? null,
            'of' => $item['of'] ?? null,
            'spans' => $item['spans'] ?? null,
            'display_state' => $item['display_state'] ?? null,
            'reason' => $item['reason'] ?? null,
            'simplify' => $simplify === '' ? null : $simplify,
            'shape' => $op === 'window' ? (string) ($item['shape'] ?? 'rect') : null,
            'glass' => $op === 'window' ? (string) ($item['glass'] ?? 'smoke') : null,
            'params' => $params,
            'instances' => $instances,
        ];
    }

    /**
     * Mot moc dat bo phan. Toa do lay nguyen tu moc (moi man mot moc rieng, khong lat guong).
     * Moc thieu y: chi dung duoc khi dong mapping khai `centreline: true` (bo phan tren truc giua).
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $mapping
     * @return array<string, mixed>
     */
    private function instance(string $name, string $id, array $item, array $mapping): array
    {
        $mark = $this->landmarks[$name] ?? null;

        if ($mark === null || $mark['X'] === null || $mark['z'] === null) {
            $this->gap('blocking', 'mapping', $id, "Mốc {$name} không có trong thiết kế hoặc thiếu x/z.");

            return ['landmark' => $name, 'X' => null, 'y' => null, 'z' => null, 'side' => 'unknown'];
        }

        $this->used[$name] = true;
        $y = $mark['y'];
        $side = $mark['side'];

        if ($y === null) {
            if (($item['centreline'] ?? false) === true && ! in_array($item['facing'] ?? null, ['side'], true)) {
                $y = 0.0;
                $side = 'centre';
                $this->assume("{$id}.centreline", "Mốc {$name} không có y — {$id} đặt trên trục giữa theo mapping", true, 'position', 0.0, $id, $mapping);
            } else {
                $this->gap('blocking', 'mapping', $id, "Mốc {$name} không có y — không mặc định trục giữa; khai `centreline: true` nếu bộ phận nằm trên trục giữa (không dùng cho mặt bên).");
            }
        }

        if (($item['facing'] ?? null) === 'side' && $y !== null && abs($y) < 1e-9) {
            $this->gap('blocking', 'mapping', $id, "Mốc {$name} nằm trên trục giữa nhưng bộ phận quay ra mạn — không xác định được mạn.");
        }

        return ['landmark' => $name, 'X' => $mark['X'], 'y' => $y, 'z' => $mark['z'], 'side' => $side];
    }

    /**
     * Giai mot tham so: {landmark, axis} | {fact} (+ pick voi so trich dan) | {code} | {value, unit, note}.
     * Moi gia tri khong den tu so lieu thiet ke la gia dinh; gia dinh anh huong hinh khoi can xac nhan.
     *
     * @param  array<string, mixed>  $ref
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $mapping
     * @return array<string, mixed>
     */
    private function resolve(array $ref, string $kind, string $id, string $name, array $config, array $mapping, string $importance): array
    {
        $key = "{$id}.{$name}";

        if (isset($ref['landmark'])) {
            $mark = $this->landmarks[(string) $ref['landmark']] ?? null;
            $axis = (string) ($ref['axis'] ?? '');

            if ($mark === null || ! in_array($axis, ['x', 'y', 'z'], true) || $kind !== $axis) {
                $this->gap('blocking', 'mapping', $key, 'Tham chiếu mốc sai: cần mốc có trong thiết kế và trục trùng loại tham số ('.$kind.').');

                return $this->param(null, 'invalid', $ref);
            }

            $this->used[$mark['name']] = true;
            $value = $mark[$axis === 'x' ? 'X' : $axis];

            if ($value === null) {
                $this->gap('blocking', 'mapping', $key, "Mốc {$mark['name']} không có trục {$axis}.");
            }

            return $this->param($value, 'design', $mark['source'].'.'.$axis);
        }

        if (isset($ref['fact'])) {
            $path = str_starts_with((string) $ref['fact'], 'data.') ? (string) $ref['fact'] : 'data.canonical.'.$ref['fact'];
            $fact = $this->facts[$path] ?? $this->facts[$path.'.value'] ?? null;

            if ($fact === null) {
                $this->gap('blocking', 'mapping', $key, "Không có fact {$path}.");

                return $this->param(null, 'invalid', $ref);
            }

            if ($fact['unit'] === 'quoted') {
                $numbers = preg_match_all('/\d+(?:\.\d+)?/', (string) $fact['phrase'], $m) > 0 ? array_map('floatval', $m[0]) : [];
                $unit = preg_match('/(mm|cm|m|metres?|meters?)\s*$/i', (string) $fact['phrase'], $u) === 1 ? strtolower($u[1]) : '';
                $scale = self::LENGTH_UNITS[$unit] ?? (str_starts_with($unit, 'met') ? 1.0 : null);
                $pick = $ref['pick'] ?? (count($numbers) === 1 ? 0 : null);

                if ($kind !== 'len' || $scale === null || ! is_int($pick) || ! isset($numbers[$pick])) {
                    $this->gap('blocking', 'mapping', $key, "Số trích dẫn \"{$fact['phrase']}\" cần chỉ rõ số nào (pick) gán cho tham số chiều {$name}.");

                    return $this->param(null, 'invalid', $ref);
                }

                $value = $numbers[$pick] * $scale;
                $this->assume($key, "{$name} = {$value} m lấy từ cụm trích dẫn \"{$fact['phrase']}\" ({$fact['path']}) — giá trị xấp xỉ, chiều đặt theo mapping", true, $importance === 'detail' ? 'dimension' : 'identity', $value, $id, $mapping);

                return $this->param($value, 'approximate', $fact['path']);
            }

            $value = $this->convert($fact, $kind);

            if ($value === null) {
                $this->gap('blocking', 'mapping', $key, "Fact {$path} có đơn vị ".json_encode($fact['unit']).' không đổi được sang loại '.$kind.'.');
            }

            return $this->param($value, 'design', $fact['path']);
        }

        if (isset($ref['range'])) {
            $entity = $this->ranges[(string) $ref['range']] ?? null;
            $bound = $ref['bound'] ?? null;

            if ($entity === null || $kind !== 'x' || ! in_array($bound, ['from', 'to'], true) || ! is_numeric($entity[$bound] ?? null)) {
                $this->gap('blocking', 'mapping', $key, 'Tham chiếu phạm vi sai: cần {range: N:… hoặc R:…, bound: from|to} có số x trong thiết kế.');

                return $this->param(null, 'invalid', $ref);
            }

            return $this->param((float) $entity[$bound] * $this->loa, 'design', (string) ($entity['range_source'] ?? $entity['path']).'#'.$bound);
        }

        if (isset($ref['code'])) {
            return $this->code((string) $ref['code'], $id, $config, $mapping, $importance === 'detail' ? 'dimension' : 'identity', $kind);
        }

        if (array_key_exists('value', $ref) && is_numeric($ref['value'])) {
            $value = (float) $ref['value'];
            $unit = (string) ($ref['unit'] ?? '');

            if ($kind === 'x' && $unit === 'fraction_of_loa') {
                $value *= $this->loa;
            } elseif (in_array($kind, ['deg', 'count', 'ratio'], true) ? $unit !== ['deg' => 'deg', 'count' => 'count', 'ratio' => 'ratio'][$kind] : ! isset(self::LENGTH_UNITS[$unit])) {
                $this->gap('blocking', 'mapping', $key, 'Giá trị nhập tay phải ghi đơn vị đúng loại tham số ('.$kind.').');

                return $this->param(null, 'invalid', $ref);
            } elseif (isset(self::LENGTH_UNITS[$unit])) {
                $value *= self::LENGTH_UNITS[$unit];
            }

            $note = trim((string) ($ref['note'] ?? ''));

            if ($note === '') {
                $this->gap('blocking', 'mapping', $key, 'Giá trị nhập tay phải ghi lý do (note).');
            }

            $this->assume($key, "{$name} = ".self::fmt($value).($kind === 'deg' ? '°' : (in_array($kind, ['count', 'ratio'], true) ? '' : ' m'))." — {$note}", true, $importance === 'detail' ? 'dimension' : 'identity', $value, $id, $mapping);

            return $this->param($value, 'assumption', 'mapping');
        }

        $this->gap('blocking', 'mapping', $key, 'Tham số phải là {landmark, axis}, {fact}, {code} hoặc {value, unit, note}.');

        return $this->param(null, 'invalid', $ref);
    }

    /**
     * Gia dinh chung trong config proxy (G*): "G6.1" la phan tu 1.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $mapping
     * @return array<string, mixed>
     */
    private function code(string $code, string $owner, array $config, array $mapping, string $affects, ?string $kind = null): array
    {
        [$key, $index] = array_pad(explode('.', $code, 2), 2, null);
        $entry = $config['assumptions'][$key] ?? null;
        $value = is_array($entry) ? $entry['value'] : null;
        $value = is_array($value) ? ($index === null ? $value : ($value[(int) $index] ?? null)) : ($index === null ? $value : null);

        if ($value === null) {
            $this->gap('blocking', 'mapping', "{$owner}:{$code}", "Không có giả định {$code} trong cấu hình proxy.");

            return $this->param(null, 'invalid', ['code' => $code]);
        }

        $this->assume($key, (string) $entry['label'].' = '.(is_array($entry['value']) ? implode(' ; ', $entry['value']) : $entry['value']).' '.$entry['unit'], true, $affects, $entry['value'], $owner, $mapping, (string) $entry['unit']);

        if ($kind !== null) {
            $converted = self::convertCode($value, (string) $entry['unit'], $index, $kind, $this->loa);

            if ($converted === null) {
                $this->gap('blocking', 'mapping', "{$owner}:{$code}", "Giả định {$code} (đơn vị ".self::unitOf((string) $entry['unit'], $index).") không dùng được cho tham số loại {$kind}.");
            }

            $value = $converted;
        }

        return $this->param(is_numeric($value) ? (float) $value : $value, 'assumption', 'config:'.$code);
    }

    /**
     * Quan he giua bo phan: cau phai bac qua mot phep khoet co that va nam trong pham vi cua no;
     * relief phai gan vao bo phan co that.
     *
     * @param  array<string, array<string, mixed>>  $parts
     */
    private function relations(array $parts): void
    {
        foreach ($parts as $part) {
            if ($part['op'] === 'bridge') {
                $cut = $parts[$part['spans'] ?? ''] ?? null;
                $X = $part['params']['center_x']['value'] ?? null;

                if ($cut === null || ! in_array($cut['op'], ['cut', 'recess', 'basin'], true)) {
                    $this->gap('blocking', 'mapping', $part['id'], 'Cầu phải khai `spans` là mã một phép khoét có trong mapping.');
                } elseif (is_float($X) && isset($cut['params']['from_x']['value'], $cut['params']['to_x']['value'])
                    && ($X < $cut['params']['from_x']['value'] || $X > $cut['params']['to_x']['value'])) {
                    $this->gap('blocking', 'mapping', $part['id'], "Tâm cầu X {$X} m nằm ngoài phạm vi khoét {$cut['id']}.");
                }
            }

            if (in_array($part['op'], self::ATTACHED, true) && ! isset($parts[$part['of'] ?? ''])) {
                $this->gap('blocking', 'mapping', $part['id'], "Phép {$part['op']} phải khai `of` là mã bộ phận có trong spec.");
            }
        }
    }

    /**
     * Do phu: moi doi moi phai co bo phan (hoac omit co ly do); moc MS8 phai co hinh hoc; trang
     * thai the hien cua QA phai gan vao bo phan; moc chua dung la canh bao.
     *
     * @param  array<string, array<string, mixed>>  $parts
     * @param  list<array<string, mixed>>  $innovations
     * @param  array<string, mixed>  $mapping
     * @param  array<string, mixed>  $qa
     * @param  array<string, array<string, mixed>>  $states
     */
    private function coverage(array $parts, array $innovations, array $mapping, array $qa, array $states): void
    {
        foreach ($innovations as $innovation) {
            $code = substr((string) $innovation['id'], 2);

            if (array_filter($parts, static fn (array $p): bool => ($p['innovation'] ?? null) === $code && $p['op'] !== 'omit') === []) {
                $this->gap('blocking', 'coverage', (string) $innovation['id'], "Đổi mới {$code} ({$innovation['name']}) chưa có bộ phận dựng — thêm mapping có phép dựng (không dùng omit).");
            }
        }

        foreach ((array) ($qa['ms8_target']['entities'] ?? []) as $entity) {
            $name = str_starts_with((string) $entity, 'L:') ? substr((string) $entity, 2) : (string) $entity;

            if (! isset($this->used[$name])) {
                $this->gap('blocking', 'coverage', (string) $entity, "Mốc mục tiêu MS8 {$name} chưa gắn vào bộ phận nào — ảnh kiểm MS8 không có hình học để lấy khung.");
            }
        }

        $linked = array_filter(array_column($parts, 'display_state'));

        foreach (array_keys($states) as $state) {
            if (! in_array($state, $linked, true)) {
                $this->gap('warning', 'coverage', (string) $state, 'Trạng thái thể hiện của QA chưa gắn vào bộ phận nào.');
            }
        }

        $unused = (array) ($mapping['unused'] ?? []);

        foreach ($this->landmarks as $name => $mark) {
            if (! isset($this->used[$name]) && ! isset($unused[$name])) {
                $this->gap('warning', 'coverage', $mark['id'], "Mốc {$name} chưa dùng cho bộ phận nào (khai `unused` kèm lý do nếu không cần dựng).");
            }
        }
    }

    /**
     * Build state: moi bo phan co mat phai co it nhat mot bo phan proxy cung registry. BS05 la
     * mo hinh ti le: lay ti le tu thiet ke neu co.
     *
     * @param  array<string, mixed>  $canonical
     * @param  array<string, array<string, mixed>>  $parts
     * @param  list<string>  $registry
     * @param  array<string, mixed>  $mapping
     * @return list<array<string, mixed>>
     */
    private function buildStates(array $canonical, array $parts, array $registry, array $mapping): array
    {
        $byRegistry = [];

        foreach ($parts as $part) {
            if ($part['op'] !== 'omit' && is_string($part['registry'] ?? null)) {
                $byRegistry[$part['registry']][] = $part['id'];
            }
        }

        $states = [];

        foreach (array_values((array) ($canonical['build_states'] ?? [])) as $i => $state) {
            if (! is_array($state) || ! is_string($state['id'] ?? null)) {
                continue;
            }

            $present = array_values(array_map('strval', (array) ($state['present_parts'] ?? [])));
            $absent = array_values(array_map('strval', (array) ($state['absent_parts'] ?? [])));

            foreach (array_diff([...$present, ...$absent], $registry) as $unknown) {
                $this->gap('warning', 'build_states', $state['id'], "Bộ phận {$unknown} không thuộc danh sách thi công.");
            }

            $missing = array_values(array_filter($present, static fn (string $p): bool => ($byRegistry[$p] ?? []) === []));

            foreach ($missing as $part) {
                $this->gap('blocking', 'build_states', "{$state['id']}:{$part}", "{$state['id']} có {$part} nhưng proxy chưa có bộ phận dựng cho mã này — bổ sung mapping hoặc giả định.");
            }

            [$scale, $scaleBasis] = [null, null];

            if (($state['kind'] ?? null) === 'scale_model') {
                [$scale, $scaleBasis] = [$this->fact('data.canonical.design_parameters.scale_model_ratio.value', 'raw'), 'design'];

                // Thiet ke khong ghi ti le: dung gia tri cua spec Phase 3P lam gia dinh co so cu
                // the, phai xac nhan — khong vua thieu ti le vua bao du dieu kien.
                if ($scale === null || $scale <= 0) {
                    [$scale, $scaleBasis] = [self::SCALE_MODEL_RATIO, 'assumption'];
                    $this->assume("{$state['id']}.scale", "Tỉ lệ mô hình {$state['id']} = 1:".self::fmt(self::SCALE_MODEL_RATIO).' theo spec Phase 3P (thiết kế không ghi tỉ lệ)', true, 'shape', self::SCALE_MODEL_RATIO, $state['id'], $mapping);
                }
            }

            $states[] = [
                'id' => $state['id'],
                'kind' => (string) ($state['kind'] ?? ''),
                'present' => $present,
                'absent' => $absent,
                'parts' => array_values(array_merge(...array_map(static fn (string $p): array => $byRegistry[$p] ?? [], $present ?: ['']))),
                'surfaces' => (array) ($state['surfaces_by_part'] ?? []),
                'scale' => $scale,
                'scale_basis' => $scaleBasis,
                'missing' => $missing,
                'source' => "data.canonical.build_states.{$i}",
            ];
        }

        if ($states === []) {
            $this->gap('warning', 'build_states', 'build_states', 'Thiết kế không có build_states.');
        }

        return $states;
    }

    /**
     * Camera kiem: khung o 960x1080 cua bo model sheet (khong co gian), goc theo cau hinh
     * frames. Cao do "eye level" va goc MS8 la gia dinh danh nghia, chua hieu chinh theo anh.
     *
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $qa
     * @return list<array<string, mixed>>
     */
    private function cameras(array $source, array $config, array $qa): array
    {
        $cameras = [];

        foreach ((array) $source['views'] as $view) {
            $frame = $config['frames'][$view['id']] ?? null;

            if (! is_array($frame)) {
                $this->gap('blocking', 'cameras', (string) $view['id'], 'Không có cấu hình camera cho góc này.');

                continue;
            }

            $used = [];
            $angle = function (mixed $value) use (&$used, $config): ?float {
                if (is_int($value) || is_float($value)) {
                    return (float) $value;
                }

                [$key, $index] = array_pad(explode('.', (string) $value, 2), 2, null);
                $entry = $config['assumptions'][$key]['value'] ?? null;
                $used[] = (string) $value;

                return is_numeric($entry) ? (float) $entry : (is_array($entry) && is_numeric($entry[(int) $index] ?? null) ? (float) $entry[(int) $index] : null);
            };
            $target = ($frame['target'] ?? 'yacht') === 'yacht'
                ? ['kind' => 'yacht']
                : ['kind' => 'entities', 'innovation' => $qa['ms8_target']['innovation'] ?? null, 'landmarks' => array_values(array_map(static fn (string $e): string => str_starts_with($e, 'L:') ? substr($e, 2) : $e, (array) ($qa['ms8_target']['entities'] ?? [])))];

            $camera = [
                'view' => (string) $view['id'],
                'label' => (string) $view['view'],
                'projection' => (string) $frame['projection'],
                'azimuth_deg' => $angle($frame['azimuth']),
                'elevation_deg' => isset($frame['elevation']) ? $angle($frame['elevation']) : null,
                'eye_height_m' => isset($frame['eye_height']) ? $angle($frame['eye_height']) : null,
                'focal_mm' => isset($frame['focal_mm']) ? (float) $frame['focal_mm'] : null,
                'width' => (int) $view['width'],
                'height' => (int) $view['height'],
                'target' => $target,
                'assumptions' => $used,
                'status' => $used === [] ? 'nominal' : 'nominal_assumed',
                'source' => 'config:video.superyacht.model_sheet.frames',
                'frames_sha256' => hash('sha256', (string) json_encode($config['frames'], JSON_UNESCAPED_SLASHES)),
            ];

            if ($target['kind'] === 'entities' && $target['landmarks'] === []) {
                $this->gap('blocking', 'cameras', $camera['view'], 'QA của master không có mốc mục tiêu MS8.');
            }

            // Gia dinh camera danh nghia: chua hieu chinh theo anh model sheet (buoc doi chieu).
            foreach ($used as $code) {
                $key = explode('.', $code)[0];
                $entry = (array) ($config['assumptions'][$key] ?? []);
                $this->assume($key, (string) ($entry['label'] ?? $key).' = '.(is_array($entry['value'] ?? null) ? implode(' ; ', $entry['value']) : ($entry['value'] ?? '?')).' '.($entry['unit'] ?? ''), false, 'camera', $entry['value'] ?? null, $camera['view'], []);
            }

            $cameras[] = $camera;
        }

        if ($cameras !== []) {
            // Goc/lens/eye level lay tu cau hinh hien hanh, master khong luu camera 3D: du xem
            // truoc, chua du cho buoc doi chieu chinh thuc.
            $this->gap('warning', 'cameras', 'cameras', 'Camera là thông số danh nghĩa từ cấu hình hiện hành, chưa đóng băng theo master — phải hiệu chỉnh và lưu camera có phiên bản trước bước đối chiếu chính thức.');
        }

        return $cameras;
    }

    /**
     * Danh sach diem [x, gia tri] cua profile/plan doc qua facts (x doi sang met).
     *
     * @return list<array{0: float, 1: float}>
     */
    private function points(string $list, int $count, string $kind): array
    {
        $points = [];

        for ($i = 0; $i < $count; $i++) {
            $x = $this->fact("data.canonical.{$list}.{$i}.0", 'x');
            $v = $this->fact("data.canonical.{$list}.{$i}.1", $kind);

            if ($x !== null && $v !== null) {
                $points[] = [round($x, 4), round($v, 4)];
            }
        }

        return $points;
    }

    /** So cua mot fact theo loai dai luong; null neu thieu hoac sai don vi. */
    private function fact(string $path, string $kind): ?float
    {
        $fact = $this->facts[$path] ?? null;

        return $fact === null ? null : $this->convert($fact, $kind);
    }

    /** @param array<string, mixed> $fact */
    private function convert(array $fact, string $kind): ?float
    {
        $value = (float) $fact['value'];
        $unit = $fact['unit'];

        return match (true) {
            $kind === 'raw' => $value,
            $kind === 'x' && $unit === 'fraction_of_loa' => $this->loa > 0 ? $value * $this->loa : null,
            $kind === 'z' && in_array($unit, ['m_above_dwl', 'm'], true) => $value,
            $kind === 'y' && in_array($unit, ['m_offset', 'm'], true) => $value,
            in_array($kind, ['len', 'z', 'y'], true) && is_string($unit) && preg_match('/^(mm|cm|m)(?:\s|$)/', $unit, $m) === 1 => $value * self::LENGTH_UNITS[$m[1]],
            $kind === 'deg' && in_array($unit, ['deg', 'degrees', '°'], true) => $value,
            $kind === 'count' && ($unit === null || ! preg_match('/^(mm|cm|m)\b/', (string) $unit)) => $value,
            default => null,
        };
    }

    /** @return array{value: mixed, basis: string, source: mixed} */
    private function param(mixed $value, string $basis, mixed $source): array
    {
        return ['value' => is_float($value) ? round($value, 4) : $value, 'basis' => $basis, 'source' => $source];
    }

    /**
     * Ghi mot gia dinh. Gia dinh anh huong nhan dang, hinh khoi hoac vi tri phai xac nhan rieng
     * truoc khi duyet; gia dinh kich thuoc cua chi tiet nho duoc liet ke va duyet cung proxy.
     * Xac nhan chi con hieu luc khi gia tri luc xac nhan trung gia tri hien tai.
     *
     * @param  array<string, mixed>  $mapping
     */
    private function assume(string $id, string $label, bool $confirm, string $affects, mixed $value, string $owner, array $mapping, ?string $unit = null): void
    {
        $existing = $this->assumptions[$id] ?? null;
        $confirmed = array_key_exists($id, (array) ($mapping['confirmed'] ?? [])) && self::same($mapping['confirmed'][$id], $value);
        $required = $confirm && in_array($affects, self::CONFIRM, true);

        $this->assumptions[$id] = [
            'id' => $id,
            'label' => $label,
            'value' => $value,
            // Don vi cua gia tri tho (gia dinh config): validator quy doi lai dung mot cach.
            'unit' => $unit ?? ($existing['unit'] ?? null),
            'affects' => $affects,
            'used_by' => array_values(array_unique([...($existing['used_by'] ?? []), $owner])),
            'requires_confirmation' => $required || ($existing['requires_confirmation'] ?? false),
            'confirmed' => $confirmed,
        ];
    }

    private function gap(string $severity, string $scope, string $subject, string $message): void
    {
        $this->gaps[] = ['severity' => $severity, 'scope' => $scope, 'subject' => $subject, 'message' => $message];
    }

    /** @param list<array{0: float, 1: float}> $points */
    public static function interpolate(array $points, float $x): float
    {
        if ($points === []) {
            return NAN;
        }

        if ($x <= $points[0][0]) {
            return $points[0][1];
        }

        for ($i = 1, $n = count($points); $i < $n; $i++) {
            if ($x <= $points[$i][0]) {
                [$a, $b] = [$points[$i - 1], $points[$i]];

                return $b[0] === $a[0] ? max($a[1], $b[1]) : $a[1] + ($b[1] - $a[1]) * ($x - $a[0]) / ($b[0] - $a[0]);
            }
        }

        return $points[count($points) - 1][1];
    }

    /**
     * Quy doi gia tri tho cua gia dinh config sang tham so, theo DUNG don vi khai trong config
     * ("° ; m" la don vi tung phan tu): m giu nguyen, mm/cm doi sang met, "phần LOA" nhan LOA
     * (chi cho vi tri/chieu dai), ° cho goc. Don vi khong hop loai tham so: null.
     */
    public static function convertCode(mixed $value, string $units, ?string $index, string $kind, float $loa): ?float
    {
        $unit = self::unitOf($units, $index);
        $lengths = ['x', 'y', 'z', 'len'];

        return match (true) {
            ! is_int($value) && ! is_float($value) => null,
            $unit === 'phần LOA' && in_array($kind, ['x', 'len'], true) && $loa > 0 => (float) $value * $loa,
            isset(self::LENGTH_UNITS[$unit]) && in_array($kind, $lengths, true) => (float) $value * self::LENGTH_UNITS[$unit],
            $unit === '°' && $kind === 'deg' => (float) $value,
            default => null,
        };
    }

    public static function unitOf(string $units, ?string $index): string
    {
        $list = array_map('trim', explode(';', $units));

        return $list[$index === null ? 0 : (int) $index] ?? $list[0];
    }

    /** Loai dai luong cua mot tham so (x, y, z, len, deg, count, ratio). */
    public static function kindOf(string $param): string
    {
        return self::KINDS[$param] ?? 'len';
    }

    /**
     * Gia tri xac nhan trung gia tri dang dung: so so theo gia tri (25 va 25.0 la mot, JSON luu DB
     * co the bo .0), mang so tung phan tu, con lai so dung nguyen.
     */
    public static function same(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return abs((float) $a - (float) $b) <= 1e-4;
        }

        if (is_array($a) && is_array($b) && array_is_list($a) && array_is_list($b) && count($a) === count($b)) {
            return array_filter(array_map(static fn (mixed $x, mixed $y): bool => self::same($x, $y), $a, $b), static fn (bool $ok): bool => ! $ok) === [];
        }

        return $a === $b;
    }

    private static function fmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
