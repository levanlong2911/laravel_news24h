<?php

declare(strict_types=1);

namespace App\Video\Superyacht\Script;

final class SuperyachtScriptCheck
{
    public const FORMAT = 'phase4-script-v2';

    public const VERSION = 'phase4-script-check-v2';

    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNSCORED = 'UNSCORED';

    public const JUDGE = 'not_run';

    private const ACTS = ['A1', 'A2', 'A3', 'A4'];

    private const CONSTRUCTION = ['BS06', 'BS07', 'BS08', 'BS09', 'BS10', 'BS11'];

    private const DENSITY_TYPES = ['STAGE', 'SPEC', 'NAME', 'QUESTION'];

    private const VARIETY = ['location', 'time_light', 'camera_family', 'action'];

    private const CHARACTER_WORDS = [20, 30];

    private const EPSILON = 0.0001;

    /**
     * @param  ?array<string, mixed>  $response
     * @param  array{project_id: string, prompt_version: string, video_length: int|float, rooms: list<string>, exterior_zones: list<string>, source_names: list<string>, forbidden: list<string>}  $context
     * @param  list<array{code: string, path: string, message: string}>  $schema
     * @param  array<string, mixed>  $policy  video.superyacht.script.check, pinned with the run
     * @return array{version: string, format: string, verdict: string, status: ?string, error: ?string, schema: list<string>, issues: list<string>, warnings: list<string>, judge: string}
     */
    public static function evaluate(?array $response, array $context, array $schema, array $policy): array
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

        $schemaLines = self::schemaLines($schema, $response);

        if ($status === 'fail') {
            $issues[] = 'Model báo fail: '.implode('; ', array_filter((array) ($response['flags'] ?? []), 'is_string')).'.';

            return self::result(self::FAIL, $status, null, $schemaLines, $issues);
        }

        if ($schemaLines !== []) {
            $issues[] = 'Chưa kiểm nghiệp vụ: cấu trúc chưa đúng hợp đồng Phase4Script@2.0.0.';

            return self::result(self::FAIL, $status, null, $schemaLines, $issues);
        }

        $data = $response['data'];
        $story = $data['story'];
        $script = $data['script'];
        $beats = $script['beats'];
        $length = (float) $policy['final_cut_sec'];
        $tolerance = (float) $policy['length_tolerance_sec'];

        if (abs((float) $context['video_length'] - $length) > self::EPSILON) {
            $issues[] = "Brief của lượt chạy là {$context['video_length']} s; phim final của hợp đồng v2 cố định ".self::s($length).' s.';
        }
        $byId = [];
        $starts = [];
        $total = 0.0;

        foreach ($beats as $beat) {
            $byId[$beat['id']] ??= $beat;
            $starts[$beat['id']] ??= $total;
            $total += (float) $beat['target_sec'];
        }

        $ids = array_column($beats, 'id');
        $expected = array_map(static fn (int $n): string => "B{$n}", range(0, 16));

        if ($ids !== $expected) {
            $issues[] = 'script.beats phải đủ B0–B16, đúng thứ tự, mỗi beat một lần; đang là: '.implode(', ', $ids).'.';
        }

        if (abs($total - $length) > $tolerance + self::EPSILON) {
            $issues[] = 'Tổng target_sec là '.self::s($total).' s; phim final dài '.self::s($length).' s (dung sai ±'.self::s($tolerance).' s).';
        }

        if (abs((float) $script['music_brief']['length'] - $length) > self::EPSILON) {
            $issues[] = 'music_brief.length là '.self::s((float) $script['music_brief']['length']).' s; phim final dài '.self::s($length).' s.';
        }

        if ($script['music_brief']['bpm'][0] > $script['music_brief']['bpm'][1]) {
            $issues[] = 'music_brief.bpm phải là [thấp, cao].';
        }

        array_push($issues, ...self::tourIssues($script, $story, $byId, $context, (array) $policy['tour'], $tolerance));
        array_push($issues, ...self::referenceIssues($story, $script, $byId));

