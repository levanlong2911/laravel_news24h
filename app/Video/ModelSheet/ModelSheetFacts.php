<?php

declare(strict_types=1);

namespace App\Video\ModelSheet;

/**
 * Bang du kien model sheet tu MOT ban thiet ke Phase 3 da luu.
 *
 * Lap chi muc MOI gia tri so trong `canonical` (ke ca diem profile/plan) — khong doc theo danh
 * sach duong dan co dinh, vi moi luot Phase 3 tu dat ten khoa. Phan loai moc theo phia/tang (doc
 * truong nguon neu co, mau thuan voi toa do thi bao), lap danh sach ung vien UU TIEN cho tung
 * goc, do phu bat buoc (innovation, identity feature, must-avoid, nguon trang thai), muc tieu
 * MS8 co cau truc va ban nhap prompt. Ket qua la dau vao cho QA (Sonnet) va bo kiem.
 *
 * Logic thuan, khong doc DB. Khong them hinh hoc: moi so lay tu thiet ke; vai so suy ra truc
 * tiep tu diem cua thiet ke ghi ro cong thuc; doi don vi ghi ro he so.
 */
final class ModelSheetFacts
{
    public const VERSION = 'model-sheet-facts-v6';

    /** Goc chi thay mot man. */
    public const VIEW_SIDES = ['MS1' => 'port', 'MS2' => 'port', 'MS3' => 'starboard', 'MS7' => 'port'];

    public const SIDES = ['port', 'starboard', 'centre', 'both'];

    public const LEVELS = ['below_waterline', 'hull', 'deck', 'top'];

    /** Bo phan vo/khoi ngoai that duoc phep lam vat che (ten khoa trong nhom exterior* cua yacht_bible). */
    public const ENVELOPE_PARTS = ['silhouette', 'hull', 'superstructure', 'bow', 'stern', 'transom', 'deckhouse', 'mast', 'mast_and_radar', 'mast_radar', 'bulwark', 'foredeck', 'aft_deck', 'water_access', 'platform'];

    /** Danh sach diem: vi tri trong cap -> truc. */
    private const POINT_LISTS = ['profile_curve' => ['x', 'z'], 'plan_outline' => ['x', 'y']];

    private const FEET = 0.3048;

    /** @var array<string, array<string, mixed>> */
    private array $facts = [];

    /** @var array<string, string> path => fact id */
    private array $byPath = [];

    /** @var array<string, array<string, mixed>> */
    private array $entities = [];

    /** @var array{y: ?float, z: ?float} he so doi sang met */
    private array $scale = ['y' => null, 'z' => null];

    /** @var list<string> */
    private array $conflicts = [];

    private ?string $coordinateProblem;

    /**
     * @param  array<string, mixed>  $design  cau tra loi Phase 3 da luu
     * @param  array<string, mixed>  $dna  khoi `dna` cua lan Phase 2 ma thiet ke da dung
     * @param  list<array{id: string, view: string}>  $views  thu tu MS1..MS8 va nhan goc
     * @param  array<string, float>  $priority  video.superyacht.model_sheet.qa.priority
     */
    public function __construct(
        private readonly array $design,
        private readonly array $dna,
        private readonly array $views,
        private readonly array $priority,
    ) {
        $this->coordinateProblem = $this->checkCoordinates();
        $this->walk($this->canonical(), 'data.canonical', [], null);
        $this->walk((array) ($this->bible()['specs'] ?? []), 'data.yacht_bible.specs', [], null);
        $this->derive();
        $this->landmarks();
        $this->ranged();
        $this->innovations();
        $this->quoted();
    }

    public function coordinateProblem(): ?string
    {
        return $this->coordinateProblem;
    }

    /** @return list<string> mau thuan trong chinh du lieu nguon (truong khai bao trai toa do) */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * Du lieu nen tang de xac dinh con tau; thieu thi chan (khong phai truong theo dang mot tau).
     *
     * @return list<string>
     */
    public function minimumProblems(): array
    {
        $problems = [];
        $dimensions = (array) ($this->canonical()['dimensions'] ?? []);

        foreach (['loa', 'beam', 'draft'] as $key) {
            if (! is_numeric($dimensions[$key]['value'] ?? null)) {
                $problems[] = "data.canonical.dimensions.{$key}";
            }
        }

        if (! array_is_list((array) ($this->canonical()['decks'] ?? [])) || ($this->canonical()['decks'] ?? []) === []) {
            $problems[] = 'data.canonical.decks';
        }

        if ($this->entitiesOf('innovation') === []) {
            $problems[] = 'data.yacht_bible.innovations (không có innovation nào có id)';
        }

        $colors = array_filter((array) ($this->bible()['colors'] ?? []), static fn (mixed $c): bool => is_array($c) && is_string($c['name'] ?? null) && trim($c['name']) !== '');

        if ($colors === []) {
            $problems[] = 'data.yacht_bible.colors';
        }

        return $problems;
    }

    /** @return array<string, array<string, mixed>> */
    public function facts(): array
    {
        return $this->facts;
    }

    /** @return array<string, array<string, mixed>> */
    public function entities(): array
    {
        return $this->entities;
    }

