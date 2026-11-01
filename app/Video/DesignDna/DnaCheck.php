<?php

declare(strict_types=1);

namespace App\Video\DesignDna;

final class DnaCheck
{
    public const FORMAT = 'phase2-dna-v1';

    public const FORMAT_V2 = 'phase2-dna-v2';

    public const INPUT_FORMAT = 'phase2-input-v1';

    public const VERSION = 'dna-check-v1';

    public const VERSION_V2 = 'dna-check-v3';

    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNSCORED = 'UNSCORED';

    public const THIN_SOURCE = 'THIN_SOURCE';

    /** @var list<string> the self-checks of a v2 answer, in their fixed order */
    public const CHECKS = ['source_floor', 'theme', 'departures', 'novelty', 'plausibility', 'coherence', 'silhouette', 'must_avoid', 'hero', 'contract_and_names'];

    /** @var list<string> */
    private const METHODS = ['CROSS-DOMAIN', 'INVERT', 'MERGE', 'RELOCATE', 'SCALE SHIFT', 'SUBTRACT'];

    /** @var list<string> */
    private const EMOTIONS = ['awe', 'serenity', 'power', 'mystery', 'freedom', 'precision'];

    /** @var list<string> */
    private const VISIBLE = ['exterior', 'interior', 'both'];

    /** @var list<string> */
    private const SOURCE_LISTS = ['exterior', 'interior', 'technology', 'visuals'];

    /** @var list<string> the text fields of an innovation a source name may never enter */
    private const INNOVATION_TEXT = ['name', 'what', 'why_new', 'plausibility'];

    /** @var array<string, list<string>> */
    private const ITEM_KEYS = [
        'departures' => ['id', 'from_id', 'method', 'result'],
        'innovations' => ['id', 'name', 'what', 'why_new', 'plausibility', 'visible'],
    ];

