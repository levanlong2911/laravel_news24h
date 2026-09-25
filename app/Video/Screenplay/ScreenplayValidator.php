<?php

declare(strict_types=1);

namespace App\Video\Screenplay;

use App\Video\Evidence\ExcludedTermMatcher;

final class ScreenplayValidator
{
    private const UNIT =
        '(?:m|metre|metres|meter|meters|ft|feet|foot|km|mile|miles|'
        .'nm|nautical\s+miles?|kg|tonne|tonnes|ton|tons|gt|knot|knots|kw|hp|'
        .'cm|mm|inch|inches|%)';

    private const NUMBER_WORD =
        '(?:zero|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|'
        .'thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty|'
        .'thirty|forty|fifty|sixty|seventy|eighty|ninety|hundred|thousand|million)';

    private const DIGIT_UNIT_PATTERN = '/\d[\d.,]*\s*'.self::UNIT.'\b/iu';

    private const WORD_UNIT_PATTERN =
        '/\b'.self::NUMBER_WORD.'(?:[\s\-]+(?:and[\s\-]+)?'.self::NUMBER_WORD.')*'
        .'(?:\s+point(?:[\s\-]+'.self::NUMBER_WORD.')+)?\s+'.self::UNIT.'\b/iu';

    private const MONTH =
        '(?:january|february|march|april|may|june|july|august|september|october|november|december)';

    /** @var list<string> */
    private const DATE_PATTERNS = [
        '/\b(?:19|20)\d{2}\b/u',
        '/\b\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}\b/u',
        '/\b'.self::MONTH.'\b\.?,?\s+\d{1,4}(?:st|nd|rd|th)?\b/iu',
        '/\b\d{1,2}(?:st|nd|rd|th)?\s+'.self::MONTH.'\b/iu',
    ];

    /** @var list<string> */
    private const AUDIENCE_STATE = [
        'unseen', 'not seen', 'assumed', 'so far', 'yet to', 'revealed as',
        'the viewer', 'we see', 'we now',
    ];

    /** @var list<string> */
    private const THESIS_FIELDS = [
        'central_idea', 'visible_difference', 'spatial_consequence',
        'coherence', 'realization',
    ];

    public function __construct(
        private readonly ExcludedTermMatcher $matcher = new ExcludedTermMatcher,
    ) {}

    /** @var list<string> A response is a whole film. */
    public const CONTRACTS = ['screenplay_v2', 'screenplay_v3'];

    /** @var list<string> A response is one step towards a film, not a film. */
    public const STEP_CONTRACTS = ['screenplay_foundation_v1', 'screenplay_foundation_v2'];

    /** @var list<string> */
    private const DIMENSIONED_CONTRACTS = ['screenplay_foundation_v2'];

    /** @var list<string> */
    public const ALL_CONTRACTS = [...self::CONTRACTS, ...self::STEP_CONTRACTS];

    /** @var array<string, string> */
    private const FOUNDATION_TEXTS = [
        'logline' => 'logline',
        'synopsis' => 'synopsis',
        'ending' => 'ending',
    ];

    /** @var list<string> */
    private const TREATMENT_FIELDS = [
        'stage', 'dramatic_purpose', 'observable_development',
        'participants', 'handover_to_next',
    ];

    /** @var array<string, string> */
    private const TYPE_NAMES = [
        'string' => 'a string',
        'integer' => 'an integer',
        'list' => 'a list',
    ];

    /** @var array<string, array<string, string>> */
    private const NESTED_TEXT_FIELDS = [
        'design_thesis' => [
            'central_idea' => 'string',
            'visible_difference' => 'string',
            'spatial_consequence' => 'string',
            'coherence' => 'string',
            'realization' => 'string',
        ],
        'premise' => [
            'question' => 'string',
            'force' => 'string',
            'device' => 'string',
            'change' => 'string',
            'answer' => 'string',
        ],
    ];

    /** @var array<string, array<string, string>> */
    private const FIELD_TYPES = [
        'characters' => [
            'id' => 'string',
            'name' => 'string',
            'role' => 'string',
            'kind' => 'string',
            'description' => 'string',
            'personality' => '?string',
            'appearance' => 'string',
        ],
        'locations' => [
            'id' => 'string',
            'name' => 'string',
            'description' => 'string',
        ],
        'scenes' => [
            'id' => 'string',
            'stage' => 'string',
            'location_id' => 'string',
            'int_ext' => 'string',
            'time' => 'string',
            'character_ids' => 'list',
            'action' => 'string',
            'dialogue' => 'list',
            'sound' => '?string',
            'duration_estimate_ms' => 'integer',
            'why_it_cannot_be_cut' => 'string',
            'leads_to' => '?string',
        ],
        'dialogue' => [
            'character_id' => 'string',
            'line' => 'string',
        ],
    ];

    /** @var array<string, list<string>> */
    private const FIELD_ENUMS = [
        'characters.role' => ['protagonist', 'supporting', 'incidental'],
        'scenes.int_ext' => ['INT', 'EXT'],
        'scenes.time' => ['DAY', 'NIGHT', 'DAWN', 'DUSK', 'CONTINUOUS'],
    ];

