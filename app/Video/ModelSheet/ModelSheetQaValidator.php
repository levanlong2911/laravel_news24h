<?php

declare(strict_types=1);

namespace App\Video\ModelSheet;

/**
 * Kiem ket qua QA model sheet (Sonnet) truoc khi ghep prompt. Doc lap voi luot goi model de
 * kiem duoc bang JSON gia.
 *
 * Kiem bang cau truc, khong bang ngu nghia van xuoi: nguon ton tai; moi so qua the du kien va
 * gan voi chu the + thuoc tinh so huu no; do phu va quyet dinh camera noi voi CAU cu the; trang
 * thai gan voi bo phan va nguon trang thai co that; MS8 khop muc tieu thiet ke. Dat cac kiem nay
 * la du dieu kien gui render theo luat da dinh nghia, KHONG phai chung nhan hinh hoc dung; nguoi
 * duyet van xem prompt va duyet anh dau ra.
 */
final class ModelSheetQaValidator
{
    public const VERSION = 'model-sheet-qa-schema-v7';

    public const DECISIONS = ['include', 'shared', 'occluded', 'out_of_frame', 'state_hidden', 'not_exterior', 'unknown'];

    /** Khoi duoc phep ghi trong coverage.where (cung cac ma goc). */
    public const BLOCKS = ['shared', 'surfaces', 'display_state', 'critical_absences'];

    /** Chu the "yacht" chi so huu so cua cac nhanh muc ca tau nay (design_parameters gom ca thong so ca tau: so mau, so cabin). */
    private const YACHT_PATHS = ['data.canonical.dimensions', 'data.yacht_bible.specs', 'data.canonical.decks', 'data.canonical.deck_outlines', 'data.canonical.profile_curve', 'data.canonical.plan_outline', 'data.canonical.design_parameters', 'derived.'];

    /** Tham so cua bo phan: chu the innovation duoc so huu neu ten tham so xuat hien trong mo ta innovation. */
    private const PARAMETER_PATHS = ['data.canonical.design_parameters', 'data.canonical.feature_geometry', 'data.canonical.dimensions'];

    /** Kich thuoc chung cua ca tau: chi chu the "yacht", khong bao gio thuoc mot innovation. */
    private const YACHT_DIMENSIONS = ['loa', 'lwl', 'beam', 'draft', 'air_draft', 'freeboard', 'bow_freeboard', 'crest_height', 'gross_tonnage', 'displacement', 'height', 'length'];

    private const INTERIOR_PATHS = ['data.anchors.interior', 'data.yacht_bible.rooms', 'data.canonical.rooms', 'data.canonical.interior_set_states'];

    /** Tham chieu: ma du kien F12, thuc the L:/R:/N:, hoac duong dan trong thiet ke (data.…) / DNA (dna.…). */
    private const REF = ['type' => 'string'];

