<?php

declare(strict_types=1);

namespace App\Video\DesignDna;

final class DnaCheck
{
    public const FORMAT = 'phase2-dna-v1';

    public const VERSION = 'dna-check-v1';

    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNSCORED = 'UNSCORED';

    public const NOT_CHECKED = 'NOT_CHECKED';

    public const THIN_SOURCE = 'THIN_SOURCE';

    /** @var list<string> */
    private const METHODS = ['CROSS-DOMAIN', 'INVERT', 'MERGE', 'RELOCATE', 'SCALE SHIFT', 'SUBTRACT'];

    /** @var list<string> */
    private const EMOTIONS = ['awe', 'serenity', 'power', 'mystery', 'freedom', 'precision'];

    /** @var list<string> */
    private const VISIBLE = ['exterior', 'interior', 'both'];

    /** @var list<string> */
    private const SOURCE_LISTS = ['exterior', 'interior', 'technology', 'visuals'];

    /** @var array<string, list<string>> */
    private const ITEM_KEYS = [
        'departures' => ['id', 'from_id', 'method', 'result'],
        'innovations' => ['id', 'name', 'what', 'why_new', 'plausibility', 'visible'],
    ];

    /**
     * @param  array<mixed>|null  $response
     * @param  array<mixed>  $inspiration
     * @param  list<string>  $flags
     * @return array{version: string, format: string, verdict: string, issues: list<string>, novelty: string, plausibility: string, phase3_ready: bool, error: ?string}
     */
    public static function evaluate(?array $response, array $inspiration, array $flags, string $novelty = self::NOT_CHECKED, string $plausibility = self::NOT_CHECKED): array
    {
        if ($response === null) {
            return self::unscored('Phản hồi không đọc được JSON.');
        }

        if (($response['format'] ?? null) !== self::FORMAT) {
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

            foreach (['name', 'what', 'why_new', 'plausibility'] as $field) {
                if (trim($innovation[$field]) === '') {
                    $issues[] = "{$name}: {$field} trống.";
                }
            }

            if (! in_array($innovation['visible'], self::VISIBLE, true)) {
                $issues[] = "{$name}: visible \"{$innovation['visible']}\" không thuộc ".implode(', ', self::VISIBLE).'.';
            }
        }

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

        $avoid = array_unique(array_filter(array_map(static fn (string $item): string => mb_strtolower(trim($item)), $dna['must_avoid'])));

        if (count($avoid) < 8) {
            $issues[] = 'must_avoid cần ít nhất 8 mục khác nhau, đang có '.count($avoid).'.';
        }

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

        $verdict = $issues === [] ? self::PASS : self::FAIL;

        return [
            'version' => self::VERSION,
            'format' => self::FORMAT,
            'verdict' => $verdict,
            'issues' => $issues,
            'novelty' => $novelty,
            'plausibility' => $plausibility,
            'phase3_ready' => $verdict === self::PASS && $novelty === self::PASS && $plausibility === self::PASS,
            'error' => null,
        ];
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
     * @return list<string>
     */
    private static function blockedNames(array $inspiration): array
    {
        return self::sourceNames(is_array($inspiration['source_identity'] ?? null) ? $inspiration['source_identity'] : []);
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

    private static function label(string $id, string $kind, int $index): string
    {
        return trim($id) !== '' ? trim($id) : "{$kind} #".($index + 1);
    }

    /** @return array{version: string, format: string, verdict: string, issues: list<string>, novelty: string, plausibility: string, phase3_ready: bool, error: ?string} */
    private static function unscored(string $error): array
    {
        return [
            'version' => self::VERSION,
            'format' => self::FORMAT,
            'verdict' => self::UNSCORED,
            'issues' => [],
            'novelty' => self::NOT_CHECKED,
            'plausibility' => self::NOT_CHECKED,
            'phase3_ready' => false,
            'error' => $error,
        ];
    }
}
