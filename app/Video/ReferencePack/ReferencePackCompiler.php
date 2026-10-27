<?php

declare(strict_types=1);

namespace App\Video\ReferencePack;

/**
 * Phase 3R: lap danh sach muc cua bo anh tham chieu tu du kien thiet ke (code quyet dinh danh
 * sach bat buoc va do phu; khong giao cho LLM). Thuan: khong doc DB, khong goi model.
 *
 * Doc ca hai cau truc thiet ke dang co (yacht_bible.exterior_description hoac .exterior;
 * innovation .visible_expression hoac .visible), giu duong dan nguon cho moi du kien. Du kien
 * thieu thi muc bao thieu nguon thiet ke — khong tu them chi tiet hay kich thuoc.
 *
 * Muc dac thu chi bat buoc khi thiet ke thuc su co: helideck / stern platform theo bo phan co mat
 * o build state hoan thien cua canonical (present_parts), khong doan theo chu.
 */
final class ReferencePackCompiler
{
    public const VERSION = 'reference-pack-compiler-v1';

    public const LEVEL_EXTERIOR = 'exterior';
    public const LEVEL_INTERIOR = 'interior';
    public const LEVEL_VIDEO = 'video';
    public const LEVEL_STYLE = 'style';

    public const METHOD_REUSE = 'reuse';
    public const METHOD_CODE = 'code';
    public const METHOD_RENDER = 'render';
    public const METHOD_MISSING = 'missing_source';
    public const METHOD_NOT_APPLICABLE = 'not_applicable';
    public const METHOD_UNSUPPORTED = 'unsupported';
    public const METHOD_PENDING = 'pending';

    public const REQUIRED = 'required';
    public const OPTIONAL = 'optional';

    public const VIEW_IDS = ['MS1', 'MS2', 'MS3', 'MS4', 'MS5', 'MS6', 'MS7', 'MS8'];

    private const SUFFIX = 'Neutral light-grey studio background, soft even light. No text, labels, numbers, logos or watermarks anywhere in the image.';

    /** Chi tiet ngoai that: [ID, bo phan canonical can co mat (null = luon), khoa du kien theo thu tu uu tien, anh edit, anh bo sung]. */
    private const DETAILS = [
        ['bow', null, ['bow'], 'MS4', ['MS2']],
        ['stern', null, ['stern', 'transom'], 'MS5', ['MS3']],
        ['glazing', 'glazing', ['glazing'], 'MS1', ['MS2']],
        ['helideck', 'helideck', ['helideck'], 'MS6', ['MS7']],
        ['swim_platform', 'stern_platform', ['water_access', 'platform', 'stern_platform'], 'MS5', ['MS3']],
    ];

