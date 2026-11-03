<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoShotPrompt
{
    public const VERSION = 'moto-shot-prompt-v4';

    /**
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @return bool whether the design's finishes and colours are how the parts look at the build state: a finished motorcycle, or drawings and models before a physical one exists
     */
    public static function finishShown(?string $state, array $context): bool
    {
        $facts = $state === null ? null : ($context['states'][$state] ?? null);

        return ! (is_array($facts) && $facts['physical']) || in_array($state, (array) ($context['finished_states'] ?? []), true);
    }

    /**
     * @param  array<string, mixed>  $row  a shot of the plan with its derived build state, environment, continuity and absent parts
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @return string the keyframe prompt: each label followed by the shot's own field or the source record it names, verbatim — the build state's surface and finishes, under the motorcycle when the shot frames it, the parts in frame with their description, faces, size, the finished surfaces of a finished motorcycle or else only the materials, and the position the shot starts in, where the work the shot shows has each part at its start, the scene's set, the plate's light with the changes handed to the shot's beats, the people with the protection the scene plan establishes; nothing is added that the shot or its sources do not say
     */
    public static function image(array $row, array $context): string
    {
        $config = (array) config('video.moto.shots.prompt.image');
        $labels = (array) $config['labels'];
        $facts = $row['state'] === null ? null : ($context['states'][$row['state']] ?? null);
        $physical = is_array($facts) && $facts['physical'];
        $finished = self::finishShown($row['state'], $context);
        $stage = $physical ? self::stage((string) $row['state'], $facts) : '';
        $start = self::positions((array) $row['configurations']['start'], $context);
        [$light, $changes] = self::changes($row, $context);
        $lines = [
            $config['style'].'.',
            self::line($labels['frame'], (string) ($context['aspect_ratio'] ?? '')),
            self::line($labels['camera'], self::camera((array) $row['camera'])),
            self::line($labels['first_frame'], (string) $row['first_frame']),
            match (true) {
                ! $physical => '',
                MotoShotPlanner::framesMotorcycle($row, (array) ($row['held_parts'] ?? [])) => self::line($labels['motorcycle'], $context['motorcycle'].' at '.$stage),
                default => self::line($labels['stage'], $stage),
            },
            ...array_map(static fn (array $item): string => self::line($labels['part'], self::part($item, $facts, $start, $context, $finished)), (array) $row['parts']),
            self::line($labels['work'], self::firstPlaces((array) ($row['work_items'] ?? []))),
            self::line($labels['absent'], implode(', ', array_column((array) $row['absent_parts'], 'name'))),
            self::line($labels['people'], self::people($row, $context)),
            self::line($labels['place'], self::place($row, $context)),
            self::line($labels['set'], (string) ($row['set_brief'] ?? '')),
            self::line($labels['light'], $light),
            self::line($labels['changes'], $changes),
            self::line($labels['continuity'], self::continuity($row)),
            $config['suffix'].'.',
        ];

        return implode("\n", array_values(array_filter($lines, static fn (string $line): bool => $line !== '')));
    }

    /**
     * @param  array<string, mixed>  $row  a shot of the plan
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @return string the clip prompt: the first frame is the keyframe, then the work the shot shows with where each part goes, the shot's action, result, timing, camera move, end frame, the positions parts end in, where the work leaves each part and what carries on, verbatim
     */
    public static function video(array $row, array $context): string
    {
        $config = (array) config('video.moto.shots.prompt.video');
        $labels = (array) $config['labels'];
        $camera = (array) $row['camera'];
        $results = array_values(array_filter((array) $row['coverage'], static fn (array $item): bool => $item['result_at_ms'] !== null));
        $timing = 'keep '.$row['used_start_ms'].'–'.((int) $row['used_start_ms'] + (int) $row['used_duration_ms']).' ms of a '.$row['render_duration_ms'].' ms clip'
            .($results === [] ? '' : '; '.implode('; ', array_map(static fn (array $item): string => 'the result of '.$item['beat'].' is visible at '.$item['result_at_ms'].' ms', $results)));
        $start = (array) $row['configurations']['start'];
        $moved = array_values(array_diff((array) $row['configurations']['end'], $start));
        $items = (array) ($row['work_items'] ?? []);
        $ends = [];

        foreach ($items as $item) {
            foreach ($item['parts'] as $place) {
                $ends[$place['part']] = $place['name'].' '.self::where($place['end']);
            }
        }

        $lines = [
            (string) $config['lead'],
            self::line($labels['work'], implode('; ', array_map(static fn (array $item): string => self::work($item).': '.implode(', ', array_map(static fn (array $place): string => $place['name'].($place['start'] === $place['end'] ? ' '.self::where($place['start']) : ' from '.self::where($place['start']).' to '.self::where($place['end'])), $item['parts'])), $items))),
            self::line($labels['action'], (string) $row['action']),
            self::line($labels['result'], (string) $row['result']),
            self::line($labels['timing'], $timing),
            self::line($labels['camera'], $camera['move'] === 'static'
                ? 'static, '.$camera['start_pose']
                : $camera['move'].' '.self::number($camera['move_value']).' '.$camera['move_unit'].', from '.$camera['start_pose'].' to '.$camera['end_pose']),
            self::line($labels['end_frame'], (string) ($row['end_frame'] ?? '')),
            self::line($labels['end_configuration'], implode('; ', array_map(static fn (array $position): string => $position['part_name'].' '.$position['name'].': '.$position['description'], self::positions($moved, $context)))),
            self::line($labels['work_end'], implode('; ', $ends)),
            self::line($labels['continuity'], implode('; ', (array) $row['continuity']['carries'])),
            $config['suffix'].'.',
        ];

        return implode("\n", array_values(array_filter($lines, static fn (string $line): bool => $line !== '')));
    }

    /**
     * @param  list<array{part: string, operation: string, parts: list<array<string, mixed>>}>  $items  the work items the shot shows, in order
     * @return string each item the shot shows, with where each part is at the first frame given at the first item that moves or keeps it; a later item on the same part names only its work, since where it starts is a moment inside the clip
     */
    private static function firstPlaces(array $items): string
    {
        $placed = [];
        $texts = [];

        foreach ($items as $item) {
            $places = [];

            foreach ($item['parts'] as $place) {
                if (! isset($placed[$place['part']])) {
                    $placed[$place['part']] = true;
                    $places[] = $place['name'].' '.self::where($place['start']);
                }
            }

            $texts[] = self::work($item).($places === [] ? '' : ': '.implode(', ', $places));
        }

        return implode('; ', $texts);
    }

    /** @param array{part: string, operation: string, parts: list<array<string, mixed>>} $item */
    private static function work(array $item): string
    {
        $words = (array) config('video.moto.shots.prompt.work.operations');
        $name = collect($item['parts'])->firstWhere('part', $item['part'])['name'] ?? $item['part'];

        return ($words[$item['operation']] ?? str_replace('_', ' ', $item['operation'])).' '.$name;
    }

    private static function where(string $location): string
    {
        return (string) (config('video.moto.shots.prompt.work.locations')[$location] ?? str_replace('_', ' ', $location));
    }

    /** @param array<string, mixed> $camera */
    private static function camera(array $camera): string
    {
        $parts = [
            str_replace('_', ' ', (string) $camera['scale']),
            self::number($camera['lens_mm']).' mm lens',
            $camera['height_m'] === null ? null : 'lens '.self::number($camera['height_m']).' m above the floor',
            $camera['distance_m'] === null ? null : self::number($camera['distance_m']).' m from the '.str_replace('_', ' ', (string) $camera['target']),
            $camera['azimuth_deg'] === null ? null : 'azimuth '.self::number($camera['azimuth_deg']).'°',
            $camera['elevation_deg'] === null ? null : 'elevation '.self::number($camera['elevation_deg']).'°',
        ];
        $text = implode(', ', array_filter($parts, 'is_string'));

        return $camera['azimuth_deg'] === null ? $text : $text.'. '.config('video.moto.model_sheet.camera_convention');
    }

    /** @param array<string, mixed> $facts */
    private static function stage(string $state, array $facts): string
    {
        $finishes = array_filter((array) ($facts['finishes'] ?? []), 'is_string');
        $text = $state.(is_string($facts['surface'] ?? null) ? ': '.rtrim($facts['surface'], '.') : '');

        return $finishes === [] ? $text : $text.'. Finishes at this stage: '.implode(', ', array_map(static fn (string $group, string $finish): string => $group.' '.str_replace('_', ' ', $finish), array_keys($finishes), $finishes));
    }

    /**
     * @param  array{part: string, presentation: string}  $item
     * @param  ?array<string, mixed>  $facts
     * @param  list<array<string, mixed>>  $start  the positions the shot starts in
     * @param  array<string, mixed>  $context
     * @param  bool  $finished  finishShown() at the shot's build state
     */
    private static function part(array $item, ?array $facts, array $start, array $context, bool $finished): string
    {
        $record = (array) ($context['part_records'][$item['part']] ?? []);
        $words = (array) config('video.moto.shots.prompt.presentations');
        $palette = (array) ($context['palette'] ?? []);
        $work = is_array($facts) ? ($facts['work'][$item['part']] ?? null) : null;
        $extent = is_array($record['extent'] ?? null) ? $record['extent'] : null;
        $position = collect($start)->firstWhere('part', $item['part']);
        $sentences = [
            ($record['name'] ?? $item['part']).' ('.($words[$item['presentation']] ?? $item['presentation']).')'.($item['presentation'] === 'being_made' && is_string($work) ? ': '.rtrim($work, '.') : ''),
            is_string($record['description'] ?? null) ? rtrim($record['description'], '.') : null,
            ...array_map(static fn (array $face): string => str_replace('_', ' ', $face['facing']).': '.rtrim($face['description'], '.'), (array) ($record['faces'] ?? [])),
            $extent === null ? null : 'about '.self::number(($extent['x_to'] - $extent['x_from']) / 1000).' × '.self::number(($extent['y_max'] - $extent['y_min']) / 1000).' × '.self::number(($extent['z_max'] - $extent['z_min']) / 1000).' m (length × width × height)',
            match (true) {
                ($record['surfaces'] ?? []) === [] => null,
                $finished => 'finished surfaces: '.implode('; ', array_map(static fn (array $surface): string => trim($surface['element'].' '.$surface['material'].', '.$surface['finish'].(is_string($surface['color_id'] ?? null) && isset($palette[$surface['color_id']]) ? ', '.$palette[$surface['color_id']]['name'].' '.$palette[$surface['color_id']]['hex'] : '')), (array) $record['surfaces'])),
                default => 'materials: '.implode('; ', array_map(static fn (array $surface): string => trim($surface['element'].' '.$surface['material']), (array) $record['surfaces'])),
            },
            $position === null ? null : 'position '.$position['name'].': '.rtrim($position['description'], '.'),
        ];

        return implode('. ', array_filter($sentences, 'is_string'));
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $context
     * @return list<array{part: string, part_name: string, name: string, description: string}>
     */
    private static function positions(array $ids, array $context): array
    {
        $positions = [];

        foreach ($ids as $id) {
            $configuration = $context['configurations'][$id] ?? null;

            if (is_array($configuration)) {
                $positions[] = ['part' => (string) $configuration['part'], 'part_name' => (string) ($context['parts'][$configuration['part']] ?? $configuration['part']), 'name' => (string) $configuration['name'], 'description' => (string) $configuration['description']];
            }
        }

        return $positions;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $context
     */
    private static function people(array $row, array $context): string
    {
        $moment = is_array($row['continuity_start']) ? $row['continuity_start'] : (is_array($row['continuity_end']) ? $row['continuity_end'] : null);
        $protection = $moment === null ? [] : array_column((array) $moment['protection'], 'items', 'character');

        return implode(' ', array_map(static function (array $person) use ($context, $protection): string {
            $items = $protection[$person['character']] ?? null;
            $worn = is_array($items) ? ($items === [] ? ' Wears no protective equipment.' : ' Wears: '.implode(', ', $items).'.') : '';

            return ucfirst($person['character']).': '.rtrim((string) ($context['characters'][$person['character']] ?? ''), '.').'.'.$worn.' Seen only as '.$person['view'].'.';
        }, (array) $row['people']));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $context
     */
    private static function place(array $row, array $context): string
    {
        $environment = (array) $row['environment'];

        if ($environment['mode'] === 'request') {
            return 'a background still to be made: '.$environment['request'];
        }

        $location = $environment['mode'] === 'background' ? ($context['backgrounds'][$environment['background']]['location'] ?? null) : ($row['places'][0] ?? null);
        $place = is_string($location) ? ($context['places'][$location] ?? null) : null;

        return is_array($place) ? $place['name'].': '.$place['space'] : '';
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $context
     * @return array{0: string, 1: string} the plate's base light with the light changes handed to the shot's beats, then every other change handed to them with what must stay
     */
    private static function changes(array $row, array $context): array
    {
        $background = $row['environment']['background'] ?? null;

        if (! is_string($background)) {
            return ['', ''];
        }

        $plate = (array) ($context['backgrounds'][$background] ?? []);
        $beats = array_column((array) $row['coverage'], 'beat');
        $mine = array_values(array_filter((array) ($plate['shot_changes'] ?? []), static fn (array $change): bool => array_intersect((array) $change['beats'], $beats) !== []));
        $light = array_values(array_filter($mine, static fn (array $change): bool => $change['kind'] === 'light'));
        $other = array_values(array_filter($mine, static fn (array $change): bool => $change['kind'] !== 'light'));
        $describe = static fn (array $change): string => rtrim($change['change'], '.').(trim((string) ($change['keep'] ?? '')) === '' ? '' : ' (keep '.rtrim($change['keep'], '.').')');

        return [
            trim(rtrim((string) ($plate['light'] ?? ''), '.').($light === [] ? '' : '; for this shot: '.implode('; ', array_map($describe, $light)))),
            implode('; ', array_map($describe, $other)),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function continuity(array $row): string
    {
        if (is_array($row['continuity_start'])) {
            $start = $row['continuity_start'];
            $items = array_filter([
                is_string($start['placement']) ? $start['placement'] : null,
                is_array($start['detached_parts']) && $start['detached_parts'] !== [] ? 'off the motorcycle: '.implode(', ', array_map(static fn (array $item): string => $item['part'].' '.str_replace('_', ' ', $item['status']), $start['detached_parts'])) : null,
                is_array($start['lights_on']) && $start['lights_on'] !== [] ? 'lights on: '.implode(', ', $start['lights_on']) : null,
                is_string($start['surface_condition']) ? 'surface '.$start['surface_condition'] : null,
                is_string($start['weather']) ? $start['weather'] : null,
            ], 'is_string');

            return implode('; ', $items);
        }

        return is_array($row['carried_from']) && $row['carried_from']['carries'] !== [] ? implode('; ', $row['carried_from']['carries']) : '';
    }

    private static function line(string $label, string $text): string
    {
        return trim($text) === '' ? '' : $label.' '.rtrim(trim($text), '.').'.';
    }

    private static function number(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
