<?php

declare(strict_types=1);

namespace App\Video\Superyacht\Locations;

use App\Video\Locations\LocationReferencePlanner;

final class SuperyachtLocationsCheck
{
    public const FORMAT = 'phase5-locations-v4';

    public const VERSION = 'phase5-locations-check-v12';

    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNSCORED = 'UNSCORED';

    public const JUDGE = 'not_run';

    private const LIGHT_FIELDS = ['time_light', 'visual', 'action', 'tour'];

    private const CAMERA_MOVE = '/\b(descend(s|ing)?|ascend(s|ing)?|pan(s|ning)?|dolly|dollies|track(s|ing)? (along|past|toward|towards|with)|travel(s|ling|led|ing)|mov(e|es|ing)|slid(e|es|ing)|push(es|ing)? in|pull(s|ing)? (back|out)|crane(s|ing)? (up|down))\b|\bfrom \d+(\.\d+)? ?m to \d+(\.\d+)? ?m\b/i';

    private const YACHT_WORDS = '/\b(yachts?|hulls?)\b/iu';

    private const SUBJECTS = ['full_yacht' => 'tàu', 'component' => 'bộ phận', 'scale_model' => 'mô hình'];

    private const AXES = ['length_m' => 'length', 'width_m' => 'width', 'height_m' => 'height'];

