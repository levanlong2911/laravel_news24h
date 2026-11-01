<?php

declare(strict_types=1);

namespace App\Video\Superyacht\Locations;

use RuntimeException;

final class SuperyachtLocationsPayload
{
    public const VERSION = 'phase5-payload-v7';

    public const BEAT_KEYS = ['id', 'bs', 'location', 'time_light', 'rooms', 'visual', 'action', 'camera_family', 'purpose', 'reveal', 'link', 'target_sec'];

    public const CHECK_BEAT_KEYS = ['id', 'bs', 'location', 'visual', 'action', 'time_light', 'purpose'];

    public const SPEC_KEYS = ['loa', 'lwl', 'beam', 'draft', 'decks'];

    public const ROOM_KEYS = ['name', 'deck', 'position', 'size_feel', 'materials', 'signature_detail', 'window_type'];

    public const PROMPT_ROOM_KEYS = ['name', 'deck', 'size_feel', 'window_type', 'signature_detail'];

    /** @var list<string> the room fields of a design that records its spaces, sent once so that every room a beat describes can be recognised and planned */
    public const SPATIAL_ROOM_KEYS = ['name', 'position', 'size_feel', 'use', 'activities', 'privacy', 'layout', 'built_ins', 'furniture', 'surfaces', 'signature_detail', 'window_type', 'lighting'];

    private const VISIBLE_KEYS = ['visible_expression', 'visible'];

    /**
     * @param  array<string, mixed>  $answer  the data of the pinned design answer
     * @return array{interior_set_states: array<string, string>, lighting_signature: ?string, identity_features: list<array<string, mixed>>} the design texts Phase 5 reads from the design stage itself because the script's pinned snapshot does not carry them
     */
    public static function designOf(array $answer): array
    {
        $states = $answer['yacht_bible']['interior_set_states'] ?? [];
        $signature = $answer['yacht_bible']['lighting_signature'] ?? null;

        return [
            'interior_set_states' => array_filter(is_array($states) ? $states : [], static fn (mixed $sentence, mixed $state): bool => is_string($state) && is_string($sentence) && trim($sentence) !== '', ARRAY_FILTER_USE_BOTH),
            'lighting_signature' => is_string($signature) && trim($signature) !== '' ? $signature : null,
            'identity_features' => array_values(array_filter((array) ($answer['canonical']['identity_features'] ?? []), static fn (mixed $feature): bool => is_array($feature) && is_string($feature['id'] ?? null))),
        ];
    }

