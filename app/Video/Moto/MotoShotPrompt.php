<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Video\Superyacht\Support\CanonicalJson;

final class MotoShotPrompt
{
    public const VERSION = 'moto-shot-prompt-v5';

    public const CLIP_COMPILER = 'moto-clip-compiler-v3';

    public const KEYFRAME_VERSION = 'moto-keyframe-prompt-v1';

    /** @var list<string> the fields of the phase-10 template a clip prompt cannot go without */
    private const CLIP_REQUIRED = ['duration_seconds', 'camera', 'action', 'result'];

    /** @var list<string> the fields of the phase-10 template whose line drops out when the shot has nothing to say */
    private const CLIP_OPTIONAL = ['start_state', 'work', 'timing', 'end_frame', 'end_configuration', 'work_end', 'end_state'];

    /**
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @return bool whether the build state is a finished motorcycle, or a stage before a physical one exists
     */
    public static function finishShown(?string $state, array $context): bool
    {
        $facts = $state === null ? null : ($context['states'][$state] ?? null);

        return ! (is_array($facts) && $facts['physical']) || in_array($state, (array) ($context['finished_states'] ?? []), true);
    }

    /**
     * @param  array{part: string, presentation: string}  $item  a part in frame
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @return list<array{element: string, status: string, text: string}> every surface of the part at the shot's first frame, from its build state, how it is shown and what is true at that frame, never what the shot goes on to do: none for a drawing, the design's model stage for a model, and needs_check where the sources give no finish
     */
    public static function surfaces(array $item, ?string $state, string $firstFrame, array $context): array
    {
        $record = (array) ($context['part_records'][$item['part']] ?? []);
        $records = (array) ($context['state_records'] ?? []);
        $at = match ($item['presentation']) {
            'drawing' => null,
            'model' => collect($records)->firstWhere('kind', 'model') ?? ['id' => (string) $state, 'kind' => 'model'],
            default => $state === null ? null : ($records[$state] ?? null),
        };

        if ($record === [] || ! is_array($at)) {
            return [];
        }

        $being = $item['presentation'] === 'being_made' || collect((array) ($at['in_progress'] ?? []))->contains('part', $item['part']);
        $reference = (array) config('video.moto.reference');

        return MotoReferenceSurface::components($record, $at, (array) ($context['palette'] ?? []), $being, array_values(array_filter([
            is_string($at['surface'] ?? null) ? ['path' => "design.build_states.{$at['id']}.surface", 'text' => $at['surface']] : null,
            ['path' => 'shot.first_frame', 'text' => $firstFrame],
        ])), (array) $reference['surface_groups'], (array) $reference['finish_labels'], (string) ($context['surface_rule'] ?? MotoReferenceSurface::RULE_ANY_MATERIAL));
    }

