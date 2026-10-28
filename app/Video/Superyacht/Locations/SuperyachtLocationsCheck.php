<?php

declare(strict_types=1);

namespace App\Video\Superyacht\Locations;

use App\Video\Locations\LocationReferencePlanner;

final class SuperyachtLocationsCheck
{
    public const FORMAT = 'phase5-locations-v1';

    public const VERSION = 'phase5-locations-check-v2';

    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNSCORED = 'UNSCORED';

    public const JUDGE = 'not_run';

    /**
     * @param  ?array<string, mixed>  $response
     * @param  array{project_id: string, prompt_version: string, rooms: list<string>, exterior_zones: list<string>, beats: list<array{id: string, rooms: list<array{id: string, state: string}>}>, tour: list<array{index: int, source_id: string, match_bare_beat: ?string}>, yacht: array{loa_m: ?float, beam_m: ?float, height_m: ?float}, forbidden: list<string>, source_names: list<string>, max_sources: int, policy: array<string, mixed>}  $context
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
        $spaces = self::spaces($data, $context, $issues);
        $bindings = (array) $data['bindings'];

        foreach ($bindings as $i => $binding) {
            self::resolve($spaces, (array) $binding['space'], (string) $binding['lighting'], (string) $binding['view'], "bindings[{$i}]", $issues);
        }

        self::coverage($bindings, $context, $issues);
        self::anchors($data, (array) $context['policy'], $issues, $warnings);
        self::names($data, $context, $issues);
        self::dimensions((array) $data['locations'], $context, $issues, $warnings);
        self::requests($data, $spaces, $bindings, $context, $issues);

        if ($status === 'partial') {
            $warnings[] = 'Model báo partial: '.implode('; ', array_filter((array) ($response['flags'] ?? []), 'is_string')).'.';
        }

        return self::result($issues === [] ? self::PASS : self::FAIL, $status, null, [], $issues, $warnings);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string> every anchor by the space and state it describes
     */
    public static function anchorTexts(array $data): array
    {
        $texts = [];

        foreach ((array) $data['locations'] as $location) {
            $texts[(string) $location['id']] = (string) $location['anchor'];
        }

        foreach ((array) $data['interior_sets'] as $set) {
            foreach ((array) $set['states'] as $state) {
                $texts[$set['room'].' '.$state['state']] = (string) $state['anchor'];
            }
        }

        foreach ((array) $data['exterior_amenities'] as $amenity) {
            $texts[(string) $amenity['zone']] = (string) $amenity['anchor'];
        }

        return $texts;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     * @param  list<string>  $issues
     * @return array<string, array{lighting: list<string>, states: ?list<string>}> every defined space by kind:id
     */
    private static function spaces(array $data, array $context, array &$issues): array
    {
        $spaces = [];
        $rooms = array_flip($context['rooms']);
        $zones = array_flip($context['exterior_zones']);
        $locationIds = [];

        foreach ((array) $data['locations'] as $location) {
            $locationIds[(string) $location['id']] = true;
            self::define($spaces, 'location:'.$location['id'], (array) $location['lighting'], (array) $location['views'], null, "Địa điểm {$location['id']}", $issues);
        }

        foreach ((array) $data['interior_sets'] as $set) {
            $room = (string) $set['room'];

            if (! isset($rooms[$room])) {
                $issues[] = "Phòng {$room} không có trong thiết kế nguồn.";
            }

            $states = array_map(static fn (array $state): string => (string) $state['state'], (array) $set['states']);

            if (count($states) !== count(array_unique($states))) {
                $issues[] = "Phòng {$room} khai một trạng thái hai lần.";
            }

            self::define($spaces, 'interior:'.$room, (array) $set['lighting'], (array) $set['views'], $states, "Phòng {$room}", $issues);
        }

        foreach ((array) $data['exterior_amenities'] as $amenity) {
            $zone = (string) $amenity['zone'];

            if (! isset($zones[$zone])) {
                $issues[] = "Vùng ngoài {$zone} không có trong thiết kế nguồn.";
            }

            if ($amenity['setting'] !== null && ! isset($locationIds[$amenity['setting']])) {
                $issues[] = "Vùng ngoài {$zone} đặt trong {$amenity['setting']}, địa điểm này không được định nghĩa.";
            }

            self::define($spaces, 'exterior:'.$zone, (array) $amenity['lighting'], (array) $amenity['views'], null, "Vùng ngoài {$zone}", $issues);
        }

        return $spaces;
    }

    /**
     * @param  array<string, array{lighting: list<string>, views: list<string>, states: ?list<string>}>  $spaces
     * @param  list<array<string, mixed>>  $lighting
     * @param  list<array<string, mixed>>  $views
     * @param  ?list<string>  $states
     * @param  list<string>  $issues
     */
    private static function define(array &$spaces, string $key, array $lighting, array $views, ?array $states, string $label, array &$issues): void
    {
        if (isset($spaces[$key])) {
            $issues[] = "{$label} được định nghĩa hai lần.";
        }

        $ids = array_map(static fn (array $light): string => (string) $light['id'], $lighting);
        $viewIds = array_map(static fn (array $view): string => (string) $view['id'], $views);

        if (count($ids) !== count(array_unique($ids))) {
            $issues[] = "{$label} có hai ánh sáng cùng id.";
        }

        if (count($viewIds) !== count(array_unique($viewIds))) {
            $issues[] = "{$label} có hai góc nhìn cùng id.";
        }

        $spaces[$key] = ['lighting' => $ids, 'views' => $viewIds, 'states' => $states];
    }

    /**
     * @param  array<string, array{lighting: list<string>, views: list<string>, states: ?list<string>}>  $spaces
     * @param  array<string, mixed>  $space
     * @param  list<string>  $issues
     */
    private static function resolve(array $spaces, array $space, string $lighting, string $view, string $at, array &$issues): bool
    {
        $key = $space['kind'].':'.$space['id'];
        $defined = $spaces[$key] ?? null;

        if ($defined === null) {
            $issues[] = "{$at} trỏ tới {$key}, không gian này không được định nghĩa.";

            return false;
        }

        $ok = true;

        if ($space['kind'] === 'interior' && ($space['state'] === null || ! in_array($space['state'], (array) $defined['states'], true))) {
            $issues[] = "{$at}: phòng {$space['id']} không có trạng thái ".json_encode($space['state']).' được định nghĩa.';
            $ok = false;
        }

        if ($space['kind'] !== 'interior' && $space['state'] !== null) {
            $issues[] = "{$at}: {$key} không phải phòng nội thất, state phải là null.";
            $ok = false;
        }

        if (! in_array($lighting, $defined['lighting'], true)) {
            $issues[] = "{$at}: ánh sáng {$lighting} không có trong {$key}.";
            $ok = false;
        }

        if (! in_array($view, $defined['views'], true)) {
            $issues[] = "{$at}: góc nhìn {$view} không có trong {$key}.";
            $ok = false;
        }

        return $ok;
    }

    /**
     * @param  list<array<string, mixed>>  $bindings
     * @param  array<string, mixed>  $context
     * @param  list<string>  $issues
     */
    private static function coverage(array $bindings, array $context, array &$issues): void
    {
        $beats = [];

        foreach ($context['beats'] as $beat) {
            $beats[$beat['id']] = $beat;
        }

        $byBeat = [];
        $stops = [];

        foreach ($bindings as $i => $binding) {
            $beat = (string) $binding['beat'];

            if (! isset($beats[$beat])) {
                $issues[] = "bindings[{$i}] nối beat {$beat}, kịch bản không có beat này.";

                continue;
            }

            $byBeat[$beat][] = $binding;

            if ($binding['tour_stop'] !== null) {
                $stops[(int) $binding['tour_stop']][] = $binding;
            }
        }

        foreach ($beats as $id => $beat) {
            if (! isset($byBeat[$id])) {
                $issues[] = "Beat {$id} chưa được nối với không gian nào.";

                continue;
            }

            foreach ($beat['rooms'] as $room) {
                $found = array_filter($byBeat[$id], static fn (array $b): bool => $b['space']['kind'] === 'interior' && $b['space']['id'] === $room['id'] && $b['space']['state'] === $room['state']);

                if ($found === []) {
                    $issues[] = "Beat {$id} dùng phòng {$room['id']} ở trạng thái {$room['state']} nhưng chưa được nối.";
                }
            }
        }

        $tourBeat = (string) ($context['tour_beat'] ?? 'B14');

        foreach ($context['tour'] as $stop) {
            $kind = str_starts_with($stop['source_id'], 'EXT') ? 'exterior' : 'interior';
            $found = array_filter($stops[$stop['index']] ?? [], static fn (array $b): bool => $b['beat'] === $tourBeat && $b['space']['kind'] === $kind && $b['space']['id'] === $stop['source_id']);

            if ($found === []) {
                $issues[] = "Điểm dừng tour #{$stop['index']} ({$stop['source_id']}) chưa được nối đúng không gian.";
            }
        }

        foreach (array_keys($stops) as $index) {
            if (! in_array($index, array_column($context['tour'], 'index'), true)) {
                $issues[] = "Binding trỏ tới điểm dừng tour #{$index}, tour không có điểm này.";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $policy
     * @param  list<string>  $issues
     * @param  list<string>  $warnings  prose that may contradict the space, for a person to read
     */
    private static function anchors(array $data, array $policy, array &$issues, array &$warnings): void
    {
        $max = (int) $policy['anchor_max_words'];

        foreach (self::anchorTexts($data) as $label => $anchor) {
            $words = self::words($anchor);

            if ($words > $max) {
                $issues[] = "Anchor {$label} có {$words} từ; tối đa {$max}.";
            }
        }

        foreach ((array) $data['locations'] as $location) {
            if (preg_match('/\byachts?\b/iu', (string) $location['anchor']) === 1) {
                $warnings[] = "Anchor địa điểm {$location['id']} có chữ \"yacht\" — đọc lại để chắc ảnh địa điểm không lấy tàu làm chủ thể.";
            }
        }

        foreach ((array) $data['interior_sets'] as $set) {
            $finished = [];

            foreach ((array) $set['states'] as $state) {
                if ($state['state'] === 'finished') {
                    $finished = array_map(static fn (string $material): string => mb_strtolower(trim($material)), (array) $state['materials']);
                }
            }

            foreach ((array) $set['states'] as $state) {
                if ($state['state'] === 'finished') {
                    continue;
                }

                $anchor = mb_strtolower((string) $state['anchor']);

                foreach ($finished as $material) {
                    if ($material !== '' && str_contains($anchor, $material)) {
                        $warnings[] = "Anchor {$set['room']} {$state['state']} có cụm \"{$material}\" (vật liệu của trạng thái finished) — đọc lại để chắc câu không tả vật liệu đó đã lắp.";
                    }
                }
            }
        }
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
                $issues[] = "Có tên nguồn thật \"{$name}\" trong mô tả.";
            }
        }

        foreach ($context['forbidden'] as $word) {
            if (trim($word) !== '' && preg_match('/\b'.preg_quote(mb_strtolower($word), '/').'\b/u', $text) === 1) {
                $issues[] = "Có từ cấm \"{$word}\" trong mô tả.";
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $locations
     * @param  array<string, mixed>  $context
     * @param  list<string>  $issues
     * @param  list<string>  $warnings
     */
    private static function dimensions(array $locations, array $context, array &$issues, array &$warnings): void
    {
        $policy = (array) $context['policy'];
        $clearance = (array) $policy['clearance_m'];
        $yacht = ['length_m' => $context['yacht']['loa_m'], 'width_m' => $context['yacht']['beam_m'], 'height_m' => $context['yacht']['height_m']];
        $axes = ['length_m' => 'length', 'width_m' => 'width', 'height_m' => 'height'];

        foreach ($locations as $location) {
            foreach ($axes as $axis => $word) {
                $dimension = (array) $location['dimensions'][$axis];

                if (($dimension['basis'] === 'unknown') !== ($dimension['value'] === null)) {
                    $issues[] = "{$location['id']}.{$axis}: basis unknown đi với value null, và ngược lại.";
                }
            }

            if (! in_array($location['kind'], (array) $policy['enclosing_kinds'], true)) {
                continue;
            }

            foreach ($axes as $axis => $word) {
                $value = $location['dimensions'][$axis]['value'];
                $need = $yacht[$axis] === null ? null : (float) $yacht[$axis] + (float) $clearance[$word];

                if ($value === null || $need === null) {
                    $warnings[] = "{$location['id']} ({$location['kind']}): chưa xác minh {$word} — ".($value === null ? 'địa điểm chưa có số đo' : 'thiết kế không nêu kích thước tàu tương ứng').'.';

                    continue;
                }

                if ((float) $value < $need) {
                    $issues[] = "{$location['id']} ({$location['kind']}) {$word} ".self::m((float) $value).' m không chứa được tàu: cần ≥ '.self::m($need).' m (tàu '.self::m((float) $yacht[$axis])." m + khoảng hở {$clearance[$word]} m).";
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array{lighting: list<string>, states: ?list<string>}>  $spaces
     * @param  list<array<string, mixed>>  $bindings
     * @param  array<string, mixed>  $context
     * @param  list<string>  $issues
     */
    private static function requests(array $data, array $spaces, array $bindings, array $context, array &$issues): void
    {
        $requests = [];

        foreach ((array) $data['reference_requests'] as $i => $request) {
            $id = (string) $request['id'];

            if (isset($requests[$id])) {
                $issues[] = "Yêu cầu ảnh {$id} khai hai lần.";
            }

            $requests[$id] = $request;
            self::resolve($spaces, (array) $request['space'], (string) $request['lighting'], (string) $request['view'], "reference_requests[{$i}] ({$id})", $issues);
        }

        $settings = array_column((array) $data['exterior_amenities'], 'setting', 'zone');

        foreach ($requests as $id => $request) {
            $inputs = (array) $request['inputs'];
            $space = (array) $request['space'];
            $sheet = 0;

            foreach ($inputs as $position => $input) {
                if ($input['kind'] === 'model_sheet') {
                    $sheet++;

                    if ($space['kind'] !== 'exterior') {
                        $issues[] = "Yêu cầu ảnh {$id}: chỉ vùng ngoài tàu được lấy model sheet làm ảnh nguồn.";
                    }

                    if ($input['id'] !== null || $input['role'] !== 'identity') {
                        $issues[] = "Yêu cầu ảnh {$id}: model sheet là ảnh nguồn vai identity, id null.";
                    }

                    continue;
                }

                if ($input['id'] === null || ! isset($requests[$input['id']])) {
                    $issues[] = "Yêu cầu ảnh {$id} lấy ảnh nguồn ".json_encode($input['id']).', yêu cầu này không tồn tại.';

                    continue;
                }

                if ($input['id'] === $id) {
                    $issues[] = "Yêu cầu ảnh {$id} lấy chính nó làm ảnh nguồn.";

                    continue;
                }

                $source = $requests[$input['id']];
                $sameSpace = $source['space']['kind'] === $space['kind'] && $source['space']['id'] === $space['id'];

                if ($input['role'] === 'keep_view' && ($position !== 0 || ! $sameSpace || $source['view'] !== $request['view'])) {
                    $issues[] = "Yêu cầu ảnh {$id}: ảnh giữ góc {$input['id']} phải đứng đầu danh sách, cùng không gian và cùng view {$request['view']}.";
                }

                if ($input['role'] === 'identity' && ! $sameSpace) {
                    $issues[] = "Yêu cầu ảnh {$id}: ảnh nhận dạng {$input['id']} phải cùng không gian {$space['kind']}:{$space['id']}.";
                }

                if ($input['role'] === 'setting' && ($space['kind'] !== 'exterior' || $source['space']['kind'] !== 'location' || $source['space']['id'] !== ($settings[$space['id']] ?? null))) {
                    $issues[] = "Yêu cầu ảnh {$id}: ảnh bối cảnh {$input['id']} chỉ dùng cho vùng ngoài tàu và phải là ảnh của địa điểm ".json_encode($settings[$space['id']] ?? null).' mà vùng đó đặt trong.';
                }
            }

            if ($sheet > 1) {
                $issues[] = "Yêu cầu ảnh {$id} khai model sheet hai lần.";
            }

            if (count($inputs) > (int) $context['max_sources']) {
                $issues[] = "Yêu cầu ảnh {$id} có ".count($inputs)." ảnh nguồn; tối đa {$context['max_sources']}.";
            }

            if ($request['space']['kind'] === 'exterior' && $sheet === 0) {
                $issues[] = "Yêu cầu ảnh {$id} (vùng ngoài tàu {$request['space']['id']}) phải lấy model sheet làm ảnh nguồn.";
            }
        }

        if (self::cyclic($requests)) {
            $issues[] = 'Các yêu cầu ảnh lấy ảnh nguồn của nhau thành vòng.';
        }

        $covered = array_flip(array_map(static fn (array $request): string => LocationReferencePlanner::usageKey((array) $request['space'], (string) $request['lighting'], (string) $request['view']), $requests));

        foreach (array_unique(array_map(static fn (array $binding): string => LocationReferencePlanner::usageKey((array) $binding['space'], (string) $binding['lighting'], (string) $binding['view']), $bindings)) as $key) {
            if (! isset($covered[$key])) {
                $issues[] = "Không gian {$key} (không gian:trạng thái:ánh sáng:góc nhìn) được dùng trong phim nhưng chưa có yêu cầu ảnh tham chiếu.";
            }
        }

        self::views($requests, $bindings, $context, $issues);
    }

    /**
     * @param  array<string, array<string, mixed>>  $requests
     * @param  list<array<string, mixed>>  $bindings
     * @param  array<string, mixed>  $context
     * @param  list<string>  $issues
     */
    private static function views(array $requests, array $bindings, array $context, array &$issues): void
    {
        $byRoom = [];

        foreach ($requests as $id => $request) {
            if ($request['space']['kind'] === 'interior') {
                $byRoom[$request['space']['id']][$id] = $request;
            }
        }

        foreach ($byRoom as $room => $roomRequests) {
            if (count(array_unique(array_map(static fn (array $r): string => (string) $r['space']['state'], $roomRequests))) < 2) {
                continue;
            }

            foreach (array_unique(array_column($roomRequests, 'view')) as $view) {
                $group = array_filter($roomRequests, static fn (array $r): bool => $r['view'] === $view);
                $roots = array_filter($group, static fn (array $r): bool => array_filter((array) $r['inputs'], static fn (array $input): bool => $input['kind'] === 'request' && $input['role'] === 'keep_view' && isset($group[$input['id']])) === []);

                if (count($group) > 1 && count($roots) !== 1) {
                    $issues[] = "Phòng {$room} góc {$view}: các ảnh nhiều trạng thái phải dựng từ một ảnh gốc rồi sửa sang trạng thái khác (đang có ".count($roots).' ảnh không lấy ảnh nào của cùng phòng làm ảnh giữ góc).';
                }
            }
        }

        $tourBeat = (string) ($context['tour_beat'] ?? 'B14');

        foreach ($context['tour'] as $stop) {
            if ($stop['match_bare_beat'] === null || ! str_starts_with($stop['source_id'], 'INT')) {
                continue;
            }

            $room = (string) $stop['source_id'];
            $beat = (string) $stop['match_bare_beat'];
            $inRoom = static fn (array $b): bool => $b['space']['kind'] === 'interior' && $b['space']['id'] === $room;
            $stopViews = array_unique(array_column(array_filter($bindings, static fn (array $b): bool => $b['beat'] === $tourBeat && $b['tour_stop'] === $stop['index'] && $inRoom($b)), 'view'));
            $bareViews = array_unique(array_column(array_filter($bindings, static fn (array $b): bool => $b['beat'] === $beat && $b['space']['state'] === 'bare' && $inRoom($b)), 'view'));

            if ($stopViews === []) {
                continue;
            }

            if ($bareViews === []) {
                $issues[] = "Điểm dừng tour #{$stop['index']} ({$room}) lặp góc của {$beat}, nhưng {$beat} không nối phòng {$room} ở trạng thái bare.";

                continue;
            }

            foreach (array_diff($stopViews, $bareViews) as $view) {
                $issues[] = "Điểm dừng tour #{$stop['index']} ({$room}) lặp góc của {$beat}: điểm dừng dùng view {$view}, cảnh bare của {$beat} dùng view ".implode(', ', $bareViews).' — hai cảnh phải chung một view.';
            }
        }
    }

    /** @param array<string, array<string, mixed>> $requests */
    private static function cyclic(array $requests): bool
    {
        $state = [];
        $visit = static function (string $id) use (&$visit, &$state, $requests): bool {
            if (($state[$id] ?? 0) === 1) {
                return true;
            }

            if (($state[$id] ?? 0) === 2 || ! isset($requests[$id])) {
                return false;
            }

            $state[$id] = 1;

            foreach ((array) $requests[$id]['inputs'] as $input) {
                if ($input['kind'] === 'request' && is_string($input['id']) && $visit($input['id'])) {
                    return true;
                }
            }

            $state[$id] = 2;

            return false;
        };

        foreach (array_keys($requests) as $id) {
            if ($visit((string) $id)) {
                return true;
            }
        }

        return false;
    }

    private static function words(string $text): int
    {
        return (int) preg_match_all('/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u', $text);
    }

    private static function m(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        $found = [];

        array_walk_recursive($values, static function (mixed $value) use (&$found): void {
            if (is_string($value)) {
                $found[] = $value;
            }
        });

        return $found;
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