    /**
     * Du kien thiet ke dung cho 3R, kem duong dan nguon.
     *
     * @param  array<string, mixed>  $data  output_json.design.data cua ban thiet ke
     * @return array<string, mixed>
     */
    public static function facts(array $data): array
    {
        $bible = is_array($data['yacht_bible'] ?? null) ? $data['yacht_bible'] : [];
        [$exteriorPath, $exterior] = is_array($bible['exterior_description'] ?? null)
            ? ['yacht_bible.exterior_description', $bible['exterior_description']]
            : ['yacht_bible.exterior', is_array($bible['exterior'] ?? null) ? $bible['exterior'] : []];
        $anchors = is_array($data['anchors'] ?? null) ? $data['anchors'] : [];
        $canonical = is_array($data['canonical'] ?? null) ? $data['canonical'] : [];
        $states = array_values(array_filter((array) ($canonical['build_states'] ?? []), 'is_array'));
        $finished = array_values(array_filter($states, static fn (array $s): bool => ($s['kind'] ?? null) === 'finished'));
        $finalState = $finished === [] ? ($states === [] ? [] : $states[count($states) - 1]) : $finished[count($finished) - 1];
        $text = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return [
            'name' => $text($bible['name'] ?? null),
            'exterior_path' => $exteriorPath,
            'exterior' => array_filter(array_map($text, $exterior)),
            'yacht_anchor' => $text($anchors['yacht'] ?? null),
            'finished_surface' => $text($anchors['finished_surface'] ?? null) ?? $text($canonical['surfaces']['finished'] ?? null),
            'finished_surface_path' => $text($anchors['finished_surface'] ?? null) !== null ? 'anchors.finished_surface' : 'canonical.surfaces.finished',
            'finished_state' => $text($finalState['id'] ?? null),
            'finished_parts' => array_values(array_filter((array) ($finalState['present_parts'] ?? []), 'is_string')),
            'colors' => array_values(array_filter(array_map(static fn (mixed $c): ?array => is_array($c) && is_string($c['hex'] ?? null) && preg_match('/^#[0-9A-Fa-f]{6}$/', $c['hex']) === 1
                ? ['role' => (string) ($c['role'] ?? ''), 'name' => (string) ($c['name'] ?? ''), 'hex' => strtoupper($c['hex'])] : null, (array) ($bible['colors'] ?? [])))),
            'innovations' => array_values(array_filter(array_map(static fn (mixed $n): ?array => is_array($n) && is_string($n['id'] ?? null) ? [
                'id' => $n['id'],
                'name' => $text($n['name'] ?? null),
                'position' => $text($n['position'] ?? null),
                'geometry' => $text($n['geometry'] ?? null),
                'visible' => $text($n['visible_expression'] ?? $n['visible'] ?? null),
            ] : null, (array) ($bible['innovations'] ?? [])))),
            'rooms' => array_values(array_filter(array_map(static fn (mixed $r): ?array => is_array($r) && is_string($r['id'] ?? null) ? [
                'id' => $r['id'],
                'name' => $text($r['name'] ?? null),
                'deck' => $text($r['deck'] ?? null),
                'position' => $text($r['position'] ?? null),
                'size_feel' => $text($r['size_feel'] ?? null),
                'materials' => array_values(array_filter(array_map($text, (array) ($r['materials'] ?? [])))),
                'palette' => array_values(array_filter(array_map($text, (array) ($r['palette'] ?? [])))),
                'signature_detail' => $text($r['signature_detail'] ?? null),
                'window_type' => $text($r['window_type'] ?? null),
            ] : null, (array) ($bible['rooms'] ?? [])))),
            'interior_anchors' => array_filter(array_map($text, (array) ($anchors['interior'] ?? []))),
        ];
    }

