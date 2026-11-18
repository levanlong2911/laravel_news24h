<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Services\Video\ModelSheetQaService;
use App\Services\Video\ModelSheetService;
use App\Video\ModelSheet\ModelSheetMaster;

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

    /** @var ?array<string, string> the texts of the design the prompt being composed has taken so far, by the path each was taken from; null while no prompt is being composed */
    private static ?array $taken = null;

    /** @var array<string, string> the name of each record of the design by its id, while a prompt is being composed */
    private static array $names = [];

    public const VERSION_THEMED = 'moto-sheet-prompt-v2';

    /**
     * @param  bool  $themed  whether the sheet is of a design that records its ornament and shows it
     * @return array<string, mixed> video.moto.model_sheet, for a themed design with the presentation that lets the ornament of the motorcycle show
     */
    public static function config(bool $themed): array
    {
        $config = (array) config('video.moto.model_sheet');

        if (! $themed) {
            return $config;
        }

        $policy = (array) config('video.moto.model_sheet_themed');
        $config['suffix'] = (string) $policy['suffix'];
        $config['master']['prompt']['rules'] = (array) $policy['rules'];

        return $config;
    }

    /**
     * @param  array<string, mixed>  $data  the data of a design
     * @param  array<string, mixed>  $config  the model-sheet config of the design
     * @return list<array{id: string, view: string, row: int, column: int, x: int, y: int, width: int, height: int}> the panels of the master, a detail panel labelled with the target the design gives that view
     */
    public static function panelViews(array $data, array $config, ModelSheetMaster $master): array
    {
        $targets = array_column((array) ($data['model_sheet']['views'] ?? []), 'target', 'id');

        return array_map(static function (array $view) use ($targets, $config): array {
            $target = in_array($view['id'], (array) $config['master']['detail_views'], true) ? ($targets[$view['id']] ?? null) : null;

            return is_string($target) && trim($target) !== '' ? ['view' => trim($target)] + $view : $view;
        }, ModelSheetService::views($config, $master));
    }

    /**
     * @param  array<string, mixed>  $data  the data of a themed design that writes no model-sheet prompt
     * @return list<string> what would stop the model-sheet prompt composed from this design, found before the design is kept
     */
    public static function composedProblems(array $data): array
    {
        [$composed, $pinned] = self::composedThemed($data);

        // Each finding keeps the decision of its rule; only the words that name the prompt change.
        return array_map(
            static fn (Finding|string $problem): Finding|string => $problem instanceof Finding
                ? $problem->retold((string) preg_replace('/^final_prompt/u', 'Prompt model sheet ghép từ thiết kế', $problem->message))
                : (string) preg_replace('/^final_prompt/u', 'Prompt model sheet ghép từ thiết kế', $problem),
            self::findings($data, $pinned, $composed['prompt'], $composed['sources']),
        );
    }

    /**
     * @param  array<string, mixed>  $data  the data of a themed design that writes no model-sheet prompt
     * @return array<string, string> every text of the design the model-sheet prompt composed from it takes, by its path with the id of its record — the same composition composedProblems() checks
     */
    public static function composedSources(array $data): array
    {
        return self::composedThemed($data)[0]['sources'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array{prompt: string, sources: array<string, string>}, 1: array<string, mixed>} the prompt composed from a themed design and the fixed rules it was composed with
     */
    private static function composedThemed(array $data): array
    {
        $config = self::config(true);
        $pinned = ModelSheetQaService::fixedRules(['views' => self::panelViews($data, $config, new ModelSheetMaster((array) $config['master'])), 'layout_guide' => false], $config);

        return [self::composed($data, $config, $pinned, true), $pinned];
    }

    /**
     * @param  array<string, mixed>  $data  the data of the approved design
     * @param  array<string, mixed>  $config  video.moto.model_sheet
     * @param  array<string, mixed>  $pinned  ModelSheetQaService::fixedRules of that config
     * @param  bool  $themed  whether the design records its ornament and what an image must keep
     * @return array{prompt: string, sources: array<string, string>} the prompt and every text of the design written into it, as written, by the path it was taken from with the id of its record; a text the prompt does not take is not among them. An id a text uses to refer to a colour, a part, a position, a feature or a system is written as the name of that record
     */
    public static function composed(array $data, array $config, array $pinned, bool $themed = false): array
    {
        [$outer, $outerNames] = [self::$taken, self::$names];
        self::$taken = [];
        self::$names = self::names($data);

        try {
            $prompt = self::write($data, $config, $pinned, $themed);

            return ['prompt' => $prompt, 'sources' => self::$taken];
        } finally {
            [self::$taken, self::$names] = [$outer, $outerNames];
        }
    }

    /**
     * @param  array<string, mixed>  $data  the data of the approved design
     * @param  array<string, mixed>  $config  video.moto.model_sheet
     * @param  array<string, mixed>  $pinned  ModelSheetQaService::fixedRules of that config
     * @param  bool  $themed  whether the design records its ornament and what an image must keep
     */
    public static function compile(array $data, array $config, array $pinned, bool $themed = false): string
    {
        return self::composed($data, $config, $pinned, $themed)['prompt'];
    }

    /**
     * @param  string  $path  where the text sits in the design, with the id of its record
     * @return string the text, noted as taken into the prompt being composed
     */
    private static function take(string $path, mixed $text): string
    {
        if (! is_string($text) || trim($text) === '') {
            return (string) $text;
        }

        $written = self::named($text);

        if (self::$taken !== null) {
            self::$taken[$path] = $written;
        }

        return $written;
    }

    /**
     * @return string the text with every id of a record of the design it refers to written as the name of that record, since an image model cannot look an id up; an id the design does not hold stays as it is
     */
    private static function named(string $text): string
    {
        if (self::$names === []) {
            return $text;
        }

        $ids = implode('|', array_map(static fn (string $id): string => preg_quote($id, '/'), array_keys(self::$names)));

        return (string) preg_replace_callback('/(?<![\p{L}\p{N}-])('.$ids.')(?![\p{L}\p{N}-])/u', static fn (array $found): string => self::$names[$found[1]], $text);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string> the name of every colour, part, position of a part, feature and system of the design by its id, the longer ids first
     */
    private static function names(array $data): array
    {
        $names = array_column((array) ($data['motorcycle']['palette'] ?? []), 'name', 'id')
            + array_column((array) ($data['features'] ?? []), 'name', 'id')
            + array_column((array) ($data['systems'] ?? []), 'name', 'id');

        foreach ((array) ($data['parts'] ?? []) as $part) {
            $names[(string) $part['id']] = (string) $part['name'];

            foreach ((array) ($part['configurations'] ?? []) as $configuration) {
                $names[(string) $configuration['id']] = $part['name'].' ('.$configuration['name'].')';
            }
        }

        $names = array_filter($names, static fn (mixed $name, mixed $id): bool => is_string($name) && $name !== '' && is_string($id) && $id !== '', ARRAY_FILTER_USE_BOTH);
        uksort($names, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $names;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return string the name of a part, taken from the part that holds it; the id when the design has no such part
     */
    private static function partName(array $data, string $id): string
    {
        $at = array_search($id, array_column($data['parts'], 'id'), true);

        return $at === false ? $id : self::take("parts.{$at}.name — {$id}", $data['parts'][$at]['name']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $pinned
     */
    private static function write(array $data, array $config, array $pinned, bool $themed): string
    {
        $neverShown = [];
        $alwaysKept = [];

        foreach ($data['identity_rules'] as $at => $rule) {
            if ($rule['kind'] === 'must_not') {
                $neverShown[] = self::take("identity_rules.{$at}.description — {$rule['id']}", $rule['description']);
            } elseif ($themed && $rule['kind'] === 'must_keep') {
                $alwaysKept[] = self::take("identity_rules.{$at}.description — {$rule['id']}", $rule['description']);
            }
        }

        return implode("\n\n", array_filter([
            (string) $pinned['layout'],
            (string) $config['camera_convention'],
            self::shape($data),
            self::surfaces($data),
            $themed ? self::ornament($data) : '',
            self::displayed($data),
            ...self::panels($data, $config, $pinned),
            'Framing: '.$pinned['framing'].' '.implode(' ', (array) $pinned['detail_framing']),
            'Rules: '.implode(' ', (array) $pinned['rules']),
            $alwaysKept === [] ? '' : self::listed('This motorcycle keeps in every panel:', $alwaysKept),
            $neverShown === [] ? '' : self::listed('This motorcycle never shows:', $neverShown),
            (string) $pinned['presentation'],
            'Studio: '.$pinned['studio'].'.',
        ], static fn (string $block): bool => $block !== ''));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return string every marking the design shows in the model sheet: the part that carries it, where on it, what it is, how it is made, how much it covers and its colours; empty when the design shows none
     */
    private static function ornament(array $data): string
    {
        $palette = array_column($data['motorcycle']['palette'], 'id');
        $lines = [];

        foreach ($data['motorcycle']['markings'] as $at => $marking) {
            if ($marking['shown_in_model_sheet']) {
                $from = "motorcycle.markings.{$at}";
                $of = " — {$marking['part']}";
                $colours = [];

                foreach ((array) $marking['color_ids'] as $id) {
                    $colour = array_search($id, $palette, true);

                    if ($colour !== false) {
                        $colours[] = self::take("motorcycle.palette.{$colour}.name — {$id}", $data['motorcycle']['palette'][$colour]['name']);
                    }
                }

                $lines[] = self::partName($data, (string) $marking['part']).', '.self::take("{$from}.placement{$of}", $marking['placement']).': '.self::take("{$from}.description{$of}", $marking['description'])
                    .' Made by: '.self::take("{$from}.technique{$of}", $marking['technique']).'. Covers: '.self::take("{$from}.coverage{$of}", $marking['coverage']).'.'.($colours === [] ? '' : ' Colours: '.implode(', ', $colours).'.');
            }
        }

        return $lines === [] ? '' : self::listed('Ornament on the motorcycle, drawn in every panel that sees it, exactly as described:', $lines);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $pinned
     * @param  array<string, string>  $sources  the design texts composed() wrote into that prompt, so a named source is traced to its fields
     * @return list<string> what stops the render: a blocking source issue a view names and every fault the design check finds in a final prompt that holds it (RULE or a requirement); its notes on wording never do
     */
    public static function problems(array $data, array $pinned, string $prompt, array $sources = []): array
    {
        // What stops the render: a note on the wording of the prompt (an id, a field name) is shown by the design check and never holds the render. The render reads the rest as text, as it always did.
        $holding = array_filter(self::findings($data, $pinned, $prompt, $sources), static fn (Finding|string $finding): bool => ! ($finding instanceof Finding && $finding->kind === MotoCheckRules::NOTE));

        return array_values(array_map('strval', $holding));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $pinned
     * @param  array<string, string>  $sources
     * @return list<Finding> problems(), each with the decision and the code of its rule: for the design check
     */
    private static function findings(array $data, array $pinned, string $prompt, array $sources = []): array
    {
        $issues = array_column($data['source_issues'], null, 'id');
        $problems = [];

        foreach ($data['model_sheet']['views'] as $view) {
            foreach ((array) $view['issues'] as $id) {
                $issue = $issues[$id] ?? ['problem' => 'không có trong source_issues', 'blocking' => true];

                if ($issue['blocking']) {
                    // A blocking source issue the design names stops the model sheet render: a condition of the next step.
                    $problems[] = Finding::requirement('MotoSheetPrompt.findings.source_issue', "{$view['id']}: {$id}: {$issue['problem']}");
                }
            }
        }

        return [...$problems, ...MotoDesignCheck::promptIssues($data, $pinned, $prompt, $sources, self::longestBlocks($prompt))];
    }

    /**
     * @return array<string, int> the four longest blocks of a composed prompt by the title that opens each, with their characters; the eight panels count as one block
     */
    public static function longestBlocks(string $prompt): array
    {
        $blocks = [];

        foreach (explode("\n\n", $prompt) as $block) {
            $panel = str_starts_with($block, 'Row ');
            $title = null;

            foreach (explode("\n", $block) as $line) {
                if ($title === null || (! $panel && ! str_starts_with($line, '- '))) {
                    $title = $panel ? 'Panels' : mb_substr((string) strtok($line, ':'), 0, 40);
                }

                $blocks[$title] = ($blocks[$title] ?? 0) + mb_strlen($line) + 1;
            }
        }

        arsort($blocks);

        return array_slice($blocks, 0, 4, true);
    }

    /** @param  array<string, mixed>  $data */
    private static function shape(array $data): string
    {
        $parts = array_column($data['parts'], null, 'id');
        $motorcycle = $data['motorcycle'];
        $silhouette = self::take('motorcycle.silhouette', $motorcycle['silhouette']);
        $symmetry = self::take('motorcycle.symmetry', $motorcycle['symmetry']);
        $figures = [];

        foreach ($motorcycle['specs'] as $key => $spec) {
            if ($key !== 'justifications' && is_array($spec) && ($spec['value'] ?? null) !== null && ! in_array($spec['unit'] ?? '', self::NON_SHAPE_UNITS, true)) {
                $figures[] = self::take("motorcycle.specs.{$key}", ucfirst(str_replace('_', ' ', (string) $key))).' '.self::figure($spec, "motorcycle.specs.{$key}");
            }
        }

        $lines = [];

        foreach ($data['systems'] as $at => $system) {
            foreach ((array) $system['lines'] as $index => $line) {
                if ($line['exposure'] !== 'hidden') {
                    $from = "systems.{$at}.lines.{$index}";
                    $of = ' — '.($line['id'] ?? $system['id'] ?? '');
                    $lines[] = self::take("{$from}.function{$of}", $line['function']).' ('.self::take("{$from}.kind{$of}", $line['kind']).', '.($line['exposure'] === 'partial' ? 'partially exposed' : 'exposed').'): '.self::take("{$from}.routing{$of}", $line['routing']).'.';
                }
            }
        }

        $present = [];
        $absent = [];

        foreach ($data['parts'] as $at => $part) {
            if ($part['present']) {
                $present[] = self::partLine($part, $at);
            } else {
                $absent[] = self::take("parts.{$at}.name — {$part['id']}", $part['name']).': '.self::take("parts.{$at}.description — {$part['id']}", $part['description']);
            }
        }

        $features = [];

        foreach ($data['features'] as $at => $feature) {
            $features[] = self::featureLine($feature, $at, $data, $parts);
        }

        return implode("\n", array_filter([
            'The motorcycle: '.$silhouette,
            'Left and right: '.$symmetry,
            'Figures: '.implode(', ', $figures).'.',
            self::listed('Parts:', $present),
            self::listed('Features:', $features),
            $lines === [] ? '' : self::listed('Hoses, cables and wires that show:', $lines),
            $absent === [] ? '' : self::listed('Not on this motorcycle:', $absent),
            'Notes: '.self::take('motorcycle.engineering_notes', $motorcycle['engineering_notes']),
        ], static fn (string $block): bool => $block !== ''));
    }

    /**
     * @param  array<string, mixed>  $part
     * @param  int  $at  where the part sits in the design
     */
    private static function partLine(array $part, int $at): string
    {
        $from = "parts.{$at}";
        $of = " — {$part['id']}";
        $more = [];

        foreach ((array) $part['elements'] as $index => $element) {
            $more[] = self::take("{$from}.elements.{$index}.element{$of}", $element['element']).' × '.$element['count'].', '.self::take("{$from}.elements.{$index}.arrangement{$of}", $element['arrangement'])
                .(is_string($element['size'] ?? null) ? ', '.self::take("{$from}.elements.{$index}.size{$of}", $element['size']) : '');
        }

        foreach ((array) $part['relations'] as $index => $relation) {
            if (isset($relation['note'])) {
                $more[] = self::take("{$from}.relations.{$index}.note{$of}", $relation['note']);
            }
        }

        return self::take("{$from}.name{$of}", $part['name']).': '.self::take("{$from}.description{$of}", $part['description']).implode('', array_map(static fn (string $text): string => ' '.$text.'.', $more));
    }

    /**
     * @param  array<string, mixed>  $feature
     * @param  int  $at  where the feature sits in the design
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string, mixed>>  $parts
     */
    private static function featureLine(array $feature, int $at, array $data, array $parts): string
    {
        $names = array_map(static fn (string $id): string => self::partName($data, $id), array_values(array_filter((array) $feature['parts'], static fn (string $id): bool => isset($parts[$id]['name']))));
        $measures = [];

        foreach ((array) $feature['measures'] as $key => $measure) {
            if (($measure['value'] ?? null) !== null && ! in_array($measure['unit'] ?? '', self::NON_SHAPE_UNITS, true)) {
                $measures[] = self::take("features.{$at}.measures.{$key} — {$feature['id']}", str_replace('_', ' ', (string) $key)).' '.self::figure($measure, "features.{$at}.measures.{$key}", " — {$feature['id']}");
            }
        }

        return self::take("features.{$at}.name — {$feature['id']}", $feature['name']).($names === [] ? '' : ' (a feature of '.implode(', ', $names).', not a separate part)').': '
            .self::take("features.{$at}.visible_expression — {$feature['id']}", $feature['visible_expression']).($measures === [] ? '' : ' Measures: '.implode(', ', $measures).'.');
    }

    /** @param  array<string, mixed>  $data */
    private static function surfaces(array $data): string
    {
        $ids = array_column($data['motorcycle']['palette'], 'id');
        $colours = [];
        $materials = [];
        $surfaces = [];

        foreach ($data['motorcycle']['palette'] as $at => $colour) {
            $from = "motorcycle.palette.{$at}";
            $colours[] = self::take("{$from}.name — {$colour['id']}", $colour['name']).': '.self::take("{$from}.finish — {$colour['id']}", $colour['finish']).'. Applied to: '.self::take("{$from}.applied_to — {$colour['id']}", $colour['applied_to']).'.';
        }

        foreach ($data['motorcycle']['materials'] as $at => $material) {
            $from = "motorcycle.materials.{$at}";
            $materials[] = self::take("{$from}.element", $material['element']).': '.self::take("{$from}.material", $material['material']).', '.self::take("{$from}.finish", $material['finish']);
        }

        foreach ($data['parts'] as $at => $part) {
            if (! $part['present']) {
                continue;
            }

            $items = [];

            foreach ($part['surfaces'] as $index => $item) {
                $from = "parts.{$at}.surfaces.{$index}";
                $colour = array_search($item['color_id'] ?? '', $ids, true);
                $items[] = self::take("{$from}.element — {$part['id']}", $item['element']).': '.self::take("{$from}.material — {$part['id']}", $item['material']).', '.self::take("{$from}.finish — {$part['id']}", $item['finish'])
                    .($colour === false ? '' : ' in '.self::take("motorcycle.palette.{$colour}.name — {$ids[$colour]}", $data['motorcycle']['palette'][$colour]['name']));
            }

            $surfaces[] = self::take("parts.{$at}.name — {$part['id']}", $part['name']).': '.implode('; ', $items);
        }

        return implode("\n", [
            self::listed('Colours:', $colours),
            self::listed('Materials:', $materials),
            self::listed('Surfaces of each part:', $surfaces),
            'Lights: '.self::take('motorcycle.lighting_signature.text', $data['motorcycle']['lighting_signature']['text']),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private static function displayed(array $data): string
    {
        $shown = [];

        foreach ($data['parts'] as $at => $part) {
            foreach ((array) $part['configurations'] as $index => $configuration) {
                if (in_array($configuration['id'], (array) $data['model_sheet']['display_configuration'], true)) {
                    $shown[] = self::take("parts.{$at}.name — {$part['id']}", $part['name']).': '.self::take("parts.{$at}.configurations.{$index}.description — {$configuration['id']}", $configuration['description']);
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
        $order = (array) $config['master']['order'];
        $blocks = [];

        foreach ($data['model_sheet']['views'] as $at => $view) {
            $detail = in_array($view['id'], (array) $config['master']['detail_views'], true);
            $block = $pinned['panels'][array_search($view['id'], $order, true)].'. Camera: '.self::camera($view['camera'], "model_sheet.views.{$at}.camera", " — {$view['id']}").' Target: '.self::take("model_sheet.views.{$at}.target — {$view['id']}", $view['target']).'.';

            foreach (['visible' => 'Clearly visible:', 'partial' => 'Partly visible through natural occlusion:', 'uncertain' => 'May be hidden from this camera:'] as $level => $title) {
                $items = array_map(static fn (string $id): string => self::viewItem($id, $level, $view['camera'], $detail, $data, "model_sheet.views.{$at}.{$level} — {$view['id']}"), $view[$level]);
                $block .= $items === [] ? '' : "\n".self::listed($title, $items);
            }

            $blocks[] = $block;
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $camera
     * @param  array<string, mixed>  $data
     * @param  string  $listed  the list of the view that names the id
     * @return string a part with the faces this camera sees; a feature named as one, with what it looks like unless it is plainly in view; a connection or a system in the words the design writes for it; an id the design does not hold stays as it is, so the prompt check stops it
     */
    private static function viewItem(string $id, string $level, array $camera, bool $detail, array $data, string $listed): string
    {
        $feature = array_search($id, array_column($data['features'], 'id'), true);

        if ($feature !== false) {
            return 'the feature '.self::take("features.{$feature}.name — {$id}", $data['features'][$feature]['name'])
                .($level === 'visible' ? '' : ': '.self::take("features.{$feature}.visible_expression — {$id}", $data['features'][$feature]['visible_expression']));
        }

        $part = array_search($id, array_column($data['parts'], 'id'), true);

        if ($part === false) {
            $connection = array_search($id, array_column($data['connections'], 'id'), true);
            $system = array_search($id, array_column($data['systems'], 'id'), true);

            return match (true) {
                $connection !== false => self::take("connections.{$connection}.description — {$id}", $data['connections'][$connection]['description']),
                $system !== false => self::take("systems.{$system}.name — {$id}", $data['systems'][$system]['name']),
                default => self::take($listed, $id),
            };
        }

        $faces = [];

        foreach (self::facesSeenAt($data['parts'][$part], $camera, $detail) as $index => $description) {
            $faces[] = self::take("parts.{$part}.faces.{$index}.description — {$id}", $description);
        }

        return self::partName($data, $id).($faces === [] ? '' : ': '.implode('; ', $faces));
    }

    /**
     * @param  array<string, mixed>  $part
     * @param  array<string, mixed>  $camera
     * @param  bool  $bestOnly  a close-up keeps the one face that looks at the camera most, so the rest of the part does not pull the frame away from its subject
     * @return list<string> the text of the faces this camera sees
     */
    public static function facesSeen(array $part, array $camera, bool $bestOnly): array
    {
        return array_values(self::facesSeenAt($part, $camera, $bestOnly));
    }

    /**
     * @param  array<string, mixed>  $part
     * @param  array<string, mixed>  $camera
     * @return array<int, string> the text of the faces this camera sees, each under the place of its face in the part
     */
    private static function facesSeenAt(array $part, array $camera, bool $bestOnly): array
    {
        $turn = self::turnToCamera($camera);
        $faces = array_filter((array) $part['faces'], static fn (array $face): bool => isset($turn[$face['facing']]));

        if ($bestOnly) {
            $best = null;

            foreach ($faces as $index => $face) {
                $best = $best === null || $turn[$face['facing']] > $turn[$faces[$best]['facing']] ? $index : $best;
            }

            return $best !== null && $turn[$faces[$best]['facing']] > 0 ? [$best => (string) $faces[$best]['description']] : [];
        }

        return array_map(
            static fn (array $face): string => (string) $face['description'],
            array_filter($faces, static fn (array $face): bool => $turn[$face['facing']] >= ($face['facing'] === 'top' ? self::SEEN_TOP : self::SEEN_SIDE)),
        );
    }

    /**
     * @param  array<string, mixed>  $camera
     * @return array<string, float> how far each side turns to the camera, from 1 straight at it to -1 straight away, a side seen edge-on at exactly 0; both_sides is the flank the camera stands on, and a camera with no azimuth sees no flank
     */
    private static function turnToCamera(array $camera): array
    {
        $elevation = deg2rad((float) ($camera['elevation_deg'] ?? 0));
        $turn = ['top' => round(sin($elevation), 9)];

        if (($camera['azimuth_deg'] ?? null) !== null) {
            foreach (self::SIDES as $side => $azimuth) {
                $turn[$side] = round(cos($elevation) * cos(deg2rad((float) $camera['azimuth_deg'] - $azimuth)), 9);
            }

            $turn['both_sides'] = max($turn['left'], $turn['right']);
        }

        return $turn;
    }

    /**
     * @param  array<string, mixed>  $camera
     * @param  string  $from  where the camera sits in the design
     * @param  string  $of  the id of its view
     */
    private static function camera(array $camera, string $from, string $of): string
    {
        return implode(', ', array_filter([
            ($camera['azimuth_deg'] ?? null) === null ? '' : 'azimuth '.$camera['azimuth_deg'],
            'elevation '.$camera['elevation_deg'],
            self::take("{$from}.projection{$of}", (string) $camera['projection']),
            isset($camera['lens_mm']) ? $camera['lens_mm'].' mm lens' : '',
            isset($camera['front']) ? 'the front of the motorcycle '.self::take("{$from}.front{$of}", $camera['front']) : '',
        ], static fn (string $piece): bool => $piece !== '')).'.';
    }

    /**
     * @param  array<string, mixed>  $figure  a {value, unit} figure
     * @param  string  $from  where the figure sits in the design
     * @param  string  $of  the id of the record that holds it, when it has one
     */
    private static function figure(array $figure, string $from, string $of = ''): string
    {
        $value = is_string($figure['value']) ? self::take("{$from}.value{$of}", $figure['value']) : $figure['value'];

        return $value.(is_string($figure['unit'] ?? null) ? ' '.self::take("{$from}.unit{$of}", $figure['unit']) : '');
    }

    /** @param  list<string>  $items */
    private static function listed(string $title, array $items): string
    {
        return $title."\n".implode("\n", array_map(static fn (string $item): string => '- '.$item, $items));
    }
}