    /** @var array<string, list<string>> */
    private const CHARACTER_KINDS = [
        'screenplay_v2' => ['object', 'person'],
        'screenplay_v3' => ['object', 'person', 'group'],
    ];

    /** @return list<string> Configuration errors, checked before any model call. */
    public function profileViolations(array $profile, string $contract): array
    {
        if (! in_array($contract, self::ALL_CONTRACTS, true)) {
            return ['contract_version: unsupported author contract'];
        }

        $errors = [];
        if (($profile['contract_version'] ?? null) !== $contract) {
            $errors[] = 'contract_version: must match the author contract';
        }

        if (in_array($contract, self::CONTRACTS, true)) {
            foreach (['min_scenes', 'max_scenes', 'max_subjects', 'max_locations'] as $key) {
                if (! is_int($profile[$key] ?? null) || $profile[$key] <= 0) {
                    $errors[] = "{$key}: must be a positive integer";
                }
            }
            if (is_int($profile['min_scenes'] ?? null) && is_int($profile['max_scenes'] ?? null)
                && $profile['min_scenes'] > $profile['max_scenes']) {
                $errors[] = 'min_scenes: must not exceed max_scenes';
            }
        }

        $stages = [];
        foreach (['arc_stages', 'arc_required_stages'] as $key) {
            $rows = $profile[$key] ?? null;
            $stages[$key] = [];
            if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
                $errors[] = "{$key}: must be a nonempty list";
                continue;
            }
            foreach ($rows as $index => $stage) {
                if (! is_string($stage) || trim($stage) === '') {
                    $errors[] = "{$key}[{$index}]: must be a nonempty string";
                } elseif (in_array($stage, $stages[$key], true)) {
                    $errors[] = "{$key}[{$index}]: duplicate stage";
                } else {
                    $stages[$key][] = $stage;
                }
            }
        }
        if (array_diff($stages['arc_required_stages'], $stages['arc_stages']) !== []) {
            $errors[] = 'arc_required_stages: must be a subset of arc_stages';
        }
        if (is_int($profile['max_scenes'] ?? null)
            && count($stages['arc_required_stages']) > $profile['max_scenes']) {
            $errors[] = 'max_scenes: cannot accommodate all required stages';
        }

        if (in_array($contract, self::DIMENSIONED_CONTRACTS, true)) {
            $bounds = $this->lengthBounds($profile);
            if ($bounds === null) {
                $errors[] = 'dimension_bounds.length_m: must declare positive integer min and max';
            } elseif ($bounds[0] > $bounds[1]) {
                $errors[] = 'dimension_bounds.length_m: min must not exceed max';
            }
        }

        if ($contract !== 'screenplay_v3') {
            return $errors;
        }

        $coverage = $profile['coverage'] ?? null;
        if (! is_array($coverage) || ! array_is_list($coverage) || $coverage === []) {
            $errors[] = 'coverage: must be a nonempty list';
            return $errors;
        }