    /**
     * @param  array<string, mixed>  $row  a shot of the plan with its derived build state, environment, continuity and absent parts
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @return string the keyframe prompt: each label followed by the shot's own field or the source record it names, verbatim — the build state's surface and finishes, under the motorcycle when the shot frames it, the parts in frame with their description, faces, size, surfaces at the first frame and the position the shot starts in, the parts out of frame with their effect, where the work the shot shows has each part at its start, the scene's set, the plate's light with the changes handed to the shot's beats, the people with the protection the scene plan establishes; nothing is added that the shot or its sources do not say
     */
    public static function image(array $row, array $context): string
    {
        $config = (array) config('video.moto.shots.prompt.image');
        $labels = (array) $config['labels'];
        $facts = $row['state'] === null ? null : ($context['states'][$row['state']] ?? null);
        $physical = is_array($facts) && $facts['physical'];
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
            ...array_map(static fn (array $item): string => self::line($labels['part'], self::part($item, $facts, $start, $context, $row)), (array) $row['parts']),
            self::line($labels['off_frame'], implode('; ', array_map(static fn (array $item): string => ($context['parts'][$item['part']] ?? $item['part']).': '.rtrim((string) $item['effect'], '.'), (array) ($row['off_frame'] ?? [])))),
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
     * @return array{text: string, version: string, surface_rule: string, sha256: string} the prompt a keyframe is sent with, to pin in its request: image() compiled under the current surface rule whatever rule the run was pinned with, so a plan approved under an older rule needs no new run of the model for words code put together. The prompts stored in the plan stay as they were approved
     */
    public static function keyframe(array $row, array $context): array
    {
        $text = self::image($row, ['surface_rule' => MotoReferenceSurface::RULE_MATERIAL] + $context);

        return ['text' => $text, 'version' => self::KEYFRAME_VERSION, 'surface_rule' => MotoReferenceSurface::RULE_MATERIAL, 'sha256' => CanonicalJson::hashString($text)];
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
     * @param  array<string, mixed>  $row  a shot of the plan
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @param  string  $template  the text of the current phase-10 revision, as the resolver gave it
     * @return string the prompt sent with the shot's approved first frame: the template with each field filled from the shot, and a line dropped when its optional field is empty. It names no internal id and no edit instruction, since Phase 11 does the cutting. Every work item keeps its work: an item that moves a part says where it goes, and one that moves none says once where its parts stay. video() stays the planning prompt stored in the approved plan
     *
     * @throws \InvalidArgumentException when the template breaks the field contract or the shot lacks a field it cannot go without
     */
    public static function clip(array $row, array $context, string $template): string
    {
        $problem = self::clipTemplateProblem($template);

        if ($problem !== null) {
            throw new \InvalidArgumentException($problem);
        }

        $missing = self::clipMissing($row, $context);

        if ($missing !== []) {
            throw new \InvalidArgumentException('Shot '.($row['id'] ?? '?').' thiếu trường bắt buộc của prompt clip: '.implode(', ', $missing).'.');
        }

        $map = [];

        foreach (self::clipFields($row, $context) as $field => $value) {
            $map['{'.$field.'}'] = rtrim(trim($value), '.');
        }

        $lines = [];

        foreach (preg_split('/\R/', $template) ?: [] as $line) {
            preg_match_all('/\{([a-z_]+)\}/', $line, $slots);

            if (array_filter($slots[0], static fn (string $slot): bool => $map[$slot] === '') === []) {
                $lines[] = strtr($line, $map);
            }
        }

        $prompt = implode("\n", array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== '')));

        foreach (self::CLIP_REQUIRED as $field) {
            if (! str_contains($prompt, $map['{'.$field.'}'])) {
                throw new \InvalidArgumentException('Prompt clip của shot '.($row['id'] ?? '?').' mất trường bắt buộc '.$field.' khi biên dịch.');
            }
        }

        return $prompt;
    }

    /** @return ?string why the template cannot be compiled: a field outside the contract, a required field it does not use, a required field sharing a line with an optional one, which would drop with it, or a stray brace. The text is only ever filled in, never run */
    public static function clipTemplateProblem(string $template): ?string
    {
        preg_match_all('/\{([^{}]*)\}/', $template, $found);
        $unknown = array_values(array_diff($found[1], self::CLIP_REQUIRED, self::CLIP_OPTIONAL));

        if ($unknown !== []) {
            return 'Mẫu prompt clip có trường không được hỗ trợ: '.implode(', ', $unknown).'.';
        }

        $absent = array_values(array_diff(self::CLIP_REQUIRED, $found[1]));

        if ($absent !== []) {
            return 'Mẫu prompt clip thiếu trường bắt buộc: '.implode(', ', $absent).'.';
        }

        foreach (preg_split('/\R/', $template) ?: [] as $line) {
            preg_match_all('/\{([a-z_]+)\}/', $line, $slots);

            if (array_intersect($slots[1], self::CLIP_REQUIRED) !== [] && array_intersect($slots[1], self::CLIP_OPTIONAL) !== []) {
                return 'Mẫu prompt clip đặt trường bắt buộc cùng dòng với trường tùy chọn ('.trim($line).') — dòng đó sẽ mất khi trường tùy chọn rỗng.';
            }
        }

        return preg_match('/[{}]/', (string) preg_replace('/\{[^{}]*\}/', '', $template)) === 1
            ? 'Mẫu prompt clip có dấu ngoặc nhọn lẻ.'
            : null;
    }

