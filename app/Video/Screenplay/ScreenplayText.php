<?php

declare(strict_types=1);

namespace App\Video\Screenplay;

final class ScreenplayText
{
    /** @param array<string, mixed> $screenplay */
    public static function render(array $screenplay): string
    {
        $version = (string) ($screenplay['schema_version'] ?? '');

        return match ($version) {
            'screenplay_v2' => self::renderV2($screenplay),
            'screenplay_v3' => self::renderV3($screenplay),
            '' => self::renderLegacy($screenplay),
            default => "Screenplay version {$version} is not supported by this view.",
        };
    }

    /** @param array<string, mixed> $screenplay */
    private static function renderV2(array $screenplay): string
    {
        return self::renderBody($screenplay, false);
    }

    /** @param array<string, mixed> $screenplay */
    private static function renderV3(array $screenplay): string
    {
        return self::renderBody($screenplay, true);
    }

    /** @param array<string, mixed> $screenplay */
    private static function renderBody(array $screenplay, bool $withAccounting): string
    {
        $lines = [
            (string) ($screenplay['logline'] ?? ''),
            '',
            'DESIGN THESIS',
            '  Central idea        : '.($screenplay['design_thesis']['central_idea'] ?? ''),
            '  Visible difference  : '.($screenplay['design_thesis']['visible_difference'] ?? ''),
            '  Spatial consequence : '.($screenplay['design_thesis']['spatial_consequence'] ?? ''),
            '  Coherence           : '.($screenplay['design_thesis']['coherence'] ?? ''),
            '  Realization         : '.($screenplay['design_thesis']['realization'] ?? ''),
            '',
            'PREMISE',
            '  Question : '.($screenplay['premise']['question'] ?? ''),
            '  Force    : '.($screenplay['premise']['force'] ?? ''),
            '  Device   : '.($screenplay['premise']['device'] ?? ''),
            '  Change   : '.($screenplay['premise']['change'] ?? ''),
            '  Answer   : '.($screenplay['premise']['answer'] ?? ''),
            '',
            'CHARACTERS',
        ];

        foreach ($screenplay['characters'] ?? [] as $character) {
            $lines[] = '';
            $lines[] = '  '.$character['name'].'   ['.$character['id'].' · '
                .$character['role'].' · '.$character['kind'].']';
            $lines[] = '    Role       : '.$character['description'];

            if (($character['personality'] ?? null) !== null) {
                $lines[] = '    Personality: '.$character['personality'];
            }

            $lines[] = '    Appearance : '.$character['appearance'];
        }

        $lines[] = '';
        $lines[] = 'LOCATIONS';

        foreach ($screenplay['locations'] ?? [] as $location) {
            $lines[] = '';
            $lines[] = '  '.$location['name'].'   ['.$location['id'].']';
            $lines[] = '    '.$location['description'];
        }

        $total = 0;

        foreach ($screenplay['scenes'] ?? [] as $scene) {
            $location = self::locationName($screenplay, (string) $scene['location_id']);
            $total += (int) ($scene['duration_estimate_ms'] ?? 0);

            $lines[] = '';
            $lines[] = str_repeat('=', 70);
            $lines[] = strtoupper((string) $scene['id']).' — '.$scene['int_ext'].'. '
                .mb_strtoupper($location).' — '.$scene['time'];
            $lines[] = 'Stage: '.$scene['stage'].'   ·   '.(int) $scene['duration_estimate_ms'].'ms';

            if ($withAccounting) {
                $lines[] = self::buildStateLine($scene);
            }

            $lines[] = '';
            $lines[] = (string) $scene['action'];

            foreach ($scene['dialogue'] ?? [] as $line) {
                $lines[] = '';
                $lines[] = '        '.mb_strtoupper(self::characterName($screenplay, (string) $line['character_id']));
                $lines[] = '        '.$line['line'];
            }

            if (($scene['sound'] ?? null) !== null) {
                $lines[] = '';
                $lines[] = 'Sound: '.$scene['sound'];
            }

            $lines[] = '';
            $lines[] = '» Cannot be cut: '.$scene['why_it_cannot_be_cut'];

            if (($scene['leads_to'] ?? null) !== null) {
                $lines[] = '» Leads to    : '.$scene['leads_to'];
            }
        }

        $lines[] = '';
        $lines[] = str_repeat('=', 70);
        $lines[] = 'ESTIMATED TOTAL '.$total.'ms ('.round($total / 1000, 1).'s)';

        if ($withAccounting) {
            $lines = array_merge($lines, self::coverageLines($screenplay));
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $scene */
    private static function buildStateLine(array $scene): string
    {
        if (! array_key_exists('build_state', $scene)) {
            return 'Build state: MISSING — this scene does not declare one.';
        }

        $state = $scene['build_state'];

        if ($state === null) {
            return 'Build state: not shown in this scene. It does not carry over from the scene before.';
        }

        if (! is_array($state)) {
            return 'Build state: UNREADABLE — stored as '.get_debug_type($state).', expected an object or null.';
        }

        return 'Build state: '.($state['subject_id'] ?? '?').' — '.($state['state'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @return list<string>
     */
    private static function coverageLines(array $screenplay): array
    {
        $lines = ['', str_repeat('=', 70), 'COVERAGE'];

        foreach ($screenplay['coverage'] ?? [] as $item) {
            $mode = (string) ($item['mode'] ?? '');
            $scenes = array_filter((array) ($item['scene_ids'] ?? []), 'is_string');

            $lines[] = '';
            $lines[] = '  '.($item['coverage_id'] ?? '?').'   ['.$mode
                .($scenes === [] ? '' : ' · '.implode(', ', $scenes)).']';

            if ($mode === 'not_applicable') {
                $lines[] = '    SET ASIDE — needs editorial review. This is a request, not an approval.';
            }

            $lines[] = '    '.($item['evidence'] ?? '');
        }

        return $lines;
    }

    /** @param array<string, mixed> $screenplay */
    private static function renderLegacy(array $screenplay): string
    {
        $lines = [
            'LEGACY SCREENPLAY (pre screenplay_v2) — view only, not for production.',
            '',
            (string) ($screenplay['logline'] ?? ''),
            '',
        ];

        foreach ($screenplay['subjects'] ?? [] as $subject) {
            $lines[] = '  '.($subject['id'] ?? '?').'  '.($subject['role'] ?? '');
            $lines[] = '    '.($subject['identity'] ?? '');
        }

        foreach ($screenplay['scenes'] ?? [] as $scene) {
            $lines[] = '';
            $lines[] = ($scene['id'] ?? '?').'  '.($scene['beat'] ?? '').'  @ '.($scene['location_id'] ?? '');
            $lines[] = '  '.($scene['purpose'] ?? '');
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $screenplay */
    private static function locationName(array $screenplay, string $id): string
    {
        foreach ($screenplay['locations'] ?? [] as $location) {
            if (($location['id'] ?? null) === $id) {
                return (string) $location['name'];
            }
        }

        return $id;
    }

    /** @param array<string, mixed> $screenplay */
    private static function characterName(array $screenplay, string $id): string
    {
        foreach ($screenplay['characters'] ?? [] as $character) {
            if (($character['id'] ?? null) === $id) {
                return (string) $character['name'];
            }
        }

        return $id;
    }
}
