<?php

declare(strict_types=1);

namespace App\Video\Locations;

final class LocationReferencePrompt
{
    public const VERSION = 'phase5-background-prompt-v3';

    public const TASK = 'location_reference';

    public const OPERATION = 'location_reference';

    /**
     * @param  array<string, mixed>  $row  one row of LocationReferencePlanner::plan()
     * @param  array{labels: array<string, string>, style: array<string, string>, suffix: array<string, string>}  $config  video.superyacht.locations.reference
     * @return string the whole prompt of one background: the style of its kind (open water has its own), every part of the row in order behind its configured label, then the suffix of its kind
     */
    public static function compose(array $row, array $config): string
    {
        $parts = [self::sentence((string) ($config['style'][$row['style'] ?? $row['kind']] ?? $config['style'][$row['kind']]))];

        foreach ((array) $row['prompt_parts'] as $part) {
            $label = $config['labels'][$part['key']] ?? null;
            $parts[] = ($label === null ? '' : $label.' ').self::sentence((string) $part['text']);
        }

        $parts[] = self::sentence((string) $config['suffix'][$row['kind']]);

        return implode(' ', $parts);
    }

    private static function sentence(string $text): string
    {
        $text = trim($text);

        return $text === '' || preg_match('/[.!?]$/u', $text) === 1 ? $text : $text.'.';
    }
}
