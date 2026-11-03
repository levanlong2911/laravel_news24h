<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoLocationsCheck
{
    public const VERSION = 'moto-locations-check-v1';

    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNSCORED = 'UNSCORED';

    public const JUDGE = 'not_run';

    /** @var list<string> */
    private const MAKE_WORDS = ['Harley', 'Davidson'];

    /** @var list<string> the words that name the motorcycle in a text the plate prompt carries */
    private const SUBJECT_WORDS = ['motorcycle', 'motorbike', 'bike'];

    /** @var list<string> the words that name a person in a text the plate prompt carries */
    private const PERSON_WORDS = ['builder', 'rider', 'person', 'people', 'mechanic'];

    /** @var list<string> the temporary equipment of a build state, as a place's fixed equipment would name it */
    private const SUPPORT_WORDS = ['lift table', 'frame jig', 'engine stand', 'wheel dolly', 'paddock stand'];

    /** @var list<string> the kinds of place with walls, whose floor must be measured to hold a subject */
    private const ENCLOSED_KINDS = ['studio', 'workshop', 'paint_booth', 'assembly_bay'];

    /**
     * @param  ?array<string, mixed>  $response
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @param  list<array{code: string, path: string, message: string}>  $schema
     * @return array{version: string, format: string, verdict: string, status: ?string, error: ?string, schema: list<string>, issues: list<string>, warnings: list<string>, judge: string}
     */
    public static function evaluate(?array $response, array $context, array $schema): array
    {
        if ($response === null) {
            return self::result(self::UNSCORED, null, 'Phản hồi không đọc được JSON.');
        }

        if (($response['format'] ?? null) !== MotoContract::LOCATIONS_FORMAT) {
            return self::result(self::UNSCORED, null, 'Phản hồi không theo định dạng '.MotoContract::LOCATIONS_FORMAT.'.');
        }

        $status = is_string($response['status'] ?? null) ? $response['status'] : null;
        $schemaLines = array_map(static fn (array $v): string => trim($v['path'].' '.$v['message']), $schema);

        if ($schemaLines !== []) {
            return self::result(self::FAIL, $status, null, $schemaLines);
        }

        if ($status === 'fail') {
            return self::result(self::FAIL, $status, 'Model trả status fail: '.implode('; ', (array) ($response['flags'] ?? [])));
        }

        $issues = [];

        if (($response['project_id'] ?? null) !== ($context['project_id'] ?? null)) {
            $issues[] = 'project_id không khớp lượt chạy.';
        }

        if (($response['prompt_version'] ?? null) !== ($context['prompt_version'] ?? null)) {
            $issues[] = 'prompt_version là "'.($response['prompt_version'] ?? '').'"; lượt chạy này dùng '.($context['prompt_version'] ?? '').'.';
        }

        $data = (array) $response['data'];
        $beats = array_column((array) ($context['beats'] ?? []), null, 'id');
        $places = [];

        foreach ((array) $data['locations'] as $place) {
            if (isset($places[$place['id']])) {
                $issues[] = "{$place['id']} khai hai lần.";
            }

            $places[$place['id']] = $place;
        }

        [$backgroundIssues, $served] = self::backgroundIssues((array) $data['backgrounds'], $places, $beats, (array) ($context['build_states'] ?? []));
        $used = [...array_column((array) $data['backgrounds'], 'location'), ...array_filter(array_column((array) $data['unplated'], 'location'), 'is_string')];
        $held = array_merge([], ...array_map(static fn (array $conflict): array => $conflict['blocking'] ? (array) $conflict['beats'] : [], (array) $data['conflicts']));
        $covered = [...array_merge([], ...array_values($served)), ...array_column((array) $data['unplated'], 'beat'), ...$held];

        foreach (array_keys($places) as $id) {
            if (! in_array($id, $used, true)) {
                $issues[] = "{$id} không có ảnh nền hay beat nào dùng.";
            }
        }

        foreach (array_keys($beats) as $id) {
            if (! in_array($id, $covered, true)) {
                $issues[] = "{$id} không đứng trên ảnh nền nào, không có trong unplated và không có xung đột chặn nào nêu nó.";
            }
        }

        foreach ((array) $data['unplated'] as $entry) {
            if (! isset($beats[$entry['beat']])) {
                $issues[] = "unplated nhắc {$entry['beat']}, không có trong kịch bản.";
            }

            if (is_string($entry['location']) && ! isset($places[$entry['location']])) {
                $issues[] = "unplated {$entry['beat']} nhắc {$entry['location']}, không có trong locations.";
            }
        }

        foreach ((array) $data['continuity'] as $entry) {
            $reach = [];

            foreach ($entry['backgrounds'] as $id) {
                if (! isset($served[$id])) {
                    $issues[] = "continuity nhắc {$id}, không có trong backgrounds.";
                }

                $reach = [...$reach, ...($served[$id] ?? [])];
            }

            foreach ($entry['beats'] as $id) {
                if (! in_array($id, $reach, true)) {
                    $issues[] = "continuity: {$id} không phải beat của ".implode(', ', $entry['backgrounds']).'.';
                }
            }
        }

        [$conflictIssues, $conflictWarnings, $blocking] = self::conflictIssues((array) $data['conflicts'], $beats, $served, (array) ($context['build_states'] ?? []));

        if ($blocking && $status !== 'partial') {
            $issues[] = 'Còn xung đột chặn nhưng status không phải partial.';
        }

        [$sizeIssues, $sizeWarnings] = self::sizeIssues((array) $data['backgrounds'], $places, $beats, self::ENCLOSED_KINDS);
        $issues = [
            ...$issues,
            ...$backgroundIssues,
            ...$conflictIssues,
            ...$sizeIssues,
            ...self::nameIssues($data, $context),
        ];
        $warnings = [...$conflictWarnings, ...$sizeWarnings, ...self::renderWarnings($places, (array) $data['backgrounds'], $context)];

        return self::result($issues === [] ? self::PASS : self::FAIL, $status, null, [], $issues, $warnings);
    }

    /**
     * @param  list<array<string, mixed>>  $backgrounds
     * @param  array<string, array<string, mixed>>  $places
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, array<string, mixed>>  $states
     * @return array{0: list<string>, 1: array<string, list<string>>} the issues and the beats each background serves
     */
    private static function backgroundIssues(array $backgrounds, array $places, array $beats, array $states): array
    {
        $issues = [];
        $served = [];

        foreach ($backgrounds as $background) {
            $id = $background['id'];

            if (isset($served[$id])) {
                $issues[] = "{$id} khai hai lần.";
            }

            if (! isset($places[$background['location']])) {
                $issues[] = "{$id} đứng trên {$background['location']}, không có trong locations.";
            }

            $mine = [];
            $seen = [];

            foreach ($background['uses'] as $use) {
                if (! isset($beats[$use['beat']])) {
                    $issues[] = "{$id} dùng cho {$use['beat']}, không có trong kịch bản.";

                    continue;
                }

                $key = $use['beat'].'|'.$use['part'];

                if (isset($seen[$key])) {
                    $issues[] = "{$id} khai hai lần cùng phần \"{$use['part']}\" của {$use['beat']}.";
                }

                $seen[$key] = true;
                $mine[] = $use['beat'];
            }

            $mine = array_values(array_unique($mine));
            $served[$id] = [...($served[$id] ?? []), ...$mine];
            if ($background['light']['evidence'] !== null) {
                $issues = [...$issues, ...self::quoteIssues("{$id} light", $background['light']['evidence'], $mine, $beats)];
            }

            foreach ($background['details'] as $detail) {
                if ($detail['evidence'] !== null) {
                    $issues = [...$issues, ...self::quoteIssues("{$id} detail \"{$detail['text']}\"", $detail['evidence'], $mine, $beats)];
                }
            }

            foreach ($background['shot_changes'] as $change) {
                foreach (array_diff($change['beats'], $mine) as $beat) {
                    $issues[] = "{$id}: shot_changes nhắc {$beat}, không phải beat của ảnh nền này.";
                }
            }

            foreach ($mine as $beat) {
                $state = $beats[$beat]['state'] ?? null;
                $temporary = is_string($state) ? (array) ($states[$state]['temporary'] ?? []) : [];

                foreach (array_diff($background['support'], $temporary) as $item) {
                    $issues[] = "{$id} có {$item}, nhưng {$beat} ở ".($state ?? 'không giai đoạn')." không có thiết bị tạm này.";
                }
            }

            $surfaces = array_values(array_unique(array_filter(array_map(
                static fn (string $beat): ?bool => is_array($beats[$beat]['situation'] ?? null) ? ($beats[$beat]['situation']['dry_road'] ?? null) : null,
                $mine,
            ), 'is_bool')));

            if (count($surfaces) > 1) {
                $issues[] = "{$id} phục vụ cả beat đường khô lẫn đường ướt — tách ảnh nền.";
            } elseif ($surfaces === [] && $background['ground'] !== null) {
                $issues[] = "{$id} ghi ground {$background['ground']} nhưng không beat nào xác lập mặt đường.";
            } elseif ($surfaces !== [] && $background['ground'] !== ($surfaces[0] ? 'dry' : 'wet')) {
                $issues[] = "{$id} phải có ground ".($surfaces[0] ? 'dry' : 'wet').' theo mặt đường của beat.';
            }
        }

        return [$issues, $served];
    }

    /**
     * @param  array{beat: string, field: string, quote: string}  $evidence
     * @param  list<string>  $mine  the beats the background serves
     * @param  array<string, array<string, mixed>>  $beats
     * @return list<string>
     */
    private static function quoteIssues(string $label, array $evidence, array $mine, array $beats): array
    {
        if (! in_array($evidence['beat'], $mine, true)) {
            return ["{$label}: trích {$evidence['beat']}, không phải beat của ảnh nền này."];
        }

        $text = $beats[$evidence['beat']][$evidence['field']] ?? null;

        return is_string($text) && str_contains($text, $evidence['quote'])
            ? []
            : ["{$label}: \"{$evidence['quote']}\" không có nguyên văn trong {$evidence['beat']}.{$evidence['field']}."];
    }

    /**
     * @param  list<array<string, mixed>>  $conflicts
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, list<string>>  $served
     * @param  array<string, array<string, mixed>>  $states
     * @return array{0: list<string>, 1: list<string>, 2: bool} the issues, the warnings that show a conflict which blocks nothing, and whether a blocking conflict is open
     */
    private static function conflictIssues(array $conflicts, array $beats, array $served, array $states): array
    {
        $issues = [];
        $warnings = [];
        $blocking = false;

        foreach ($conflicts as $index => $conflict) {
            $label = 'Xung đột '.($index + 1);

            if ($conflict['beats'] === [] && $conflict['backgrounds'] === []) {
                $issues[] = "{$label} không nêu beat hay ảnh nền nào bị ảnh hưởng.";
            }

            foreach ($conflict['beats'] as $beat) {
                if (! isset($beats[$beat])) {
                    $issues[] = "{$label} nhắc {$beat}, không có trong kịch bản.";
                }
            }

            foreach ($conflict['backgrounds'] as $background) {
                if (! isset($served[$background])) {
                    $issues[] = "{$label} nhắc {$background}, không có trong backgrounds.";
                }
            }

            foreach ($conflict['evidence'] as $evidence) {
                $problem = self::evidenceProblem($evidence, $beats, $states);

                if ($problem !== null) {
                    $issues[] = "{$label}: {$problem}";
                }
            }

            if ($conflict['blocking']) {
                $blocking = true;
            } else {
                $warnings[] = "{$label} ({$conflict['kind']}, không chặn): {$conflict['note']}";
            }
        }

        return [$issues, $warnings, $blocking];
    }

    /**
     * @param  array{path: string, quote?: string, value?: mixed}  $evidence
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, array<string, mixed>>  $states
     * @return ?string why the evidence is not found in the pinned input: a text is quoted verbatim, a number, boolean, null or list item is given with its exact value and type
     */
    private static function evidenceProblem(array $evidence, array $beats, array $states): ?string
    {
        [$found, $value] = self::resolve($evidence['path'], $beats, $states);

        if (! $found) {
            return "{$evidence['path']} không có trong dữ liệu đã ghim.";
        }

        $list = is_array($value) && array_is_list($value) ? $value : null;
        $text = is_string($value) || ($list !== null && $list !== [] && array_filter($list, 'is_string') === $list);

        if ($text !== array_key_exists('quote', $evidence)) {
            return $text ? "{$evidence['path']} là văn bản — trích nguyên văn bằng quote." : "{$evidence['path']} không phải văn bản — ghi value đúng giá trị và kiểu.";
        }

        if ($text) {
            return array_filter((array) $value, static fn (string $item): bool => str_contains($item, $evidence['quote'])) === []
                ? "\"{$evidence['quote']}\" không có nguyên văn trong {$evidence['path']}."
                : null;
        }

        $matches = self::same($value, $evidence['value']) || ($list !== null && array_filter($list, static fn (mixed $item): bool => self::same($item, $evidence['value'])) !== []);

        return $matches ? null : "{$evidence['path']} không khớp giá trị ".json_encode($evidence['value'], JSON_UNESCAPED_UNICODE).'.';
    }

    /**
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, array<string, mixed>>  $states
     * @return array{0: bool, 1: mixed} whether the path names a value of the pinned input, and that value; only the paths the schema allows are read
     */
    private static function resolve(string $path, array $beats, array $states): array
    {
        $parts = explode('.', $path);

        if ($parts[0] === 'build_states') {
            $state = $states[$parts[1]] ?? null;

            return is_array($state) && array_key_exists($parts[2], $state) ? [true, $state[$parts[2]]] : [false, null];
        }

        $beat = $beats[$parts[1]] ?? null;
        $field = $parts[2] ?? null;

        if (! is_array($beat) || $field === null) {
            return [false, null];
        }

        $pick = static function (array $list, string $key, string $id): ?array {
            foreach ($list as $item) {
                if (($item[$key] ?? null) === $id) {
                    return $item;
                }
            }

            return null;
        };
        $entry = match ($field) {
            'situation' => is_array($beat['situation']) && array_key_exists($parts[3], $beat['situation']) ? ['value' => $beat['situation'][$parts[3]]] : null,
            'subjects' => ($subject = $pick((array) $beat['subjects'], 'ref', $parts[3])) !== null && array_key_exists($parts[4], $subject['size']) ? ['value' => $subject['size'][$parts[4]]] : null,
            'operations' => ($operation = $pick((array) $beat['operations'], 'source', "{$parts[3]}.use_sequence")) !== null ? ['value' => $operation['steps']] : null,
            'conditions' => ($condition = $pick((array) $beat['conditions'], 'source', "{$parts[3]}.time_light")) !== null ? ['value' => $condition['text']] : null,
            'limits' => ($limit = $pick((array) $beat['limits'], 'source', $parts[3])) !== null && array_key_exists($parts[4], $limit) ? ['value' => $limit[$parts[4]]] : null,
            default => count($parts) === 3 && array_key_exists($field, $beat) ? ['value' => $beat[$field]] : null,
        };

        return $entry === null ? [false, null] : [true, $entry['value']];
    }

    /** @return bool whether two values are equal in value and type all the way down: a number only matches the same number (5 and 5.0 alike, no tolerance), an object matches key by key, a list item by item in order */
    public static function same(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual) || is_array($expected)) {
            if (! is_array($actual) || ! is_array($expected) || count($actual) !== count($expected) || array_is_list($actual) !== array_is_list($expected)) {
                return false;
            }

            foreach ($actual as $key => $item) {
                if (! array_key_exists($key, $expected) || ! self::same($item, $expected[$key])) {
                    return false;
                }
            }

            return true;
        }

        if ((is_int($actual) || is_float($actual)) && (is_int($expected) || is_float($expected))) {
            return (float) $actual === (float) $expected;
        }

        return $actual === $expected;
    }

    /**
     * @param  list<array<string, mixed>>  $backgrounds
     * @param  array<string, array<string, mixed>>  $places
     * @param  array<string, array<string, mixed>>  $beats
     * @param  list<string>  $enclosed  the kinds of place with walls
     * @return array{0: list<string>, 1: list<string>} the subjects of a use that cannot fit the place they stand in at any angle (their short side wider than the floor, their long side longer than its diagonal or their area larger), then the warnings: a subject that fits only across the floor, not parallel to its sides or turned 90°, and an enclosed place whose floor is not measured well enough to tell
     */
    private static function sizeIssues(array $backgrounds, array $places, array $beats, array $enclosed): array
    {
        $issues = [];
        $warnings = [];

        foreach ($backgrounds as $background) {
            $place = $places[$background['location']] ?? null;

            if ($place === null) {
                continue;
            }

            $length = $place['dimensions']['length_m']['value'];
            $width = $place['dimensions']['width_m']['value'];
            $height = $place['dimensions']['height_m']['value'];
            $known = array_values(array_filter([$length, $width], 'is_numeric'));

            foreach (array_unique(array_column($background['uses'], 'beat')) as $beat) {
                foreach ((array) ($beats[$beat]['subjects'] ?? []) as $subject) {
                    $size = $subject['size'];

                    if (! is_numeric($size['length_m'] ?? null) || ! is_numeric($size['width_m'] ?? null)) {
                        continue;
                    }

                    $long = max((float) $size['length_m'], (float) $size['width_m']);
                    $short = min((float) $size['length_m'], (float) $size['width_m']);
                    $subjectLabel = "{$subject['ref']} của {$beat} ({$size['length_m']} × {$size['width_m']} m)";

                    if (count($known) === 2) {
                        $big = (float) max($known);
                        $small = (float) min($known);

                        if ($short > $small || $long > sqrt($big ** 2 + $small ** 2) || $long * $short > $big * $small) {
                            $issues[] = "{$background['location']} không chứa vừa {$subjectLabel}: không vừa ở bất kỳ góc đặt nào trên mặt bằng {$length} × {$width} m.";
                        } elseif ($long > $big) {
                            $warnings[] = "{$background['location']}: {$subjectLabel} không vừa khi đặt song song hay xoay 90° trên mặt bằng {$length} × {$width} m, chỉ có thể vừa khi đặt chéo — chờ xác định bố trí.";
                        }
                    } elseif (count($known) === 1 && $short > (float) $known[0]) {
                        $issues[] = "{$background['location']} không chứa vừa {$subjectLabel}: cạnh đã biết {$known[0]} m nhỏ hơn cạnh ngắn của chủ thể, ở bất kỳ góc đặt nào.";
                    } elseif (count($known) < 2 && in_array($place['kind'], $enclosed, true)) {
                        $warnings[] = "{$background['location']} chưa đủ số đo mặt bằng để kiểm chỗ cho {$subject['ref']} của {$beat}.";
                    }

                    if (is_numeric($height) && is_numeric($size['height_m'] ?? null) && (float) $size['height_m'] > (float) $height) {
                        $issues[] = "{$background['location']} thấp hơn {$subject['ref']} của {$beat} ({$size['height_m']} m).";
                    }
                }
            }
        }

        return [$issues, $warnings];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    private static function nameIssues(array $data, array $context): array
    {
        $issues = [];
        $names = [...MotoContract::blockedForms((array) ($context['source_names'] ?? []), (array) ($context['base_names'] ?? [])), ...self::MAKE_WORDS];

        foreach (self::texts($data, '') as $path => $text) {
            foreach ($names as $name) {
                if (MotoContract::mentions($text, $name)) {
                    $issues[] = "{$path}: dùng tên nguồn hoặc tên hãng \"{$name}\".";
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, array<string, mixed>>  $places
     * @param  list<array<string, mixed>>  $backgrounds
     * @param  array<string, mixed>  $context
     * @return list<string> the warnings a reviewer reads before rendering: a subject named in a text the plate prompt carries, temporary equipment kept as a place's fixed equipment, two places alike in every fixed field
     */
    private static function renderWarnings(array $places, array $backgrounds, array $context): array
    {
        $warnings = [];
        $words = [...self::SUBJECT_WORDS, ...self::PERSON_WORDS, ...array_filter([$context['motorcycle_name'] ?? null], 'is_string')];
        $texts = [];
        $seen = [];

        foreach ($places as $id => $place) {
            $texts["{$id}.space"] = [$place['space']];
            $texts["{$id}.surfaces"] = $place['surfaces'];
            $texts["{$id}.fixed_equipment"] = $place['fixed_equipment'];

            foreach ($place['fixed_equipment'] as $item) {
                foreach (self::SUPPORT_WORDS as $support) {
                    if (MotoContract::mentions($item, $support)) {
                        $warnings[] = "{$id}.fixed_equipment có \"{$support}\" — thiết bị tạm theo giai đoạn thuộc support của ảnh nền, không thuộc thiết bị cố định.";
                    }
                }
            }

            $key = json_encode([$place['kind'], $place['space'], $place['surfaces'], $place['fixed_equipment']]);

            if (isset($seen[$key])) {
                $warnings[] = "{$id} giống hệt {$seen[$key]} ở mọi trường cố định — có thể là một nơi.";
            }

            $seen[$key] = $id;
        }

        foreach ($backgrounds as $background) {
            $texts["{$background['id']}.composition"] = [$background['composition']];
            $texts["{$background['id']}.light"] = [$background['light']['description']];
            $texts["{$background['id']}.details"] = array_column($background['details'], 'text');
            $texts["{$background['id']}.must_show"] = $background['must_show'];
        }

        foreach ($texts as $path => $list) {
            foreach ($list as $text) {
                foreach ($words as $word) {
                    if (MotoContract::mentions((string) $text, $word)) {
                        $warnings[] = "{$path} nhắc \"{$word}\" — người duyệt xem lại: ảnh nền chỉ chứa môi trường (thiết bị trống, chỗ trống cho chủ thể hay câu phủ định vẫn có thể hợp lệ).";
                    }
                }
            }
        }

        return $warnings;
    }

    /** @return array<string, string> every text of the answer by its path */
    private static function texts(mixed $node, string $path): array
    {
        if (is_string($node)) {
            return [$path => $node];
        }

        $texts = [];

        foreach (is_array($node) ? $node : [] as $key => $value) {
            $texts = [...$texts, ...self::texts($value, $path === '' ? (string) $key : "{$path}.{$key}")];
        }

        return $texts;
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
            'format' => MotoContract::LOCATIONS_FORMAT,
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
