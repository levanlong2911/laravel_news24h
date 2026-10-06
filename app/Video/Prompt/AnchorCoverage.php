<?php

declare(strict_types=1);

namespace App\Video\Prompt;

use App\Video\Screenplay\VesselDesign;

final class AnchorCoverage
{
    public const VERSION = 'anchor-coverage-v4';

    public const MIN_QUOTE_LENGTH = 20;

    /** @var list<string> */
    private const RESERVED_WORDS = ['hull', 'waterline'];

    /** @var list<string> */
    private const CANONICAL_SECTIONS = [
        'hull_geometry', 'bow_geometry', 'superstructure_geometry', 'stern_geometry', 'primary_masses',
        'primary_voids', 'signature_regions', 'spatial_topology', 'geometric_relationships', 'transitions',
        'permanent_secondary_geometry', 'must_preserve', 'must_not_introduce',
    ];

    /** @var list<string> */
    private const SKIPPED_KEYS = [
        'id', 'feature_id', 'component_id', 'status', 'priority', 'refs', 'proof_views', 'proof_conditions',
        'subject', 'object', 'between', 'kind', VesselDesign::EXTERIOR_ROLE_KEY, VesselDesign::ANCHOR_ROLE_KEY, VesselDesign::SECONDARY_FORM_KEY,
        VesselDesign::EQUIPMENT_KIND_KEY,
    ];

    /** @var list<string> */
    private const BASIN_TERMS = ['pool', 'pools', 'basin', 'basins', 'tank'];

    /** @var list<string> */
    private const HULL_TERMS = ['hull'];

    /** @var array<string, array<string, list<string>>> */
    private const TERMS = [
        'face' => [
            'forward' => ['forward', 'bow', 'front', 'fore'], 'aft' => ['aft', 'stern', 'rear'],
            'port' => ['port'], 'starboard' => ['starboard'],
            'upward' => ['roof', 'upward', 'overhead', 'top', 'skylight', 'skylights'],
            'downward' => ['floor', 'downward', 'underside', 'below'],
        ],
        'opening_kind' => [
            'glazing' => ['glazing', 'glazed', 'glass', 'window', 'windows'],
            'doorway' => ['door', 'doors', 'doorway', 'doorways'], 'hatch' => ['hatch', 'hatches'],
            'open_void' => ['open', 'opening', 'openings'],
            'movable_closure' => ['sliding', 'slide', 'slides', 'folding', 'fold', 'folds', 'gate', 'gates', 'hinged', 'movable', 'retractable'],
        ],
        'route_kind' => [
            'stair' => ['stair', 'stairs', 'staircase', 'flight', 'flights', 'steps'], 'ramp' => ['ramp', 'ramps'],
            'lift' => ['lift', 'lifts', 'elevator'], 'walkway' => ['walkway', 'walkways', 'gangway', 'passage', 'path'],
        ],
        'direction' => [
            'forward' => ['forward', 'toward the bow', 'towards the bow'], 'aft' => ['aft', 'toward the stern', 'towards the stern'],
            'port' => ['port'], 'starboard' => ['starboard'], 'inboard' => ['inboard'], 'outboard' => ['outboard'],
            'vertical' => ['vertical', 'vertically', 'straight up', 'rising', 'rises', 'climb', 'climbs', 'ladder', 'up through'],
        ],
        'orientation' => [
            'fore_and_aft' => ['fore-and-aft', 'fore and aft', 'longitudinal', 'longitudinally', 'lengthwise'],
            'athwartships' => ['athwartships', 'athwart', 'across', 'transverse', 'crosswise'],
            'square_or_round' => ['square', 'round', 'circular'],
        ],
        'water_level' => [
            'below_surface' => ['recessed', 'recess', 'sunken', 'below', 'set into', 'inset'],
            'flush_with_surface' => ['flush', 'level with'], 'raised_above_surface' => ['raised', 'above'],
        ],
        'surface_kind' => [
            'deck_floor' => ['floor', 'floors'], 'open_deck' => ['open deck', 'open decks'], 'terrace' => ['terrace', 'terraces'],
            'landing' => ['landing', 'landings'], 'walkway' => ['walkway', 'passage', 'path'], 'platform' => ['platform', 'platforms'],
        ],
        'side' => ['port' => ['port'], 'starboard' => ['starboard']],
        'relation' => [
            'forward_of' => ['forward of', 'ahead of', 'in front of'], 'aft_of' => ['aft of', 'behind'],
            'above' => ['above', 'over', 'on top of'], 'below' => ['below', 'under', 'beneath', 'underneath'],
            'beside' => ['beside', 'next to', 'alongside'], 'within' => ['within', 'inside', 'in'],
            'around' => ['around', 'surrounding', 'encircling'],
        ],
    ];

