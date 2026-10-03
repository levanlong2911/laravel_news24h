<?php

declare(strict_types=1);

namespace App\Video\Screenplay;

final class ProtagonistProfile
{
    public const PROFILE_FLAG = 'protagonist_profile';

    public const OUTPUT_KEY = 'protagonist_profile';

    public const CHARACTER_KEY = 'profile';

    public const FOCUS_KEY = 'protagonist_profile_focus';

    public const CAST_FLAG = 'cast';

    public const PROTAGONIST_ONLY = 'protagonist_only';

    /** @var list<string> */
    public const SECTIONS = [
        'size_and_dimensions',
        'form_and_proportions',
        'spatial_layout',
        'deck_organization',
        'materials_and_finishes',
        'amenities',
        'windows_and_glazing',
        'wellness_and_relaxation',
        'transformable_spaces',
        'capacity',
        'construction_new_build_and_refit',
    ];

    /** @var list<string> */
    public const IMAGE_SECTIONS = [
        'size_and_dimensions',
        'form_and_proportions',
        'spatial_layout',
        'deck_organization',
        'materials_and_finishes',
        'amenities',
        'windows_and_glazing',
        'wellness_and_relaxation',
        'capacity',
        'construction_new_build_and_refit',
    ];

    /** @var list<string> */
    public const FEATURE_FIELDS = [
        'name',
        'location',
        'visual_difference',
        'fixed_and_moving_parts',
        'standard_state',
        'standard_geometry',
        'other_states',
        'motion_and_clearance',
        'access_and_furniture',
        'verification',
    ];

    /** @var list<string> */
    public const FEATURE_TAGS = ['origin', 'kind', 'region'];

    /** @var list<string> */
    public const FEATURE_ORIGINS = ['inherited', 'proposed'];

    /** @var list<string> */
    public const FEATURE_KINDS = ['fixed', 'transforming'];

    /** @var list<string> */
    public const REGIONS = [
        'bow',
        'forward',
        'midship',
        'aft',
        'stern',
        'port',
        'starboard',
        'port_and_starboard',
        'roof',
        'whole_vessel',
    ];

    /** @var list<string> */
    public const RENDERED_FEATURE_FIELDS = [
        'name',
        'region',
        'location',
        'visual_difference',
        'fixed_and_moving_parts',
        'standard_state',
        'standard_geometry',
    ];

    public const INTERIOR_KEY = 'interior_spaces';

    /** @var array<string, array{0: int, 1: int}> */
    public const INTERIOR_FIELDS = [
        'deck' => [3, 120],
        'position' => [40, 600],
        'layout' => [40, 1500],
        'access' => [20, 600],
        'materials_and_light' => [40, 1200],
        'fixed_furniture' => [20, 800],
        'identity' => [20, 600],
        'undetermined' => [0, 600],
    ];

    /** @var list<string> */
    public const FIGURE_FIELDS = ['quantity', 'value', 'unit', 'origin', 'source_path', 'note'];

    /** @var array<string, string> */
    public const QUANTITIES = [
        'length_overall' => 'm',
        'beam' => 'm',
        'draft' => 'm',
        'air_draft' => 'm',
        'deck_count' => 'decks',
        'guest_count' => 'guests',
        'guest_cabin_count' => 'cabins',
        'crew_count' => 'crew',
        'tender_count' => 'tenders',
        'max_speed' => 'kn',
        'cruising_speed' => 'kn',
        'range' => 'nm',
        'gross_tonnage' => 'GT',
        'displacement' => 't',
    ];

    /** @var array<string, int> */
    public const COUNT_MINIMUMS = [
        'deck_count' => 1,
        'guest_count' => 1,
        'guest_cabin_count' => 1,
        'crew_count' => 1,
        'tender_count' => 0,
    ];

    /** @var array<string, string> */
    public const INHERITED = [
        'principal_dimensions.length_m' => 'length_overall',
        'principal_dimensions.beam_m' => 'beam',
    ];

    /** @var list<string> */
    public const ORIGINS = ['inherited', 'proposed', 'undetermined'];

    public const MIN_FEATURES = 3;

    public const MAX_FEATURES = 5;

    public const SECTION_MIN = 40;

    public const SECTION_MAX = 2000;

    public const FEATURE_FIELD_MAX = 2000;

    public const NOTE_MAX = 200;

    /** @var list<string> */
    public const NOVELTY_CLAIMS = [
        '/\bworld\'?s\s+first\b/iu',
        '/\bfirst\s+of\s+its\s+kind\b/iu',
        '/\bthe\s+first\s+(?:super)?yachts?\b/iu',
        '/\bfirst\s+(?:super)?yachts?\s+(?:ever|to)\b/iu',
        '/\bindustry[\s\-]+first\b/iu',
        '/\bnever\s+(?:been\s+)?(?:seen|done|built|tried|attempted)\s+before\b/iu',
        '/\bnever\s+before\s+(?:seen|built|done|tried|attempted)\b/iu',
        '/\bno\s+other\s+(?:super)?yachts?\b/iu',
        '/\bunlike\s+any\s+(?:other\s+)?(?:super)?yachts?\b/iu',
        '/\bunprecedented\b/iu',
        '/\bone[\s\-]+of[\s\-]+a[\s\-]+kind\b/iu',
        '/\bthe\s+only\s+(?:super)?yachts?\b/iu',
    ];

    /**
     * @param  array<string, mixed>  $profile
     */
    public static function enabled(array $profile): bool
    {
        return ($profile[self::PROFILE_FLAG] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    public static function protagonistOnly(array $profile): bool
    {
        return ($profile[self::CAST_FLAG] ?? null) === self::PROTAGONIST_ONLY;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    public static function focus(array $profile): array
    {
        $focus = $profile[self::FOCUS_KEY] ?? [];

        return is_array($focus)
            ? array_values(array_unique(array_filter($focus, static fn (mixed $region): bool => in_array($region, self::REGIONS, true))))
            : [];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public static function forImage(array $profile): array
    {
        $image = [];

        foreach (self::IMAGE_SECTIONS as $section) {
            $image[$section] = $profile[$section] ?? null;
        }

        $image['figures'] = array_values(array_filter(
            (array) ($profile['figures'] ?? []),
            static fn (mixed $figure): bool => is_array($figure) && ($figure['origin'] ?? null) !== 'undetermined',
        ));

        $image['signature_features'] = array_map(
            static fn (mixed $feature): array => array_intersect_key(
                is_array($feature) ? $feature : [],
                array_flip(self::RENDERED_FEATURE_FIELDS),
            ),
            array_values((array) ($profile['signature_features'] ?? [])),
        );

        return $image;
    }

    /**
     * @param  list<mixed>  $characters
     */
    public static function receiverIndex(array $characters): ?int
    {
        $found = [];

        foreach ($characters as $index => $character) {
            if (is_array($character)
                && ($character['role'] ?? null) === 'protagonist'
                && ($character['kind'] ?? null) === 'object') {
                $found[] = $index;
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }
}
