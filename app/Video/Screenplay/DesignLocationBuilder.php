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

    public const STORY_NAME_KEY = 'story_name';

    public const ROUTE_EVIDENCE_KEY = 'route_evidence';

    /** @var list<string> */
    private const ROUTE_PART_WORDS = ['landing', 'landings', 'foot', 'top', 'bottom'];

    /** @var array<string, list<string>> */
    private const DIRECTION_WORDS = [
        'down' => ['down', 'downward', 'downwards'],
        'up' => ['up', 'upward', 'upwards'],
    ];

    /** @var array<string, list<string>> */
    private const DIRECTION_VERBS = [
        'down' => ['descend', 'descends', 'descended', 'descending'],
        'up' => ['ascend', 'ascends', 'ascended', 'ascending'],
    ];

    /** @var list<string> */
    private const MOVE_VERBS = [
        'walk', 'walks', 'walked', 'walking', 'go', 'goes', 'went', 'going', 'climb', 'climbs', 'climbed', 'climbing',
        'take', 'takes', 'took', 'taking', 'head', 'heads', 'headed', 'heading', 'come', 'comes', 'came', 'coming',
        'run', 'runs', 'ran', 'running', 'step', 'steps', 'stepped', 'stepping', 'move', 'moves', 'moved', 'moving',
        'hurry', 'hurries', 'hurried', 'hurrying',
    ];

    /** @var list<string> */
    private const MOVEMENT_WORDS = [
        ...self::DIRECTION_WORDS['down'], ...self::DIRECTION_WORDS['up'], ...self::DIRECTION_VERBS['down'], ...self::DIRECTION_VERBS['up'],
        ...self::MOVE_VERBS, 'from', 'toward', 'towards',
    ];

    /** @var list<string> */
    private const NAME_STOPWORDS = ['the', 'a', 'an', 'of', 'on', 'at', 'in', 'to', 'and', 's'];

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
     * @param  list<mixed>  $answer  the locations the model chose from the screenplay content alone
     * @param  array<string, mixed>  $design  the verified source design output
     * @param  string  $story  every text of the screenplay content the model read
     * @param  list<array<string, mixed>>  $characters
     * @param  array<string, string>  $briefLabels  film_brief space key => label
     * @return array{0: ?list<array<string, mixed>>, 1: list<string>}
     */
    public static function fromStory(array $answer, array $design, string $story, array $characters, array $briefLabels): array
    {
        $index = self::index($design);
        $briefRegions = array_values(array_filter(array_map(
            static fn (string $space): ?string => self::spaceRegion($space, $index, $briefLabels)[0],
            array_keys($briefLabels),
        )));
        $storyText = self::flat($story);
        $violations = [];
        $problems = [];
        $pending = [];
        $rows = [];
        $owned = [];

        foreach ($answer as $position => $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null)) {
                $violations[] = 'locations['.$position.']: must be an object with an id';

                continue;
            }

            $id = $row['id'];
            $converted = array_diff_key($row, [self::STORY_NAME_KEY => true, self::ROUTE_EVIDENCE_KEY => true]);
            $converted[self::DESIGN_REFS_KEY] = [];

            if (($row['spatial_relation'] ?? null) === LocationProfile::SUBJECT_PART) {
                [$refs, $problem, $routes] = array_pad(self::storyRefs($id, $row, $index, $briefLabels, $briefRegions, $storyText), 3, null);

                if ($problem !== null) {
                    $problems[$id] = $problem;
                } elseif ($routes !== null) {
                    $pending[$id] = $routes;
                } else {
                    $surface = self::regionSurface((string) $refs[0]['id'], $index);
                    $refs = $surface === null ? $refs : [...$refs, ['role' => 'surface', 'id' => $surface]];
                    $converted[self::DESIGN_REFS_KEY] = $refs;
                    $owned[$id] = self::ownership($id, $converted, $index, $briefLabels)[0];
                }
            }

            $rows[$id] = $converted;
        }

        $placed = $owned;

        foreach ($pending as $id => $routes) {
            $joined = [];

            foreach ($rows as $other => $row) {
                $targets = array_column(array_filter((array) ($row['connections'] ?? []), 'is_array'), 'target_location_id');

                if ($other === $id) {
                    $joined = [...$joined, ...$targets];
                } elseif (in_array($id, $targets, true)) {
                    $joined[] = $other;
                }
            }

            $checked = array_values(array_unique(array_filter(
                $joined,
                static fn (mixed $other): bool => is_string($other) && $other !== $id && (isset($placed[$other])
                    || (($rows[$other]['spatial_relation'] ?? null) === LocationProfile::EXTERNAL)),
            )));
            $kept = [];

            foreach ($routes['routes'] as $route) {
                $trial = ['id' => $id, self::DESIGN_REFS_KEY => [['role' => 'route', 'id' => $route]]];
                $through = self::ownership($id, $trial, $index, $briefLabels)[0];
                $joinsAll = true;

                foreach ($checked as $other) {
                    if (self::joiningRefs($through, $placed[$other] ?? null, $index) === []) {
                        $joinsAll = false;
                    }
                }

                if ($joinsAll) {
                    $kept[$route] = $through;
                }
            }

            if (count($kept) === 1) {
                $rows[$id][self::DESIGN_REFS_KEY] = [['role' => 'route', 'id' => (string) array_key_first($kept)]];
                $owned[$id] = $kept[array_key_first($kept)];
            } elseif ($kept === []) {
                $problems[$id] = "{$id}.story_name: \"{$routes['name']}\" matches ".count($routes['routes']).' routes of the design ('
                    .implode('; ', array_map(static fn (string $route): string => trim((string) ($index['routes'][$route]['support'] ?? '')), $routes['routes']))
                    .'), but none of them joins '.implode(' and ', $checked).' as the screenplay content connects it, '
                    .'so the screenplay content does not establish which part of the vessel it is';
            } else {
                $problems[$id] = self::routesAmbiguity($id, $routes['name'], array_map('strval', array_keys($kept)), $routes['missing'], $index);
            }
        }

        $violations = [...$violations, ...array_values(array_intersect_key(array_replace(array_fill_keys(array_keys($rows), null), $problems), $problems))];

        foreach ($rows as $id => $row) {
            $connections = [];

            foreach ((array) ($row['connections'] ?? []) as $position => $connection) {
                if (! is_array($connection) || ! is_string($connection['target_location_id'] ?? null)) {
                    $connections[] = $connection;

                    continue;
                }

                $target = $connection['target_location_id'];
                $from = $owned[$id] ?? null;
                $to = $owned[$target] ?? null;
                $refs = [];
                $unresolved = static fn (string $place): bool => ($rows[$place]['spatial_relation'] ?? null) === LocationProfile::SUBJECT_PART && ! isset($owned[$place]);

                if ($unresolved($id) || (isset($rows[$target]) && $unresolved($target))) {
                    $connections[] = ['target_location_id' => $target, 'via_refs' => [], 'via' => $connection['via'] ?? null];

                    continue;
                }

                if (($from !== null || $to !== null) && isset($rows[$target])) {
                    $refs = self::joiningRefs($from, $to, $index);

                    if ($refs === []) {
                        $violations[] = "{$id}.connections[{$position}]: the design has no route or opening between {$id} and {$target}, "
                            .'so the screenplay content does not establish how one leads to the other';
                    }
                }

                $connections[] = ['target_location_id' => $target, 'via_refs' => $refs, 'via' => $connection['via'] ?? null];
            }

            $rows[$id]['connections'] = $connections;
        }

        if ($violations !== []) {
            return [null, array_values(array_unique($violations))];
        }

        return self::build(array_values($rows), $design, $characters, $briefLabels);
    }

    /**
     * @param  array<string, mixed>  $index
     * @return string|null the surface a region without a surface of its own stands on, when the design leaves one
     */
    private static function regionSurface(string $region, array $index): ?string
    {
        $regions = array_keys($index['spatial_topology']);

        foreach ($index['surfaces'] as $surface) {
            if (($surface['relation'] ?? null) === 'within' && ($surface['relative_to'] ?? null) === $region) {
                return null;
            }
        }

        $deckName = (string) ($index['spatial_topology'][$region]['deck'] ?? '');
        $deck = null;

        foreach ($index['decks'] as $id => $row) {
            if (trim((string) ($row['name'] ?? '')) === trim($deckName)) {
                $deck = (string) $id;
            }
        }

        $candidates = array_keys(array_filter(
            $index['surfaces'],
            static fn (array $surface): bool => $deck !== null && ($surface['deck'] ?? null) === $deck
                && ($surface['status'] ?? null) === 'locked'
                && ! in_array($surface['relative_to'] ?? null, $regions, true),
        ));

        $deckWords = self::nameWords($deckName);
        $words = array_diff(self::nameWords((string) ($index['spatial_topology'][$region]['region'] ?? '')), $deckWords);
        $evidenced = array_values(array_filter(
            $candidates,
            static fn (int|string $candidate): bool => array_intersect($words, array_diff(self::nameWords(str_replace('_', ' ', (string) $candidate)), $deckWords)) !== [],
        ));

        return count($evidenced) === 1 ? (string) $evidenced[0] : null;
    }

    /**
     * @param  array<string, mixed>  $index
     * @param  array<string, string>  $briefLabels
     * @return array{0: ?string, 1: list<string>} the region that is the room, and every region the design links the space to
     */
    private static function spaceRegion(string $space, array $index, array $briefLabels): array
    {
        $candidates = array_values(array_filter(
            $index['regions_of_space'][$space] ?? [],
            static fn (string $ref): bool => isset($index['spatial_topology'][$ref]),
        ));

        if (count($candidates) < 2) {
            return [$candidates[0] ?? null, $candidates];
        }

        $words = self::nameWords(($briefLabels[$space] ?? '').' '.str_replace('_', ' ', $space));
        $whole = [];
        $partial = [];

        foreach ($candidates as $candidate) {
            $own = self::nameWords(
                ((string) ($index['spatial_topology'][$candidate]['region'] ?? '')).' '.((string) ($index['spatial_topology'][$candidate]['deck'] ?? '')),
            );

            if ($words !== [] && array_diff($words, $own) === []) {
                $whole[] = (string) $candidate;
            }

            if (array_intersect($words, $own) !== []) {
                $partial[] = (string) $candidate;
            }
        }

        $found = match (true) {
            count($whole) === 1 => $whole[0],
            $whole === [] && count($partial) === 1 => $partial[0],
            default => null,
        };

        return [$found, $candidates];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $index
     * @param  array<string, string>  $briefLabels
     * @param  list<string>  $briefRegions  the region that is each space of the project brief
     * @return array{0: list<array{role: string, id: string}>, 1: ?string, 2?: ?array{name: string, routes: list<string>, missing: list<string>}}
     */
    private static function storyRefs(string $id, array $row, array $index, array $briefLabels, array $briefRegions, string $storyText): array
    {
        $space = $row[FilmBrief::COVERAGE_SPACE_KEY] ?? null;
        $label = static fn (string $regionId): string => trim((string) ($index['spatial_topology'][$regionId]['region'] ?? $regionId));

        if (is_string($space)) {
            [$region, $candidates] = self::spaceRegion($space, $index, $briefLabels);

            if ($region === null) {
                return [[], $candidates === []
                    ? "{$id}.brief_space: the design gives no region for {$space}, so the place cannot be built"
                    : "{$id}.brief_space: the design links {$space} to ".implode('; ', array_map($label, $candidates))
                        .' and does not establish which one is the room, so the place cannot be built'];
            }

            $position = 'interior_spaces.'.($index['interior'][$space]['position_index'] ?? -1);
            $basins = array_keys(array_filter(
                $index['basins'],
                static fn (array $basin): bool => in_array($position, (array) ($basin['profile_paths'] ?? []), true),
            ));

            return [[
                ['role' => 'region', 'id' => $region],
                ...array_map(static fn (int|string $basin): array => ['role' => 'basin', 'id' => (string) $basin], $basins),
            ], null];
        }

        $name = is_string($row[self::STORY_NAME_KEY] ?? null) ? trim($row[self::STORY_NAME_KEY]) : '';

        if ($name === '') {
            return [[], "{$id}.story_name: a place aboard that is no space of the project brief is named as the screenplay content names it"];
        }

        if (! str_contains($storyText, self::flat($name))) {
            return [[], "{$id}.story_name: \"{$name}\" is not written in the screenplay content"];
        }

        $words = self::nameWords($name);
        $matches = [];

        foreach ($index['spatial_topology'] as $regionId => $region) {
            if (($region['status'] ?? null) === 'locked' && $words !== []
                && array_diff($words, self::nameWords(((string) ($region['region'] ?? '')).' '.((string) ($region['deck'] ?? '')))) === []) {
                $matches[] = (string) $regionId;
            }
        }

        if ($matches === []) {
            return self::storyRoute($id, $name, $words, $row[self::ROUTE_EVIDENCE_KEY] ?? null, $index, $storyText);
        }

        return match (true) {
            count($matches) > 1 => [[], "{$id}.story_name: \"{$name}\" matches ".implode('; ', array_map($label, $matches))
                .' in the design, so the screenplay content does not establish which one it is'],
            in_array($matches[0], $briefRegions, true) => [[], "{$id}.story_name: \"{$name}\" is ".$label($matches[0]).', a space of the project brief, so the place carries its brief_space'],
            default => [[['role' => 'region', 'id' => $matches[0]]], null],
        };
    }

    /**
     * @param  list<string>  $words
     * @param  array<string, mixed>  $index
     * @return array{0: list<array{role: string, id: string}>, 1: ?string, 2?: ?array{name: string, routes: list<string>, missing: list<string>}}
     */
    private static function storyRoute(string $id, string $name, array $words, mixed $evidence, array $index, string $storyText): array
    {
        $part = array_values(array_intersect($words, self::ROUTE_PART_WORDS));

        if ($part !== []) {
            return [[], "{$id}.story_name: \"{$name}\" names a {$part[0]}, one part of a stair or walkway; the design holds no {$part[0]} "
                .'as a place of its own, so the screenplay content does not establish which one it is'];
        }

        $words = array_values(array_diff($words, self::MOVEMENT_WORDS));
        $deckName = static fn (mixed $deck): string => is_string($deck) ? (string) ($index['decks'][$deck]['name'] ?? $deck) : '';
        $routeWords = static fn (string $routeId, array $route): array => self::nameWords(implode(' ', [
            str_replace('_', ' ', (string) preg_replace('/^r_/', '', $routeId)), (string) ($route['kind'] ?? ''), (string) ($route['support'] ?? ''),
            $deckName($route['from']['deck'] ?? null), $deckName($route['to']['deck'] ?? null),
        ]));
        $endFor = static function (array $route, string $deck) use ($deckName): ?string {
            foreach (['from', 'to'] as $end) {
                $own = self::nameWords($deckName($route[$end]['deck'] ?? null));

                if ($own !== [] && array_diff(self::nameWords($deck), $own) === []) {
                    return $end;
                }
            }

            return null;
        };
        $missing = ['the decks it joins'];
        $matches = [];

        foreach ($index['routes'] as $routeId => $route) {
            if (($route['status'] ?? null) === 'locked' && $words !== [] && array_diff($words, $routeWords((string) $routeId, $route)) === []) {
                $matches[(string) $routeId] = $route;
            }
        }

        if (is_array($evidence)) {
            $quote = is_string($evidence['quote'] ?? null) ? trim($evidence['quote']) : '';

            if ($quote === '' || ! str_contains($storyText, self::flat($quote))) {
                return [[], "{$id}.route_evidence.quote: is not written word for word in the screenplay content"];
            }

            foreach (['from_deck', 'to_deck', 'side'] as $field) {
                if (is_string($evidence[$field] ?? null) && ! str_contains(self::flat($quote), self::flat($evidence[$field]))) {
                    return [[], "{$id}.route_evidence.{$field}: \"{$evidence[$field]}\" is not in the quoted words"];
                }
            }

            $ends = array_filter(['from' => $evidence['from_deck'] ?? null, 'to' => $evidence['to_deck'] ?? null], 'is_string');
            $direction = self::stairDirection($quote, $words, array_values($ends));
            $side = is_string($evidence['side'] ?? null) ? self::nameWords($evidence['side']) : [];
            $level = static fn (array $route, string $end): ?int => is_int($index['decks'][$route[$end]['deck'] ?? '']['level'] ?? null)
                ? $index['decks'][$route[$end]['deck']]['level']
                : null;
            $matches = array_filter($matches, static function (array $route, string $routeId) use ($ends, $side, $direction, $endFor, $level, $routeWords): bool {
                foreach ($ends as $role => $deck) {
                    $end = $endFor($route, $deck);

                    if ($end === null) {
                        return false;
                    }

                    $here = $level($route, $end);
                    $there = $level($route, $end === 'from' ? 'to' : 'from');

                    if ($direction !== null && $here !== null && $there !== null
                        && (($role === 'to') === ($direction === 'down') ? $here > $there : $here < $there)) {
                        return false;
                    }
                }

                return array_diff($side, $routeWords($routeId, $route)) === [];
            }, ARRAY_FILTER_USE_BOTH);
            $missing = array_values(array_filter([
                ! isset($ends['from']) ? 'the deck it starts from' : null,
                ! isset($ends['to']) ? 'the deck it arrives at' : null,
                $direction === null ? 'whether it goes up or down' : null,
            ]));
        }

        return $matches === []
            ? [[], "{$id}.story_name: \"{$name}\" matches no region or route of the design, so the screenplay content does not establish which part of the vessel it is", null]
            : [[], null, ['name' => $name, 'routes' => array_map('strval', array_keys($matches)), 'missing' => $missing]];
    }

    /**
     * @param  array<string, mixed>|null  $from  the design parts of one place, null for a place away from the subject
     * @param  array<string, mixed>|null  $to  the design parts of the other place, null for a place away from the subject
     * @param  array<string, mixed>  $index
     * @return list<string> the routes and openings of the design that join the two places
     */
    private static function joiningRefs(?array $from, ?array $to, array $index): array
    {
        $refs = [];

        foreach ([...array_keys($index['openings']), ...array_keys($index['routes'])] as $ref) {
            if (self::viaRef('', (string) $ref, $from, $to, $index)[1] === null) {
                $refs[] = (string) $ref;
            }
        }

        return $refs;
    }

    /**
     * @param  list<string>  $routes
     * @param  list<string>  $missing  the facts of a stair the screenplay content does not give
     * @param  array<string, mixed>  $index
     */
    private static function routesAmbiguity(string $id, string $name, array $routes, array $missing, array $index): string
    {
        $level = static fn (string $route): bool => ($index['routes'][$route]['from']['deck'] ?? null) === ($index['routes'][$route]['to']['deck'] ?? null);
        $level = array_filter($routes, $level) === $routes;
        $pairs = array_unique(array_map(static function (string $route) use ($index): string {
            $decks = [(string) ($index['routes'][$route]['from']['deck'] ?? ''), (string) ($index['routes'][$route]['to']['deck'] ?? '')];
            sort($decks);

            return implode('|', $decks);
        }, $routes));
        $wanting = match (true) {
            $level => 'which side or which places it joins',
            count($pairs) === 1 => 'which side it is on',
            default => $missing === [] ? 'enough to tell them apart' : implode(', ', $missing),
        };

        return "{$id}.story_name: \"{$name}\" matches ".count($routes).($level ? ' walkways' : ' routes').' of the design ('
            .implode('; ', array_map(static fn (string $route): string => trim((string) ($index['routes'][$route]['support'] ?? '')), $routes))
            ."), and the screenplay content does not give {$wanting}, so it does not establish which one it is";
    }

    /**
     * @param  list<string>  $nameWords  the words that name the stair or walkway
     * @param  list<string>  $decks  the decks the evidence names
     * @return 'up'|'down'|null the direction the quote gives the movement on this stair, or null when it gives none for certain
     */
    private static function stairDirection(string $quote, array $nameWords, array $decks): ?string
    {
        preg_match_all('/[a-z0-9]+|[^a-z0-9\s]/', (string) preg_replace("/(?<=[a-z0-9])['’-](?=[a-z0-9])/u", ' ', mb_strtolower($quote)), $found);
        $tokens = $found[0];
        $deckWords = array_merge([], ...array_map(self::nameWords(...), $decks));
        $core = array_values(array_diff($nameWords, $deckWords));
        $start = null;
        $end = null;

        for ($position = 0; $position < count($tokens) && $start === null; $position++) {
            $run = $position;

            while ($run < count($tokens) && in_array($tokens[$run], $core, true)) {
                $run++;
            }

            if ($core !== [] && array_diff($core, array_slice($tokens, $position, $run - $position)) === []) {
                $start = $position;
                $end = $run - 1;
            }
        }

        if ($start === null) {
            return null;
        }

        $before = $start - 1;

        if (in_array($tokens[$before] ?? null, ['the', 'a'], true)) {
            $before--;
        }

        $directionOf = static function (?string $token, array $sets): ?string {
            foreach ($sets as $direction => $words) {
                if (in_array($token, $words, true)) {
                    return $direction;
                }
            }

            return null;
        };
        $moves = static fn (?string $token): bool => in_array($token, self::MOVE_VERBS, true);
        $found = array_values(array_filter([
            $directionOf($tokens[$before] ?? null, self::DIRECTION_VERBS),
            $moves($tokens[$before - 1] ?? null) ? $directionOf($tokens[$before] ?? null, self::DIRECTION_WORDS) : null,
            $moves($tokens[$before] ?? null) ? $directionOf($tokens[$end + 1] ?? null, self::DIRECTION_WORDS) : null,
        ]));

        return count(array_unique($found)) === 1 ? $found[0] : null;
    }

    /** @return list<string> */
    private static function nameWords(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($text)) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            static fn (string $word): bool => $word !== '' && ! in_array($word, self::NAME_STOPWORDS, true),
        )));
    }

    private static function flat(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
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
            $refs = is_array($connection) && is_array($connection['via_refs'] ?? null)
                ? array_values(array_filter($connection['via_refs'], 'is_string'))
                : (is_string($ref) ? [$ref] : []);
            $text = is_array($connection) && is_string($connection['via'] ?? null) ? trim($connection['via']) : '';
            $other = is_string($target) ? ($places[$target] ?? null) : null;

            if ($other === null) {
                $violations[] = "{$where}: target ".(is_string($target) ? $target : 'the value').' is not a declared location';

                continue;
            }

            $aboard = $place['owned'] !== null && $other['owned'] !== null;
            $crossing = ($place['owned'] !== null) !== ($other['owned'] !== null);

            if ($aboard && $refs === []) {
                $violations[] = "{$where}: two places aboard connect through a route or opening of the design, named in via_ref";

                continue;
            }

            if ($crossing && $refs === []) {
                $violations[] = "{$where}: ".self::ENDPOINT_UNRESOLVED.': a place aboard reaches a place away from the subject only through '
                    .'an opening of the design that leads outside, named in via_ref; moving between them in the story is not a connection';

                continue;
            }

            if (! $aboard && ! $crossing && ($ref !== null || $refs !== [])) {
                $violations[] = "{$where}.via_ref: two places away from the subject pass through no part of the design, so it is null";

                continue;
            }

            if (! $aboard && $text === '') {
                $violations[] = "{$where}: a connection with a place away from the subject says in via how it is made";

                continue;
            }

            $refText = null;
            $phrases = [];
            $problem = null;

            foreach ($refs as $one) {
                [$phrase, $problem] = self::viaRef($where, $one, $place['owned'], $other['owned'], $index);

                if ($problem !== null) {
                    break;
                }

                $phrases[] = (string) $phrase;
            }

            if ($problem !== null) {
                $violations[] = $problem;

                continue;
            }

            if ($phrases !== []) {
                $refText = implode(' or ', array_values(array_unique($phrases)));
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
