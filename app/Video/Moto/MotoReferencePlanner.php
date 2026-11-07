<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Services\Video\OpenAiImageClient;
use App\Video\ModelSheet\ModelSheetMaster;
use App\Video\Superyacht\Support\CanonicalJson;
use RuntimeException;

final class MotoReferencePlanner
{
    public const VERSION = 'moto-reference-plan-v2';

    public const SCHEMA = 'MotoReferencePlan@1.1.0';

    public const PROMPT_VERSION = 'moto-reference-master-prompt-v3';

    public const PART_VERSION = 'moto-reference-plan-v3';

    public const PART_SCHEMA = 'MotoReferencePlan@2.0.0';

    public const PART_PROMPT_VERSION = 'moto-reference-part-prompt-v2';

    /** @var list<string> the sections of a cell in the order the prompt gives them */
    public const SECTIONS = ['must_show', 'moment', 'framing', 'shape', 'surface', 'exclude'];

    /** @var list<string> the camera targets whose angles are angles around the motorcycle */
    private const AROUND_MOTORCYCLE = ['part', 'motorcycle'];

    /**
     * @param  list<array<string, mixed>>  $shots  the shots of the derived shot plan
     * @param  array<string, mixed>  $design  the data of the design the model sheet and the shots pin
     * @param  list<string>  $finished  the finished build states the model sheet shows
     * @param  array<string, array{artifact_id: string, camera: array<string, mixed>, visible: list<string>, partial: list<string>, uncertain: list<string>}>  $views  every model sheet view with an approved crop
     * @param  array<string, mixed>  $settings  video.moto.reference
     * @param  array<string, mixed>  $manifest  the pinned sources the plan is built from
     * @return array{version: string, schema: string, needs: list<array<string, mixed>>, references: list<array<string, mixed>>, bindings: list<array<string, mixed>>, dependencies: list<array<string, mixed>>, source_manifest: array<string, mixed>} every need of the shots, one cell for every part a design need names, in natural order of the part ids, and how each need is bound
     *
     * @throws RuntimeException when a shot names a part or build state the design does not have
     */
    public static function partPlan(array $shots, array $design, array $finished, array $views, array $settings, array $manifest): array
    {
        $parts = array_column((array) ($design['parts'] ?? []), null, 'id');
        $states = array_column((array) ($design['build_states'] ?? []), null, 'id');
        $order = self::workOrder($shots);
        $work = self::workWords($shots);
        $needs = [];
        $bindings = [];
        $usage = [];

        foreach ($shots as $shot) {
            $shotId = (string) $shot['id'];

            foreach (array_values((array) ($shot['reference_needs'] ?? [])) as $index => $need) {
                $needId = $shotId.'#'.($index + 1);
                $target = is_string($need['target'] ?? null) ? $need['target'] : null;
                $kind = (string) $need['kind'];

                if (in_array($kind, ['design', 'identity'], true) && ! isset($states[(string) ($shot['state'] ?? '')])) {
                    throw new RuntimeException("{$shotId}: build state ".($shot['state'] ?? 'null').' không có trong thiết kế.');
                }

                $record = [
                    'id' => $needId,
                    'shot_id' => $shotId,
                    'index' => $index,
                    'kind' => $kind,
                    'target' => $target,
                    'purpose' => (string) ($need['purpose'] ?? ''),
                    'state' => is_string($shot['state'] ?? null) ? $shot['state'] : null,
                    'placement' => $target === null ? null : self::placement($shot, $target),
                    'checkpoint' => $target === null ? null : self::checkpoint($shot, $target, $order, $work),
                    'camera' => array_intersect_key((array) $shot['camera'], array_flip(['target', 'target_part', 'scale', 'lens_mm', 'azimuth_deg', 'elevation_deg'])),
                ];
                $needs[] = $record;

                if ($kind === 'environment') {
                    $bindings[] = ['need_id' => $needId, 'type' => 'environment', 'source' => $target, 'rule' => $target === null ? 'requested_background' : 'plate'];

                    continue;
                }

                if ($kind === 'continuity') {
                    $bindings[] = ['need_id' => $needId, 'type' => 'continuity', 'source' => $target, 'rule' => 'previous_shot'];

                    continue;
                }

                if ($kind === 'identity') {
                    $bindings[] = self::wholeIdentity($shot, $needId, $finished, $views);

                    continue;
                }

                if ($target === null || ! isset($parts[$target])) {
                    throw new RuntimeException("{$needId}: part ".($target ?? 'null').' không có trong thiết kế đã duyệt.');
                }

                $usage[$target]['needs'][] = $needId;
                $usage[$target]['shots'][] = $shotId;
                $usage[$target]['purposes'][] = $record['purpose'];
                $usage[$target]['cameras'][] = (array) $shot['camera'];
                $bindings[] = ['need_id' => $needId, 'type' => 'reference', 'source' => self::partCellId($target), 'rule' => self::finishedInShot($shot, $record, $finished) ? 'part_finished' : 'part_geometry'];
            }
        }

        uksort($usage, 'strnatcmp');
        $references = [];

        foreach ($usage as $id => $use) {
            $references[] = self::partReference((string) $id, $use, $design, $parts, $views, $settings, $manifest);
        }

        return [
            'version' => self::PART_VERSION,
            'schema' => self::PART_SCHEMA,
            'needs' => $needs,
            'references' => $references,
            'bindings' => $bindings,
            'dependencies' => self::dependencies($references, $manifest),
            'source_manifest' => $manifest,
        ];
    }

    /**
     * @param  list<string>  $ids  the cells to render, in cell order
     * @param  array{rows: int, columns: int, size: string}  $grid  what a person chose
     * @return array{id: string, regime: string, medium: string, cell_ids: list<string>, shortage: int, layout: array<string, mixed>, layout_sha256: string} the master of the cells on the chosen grid, the cells left over stay empty and a grid too small for them keeps how many cells it lacks; one cell is one picture with no grid
     */
    public static function partSheet(array $ids, array $grid): array
    {
        $single = count($ids) === 1;
        $rows = $single ? 1 : (int) $grid['rows'];
        $columns = $single ? 1 : (int) $grid['columns'];
        $capacity = $rows * $columns;
        $order = array_slice($ids, 0, $capacity);

        for ($index = count($order); $index < $capacity; $index++) {
            $order[] = 'EMPTY'.($index + 1);
        }

        return self::partSheetOn($ids, ['size' => (string) $grid['size'], 'columns' => $columns, 'rows' => $rows, 'order' => $order], max(0, count($ids) - $capacity));
    }

    /**
     * @param  list<string>  $ids  the cells to render, in cell order
     * @param  array<string, mixed>  $layout  the layout the sheet stands on
     * @return array{id: string, regime: string, medium: string, cell_ids: list<string>, shortage: int, layout: array<string, mixed>, layout_sha256: string}
     */
    public static function partSheetOn(array $ids, array $layout, int $shortage = 0): array
    {
        return [
            'id' => 'M'.substr(hash('sha256', 'part|'.implode('|', $ids)), 0, 10),
            'regime' => 'colour',
            'medium' => 'physical',
            'cell_ids' => $ids,
            'shortage' => $shortage,
            'layout' => $layout,
            'layout_sha256' => (new ModelSheetMaster($layout))->layoutSha256(),
        ];
    }

    /**
     * @param  array{cell_ids: list<string>, layout: array<string, mixed>}  $sheet
     * @param  array<string, array<string, mixed>>  $references  the references by id
     * @param  array<string, mixed>  $settings  video.moto.reference
     * @return string the prompt of the master: the canvas with its grid, the angle convention, what the attached model sheet is for, the common rules, the empty cells and the text of every cell as the design writes it; a master of one image names no grid and no cell position
     *
     * @throws RuntimeException when the prompt is longer than the image model accepts
     */
    public static function partPrompt(array $sheet, array $references, array $settings): string
    {
        $prompt = (array) $settings['prompt'];
        $partSheet = (array) $settings['part_sheet'];
        $labels = (array) $prompt['labels'];
        $cells = (new ModelSheetMaster($sheet['layout']))->cells();
        $single = count($cells) === 1;
        $empty = array_values(array_filter($cells, static fn (array $cell): bool => ! in_array($cell['id'], $sheet['cell_ids'], true)));
        [$width, $height] = explode('x', (string) $sheet['layout']['size']);
        $master = (array) config('video.moto.model_sheet.master');
        $blocks = [
            rtrim((string) $prompt['styles']['physical'], '.').'.',
            strtr((string) ($single ? $prompt['canvas_single'] : $partSheet['canvas']), [
                ':width' => $width,
                ':height' => $height,
                ':rows' => (string) $sheet['layout']['rows'],
                ':columns' => (string) $sheet['layout']['columns'],
                ':cells' => (string) count($cells),
                ':cell_width' => self::spread(array_column($cells, 'width')),
                ':cell_height' => self::spread(array_column($cells, 'height')),
            ]),
            strtr((string) $partSheet['source'], [':rows' => (string) $master['rows'], ':columns' => (string) $master['columns']]),
            (string) config('video.moto.model_sheet.camera_convention'),
            implode("\n", array_map(static fn (string $rule): string => '- '.$rule, [...($single ? [] : [(string) $partSheet['lines']]), ...(array) $partSheet['rules']])),
        ];

        if ($empty !== []) {
            $blocks[] = rtrim((string) $prompt['empty'], '.').': '.implode('; ', array_map(static fn (array $cell): string => self::position($cell, $labels), $empty)).'.';
        }

        foreach ($cells as $cell) {
            $reference = $references[$cell['id']] ?? null;

            if ($reference === null) {
                continue;
            }

            $heading = $single ? "{$reference['name']}." : "{$labels['cell']} ".($cell['index'] + 1).', '.self::position($cell, $labels)." (x {$cell['x']}–".($cell['x'] + $cell['width'] - 1).", y {$cell['y']}–".($cell['y'] + $cell['height'] - 1)."): {$reference['name']}.";
            $blocks[] = implode("\n", [$heading, ...(array) $reference['lines']]);
        }

        $text = implode("\n\n", $blocks);

        if (mb_strlen($text) > OpenAiImageClient::MAX_PROMPT_CHARS) {
            throw new RuntimeException('Prompt master '.mb_strlen($text).' ký tự, vượt giới hạn '.OpenAiImageClient::MAX_PROMPT_CHARS.' — bớt part hoặc chia lại trước khi render.');
        }

        return $text;
    }

