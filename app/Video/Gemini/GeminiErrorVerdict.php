<?php

namespace App\Video\Gemini;

final class GeminiErrorVerdict
{
    public const SENTINEL = '__probe_unknown_field__';

    /**
     * Google liet ke MOI truong la VA moi gia tri sai trong mot lan. Truong
     * dung ma gia tri sai (enum) la mot loai rieng: body co the dung duoc neu
     * doi gia tri, nhung KHONG dung duoc voi gia tri dang gui.
     *
     * @return array{0: string, 1: list<array{name: string, at: string}>, 2: list<array{at: string, type: string, value: string}>}
     */
    public static function classify(string $message): array
    {
        preg_match_all("/Unknown name \"([^\"]+)\" at '([^']*)'/", $message, $u, PREG_SET_ORDER);
        preg_match_all("/Invalid value at '([^']*)' \(([^)]*)\), (?:\"([^\"]*)\"|(\S+))/", $message, $i, PREG_SET_ORDER);

        $unknown = array_map(
            static fn (array $m): array => ['name' => $m[1], 'at' => $m[2]],
            $u,
        );

        $invalid = array_map(static fn (array $m): array => [
            'at' => $m[1],
            'type' => $m[2],
            'value' => ($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''),
        ], $i);

        $names = array_values(array_unique(array_column($unknown, 'name')));
        $fieldUnknown = array_values(array_diff($names, [self::SENTINEL]));

        return match (true) {
            $unknown === [] && $invalid === [] => ['inconclusive', $unknown, $invalid],
            $fieldUnknown !== [] => ['field_rejected', $unknown, $invalid],
            $invalid !== [] => ['value_rejected', $unknown, $invalid],
            $names === [self::SENTINEL] => ['field_accepted', $unknown, $invalid],
            default => ['inconclusive', $unknown, $invalid],
        };
    }
}