    /**
     * Ung vien uu tien theo goc. Nguong vi tri chi de chon, khong phai bang chung che khuat;
     * innovation va chi tiet chua phan loai duoc luon co mat de QA quyet.
     *
     * @return array<string, list<array{entity: string, reason: string}>>
     */
    public function candidates(): array
    {
        $p = $this->priority;
        $range = $this->ms8Target()['range'];
        $rules = [
            'MS1' => fn (array $e): bool => $this->sees($e, 'port'),
            'MS2' => fn (array $e): bool => $this->sees($e, 'port') && (float) $e['from'] <= (float) $p['MS2_max_x'],
            'MS3' => fn (array $e): bool => $this->sees($e, 'starboard') && (float) $e['to'] >= (float) $p['MS3_min_x'],
            'MS4' => fn (array $e): bool => (float) $e['from'] <= (float) $p['MS4_max_x'] || $e['full_beam'] === true,
            'MS5' => fn (array $e): bool => (float) $e['to'] >= (float) $p['MS5_min_x'] || $e['full_beam'] === true,
            'MS6' => fn (array $e): bool => in_array($e['level'], ['deck', 'top', 'unknown'], true),
            'MS7' => fn (array $e): bool => in_array($e['level'], ['hull', 'deck', 'top', 'unknown'], true) && $this->sees($e, 'port'),
            'MS8' => fn (array $e): bool => $range !== null && (float) $e['to'] >= $range[0] - (float) $p['MS8_near_loa'] && (float) $e['from'] <= $range[1] + (float) $p['MS8_near_loa'],
        ];
        $out = [];

        foreach ($this->views as $view) {
            $rule = $rules[$view['id']] ?? static fn (): bool => true;
            $list = [];

            foreach ($this->entities as $id => $entity) {
                if ($entity['kind'] === 'innovation') {
                    $list[] = ['entity' => $id, 'reason' => 'required'];
                } elseif ($entity['from'] === null) {
                    $list[] = ['entity' => $id, 'reason' => 'unclassified'];
                } elseif ($rule($entity)) {
                    $list[] = ['entity' => $id, 'reason' => 'priority'];
                }
            }

            $out[$view['id']] = $list;
        }

        return $out;
    }

    /**
     * Muc tieu MS8 co cau truc, dung chung cho nhan goc, cau camera, chon moc va bo kiem:
     * innovation thiet ke tu khai trong nhan MS8 (`declared`), mac dinh N1 CHI KHI thiet ke khong
     * co nhan; id khai ma khong ton tai (hoac nhan khong neu innovation) la `problem`, khong doi
     * sang chu the khac. Pham vi x neu chinh thiet ke ghi so.
     *
     * @return array{label: ?string, label_path: ?string, innovation: ?string, declared: bool, problem: ?string, name: ?string, path: ?string, range: ?array{0: float, 1: float}, camera_target: ?string}
     */
    public function ms8Target(): array
    {
        $label = null;
        $labelPath = null;

        foreach ((array) ($this->canonical()['model_sheet']['views'] ?? []) as $index => $view) {
            if (is_array($view) && ($view['id'] ?? null) === 'MS8' && is_string($view['view'] ?? null) && trim($view['view']) !== '') {
                [$label, $labelPath] = [trim($view['view']), "data.canonical.model_sheet.views.{$index}.view"];
            }
        }

        $named = $label !== null && preg_match('/\bN(\d+)\b/', $label, $m) === 1 ? 'N'.$m[1] : null;
        $problem = match (true) {
            $named !== null && ! isset($this->entities['N:'.$named]) => "Thiết kế khai MS8 là {$named} ({$labelPath}) nhưng không có innovation {$named} — sửa nguồn, không đổi sang chủ thể khác.",
            $label !== null && $named === null => "Nhãn MS8 của thiết kế ({$labelPath}) không nêu innovation nào (Nx) — chưa hỗ trợ mục tiêu chi tiết ngoài innovation; sửa nhãn trong thiết kế.",
            $label === null && ! isset($this->entities['N:N1']) => 'Thiết kế không khai mục tiêu MS8 và không có N1 để dùng mặc định.',
            default => null,
        };
        $innovation = $problem !== null ? null : ($named ?? 'N1');
        $entity = $innovation === null ? null : $this->entities['N:'.$innovation];
        $name = $entity === null ? null : (string) $entity['name'];

        return [
            'label' => $label,
            'label_path' => $labelPath,
            'innovation' => $innovation,
            'declared' => $named !== null && $problem === null,
            'problem' => $problem,
            'name' => $name,
            'path' => $entity['path'] ?? null,
            'range' => $entity !== null && $entity['from'] !== null ? [(float) $entity['from'], (float) $entity['to']] : null,
            'camera_target' => $innovation === null ? null : 'innovation '.$innovation.($name !== null && $name !== '' ? ' ('.$name.')' : ''),
        ];
    }