        $ids = [];
        foreach ($coverage as $index => $item) {
            $path = "coverage[{$index}]";
            if (! is_array($item)) {
                $errors[] = "{$path}: must be an object";
                continue;
            }
            $id = $item['id'] ?? null;
            if (! is_string($id) || preg_match('/^cov_[a-z0-9_]{3,40}$/D', $id) !== 1) {
                $errors[] = "{$path}.id: invalid coverage id";
            } elseif (in_array($id, $ids, true)) {
                $errors[] = "{$path}.id: duplicate coverage id";
            } else {
                $ids[] = $id;
            }
            if (! in_array($item['stage'] ?? null, $stages['arc_stages'], true)) {
                $errors[] = "{$path}.stage: must belong to arc_stages";
            }
            if (! in_array($item['level'] ?? null, ['required', 'shown_or_transition'], true)) {
                $errors[] = "{$path}.level: unsupported coverage level";
            }
            if (! is_string($item['label'] ?? null) || trim($item['label']) === '') {
                $errors[] = "{$path}.label: must be a nonempty string";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $profile
     * @param  list<string>  $excludedNames
     * @return list<string>
     */
    public function structural(
        array $screenplay,
        array $profile,
        string $contract,
        array $excludedNames = [],
    ): array {
        if (! in_array($contract, self::ALL_CONTRACTS, true)) {
            return ['contract_version: unsupported author contract'];
        }

        if (($profile['contract_version'] ?? null) !== $contract) {
            return ['contract_version: profile does not match the author contract'];
        }

        if (in_array($contract, self::STEP_CONTRACTS, true)) {
            return $this->foundationViolations($screenplay, $profile, $contract, $excludedNames);
        }

        $shape = $this->shapeViolations($screenplay, $contract);

        if ($shape !== []) {
            return $shape;
        }

        $violations = array_merge(
            $this->designThesisViolations($screenplay),
            $this->forbiddenTermViolations($screenplay, $profile),
            $this->identityViolations($screenplay),
            $this->linkViolations($screenplay, $profile),
            $this->stageViolations($screenplay, $profile),
            $this->sourceFactViolations($screenplay, $contract, $excludedNames),
        );

        if ($contract === 'screenplay_v3') {
            $violations = array_merge(
                $violations,
                $this->subjectLimitViolations($screenplay, $profile),
                $this->speakerViolations($screenplay),
                $this->buildStateViolations($screenplay),
                $this->coverageViolations($screenplay, $profile),
            );
        }

        return array_values(array_unique($violations));
    }

    /**
     * @param  array<string, mixed>  $foundation
     * @param  array<string, mixed>  $profile
     * @param  list<string>  $excludedNames
     * @return list<string>
     */
    private function foundationViolations(
        array $foundation,
        array $profile,
        string $contract,
        array $excludedNames,
    ): array {
        $violations = [];

        foreach (self::FOUNDATION_TEXTS as $key => $path) {
            if (! is_string($foundation[$key] ?? null) || trim($foundation[$key]) === '') {
                $violations[] = "{$path}: must be a nonempty string";
            }
        }

        foreach (['design_thesis' => self::THESIS_FIELDS,
            'premise' => ['question', 'force', 'device', 'change', 'answer']] as $group => $fields) {
            if (! is_array($foundation[$group] ?? null)) {
                $violations[] = "{$group}: must be an object";

                continue;
            }

            foreach ($fields as $field) {
                if (! is_string($foundation[$group][$field] ?? null)
                    || trim($foundation[$group][$field]) === '') {
                    $violations[] = "{$group}.{$field}: must be a nonempty string";
                }
            }
        }

        foreach (['scenes', 'coverage', 'shots', 'characters', 'locations',
            'duration_estimate_ms', 'dialogue'] as $forbidden) {
            if (array_key_exists($forbidden, $foundation)) {
                $violations[] = "{$forbidden}: this step does not produce it";
            }
        }

        return array_merge(
            $violations,
            in_array($contract, self::DIMENSIONED_CONTRACTS, true)
                ? $this->dimensionViolations($foundation, $profile)
                : [],
            $this->treatmentViolations($foundation, $profile),
            $this->sourceFactsIn($this->foundationTexts($foundation), $excludedNames),
        );
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array{0: int, 1: int}|null
     */
    private function lengthBounds(array $profile): ?array
    {
        $min = $profile['dimension_bounds']['length_m']['min'] ?? null;
        $max = $profile['dimension_bounds']['length_m']['max'] ?? null;

        return is_int($min) && is_int($max) && $min > 0 && $max > 0 ? [$min, $max] : null;
    }

    /**
     * @param  array<string, mixed>  $foundation
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    private function dimensionViolations(array $foundation, array $profile): array
    {
        $dimensions = $foundation['principal_dimensions'] ?? null;

        if (! is_array($dimensions)) {
            return ['principal_dimensions: must be an object'];
        }

        $violations = [];
        $length = $dimensions['length_m'] ?? null;
        $beam = $dimensions['beam_m'] ?? null;
        $bounds = $this->lengthBounds($profile);

        if (! is_int($length)) {
            $violations[] = 'principal_dimensions.length_m: must be an integer';
        } elseif ($bounds === null) {
            $violations[] = 'profile declares no dimension_bounds.length_m';
        } elseif ($length < $bounds[0] || $length > $bounds[1]) {
            $violations[] = "principal_dimensions.length_m: {$length} is outside {$bounds[0]}–{$bounds[1]}";
        }

        if (! is_int($beam) && ! is_float($beam)) {
            $violations[] = 'principal_dimensions.beam_m: must be a number';
        } elseif ($beam <= 0) {
            $violations[] = 'principal_dimensions.beam_m: must be greater than zero';
        } elseif (is_int($length) && $beam >= $length) {
            $violations[] = 'principal_dimensions.beam_m: must be less than length_m';
        }

        if (! is_string($dimensions['rationale'] ?? null) || trim($dimensions['rationale']) === '') {
            $violations[] = 'principal_dimensions.rationale: must be a nonempty string';
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $foundation
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    private function treatmentViolations(array $foundation, array $profile): array
    {
        $expected = array_values(array_filter($this->rowsOf($profile, 'arc_stages'), 'is_string'));
        $rows = $foundation['stage_treatments'] ?? null;

        if ($expected === []) {
            return ['profile declares no arc_stages'];
        }

        if (! is_array($rows) || ! array_is_list($rows)) {
            return ['stage_treatments: must be a list'];
        }

        $violations = [];

        foreach ($rows as $index => $row) {
            $path = "stage_treatments[{$index}]";

            if (! is_array($row)) {
                $violations[] = "{$path}: must be an object";

                continue;
            }

            foreach (self::TREATMENT_FIELDS as $field) {
                if (! is_string($row[$field] ?? null) || trim($row[$field]) === '') {
                    $violations[] = "{$path}.{$field}: must be a nonempty string";
                }
            }
        }

        $given = array_map(
            static fn (mixed $row): mixed => is_array($row) ? ($row['stage'] ?? null) : null,
            $rows,
        );

        if ($given !== $expected) {
            $violations[] = 'stage_treatments: must carry every arc_stage exactly once, in order — expected '
                .implode(', ', $expected).'; got '
                .implode(', ', array_map(static fn (mixed $v): string => is_string($v) ? $v : '?', $given));
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $foundation
     * @return array<string, string>
     */
    private function foundationTexts(array $foundation): array
    {
        $texts = [];

        foreach (self::FOUNDATION_TEXTS as $key => $path) {
            $texts[$path] = (string) ($foundation[$key] ?? '');
        }

        foreach (['design_thesis' => self::THESIS_FIELDS,
            'premise' => ['question', 'force', 'device', 'change', 'answer']] as $group => $fields) {
            foreach ($fields as $field) {
                $texts["{$group}.{$field}"] = (string) ($foundation[$group][$field] ?? '');
            }
        }

        $rationale = $foundation['principal_dimensions']['rationale'] ?? '';
        $texts['principal_dimensions.rationale'] = is_string($rationale) ? $rationale : '';

        foreach ($this->rowsOf($foundation, 'stage_treatments') as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $label = is_string($row['stage'] ?? null) ? $row['stage'] : "stage_treatments[{$index}]";

            foreach (self::TREATMENT_FIELDS as $field) {
                if ($field !== 'stage') {
                    $texts["{$label}.{$field}"] = (string) ($row[$field] ?? '');
                }
            }
        }

        return $texts;
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @return list<string>
     */
    private function shapeViolations(array $screenplay, string $contract): array
    {
        $violations = [];

        if (! is_string($screenplay['logline'] ?? null)) {
            $violations[] = 'logline: must be a string';
        }

        foreach (self::NESTED_TEXT_FIELDS as $key => $fields) {
            $row = $screenplay[$key] ?? null;

            if (! is_array($row)) {
                $violations[] = "{$key}: must be an object";

                continue;
            }

            $violations = array_merge(
                $violations,
                $this->fieldViolations($key, $key, $row, $fields, $contract),
            );
        }

        $collections = ['characters', 'locations', 'scenes'];

        if ($contract === 'screenplay_v3') {
            $collections[] = 'coverage';
        }

        foreach ($collections as $key) {
            $rows = $screenplay[$key] ?? null;

            if (! is_array($rows) || ! array_is_list($rows)) {
                $violations[] = "{$key}: must be a list";

                continue;
            }

            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    $violations[] = "{$key}[{$index}]: must be an object";

                    continue;
                }

                if (! array_key_exists($key, self::FIELD_TYPES)) {
                    continue;
                }

                $path = $key === 'scenes'
                    ? $this->scenePath($row, $index)
                    : "{$key}[{$index}]";

                $violations = array_merge(
                    $violations,
                    $this->fieldViolations($key, $path, $row, self::FIELD_TYPES[$key], $contract),
                );

                if ($key === 'scenes') {
                    $violations = array_merge($violations, $this->sceneRowViolations($path, $row, $contract));
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $types
     * @return list<string>
     */
    private function fieldViolations(
        string $collection,
        string $path,
        array $row,
        array $types,
        string $contract,
    ): array {
        $violations = [];

        foreach ($types as $field => $type) {
            $where = "{$path}.{$field}";

            if (! array_key_exists($field, $row)) {
                $violations[] = "{$where}: is required";

                continue;
            }

            $value = $row[$field];
            $nullable = str_starts_with($type, '?');

            if ($value === null) {
                if (! $nullable) {
                    $violations[] = "{$where}: must not be null";
                }

                continue;
            }

            $expected = ltrim($type, '?');

            if (! $this->isOfType($value, $expected)) {
                $violations[] = "{$where}: must be ".self::TYPE_NAMES[$expected];

                continue;
            }

            $allowed = $this->allowedValues($collection, $field, $contract);

            if ($allowed !== null && ! in_array($value, $allowed, true)) {
                $violations[] = "{$where}: must be one of ".implode(', ', $allowed);
            }
        }

        return $violations;
    }

    private function isOfType(mixed $value, string $expected): bool
    {
        return match ($expected) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'list' => is_array($value) && array_is_list($value),
            default => throw new \LogicException(
                "ScreenplayValidator declares an unsupported field type: {$expected}"
            ),
        };
    }

    /** @return list<string>|null */
    private function allowedValues(string $collection, string $field, string $contract): ?array
    {
        if ($collection === 'characters' && $field === 'kind') {
            return self::CHARACTER_KINDS[$contract] ?? null;
        }

        return self::FIELD_ENUMS["{$collection}.{$field}"] ?? null;
    }

    /** @param  array<string, mixed>  $scene */
    private function scenePath(array $scene, int|string $index): string
    {
        $id = $scene['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : "scenes[{$index}]";
    }

    /**
     * @param  array<string, mixed>  $scene
     * @return list<string>
     */
    private function sceneRowViolations(string $path, array $scene, string $contract): array
    {
        $violations = [];
        $characterIds = $scene['character_ids'] ?? null;

        if (is_array($characterIds) && array_is_list($characterIds)) {
            foreach ($characterIds as $index => $characterId) {
                if (! is_string($characterId)) {
                    $violations[] = "{$path}.character_ids[{$index}]: must be a string";
                }
            }
        }

        $dialogue = $scene['dialogue'] ?? null;

        if (! is_array($dialogue) || ! array_is_list($dialogue)) {
            return $violations;
        }

        foreach ($dialogue as $index => $line) {
            if (! is_array($line)) {
                $violations[] = "{$path}.dialogue[{$index}]: must be an object";

                continue;
            }

            $violations = array_merge($violations, $this->fieldViolations(
                'dialogue',
                "{$path}.dialogue[{$index}]",
                $line,
                self::FIELD_TYPES['dialogue'],
                $contract,
            ));
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @return list<string>
     */
    private function designThesisViolations(array $screenplay): array
    {
        $thesis = $screenplay['design_thesis'] ?? [];
        $violations = [];

        foreach (self::THESIS_FIELDS as $field) {
            if (trim((string) ($thesis[$field] ?? '')) === '') {
                $violations[] = "design_thesis.{$field} is empty";
            }
        }

        return $violations;
    }

    /** @param  array<string, mixed>  $screenplay */
    private function protagonistAppearance(array $screenplay): string
    {
        foreach ($screenplay['characters'] ?? [] as $character) {
            if (($character['role'] ?? null) === 'protagonist') {
                return mb_strtolower((string) ($character['appearance'] ?? ''));
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    private function forbiddenTermViolations(array $screenplay, array $profile): array
    {
        $appearance = $this->protagonistAppearance($screenplay);
        $violations = [];

        foreach ($profile['concept_forbidden_terms'] ?? [] as $term) {
            if ($this->matcher->containsTerm($appearance, mb_strtolower((string) $term))) {
                $violations[] = "the protagonist's appearance uses the forbidden term \"{$term}\"";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    private function antipatternWarnings(array $screenplay, array $profile): array
    {
        $thesis = mb_strtolower(implode(' ', array_map(
            static fn ($value): string => is_string($value) ? $value : '',
            $screenplay['design_thesis'] ?? [],
        )));

        $text = $this->protagonistAppearance($screenplay).' '.$thesis;
        $warnings = [];

        foreach ($profile['concept_antipatterns'] ?? [] as $pattern) {
            $hits = $this->antipatternHits($text, (string) $pattern);

            if ($hits >= 3) {
                $warnings[] = "the design shares wording with a category antipattern on {$hits} counts, "
                    ."read it before accepting: ".mb_substr((string) $pattern, 0, 90);
            }
        }

        return $warnings;
    }

    private function antipatternHits(string $text, string $pattern): int
    {
        $hits = 0;

        foreach (preg_split('/[,;]/u', $pattern) ?: [] as $clause) {
            $words = array_values(array_filter(
                preg_split('/[^a-z]+/u', mb_strtolower($clause)) ?: [],
                static fn (string $word): bool => mb_strlen($word) > 4,
            ));

            if ($words === []) {
                continue;
            }

            $matched = array_filter($words, static fn (string $word): bool => str_contains($text, $word));

            if (count($matched) * 2 >= count($words)) {
                $hits++;
            }
        }

        return $hits;
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    public function editorial(array $screenplay, array $profile): array
    {
        return array_values(array_unique(array_merge(
            $this->audienceStateViolations($screenplay),
            $this->appearanceViolations($screenplay),
            $this->antipatternWarnings($screenplay, $profile),
            $this->coverageReviewWarnings($screenplay, $profile),
        )));
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    private function coverageReviewWarnings(array $screenplay, array $profile): array
    {
        if (($profile['contract_version'] ?? null) !== 'screenplay_v3') {
            return [];
        }

        $warnings = [];

        foreach ($this->rowsOf($screenplay, 'coverage') as $item) {
            if (! is_array($item) || ($item['mode'] ?? null) !== 'not_applicable') {
                continue;
            }

            $id = is_string($item['coverage_id'] ?? null) ? $item['coverage_id'] : '?';
            $reason = is_string($item['evidence'] ?? null) ? $item['evidence'] : '';

            $warnings[] = "coverage {$id} is set aside as not_applicable and needs an editor to accept the reason: "
                .mb_substr($reason, 0, 90);
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @return list<string>
     */
    private function identityViolations(array $screenplay): array
    {
        $violations = [];
        $characters = $screenplay['characters'] ?? [];

        $roles = array_column($characters, 'role');
        $heroes = count(array_filter($roles, static fn (string $r): bool => $r === 'protagonist'));

        if ($heroes !== 1) {
            $violations[] = "characters must hold exactly one protagonist, holds {$heroes}";
        }

        foreach ([['characters', $characters], ['locations', $screenplay['locations'] ?? []],
            ['scenes', $screenplay['scenes'] ?? []]] as [$name, $rows]) {
            $ids = array_column($rows, 'id');

            if (count($ids) !== count(array_unique($ids))) {
                $violations[] = "{$name} carries duplicate ids";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    private function linkViolations(array $screenplay, array $profile): array
    {
        $characterIds = array_column($screenplay['characters'] ?? [], 'id');
        $locationIds = array_column($screenplay['locations'] ?? [], 'id');
        $scenes = $screenplay['scenes'] ?? [];

        $violations = [];
        $max = (int) ($profile['max_scenes'] ?? 0);
        $min = (int) ($profile['min_scenes'] ?? 0);

        if ($max > 0 && count($scenes) > $max) {
            $violations[] = 'scene count '.count($scenes)." exceeds profile maximum {$max}";
        }

        if ($min > 0 && count($scenes) < $min) {
            $violations[] = 'scene count '.count($scenes)." is below profile minimum {$min}";
        }

        foreach ($scenes as $position => $scene) {
            if (! is_array($scene)) {
                $violations[] = "scenes[{$position}]: must be an object";

                continue;
            }

            $id = $this->sceneLabel($scene);
            $location = $scene['location_id'] ?? null;

            if (! in_array($location, $locationIds, true)) {
                $violations[] = "{$id} names location ".$this->label($location).' that is not declared';
            }

            foreach ($this->rowsOf($scene, 'character_ids') as $characterId) {
                if (! in_array($characterId, $characterIds, true)) {
                    $violations[] = "{$id} names character ".$this->label($characterId).' that is not declared';
                }
            }

            foreach ($this->rowsOf($scene, 'dialogue') as $index => $line) {
                if (! is_array($line)) {
                    $violations[] = "{$id}.dialogue[{$index}]: must be an object";

                    continue;
                }

                if (! in_array($line['character_id'] ?? null, $characterIds, true)) {
                    $violations[] = "{$id} gives a line to ".$this->label($line['character_id'] ?? null)
                        .' who is not declared';
                }
            }

            if ((int) ($scene['duration_estimate_ms'] ?? 0) <= 0) {
                $violations[] = "{$id} has no positive duration estimate";
            }
        }

        $last = array_key_last($scenes);

        foreach ($scenes as $position => $scene) {
            $id = (string) ($scene['id'] ?? '?');
            $leadsTo = $scene['leads_to'] ?? null;

            if ($position === $last && $leadsTo !== null) {
                $violations[] = "{$id} is the last scene and must carry a null leads_to";
            }

            if ($position !== $last && ! is_string($leadsTo)) {
                $violations[] = "{$id} is not the last scene and must say what it leads to";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    private function stageViolations(array $screenplay, array $profile): array
    {
        $allowed = $profile['arc_stages'] ?? [];
        $required = $profile['arc_required_stages'] ?? [];

        if ($allowed === []) {
            return ['profile declares no arc_stages'];
        }

        $violations = [];
        $rank = array_flip($allowed);
        $highest = -1;
        $used = [];

        foreach ($screenplay['scenes'] ?? [] as $scene) {
            $id = (string) ($scene['id'] ?? '?');
            $stage = (string) ($scene['stage'] ?? '');

            if (! array_key_exists($stage, $rank)) {
                $violations[] = "{$id} names stage {$stage} that is not in arc_stages";

                continue;
            }

            $used[] = $stage;

            if ($rank[$stage] < $highest) {
                $violations[] = "{$id} steps back to stage {$stage}";
            }

            $highest = max($highest, $rank[$stage]);
        }

        foreach (array_diff($required, $used) as $missing) {
            $violations[] = "required stage {$missing} has no scene";
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    private function subjectLimitViolations(array $screenplay, array $profile): array
    {
        $violations = [];

        foreach (['characters' => 'max_subjects', 'locations' => 'max_locations'] as $collection => $key) {
            $rows = $screenplay[$collection] ?? null;

            if (! is_array($rows) || ! array_is_list($rows)) {
                $violations[] = "{$collection}: must be a list";

                continue;
            }

            $limit = $profile[$key] ?? null;

            if (is_int($limit) && count($rows) > $limit) {
                $violations[] = "{$collection} declares ".count($rows)." entries, profile {$key} allows {$limit}";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @return list<string>
     */
    private function speakerViolations(array $screenplay): array
    {
        $kinds = [];

        foreach ($this->rowsOf($screenplay, 'characters') as $character) {
            if (is_array($character) && is_string($character['id'] ?? null)) {
                $kinds[$character['id']] = $character['kind'] ?? null;
            }
        }

        $violations = [];

        foreach ($this->rowsOf($screenplay, 'scenes') as $scene) {
            if (! is_array($scene)) {
                continue;
            }

            $id = $this->sceneLabel($scene);
            $present = array_filter($this->rowsOf($scene, 'character_ids'), 'is_string');

            foreach ($this->rowsOf($scene, 'dialogue') as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }

                $speaker = $line['character_id'] ?? null;

                if (! is_string($speaker) || ! array_key_exists($speaker, $kinds)) {
                    continue;
                }

                if (! in_array($speaker, $present, true)) {
                    $violations[] = "{$id}.dialogue[{$index}]: {$speaker} speaks but is not among the scene character_ids";
                }

                if (($kinds[$speaker] ?? null) !== 'person') {
                    $violations[] = "{$id}.dialogue[{$index}]: {$speaker} is not a person and cannot speak";
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @return list<string>
     */
    private function buildStateViolations(array $screenplay): array
    {
        $objects = [];

        foreach ($this->rowsOf($screenplay, 'characters') as $character) {
            if (is_array($character) && is_string($character['id'] ?? null)
                && ($character['kind'] ?? null) === 'object') {
                $objects[] = $character['id'];
            }
        }

        $violations = [];

        foreach ($this->rowsOf($screenplay, 'scenes') as $scene) {
            if (! is_array($scene)) {
                continue;
            }

            $id = $this->sceneLabel($scene);

            if (! array_key_exists('build_state', $scene)) {
                $violations[] = "{$id}.build_state: must be present, null only when it does not apply";

                continue;
            }

            $state = $scene['build_state'];

            if ($state === null) {
                continue;
            }

            if (! is_array($state)) {
                $violations[] = "{$id}.build_state: must be an object or null";

                continue;
            }

            $subject = $state['subject_id'] ?? null;

            if (! is_string($subject) || ! in_array($subject, $objects, true)) {
                $violations[] = "{$id}.build_state.subject_id: must name a declared character whose kind is object";
            }

            if (! is_string($state['state'] ?? null) || trim($state['state']) === '') {
                $violations[] = "{$id}.build_state.state: must be a nonempty string";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    private function coverageViolations(array $screenplay, array $profile): array
    {
        $expected = [];

        foreach ($this->rowsOf($profile, 'coverage') as $item) {
            if (is_array($item) && is_string($item['id'] ?? null)) {
                $expected[$item['id']] = $item['level'] ?? null;
            }
        }

        $rows = $screenplay['coverage'] ?? null;

        if (! is_array($rows) || ! array_is_list($rows)) {
            return ['coverage: must be a list'];
        }

        $order = [];

        foreach ($this->rowsOf($screenplay, 'scenes') as $position => $scene) {
            if (is_array($scene) && is_string($scene['id'] ?? null)) {
                $order[$scene['id']] = $position;
            }
        }

        $violations = [];
        $seen = [];

        foreach ($rows as $index => $item) {
            $path = "coverage[{$index}]";

            if (! is_array($item)) {
                $violations[] = "{$path}: must be an object";

                continue;
            }

            $id = $item['coverage_id'] ?? null;

            if (! is_string($id) || ! array_key_exists($id, $expected)) {
                $violations[] = "{$path}.coverage_id: is not declared by the profile";

                continue;
            }

            if (in_array($id, $seen, true)) {
                $violations[] = "{$path}.coverage_id: {$id} appears more than once";

                continue;
            }

            $seen[] = $id;

            if (! is_string($item['evidence'] ?? null) || trim($item['evidence']) === '') {
                $violations[] = "{$path}.evidence: must be a nonempty string";
            }

            $mode = $item['mode'] ?? null;

            if (! in_array($mode, ['shown', 'transition', 'not_applicable'], true)) {
                $violations[] = "{$path}.mode: unsupported coverage mode";

                continue;
            }

            if ($expected[$id] === 'required' && $mode !== 'shown') {
                $violations[] = "{$path}.mode: {$id} is required and accepts only shown, carries {$mode}";
            }

            $sceneIds = $item['scene_ids'] ?? null;

            if (! is_array($sceneIds) || ! array_is_list($sceneIds)) {
                $violations[] = "{$path}.scene_ids: must be a list";

                continue;
            }

            foreach ($sceneIds as $position => $sceneId) {
                if (! is_string($sceneId) || ! array_key_exists($sceneId, $order)) {
                    $violations[] = "{$path}.scene_ids[{$position}]: names a scene that is not declared";
                }
            }

            if (count($sceneIds) !== count(array_unique($sceneIds, SORT_REGULAR))) {
                $violations[] = "{$path}.scene_ids: names the same scene twice";
            }

            $violations = array_merge(
                $violations,
                $this->coverageModeViolations($path, $mode, $sceneIds, $order),
            );
        }

        foreach (array_diff(array_keys($expected), $seen) as $missing) {
            $violations[] = "coverage: {$missing} is declared by the profile but absent from the screenplay";
        }

        return $violations;
    }

    /**
     * @param  list<mixed>  $sceneIds
     * @param  array<string, int|string>  $order
     * @return list<string>
     */
    private function coverageModeViolations(string $path, string $mode, array $sceneIds, array $order): array
    {
        if ($mode === 'not_applicable') {
            return $sceneIds === [] ? [] : ["{$path}.scene_ids: not_applicable must name no scene"];
        }

        if ($mode === 'shown') {
            return $sceneIds === [] ? ["{$path}.scene_ids: shown needs at least one scene"] : [];
        }

        if (count($sceneIds) !== 2) {
            return ["{$path}.scene_ids: transition needs exactly two scenes, carries ".count($sceneIds)];
        }

        [$before, $after] = $sceneIds;

        if (! is_string($before) || ! is_string($after)
            || ! array_key_exists($before, $order) || ! array_key_exists($after, $order)) {
            return [];
        }

        if ($order[$before] >= $order[$after]) {
            return ["{$path}.scene_ids: transition must name the scene before then the scene after, in screenplay order"];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<int|string, mixed>
     */
    private function rowsOf(array $source, string $key): array
    {
        $rows = $source[$key] ?? null;

        return is_array($rows) ? $rows : [];
    }

    /** @param  array<string, mixed>  $scene */
    private function sceneLabel(array $scene): string
    {
        return is_string($scene['id'] ?? null) ? $scene['id'] : '?';
    }

    private function label(mixed $value): string
    {
        return is_string($value) ? $value : get_debug_type($value);
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  list<string>  $excludedNames
     * @return list<string>
     */
    private function sourceFactViolations(array $screenplay, string $contract, array $excludedNames): array
    {
        return $this->sourceFactsIn($this->narrativeTexts($screenplay, $contract), $excludedNames);
    }

    /**
     * @param  array<string, string>  $texts
     * @param  list<string>  $excludedNames
     * @return list<string>
     */
    private function sourceFactsIn(array $texts, array $excludedNames): array
    {
        $violations = [];

        foreach ($texts as $where => $text) {
            foreach ([self::DIGIT_UNIT_PATTERN, self::WORD_UNIT_PATTERN] as $pattern) {
                if (preg_match($pattern, $text, $match) === 1) {
                    $violations[] = "{$where} carries a measurement: ".trim($match[0]);

                    break;
                }
            }

            foreach (self::DATE_PATTERNS as $pattern) {
                if (preg_match($pattern, $text, $match) === 1) {
                    $violations[] = "{$where} carries a calendar date: ".trim($match[0]);

                    break;
                }
            }

            foreach ($excludedNames as $term) {
                if ($this->matcher->containsTerm(mb_strtolower($text), mb_strtolower($term))) {
                    $violations[] = "{$where} carries the excluded name {$term}";
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @return list<string>
     */
    private function audienceStateViolations(array $screenplay): array
    {
        $violations = [];

        foreach ($screenplay['scenes'] ?? [] as $scene) {
            $action = mb_strtolower((string) ($scene['action'] ?? ''));

            foreach (self::AUDIENCE_STATE as $phrase) {
                if (str_contains($action, $phrase)) {
                    $violations[] = "{$scene['id']} action describes the audience, not the world: \"{$phrase}\"";

                    break;
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @return list<string>
     */
    private function appearanceViolations(array $screenplay): array
    {
        $violations = [];

        foreach ($screenplay['characters'] ?? [] as $character) {
            $appearance = mb_strtolower((string) ($character['appearance'] ?? ''));

            foreach (['cinematic', 'photorealistic', 'ultra-detailed', '8k', 'golden hour',
                'dramatic lighting', 'award-winning', 'bokeh', 'depth of field'] as $word) {
                if (str_contains($appearance, $word)) {
                    $violations[] = "{$character['id']} appearance carries the style word \"{$word}\"";
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @return array<string, string>
     */
    private function narrativeTexts(array $screenplay, string $contract): array
    {
        $texts = ['logline' => (string) ($screenplay['logline'] ?? '')];

        foreach (self::THESIS_FIELDS as $key) {
            $texts["design_thesis.{$key}"] = (string) ($screenplay['design_thesis'][$key] ?? '');
        }

        foreach (['question', 'force', 'device', 'change', 'answer'] as $key) {
            $texts["premise.{$key}"] = (string) ($screenplay['premise'][$key] ?? '');
        }

        foreach ($screenplay['characters'] ?? [] as $character) {
            foreach (['name', 'description', 'personality', 'appearance'] as $key) {
                $texts["{$character['id']}.{$key}"] = (string) ($character[$key] ?? '');
            }
        }

        foreach ($screenplay['locations'] ?? [] as $location) {
            foreach (['name', 'description'] as $key) {
                $texts["{$location['id']}.{$key}"] = (string) ($location[$key] ?? '');
            }
        }

        foreach ($screenplay['scenes'] ?? [] as $scene) {
            $id = (string) ($scene['id'] ?? '?');
            $texts["{$id}.action"] = (string) ($scene['action'] ?? '');
            $texts["{$id}.sound"] = (string) ($scene['sound'] ?? '');
            $texts["{$id}.why_it_cannot_be_cut"] = (string) ($scene['why_it_cannot_be_cut'] ?? '');
            $texts["{$id}.leads_to"] = (string) ($scene['leads_to'] ?? '');

            foreach ($scene['dialogue'] ?? [] as $index => $line) {
                $texts["{$id}.dialogue[{$index}]"] = (string) ($line['line'] ?? '');
            }

            if ($contract === 'screenplay_v3' && is_array($scene['build_state'] ?? null)) {
                $texts["{$id}.build_state.state"] = (string) ($scene['build_state']['state'] ?? '');
            }
        }

        if ($contract === 'screenplay_v3') {
            foreach ($this->rowsOf($screenplay, 'coverage') as $index => $item) {
                if (is_array($item)) {
                    $texts["coverage[{$index}].evidence"] = (string) ($item['evidence'] ?? '');
                }
            }
        }

        return $texts;
    }
}
