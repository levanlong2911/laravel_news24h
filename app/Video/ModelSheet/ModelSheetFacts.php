<?php

declare(strict_types=1);

namespace App\Video\ModelSheet;

/**
 * Chi muc va ban nhap model sheet tu MOT ban thiet ke Phase 3 da luu; khong doc DNA.
 *
 * Chi muc chon nguon ngoai that theo bay nhom (nhan dien, hinh hoc, ngoai that, innovation, be mat,
 * trang thai, ghi chu); moi muc co ma, duong dan nguon giu chi so mang va noi dung goc, so do ghi du
 * don vi. Noi that chi vao khi dinh nghia phan nhin thay tu ngoai (cua so, ho kinh, cua ra ngoai).
 * Ban nhap gom mo ta chung bay phan va tam goc MS1-MS8: camera tu config, moc co vi tri kem cach dat
 * so voi goc, so do va muc chua phan loai chac chan. Khong loai chi tiet vi nam duoi nuoc hay sau mot
 * nguong chieu dai; man dat chi tiet khong phai huong mat; phan chua ro de Sonnet doc tu thiet ke.
 *
 * Logic thuan, khong doc DB. Khong them hinh hoc: so suy ra ghi ro cong thuc.
 */
final class ModelSheetFacts
{
    public const VERSION = 'model-sheet-facts-v10';

    /** @var array<string, string> index group => id prefix */
    public const GROUPS = ['identity' => 'ID', 'geometry' => 'GM', 'exterior' => 'EX', 'innovation' => 'IV', 'surfaces' => 'SF', 'states' => 'ST', 'notes' => 'NT'];

    /** @var list<string> the seven parts of the shared description, in order */
    public const SECTIONS = ['type_and_proportions', 'profile', 'decks_in_envelope', 'glazing_doors_openings', 'innovations', 'asymmetry', 'colours_and_finish'];

    public const SIDES = ['port', 'starboard', 'centre', 'both'];

    public const LEVELS = ['below_waterline', 'hull', 'deck', 'top'];

    /** @var array<string, string> exterior form field => shared section; any other field joins the profile */
    private const FORM_SECTIONS = [
        'superstructure' => 'decks_in_envelope', 'deckhouse' => 'decks_in_envelope', 'mast' => 'decks_in_envelope', 'mast_radar' => 'decks_in_envelope',
        'mast_and_radar' => 'decks_in_envelope', 'helidecks' => 'decks_in_envelope', 'helideck' => 'decks_in_envelope',
        'glazing' => 'glazing_doors_openings', 'water_access' => 'glazing_doors_openings', 'platform' => 'glazing_doors_openings', 'doors' => 'glazing_doors_openings',
    ];

    /** @var list<string> innovation fields about purpose, inside effect or risks, never the outside */
    private const NOT_EXTERIOR = ['plausibility_note', 'effect', 'purpose'];

    /** @var array<string, string> main dimension => role of the measure */
    private const MAIN_DIMENSIONS = ['loa' => 'length', 'lwl' => 'length', 'beam' => 'beam', 'draft' => 'draft'];

    /** @var array<string, list<string>> kind of view => roles of the whole-yacht measures it shows */
    private const VIEW_MEASURES = [
        'profile' => ['length', 'draft', 'height'],
        'bow' => ['beam', 'draft', 'height'],
        'stern' => ['beam', 'stern_width', 'draft', 'height'],
        'plan' => ['length', 'beam', 'stern_width', 'ratio'],
        'oblique' => ['length', 'beam', 'draft', 'height', 'ratio'],
    ];

    private const FEET = 0.3048;

    /** @var string the engineering properties of a part, which take the word before them into the technical term: glazing thickness, installed power, wind-speed threshold */
    private const TECHNICAL_PROPERTY = '(?:displacement|power|capacit(?:y|ies)|scantlings?|stiffness|fatigue|attenuation|noise|vibrations?|thickness(?:es)?|thresholds?|stability|stress(?:es)?|loads?)';

    /** @var string the engineering words that stand alone: verification, calculation and engineering itself */
    private const TECHNICAL_TERM = '(?:kW|MW|acoustic\w*|calculat\w*|classification|engineer\w*|certif\w*|validation|unknowns?|structural)';

    /** @var list<string> the words that show nothing on their own: function words and the vocabulary of verifying a design */
    private const NEUTRAL_WORDS = [
        'a', 'an', 'the', 'and', 'or', 'nor', 'but', 'of', 'to', 'by', 'for', 'with', 'without', 'as', 'at', 'in', 'on', 'from', 'before', 'after', 'until', 'unless', 'than',
        'also', 'only', 'any', 'all', 'each', 'every', 'both', 'either', 'neither', 'no', 'not', 'none', 'this', 'that', 'these', 'those', 'its', 'their', 'it', 'they', 'there',
        'is', 'are', 'be', 'been', 'being', 'was', 'were', 'will', 'would', 'may', 'might', 'must', 'shall', 'should', 'can', 'could', 'has', 'have', 'had', 'do', 'does',
        'remain', 'remains', 'stay', 'stays', 'still', 'yet', 'further', 'final', 'fixed', 'given', 'stated', 'supplied', 'invented', 'figure', 'figures', 'value', 'values',
        'quantity', 'quantities', 'number', 'numbers', 'numerical', 'unresolved', 'pending', 'confirm', 'confirmed', 'confirmation', 'verify', 'verified', 'verification',
        'checked', 'tested', 'assumed', 'assumption', 'assumptions', 'require', 'requires', 'required', 'specialist', 'specialists', 'claim', 'claims', 'real', 'approval', 'approved',
    ];

    /** @var array<string, list<array{id: string, path: string, subject: string, content: string}>> group => items */
    private array $index = [];