    /**
     * Do phu bat buoc: moi innovation (thiet ke + DNA), identity_features, must-avoid va moi
     * nguon trang thai (operational_limits) — trang thai thi phai chon hoac giai trinh.
     *
     * @return list<array{id: string, kind: string, paths: list<string>, text: string}>
     */
    public function coverage(): array
    {
        $items = [];
        $byId = [];

        foreach ((array) ($this->bible()['innovations'] ?? []) as $index => $innovation) {
            if (is_array($innovation) && is_string($innovation['id'] ?? null)) {
                $byId[$innovation['id']] = ['paths' => ["data.yacht_bible.innovations.{$index}"], 'text' => trim((string) ($innovation['name'] ?? ''))];
            }
        }

        foreach ((array) ($this->dna['innovations'] ?? []) as $index => $innovation) {
            if (is_array($innovation) && is_string($innovation['id'] ?? null)) {
                $byId[$innovation['id']]['paths'][] = "dna.innovations.{$index}";
                $byId[$innovation['id']]['text'] = ($byId[$innovation['id']]['text'] ?? '') ?: trim((string) ($innovation['name'] ?? ''));
            }
        }

        ksort($byId, SORT_NATURAL);

        foreach ($byId as $id => $item) {
            $items[] = ['id' => (string) $id, 'kind' => 'innovation', 'paths' => $item['paths'], 'text' => $item['text']];
        }

        foreach ((array) ($this->canonical()['identity_features'] ?? []) as $index => $feature) {
            if (is_array($feature) && is_string($feature['id'] ?? null)) {
                $items[] = ['id' => $feature['id'], 'kind' => 'identity_feature', 'paths' => ["data.canonical.identity_features.{$index}"], 'text' => trim((string) ($feature['description'] ?? ''))];
            }
        }

        [$avoid, $root] = is_array($this->bible()['must_avoid_resolution'] ?? null)
            ? [$this->bible()['must_avoid_resolution'], 'data.yacht_bible.must_avoid_resolution']
            : [(array) ($this->dna['must_avoid'] ?? []), 'dna.must_avoid'];

        foreach (array_values($avoid) as $index => $line) {
            $text = is_string($line) ? $line : (is_array($line) ? (string) ($line['text'] ?? $line['resolution'] ?? json_encode($line)) : '');

            if (trim($text) !== '') {
                $items[] = ['id' => 'MA'.($index + 1), 'kind' => 'must_avoid', 'paths' => ["{$root}.{$index}"], 'text' => trim($text)];
            }
        }

        foreach ($this->stateSources() as $source) {
            $items[] = ['id' => $source['id'], 'kind' => 'state_source', 'paths' => [$source['path']], 'text' => $source['text']];
        }

        return $items;
    }

    /**
     * Nguon trang thai co cau truc: moi muc operational_limits co `feature` (id on dinh OL:feature).
     *
     * @return list<array{id: string, path: string, text: string}>
     */
    public function stateSources(): array
    {
        $sources = [];

        foreach ((array) ($this->canonical()['operational_limits'] ?? []) as $index => $limit) {
            if (is_array($limit) && is_string($limit['feature'] ?? null) && trim($limit['feature']) !== '') {
                $sources[] = ['id' => 'OL:'.trim($limit['feature']), 'path' => "data.canonical.operational_limits.{$index}", 'text' => trim((string) ($limit['otherwise'] ?? ''))];
            }
        }

        return $sources;
    }

    /**
     * Vat che hop le ngoai cac thuc the: bo phan vo/khoi co ten (ENVELOPE_PARTS) trong nhom exterior*
     * cua yacht_bible, va cac vung ngoai that cua anchors.exterior. Mau, thong so... khong co o day.
     *
     * @return list<array{id: string, path: string}>
     */
    public function occluders(): array
    {
        $out = [];

        foreach ($this->bible() as $group => $fields) {
            if (! str_starts_with((string) $group, 'exterior') || ! is_array($fields) || array_is_list($fields)) {
                continue;
            }

            foreach ($fields as $key => $text) {
                if (in_array($key, self::ENVELOPE_PARTS, true) && is_string($text) && trim($text) !== '') {
                    $out[] = ['id' => 'P:'.$key, 'path' => "data.yacht_bible.{$group}.{$key}"];
                }
            }
        }

        foreach ((array) ($this->design['data']['anchors']['exterior'] ?? []) as $key => $text) {
            if (is_string($text) && trim($text) !== '') {
                $out[] = ['id' => 'P:'.$key, 'path' => "data.anchors.exterior.{$key}"];
            }
        }

        return $out;
    }

