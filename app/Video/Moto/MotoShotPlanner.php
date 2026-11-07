<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Video\Superyacht\Support\CanonicalJson;

final class MotoShotPlanner
{
    public const VERSION = 'moto-shots-plan-v5';

    /** @var int the width in degrees of one angle group of a fingerprint */
    private const ANGLE_GROUP = 30;

    /**
     * @param  array<string, mixed>  $document  a moto Phase 7 document that passed its schema
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @return array{version: string, timing?: string, total_ms: int, shots: list<array<string, mixed>>, conflicts: list<array<string, mixed>>, finished_states: list<string>} the timing the run pinned, when it pinned one, and every shot with the screen time MotoShotsCheck::timing() gives it and each coverage part, its film timing, the clip and film span of each coverage part, its build state, environment, incoming transition, scene continuity, the work items it shows, where the work holds each part at its first frame, absent parts, limits, cards, fingerprint and the image and video prompts code composes from it
     */
    public static function plan(array $document, array $context): array
    {
        $data = (array) $document['data'];
        $scenes = array_column((array) $context['scenes'], null, 'id');
        $beats = array_column((array) $context['beats'], null, 'id');
        $written = array_values((array) $data['shots']);
        $pinned = $context['timing'] ?? null;
        $timing = MotoShotsCheck::timing($written, $beats, $pinned)['shots'];
        $shots = array_map(static fn (array $shot): array => array_replace($shot, [
            'used_duration_ms' => $timing[$shot['id']]['used_duration_ms'],
            'coverage' => array_map(static fn (array $item, int $length): array => array_replace($item, ['used_duration_ms' => $length]), array_values((array) $shot['coverage']), $timing[$shot['id']]['coverage']),
        ]), $written);
        $placed = is_array($context['work'] ?? null) ? MotoWork::shotPlaces(MotoShotsCheck::workShots($shots), $context['work'])['shots'] : [];
        $rows = [];
        $cursor = 0;

        foreach ($shots as $index => $shot) {
            $scene = $scenes[$shot['scene']] ?? null;
            $state = is_array($scene) ? $scene['state'] : null;
            $first = $index === 0 || $shots[$index - 1]['scene'] !== $shot['scene'];
            $last = $index === count($shots) - 1 || $shots[$index + 1]['scene'] !== $shot['scene'];
            $previous = $first ? null : $shots[$index - 1];
            $at = (int) $shot['used_start_ms'];
            $film = $cursor;
            $coverage = [];

            foreach ((array) $shot['coverage'] as $item) {
                $length = (int) $item['used_duration_ms'];
                $coverage[] = $item + ['clip_from_ms' => $at, 'clip_to_ms' => $at + $length, 'film_from_ms' => $film, 'film_to_ms' => $film + $length];
                $at += $length;
                $film += $length;
            }

            $covered = array_values(array_filter(array_map(static fn (array $item): ?array => $beats[$item['beat']] ?? null, (array) $shot['coverage'])));
            $row = $shot + [
                'tl_in_ms' => $cursor,
                'tl_out_ms' => $cursor + (int) $shot['used_duration_ms'],
                'coverage_spans' => $coverage,
                'parts_named' => array_map(static fn (array $item): array => $item + ['name' => $context['parts'][$item['part']] ?? null], (array) $shot['parts']),
                'state' => $state,
                'places' => is_array($scene) ? array_values((array) $scene['places']) : [],
                'set_brief' => is_array($scene) ? ($scene['set_brief'] ?? null) : null,
                'transition_in' => $first
                    ? ($scene['transition_in'] ?? null)
                    : ['kind' => $shot['links']['match_from'] !== null ? 'match_cut' : 'cut', 'from' => $shot['links']['match_from'] ?? $previous['id']],
                'continuity_start' => $first ? ($scene['continuity_start'] ?? null) : null,
                'continuity_end' => $last ? ($scene['continuity_end'] ?? null) : null,
                'carried_from' => $previous === null ? null : ['shot' => $previous['id'], 'carries' => array_values((array) $previous['continuity']['carries'])],
                'work_items' => self::workItems($shot, $context),
                'held_parts' => $placed[$shot['id']] ?? [],
                'absent_parts' => self::absent($shot, $state, $placed[$shot['id']] ?? [], $context),
                'limits' => self::limits($covered),
                'cards' => self::cards($cursor, $cursor + (int) $shot['used_duration_ms'], $beats),
                'fingerprint' => self::fingerprint($shot, $state),
            ];
            $image = MotoShotPrompt::image($row, $context);
            $video = MotoShotPrompt::video($row, $context);
            $rows[] = $row + [
                'prompts' => [
                    'composer' => MotoShotPrompt::VERSION,
                    'image' => $image,
                    'image_sha256' => CanonicalJson::hashString($image),
                    'video' => $video,
                    'video_sha256' => CanonicalJson::hashString($video),
                ],
            ];
            $cursor += (int) $shot['used_duration_ms'];
        }

        return [
            'version' => self::VERSION,
        ] + ($pinned === null ? [] : ['timing' => $pinned]) + [
            'total_ms' => (int) $context['total_ms'],
            'shots' => $rows,
            'conflicts' => array_values((array) $data['conflicts']),
            'finished_states' => array_values((array) ($context['finished_states'] ?? [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $shot
     * @return string what makes two shots the same angle: build state, environment, target, angle group, scale and camera move
     */
    public static function fingerprint(array $shot, ?string $state): string
    {
        $camera = (array) $shot['camera'];
        $environment = (array) $shot['environment'];
        $angle = is_numeric($camera['azimuth_deg'] ?? null) ? (string) intdiv((int) floor((float) $camera['azimuth_deg']), self::ANGLE_GROUP) : '-';

        return implode('|', [$state ?? '-', $environment['background'] ?? $environment['mode'], $camera['target'], $camera['target_part'] ?? '-', $angle, $camera['scale'], $camera['move']]);
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  array<string, mixed>  $context
     * @return list<array{id: string, beat: string, part: string, operation: string, parts: list<array{part: string, name: string, start: string, end: string, start_status: string, end_status: string}>}> the work items of the scene plan the shot shows, with every part each moves or keeps in place and where it is before and after
     */
    private static function workItems(array $shot, array $context): array
    {
        $items = [];

        foreach ((array) ($shot['work'] ?? []) as $key) {
            $record = $context['work']['items'][$key] ?? null;

            if (is_array($record)) {
                $items[] = [
                    'id' => (string) $key,
                    'beat' => $record['beat'],
                    'part' => $record['part'],
                    'operation' => $record['operation'],
                    'parts' => array_map(static fn (string $part, array $place): array => ['part' => $part, 'name' => (string) ($context['parts'][$part] ?? $part)] + $place, array_keys($record['parts']), array_values($record['parts'])),
                ];
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  array<string, array{location: string}>  $held  where the work holds each part at the shot's first frame
     * @return bool whether the shot frames the motorcycle: it aims at the motorcycle or shows a part on it, by where the work holds the part, otherwise by its presentation
     */
    public static function framesMotorcycle(array $shot, array $held): bool
    {
        if ($shot['camera']['target'] === 'motorcycle') {
            return true;
        }

        foreach ((array) $shot['parts'] as $item) {
            if (isset($held[$item['part']]) ? $held[$item['part']]['location'] === MotoWork::ON : $item['presentation'] === 'installed') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  array<string, array{location: string, status: string}>  $held  where the work holds each part at the shot's first frame
     * @param  array<string, mixed>  $context
     * @return list<array{part: string, name: string}> when the shot frames the motorcycle, the parts of the design off it at the shot's first frame and not shown in the shot otherwise: held off by the work, or, where the work holds no place, not on the motorcycle at the build state
     */
    private static function absent(array $shot, ?string $state, array $held, array $context): array
    {
        $facts = $state === null ? null : ($context['states'][$state] ?? null);

        if (! is_array($facts) || ! $facts['physical'] || ! self::framesMotorcycle($shot, $held)) {
            return [];
        }

        $shown = array_column((array) $shot['parts'], 'part');
        $absent = [];

        foreach ((array) $context['parts'] as $part => $name) {
            $place = $held[$part]['location'] ?? null;
            $off = $place === null ? ! in_array($part, (array) $facts['present'], true) : $place === MotoWork::OFF;

            if ($off && ! in_array($part, $shown, true)) {
                $absent[] = ['part' => (string) $part, 'name' => (string) $name];
            }
        }

        return $absent;
    }

    /**
     * @param  list<array<string, mixed>>  $covered
     * @return list<array<string, mixed>> the operational limits of the beats the shot covers, once each
     */
    private static function limits(array $covered): array
    {
        $limits = [];

        foreach ($covered as $beat) {
            foreach ((array) $beat['limits'] as $limit) {
                $limits[$limit['source']] = $limit;
            }
        }

        return array_values($limits);
    }

    /**
     * @param  array<string, array<string, mixed>>  $beats
     * @return list<array{type: string, text: string, from_ms: int, to_ms: int}> every script card on screen during the shot, with its span in shot time; a card may run across a cut
     */
    private static function cards(int $in, int $out, array $beats): array
    {
        $cards = [];

        foreach ($beats as $beat) {
            foreach ((array) $beat['cards'] as $card) {
                $start = (int) $beat['start_ms'] + (int) $card['offset_ms'];
                $end = $start + (int) $card['duration_ms'];

                if ($start < $out && $end > $in) {
                    $cards[] = ['type' => (string) $card['type'], 'text' => (string) $card['text'], 'from_ms' => max($start, $in) - $in, 'to_ms' => min($end, $out) - $in];
                }
            }
        }

        return $cards;
    }
}
