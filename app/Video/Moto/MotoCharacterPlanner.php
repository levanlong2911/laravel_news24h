<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Services\Video\OpenAiImageClient;
use App\Video\ModelSheet\ModelSheetMaster;
use App\Video\Superyacht\Support\CanonicalJson;
use RuntimeException;

final class MotoCharacterPlanner
{
    public const VERSION = 'moto-character-plan-v2';

    public const PROMPT_VERSION = 'moto-character-prompt-v2';

    /** @var string the key of the project metadata that lists the characters shown in the clothes of the script where the source names no protective equipment */
    public const SCRIPT_OUTFIT_KEY = 'moto_character_script_outfit';

    /** @var string how a look cell is marked when the source names no protective equipment and the character is shown in the clothes of the script; the source keeps its null */
    public const SCRIPT_OUTFIT = 'script_outfit';

    /** @var list<string> the settings an angle cell or a portrait is drawn from: how a person is photographed, what keeps it one person, the rules of the picture and what an angle and the portrait show. How a hand, a hidden face or a piece of equipment is shown, and how a sheet is laid out, belong to the looks and the sheet, never to who the person is */
    private const IDENTITY_SETTINGS = ['style', 'same_person', 'rules', 'angle_convention', 'angle_show', 'portrait'];

    /** @var list<string> what of a shot a decision on the clothes of a character was made from: where it stands in the film, what happens in it and the work done in it */
    private const OUTFIT_SHOT_FIELDS = ['scene', 'state', 'first_frame', 'action', 'action_timeline', 'result', 'work_items'];

    /**
     * @param  mixed  $metadata  the metadata of a project
     * @param  list<array<string, mixed>>  $shots  the stored shots of the approved shot plan
     * @param  array<string, string>  $characters  the description of every character in the approved script, by id
     * @return array{valid: list<string>, lapsed: list<string>} the characters the project shows in the clothes of the script where the source names no protective equipment: valid while the description of the character and the shots that show it with nothing stated are the ones the decision was made from, lapsed once they changed
     */
    public static function scriptOutfit(mixed $metadata, array $shots, array $characters): array
    {
        $stored = (array) (((array) $metadata)[self::SCRIPT_OUTFIT_KEY]['records'] ?? []);
        $state = ['valid' => [], 'lapsed' => []];

        foreach ($stored as $character => $record) {
            if (is_string($character) && is_string($record['fingerprint'] ?? null)) {
                $state[hash_equals($record['fingerprint'], self::outfitFingerprint($character, $shots, $characters)) ? 'valid' : 'lapsed'][] = $character;
            }
        }

        return $state;
    }

    /**
     * @param  list<array<string, mixed>>  $shots  the stored shots of the approved shot plan
     * @param  array<string, string>  $characters  the description of every character in the approved script, by id
     * @param  array<string, mixed>  $settings  video.moto.reference.character
     * @param  mixed  $metadata  the metadata of the project
     * @return array<string, mixed> plan() with the decisions on the clothes of the script the project holds, each applied only while its sources stand
     */
    public static function planFor(array $shots, array $characters, array $settings, mixed $metadata): array
    {
        $outfit = self::scriptOutfit($metadata, $shots, $characters);

        return self::plan($shots, $characters, $settings, $outfit['valid'], $outfit['lapsed']);
    }

    /**
     * @param  list<array<string, mixed>>  $shots  the stored shots of the approved shot plan
     * @param  array<string, string>  $characters
     * @return string the hash of what a decision to show the character in the clothes of the script rests on: its description and, for every shot that shows it with no protective equipment stated, how it is seen there and what the shot does; a change to anything else leaves it as it is
     */
    public static function outfitFingerprint(string $character, array $shots, array $characters): string
    {
        $byId = array_column($shots, null, 'id');
        $seen = [];

        foreach ($shots as $shot) {
            foreach ((array) ($shot['people'] ?? []) as $person) {
                if ($person['character'] === $character && self::effectiveItems($shot, $character, $byId) === null) {
                    $seen[] = ['shot' => (string) $shot['id'], 'person' => $person, 'record' => array_intersect_key($shot, array_flip(self::OUTFIT_SHOT_FIELDS))];
                }
            }
        }

        return CanonicalJson::hash(['character' => $character, 'description' => $characters[$character] ?? null, 'shots' => $seen]);
    }