    /**
     * Ban nhap do code ghep: chi la doi chieu cho QA (bao thay doi), khong gui toi model anh.
     */
    public function draft(): string
    {
        $bible = $this->bible();
        $canonical = $this->canonical();
        $blocks = [];
        $measures = [];

        foreach ((array) ($canonical['dimensions'] ?? []) as $key => $entry) {
            if (is_array($entry) && is_numeric($entry['value'] ?? null)) {
                $measures[] = (in_array($key, ['loa', 'lwl'], true) ? strtoupper((string) $key) : str_replace('_', ' ', (string) $key)).' '.self::number((float) $entry['value']).' '.trim((string) ($entry['unit'] ?? ''));
            }
        }

        $decks = array_values(array_filter((array) ($canonical['decks'] ?? []), static fn (mixed $d): bool => is_array($d) && is_string($d['name'] ?? null)));
        usort($decks, static fn (array $a, array $b): int => ((float) ($a['floor_z_m'] ?? 0)) <=> ((float) ($b['floor_z_m'] ?? 0)));
        $blocks[] = trim(implode(', ', $measures).($decks === [] ? '' : '. '.count($decks).' decks from lowest: '.implode(', ', array_column($decks, 'name')).'.'));

        $exterior = [];

        foreach ($bible as $key => $value) {
            if (str_starts_with((string) $key, 'exterior') && is_array($value) && ! array_is_list($value)) {
                array_push($exterior, ...array_values(array_filter($value, static fn (mixed $v): bool => is_string($v) && trim($v) !== '')));
            }
        }

        $blocks[] = implode(' ', array_map('trim', $exterior));

        $features = [];

        foreach ((array) ($bible['innovations'] ?? []) as $innovation) {
            if (is_array($innovation) && is_string($innovation['id'] ?? null)) {
                $texts = array_filter($innovation, static fn (mixed $v, string|int $k): bool => is_string($v) && trim($v) !== '' && ! in_array($k, ['id', 'plausibility_note'], true), ARRAY_FILTER_USE_BOTH);
                $features[] = $innovation['id'].' '.implode(' ', array_map('trim', $texts));
            }
        }

        $blocks[] = implode("\n", $features);
        $surfaces = array_values(array_filter((array) ($canonical['surfaces'] ?? []), static fn (mixed $v): bool => is_string($v) && trim($v) !== ''));
        $colors = array_map(static fn (array $c): string => trim(($c['name'] ?? '').' '.($c['role'] ?? '')), array_filter((array) ($bible['colors'] ?? []), 'is_array'));
        $blocks[] = trim(implode(' ', $surfaces).($colors === [] ? '' : ' Colours: '.implode(', ', $colors).'.'));
        $blocks[] = implode(' ', array_map(static fn (array $item): string => $item['text'], array_filter($this->coverage(), static fn (array $item): bool => in_array($item['kind'], ['identity_feature', 'must_avoid'], true))));

        return implode("\n\n", array_values(array_filter($blocks, static fn (string $b): bool => trim($b) !== '')));
    }

    /** @return array<string, mixed> anh chup dua vao hash dau vao QA va luu kem ket qua */
    public function snapshot(): array
    {
        return [
            'version' => self::VERSION,
            'coordinate_problem' => $this->coordinateProblem,
            'conflicts' => $this->conflicts,
            'minimum_problems' => $this->minimumProblems(),
            'facts' => array_values($this->facts),
            'entities' => array_values($this->entities),
            'candidates' => $this->candidates(),
            'ms8' => $this->ms8Target(),
            'coverage' => $this->coverage(),
            'state_sources' => $this->stateSources(),
            'occluders' => $this->occluders(),
            'draft' => $this->draft(),
        ];
    }

    /**
     * Viet mot du kien thanh chu (code chiu trach nhiem lam tron va goi dung dai luong).
     *
     * @param  array<string, mixed>  $fact
     */
    public static function render(array $fact): string
    {
        $value = (float) $fact['value'];

        return match ($fact['unit']) {
            // Trich nguyen van tu van xuoi thiet ke: in dung cum chu goc.
            'quoted' => (string) $fact['phrase'],
            'fraction_of_loa' => (abs($value * 100 - round($value * 100)) < 0.05 ? self::number(round($value * 100)).'%' : 'about '.self::number(round($value * 100, 1)).'%').' of the length from the bow',
            'm_above_dwl' => match (true) {
                $value > 0 => self::number($value).' metres above the design waterline',
                $value < 0 => self::number(-$value).' metres below the design waterline',
                default => 'at the design waterline',
            },
            'm_offset' => match (true) {
                $value > 0 => self::number($value).' m to port of the centreline',
                $value < 0 => self::number(-$value).' m to starboard of the centreline',
                default => 'on the centreline',
            },
            null => self::number($value),
            default => self::number($value).' '.$fact['unit'],
        };
    }

    public static function number(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }

    /** @return array<string, mixed> */
    private function canonical(): array
    {
        return (array) ($this->design['data']['canonical'] ?? []);
    }

    /** @return array<string, mixed> */
    private function bible(): array
    {
        return (array) ($this->design['data']['yacht_bible'] ?? []);
    }

