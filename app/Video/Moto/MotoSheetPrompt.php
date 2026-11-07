<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoSheetPrompt
{
    public const VERSION = 'moto-sheet-prompt-v1';

    /** @var list<string> the units of the figures that say nothing about the shape */
    private const NON_SHAPE_UNITS = ['kg', 'l', 'cc'];

    /** @var array<string, int> the azimuth each side of the motorcycle faces */
    private const SIDES = ['front' => 0, 'left' => 90, 'rear' => 180, 'right' => 270];

    /** @var float how far a side must turn to the camera to count as seen: about 67 degrees off its axis */
    private const SEEN_SIDE = 0.38;

    /** @var float how far the top must turn to the camera to count as seen: an elevation of 30 degrees */
    private const SEEN_TOP = 0.5;

    /**
     * @param  array<string, mixed>  $data  the data of the approved design
     * @param  array<string, mixed>  $config  video.moto.model_sheet
     * @param  array<string, mixed>  $pinned  ModelSheetQaService::fixedRules of that config
     */
    public static function compile(array $data, array $config, array $pinned): string
    {
        $neverShown = array_column(array_filter($data['identity_rules'], static fn (array $rule): bool => $rule['kind'] === 'must_not'), 'description');

        return implode("\n\n", array_filter([
            (string) $pinned['layout'],
            (string) $config['camera_convention'],
            self::shape($data),
            self::surfaces($data),
            self::displayed($data),
            ...self::panels($data, $config, $pinned),
            'Framing: '.$pinned['framing'].' '.implode(' ', (array) $pinned['detail_framing']),
            'Rules: '.implode(' ', (array) $pinned['rules']),
            $neverShown === [] ? '' : self::listed('This motorcycle never shows:', $neverShown),
            (string) $pinned['presentation'],
            'Studio: '.$pinned['studio'].'.',
        ], static fn (string $block): bool => $block !== ''));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $pinned
     * @return list<string> what stops the render: a blocking source issue a view names and every fault the design check finds in a final prompt
     */
    public static function problems(array $data, array $pinned, string $prompt): array
    {
        $issues = array_column($data['source_issues'], null, 'id');
        $problems = [];

        foreach ($data['model_sheet']['views'] as $view) {
            foreach ((array) $view['issues'] as $id) {
                $issue = $issues[$id] ?? ['problem' => 'không có trong source_issues', 'blocking' => true];

                if ($issue['blocking']) {
                    $problems[] = "{$view['id']}: {$id}: {$issue['problem']}";
                }
            }
        }

        $data['model_sheet']['final_prompt'] = $prompt;

        return [...$problems, ...MotoDesignCheck::promptIssues($data, $pinned)];
    }

    /** @param  array<string, mixed>  $data */
    private static function shape(array $data): string
    {
        $parts = array_column($data['parts'], null, 'id');
        $motorcycle = $data['motorcycle'];
        $figures = [];

        foreach ($motorcycle['specs'] as $key => $spec) {
            if ($key !== 'justifications' && is_array($spec) && ($spec['value'] ?? null) !== null && ! in_array($spec['unit'] ?? '', self::NON_SHAPE_UNITS, true)) {
                $figures[] = ucfirst(str_replace('_', ' ', (string) $key)).' '.self::figure($spec);
            }
        }

        $lines = [];

        foreach ($data['systems'] as $system) {
            foreach ((array) $system['lines'] as $line) {
                if ($line['exposure'] !== 'hidden') {
                    $lines[] = $line['function'].' ('.$line['kind'].', '.($line['exposure'] === 'partial' ? 'partially exposed' : 'exposed').'): '.$line['routing'].'.';
                }
            }
        }

        $present = array_filter($parts, static fn (array $part): bool => $part['present']);
        $absent = array_filter($parts, static fn (array $part): bool => ! $part['present']);

        return implode("\n", array_filter([
            'The motorcycle: '.$motorcycle['silhouette'],
            'Left and right: '.$motorcycle['symmetry'],
            'Figures: '.implode(', ', $figures).'.',
            self::listed('Parts:', array_map(self::partLine(...), array_values($present))),
            self::listed('Features:', array_map(static fn (array $feature): string => self::featureLine($feature, $parts), $data['features'])),
            $lines === [] ? '' : self::listed('Hoses, cables and wires that show:', $lines),
            $absent === [] ? '' : self::listed('Not on this motorcycle:', array_map(static fn (array $part): string => $part['name'].': '.$part['description'], array_values($absent))),
            'Notes: '.$motorcycle['engineering_notes'],
        ], static fn (string $block): bool => $block !== ''));
    }

    /** @param  array<string, mixed>  $part */
    private static function partLine(array $part): string
    {
        $elements = array_map(static fn (array $element): string => $element['element'].' × '.$element['count'].', '.$element['arrangement'].(is_string($element['size'] ?? null) ? ', '.$element['size'] : ''), (array) $part['elements']);
        $notes = array_column((array) $part['relations'], 'note');

        return $part['name'].': '.$part['description'].implode('', array_map(static fn (string $text): string => ' '.$text.'.', [...$elements, ...$notes]));
    }

    /**
     * @param  array<string, mixed>  $feature
     * @param  array<string, array<string, mixed>>  $parts
     */
    private static function featureLine(array $feature, array $parts): string
    {
        $names = array_values(array_filter(array_map(static fn (string $id): ?string => $parts[$id]['name'] ?? null, (array) $feature['parts'])));
        $measures = [];

        foreach ((array) $feature['measures'] as $key => $measure) {
            if (($measure['value'] ?? null) !== null && ! in_array($measure['unit'] ?? '', self::NON_SHAPE_UNITS, true)) {
                $measures[] = str_replace('_', ' ', (string) $key).' '.self::figure($measure);
            }
        }

        return $feature['name'].($names === [] ? '' : ' (a feature of '.implode(', ', $names).', not a separate part)').': '.$feature['visible_expression'].($measures === [] ? '' : ' Measures: '.implode(', ', $measures).'.');
    }

    /** @param  array<string, mixed>  $data */
    private static function surfaces(array $data): string
    {
        $palette = array_column($data['motorcycle']['palette'], null, 'id');
        $surface = static fn (array $item): string => $item['element'].': '.$item['material'].', '.$item['finish'].(isset($palette[$item['color_id'] ?? '']) ? ' in '.$palette[$item['color_id']]['name'] : '');
        $present = array_filter($data['parts'], static fn (array $part): bool => $part['present']);

        return implode("\n", [
            self::listed('Colours:', array_map(static fn (array $colour): string => $colour['name'].': '.$colour['finish'].'. Applied to: '.$colour['applied_to'].'.', array_values($palette))),
            self::listed('Materials:', array_map(static fn (array $material): string => $material['element'].': '.$material['material'].', '.$material['finish'], $data['motorcycle']['materials'])),
            self::listed('Surfaces of each part:', array_map(static fn (array $part): string => $part['name'].': '.implode('; ', array_map($surface, $part['surfaces'])), array_values($present))),
            'Lights: '.$data['motorcycle']['lighting_signature']['text'],
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private static function displayed(array $data): string
    {
        $shown = [];

        foreach ($data['parts'] as $part) {
            foreach ((array) $part['configurations'] as $configuration) {
                if (in_array($configuration['id'], (array) $data['model_sheet']['display_configuration'], true)) {
                    $shown[] = $part['name'].': '.$configuration['description'];
                }
            }
        }

        return 'Displayed configuration: '.implode('; ', $shown).'.';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $pinned
     * @return list<string> one block per panel: its pinned line, its camera and what it shows at each level of visibility
     */
    private static function panels(array $data, array $config, array $pinned): array
    {
        $parts = array_column($data['parts'], null, 'id');
        $features = array_column($data['features'], null, 'id');
        $others = array_column($data['connections'], 'description', 'id') + array_column($data['systems'], 'name', 'id');
        $order = (array) $config['master']['order'];
        $blocks = [];

        foreach ($data['model_sheet']['views'] as $view) {
            $detail = in_array($view['id'], (array) $config['master']['detail_views'], true);
            $block = $pinned['panels'][array_search($view['id'], $order, true)].'. Camera: '.self::camera($view['camera']).' Target: '.$view['target'].'.';

            foreach (['visible' => 'Clearly visible:', 'partial' => 'Partly visible through natural occlusion:', 'uncertain' => 'May be hidden from this camera:'] as $level => $title) {
                $items = array_map(static fn (string $id): string => self::viewItem($id, $level, $view['camera'], $detail, $parts, $features, $others), $view[$level]);
                $block .= $items === [] ? '' : "\n".self::listed($title, $items);
            }

            $blocks[] = $block;
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $camera
     * @param  array<string, array<string, mixed>>  $parts
     * @param  array<string, array<string, mixed>>  $features
     * @param  array<string, string>  $others  what the design writes for a connection or a system a view lists
     * @return string a part with the faces this camera sees; a feature named as one, with what it looks like unless it is plainly in view; an id the design does not hold stays as it is, so the prompt check stops it
     */
    private static function viewItem(string $id, string $level, array $camera, bool $detail, array $parts, array $features, array $others): string
    {
        if (isset($features[$id])) {
            return 'the feature '.$features[$id]['name'].($level === 'visible' ? '' : ': '.$features[$id]['visible_expression']);
        }

        if (! isset($parts[$id])) {
            return $others[$id] ?? $id;
        }

        $faces = self::facesSeen($parts[$id], $camera, $detail);

        return $parts[$id]['name'].($faces === [] ? '' : ': '.implode('; ', $faces));
    }

    /**
     * @param  array<string, mixed>  $part
     * @param  array<string, mixed>  $camera
     * @param  bool  $bestOnly  a close-up keeps the one face that looks at the camera most, so the rest of the part does not pull the frame away from its subject
     * @return list<string> the text of the faces this camera sees
     */
    public static function facesSeen(array $part, array $camera, bool $bestOnly): array
    {
        $turn = self::turnToCamera($camera);
        $faces = array_values(array_filter((array) $part['faces'], static fn (array $face): bool => isset($turn[$face['facing']])));

        if ($bestOnly) {
            usort($faces, static fn (array $a, array $b): int => $turn[$b['facing']] <=> $turn[$a['facing']]);

            return array_column(array_filter(array_slice($faces, 0, 1), static fn (array $face): bool => $turn[$face['facing']] > 0), 'description');
        }

        return array_column(array_filter($faces, static fn (array $face): bool => $turn[$face['facing']] >= ($face['facing'] === 'top' ? self::SEEN_TOP : self::SEEN_SIDE)), 'description');
    }

    /**
     * @param  array<string, mixed>  $camera
     * @return array<string, float> how far each side turns to the camera, from 1 straight at it to -1 straight away
     */
    private static function turnToCamera(array $camera): array
    {
        $elevation = deg2rad((float) ($camera['elevation_deg'] ?? 0));
        $turn = ['top' => sin($elevation)];

        if (($camera['azimuth_deg'] ?? null) !== null) {
            foreach (self::SIDES as $side => $azimuth) {
                $turn[$side] = cos($elevation) * cos(deg2rad((float) $camera['azimuth_deg'] - $azimuth));
            }
        }

        return $turn;
    }

    /** @param  array<string, mixed>  $camera */
    private static function camera(array $camera): string
    {
        return implode(', ', array_filter([
            ($camera['azimuth_deg'] ?? null) === null ? '' : 'azimuth '.$camera['azimuth_deg'],
            'elevation '.$camera['elevation_deg'],
            (string) $camera['projection'],
            isset($camera['lens_mm']) ? $camera['lens_mm'].' mm lens' : '',
            isset($camera['front']) ? 'the front of the motorcycle '.$camera['front'] : '',
        ], static fn (string $piece): bool => $piece !== '')).'.';
    }

    /** @param  array<string, mixed>  $figure  a {value, unit} figure */
    private static function figure(array $figure): string
    {
        return $figure['value'].(is_string($figure['unit'] ?? null) ? ' '.$figure['unit'] : '');
    }

    /** @param  list<string>  $items */
    private static function listed(string $title, array $items): string
    {
        return $title."\n".implode("\n", array_map(static fn (string $item): string => '- '.$item, $items));
    }
}
