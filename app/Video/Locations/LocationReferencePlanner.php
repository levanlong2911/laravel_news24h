<?php

declare(strict_types=1);

namespace App\Video\Locations;

final class LocationReferencePlanner
{
    public const VERSION = 'phase5-background-plan-v6';

    private const AXES = ['length_m' => 'length', 'width_m' => 'width', 'height_m' => 'overall height'];

    /**
     * @param  array<string, mixed>  $document  a Phase 5 document that passed its check
     * @param  array<string, mixed>  $context  the check context pinned with the run, holding the design texts of every id
     * @return array{version: string, locations: list<array<string, mixed>>, images: list<array<string, mixed>>, unplated: list<array<string, mixed>>, unresolved: list<array<string, mixed>>, conflicts: list<array<string, mixed>>, continuity: list<array<string, mixed>>, script_continuity: list<array<string, mixed>>} the locations of the answer, then every background in the order the script first uses it, each with the parts its prompt is built from and where each part comes from, the conflicts that hold it back and the continuity the script itself requires
     */
    public static function plan(array $document, array $context): array
    {
        $data = (array) $document['data'];
        $places = array_column(array_values((array) $data['locations']), null, 'id');
        $position = array_flip(array_column((array) $context['beats'], 'id'));
        $bs = array_column((array) $context['beats'], 'bs', 'id');
        $conflicts = array_values((array) $data['conflicts']);
        $continuity = array_values((array) $data['continuity']);
        $backgrounds = array_values((array) $data['backgrounds']);
        $required = self::matches($backgrounds, $context);
        $first = static fn (array $background): array => min(array_map(static fn (array $use): array => [$position[$use['beat']] ?? PHP_INT_MAX, $use['tour_stop'] ?? -1], (array) $background['uses']));
        usort($backgrounds, static fn (array $a, array $b): int => $first($a) <=> $first($b));
        $rows = [];

        foreach ($backgrounds as $background) {
            $id = (string) $background['id'];
            $beats = array_column((array) $background['uses'], 'beat');
            $mine = array_values(array_filter($conflicts, static fn (array $conflict): bool => $conflict['background'] === $id || ($conflict['background'] === null && in_array($conflict['beat'], $beats, true))));
            $place = $background['kind'] === 'location' ? ($places[$background['source']['id']] ?? null) : null;
            $rows[] = [
                'id' => $id,
                'name' => (string) $background['name'],
                'kind' => (string) $background['kind'],
                'style' => $place !== null && in_array($place['kind'] ?? null, (array) ($context['policy']['open_water_kinds'] ?? []), true) ? 'open_water' : (string) $background['kind'],
                'source' => (array) $background['source'],
                'state' => $background['source']['state'],
                'location' => $place,
                'uses' => array_values(array_unique($beats)),
                'parts' => array_values((array) $background['uses']),
                'sharing' => (string) $background['sharing'],
                'light' => (array) $background['light'],
                'condition' => array_values((array) $background['condition']),
                'details' => array_values((array) $background['details']),
                'design_refs' => array_values((array) ($background['design_refs'] ?? [])),
                'lighting_signature' => (bool) ($background['lighting_signature'] ?? false),
                'must_show' => array_values((array) $background['must_show']),
                'avoid' => array_values((array) $background['avoid']),
                'shot_changes' => array_values((array) $background['shot_changes']),
                'assumptions' => array_values((array) $background['assumptions']),
                'conflicts' => $mine,
                'blocked' => $mine !== [],
                'continuity' => array_values(array_filter($continuity, static fn (array $entry): bool => in_array($id, (array) $entry['backgrounds'], true))),
                'script_continuity' => array_values(array_filter($required, static fn (array $match): bool => in_array($id, [...$match['bare'], ...$match['stop']], true))),
                'prompt_parts' => self::promptParts($background, (array) $context['design'], (string) ($bs[$background['uses'][0]['beat']] ?? ''), $place, self::states($background, $bs)),
                'order' => count($rows),
            ];
        }

        return [
            'version' => self::VERSION,
            'locations' => array_values($places),
            'images' => $rows,
            'unplated' => array_values((array) $data['unplated']),
            'unresolved' => array_values((array) $data['unresolved']),
            'conflicts' => $conflicts,
            'continuity' => $continuity,
            'script_continuity' => $required,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $backgrounds  the backgrounds of a Phase 5 document
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @return list<array{index: int, room: string, beat: string, stop: list<string>, bare: list<string>, requirement: string}> every tour stop the script ties to the composition of an earlier beat, with the backgrounds of the stop and of that room in the earlier beat, and the stop's own words as the requirement
     */
    public static function matches(array $backgrounds, array $context): array
    {
        $matches = [];

        foreach ((array) $context['tour'] as $stop) {
            if (! is_string($stop['match_bare_beat'] ?? null) || $stop['match_bare_beat'] === '') {
                continue;
            }

            $beat = $stop['match_bare_beat'];
            $room = (string) $stop['source_id'];
            $serving = static fn (callable $test): array => array_values(array_column(array_filter($backgrounds, static fn (array $background): bool => (string) $background['source']['id'] === $room
                && array_filter((array) $background['uses'], $test) !== []), 'id'));
            $matches[] = [
                'index' => (int) $stop['index'],
                'room' => $room,
                'beat' => $beat,
                'stop' => $serving(static fn (array $use): bool => $use['beat'] === $context['tour_beat'] && $use['tour_stop'] === $stop['index']),
                'bare' => $serving(static fn (array $use): bool => $use['beat'] === $beat && $use['tour_stop'] === null),
                'requirement' => (string) $stop['visual'],
            ];
        }

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $background
     * @param  array<string, mixed>  $design
     * @param  ?array<string, mixed>  $place  the location of the same answer a location background stands on
     * @param  list<string>  $states  the build state of every use of the background
     * @return list<array{key: string, text: string, source: string}> the parts of one background's prompt in prompt order: the kind of space and its state, then the design's own texts for a designed id in that state — architecture and scale, materials on their surfaces, palette, windows and openings, signature detail, the design features it shows, the identity it keeps in frame — or the fixed description of its location with every known size and its clear height, then the added layout, the condition, the base light, what lies beyond, the base frame, what must show and what to avoid
     */
    private static function promptParts(array $background, array $design, string $bs, ?array $place, array $states = []): array
    {
        $parts = [];
        $add = static function (string $key, string $text, string $source) use (&$parts): void {
            if (trim($text) !== '') {
                $parts[] = ['key' => $key, 'text' => $text, 'source' => $source];
            }
        };
        $id = (string) $background['source']['id'];
        $state = $background['source']['state'];
        $quoted = static fn (?array $evidence): string => $evidence === null ? 'giả định' : $evidence['beat'].($evidence['tour_stop'] === null ? '' : '#'.$evidence['tour_stop']).'.'.$evidence['field'];

        $add('scope', (string) $background['scope'], 'ai:scope');

        $spatial = isset($design['spaces']);

        if ($background['kind'] === 'interior' && $spatial) {
            $room = (array) ($design['rooms'][$id] ?? []);
            $fields = (array) ($room['fields'] ?? []);
            [$figures, $bases] = self::measured((array) ($room['dimensions'] ?? []));
            $add('state', (string) ($design['interior_set_states'][$state] ?? ''), "design:yacht_bible.interior_set_states.{$state}");
            $add('space', (string) ($fields['name'] ?? ''), "design:yacht_bible.rooms[{$id}].name");
            $add('deck', (string) ($fields['deck'] ?? ''), "design:canonical.spaces[{$id}].decks");
            $add('size', (string) ($fields['size_feel'] ?? ''), "design:yacht_bible.rooms[{$id}].size_feel");
            $add('dimensions', $figures, "design:canonical.spaces[{$id}].dimensions ({$bases}) + dimensions.scope");

            if ($state === 'finished') {
                $add('materials', self::joined((array) ($room['surfaces'] ?? []), ['element', 'material', 'finish'], ': '), "design:yacht_bible.rooms[{$id}].surfaces");
                $add('palette', implode(', ', (array) ($room['palette'] ?? [])), "design:yacht_bible.rooms[{$id}].palette");
            }

            if ($state !== 'bare') {
                $add('built_ins', self::joined((array) ($room['built_ins'] ?? []), ['name', 'description'], ': '), "design:yacht_bible.rooms[{$id}].built_ins");
            }

            if ($state === 'finished') {
                $add('furniture', self::joined((array) ($room['furniture'] ?? []), ['item', 'placement'], ', '), "design:yacht_bible.rooms[{$id}].furniture");
                $add('room_layout', implode('; ', array_filter((array) ($room['layout'] ?? []), 'is_string')), "design:yacht_bible.rooms[{$id}].layout");
                $add('lighting', self::joined((array) ($room['lighting'] ?? []), ['source', 'position', 'serves'], ', '), "design:yacht_bible.rooms[{$id}].lighting");
            }

            $add('window', (string) ($fields['window_type'] ?? ''), "design:yacht_bible.rooms[{$id}].window_type");

            if ($state === 'finished') {
                $add('signature', (string) ($fields['signature_detail'] ?? ''), "design:yacht_bible.rooms[{$id}].signature_detail");
            }
        }

        if ($background['kind'] === 'interior' && ! $spatial) {
            $room = (array) ($design['rooms'][$id] ?? []);
            $fields = (array) ($room['fields'] ?? []);
            $add('state', (string) ($design['interior_set_states'][$state] ?? ''), "design:yacht_bible.interior_set_states.{$state}");
            $add('space', (string) ($fields['name'] ?? ''), "design:yacht_bible.rooms[{$id}].name");
            $add('deck', (string) ($fields['deck'] ?? ''), "design:yacht_bible.rooms[{$id}].deck");
            $add('size', (string) ($fields['size_feel'] ?? ''), "design:yacht_bible.rooms[{$id}].size_feel");

            if ($state === 'finished') {
                $add('materials', implode(', ', (array) ($room['materials'] ?? [])), "design:yacht_bible.rooms[{$id}].materials");
                $add('palette', implode(', ', (array) ($room['palette'] ?? [])), "design:yacht_bible.rooms[{$id}].palette");
            }

            $add('window', (string) ($fields['window_type'] ?? ''), "design:yacht_bible.rooms[{$id}].window_type");

            if ($state === 'finished') {
                $add('signature', (string) ($fields['signature_detail'] ?? ''), "design:yacht_bible.rooms[{$id}].signature_detail");

                if ((array) ($room['materials'] ?? []) === []) {
                    $add('anchor', (string) ($design['interior_anchors'][$id] ?? ''), "design:anchors.interior.{$id} (thay cho vật liệu thiết kế còn thiếu)");
                }
            }
        }

        if ($background['kind'] === 'exterior') {
            $add('zone', (string) ($design['zones'][$id] ?? ''), "design:yacht_bible.exterior_zones[{$id}].text");

            if ($spatial) {
                [$figures, $bases] = self::measured((array) ($design['zone_dimensions'][$id] ?? []));
                $add('dimensions', $figures, "design:canonical.spaces[{$id}].dimensions ({$bases}) + dimensions.scope");
            }

            if (($design['build_kinds'][$bs] ?? null) === 'finished') {
                $add('anchor', (string) ($design['exterior_anchors'][$id] ?? ''), "design:anchors.exterior.{$id}");
            }

            $add('surface', (string) ($design['surface_states'][$bs] ?? ''), "design:anchors.surface_states.{$bs}");
        }

        if ($background['kind'] !== 'location') {
            foreach ((array) ($background['design_refs'] ?? []) as $ref) {
                if ($spatial) {
                    $relation = self::relation($design, $id, $ref);

                    if ($relation === 'mounted') {
                        $add('feature', (string) ($design['innovations'][$ref['id']]['visible'] ?? ''), "design:yacht_bible.innovations[{$ref['id']}].visible_expression (mounted_in {$id})");
                        $innovation = (array) ($design['innovations'][$ref['id']] ?? []);
                        [$elements, $sized] = self::elements((array) ($innovation['visible_elements'] ?? []), (array) ($innovation['measures'] ?? []));
                        $add('elements', $elements, "design:canonical.innovations[{$ref['id']}].visible_elements".($sized === [] ? '' : ' + canonical.dimensions['.implode(', ', $sized).']')." (mounted_in {$id})");
                    } elseif ($relation === 'effect') {
                        $add('effect', (string) ($design['innovations'][$ref['id']]['effect'] ?? ''), "design:yacht_bible.innovations[{$ref['id']}].effect (effect_in {$id})");
                    }

                    continue;
                }

                $link = self::featureLink($background, $design, (string) $ref['id']);

                if ($link !== null) {
                    $add('feature', (string) ($design['innovations'][$ref['id']]['visible'] ?? ''), "design:yacht_bible.innovations[{$ref['id']}].visible ({$link})");
                }
            }

            $finished = $background['kind'] === 'interior' ? $state === 'finished' : ($design['build_kinds'][$bs] ?? null) === 'finished';

            if ((bool) ($background['lighting_signature'] ?? false) && $finished) {
                $add('lighting_signature', (string) ($design['lighting_signature'] ?? ''), 'design:yacht_bible.lighting_signature');
            }

            foreach ($spatial ? self::kept($design, $id, $background, $states) : [] as $rule) {
                $add('keep', $rule['description'], "design:canonical.identity_features[{$rule['id']}]");
            }
        }

        if ($background['kind'] === 'location' && $place !== null) {
            $add('space', (string) $place['space'], "ai:locations[{$id}].space");
            [$size, $bases] = self::size((array) $place['dimensions']);
            $add('dimensions', $size, "ai:locations[{$id}].dimensions ({$bases})");
            $clear = (array) ($place['fit']['clear_height_m'] ?? []);

            if (is_numeric($clear['value'] ?? null)) {
                $add('clear_height', self::metres((float) $clear['value']), "ai:locations[{$id}].fit.clear_height_m ({$clear['basis']})");
            }

            $add('surfaces', implode(', ', (array) $place['surfaces']), "ai:locations[{$id}].surfaces");
            $add('equipment', implode(', ', (array) $place['fixed_equipment']), "ai:locations[{$id}].fixed_equipment");
            $add('palette', implode(', ', (array) $place['palette']), "ai:locations[{$id}].palette");
        }

        foreach ((array) $background['details'] as $detail) {
            $add(match (true) {
                $background['kind'] === 'location' => 'props',
                $background['kind'] === 'interior' && $state !== 'finished' => 'detail',
                default => 'layout',
            }, (string) $detail['text'], 'ai:details ('.$quoted($detail['evidence']).')');
        }

        foreach ((array) $background['condition'] as $condition) {
            $add('condition', (string) $condition['text'], 'ai:condition'.($condition['limit'] === null ? '' : " ({$condition['limit']})"));
        }

        $add('light', (string) $background['light']['description'], 'ai:light ('.$quoted($background['light']['evidence']).')');
        $add('environment', (string) ($background['surroundings'] ?? ''), 'ai:surroundings');
        $add('composition', (string) $background['composition'], 'ai:composition');
        $add('must_show', implode('; ', array_map('strval', (array) $background['must_show'])), 'ai:must_show');
        $avoid = array_map('strval', (array) $background['avoid']);
        $excluded = $spatial && $background['kind'] !== 'location' ? self::excluded($design, $id, $background['kind'] === 'exterior', $states) : [];

        foreach ($excluded as $rule) {
            if (! in_array(mb_strtolower($rule['description']), array_map('mb_strtolower', $avoid), true)) {
                $avoid[] = $rule['description'];
            }
        }

        $add('avoid', implode('; ', $avoid), $excluded === [] ? 'ai:avoid' : 'ai:avoid + design:canonical.identity_features['.implode(', ', array_column($excluded, 'id')).']');

        return $parts;
    }

    /**
     * @param  array<string, mixed>  $design  the design context of a design that records its spaces
     * @param  array<string, mixed>  $ref  one design_refs entry of a background on that space
     * @return ?string the relation the design itself gives the innovation for this space and the ref names — mounted, effect — or null when the design gives none, including a space the innovation only serves
     */
    public static function relation(array $design, string $space, array $ref): ?string
    {
        $innovation = $design['innovations'][$ref['id'] ?? ''] ?? null;
        $relation = $ref['relation'] ?? null;

        return match (true) {
            ! is_array($innovation) => null,
            $relation === 'mounted' && in_array($space, (array) ($innovation['mounted_in'] ?? []), true) => 'mounted',
            $relation === 'effect' && in_array($space, (array) ($innovation['effect_in'] ?? []), true) && is_string($innovation['effect'] ?? null) => 'effect',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $design  the design context of a design that records its spaces
     * @param  list<string>  $states  the build state of every use of the background
     * @return list<array{id: string, kind: string, description: string, from_state: ?string}> the must_not rules of the space, and for an exterior area those of the whole yacht too, that apply at every use
     */
    public static function excluded(array $design, string $space, bool $exterior, array $states = []): array
    {
        $rules = [...(array) ($design['rules'][$space] ?? []), ...($exterior ? (array) ($design['rules']['YACHT'] ?? []) : [])];

        return array_values(array_filter($rules, static fn (array $rule): bool => $rule['kind'] === 'must_not' && trim($rule['description']) !== '' && self::applies($rule, $states)));
    }

    /**
     * @param  array<string, mixed>  $design  the design context of a design that records its spaces
     * @param  list<string>  $states  the build state of every use of the background
     * @return list<array{id: string, kind: string, description: string, from_state: ?string}> the must_keep rules of the space that apply at every use of the background
     */
    public static function required(array $design, string $space, array $states): array
    {
        return array_values(array_filter((array) ($design['rules'][$space] ?? []), static fn (array $rule): bool => $rule['kind'] === 'must_keep' && trim($rule['description']) !== '' && self::applies($rule, $states)));
    }

    /**
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>  $background
     * @param  list<string>  $states
     * @return list<array{id: string, kind: string, description: string, from_state: ?string}> the must_keep rules of the space the background says it keeps in frame
     */
    private static function kept(array $design, string $space, array $background, array $states): array
    {
        $inFrame = array_column(array_filter((array) ($background['keeps'] ?? []), static fn (mixed $keep): bool => is_array($keep) && ($keep['in_frame'] ?? false) === true), 'rule');

        return array_values(array_filter(self::required($design, $space, $states), static fn (array $rule): bool => in_array($rule['id'], $inFrame, true)));
    }

    /**
     * @param  array{from_state?: ?string}  $rule  an identity rule of the design
     * @param  list<string>  $states  the build state of every use of the background
     * @return bool whether the rule applies at every use: always without a from_state, otherwise once each use has reached that build state
     */
    public static function applies(array $rule, array $states): bool
    {
        $from = self::stage($rule['from_state'] ?? null);

        return $from === null || array_filter($states, static fn (string $state): bool => (self::stage($state) ?? 0) < $from) === [];
    }

    /**
     * @param  array<string, mixed>  $background
     * @param  array<string, string>  $bs  the build state of every beat
     * @return list<string> the build state of every use of the background
     */
    public static function states(array $background, array $bs): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (array $use): string => (string) ($bs[$use['beat']] ?? ''), (array) $background['uses']))));
    }