    public static function schema(): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['prompt', 'coverage', 'conflicts', 'alignment'],
            'properties' => [
                'prompt' => ['type' => 'string'],
                'coverage' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['source_path', 'quote'],
                    'properties' => ['source_path' => ['type' => 'string'], 'quote' => ['type' => 'string']],
                ]],
                'conflicts' => ['type' => 'array', 'items' => ['type' => 'string']],
                'alignment' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['profile_path', 'canonical_evidence'],
                    'properties' => [
                        'profile_path' => ['type' => 'string'],
                        'canonical_evidence' => ['type' => 'array', 'items' => [
                            'type' => 'object', 'additionalProperties' => false,
                            'required' => ['source_path', 'quote'],
                            'properties' => ['source_path' => ['type' => 'string'], 'quote' => ['type' => 'string']],
                        ]],
                    ],
                ]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, array{fact: string, quote_min_length: int, quote_must_state: list<list<string>>}>
     */
    public static function requirements(array $source): array
    {
        $canonical = $source['design']['canonical_design'] ?? [];
        $declare = static fn (string $fact, array $terms = []): array => [
            'fact' => $fact, 'quote_min_length' => self::MIN_QUOTE_LENGTH, 'quote_must_state' => $terms,
        ];
        $requirements = [];

        foreach (self::CANONICAL_SECTIONS as $section) {
            $node = $canonical[$section] ?? [];

            if (is_array($node) && array_is_list($node)) {
                $node = array_map(static fn (mixed $row): mixed => self::isSupport($row) ? [] : $row, $node);
            }

            $requirements += array_map($declare, VesselDesign::textPaths($node, 'design.canonical_design.'.$section, self::SKIPPED_KEYS));
        }

        foreach (self::typedRequirements($canonical) as $path => [$text, $terms]) {
            $requirements[$path] = $declare($text, $terms);
        }

        $requirements += array_map($declare, VesselDesign::textPaths($source['participant']['profile'] ?? [], 'participant.profile', self::SKIPPED_KEYS));

        return array_filter($requirements, static fn (array $row): bool => trim($row['fact']) !== '');
    }

    private static function isSupport(mixed $row): bool
    {
        return is_array($row) && ($row[VesselDesign::ANCHOR_ROLE_KEY] ?? null) === VesselDesign::ANCHOR_SUPPORT;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<string>
     */
    public static function internalIds(string $prompt, array $source): array
    {
        $declared = [];

        array_walk_recursive($source, static function (mixed $value, mixed $key) use (&$declared): void {
            if (in_array($key, ['id', 'feature_id', 'component_id'], true) && is_string($value) && preg_match('/[_\d]/', $value) === 1
                && ! in_array(mb_strtolower($value), self::RESERVED_WORDS, true)) {
                $declared[mb_strtolower($value)] = true;
            }
        });

        preg_match_all('/[\p{L}\p{N}_]+/u', mb_strtolower($prompt), $tokens);
        $named = array_values(array_unique(array_filter($tokens[0], static fn (string $token): bool => isset($declared[$token]))));
        sort($named);

        return $named;
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @return array<string, array{0: string, 1: list<list<string>>}>
     */
    private static function typedRequirements(array $canonical): array
    {
        $rows = static fn (string $list): array => is_array($canonical[$list] ?? null) && array_is_list($canonical[$list])
            ? array_values(array_filter($canonical[$list], 'is_array'))
            : [];
        $deckNames = [];

        foreach ($rows('decks') as $deck) {
            if (is_string($deck['id'] ?? null)) {
                $deckNames[$deck['id']] = is_string($deck['name'] ?? null) && trim($deck['name']) !== '' ? $deck['name'] : $deck['id'];
            }
        }

        $surfaces = [];

        foreach ($rows('surfaces') as $surface) {
            if (is_string($surface['id'] ?? null)) {
                $surfaces[$surface['id']] = $surface;
            }
        }

        $surfaceDecks = array_map(static fn (array $surface): mixed => $surface['deck'] ?? null, $surfaces);

        $deck = static fn (mixed $id): array => [[mb_strtolower((string) ($deckNames[$id] ?? $id))]];
        $term = static fn (string $group, mixed $value): array => [self::TERMS[$group][$value] ?? [mb_strtolower((string) $value)]];
        $requirements = [];
        $add = static function (string $path, string $text, array $terms) use (&$requirements): void {
            $requirements['design.canonical_design.'.$path] = [$text, $terms];
        };

        $v = static fn (mixed $value): string => is_scalar($value) ? str_replace('_', ' ', (string) $value) : '';
        $name = static fn (mixed $id): string => is_string($id) ? (string) ($deckNames[$id] ?? $id) : '';
        $roles = [];
        $secondaries = [];

        foreach ($rows('permanent_secondary_geometry') as $row) {
            if (is_string($row['id'] ?? null) && is_string($row['element'] ?? null) && trim($row['element']) !== '') {
                $secondaries[$row['id']] = $row['element'];
            }
        }

        foreach (['primary_masses', 'primary_voids'] as $list) {
            foreach ($rows($list) as $row) {
                if (is_string($row['id'] ?? null) && is_string($row['role'] ?? null)) {
                    $roles[$row['id']] = $row['role'];
                }
            }
        }

        $place = static function (mixed $id) use ($canonical, $surfaces, $roles, $term, $v): array {
            $placement = is_string($id) ? VesselDesign::surfacePlacement($canonical, $id) : null;

            if ($placement === null) {
                return [$v($id), [[mb_strtolower($v($id))]]];
            }

            $terms = [[$placement['deck']], ...$term('surface_kind', $placement['kind'])];
            $text = 'the '.$v($placement['kind'])." on {$placement['deck']}";

            if (($placement['side'] ?? null) !== null) {
                $terms = [...$terms, ...(isset(self::TERMS['side'][$placement['side']]) ? $term('side', $placement['side']) : [])];
                $text .= $placement['side'] === 'both' ? ', across both sides' : ', on the '.$v($placement['side']).($placement['side'] === 'centerline' ? '' : ' side');
            }

            if ($placement['relation'] !== null) {
                $terms = [...$terms, ...$term('relation', $placement['relation'])];
                $text .= ' '.$v($placement['relation']);
            }

            if (is_array($placement['reference']) && in_array($placement['reference']['type'] ?? null, ['region', 'secondary'], true)) {
                $terms[] = [mb_strtolower($placement['reference']['name'])];
                $text .= ' the '.$placement['reference']['name'];
            } elseif (is_array($placement['reference']) && ($placement['reference']['type'] ?? null) === 'basin') {
                $terms = [...$terms, self::BASIN_TERMS, [$placement['reference']['deck']], ...$term('orientation', $placement['reference']['orientation'])];
                $text .= ' the '.$v($placement['reference']['orientation']).' basin on '.$placement['reference']['deck'];
            } elseif (is_array($placement['reference'])) {
                $terms = [...$terms, [$placement['reference']['deck']], ...$term('surface_kind', $placement['reference']['kind'])];
                $text .= ' the '.$v($placement['reference']['kind'])." on {$placement['reference']['deck']}";

                if (isset($placement['reference']['side'])) {
                    $terms = [...$terms, ...(isset(self::TERMS['side'][$placement['reference']['side']]) ? $term('side', $placement['reference']['side']) : [])];
                    $text .= $placement['reference']['side'] === 'both' ? ' across both sides' : ' on the '.$v($placement['reference']['side']).($placement['reference']['side'] === 'centerline' ? '' : ' side');
                }
            } elseif ($placement['reference'] === 'hull') {
                $terms = [...$terms, self::HULL_TERMS];
                $text .= ' the hull';
            } elseif ($placement['reference'] === 'unnamed') {
                $text .= ' the '.($roles[$surfaces[$id]['relative_to'] ?? ''] ?? 'referenced volume');
            }

            return [$text, $terms];
        };

        foreach ($rows('decks') as $index => $row) {
            $add("decks.{$index}", 'the deck named '.$name($row['id'] ?? null), $deck($row['id'] ?? null));
        }

        foreach ($rows('openings') as $index => $row) {
            if (self::isSupport($row)) {
                continue;
            }

            $element = is_string($row['host'] ?? null) ? ($secondaries[$row['host']] ?? null) : null;
            $fact = 'a '.$v($row['kind'] ?? null).' opening in the '.$v($row['face'] ?? null).' face of '
                .($element === null ? '' : "the {$element} on ").$name($row['deck'] ?? null);
            $add("openings.{$index}.deck", $fact, $deck($row['deck'] ?? null));
            $add("openings.{$index}.face", $fact, $term('face', $row['face'] ?? null));
            $add("openings.{$index}.kind", $fact, $term('opening_kind', $row['kind'] ?? null));

            if ($element !== null) {
                $add("openings.{$index}.host", $fact, [[mb_strtolower($element)]]);
            }
        }

        foreach ($rows('surfaces') as $index => $row) {
            if (self::isSupport($row)) {
                continue;
            }

            [$text, $terms] = $place($row['id'] ?? null);
            $add("surfaces.{$index}", $text, $terms);
        }

        foreach ($rows('basins') as $index => $row) {
            if (self::isSupport($row)) {
                continue;
            }

            $surface = is_string($row['surface'] ?? null) ? $row['surface'] : '';
            $fact = 'a basin set into '.$place($surface)[0].', running '.$v($row['orientation'] ?? null)
                .', its water '.$v($row['water_level'] ?? null);
            $add("basins.{$index}.surface", $fact, [...$deck($surfaceDecks[$surface] ?? null), self::BASIN_TERMS]);
            $add("basins.{$index}.orientation", $fact, $term('orientation', $row['orientation'] ?? null));
            $add("basins.{$index}.water_level", $fact, $term('water_level', $row['water_level'] ?? null));
        }

        foreach ($rows('routes') as $index => $row) {
            if (self::isSupport($row)) {
                continue;
            }

            $ends = [];

            foreach (['from', 'to'] as $end) {
                $target = $row[$end]['place'] ?? null;
                $ends[$end] = $name($row[$end]['deck'] ?? null).(is_string($target) ? ' at '.$place($target)[0] : '');
            }

            $fact = 'a '.$v($row['kind'] ?? null).' from '.$ends['from'].' to '.$ends['to'].', running '.$v($row['direction'] ?? null);
            $add("routes.{$index}.kind", $fact, $term('route_kind', $row['kind'] ?? null));
            $add("routes.{$index}.from", $fact, $deck($row['from']['deck'] ?? null));
            $add("routes.{$index}.to", $fact, $deck($row['to']['deck'] ?? null));

            foreach (['from', 'to'] as $end) {
                $target = $row[$end]['place'] ?? null;

                if (is_string($target)) {
                    $add("routes.{$index}.{$end}.place", $fact, $place($target)[1]);
                }
            }
            $add("routes.{$index}.direction", $fact, $term('direction', $row['direction'] ?? null));
        }

        return $requirements;
    }

    public static function violations(array $stored, array $source): array
    {
        if (($stored['coverage_version'] ?? null) !== self::VERSION) {
            return ['coverage: missing or outdated contract'];
        }
        $prompt = $stored['prompt'] ?? null;
        $coverage = $stored['coverage'] ?? null;
        $conflicts = $stored['conflicts'] ?? null;
        if (! is_string($prompt) || trim($prompt) === '' || ! is_array($coverage) || ! array_is_list($coverage)
            || ! is_array($conflicts) || ! array_is_list($conflicts)) {
            return ['coverage: invalid response shape'];
        }
        $errors = $conflicts === [] ? [] : ['coverage: unresolved source conflicts'];

        foreach (VesselDesign::indistinctSurfaces($source['design']['canonical_design'] ?? []) as [$first, $second]) {
            $errors[] = "coverage: {$first} and {$second} cannot be told apart in words; a locating fact is missing";
        }

        foreach (self::internalIds($prompt, $source) as $id) {
            $errors[] = "coverage: prompt writes the internal id {$id} instead of naming the part";
        }

        $required = self::requirements($source);
        $seen = [];
        foreach ($coverage as $row) {
            $path = is_array($row) ? ($row['source_path'] ?? null) : null;
            $quote = is_array($row) ? ($row['quote'] ?? null) : null;
            if (! is_string($path) || ! array_key_exists($path, $required)) {
                $errors[] = 'coverage: unknown source path';
                continue;
            }
            if (isset($seen[$path])) {
                $errors[] = "coverage: duplicate {$path}";
            }
            if (! is_string($quote) || mb_strlen(trim($quote)) < $required[$path]['quote_min_length'] || ! str_contains($prompt, $quote)) {
                $errors[] = "coverage: missing exact prompt evidence for {$path}";
            } else {
                foreach ($required[$path]['quote_must_state'] as $group) {
                    $said = array_filter($group, static fn (string $word): bool => self::states($quote, $word));

                    if ($said === []) {
                        $errors[] = "coverage: evidence for {$path} does not state ".implode(' / ', $group);
                    }
                }
            }
            $seen[$path] = true;
        }
        foreach (array_diff_key($required, $seen) as $path => $_) {
            $errors[] = "coverage: missing {$path}";
        }
        return array_values(array_unique(array_merge($errors, self::alignmentViolations($stored, $required))));
    }

    private static function states(string $quote, string $term): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/iu', $quote) === 1;
    }

    private static function alignmentViolations(array $stored, array $required): array
    {
        $profiles = array_filter($required, static fn ($path) => str_starts_with($path, 'participant.profile.'), ARRAY_FILTER_USE_KEY);
        $rows = $stored['alignment'] ?? [];
        if (! is_array($rows) || ! array_is_list($rows)) {
            return ['alignment: invalid response shape'];
        }
        $seen = [];
        $errors = [];
        foreach ($rows as $row) {
            $path = is_array($row) ? ($row['profile_path'] ?? null) : null;
            if (! is_string($path) || ! isset($profiles[$path]) || isset($seen[$path])) {
                $errors[] = 'alignment: unknown or duplicate profile path';
                continue;
            }
            $seen[$path] = true;
            $evidence = $row['canonical_evidence'] ?? null;
            if (! is_array($evidence) || ! array_is_list($evidence) || $evidence === []) {
                $errors[] = "alignment: no canonical evidence for {$path}";
                continue;
            }
            foreach ($evidence as $item) {
                $target = is_array($item) ? ($item['source_path'] ?? null) : null;
                $quote = is_array($item) ? ($item['quote'] ?? null) : null;
                if (! is_string($target) || ! str_starts_with($target, 'design.canonical_design.')
                    || ! isset($required[$target]) || ! is_string($quote) || trim($quote) === ''
                    || ! str_contains($required[$target]['fact'], $quote)) {
                    $errors[] = "alignment: invalid canonical evidence for {$path}";
                }
            }
        }
        foreach (array_diff_key($profiles, $seen) as $path => $_) {
            $errors[] = "alignment: missing {$path}";
        }
        return $errors;
    }
}
