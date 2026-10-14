<?php

declare(strict_types=1);

namespace App\Video\Screenplay;

final class DesignLocationBuilder
{
    public const EQUIPMENT_ACCESS_KEY = 'equipment_access';

    public const DESIGN_REFS_KEY = 'design_refs';

    public const NOT_ESTABLISHED = 'not_established';

    public const NOT_APPLICABLE = 'not_applicable';

    public const ENDPOINT_UNRESOLVED = 'connection_endpoint_unresolved';

    /** @var array<string, string> */
    private const REF_LISTS = [
        'region' => 'spatial_topology',
        'surface' => 'surfaces',
        'route' => 'routes',
        'basin' => 'basins',
    ];

    /** @var list<string> */
    private const DESIGN_TEXT_FIELDS = ['name', 'description', 'enclosure', 'layout'];

    /** @var list<string> */
    private const LIGHT_KINDS = ['glazing', 'open_void'];

    /**
     * @param  list<mixed>  $answer  the locations the model chose
     * @param  array<string, mixed>  $design  the verified source design output
     * @param  list<array<string, mixed>>  $characters
     * @param  array<string, string>  $briefLabels  film_brief space key => label
     * @return array{0: ?list<array<string, mixed>>, 1: list<string>}
     */
    public static function build(array $answer, array $design, array $characters, array $briefLabels): array
    {
        $index = self::index($design);
        $subjectId = self::subjectId($characters);
        $violations = $subjectId === null ? ['characters: no protagonist of kind object to own the places aboard'] : [];
        $places = [];

        foreach ($answer as $position => $row) {
            $where = 'locations['.$position.']';

            if (! is_array($row) || ! is_string($row['id'] ?? null)) {
                $violations[] = "{$where}: must be an object with an id";

                continue;
            }

            $where = $row['id'];
            $relation = $row['spatial_relation'] ?? null;

            if ($relation === LocationProfile::SUBJECT_PART) {
                [$owned, $problems] = self::ownership($where, $row, $index, $briefLabels);
                $violations = [...$violations, ...$problems];
                $places[$row['id']] = ['row' => $row, 'owned' => $owned];
            } elseif ($relation === LocationProfile::EXTERNAL) {
                $violations = [...$violations, ...self::externalViolations($where, $row)];
                $places[$row['id']] = ['row' => $row, 'owned' => null];
            } else {
                $violations[] = "{$where}.spatial_relation: must be external or subject_part";
            }
        }

        if ($violations !== []) {
            return [null, array_values(array_unique($violations))];
        }

        $rows = [];

        foreach ($places as $id => $place) {
            [$connections, $problems] = self::connections($id, $place, $places, $index);
            $violations = [...$violations, ...$problems];

            if ($place['owned'] === null) {
                $rows[] = self::externalRow($place['row'], $connections);

                continue;
            }

            [$row, $problems] = self::subjectRow($place['row'], $place['owned'], $connections, $index, $briefLabels, (string) $subjectId);
            $violations = [...$violations, ...$problems];
            $rows[] = $row;
        }

        return $violations === [] ? [$rows, []] : [null, array_values(array_unique($violations))];
    }

    /**
     * @param  array<string, mixed>  $design
     * @return array<string, mixed>
     */
    private static function index(array $design): array
    {
        $canonical = is_array($design[VesselDesign::CANONICAL_KEY] ?? null) ? $design[VesselDesign::CANONICAL_KEY] : [];
        $index = ['decks' => [], 'openings' => [], 'regions_of_space' => [], 'interior' => []];

        foreach ([...array_values(self::REF_LISTS), 'decks', 'openings'] as $list) {
            foreach ((array) ($canonical[$list] ?? []) as $row) {
                if (is_array($row) && is_string($row['id'] ?? null)) {
                    $index[$list][$row['id']] = $row;
                }
            }
        }

        $interior = (array) ($design[ProtagonistProfile::OUTPUT_KEY][ProtagonistProfile::INTERIOR_KEY] ?? []);

        foreach ($interior as $position => $space) {
            if (is_array($space) && is_string($space['space'] ?? null)) {
                $index['interior'][$space['space']] = $space + ['position_index' => $position];
            }
        }

        foreach ((array) ($design[VesselDesign::GEOMETRY_LINKS_KEY] ?? []) as $link) {
            if (! is_array($link) || ($link['role'] ?? null) !== 'space'
                || preg_match('/^'.preg_quote(ProtagonistProfile::INTERIOR_KEY, '/').'\.(\d+)$/', (string) ($link['profile_path'] ?? ''), $match) !== 1) {
                continue;
            }

            $space = $interior[(int) $match[1]]['space'] ?? null;

            if (is_string($space)) {
                $index['regions_of_space'][$space] = array_values(array_filter((array) ($link['refs'] ?? []), 'is_string'));
            }
        }

        return $index;
    }