    /**
     * Kiem TUNG truc: chieu va don vi. x la phan LOA tinh tu mui; y tinh tu truc giua, duong la
     * man trai; z tren duong nuoc thiet ke. y/z bang met (dung nguyen) hoac feet (doi x0.3048);
     * khong xac nhan duoc truc nao thi chan va khong suy man.
     */
    private function checkCoordinates(): ?string
    {
        $system = $this->canonical()['coordinate_system'] ?? null;
        $axes = ['x' => '', 'y' => '', 'z' => ''];

        if (is_array($system)) {
            foreach (array_keys($axes) as $axis) {
                $axes[$axis] = is_string($system[$axis] ?? null) ? strtolower($system[$axis]) : '';
            }
        } elseif (is_string($system)) {
            foreach (preg_split('/[;\n]+/', strtolower($system)) ?: [] as $clause) {
                if (preg_match('/^\s*(x|y|z)\b/', $clause, $m) === 1 && $axes[$m[1]] === '') {
                    $axes[$m[1]] = $clause;
                }
            }
        }

        $missing = [];

        if (preg_match('/fraction of (the )?(overall length|loa)/', $axes['x']) !== 1 || preg_match('/from (the )?(foremost )?bow/', $axes['x']) !== 1) {
            $missing[] = 'x là phần LOA tính từ mũi';
        }

        if (preg_match('/positive (to(wards)? )?port/', $axes['y']) !== 1) {
            $missing[] = 'y dương là mạn trái (port)';
        }

        // Goc y phai la truc giua: "tu man phai" thi dau y khong con cho biet man.
        if (preg_match('/\b(from|of|to|relative to) (the )?(centre ?line|center ?line|centreline|centerline)\b/', $axes['y']) !== 1) {
            $missing[] = 'gốc của y là trục giữa (centreline)';
        }

        if (preg_match('/above (the )?design waterline/', $axes['z']) !== 1) {
            $missing[] = 'z tính trên đường nước thiết kế';
        }

        foreach (['y', 'z'] as $axis) {
            $metres = preg_match('/\b(metres?|meters?|m)\b/', $axes[$axis]) === 1;
            $feet = preg_match('/\b(feet|foot|ft)\b/', $axes[$axis]) === 1;
            $this->scale[$axis] = $metres === $feet ? null : ($metres ? 1.0 : self::FEET);

            if ($this->scale[$axis] === null) {
                $missing[] = "đơn vị của {$axis} (mét hoặc feet, đúng một loại)";
            }
        }

        return $missing === [] ? null : 'Không xác nhận được hệ toạ độ của thiết kế (data.canonical.coordinate_system): thiếu '.implode('; ', $missing).'.';
    }

    /**
     * @param  array<string|int, mixed>  $parent
     * @param  list<string>  $trail  cac khoa tu goc canonical
     */
    private function walk(mixed $node, string $path, array $trail, string|int|null $key, array $parent = []): void
    {
        if (is_array($node)) {
            foreach ($node as $childKey => $child) {
                $this->walk($child, $path.'.'.$childKey, [...$trail, (string) $childKey], $childKey, $node);
            }

            return;
        }

        if ((! is_int($node) && ! is_float($node)) || $key === null) {
            return;
        }

        // Diem cua danh sach diem: [x, z] cua profile, [x, y] cua plan.
        $list = count($trail) >= 3 ? $trail[count($trail) - 3] : null;
        $property = is_int($key) && isset(self::POINT_LISTS[$list]) ? (self::POINT_LISTS[$list][$key] ?? (string) $key) : (string) $key;
        [$value, $unit, $formula] = $this->measure((float) $node, $property, $parent);
        $this->add($path, $value, $unit, $this->quantityOf($path), $property === 'value' ? (string) ($trail[count($trail) - 2] ?? 'value') : $property, $formula);
    }

    /**
     * Gia tri va don vi cua mot so. Toa do y/z theo he toa do da xac nhan (feet thi doi sang met).
     *
     * @param  array<string|int, mixed>  $parent
     * @return array{0: float, 1: ?string, 2: ?string}
     */
    private function measure(float $value, string $key, array $parent): array
    {
        if ($key === 'value' && is_string($parent['unit'] ?? null) && trim($parent['unit']) !== '') {
            return [$value, trim($parent['unit']), null];
        }

        if ($this->coordinateProblem !== null) {
            return [$value, str_ends_with($key, '_m') ? 'm' : null, null];
        }

        $axis = match (true) {
            $key === 'x' || str_ends_with($key, '_x') => 'x',
            $key === 'y' => 'y',
            $key === 'z' || str_ends_with($key, '_z') => 'z',
            default => null,
        };

        if ($axis === 'x') {
            return [$value, 'fraction_of_loa', null];
        }

        if ($axis !== null) {
            $scale = (float) $this->scale[$axis];

            return [$value * $scale, $axis === 'y' ? 'm_offset' : 'm_above_dwl', $scale === 1.0 ? null : 'converted from feet x '.self::FEET];
        }

        return match (true) {
            str_ends_with($key, '_z_m') => [$value, 'm_above_dwl', null],
            str_ends_with($key, '_m') => [$value, 'm', null],
            str_ends_with($key, '_mm') => [$value, 'mm', null],
            str_ends_with($key, '_deg') => [$value, 'degrees', null],
            default => [$value, null, null],
        };
    }

    /** Ten dai luong de nguoi va QA biet so nay cua bo phan nao: decks[Lee].floor_z_m. */
    private function quantityOf(string $path): string
    {
        $segments = explode('.', $path);
        $node = $this->design;
        $named = [];

        foreach ($segments as $segment) {
            $node = is_array($node) ? ($node[$segment] ?? null) : null;

            if (ctype_digit($segment) && is_array($node) && $named !== []) {
                $name = $node['name'] ?? $node['id'] ?? $node['deck'] ?? $node['feature'] ?? null;
                $named[count($named) - 1] .= '['.(is_string($name) ? $name : $segment).']';

                continue;
            }

            $named[] = $segment;
        }

        return implode('.', array_slice(array_values(array_diff($named, ['data', 'canonical'])), -3));
    }

