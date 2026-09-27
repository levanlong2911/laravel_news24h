<?php

declare(strict_types=1);

namespace App\Video\Screenplay;

final class ScreenplayContentHash
{
    /** @var list<string> */
    private const CONTENT_FIELDS = [
        'schema_version', 'logline', 'design_thesis', 'principal_dimensions',
        'premise', 'synopsis', 'stage_treatments', 'ending', 'characters',
        'locations', 'scenes', 'coverage',
    ];

    /** @param array<string, mixed> $screenplay */
    public static function of(array $screenplay): string
    {
        $content = [];

        foreach (self::CONTENT_FIELDS as $field) {
            if (array_key_exists($field, $screenplay)) {
                $content[$field] = self::sortObjects($screenplay[$field]);
            }
        }

        return hash('sha256', json_encode(
            $content,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private static function sortObjects(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $child) {
            $value[$key] = self::sortObjects($child);
        }

        return $value;
    }
}
