<?php

declare(strict_types=1);

namespace App\Video\YachtDesign;

final class DesignProse
{
    /** @var list<string> */
    private const BIBLE_SECTIONS = ['name', 'fictional_status', 'code_assumption', 'specs', 'colors', 'exterior', 'exterior_zones', 'innovations', 'decks', 'rooms'];

    /**
     * @param  array<mixed>  $data
     * @return list<array{title: string, blocks: list<array{heading: ?string, lines: list<array{label: ?string, text: string|list<string>}>}>}>
     */
    public static function sections(array $data): array
    {
        $bible = self::map($data['yacht_bible'] ?? null);
        $anchors = self::map($data['anchors'] ?? null);
        $canonical = self::map($data['canonical'] ?? null);
        $states = self::map($data['build_states'] ?? null);

        return array_values(array_filter([
            self::section('Overview', [self::block(
                is_string($bible['name'] ?? null) ? $bible['name'] : null,
                [...self::lines(array_intersect_key($bible, array_flip(['fictional_status', 'code_assumption']))), ...self::lines(['yacht_anchor' => $anchors['yacht'] ?? null])],
            )]),
            self::section('Exterior', [
                self::block(null, [...self::lines(['geometry_anchor' => $anchors['geometry'] ?? null, 'finished_surface' => $anchors['finished_surface'] ?? null]), ...self::lines(self::map($bible['exterior'] ?? null))]),
                ...array_map(
                    static fn (array $zone): ?array => self::block(self::title($zone), [...self::lines($zone, ['id']), ...self::lines(['anchor' => self::map($anchors['exterior'] ?? null)[$zone['id'] ?? ''] ?? null])]),
                    self::items($bible['exterior_zones'] ?? null),
                ),
            ]),
            self::section('Innovations', array_map(static fn (array $item): ?array => self::block(self::title($item), self::lines($item, ['id', 'name'])), self::items($bible['innovations'] ?? null))),
            self::section('Decks', array_map(static fn (array $deck): ?array => self::block(self::title($deck), self::lines($deck, ['name'])), self::items($bible['decks'] ?? null))),
            self::section('Interior', array_map(
                static fn (array $room): ?array => self::block(self::title($room), [...self::lines($room, ['id', 'name']), ...self::lines(['anchor' => self::map($anchors['interior'] ?? null)[$room['id'] ?? ''] ?? null])]),
                self::items($bible['rooms'] ?? null),
            )),
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
     * @return list<array{label: ?string, text: string|list<string>}>
     */
    private static function lines(array $fields, array $skip = [], ?string $prefix = null): array
    {
        $lines = [];

        foreach ($fields as $key => $value) {
            if ($key === 'basis' || in_array($key, $skip, true)) {
                continue;
            }

            $label = ($prefix === null ? '' : $prefix.' — ').self::label((string) $key);

            if (self::prose($value)) {
                $lines[] = ['label' => $label, 'text' => trim($value)];
            } elseif (is_array($value) && array_is_list($value)) {
                $texts = array_values(array_map('trim', array_filter($value, self::prose(...))));

                if ($texts !== []) {
                    $lines[] = ['label' => $label, 'text' => $texts];
                }
            } elseif (is_array($value)) {
                array_push($lines, ...self::lines($value, [], $label));
            }
        }

        return $lines;
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
