<?php

declare(strict_types=1);

namespace App\Video\Screenplay;

final class FilmBrief
{
    public const PROFILE_KEY = 'film_brief';

    public const REQUIREMENT_KEY = 'film_brief';

    public const FORMAT = 'film_brief_v1';

    public const PROFILE_SPACES_KEY = 'film_brief_spaces';

    public const COVERAGE_PREFIX = 'cov_space_';

    public const COVERAGE_SPACE_KEY = 'brief_space';

    public const COVERAGE_OFF_SCREEN_KEY = 'off_screen';

    public const SPACE_KEY_PATTERN = '/^[a-z][a-z0-9_]{1,29}$/D';

    public const MAX_SPACES = 12;

    public const MAX_ITEMS = 8;

    /** @var array{0: int, 1: int} */
    public const LABEL_LIMITS = [2, 80];

    /** @var array{0: int, 1: int} */
    public const NOTE_LIMITS = [0, 300];

    /** @var array{0: int, 1: int} */
    public const ITEM_LIMITS = [3, 300];

    /** @var list<string> */
    public const LIST_FIELDS = ['highlights', 'storytelling', 'limits'];

    /** @var list<string> */
    public const FIELDS = ['spaces', 'highlights', 'off_screen', 'storytelling', 'limits'];

    /**
     * @param  array<string, mixed>  $profile
     * @return array{0: ?array<string, mixed>, 1: list<string>}
     */
    public static function ofProfile(array $profile): array
    {
        $brief = $profile[self::PROFILE_KEY] ?? null;

        if ($brief === null) {
            return [null, []];
        }

        if (! is_array($brief) || array_is_list($brief)) {
            return [null, ['film_brief: must be an object']];
        }

        $violations = [];

        if (($brief['format'] ?? null) !== self::FORMAT) {
            $violations[] = 'film_brief.format: must be '.self::FORMAT;
        }

        if (! is_int($brief['revision'] ?? null) || $brief['revision'] < 1) {
            $violations[] = 'film_brief.revision: must be a positive integer';
        }

        $content = array_diff_key($brief, array_flip(['format', 'revision']));
        $violations = [...$violations, ...self::violations($content)];

        return $violations === []
            ? [['format' => self::FORMAT, 'revision' => $brief['revision']] + self::normalize($content), []]
            : [null, $violations];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return list<string>
     */
    public static function violations(array $content): array
    {
        $violations = [];

        foreach (array_diff(array_keys($content), self::FIELDS) as $extra) {
            $violations[] = "film_brief.{$extra}: is not part of the brief";
        }

        $spaces = $content['spaces'] ?? null;

        if (! is_array($spaces) || ! array_is_list($spaces) || $spaces === []) {
            $violations[] = 'film_brief.spaces: must be a nonempty list';
            $spaces = [];
        } elseif (count($spaces) > self::MAX_SPACES) {
            $violations[] = 'film_brief.spaces: holds at most '.self::MAX_SPACES.' spaces';
        }

        $keys = [];

        foreach ($spaces as $index => $space) {
            $where = "film_brief.spaces[{$index}]";

            if (! is_array($space)) {
                $violations[] = "{$where}: must be an object";

                continue;
            }

            foreach (array_diff(array_keys($space), ['key', 'label', 'note']) as $extra) {
                $violations[] = "{$where}.{$extra}: is not part of a space";
            }

            $key = $space['key'] ?? null;

            if (! is_string($key) || preg_match(self::SPACE_KEY_PATTERN, $key) !== 1) {
                $violations[] = "{$where}.key: lowercase letters, digits and underscores, starting with a letter, 2-30 characters";
            } elseif (in_array($key, $keys, true)) {
                $violations[] = "{$where}.key: {$key} is already listed";
            } else {
                $keys[] = $key;
            }

            $violations = array_merge(
                $violations,
                self::textViolations("{$where}.label", $space['label'] ?? null, self::LABEL_LIMITS),
                array_key_exists('note', $space) ? self::textViolations("{$where}.note", $space['note'], self::NOTE_LIMITS) : [],
            );
        }

        foreach (self::LIST_FIELDS as $field) {
            $violations = array_merge($violations, self::listViolations("film_brief.{$field}", $content[$field] ?? []));
        }

        $offScreen = $content['off_screen'] ?? [];

        if (! is_array($offScreen) || ! array_is_list($offScreen)) {
            return [...$violations, 'film_brief.off_screen: must be a list'];
        }

        if (count($offScreen) > self::MAX_ITEMS) {
            $violations[] = 'film_brief.off_screen: holds at most '.self::MAX_ITEMS.' items';
        }

        foreach ($offScreen as $index => $item) {
            $where = "film_brief.off_screen[{$index}]";

            if (! is_array($item)) {
                $violations[] = "{$where}: must be an object";

                continue;
            }

            foreach (array_diff(array_keys($item), ['text', 'coverage_ids']) as $extra) {
                $violations[] = "{$where}.{$extra}: is not part of an off-screen item";
            }

            $violations = array_merge($violations, self::textViolations("{$where}.text", $item['text'] ?? null, self::ITEM_LIMITS));
            $ids = $item['coverage_ids'] ?? [];

            if (! is_array($ids) || ! array_is_list($ids)) {
                $violations[] = "{$where}.coverage_ids: must be a list";

                continue;
            }

            foreach ($ids as $position => $id) {
                if (! is_string($id) || preg_match('/^cov_[a-z0-9_]{3,40}$/D', $id) !== 1) {
                    $violations[] = "{$where}.coverage_ids[{$position}]: must be a coverage id";
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $requirements
     * @return array<string, mixed>|null
     */
    public static function ofRequirements(array $requirements): ?array
    {
        $brief = $requirements[self::REQUIREMENT_KEY] ?? null;

        return is_array($brief) && ($brief['format'] ?? null) === self::FORMAT ? $brief : null;
    }

    /**
     * @param  array<string, mixed>|null  $brief
     * @return list<string>
     */
    public static function spaceKeys(?array $brief): array
    {
        return $brief === null ? [] : array_values(array_map(
            static fn (array $space): string => (string) $space['key'],
            $brief['spaces'] ?? [],
        ));
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    public static function profileSpaces(array $profile): array
    {
        $keys = $profile[self::PROFILE_SPACES_KEY] ?? [];

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>|null  $brief
     * @return array<string, mixed>
     */
    public static function spaceField(array $field, ?array $brief): array
    {
        $keys = self::spaceKeys($brief);

        if ($keys === []) {
            return $field;
        }

        unset($field['pattern']);
        $field['enum'] = in_array('null', (array) ($field['type'] ?? []), true) ? [...$keys, null] : $keys;

        return $field;
    }

    public static function coverageId(string $key): string
    {
        return self::COVERAGE_PREFIX.$key;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>|null  $brief
     * @return array<string, mixed>
     */
    public static function applyToProfile(array $profile, ?array $brief): array
    {
        unset($profile[self::PROFILE_KEY]);

        if ($brief === null) {
            return $profile;
        }

        $profile[self::PROFILE_SPACES_KEY] = self::spaceKeys($brief);

        if (is_int($profile['max_locations'] ?? null)) {
            $profile['max_locations'] += count($profile[self::PROFILE_SPACES_KEY]);
        }

        if (! is_array($profile['coverage'] ?? null)) {
            return $profile;
        }

        $offScreen = self::offScreenCoverage($brief);

        foreach ($profile['coverage'] as $index => $item) {
            if (is_array($item) && in_array($item['id'] ?? null, $offScreen, true)) {
                $profile['coverage'][$index][self::COVERAGE_OFF_SCREEN_KEY] = true;
            }
        }

        $stages = is_array($profile['arc_stages'] ?? null) ? $profile['arc_stages'] : [];
        $stage = $stages === [] ? null : $stages[array_key_last($stages)];

        foreach ($brief['spaces'] as $space) {
            $profile['coverage'][] = [
                'id' => self::coverageId((string) $space['key']),
                'stage' => $stage,
                'level' => 'required',
                'label' => trim((string) $space['label']).': the space is seen in use, with its layout readable.',
                self::COVERAGE_SPACE_KEY => (string) $space['key'],
            ];
        }

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $brief
     * @return list<string>
     */
    public static function profileViolations(array $profile, array $brief): array
    {
        $coverage = [];

        foreach (is_array($profile['coverage'] ?? null) ? $profile['coverage'] : [] as $item) {
            if (is_array($item) && is_string($item['id'] ?? null)) {
                $coverage[$item['id']] = $item['level'] ?? null;
            }
        }

        $violations = [];

        foreach (self::offScreenCoverage($brief) as $id) {
            if (! array_key_exists($id, $coverage)) {
                $violations[] = "film_brief.off_screen: {$id} is not a coverage item of this profile";
            } elseif ($coverage[$id] === 'required') {
                $violations[] = "film_brief.off_screen: {$id} is required by the profile and cannot be left off screen";
            }
        }

        foreach (self::spaceKeys($brief) as $key) {
            if (array_key_exists(self::coverageId($key), $coverage)) {
                $violations[] = 'film_brief.spaces: '.self::coverageId($key).' already exists in the profile';
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $brief
     * @return list<string>
     */
    public static function offScreenCoverage(array $brief): array
    {
        $ids = [];

        foreach ($brief['off_screen'] ?? [] as $item) {
            foreach ($item['coverage_ids'] ?? [] as $id) {
                $ids[] = (string) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private static function normalize(array $content): array
    {
        $trim = static fn (mixed $text): string => trim((string) $text);

        return [
            'spaces' => array_values(array_map(static fn (array $space): array => [
                'key' => (string) $space['key'],
                'label' => $trim($space['label']),
                'note' => $trim($space['note'] ?? ''),
            ], $content['spaces'] ?? [])),
            'highlights' => array_values(array_map($trim, $content['highlights'] ?? [])),
            'off_screen' => array_values(array_map(static fn (array $item): array => [
                'text' => $trim($item['text']),
                'coverage_ids' => array_values(array_map('strval', $item['coverage_ids'] ?? [])),
            ], $content['off_screen'] ?? [])),
            'storytelling' => array_values(array_map($trim, $content['storytelling'] ?? [])),
            'limits' => array_values(array_map($trim, $content['limits'] ?? [])),
        ];
    }

    /**
     * @param  array{0: int, 1: int}  $limits
     * @return list<string>
     */
    private static function textViolations(string $where, mixed $value, array $limits): array
    {
        if (! is_string($value)) {
            return ["{$where}: must be a string"];
        }

        $length = mb_strlen(trim($value));

        if ($length < $limits[0] || $length > $limits[1]) {
            return ["{$where}: must hold {$limits[0]}-{$limits[1]} characters, holds {$length}"];
        }

        return [];
    }

    /** @return list<string> */
    private static function listViolations(string $where, mixed $items): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            return ["{$where}: must be a list"];
        }

        $violations = count($items) > self::MAX_ITEMS ? ["{$where}: holds at most ".self::MAX_ITEMS.' items'] : [];

        foreach ($items as $index => $item) {
            $violations = array_merge($violations, self::textViolations("{$where}[{$index}]", $item, self::ITEM_LIMITS));
        }

        return $violations;
    }
}