    /**
     * @param  ?array<string, mixed>  $response
     * @param  array{project_id: string, prompt_version: string, beats: list<array<string, mixed>>, tour_beat: string, tour: list<array{index: int, source_id: string, visual: string, match_bare_beat: ?string, sec: ?float}>, beat_presence: array<string, array{bs: string, kind: string}>, yacht: array{loa_m: ?float, beam_m: ?float, height_m: ?float}, design: array<string, mixed>, forbidden: list<string>, source_names: list<string>, policy: array<string, mixed>}  $context
     * @param  list<array{code: string, path: string, message: string}>  $schema
     * @return array{version: string, format: string, verdict: string, status: ?string, error: ?string, schema: list<string>, issues: list<string>, warnings: list<string>, judge: string}
     */
    public static function evaluate(?array $response, array $context, array $schema): array
    {
        if ($response === null) {
            return self::result(self::UNSCORED, null, 'Phản hồi không đọc được JSON.');
        }

        if (($response['format'] ?? null) !== self::FORMAT) {
            return self::result(self::UNSCORED, null, 'Phản hồi không theo định dạng '.self::FORMAT.'.');
        }

        $status = is_string($response['status'] ?? null) ? $response['status'] : null;
        $issues = [];

        if (($response['project_id'] ?? null) !== $context['project_id']) {
            $issues[] = 'project_id không phải dự án của lượt chạy này.';
        }

        if (($response['prompt_version'] ?? null) !== $context['prompt_version']) {
            $issues[] = 'prompt_version là '.json_encode($response['prompt_version'] ?? null, JSON_UNESCAPED_UNICODE)."; lượt chạy này dùng {$context['prompt_version']}.";
        }

        $schemaLines = array_values(array_unique(array_map(static fn (array $v): string => "{$v['path']}: {$v['message']}", $schema)));

        if ($status === 'fail') {
            $issues[] = 'Model báo fail: '.implode('; ', array_filter((array) ($response['flags'] ?? []), 'is_string')).'.';

            return self::result(self::FAIL, $status, null, $schemaLines, $issues);
        }

        if ($schemaLines !== []) {
            return self::result(self::FAIL, $status, null, $schemaLines, $issues);
        }

        $data = (array) $response['data'];
        $warnings = [];
        $beats = array_column($context['beats'], null, 'id');
        $places = self::locations($data, $issues, $warnings);
        $context['locations'] = array_keys($places);
        $backgrounds = array_values((array) $data['backgrounds']);
        $ids = array_column($backgrounds, 'id');

        foreach (array_unique(array_diff_assoc($ids, array_unique($ids))) as $id) {
            $issues[] = "Mã bối cảnh {$id} bị trùng.";
        }

        foreach ($backgrounds as $background) {
            self::source($background, $beats, $context, $issues);
            self::uses($background, $beats, $context, $issues, $warnings);
            self::design($background, $beats, $context, $issues, $warnings);
            self::details($background, $places, $issues);
            self::designRefs($background, $beats, $context, $issues, $warnings);
            self::signatureLight($background, $beats, $context, $issues, $warnings);
            self::finishes($background, $context, $warnings);
            self::surroundings($background, $context, $warnings);
            self::exclusions($background, $context, $warnings);
            self::identity($background, $beats, $context, $issues, $warnings);
            self::levels($background, $context, $warnings);
            self::upgrades($background, $places, $context, $warnings);
            self::temperatures($background, $context, $warnings);
            self::dominant($background, $places, $context, $warnings);
            self::wording($background, $warnings);
        }

        self::duplicates($backgrounds, $beats, $context, $issues);
        self::identityShown($backgrounds, $context, $warnings);
        self::coverage($data, $backgrounds, $context, $issues);
        self::references($data, $ids, $beats, $context, $issues);
        self::continuity($data, $backgrounds, $beats, $context, $issues, $warnings);
        self::fit($places, self::standing($data, $backgrounds), $context, $issues, $warnings);
        self::pacing($backgrounds, $beats, $context, $warnings);
        self::names($data, $context, $issues);

        $open = (array) $data['conflicts'] !== [] || (array) $data['unresolved'] !== [];

        if ($open && $status === 'ok') {
            $issues[] = 'Còn xung đột hoặc mục chưa xác định mà status là ok — phải là partial và nói rõ trong flags.';
        }

        if ($status === 'partial') {
            $warnings[] = 'Model báo partial: '.implode('; ', array_filter((array) ($response['flags'] ?? []), 'is_string')).'.';
        }

        foreach ($backgrounds as $background) {
            foreach ((array) $background['assumptions'] as $assumption) {
                $warnings[] = "{$background['id']} giả định {$assumption['subject']}: {$assumption['value']} — {$assumption['reason']}.";
            }
        }

        return self::result($issues === [] ? self::PASS : self::FAIL, $status, null, [], $issues, $warnings);
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, mixed>  $context
     * @return ?string why the quote is not found verbatim in the field it names, null when it is
     */
    public static function quoteProblem(array $evidence, array $beats, array $context): ?string
    {
        $beat = $beats[$evidence['beat']] ?? null;

        if ($beat === null) {
            return "beat {$evidence['beat']} không có trong kịch bản";
        }

        if ($evidence['field'] === 'tour') {
            $stop = $evidence['tour_stop'];

            if ($evidence['beat'] !== $context['tour_beat'] || $stop === null || ! isset($context['tour'][$stop])) {
                return "câu trích tour phải nêu một điểm dừng của {$context['tour_beat']}";
            }

            $text = (string) $context['tour'][$stop]['visual'];
            $where = "{$context['tour_beat']} điểm dừng {$stop}";
        } else {
            $text = (string) ($beat[$evidence['field']] ?? '');
            $where = "{$evidence['beat']}.{$evidence['field']}";
        }

        return str_contains(self::norm($text), self::norm((string) $evidence['quote']))
            ? null
            : 'câu trích "'.$evidence['quote']."\" không có nguyên văn trong {$where}";
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $issues
     * @param  list<string>  $warnings
     * @return array<string, array<string, mixed>> every location by id, first declaration kept
     */
    private static function locations(array $data, array &$issues, array &$warnings): array
    {
        $places = [];
        $looks = [];

        foreach ((array) $data['locations'] as $location) {
            $id = (string) $location['id'];

            if (isset($places[$id])) {
                $issues[] = "Mã địa điểm {$id} bị trùng — mỗi nơi một mã.";

                continue;
            }

            $places[$id] = $location;
            $look = self::look($location);

            if (isset($looks[$look])) {
                $warnings[] = "{$id} và {$looks[$look]} giống nhau ở mọi thuộc tính cố định (loại, không gian, kích thước, chiều cao thông thủy, bề mặt, thiết bị, màu) — nghi là cùng một nơi; người duyệt kiểm, nếu đúng thì dùng chung một mã LOC.";
            }

            $looks[$look] ??= $id;

            foreach (['space' => [(string) $location['space']], 'surfaces' => (array) $location['surfaces'], 'fixed_equipment' => (array) $location['fixed_equipment']] as $field => $texts) {
                foreach ($texts as $text) {
                    if (preg_match(self::YACHT_WORDS, (string) $text, $match) === 1) {
                        $warnings[] = "{$id}.{$field} có chữ \"{$match[0]}\" — mô tả cố định của địa điểm không có tàu, tàu được thêm ở bước shot; đọc lại.";
                    }
                }
            }
        }

        return $places;
    }

    /**
     * @param  array<string, mixed>  $location
     * @return string the key two locations share only when every fixed attribute is the same: kind, space, the value of every dimension and of the clear height, surfaces, fixed equipment and palette
     */
    private static function look(array $location): string
    {
        $list = static function (array $texts): string {
            $texts = array_map(static fn (mixed $text): string => mb_strtolower(self::norm((string) $text)), $texts);
            sort($texts);

            return implode(';', $texts);
        };
        $figures = array_map(
            static fn (mixed $dimension): string => is_array($dimension) && is_numeric($dimension['value'] ?? null) ? self::m((float) $dimension['value']) : '-',
            [...array_map(static fn (string $axis): mixed => $location['dimensions'][$axis] ?? null, array_keys(self::AXES)), $location['fit']['clear_height_m'] ?? null],
        );

        return implode('|', [
            (string) $location['kind'],
            mb_strtolower(self::norm((string) $location['space'])),
            implode(';', $figures),
            $list((array) $location['surfaces']),
            $list((array) $location['fixed_equipment']),
            $list((array) $location['palette']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $background
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, mixed>  $context
     * @param  list<string>  $issues
     */
    private static function source(array $background, array $beats, array $context, array &$issues): void
    {
        $source = (array) $background['source'];
        $id = (string) $source['id'];
        $label = "{$background['id']} ({$background['kind']} {$id}".($source['state'] !== null ? ' '.$source['state'] : '').')';
        $design = (array) $context['design'];

        if ($background['kind'] === 'interior' && ! isset($design['rooms'][$id])) {
            $issues[] = "{$label}: phòng {$id} không có trong thiết kế đã ghim.";
        }

        if ($background['kind'] === 'exterior' && ! isset($design['zones'][$id])) {
            $issues[] = "{$label}: vùng {$id} không có trong thiết kế đã ghim.";
        }

        if ($background['kind'] === 'location' && ! in_array($id, $context['locations'], true)) {
            $issues[] = "{$label}: {$id} không có trong locations[] của chính kết quả này.";
        }

        if ($source['type'] === 'script_id') {
            $named = $background['kind'] === 'interior'
                ? self::roomNamed($id, (string) $source['state'], $context['beats'])
                : in_array($id, array_column($context['tour'], 'source_id'), true);

            if (! $named) {
                $issues[] = "{$label}: kịch bản không ghi {$id}".($source['state'] !== null ? " ở trạng thái {$source['state']}" : '').' — dùng mapped_id kèm câu trích.';
            }
        }

        foreach ((array) $source['evidence'] as $evidence) {
            if (($problem = self::quoteProblem($evidence, $beats, $context)) !== null) {
                $issues[] = "{$label}: bằng chứng gán ID — {$problem}.";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $background
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, mixed>  $context
     * @param  list<string>  $issues
     * @param  list<string>  $warnings
     */
    private static function uses(array $background, array $beats, array $context, array &$issues, array &$warnings): void
    {
        $id = (string) $background['id'];
        $source = (array) $background['source'];
        $served = [];

        foreach ((array) $background['uses'] as $use) {
            $beat = $beats[$use['beat']] ?? null;

            if ($beat === null) {
                $issues[] = "{$id}: beat {$use['beat']} không có trong kịch bản.";

                continue;
            }

            $served[] = (string) $use['beat'];
            $tour = $use['tour_stop'] !== null;

            if ($tour) {
                $stop = $context['tour'][$use['tour_stop']] ?? null;

                if ($use['beat'] !== $context['tour_beat'] || $stop === null) {
                    $issues[] = "{$id}: điểm dừng {$use['tour_stop']} không thuộc tour {$context['tour_beat']}.";

                    continue;
                }

                if ($background['kind'] === 'location' || $stop['source_id'] !== $source['id']) {
                    $issues[] = "{$id}: điểm dừng {$use['tour_stop']} là {$stop['source_id']}, bối cảnh này là {$source['id']}.";

                    continue;
                }
            }

            if ($background['kind'] !== 'interior') {
                continue;
            }

            $states = array_column(array_filter((array) $beat['rooms'], static fn (array $room): bool => $room['id'] === $source['id']), 'state');
            $where = $tour ? "{$use['beat']} điểm dừng {$use['tour_stop']}" : (string) $use['beat'];

            if ($states !== [] && ! in_array($source['state'], $states, true)) {
                $issues[] = "{$id}: kịch bản ghi {$source['id']} ở {$where} là ".implode(', ', $states).", bối cảnh này là {$source['state']}.";
            }

            if ($states === [] && $tour) {
                $warnings[] = "{$id}: kịch bản không ghi trạng thái của {$source['id']} ở {$where} — người duyệt kiểm trạng thái {$source['state']}.";
            }

            if ($states === [] && ! $tour && $source['type'] === 'script_id') {
                $issues[] = "{$id}: {$use['beat']} không ghi phòng {$source['id']} — một lần dùng như vậy cần mapped_id kèm câu trích.";
            }
        }

        $light = (array) $background['light']['evidence'];

        if (! in_array($light['field'], self::LIGHT_FIELDS, true)) {
            $issues[] = "{$id}: nguồn ánh sáng nền phải trích từ time_light, visual, action hoặc tour, không phải {$light['field']}.";
        }

        if (! in_array($light['beat'], $served, true)) {
            $issues[] = "{$id}: ánh sáng nền trích từ {$light['beat']}, beat bối cảnh này không phục vụ.";
        }

        if (($problem = self::quoteProblem($light, $beats, $context)) !== null) {
            $issues[] = "{$id}: ánh sáng nền — {$problem}.";
        }

        foreach ((array) $background['details'] as $detail) {
            if ($detail['evidence'] === null) {
                continue;
            }

            if (! in_array($detail['evidence']['beat'], $served, true)) {
                $issues[] = "{$id}: chi tiết \"{$detail['text']}\" trích từ {$detail['evidence']['beat']}, beat bối cảnh này không phục vụ.";
            }

            if (($problem = self::quoteProblem($detail['evidence'], $beats, $context)) !== null) {
                $issues[] = "{$id}: chi tiết \"{$detail['text']}\" — {$problem}.";
            }
        }

        foreach ((array) $background['shot_changes'] as $change) {
            foreach (array_diff((array) $change['beats'], $served) as $beat) {
                $issues[] = "{$id}: thay đổi cho shot nêu {$beat}, beat bối cảnh này không phục vụ.";
            }
        }

        foreach ((array) $background['condition'] as $condition) {
            if ($condition['limit'] !== null && ! in_array($condition['limit'], (array) $context['design']['limits'], true)) {
                $issues[] = "{$id}: giới hạn vận hành {$condition['limit']} không có trong thiết kế.";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $background
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, mixed>  $context
     * @param  list<string>  $issues
     * @param  list<string>  $warnings
     */
    private static function design(array $background, array $beats, array $context, array &$issues, array &$warnings): void
    {
        $id = (string) $background['id'];
        $source = (array) $background['source'];
        $design = (array) $context['design'];

        if ($background['kind'] === 'interior') {
            if (trim((string) ($design['interior_set_states'][$source['state']] ?? '')) === '') {
                $issues[] = "{$id}: thiết kế không có câu interior_set_states.{$source['state']} — không ghép được prompt.";
            }

            if ($source['state'] === 'finished') {
                self::finishedRoom($id, (string) $source['id'], $design, (array) $context['policy'], $issues, $warnings);
            }
        }

        if ($background['kind'] !== 'exterior') {
            return;
        }

        $surfaces = [];

        foreach ((array) $background['uses'] as $use) {
            $bs = (string) ($beats[$use['beat']]['bs'] ?? '');
            $surface = $design['surface_states'][$bs] ?? null;

            if (! is_string($surface) || trim($surface) === '') {
                $issues[] = "{$id}: thiết kế không có bề mặt của {$bs} ({$use['beat']}) — không ghép được prompt.";

                continue;
            }

            $surfaces[($design['build_kinds'][$bs] ?? '-').':'.mb_strtolower(self::norm($surface))][] = "{$use['beat']} {$bs}";
        }

        if (count($surfaces) > 1) {
            $issues[] = "{$id}: các lần dùng ở các giai đoạn đóng tàu hoặc trạng thái bề mặt khác nhau (".implode(' / ', array_map(static fn (array $uses): string => implode(', ', $uses), $surfaces)).') — tách thành bối cảnh riêng.';
        }

        $first = (string) ($beats[$background['uses'][0]['beat']]['bs'] ?? '');

        if (($design['build_kinds'][$first] ?? null) === 'finished' && trim((string) ($design['exterior_anchors'][$source['id']] ?? '')) === '') {
            $issues[] = "{$id}: thiết kế không có anchor của {$source['id']} — không ghép được prompt.";
        }
    }

    /**
     * @param  array<string, mixed>  $background
     * @param  array<string, array<string, mixed>>  $places
     * @param  list<string>  $issues
     */
    private static function details(array $background, array $places, array &$issues): void
    {
        $place = $background['kind'] === 'location' ? ($places[$background['source']['id']] ?? null) : null;

        if ($place === null) {
            return;
        }

        $fixed = [];

        foreach ([(string) $place['space'], ...(array) $place['surfaces'], ...(array) $place['fixed_equipment']] as $text) {
            $fixed[mb_strtolower(self::norm((string) $text))] = (string) $text;
        }

        foreach ((array) $background['details'] as $detail) {
            $copied = $fixed[mb_strtolower(self::norm((string) $detail['text']))] ?? null;

            if ($copied !== null) {
                $issues[] = "{$background['id']}: chi tiết \"{$detail['text']}\" chép lại mô tả cố định của {$place['id']} — mô tả cố định chỉ nằm ở locations[], hệ thống đã gửi nó kèm prompt.";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $background
     * @param  array<string, mixed>  $context
     * @param  list<string>  $warnings
     */
    private static function finishes(array $background, array $context, array &$warnings): void
    {
        $state = $background['source']['state'];

        if ($background['kind'] !== 'interior' || ! in_array($state, ['bare', 'fitout'], true)) {
            return;
        }

        $room = (array) ($context['design']['rooms'][$background['source']['id']] ?? []);
        $finished = self::tokens(implode(' ', (array) ($room['materials'] ?? [])), (array) $context['policy']);
        $named = array_values(array_intersect(self::tokens(self::authored($background), (array) $context['policy']), $finished));

        if ($named !== []) {
            $warnings[] = "{$background['id']}: phòng {$background['source']['id']} ở trạng thái {$state} có chữ trùng vật liệu hoàn thiện (".implode(', ', $named).") — người duyệt đọc prompt để chắc ảnh không đòi vật liệu đó lộ ra trái câu trạng thái {$state} của thiết kế.";
        }
    }

    /**
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>  $policy
     * @param  list<string>  $issues
     * @param  list<string>  $warnings
     */
    private static function finishedRoom(string $id, string $room, array $design, array $policy, array &$issues, array &$warnings): void
    {
        $entry = (array) ($design['rooms'][$room] ?? []);
        $fields = (array) ($entry['fields'] ?? []);
        $anchor = trim((string) ($design['interior_anchors'][$room] ?? ''));

        if ((array) ($entry['materials'] ?? []) === []) {
            if ($anchor === '') {
                $issues[] = "{$id}: thiết kế không có vật liệu lẫn anchor hoàn thiện của {$room} — không ghép được prompt.";
            } else {
                $warnings[] = "{$id}: thiết kế không có danh sách vật liệu của {$room} — prompt dùng anchor hoàn thiện thay thế; người duyệt kiểm ánh sáng trong anchor không trái ánh sáng nền.";
            }

            return;
        }

        if (trim((string) ($fields['signature_detail'] ?? '')) === '') {
            $warnings[] = "{$id}: thiết kế không có chi tiết nhận dạng của {$room} — prompt thiếu đặc điểm kiến trúc chủ đạo.";
        }

        $ignored = self::stems(implode(' ', (array) ($policy['anchor_ignore_words'] ?? [])), $policy);
        $known = [...self::stems(implode(' ', [...array_values($fields), ...(array) $entry['materials'], ...(array) ($entry['palette'] ?? [])]), $policy), ...$ignored];
        $only = array_values(array_unique(array_filter(
            preg_split('/[^\p{L}]+/u', mb_strtolower($anchor)) ?: [],
            static fn (string $word): bool => $word !== '' && array_intersect(self::stems($word, $policy), $known) === [] && self::stems($word, $policy) !== [],
        )));

        if ($only !== []) {
            $warnings[] = "{$id}: anchor hoàn thiện của {$room} có chữ không nằm trong các trường thiết kế (".implode(', ', array_slice($only, 0, 8)).') — prompt không dùng anchor; người duyệt kiểm thông tin đó có cần không.';
        }
    }

    /**
     * @param  array<string, mixed>  $background
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, mixed>  $context
     * @param  list<string>  $issues
     * @param  list<string>  $warnings
     */
    private static function designRefs(array $background, array $beats, array $context, array &$issues, array &$warnings): void
    {
        $id = (string) $background['id'];
        $refs = (array) ($background['design_refs'] ?? []);

        if ($refs === []) {
            return;
        }

        if ($background['kind'] === 'location') {
            $issues[] = "{$id}: ảnh địa điểm không mang chi tiết thiết kế của tàu (design_refs).";

            return;
        }

        $design = (array) $context['design'];
        $own = self::spaceText($background, $design);
        $space = (string) $background['source']['id'];

        foreach ($refs as $ref) {
            $innovation = $design['innovations'][$ref['id']] ?? null;

            if (! is_array($innovation)) {
                $issues[] = "{$id}: {$ref['id']} không có trong innovations của thiết kế đã ghim.";

                continue;
            }

            if (isset($design['spaces'])) {
                $relation = LocationReferencePlanner::relation($design, $space, $ref);

                if ($relation === null) {
                    $issues[] = "{$id}: thiết kế không cho {$ref['id']} ({$innovation['name']}) quan hệ ".json_encode($ref['relation'] ?? null)." với {$space} — "
                        .'mounted_in: '.(implode(', ', (array) ($innovation['mounted_in'] ?? [])) ?: '—')
                        .'; effect_in: '.(implode(', ', (array) ($innovation['effect_in'] ?? [])) ?: '—')
                        .'; serves: '.(implode(', ', (array) ($innovation['serves'] ?? [])) ?: '—').' (phục vụ một khu không làm thiết bị hiện ra ở đó).';

                    continue;
                }

                if ($relation === 'mounted' && ($innovation['visible'] ?? null) === null) {
                    $issues[] = "{$id}: {$ref['id']} không có mô tả phần nhìn thấy trong thiết kế — không ghép được prompt.";
                }

                self::featureStage($id, $ref['id'], $innovation, $background, $beats, $issues, $warnings);

                continue;
            }

            if (($innovation['visible'] ?? null) === null) {
                $issues[] = "{$id}: {$ref['id']} không có mô tả phần nhìn thấy trong thiết kế — không ghép được prompt.";
            }

            if (! str_contains(mb_strtolower(self::norm($own)), mb_strtolower(self::norm((string) $ref['quote'])))) {
                $issues[] = "{$id}: căn cứ của {$ref['id']} — câu trích \"{$ref['quote']}\" không có nguyên văn trong thiết kế của {$background['source']['id']}.";
            }

            $link = LocationReferencePlanner::featureLink($background, $design, (string) $ref['id']);

            if ($link === null) {
                $warnings[] = "{$id}: thiết kế không gắn {$ref['id']} ({$innovation['name']}) với {$background['source']['id']} — không nêu tên chi tiết đó trong mô tả của khu, cũng không nêu tên khu trong vị trí của chi tiết (chữ chung hay câu phủ định không tính); tham chiếu chưa giải quyết, không ghép vào prompt.";

                continue;
            }

            if (str_starts_with($link, 'vị trí')) {
                $warnings[] = "{$id}: {$ref['id']} ({$innovation['name']}) gắn với {$background['source']['id']} chỉ qua vị trí của nó (\"{$innovation['position']}\") — người duyệt kiểm chi tiết đó nằm trong khung hình của khu này chứ không chỉ ở cạnh khu.";
            }

            self::featureStage($id, $ref['id'], $innovation, $background, $beats, $issues, $warnings);
        }
    }

    /**
     * @param  array<string, mixed>  $innovation
     * @param  array<string, mixed>  $background
     * @param  array<string, array<string, mixed>>  $beats
     * @param  list<string>  $issues  a use at a build state before the feature exists
     * @param  list<string>  $warnings  a feature whose build state the design does not state
     */
    private static function featureStage(string $id, string $ref, array $innovation, array $background, array $beats, array &$issues, array &$warnings): void
    {
        $from = $innovation['from_state'] ?? null;

        if (! is_string($from)) {
            $warnings[] = "{$id}: thiết kế không ghi giai đoạn xuất hiện của {$ref} ({$innovation['name']}) — chưa kiểm được chi tiết đó đã có ở build state của các lần dùng; người duyệt kiểm.";
        }

        foreach ((array) $background['uses'] as $use) {
            $bs = (string) ($beats[$use['beat']]['bs'] ?? '');

            if (is_string($from) && self::stage($bs) !== null && self::stage($from) !== null && self::stage($bs) < self::stage($from)) {
                $issues[] = "{$id}: {$ref} ({$innovation['name']}) chỉ có từ {$from}, {$use['beat']} ở {$bs}.";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $background
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, mixed>  $context
     * @param  list<string>  $issues
     * @param  list<string>  $warnings
     */
    private static function signatureLight(array $background, array $beats, array $context, array &$issues, array &$warnings): void
    {
        if (! (bool) ($background['lighting_signature'] ?? false)) {
            return;
        }

        $id = (string) $background['id'];

        if ($background['kind'] === 'location' || ($context['design']['lighting_signature'] ?? null) === null) {
            $issues[] = $background['kind'] === 'location'
                ? "{$id}: ảnh địa điểm không mang chữ ký ánh sáng của tàu."
                : "{$id}: thiết kế không có lighting_signature — không ghép được prompt.";

            return;
        }

        $unbuilt = self::unlit($background, $beats, (array) $context['design']);

        if ($unbuilt !== null) {
            $issues[] = "{$id}: {$unbuilt} chưa có hệ đèn hoàn thiện của thiết kế — không dùng lighting_signature.";

            return;
        }

        if (array_intersect(self::stems((string) $context['design']['lighting_signature'], (array) $context['policy']), self::stems(self::spaceText($background, (array) $context['design']), (array) $context['policy'])) === []) {
            $warnings[] = "{$id}: chữ ký ánh sáng của thiết kế không chung chữ nào với thiết kế của {$background['source']['id']} — người duyệt kiểm hệ đèn đó có áp dụng cho khu vực này không.";
        }

        $words = array_map('mb_strtolower', (array) ($context['policy']['night_words'] ?? []));
        $texts = mb_strtolower(implode(' ', [
            (string) $background['light']['description'],
            ...array_map(static fn (array $use): string => (string) ($beats[$use['beat']]['time_light'] ?? ''), (array) $background['uses']),
        ]));

        if (array_filter($words, static fn (string $word): bool => preg_match('/\b'.preg_quote($word, '/').'\b/u', $texts) === 1) === []) {
            $warnings[] = "{$id}: dùng chữ ký ánh sáng của thiết kế nhưng ánh sáng nền và time_light của các lần dùng không phải đêm, hoàng hôn hay giờ xanh — người duyệt kiểm.";
        }
    }

    /**
     * @param  array<string, mixed>  $background
     * @param  array<string, mixed>  $context
     * @param  list<string>  $warnings
     */
    /**
     * @param  array<string, mixed>  $background
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, mixed>  $context
     * @param  list<string>  $issues  a must_keep rule of the space that applies at the uses and is not accounted for, a rule declared that is not one of them, or a rule declared more than once
     * @param  list<string>  $warnings  a rule of the space whose build state some uses reach and others do not
     */
    private static function identity(array $background, array $beats, array $context, array &$issues, array &$warnings): void
    {
        $design = (array) $context['design'];

        if (! isset($design['spaces']) || $background['kind'] === 'location') {
            return;
        }

        $id = (string) $background['id'];
        $space = (string) $background['source']['id'];
        $states = LocationReferencePlanner::states($background, array_map(static fn (array $beat): string => (string) ($beat['bs'] ?? ''), $beats));
        $required = array_column(LocationReferencePlanner::required($design, $space, $states), null, 'id');
        $keeps = array_values(array_filter((array) ($background['keeps'] ?? []), static fn (mixed $keep): bool => is_array($keep) && is_string($keep['rule'] ?? null)));
        $declared = array_column($keeps, null, 'rule');

        foreach (array_filter(array_count_values(array_column($keeps, 'rule')), static fn (int $count): bool => $count > 1) as $rule => $count) {
            $issues[] = "{$id}: keeps khai {$rule} {$count} lần — mỗi quy tắc khai đúng một lần, một câu trả lời in_frame.";
        }

        foreach (array_keys(array_diff_key($declared, $required)) as $rule) {
            $issues[] = "{$id}: {$rule} không phải quy tắc must_keep của {$space} áp ở các lần dùng này — keeps chỉ khai quy tắc của chính không gian.";
        }

        foreach (array_diff_key($required, $declared) as $rule => $entry) {
            $issues[] = "{$id}: chưa khai đặc điểm bắt buộc {$rule} của {$space} (\"{$entry['description']}\") — ghi in_frame true, hoặc false kèm lý do.";
        }

        foreach ([...(array) ($design['rules'][$space] ?? []), ...($background['kind'] === 'exterior' ? (array) ($design['rules']['YACHT'] ?? []) : [])] as $rule) {
            $reached = array_filter($states, static fn (string $state): bool => LocationReferencePlanner::applies($rule, [$state]));

            if ($reached !== [] && count($reached) < count($states)) {
                $warnings[] = "{$id}: các lần dùng nằm cả trước và từ {$rule['from_state']}, giai đoạn quy tắc {$rule['id']} (\"{$rule['description']}\") bắt đầu áp — quy tắc không ghép vào prompt; người duyệt kiểm hoặc tách bối cảnh.";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $background
     * @param  array<string, mixed>  $context
     * @param  list<string>  $warnings  a background of a space that rises through several decks whose scope names none of them, so the frame may open the whole envelope into one volume
     */
    private static function levels(array $background, array $context, array &$warnings): void
    {
        if ($background['kind'] === 'location' || ! isset($context['design']['spaces'])) {
            return;
        }

        $space = (string) $background['source']['id'];
        $decks = array_values(array_filter((array) ($context['design']['spaces'][$space]['decks'] ?? []), 'is_string'));
        $named = array_filter($decks, static fn (string $deck): bool => preg_match('/\b'.preg_quote($deck, '/').'[\s-]+(deck|landing|level)\b/iu', (string) $background['scope']) === 1);

        if (count($decks) > 1 && $named === []) {
            $warnings[] = "{$background['id']}: {$space} xuyên các boong ".implode(', ', $decks).' nhưng scope không nêu boong nào — ghi rõ chiếu nghỉ, đoạn thang hoặc tầng mà ảnh thể hiện; kích thước của thiết kế đo cả khối, không phải khoảng nhìn của ảnh.';
        }
    }

    /**
     * @param  list<array<string, mixed>>  $backgrounds
     * @param  array<string, mixed>  $context
     * @param  list<string>  $warnings  a must_keep rule of a space that no plate of that space keeps in frame, with the reasons given
     */
    private static function identityShown(array $backgrounds, array $context, array &$warnings): void
    {
        if (! isset($context['design']['spaces'])) {
            return;
        }

        $shown = [];
        $reasons = [];

        foreach ($backgrounds as $background) {
            foreach ($background['kind'] === 'location' ? [] : array_filter((array) ($background['keeps'] ?? []), 'is_array') as $keep) {
                $key = $background['source']['id'].'|'.$keep['rule'];
                $shown[$key] = ($shown[$key] ?? false) || ($keep['in_frame'] ?? false) === true;

                if (($keep['in_frame'] ?? false) !== true) {
                    $reasons[$key][] = "{$background['id']}: {$keep['reason']}";
                }
            }
        }

        foreach (array_keys(array_filter($shown, static fn (bool $any): bool => ! $any)) as $key) {
            [$space, $rule] = explode('|', $key, 2);
            $warnings[] = "Không ảnh nào của {$space} giữ đặc điểm bắt buộc {$rule} trong khung (".implode('; ', $reasons[$key]).') — người duyệt kiểm có cần một ảnh khác đảm nhiệm không.';
        }
    }

    private static function exclusions(array $background, array $context, array &$warnings): void
    {
        if (isset($context['design']['spaces'])) {
            return;
        }

        $source = (array) $background['source'];
        $design = (array) $context['design'];
        $fields = match ($background['kind']) {
            'interior' => array_intersect_key((array) ($design['rooms'][$source['id']]['fields'] ?? []), array_flip($source['state'] === 'finished' ? ['signature_detail', 'window_type'] : ['window_type'])),
            'exterior' => ['zone' => (string) ($design['zones'][$source['id']] ?? '')],
            default => [],
        };
        $avoid = mb_strtolower(implode(' ', array_map('strval', (array) $background['avoid'])));
        $missing = [];

        foreach ($fields as $field => $text) {
            preg_match_all('/\b(?:no|without)\s+([^.;:,()]+)/iu', (string) $text, $matches);

            foreach ($matches[1] as $phrase) {
                foreach ((array) preg_split('/\s+(?:or|and|nor)\s+/iu', (string) $phrase) as $object) {
                    $object = (string) preg_replace('/\s+(?:is|are)\s+(?:present|visible|shown|used)\s*$/iu', '', trim((string) $object));
                    $words = array_values(array_filter(
                        (array) preg_split('/[^\p{L}\-]+/u', mb_strtolower(trim((string) $object))),
                        static fn (string $word): bool => mb_strlen($word) >= 4 && ! in_array($word, ['present', 'visible', 'shown', 'used'], true),
                    ));

                    if ($words === [] || str_ends_with($words[0], 'ing')) {
                        continue;
                    }

                    if (array_filter($words, static fn (string $word): bool => str_contains($avoid, $word)) === []) {
                        $missing[] = "\"".trim((string) $object)."\" ({$field})";
                    }
                }
            }
        }

        if ($missing !== []) {
            $warnings[] = "{$background['id']}: thiết kế loại trừ ".implode(', ', array_unique($missing)).' mà avoid chưa nêu — câu của thiết kế vẫn nằm nguyên trong prompt; người duyệt kiểm có cần thêm vào phần loại trừ không.';
        }
    }

    /**
     * @param  array<string, mixed>  $background
     * @param  array<string, array<string, mixed>>  $places
     * @param  array<string, mixed>  $context
     * @param  list<string>  $warnings  a material or ornament the text asks for that the space's design does not give; the light is left out, where gold or crystal names a colour of light
     */
    private static function upgrades(array $background, array $places, array $context, array &$warnings): void
    {
        $design = (array) $context['design'];
        $place = $background['kind'] === 'location' ? ($places[$background['source']['id']] ?? null) : null;
        $sourced = mb_strtolower($background['kind'] === 'location' ? '' : self::spaceText($background, $design));
        $written = mb_strtolower(implode(' ', [
            (string) $background['scope'],
            (string) $background['composition'],
            (string) ($background['surroundings'] ?? ''),
            ...array_map(static fn (array $detail): string => (string) $detail['text'], (array) $background['details']),
            ...array_map(static fn (array $condition): string => (string) $condition['text'], (array) $background['condition']),
            ...array_map('strval', (array) $background['must_show']),
            ...($place === null ? [] : [(string) $place['space'], ...array_map('strval', (array) $place['surfaces']), ...array_map('strval', (array) $place['fixed_equipment'])]),
        ]));
        $found = array_values(array_filter(
            array_map('mb_strtolower', (array) ($context['policy']['unsourced_luxury'] ?? [])),
            static fn (string $word): bool => preg_match('/(?<![\p{L}\-])'.preg_quote($word, '/').'(?![\p{L}\-])/u', $written) === 1 && preg_match('/(?<![\p{L}\-])'.preg_quote($word, '/').'(?![\p{L}\-])/u', $sourced) !== 1,
        ));

        if ($found !== []) {
            $warnings[] = "{$background['id']}: có ".implode(', ', $found).' mà thiết kế của không gian này không có — không tự nâng cấp bằng vật liệu hoặc đồ trang trí không nguồn; người duyệt kiểm.';
        }
    }

    /**
     * @param  array<string, mixed>  $background
     * @param  array<string, mixed>  $context
     * @param  list<string>  $warnings
     */
    private static function temperatures(array $background, array $context, array &$warnings): void
    {
        $text = implode(' ', [(string) $background['light']['description'], ...array_map(static fn (array $detail): string => (string) $detail['text'], (array) $background['details'])]);
        preg_match_all('/\b(\d{4})\s?K\b/u', $text, $matches);
        $sourced = implode(' ', [
            (bool) ($background['lighting_signature'] ?? false) ? (string) ($context['design']['lighting_signature'] ?? '') : '',
            ...array_map(static fn (array $assumption): string => $assumption['subject'].' '.$assumption['value'], (array) $background['assumptions']),
        ]);
        $free = array_values(array_unique(array_filter($matches[1], static fn (string $kelvin): bool => ! str_contains($sourced, $kelvin))));

        if ($free !== []) {
            $warnings[] = "{$background['id']}: ánh sáng nêu ".implode(', ', array_map(static fn (string $kelvin): string => "{$kelvin} K", $free)).' mà thiết kế không nêu và không ghi là giả định — người duyệt kiểm.';
        }
    }

    /**
     * @param  array<string, mixed>  $background
     * @param  array<string, array<string, mixed>>  $places
     * @param  array<string, mixed>  $context
     * @param  list<string>  $warnings
     */
    private static function dominant(array $background, array $places, array $context, array &$warnings): void
    {
        $first = (string) ($background['must_show'][0] ?? '');
        $place = $background['kind'] === 'location' ? ($places[$background['source']['id']] ?? null) : null;

        if ($place !== null && in_array($place['kind'], (array) ($context['policy']['open_water_kinds'] ?? []), true)) {
            return;
        }

        $own = $place === null
            ? self::spaceText($background, (array) $context['design'])
            : implode(' ', [(string) $place['space'], ...array_map('strval', (array) $place['surfaces']), ...array_map('strval', (array) $place['fixed_equipment'])]);

        if ($first === '' || array_intersect(self::stems($first, (array) $context['policy'], 4), self::stems($own, (array) $context['policy'], 4)) === []) {
            $warnings[] = "{$background['id']}: must_show đầu tiên ".($first === '' ? 'trống' : "\"{$first}\"").' không nêu một đặc điểm kiến trúc có trong mô tả của chính không gian — người duyệt kiểm đặc điểm chủ đạo có xuất hiện trong ảnh không.';
        }
    }

    /**
     * @param  array<string, mixed>  $background
     * @param  array<string, mixed>  $design
     * @return string the design's own text of a designed room or area: the room's fields, materials and anchor — and the built-in pieces, furniture, fixed lights and layout of a design that records them — or the zone text and its anchor
     */
    private static function spaceText(array $background, array $design): string
    {
        $id = (string) $background['source']['id'];

        return match ($background['kind']) {
            'interior' => implode(' ', [
                ...array_values((array) ($design['rooms'][$id]['fields'] ?? [])),
                ...(array) ($design['rooms'][$id]['materials'] ?? []),
                (string) ($design['interior_anchors'][$id] ?? ''),
                (string) ($design['rooms'][$id]['text'] ?? ''),
            ]),
            'exterior' => implode(' ', [(string) ($design['zones'][$id] ?? ''), (string) ($design['exterior_anchors'][$id] ?? '')]),
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return list<string> the distinctive words of a text without a plural ending, so that "terrace" meets "terraces"
     */
    private static function stems(string $text, array $policy, ?int $length = null): array
    {
        $policy = $length === null ? $policy : ['token_min_length' => $length] + $policy;
        $words = [];

        foreach (self::tokens($text, $policy) as $word) {
            array_push($words, $word, ...array_filter(explode('-', $word), static fn (string $part): bool => mb_strlen($part) >= (int) ($policy['token_min_length'] ?? 5)));
        }

        return array_values(array_unique(array_map(static fn (string $word): string => (string) preg_replace('/(?<=\p{L}{3})(es|s)$/u', '', $word), $words)));
    }

    /**
     * @param  array<string, mixed>  $background
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, mixed>  $design
     * @return ?string what of a background is not finished yet, so that the design's finished lighting cannot be in it: a room that is not in its finished state, or an exterior use at a build state that is not of the finished kind; null when every use is finished
     */
    public static function unlit(array $background, array $beats, array $design): ?string
    {
        if ($background['kind'] === 'interior') {
            return $background['source']['state'] === 'finished' ? null : "phòng {$background['source']['id']} ở trạng thái {$background['source']['state']}";
        }

        foreach ((array) $background['uses'] as $use) {
            $bs = (string) ($beats[$use['beat']]['bs'] ?? '');

            if (($design['build_kinds'][$bs] ?? null) !== 'finished') {
                return "{$use['beat']} ở {$bs}";
            }
        }

        return null;
    }

    private static function stage(string $buildState): ?int
    {
        return preg_match('/^BS(\d+)$/', $buildState, $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * @param  array<string, mixed>  $background
     * @param  array<string, mixed>  $context
     * @param  list<string>  $warnings
     */
    private static function surroundings(array $background, array $context, array &$warnings): void
    {
        if ($background['kind'] !== 'exterior' || ! is_string($background['surroundings'])) {
            return;
        }

        $id = (string) $background['source']['id'];
        $design = (array) $context['design'];
        $area = self::tokens(((string) ($design['zones'][$id] ?? '')).' '.((string) ($design['exterior_anchors'][$id] ?? '')), (array) $context['policy']);
        $named = array_values(array_intersect(self::tokens($background['surroundings'], (array) $context['policy']), $area));

        if ($named !== []) {
            $warnings[] = "{$background['id']}: cảnh xung quanh của {$id} có chữ trùng với chính vùng tàu (".implode(', ', $named).') — người duyệt kiểm cảnh xung quanh chỉ nêu những gì nằm ngoài con tàu.';
        }
    }

    /**
     * @param  array<string, mixed>  $background
     * @param  list<string>  $warnings
     */
    private static function wording(array $background, array &$warnings): void
    {
        if (preg_match(self::CAMERA_MOVE, (string) $background['composition'], $match) === 1) {
            $warnings[] = "{$background['id']}: composition có thể tả chuyển động máy (\"{$match[0]}\") — ảnh bối cảnh là một khung tĩnh; đọc lại.";
        }

        if ($background['kind'] === 'location' && preg_match(self::YACHT_WORDS, self::authored($background), $match) === 1) {
            $warnings[] = "{$background['id']} ({$background['source']['id']}) có chữ \"{$match[0]}\" — ảnh địa điểm không có tàu, tàu được thêm ở bước shot; đọc lại.";
        }
    }

    /**
     * @param  list<array<string, mixed>>  $backgrounds
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, mixed>  $context
     * @param  list<string>  $issues
     */
    private static function duplicates(array $backgrounds, array $beats, array $context, array &$issues): void
    {
        $seen = [];

        foreach ($backgrounds as $background) {
            $conditions = array_map(static fn (array $condition): string => mb_strtolower(self::norm((string) $condition['text'])), (array) $background['condition']);
            sort($conditions);
            $key = implode('|', [$background['kind'], $background['source']['id'], $background['source']['state'] ?? '-', self::buildLook($background, $beats, $context), implode(';', $conditions), mb_strtolower(self::norm((string) $background['composition']))]);

            if (isset($seen[$key])) {
                $issues[] = "{$background['id']} giống hệt {$seen[$key]} (cùng không gian, trạng thái, giai đoạn đóng tàu và bề mặt, tình trạng và bố cục) — dùng chung một bối cảnh.";
            }

            $seen[$key] ??= (string) $background['id'];
        }
    }

    /**
     * @param  array<string, mixed>  $background
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, mixed>  $context
     * @return string what of the yacht's build an exterior background shows: the kind of build state and the surface of each of its uses, the two things its prompt is built from; empty for a room or a location
     */
    private static function buildLook(array $background, array $beats, array $context): string
    {
        if ($background['kind'] !== 'exterior') {
            return '';
        }

        $looks = array_map(static function (array $use) use ($beats, $context): string {
            $bs = (string) ($beats[$use['beat']]['bs'] ?? '');

            return ($context['design']['build_kinds'][$bs] ?? '-').':'.mb_strtolower(self::norm((string) ($context['design']['surface_states'][$bs] ?? '')));
        }, (array) $background['uses']);
        sort($looks);

        return implode(';', array_unique($looks));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $backgrounds
     * @param  array<string, mixed>  $context
     * @param  list<string>  $issues
     */
    private static function coverage(array $data, array $backgrounds, array $context, array &$issues): void
    {
        $covered = [];
        $stops = [];
        $rooms = [];

        foreach ($backgrounds as $background) {
            foreach ((array) $background['uses'] as $use) {
                $covered[$use['beat']] = true;

                if ($use['tour_stop'] !== null) {
                    $stops[$use['tour_stop']] = true;
                }

                if ($background['kind'] === 'interior') {
                    $rooms[$use['beat'].'|'.$background['source']['id'].'|'.$background['source']['state']] = true;
                }
            }
        }

        $beats = array_column($context['beats'], null, 'id');

        foreach (self::entries($data) as [$entry, $unplated]) {
            if (self::entryProblems($entry, $unplated, $beats, $context) !== []) {
                continue;
            }

            $covered[$entry['beat']] = true;

            if ($entry['tour_stop'] !== null) {
                $stops[$entry['tour_stop']] = true;
            }

            if (($entry['space']['kind'] ?? null) === 'interior') {
                $rooms[$entry['beat'].'|'.$entry['space']['id'].'|'.$entry['space']['state']] = true;
            }
        }

        foreach ($context['beats'] as $beat) {
            if (! isset($covered[$beat['id']])) {
                $issues[] = "Beat {$beat['id']} chưa có bối cảnh, lý do không cần ảnh riêng hoặc mục chưa xác định.";
            }

            foreach ((array) $beat['rooms'] as $room) {
                if (! isset($rooms[$beat['id'].'|'.$room['id'].'|'.$room['state']])) {
                    $issues[] = "Beat {$beat['id']} ghi phòng {$room['id']} ở trạng thái {$room['state']} mà không bối cảnh nào thể hiện, cũng không mục không cần ảnh riêng hay chưa xác định nào nêu phòng và trạng thái đó.";
                }
            }
        }

        foreach ($context['tour'] as $stop) {
            if (! isset($stops[$stop['index']])) {
                $issues[] = "Điểm dừng tour {$stop['index']} ({$stop['source_id']}) chưa có bối cảnh, lý do không cần ảnh riêng hoặc mục chưa xác định.";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $ids
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, mixed>  $context
     * @param  list<string>  $issues
     */
    private static function references(array $data, array $ids, array $beats, array $context, array &$issues): void
    {
        $design = array_merge(array_keys((array) $context['design']['rooms']), array_keys((array) $context['design']['zones']));

        foreach (self::entries($data) as [$entry, $unplated]) {
            foreach (self::entryProblems($entry, $unplated, $beats, $context) as $problem) {
                $issues[] = "Mục {$entry['part']}: {$problem}.";
            }
        }

        foreach ((array) $data['unresolved'] as $entry) {
            foreach ((array) $entry['evidence'] as $evidence) {
                if (($problem = self::quoteProblem($evidence, $beats, $context)) !== null) {
                    $issues[] = "Mục chưa xác định \"{$entry['text']}\": {$problem}.";
                }
            }

            foreach (array_diff((array) $entry['candidates'], $design) as $candidate) {
                $issues[] = "Mục chưa xác định \"{$entry['text']}\": ứng viên {$candidate} không có trong thiết kế.";
            }
        }

        foreach ((array) $data['conflicts'] as $conflict) {
            if ($conflict['background'] !== null && ! in_array($conflict['background'], $ids, true)) {
                $issues[] = "Xung đột {$conflict['field']}: bối cảnh {$conflict['background']} không có.";
            }

            if ($conflict['background'] === null && $conflict['beat'] === null) {
                $issues[] = "Xung đột {$conflict['field']} không nêu bối cảnh hay beat — không xác định được những bối cảnh phải chờ.";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $backgrounds
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, mixed>  $context
     * @param  list<string>  $issues
     * @param  list<string>  $warnings
     */
    private static function continuity(array $data, array $backgrounds, array $beats, array $context, array &$issues, array &$warnings): void
    {
        $byId = array_column($backgrounds, null, 'id');
        $served = static fn (array $background): array => array_column((array) $background['uses'], 'beat');
        $entries = array_values((array) $data['continuity']);

        foreach ($entries as $entry) {
            foreach (array_diff((array) $entry['backgrounds'], array_keys($byId)) as $missing) {
                $issues[] = "Nối cảnh \"{$entry['requirement']}\": bối cảnh {$missing} không có.";
            }

            $reached = array_merge([], ...array_map(static fn (string $id): array => isset($byId[$id]) ? $served($byId[$id]) : [], (array) $entry['backgrounds']));

            foreach ((array) $entry['beats'] as $beat) {
                if (! isset($beats[$beat]) || ! in_array($beat, $reached, true)) {
                    $issues[] = "Nối cảnh \"{$entry['requirement']}\": {$beat} không phải beat mà các bối cảnh nêu phục vụ.";
                }
            }
        }

        foreach (LocationReferencePlanner::matches($backgrounds, $context) as $match) {
            if ($match['bare'] === [] || $match['stop'] === []) {
                $warnings[] = "Điểm dừng tour {$match['index']} ({$match['room']}) phải giữ góc máy của {$match['beat']}, nhưng {$match['beat']} không có bối cảnh {$match['room']} riêng — người duyệt kiểm phần bàn giao.";

                continue;
            }

            $kept = array_filter($entries, static fn (array $entry): bool => array_intersect($match['bare'], (array) $entry['backgrounds']) !== []
                && array_intersect($match['stop'], (array) $entry['backgrounds']) !== []
                && in_array($match['beat'], (array) $entry['beats'], true) && in_array($context['tour_beat'], (array) $entry['beats'], true));

            if ($kept === []) {
                $issues[] = "Điểm dừng tour {$match['index']} ({$match['room']}) phải giữ góc máy của {$match['beat']}: thiếu mục nối cảnh nối ".implode('/', $match['bare']).' với '.implode('/', $match['stop'])." và nêu {$match['beat']}, {$context['tour_beat']}.";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $backgrounds
     * @return array<string, list<string>> the beats that stand on every location: the uses of its backgrounds and the unplated and unresolved entries that name it
     */
    private static function standing(array $data, array $backgrounds): array
    {
        $standing = [];

        foreach ($backgrounds as $background) {
            if ($background['kind'] === 'location') {
                foreach ((array) $background['uses'] as $use) {
                    $standing[(string) $background['source']['id']][] = (string) $use['beat'];
                }
            }
        }

        foreach (self::entries($data) as [$entry]) {
            if (($entry['space']['kind'] ?? null) === 'location') {
                $standing[(string) $entry['space']['id']][] = (string) $entry['beat'];
            }
        }

        return array_map(static fn (array $beats): array => array_values(array_unique($beats)), $standing);
    }

    /**
     * @param  array<string, array<string, mixed>>  $places
     * @param  array<string, list<string>>  $standing
     * @param  array<string, mixed>  $context
     * @param  list<string>  $issues
     * @param  list<string>  $warnings
     */
    private static function fit(array $places, array $standing, array $context, array &$issues, array &$warnings): void
    {
        $policy = (array) $context['policy'];
        $clearances = (array) $policy['clearance_m'];
        $yacht = ['length_m' => $context['yacht']['loa_m'], 'width_m' => $context['yacht']['beam_m'], 'height_m' => $context['yacht']['height_m']];

        foreach ($places as $id => $location) {
            $fit = (array) $location['fit'];
            $subject = (string) $fit['subject'];
            $listed = array_map('strval', (array) $fit['beats']);
            $size = is_array($fit['subject_size']) ? $fit['subject_size'] : null;
            $bound = $standing[$id] ?? [];

            if ($bound === []) {
                $issues[] = "{$id} không được bối cảnh, mục không cần ảnh riêng hay chưa xác định nào dùng — bỏ địa điểm này hoặc nối nó vào beat đứng ở đó.";
            }

            $figures = ['dimensions.length_m' => $location['dimensions']['length_m'], 'dimensions.width_m' => $location['dimensions']['width_m'], 'dimensions.height_m' => $location['dimensions']['height_m'], 'fit.clear_height_m' => $fit['clear_height_m']];

            foreach (self::AXES as $axis => $word) {
                $figures["fit.subject_size.{$axis}"] = $size[$axis] ?? null;
            }

            foreach ($figures as $path => $dimension) {
                if (is_array($dimension) && ($dimension['basis'] === 'unknown') !== ($dimension['value'] === null)) {
                    $issues[] = "{$id}.{$path}: basis unknown đi với value null, và ngược lại.";
                }
            }

            foreach (array_diff($listed, $bound) as $beat) {
                $issues[] = "{$id}.fit.beats nêu {$beat}, beat này không đứng ở {$id} (không bối cảnh, mục không cần ảnh riêng hay chưa xác định nào của {$id} phục vụ beat đó).";
            }

            if (($subject === 'none') !== ($listed === [])) {
                $issues[] = $subject === 'none'
                    ? "{$id}: fit.subject none nhưng fit.beats nêu ".implode(', ', $listed).'.'
                    : "{$id}: fit.subject {$subject} cần nêu các beat có {$subject} trong fit.beats.";
            }

            foreach ($bound as $beat) {
                $presence = $context['beat_presence'][$beat] ?? null;

                if (! is_array($presence)) {
                    continue;
                }

                $model = $presence['kind'] === 'scale_model';
                $needed = $model ? 'scale_model' : 'full_yacht';

                if ($subject !== $needed || ! in_array($beat, $listed, true)) {
                    $issues[] = "{$beat} ở {$presence['bs']} ".($model ? 'chỉ có mô hình' : 'có cả con tàu')." — {$id} phải chứa {$needed} và ghi {$beat} trong fit.beats"
                        .($subject === $needed ? '.' : ', không thay bằng '.(self::SUBJECTS[$subject] ?? 'không gian không có tàu').($subject === 'none' ? '.' : ' tự thêm.'));
                }
            }

            if (in_array($subject, ['component', 'scale_model'], true) !== ($size !== null)) {
                $issues[] = "{$id}: fit.subject_size bắt buộc với component và scale_model, để null với full_yacht và none.";
            }

            if ($fit['overhead'] === 'open' && $fit['clear_height_m']['value'] !== null) {
                $issues[] = "{$id}: fit.overhead open thì fit.clear_height_m để unknown.";
            }

            $enclosed = in_array($location['kind'], (array) $policy['enclosed_kinds'], true);
            $openWater = in_array($location['kind'], (array) $policy['open_water_kinds'], true);

            if ($enclosed && ($fit['footprint'] !== 'bounded' || $fit['overhead'] === 'open')) {
                $issues[] = "{$id} là {$location['kind']}, một không gian kín: fit.footprint phải là bounded và fit.overhead không được là open.";
            }

            if ($location['kind'] === 'test_tank' && $subject !== 'scale_model') {
                $warnings[] = "{$id} là test_tank nhưng fit.subject là {$subject} — đọc lại.";
            }

            if (in_array($location['kind'], ['construction_hall', 'paint_hall'], true) && $subject === 'none') {
                $warnings[] = "{$id} là {$location['kind']} nhưng không chứa gì của tàu (fit.subject none) — đọc lại.";
            }

            if (! isset(self::SUBJECTS[$subject])) {
                continue;
            }

            $label = self::SUBJECTS[$subject];
            $clearance = (array) $clearances[$subject];
            $target = $subject === 'full_yacht' ? $yacht : array_map(static fn (mixed $d): mixed => is_array($d) ? $d['value'] : null, (array) $size);
            $assumed = [];
            $axes = $fit['footprint'] === 'bounded' ? ['length_m' => $location['dimensions']['length_m'], 'width_m' => $location['dimensions']['width_m']] : [];

            if ($fit['footprint'] === 'open' && ! $openWater) {
                $warnings[] = "{$id}: chưa xác minh length, width — {$location['kind']} khai fit.footprint open nên không được so với {$label}.";
            }

            if ($fit['overhead'] === 'constrained') {
                $axes['height_m'] = $fit['clear_height_m'];
            } elseif ($fit['overhead'] === 'unknown') {
                $warnings[] = "{$id}: chưa xác minh chiều cao thông thủy — fit.overhead unknown.";
            } elseif (! $openWater) {
                $warnings[] = "{$id}: chưa xác minh clear height — {$location['kind']} khai fit.overhead open nên không được so với {$label}.";
            }

            foreach ($axes as $axis => $have) {
                $word = $axis === 'height_m' ? 'clear height' : self::AXES[$axis];
                $base = $target[$axis] ?? null;

                if ($have['value'] === null) {
                    $warnings[] = "{$id}: chưa xác minh {$word} — địa điểm chưa có số đo.";

                    continue;
                }

                if ($base === null) {
                    $warnings[] = "{$id}: chưa xác minh {$word} — chưa có kích thước {$label} tương ứng.";

                    continue;
                }

                $room = (float) $clearance[self::AXES[$axis]];

                if ((float) $have['value'] < (float) $base) {
                    $issues[] = "{$id} {$word} ".self::m((float) $have['value'])." m không chứa được {$label}: {$label} ".self::m((float) $base).' m.';

                    continue;
                }

                if ((float) $have['value'] < (float) $base + $room) {
                    $warnings[] = "{$id} {$word} ".self::m((float) $have['value'])." m chứa được {$label} (".self::m((float) $base).' m) nhưng dư ít hơn khoảng hở sản xuất khuyến nghị '.self::m($room).' m — chính sách cấu hình, không phải số thiết kế.';
                }

                if ($have['basis'] === 'production_assumption' || ($subject !== 'full_yacht' && ($size[$axis]['basis'] ?? null) === 'production_assumption')) {
                    $assumed[] = $word;
                }
            }

            if ($assumed !== []) {
                $warnings[] = "{$id}: đạt kích thước theo số giả định sản xuất (".implode(', ', $assumed).') — chưa phải số đã xác minh.';
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $backgrounds
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, mixed>  $context
     * @param  list<string>  $warnings
     */
    private static function pacing(array $backgrounds, array $beats, array $context, array &$warnings): void
    {
        $policy = (array) $context['policy'];
        $short = (float) ($policy['short_beat_sec'] ?? 0);
        $most = (int) ($policy['short_beat_max_must_show'] ?? PHP_INT_MAX);
        $detailsOnly = (array) ($policy['details_only_reveals'] ?? []);
        $stops = array_column($context['tour'], null, 'index');
        $counts = array_count_values(array_merge([], ...array_map(static fn (array $background): array => array_column((array) $background['uses'], 'beat'), $backgrounds)));

        foreach ($backgrounds as $background) {
            $seconds = [];

            foreach ((array) $background['uses'] as $use) {
                $beat = $beats[$use['beat']] ?? null;

                if ($beat === null) {
                    continue;
                }

                $stop = $use['tour_stop'] === null ? null : ($stops[$use['tour_stop']]['sec'] ?? null);
                $seconds[] = is_numeric($stop) ? (float) $stop : (is_numeric($beat['target_sec'] ?? null) ? (float) $beat['target_sec'] / max(1, $counts[$use['beat']] ?? 1) : null);

                if ($background['kind'] === 'exterior' && in_array($beat['reveal'] ?? null, $detailsOnly, true)) {
                    $warnings[] = "{$background['id']} (vùng ngoài tàu {$background['source']['id']}) phục vụ {$use['beat']} ở mức {$beat['reveal']} (chỉ chi tiết) — đọc lại để ảnh không lộ tàu sớm.";
                }
            }

            $details = count((array) $background['must_show']);
            $longest = $seconds === [] || in_array(null, $seconds, true) ? null : max($seconds);

            if ($longest !== null && $short > 0 && $longest <= $short && $details > $most) {
                $warnings[] = "{$background['id']} chỉ xuất hiện tối đa khoảng ".self::m($longest)." s mỗi lần nhưng có {$details} chi tiết bắt buộc — khung ngắn cần một chi tiết nhận ra ngay; đọc lại.";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{0: array<string, mixed>, 1: bool}> every unplated and unresolved entry, with true for an unplated one
     */
    private static function entries(array $data): array
    {
        return [
            ...array_map(static fn (array $entry): array => [$entry, true], array_values((array) $data['unplated'])),
            ...array_map(static fn (array $entry): array => [$entry, false], array_values((array) $data['unresolved'])),
        ];
    }

    /**
     * @param  array<string, mixed>  $entry  an unplated or unresolved entry
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, mixed>  $context
     * @return list<string> why the entry cannot stand for the use it names: a beat or stop that does not exist, a space that does not exist, or a space that is not the room in its state or the area the script gives that use; an unresolved entry may leave its space null, an unplated entry of a tour stop may not
     */
    private static function entryProblems(array $entry, bool $unplated, array $beats, array $context): array
    {
        $problems = [];
        $space = is_array($entry['space']) ? $entry['space'] : null;
        $beat = $beats[$entry['beat']] ?? null;

        if ($beat === null) {
            return ["beat {$entry['beat']} không có trong kịch bản"];
        }

        if ($space !== null && ($problem = self::spaceProblem($space, $context)) !== null) {
            $problems[] = $problem;
        }

        $label = $space === null ? '' : $space['kind'].' '.$space['id'].($space['state'] !== null ? ' '.$space['state'] : '');
        $states = static fn (string $room): array => array_column(array_filter((array) $beat['rooms'], static fn (array $entry): bool => $entry['id'] === $room), 'state');

        if ($entry['tour_stop'] === null) {
            if (($space['kind'] ?? null) === 'interior' && ($given = $states((string) $space['id'])) !== [] && ! in_array($space['state'], $given, true)) {
                $problems[] = "kịch bản ghi {$space['id']} ở {$entry['beat']} là ".implode(', ', $given).", mục nêu {$space['state']}";
            }

            return $problems;
        }

        $stop = $entry['beat'] === $context['tour_beat'] ? ($context['tour'][$entry['tour_stop']] ?? null) : null;

        if ($stop === null) {
            return [...$problems, "điểm dừng {$entry['tour_stop']} không thuộc tour {$context['tour_beat']}"];
        }

        $room = (string) $stop['source_id'];
        $kind = str_starts_with($room, 'INT') ? 'interior' : 'exterior';

        if ($space === null) {
            return $unplated ? [...$problems, "điểm dừng {$entry['tour_stop']} là {$room} — mục không cần ảnh riêng phải nêu đúng không gian đó trong space"] : $problems;
        }

        if ($space['kind'] !== $kind || $space['id'] !== $room) {
            return [...$problems, "điểm dừng {$entry['tour_stop']} là {$room}, mục nêu {$label}"];
        }

        if ($kind === 'interior' && ($given = $states($room)) !== [] && ! in_array($space['state'], $given, true)) {
            $problems[] = "kịch bản ghi {$room} ở {$entry['beat']} điểm dừng {$entry['tour_stop']} là ".implode(', ', $given).", mục nêu {$space['state']}";
        }

        return $problems;
    }

    /**
     * @param  array{kind: string, id: string, state: ?string}  $space
     * @param  array<string, mixed>  $context
     * @return ?string why the space an entry names does not exist, null when it does
     */
    private static function spaceProblem(array $space, array $context): ?string
    {
        return match ($space['kind']) {
            'interior' => ! isset($context['design']['rooms'][$space['id']]) || ! str_starts_with($space['id'], 'INT') ? "phòng {$space['id']} không có trong thiết kế đã ghim"
                : ($space['state'] === null ? "phòng {$space['id']} cần trạng thái" : null),
            'exterior' => ! isset($context['design']['zones'][$space['id']]) || ! str_starts_with($space['id'], 'EXT') ? "vùng {$space['id']} không có trong thiết kế đã ghim"
                : ($space['state'] !== null ? "vùng {$space['id']} không có trạng thái phòng" : null),
            default => ! in_array($space['id'], (array) $context['locations'], true) || ! str_starts_with($space['id'], 'LOC') ? "{$space['id']} không có trong locations[] của chính kết quả này"
                : ($space['state'] !== null ? "{$space['id']} không có trạng thái phòng" : null),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     * @param  list<string>  $issues
     */
    private static function names(array $data, array $context, array &$issues): void
    {
        $text = mb_strtolower(implode("\n", self::strings($data)));

        foreach ($context['source_names'] as $name) {
            if (trim($name) !== '' && str_contains($text, mb_strtolower($name))) {
                $issues[] = "Có tên nguồn thật \"{$name}\" trong kết quả.";
            }
        }

        foreach ($context['forbidden'] as $word) {
            if (trim($word) !== '' && preg_match('/\b'.preg_quote(mb_strtolower($word), '/').'\b/u', $text) === 1) {
                $issues[] = "Có từ cấm \"{$word}\" trong kết quả.";
            }
        }
    }

    /** @param list<array<string, mixed>> $beats */
    private static function roomNamed(string $room, string $state, array $beats): bool
    {
        foreach ($beats as $beat) {
            foreach ((array) $beat['rooms'] as $entry) {
                if ($entry['id'] === $room && $entry['state'] === $state) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $background the text Sonnet wrote for a background that reaches its prompt, without what it asks to avoid */
    private static function authored(array $background): string
    {
        return implode(' ', [
            (string) $background['scope'],
            (string) $background['composition'],
            (string) $background['light']['description'],
            (string) ($background['surroundings'] ?? ''),
            ...array_map(static fn (array $detail): string => (string) $detail['text'], (array) $background['details']),
            ...array_map(static fn (array $condition): string => (string) $condition['text'], (array) $background['condition']),
            ...array_map('strval', (array) $background['must_show']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return list<string> the distinctive words of a text: long enough and not on the generic list
     */
    private static function tokens(string $text, array $policy): array
    {
        preg_match_all('/\p{L}[\p{L}\-]*/u', mb_strtolower($text), $matches);
        $generic = array_map('mb_strtolower', (array) ($policy['generic_words'] ?? []));

        return array_values(array_unique(array_filter($matches[0], static fn (string $word): bool => mb_strlen($word) >= (int) ($policy['token_min_length'] ?? 5) && ! in_array($word, $generic, true))));
    }

    /** @return list<string> */
    private static function strings(mixed $node): array
    {
        if (is_string($node)) {
            return [$node];
        }

        return is_array($node) ? array_merge([], ...array_map(self::strings(...), array_values($node))) : [];
    }

    private static function norm(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function m(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
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
            'format' => self::FORMAT,
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