    /** @param  list<array<string, mixed>>  $characters */
    private static function subjectId(array $characters): ?string
    {
        foreach ($characters as $character) {
            if (is_array($character) && ($character['kind'] ?? null) === 'object' && ($character['role'] ?? null) === 'protagonist'
                && is_string($character['id'] ?? null)) {
                return $character['id'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $index
     * @param  array<string, string>  $briefLabels
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function ownership(string $where, array $row, array $index, array $briefLabels): array
    {
        $violations = [];
        $refs = ['region' => [], 'surface' => [], 'route' => [], 'basin' => []];

        foreach (self::DESIGN_TEXT_FIELDS as $field) {
            if (($row[$field] ?? null) !== null) {
                $violations[] = "{$where}.{$field}: a place aboard takes it from the design, so it is null";
            }
        }

        foreach (['fixed_features', 'light_sources'] as $field) {
            if (($row[$field] ?? []) !== []) {
                $violations[] = "{$where}.{$field}: a place aboard takes it from the design, so it is []";
            }
        }

        $given = $row[self::DESIGN_REFS_KEY] ?? null;

        if (! is_array($given) || ! array_is_list($given) || $given === []) {
            return [$refs, [...$violations, "{$where}.design_refs: a place aboard names at least one part of the design"]];
        }

        foreach ($given as $position => $ref) {
            $role = is_array($ref) ? ($ref['role'] ?? null) : null;
            $id = is_array($ref) ? ($ref['id'] ?? null) : null;
            $list = self::REF_LISTS[$role] ?? null;
            $at = "{$where}.design_refs[{$position}]";

            if ($list === null || ! is_string($id)) {
                $violations[] = "{$at}: must name a role (".implode(', ', array_keys(self::REF_LISTS)).') and an id';
            } elseif (! isset($index[$list][$id])) {
                $violations[] = "{$at}: {$id} is not a {$role} of the design";
            } elseif (($index[$list][$id]['status'] ?? null) !== 'locked') {
                $violations[] = "{$at}: {$id} is not locked in the design, so no place is built on it";
            } else {
                $refs[$role][] = $id;
            }
        }

        if ($refs['basin'] !== [] && $refs['region'] === [] && $refs['surface'] === []) {
            $violations[] = "{$where}.design_refs: a basin is named beside the region or surface that holds the pool, never alone";
        }

        $basinSurfaces = [];

        foreach (array_values(array_unique($refs['basin'])) as $basin) {
            $surface = $index['basins'][$basin]['surface'] ?? null;

            if (! is_string($surface) || ! isset($index['surfaces'][$surface])) {
                $violations[] = "{$where}.design_refs: basin {$basin} stands on ".(is_string($surface) ? $surface : 'no surface').', which is not a surface of the design';
            } elseif (($index['surfaces'][$surface]['status'] ?? null) !== 'locked') {
                $violations[] = "{$where}.design_refs: basin {$basin} stands on {$surface}, which is not locked in the design";
            } else {
                $basinSurfaces[] = $surface;
            }
        }

        $owned = self::owned($refs, $index, $basinSurfaces);
        $space = $row[FilmBrief::COVERAGE_SPACE_KEY] ?? null;

        if (is_string($space)) {
            $violations = [...$violations, ...self::briefViolations($where, $space, $owned, $index, $briefLabels)];
        }

        if ($owned['decks'] === []) {
            $violations[] = "{$where}: the design parts it names place it on no deck";
        }

        return [$owned, $violations];
    }

    /**
     * @param  array<string, list<string>>  $refs
     * @param  array<string, mixed>  $index
     * @param  list<string>  $basinSurfaces  the surfaces the named basins stand on
     * @return array<string, mixed>
     */
    private static function owned(array $refs, array $index, array $basinSurfaces = []): array
    {
        $regions = array_values(array_unique($refs['region']));
        $surfaces = [...$refs['surface'], ...$basinSurfaces];

        foreach ($index['surfaces'] as $id => $surface) {
            if (($surface['relation'] ?? null) === 'within' && in_array($surface['relative_to'] ?? null, $regions, true)) {
                $surfaces[] = $id;
            }
        }

        $surfaces = array_values(array_unique($surfaces));
        $basins = $refs['basin'];

        foreach ($index['basins'] as $id => $basin) {
            if (in_array($basin['surface'] ?? null, $surfaces, true)) {
                $basins[] = $id;
            }
        }

        $routes = $refs['route'];

        foreach ($index['routes'] as $id => $route) {
            if (in_array($route['from']['place'] ?? null, $surfaces, true) || in_array($route['to']['place'] ?? null, $surfaces, true)) {
                $routes[] = $id;
            }
        }

        $served = [...$regions, ...$surfaces];
        $openings = array_keys(array_filter(
            $index['openings'],
            static fn (array $opening): bool => in_array($opening['serves'] ?? null, $served, true),
        ));
        $decks = [];

        foreach ($surfaces as $id) {
            $decks[] = $index['surfaces'][$id]['deck'] ?? null;
        }

        foreach ($refs['route'] as $id) {
            $decks[] = $index['routes'][$id]['from']['deck'] ?? null;
            $decks[] = $index['routes'][$id]['to']['deck'] ?? null;
        }

        foreach ($openings as $id) {
            $decks[] = $index['openings'][$id]['deck'] ?? null;
        }

        return [
            'refs' => $refs,
            'regions' => $regions,
            'surfaces' => $surfaces,
            'basins' => array_values(array_unique($basins)),
            'routes' => array_values(array_unique($routes)),
            'openings' => $openings,
            'decks' => array_values(array_unique(array_filter($decks, static fn (mixed $deck): bool => is_string($deck) && isset($index['decks'][$deck])))),
        ];
    }

    /**
     * @param  array<string, mixed>  $owned
     * @param  array<string, mixed>  $index
     * @param  array<string, string>  $briefLabels
     * @return list<string>
     */
    private static function briefViolations(string $where, string $space, array $owned, array $index, array $briefLabels): array
    {
        if (! isset($briefLabels[$space])) {
            return [];
        }

        $regions = $index['regions_of_space'][$space] ?? [];
        $room = $index['interior'][$space] ?? null;

        if ($regions === [] || $room === null) {
            return ["{$where}.brief_space: the design gives no interior entry and region for {$space}, so the place cannot be built"];
        }

        $violations = [];

        if (array_intersect($regions, $owned['regions']) === []) {
            $violations[] = "{$where}.design_refs: {$space} is the design region ".implode(' or ', $regions).', which the place does not name';
        }

        $deckNames = array_map(static fn (string $deck): string => (string) ($index['decks'][$deck]['name'] ?? $deck), $owned['decks']);

        if (is_string($room['deck'] ?? null) && $owned['decks'] !== [] && ! in_array($room['deck'], $deckNames, true)) {
            $violations[] = "{$where}: the design puts {$space} on {$room['deck']}, the parts it names lie on ".implode(', ', $deckNames);
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private static function externalViolations(string $where, array $row): array
    {
        $violations = [];

        if (($row[self::DESIGN_REFS_KEY] ?? []) !== []) {
            $violations[] = "{$where}.design_refs: a place away from the subject names no part of the design";
        }

        foreach (self::DESIGN_TEXT_FIELDS as $field) {
            if (! is_string($row[$field] ?? null) || trim($row[$field]) === '') {
                $violations[] = "{$where}.{$field}: a place away from the subject describes itself";
            }
        }

        if (($row[FilmBrief::COVERAGE_SPACE_KEY] ?? null) !== null) {
            $violations[] = "{$where}.brief_space: a space of the project brief is part of the subject";
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $place
     * @param  array<string, array<string, mixed>>  $places
     * @param  array<string, mixed>  $index
     * @return array{0: list<array{target_location_id: string, via: string}>, 1: list<string>}
     */
    private static function connections(string $id, array $place, array $places, array $index): array
    {
        $connections = [];
        $violations = [];

        foreach ((array) ($place['row']['connections'] ?? []) as $position => $connection) {
            $where = "{$id}.connections[{$position}]";
            $target = is_array($connection) ? ($connection['target_location_id'] ?? null) : null;
            $ref = is_array($connection) ? ($connection['via_ref'] ?? null) : null;
            $text = is_array($connection) && is_string($connection['via'] ?? null) ? trim($connection['via']) : '';
            $other = is_string($target) ? ($places[$target] ?? null) : null;

            if ($other === null) {
                $violations[] = "{$where}: target ".(is_string($target) ? $target : 'the value').' is not a declared location';

                continue;
            }

            $aboard = $place['owned'] !== null && $other['owned'] !== null;
            $crossing = ($place['owned'] !== null) !== ($other['owned'] !== null);

            if ($aboard && ! is_string($ref)) {
                $violations[] = "{$where}: two places aboard connect through a route or opening of the design, named in via_ref";

                continue;
            }

            if ($crossing && ! is_string($ref)) {
                $violations[] = "{$where}: ".self::ENDPOINT_UNRESOLVED.': a place aboard reaches a place away from the subject only through '
                    .'an opening of the design that leads outside, named in via_ref; moving between them in the story is not a connection';

                continue;
            }

            if (! $aboard && ! $crossing && $ref !== null) {
                $violations[] = "{$where}.via_ref: two places away from the subject pass through no part of the design, so it is null";

                continue;
            }

            if (! $aboard && $text === '') {
                $violations[] = "{$where}: a connection with a place away from the subject says in via how it is made";

                continue;
            }

            $refText = null;

            if (is_string($ref)) {
                [$refText, $problem] = self::viaRef($where, $ref, $place['owned'], $other['owned'], $index);

                if ($problem !== null) {
                    $violations[] = $problem;

                    continue;
                }
            }

            $via = $aboard ? (string) $refText : ($refText === null ? $text : $refText.': '.$text);
            $connections[] = ['target_location_id' => $target, 'via' => self::clean($via)];
        }

        return [$connections, $violations];
    }

    /**
     * @param  array<string, mixed>|null  $from
     * @param  array<string, mixed>|null  $to
     * @param  array<string, mixed>  $index
     * @return array{0: ?string, 1: ?string}
     */
    private static function viaRef(string $where, string $ref, ?array $from, ?array $to, array $index): array
    {
        $sides = array_values(array_filter([$from, $to]));
        $unresolved = "{$where}.via_ref: ".self::ENDPOINT_UNRESOLVED.': ';

        if (isset($index['routes'][$ref])) {
            $route = $index['routes'][$ref];
            $ends = [$route['from']['place'] ?? null, $route['to']['place'] ?? null];
            $reaches = static fn (array $side, mixed $end): bool => in_array($end, $side['surfaces'], true);
            $joins = match (true) {
                count($sides) === 1 => false,
                in_array($ref, $sides[0]['refs']['route'], true) && in_array($ref, $sides[1]['refs']['route'], true) => false,
                in_array($ref, $sides[0]['refs']['route'], true) => $reaches($sides[1], $ends[0]) || $reaches($sides[1], $ends[1]),
                in_array($ref, $sides[1]['refs']['route'], true) => $reaches($sides[0], $ends[0]) || $reaches($sides[0], $ends[1]),
                default => ($reaches($sides[0], $ends[0]) && $reaches($sides[1], $ends[1]))
                    || ($reaches($sides[0], $ends[1]) && $reaches($sides[1], $ends[0])),
            };

            return $joins
                ? [self::routePhrase($route, $index), null]
                : [null, $unresolved.(count($sides) === 1
                    ? "route {$ref} stays aboard and cannot reach a place away from the subject"
                    : "route {$ref} does not run from one of the two places to the other")];
        }

        if (isset($index['openings'][$ref])) {
            $opening = $index['openings'][$ref];
            $connects = $opening[VesselDesign::CONNECTS_KEY] ?? null;

            if (! is_array($connects) || count($connects) !== 2) {
                return [null, $unresolved."opening {$ref} records only the place it serves, not what lies on its other side"];
            }

            [$first, $second] = array_values($connects);
            $holds = static fn (array $side, mixed $end): bool => in_array($end, [...$side['regions'], ...$side['surfaces']], true);
            $joins = count($sides) === 1
                ? ($holds($sides[0], $first) && $second === null) || ($holds($sides[0], $second) && $first === null)
                : ($holds($sides[0], $first) && $holds($sides[1], $second)) || ($holds($sides[0], $second) && $holds($sides[1], $first));

            return $joins
                ? [self::openingPhrase($opening, $index), null]
                : [null, $unresolved."opening {$ref} joins ".self::sideLabel($first).' and '.self::sideLabel($second).', not these two places'];
        }

        return [null, "{$where}.via_ref: {$ref} is not a route or opening of the design"];
    }

    private static function sideLabel(mixed $side): string
    {
        return is_string($side) ? $side : 'the outside of the vessel';
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{target_location_id: string, via: string}>  $connections
     * @return array<string, mixed>
     */
    private static function externalRow(array $row, array $connections): array
    {
        return [
            'id' => $row['id'],
            'name' => trim((string) $row['name']),
            'description' => trim((string) $row['description']),
            'story_use' => trim((string) ($row['story_use'] ?? '')),
            'spatial_relation' => LocationProfile::EXTERNAL,
            'subject_id' => null,
            FilmBrief::COVERAGE_SPACE_KEY => null,
            LocationProfile::ENCLOSURE_KEY => $row['enclosure'],
            'layout' => trim((string) $row['layout']),
            'connections' => $connections,
            'fixed_features' => self::texts($row['fixed_features'] ?? []),
            'light_sources' => self::texts($row['light_sources'] ?? []),
            self::EQUIPMENT_ACCESS_KEY => ['large_equipment' => self::NOT_APPLICABLE, 'routes' => []],
            self::DESIGN_REFS_KEY => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $owned
     * @param  list<array{target_location_id: string, via: string}>  $connections
     * @param  array<string, mixed>  $index
     * @param  array<string, string>  $briefLabels
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function subjectRow(array $row, array $owned, array $connections, array $index, array $briefLabels, string $subjectId): array
    {
        $id = (string) $row['id'];
        $space = is_string($row[FilmBrief::COVERAGE_SPACE_KEY] ?? null) ? $row[FilmBrief::COVERAGE_SPACE_KEY] : null;
        $room = $space === null ? null : ($index['interior'][$space] ?? null);
        [$enclosure, $enclosureProblem] = self::enclosure($id, $owned, $index);
        $layout = self::clean(implode(' ', array_filter([
            is_array($room) ? trim((string) ($room['position'] ?? '')) : '',
            ...array_map(static fn (string $region): string => trim((string) ($index['spatial_topology'][$region]['boundaries'] ?? '')), $owned['regions']),
            ...($room === null ? array_map(static fn (string $region): string => trim((string) ($index['spatial_topology'][$region]['circulation'] ?? '')), $owned['regions']) : []),
            ...array_map(static fn (string $route): string => trim((string) ($index['routes'][$route]['support'] ?? '')), $owned['refs']['route']),
            ...array_map(static fn (string $surface): string => self::surfacePhrase($index['surfaces'][$surface], $index).'.', $owned['refs']['surface']),
        ])));
        $problems = $enclosureProblem === null ? [] : [$enclosureProblem];

        if (mb_strlen($layout) < 40) {
            $problems[] = "{$id}: the design parts it names do not describe the place well enough to build its layout";
        }

        $built = [
            'id' => $id,
            'name' => self::clean(self::name($owned, $space, $index, $briefLabels)),
            'description' => self::description($owned, $index),
            'story_use' => trim((string) ($row['story_use'] ?? '')),
            'spatial_relation' => LocationProfile::SUBJECT_PART,
            'subject_id' => $subjectId,
            FilmBrief::COVERAGE_SPACE_KEY => $space,
            LocationProfile::ENCLOSURE_KEY => $enclosure,
            'layout' => $layout,
            'connections' => $connections,
            'fixed_features' => self::fixedFeatures($owned, $index),
            'light_sources' => self::lightSources($owned, $index, $enclosure),
            self::EQUIPMENT_ACCESS_KEY => ['large_equipment' => self::NOT_ESTABLISHED, 'routes' => $owned['routes']],
            self::DESIGN_REFS_KEY => array_values(array_merge(...array_map(
                static fn (string $role, array $ids): array => array_map(static fn (string $ref): array => ['role' => $role, 'id' => $ref], $ids),
                array_keys($owned['refs']),
                array_values($owned['refs']),
            ))),
        ];

        return [$built, $problems];
    }

    /**
     * @param  array<string, mixed>  $owned
     * @param  array<string, mixed>  $index
     * @return array{0: ?string, 1: ?string}
     */
    private static function enclosure(string $id, array $owned, array $index): array
    {
        $roles = [];

        foreach ($owned['regions'] as $region) {
            $roles[] = $index['spatial_topology'][$region]['exterior_role'] ?? null;
        }

        if ($roles === []) {
            foreach ([...$owned['refs']['surface'], ...$owned['refs']['route']] as $part) {
                $roles[] = ($index['surfaces'][$part] ?? $index['routes'][$part] ?? [])['exterior_role'] ?? null;
            }
        }

        $kinds = array_values(array_unique(array_map(static fn (mixed $role): ?string => match ($role) {
            'exterior' => LocationProfile::OPEN_AIR,
            'interior_only', 'interior_affects_exterior' => LocationProfile::INTERIOR,
            default => null,
        }, $roles)));

        return count($kinds) === 1 && $kinds[0] !== null
            ? [$kinds[0], null]
            : [null, "{$id}.enclosure: the design leaves it unsettled whether the place is enclosed or open to the sky"];
    }

    /**
     * @param  array<string, mixed>  $owned
     * @param  array<string, mixed>  $index
     * @param  array<string, string>  $briefLabels
     */
    private static function name(array $owned, ?string $space, array $index, array $briefLabels): string
    {
        if ($space !== null && isset($briefLabels[$space])) {
            return $briefLabels[$space];
        }

        if ($owned['regions'] !== []) {
            return (string) ($index['spatial_topology'][$owned['regions'][0]]['region'] ?? $owned['regions'][0]);
        }

        if ($owned['refs']['route'] !== []) {
            return ucfirst(self::routePhrase($index['routes'][$owned['refs']['route'][0]], $index, false));
        }

        return ucfirst(self::surfacePhrase($index['surfaces'][$owned['refs']['surface'][0]], $index, false));
    }

    /**
     * @param  array<string, mixed>  $owned
     * @param  array<string, mixed>  $index
     */
    private static function description(array $owned, array $index): string
    {
        $parts = [];

        foreach ($owned['regions'] as $region) {
            $row = $index['spatial_topology'][$region];
            $parts[] = trim((string) ($row['region'] ?? '')).(is_string($row['deck'] ?? null) ? ', '.trim($row['deck']) : '');
        }

        foreach ($owned['refs']['route'] as $route) {
            $parts[] = ucfirst(self::routePhrase($index['routes'][$route], $index, false));
        }

        foreach ($owned['refs']['surface'] as $surface) {
            $parts[] = ucfirst(self::surfacePhrase($index['surfaces'][$surface], $index, false));
        }

        return self::clean(implode('. ', array_filter($parts)).'.');
    }

    /**
     * @param  array<string, mixed>  $owned
     * @param  array<string, mixed>  $index
     * @return list<string>
     */
    private static function fixedFeatures(array $owned, array $index): array
    {
        $features = [];

        foreach ($owned['openings'] as $opening) {
            if (! in_array($index['openings'][$opening]['kind'] ?? null, self::LIGHT_KINDS, true)) {
                $features[] = self::openingPhrase($index['openings'][$opening], $index);
            }
        }

        foreach ($owned['routes'] as $route) {
            $features[] = self::routePhrase($index['routes'][$route], $index);
        }

        foreach ($owned['basins'] as $basin) {
            $row = $index['basins'][$basin];
            $features[] = 'the pool basin set '.self::words((string) ($row['water_level'] ?? '')).' in '
                .self::surfacePhrase($index['surfaces'][$row['surface']] ?? ['id' => (string) ($row['surface'] ?? '')], $index)
                .', running '.self::words((string) ($row['orientation'] ?? ''));
        }

        foreach ($owned['surfaces'] as $surface) {
            if (($index['surfaces'][$surface]['kind'] ?? null) !== 'deck_floor') {
                $features[] = self::surfacePhrase($index['surfaces'][$surface], $index);
            }
        }

        return array_values(array_unique(array_map(static fn (string $feature): string => self::clean($feature), $features)));
    }

    /**
     * @param  array<string, mixed>  $owned
     * @param  array<string, mixed>  $index
     * @return list<string>
     */
    private static function lightSources(array $owned, array $index, ?string $enclosure): array
    {
        $lights = [];

        foreach ($owned['openings'] as $opening) {
            if (in_array($index['openings'][$opening]['kind'] ?? null, self::LIGHT_KINDS, true)) {
                $lights[] = self::openingPhrase($index['openings'][$opening], $index);
            }
        }

        if ($enclosure === LocationProfile::OPEN_AIR) {
            $lights[] = 'the open sky above the place';
        }

        return array_values(array_unique($lights));
    }

    /**
     * @param  array<string, mixed>  $route
     * @param  array<string, mixed>  $index
     */
    private static function routePhrase(array $route, array $index, bool $article = true): string
    {
        $kind = self::words((string) ($route['kind'] ?? 'route'));
        $from = self::deckName($route['from']['deck'] ?? null, $index);
        $to = self::deckName($route['to']['deck'] ?? null, $index);

        return ($article ? 'the ' : '').($from === $to ? "{$kind} on {$from}" : "{$kind} from {$from} to {$to}");
    }

    /**
     * @param  array<string, mixed>  $opening
     * @param  array<string, mixed>  $index
     */
    private static function openingPhrase(array $opening, array $index): string
    {
        return 'the '.self::words((string) ($opening['kind'] ?? 'opening')).' in the '.self::words((string) ($opening['face'] ?? ''))
            .' face on '.self::deckName($opening['deck'] ?? null, $index);
    }

    /**
     * @param  array<string, mixed>  $surface
     * @param  array<string, mixed>  $index
     */
    private static function surfacePhrase(array $surface, array $index, bool $article = true): string
    {
        $side = (string) ($surface['side'] ?? 'unspecified');

        return ($article ? 'the ' : '').self::words((string) ($surface['kind'] ?? 'surface')).' on '.self::deckName($surface['deck'] ?? null, $index)
            .(in_array($side, ['port', 'starboard', 'centerline'], true) ? ', '.($side === 'centerline' ? 'on the centerline' : "to {$side}") : '');
    }

    /** @param  array<string, mixed>  $index */
    private static function deckName(mixed $deck, array $index): string
    {
        return is_string($deck) ? (string) ($index['decks'][$deck]['name'] ?? $deck) : 'an unnamed deck';
    }

    private static function words(string $value): string
    {
        return str_replace('_', ' ', $value);
    }

    private static function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** @return list<string> */
    private static function texts(mixed $values): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $value): string => is_string($value) ? trim($value) : '', is_array($values) ? $values : []),
            static fn (string $value): bool => $value !== '',
        ));
    }
}