    /**
     * @param  array<string, mixed>  $script  the data of the approved script document (story, script)
     * @param  array<string, mixed>  $pinned  the design snapshot the script was written from
     * @param  array{interior_set_states: array<string, string>, lighting_signature: ?string, identity_features: list<array<string, mixed>>}  $design  designOf() of the pinned design stage
     * @param  array{loa_m: ?float, beam_m: ?float, height_m: ?float}  $dimensions
     * @param  list<string>  $avoidNames
     * @return array{payload: array<string, mixed>, context: array<string, mixed>} what Phase 5 sends once — the beats, the tour, the notes, the yacht's size and decks, every room and exterior zone with the fields that identify it, the design's innovations and night lighting, the build states and the operating limits — and the design texts the system keeps to check the answer and to build every prompt
     *
     * @throws RuntimeException when the pinned design lacks its bible or anchors, or does not describe a room or zone the script names
     */
    public static function build(array $script, array $pinned, array $design, array $dimensions, string $tourBeat, array $avoidNames): array
    {
        $bible = (array) ($pinned['yacht_bible'] ?? throw new RuntimeException('Bản thiết kế kịch bản đã ghim không có yacht_bible.'));
        $canonical = (array) ($pinned['canonical'] ?? []);
        $beats = array_values((array) $script['script']['beats']);
        $stops = array_values((array) $script['script']['tour']['stops']);
        $notes = $script['story']['notes'] ?? null;
        $rooms = array_values(array_filter((array) ($bible['rooms'] ?? []), static fn (mixed $room): bool => is_array($room) && is_string($room['id'] ?? null)));
        $zones = array_values(array_filter((array) ($bible['exterior_zones'] ?? []), static fn (mixed $zone): bool => is_array($zone) && is_string($zone['id'] ?? null)));
        $states = array_values(array_filter((array) ($canonical['build_states'] ?? []), static fn (mixed $state): bool => is_array($state) && is_string($state['id'] ?? null) && is_string($state['kind'] ?? null)));
        $limits = array_values(array_filter((array) ($canonical['operational_limits'] ?? []), 'is_array'));
        $kinds = array_column($states, 'kind', 'id');
        $innovations = self::innovations((array) ($bible['innovations'] ?? []), $design['identity_features'], self::relations($canonical), (array) ($canonical['dimensions'] ?? []));
        $spaces = self::spaces($canonical);
        $spatial = self::spatial($pinned);

        self::assertNamed($beats, $stops, array_column($rooms, 'id'), array_column($zones, 'id'));

        return [
            'payload' => [
                'script' => [
                    'beats' => array_map(static fn (array $beat): array => array_intersect_key($beat, array_flip(self::BEAT_KEYS)), $beats),
                    'tour' => [
                        'beat' => $tourBeat,
                        'stops' => array_map(static fn (array $stop, int $index): array => [
                            'index' => $index,
                            'source_id' => $stop['source_id'],
                            'visual' => $stop['visual'] ?? null,
                            'match_bare_beat' => $stop['match_bare_beat'] ?? null,
                            'sec' => $stop['sec'] ?? null,
                        ], $stops, array_keys($stops)),
                    ],
                    'notes' => is_string($notes) && trim($notes) !== '' ? $notes : null,
                ],
                'yacht' => [
                    'name' => $bible['name'] ?? throw new RuntimeException('yacht_bible đã ghim của kịch bản thiếu name.'),
                    'specs' => array_intersect_key((array) ($bible['specs'] ?? []), array_flip(self::SPEC_KEYS)),
                    'decks' => array_values((array) ($bible['decks'] ?? [])),
                ],
                'dimensions' => $dimensions,
                'design' => $spatial ? [
                    'rooms' => array_map(static fn (array $room): array => ['id' => $room['id']] + array_intersect_key($room, array_flip(self::SPATIAL_ROOM_KEYS))
                        + ['decks' => $spaces[$room['id']]['decks'] ?? [], 'dimensions' => $spaces[$room['id']]['dimensions'] ?? null], $rooms),
                    'exterior_zones' => array_map(static fn (array $zone): array => ['id' => $zone['id'], 'name' => $zone['name'] ?? null, 'text' => $zone['text'] ?? null, 'use' => $zone['use'] ?? null,
                        'decks' => $spaces[$zone['id']]['decks'] ?? [], 'dimensions' => $spaces[$zone['id']]['dimensions'] ?? null], $zones),
                    'innovations' => array_values(array_map(static fn (array $innovation): array => array_intersect_key($innovation, array_flip(['id', 'name', 'purpose', 'position', 'visible', 'visible_elements', 'effect', 'mounted_in', 'serves', 'effect_in', 'from_state'])), $innovations)),
                    'connections' => array_values(array_filter((array) ($canonical['connections'] ?? []), 'is_array')),
                    'routes' => array_values(array_filter((array) ($canonical['routes'] ?? []), 'is_array')),
                    'identity_rules' => array_values(array_filter((array) ($canonical['identity_features'] ?? []), 'is_array')),
                    'lighting_signature' => $design['lighting_signature'],
                    'interior_states' => array_keys($design['interior_set_states']),
                    'build_states' => self::buildStates((array) ($pinned['build_states'] ?? []), $kinds),
                    'operational_limits' => $limits,
                ] : [
                    'rooms' => array_map(static fn (array $room): array => ['id' => $room['id']] + array_filter(
                        array_intersect_key($room, array_flip(self::ROOM_KEYS)),
                        static fn (mixed $value): bool => $value !== '' && $value !== null && $value !== [],
                    ), $rooms),
                    'exterior_zones' => array_map(static fn (array $zone): array => ['id' => $zone['id'], 'text' => $zone['text'] ?? null], $zones),
                    'innovations' => array_values(array_map(static fn (array $innovation): array => array_intersect_key($innovation, array_flip(['id', 'name', 'position', 'visible', 'from_state'])), $innovations)),
                    'lighting_signature' => $design['lighting_signature'],
                    'interior_states' => array_keys($design['interior_set_states']),
                    'build_states' => self::buildStates((array) ($pinned['build_states'] ?? []), $kinds),
                    'operational_limits' => $limits,
                ],
                'avoid_names' => $avoidNames,
            ],
            'context' => [
                'beats' => array_map(static fn (array $beat): array => array_intersect_key($beat, array_flip(self::CHECK_BEAT_KEYS)) + [
                    'rooms' => array_map(static fn (array $room): array => ['id' => (string) $room['id'], 'state' => (string) $room['set_state']], (array) ($beat['rooms'] ?? [])),
                    'target_sec' => is_numeric($beat['target_sec'] ?? null) ? (float) $beat['target_sec'] : null,
                    'reveal' => is_string($beat['reveal'] ?? null) ? $beat['reveal'] : null,
                ], $beats),
                'tour_beat' => $tourBeat,
                'tour' => array_map(static fn (array $stop, int $index): array => [
                    'index' => $index,
                    'source_id' => (string) $stop['source_id'],
                    'visual' => (string) ($stop['visual'] ?? ''),
                    'match_bare_beat' => $stop['match_bare_beat'] ?? null,
                    'sec' => is_numeric($stop['sec'] ?? null) ? (float) $stop['sec'] : null,
                ], $stops, array_keys($stops)),
                'beat_presence' => self::presence($beats, $kinds),
                'yacht' => $dimensions,
                'design' => self::designContext($pinned, $design),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $pinned  the design snapshot the script was written from
     * @param  array{interior_set_states: array<string, string>, lighting_signature: ?string, identity_features: list<array<string, mixed>>}  $design  designOf() of the pinned design stage
     * @return array<string, mixed> every design text the check and the prompts read, by id: the prompt fields, materials and palette of each room, the state sentences, the anchors, the zone texts, the surfaces, the innovations with what is visible of them and from which build state, the night lighting, the build kinds and the operating limits
     */
    public static function designContext(array $pinned, array $design): array
    {
        $bible = (array) ($pinned['yacht_bible'] ?? []);
        $anchors = (array) ($pinned['anchors'] ?? []);
        $canonical = (array) ($pinned['canonical'] ?? []);
        $rooms = array_values(array_filter((array) ($bible['rooms'] ?? []), static fn (mixed $room): bool => is_array($room) && is_string($room['id'] ?? null)));
        $zones = array_values(array_filter((array) ($bible['exterior_zones'] ?? []), static fn (mixed $zone): bool => is_array($zone) && is_string($zone['id'] ?? null)));
        $states = array_values(array_filter((array) ($canonical['build_states'] ?? []), static fn (mixed $state): bool => is_array($state) && is_string($state['id'] ?? null) && is_string($state['kind'] ?? null)));
        $limits = array_values(array_filter((array) ($canonical['operational_limits'] ?? []), 'is_array'));
        $context = [
            'rooms' => array_column(array_map(static fn (array $room): array => [
                'id' => $room['id'],
                'fields' => array_filter(array_intersect_key($room, array_flip(self::PROMPT_ROOM_KEYS)), static fn (mixed $value): bool => is_string($value) && trim($value) !== ''),
                'materials' => array_values(array_filter((array) ($room['materials'] ?? []), 'is_string')),
                'palette' => array_values(array_filter((array) ($room['palette'] ?? []), 'is_string')),
            ], $rooms), null, 'id'),
            'interior_set_states' => $design['interior_set_states'],
            'interior_anchors' => array_filter((array) ($anchors['interior'] ?? []), 'is_string'),
            'zones' => array_column(array_map(static fn (array $zone): array => ['id' => $zone['id'], 'text' => (string) ($zone['text'] ?? '')], $zones), 'text', 'id'),
            'exterior_anchors' => array_filter((array) ($anchors['exterior'] ?? []), 'is_string'),
            'surface_states' => array_filter((array) ($anchors['surface_states'] ?? []), 'is_string'),
            'innovations' => self::innovations((array) ($bible['innovations'] ?? []), $design['identity_features'], self::relations($canonical), (array) ($canonical['dimensions'] ?? [])),
            'lighting_signature' => $design['lighting_signature'],
            'build_kinds' => array_column($states, 'kind', 'id'),
            'limits' => array_values(array_filter(array_map(static fn (array $limit): ?string => is_string($limit['feature'] ?? null) ? $limit['feature'] : null, $limits))),
        ];

        if (! self::spatial($pinned)) {
            return $context;
        }

        $spaces = self::spaces($canonical);
        $rules = [];

        foreach (array_filter((array) ($canonical['identity_features'] ?? []), 'is_array') as $rule) {
            foreach ((array) ($rule['scope'] ?? []) as $scope) {
                $rules[(string) $scope][] = ['id' => (string) ($rule['id'] ?? ''), 'kind' => (string) ($rule['kind'] ?? ''), 'description' => (string) ($rule['description'] ?? ''), 'from_state' => is_string($rule['from_state'] ?? null) ? $rule['from_state'] : null];
            }
        }

        foreach ($rooms as $room) {
            $surfaces = array_values(array_filter((array) ($room['surfaces'] ?? []), 'is_array'));
            $builtIns = array_values(array_filter((array) ($room['built_ins'] ?? []), 'is_array'));
            $furniture = array_values(array_filter((array) ($room['furniture'] ?? []), 'is_array'));
            $lighting = array_values(array_filter((array) ($room['lighting'] ?? []), 'is_array'));
            $layout = array_filter((array) ($room['layout'] ?? []), 'is_string');
            $deck = implode(', ', array_filter((array) ($spaces[$room['id']]['decks'] ?? []), 'is_string'));
            $entry = &$context['rooms'][$room['id']];
            $entry['fields'] += $deck === '' ? [] : ['deck' => $deck];
            $entry['materials'] = array_values(array_map(static fn (array $surface): string => trim(((string) ($surface['material'] ?? '')).' '.((string) ($surface['element'] ?? ''))), $surfaces));
            $entry['surfaces'] = $surfaces;
            $entry['built_ins'] = $builtIns;
            $entry['furniture'] = $furniture;
            $entry['lighting'] = $lighting;
            $entry['layout'] = $layout;
            $entry['dimensions'] = $spaces[$room['id']]['dimensions'] ?? null;
            $entry['text'] = implode(' ', [
                ...array_map(static fn (array $piece): string => ((string) ($piece['name'] ?? '')).' '.((string) ($piece['description'] ?? '')), $builtIns),
                ...array_map(static fn (array $item): string => ((string) ($item['item'] ?? '')).' '.((string) ($item['placement'] ?? '')), $furniture),
                ...array_map(static fn (array $light): string => ((string) ($light['source'] ?? '')).' '.((string) ($light['position'] ?? '')), $lighting),
                ...array_values($layout),
            ]);
            unset($entry);
        }

        $context['zone_names'] = array_column(array_map(static fn (array $zone): array => ['id' => $zone['id'], 'name' => (string) ($zone['name'] ?? '')], $zones), 'name', 'id');
        $context['zone_dimensions'] = array_map(static fn (array $space): mixed => $space['dimensions'] ?? null, array_intersect_key($spaces, $context['zones']));
        $context['rules'] = $rules;
        $context['spaces'] = $spaces;

        return $context;
    }

    /** @param  array<string, mixed>  $pinned  the design snapshot the script was written from */
    public static function spatial(array $pinned): bool
    {
        return is_array($pinned['canonical']['spaces'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @return array<string, array{decks: list<string>, dimensions: mixed}> every space of a design that records its spaces, by id
     */
    private static function spaces(array $canonical): array
    {
        $spaces = [];

        foreach (array_filter((array) ($canonical['spaces'] ?? []), static fn (mixed $space): bool => is_array($space) && is_string($space['id'] ?? null)) as $space) {
            $spaces[$space['id']] = ['decks' => array_values(array_filter((array) ($space['decks'] ?? []), 'is_string')), 'dimensions' => $space['dimensions'] ?? null];
        }

        return $spaces;
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @return array<string, array<string, mixed>> the relations of every innovation of a design that records them, by id
     */
    private static function relations(array $canonical): array
    {
        return array_column(array_values(array_filter((array) ($canonical['innovations'] ?? []), static fn (mixed $row): bool => is_array($row) && is_string($row['id'] ?? null))), null, 'id');
    }

    /**
     * @param  array<string, mixed>  $dimensions  the canonical dimensions of the design
     * @return array<string, array{value: int|float, unit: string, quantity: ?string, basis: ?string, path: string}> the measures of one innovation's equipment, by key, as the design records them under the lowercase id of that innovation, with what each measures; the measures of every other innovation stay out
     */
    private static function measures(array $dimensions, string $id): array
    {
        $own = [];

        foreach ($dimensions as $key => $measure) {
            if (is_string($key) && str_starts_with($key, mb_strtolower($id).'_') && is_array($measure) && (is_int($measure['value'] ?? null) || is_float($measure['value'] ?? null)) && is_string($measure['unit'] ?? null)) {
                $own[$key] = [
                    'value' => $measure['value'],
                    'unit' => $measure['unit'],
                    'quantity' => is_string($measure['quantity'] ?? null) ? $measure['quantity'] : null,
                    'basis' => is_string($measure['basis'] ?? null) ? $measure['basis'] : null,
                    'path' => "canonical.dimensions.{$key}",
                ];
            }
        }

        return $own;
    }

    /**
     * @param  list<mixed>  $innovations
     * @param  list<array<string, mixed>>  $features  the canonical identity features of the design
     * @param  array<string, array<string, mixed>>  $relations  the canonical relations of each innovation, for a design that records them
     * @param  array<string, mixed>  $dimensions  the canonical dimensions of the design, where each measure of an innovation's equipment is named by its lowercase id
     * @return array<string, array<string, mixed>> every innovation by id with the design's own text of what is visible of it, whichever key the design wrote it under, and the build state it exists from — for a design that records relations also its purpose, its effect, where it is mounted, whom it serves, where its effect is seen, the count, arrangement and measure keys of each part a camera sees, and its own measures with the path each comes from
     */
    private static function innovations(array $innovations, array $features, array $relations = [], array $dimensions = []): array
    {
        $from = [];

        foreach ($features as $feature) {
            if (preg_match('/^P0-(N\d+)$/', (string) $feature['id'], $match) === 1 && is_string($feature['from_state'] ?? null)) {
                $from[$match[1]] = $feature['from_state'];
            }
        }

        $rows = [];

        foreach ($innovations as $innovation) {
            if (! is_array($innovation) || ! is_string($innovation['id'] ?? null)) {
                continue;
            }

            $visible = null;

            foreach (self::VISIBLE_KEYS as $key) {
                $visible ??= is_string($innovation[$key] ?? null) && trim($innovation[$key]) !== '' ? $innovation[$key] : null;
            }

            $relation = $relations[$innovation['id']] ?? null;
            $rows[$innovation['id']] = [
                'id' => $innovation['id'],
                'name' => (string) ($innovation['name'] ?? ''),
                'position' => (string) ($innovation['position'] ?? ''),
                'geometry' => (string) ($innovation['geometry'] ?? ''),
                'visible' => $visible,
                'from_state' => $relation === null ? ($from[$innovation['id']] ?? null) : (is_string($relation['from_state'] ?? null) ? $relation['from_state'] : null),
            ] + ($relations === [] ? [] : [
                'purpose' => is_string($innovation['purpose'] ?? null) ? $innovation['purpose'] : null,
                'effect' => is_string($innovation['effect'] ?? null) && trim($innovation['effect']) !== '' ? $innovation['effect'] : null,
                'mounted_in' => array_values(array_filter((array) ($relation['mounted_in'] ?? []), 'is_string')),
                'serves' => array_values(array_filter((array) ($relation['serves'] ?? []), 'is_string')),
                'effect_in' => array_values(array_filter((array) ($relation['effect_in'] ?? []), 'is_string')),
                'visible_elements' => array_values(array_map(
                    static fn (array $element): array => array_intersect_key($element, array_flip(['element', 'count', 'arrangement', 'basis', 'measures'])),
                    array_filter((array) ($relation['visible_elements'] ?? []), static fn (mixed $element): bool => is_array($element) && is_string($element['element'] ?? null) && is_int($element['count'] ?? null) && is_string($element['arrangement'] ?? null)),
                )),
                'measures' => self::measures($dimensions, $innovation['id']),
            ]);
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $beats
     * @param  list<array<string, mixed>>  $stops
     * @param  list<string>  $rooms
     * @param  list<string>  $zones
     *
     * @throws RuntimeException when the script names a room or zone the pinned design does not describe
     */
    private static function assertNamed(array $beats, array $stops, array $rooms, array $zones): void
    {
        $named = array_merge(
            array_merge([], ...array_map(static fn (array $beat): array => array_map(static fn (array $room): string => (string) $room['id'], (array) ($beat['rooms'] ?? [])), $beats)),
            array_map(static fn (array $stop): string => (string) $stop['source_id'], $stops),
        );

        foreach (array_unique($named) as $id) {
            if (! in_array($id, str_starts_with($id, 'EXT') ? $zones : $rooms, true)) {
                throw new RuntimeException("Kịch bản dùng {$id}, bản thiết kế đã ghim không mô tả không gian này.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $sentences  the design's sentence for every build state
     * @param  array<string, string>  $kinds  the kind of every canonical build state
     * @return list<array{id: string, kind: ?string, text: ?string}> every build state in design order, with its kind when the yacht or its model physically exists in it
     */
    private static function buildStates(array $sentences, array $kinds): array
    {
        $rows = [];

        foreach ($sentences as $id => $text) {
            $rows[(string) $id] = ['id' => (string) $id, 'kind' => $kinds[$id] ?? null, 'text' => is_string($text) ? $text : null];
        }

        foreach ($kinds as $id => $kind) {
            $rows[$id] ??= ['id' => $id, 'kind' => $kind, 'text' => null];
        }

        return array_values($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $beats
     * @param  array<string, string>  $kinds
     * @return array<string, array{bs: string, kind: string}> every beat whose build state the design describes as physical, with that state's kind
     */
    private static function presence(array $beats, array $kinds): array
    {
        $presence = [];

        foreach ($beats as $beat) {
            $bs = (string) ($beat['bs'] ?? '');

            if (isset($kinds[$bs])) {
                $presence[(string) $beat['id']] = ['bs' => $bs, 'kind' => $kinds[$bs]];
            }
        }

        return $presence;
    }
}