    /** @var array<string, int> group => last number */
    private array $counters = [];

    /** @var array<string, list<string>> section => item ids */
    private array $sections = [];

    /** @var array<string, string> item id => role of a whole-yacht measure */
    private array $roles = [];

    /** @var array<string, array{x: float, y: ?float, z: ?float, facing: ?string, side: ?string}> landmark item id => position */
    private array $positions = [];

    /** @var list<string> item ids that carry a size of an innovation */
    private array $innovationMeasures = [];

    /** @var list<string> exterior and innovation item ids without a position code can read */
    private array $unpositioned = [];

    /** @var list<string> */
    private array $guidance = [];

    /** @var array{y: ?float, z: ?float} he so doi sang met */
    private array $scale = ['y' => null, 'z' => null];

    /** @var list<string> */
    private array $conflicts = [];

    private ?string $coordinateProblem;

    /**
     * @param  array<string, mixed>  $design  cau tra loi Phase 3 da luu
     * @param  list<array{id: string, view: string}>  $views  thu tu MS1..MS8 va nhan goc
     * @param  array<string, array<string, mixed>>  $cameras  video.superyacht.model_sheet.cameras
     */
    public function __construct(
        private readonly array $design,
        private readonly array $views,
        private readonly array $cameras,
    ) {
        $this->coordinateProblem = $this->checkCoordinates();
        $this->identity();
        $this->geometry();
        $this->exterior();
        $this->innovations();
        $this->surfaces();
        $this->states();
        $this->notes();
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

        if (array_filter((array) ($this->bible()['innovations'] ?? []), static fn (mixed $n): bool => is_array($n) && is_string($n['id'] ?? null)) === []) {
            $problems[] = 'data.yacht_bible.innovations (không có innovation nào có id)';
        }

        $colors = array_filter((array) ($this->bible()['colors'] ?? []), static fn (mixed $c): bool => is_array($c) && is_string($c['name'] ?? null) && trim($c['name']) !== '');

        if ($colors === []) {
            $problems[] = 'data.yacht_bible.colors';
        }

        return $problems;
    }

    /** @return array<string, list<array{id: string, path: string, subject: string, content: string}>> group => items, in the order of GROUPS */
    public function index(): array
    {
        return array_merge(array_fill_keys(array_keys(self::GROUPS), []), $this->index);
    }

    /** @return list<string> notes items that hold the design's own MS8 guidance */
    public function guidance(): array
    {
        return $this->guidance;
    }

    /**
     * Mo ta chung bay phan va ban nhap tam goc; muc chi ghi ma, noi dung nam mot lan trong chi muc.
     *
     * @return array{shared: array<string, list<string>>, unpositioned: list<string>, views: list<array<string, mixed>>}
     */
    public function draft(): array
    {
        $views = [];

        foreach ($this->views as $view) {
            $views[] = $this->view((string) $view['id'], (string) $view['view'], (array) ($this->cameras[$view['id']] ?? []));
        }

        return [
            'shared' => array_combine(self::SECTIONS, array_map(fn (string $section): array => $this->sections[$section] ?? [], self::SECTIONS)),
            'unpositioned' => $this->unpositioned,
            'views' => $views,
        ];
    }

