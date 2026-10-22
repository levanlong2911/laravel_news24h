<?php

declare(strict_types=1);

namespace App\Video\Llm;

final class JsonAnswer
{
    /** @return array{0: mixed, 1: ?string} [$decoded, $error] */
    public static function parse(string $text): array
    {
        $decoded = json_decode($text);

        if (json_last_error() === JSON_ERROR_NONE) {
            return [$decoded, null];
        }

        $error = json_last_error_msg();

        if (preg_match('/\A\s*```(?:json)?[ \t]*\r?\n(.*)\r?\n```\s*\z/s', $text, $fenced) === 1) {
            $decoded = json_decode($fenced[1]);

            if (json_last_error() === JSON_ERROR_NONE) {
                return [$decoded, null];
            }

            $error = json_last_error_msg();
        }

        return [null, $error];
    }

    /** @return array<mixed>|null */
    public static function object(string $text): ?array
    {
        [$decoded, $error] = self::parse($text);
        $array = $error === null ? json_decode((string) json_encode($decoded), true) : null;

        return is_array($array) && ! array_is_list($array) ? $array : null;
    }
}