    /**
     * @param  array<mixed>|null  $response
     * @param  array<mixed>  $inspiration
     * @param  list<string>  $flags
     * @param  ?list<string>  $schemaErrors  for a phase2-dna-v2 answer: its violations of the schema the run pinned
     * @param  ?string  $contract  the format the run pinned; the answer's own format never chooses the check
     * @return array{version: string, format: string, verdict: string, issues: list<string>, error: ?string}
     */
    public static function evaluate(?array $response, array $inspiration, array $flags, ?array $schemaErrors = null, ?string $contract = null): array
    {
        if ($response === null) {
            return self::unscored('Phản hồi không đọc được JSON.', $contract);
        }

        $format = $response['format'] ?? null;
        $contract ??= $format === self::FORMAT_V2 ? self::FORMAT_V2 : self::FORMAT;

        if ($contract === self::FORMAT_V2) {
            return $format === self::FORMAT_V2
                ? self::evaluateV2($response, $inspiration, $flags, $schemaErrors ?? ['không có JSON Schema đã ghim để kiểm'])
                : self::unscored('Lượt này ghim hợp đồng '.self::FORMAT_V2.' nhưng phản hồi khai format '.json_encode($format).' — sai hợp đồng, không dùng tiếp.', self::FORMAT_V2);
        }

        if ($format !== self::FORMAT) {
            return self::unscored('Phản hồi không theo định dạng '.self::FORMAT.'.');
        }

        $structure = self::structureErrors($response);

        if ($structure !== []) {
            return self::unscored('Phản hồi thiếu hoặc sai cấu trúc: '.implode('; ', $structure).'.');
        }

        $dna = $response['dna'];
        $issues = [];
        $thin = in_array(self::THIN_SOURCE, $flags, true);
        $departures = $dna['departures'];
        $sourceIds = self::sourceIds($inspiration);

        if ($thin ? count($departures) !== 5 : (count($departures) < 3 || count($departures) > 5)) {
            $issues[] = 'Cần '.($thin ? 'đúng 5 departure (THIN_SOURCE)' : '3–5 departure').', đang có '.count($departures).'.';
        }

        foreach ($departures as $index => $departure) {
            $name = self::label($departure['id'], 'departure', $index);

            if (! in_array($departure['from_id'], $sourceIds, true)) {
                $issues[] = "{$name}: from_id \"{$departure['from_id']}\" không phải ID nào trong kết quả Phase 1.";
            }

            $methods = array_map('trim', explode('+', $departure['method']));

            if (array_diff($methods, self::METHODS) !== [] || in_array('', $methods, true)) {
                $issues[] = "{$name}: phương pháp \"{$departure['method']}\" không thuộc ".implode(', ', self::METHODS).'.';
            }

            if (trim($departure['result']) === '') {
                $issues[] = "{$name}: result trống.";
            }
        }

        if (count($dna['innovations']) !== 3) {
            $issues[] = 'Cần đúng 3 innovation, đang có '.count($dna['innovations']).'.';
        }

        foreach ($dna['innovations'] as $index => $innovation) {
            $name = self::label($innovation['id'], 'innovation', $index);

            foreach (self::INNOVATION_TEXT as $field) {
                if (trim($innovation[$field]) === '') {
                    $issues[] = "{$name}: {$field} trống.";
                }
            }

            if (! in_array($innovation['visible'], self::VISIBLE, true)) {
                $issues[] = "{$name}: visible \"{$innovation['visible']}\" không thuộc ".implode(', ', self::VISIBLE).'.';
            }
        }

        $issues = [...$issues, ...self::duplicateIds($dna), ...self::shortAvoidList($dna['must_avoid'], false)];

        foreach (['theme', 'silhouette', 'hero_moment'] as $field) {
            if (trim($dna[$field]) === '') {
                $issues[] = "{$field} trống.";
            }
        }

        if (! in_array($dna['emotion'], self::EMOTIONS, true)) {
            $issues[] = "emotion \"{$dna['emotion']}\" không thuộc ".implode(', ', self::EMOTIONS).'.';
        }

        $innovationNames = implode("\n", array_map(static fn (array $innovation): string => $innovation['name'], $dna['innovations']));

        foreach (self::blockedNames($inspiration) as $blocked) {
            if (preg_match('/\b'.preg_quote($blocked, '/').'\b/iu', $innovationNames) === 1) {
                $issues[] = "Tên innovation của thiết kế mới dùng tên tàu nguồn: \"{$blocked}\".";
            }
        }

        return [
            'version' => self::VERSION,
            'format' => self::FORMAT,
            'verdict' => $issues === [] ? self::PASS : self::FAIL,
            'issues' => $issues,
            'error' => null,
        ];
    }