    /** @return array<string, mixed> anh chup dua vao hash dau vao QA va luu kem bo model sheet */
    public function snapshot(): array
    {
        return [
            'version' => self::VERSION,
            'coordinate_problem' => $this->coordinateProblem,
            'conflicts' => $this->conflicts,
            'minimum_problems' => $this->minimumProblems(),
            'index' => $this->index(),
            'draft' => $this->draft(),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot  the snapshot of the facts of one design
     * @return list<string> what keeps the model sheet from opening that design: coordinate system, base data and conflicts in the source
     */
    public static function blocking(array $snapshot): array
    {
        return array_values(array_filter([
            $snapshot['coordinate_problem'] ?? null,
            ($snapshot['minimum_problems'] ?? []) === [] ? null : 'Thiết kế thiếu dữ liệu nền tảng cho model sheet: '.implode(', ', $snapshot['minimum_problems']).'.',
            ($snapshot['conflicts'] ?? []) === [] ? null : 'Dữ liệu nguồn mâu thuẫn (sửa ở thiết kế): '.implode(' ', $snapshot['conflicts']),
        ]));
    }

    /**
     * @param  array<string, mixed>  $snapshot  the snapshot of the facts of one design
     * @return string the draft with every index id written out, for a person to read
     */
    public static function preview(array $snapshot): string
    {
        $items = array_column(array_merge(...array_values(array_map('array_values', (array) ($snapshot['index'] ?? [])))), null, 'id');
        $draft = (array) ($snapshot['draft'] ?? []);
        $line = static fn (string $id, string $note = ''): string => "[{$id}] ".($items[$id]['subject'] ?? '?').($note === '' ? '' : " — {$note}").': '.($items[$id]['content'] ?? '?');
        $grouped = static fn (array $groups, string $key, string $mark): array => array_merge([], ...array_map(
            static fn (array $group): array => array_map(static fn (string $id): string => $mark.$line($id, (string) $group[$key]), (array) $group['ids']),
            $groups,
        ));
        $out = ['SHARED DESCRIPTION'];

        foreach ((array) ($draft['shared'] ?? []) as $section => $ids) {
            $out[] = '';
            $out[] = '## '.str_replace('_', ' ', (string) $section);
            array_push($out, ...array_map($line, (array) $ids));
        }

        foreach ((array) ($draft['views'] ?? []) as $view) {
            $out[] = '';
            $out[] = "{$view['id']} — {$view['label']}";
            $out[] = 'Camera: '.json_encode($view['camera'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            array_push($out, ...$grouped((array) $view['related'], 'placement', '+ '));
            $out[] = 'Measures: '.implode('; ', array_map(static fn (string $id): string => "[{$id}] ".($items[$id]['content'] ?? '?'), (array) $view['measures']));
            array_push($out, ...$grouped((array) $view['unclassified'], 'reason', '? '));
        }

        $out[] = '';
        $out[] = 'UNPOSITIONED (every view decides from the design)';
        array_push($out, ...array_map($line, (array) ($draft['unpositioned'] ?? [])));

        return implode("\n", $out);
    }

    /** @return string a condition value as the design writes it: the text itself, or the JSON literal of a number, true, false, null or list, never rounded */
    private static function literal(mixed $value): string
    {
        return is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

    /** @return array<string, mixed> */
    private function anchors(): array
    {
        return (array) ($this->design['data']['anchors'] ?? []);
    }

    /** @param  mixed  $scope  the scope of an identity rule or the space a limit governs; an older design gives none */
    private static function exteriorScope(mixed $scope): bool
    {
        return ! is_array($scope) || array_filter($scope, static fn (mixed $id): bool => $id === 'YACHT' || (is_string($id) && str_starts_with($id, 'EXT'))) !== [];
    }

    private function add(string $group, string $path, string $subject, string $content, ?string $section = null): string
    {
        $this->counters[$group] = ($this->counters[$group] ?? 0) + 1;
        $id = self::GROUPS[$group].str_pad((string) $this->counters[$group], 2, '0', STR_PAD_LEFT);
        $this->index[$group][] = ['id' => $id, 'path' => $path, 'subject' => $subject, 'content' => $content];

        if ($section !== null) {
            $this->sections[$section][] = $id;
        }

        return $id;
    }

    private function text(mixed $value): ?string
    {
        if (is_array($value) && array_is_list($value)) {
            $value = implode(' ', array_filter($value, 'is_string'));
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function identity(): void
    {
        $anchors = $this->anchors();

        if (($yacht = $this->text($anchors['yacht'] ?? null)) !== null) {
            $this->add('identity', 'data.anchors.yacht', 'yacht', $yacht, 'type_and_proportions');
        }

        if (($assumption = $this->text($this->bible()['code_assumption'] ?? null)) !== null) {
            $this->add('identity', 'data.yacht_bible.code_assumption', 'construction and propulsion', $assumption, 'type_and_proportions');
        }

        foreach ((array) ($this->canonical()['dimensions'] ?? []) as $key => $entry) {
            $key = (string) $key;

            if (! is_array($entry) || ! is_numeric($entry['value'] ?? null) || preg_match('/^n\d+_/', $key) === 1
                || (! isset(self::MAIN_DIMENSIONS[$key]) && trim((string) ($entry['unit'] ?? '')) !== 'm')) {
                continue;
            }

            $label = in_array($key, ['loa', 'lwl'], true) ? strtoupper($key) : str_replace('_', ' ', $key);
            $id = $this->add('identity', "data.canonical.dimensions.{$key}", $label, $label.' '.self::number((float) $entry['value']).' '.trim((string) ($entry['unit'] ?? '')), 'type_and_proportions');

            if (isset(self::MAIN_DIMENSIONS[$key])) {
                $this->roles[$id] = self::MAIN_DIMENSIONS[$key];
            }
        }

        $decks = $this->bible()['specs']['decks']['value'] ?? null;

        if (is_numeric($decks)) {
            $this->add('identity', 'data.yacht_bible.specs.decks', 'deck count', self::number((float) $decks).' decks', 'type_and_proportions');
        }

        $dimensions = (array) ($this->canonical()['dimensions'] ?? []);
        $loa = is_numeric($dimensions['loa']['value'] ?? null) ? (float) $dimensions['loa']['value'] : null;
        $beam = is_numeric($dimensions['beam']['value'] ?? null) ? (float) $dimensions['beam']['value'] : null;

        if ($loa !== null && $beam !== null && $beam > 0) {
            $id = $this->add('identity', 'derived.length_to_beam', 'proportion', 'length-to-beam ratio '.self::number(round($loa / $beam, 2)).' (LOA '.self::number($loa).' m / beam '.self::number($beam).' m)', 'type_and_proportions');
            $this->roles[$id] = 'ratio';
        }
    }

    private function geometry(): void
    {
        $canonical = $this->canonical();
        $system = $canonical['coordinate_system'] ?? null;

        if (is_array($system) || is_string($system)) {
            $this->add('geometry', 'data.canonical.coordinate_system', 'coordinate system', is_array($system) ? $this->describe($system) : $system, 'profile');
        }

        foreach ($this->forms() as [$group, $key, $text]) {
            if ($key === 'silhouette') {
                $this->add('geometry', "data.yacht_bible.{$group}.{$key}", 'silhouette', $text, 'profile');
            }
        }

        if (($geometry = $this->text($this->anchors()['geometry'] ?? null)) !== null && ! str_contains((string) ($this->anchors()['yacht'] ?? ''), $geometry)) {
            $this->add('geometry', 'data.anchors.geometry', 'geometry', $geometry, 'profile');
        }

        $axes = is_array($system) ? $system : [];

        foreach (['profile_curve' => ['x', 'z'], 'plan_outline' => ['x', 'y']] as $key => [$first, $second]) {
            $points = array_values(array_filter((array) ($canonical[$key] ?? []), static fn (mixed $p): bool => is_array($p) && is_numeric($p[0] ?? null) && is_numeric($p[1] ?? null)));

            if ($points !== []) {
                $this->add('geometry', "data.canonical.{$key}", str_replace('_', ' ', $key),
                    "[{$first}, {$second}] points, {$first}: ".($axes[$first] ?? $first).", {$second}: ".($axes[$second] ?? $second).': '
                    .implode(' ', array_map(static fn (array $p): string => '['.self::number((float) $p[0]).', '.self::number((float) $p[1]).']', $points)), 'profile');
            }
        }

        $this->derive();

        foreach ((array) ($canonical['decks'] ?? []) as $index => $deck) {
            if (is_array($deck)) {
                $this->add('geometry', "data.canonical.decks.{$index}", trim(($deck['name'] ?? $index).' deck'), $this->describe($deck, ['name']), 'decks_in_envelope');
            }
        }

        foreach ((array) ($canonical['deck_outlines'] ?? []) as $index => $outline) {
            if (is_array($outline)) {
                $this->add('geometry', "data.canonical.deck_outlines.{$index}", trim(($outline['deck'] ?? $index).' deck outline'), $this->describe($outline, ['deck']), 'decks_in_envelope');
            }
        }

        $this->landmarks();
    }

    /** So suy truc tiep tu diem cua thiet ke (khong them hinh hoc). */
    private function derive(): void
    {
        if ($this->coordinateProblem !== null) {
            return;
        }

        $profile = array_values(array_filter((array) ($this->canonical()['profile_curve'] ?? []), static fn (mixed $p): bool => is_array($p) && is_numeric($p[0] ?? null) && is_numeric($p[1] ?? null)));

        if ($profile !== []) {
            $peak = max(array_map(static fn (array $p): float => (float) $p[1], $profile));
            $xs = array_map(static fn (array $p): float => (float) $p[0], array_filter($profile, static fn (array $p): bool => abs((float) $p[1] - $peak) < 1e-9));
            $top = $peak * (float) $this->scale['z'];
            $at = count($xs) === 1 ? 'at '.$this->along(min($xs)) : 'from '.$this->along(min($xs)).' to '.$this->along(max($xs));
            $id = $this->add('geometry', 'derived.profile_top', 'highest point of the profile', $this->height($top).' '.$at.' (max z of data.canonical.profile_curve)', 'profile');
            $this->roles[$id] = 'height';
            $draft = $this->canonical()['dimensions']['draft']['value'] ?? null;

            if (is_numeric($draft)) {
                $id = $this->add('geometry', 'derived.keel_to_top', 'height from the keel to the highest point', self::number((float) $draft + $top).' m (draft '.self::number((float) $draft).' m + '.self::number($top).' m)', 'profile');
                $this->roles[$id] = 'height';
            }
        }

        $outline = array_values(array_filter((array) ($this->canonical()['plan_outline'] ?? []), static fn (mixed $p): bool => is_array($p) && is_numeric($p[0] ?? null) && is_numeric($p[1] ?? null)));

        if ($outline !== []) {
            $stern = max(array_map(static fn (array $p): float => (float) $p[0], $outline));
            $ys = array_map(fn (array $p): float => (float) $p[1] * (float) $this->scale['y'], array_filter($outline, static fn (array $p): bool => abs((float) $p[0] - $stern) < 1e-9));

            if (min($ys) < 0 && max($ys) > 0) {
                $id = $this->add('geometry', 'derived.stern_width', 'stern width', self::number(max($ys) - min($ys)).' m at the aftmost point of the plan outline (max y - min y of data.canonical.plan_outline at x '.self::number($stern).')', 'profile');
                $this->roles[$id] = 'stern_width';
            }
        }
    }

    private function landmarks(): void
    {
        $marks = $this->canonical()['landmarks'] ?? null;

        if (! is_array($marks) || array_is_list($marks)) {
            return;
        }

        $located = [];

        foreach ($marks as $key => $mark) {
            if (! is_array($mark)) {
                continue;
            }

            $path = "data.canonical.landmarks.{$key}";
            $known = $this->coordinateProblem === null && is_numeric($mark['x'] ?? null);
            $y = $known && is_numeric($mark['y'] ?? null) ? (float) $mark['y'] * (float) $this->scale['y'] : null;
            $z = $known && is_numeric($mark['z'] ?? null) ? (float) $mark['z'] * (float) $this->scale['z'] : null;
            $declared = $this->declared($mark, $path);

            if ($declared['side'] !== null && $y !== null && ! $this->sideAgrees($declared['side'], $y)) {
                $this->conflicts[] = "{$path}: side khai \"{$declared['side']}\" nhưng y = ".self::number($y).' m.';
            }

            if ($declared['level'] !== null && $z !== null && (($declared['level'] === 'below_waterline') !== ($z < 0))) {
                $this->conflicts[] = "{$path}: level khai \"{$declared['level']}\" nhưng z = ".self::number($z).' m.';
            }

            $located[(string) $key] = ['path' => $path, 'mark' => $mark, 'known' => $known, 'y' => $y, 'z' => $z, 'side' => $declared['side'], 'facing' => $declared['facing']];
        }

        foreach ($located as $key => $entry) {
            $offCentre = ($entry['y'] !== null && abs($entry['y']) > 1e-9) || in_array($entry['side'], ['port', 'starboard'], true);
            $paired = $entry['y'] !== null && array_filter($located, static fn (array $other): bool => $other['y'] !== null
                && abs($other['y'] + $entry['y']) < 1e-6 && abs((float) $other['mark']['x'] - (float) $entry['mark']['x']) < 1e-6 && abs((float) $other['z'] - (float) $entry['z']) < 1e-6) !== [];
            $id = $this->add('geometry', $entry['path'], $key, $this->describe($entry['mark']), $offCentre && ! $paired ? 'asymmetry' : 'profile');

            if ($entry['known']) {
                $this->positions[$id] = ['x' => (float) $entry['mark']['x'], 'y' => $entry['y'], 'z' => $entry['z'], 'facing' => $entry['facing'], 'side' => $entry['side']];
            }
        }
    }

    /** @return list<array{0: string, 1: string, 2: string}> group, field and text of every exterior form field of the yacht bible */
    private function forms(): array
    {
        $out = [];

        foreach ($this->bible() as $group => $fields) {
            if (! str_starts_with((string) $group, 'exterior') || ! is_array($fields) || array_is_list($fields)) {
                continue;
            }

            foreach ($fields as $key => $text) {
                if (($text = $this->text($text)) !== null) {
                    $out[] = [(string) $group, (string) $key, $text];
                }
            }
        }

        return $out;
    }

    private function exterior(): void
    {
        foreach ($this->forms() as [$group, $key, $text]) {
            if ($key !== 'silhouette') {
                $this->unpositioned[] = $this->add('exterior', "data.yacht_bible.{$group}.{$key}", str_replace('_', ' ', $key), $text, self::FORM_SECTIONS[$key] ?? 'profile');
            }
        }

        $names = $this->spaceNames();

        foreach ((array) ($this->bible()['exterior_zones'] ?? []) as $index => $zone) {
            if (is_array($zone) && ($text = $this->text($zone['text'] ?? null)) !== null) {
                $this->unpositioned[] = $this->add('exterior', "data.yacht_bible.exterior_zones.{$index}", $this->named((string) ($zone['id'] ?? $index), $names), $text, 'glazing_doors_openings');
            }
        }

        foreach ((array) ($this->anchors()['exterior'] ?? []) as $key => $text) {
            if (($text = $this->text($text)) !== null) {
                $this->unpositioned[] = $this->add('exterior', "data.anchors.exterior.{$key}", $this->named((string) $key, $names).' anchor', $text, 'glazing_doors_openings');
            }
        }

        $decks = [];

        foreach ((array) ($this->canonical()['spaces'] ?? []) as $index => $space) {
            if (! is_array($space) || ! is_string($space['id'] ?? null)) {
                continue;
            }

            $decks[$space['id']] = implode(', ', array_filter((array) ($space['decks'] ?? []), 'is_string'));

            if (str_starts_with($space['id'], 'EXT')) {
                $this->unpositioned[] = $this->add('exterior', "data.canonical.spaces.{$index}", $this->named($space['id'], $names),
                    'decks: '.$decks[$space['id']].'; '.$this->describe((array) ($space['dimensions'] ?? [])), 'glazing_doors_openings');
            }
        }

        foreach ((array) ($this->bible()['rooms'] ?? []) as $index => $room) {
            if (is_array($room) && ($text = $this->text($room['window_type'] ?? null)) !== null) {
                $id = (string) ($room['id'] ?? $index);
                $this->unpositioned[] = $this->add('exterior', "data.yacht_bible.rooms.{$index}.window_type", $this->named($id, $names).' window',
                    $text.(($decks[$id] ?? '') === '' ? '' : " (room on the {$decks[$id]} ".(str_contains($decks[$id], ',') ? 'decks' : 'deck').')'), 'glazing_doors_openings');
            }
        }

        foreach ((array) ($this->canonical()['connections'] ?? []) as $index => $connection) {
            $between = is_array($connection) ? array_values(array_filter((array) ($connection['between'] ?? []), 'is_string')) : [];

            if (! is_array($connection) || ($connection['kind'] ?? null) === 'adjacent' || array_filter($between, static fn (string $id): bool => ! str_starts_with($id, 'INT')) === []) {
                continue;
            }

            $this->unpositioned[] = $this->add('exterior', "data.canonical.connections.{$index}", (string) ($connection['id'] ?? $index),
                trim(($connection['kind'] ?? 'connection').' between '.implode(' and ', array_map(fn (string $id): string => $this->named($id, $names), $between)).'. '.($this->text($connection['note'] ?? null) ?? '')), 'glazing_doors_openings');
        }
    }

    /** @return array<string, string> space or room id => name */
    private function spaceNames(): array
    {
        $names = [];

        foreach ([...(array) ($this->bible()['exterior_zones'] ?? []), ...(array) ($this->bible()['rooms'] ?? [])] as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null) && is_string($entry['name'] ?? null)) {
                $names[$entry['id']] = $entry['name'];
            }
        }

        return $names;
    }

    /** @param  array<string, string>  $names */
    private function named(string $id, array $names): string
    {
        return isset($names[$id]) ? "{$names[$id]} ({$id})" : $id;
    }

    private function innovations(): void
    {
        $dimensions = (array) ($this->canonical()['dimensions'] ?? []);
        $names = [];

        foreach ((array) ($this->bible()['innovations'] ?? []) as $index => $innovation) {
            if (! is_array($innovation) || ! is_string($innovation['id'] ?? null)) {
                continue;
            }

            $names[$innovation['id']] = trim($innovation['id'].' '.($innovation['name'] ?? ''));

            foreach ($innovation as $field => $value) {
                if (! in_array($field, ['id', 'name', ...self::NOT_EXTERIOR], true) && ($text = $this->text($value)) !== null) {
                    $this->unpositioned[] = $this->add('innovation', "data.yacht_bible.innovations.{$index}.{$field}", $names[$innovation['id']].' '.str_replace('_', ' ', (string) $field), $text, 'innovations');
                }
            }
        }

        $spaces = $this->spaceNames();
        $referenced = [];

        foreach ((array) ($this->canonical()['innovations'] ?? []) as $index => $innovation) {
            if (! is_array($innovation) || ! is_string($innovation['id'] ?? null)) {
                continue;
            }

            $name = $names[$innovation['id']] ?? $innovation['id'];
            $mounted = array_values(array_filter((array) ($innovation['mounted_in'] ?? []), 'is_string'));

            if ($mounted !== []) {
                $this->unpositioned[] = $this->add('innovation', "data.canonical.innovations.{$index}.mounted_in", $name.' mounted in', implode(', ', array_map(fn (string $id): string => $this->named($id, $spaces), $mounted)), 'innovations');
            }

            foreach ((array) ($innovation['visible_elements'] ?? []) as $at => $element) {
                if (! is_array($element) || ! is_string($element['element'] ?? null)) {
                    continue;
                }

                $sizes = [];

                foreach ((array) ($element['measures'] ?? []) as $key) {
                    if (is_string($key) && is_numeric($dimensions[$key]['value'] ?? null)) {
                        $sizes[] = $this->measureName($key).' '.self::number((float) $dimensions[$key]['value']).' '.trim((string) ($dimensions[$key]['unit'] ?? ''))." ({$key})";
                        $referenced[$key] = true;
                    }
                }

                $arrangement = $this->text($element['arrangement'] ?? null);
                $text = (is_int($element['count'] ?? null) ? $element['count'].' × ' : '').$element['element'].($arrangement === null ? '' : ': '.$arrangement);
                $id = $this->add('innovation', "data.canonical.innovations.{$index}.visible_elements.{$at}", $name.' '.$element['element'],
                    $sizes === [] ? $text : rtrim($text, '.').'. Sizes: '.implode(', ', $sizes).'.', 'innovations');
                $this->unpositioned[] = $id;

                if ($sizes !== []) {
                    $this->innovationMeasures[] = $id;
                }
            }
        }

        foreach ($dimensions as $key => $entry) {
            $key = (string) $key;
            $length = ($entry['quantity'] ?? null) === 'length' || (! isset($entry['quantity']) && trim((string) ($entry['unit'] ?? '')) === 'm');

            if (isset($referenced[$key]) || ! is_array($entry) || ! is_numeric($entry['value'] ?? null) || preg_match('/^n(\d+)_/', $key, $m) !== 1 || ! $length) {
                continue;
            }

            $id = $this->add('innovation', "data.canonical.dimensions.{$key}", ($names['N'.$m[1]] ?? 'N'.$m[1]).' '.$this->measureName($key),
                $this->measureName($key).' '.self::number((float) $entry['value']).' '.trim((string) ($entry['unit'] ?? '')), 'innovations');
            $this->unpositioned[] = $id;
            $this->innovationMeasures[] = $id;
        }
    }

    private function measureName(string $key): string
    {
        return str_replace('_', ' ', (string) preg_replace('/^n\d+_/', '', $key));
    }

    private function surfaces(): void
    {
        $surfaces = (array) ($this->canonical()['surfaces'] ?? []);
        [$colors, $root] = array_filter((array) ($surfaces['colors'] ?? []), 'is_array') !== []
            ? [(array) $surfaces['colors'], 'data.canonical.surfaces.colors']
            : [(array) ($this->bible()['colors'] ?? []), 'data.yacht_bible.colors'];

        foreach ($colors as $index => $color) {
            if (is_array($color) && ($name = $this->text($color['name'] ?? null)) !== null) {
                $this->add('surfaces', "{$root}.{$index}", trim((string) ($color['role'] ?? 'colour')).' colour', $name.(is_string($color['hex'] ?? null) ? " ({$color['hex']})" : ''), 'colours_and_finish');
            }
        }

        foreach ($surfaces as $key => $value) {
            if ($key !== 'colors' && ($text = $this->text($value)) !== null) {
                $this->add('surfaces', "data.canonical.surfaces.{$key}", str_replace('_', ' ', (string) $key).' surfaces', $text, 'colours_and_finish');
            }
        }

        if (($finish = $this->text($this->anchors()['finished_surface'] ?? null)) !== null && ! str_contains((string) ($this->anchors()['yacht'] ?? ''), $finish)) {
            $this->add('surfaces', 'data.anchors.finished_surface', 'finished surface', $finish, 'colours_and_finish');
        }
    }

    private function states(): void
    {
        $finished = [];

        foreach ((array) ($this->canonical()['build_states'] ?? []) as $index => $state) {
            if (is_array($state) && ($state['kind'] ?? null) === 'finished' && is_string($state['id'] ?? null)) {
                $finished[] = $state['id'];
                $this->add('states', "data.canonical.build_states.{$index}", $state['id'].' finished state', $this->text($state['compiled_text'] ?? null) ?? $this->describe($state, ['id', 'kind']));
            }
        }

        foreach ($finished as $id) {
            if (($text = $this->text($this->design['data']['build_states'][$id] ?? null)) !== null) {
                $this->add('states', "data.build_states.{$id}", $id.' build state', $text);
            }

            if (($text = $this->text($this->anchors()['surface_states'][$id] ?? null)) !== null) {
                $this->add('states', "data.anchors.surface_states.{$id}", $id.' surfaces', $text);
            }
        }

        foreach ((array) ($this->canonical()['operational_limits'] ?? []) as $index => $limit) {
            $governed = is_array($limit) ? ($limit['applies_to'] ?? null) : null;

            if (! is_array($limit) || ! is_string($limit['feature'] ?? null) || (is_array($governed) && ($governed['kind'] ?? null) === 'space' && ! self::exteriorScope([$governed['id'] ?? null]))) {
                continue;
            }

            $conditions = is_array($limit['allowed_when'] ?? null) ? $limit['allowed_when'] : [];
            $this->add('states', "data.canonical.operational_limits.{$index}", $limit['feature'], implode('; ', array_filter([
                is_array($governed) ? 'applies to '.trim(($governed['kind'] ?? '').' '.($governed['id'] ?? '')) : null,
                $conditions === [] ? null : 'allowed when: '.implode(', ', array_map(static fn (string|int $key, mixed $value): string => $key.' = '.self::literal($value), array_keys($conditions), array_values($conditions))),
                'otherwise: '.($this->text($limit['otherwise'] ?? null) ?? ''),
            ])));
        }

        if (($lighting = $this->text($this->bible()['lighting_signature'] ?? null)) !== null) {
            $this->add('states', 'data.yacht_bible.lighting_signature', 'lighting signature', $lighting);
        }
    }

    private function notes(): void
    {
        $notes = $this->text($this->design['notes'] ?? null);

        foreach ($notes === null ? [] : (preg_split('/(?<=[.!?])\s+(?=[A-Z])/u', $notes) ?: []) as $index => $sentence) {
            if (! self::technicalOnly($sentence)) {
                $this->note('notes', 'notes, sentence '.($index + 1), $sentence);
            }
        }

        foreach ((array) ($this->design['flags'] ?? []) as $index => $flag) {
            if (($text = $this->text($flag)) !== null) {
                $this->note("flags.{$index}", 'flag', $text);
            }
        }

        foreach ((array) ($this->canonical()['identity_features'] ?? []) as $index => $feature) {
            if (is_array($feature) && is_string($feature['id'] ?? null) && self::exteriorScope($feature['scope'] ?? null) && ($text = $this->text($feature['description'] ?? null)) !== null) {
                $this->note("data.canonical.identity_features.{$index}", $feature['id'], trim(($feature['kind'] ?? '').': '.$text, ': '));
            }
        }

        foreach ((array) ($this->canonical()['model_sheet']['views'] ?? []) as $index => $view) {
            if (is_array($view) && ($text = $this->text($view['view'] ?? null)) !== null) {
                $this->note("data.canonical.model_sheet.views.{$index}.view", 'model sheet view '.($view['id'] ?? $index), (string) ($view['id'] ?? '').': '.$text);
            }
        }

        foreach (array_values((array) ($this->bible()['must_avoid_resolution'] ?? [])) as $index => $line) {
            $text = is_array($line) ? $this->text($line['text'] ?? $line['resolution'] ?? null) : $this->text($line);

            if ($text !== null) {
                $this->note("data.yacht_bible.must_avoid_resolution.{$index}", 'must avoid', $text);
            }
        }
    }

    /** @return bool true only when the sentence names an engineering term and every other word of it is a neutral word; any word left over — a part, an absence, a finish, a condition — keeps the sentence for Sonnet */
    private static function technicalOnly(string $sentence): bool
    {
        $technical = '/\b(?:(?:[\p{L}\d]+(?:-[\p{L}\d]+)*[\s-]+)?'.self::TECHNICAL_PROPERTY.'|'.self::TECHNICAL_TERM.')\b/iu';
        $rest = (string) preg_replace($technical, ' ', $sentence, -1, $found);

        if ($found === 0) {
            return false;
        }

        preg_match_all('/[\p{L}\d]+/u', mb_strtolower($rest), $words);

        return array_diff($words[0], self::NEUTRAL_WORDS) === [];
    }

    private function note(string $path, string $subject, string $content): void
    {
        $id = $this->add('notes', $path, $subject, $content);

        if (preg_match('/\bMS8\b/', $content) === 1) {
            $this->guidance[] = $id;
        }
    }

    /**
     * @param  array<string, mixed>  $camera  video.superyacht.model_sheet.cameras.<id>
     * @return array<string, mixed>
     */
    private function view(string $id, string $label, array $camera): array
    {
        $kind = $this->kind($camera);
        $related = [];
        $unclassified = [];

        if ($kind === 'detail') {
            return [
                'id' => $id,
                'label' => $label,
                'camera' => $camera,
                'related' => $this->guidance === [] ? [] : [['placement' => 'MS8 guidance written in the design', 'ids' => $this->guidance]],
                'measures' => $this->innovationMeasures,
                'unclassified' => [],
            ];
        }

        foreach ($this->positions as $item => $position) {
            [$certain, $text] = $this->placement($position, $camera, $kind);

            if ($certain) {
                $related[$text][] = $item;
            } else {
                $unclassified[$text][] = $item;
            }
        }

        return [
            'id' => $id,
            'label' => $label,
            'camera' => $camera,
            'related' => self::grouped($related, 'placement'),
            'measures' => array_keys(array_filter($this->roles, static fn (string $role): bool => in_array($role, self::VIEW_MEASURES[$kind], true))),
            'unclassified' => self::grouped($unclassified, 'reason'),
        ];
    }

    /**
     * @param  array<string, list<string>>  $byText  placement or reason => item ids
     * @return list<array<string, mixed>>
     */
    private static function grouped(array $byText, string $key): array
    {
        return array_map(static fn (string $text, array $ids): array => [$key => $text, 'ids' => $ids], array_keys($byText), array_values($byText));
    }

    /** @param  array<string, mixed>  $camera */
    private function kind(array $camera): string
    {
        $azimuth = $camera['azimuth_deg'] ?? null;
        $elevation = (float) ($camera['elevation_deg'] ?? 0);

        return match (true) {
            isset($camera['target']) => 'detail',
            $elevation >= 90 || $azimuth === null => 'plan',
            fmod((float) $azimuth, 360.0) === 0.0 => 'bow',
            fmod((float) $azimuth, 360.0) === 180.0 => 'stern',
            $elevation === 0.0 && abs(sin(deg2rad((float) $azimuth))) === 1.0 => 'profile',
            default => 'oblique',
        };
    }

    /**
     * Cach dat mot moc so voi goc may: phia dat (khong phai huong mat), duoi duong nuoc van giu.
     *
     * @param  array{x: float, y: ?float, z: ?float, facing: ?string, side: ?string}  $position
     * @param  array<string, mixed>  $camera
     * @return array{0: bool, 1: string} [ro voi goc nay, mo ta]
     */
    private function placement(array $position, array $camera, string $kind): array
    {
        $side = match (true) {
            $position['y'] !== null && $position['y'] > 1e-9 => 'port',
            $position['y'] !== null && $position['y'] < -1e-9 => 'starboard',
            $position['y'] !== null => 'centre',
            default => $position['side'],
        };
        $where = match ($side) {
            'port', 'starboard' => "placed on the {$side} side",
            'centre' => 'placed on the centreline',
            'both' => 'placed on both sides',
            default => 'no side given in the design',
        };
        $notes = [];
        $certain = true;

        if (in_array($side, ['port', 'starboard'], true) && ! in_array($kind, ['bow', 'stern', 'plan'], true)) {
            $near = sin(deg2rad((float) $camera['azimuth_deg'])) > 0 ? 'port' : 'starboard';
            $certain = $side === $near;
            $notes[] = $certain ? 'the side this camera faces' : 'away from this camera; the design does not say whether the shell hides it';
        }

        if ($position['z'] !== null && $position['z'] < 0) {
            $notes[] = 'below the design waterline';
            $certain = $certain && $kind !== 'plan';
        }

        if ($position['facing'] !== null) {
            $notes[] = "declared facing {$position['facing']}";
        }

        return [$certain, implode('; ', [$where, ...$notes])];
    }

    /**
     * Viet mot ban ghi thiet ke thanh chu, so co du don vi: x la phan LOA tu mui, y lech truc giua,
     * z va *_z_m tren duong nuoc thiet ke, *_m la met; basis khong vao.
     *
     * @param  array<string|int, mixed>  $record
     * @param  list<string>  $skip
     */
    private function describe(array $record, array $skip = []): string
    {
        $parts = [];

        foreach ($record as $key => $value) {
            $key = (string) $key;

            if ($key === 'basis' || in_array($key, $skip, true) || $value === null || (is_string($value) && trim($value) === '') || $value === []) {
                continue;
            }

            $label = str_replace('_', ' ', (string) preg_replace('/(_z_m|_z|_x|_m)$/', '', $key));

            $parts[] = match (true) {
                is_numeric($value) && ! is_string($value) && ($key === 'x' || str_ends_with($key, '_x')) => trim(($key === 'x' ? '' : $label.' ').$this->along((float) $value)),
                is_numeric($value) && ! is_string($value) && $key === 'y' => $this->offset((float) $value),
                is_numeric($value) && ! is_string($value) && ($key === 'z' || str_ends_with($key, '_z')) => trim(($key === 'z' ? '' : $label.' ').$this->height((float) $value, true)),
                is_numeric($value) && ! is_string($value) && str_ends_with($key, '_z_m') => $label.' '.$this->height((float) $value),
                is_numeric($value) && ! is_string($value) && str_ends_with($key, '_m') => $label.' '.self::number((float) $value).' m',
                is_array($value) && array_key_exists('value', $value) => $label.' '.($value['value'] === null
                    ? 'not given'.(is_string($value['basis'] ?? null) ? " ({$value['basis']})" : '')
                    : self::number((float) $value['value']).' '.trim((string) ($value['unit'] ?? (str_ends_with($key, '_m') ? 'm' : '')))),
                is_bool($value) => $label.': '.($value ? 'yes' : 'no'),
                is_array($value) && array_is_list($value) => $label.': '.implode(', ', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : (string) json_encode($v), $value)),
                is_array($value) => $label.': '.$this->describe($value),
                is_numeric($value) && ! is_string($value) => $label.' '.self::number((float) $value),
                default => $label.': '.trim((string) $value),
            };
        }

        return implode('; ', array_filter($parts, static fn (string $p): bool => trim($p) !== ''));
    }

    private function along(float $x): string
    {
        if ($this->coordinateProblem !== null) {
            return 'x '.self::number($x);
        }

        return self::number(round($x * 100, 2)).'% of the length from the bow (x '.self::number($x).')';
    }

    private function offset(float $raw): string
    {
        if ($this->coordinateProblem !== null) {
            return 'y '.self::number($raw);
        }

        $value = $raw * (float) $this->scale['y'];
        $converted = (float) $this->scale['y'] === 1.0 ? '' : ' (converted from '.self::number($raw).' ft)';

        return match (true) {
            $value > 0 => self::number($value).' m to port of the centreline'.$converted,
            $value < 0 => self::number(-$value).' m to starboard of the centreline'.$converted,
            default => 'on the centreline',
        };
    }

    private function height(float $raw, bool $axis = false): string
    {
        if ($axis && $this->coordinateProblem !== null) {
            return 'z '.self::number($raw);
        }

        $scale = $axis ? (float) $this->scale['z'] : 1.0;
        $value = $raw * $scale;
        $converted = $scale === 1.0 ? '' : ' (converted from '.self::number($raw).' ft)';

        return match (true) {
            $value > 0 => self::number($value).' m above the design waterline'.$converted,
            $value < 0 => self::number(-$value).' m below the design waterline'.$converted,
            default => 'at the design waterline',
        };
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
     * Truong khai trong nguon cua mot chi tiet (side, level, facing); gia tri ngoai danh sach cho
     * phep la mau thuan nguon. Huong mat khong so voi man dat: vach man phai co the quay sang trai.
     *
     * @param  array<string, mixed>  $item
     * @return array{side: ?string, level: ?string, facing: ?string}
     */
    private function declared(array $item, string $path): array
    {
        $out = ['side' => null, 'level' => null, 'facing' => null];

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

        return $out;
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
}