    private static function stage(mixed $state): ?int
    {
        return is_string($state) && preg_match('/^BS(\d{2})$/', $state, $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * @param  list<mixed>  $items  a list of design objects
     * @param  list<string>  $keys  the fields of each object, in prompt order
     * @return string every object's fields word for word, the first joined to the rest by $glue and the rest by commas, the objects separated by semicolons
     */
    private static function joined(array $items, array $keys, string $glue): string
    {
        $texts = [];

        foreach (array_filter($items, 'is_array') as $item) {
            $values = array_values(array_filter(array_map(static fn (string $key): string => trim((string) ($item[$key] ?? '')), $keys), static fn (string $value): bool => $value !== ''));

            if ($values !== []) {
                $texts[] = rtrim(array_shift($values).($values === [] ? '' : $glue.implode(', ', $values)), '.');
            }
        }

        return implode('; ', $texts);
    }

    /**
     * @param  array<string, mixed>  $background  a designed room or area of a Phase 5 document
     * @param  array<string, mixed>  $design  the design context pinned with the run
     * @return ?string how the design itself ties the innovation to this space — the space's own text names the innovation, or the innovation's position names the space — outside a negated clause; null when the design does not tie them, whatever words they share
     */
    public static function featureLink(array $background, array $design, string $id): ?string
    {
        $innovation = $design['innovations'][$id] ?? null;

        if (! is_array($innovation)) {
            return null;
        }

        $space = (string) $background['source']['id'];
        $own = $background['kind'] === 'interior'
            ? implode(' ', [...array_values((array) ($design['rooms'][$space]['fields'] ?? [])), ...(array) ($design['rooms'][$space]['materials'] ?? []), (string) ($design['interior_anchors'][$space] ?? '')])
            : implode(' ', [(string) ($design['zones'][$space] ?? ''), (string) ($design['exterior_anchors'][$space] ?? '')]);
        $name = rtrim(trim((string) $innovation['name']), '.');

        if ($name !== '' && self::names($own, $name)) {
            return "{$space} nêu {$name}";
        }

        $spaceName = $background['kind'] === 'interior'
            ? (string) ($design['rooms'][$space]['fields']['name'] ?? '')
            : (preg_match('/^([^:.;]{3,60}):/u', (string) ($design['zones'][$space] ?? ''), $match) === 1 ? $match[1] : '');

        if (trim($spaceName) !== '' && self::names((string) $innovation['position'], trim($spaceName))) {
            return "vị trí {$id} nêu ".trim($spaceName);
        }

        return null;
    }

    /** @return bool whether the text names the phrase as whole words, the last one with or without its plural ending, at least once outside a negated clause */
    private static function names(string $text, string $phrase): bool
    {
        $words = array_values(array_filter((array) preg_split('/[\s\-]+/u', mb_strtolower($phrase)), static fn (string $word): bool => $word !== ''));

        if ($words === []) {
            return false;
        }

        $last = (string) preg_replace('/(es|s)$/u', '', (string) array_pop($words));
        $pattern = implode('[\s\-]+', [...array_map(static fn (string $word): string => preg_quote($word, '/'), $words), preg_quote($last, '/').'(?:es|s)?']);
        preg_match_all('/(?<![\p{L}\p{N}])'.$pattern.'(?![\p{L}\p{N}])/u', mb_strtolower($text), $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [, $offset]) {
            $clauses = (array) preg_split('/[.;:,!?]/u', substr(mb_strtolower($text), 0, (int) $offset));
            $before = array_slice(array_values(array_filter((array) preg_split('/[^\p{L}\p{N}\-]+/u', (string) end($clauses)), static fn (string $word): bool => $word !== '')), -4);

            if (array_intersect($before, ['no', 'not', 'never', 'without', 'nor', 'neither', 'none']) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $dimensions  a location's length_m, width_m and height_m, each {value, basis}
     * @return array{0: string, 1: string} every axis that has a value, with its unit, and the basis of each of those values; an unknown axis gives no figure
     */
    private static function size(array $dimensions): array
    {
        $figures = [];
        $bases = [];

        foreach (self::AXES as $axis => $word) {
            $dimension = (array) ($dimensions[$axis] ?? []);

            if (is_numeric($dimension['value'] ?? null)) {
                $figures[] = $word.' '.self::metres((float) $dimension['value']);
                $bases[] = $axis.' '.$dimension['basis'];
            }
        }

        return [implode(', ', $figures), implode('; ', $bases)];
    }

    /**
     * @param  list<mixed>  $elements  the visible parts of an installed feature, each with its element, count, arrangement and the keys of the measures that size it, as the design records them
     * @param  array<string, mixed>  $measures  the measures of that feature, by key, as the pinned context keeps them
     * @return array{0: string, 1: list<string>} every part as its count, its element and its arrangement with the measures it names as its own that the design declares a length in metres, so the frame shows what the design and the model sheet use; then the keys of those lengths. A measure no part names, or one declared a volume or a mass whatever its unit, never reaches the prompt
     */
    private static function elements(array $elements, array $measures): array
    {
        $texts = [];
        $sized = [];

        foreach (array_filter($elements, static fn (mixed $element): bool => is_array($element) && is_int($element['count'] ?? null) && is_string($element['element'] ?? null) && is_string($element['arrangement'] ?? null)) as $element) {
            $sizes = [];

            foreach ((array) ($element['measures'] ?? []) as $key) {
                $measure = $measures[$key] ?? null;

                if (is_string($key) && is_array($measure) && ($measure['quantity'] ?? null) === 'length' && ($measure['unit'] ?? null) === 'm' && is_numeric($measure['value'] ?? null)) {
                    $sizes[] = str_replace('_', ' ', (string) preg_replace('/^n\d+_/', '', $key)).' '.self::metres((float) $measure['value']);
                    $sized[] = $key;
                }
            }

            $texts[] = "{$element['count']} × {$element['element']}, {$element['arrangement']}".($sizes === [] ? '' : ' ('.implode(', ', $sizes).')');
        }

        return [implode('; ', $texts), $sized];
    }

    /**
     * @param  array<string, mixed>  $dimensions  a designed space's length_m, width_m and height_m with the scope the design says they measure
     * @return array{0: string, 1: string} the figures followed by that scope word for word, so a height of a whole envelope never reads as one open volume, and the basis of each figure
     */
    private static function measured(array $dimensions): array
    {
        [$figures, $bases] = self::size($dimensions);
        $scope = is_string($dimensions['scope'] ?? null) ? trim($dimensions['scope']) : '';

        return [$figures === '' || $scope === '' ? $figures : "{$figures} — {$scope}", $bases];
    }

    private static function metres(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').' m';
    }
}