    /**
     * Danh sach muc on dinh cua bo tham chieu.
     *
     * @param  array<string, mixed>  $facts  self::facts()
     * @param  array<string, string>  $viewLabels  MS1..MS8 => nhan goc cua bo model sheet
     * @return list<array<string, mixed>>
     */
    public static function plan(array $facts, array $viewLabels): array
    {
        $items = [];

        foreach (self::VIEW_IDS as $view) {
            $items[] = self::item("view.{$view}", 'views', $viewLabels[$view] ?? $view, self::LEVEL_EXTERIOR, self::REQUIRED, self::METHOD_REUSE,
                'Dùng lại ô '.$view.' của model sheet đã duyệt — không render lại.', ["model_sheet.crops.{$view}"], [], ['view' => $view]);
        }

        foreach (self::DETAILS as [$id, $part, $keys, $edit, $refs]) {
            $key = array_values(array_filter($keys, static fn (string $k): bool => isset($facts['exterior'][$k])))[0] ?? $keys[0];
            $source = $facts['exterior_path'].'.'.$key;
            $present = $part === null || in_array($part, $facts['finished_parts'], true);
            $fact = $facts['exterior'][$key] ?? null;

            $items[] = match (true) {
                ! $present => self::item("detail.{$id}", 'details', $id, self::LEVEL_EXTERIOR, self::OPTIONAL, self::METHOD_NOT_APPLICABLE,
                    "Build state hoàn thiện {$facts['finished_state']} không có bộ phận {$part}.", ['canonical.build_states.present_parts'], []),
                $fact === null => self::item("detail.{$id}", 'details', $id, self::LEVEL_EXTERIOR, self::REQUIRED, self::METHOD_MISSING,
                    "Thiết kế thiếu mô tả {$source}.", [$source], []),
                default => self::item("detail.{$id}", 'details', $id, self::LEVEL_EXTERIOR, self::REQUIRED, self::METHOD_RENDER,
                    'Ô MS 960×1080 chưa có vùng chi tiết đo được đủ độ phân giải cận cảnh — edit từ các góc MS thấy chi tiết này.', [$source, $facts['finished_surface_path']],
                    self::inputs($edit, $refs), ['must_show' => $fact]),
            };
        }

        foreach ($facts['innovations'] as $index => $innovation) {
            $source = "yacht_bible.innovations.{$index}";
            $label = trim($innovation['id'].' '.($innovation['name'] ?? ''));
            $mustShow = implode(' ', array_filter([$innovation['position'], $innovation['geometry'], $innovation['visible']]));
            $ms8 = strtolower($viewLabels['MS8'] ?? '');
            $targeted = $ms8 !== '' && (str_contains($ms8, strtolower($innovation['id'])) || ($innovation['name'] !== null && str_contains($ms8, strtolower($innovation['name']))));

            $items[] = $mustShow === ''
                ? self::item("detail.innovation.{$innovation['id']}", 'details', $label, self::LEVEL_EXTERIOR, self::REQUIRED, self::METHOD_MISSING,
                    "Thiết kế thiếu vị trí/hình học của {$innovation['id']}.", [$source], [])
                : self::item("detail.innovation.{$innovation['id']}", 'details', $label, self::LEVEL_EXTERIOR, self::REQUIRED, self::METHOD_RENDER,
                    $targeted ? 'MS8 là góc cận của innovation này — edit từ MS8.' : 'Edit từ góc MS nhìn thấy vị trí innovation.',
                    [$source, $facts['finished_surface_path']], $targeted ? self::inputs('MS8', ['MS1', 'MS7']) : self::inputs('MS7', ['MS1', 'MS2']),
                    ['must_show' => $mustShow]);
        }

        $items[] = $facts['colors'] === []
            ? self::item('color.exterior', 'colors', 'exterior colors', self::LEVEL_EXTERIOR, self::REQUIRED, self::METHOD_MISSING,
                'Thiết kế thiếu yacht_bible.colors có HEX hợp lệ.', ['yacht_bible.colors'], [])
            : self::item('color.exterior', 'colors', 'exterior colors', self::LEVEL_EXTERIOR, self::REQUIRED, self::METHOD_CODE,
                'Dựng bằng code từ HEX đã khai, giữ quan hệ màu–vai trò; không thêm màu để đủ số lượng.', ['yacht_bible.colors'], [], ['colors' => $facts['colors']]);

        foreach ($facts['rooms'] as $index => $room) {
            $source = "yacht_bible.rooms.{$index}";
            $anchor = $facts['interior_anchors'][$room['id']] ?? null;
            $missing = array_values(array_filter([
                $anchor === null ? "anchors.interior.{$room['id']}" : null,
                $room['materials'] === [] ? "{$source}.materials" : null,
                $room['window_type'] === null ? "{$source}.window_type" : null,
            ]));
            $label = trim($room['id'].' '.($room['name'] ?? ''));

            foreach (['finished' => 'ảnh phòng hoàn thiện', 'materials' => 'bảng vật liệu'] as $kind => $what) {
                $items[] = $missing === []
                    ? self::item("room.{$room['id']}.{$kind}", 'rooms', $label, self::LEVEL_INTERIOR, self::OPTIONAL, self::METHOD_RENDER,
                        "Tạo {$what} từ INT anchor và thông số phòng; không suy từ ảnh ngoại thất. Chỉ bắt buộc khi shot dùng phòng này.",
                        [$source, "anchors.interior.{$room['id']}"], [], ['room' => $room['id'], 'kind' => $kind])
                    : self::item("room.{$room['id']}.{$kind}", 'rooms', $label, self::LEVEL_INTERIOR, self::OPTIONAL, self::METHOD_MISSING,
                        'Thiếu nguồn thiết kế: '.implode(', ', $missing).'.', $missing, [], ['room' => $room['id'], 'kind' => $kind]);
            }
        }

        $items[] = self::item('video.turntable', 'turntable', 'turntable', self::LEVEL_VIDEO, self::OPTIONAL, self::METHOD_UNSUPPORTED,
            'Adapter tạo turntable từ khung MS chưa được triển khai và kiểm thử trong đợt này.', [], []);
        $items[] = self::item('style.refs', 'style', 'style refs', self::LEVEL_STYLE, self::OPTIONAL, self::METHOD_PENDING,
            'Bổ sung từ Phase 5L bằng revision pack mới; không chặn bước tạo style.', [], []);

        return $items;
    }

