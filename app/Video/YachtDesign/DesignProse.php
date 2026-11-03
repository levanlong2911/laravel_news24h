<?php

declare(strict_types=1);

namespace App\Video\YachtDesign;

final class DesignProse
{
    /** @var list<string> */
    private const BIBLE_SECTIONS = ['name', 'fictional_status', 'code_assumption', 'specs', 'colors', 'exterior', 'exterior_zones', 'innovations', 'decks', 'rooms'];

    /** @var array<string, string> the list blocks of a phase3-design-v3 record by data key, with their section titles, in record order */
    private const RECORD_SECTIONS = ['parts' => 'Parts', 'decks' => 'Decks', 'spaces' => 'Spaces', 'connections' => 'Connections', 'routes' => 'Routes', 'innovations' => 'Innovations', 'operational_limits' => 'Operations', 'build_states' => 'Build states', 'identity_rules' => 'Identity rules', 'source_issues' => 'Source issues'];

    /** @var array<string, string> the list blocks of a moto-design-v1 record by data key, with their section titles, in record order */
    private const MOTO_SECTIONS = ['parts' => 'Parts', 'systems' => 'Systems', 'connections' => 'Connections', 'features' => 'Features', 'operational_limits' => 'Operations', 'build_states' => 'Build states', 'identity_rules' => 'Identity rules', 'source_issues' => 'Source issues'];

    /**
     * @param  array<mixed>  $data
     * @return list<array{title: string, blocks: list<array{heading: ?string, lines: list<array{label: ?string, text: string|list<string>}>}>}>
     */
    public static function sections(array $data): array
    {
        if (self::map($data['motorcycle'] ?? null) !== []) {
            return self::record($data, 'motorcycle', 'Motorcycle', self::MOTO_SECTIONS);
        }

        if (self::map($data['vessel'] ?? null) !== []) {
            return self::record($data, 'vessel', 'Vessel', self::RECORD_SECTIONS);
        }

        $bible = self::map($data['yacht_bible'] ?? null);
        $anchors = self::map($data['anchors'] ?? null);
        $canonical = self::map($data['canonical'] ?? null);
        $states = self::map($data['build_states'] ?? null);
        $spaces = array_column(self::items($canonical['spaces'] ?? null), null, 'id');
        $relations = array_column(self::items($canonical['innovations'] ?? null), null, 'id');

        return array_values(array_filter([
            self::section('Overview', [self::block(
                is_string($bible['name'] ?? null) ? $bible['name'] : null,
                [...self::lines(array_intersect_key($bible, array_flip(['fictional_status', 'code_assumption']))), ...self::lines(['yacht_anchor' => $anchors['yacht'] ?? null])],
            )]),
            self::section('Exterior', [
                self::block(null, [...self::lines(['geometry_anchor' => $anchors['geometry'] ?? null, 'finished_surface' => $anchors['finished_surface'] ?? null]), ...self::lines(self::map($bible['exterior'] ?? null))]),
                ...array_map(
                    static fn (array $zone): ?array => self::block(self::title($zone), [...self::lines($zone, ['id', 'name']), ...self::space($spaces[$zone['id'] ?? ''] ?? null), ...self::lines(['anchor' => self::map($anchors['exterior'] ?? null)[$zone['id'] ?? ''] ?? null])]),
                    self::items($bible['exterior_zones'] ?? null),
                ),
            ]),
            self::section('Innovations', array_map(static fn (array $item): ?array => self::block(self::title($item), [...self::lines($item, ['id', 'name']), ...self::lines(array_diff_key(self::map($relations[$item['id'] ?? ''] ?? null), ['id' => true]))]), self::items($bible['innovations'] ?? null))),
            self::section('Decks', array_map(static fn (array $deck): ?array => self::block(self::title($deck), self::lines($deck, ['name'])), self::items($bible['decks'] ?? null))),
            self::section('Interior', array_map(
                static fn (array $room): ?array => self::block(self::title($room), [...self::lines($room, ['id', 'name']), ...self::space($spaces[$room['id'] ?? ''] ?? null), ...self::lines(['anchor' => self::map($anchors['interior'] ?? null)[$room['id'] ?? ''] ?? null])]),
                self::items($bible['rooms'] ?? null),
            )),
            self::section('Connections', array_map(static fn (array $item): ?array => self::block(is_string($item['id'] ?? null) ? $item['id'] : null, self::lines($item, ['id'])), self::items($canonical['connections'] ?? null))),
            self::section('Routes', array_map(static fn (array $item): ?array => self::block(is_string($item['id'] ?? null) ? $item['id'] : null, self::lines($item, ['id'])), self::items($canonical['routes'] ?? null))),
            self::section('Identity rules', array_map(static fn (array $item): ?array => self::block(is_string($item['id'] ?? null) ? $item['id'] : null, self::lines($item, ['id'])), self::items($canonical['identity_features'] ?? null))),
            self::section('Build states', array_map(
                static fn (string $id): ?array => self::block($id, self::lines(['state' => $states[$id] ?? null, 'surface_state' => self::map($anchors['surface_states'] ?? null)[$id] ?? null])),
                array_values(array_unique([...array_keys($states), ...array_keys(self::map($anchors['surface_states'] ?? null))])),
            )),
            self::section('Operations', array_map(
                static fn (array $limit): ?array => self::block(is_string($limit['feature'] ?? null) ? self::label($limit['feature']) : null, self::lines($limit, ['feature'])),
                self::items($canonical['operational_limits'] ?? null),
            )),
            self::section('Additional information', [self::block(null, self::lines(array_diff_key($bible, array_flip(self::BIBLE_SECTIONS))))]),
        ]));
    }