        foreach ($story['loops'] as $loop) {
            if (! isset($starts[$loop['plant']], $starts[$loop['payoff']])) {
                continue;
            }

            $distance = $starts[$loop['payoff']] - $starts[$loop['plant']];

            if ($distance <= 0) {
                $issues[] = "Loop {$loop['id']}: payoff {$loop['payoff']} phải đến sau plant {$loop['plant']}.";
            } elseif ($distance + self::EPSILON < (float) $policy['loop_min_distance'] * $total) {
                $issues[] = "Loop {$loop['id']}: plant và payoff cách ".self::s($distance).' s; cần ≥ '.round((float) $policy['loop_min_distance'] * 100).'% phim ('.self::s((float) $policy['loop_min_distance'] * $total).' s).';
            }
        }

        $emotions = array_map(static fn (array $beat): int => (int) $beat['emotion'], $beats);

        if (isset($byId['B16']) && (int) $byId['B16']['emotion'] < max($emotions)) {
            $issues[] = 'Đỉnh cảm xúc phải ở B16 (B16 là '.$byId['B16']['emotion'].', cao nhất là '.max($emotions).').';
        }

        foreach ($data['anchors']['character'] as $name => $anchor) {
            $words = self::words($anchor);

            if ($words < self::CHARACTER_WORDS[0] || $words > self::CHARACTER_WORDS[1]) {
                $issues[] = "Character anchor {$name} có {$words} từ; cần 20–30.";
            }
        }

        array_push($issues, ...self::cardIssues($story, $script['text_cards'], $byId, $length, $context['forbidden'], $policy));

        foreach (self::strings($data) as $text) {
            foreach ($context['source_names'] as $name) {
                if (preg_match('/\b'.preg_quote($name, '/').'\b/iu', $text) === 1) {
                    $issues[] = "Kịch bản nhắc tên nguồn “{$name}”.";
                }
            }
        }

        $rooms = array_flip($context['rooms']);

        foreach ($beats as $beat) {
            foreach ($beat['rooms'] as $room) {
                if (! isset($rooms[$room['id']])) {
                    $issues[] = "Beat {$beat['id']} dùng phòng {$room['id']}, không có trong yacht_bible.rooms của thiết kế đã duyệt.";
                }
            }
        }

        $progress = [];
        $progressSeconds = 0.0;

        foreach ($beats as $beat) {
            if ($beat['progress']) {
                $progress[$beat['bs']][] = $beat['id'];
                $progressSeconds += (float) $beat['target_sec'];
            }
        }

        foreach ($progress as $state => $list) {
            if (count($list) > 1) {
                $issues[] = 'PROGRESS có '.count($list)." shot ở {$state} (".implode(', ', $list).'); tối đa một mỗi state.';
            }
        }

        if ($total > 0 && $progressSeconds > (float) $policy['progress_max_share'] * $total + self::EPSILON) {
            $issues[] = 'PROGRESS chiếm '.round($progressSeconds / $total * 100).'% thời lượng; tối đa '.round((float) $policy['progress_max_share'] * 100).'%.';
        }

