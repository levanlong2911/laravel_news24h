<?php

declare(strict_types=1);

namespace App\Video\Screenplay;

final class SceneBeats
{
    /** @var list<string> */
    public const SCREENPLAY_VERSIONS = ['screenplay_v6', 'screenplay_v7'];

    public const CONTINUOUS = 'continuous';

    public const MONTAGE = 'montage';

    /** @var list<string> */
    public const MODES = [self::CONTINUOUS, self::MONTAGE];

    public const ROOM_STATE_KEY = 'room_state';

    public const SHELL_ONLY = 'shell_only';

    public const IN_FIT_OUT = 'in_fit_out';

    public const FITTED = 'fitted';

    /** @var list<string> */
    public const ROOM_STATES = [self::SHELL_ONLY, self::IN_FIT_OUT, self::FITTED];

    /** @var array<string, string> */
    private const ROOM_LINES = [
        self::SHELL_ONLY => 'ROOM STATE: a bare shell. Only its structure, openings and stairs stand; no finishes, fitted furniture, lamps or loose items.',
        self::IN_FIT_OUT => 'ROOM STATE: being fitted out. Only its structure and the work this frame\'s progress names are in place; nothing else of the finished room.',
        self::FITTED => 'ROOM STATE: fitted. Its designed finishes and furniture are in place.',
    ];

    /** @param  array<string, mixed>  $screenplay */
    public static function usesBeats(array $screenplay): bool
    {
        return in_array($screenplay['schema_version'] ?? null, self::SCREENPLAY_VERSIONS, true);
    }

    /**
     * @param  array<string, mixed>  $scene
     * @return list<string>
     */
    public static function beatIds(array $scene): array
    {
        $ids = [];

        foreach ((array) ($scene['beats'] ?? []) as $beat) {
            if (is_array($beat) && is_string($beat['id'] ?? null)) {
                $ids[] = $beat['id'];
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $scene
     * @return list<string>
     */
    public static function partNames(array $scene): array
    {
        $state = $scene['subject_state'] ?? null;
        $parts = [];

        if (! is_array($state)) {
            return [];
        }

        foreach (['start', 'end'] as $side) {
            foreach ((array) ($state[$side]['configuration'] ?? []) as $item) {
                if (is_array($item) && is_string($item['part'] ?? null) && trim($item['part']) !== '') {
                    $parts[trim($item['part'])] = true;
                }
            }
        }

        return array_keys($parts);
    }

    /**
     * @param  array<string, mixed>  $scene
     * @return array{subject_id: string, state: string}|null
     */
    public static function progressView(array $scene): ?array
    {
        if (is_array($scene['subject_state'] ?? null)) {
            return [
                'subject_id' => (string) ($scene['subject_state']['subject_id'] ?? ''),
                'state' => trim((string) ($scene['subject_state']['start']['progress'] ?? '')),
            ];
        }

        if (is_array($scene['build_state'] ?? null)) {
            return [
                'subject_id' => (string) ($scene['build_state']['subject_id'] ?? ''),
                'state' => trim((string) ($scene['build_state']['state'] ?? '')),
            ];
        }

        return null;
    }

    /** @param  array<string, mixed>  $scene */
    public static function subjectId(array $scene): ?string
    {
        $view = self::progressView($scene);

        return $view === null || $view['subject_id'] === '' ? null : $view['subject_id'];
    }

    public static function configurationText(mixed $configuration): string
    {
        $items = [];

        foreach ((array) $configuration as $item) {
            if (is_array($item) && trim((string) ($item['part'] ?? '')) !== '') {
                $items[] = trim((string) $item['part']).' — '.trim((string) ($item['state'] ?? ''));
            }
        }

        return implode('; ', $items);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    public static function stateLines(array $state, string $label): array
    {
        $lines = [];
        $progress = trim((string) ($state['progress'] ?? ''));

        if ($progress !== '') {
            $lines[] = $label.' PROGRESS: '.$progress;
        }

        $configuration = self::configurationText($state['configuration'] ?? []);

        if ($configuration !== '') {
            $lines[] = $label.' CONFIGURATION: '.$configuration;
        }

        return $lines;
    }

    public static function roomLine(mixed $state): ?string
    {
        return is_string($state) ? (self::ROOM_LINES[$state] ?? null) : null;
    }

    public static function progressChanges(mixed $state): bool
    {
        $start = is_array($state) && is_array($state['start'] ?? null) ? $state['start']['progress'] ?? null : null;
        $end = is_array($state) && is_array($state['end'] ?? null) ? $state['end']['progress'] ?? null : null;

        return is_string($end) && trim($end) !== '' && trim($end) !== trim((string) $start);
    }

    public static function roomOrder(?string $state): ?int
    {
        $order = array_search($state, self::ROOM_STATES, true);

        return $order === false ? null : $order;
    }

    /**
     * @param  list<mixed>  $scenes
     * @param  list<array<string, mixed>>  $locations
     * @return list<string>
     */
    public static function roomStateViolations(array $scenes, array $locations): array
    {
        $aboard = [];

        foreach ($locations as $location) {
            if (is_array($location) && is_string($location['id'] ?? null)) {
                $aboard[$location['id']] = ($location['spatial_relation'] ?? null) === LocationProfile::SUBJECT_PART;
            }
        }

        $order = array_flip(self::ROOM_STATES);
        $last = [];
        $violations = [];

        foreach ($scenes as $index => $scene) {
            if (! is_array($scene)) {
                continue;
            }

            $where = is_string($scene['id'] ?? null) ? $scene['id'] : "scenes[{$index}]";
            $location = (string) ($scene['location_id'] ?? '');
            $room = $scene[self::ROOM_STATE_KEY] ?? null;

            if (! is_array($room) || array_diff(array_keys($room), ['start', 'end']) !== [] || ! array_key_exists('start', $room) || ! array_key_exists('end', $room)) {
                $violations[] = "{$where}.room_state: must be an object with start and end";

                continue;
            }

            if (! ($aboard[$location] ?? false)) {
                if ($room['start'] !== null || $room['end'] !== null) {
                    $violations[] = "{$where}.room_state: the scene is not aboard the subject, so start and end are null";
                }

                continue;
            }

            if (! isset($order[$room['start']]) || ! isset($order[$room['end']])) {
                $violations[] = "{$where}.room_state: a scene aboard gives start and end as one of ".implode(', ', self::ROOM_STATES);

                continue;
            }

            if ($order[$room['end']] < $order[$room['start']]) {
                $violations[] = "{$where}.room_state: {$location} goes back from {$room['start']} to {$room['end']}";
            } elseif ($room['end'] !== $room['start'] && ! self::progressChanges($scene['subject_state'] ?? null)) {
                $violations[] = "{$where}.room_state: {$location} becomes {$room['end']} but the subject's progress does not change in the scene";
            }

            if (isset($last[$location]) && $order[$room['start']] < $order[$last[$location]]) {
                $violations[] = "{$where}.room_state: {$location} was left {$last[$location]} by an earlier scene and cannot start {$room['start']}";
            }

            $last[$location] = $room['end'];
        }

        return $violations;
    }
}