    private function add(string $path, float $value, ?string $unit, string $quantity, string $property, ?string $formula = null): string
    {
        $id = 'F'.(count($this->facts) + 1);
        $fact = ['id' => $id, 'path' => $path, 'quantity' => $quantity, 'property' => $property, 'value' => $value, 'unit' => $unit];

        if ($formula !== null) {
            $fact['formula'] = $formula;
        }

        $fact['render'] = self::render($fact);
        $this->facts[$id] = $fact;
        $this->byPath[$path] = $id;

        return $id;
    }

    /**
     * So chi co trong van xuoi (innovation, mo ta ngoai that, anchor ngoai that), vi du "approximately
     * 2 by 3 m": lap du kien trich nguyen van — render la dung cum chu goc, khong doi don vi hay lam
     * tron. Duong dan `<truong>#q<n>` nam duoi bo phan nen thuoc dung bo phan do.
     */
    private function quoted(): void
    {
        $roots = ['data.anchors.exterior' => (array) ($this->design['data']['anchors']['exterior'] ?? [])];

        foreach ($this->bible() as $group => $value) {
            if ($group === 'innovations' || str_starts_with((string) $group, 'exterior')) {
                $roots["data.yacht_bible.{$group}"] = (array) $value;
            }
        }

        $pattern = '/(?:(?:about|approximately|around|roughly|nearly|some)\s+)?\d+(?:\.\d+)?(?:\s*(?:by|x|×|to|–|-)\s*\d+(?:\.\d+)?)?\s*(?:mm|cm|m|metres|meters|metre|meter)\b/iu';

        foreach ($roots as $root => $node) {
            $this->quoteIn($node, $root, $pattern);
        }
    }

