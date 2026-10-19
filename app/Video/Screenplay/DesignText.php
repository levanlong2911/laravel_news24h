<?php

declare(strict_types=1);

namespace App\Video\Screenplay;

final class DesignText
{
    /** @var list<string> */
    private const SKIPPED_KEYS = ['id', 'refs', 'exterior_role', 'anchor_role', 'list', 'feature_id', 'priority', 'kind_id', 'status'];

    /** @var list<string> */
    private const ID_FIELDS = [
        'deck', 'host', 'serves', 'connects', 'relative_to', 'body', 'between', 'subject', 'object', 'refs', 'component_id', 'feature_id',
        'place', 'surface', 'access_routes', 'deck_ids', 'parts', 'open_to', 'through', 'space', 'target',
    ];

    /** @var list<string> */
    private const LABEL_FIELDS = ['name', 'region', 'element', 'feature', 'component'];

    /** @var list<string> */
    private const LABELLED_SKIPPED_KEYS = [
        'id', 'status', 'exterior_role', 'anchor_role', 'list', 'priority', 'feature_id', 'component_id', 'landmark',
        'name', 'region', 'element', 'feature', 'component',
    ];

    /** @var list<string> */
    private const FULL_SKIPPED_KEYS = ['id', 'feature_id', 'component_id'];

    /** @var array<string, string> */
    private const ROW_LABEL_FIELDS = ['signature_regions' => 'feature'];

    /**
     * @param  array<string, string>  $labels  id => the name the design gives that part
     * @param  list<string>  $missing  ids met without a name, filled in by reference
     * @param  bool  $full  every row and field for a reader of the whole design, interior rows, status and priority included
     * @return list<string>
     */
    public static function labelled(string $key, mixed $node, array $labels, array &$missing, bool $full = false, bool $multiline = false): array
    {
        $title = strtoupper(str_replace('_', ' ', $key)).':';

        if (is_string($node)) {
            return trim($node) === '' ? [] : [$title.' '.self::withLabels(trim($node), $labels)];
        }

        if (! is_array($node) || $node === []) {
            return [];
        }

        if (! array_is_list($node)) {
            $fields = self::fields($node, $labels, $missing, '', $full ? self::FULL_SKIPPED_KEYS : self::LABELLED_SKIPPED_KEYS, $full);

            return $fields === [] ? [] : [$title, ...array_map(static fn (string $field): string => '- '.$field, $fields)];
        }

        if ($key === 'decks') {
            usort($node, static fn (mixed $a, mixed $b): int => (int) (is_array($a) ? ($a['level'] ?? 0) : 0) <=> (int) (is_array($b) ? ($b['level'] ?? 0) : 0));
        }

        $rows = [];

        foreach ($node as $row) {
            if (is_string($row) && trim($row) !== '') {
                $rows[] = '- '.self::withLabels(trim($row), $labels);
            } elseif (is_array($row) && ($full || ($row['exterior_role'] ?? null) !== 'interior_only')) {
                [$label, $labelField] = self::rowLabel($row, $labels, $key);
                $skipped = $full ? [...self::FULL_SKIPPED_KEYS, ...($labelField === null ? [] : [$labelField])] : self::LABELLED_SKIPPED_KEYS;
                $fields = self::fields($row, $labels, $missing, '', $skipped, $full);
                if ($multiline) {
                    if ($rows !== []) {
                        $rows[] = '';
                    }
                    if ($label !== null && $label !== '') {
                        $rows[] = $label;
                    }
                    foreach ($fields as $field) {
                        $rows[] = '  '.$field;
                    }
                } else {
                    $rows[] = '- '.implode('; ', array_filter([$label, ...$fields], static fn (?string $part): bool => $part !== null && $part !== ''));
                }
            }
        }

        return $rows === [] ? [] : [$title, ...$rows];
    }