    /**
     * @param  array<mixed>  $data  the data of a phase3-design-v3 or moto-design-v1 answer
     * @param  string  $subject  the data key of the whole object: vessel or motorcycle
     * @param  array<string, string>  $sections  the list blocks by data key, with their section titles
     * @return list<array{title: string, blocks: list<array{heading: ?string, lines: list<array{label: ?string, text: string|list<string>}>}>}>
     */
    private static function record(array $data, string $subject, string $title, array $sections): array
    {
        $whole = self::map($data[$subject] ?? null);
        $rider = self::map($data['rider_interface'] ?? null);
        $sheet = self::map($data['model_sheet'] ?? null);
        $item = static fn (array $item): ?array => self::block(self::title($item), self::lines($item, ['id', 'name'], null, true));

        return array_values(array_filter([
            self::section($title, [self::block(is_string($whole['name'] ?? null) ? $whole['name'] : null, self::lines($whole, ['name'], null, true))]),
            $rider === [] ? null : self::section('Rider interface', [self::block(null, self::lines($rider, [], null, true))]),
            ...array_map(
                static fn (string $key, string $title): ?array => self::section($title, array_map($item, self::items($data[$key] ?? null))),
                array_keys($sections),
                array_values($sections),
            ),
            self::section('Model sheet', [
                self::block(null, self::lines(['display_configuration' => $sheet['display_configuration'] ?? null])),
                ...array_map($item, self::items($sheet['views'] ?? null)),
                self::block(null, self::lines(['final_prompt' => $sheet['final_prompt'] ?? null])),
            ]),
        ]));
    }

    /** @param  array<mixed>  $data */
    public static function text(array $data): string
    {
        $parts = [];

        foreach (self::sections($data) as $section) {
            $blocks = [];

            foreach ($section['blocks'] as $block) {
                $lines = $block['heading'] === null ? [] : [$block['heading']];

                foreach ($block['lines'] as $line) {
                    $lines[] = is_array($line['text'])
                        ? $line['label'].":\n".implode("\n", array_map(static fn (string $item): string => '- '.$item, $line['text']))
                        : $line['label'].': '.$line['text'];
                }

                $blocks[] = implode("\n", $lines);
            }

            $parts[] = $section['title']."\n\n".implode("\n\n", $blocks);
        }

        return implode("\n\n\n", $parts);
    }

    /**
     * @param  list<?array{heading: ?string, lines: list<array{label: ?string, text: string|list<string>}>}>  $blocks
     * @return ?array{title: string, blocks: list<array{heading: ?string, lines: list<array{label: ?string, text: string|list<string>}>}>}
     */
    private static function section(string $title, array $blocks): ?array
    {
        $blocks = array_values(array_filter($blocks));

        return $blocks === [] ? null : ['title' => $title, 'blocks' => $blocks];
    }