    /**
     * @param  list<array<string, mixed>>  $shots  the stored shots of a shot plan
     * @return array<string, mixed> video.moto.reference.character, and for a plan whose shots may show a face the rules, the views and the portrait cell that keep one face
     */
    public static function settingsFor(array $shots): array
    {
        $settings = (array) config('video.moto.reference.character');

        return isset($shots[0]['action_timeline']) ? array_replace($settings, (array) config('video.moto.reference.character_with_face')) : $settings;
    }

    /**
     * @param  list<array<string, mixed>>  $shots  the stored shots of the approved shot plan
     * @param  array<string, string>  $characters  the description of every character in the approved script, by id
     * @param  array<string, mixed>  $settings  video.moto.reference.character
     * @param  list<string>  $scriptOutfit  the characters of the project shown in the clothes of the script where the source names no protective equipment: their look cell lists no equipment and is marked script_outfit, and the source keeps its null; every other character with no equipment stated stays unresolved
     * @param  list<string>  $lapsedOutfit  the characters whose decision was made from sources that changed since: unresolved until it is confirmed again
     * @return array{version: string, prompt_version: string, cells: list<array<string, mixed>>, bindings: array<string, list<array<string, mixed>>>, problems: list<string>} for every character of the script that has a description, whether a shot shows it or not and whatever the shots state it wears: the angles of the settings, each item of protective equipment the shots list, then one cell for every view and list of protective equipment the shots need, with the shots that use it kept apart from its fingerprint; for each shot and person, the cell that answers it, the keyframe it continues or why nothing can be decided from the data
     */
    public static function plan(array $shots, array $characters, array $settings, array $scriptOutfit = [], array $lapsedOutfit = []): array
    {
        $byId = array_column($shots, null, 'id');
        $found = [];
        $bindings = [];
        $problems = [];

        foreach ($shots as $shot) {
            $shotId = (string) $shot['id'];

            foreach ((array) ($shot['people'] ?? []) as $person) {
                $character = (string) $person['character'];
                $view = (string) $person['view'];
                $items = self::effectiveItems($shot, $character, $byId);
                $description = $characters[$character] ?? null;

                if (! is_string($description) || trim($description) === '') {
                    $problem = "{$shotId}: kịch bản không có mô tả cho {$character} — cần kiểm.";
                } elseif ($items === null && in_array($character, $lapsedOutfit, true)) {
                    $problem = "{$shotId}: quyết định dùng trang phục kịch bản cho {$character} được xác nhận trên nguồn khác bản hiện hành (mô tả nhân vật hoặc nội dung shot đã đổi) — cần xác nhận lại.";
                } elseif ($items === null && ! in_array($character, $scriptOutfit, true)) {
                    $problem = "{$shotId}: chưa có dữ liệu bảo hộ của {$character} và không phân giải được từ shot trước — cần kiểm.";
                } else {
                    $problem = null;
                }

                if ($problem !== null) {
                    $problems[] = $problem;
                    $bindings[$shotId][] = ['type' => 'unresolved', 'character' => $character, 'view' => $view, 'problem' => $problem];

                    continue;
                }

                $unstated = $items === null;
                $items ??= [];
                $target = $unstated || isset($shot['action_timeline']) ? null : self::continuityTarget($shot);

                if ($target !== null && self::continues($byId[$target] ?? null, $character, $view, $items, $byId)) {
                    $bindings[$shotId][] = ['type' => 'continuity', 'character' => $character, 'view' => $view, 'target' => $target];

                    continue;
                }

                $id = self::cellId($unstated ? ['look', $character, $view, self::SCRIPT_OUTFIT] : ['look', $character, $view, $items]);
                $found[$character][$view][$id] ??= ['id' => $id, 'kind' => 'look', 'character' => $character, 'view' => $view, 'items' => $items, 'description' => $description, 'shots' => []] + ($unstated ? ['outfit' => self::SCRIPT_OUTFIT] : []);
                $found[$character][$view][$id]['shots'][] = $shotId;
                $bindings[$shotId][] = ['type' => 'cell', 'character' => $character, 'view' => $view, 'cell' => $id] + ($unstated ? ['outfit' => self::SCRIPT_OUTFIT] : []);
            }
        }

        $prefixes = self::prefixes(array_keys($found));
        $settingsHash = CanonicalJson::hash($settings);
        $cells = [];

        foreach ($characters as $character => $description) {
            if (is_string($description) && trim($description) !== '') {
                $found[(string) $character] ??= [];
            }
        }

        foreach ($found as $character => $views) {
            $description = (string) $characters[$character];
            $looks = array_merge(...array_map('array_values', array_values($views)));
            $worn = array_values(array_unique(array_merge(...array_column($looks, 'items'))));
            $own = [
                ...self::identityRows($character, $description, $settings),
                ...array_map(static fn (string $item, int $index): array => [
                    'id' => self::cellId(['item', $character, $item]),
                    'kind' => 'item',
                    'code' => 'D'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'character' => $character,
                    'item' => $item,
                    'description' => $description,
                    'label' => $item,
                    'shots' => [],
                ], $worn, array_keys($worn)),
                ...array_map(static fn (array $look, int $index): array => $look + [
                    'code' => $prefixes[$character].($index + 1),
                    'label' => $look['view'].' · '.(isset($look['outfit']) ? 'trang phục kịch bản — nguồn chưa nêu bảo hộ bổ sung' : ($look['items'] === [] ? 'bảo hộ trống' : 'bảo hộ: '.implode(', ', $look['items']))),
                ], $looks, array_keys($looks)),
            ];

            foreach ($own as $cell) {
                $cells[] = self::fingerprinted($cell, $settingsHash);
            }
        }

        return ['version' => self::VERSION, 'prompt_version' => self::PROMPT_VERSION, 'cells' => $cells, 'bindings' => $bindings, 'problems' => $problems];
    }

