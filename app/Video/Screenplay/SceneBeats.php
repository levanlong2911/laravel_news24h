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

    public const BEAT_ID_PATTERN = '^b[0-9]{1,2}$';

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
}