    private const NUMBERS = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['fact', 'subject', 'property'],
            'properties' => [
                'fact' => ['type' => 'string'],
                'subject' => ['type' => 'string'],
                'property' => ['type' => 'string'],
            ],
        ],
    ];

    private const SENTENCE = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['text', 'refs', 'numbers'],
        'properties' => [
            'text' => ['type' => 'string'],
            'refs' => ['type' => 'array', 'items' => self::REF],
            'numbers' => self::NUMBERS,
        ],
    ];

    /** @var array<string, mixed> JSON Schema dau ra (structured output) */
    public const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['shared', 'surfaces', 'display_state', 'views', 'ms8_target', 'critical_absences', 'coverage', 'changes', 'findings'],
        'properties' => [
            'shared' => ['type' => 'array', 'items' => self::SENTENCE],
            'surfaces' => ['type' => 'array', 'items' => self::SENTENCE],
            'display_state' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['part_id', 'part', 'state', 'refs', 'numbers'],
                    'properties' => [
                        'part_id' => ['type' => 'string'],
                        'part' => ['type' => 'string'],
                        'state' => ['type' => 'string'],
                        'refs' => ['type' => 'array', 'items' => self::REF],
                        'numbers' => self::NUMBERS,
                    ],
                ],
            ],
            'views' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['view_id', 'sentences', 'decisions'],
                    'properties' => [
                        'view_id' => ['type' => 'string'],
                        'sentences' => ['type' => 'array', 'items' => self::SENTENCE],
                        'decisions' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['entity', 'decision', 'basis', 'note'],
                                'properties' => [
                                    'entity' => ['type' => 'string'],
                                    'decision' => ['type' => 'string', 'enum' => self::DECISIONS],
                                    'basis' => ['type' => ['string', 'null']],
                                    'note' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'ms8_target' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['innovation', 'entities', 'refs'],
                'properties' => [
                    'innovation' => ['type' => 'string'],
                    'entities' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'refs' => ['type' => 'array', 'items' => self::REF],
                ],
            ],
            'critical_absences' => ['type' => 'array', 'items' => self::SENTENCE],
            'coverage' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['item', 'status', 'where', 'note'],
                    'properties' => [
                        'item' => ['type' => 'string'],
                        'status' => ['type' => 'string', 'enum' => ['used', 'not_exterior', 'not_visible']],
                        'where' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'note' => ['type' => 'string'],
                    ],
                ],
            ],
            'changes' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['action', 'text', 'reason'],
                    'properties' => [
                        'action' => ['type' => 'string', 'enum' => ['added', 'removed', 'merged', 'rewritten']],
                        'text' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                    ],
                ],
            ],
            'findings' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['severity', 'kind', 'refs', 'message'],
                    'properties' => [
                        'severity' => ['type' => 'string', 'enum' => ['blocking', 'note']],
                        'kind' => ['type' => 'string', 'enum' => ['conflict', 'gap', 'ambiguity', 'camera']],
                        'refs' => ['type' => 'array', 'items' => self::REF],
                        'message' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ];

    /** @var array<string, array<string, mixed>> */
    private array $facts = [];

    /** @var array<string, array<string, mixed>> */
    private array $entities = [];

    /** @var list<string> */
    private array $detailViews;

    /**
     * @param  array<string, mixed>  $snapshot  ModelSheetFacts::snapshot()
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>  $dna
     * @param  list<string>  $viewIds  MS1..MS8 theo thu tu
     * @param  list<string>  $detailViews  goc chi tiet (duoc phep out_of_frame)
     */
    public function __construct(
        private readonly array $snapshot,
        private readonly array $design,
        private readonly array $dna,
        private readonly array $viewIds,
        private readonly int $negativesTarget,
        array $detailViews = ['MS8'],
    ) {
        $this->detailViews = array_values($detailViews);

        foreach ((array) $snapshot['facts'] as $fact) {
            $this->facts[(string) $fact['id']] = $fact;
        }

        foreach ((array) $snapshot['entities'] as $entity) {
            $this->entities[(string) $entity['id']] = $entity;
        }
    }

    /**
     * @param  mixed  $qa  JSON QA da giai ma
     * @return array{ok: bool, errors: list<string>, blocking: list<string>, notes: list<string>}
     */
    public function validate(mixed $qa): array
    {
        $errors = [];
        $notes = [];

        if (! is_array($qa) || ($missing = array_diff((array) self::SCHEMA['required'], array_keys($qa))) !== []) {
            return ['ok' => false, 'errors' => ['Kết quả QA không đúng khung JSON'.(isset($missing) && $missing !== [] ? ' (thiếu '.implode(', ', $missing).')' : '').'.'], 'blocking' => [], 'notes' => []];
        }

        foreach (['shared', 'surfaces', 'critical_absences'] as $block) {
            foreach ($this->listOf($qa[$block], $block, $errors) as $index => $sentence) {
                $this->sentence($sentence, "{$block}[{$index}]", null, $errors, $notes);
            }
        }

        if ($this->listOf($qa['shared'], 'shared', $errors) === []) {
            $errors[] = 'Khối nhận dạng chung (shared) rỗng.';
        }

        $parts = $this->displayState($qa['display_state'], $errors, $notes);
        $this->views($qa, $parts, $errors, $notes);
        $this->ms8($qa['ms8_target'], $errors);
        $this->coverage($qa, $parts, $errors);
        foreach ($this->listOf($qa['changes'], 'changes', $errors) as $index => $change) {
            if (! is_array($change) || ! in_array($change['action'] ?? null, ['added', 'removed', 'merged', 'rewritten'], true) || ! is_string($change['text'] ?? null) || ! is_string($change['reason'] ?? null)) {
                $errors[] = "changes[{$index}]: không đúng khung (action added/removed/merged/rewritten, text, reason).";
            }
        }

        $absences = count($this->listOf($qa['critical_absences'], 'critical_absences', $errors));

        if ($absences > $this->negativesTarget) {
            $notes[] = "Có {$absences} câu vắng mặt quan trọng, nhiều hơn mục tiêu {$this->negativesTarget} — xem lại có câu nào trùng hoặc không quyết định nhận dạng không.";
        }

        $blocking = [];

        foreach ($this->listOf($qa['findings'], 'findings', $errors) as $index => $finding) {
            if (! is_array($finding) || ! in_array($finding['severity'] ?? null, ['blocking', 'note'], true)
                || ! in_array($finding['kind'] ?? null, ['conflict', 'gap', 'ambiguity', 'camera'], true) || trim((string) ($finding['message'] ?? '')) === '') {
                $errors[] = "findings[{$index}]: không đúng khung (severity blocking/note, kind conflict/gap/ambiguity/camera, message).";

                continue;
            }

            $items = array_column((array) $this->snapshot['coverage'], 'id');

            foreach ((array) ($finding['refs'] ?? []) as $ref) {
                // Finding de nguoi doc, khong vao prompt: duoc nhac ma goc, ma muc do phu; ma khong tra
                // duoc chi la ghi chu, khong chan render.
                if (! $this->resolves((string) $ref) && ! in_array((string) $ref, [...$this->viewIds, ...$items, 'ms8'], true)) {
                    $notes[] = "findings[{$index}]: tham chiếu \"{$ref}\" không tra được trong thiết kế.";
                }
            }

            $line = '['.($finding['kind'] ?? '?').'] '.trim((string) ($finding['message'] ?? ''));
            ($finding['severity'] ?? null) === 'blocking' ? $blocking[] = $line : $notes[] = $line;
        }

        return ['ok' => $errors === [] && $blocking === [], 'errors' => array_values(array_unique($errors)), 'blocking' => $blocking, 'notes' => array_values(array_unique($notes))];
    }

    public function resolves(string $ref): bool
    {
        return isset($this->facts[$ref]) || isset($this->entities[$ref]) || $this->pathValue($ref) !== null
            || in_array($ref, array_column((array) ($this->snapshot['occluders'] ?? []), 'id'), true)
            || in_array($ref, array_column((array) ($this->snapshot['state_sources'] ?? []), 'id'), true);
    }

    /** @return array<string, array<string, mixed>> */
    public function facts(): array
    {
        return $this->facts;
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $notes
     */
    private function sentence(mixed $sentence, string $where, ?string $view, array &$errors, array &$notes, bool $stateLine = false): void
    {
        if (! is_array($sentence) || ! is_string($sentence['text'] ?? null) || ! is_array($sentence['refs'] ?? null) || ! is_array($sentence['numbers'] ?? null)) {
            $errors[] = "{$where}: câu không đúng khung (text, refs, numbers).";

            return;
        }

        $text = trim($sentence['text']);
        $refs = array_map('strval', $sentence['refs']);

        if ($text === '') {
            $errors[] = "{$where}: câu rỗng.";
        }

        if ($refs === []) {
            $errors[] = "{$where}: câu không có nguồn.";
        }

        foreach ($refs as $ref) {
            if (! $this->resolves($ref)) {
                $errors[] = "{$where}: tham chiếu \"{$ref}\" không tồn tại trong thiết kế.";
            }
        }

        $this->numbers($text, (array) $sentence['numbers'], $where, $errors);
        $bare = preg_replace('/\{\{[^}]*\}\}/', '', $text) ?? '';

        if (preg_match('/(?<![A-Za-z0-9\-])\d/', $bare) === 1) {
            $errors[] = "{$where}: có số viết trần, phải dùng thẻ dữ kiện: \"".mb_substr($text, 0, 120).'"';
        }

        if (str_contains($bare, '{{') || str_contains($bare, '}}')) {
            $errors[] = "{$where}: thẻ dữ kiện viết sai cú pháp.";
        }

        // Cau di thang toi model anh: ma tham chieu (L:bow_tip, N:N2, P:hull, F12, data.…) chi nam
        // trong refs; trong cau phai goi bo phan bang loi.
        if (preg_match('/\b(?:[LRNP]:[A-Za-z0-9_:\-]+|F\d+\b|data\.[A-Za-z0-9_.]+|dna\.[A-Za-z0-9_.]+|OL:[A-Za-z0-9_]+)/', $bare, $code) === 1) {
            $errors[] = "{$where}: câu chứa mã tham chiếu \"{$code[0]}\" — mã chỉ để trong refs, câu gửi model ảnh phải gọi bộ phận bằng lời.";
        }

        $exterior = array_filter($refs, fn (string $ref): bool => ! $this->interiorRef($ref));

        if ($refs !== [] && $exterior === [] && ! $stateLine) {
            $notes[] = "{$where}: câu chỉ dẫn nguồn nội thất — kiểm tra nó có tạo hình bên ngoài không.";
        }

        $side = ModelSheetFacts::VIEW_SIDES[$view ?? ''] ?? null;

        if ($side !== null) {
            // Moi dang tham chieu (ma thuc the, ma du kien, duong dan con) quy ve bo phan so huu; co
            // cap doi xung khong lam bo phan sai phia thanh thay duoc — phai dan bo phan cung phia.
            foreach (array_unique(array_filter(array_map(fn (string $ref): ?string => $this->ownerOf($ref), $refs))) as $owner) {
                $entity = $this->entities[$owner];
                $pair = ($entity['pair'] ?? null) === null ? '' : " (dùng {$entity['pair']} cho phía {$side})";

                // Chan cung chi khi nguon KHAI huong mat; diem gan man chi la ghi chu.
                if (in_array($entity['facing'] ?? null, ['port', 'starboard'], true) && $entity['facing'] !== $side) {
                    $errors[] = "{$where}: {$owner} khai hướng ra mạn {$entity['facing']}, góc {$view} chỉ thấy mạn {$side}{$pair}.";
                } elseif (($entity['near_side'] ?? false) === true && in_array($entity['side'], ['port', 'starboard'], true) && $entity['side'] !== $side) {
                    $notes[] = "{$where}: {$owner} nằm gần mạn {$entity['side']} mà góc {$view} chỉ thấy mạn {$side}{$pair} — kiểm tra nó có thật sự thấy được.";
                }
            }
        }
    }

    /**
     * Moi the {{F}} phai co mot dong `numbers`: chu the so huu du kien, dung thuoc tinh.
     *
     * @param  list<mixed>  $numbers
     * @param  list<string>  $errors
     */
    private function numbers(string $text, array $numbers, string $where, array &$errors): void
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_:.\-]+)\s*\}\}/', $text, $tokens);
        $bound = [];

        foreach ($numbers as $index => $number) {
            $factId = is_array($number) ? (string) ($number['fact'] ?? '') : '';
            $subject = is_array($number) ? (string) ($number['subject'] ?? '') : '';
            $property = is_array($number) ? (string) ($number['property'] ?? '') : '';
            $fact = $this->facts[$factId] ?? null;

            if ($fact === null) {
                $errors[] = "{$where}: numbers[{$index}] dẫn mã dữ kiện không tồn tại \"{$factId}\".";

                continue;
            }

            $bound[$factId] = true;

            if ($property !== (string) $fact['property']) {
                $errors[] = "{$where}: {$factId} là \"{$fact['property']}\" ({$fact['quantity']}), không phải \"{$property}\".";
            }

            if (! $this->owns($subject, $fact)) {
                $errors[] = "{$where}: {$factId} ({$fact['quantity']}) không thuộc chủ thể \"{$subject}\".";
            }
        }

        foreach ($tokens[1] as $token) {
            if (! isset($this->facts[$token])) {
                $errors[] = "{$where}: thẻ số liệu {{{$token}}} không phải mã dữ kiện.";
            } elseif (! isset($bound[$token])) {
                $errors[] = "{$where}: thẻ {{{$token}}} chưa khai chủ thể và thuộc tính trong numbers.";
            }
        }

        foreach (array_diff(array_keys($bound), $tokens[1]) as $unused) {
            $errors[] = "{$where}: numbers khai {$unused} nhưng câu không dùng thẻ đó.";
        }
    }

    /** @param array<string, mixed> $fact */
    private function owns(string $subject, array $fact): bool
    {
        $path = (string) $fact['path'];

        if ($subject === 'yacht') {
            foreach (self::YACHT_PATHS as $root) {
                if (str_starts_with($path, $root)) {
                    return true;
                }
            }

            return false;
        }

        $entity = $this->entities[$subject] ?? null;

        if ($entity !== null) {
            if (in_array($fact['id'], (array) $entity['facts'], true) || str_starts_with($path, $entity['path'].'.')) {
                return true;
            }

            // Tham so bo phan (design_parameters.canyon_width) thuoc innovation khi moi tu cua ten
            // tham so deu co trong mo ta innovation do (thiet ke + DNA).
            if ($entity['kind'] === 'innovation' && ! in_array((string) $fact['property'], self::YACHT_DIMENSIONS, true)) {
                foreach (self::PARAMETER_PATHS as $root) {
                    if (str_starts_with($path, $root.'.')) {
                        return $this->mentions($entity, (string) $fact['property']);
                    }
                }
            }

            return false;
        }

        // Phan vo/vung ngoai that co ten (P:hull) so huu du kien nam trong duong dan cua no.
        foreach ((array) ($this->snapshot['occluders'] ?? []) as $part) {
            if ($subject === $part['id']) {
                $subject = (string) $part['path'];
            }
        }

        return $subject !== '' && $this->pathValue($subject) !== null
            && ($path === $subject || str_starts_with($path, $subject.'.') || str_starts_with($path, $subject.'#'));
    }

    /** @param array<string, mixed> $innovation */
    private function mentions(array $innovation, string $parameter): bool
    {
        $id = substr((string) $innovation['id'], 2);
        $texts = json_encode($this->pathValue((string) $innovation['path'])).' ';

        foreach ((array) ($this->dna['innovations'] ?? []) as $item) {
            if (is_array($item) && ($item['id'] ?? null) === $id) {
                $texts .= json_encode($item);
            }
        }

        $texts = strtolower($texts);
        // Chi doi phan TEN BO PHAN cua tham so (canyon trong canyon_width); tu chi loai kich thuoc bo qua.
        // `per` la quan he (vanes_per_field = "each field contains ... vanes"), khong phai ten bo phan.
        $generic = ['max', 'min', 'unit', 'units', 'value', 'count', 'width', 'length', 'depth', 'height', 'diameter', 'radius', 'pitch', 'volume', 'area', 'thickness', 'spacing', 'water', 'total', 'per'];
        $words = array_filter(explode('_', strtolower($parameter)), static fn (string $w): bool => strlen($w) > 2 && ! in_array($w, $generic, true));

        foreach ($words as $word) {
            if (! str_contains($texts, rtrim($word, 's'))) {
                return false;
            }
        }

        return $words !== [];
    }

    /**
     * Trang thai: id bo phan on dinh (OL:<feature> cua nguon trang thai, hoac other:<ten>), moi bo
     * phan mot trang thai, dan dung nguon trang thai cua chinh bo phan.
     *
     * @param  list<string>  $errors
     * @param  list<string>  $notes
     * @return array<string, true> part_id da khai
     */
    private function displayState(mixed $states, array &$errors, array &$notes): array
    {
        $sources = [];

        foreach ((array) ($this->snapshot['state_sources'] ?? []) as $source) {
            $sources[(string) $source['id']] = (string) $source['path'];
        }

        $parts = [];

        foreach ($this->listOf($states, 'display_state', $errors) as $index => $state) {
            $id = trim((string) ($state['part_id'] ?? ''));
            $refs = array_map('strval', (array) ($state['refs'] ?? []));

            if ($id === '' || isset($parts[$id])) {
                $errors[] = "display_state[{$index}]: part_id rỗng hoặc bộ phận có hơn một trạng thái (\"{$id}\").";
            }

            $parts[$id] = true;

            if (isset($sources[$id])) {
                if (! $this->cites($refs, [$sources[$id]])) {
                    $errors[] = "display_state[{$index}]: trạng thái của {$id} phải dẫn nguồn trạng thái của chính nó ({$sources[$id]}).";
                }
            } elseif (str_starts_with($id, 'other:') && strlen($id) > 6) {
                $text = array_filter($refs, fn (string $ref): bool => is_string($this->pathValue($ref)));

                if ($text === []) {
                    $errors[] = "display_state[{$index}]: trạng thái {$id} phải dẫn một đoạn thiết kế mô tả trạng thái, không chỉ số liệu.";
                }
            } else {
                $errors[] = "display_state[{$index}]: part_id \"{$id}\" không phải nguồn trạng thái của thiết kế (".implode(', ', array_keys($sources)).') hoặc other:<tên>.';
            }

            $this->sentence(['text' => (string) ($state['state'] ?? ''), 'refs' => $refs, 'numbers' => $state['numbers'] ?? []], "display_state[{$index}]", null, $errors, $notes, true);
        }

        return $parts;
    }

    /**
     * Quyet dinh camera noi voi cau: include phai co cau cua goc dan toi thuc the; shared phai co
     * cau khoi chung dan toi no; thuc the duoc nhac trong cau cua goc phai la include;
     * out_of_frame chi o goc chi tiet; state_hidden tro toi mot trang thai da khai.
     *
     * @param  array<string, mixed>  $qa
     * @param  array<string, true>  $parts
     * @param  list<string>  $errors
     * @param  list<string>  $notes
     */
    private function views(array $qa, array $parts, array &$errors, array &$notes): void
    {
        $views = $this->listOf($qa['views'], 'views', $errors);
        $ids = array_map(static fn (mixed $v): string => is_array($v) ? (string) ($v['view_id'] ?? '') : '', $views);

        if ($ids !== $this->viewIds) {
            $errors[] = 'views phải gồm đúng '.implode(', ', $this->viewIds).' theo thứ tự (nhận được: '.implode(', ', $ids).').';
        }

        $shared = $this->mentioned((array) $qa['shared']);

        foreach ($views as $view) {
            $id = (string) ($view['view_id'] ?? '');
            $sentences = $this->listOf($view['sentences'] ?? null, "views[{$id}].sentences", $errors);

            if ($sentences === []) {
                $errors[] = "views[{$id}]: góc chưa có mô tả riêng.";
            }

            foreach ($sentences as $index => $sentence) {
                $this->sentence($sentence, "views[{$id}].sentences[{$index}]", $id, $errors, $notes);
            }

            $inView = $this->mentioned($sentences);
            $decided = [];

            foreach ($this->listOf($view['decisions'] ?? null, "views[{$id}].decisions", $errors) as $decision) {
                $entity = (string) ($decision['entity'] ?? '');
                $kind = (string) ($decision['decision'] ?? '');
                $basis = $decision['basis'] ?? null;
                $note = trim((string) ($decision['note'] ?? ''));

                if (! isset($this->entities[$entity])) {
                    $errors[] = "views[{$id}]: quyết định cho thực thể không tồn tại \"{$entity}\".";

                    continue;
                }

                if (isset($decided[$entity])) {
                    $errors[] = "views[{$id}]: {$entity} có hơn một quyết định.";
                }

                $decided[$entity] = $kind;

                $problem = match ($kind) {
                    'include' => isset($inView[$entity]) ? null : 'ghi include nhưng không câu nào của góc dẫn tới nó.',
                    'shared' => isset($shared[$entity]) ? null : 'ghi shared nhưng khối chung không có câu dẫn tới nó.',
                    'occluded' => match (true) {
                        ! is_string($basis) || ! $this->resolves($basis) => 'bị che nhưng basis không chỉ ra bộ phận che khuất có trong thiết kế.',
                        $this->ownerOf($basis) === $entity => 'không thể tự che chính nó (basis "'.$basis.'" là chính bộ phận này).',
                        ! $this->physicalPart($basis) => 'bị che nhưng basis "'.$basis.'" không phải một bộ phận vật lý (kích thước, màu hay thông số không che được gì).',
                        default => null,
                    },
                    'out_of_frame' => in_array($id, $this->detailViews, true) ? null : 'ghi out_of_frame nhưng góc toàn tàu phải chứa trọn con tàu — chỉ góc chi tiết được cắt khung.',
                    'state_hidden' => is_string($basis) && isset($parts[$basis]) ? null : 'ghi state_hidden nhưng basis không phải part_id đã khai trong display_state.',
                    'not_exterior', 'unknown' => $note !== '' ? null : "ghi {$kind} mà không có note giải thích.",
                    default => "có quyết định không hợp lệ \"{$kind}\".",
                };

                if ($problem !== null) {
                    $errors[] = "views[{$id}]: {$entity} {$problem}";
                }

                if ($kind === 'unknown') {
                    $notes[] = "views[{$id}]: chưa xác định được {$entity} — {$note}";
                }
            }

            foreach (array_keys($inView) as $entity) {
                if (($decided[$entity] ?? null) !== 'include') {
                    $errors[] = "views[{$id}]: câu của góc nhắc {$entity} nhưng quyết định không phải include — bộ phận không thấy không được đưa vào câu mô tả.";
                }
            }

            foreach ((array) ($this->snapshot['candidates'][$id] ?? []) as $candidate) {
                if (! isset($decided[$candidate['entity']])) {
                    $errors[] = "views[{$id}]: ứng viên {$candidate['entity']} chưa có quyết định.";
                }
            }
        }
    }

    /**
     * MS8 khop muc tieu thiet ke khai; co moc thuoc muc tieu (tru khi chinh thiet ke cho pham vi);
     * quan he moc–innovation co bang chung nam trong mo ta cua chinh innovation do.
     *
     * @param  list<string>  $errors
     */
    private function ms8(mixed $target, array &$errors): void
    {
        if (! is_array($target)) {
            $errors[] = 'ms8_target không đúng khung.';

            return;
        }

        $expected = (array) ($this->snapshot['ms8'] ?? []);
        $innovation = (string) ($target['innovation'] ?? '');
        $entity = $this->entities['N:'.$innovation] ?? null;

        if ($entity === null) {
            $errors[] = "ms8_target: innovation \"{$innovation}\" không có trong thiết kế.";

            return;
        }

        if (($expected['innovation'] ?? null) === null) {
            $errors[] = 'ms8_target: bộ model sheet không có mục tiêu MS8 hợp lệ ('.($expected['problem'] ?? 'không rõ').').';
        } elseif ($innovation !== $expected['innovation']) {
            $errors[] = 'ms8_target: '.(($expected['declared'] ?? false) === true ? 'thiết kế khai' : 'mục tiêu mặc định (thiết kế không khai) và câu camera dùng')
                ." MS8 là {$expected['innovation']}, QA chọn {$innovation} — nếu mục tiêu không phù hợp, báo finding để sửa thiết kế.";
        }

        $marks = array_map('strval', (array) ($target['entities'] ?? []));

        foreach ($marks as $mark) {
            if (! isset($this->entities[$mark]) || $this->entities[$mark]['kind'] === 'innovation') {
                $errors[] = "ms8_target: mốc \"{$mark}\" không tồn tại.";
            }
        }

        if ($marks === [] && $entity['from'] === null) {
            $errors[] = "ms8_target: {$innovation} không có mốc nào và thiết kế không ghi phạm vi — camera chi tiết không có chỗ bám.";
        }

        $refs = array_map('strval', (array) ($target['refs'] ?? []));

        foreach ($refs as $ref) {
            if (! $this->resolves($ref)) {
                $errors[] = "ms8_target: tham chiếu \"{$ref}\" không tồn tại.";
            }
        }

        $own = [(string) $entity['path']];

        foreach ((array) ($this->dna['innovations'] ?? []) as $index => $item) {
            if (is_array($item) && ($item['id'] ?? null) === $innovation) {
                $own[] = "dna.innovations.{$index}";
            }
        }

        if ($marks !== [] && ! $this->cites($refs, $own)) {
            $errors[] = "ms8_target: quan hệ mốc thuộc {$innovation} phải có bằng chứng trong mô tả của chính {$innovation} (".implode(', ', $own).').';
        }
    }

    /**
     * Do phu: moi muc bat buoc dung mot lan; `used` phai chi ra khoi/goc hop le VA khoi do co cau
     * dan toi muc; nguon trang thai `used` phai co trang thai khai cho dung part_id.
     *
     * @param  array<string, mixed>  $qa
     * @param  array<string, true>  $parts
     * @param  list<string>  $errors
     */
    private function coverage(array $qa, array $parts, array &$errors): void
    {
        $required = [];

        foreach ((array) $this->snapshot['coverage'] as $item) {
            $required[(string) $item['id']] = $item;
        }

        $blocks = ['shared' => (array) $qa['shared'], 'surfaces' => (array) $qa['surfaces'], 'critical_absences' => (array) $qa['critical_absences'],
            'display_state' => array_map(static fn (mixed $s): array => is_array($s) ? ['refs' => $s['refs'] ?? []] : [], (array) $qa['display_state'])];

        foreach ((array) $qa['views'] as $view) {
            if (is_array($view)) {
                $blocks[(string) ($view['view_id'] ?? '')] = (array) ($view['sentences'] ?? []);
            }
        }

        $seen = [];

        foreach ($this->listOf($qa['coverage'], 'coverage', $errors) as $entry) {
            $id = (string) ($entry['item'] ?? '');
            $status = (string) ($entry['status'] ?? '');
            $where = array_map('strval', (array) ($entry['where'] ?? []));

            if (! isset($required[$id])) {
                $errors[] = "coverage: mục \"{$id}\" không phải mục bắt buộc.";

                continue;
            }

            if (isset($seen[$id])) {
                $errors[] = "coverage: mục {$id} được giải trình hơn một lần.";
            }

            $seen[$id] = true;

            if (! in_array($status, ['used', 'not_exterior', 'not_visible'], true)) {
                $errors[] = "coverage: mục {$id} có status không hợp lệ \"{$status}\" (used/not_exterior/not_visible).";

                continue;
            }

            if ($status !== 'used') {
                if (trim((string) ($entry['note'] ?? '')) === '') {
                    $errors[] = "coverage: mục {$id} ghi {$status} mà không có note giải thích.";
                }

                continue;
            }

            if ($where === []) {
                $errors[] = "coverage: mục {$id} ghi đã dùng nhưng không nói dùng ở đâu.";

                continue;
            }

            $linked = false;

            foreach ($where as $block) {
                if (! isset($blocks[$block])) {
                    $errors[] = "coverage: mục {$id} chỉ tới \"{$block}\", không phải khối hay góc của prompt.";

                    continue;
                }

                $linked = $linked || $this->linksItem($blocks[$block], $required[$id]);
            }

            if (! $linked) {
                $errors[] = "coverage: mục {$id} ghi đã dùng ở ".implode(', ', $where).' nhưng không câu nào ở đó dẫn tới nó.';
            }

            if ($required[$id]['kind'] === 'state_source' && ! isset($parts[$id])) {
                $errors[] = "coverage: nguồn trạng thái {$id} ghi đã dùng nhưng display_state không có part_id {$id}.";
            }
        }

        foreach ($required as $id => $item) {
            if (! isset($seen[$id])) {
                $errors[] = "coverage: chưa giải trình mục bắt buộc {$id} ({$item['kind']}).";
            }
        }
    }

    /**
     * @param  list<mixed>  $sentences
     * @param  array<string, mixed>  $item
     */
    private function linksItem(array $sentences, array $item): bool
    {
        $roots = (array) $item['paths'];
        $entity = $item['kind'] === 'innovation' ? 'N:'.$item['id'] : null;

        foreach ($sentences as $sentence) {
            $refs = is_array($sentence) ? array_map('strval', (array) ($sentence['refs'] ?? [])) : [];

            if (($entity !== null && in_array($entity, $refs, true)) || $this->cites($refs, $roots)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Thuc the duoc cac cau dan toi (ma thuc the, du kien cua no, duong dan cua no hoac duong dan con).
     *
     * @param  list<mixed>  $sentences
     * @return array<string, true>
     */
    private function mentioned(array $sentences): array
    {
        $found = [];

        foreach ($sentences as $sentence) {
            foreach (is_array($sentence) ? (array) ($sentence['refs'] ?? []) : [] as $ref) {
                if (($owner = $this->ownerOf((string) $ref)) !== null) {
                    $found[$owner] = true;
                }
            }
        }

        return $found;
    }

    /**
     * Vat che hop le: mot thuc the (moc, chi tiet keo dai, innovation — qua ma, du kien hoac duong
     * dan cua no), hoac mot bo phan vo/vung ngoai that co ten trong snapshot `occluders` (theo id
     * P:… hoac duong dan cua no). Chuoi bat ky duoi nhanh exterior (mau, thong so) KHONG du.
     */
    private function physicalPart(string $ref): bool
    {
        if ($this->ownerOf($ref) !== null) {
            return true;
        }

        if (isset($this->facts[$ref])) {
            return false;
        }

        foreach ((array) ($this->snapshot['occluders'] ?? []) as $part) {
            if ($ref === $part['id'] || $ref === $part['path'] || str_starts_with($ref, $part['path'].'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bo phan so huu mot tham chieu: ma thuc the; ma du kien (thuoc danh sach du kien cua thuc the
     * hoac nam duoi duong dan cua no); duong dan bang hoac nam duoi duong dan cua thuc the. Duong
     * dan cua innovation chua moc thi lay thuc the co duong dan dai nhat (cu the nhat).
     */
    private function ownerOf(string $ref): ?string
    {
        if (isset($this->entities[$ref])) {
            return $ref;
        }

        $path = isset($this->facts[$ref]) ? (string) $this->facts[$ref]['path'] : $ref;
        $owner = null;
        $length = -1;

        foreach ($this->entities as $id => $entity) {
            $root = (string) $entity['path'];

            if ((isset($this->facts[$ref]) && in_array($ref, (array) $entity['facts'], true)) || $path === $root || str_starts_with($path, $root.'.')) {
                if (strlen($root) > $length) {
                    [$owner, $length] = [$id, strlen($root)];
                }
            }
        }

        return $owner;
    }

    /**
     * Mot trong cac ref (duong dan hoac du kien) nam trong mot trong cac goc duong dan.
     *
     * @param  list<string>  $refs
     * @param  list<string>  $roots
     */
    private function cites(array $refs, array $roots): bool
    {
        foreach ($refs as $ref) {
            $path = isset($this->facts[$ref]) ? (string) $this->facts[$ref]['path'] : $ref;

            foreach ($roots as $root) {
                if ($path === $root || str_starts_with($path, $root.'.')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $errors
     * @return list<mixed>
     */
    private function listOf(mixed $value, string $where, array &$errors): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $errors[] = "{$where} phải là danh sách.";

            return [];
        }

        return $value;
    }

    private function interiorRef(string $ref): bool
    {
        $path = isset($this->facts[$ref]) ? (string) $this->facts[$ref]['path'] : (isset($this->entities[$ref]) ? (string) $this->entities[$ref]['path'] : $ref);

        foreach (self::INTERIOR_PATHS as $root) {
            if ($path === $root || str_starts_with($path, $root.'.')) {
                return true;
            }
        }

        return false;
    }

    private function pathValue(string $ref): mixed
    {
        [$root, $node] = match (true) {
            str_starts_with($ref, 'data.') => [substr($ref, 5), $this->design['data'] ?? null],
            str_starts_with($ref, 'dna.') => [substr($ref, 4), $this->dna],
            default => [null, null],
        };

        if ($root === null || $root === '') {
            return null;
        }

        foreach (explode('.', $root) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return $node;
    }
}