    /**
     * Prompt render cua mot muc (chi muc render). Ghep tu du kien da khai; khong them chi tiet.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $facts
     */
    public static function prompt(array $item, array $facts): string
    {
        if ($item['group'] === 'details') {
            return implode("\n\n", array_filter([
                'Close-up reference image of the '.$item['subject'].' of this exact yacht'.($facts['name'] !== null ? ' ('.$facts['name'].')' : '').'. The attached images are its approved model sheet views: keep the geometry, proportions, colours and materials exactly as shown and do not add parts that are not visible in them.',
                'Must show: '.$item['must_show'],
                $facts['finished_surface'] !== null ? 'Finished surface: '.$facts['finished_surface'] : null,
                'Finished state, the yacht only, three-quarter close view filling the frame. '.self::SUFFIX,
            ]));
        }

        $room = array_values(array_filter($facts['rooms'], static fn (array $r): bool => $r['id'] === ($item['room'] ?? null)))[0] ?? null;

        if ($item['group'] === 'rooms' && $room !== null && ($item['kind'] ?? null) === 'materials') {
            return implode("\n\n", array_filter([
                'Material board for the '.($room['name'] ?? $room['id']).' ('.$room['id'].') of a concept yacht interior.',
                'Flat lay of clean physical samples of exactly these materials: '.implode('; ', $room['materials']).'.',
                $room['palette'] === [] ? null : 'Palette: '.implode(', ', $room['palette']).'.',
                'Top-down view, even soft light, samples evenly spaced. '.self::SUFFIX,
            ]));
        }

        if ($item['group'] === 'rooms' && $room !== null) {
            return implode("\n\n", array_filter([
                'Interior reference image of the '.($room['name'] ?? $room['id']).' ('.$room['id'].') on the '.($room['deck'] ?? '').' deck of a concept yacht, in its finished state.',
                $facts['interior_anchors'][$room['id']] ?? null,
                $room['position'] !== null ? 'Position: '.$room['position'] : null,
                $room['size_feel'] !== null ? 'Size: '.$room['size_feel'] : null,
                'Materials: '.implode('; ', $room['materials']).'.',
                $room['palette'] === [] ? null : 'Palette: '.implode(', ', $room['palette']).'.',
                $room['signature_detail'] !== null ? 'Signature detail: '.$room['signature_detail'] : null,
                'Windows: '.$room['window_type'].'. Daylight consistent with this window type only.',
                'Photographic interior at eye level, no people. No text, labels, numbers, logos or watermarks anywhere in the image.',
            ]));
        }

        throw new \InvalidArgumentException("Mục {$item['id']} không render bằng prompt.");
    }

    /**
     * Anh nguon theo thu tu: anh edit truoc, toi da 4 tong cong (hop dong OpenAiImageClient).
     *
     * @param  list<string>  $refs
     * @return list<array{role: string, view: string}>
     */
    private static function inputs(string $edit, array $refs): array
    {
        return array_slice([['role' => 'edit', 'view' => $edit], ...array_map(static fn (string $v): array => ['role' => 'reference', 'view' => $v], $refs)], 0, 4);
    }

    /**
     * @param  list<string>  $sources
     * @param  list<array{role: string, view: string}>  $inputs
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function item(string $id, string $group, string $subject, string $level, string $requirement, string $method, string $reason, array $sources, array $inputs, array $extra = []): array
    {
        return ['id' => $id, 'group' => $group, 'subject' => $subject, 'level' => $level, 'requirement' => $requirement, 'method' => $method,
            'reason' => $reason, 'sources' => $sources, 'inputs' => $inputs, 'state' => 'finished'] + $extra;
    }
}