    /**
     * @param  array<string, mixed>  $settings  video.moto.reference.character
     * @return list<array<string, mixed>> the cells that give who the character is: every angle of the settings and the portrait when the settings have one, each with the fingerprint its image was rendered under and with identity, the hash of what makes it this person alone — the character, its description, the angle or the portrait, and the settings a person is photographed by. A change to how a look, a hand or a piece of equipment is shown changes the fingerprint and leaves identity as it is, so a master that holds these cells still answers who the character is
     */
    public static function identityCells(string $character, string $description, array $settings): array
    {
        $settingsHash = CanonicalJson::hash($settings);
        $identity = array_intersect_key($settings, array_flip(self::IDENTITY_SETTINGS));

        return array_map(static fn (array $cell): array => self::fingerprinted($cell, $settingsHash) + ['identity' => CanonicalJson::hash([
            'cell' => array_intersect_key($cell, array_flip(['kind', 'character', 'angle', 'description'])),
            'settings' => $identity,
        ])], self::identityRows($character, $description, $settings));
    }

    /**
     * @param  array<string, mixed>  $shot  a stored shot
     * @param  list<array<string, mixed>>  $shots  the stored shots of its plan
     * @return ?list<string> what the source states the character wears at the first frame of the shot: null when nothing says, an empty list when it states there is none
     */
    public static function statedItems(array $shot, string $character, array $shots): ?array
    {
        return self::effectiveItems($shot, $character, array_column($shots, null, 'id'));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<array<string, mixed>>
     */
    private static function identityRows(string $character, string $description, array $settings): array
    {
        return [
            ...array_map(static fn (array $angle): array => [
                'id' => self::cellId(['angle', $character, (string) $angle['code']]),
                'kind' => 'angle',
                'code' => (string) $angle['code'],
                'character' => $character,
                'angle' => $angle,
                'description' => $description,
                'label' => (string) $angle['label_vi'],
                'shots' => [],
            ], array_values((array) $settings['angles'])),
            ...(is_array($settings['portrait'] ?? null) ? [[
                'id' => self::cellId(['portrait', $character]),
                'kind' => 'portrait',
                'code' => (string) $settings['portrait']['code'],
                'character' => $character,
                'description' => $description,
                'label' => (string) $settings['portrait']['label_vi'],
                'shots' => [],
            ]] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $cell
     * @return array<string, mixed> the cell with the hash of what its image is made from
     */
    private static function fingerprinted(array $cell, string $settingsHash): array
    {
        return $cell + ['fingerprint' => CanonicalJson::hash([
            'cell' => array_diff_key($cell, array_flip(['shots', 'code', 'label'])),
            'settings' => $settingsHash,
            'version' => [self::VERSION, self::PROMPT_VERSION],
        ])];
    }

    /**
     * @param  list<array<string, mixed>>  $cells  the cells to render of one character, in cell order
     * @param  array<string, mixed>  $settings  video.moto.reference.character
     * @return array{id: string, character: string, cell_ids: list<string>, single: bool, layout: array<string, mixed>, layout_sha256: string} the master of the cells: one cell is one picture with no grid, more fill the rows of the grid from the left and the rest of the last row stays empty
     */
    public static function sheet(string $character, array $cells, string $size, array $settings): array
    {
        $ids = array_column($cells, 'id');
        $single = count($ids) === 1;
        $rows = $single ? 1 : min((int) $settings['rows'], count($ids));
        $columns = (int) ceil(count($ids) / $rows);
        $order = $ids;

        for ($index = count($order); $index < $rows * $columns; $index++) {
            $order[] = 'EMPTY'.($index + 1);
        }

        $layout = ['size' => $size, 'columns' => $columns, 'rows' => $rows, 'order' => $order];

        return [
            'id' => 'CM'.substr(hash('sha256', $character.'|'.implode('|', $ids)), 0, 10),
            'character' => $character,
            'cell_ids' => $ids,
            'single' => $single,
            'layout' => $layout,
            'layout_sha256' => (new ModelSheetMaster($layout))->layoutSha256(),
        ];
    }

    /**
     * @param  array{cell_ids: list<string>, single: bool, layout: array<string, mixed>}  $sheet
     * @param  array<string, array<string, mixed>>  $cells  the cells by id
     * @param  array<string, mixed>  $settings  video.moto.reference.character
     * @return string the prompt of the master: the canvas with its grid, the angle convention, the common rules, the empty cells and each cell: an angle of the person, one item alone, or the person wearing the protective equipment of the cell; the description is the words of the script
     *
     * @throws RuntimeException when the prompt is longer than the image model accepts
     */
    public static function prompt(array $sheet, array $cells, array $settings): string
    {
        $labels = (array) $settings['labels'];
        $positions = (new ModelSheetMaster($sheet['layout']))->cells();
        $single = $sheet['single'];
        $empty = array_values(array_filter($positions, static fn (array $cell): bool => ! in_array($cell['id'], $sheet['cell_ids'], true)));
        [$width, $height] = explode('x', (string) $sheet['layout']['size']);
        $spread = static function (array $lengths): string {
            return min($lengths) === max($lengths) ? (string) min($lengths) : min($lengths).'–'.max($lengths);
        };
        $position = static fn (array $cell): string => "{$labels['row']} {$cell['row']}, {$labels['column']} {$cell['column']}";
        $kinds = array_unique(array_column(array_intersect_key($cells, array_flip($sheet['cell_ids'])), 'kind'));
        $blocks = [
            rtrim((string) $settings['style'], '.').'.',
            strtr((string) ($single ? $settings['canvas_single'] : $settings['canvas']), [
                ':width' => $width,
                ':height' => $height,
                ':rows' => (string) $sheet['layout']['rows'],
                ':columns' => (string) $sheet['layout']['columns'],
                ':cells' => (string) count($positions),
                ':cell_width' => $spread(array_column($positions, 'width')),
                ':cell_height' => $spread(array_column($positions, 'height')),
            ]),
            ...(in_array('angle', $kinds, true) ? [(string) $settings['angle_convention']] : []),
            implode("\n", array_map(static fn (string $rule): string => '- '.$rule, [...($single ? [] : [(string) $settings['lines'], (string) $settings['same_person']]), ...(array) $settings['rules']])),
        ];

        if ($empty !== []) {
            $blocks[] = rtrim((string) $settings['empty'], '.').': '.implode('; ', array_map($position, $empty)).'.';
        }

        foreach ($positions as $place) {
            $cell = $cells[$place['id']] ?? null;

            if ($cell === null) {
                continue;
            }

            $heading = $single ? null : "{$labels['cell']} ".($place['index'] + 1).', '.$position($place)." (x {$place['x']}–".($place['x'] + $place['width'] - 1).", y {$place['y']}–".($place['y'] + $place['height'] - 1).').';
            $lines = match ($cell['kind']) {
                'angle' => [
                    "{$labels['person']} ".(string) $cell['description'],
                    "{$labels['angle']} ".(string) $cell['angle']['label'].'.',
                    "{$labels['show']} ".rtrim((string) $settings['angle_show'], '.').'.',
                ],
                'item' => [
                    "{$labels['item']} ".(string) $cell['item'].'.',
                    "{$labels['show']} ".rtrim((string) $settings['item_show'], '.').'.',
                ],
                'portrait' => [
                    "{$labels['person']} ".(string) $cell['description'],
                    "{$labels['show']} ".rtrim((string) $settings['portrait']['show'], '.').'.',
                ],
                default => [
                    "{$labels['person']} ".(string) $cell['description'],
                    "{$labels['worn']} ".($cell['items'] === [] ? $labels['worn_none'] : implode(', ', $cell['items'])).'.',
                    "{$labels['show']} ".(((array) $settings['views'])[$cell['view']] ?? strtr((string) $settings['view_other'], [':view' => (string) $cell['view']])).'.',
                ],
            };
            $blocks[] = implode("\n", array_filter([$heading, ...$lines], static fn (?string $line): bool => $line !== null));
        }

        $text = implode("\n\n", $blocks);

        if (mb_strlen($text) > OpenAiImageClient::MAX_PROMPT_CHARS) {
            throw new RuntimeException('Prompt nhân vật '.mb_strlen($text).' ký tự, vượt giới hạn '.OpenAiImageClient::MAX_PROMPT_CHARS.'.');
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  array<string, array<string, mixed>>  $byId  the shots by id
     * @param  array<string, true>  $seen  the shots already followed
     * @return ?list<string> what the character wears at the first frame of the shot, read as the keyframe prompt reads it, otherwise from the shot it carries on from; each item once, in a fixed order, its words untouched; an empty list states there is none, null that nothing says
     */
    private static function effectiveItems(array $shot, string $character, array $byId, array $seen = []): ?array
    {
        $moment = is_array($shot['continuity_start'] ?? null) ? $shot['continuity_start'] : (is_array($shot['continuity_end'] ?? null) ? $shot['continuity_end'] : null);
        $items = $moment === null ? null : (array_column((array) ($moment['protection'] ?? []), 'items', 'character')[$character] ?? null);

        if (is_array($items)) {
            $items = array_values(array_unique(array_map('strval', $items)));
            sort($items, SORT_STRING);

            return $items;
        }

        $from = $shot['carried_from']['shot'] ?? null;

        if (! is_string($from) || isset($seen[$from]) || ! isset($byId[$from]) || ! in_array($character, array_column((array) ($byId[$from]['people'] ?? []), 'character'), true)) {
            return null;
        }

        return self::effectiveItems($byId[$from], $character, $byId, $seen + [(string) $shot['id'] => true]);
    }

    /**
     * @param  ?array<string, mixed>  $target  the shot whose keyframe the shot continues
     * @param  list<string>  $items
     * @param  array<string, array<string, mixed>>  $byId
     * @return bool whether the keyframe already holds this person: the same character, seen the same way and wearing the same
     */
    private static function continues(?array $target, string $character, string $view, array $items, array $byId): bool
    {
        if ($target === null) {
            return false;
        }

        foreach ((array) ($target['people'] ?? []) as $person) {
            if ($person['character'] === $character && $person['view'] === $view && self::effectiveItems($target, $character, $byId) === $items) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $shot */
    private static function continuityTarget(array $shot): ?string
    {
        $need = collect((array) ($shot['reference_needs'] ?? []))->firstWhere('kind', 'continuity');

        return is_string($need['target'] ?? null) ? $need['target'] : null;
    }

    /** @param  list<mixed>  $key  the kind of the cell, its character and what makes it that cell */
    private static function cellId(array $key): string
    {
        return 'C'.substr(hash('sha256', json_encode($key, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 0, 10);
    }

    /**
     * @param  list<string>  $characters
     * @return array<string, string> the shortest upper-case start of each character id that no other character shares
     */
    private static function prefixes(array $characters): array
    {
        $prefixes = [];

        foreach ($characters as $character) {
            $length = 1;

            while ($length < strlen((string) $character) && count(array_filter($characters, static fn (string $other): bool => strncasecmp($other, (string) $character, $length) === 0)) > 1) {
                $length++;
            }

            $prefixes[$character] = strtoupper(substr((string) $character, 0, $length));
        }

        return $prefixes;
    }
}