    /**
     * @param  array<string, mixed>  $row  a shot of the plan
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @return list<string> the required fields the shot leaves empty
     */
    public static function clipMissing(array $row, array $context): array
    {
        $fields = self::clipFields($row, $context);

        return array_values(array_filter(self::CLIP_REQUIRED, static fn (string $field): bool => trim($fields[$field]) === ''));
    }

    /**
     * @param  array<string, mixed>  $row  a shot of the plan
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @return array<string, string> what each field of the template says for the shot
     */
    private static function clipFields(array $row, array $context): array
    {
        $camera = (array) ($row['camera'] ?? []);
        $configurations = (array) ($row['configurations'] ?? []);
        $moved = array_values(array_diff((array) ($configurations['end'] ?? []), (array) ($configurations['start'] ?? [])));
        $work = [];
        $ends = [];

        foreach ((array) ($row['work_items'] ?? []) as $item) {
            $places = array_values(array_filter((array) $item['parts'], static fn (array $place): bool => $place['start'] !== $place['end']));
            $work[] = self::work($item).': '.implode(', ', $places === []
                ? array_map(static fn (array $place): string => $place['name'].' '.self::where($place['start']), (array) $item['parts'])
                : array_map(static fn (array $place): string => $place['name'].' from '.self::where($place['start']).' to '.self::where($place['end']), $places));

            foreach ($places as $place) {
                $ends[$place['part']] = $place['name'].' '.self::where($place['end']);
            }
        }

        $length = $row['render_duration_ms'] ?? null;
        $move = (string) ($camera['move'] ?? '');

        // A pose is a phrase with commas of its own, so the parts of the move are kept apart with labels rather than "from … to …".
        return [
            'duration_seconds' => is_int($length) && $length > 0 ? self::number($length / 1000) : '',
            'start_state' => (string) ($row['continuity_start']['placement'] ?? ''),
            'camera' => match ($move) {
                '' => '',
                'static' => 'static; position: '.rtrim((string) ($camera['start_pose'] ?? ''), '.'),
                default => $move.' '.self::number($camera['move_value'] ?? 0).' '.($camera['move_unit'] ?? '').'; start: '.rtrim((string) ($camera['start_pose'] ?? ''), '.').'; end: '.rtrim((string) ($camera['end_pose'] ?? ''), '.'),
            },
            'work' => implode('; ', $work),
            'action' => (string) ($row['action'] ?? ''),
            'result' => (string) ($row['result'] ?? ''),
            'timing' => self::resultMoments((array) ($row['coverage'] ?? [])),
            'end_frame' => (string) ($row['end_frame'] ?? ''),
            'end_configuration' => implode('; ', array_map(static fn (array $position): string => $position['part_name'].' '.$position['name'].': '.$position['description'], self::positions($moved, $context))),
            'work_end' => implode('; ', $ends),
            // The end state is the shot's own, from continuity_end. continuity.carries is the note for the cut to the next shot, so it never reaches a video model.
            'end_state' => (string) ($row['continuity_end']['placement'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $row  a shot of the plan
     * @return ?string why the timing of the shot cannot be told to a model: result_at_ms is clip time, so a moment outside the rendered clip or the part the film keeps means the source does not say when it shows, and one result text cannot be tied to several moments without guessing
     */
    public static function clipProblem(array $row): ?string
    {
        $length = $row['render_duration_ms'] ?? null;
        $shot = 'Shot '.($row['id'] ?? '?');

        if (! is_int($length) || $length <= 0) {
            return $shot.' không có thời lượng render hợp lệ.';
        }

        $start = is_int($row['used_start_ms'] ?? null) ? $row['used_start_ms'] : 0;
        $used = is_int($row['used_duration_ms'] ?? null) ? $row['used_duration_ms'] : $length;

        if ($start < 0 || $used <= 0 || $start + $used > $length) {
            return $shot.' giữ đoạn '.$start.'+'.$used.' ms ngoài clip render '.$length.' ms.';
        }

        $moments = [];

        foreach ((array) ($row['coverage'] ?? []) as $item) {
            if (! is_array($item) || ! array_key_exists('result_at_ms', $item)) {
                return $shot.' thiếu mốc kết quả trong nguồn.';
            }

            $at = $item['result_at_ms'];

            if ($at !== null && (! is_int($at) || $at < 0 || $at > $length)) {
                return $shot.' có mốc kết quả ngoài clip render '.$length.' ms.';
            }

            if ($at !== null && ($at < $start || $at > $start + $used)) {
                return $shot.' có mốc kết quả '.$at.' ms ngoài đoạn phim giữ lại.';
            }

            if ($at !== null) {
                $moments[$at] = true;
            }
        }

        return count($moments) > 1
            ? $shot.' có '.count($moments).' mốc kết quả nhưng chỉ một câu kết quả — nguồn chưa nối được kết quả với thời điểm.'
            : null;
    }

    /**
     * @param  list<array<string, mixed>>  $coverage  the coverage of a shot, with result_at_ms in clip time
     * @return string when the result is visible, in seconds from the start of the rendered clip
     */
    private static function resultMoments(array $coverage): string
    {
        foreach ($coverage as $item) {
            if (is_array($item) && ($item['result_at_ms'] ?? null) !== null) {
                return 'the result is visible '.self::number($item['result_at_ms'] / 1000).' s into the clip';
            }
        }

        return '';
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
     * @param  array<string, mixed>  $row  the shot the part stands in
     */
    private static function part(array $item, ?array $facts, array $start, array $context, array $row): string
    {
        $record = (array) ($context['part_records'][$item['part']] ?? []);
        $words = (array) config('video.moto.shots.prompt.presentations');
        $work = is_array($facts) ? ($facts['work'][$item['part']] ?? null) : null;
        $extent = is_array($record['extent'] ?? null) ? $record['extent'] : null;
        $position = collect($start)->firstWhere('part', $item['part']);
        $surfaces = self::surfaces($item, is_string($row['state'] ?? null) ? $row['state'] : null, (string) $row['first_frame'], $context);
        $digital = $surfaces !== [] && array_diff(array_column($surfaces, 'status'), [MotoReferenceSurface::DIGITAL]) === [];
        $sentences = [
            ($record['name'] ?? $item['part']).' ('.($digital ? (string) ($words['digital_model'] ?? 'as a digital model') : ($words[$item['presentation']] ?? $item['presentation'])).')'.($item['presentation'] === 'being_made' && is_string($work) ? ': '.rtrim($work, '.') : ''),
            is_string($record['description'] ?? null) ? rtrim($record['description'], '.') : null,
            ...array_map(static fn (array $face): string => str_replace('_', ' ', $face['facing']).': '.rtrim($face['description'], '.'), (array) ($record['faces'] ?? [])),
            $extent === null ? null : 'about '.self::number(($extent['x_to'] - $extent['x_from']) / 1000).' × '.self::number(($extent['y_max'] - $extent['y_min']) / 1000).' × '.self::number(($extent['z_max'] - $extent['z_min']) / 1000).' m (length × width × height)',
            $surfaces === [] ? null : 'surfaces: '.implode('; ', array_column($surfaces, 'text')),
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
        $views = (array) config('video.moto.shots.prompt.people_views');

        return implode(' ', array_map(static function (array $person) use ($context, $protection, $views): string {
            $items = $protection[$person['character']] ?? null;
            $worn = is_array($items) ? ($items === [] ? ' Wears no protective equipment.' : ' Wears: '.implode(', ', $items).'.') : '';

            return ucfirst($person['character']).': '.rtrim((string) ($context['characters'][$person['character']] ?? ''), '.').'.'.$worn.' '.($views[$person['view']] ?? 'Seen only as '.$person['view'].'.');
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
