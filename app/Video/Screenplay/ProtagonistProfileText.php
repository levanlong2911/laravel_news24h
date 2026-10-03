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
        $lines = [];

        foreach (ProtagonistProfile::SECTIONS as $section) {
            $lines[] = $indent.strtoupper(str_replace('_', ' ', $section));
            $lines[] = $indent.'  '.(string) ($profile[$section] ?? '');
            $lines[] = '';
        }

        $lines[] = $indent.'FIGURES';

        foreach ((array) ($profile['figures'] ?? []) as $figure) {
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
}