    /**
     * @param  array<mixed>  $identity
     * @return list<string>
     */
    public static function sourceNames(array $identity): array
    {
        $names = [];

        foreach (['name', 'builder', 'designers'] as $field) {
            foreach ((array) ($identity[$field] ?? []) as $value) {
                if (! is_string($value)) {
                    continue;
                }

                foreach (preg_split('/[(),;\/]+/u', $value) ?: [] as $part) {
                    $part = trim((string) preg_replace('/^(?:originally|formerly|ex-)\s*/iu', '', trim($part)));

                    if (mb_strlen($part) >= 3) {
                        $names[] = $part;
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<mixed>  $answer  a v2 answer that matches the pinned schema
     * @param  array<mixed>  $inspiration
     * @param  list<string>  $flags
     * @param  list<string>  $schemaErrors
     * @return array{version: string, format: string, verdict: string, issues: list<string>, schema_errors: list<string>, error: ?string}
     */
    private static function evaluateV2(array $answer, array $inspiration, array $flags, array $schemaErrors): array
    {
        if ($schemaErrors !== []) {
            return ['schema_errors' => $schemaErrors]
                + self::unscored('Phản hồi sai JSON Schema đã ghim: '.implode('; ', array_slice($schemaErrors, 0, 5)).'.', self::FORMAT_V2);
        }

        $dna = $answer['dna'];
        $issues = [];
        $sourceIds = self::departureIds($inspiration);

        if (in_array(self::THIN_SOURCE, $flags, true) && count($dna['departures']) !== 5) {
            $issues[] = 'Nguồn mỏng (THIN_SOURCE) cần đúng 5 departure, đang có '.count($dna['departures']).'.';
        }

        foreach ($dna['departures'] as $departure) {
            if (! in_array($departure['from_id'], $sourceIds, true)) {
                $issues[] = "{$departure['id']}: from_id \"{$departure['from_id']}\" không phải ID exterior, interior, technology, visual hay signature nào của nguồn.";
            }
        }

        $issues = [...$issues, ...self::duplicateIds($dna), ...self::shortAvoidList($dna['must_avoid'], true)];

        foreach (array_values(array_unique([...self::blockedNames($inspiration), ...self::otherNames($inspiration)])) as $blocked) {
            foreach (self::creativeText($dna) as $path => $text) {
                if (preg_match('/\b'.preg_quote($blocked, '/').'\b/iu', $text) === 1) {
                    $issues[] = "{$path}: dùng tên nguồn \"{$blocked}\" trong nội dung sáng tạo.";
                }
            }
        }

        return [
            'version' => self::VERSION_V2,
            'format' => self::FORMAT_V2,
            'verdict' => $issues === [] ? self::PASS : self::FAIL,
            'issues' => $issues,
            'schema_errors' => [],
            'error' => null,
        ];
    }

    /**
     * @param  array<mixed>  $dna
     * @return array<string, string> where each text sits => the text
     */
    private static function creativeText(array $dna): array
    {
        $texts = ['theme' => $dna['theme'], 'silhouette' => $dna['silhouette'], 'hero_moment' => $dna['hero_moment']];

        foreach ($dna['departures'] as $departure) {
            $texts["{$departure['id']}.result"] = $departure['result'];
        }

        foreach ($dna['innovations'] as $innovation) {
            foreach (self::INNOVATION_TEXT as $field) {
                $texts["{$innovation['id']}.{$field}"] = $innovation[$field];
            }
        }

        foreach ($dna['must_avoid'] as $index => $item) {
            $texts["must_avoid[{$index}]"] = $item;
        }

        return $texts;
    }

    /**
     * @param  array<mixed>  $dna
     * @return list<string>
     */
    private static function duplicateIds(array $dna): array
    {
        $issues = [];

        foreach (['departures', 'innovations'] as $list) {
            $ids = array_map(static fn (array $item): string => trim($item['id']), $dna[$list]);

            if (in_array('', $ids, true)) {
                $issues[] = "Có ID rỗng trong {$list}.";
            }

            $named = array_filter($ids, static fn (string $id): bool => $id !== '');

            if (count($named) !== count(array_unique($named))) {
                $issues[] = "ID trong {$list} bị trùng.";
            }
        }

        return $issues;
    }

    /**
     * @param  list<string>  $mustAvoid
     * @param  bool  $collapseSpaces  true for a v2 answer: any run of spaces, tabs or line breaks compares as one space; a v1 answer keeps the check it was passed with
     * @return list<string>
     */
    private static function shortAvoidList(array $mustAvoid, bool $collapseSpaces): array
    {
        $key = static fn (string $item): string => mb_strtolower(trim($collapseSpaces ? (string) preg_replace('/\s+/u', ' ', $item) : $item));
        $distinct = array_unique(array_filter(array_map($key, $mustAvoid)));

        return count($distinct) < 8 ? ['must_avoid cần ít nhất 8 mục khác nhau, đang có '.count($distinct).'.'] : [];
    }

    /**
     * @param  array<mixed>  $response
     * @return list<string>
     */
    private static function structureErrors(array $response): array
    {
        $errors = [];
        $dna = $response['dna'] ?? null;
        $novelty = $response['novelty'] ?? null;

        if (! is_array($dna) || array_is_list($dna)) {
            return ['dna không phải object'];
        }

        if (! is_array($novelty) || array_is_list($novelty)) {
            $errors[] = 'novelty không phải object';
        } else {
            foreach (['judge_score', 'closest_known'] as $key) {
                if (! array_key_exists($key, $novelty)) {
                    $errors[] = "thiếu novelty.{$key}";
                }
            }
        }

        foreach (['theme', 'silhouette', 'hero_moment', 'emotion'] as $key) {
            if (! is_string($dna[$key] ?? null)) {
                $errors[] = "dna.{$key} phải là chuỗi";
            }
        }

        if (! is_array($dna['must_avoid'] ?? null) || ! array_is_list($dna['must_avoid'])
            || array_filter($dna['must_avoid'], static fn (mixed $item): bool => ! is_string($item)) !== []) {
            $errors[] = 'dna.must_avoid phải là danh sách chuỗi';
        }

        foreach (self::ITEM_KEYS as $list => $keys) {
            if (! is_array($dna[$list] ?? null) || ! array_is_list($dna[$list])) {
                $errors[] = "dna.{$list} phải là danh sách";

                continue;
            }

            foreach ($dna[$list] as $index => $item) {
                if (! is_array($item) || array_is_list($item)) {
                    $errors[] = "dna.{$list}[{$index}] không phải object";

                    continue;
                }

                foreach ($keys as $key) {
                    if (! is_string($item[$key] ?? null)) {
                        $errors[] = "dna.{$list}[{$index}].{$key} phải là chuỗi";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<mixed>  $inspiration
     * @return list<string>
     */
    private static function sourceIds(array $inspiration): array
    {
        $ids = [];

        foreach (self::SOURCE_LISTS as $list) {
            foreach (is_array($inspiration[$list] ?? null) ? $inspiration[$list] : [] as $item) {
                if (is_array($item) && is_string($item['id'] ?? null) && trim($item['id']) !== '') {
                    $ids[] = trim($item['id']);
                }
            }
        }

        return $ids;
    }

    /**
     * @param  array<mixed>  $inspiration
     * @return list<string> every id a v2 departure may cite: exterior, interior, technology, visual and signature items
     */
    public static function departureIds(array $inspiration): array
    {
        return array_values(array_unique([...self::sourceIds($inspiration), ...self::signatureIds($inspiration)]));
    }

    /**
     * @param  array<mixed>  $inspiration
     * @return list<string> the names of the other yachts the article names, kept out of a v2 brief like the source's own names
     */
    private static function otherNames(array $inspiration): array
    {
        $others = is_array($inspiration['subject']['others'] ?? null) ? $inspiration['subject']['others'] : [];

        return self::sourceNames(['name' => array_values(array_filter(array_map(static fn (mixed $other): mixed => is_array($other) ? ($other['name'] ?? null) : null, $others), 'is_string'))]);
    }

    /**
     * @param  array<mixed>  $inspiration
     * @return list<string> the ids of the source signatures; a v1 source lists signatures without ids
     */
    private static function signatureIds(array $inspiration): array
    {
        $identity = is_array($inspiration['source_identity'] ?? null) ? $inspiration['source_identity'] : [];
        $ids = [];

        foreach (is_array($identity['signatures'] ?? null) ? $identity['signatures'] : [] as $item) {
            if (is_array($item) && is_string($item['id'] ?? null) && trim($item['id']) !== '') {
                $ids[] = trim($item['id']);
            }
        }

        return $ids;
    }

    /**
     * @param  array<mixed>  $inspiration
     * @return list<string>
     */
    private static function blockedNames(array $inspiration): array
    {
        return self::sourceNames(is_array($inspiration['source_identity'] ?? null) ? $inspiration['source_identity'] : []);
    }

    private static function label(string $id, string $kind, int $index): string
    {
        return trim($id) !== '' ? trim($id) : "{$kind} #".($index + 1);
    }

    /** @return array{version: string, format: string, verdict: string, issues: list<string>, error: ?string} */
    private static function unscored(string $error, ?string $contract = null): array
    {
        $v2 = $contract === self::FORMAT_V2;

        return [
            'version' => $v2 ? self::VERSION_V2 : self::VERSION,
            'format' => $v2 ? self::FORMAT_V2 : self::FORMAT,
            'verdict' => self::UNSCORED,
            'issues' => [],
            'error' => $error,
        ];
    }
}