    /** @param array<string|int, mixed> $node */
    private function quoteIn(array $node, string $path, string $pattern): void
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $this->quoteIn($value, "{$path}.{$key}", $pattern);
            } elseif (is_string($value) && $key !== 'plausibility_note' && preg_match_all($pattern, $value, $m) > 0) {
                foreach ($m[0] as $index => $phrase) {
                    preg_match('/\d+(?:\.\d+)?/', $phrase, $number);
                    $id = 'F'.(count($this->facts) + 1);
                    $fact = ['id' => $id, 'path' => "{$path}.{$key}#q".($index + 1), 'quantity' => $this->quantityOf("{$path}.{$key}").' (quoted)', 'property' => 'quoted', 'value' => (float) $number[0], 'unit' => 'quoted', 'phrase' => trim($phrase)];
                    $fact['render'] = self::render($fact);
                    $this->facts[$id] = $fact;
                    $this->byPath[$fact['path']] = $id;
                }
            }
        }
    }

    /** So suy truc tiep tu diem cua thiet ke (khong them hinh hoc). */
    private function derive(): void
    {
        if ($this->coordinateProblem !== null) {
            return;
        }

        $outline = array_values(array_filter((array) ($this->canonical()['plan_outline'] ?? []), static fn (mixed $p): bool => is_array($p) && is_numeric($p[0] ?? null) && is_numeric($p[1] ?? null)));

        if ($outline !== []) {
            $stern = max(array_map(static fn (array $p): float => (float) $p[0], $outline));
            $ys = array_map(fn (array $p): float => (float) $p[1] * (float) $this->scale['y'], array_filter($outline, static fn (array $p): bool => abs((float) $p[0] - $stern) < 1e-9));

            if (min($ys) < 0 && max($ys) > 0) {
                $this->add('derived.stern_width', max($ys) - min($ys), 'm', 'stern width (plan outline at the aftmost x)', 'stern_width', 'max(y) - min(y) of data.canonical.plan_outline points at x = '.self::number($stern));
            }
        }

        $profile = array_values(array_filter((array) ($this->canonical()['profile_curve'] ?? []), static fn (mixed $p): bool => is_array($p) && is_numeric($p[0] ?? null) && is_numeric($p[1] ?? null)));

        if ($profile !== []) {
            usort($profile, static fn (array $a, array $b): int => (float) $b[1] <=> (float) $a[1]);
            $this->add('derived.profile_top.z', (float) $profile[0][1] * (float) $this->scale['z'], 'm_above_dwl', 'highest point of the profile curve', 'z', 'max z of data.canonical.profile_curve');
            $this->add('derived.profile_top.x', (float) $profile[0][0], 'fraction_of_loa', 'position of the highest point of the profile curve', 'x', 'x of max z in data.canonical.profile_curve');
        }
    }

    private function landmarks(): void
    {
        $marks = $this->canonical()['landmarks'] ?? null;

        if (! is_array($marks) || array_is_list($marks)) {
            return;
        }

        $beam = (float) ($this->canonical()['dimensions']['beam']['value'] ?? 0);

        foreach ($marks as $key => $mark) {
            if (! is_array($mark) || ! is_numeric($mark['x'] ?? null) || ! is_numeric($mark['z'] ?? null)) {
                continue;
            }

            $path = "data.canonical.landmarks.{$key}";
            $known = $this->coordinateProblem === null;
            $y = $known && is_numeric($mark['y'] ?? null) ? (float) $mark['y'] * (float) $this->scale['y'] : null;
            $z = $known ? (float) $mark['z'] * (float) $this->scale['z'] : null;
            $derivedSide = ! $known || $y === null ? 'unknown' : ($y > 0 ? 'port' : ($y < 0 ? 'starboard' : 'centre'));
            $declared = $this->declared($mark, $path);
            [$declaredSide, $declaredLevel, $facing] = [$declared['side'], $declared['level'], $declared['facing']];

            if ($declaredSide !== null && $y !== null && ! $this->sideAgrees($declaredSide, $y)) {
                $this->conflicts[] = "{$path}: side khai \"{$declaredSide}\" nhưng y = ".self::number($y).' m.';
            }

            $this->levelConflict($path, $declaredLevel, $z, 'z');

            if (in_array($facing, ['port', 'starboard'], true) && $y !== null && ! $this->sideAgrees($facing, $y)) {
                $this->conflicts[] = "{$path}: facing khai \"{$facing}\" nhưng y = ".self::number($y).' m.';
            }
            $this->entities["L:{$key}"] = [
                'id' => "L:{$key}",
                'kind' => 'landmark',
                'path' => $path,
                'from' => $known ? (float) $mark['x'] : null,
                'to' => $known ? (float) $mark['x'] : null,
                'y' => $y,
                'z' => $z,
                // Truong nguon uu tien; thieu y va khong khai: chua ro phia, khong mac dinh truc giua.
                'side' => $declaredSide ?? $derivedSide,
                'side_source' => $declaredSide !== null ? 'declared' : ($derivedSide === 'unknown' ? 'unknown' : 'coordinates'),
                'level' => $declaredLevel ?? ($z === null ? 'unknown' : $this->levelOf($z, ($mark['above_profile'] ?? null) === true)),
                'level_source' => $declaredLevel !== null ? 'declared' : 'estimated',
                'full_beam' => $declared['full_beam'],
                // Huong mat chi lay khi nguon khai; gan man chi la goi y, khong chung minh gan tren vo.
                'facing' => $facing,
                'near_side' => $y !== null && $beam > 0 && abs($y) >= 0.6 * $beam / 2,
                'pair' => null,
                'facts' => array_values(array_filter([$this->byPath["{$path}.x"] ?? null, $this->byPath["{$path}.y"] ?? null, $this->byPath["{$path}.z"] ?? null])),
            ];
        }

        // Cap doi xung theo TOA DO (cung x, z, y doi dau), khong theo ten goi.
        foreach ($this->entities as $id => $a) {
            foreach ($this->entities as $otherId => $b) {
                if ($id !== $otherId && $a['kind'] === 'landmark' && $b['kind'] === 'landmark' && $a['y'] !== null && $b['y'] !== null && $a['y'] > 0
                    && abs($a['y'] + $b['y']) < 1e-6 && abs(($a['from'] ?? 0) - ($b['from'] ?? 0)) < 1e-6 && abs((float) $a['z'] - (float) $b['z']) < 1e-6) {
                    $this->entities[$id]['pair'] = $otherId;
                    $this->entities[$otherId]['pair'] = $id;
                }
            }
        }
    }

    /** Chi tiet keo dai trong danh sach canonical (start_x/end_x co ten), tru boong, phong, outline. */
    private function ranged(): void
    {
        foreach ($this->canonical() as $listKey => $list) {
            if (in_array($listKey, ['decks', 'rooms', 'deck_outlines', 'build_states'], true) || ! is_array($list) || ! array_is_list($list)) {
                continue;
            }

            foreach ($list as $index => $item) {
                if (! is_array($item) || ! is_numeric($item['start_x'] ?? null) || ! is_numeric($item['end_x'] ?? null)) {
                    continue;
                }

                $name = is_string($item['name'] ?? null) ? $item['name'] : (string) $index;
                $path = "data.canonical.{$listKey}.{$index}";
                $floor = is_numeric($item['floor_z_m'] ?? null) ? (float) $item['floor_z_m'] : null;
                $declared = $this->declared($item, $path);
                $side = $declared['side'] ?? 'unknown';
                $this->levelConflict($path, $declared['level'], $floor, 'floor_z_m');
                $this->entities["R:{$listKey}:{$name}"] = [
                    'id' => "R:{$listKey}:{$name}",
                    'kind' => 'ranged',
                    'path' => $path,
                    'from' => $this->coordinateProblem === null ? (float) min($item['start_x'], $item['end_x']) : null,
                    'to' => $this->coordinateProblem === null ? (float) max($item['start_x'], $item['end_x']) : null,
                    'y' => null,
                    'z' => $floor,
                    'side' => $side,
                    'side_source' => $side === 'unknown' ? 'unknown' : 'declared',
                    'level' => $declared['level'] ?? ($floor === null ? 'unknown' : $this->levelOf($floor, false)),
                    'level_source' => $declared['level'] !== null ? 'declared' : 'estimated',
                    'full_beam' => $declared['full_beam'],
                    'facing' => $declared['facing'],
                    'near_side' => false,
                    'pair' => null,
                    'facts' => array_values(array_filter(array_map(fn (string $k): ?string => $this->byPath["{$path}.{$k}"] ?? null, ['start_x', 'end_x', 'floor_z_m']))),
                ];
            }
        }
    }

    /**
     * Innovation cua thiet ke. Pham vi x chi lay khi chinh cau mo ta ghi so (x=0.600 to x=0.693333);
     * khong co thi de trong — QA noi innovation voi cac moc co that, code kiem.
     */
    private function innovations(): void
    {
        foreach ((array) ($this->bible()['innovations'] ?? []) as $index => $innovation) {
            if (! is_array($innovation) || ! is_string($innovation['id'] ?? null)) {
                continue;
            }

            $xs = [];
            $source = null;

            foreach ($innovation as $field => $text) {
                if (is_string($text) && preg_match_all('/\bx\s*=\s*(0?\.\d+|1(?:\.0+)?|0)\b/', $text, $m) > 0) {
                    $xs = array_map('floatval', $m[1]);
                    $source = "data.yacht_bible.innovations.{$index}.{$field}";

                    break;
                }
            }

            $ok = $this->coordinateProblem === null && $xs !== [];
            $declared = $this->declared($innovation, "data.yacht_bible.innovations.{$index}");
            $this->entities['N:'.$innovation['id']] = [
                'id' => 'N:'.$innovation['id'],
                'kind' => 'innovation',
                'path' => "data.yacht_bible.innovations.{$index}",
                'name' => trim((string) ($innovation['name'] ?? '')),
                'from' => $ok ? min($xs) : null,
                'to' => $ok ? max($xs) : null,
                'range_source' => $ok ? $source : null,
                'y' => null,
                'z' => null,
                'side' => $declared['side'] ?? 'unknown',
                'side_source' => $declared['side'] !== null ? 'declared' : 'unknown',
                'level' => $declared['level'] ?? 'unknown',
                'level_source' => $declared['level'] !== null ? 'declared' : 'estimated',
                'full_beam' => $declared['full_beam'],
                'facing' => $declared['facing'],
                'near_side' => false,
                'pair' => null,
                'facts' => [],
            ];
        }
    }

    /**
     * Truong khai trong nguon cua mot chi tiet (side, level, facing, full_beam); gia tri ngoai danh
     * sach cho phep la mau thuan nguon.
     *
     * @param  array<string, mixed>  $item
     * @return array{side: ?string, level: ?string, facing: ?string, full_beam: bool}
     */
    private function declared(array $item, string $path): array
    {
        $out = ['side' => null, 'level' => null, 'facing' => null, 'full_beam' => ($item['full_beam'] ?? null) === true];

        foreach (['side' => self::SIDES, 'level' => self::LEVELS, 'facing' => ['port', 'starboard', 'forward', 'aft', 'up', 'down']] as $field => $allowed) {
            if (! is_string($item[$field] ?? null)) {
                continue;
            }

            $value = strtolower(trim($item[$field]));

            if (in_array($value, $allowed, true)) {
                $out[$field] = $value;
            } else {
                $this->conflicts[] = "{$path}.{$field} = \"{$value}\" không thuộc ".implode('/', $allowed).'.';
            }
        }

        if (in_array($out['facing'], ['port', 'starboard'], true) && in_array($out['side'], ['port', 'starboard'], true) && $out['facing'] !== $out['side']) {
            $this->conflicts[] = "{$path}: side khai \"{$out['side']}\" nhưng facing khai \"{$out['facing']}\".";
        }

        return $out;
    }

    /** Tang khai phai khop cao do: duoi duong nuoc <=> cao do am. Dung chung cho moi loai chi tiet. */
    private function levelConflict(string $path, ?string $level, ?float $height, string $field): void
    {
        if ($level !== null && $height !== null && (($level === 'below_waterline') !== ($height < 0))) {
            $this->conflicts[] = "{$path}: level khai \"{$level}\" nhưng {$field} = ".self::number($height).' m.';
        }
    }

    private function sideAgrees(string $side, float $y): bool
    {
        return match ($side) {
            'port' => $y > 0,
            'starboard' => $y < 0,
            'centre' => abs($y) < 0.5,
            default => true,
        };
    }

    private function levelOf(float $z, bool $aboveProfile): string
    {
        if ($z < 0) {
            return 'below_waterline';
        }

        $decks = array_filter((array) ($this->canonical()['decks'] ?? []), static fn (mixed $d): bool => is_array($d) && is_numeric($d['floor_z_m'] ?? null));

        if ($decks === []) {
            return 'unknown';
        }

        $floors = array_filter(array_map(static fn (array $d): float => (float) $d['floor_z_m'], $decks), static fn (float $f): bool => $f >= 0);
        $ceilings = array_map(static fn (array $d): float => (float) ($d['ceiling_z_m'] ?? $d['floor_z_m']), $decks);

        return match (true) {
            $aboveProfile || $z > max($ceilings) => 'top',
            $floors !== [] && $z < min($floors) => 'hull',
            default => 'deck',
        };
    }

    /** @return list<array<string, mixed>> */
    private function entitiesOf(string $kind): array
    {
        return array_values(array_filter($this->entities, static fn (array $e): bool => $e['kind'] === $kind));
    }

    /** Uu tien ung vien cho goc chi thay mot man (phia chua ro van la ung vien de QA quyet). */
    private function sees(array $e, string $side): bool
    {
        return in_array($e['side'], [$side, 'centre', 'both', 'unknown'], true) || $e['pair'] !== null;
    }
}
