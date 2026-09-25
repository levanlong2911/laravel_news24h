<?php

declare(strict_types=1);

namespace App\Video\Screenplay;

final class ScreenplayFoundationText
{
    /** @param array<string, mixed> $foundation */
    public static function render(array $foundation): string
    {
        $version = (string) ($foundation['schema_version'] ?? '');

        if ($version !== '' && ! in_array($version, ['screenplay_foundation_v1', 'screenplay_foundation_v2'], true)) {
            return "Foundation version {$version} is not supported by this view.";
        }

        $lines = [
            (string) ($foundation['logline'] ?? ''),
            '',
            'DESIGN THESIS',
            '  Central idea        : '.($foundation['design_thesis']['central_idea'] ?? ''),
            '  Visible difference  : '.($foundation['design_thesis']['visible_difference'] ?? ''),
            '  Spatial consequence : '.($foundation['design_thesis']['spatial_consequence'] ?? ''),
            '  Coherence           : '.($foundation['design_thesis']['coherence'] ?? ''),
            '  Realization         : '.($foundation['design_thesis']['realization'] ?? ''),
            '',
            ...self::dimensionLines($foundation['principal_dimensions'] ?? null),
            'PREMISE',
            '  Question : '.($foundation['premise']['question'] ?? ''),
            '  Force    : '.($foundation['premise']['force'] ?? ''),
            '  Device   : '.($foundation['premise']['device'] ?? ''),
            '  Change   : '.($foundation['premise']['change'] ?? ''),
            '  Answer   : '.($foundation['premise']['answer'] ?? ''),
            '',
            str_repeat('=', 70),
            'SYNOPSIS',
            '',
            (string) ($foundation['synopsis'] ?? ''),
        ];

        foreach ($foundation['stage_treatments'] ?? [] as $treatment) {
            if (! is_array($treatment)) {
                continue;
            }

            $lines[] = '';
            $lines[] = str_repeat('=', 70);
            $lines[] = mb_strtoupper((string) ($treatment['stage'] ?? '?'));
            $lines[] = '';
            $lines[] = 'Why this stage is here';
            $lines[] = '  '.($treatment['dramatic_purpose'] ?? '');
            $lines[] = '';
            $lines[] = 'What becomes observable';
            $lines[] = '  '.($treatment['observable_development'] ?? '');
            $lines[] = '';
            $lines[] = 'Who takes part';
            $lines[] = '  '.($treatment['participants'] ?? '');
            $lines[] = '';
            $lines[] = '» Carries into the next : '.($treatment['handover_to_next'] ?? '');
        }

        $lines[] = '';
        $lines[] = str_repeat('=', 70);
        $lines[] = 'ENDING';
        $lines[] = '';
        $lines[] = (string) ($foundation['ending'] ?? '');
        $lines[] = '';
        $lines[] = str_repeat('=', 70);
        $lines[] = 'No scenes yet. The scene breakdown is a later step.';

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private static function dimensionLines(mixed $dimensions): array
    {
        if (! is_array($dimensions)) {
            return [];
        }

        $number = static fn (mixed $value): string => is_int($value) || is_float($value)
            ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.')
            : '?';

        return [
            'PRINCIPAL DIMENSIONS',
            '  Length × beam : '.$number($dimensions['length_m'] ?? null).' m × '
                .$number($dimensions['beam_m'] ?? null).' m',
            '  Why           : '.(is_string($dimensions['rationale'] ?? null) ? $dimensions['rationale'] : ''),
            '',
        ];
    }
}