    /**
     * @param  list<array{label: ?string, text: string|list<string>}>  $lines
     * @return ?array{heading: ?string, lines: list<array{label: ?string, text: string|list<string>}>}
     */
    private static function block(?string $heading, array $lines): ?array
    {
        return $lines === [] && $heading === null ? null : ['heading' => $heading, 'lines' => $lines];
    }

    /**
     * @param  array<mixed>  $fields
     * @param  list<string>  $skip
     * @param  bool  $figures  also write numbers, yes/no values and each {value, unit} figure on its own line
     * @return list<array{label: ?string, text: string|list<string>}>
     */
    private static function lines(array $fields, array $skip = [], ?string $prefix = null, bool $figures = false): array
    {
        $lines = [];

        foreach ($fields as $key => $value) {
            if ($key === 'basis' || in_array($key, $skip, true)) {
                continue;
            }

            $label = ($prefix === null ? '' : $prefix.' — ').self::label((string) $key);

            if (self::prose($value)) {
                $lines[] = ['label' => $label, 'text' => trim($value)];
            } elseif ($figures && (is_int($value) || is_float($value) || is_bool($value))) {
                $lines[] = ['label' => $label, 'text' => is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value];
            } elseif ($figures && is_array($value) && (is_int($value['value'] ?? null) || is_float($value['value'] ?? null))) {
                $lines[] = ['label' => $label, 'text' => $value['value'].(is_string($value['unit'] ?? null) ? ' '.$value['unit'] : '')];
            } elseif (is_array($value) && array_is_list($value)) {
                $texts = array_values(array_filter(array_map(
                    static fn (mixed $item): ?string => self::prose($item) ? trim($item) : (is_array($item) && ! array_is_list($item) ? self::inline($item) : null),
                    $value,
                ), static fn (?string $text): bool => $text !== null && $text !== ''));

                if ($texts !== []) {
                    $lines[] = ['label' => $label, 'text' => $texts];
                }
            } elseif (is_array($value)) {
                array_push($lines, ...self::lines($value, [], $label, $figures));
            }
        }

        return $lines;
    }

    /** @param  array<mixed>  $item  one object of a list, written on one line: each filled field as "label: value" */
    private static function inline(array $item): string
    {
        $parts = [];

        foreach ($item as $key => $value) {
            $text = match (true) {
                is_string($value) => trim($value),
                is_int($value), is_float($value) => (string) $value,
                is_array($value) && array_is_list($value) => implode(', ', array_filter($value, 'is_string')),
                default => '',
            };

            if ($text !== '') {
                $parts[] = self::label((string) $key).': '.$text;
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  mixed  $space  the canonical entry of a room or zone
     * @return list<array{label: ?string, text: string|list<string>}> its decks and every known figure of its size with what the figures measure; an unknown figure says so
     */
    private static function space(mixed $space): array
    {
        if (! is_array($space)) {
            return [];
        }

        $size = self::map($space['dimensions'] ?? null);
        $figures = [];

        foreach (['length_m' => 'length', 'width_m' => 'width', 'height_m' => 'height'] as $axis => $word) {
            $value = $size[$axis]['value'] ?? null;
            $figures[] = is_int($value) || is_float($value) ? "{$word} {$value} m" : "{$word} unknown";
        }

        $scope = is_string($size['scope'] ?? null) ? ' — '.trim($size['scope']) : '';
        $decks = implode(', ', array_filter((array) ($space['decks'] ?? []), 'is_string'));

        return [
            ...($decks === '' ? [] : [['label' => 'Decks', 'text' => $decks]]),
            ['label' => 'Size', 'text' => implode(', ', $figures).$scope],
        ];
    }

    private static function prose(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && preg_match('/^#[0-9A-Fa-f]{6}$/', trim($value)) !== 1;
    }

    /** @param  array<mixed>  $item */
    private static function title(array $item): ?string
    {
        $parts = array_values(array_filter([$item['id'] ?? null, $item['name'] ?? null], static fn (mixed $part): bool => is_string($part) && trim($part) !== ''));

        return $parts === [] ? null : implode(' · ', array_map('trim', $parts));
    }

    private static function label(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }

    /** @return array<mixed> */
    private static function map(mixed $value): array
    {
        return is_array($value) && ($value === [] || ! array_is_list($value)) ? $value : [];
    }

    /** @return list<array<mixed>> */
    private static function items(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