    /**
     * @param  array<string, string>  $descriptions  row key => the description the design wrote for that row
     * @param  array<string, string>  $labels  id => the name the design gives that part
     * @param  list<string>  $missing  ids met without a name, filled in by reference
     * @param  bool  $full  every row for a reader of the whole design, interior rows included
     * @return list<string> each row as its name and its description, verbatim; a row the design gave no description keeps its labelled fields
     */
    public static function described(string $key, mixed $node, array $descriptions, array $labels, array &$missing, bool $full = false, bool $multiline = false): array
    {
        if (! is_array($node) || ! array_is_list($node) || $descriptions === []) {
            return self::labelled($key, $node, $labels, $missing, $full, $multiline);
        }

        if ($key === 'decks') {
            usort($node, static fn (mixed $a, mixed $b): int => (int) (is_array($a) ? ($a['level'] ?? 0) : 0) <=> (int) (is_array($b) ? ($b['level'] ?? 0) : 0));
        }

        $rows = [];

        foreach ($node as $row) {
            if (! is_array($row) || (! $full && ($row['exterior_role'] ?? null) === 'interior_only')) {
                continue;
            }

            $rowKey = VesselDesign::descriptionKey($key, $row);
            $description = $rowKey === null ? null : ($descriptions[$rowKey] ?? null);

            if ($description === null) {
                $rows = [...$rows, ...array_slice(self::labelled($key, [$row], $labels, $missing, $full, $multiline), 1)];

                continue;
            }

            [$label] = self::rowLabel($row, $labels, $key);
            $named = $label !== null && $label !== '';
            $interior = $full && is_array($row['interior'] ?? null) ? self::fields($row['interior'], $labels, $missing, 'interior', [], true) : [];

            if ($multiline) {
                if ($rows !== []) {
                    $rows[] = '';
                }

                if ($named) {
                    $rows[] = $label;
                }

                $rows[] = '  '.$description;

                foreach ($interior as $field) {
                    $rows[] = '  '.$field;
                }
            } else {
                $rows[] = '- '.implode('; ', [($named ? $label.': ' : '').$description, ...$interior]);
            }
        }

        return $rows === [] ? [] : [strtoupper(str_replace('_', ' ', $key)).':', ...$rows];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $labels
     * @return array{0: ?string, 1: ?string} the label and the field it was taken from
     */
    private static function rowLabel(array $row, array $labels, string $key): array
    {
        foreach (array_unique([...(isset(self::ROW_LABEL_FIELDS[$key]) ? [self::ROW_LABEL_FIELDS[$key]] : []), ...self::LABEL_FIELDS]) as $field) {
            if (is_string($row[$field] ?? null) && trim($row[$field]) !== '') {
                return [trim($row[$field]), $field];
            }
        }

        $id = $row['id'] ?? $row['component_id'] ?? null;

        return [is_string($id) && ! isset($row['statement']) && isset($labels[$id]) ? $labels[$id] : null, null];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, string>  $labels
     * @param  list<string>  $missing
     * @param  list<string>  $skipped  top-level fields left out
     * @return list<string>
     */
    private static function fields(array $node, array $labels, array &$missing, string $prefix = '', array $skipped = self::LABELLED_SKIPPED_KEYS, bool $full = false): array
    {
        $fields = [];

        foreach ($node as $field => $value) {
            if (! is_string($field) || ($prefix === '' && in_array($field, $skipped, true))) {
                continue;
            }

            $name = trim($prefix.' '.self::words($field));

            if (is_array($value) && ! array_is_list($value)) {
                $fields = [...$fields, ...self::fields($value, $labels, $missing, $name, $skipped, $full)];

                continue;
            }

            $text = self::fieldValue($field, $value, $labels, $missing, $full);

            if ($text !== '') {
                $fields[] = $name.': '.$text;
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, string>  $labels
     * @param  list<string>  $missing
     */
    private static function fieldValue(string $field, mixed $value, array $labels, array &$missing, bool $full = false): string
    {
        $isId = in_array($field, self::ID_FIELDS, true);

        if ($full && is_array($value) && ! array_is_list($value)) {
            return '('.implode('; ', self::fields($value, $labels, $missing, '', ['id'], true)).')';
        }

        if (is_array($value)) {
            $parts = [];

            foreach ($value as $item) {
                $parts[] = $item === null && $field === 'connects' ? 'outside the vessel' : self::fieldValue($field, $item, $labels, $missing, $full);
            }

            return implode(', ', array_filter($parts, static fn (string $part): bool => $part !== ''));
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($full && is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        if ($isId) {
            return self::label(trim($value), $labels, $missing);
        }

        return preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)+$/', trim($value)) === 1 ? self::words(trim($value)) : self::withLabels(trim($value), $labels);
    }

    /**
     * @param  array<string, string>  $labels
     * @param  list<string>  $missing
     */
    private static function label(string $id, array $labels, array &$missing): string
    {
        if (isset($labels[$id])) {
            return $labels[$id];
        }

        $missing[] = $id;

        return $id;
    }

    /** @param array<string, string> $labels */
    private static function withLabels(string $text, array $labels): string
    {
        if ($labels === []) {
            return $text;
        }

        $ids = array_keys($labels);
        usort($ids, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return preg_replace_callback(
            '/\b(?:'.implode('|', array_map(static fn (string $id): string => preg_quote($id, '/'), $ids)).')\b/u',
            static fn (array $match): string => $labels[$match[0]],
            $text,
        ) ?? $text;
    }

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
            'configuration_states' => trim(sprintf('%s: %s Shown in its canonical state: %s', ucfirst($name($item['component_id'] ?? null)),
                trim((string) ($item['fixed_geometry'] ?? '')), trim((string) ($item['canonical_state'] ?? '')))),
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
        $key = (string) preg_replace('/\s+/u', ' ', trim($line));

        if ($key === '' || isset($seen[$key])) {
            return [];
        }

        $seen[$key] = true;

        return [$line];
    }
}
