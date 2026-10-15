<?php

declare(strict_types=1);

namespace App\Video\Screenplay;

final class DesignText
{
    /** @var list<string> */
    private const SKIPPED_KEYS = ['id', 'refs', 'exterior_role', 'anchor_role', 'list', 'feature_id', 'priority', 'kind_id', 'status'];

    /** @param array<string, string> $names */
    public static function readable(string $text, array $names): string
    {
        return preg_replace_callback(
            '/\b[a-z]{1,2}_[a-z0-9_]+\b/',
            static fn (array $match): string => $names[$match[0]] ?? self::words(preg_replace('/^[a-z]{1,2}_/', '', $match[0]) ?? $match[0]),
            $text,
        ) ?? $text;
    }

    /**
     * @param  array<string, string>  $names
     * @param  array<string, true>  $seen
     * @return list<string>
     */
    public static function section(string $key, mixed $node, array $names, array &$seen): array
    {
        $title = strtoupper(str_replace('_', ' ', $key)).':';

        if (is_string($node)) {
            return self::fresh($title.' '.trim($node), $seen);
        }

        if (! is_array($node)) {
            return [];
        }

        $rows = [];

        if ($key === 'decks') {
            $decks = array_values(array_filter($node, 'is_array'));
            usort($decks, static fn (array $a, array $b): int => (int) ($a['level'] ?? 0) <=> (int) ($b['level'] ?? 0));
            $rows = self::fresh('Decks from lowest to highest: '.implode(', ', array_map(
                static fn (array $deck): string => trim((string) ($deck['name'] ?? $deck['id'] ?? '')).(is_string($deck['body'] ?? null) ? ' ('.$deck['body'].')' : ''),
                $decks,
            )).'.', $seen);
        } elseif (array_is_list($node)) {
            foreach ($node as $item) {
                $line = is_array($item) ? self::item($key, $item, $names) : (is_string($item) ? trim($item) : null);

                if ($line !== null && $line !== '') {
                    $rows = [...$rows, ...self::fresh('- '.$line, $seen)];
                }
            }
        } else {
            foreach ($node as $field => $value) {
                $text = self::value($value, $names);

                if ($text !== '') {
                    $rows = [...$rows, ...self::fresh('- '.self::words((string) $field).': '.$text, $seen)];
                }
            }
        }

        return $rows === [] ? [] : [$title, ...$rows];
    }

    /** @param array<string, string> $names */
    public static function value(mixed $value, array $names): string
    {
        if (is_string($value)) {
            return trim($names[$value] ?? $value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_array($value)) {
            return '';
        }

        $parts = [];

        foreach ($value as $field => $child) {
            if (is_string($field) && in_array($field, self::SKIPPED_KEYS, true)) {
                continue;
            }

            $text = self::value($child, $names);

            if ($text !== '') {
                $parts[] = is_string($field) ? self::words($field).': '.$text : $text;
            }
        }

        return implode('; ', $parts);
    }

    /** @param array<string, string> $names */
    public static function name(mixed $id, array $names): string
    {
        return is_string($id) ? ($names[$id] ?? self::words(preg_replace('/^[a-z]{1,2}_/', '', $id) ?? $id)) : '';
    }

    public static function words(string $text): string
    {
        return trim(str_replace('_', ' ', $text));
    }

    /**
     * @param  array<string, mixed>  $design
     * @return array<string, string>
     */
    public static function names(array $design): array
    {
        $names = [];
        $walk = static function (mixed $node) use (&$walk, &$names): void {
            if (! is_array($node)) {
                return;
            }

            if (is_string($node['id'] ?? null)) {
                foreach (['name', 'region', 'element', 'feature'] as $field) {
                    if (is_string($node[$field] ?? null) && trim($node[$field]) !== '') {
                        $names[$node['id']] = trim($node[$field]);

                        break;
                    }
                }
            }

            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($design['canonical_design'] ?? []);

        return $names;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, string>  $names
     */
    private static function item(string $key, array $item, array $names): ?string
    {
        if (($item['exterior_role'] ?? null) === 'interior_only') {
            return null;
        }

        $name = static fn (mixed $id): string => self::name($id, $names);

        return match ($key) {
            'openings' => sprintf('%s %s %s in %s%s.', $name($item['deck'] ?? null), self::words((string) ($item['face'] ?? '')),
                self::words((string) ($item['kind'] ?? '')), $name($item['host'] ?? null),
                array_key_exists('serves', $item) ? ', serving '.$name($item['serves']) : ''),
            'surfaces' => sprintf('%s on %s, %s %s, %s side.', ucfirst(self::words((string) ($item['kind'] ?? ''))), $name($item['deck'] ?? null),
                self::words((string) ($item['relation'] ?? '')), $name($item['relative_to'] ?? null), self::words((string) ($item['side'] ?? ''))),
            'routes' => sprintf('%s from %s (%s) to %s (%s): %s', ucfirst(self::words((string) ($item['kind'] ?? ''))),
                $name($item['from']['place'] ?? null), $name($item['from']['deck'] ?? null), $name($item['to']['place'] ?? null),
                $name($item['to']['deck'] ?? null), trim((string) ($item['support'] ?? ''))),
            'basins' => sprintf('%s on %s, %s, %s.', ucfirst($name($item['id'] ?? null)), $name($item['surface'] ?? null),
                self::words((string) ($item['orientation'] ?? '')), self::words((string) ($item['water_level'] ?? ''))),
            'permanent_secondary_geometry' => trim(sprintf('%s: %s %s', (string) ($item['element'] ?? ''), trim((string) ($item['position'] ?? '')), trim((string) ($item['description'] ?? '')))),
            'geometric_relationships' => is_string($item['statement'] ?? null) ? trim($item['statement']) : null,
            'must_preserve' => is_string($item['statement'] ?? null) ? trim($item['statement']) : null,
            'must_not_introduce' => sprintf('Never %s Instead: %s', lcfirst(rtrim(trim((string) ($item['interpretation'] ?? '')), '.')).'.', trim((string) ($item['instead'] ?? ''))),
            default => self::value(array_diff_key($item, array_flip(self::SKIPPED_KEYS)), $names),
        };
    }

    /**
     * @param  array<string, true>  $seen
     * @return list<string>
     */
    private static function fresh(string $line, array &$seen): array
    {
        $key = mb_strtolower(trim($line));

        if ($key === '' || isset($seen[$key])) {
            return [];
        }

        $seen[$key] = true;

        return [$line];
    }
}