    /**
     * @param  list<int>  $lengths
     * @return string one length, or the smallest and the largest when the cells differ
     */
    public static function spread(array $lengths): string
    {
        $low = min($lengths);
        $high = max($lengths);

        return $low === $high ? (string) $low : $low.'–'.$high;
    }

    /** @return string the id of the cell of a part: the same part keeps its cell id through every plan */
    private static function partCellId(string $part): string
    {
        return 'R'.substr(hash('sha256', 'part|'.$part), 0, 10);
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  list<string>  $finished
     * @param  array<string, array<string, mixed>>  $views
     * @return array{need_id: string, type: string, source: ?string, rule: string} the model sheet view nearest the camera for a finished motorcycle; every other state waits for the keyframe step
     */
    private static function wholeIdentity(array $shot, string $needId, array $finished, array $views): array
    {
        if (! in_array($shot['state'] ?? null, $finished, true)) {
            return ['need_id' => $needId, 'type' => 'none', 'source' => null, 'rule' => 'identity_waiting_keyframe'];
        }

        $view = MotoShots::nearestView((array) $shot['camera'], array_keys($views));

        return $view === null
            ? ['need_id' => $needId, 'type' => 'none', 'source' => null, 'rule' => 'model_sheet_missing']
            : ['need_id' => $needId, 'type' => 'model_sheet', 'source' => $view, 'rule' => 'identity_whole'];
    }

    /**
     * @param  array{needs: list<string>, shots: list<string>, purposes: list<string>, cameras: list<array<string, mixed>>}  $use
     * @param  array<string, mixed>  $design
     * @param  array<string, array<string, mixed>>  $parts
     * @param  array<string, array<string, mixed>>  $views
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed> the cell of the part with the camera it is shown from, the panels of the model sheet that show it and its text; the shots that use it stay out of the fingerprint
     */
    private static function partReference(string $id, array $use, array $design, array $parts, array $views, array $settings, array $manifest): array
    {
        $part = $parts[$id];
        $visible = array_keys(array_filter($views, static fn (array $view): bool => in_array($id, (array) $view['visible'], true)));
        $partial = array_keys(array_filter($views, static fn (array $view): bool => in_array($id, (array) $view['partial'], true)));
        $camera = self::partCamera($part, $views, $use['cameras']);
        $reference = [
            'id' => self::partCellId($id),
            'key' => 'part.'.$id,
            'kind' => 'part',
            'part' => $id,
            'name' => (string) $part['name'],
            'elements' => self::partElements($part),
            'camera' => $camera,
            'source_views' => [...$visible, ...$partial],
            'regime' => 'colour',
            'medium' => 'physical',
            'blocked' => [],
            'lines' => self::partLines($part, $design, $camera, $visible !== [] ? $visible : $partial, $settings),
            'needs' => array_values(array_unique($use['needs'])),
            'shots' => array_values(array_unique($use['shots'])),
            'purposes' => array_values(array_unique($use['purposes'])),
        ];
        $reference['fingerprint'] = CanonicalJson::hash([
            'reference' => array_diff_key($reference, array_flip(['needs', 'shots', 'purposes'])),
            'design' => $manifest['design_raw_sha256'] ?? null,
            'model_sheet' => $manifest['model_sheet_sha256'] ?? null,
            'version' => [self::PART_VERSION, self::PART_PROMPT_VERSION],
        ]);

        return $reference;
    }

    /**
     * @param  array<string, mixed>  $part
     * @param  array<string, array<string, mixed>>  $views
     * @param  list<array<string, mixed>>  $cameras  the cameras of the shots that use the part
     * @return array{view: ?string, azimuth_deg: int|float|null, elevation_deg: int|float|null} the view to show the part alone from: among the views that list it as visible, then partly visible, then all views, the one that sees most of its faces, then the one most of its shots stand within 45 degrees of, then the first
     */
    private static function partCamera(array $part, array $views, array $cameras): array
    {
        $id = (string) $part['id'];
        $tiers = [
            array_keys(array_filter($views, static fn (array $view): bool => in_array($id, (array) $view['visible'], true))),
            array_keys(array_filter($views, static fn (array $view): bool => in_array($id, (array) $view['partial'], true))),
            array_keys($views),
        ];

        foreach ($tiers as $pool) {
            $best = null;
            $bestFaces = 0;
            $bestNear = -1;

            foreach ($pool as $view) {
                $faces = count(MotoSheetPrompt::facesSeen($part, (array) $views[$view]['camera'], false));
                $near = count(array_filter($cameras, static fn (array $camera): bool => is_numeric($camera['azimuth_deg'] ?? null) && is_numeric($views[$view]['camera']['azimuth_deg'] ?? null) && self::turn((float) $camera['azimuth_deg'], (float) $views[$view]['camera']['azimuth_deg']) <= 45.0));

                if ($faces > $bestFaces || ($faces > 0 && $faces === $bestFaces && $near > $bestNear)) {
                    [$best, $bestFaces, $bestNear] = [$view, $faces, $near];
                }
            }

            if ($best !== null) {
                return self::viewCamera((string) $best, $views);
            }
        }

        $first = array_key_first($views);

        return $first === null ? ['view' => null, 'azimuth_deg' => null, 'elevation_deg' => null] : self::viewCamera((string) $first, $views);
    }

    /**
     * @param  array<string, array<string, mixed>>  $views
     * @return array{view: string, azimuth_deg: int|float|null, elevation_deg: int|float|null}
     */
    private static function viewCamera(string $view, array $views): array
    {
        $camera = (array) $views[$view]['camera'];

        return [
            'view' => $view,
            'azimuth_deg' => is_numeric($camera['azimuth_deg'] ?? null) ? $camera['azimuth_deg'] + 0 : null,
            'elevation_deg' => is_numeric($camera['elevation_deg'] ?? null) ? $camera['elevation_deg'] + 0 : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $part
     * @return list<string> what the part is made of, as the design writes it
     */
    private static function partElements(array $part): array
    {
        return array_map(static fn (array $element): string => $element['element'].' × '.$element['count'].', '.rtrim((string) $element['arrangement'], '.').(is_string($element['size'] ?? null) ? ', '.$element['size'] : ''), array_values((array) ($part['elements'] ?? [])));
    }

    /**
     * @param  array<string, mixed>  $part
     * @param  array<string, mixed>  $design
     * @param  array{view: ?string, azimuth_deg: int|float|null, elevation_deg: int|float|null}  $camera
     * @param  list<string>  $panels  the model sheet views that show the part
     * @param  array<string, mixed>  $settings  video.moto.reference
     * @return list<string> the text of the cell as the design writes it: the shape, elements, faces, size, surfaces and light of the part, the features it owns, the rules kept for this part alone and every rule against something, the parts that only meet it to leave out, the panels that show it and the view
     */
    private static function partLines(array $part, array $design, array $camera, array $panels, array $settings): array
    {
        $id = (string) $part['id'];
        $labels = (array) $settings['part_sheet']['labels'];
        $palette = array_column((array) ($design['motorcycle']['palette'] ?? []), null, 'id');
        $clean = static fn (string $text): string => rtrim($text, '.');
        $elements = self::partElements($part);
        $faces = array_map(static fn (array $face): string => str_replace('_', ' ', (string) $face['facing']).': '.$clean((string) $face['description']), array_values((array) ($part['faces'] ?? [])));
        $surfaces = array_map(static function (array $surface) use ($palette): string {
            $colour = $palette[$surface['color_id'] ?? ''] ?? null;

            return $surface['element'].': '.$surface['material'].', '.$surface['finish'].($colour === null ? '' : ' in '.$colour['name'].(is_string($colour['hex'] ?? null) ? ' ('.$colour['hex'].')' : ''));
        }, array_values((array) ($part['surfaces'] ?? [])));
        $displayed = array_values(array_filter((array) ($part['configurations'] ?? []), static fn (array $configuration): bool => in_array($configuration['id'], (array) ($design['model_sheet']['display_configuration'] ?? []), true)));
        $lights = array_map(static fn (array $light): string => $light['source'].', '.$light['position'], array_values((array) ($part['lighting'] ?? [])));
        $own = [];

        foreach ((array) ($design['features'] ?? []) as $feature) {
            if (in_array($id, self::featureOwners((array) $feature, $design), true)) {
                $own[] = "{$labels['feature']} {$feature['name']}: ".$clean((string) $feature['visible_expression']).'.';
            }
        }

        foreach ((array) ($design['identity_rules'] ?? []) as $rule) {
            $scope = array_values((array) ($rule['scope'] ?? []));
            $concerns = in_array($id, $scope, true) || in_array($id, (array) ($rule['refs'] ?? []), true);

            if ($concerns && ($scope === [$id] || ($rule['kind'] ?? null) === 'must_not')) {
                $own[] = ($labels[$rule['kind']] ?? (string) $rule['kind']).' '.$clean((string) $rule['description']).'.';
            }
        }

        $excluded = self::relatedParts($part, $design);
        $extent = is_array($part['extent'] ?? null) ? $part['extent'] : null;

        return array_values(array_filter([
            "{$labels['shape']} ".$clean((string) ($part['description'] ?? '')).'.',
            $elements === [] ? null : "{$labels['elements']} ".implode('; ', $elements).'.',
            $faces === [] ? null : "{$labels['faces']} ".implode('; ', $faces).'.',
            $extent === null ? null : "{$labels['size']} about ".self::number(($extent['x_to'] - $extent['x_from']) / 1000).' × '.self::number(($extent['y_max'] - $extent['y_min']) / 1000).' × '.self::number(($extent['z_max'] - $extent['z_min']) / 1000).' m (length × width × height).',
            $surfaces === [] ? null : "{$labels['surfaces']} ".implode('; ', $surfaces).'.',
            $displayed === [] ? null : "{$labels['configuration']} ".implode('; ', array_map(static fn (array $configuration): string => $clean((string) $configuration['description']), $displayed)).'.',
            $lights === [] ? null : "{$labels['lights']} ".implode('; ', $lights).'.',
            ...$own,
            $excluded === [] ? null : "{$labels['not_draw']} ".implode('; ', $excluded).'.',
            $panels === [] ? $labels['no_panel'] : "{$labels['panels']} ".implode('; ', array_map(self::panelPosition(...), $panels)).'.',
            "{$labels['view']} ".self::partView($camera, $settings).'.',
        ]));
    }

    /**
     * @param  array<string, mixed>  $feature
     * @param  array<string, mixed>  $design
     * @return list<string> the parts whose identity the feature is: of the parts it names, those with no smaller part of their own also named; when several remain, only those a rule kept for one part alone names it for, and none when no such rule says whose it is, so the feature of a group is never handed to each part of the group
     */
    private static function featureOwners(array $feature, array $design): array
    {
        $parts = array_column((array) ($design['parts'] ?? []), null, 'id');
        $members = array_values(array_filter((array) ($feature['parts'] ?? []), static fn (mixed $id): bool => is_string($id) && isset($parts[$id])));
        $carriers = [];

        foreach ($members as $id) {
            $steps = 0;

            while (is_string($parts[$id]['parent'] ?? null) && isset($parts[$parts[$id]['parent']]) && $steps < count($parts)) {
                $id = $parts[$id]['parent'];
                $carriers[$id] = true;
                $steps++;
            }
        }

        $owners = array_values(array_filter($members, static fn (string $id): bool => ! isset($carriers[$id])));

        if (count($owners) < 2) {
            return $owners;
        }

        return array_values(array_filter($owners, static fn (string $id): bool => collect((array) ($design['identity_rules'] ?? []))
            ->contains(static fn (array $rule): bool => ($rule['kind'] ?? null) === 'must_keep' && array_values((array) ($rule['scope'] ?? [])) === [$id] && in_array($feature['id'] ?? null, (array) ($rule['refs'] ?? []), true))));
    }

    /**
     * @param  array<string, mixed>  $part
     * @param  array<string, mixed>  $design
     * @return list<string> the names of the parts that only meet this part where it is mounted: its parent, its children, what its relations and connections name, less any the design lists as an element of this part
     */
    private static function relatedParts(array $part, array $design): array
    {
        $id = (string) $part['id'];
        $parts = array_column((array) ($design['parts'] ?? []), null, 'id');
        $elements = array_map(static fn (array $element): string => mb_strtolower((string) $element['element']), array_values((array) ($part['elements'] ?? [])));
        $related = [
            $part['parent'] ?? null,
            ...array_keys(array_filter($parts, static fn (array $other): bool => ($other['parent'] ?? null) === $id)),
            ...array_column((array) ($part['relations'] ?? []), 'target'),
        ];

        foreach ((array) ($design['connections'] ?? []) as $connection) {
            $between = array_values((array) ($connection['between'] ?? []));

            if (in_array($id, $between, true)) {
                $related = [...$related, ...$between];
            }
        }

        $names = [];

        foreach ($related as $other) {
            if (is_string($other) && $other !== $id && isset($parts[$other]) && ! in_array(mb_strtolower((string) ($parts[$other]['name'] ?? '')), $elements, true)) {
                $names[$other] = (string) ($parts[$other]['name'] ?? $other);
            }
        }

        return array_values($names);
    }

    /** @return string where a view sits on the attached model sheet, in words */
    private static function panelPosition(string $view): string
    {
        $master = (array) config('video.moto.model_sheet.master');
        $index = (int) array_search($view, (array) $master['order'], true);

        return 'row '.(intdiv($index, (int) $master['columns']) + 1).', column '.($index % (int) $master['columns'] + 1);
    }

    /**
     * @param  array{view: ?string, azimuth_deg: int|float|null, elevation_deg: int|float|null}  $camera
     * @param  array<string, mixed>  $settings  video.moto.reference
     */
    private static function partView(array $camera, array $settings): string
    {
        if ($camera['azimuth_deg'] === null) {
            return strtr((string) $settings['part_sheet']['labels']['top_view'], [':elevation' => self::number($camera['elevation_deg'] ?? 90)]);
        }

        return self::view(['target' => 'part', 'scale' => null, 'azimuth_deg' => $camera['azimuth_deg'], 'elevation_deg' => $camera['elevation_deg']], (array) $settings['prompt']['view']);
    }

    /**
     * @param  list<array<string, mixed>>  $shots  the shots of the derived shot plan
     * @param  array<string, mixed>  $design  the data of the design the model sheet and the shots pin
     * @param  list<string>  $finished  the finished build states the model sheet shows
     * @param  array<string, array{artifact_id: string, camera: array<string, mixed>, visible: list<string>, partial: list<string>, uncertain: list<string>}>  $views  every model sheet view with an approved crop
     * @param  array{answers?: array<string, list<array{question: string, answer: string}>>}  $decisions  what a person decided for a cell the sources left open
     * @param  array<string, mixed>  $settings  video.moto.reference
     * @param  array<string, mixed>  $manifest  the pinned sources the plan is built from
     * @return array{version: string, schema: string, needs: list<array<string, mixed>>, references: list<array<string, mixed>>, bindings: list<array<string, mixed>>, dependencies: list<array<string, mixed>>, sheets: list<array<string, mixed>>, source_manifest: array<string, mixed>}
     *
     * @throws RuntimeException when a shot names a part or build state the design does not have
     */
    public static function plan(array $shots, array $design, array $finished, array $views, array $decisions, array $settings, array $manifest): array
    {
        $parts = array_column((array) ($design['parts'] ?? []), null, 'id');
        $states = array_column((array) ($design['build_states'] ?? []), null, 'id');
        $order = self::workOrder($shots);
        $work = self::workWords($shots);
        $sources = [
            'parts' => $parts,
            'palette' => array_column((array) ($design['motorcycle']['palette'] ?? []), null, 'id'),
            'connections' => array_values((array) ($design['connections'] ?? [])),
            'order' => $order,
            'work' => $work,
            'symmetry' => is_string($design['motorcycle']['symmetry'] ?? null) ? $design['motorcycle']['symmetry'] : null,
            'rules' => array_values((array) ($design['identity_rules'] ?? [])),
            'features' => array_values((array) ($design['features'] ?? [])),
            'state_order' => array_flip(array_keys($states)),
            'shown' => self::shownParts($views),
            'settings' => $settings,
        ];
        $needs = [];
        $bindings = [];
        $references = [];

        foreach ($shots as $shot) {
            $shotId = (string) $shot['id'];
            $state = $states[(string) ($shot['state'] ?? '')] ?? null;

            if ($state === null && collect((array) ($shot['reference_needs'] ?? []))->contains(static fn (array $need): bool => in_array($need['kind'] ?? null, ['design', 'identity'], true))) {
                throw new RuntimeException("{$shotId}: build state ".($shot['state'] ?? 'null').' không có trong thiết kế.');
            }

            $open = [];
            $identities = [];

            foreach (array_values((array) ($shot['reference_needs'] ?? [])) as $index => $need) {
                $needId = $shotId.'#'.($index + 1);
                $target = is_string($need['target'] ?? null) ? $need['target'] : null;
                $record = [
                    'id' => $needId,
                    'shot_id' => $shotId,
                    'index' => $index,
                    'kind' => (string) $need['kind'],
                    'target' => $target,
                    'purpose' => (string) ($need['purpose'] ?? ''),
                    'state' => is_string($shot['state'] ?? null) ? $shot['state'] : null,
                    'placement' => $target === null ? null : self::placement($shot, $target),
                    'checkpoint' => $target === null ? null : self::checkpoint($shot, $target, $order, $work),
                    'camera' => array_intersect_key((array) $shot['camera'], array_flip(['target', 'target_part', 'scale', 'lens_mm', 'azimuth_deg', 'elevation_deg'])),
                ];
                $needs[] = $record;

                if ($record['kind'] === 'environment') {
                    $bindings[] = ['need_id' => $needId, 'type' => 'environment', 'source' => $target, 'rule' => $target === null ? 'requested_background' : 'plate'];

                    continue;
                }

                if ($record['kind'] === 'continuity') {
                    $bindings[] = ['need_id' => $needId, 'type' => 'continuity', 'source' => $target, 'rule' => 'previous_shot'];

                    continue;
                }

                if ($record['kind'] === 'identity') {
                    $identities[] = $record;

                    continue;
                }

                if ($target === null || ! isset($parts[$target])) {
                    throw new RuntimeException("{$needId}: part ".($target ?? 'null').' không có trong thiết kế đã duyệt.');
                }

                $binding = self::modelSheet($shot, $record, $finished, $views);

                if ($binding !== null) {
                    $bindings[] = $binding;

                    continue;
                }

                $open[] = $record;
            }

            foreach (self::groups($open, $parts, $sources['connections']) as $group) {
                $reference = self::reference($shot, $group, $state, $sources);
                $id = self::share($references, $reference, (float) $settings['share_angle_deg']);

                if ($id === null) {
                    $references[$reference['id']] = $reference;
                    $id = $reference['id'];
                } else {
                    $references[$id] = self::serve($references[$id], $reference);
                }

                foreach ($group as $need) {
                    $bindings[] = ['need_id' => $need['id'], 'type' => 'reference', 'source' => $id, 'rule' => count($group) > 1 ? 'detail_set' : 'part_detail'];
                }
            }

            foreach ($identities as $need) {
                $bindings[] = self::identity($shot, $need, $finished, $views, $references, $bindings, $state, $sources);

                $created = end($bindings);

                if ($created['type'] === 'reference' && ! isset($references[$created['source']]) && isset($created['reference'])) {
                    $references[$created['source']] = $created['reference'];
                }

                unset($bindings[array_key_last($bindings)]['reference']);
            }
        }

        $references = array_values($references);
        $keys = [];

        foreach ($references as &$reference) {
            $key = $reference['key'];
            $keys[$key] = ($keys[$key] ?? 0) + 1;
            $reference['key'] = $keys[$key] === 1 ? $key : $key.'#'.$keys[$key];
            $reference['source_fingerprint'] = CanonicalJson::hash([
                'facts' => $reference['facts'],
                'design' => $manifest['design_raw_sha256'] ?? null,
                'model_sheet' => $manifest['model_sheet_sha256'] ?? null,
            ]);
            $reference = self::withDecisions($reference, array_values((array) ($decisions['answers'][$reference['id']] ?? [])), (array) $settings['prompt']['labels']);
            $reference['fingerprint'] = CanonicalJson::hash([
                'reference' => array_diff_key($reference, array_flip(['fingerprint', 'needs', 'shots', 'earlier_decisions'])),
                'design' => $manifest['design_raw_sha256'] ?? null,
                'model_sheet' => $manifest['model_sheet_sha256'] ?? null,
                'version' => [self::VERSION, MotoReferenceSurface::VERSION, self::PROMPT_VERSION],
            ]);
        }

        unset($reference);

        return [
            'version' => self::VERSION,
            'schema' => self::SCHEMA,
            'needs' => $needs,
            'references' => $references,
            'bindings' => array_values($bindings),
            'dependencies' => self::dependencies($references, $manifest),
            'sheets' => self::sheets($references, $settings),
            'source_manifest' => $manifest,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $references  the references that still need an image, in plan order
     * @param  array<string, mixed>  $settings  video.moto.reference
     * @return list<array{id: string, regime: string, medium: string, cell_ids: list<string>, layout: array<string, mixed>, layout_sha256: string}> the masters of the references: one master holds cells of one input regime and one medium, so a digital model never shares a picture style with real parts, up to the largest layout; a state's cells stay together while they fit, then whole states are packed first-fit from the largest
     */
    public static function sheets(array $references, array $settings): array
    {
        $size = max(array_column((array) $settings['layouts'], 'cells'));
        $sheets = [];

        foreach (['grey', 'colour'] as $regime) {
            foreach (['physical', 'digital'] as $medium) {
                $states = [];

                foreach ($references as $reference) {
                    if ($reference['regime'] === $regime && $reference['medium'] === $medium) {
                        $states[$reference['state']][] = $reference['id'];
                    }
                }

                $chunks = [];

                foreach ($states as $ids) {
                    foreach (array_chunk($ids, $size) as $chunk) {
                        $chunks[] = $chunk;
                    }
                }

                usort($chunks, static fn (array $a, array $b): int => count($b) <=> count($a));
                $bins = [];

                foreach ($chunks as $chunk) {
                    foreach ($bins as &$bin) {
                        if (count($bin) + count($chunk) <= $size) {
                            array_push($bin, ...$chunk);

                            continue 2;
                        }
                    }

                    unset($bin);
                    $bins[] = $chunk;
                }

                unset($bin);

                foreach ($bins as $ids) {
                    $sheets[] = self::sheet($regime, $medium, $ids, $settings);
                }
            }
        }

        return $sheets;
    }

    /**
     * @param  list<string>  $ids  the reference ids of the cells, in cell order
     * @param  array<string, mixed>  $settings  video.moto.reference
     * @return array{id: string, regime: string, medium: string, cell_ids: list<string>, layout: array<string, mixed>, layout_sha256: string} the master of the cells on the smallest offered layout that holds them; the cells left over stay empty
     *
     * @throws RuntimeException when no offered layout holds that many cells
     */
    public static function sheet(string $regime, string $medium, array $ids, array $settings): array
    {
        $layouts = (array) $settings['layouts'];
        usort($layouts, static fn (array $a, array $b): int => (int) $a['cells'] <=> (int) $b['cells']);
        $chosen = null;

        foreach ($layouts as $candidate) {
            if (count($ids) <= (int) $candidate['cells']) {
                $chosen = $candidate;

                break;
            }
        }

        if ($chosen === null) {
            throw new RuntimeException('Không có lưới nào chứa được '.count($ids).' ô.');
        }

        $order = $ids;
        $columns = (int) $chosen['columns'];
        $rows = (int) $chosen['rows'];

        for ($index = count($order); $index < $columns * $rows; $index++) {
            $order[] = 'EMPTY'.($index + 1);
        }

        $layout = ['size' => (string) $chosen['size'], 'columns' => $columns, 'rows' => $rows, 'cell' => (array) $chosen['cell'], 'order' => $order];

        return [
            'id' => 'M'.substr(hash('sha256', $regime.'|'.implode('|', $ids)), 0, 10),
            'regime' => $regime,
            'medium' => $medium,
            'cell_ids' => $ids,
            'layout' => $layout,
            'layout_sha256' => (new ModelSheetMaster($layout))->layoutSha256(),
        ];
    }

    /**
     * @param  array{regime: string, medium: string, cell_ids: list<string>, layout: array<string, mixed>}  $sheet
     * @param  array<string, array<string, mixed>>  $references  the references by id
     * @param  array<string, list<array{section: string, text: string}>>  $lines  the accepted lines of each cell by reference id
     * @param  array<string, mixed>  $settings  video.moto.reference
     * @return string the master prompt: the fixed frame of the sheet, then every cell section by section with the lines code locks in place; a master of one image names no grid, no empty cell and no cell position
     *
     * @throws RuntimeException when the prompt is longer than the image model accepts
     */
    public static function masterPrompt(array $sheet, array $references, array $lines, array $settings): string
    {
        $prompt = (array) $settings['prompt'];
        $cells = (new ModelSheetMaster($sheet['layout']))->cells();
        $single = count($cells) === 1;
        $empty = array_values(array_filter($cells, static fn (array $cell): bool => ! in_array($cell['id'], $sheet['cell_ids'], true)));
        [$width, $height] = explode('x', (string) $sheet['layout']['size']);
        $blocks = [
            rtrim((string) $prompt['styles'][$sheet['medium']], '.').'.',
            strtr((string) $prompt[$single ? 'canvas_single' : 'canvas'], [
                ':width' => $width,
                ':height' => $height,
                ':rows' => (string) $sheet['layout']['rows'],
                ':columns' => (string) $sheet['layout']['columns'],
                ':cells' => (string) count($cells),
                ':cell_width' => (string) $sheet['layout']['cell']['width'],
                ':cell_height' => (string) $sheet['layout']['cell']['height'],
            ]),
            (string) $prompt['sources'][$sheet['regime']],
            (string) config('video.moto.model_sheet.camera_convention'),
            implode("\n", array_map(static fn (string $rule): string => '- '.$rule, (array) $prompt['rules'])),
        ];

        if ($empty !== []) {
            $blocks[] = rtrim((string) $prompt['empty'], '.').': '.implode('; ', array_map(static fn (array $cell): string => self::position($cell, (array) $prompt['labels']), $empty)).'.';
        }

        foreach ($cells as $cell) {
            $reference = $references[$cell['id']] ?? null;

            if ($reference === null) {
                continue;
            }

            $blocks[] = self::cellText($cell, $reference, $lines[$cell['id']] ?? [], (array) $prompt['labels'], $single);
        }

        $text = implode("\n\n", $blocks);

        if (mb_strlen($text) > OpenAiImageClient::MAX_PROMPT_CHARS) {
            throw new RuntimeException('Prompt master '.mb_strlen($text).' ký tự, vượt giới hạn '.OpenAiImageClient::MAX_PROMPT_CHARS.' — tách bớt ô sang master khác.');
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $reference
     * @return list<array{section: string, text: string}> the draft lines of the reference as code wrote them
     */
    public static function draftLines(array $reference): array
    {
        return array_map(static fn (array $line): array => ['section' => (string) $line['section'], 'text' => (string) $line['text']], (array) $reference['draft']);
    }

    /**
     * @param  array{row: int, column: int}  $cell
     * @param  array<string, mixed>  $labels
     */
    private static function position(array $cell, array $labels): string
    {
        return strtr((string) $labels['position'], [':row' => (string) $cell['row'], ':column' => (string) $cell['column']]);
    }

    /**
     * @param  array{index: int, row: int, column: int, x: int, y: int, width: int, height: int}  $cell
     * @param  array<string, mixed>  $reference
     * @param  list<array{section: string, text: string}>  $lines
     * @param  array<string, mixed>  $labels
     * @return string what must show, then the moment with the placement and work code locks, the view code locks, the shape, the surfaces and what is not present; the order holds whatever order the lines came in
     */
    private static function cellText(array $cell, array $reference, array $lines, array $labels, bool $single): string
    {
        $words = (array) config('video.moto.shots.prompt.presentations');
        $where = (array) config('video.moto.shots.prompt.work.locations');
        $parts = array_map(static fn (array $part): string => "{$part['part']} {$part['name']} (".($part['role'] === 'subject' ? 'subject' : 'context').', '.($words[$part['presentation']] ?? $part['presentation']).($part['location'] !== null && isset($where[$part['location']]) ? ', '.$where[$part['location']] : '').')', (array) $reference['parts']);
        $requirement = (array) $reference['requirement'];
        $section = static fn (string $name): array => array_column(array_filter($lines, static fn (array $line): bool => $line['section'] === $name), 'text');

        return implode("\n", array_filter([
            $single ? (string) $reference['key'] : "{$labels['cell']} ".($cell['index'] + 1).', '.self::position($cell, $labels)." (x {$cell['x']}–".($cell['x'] + $cell['width'] - 1).", y {$cell['y']}–".($cell['y'] + $cell['height'] - 1)."): {$reference['key']}",
            ...$section('must_show'),
            "{$labels['parts']} ".implode('; ', $parts).'.',
            "{$labels['stage']} {$reference['state']}.",
            $requirement['done'] === [] ? null : "{$labels['done']} ".implode(', ', $requirement['done']).'.',
            $requirement['pending'] === [] ? null : "{$labels['pending']} ".implode(', ', $requirement['pending']).'.',
            ...$section('moment'),
            "{$labels['view']} {$requirement['view']}.",
            ...$section('framing'),
            ...$section('shape'),
            ...$section('surface'),
            $requirement['absent'] === [] ? null : "{$labels['absent']} ".implode(', ', $requirement['absent']).'.',
            ...$section('exclude'),
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $shots
     * @return array<string, string> the word of the operation of every work item the shots show
     */
    private static function workWords(array $shots): array
    {
        $operations = (array) config('video.moto.shots.prompt.work.operations');
        $words = [];

        foreach ($shots as $shot) {
            foreach ((array) ($shot['work_items'] ?? []) as $item) {
                $words[(string) $item['id']] = (string) ($operations[$item['operation']] ?? str_replace('_', ' ', (string) $item['operation']));
            }
        }

        return $words;
    }

    /**
     * @param  array<string, array{visible: list<string>, partial: list<string>, uncertain: list<string>}>  $views
     * @return array<string, true> every part any panel of the model sheet shows: the input image is the whole sheet, so any of them can be pulled into a cell
     */
    private static function shownParts(array $views): array
    {
        $shown = [];

        foreach ($views as $view) {
            foreach ([...$view['visible'], ...$view['partial'], ...$view['uncertain']] as $part) {
                $shown[(string) $part] = true;
            }
        }

        return $shown;
    }

    /**
     * @param  list<array<string, mixed>>  $shots
     * @return array<string, list<string>> the ids of the work items on each part, in screen order
     */
    private static function workOrder(array $shots): array
    {
        $order = [];
        $seen = [];

        foreach ($shots as $shot) {
            foreach ((array) ($shot['work_items'] ?? []) as $item) {
                $id = (string) $item['id'];

                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;

                foreach (array_unique([(string) $item['part'], ...array_map(static fn (array $part): string => (string) $part['part'], (array) ($item['parts'] ?? []))]) as $part) {
                    $order[$part][] = $id;
                }
            }
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $shot
     * @return array{presentation: string, location: ?string, status: string, item: ?string, moment: ?string}
     */
    private static function placement(array $shot, string $part): array
    {
        $presentation = (string) (collect((array) ($shot['parts'] ?? []))->firstWhere('part', $part)['presentation'] ?? 'installed');
        $held = ((array) ($shot['held_parts'] ?? []))[$part] ?? null;

        return [
            'presentation' => $presentation,
            'location' => is_array($held) ? (string) $held['location'] : match ($presentation) {
                'installed' => 'on_motorcycle',
                'removed', 'awaiting_install' => 'off_motorcycle',
                default => null,
            },
            'status' => is_array($held) ? (string) $held['status'] : $presentation,
            'item' => is_array($held) ? (string) $held['item'] : null,
            'moment' => is_array($held) ? (string) $held['moment'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  array<string, list<string>>  $order
     * @param  array<string, string>  $work  the word of the operation of every work item
     * @return ?array{after: ?string, before: ?string, label: string, text: string, done: list<string>, pending: list<string>} the last work item on the part done before the first frame, the next one and the words of all the work on either side, from the work the shot holds the part in; null when the shot does no work on it
     */
    private static function checkpoint(array $shot, string $part, array $order, array $work): ?array
    {
        $held = ((array) ($shot['held_parts'] ?? []))[$part] ?? null;

        if (! is_array($held) || ! is_string($held['item'] ?? null)) {
            return null;
        }

        $items = $order[$part] ?? [];
        $index = array_search($held['item'], $items, true);

        if ($index === false) {
            return null;
        }

        $split = ($held['moment'] ?? null) === 'start' ? $index : $index + 1;
        $after = $items[$split - 1] ?? null;
        $before = $items[$split] ?? null;
        $name = static fn (string $id): string => $id.(isset($work[$id]) ? " ({$work[$id]})" : '');
        $word = static fn (string $id): string => $work[$id] ?? $id;

        return [
            'after' => $after,
            'before' => $before,
            'label' => $after !== null ? 'after-'.$after : 'before-'.$before,
            'text' => implode(', ', array_filter([$after === null ? null : 'after '.$name($after), $before === null ? null : 'before '.$name($before)])),
            'done' => array_map($word, array_slice($items, 0, $split)),
            'pending' => array_map($word, array_slice($items, $split)),
        ];
    }

    /**
     * @param  array<string, mixed>  $shot
     * @return array{target: string, target_part: mixed, scale: mixed, azimuth_deg: int|float|null, elevation_deg: int|float|null, request: ?string} the camera of the cell; a shot that frames a screen or a drawing has no angle around the motorcycle, and its image is its own
     */
    private static function camera(array $shot): array
    {
        $around = in_array($shot['camera']['target'] ?? null, self::AROUND_MOTORCYCLE, true);
        $azimuth = $around && is_numeric($shot['camera']['azimuth_deg'] ?? null) ? $shot['camera']['azimuth_deg'] + 0 : null;

        return [
            'target' => (string) ($shot['camera']['target'] ?? ''),
            'target_part' => $shot['camera']['target_part'] ?? null,
            'scale' => $shot['camera']['scale'] ?? null,
            'azimuth_deg' => $azimuth,
            'elevation_deg' => $around && is_numeric($shot['camera']['elevation_deg'] ?? null) ? $shot['camera']['elevation_deg'] + 0 : null,
            'request' => $azimuth === null ? (string) $shot['id'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $camera  the camera of the cell
     * @param  array<string, mixed>  $words  video.moto.reference.prompt.view
     * @return string the side the camera stands on and its height in words with the numbers beside them, then what fills the cell
     */
    private static function view(array $camera, array $words): string
    {
        $scale = is_string($camera['scale'] ?? null) ? $camera['scale'] : null;
        $elevation = $camera['elevation_deg'];
        $azimuth = $camera['azimuth_deg'];
        $nearest = null;

        foreach ((array) $words['sides'] as $degrees => $side) {
            if ($azimuth !== null && ($nearest === null || self::turn((float) $azimuth, (float) $degrees) < self::turn((float) $azimuth, (float) $nearest[0]))) {
                $nearest = [$degrees, $side];
            }
        }

        return implode('; ', array_filter([
            match (true) {
                ! in_array($camera['target'], self::AROUND_MOTORCYCLE, true) => (string) $words['own_angle'],
                $nearest === null => (string) $words['no_side'],
                default => "{$nearest[1]} (azimuth ".self::number($azimuth).'°)',
            },
            $elevation === null ? null : match (true) {
                $elevation < 0 => $words['below'],
                (float) $elevation === 0.0 => $words['level'],
                $elevation <= 15 => $words['slightly_above'],
                $elevation <= 60 => $words['above'],
                default => $words['overhead'],
            }.' (elevation '.self::number($elevation).'°)',
            $scale === null ? null : ($words['scales'][$scale] ?? str_replace('_', ' ', $scale).' framing'),
        ]));
    }

    private static function turn(float $from, float $to): float
    {
        return abs(fmod($from - $to + 540.0, 360.0) - 180.0);
    }

    /**
     * @param  array<string, mixed>  $camera  the camera of the cell
     * @return ?list<string> the faces of a part the camera can see, null when the shot fixes no side of the motorcycle
     */
    private static function seenFaces(array $camera): ?array
    {
        if ($camera['azimuth_deg'] === null) {
            return null;
        }

        $seen = [];

        foreach (['front' => 0.0, 'left' => 90.0, 'rear' => 180.0, 'right' => 270.0] as $face => $degrees) {
            if (self::turn((float) $camera['azimuth_deg'], $degrees) < 90.0) {
                $seen[] = $face;
            }
        }

        $elevation = (float) ($camera['elevation_deg'] ?? 0);

        if ($elevation !== 0.0) {
            $seen[] = $elevation > 0 ? 'top' : 'bottom';
        }

        return $seen;
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  array<string, mixed>  $need
     * @param  list<string>  $finished
     * @param  array<string, array<string, mixed>>  $views
     * @return ?array<string, mixed> the model sheet view that shows the part fully and answers a design need of a finished, installed part in its own surface and configuration; null when an image has to be made
     */
    private static function modelSheet(array $shot, array $need, array $finished, array $views): ?array
    {
        $target = (string) $need['target'];

        if (! self::finishedInShot($shot, $need, $finished)) {
            return null;
        }

        $shown = array_keys(array_filter($views, static fn (array $view): bool => in_array($target, $view['visible'], true)));

        if ($shown === []) {
            return null;
        }

        $view = MotoShots::nearestView((array) $shot['camera'], $shown) ?? $shown[0];

        return ['need_id' => $need['id'], 'type' => 'model_sheet', 'source' => $view, 'rule' => 'visible'];
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  array<string, mixed>  $need
     * @param  list<string>  $finished
     * @return bool whether the part stands in the shot finished and installed, in its own surface and configuration
     */
    private static function finishedInShot(array $shot, array $need, array $finished): bool
    {
        $target = (string) $need['target'];
        $placement = (array) $need['placement'];
        $configured = array_filter((array) ($shot['configurations']['start'] ?? []), static fn (mixed $id): bool => is_string($id) && str_starts_with($id, $target.'-'));

        return in_array($shot['state'] ?? null, $finished, true) && $placement['presentation'] === 'installed' && $configured === []
            && ! ($placement['item'] !== null && ($placement['location'] !== 'on_motorcycle' || $placement['status'] !== 'installed'))
            && empty($shot['continuity_start']['surface_condition'] ?? null);
    }

    /**
     * @param  list<array<string, mixed>>  $open  the design needs of one shot no model sheet view answers
     * @param  array<string, array<string, mixed>>  $parts
     * @param  list<array<string, mixed>>  $connections
     * @return list<list<array<string, mixed>>> the needs grouped into one image per group: parts the design relates to each other share a detail set, every other part has its own detail
     */
    private static function groups(array $open, array $parts, array $connections): array
    {
        $groups = [];

        foreach ($open as $need) {
            foreach ($groups as &$group) {
                foreach ($group as $member) {
                    if (self::related((string) $member['target'], (string) $need['target'], $parts, $connections) !== []) {
                        $group[] = $need;

                        continue 3;
                    }
                }
            }

            unset($group);
            $groups[] = [$need];
        }

        return $groups;
    }

    /**
     * @param  array<string, array<string, mixed>>  $parts
     * @param  list<array<string, mixed>>  $connections
     * @return list<array{path: string, text: string}> what in the design ties the two parts: a relation of either, a connection between them or one being the parent of the other
     */
    private static function related(string $a, string $b, array $parts, array $connections): array
    {
        $evidence = [];

        foreach ([[$a, $b], [$b, $a]] as [$from, $to]) {
            foreach (array_values((array) ($parts[$from]['relations'] ?? [])) as $index => $relation) {
                if (($relation['target'] ?? null) === $to) {
                    $evidence[] = ['path' => "design.parts.{$from}.relations[{$index}]", 'text' => ($parts[$from]['name'] ?? $from).' '.str_replace('_', ' ', (string) $relation['type']).' '.($parts[$to]['name'] ?? $to).(is_string($relation['note'] ?? null) && $relation['note'] !== '' ? ': '.rtrim($relation['note'], '.') : '')];
                }
            }

            if (($parts[$from]['parent'] ?? null) === $to) {
                $evidence[] = ['path' => "design.parts.{$from}.parent", 'text' => ($parts[$from]['name'] ?? $from).' is carried by '.($parts[$to]['name'] ?? $to)];
            }
        }

        foreach ($connections as $connection) {
            $between = (array) ($connection['between'] ?? []);

            if (in_array($a, $between, true) && in_array($b, $between, true)) {
                $evidence[] = ['path' => 'design.connections.'.$connection['id'], 'text' => rtrim((string) $connection['description'], '.')];
            }
        }

        return $evidence;
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  list<array<string, mixed>>  $group  the needs the image answers in this shot, the first one leading
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $sources  what plan() reads from the design, the work and the decisions of the project
     * @return array<string, mixed> the image the needs ask for: its parts as subjects or context with their placement and surfaces at the state, the checkpoint, the camera, the input regime and medium, what a person checks the image against, the facts the cell may use and the draft lines code writes from them; what only holds once the part is finished and fitted stays a target fact and never a line
     */
    private static function reference(array $shot, array $group, array $state, array $sources, string $kind = ''): array
    {
        $parts = (array) $sources['parts'];
        $connections = (array) $sources['connections'];
        $settings = (array) $sources['settings'];
        $shotId = (string) $shot['id'];
        $subjects = array_values(array_unique(array_map(static fn (array $need): string => (string) $need['target'], $group)));
        $kind = $kind !== '' ? $kind : (count($subjects) > 1 ? 'detail_set' : 'part_detail');
        $context = [];

        if ($kind !== 'state') {
            foreach ((array) ($shot['parts'] ?? []) as $item) {
                $id = (string) $item['part'];

                if (in_array($id, $subjects, true) || in_array($id, $context, true) || ! isset($parts[$id])) {
                    continue;
                }

                foreach ($subjects as $subject) {
                    if (self::related($subject, $id, $parts, $connections) !== []) {
                        $context[] = $id;

                        break;
                    }
                }
            }
        }

        $checkpoint = null;

        foreach ($subjects as $id) {
            $checkpoint ??= self::checkpoint($shot, $id, (array) $sources['order'], (array) $sources['work']);
        }

        $camera = self::camera($shot);
        $seen = self::seenFaces($camera);
        $building = ($state['kind'] ?? null) === 'build';
        $designTime = $building ? MotoReferenceFacts::TARGET : MotoReferenceFacts::CONSTANT;
        $needs = array_values(array_column($group, null, 'id'));
        $texts = self::firstFrameTexts($shot, $state, $needs);
        $facts = new MotoReferenceFacts(['state' => (string) $state['id'], 'checkpoint' => $checkpoint['label'] ?? null]);
        $cellParts = [];
        $fitted = [];
        $labels = (array) $settings['prompt']['labels'];
        $words = (array) config('video.moto.shots.prompt.presentations');

        foreach ([...array_map(static fn (string $id): array => [$id, 'subject'], $subjects), ...array_map(static fn (string $id): array => [$id, 'context'], $context)] as [$id, $role]) {
            $part = $parts[$id];
            $placement = self::placement($shot, $id);
            $beingMade = $placement['presentation'] === 'being_made' || collect((array) ($state['in_progress'] ?? []))->contains('part', $id);
            $fitted[$id] = ! $beingMade && $placement['location'] !== 'off_motorcycle';
            $components = MotoReferenceSurface::components($part, $state, (array) $sources['palette'], $beingMade, $texts, (array) $settings['surface_groups'], (array) $settings['finish_labels']);
            $cellParts[] = [
                'part' => $id,
                'name' => (string) $part['name'],
                'role' => $role,
                'presentation' => $placement['presentation'],
                'location' => $placement['location'],
                'status' => $placement['status'],
                'components' => $components,
            ];
            $name = (string) $part['name'];
            $nameFact = $facts->add($id, null, 'identity', "{$id}: {$name}", "design.parts.{$id}.name", [], MotoReferenceFacts::CONSTANT);

            if ($role === 'context') {
                $facts->line($labels['context'].' '.$name.' ('.($words[$placement['presentation']] ?? $placement['presentation']).').', [$nameFact], 'shape');
            } else {
                $description = $facts->add($id, null, 'geometry', rtrim((string) ($part['description'] ?? ''), '.'), "design.parts.{$id}.description", [], $designTime);
                $facts->line("{$labels['subject']} {$name} (".($words[$placement['presentation']] ?? $placement['presentation']).'): '.rtrim((string) ($part['description'] ?? ''), '.').'.', [$nameFact, $description], 'shape', $building);

                if ($kind !== 'state') {
                    $faces = [];
                    $refs = [];

                    foreach (array_values((array) ($part['faces'] ?? [])) as $index => $face) {
                        if ($seen !== null && ! in_array($face['facing'], $seen, true)) {
                            continue;
                        }

                        $text = str_replace('_', ' ', (string) $face['facing']).': '.rtrim((string) $face['description'], '.');
                        $faces[] = $text;
                        $refs[] = $facts->add($id, null, 'geometry', $text, "design.parts.{$id}.faces[{$index}]", [], $designTime);
                    }

                    if ($faces !== [] && $seen !== null) {
                        $facts->line("{$labels['faces']} ".implode('; ', $faces).'.', $refs, 'shape', $building);
                    }

                    $extent = is_array($part['extent'] ?? null) ? $part['extent'] : null;

                    if ($extent !== null) {
                        $size = 'about '.self::number(($extent['x_to'] - $extent['x_from']) / 1000).' × '.self::number(($extent['y_max'] - $extent['y_min']) / 1000).' × '.self::number(($extent['z_max'] - $extent['z_min']) / 1000).' m (length × width × height)';
                        $facts->line("{$labels['size']} {$size}.", [$facts->add($id, null, 'geometry', $size, "design.parts.{$id}.extent", [], MotoReferenceFacts::CONSTANT)], 'shape');
                    }

                    $elements = [];
                    $refs = [];

                    foreach (array_values((array) ($part['elements'] ?? [])) as $index => $element) {
                        $text = $element['count'].' × '.$element['element'].', '.rtrim((string) $element['arrangement'], '.').(is_string($element['size'] ?? null) ? ', '.$element['size'] : '');
                        $elements[] = $text;
                        $refs[] = $facts->add($id, (string) $element['element'], 'geometry', $text, "design.parts.{$id}.elements[{$index}]", [], $designTime);
                    }

                    if ($elements !== []) {
                        $facts->line("{$labels['elements']} ".implode('; ', $elements).'.', $refs, 'shape', $building);
                    }
                }
            }

            $refs = [];

            foreach ($components as $index => $component) {
                $refs[] = $facts->add($id, $component['element'], 'surface', $component['text'], "design.parts.{$id}.surfaces[{$index}]", $component['basis']);
            }

            if ($refs !== []) {
                $facts->line("{$labels['surfaces']} {$name} — ".implode('; ', array_map(static fn (array $component): string => $component['text'], $components)).'.', $refs, 'surface');
            }

            $progress = collect((array) ($state['in_progress'] ?? []))->firstWhere('part', $id);

            if ($role === 'subject' && is_array($progress)) {
                $facts->add($id, null, 'state', rtrim((string) $progress['description'], '.'), "design.build_states.{$state['id']}.in_progress");
            }

            $parent = is_string($part['parent'] ?? null) ? $part['parent'] : null;

            if ($role === 'subject' && $parent !== null && ! in_array($parent, [...$subjects, ...$context], true)) {
                foreach ($connections as $connection) {
                    $between = (array) ($connection['between'] ?? []);

                    if (in_array($id, $between, true) && in_array($parent, $between, true)) {
                        $fact = $facts->add($id, null, 'relation', rtrim((string) $connection['description'], '.'), 'design.connections.'.$connection['id'], [], $fitted[$id] ? MotoReferenceFacts::NOW : MotoReferenceFacts::TARGET);

                        if ($fitted[$id]) {
                            $facts->line("{$labels['mount']} ".rtrim((string) $connection['description'], '.').'.', [$fact], 'shape');
                        }
                    }
                }
            }
        }

        $members = [...$subjects, ...$context];

        foreach ($members as $i => $a) {
            foreach (array_slice($members, $i + 1) as $b) {
                if (! in_array($a, $subjects, true) && ! in_array($b, $subjects, true)) {
                    continue;
                }

                foreach (self::related($a, $b, $parts, $connections) as $tie) {
                    if (str_ends_with($tie['path'], '.parent')) {
                        continue;
                    }

                    $together = $fitted[$a] && $fitted[$b];
                    $fact = $facts->add($a, null, 'relation', $tie['text'], $tie['path'], [], $together ? MotoReferenceFacts::NOW : MotoReferenceFacts::TARGET);

                    if ($together) {
                        $facts->line("{$labels['relation']} {$tie['text']}.", [$fact], 'shape');
                    }
                }
            }
        }

        $stageText = is_string($state['surface'] ?? null) ? rtrim($state['surface'], '.') : (is_string($state['text'] ?? null) ? rtrim($state['text'], '.') : null);

        if ($stageText !== null) {
            $facts->add(null, null, 'state', $stageText, "design.build_states.{$state['id']}.".(is_string($state['surface'] ?? null) ? 'surface' : 'text'));
        }

        foreach ($needs as $need) {
            $facts->line("{$labels['must_show']} ".rtrim($need['purpose'], '.').'.', [$facts->add($kind === 'state' ? null : $need['target'], null, 'shot', $need['purpose'], "shots.{$shotId}.reference_needs[{$need['index']}].purpose")], 'must_show');
        }

        $firstFrame = trim((string) ($shot['first_frame'] ?? ''));

        if ($firstFrame !== '') {
            $facts->line("{$labels['first_frame']} ".rtrim($firstFrame, '.').'.', [$facts->add(null, null, 'shot', $firstFrame, "shots.{$shotId}.first_frame")], 'moment', true);
        }

        $pose = trim((string) ($shot['camera']['start_pose'] ?? ''));

        if ($pose !== '') {
            $facts->line("{$labels['pose']} ".rtrim($pose, '.').'.', [$facts->add(null, null, 'view', $pose, "shots.{$shotId}.camera.start_pose")], 'framing', true);
        }

        if (is_string($shot['continuity_start']['placement'] ?? null)) {
            $facts->add(null, null, 'placement', $shot['continuity_start']['placement'], "shots.{$shotId}.continuity_start.placement");
        }

        foreach (array_values((array) ($shot['carried_from']['carries'] ?? [])) as $index => $carried) {
            $facts->add(null, null, 'shot', (string) $carried, "shots.{$shotId}.carried_from.carries[{$index}]");
        }

        foreach (array_values((array) ($shot['assumptions'] ?? [])) as $index => $assumption) {
            $facts->add(null, null, 'shot', (string) $assumption, "shots.{$shotId}.assumptions[{$index}]");
        }

        self::designRules($facts, $members, $cellParts, $state, $sources, $building);

        $allComponents = array_merge(...array_map(static fn (array $part): array => $part['components'], $cellParts ?: [['components' => []]]));
        $unknown = array_values(array_map(static fn (array $component): string => $component['element'], array_filter($allComponents, static fn (array $component): bool => $component['status'] === MotoReferenceSurface::UNKNOWN)));
        $base = [
            'kind' => $kind,
            'state' => (string) $state['id'],
            'checkpoint' => $checkpoint['label'] ?? null,
            'parts' => array_map(static fn (array $part): array => array_intersect_key($part, array_flip(['part', 'role', 'presentation', 'location', 'status'])), $cellParts),
            'request' => $camera['request'],
        ];

        return [
            'id' => 'R'.substr(hash('sha256', CanonicalJson::hash(['base' => $base, 'azimuth' => $camera['azimuth_deg'], 'elevation' => $camera['elevation_deg']])), 0, 10),
            'key' => self::key($kind, $subjects, $cellParts, $state['id'], $checkpoint, $camera),
            'kind' => $kind,
            'state' => (string) $state['id'],
            'state_kind' => (string) ($state['kind'] ?? ''),
            'checkpoint' => $checkpoint,
            'parts' => $cellParts,
            'camera' => $camera,
            'regime' => MotoReferenceSurface::regime($allComponents),
            'medium' => $allComponents !== [] && array_filter($allComponents, static fn (array $component): bool => $component['status'] !== MotoReferenceSurface::DIGITAL) === [] ? 'digital' : 'physical',
            'blocked' => $unknown === [] ? [] : ['Chưa xác lập được bề mặt ở '.$state['id'].': '.implode(', ', $unknown).' — cần kiểm trước khi viết prompt.'],
            'base' => hash('sha256', CanonicalJson::hash($base)),
            'needs' => array_map(static fn (array $need): string => (string) $need['id'], $group),
            'shots' => [$shotId],
            'requirement' => [
                'must_show' => array_map(static fn (array $need): array => ['shot' => $shotId, 'text' => (string) $need['purpose']], $needs),
                'first_frame' => $firstFrame === '' ? [] : [['shot' => $shotId, 'text' => $firstFrame]],
                'pose' => $pose === '' ? [] : [['shot' => $shotId, 'text' => $pose]],
                'view' => self::view($camera, (array) $settings['prompt']['view']),
                'done' => array_values((array) ($checkpoint['done'] ?? [])),
                'pending' => array_values((array) ($checkpoint['pending'] ?? [])),
                'absent' => self::absentParts($shot, $members, (array) $sources['shown']),
                'decisions' => [],
            ],
            'earlier_decisions' => [],
            'draft' => $facts->lines(),
            'facts' => $facts->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $reference  a planned reference with its source fingerprint
     * @param  list<array{question: string, answer: string, source?: string}>  $answers  everything a person decided for this cell, in order
     * @param  array<string, mixed>  $labels
     * @return array<string, mixed> the reference with the newest answer to each question: made for its present sources it is a fact and a line, made for other sources it stays out of the prompt and is listed for a person to confirm again; an older answer to the same question has no effect and stays only in the project history
     */
    private static function withDecisions(array $reference, array $answers, array $labels): array
    {
        $facts = MotoReferenceFacts::from($reference['facts'], $reference['draft'], ['state' => (string) $reference['state'], 'checkpoint' => $reference['checkpoint']['label'] ?? null]);
        $newest = [];

        foreach ($answers as $answer) {
            $newest[self::decisionId((string) $answer['question'])] = $answer;
        }

        $current = [];
        $earlier = [];

        foreach ($newest as $id => $answer) {
            $decision = ['id' => (string) $id, 'question' => trim((string) $answer['question']), 'answer' => trim((string) $answer['answer'])];

            if (($answer['source'] ?? null) !== $reference['source_fingerprint']) {
                $earlier[] = $decision;

                continue;
            }

            $value = rtrim($decision['answer'], '.');
            $facts->line("{$labels['decided']} {$value}.", [$facts->add(null, null, 'decision', "{$decision['question']} — {$value}", "decisions.answers.{$reference['id']}.{$id}")], 'moment', true);
            $current[] = $decision;
        }

        $reference['draft'] = $facts->lines();
        $reference['facts'] = $facts->all();
        $reference['requirement']['decisions'] = $current;
        $reference['earlier_decisions'] = $earlier;

        return $reference;
    }

    /** @return string the id of a question, the same whatever its case, spacing or final punctuation */
    private static function decisionId(string $question): string
    {
        return 'D'.substr(hash('sha256', trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($question)), " \t\n\r.?!:;,")), 0, 10);
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  array<string, mixed>  $state
     * @param  list<array<string, mixed>>  $needs  the needs the image answers
     * @return list<array{path: string, text: string}> the texts that hold at the first frame and may stand as evidence of a surface; what the shot goes on to do is not among them
     */
    private static function firstFrameTexts(array $shot, array $state, array $needs): array
    {
        $shotId = (string) $shot['id'];
        $carried = array_values((array) ($shot['carried_from']['carries'] ?? []));

        return [
            ...(is_string($state['surface'] ?? null) ? [['path' => "design.build_states.{$state['id']}.surface", 'text' => $state['surface']]] : []),
            ['path' => "shots.{$shotId}.first_frame", 'text' => (string) ($shot['first_frame'] ?? '')],
            ...(is_string($shot['continuity_start']['placement'] ?? null) ? [['path' => "shots.{$shotId}.continuity_start.placement", 'text' => $shot['continuity_start']['placement']]] : []),
            ...array_map(static fn (int $index, mixed $text): array => ['path' => "shots.{$shotId}.carried_from.carries[{$index}]", 'text' => (string) $text], array_keys($carried), $carried),
            ...array_map(static fn (array $need): array => ['path' => "shots.{$shotId}.reference_needs[{$need['index']}].purpose", 'text' => (string) $need['purpose']], $needs),
        ];
    }

    /**
     * @param  list<string>  $members  the parts of the cell
     * @param  list<array<string, mixed>>  $cellParts
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $sources
     * @param  bool  $building  the state is a physical build before the finished motorcycle
     */
    private static function designRules(MotoReferenceFacts $facts, array $members, array $cellParts, array $state, array $sources, bool $building): void
    {
        $onMotorcycle = array_filter($cellParts, static fn (array $part): bool => $part['location'] !== 'off_motorcycle') !== [];

        if ($onMotorcycle && is_string($sources['symmetry'])) {
            $facts->add(null, null, 'rule', $sources['symmetry'], 'design.motorcycle.symmetry', [], MotoReferenceFacts::CONSTANT);
        }

        foreach ((array) $sources['rules'] as $rule) {
            if (array_intersect($members, [...(array) ($rule['scope'] ?? []), ...(array) ($rule['refs'] ?? [])]) !== []) {
                $facts->add(null, null, 'rule', "{$rule['kind']}: {$rule['description']}", 'design.identity_rules.'.$rule['id'], [], $rule['kind'] === 'must_keep' && $building ? MotoReferenceFacts::TARGET : MotoReferenceFacts::CONSTANT);
            }
        }

        $order = (array) $sources['state_order'];

        foreach ((array) $sources['features'] as $feature) {
            if (array_intersect($members, (array) ($feature['parts'] ?? [])) === []) {
                continue;
            }

            $time = ($order[$state['id']] ?? 0) < ($order[$feature['from_state'] ?? ''] ?? 0) ? MotoReferenceFacts::TARGET : MotoReferenceFacts::CONSTANT;

            if (is_string($feature['visible_expression'] ?? null)) {
                $facts->add(null, null, 'rule', rtrim($feature['visible_expression'], '.'), "design.features.{$feature['id']}.visible_expression", [], $time);
            }

            foreach ((array) ($feature['measures'] ?? []) as $name => $measure) {
                $facts->add(null, null, 'rule', str_replace('_', ' ', (string) $name).": {$measure['value']} {$measure['unit']} (".str_replace('_', ' ', mb_strtolower((string) $measure['basis'])).')', "design.features.{$feature['id']}.measures.{$name}", [], $time);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  list<string>  $members  the parts of the cell
     * @param  array<string, true>  $shown  every part a panel of the model sheet shows
     * @return list<string> the names of the parts the shot says are not there yet and the input image can still pull in
     */
    private static function absentParts(array $shot, array $members, array $shown): array
    {
        $names = [];

        foreach ((array) ($shot['absent_parts'] ?? []) as $part) {
            if (isset($shown[$part['part']]) && ! in_array($part['part'], $members, true)) {
                $names[] = (string) $part['name'];
            }
        }

        return $names;
    }

    /**
     * @param  array<string, array<string, mixed>>  $references  the references planned so far
     * @param  array<string, mixed>  $reference  a new reference
     * @return ?string the planned reference the new one can share: the same parts, placement, state, checkpoint and regime, no request of its own, and both angles within the threshold
     */
    private static function share(array $references, array $reference, float $threshold): ?string
    {
        if ($reference['camera']['request'] !== null) {
            return isset($references[$reference['id']]) ? $reference['id'] : null;
        }

        foreach ($references as $id => $planned) {
            if ($planned['base'] !== $reference['base'] || $planned['regime'] !== $reference['regime'] || $planned['camera']['request'] !== null) {
                continue;
            }

            $azimuth = abs(fmod((float) $planned['camera']['azimuth_deg'] - (float) $reference['camera']['azimuth_deg'] + 540.0, 360.0) - 180.0);
            $elevation = abs((float) $planned['camera']['elevation_deg'] - (float) $reference['camera']['elevation_deg']);

            if ($azimuth <= $threshold && $elevation <= $threshold && $planned['camera']['elevation_deg'] !== null && $reference['camera']['elevation_deg'] !== null) {
                return (string) $id;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $planned
     * @param  array<string, mixed>  $reference  the same image asked for by another shot
     * @return array<string, mixed> the planned reference serving the other shot's needs too, with that shot's purposes as further draft lines, facts and requirements
     */
    private static function serve(array $planned, array $reference): array
    {
        $planned['needs'] = [...$planned['needs'], ...$reference['needs']];
        $planned['shots'] = array_values(array_unique([...$planned['shots'], ...$reference['shots']]));

        foreach (['must_show', 'first_frame', 'pose'] as $list) {
            $planned['requirement'][$list] = [...$planned['requirement'][$list], ...$reference['requirement'][$list]];
        }

        $facts = MotoReferenceFacts::from($planned['facts'], $planned['draft'], ['state' => (string) $planned['state'], 'checkpoint' => $planned['checkpoint']['label'] ?? null]);
        $map = [];

        foreach ($reference['facts'] as $fact) {
            if (str_starts_with((string) $fact['source_path'], 'shots.')) {
                $map[$fact['id']] = $facts->add($fact['part_id'], $fact['element'], $fact['topic'], $fact['value'], $fact['source_path'], $fact['basis'] ?? [], $fact['time']);
            }
        }

        foreach ($reference['draft'] as $line) {
            $refs = array_values(array_intersect_key($map, array_flip($line['fact_refs'])));

            if ($refs !== [] && count($refs) === count($line['fact_refs'])) {
                $facts->line($line['text'], $refs, $line['section'], $line['must_rewrite']);
            }
        }

        $planned['draft'] = $facts->lines();
        $planned['facts'] = $facts->all();

        return $planned;
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  array<string, mixed>  $need  an identity need
     * @param  list<string>  $finished
     * @param  array<string, array<string, mixed>>  $views
     * @param  array<string, array<string, mixed>>  $references
     * @param  list<array<string, mixed>>  $bindings
     * @param  array<string, mixed>  $sources  what plan() reads from the design, the work and the decisions of the project
     * @return array<string, mixed> the whole motorcycle of the model sheet at a finished state; at an earlier state the detail image of this shot that holds every part in frame, the camera's part among them; otherwise a state image of the whole motorcycle, carried under reference for the caller to add
     */
    private static function identity(array $shot, array $need, array $finished, array $views, array $references, array $bindings, array $state, array $sources): array
    {
        $parts = (array) $sources['parts'];

        if (in_array($shot['state'] ?? null, $finished, true)) {
            $view = MotoShots::nearestView((array) $shot['camera'], array_keys($views));

            return $view === null
                ? ['need_id' => $need['id'], 'type' => 'none', 'source' => null, 'rule' => 'model_sheet_missing']
                : ['need_id' => $need['id'], 'type' => 'model_sheet', 'source' => $view, 'rule' => 'identity_whole'];
        }

        $inFrame = array_values(array_filter(array_map(static fn (array $item): string => (string) $item['part'], (array) ($shot['parts'] ?? [])), static fn (string $id): bool => isset($parts[$id])));

        if (($shot['camera']['target'] ?? null) === 'part') {
            foreach ($bindings as $binding) {
                if ($binding['type'] !== 'reference' || ! str_starts_with((string) $binding['need_id'], $shot['id'].'#')) {
                    continue;
                }

                $reference = $references[$binding['source']];
                $subjects = array_map(static fn (array $part): string => $part['part'], array_filter($reference['parts'], static fn (array $part): bool => $part['role'] === 'subject'));

                if (array_diff($inFrame, $subjects) === [] && in_array($shot['camera']['target_part'] ?? null, $subjects, true)) {
                    return ['need_id' => $need['id'], 'type' => 'reference', 'source' => $reference['id'], 'rule' => 'identity_scope'];
                }
            }

            return ['need_id' => $need['id'], 'type' => 'none', 'source' => null, 'rule' => 'identity_without_detail'];
        }

        $group = array_map(static fn (string $id): array => ['id' => $need['id'], 'index' => $need['index'], 'target' => $id, 'purpose' => $need['purpose']], $inFrame);
        $reference = self::reference($shot, $group, $state, $sources, 'state');
        $reference['needs'] = [$need['id']];

        return ['need_id' => $need['id'], 'type' => 'reference', 'source' => $reference['id'], 'rule' => 'identity_state', 'reference' => $reference];
    }

    /**
     * @param  list<string>  $subjects
     * @param  list<array<string, mixed>>  $parts
     * @param  ?array<string, mixed>  $checkpoint
     * @param  array<string, mixed>  $camera
     */
    private static function key(string $kind, array $subjects, array $parts, string $state, ?array $checkpoint, array $camera): string
    {
        $where = ['on_motorcycle' => 'on', 'off_motorcycle' => 'off'];
        $lead = $parts[0] ?? ['location' => null, 'presentation' => ''];
        $location = $where[$lead['location'] ?? ''] ?? ($lead['presentation'] === 'model' ? 'model' : ($lead['presentation'] === 'drawing' ? 'drawing' : 'na'));
        $segments = [
            match ($kind) {
                'detail_set' => 'detail_set',
                'state' => 'state',
                default => 'part',
            },
            $kind === 'state' ? null : implode('+', $subjects),
            $state,
            $checkpoint['label'] ?? null,
            $kind === 'part_detail' ? $location : null,
            self::angle('az', $camera['azimuth_deg']),
            self::angle('el', $camera['elevation_deg']),
            $camera['request'] === null ? null : 'req-'.$camera['request'],
        ];

        return implode('.', array_filter($segments, static fn (?string $segment): bool => $segment !== null && $segment !== ''));
    }

    public static function angle(string $prefix, int|float|null $value): string
    {
        if ($value === null) {
            return $prefix.'-na';
        }

        $sign = $value < 0 ? '-' : '';
        $absolute = abs((float) $value);
        $whole = (int) floor($absolute);
        $fraction = rtrim(rtrim(number_format($absolute - $whole, 3, '.', ''), '0'), '.');

        return $prefix.$sign.str_pad((string) $whole, 3, '0', STR_PAD_LEFT).($fraction === '0' || $fraction === '' ? '' : substr($fraction, 1));
    }

    /**
     * @param  list<array<string, mixed>>  $references
     * @param  array<string, mixed>  $manifest
     * @return list<array{id: string, kind: string, required_by: list<string>, source: array<string, mixed>}> the sources the masters need: the approved model sheet master for every reference, and its greyscale copy, made by code, for the grey references
     */
    private static function dependencies(array $references, array $manifest): array
    {
        $grey = array_values(array_map(static fn (array $reference): string => $reference['id'], array_filter($references, static fn (array $reference): bool => $reference['regime'] === 'grey')));
        $all = array_values(array_map(static fn (array $reference): string => $reference['id'], $references));

        return array_values(array_filter([
            $all === [] ? null : ['id' => 'model_sheet_master', 'kind' => 'approved_image', 'required_by' => $all, 'source' => ['artifact_id' => $manifest['model_sheet_artifact_id'] ?? null, 'sha256' => $manifest['model_sheet_sha256'] ?? null]],
            $grey === [] ? null : ['id' => 'model_sheet_greyscale', 'kind' => 'derived_image', 'required_by' => $grey, 'source' => ['from' => 'model_sheet_master', 'transform' => 'greyscale-v1', 'sha256' => $manifest['model_sheet_sha256'] ?? null]],
        ]));
    }

    private static function number(int|float $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
