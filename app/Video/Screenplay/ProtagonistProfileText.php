<?php

declare(strict_types=1);

namespace App\Video\Screenplay;

final class ProtagonistProfileText
{
    /**
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    public static function lines(array $profile, string $indent = ''): array
    {
        if (VesselDesign::isV3Profile($profile)) {
            return self::v3ProfileLines($profile, $indent);
        }

        $lines = [];

        foreach (ProtagonistProfile::SECTIONS as $section) {
            $lines[] = $indent.strtoupper(str_replace('_', ' ', $section));
            $lines[] = $indent.'  '.(string) ($profile[$section] ?? '');
            $lines[] = '';
        }

        $lines = [...$lines, ...self::figureLines((array) ($profile['figures'] ?? []), $indent)];
        $lines[] = '';
        $lines[] = $indent.'SIGNATURE FEATURES';

        foreach ((array) ($profile['signature_features'] ?? []) as $index => $feature) {
            if (! is_array($feature)) {
                continue;
            }

            $lines[] = $indent.'  '.($index + 1).'. '.($feature['name'] ?? '');

            $tags = array_filter(array_map(
                static fn (string $tag): string => str_replace('_', ' ', (string) ($feature[$tag] ?? '')),
                ProtagonistProfile::FEATURE_TAGS,
            ), static fn (string $tag): bool => $tag !== '');

            if ($tags !== []) {
                $lines[] = $indent.'     '.implode(' · ', $tags);
            }

            foreach (ProtagonistProfile::FEATURE_FIELDS as $field) {
                if ($field !== 'name') {
                    $lines[] = $indent.'     '.str_replace('_', ' ', $field).': '.($feature[$field] ?? '');
                }
            }
        }

        $rooms = (array) ($profile[ProtagonistProfile::INTERIOR_KEY] ?? []);

        if ($rooms !== []) {
            $lines[] = '';
            $lines[] = $indent.'INTERIOR SPACES';
        }

        foreach ($rooms as $room) {
            if (! is_array($room)) {
                continue;
            }

            $lines[] = $indent.'  ['.($room['space'] ?? '?').']';

            foreach (array_keys(ProtagonistProfile::INTERIOR_FIELDS) as $field) {
                if (trim((string) ($room[$field] ?? '')) !== '') {
                    $lines[] = $indent.'     '.str_replace('_', ' ', $field).': '.$room[$field];
                }
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    public static function render(array $profile, string $indent = ''): string
    {
        return implode("\n", self::lines($profile, $indent));
    }

    /**
     * @param  array<string, mixed>  $design  a vessel_design_v3 output
     * @return list<string>
     */
    public static function designLines(array $design, string $indent = '', bool $multiline = false): array
    {
        $lines = self::v3ProfileLines(['figures' => $design['figures'] ?? [], 'construction' => $design['construction'] ?? null], $indent);
        $labels = VesselDesign::v3Labels($design);
        $missing = [];
        $sections = [...(array) ($design[VesselDesign::CANONICAL_KEY] ?? []), VesselDesign::UNRESOLVED_KEY => $design[VesselDesign::UNRESOLVED_KEY] ?? []];

        foreach ($sections as $key => $node) {
            $block = DesignText::labelled((string) $key, $node, $labels, $missing, true, $multiline);

            if ($block !== []) {
                $lines = [...$lines, '', ...array_map(static fn (string $line): string => $indent.$line, $block)];
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $design  a vessel_design_v4 output
     * @return list<string> every row as its name and the description the design wrote, verbatim
     */
    public static function describedLines(array $design, string $indent = '', bool $multiline = false): array
    {
        $descriptions = VesselDesign::descriptions($design);
        $lines = [$indent.'FIGURES'];

        foreach ((array) ($design['figures'] ?? []) as $figure) {
            if (! is_array($figure)) {
                continue;
            }

            $value = trim((string) ($figure['value'] ?? ''));
            $text = $descriptions['figures'][VesselDesign::descriptionKey('figures', $figure) ?? ''] ?? trim((string) ($figure['note'] ?? ''));
            $lines[] = $indent.'  '.str_replace('_', ' ', (string) ($figure['quantity'] ?? '?')).': '
                .($value === '' ? 'not decided' : $value.' '.($figure['unit'] ?? ''))
                .($text === '' ? '' : ' — '.$text);
        }

        $lines = [...$lines, ...array_slice(self::v3ProfileLines(['construction' => $design['construction'] ?? null], $indent), 1)];
        $labels = VesselDesign::v3Labels($design);
        $missing = [];
        $sections = [...(array) ($design[VesselDesign::CANONICAL_KEY] ?? []), VesselDesign::UNRESOLVED_KEY => $design[VesselDesign::UNRESOLVED_KEY] ?? []];

        foreach ($sections as $key => $node) {
            $block = DesignText::described((string) $key, $node, $descriptions[$key] ?? [], $labels, $missing, true, $multiline);

            if ($block !== []) {
                $lines = [...$lines, '', ...array_map(static fn (string $line): string => $indent.$line, $block)];
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    private static function v3ProfileLines(array $profile, string $indent): array
    {
        $lines = self::figureLines((array) ($profile['figures'] ?? []), $indent);
        $lines[] = '';
        $lines[] = $indent.'CONSTRUCTION';

        foreach ((array) ($profile['construction'] ?? []) as $field => $text) {
            if (is_string($text) && trim($text) !== '') {
                $lines[] = $indent.'  '.str_replace('_', ' ', (string) $field).': '.trim($text);
            }
        }

        return $lines;
    }

    /**
     * @param  array<mixed>  $figures
     * @return list<string>
     */
    private static function figureLines(array $figures, string $indent): array
    {
        $lines = [$indent.'FIGURES'];

        foreach ($figures as $figure) {
            if (! is_array($figure)) {
                continue;
            }

            $value = trim((string) ($figure['value'] ?? ''));
            $note = trim((string) ($figure['note'] ?? ''));
            $lines[] = $indent.'  '.str_replace('_', ' ', (string) ($figure['quantity'] ?? '?')).': '
                .($value === '' ? 'not decided' : $value.' '.($figure['unit'] ?? ''))
                .' ('.($figure['origin'] ?? '?').(($figure['source_path'] ?? '') === '' ? '' : ', '.$figure['source_path']).')'
                .($note === '' ? '' : ' — '.$note);
        }

        return $lines;
    }
}