        return self::result(
            $issues === [] ? self::PASS : self::FAIL,
            $status,
            null,
            [],
            $issues,
            self::warnings($story, $script, $byId, (array) $policy['warn']),
        );
    }

    /**
     * @param  array<string, mixed>  $script
     * @param  array<string, mixed>  $story
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array<string, mixed>  $context
     * @param  array{min_sec: int|float, max_sec: int|float, location_max_sec: int|float}  $tour
     * @return list<string>
     */
    private static function tourIssues(array $script, array $story, array $byId, array $context, array $tour, float $tolerance): array
    {
        $issues = [];
        $b14 = $byId['B14'] ?? null;

        if ($b14 === null) {
            return ['Thiếu beat B14 nên không kiểm được tour.'];
        }

        $target = (float) $b14['target_sec'];

        if ($target + self::EPSILON < (float) $tour['min_sec'] || $target - self::EPSILON > (float) $tour['max_sec']) {
            $issues[] = 'B14 dài '.self::s($target).' s; tour phải dài '.self::s((float) $tour['min_sec']).'–'.self::s((float) $tour['max_sec']).' s.';
        }

        $stops = $script['tour']['stops'];
        $sum = array_sum(array_map(static fn (array $stop): float => (float) $stop['sec'], $stops));

        if (abs($sum - $target) > $tolerance + self::EPSILON) {
            $issues[] = 'Các điểm dừng của tour cộng lại '.self::s($sum).' s; B14 dài '.self::s($target).' s.';
        }

        $known = array_flip([...$context['rooms'], ...$context['exterior_zones']]);
        $perPlace = [];
        $interior = [];

        foreach ($stops as $stop) {
            $perPlace[$stop['source_id']] = ($perPlace[$stop['source_id']] ?? 0.0) + (float) $stop['sec'];

            if (! isset($known[$stop['source_id']])) {
                $issues[] = "Tour dùng {$stop['source_id']}, không có trong yacht_bible.rooms hoặc exterior_zones của thiết kế đã duyệt.";
            }

            if (str_starts_with($stop['source_id'], 'INT') && ! in_array($stop['source_id'], $interior, true)) {
                $interior[] = $stop['source_id'];
            }
        }

        foreach ($perPlace as $place => $seconds) {
            if ($seconds > (float) $tour['location_max_sec'] + self::EPSILON) {
                $issues[] = "{$place} xuất hiện tổng ".self::s($seconds).' s trong tour; mỗi địa điểm tối đa '.self::s((float) $tour['location_max_sec']).' s.';
            }
        }

        $listed = array_column($b14['rooms'], 'id');

        if ($listed !== $interior) {
            $issues[] = 'B14.rooms ('.implode(', ', $listed).') phải khớp đúng các phòng INT của tour theo thứ tự xuất hiện ('.implode(', ', $interior).').';
        }

        foreach ($b14['rooms'] as $room) {
            if ($room['set_state'] !== 'finished') {
                $issues[] = "Phòng {$room['id']} trong tour phải ở trạng thái finished.";
            }
        }

        foreach ($stops as $stop) {
            $bare = $stop['match_bare_beat'];

            if ($bare === null) {
                continue;
            }

            $beat = $byId[$bare] ?? null;
            $isBare = $beat !== null && in_array(['id' => $stop['source_id'], 'set_state' => 'bare'], array_map(static fn (array $room): array => ['id' => $room['id'], 'set_state' => $room['set_state']], $beat['rooms']), true);

            if ($beat === null || self::position($bare) >= 14 || ! $isBare) {
                $issues[] = "Điểm dừng {$stop['source_id']} tham chiếu {$bare}, nhưng {$bare} không phải beat trước B14 có phòng {$stop['source_id']} ở trạng thái bare.";
            }
        }

        foreach ($story['loops'] as $loop) {
            if ($loop['payoff'] !== 'B14' || ! isset($byId[$loop['plant']])) {
                continue;
            }

            $bareRooms = array_column(array_filter($byId[$loop['plant']]['rooms'], static fn (array $room): bool => $room['set_state'] === 'bare'), 'id');

            if ($bareRooms === []) {
                continue;
            }

            $matched = array_filter($stops, static fn (array $stop): bool => in_array($stop['source_id'], $bareRooms, true) && $stop['match_bare_beat'] === $loop['plant']);

            if ($matched === []) {
                $issues[] = "Loop {$loop['id']} là loop phòng trống ({$loop['plant']} → B14) nhưng tour không có điểm dừng của ".implode('/', $bareRooms)." với match_bare_beat {$loop['plant']}.";
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $story
     * @param  array<string, mixed>  $script
     * @param  array<string, array<string, mixed>>  $byId
     * @return list<string>
     */
    private static function referenceIssues(array $story, array $script, array $byId): array
    {
        $references = [];

        foreach ($story['obstacles'] as $obstacle) {
            $references[] = ["obstacle {$obstacle['id']}", $obstacle['beat']];
        }

        foreach ($story['loops'] as $loop) {
            $references[] = ["loop {$loop['id']} (plant)", $loop['plant']];
            $references[] = ["loop {$loop['id']} (payoff)", $loop['payoff']];
        }

        foreach ($story['money_shots'] as $shot) {
            $references[] = ['money shot', $shot['beat']];
        }

        foreach ($script['text_cards'] as $card) {
            $references[] = ["card “{$card['text']}”", $card['beat']];
        }

        foreach ($script['sfx_plan'] as $sfx) {
            $references[] = ['sfx_plan', $sfx['beat']];
        }

        $issues = [];

        foreach ($references as [$label, $beat]) {
            if (! isset($byId[$beat])) {
                $issues[] = "{$label} nhắc beat {$beat}, không có trong script.beats.";
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $story
     * @param  list<array<string, mixed>>  $cards
     * @param  array<string, array<string, mixed>>  $byId
     * @param  list<string>  $forbidden
     * @param  array<string, mixed>  $policy
     * @return list<string>
     */
    private static function cardIssues(array $story, array $cards, array $byId, float $length, array $forbidden, array $policy): array
    {
        $issues = [];
        $perBeat = [];
        $counted = 0;
        $types = [];
        $questions = [];
        $from = self::position((string) $policy['name_card_from']);

        foreach ($cards as $card) {
            $words = self::words($card['text']);

            if ($words > (int) $policy['card_max_words']) {
                $issues[] = "Card “{$card['text']}” có {$words} từ; tối đa {$policy['card_max_words']}.";
            }

            $perBeat[$card['beat']] = ($perBeat[$card['beat']] ?? 0.0) + (float) $card['sec'];
            $types[$card['type']] = true;
            $counted += in_array($card['type'], self::DENSITY_TYPES, true) ? 1 : 0;

            if ($card['type'] === 'NAME' && self::position($card['beat']) < $from) {
                $issues[] = "Card tên “{$card['text']}” ở {$card['beat']}; tên tàu xuất hiện sớm nhất ở {$policy['name_card_from']} (cuối A1).";
            }

            if ($card['type'] === 'QUESTION') {
                $questions[] = $card['text'];
            }

            foreach ($forbidden as $word) {
                if (preg_match('/\b'.preg_quote($word, '/').'\b/iu', $card['text']) === 1) {
                    $issues[] = "Card “{$card['text']}” dùng từ cấm “{$word}”.";
                }
            }
        }

        foreach ($perBeat as $beat => $seconds) {
            if (isset($byId[$beat]) && $seconds > (float) $byId[$beat]['target_sec'] + self::EPSILON) {
                $issues[] = "Card ở {$beat} dài tổng ".self::s($seconds).' s, vượt target_sec '.self::s((float) $byId[$beat]['target_sec']).' s (card nằm trong beat, mỗi lúc một card).';
            }
        }

        $allowed = (int) floor($length / (float) $policy['card_seconds_per_card']);

        if ($counted > $allowed) {
            $issues[] = "{$counted} card STAGE/SPEC/NAME/QUESTION cho phim ".self::s($length)." s; tối đa {$allowed} (≤ 1 card mỗi {$policy['card_seconds_per_card']} s).";
        }

        foreach (['DISCLAIMER', 'END'] as $type) {
            if (! isset($types[$type])) {
                $issues[] = "Thiếu card {$type}.";
            }
        }

        if (count($questions) > 1) {
            $issues[] = 'Có '.count($questions).' card QUESTION; tối đa một (Step 1.2).';
        }

        if ($story['question_card'] === null && $questions !== []) {
            $issues[] = 'story.question_card là null nhưng có card QUESTION.';
        }

        if ($story['question_card'] !== null && count($questions) === 1 && $questions[0] !== $story['question_card']) {
            $issues[] = "Card QUESTION “{$questions[0]}” khác story.question_card “{$story['question_card']}”.";
        }

        if ($story['question_card'] !== null) {
            foreach ($forbidden as $word) {
                if (preg_match('/\b'.preg_quote($word, '/').'\b/iu', $story['question_card']) === 1) {
                    $issues[] = "story.question_card dùng từ cấm “{$word}”.";
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $story
     * @param  array<string, mixed>  $script
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array{beat_min_sec: int|float, hero_min_sec: int|float, hero_card_share: int|float, construction_run: int, money_shots_outside: int}  $warn
     * @return list<string>
     */
    private static function warnings(array $story, array $script, array $byId, array $warn): array
    {
        $warnings = [];
        $beats = $script['beats'];

        foreach ($beats as $beat) {
            if ((float) $beat['target_sec'] + self::EPSILON < (float) $warn['beat_min_sec']) {
                $warnings[] = "Beat {$beat['id']} chỉ ".self::s((float) $beat['target_sec']).' s, khó đọc kịp (nhắc dưới '.self::s((float) $warn['beat_min_sec']).' s).';
            }
        }

        if (isset($byId['B16'])) {
            $hero = (float) $byId['B16']['target_sec'];
            $cards = array_sum(array_map(static fn (array $card): float => $card['beat'] === 'B16' ? (float) $card['sec'] : 0.0, $script['text_cards']));

            if ($hero + self::EPSILON < (float) $warn['hero_min_sec']) {
                $warnings[] = 'Hero B16 chỉ '.self::s($hero).' s (nhắc dưới '.self::s((float) $warn['hero_min_sec']).' s).';
            }

            if ($hero > 0 && $cards / $hero > (float) $warn['hero_card_share'] + self::EPSILON) {
                $warnings[] = 'Card chiếm '.round($cards / $hero * 100).'% thời lượng hero B16 (nhắc trên '.round((float) $warn['hero_card_share'] * 100).'%).';
            }
        }

        $outside = count(array_filter($story['money_shots'], static fn (array $shot): bool => ! $shot['from_suggestions']));

        if ($outside < (int) $warn['money_shots_outside']) {
            $warnings[] = "Chỉ {$outside} money shot ngoài danh sách gợi ý Step 1.9 (khuyến nghị ≥ {$warn['money_shots_outside']}).";
        }

        $acts = [];

        foreach ($story['money_shots'] as $shot) {
            if (isset($byId[$shot['beat']])) {
                $acts[$byId[$shot['beat']]['act']] = true;
            }
        }

        foreach (array_diff(self::ACTS, array_keys($acts)) as $act) {
            $warnings[] = "Act {$act} chưa có money shot nào (khuyến nghị một mỗi act).";
        }

        $run = [];
        $previous = null;
        $flush = static function (array $run) use (&$warnings, $warn): void {
            if (count($run) > (int) $warn['construction_run']) {
                $warnings[] = 'Một build state thi công lấp '.count($run).' beat liền nhau ('.implode(', ', $run).'); khuyến nghị tối đa '.$warn['construction_run'].'.';
            }
        };

        foreach ($beats as $beat) {
            if ($beat['bs'] !== $previous) {
                $flush($run);
                $run = [];
            }

            if (in_array($beat['bs'], self::CONSTRUCTION, true)) {
                $run[] = $beat['id'];
            }

            $previous = $beat['bs'];
        }

        $flush($run);
        $byState = [];

        foreach ($beats as $beat) {
            $byState[$beat['bs']][] = $beat;
        }

        foreach ($byState as $state => $group) {
            for ($i = 0; $i < count($group); $i++) {
                for ($j = $i + 1; $j < count($group); $j++) {
                    $differs = count(array_filter(self::VARIETY, static fn (string $field): bool => self::normal((string) $group[$i][$field]) !== self::normal((string) $group[$j][$field])));

                    if ($differs < 2) {
                        $warnings[] = "Beat {$group[$i]['id']} và {$group[$j]['id']} cùng {$state} nhưng chỉ khác {$differs}/4 ở địa điểm · thời điểm · camera · hành động; dễ thành hình lặp.";
                    }
                }
            }
        }

        for ($i = 1; $i < count(self::ACTS); $i++) {
            $seen = static fn (string $act, string $field): array => array_map(static fn (array $beat): string => self::normal((string) $beat[$field]), array_values(array_filter($beats, static fn (array $beat): bool => $beat['act'] === $act)));
            $now = $seen(self::ACTS[$i], 'location');

            if ($now !== [] && array_diff($now, $seen(self::ACTS[$i - 1], 'location')) === [] && array_diff($seen(self::ACTS[$i], 'time_light'), $seen(self::ACTS[$i - 1], 'time_light')) === []) {
                $warnings[] = 'Act '.self::ACTS[$i].' không có địa điểm hay thời điểm nào chưa thấy ở '.self::ACTS[$i - 1].'.';
            }
        }

        return $warnings;
    }

    private static function s(float $value): string
    {
        return self::seconds($value);
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

    /** @return list<string> */
    public static function forbiddenWords(string $prompt): array
    {
        if (preg_match('/\*\*Forbidden in cards and titles:?\*\*:?\s*([^\n]+)/u', $prompt, $match) !== 1) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (string $word): string => trim($word, " .\t"), explode(',', $match[1]))));
    }

    private static function words(string $text): int
    {
        return (int) preg_match_all('/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u', $text);
    }

    /**
     * @param  list<array{code: string, path: string, message: string}>  $schema
     * @param  array<string, mixed>  $response
     * @return list<string>
     */
    private static function schemaLines(array $schema, array $response): array
    {
        $lines = [];
        $beats = is_array($response['data']['script']['beats'] ?? null) ? $response['data']['script']['beats'] : [];

        foreach ($schema as $violation) {
            $path = $violation['path'];
            $message = $violation['message'];
            $where = $path;

            if (preg_match('#^/data/script/beats/(\d+)#', $path, $match) === 1) {
                $id = $beats[(int) $match[1]]['id'] ?? null;
                $where = (is_string($id) ? "beat {$id}" : 'beat #'.$match[1]).' ('.$path.')';
            }

            $text = match (true) {
                str_ends_with($path, '/act') => 'act không đúng vị trí beat (Step 1.10)',
                str_ends_with($path, '/link') => 'chỉ B0 được để link null; mọi beat khác phải có link',
                preg_match('#^/data/script/tour/stops/\d+/sec$#', $path) === 1 => 'mỗi hình trong tour dài tối đa 1,5 s',
                str_ends_with($path, '/reveal') => 'B16 phải là H5 và không beat nào khác là H5',
                str_ends_with($path, '/set_state') => 'set_state không hợp lệ; phòng finished chỉ ở A4',
                preg_match('#^/data/script/text_cards/\d+/text$#', $path) === 1 && str_contains($message, 'pattern') => 'card SPEC phải kết thúc bằng “· CONCEPT”',
                $path === '/flags' => 'status partial hoặc fail phải nêu lý do trong flags',
                $path === '/data' && str_contains($message, 'properties') && ! str_contains($message, 'required') => 'status fail thì data phải là {}',
                preg_match('/Additional object properties are not allowed: (.+)$/', $message, $extra) === 1 => 'có trường ngoài hợp đồng: '.$extra[1],
                preg_match('/The required properties \((.+)\) are missing/', $message, $missing) === 1 => 'thiếu trường: '.$missing[1],
                default => $message,
            };

            $lines[] = "{$where}: {$text}";
        }

        return array_values(array_unique($lines));
    }

    private static function position(string $beat): int
    {
        return (int) substr($beat, 1);
    }

    private static function normal(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    private static function seconds(float $value): string
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
}
