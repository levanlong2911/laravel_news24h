<?php

declare(strict_types=1);

namespace App\Video\Scene;

use App\Video\Screenplay\SceneBeats;

final class FilmCutPlan
{
    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $preset
     * @param  list<string>  $arcStages
     * @return array<string, mixed>
     *
     * @throws ScenePlanException
     */
    public static function fromScreenplay(array $screenplay, array $preset, array $arcStages, ?string $finishedFrom): array
    {
        $count = (int) ($preset['segment_count'] ?? 0);
        $segmentMs = (int) ($preset['segment_ms'] ?? 0);
        $maxScenes = (int) ($preset['max_scenes'] ?? 0);

        if ($count < 1 || $segmentMs < 1 || $maxScenes < 1 || $count * $segmentMs !== (int) ($preset['total_ms'] ?? 0)) {
            throw new ScenePlanException('film_cut preset is inconsistent: segment count, segment length, total length and scene limit do not agree.');
        }

        $scenes = array_values(array_filter((array) ($screenplay['scenes'] ?? []), 'is_array'));

        if ($scenes === []) {
            throw new ScenePlanException('The screenplay has no scene.');
        }

        if (count($scenes) > $maxScenes) {
            throw new ScenePlanException('The screenplay has '.count($scenes).' scenes; the 30-second film takes at most '.$maxScenes.'.');
        }

        $names = [];

        foreach ((array) ($screenplay['locations'] ?? []) as $location) {
            if (is_array($location) && is_string($location['id'] ?? null)) {
                $names[$location['id']] = trim((string) ($location['name'] ?? $location['id']));
            }
        }

        $finishedIndex = $finishedFrom === null ? false : array_search($finishedFrom, $arcStages, true);
        $built = [];
        $totals = array_fill(1, $count, 0);
        $current = 1;
        $offset = 0;

        foreach ($scenes as $position => $scene) {
            $id = is_string($scene['id'] ?? null) ? $scene['id'] : 'scene '.($position + 1);
            $segment = $scene['film_segment'] ?? null;
            $duration = $scene['duration_estimate_ms'] ?? null;
            $camera = is_string($scene['camera'] ?? null) ? trim($scene['camera']) : '';

            if (! is_int($segment) || $segment < 1 || $segment > $count) {
                throw new ScenePlanException($id.': has no film segment from 1 to '.$count.'; the screenplay was not written for the 30-second film.');
            }

            if ($segment < $current || $segment > $current + 1) {
                throw new ScenePlanException($id.': goes from segment '.$current.' to segment '.$segment.'; segments follow the scene order.');
            }

            if (! is_int($duration) || $duration < 1) {
                throw new ScenePlanException($id.': has no positive duration.');
            }

            if ($camera === '') {
                throw new ScenePlanException($id.': has no camera.');
            }

            if ($segment !== $current) {
                $current = $segment;
                $offset = 0;
            }

            $beats = array_values(array_filter((array) ($scene['beats'] ?? []), 'is_array'));
            $subject = is_array($scene['subject_state'] ?? null) ? $scene['subject_state'] : null;
            $room = is_array($scene[SceneBeats::ROOM_STATE_KEY] ?? null) ? $scene[SceneBeats::ROOM_STATE_KEY] : null;
            $stage = (string) ($scene['stage'] ?? '');
            $stageIndex = array_search($stage, $arcStages, true);
            $locationId = (string) ($scene['location_id'] ?? '');
            $roomState = [
                'start' => is_string($room['start'] ?? null) ? $room['start'] : null,
                'end' => is_string($room['end'] ?? null) ? $room['end'] : null,
            ];
            $why = trim((string) ($scene['why_it_cannot_be_cut'] ?? ''));

            $built[] = [
                'order' => $position + 1,
                'segment' => $segment,
                'start_ms' => $offset,
                'end_ms' => $offset + $duration,
                'content_group' => $stage,
                'scene_id' => $id,
                'beat_ids' => array_values(array_filter(array_column($beats, 'id'), 'is_string')),
                'location_id' => $locationId,
                'location_name' => $names[$locationId] ?? $locationId,
                'source' => [
                    'stage' => $stage,
                    'action' => trim((string) ($scene['action'] ?? '')),
                    'visible_result' => $beats === [] ? '' : trim((string) ($beats[count($beats) - 1]['visible_result'] ?? '')),
                    'beat_position' => null,
                    'progress' => [
                        'start' => is_string($subject['start']['progress'] ?? null) ? trim($subject['start']['progress']) : null,
                        'end' => is_string($subject['end']['progress'] ?? null) ? trim($subject['end']['progress']) : null,
                    ],
                    'room_state' => $roomState,
                    'state_confirmed' => true,
                    'finished' => $finishedIndex !== false && $stageIndex !== false && $stageIndex >= $finishedIndex,
                    'character_ids' => array_values(array_filter((array) ($scene['character_ids'] ?? []), 'is_string')),
                ],
                'frame' => [
                    'progress' => $subject === null ? null : (is_string($subject['start']['progress'] ?? null) ? trim($subject['start']['progress']) : null),
                    'configuration' => $subject === null ? [] : array_values(array_filter((array) ($subject['start']['configuration'] ?? []), 'is_array')),
                    'room_state' => $roomState['start'],
                ],
                'focus' => $why,
                'purpose' => $why,
                'keyframe' => $beats === [] ? '' : trim((string) ($beats[0]['visible_result'] ?? '')),
                'visual' => '',
                'camera' => $camera,
                'leads_to' => is_string($scene['leads_to'] ?? null) ? trim($scene['leads_to']) : null,
            ];

            $offset += $duration;
            $totals[$segment] += $duration;
        }

        if ($current !== $count) {
            throw new ScenePlanException('The screenplay fills '.$current.' segment(s); the film has '.$count.'.');
        }

        foreach ($totals as $segment => $total) {
            if ($total !== $segmentMs) {
                throw new ScenePlanException('Segment '.$segment.' lasts '.$total.' ms; each segment lasts exactly '.$segmentMs.' ms.');
            }
        }

        $junctions = [];

        foreach (self::segments($built) as $index => $shots) {
            if ($index < $count - 1) {
                $last = $shots[count($shots) - 1];
                $junctions[] = ['after_shot' => (int) $last['order'], 'link' => (string) ($last['leads_to'] ?? '')];
            }
        }

        return [
            'shape' => FilmCutAuthor::SHAPE,
            'sub_shots' => $built,
            'junctions' => $junctions,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $subShots
     * @return list<list<array<string, mixed>>>
     */
    public static function segments(array $subShots): array
    {
        $segments = [];

        foreach ($subShots as $shot) {
            $segments[(int) $shot['segment'] - 1][] = $shot;
        }

        ksort($segments);

        return array_values($segments);
    }
}
