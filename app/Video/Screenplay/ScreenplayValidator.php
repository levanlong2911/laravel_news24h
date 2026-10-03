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
    public const CONTRACTS = ['screenplay_v2', 'screenplay_v3', 'screenplay_v4', 'screenplay_v5', 'screenplay_v6', 'screenplay_v7'];

    /** @var list<string> A response is one step towards a film, not a film. */
    public const STEP_CONTRACTS = [
        'screenplay_foundation_v1',
        'screenplay_foundation_v2',
        'screenplay_foundation_v3',
        'screenplay_scene_expansion_v1',
        'screenplay_characters_v1',
        'screenplay_locations_v1',
        'screenplay_scene_expansion_v2',
        'screenplay_locations_v2',
        'screenplay_scene_expansion_v3',
        'screenplay_scene_expansion_v4',
        'screenplay_characters_v2',
        'screenplay_locations_v3',
        VesselDesign::CONTRACT,
        VesselDesign::STORY_CONTRACT,
        VesselDesign::FOUNDATION_CONTRACT,
    ];

    /** @var list<string> */
    public const EXPANSION_CONTRACTS = [
        'screenplay_scene_expansion_v1', 'screenplay_scene_expansion_v2', 'screenplay_scene_expansion_v3',
        'screenplay_scene_expansion_v4',
    ];

    /** @var list<string> */
    private const LISTED_SUBJECT_CONTRACTS = [
        'screenplay_scene_expansion_v2', 'screenplay_scene_expansion_v3', 'screenplay_scene_expansion_v4',
    ];

    /** @var list<string> */
    private const BEAT_CONTRACTS = ['screenplay_v6', 'screenplay_v7', 'screenplay_scene_expansion_v4'];

    /** @var array<string, array<string, string>> */
    private const BEAT_FIELD_TYPES = [
        'scenes' => [
            'scene_mode' => 'string',
            'beats' => 'list',
        ],
    ];

    /** @var array<string, array{0: int, 1: int}> */
    private const BEAT_TEXT_LIMITS = [
        'action' => [10, 400],
        'visible_result' => [10, 300],
    ];

    /** @var array{0: int, 1: int} */
    public const PROGRESS_LIMITS = [3, 600];

    /** @var array<string, array{0: int, 1: int}> */
    private const CONFIGURATION_LIMITS = [
        'part' => [2, 80],
        'state' => [2, 120],
    ];

    /** @var array<string, string> */
    public const CAST_CONTRACTS = [
        'screenplay_characters_v1' => 'characters',
        'screenplay_characters_v2' => 'characters',
        'screenplay_locations_v1' => 'locations',
        'screenplay_locations_v2' => 'locations',
        'screenplay_locations_v3' => 'locations',
    ];

    /** @var list<string> */
    private const BRIEF_SPACE_CONTRACTS = ['screenplay_locations_v3'];

    /** @var list<string> */
    private const DIMENSIONED_CONTRACTS = [
        'screenplay_foundation_v2', 'screenplay_foundation_v3', 'screenplay_v4', 'screenplay_v5', 'screenplay_v6', 'screenplay_v7',
        VesselDesign::CONTRACT, VesselDesign::FOUNDATION_CONTRACT,
    ];

    /** @var list<string> */
    private const STORY_FORBIDDEN = [
        'design_thesis', 'principal_dimensions', 'space_plan', VesselDesign::VESSEL_KEY, ProtagonistProfile::OUTPUT_KEY,
        'characters', 'locations', 'scenes', 'coverage', 'shots', 'dialogue', 'duration_estimate_ms',
    ];

    /** @var list<string> */
    private const DESIGN_FORBIDDEN = [
        'logline', 'premise', 'synopsis', 'stage_treatments', 'ending', 'space_plan',
        'characters', 'locations', 'scenes', 'coverage', 'shots', 'dialogue', 'duration_estimate_ms',
    ];

    /** @var list<string> */
    private const SPACE_PLAN_CONTRACTS = ['screenplay_foundation_v3'];

    /** @var list<string> */
    private const SPACE_PLAN_FIELDS = ['placement', 'role', 'layout_decision'];

    /** @var list<string> */
    private const SCENE_CONTRACTS = [
        'screenplay_v3', 'screenplay_v4', 'screenplay_v5', 'screenplay_v6', 'screenplay_v7',
        'screenplay_scene_expansion_v1', 'screenplay_scene_expansion_v2', 'screenplay_scene_expansion_v3',
        'screenplay_scene_expansion_v4',
    ];

    /** @var list<string> */
    private const FOUNDATION_FIELDS = [
        'logline', 'design_thesis', 'principal_dimensions', 'space_plan', 'premise',
        'synopsis', 'stage_treatments', 'ending',
    ];

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

    /** @var list<string> */
    private const PLACE_CONTRACTS = [
        'screenplay_v5', 'screenplay_v6', 'screenplay_v7', 'screenplay_locations_v2', 'screenplay_locations_v3',
        'screenplay_scene_expansion_v3', 'screenplay_scene_expansion_v4',
    ];

    /** @var array<string, array<string, string>> */
    private const PLACE_FIELD_TYPES = [
        'locations' => [
            'story_use' => 'string',
            'spatial_relation' => 'string',
            'subject_id' => '?string',
            'layout' => 'string',
            'connections' => 'list',
            'fixed_features' => 'list',
            'light_sources' => 'list',
        ],
        'scenes' => [
            'light_and_weather' => 'string',
            'props' => 'list',
        ],
    ];

    /** @var array<string, array{0: int, 1: int}> */
    private const PLACE_TEXT_LIMITS = [
        'locations.story_use' => [20, 400],
        'locations.layout' => [40, 1200],
        'scenes.light_and_weather' => [10, 300],
    ];

    /** @var array<string, array{0: int, 1: int}> */
    private const PLACE_ITEM_LIMITS = [
        'locations.fixed_features' => [3, 200],
        'locations.light_sources' => [3, 200],
        'scenes.props' => [3, 150],
    ];

    /** @var array{0: int, 1: int} */
    private const CONNECTION_VIA_LIMITS = [10, 300];

    /** @var array<string, list<string>> */
    private const FIELD_ENUMS = [
        'characters.role' => ['protagonist', 'supporting', 'incidental'],
        'locations.spatial_relation' => LocationProfile::RELATIONS,
        'scenes.scene_mode' => SceneBeats::MODES,
        'scenes.int_ext' => ['INT', 'EXT'],
        'scenes.time' => ['DAY', 'NIGHT', 'DAWN', 'DUSK', 'CONTINUOUS'],
    ];

    /** @var array<string, list<string>> */
    private const CHARACTER_KINDS = [
        'screenplay_v2' => ['object', 'person'],
        'screenplay_v3' => ['object', 'person', 'group'],
        'screenplay_v4' => ['object', 'person', 'group'],
        'screenplay_v5' => ['object', 'person', 'group'],
        'screenplay_v6' => ['object', 'person', 'group'],
        'screenplay_v7' => ['object', 'person', 'group'],
        'screenplay_scene_expansion_v1' => ['object', 'person', 'group'],
        'screenplay_scene_expansion_v2' => ['object', 'person', 'group'],
        'screenplay_scene_expansion_v3' => ['object', 'person', 'group'],
        'screenplay_scene_expansion_v4' => ['object', 'person', 'group'],
        'screenplay_characters_v1' => ['object', 'person', 'group'],
        'screenplay_characters_v2' => ['object', 'person', 'group'],
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

        if (in_array($contract, self::CONTRACTS, true)
            || in_array($contract, self::EXPANSION_CONTRACTS, true)
            || array_key_exists($contract, self::CAST_CONTRACTS)) {
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

        $stages = ['arc_stages' => [], 'arc_required_stages' => []];
        foreach ($contract === VesselDesign::CONTRACT ? [] : ['arc_stages', 'arc_required_stages'] as $key) {
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

        $people = $profile['people_policy'] ?? null;
        if (is_array($people) && array_key_exists('dialogue_allowed', $people) && ! is_bool($people['dialogue_allowed'])) {
            $errors[] = 'people_policy.dialogue_allowed: must be true or false';
        }

        if (array_key_exists(ProtagonistProfile::CAST_FLAG, $profile) && ! ProtagonistProfile::protagonistOnly($profile)) {
            $errors[] = ProtagonistProfile::CAST_FLAG.': must be '.ProtagonistProfile::PROTAGONIST_ONLY.' when present';
        }

        $focus = $profile[ProtagonistProfile::FOCUS_KEY] ?? null;

        if (array_key_exists(ProtagonistProfile::FOCUS_KEY, $profile)
            && (! is_array($focus) || ! array_is_list($focus) || $focus === []
                || count(ProtagonistProfile::focus($profile)) !== count($focus))) {
            $errors[] = ProtagonistProfile::FOCUS_KEY.': must be a nonempty list of distinct regions: '
                .implode(', ', ProtagonistProfile::REGIONS);
        }

        if ($contract === VesselDesign::CONTRACT) {
            $errors = array_merge($errors, VesselDesign::profileRuleViolations(
                $profile,
                config('video.screenplay.design.profile_contract_version'),
            ));
        }

        if (! in_array($contract, self::SCENE_CONTRACTS, true)) {
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

        if ($contract === VesselDesign::CONTRACT) {
            return $this->designViolations($screenplay, $profile, $excludedNames);
        }

        if ($contract === VesselDesign::STORY_CONTRACT) {
            return $this->storyViolations($screenplay, $profile, $excludedNames);
        }

        if (array_key_exists($contract, self::CAST_CONTRACTS)) {
            return $this->castViolations($screenplay, $profile, $contract, $excludedNames);
        }

        if (in_array($contract, self::EXPANSION_CONTRACTS, true)) {
            return $this->expansionViolations($screenplay, $profile, $contract, $excludedNames);
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
            $this->placeLinkViolations($screenplay, $contract),
            $this->stageViolations($screenplay, $profile),
            $this->sourceFactViolations($screenplay, $contract, $excludedNames),
        );

        if (in_array($contract, self::SCENE_CONTRACTS, true)) {
            $violations = array_merge($violations, $this->sceneRuleViolations($screenplay, $profile, $contract));
        }

        if (in_array($contract, self::DIMENSIONED_CONTRACTS, true)) {
            $violations = array_merge(
                $violations,
                $this->foundationPartViolations($screenplay, $profile, $contract, $excludedNames),
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

        foreach (['scenes', 'coverage', 'shots', 'characters', 'locations',
            'duration_estimate_ms', 'dialogue'] as $forbidden) {
            if (array_key_exists($forbidden, $foundation)) {
                $violations[] = "{$forbidden}: this step does not produce it";
            }
        }

        return array_merge(
            $this->foundationPartViolations($foundation, $profile, $contract, $excludedNames),
            $violations,
            in_array($contract, self::SPACE_PLAN_CONTRACTS, true) ? $this->spacePlanViolations($foundation, $profile) : [],
        );
    }

    /**
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>  $profile
     * @param  list<string>  $excludedNames
     * @return list<string>
     */
    private function designViolations(array $design, array $profile, array $excludedNames): array
    {
        $violations = [];

        foreach (self::DESIGN_FORBIDDEN as $forbidden) {
            if (array_key_exists($forbidden, $design)) {
                $violations[] = "{$forbidden}: this step does not produce it";
            }
        }

        $vessel = $design[VesselDesign::VESSEL_KEY] ?? null;
        $texts = [];

        if (! is_array($vessel)) {
            $violations[] = VesselDesign::VESSEL_KEY.': must be an object';
        } else {
            foreach (VesselDesign::VESSEL_FIELDS as $field => [$min, $max]) {
                $where = VesselDesign::VESSEL_KEY.".{$field}";

                if (! is_string($vessel[$field] ?? null)) {
                    $violations[] = "{$where}: must be a string";

                    continue;
                }

                $texts[$where] = $vessel[$field];
                $violations = array_merge($violations, $this->lengthViolations($where, $vessel[$field], $min, $max));
            }
        }

        if (! is_array($design['design_thesis'] ?? null)) {
            $violations[] = 'design_thesis: must be an object';
        } else {
            foreach (self::THESIS_FIELDS as $field) {
                if (! is_string($design['design_thesis'][$field] ?? null) || trim($design['design_thesis'][$field]) === '') {
                    $violations[] = "design_thesis.{$field}: must be a nonempty string";
                } else {
                    $texts["design_thesis.{$field}"] = $design['design_thesis'][$field];
                }
            }
        }

        if (! VesselDesign::hasCanonical($design)) {
            $violations[] = VesselDesign::CANONICAL_KEY.': must be an object';
        }

        $canonicalTexts = VesselDesign::canonicalTexts($design);

        foreach ([...$texts, ...$canonicalTexts] as $where => $text) {
            foreach (ProtagonistProfile::NOVELTY_CLAIMS as $pattern) {
                if (preg_match($pattern, $text, $match) === 1) {
                    $violations[] = "{$where} claims market novelty: \"".trim($match[0]).'"';

                    break;
                }
            }
        }

        if ($violations !== []) {
            return array_values(array_unique($violations));
        }

        return array_values(array_unique(array_merge(
            VesselDesign::gateViolations($design, $profile),
            $this->relationViolations($design, $profile),
            $this->credibilityClaimViolations($canonicalTexts, $profile),
            $this->sourceFactsIn($canonicalTexts, $excludedNames),
            $this->dimensionViolations($design, $profile),
            $this->forbiddenTermViolations(['characters' => [VesselDesign::vesselRow($design)]], $profile),
            $this->protagonistProfileViolations(
                $design[ProtagonistProfile::OUTPUT_KEY] ?? null,
                $design,
                $excludedNames,
                ProtagonistProfile::focus($profile),
                FilmBrief::profileSpaces($profile),
            ),
            $this->sourceFactsIn($texts, $excludedNames),
        )));
    }

    /**
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    private function relationViolations(array $design, array $profile): array
    {
        $allowed = VesselDesign::relationEnum($profile);

        if ($allowed === null) {
            return [];
        }

        $violations = [];

        foreach ((array) ($design[VesselDesign::CANONICAL_KEY]['geometric_relationships'] ?? []) as $index => $relation) {
            if (is_array($relation) && ! in_array($relation['relation'] ?? null, $allowed, true)) {
                $violations[] = VesselDesign::CANONICAL_KEY.".geometric_relationships[{$index}].relation: "
                    .$this->label($relation['relation'] ?? null).' is not a relation the profile allows';
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, string>  $texts
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    private function credibilityClaimViolations(array $texts, array $profile): array
    {
        $claims = array_filter(
            (array) ($profile['physical_credibility_policy']['forbidden_claims'] ?? []),
            static fn (mixed $claim): bool => is_string($claim) && trim($claim) !== '',
        );
        $violations = [];

        foreach ($texts as $where => $text) {
            foreach ($claims as $claim) {
                $words = preg_split('/[\s-]+/u', trim($claim)) ?: [];
                $pattern = '/\b'.implode('[\s-]+', array_map(static fn (string $word): string => preg_quote($word, '/'), $words)).'\b/iu';

                if (preg_match($pattern, $text) === 1) {
                    $violations[] = "{$where} makes a claim the profile forbids: \"{$claim}\"";

                    break;
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $story
     * @param  array<string, mixed>  $profile
     * @param  list<string>  $excludedNames
     * @return list<string>
     */
    private function storyViolations(array $story, array $profile, array $excludedNames): array
    {
        $violations = [];

        foreach (self::STORY_FORBIDDEN as $forbidden) {
            if (array_key_exists($forbidden, $story)) {
                $violations[] = "{$forbidden}: this step does not produce it, the design is fixed";
            }
        }

        foreach (self::FOUNDATION_TEXTS as $key => $path) {
            if (! is_string($story[$key] ?? null) || trim($story[$key]) === '') {
                $violations[] = "{$path}: must be a nonempty string";
            }
        }

        if (! is_array($story['premise'] ?? null)) {
            $violations[] = 'premise: must be an object';
        } else {
            foreach (['question', 'force', 'device', 'change', 'answer'] as $field) {
                if (! is_string($story['premise'][$field] ?? null) || trim($story['premise'][$field]) === '') {
                    $violations[] = "premise.{$field}: must be a nonempty string";
                }
            }
        }

        return array_values(array_unique(array_merge(
            $violations,
            $this->treatmentViolations($story, $profile),
            $this->sourceFactsIn($this->foundationTexts($story), $excludedNames),
        )));
    }

    /**
     * @param  array<string, mixed>  $foundation
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    private function spacePlanViolations(array $foundation, array $profile): array
    {
        $rows = $foundation['space_plan'] ?? null;

        if (! is_array($rows) || ! array_is_list($rows)) {
            return ['space_plan: must be a list'];
        }

        $expected = FilmBrief::profileSpaces($profile);
        $seen = [];
        $violations = [];

        foreach ($rows as $index => $row) {
            $path = "space_plan[{$index}]";

            if (! is_array($row)) {
                $violations[] = "{$path}: must be an object";

                continue;
            }

            $space = $row['space'] ?? null;

            if (! in_array($space, $expected, true)) {
                $violations[] = "{$path}.space: ".$this->label($space).' is not a space of the project brief';
            } elseif (in_array($space, $seen, true)) {
                $violations[] = "{$path}.space: {$space} is already planned";
            } else {
                $seen[] = $space;
            }

            foreach (self::SPACE_PLAN_FIELDS as $field) {
                if (! is_string($row[$field] ?? null) || trim($row[$field]) === '') {
                    $violations[] = "{$path}.{$field}: must be a nonempty string";
                }
            }
        }

        foreach (array_diff($expected, $seen) as $missing) {
            $violations[] = "space_plan: the project brief space {$missing} has no entry";
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $foundation
     * @param  array<string, mixed>  $profile
     * @param  list<string>  $excludedNames
     * @return list<string>
     */
    private function foundationPartViolations(
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
     * @param  array<string, mixed>  $expansion
     * @param  array<string, mixed>  $profile
     * @param  list<string>  $excludedNames
     * @return list<string>
     */
    private function expansionViolations(
        array $expansion,
        array $profile,
        string $contract,
        array $excludedNames,
    ): array {
        $violations = [];

        foreach (self::FOUNDATION_FIELDS as $field) {
            if (array_key_exists($field, $expansion)) {
                $violations[] = "{$field}: this step does not produce it";
            }
        }

        $shape = $this->collectionShapeViolations($expansion, $contract);

        if ($shape !== []) {
            return array_values(array_unique(array_merge($violations, $shape)));
        }

        return array_values(array_unique(array_merge(
            $violations,
            $this->forbiddenTermViolations($expansion, $profile),
            $this->identityViolations($expansion),
            $this->linkViolations($expansion, $profile),
            $this->placeLinkViolations($expansion, $contract),
            $this->stageViolations($expansion, $profile),
            $this->sceneRuleViolations($expansion, $profile, $contract),
            in_array($contract, self::LISTED_SUBJECT_CONTRACTS, true)
                ? $this->unlistedSubjectViolations($expansion)
                : [],
            $this->sourceFactsIn($this->sceneTexts($expansion, $contract), $excludedNames),
        )));
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @return list<string>
     */
    private function unlistedSubjectViolations(array $screenplay): array
    {
        $violations = [];

        foreach ($this->rowsOf($screenplay, 'scenes') as $scene) {
            if (! is_array($scene)) {
                continue;
            }

            $field = is_array($scene['subject_state'] ?? null) ? 'subject_state' : 'build_state';
            $subject = is_array($scene[$field] ?? null) ? ($scene[$field]['subject_id'] ?? null) : null;

            if (is_string($subject) && ! in_array($subject, (array) ($scene['character_ids'] ?? []), true)) {
                $violations[] = "{$this->sceneLabel($scene)}.{$field}.subject_id: {$subject} must be listed in character_ids";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $part
     * @param  array<string, mixed>  $profile
     * @param  list<string>  $excludedNames
     * @return list<string>
     */
    private function castViolations(
        array $part,
        array $profile,
        string $contract,
        array $excludedNames,
    ): array {
        $collection = self::CAST_CONTRACTS[$contract];
        $foreign = [];
        $violations = [];

        foreach ([...self::FOUNDATION_FIELDS, 'characters', 'locations', 'scenes', 'coverage'] as $field) {
            if ($field !== $collection && array_key_exists($field, $part)) {
                $foreign[] = "{$field}: this step does not produce it";
            }
        }

        $rows = $part[$collection] ?? null;

        if (! is_array($rows) || ! array_is_list($rows)) {
            return [...$foreign, "{$collection}: must be a list"];
        }

        if ($rows === []) {
            return [...$foreign, "{$collection}: must not be empty"];
        }

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $violations[] = "{$collection}[{$index}]: must be an object";

                continue;
            }

            $violations = array_merge(
                $violations,
                $this->fieldViolations(
                    $collection, "{$collection}[{$index}]", $row, $this->fieldTypes($collection, $contract), $contract,
                ),
                $this->placeRowViolations($collection, "{$collection}[{$index}]", $row, $contract),
            );
        }

        if ($violations !== []) {
            return array_values(array_unique([...$foreign, ...$violations]));
        }

        $violations = $foreign;
        $cast = ['characters' => [], 'locations' => [], $collection => $rows];
        $ids = array_column($rows, 'id');

        if (count($ids) !== count(array_unique($ids))) {
            $violations[] = "{$collection} carries duplicate ids";
        }

        if ($collection === 'characters') {
            $violations = array_merge(
                $violations,
                $this->identityViolations($cast),
                $this->forbiddenTermViolations($cast, $profile),
            );

            $asked = ProtagonistProfile::enabled($profile);
            $given = array_key_exists(ProtagonistProfile::OUTPUT_KEY, $part);

            if ($asked && ! $given) {
                $violations[] = ProtagonistProfile::OUTPUT_KEY.': the profile asks for it';
            }

            if (! $asked && $given) {
                $violations[] = ProtagonistProfile::OUTPUT_KEY.': this step does not produce it';
            }

            if ($asked && ProtagonistProfile::receiverIndex($rows) === null) {
                $violations[] = 'characters: exactly one protagonist of kind object receives the protagonist_profile';
            }

            if (ProtagonistProfile::protagonistOnly($profile)
                && (count($rows) !== 1 || ProtagonistProfile::receiverIndex($rows) === null)) {
                $violations[] = 'characters: this profile declares only the protagonist, one character of kind object, found '
                    .count($rows);
            }
        }

        return array_values(array_unique(array_merge(
            $violations,
            in_array($contract, self::BRIEF_SPACE_CONTRACTS, true) ? $this->briefSpaceViolations($rows, $profile) : [],
            in_array($contract, self::BRIEF_SPACE_CONTRACTS, true) ? $this->enclosureViolations($rows) : [],
            $this->subjectLimitViolations($cast, $profile),
            $this->sourceFactsIn($this->sceneTexts($cast, $contract), $excludedNames),
        )));
    }

    /**
     * @param  list<array<string, mixed>>  $locations
     * @return list<string>
     */
    private function enclosureViolations(array $locations): array
    {
        $violations = [];

        foreach ($locations as $index => $location) {
            $id = is_string($location['id'] ?? null) ? $location['id'] : "locations[{$index}]";

            if (! in_array($location[LocationProfile::ENCLOSURE_KEY] ?? null, LocationProfile::ENCLOSURES, true)) {
                $violations[] = "{$id}.enclosure: must be one of ".implode(', ', LocationProfile::ENCLOSURES);
            }
        }

        return $violations;
    }

    /**
     * @param  list<array<string, mixed>>  $locations
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    public function briefSpaceViolations(array $locations, array $profile): array
    {
        $spaces = FilmBrief::profileSpaces($profile);
        $claimed = [];
        $violations = [];

        foreach ($locations as $index => $location) {
            $id = is_string($location['id'] ?? null) ? $location['id'] : "locations[{$index}]";

            if (! array_key_exists(FilmBrief::COVERAGE_SPACE_KEY, $location)) {
                if ($spaces !== []) {
                    $violations[] = "{$id}.brief_space: is required, null when the place is no space of the project brief";
                }

                continue;
            }

            $space = $location[FilmBrief::COVERAGE_SPACE_KEY];

            if ($space === null) {
                continue;
            }

            if (! in_array($space, $spaces, true)) {
                $violations[] = "{$id}.brief_space: ".$this->label($space).' is not a space of the project brief';
            } elseif (isset($claimed[$space])) {
                $violations[] = "{$id}.brief_space: {$space} is already the place of {$claimed[$space]}";
            } else {
                $claimed[$space] = $id;
            }

            if (($location['spatial_relation'] ?? null) !== LocationProfile::SUBJECT_PART) {
                $violations[] = "{$id}.brief_space: a space of the project brief is part of the subject, so the location is subject_part";
            }
        }

        foreach (array_diff($spaces, array_keys($claimed)) as $missing) {
            $violations[] = "locations: the project brief space {$missing} has no location";
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $foundation
     * @param  list<string>  $excludedNames
     * @param  list<string>  $focus
     * @param  list<string>  $spaces
     * @return list<string>
     */
    public function protagonistProfileViolations(
        mixed $profile,
        array $foundation,
        array $excludedNames = [],
        array $focus = [],
        array $spaces = [],
    ): array {
        $shape = $this->protagonistProfileShapeViolations($profile);

        if ($shape !== []) {
            return $shape;
        }

        $texts = [];

        foreach (ProtagonistProfile::SECTIONS as $section) {
            $texts["protagonist_profile.{$section}"] = $profile[$section];
        }

        $interior = $this->interiorSpaceViolations($profile[ProtagonistProfile::INTERIOR_KEY] ?? null, $spaces);

        if ($interior !== []) {
            return $interior;
        }

        foreach ($profile[ProtagonistProfile::INTERIOR_KEY] ?? [] as $index => $room) {
            foreach (array_keys(ProtagonistProfile::INTERIOR_FIELDS) as $field) {
                $texts["protagonist_profile.interior_spaces[{$index}].{$field}"] = $room[$field];
            }
        }

        $names = [];

        foreach ($profile['signature_features'] as $index => $feature) {
            $names[] = mb_strtolower(trim($feature['name']));

            foreach (ProtagonistProfile::FEATURE_FIELDS as $field) {
                $texts["protagonist_profile.signature_features[{$index}].{$field}"] = $feature[$field];
            }
        }

        $violations = [];

        if (count($names) !== count(array_unique($names))) {
            $violations[] = 'protagonist_profile.signature_features: two features share a name';
        }

        $regions = array_column($profile['signature_features'], 'region');

        foreach ($focus as $region) {
            if (! in_array($region, $regions, true)) {
                $violations[] = "protagonist_profile.signature_features: the profile asks for a feature at the {$region}";
            }
        }

        foreach ($texts as $where => $text) {
            foreach (ProtagonistProfile::NOVELTY_CLAIMS as $pattern) {
                if (preg_match($pattern, $text, $match) === 1) {
                    $violations[] = "{$where} claims market novelty: \"".trim($match[0]).'"';

                    break;
                }
            }
        }

        return array_values(array_unique(array_merge(
            $violations,
            $this->figureViolations($profile['figures'], $foundation),
            $this->sourceFactsIn($texts, $excludedNames),
        )));
    }

    /**
     * @param  list<string>  $spaces
     * @return list<string>
     */
    private function interiorSpaceViolations(mixed $rooms, array $spaces): array
    {
        $at = ProtagonistProfile::OUTPUT_KEY.'.'.ProtagonistProfile::INTERIOR_KEY;

        if ($rooms === null) {
            return $spaces === [] ? [] : ["{$at}: the project brief lists spaces, so the profile describes each of them"];
        }

        if (! is_array($rooms) || ! array_is_list($rooms)) {
            return ["{$at}: must be a list"];
        }

        $violations = [];
        $seen = [];

        foreach ($rooms as $index => $room) {
            $where = "{$at}[{$index}]";

            if (! is_array($room)) {
                $violations[] = "{$where}: must be an object";

                continue;
            }

            $space = $room['space'] ?? null;

            if (! in_array($space, $spaces, true)) {
                $violations[] = "{$where}.space: ".$this->label($space).' is not a space of the project brief';
            } elseif (in_array($space, $seen, true)) {
                $violations[] = "{$where}.space: {$space} is already described";
            } else {
                $seen[] = $space;
            }

            foreach (ProtagonistProfile::INTERIOR_FIELDS as $field => [$min, $max]) {
                $violations = is_string($room[$field] ?? null)
                    ? array_merge($violations, $this->lengthViolations("{$where}.{$field}", $room[$field], $min, $max))
                    : [...$violations, "{$where}.{$field}: must be a string"];
            }
        }

        foreach (array_diff($spaces, $seen) as $missing) {
            $violations[] = "{$at}: the project brief space {$missing} is not described";
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function protagonistProfileShapeViolations(mixed $profile): array
    {
        $at = ProtagonistProfile::OUTPUT_KEY;

        if (! is_array($profile) || array_is_list($profile)) {
            return ["{$at}: must be an object"];
        }

        $violations = [];

        foreach (ProtagonistProfile::SECTIONS as $section) {
            $length = is_string($profile[$section] ?? null) ? mb_strlen(trim($profile[$section])) : null;

            if ($length === null || $length < ProtagonistProfile::SECTION_MIN || $length > ProtagonistProfile::SECTION_MAX) {
                $violations[] = "{$at}.{$section}: must be text of ".ProtagonistProfile::SECTION_MIN
                    .' to '.ProtagonistProfile::SECTION_MAX.' characters';
            }
        }

        $figures = $profile['figures'] ?? null;

        if (! is_array($figures) || ! array_is_list($figures) || $figures === []) {
            $violations[] = "{$at}.figures: must be a nonempty list";
        } else {
            foreach ($figures as $index => $figure) {
                if (! is_array($figure)) {
                    $violations[] = "{$at}.figures[{$index}]: must be an object";

                    continue;
                }

                foreach (ProtagonistProfile::FIGURE_FIELDS as $field) {
                    if (! is_string($figure[$field] ?? null)) {
                        $violations[] = "{$at}.figures[{$index}].{$field}: must be a string";
                    }
                }

                if (mb_strlen((string) ($figure['note'] ?? '')) > ProtagonistProfile::NOTE_MAX) {
                    $violations[] = "{$at}.figures[{$index}].note: at most ".ProtagonistProfile::NOTE_MAX.' characters';
                }
            }
        }

        $features = $profile['signature_features'] ?? null;

        if (! is_array($features) || ! array_is_list($features)) {
            $violations[] = "{$at}.signature_features: must be a list";

            return $violations;
        }

        if (count($features) < ProtagonistProfile::MIN_FEATURES || count($features) > ProtagonistProfile::MAX_FEATURES) {
            $violations[] = "{$at}.signature_features: must hold ".ProtagonistProfile::MIN_FEATURES
                .' to '.ProtagonistProfile::MAX_FEATURES.' features, found '.count($features);
        }

        foreach ($features as $index => $feature) {
            if (! is_array($feature)) {
                $violations[] = "{$at}.signature_features[{$index}]: must be an object";

                continue;
            }

            foreach (ProtagonistProfile::FEATURE_FIELDS as $field) {
                $length = is_string($feature[$field] ?? null) ? mb_strlen(trim($feature[$field])) : null;

                if ($length === null || $length === 0 || $length > ProtagonistProfile::FEATURE_FIELD_MAX) {
                    $violations[] = "{$at}.signature_features[{$index}].{$field}: must be text of 1 to "
                        .ProtagonistProfile::FEATURE_FIELD_MAX.' characters';
                }
            }

            $allowed = [
                'origin' => ProtagonistProfile::FEATURE_ORIGINS,
                'kind' => ProtagonistProfile::FEATURE_KINDS,
                'region' => ProtagonistProfile::REGIONS,
            ];

            foreach ($allowed as $field => $values) {
                if (! in_array($feature[$field] ?? null, $values, true)) {
                    $violations[] = "{$at}.signature_features[{$index}].{$field}: must be one of ".implode(', ', $values);
                }
            }
        }

        return $violations;
    }

    /**
     * @param  list<array<string, string>>  $figures
     * @param  array<string, mixed>  $foundation
     * @return list<string>
     */
    private function figureViolations(array $figures, array $foundation): array
    {
        $violations = [];
        $seen = [];

        foreach ($figures as $index => $figure) {
            $at = "protagonist_profile.figures[{$index}]";
            $quantity = $figure['quantity'];
            $value = trim($figure['value']);
            $path = trim($figure['source_path']);

            if (! array_key_exists($quantity, ProtagonistProfile::QUANTITIES)) {
                $violations[] = "{$at}.quantity: {$quantity} is not a known quantity";

                continue;
            }

            if (isset($seen[$quantity])) {
                $violations[] = "{$at}.quantity: {$quantity} is listed more than once";
            }

            $seen[$quantity] = $figure['origin'];

            if ($figure['unit'] !== ProtagonistProfile::QUANTITIES[$quantity]) {
                $violations[] = "{$at}.unit: {$quantity} is measured in ".ProtagonistProfile::QUANTITIES[$quantity].", not {$figure['unit']}";
            }

            if ($figure['origin'] !== 'undetermined') {
                $valueViolation = $this->figureValueViolation($quantity, $value);

                if ($valueViolation !== null) {
                    $violations[] = "{$at}.value: {$valueViolation}";

                    continue;
                }
            }

            switch ($figure['origin']) {
                case 'inherited':
                    $source = $this->foundationValue($foundation, $path);

                    if ((ProtagonistProfile::INHERITED[$path] ?? null) !== $quantity) {
                        $violations[] = "{$at}.source_path: {$quantity} is not inherited from ".($path === '' ? 'an empty path' : $path);
                    } elseif (! is_int($source) && ! is_float($source)) {
                        $violations[] = "{$at}.source_path: {$path} holds no figure in the foundation";
                    } elseif ((float) $value !== (float) $source) {
                        $violations[] = "{$at}.value: {$value} differs from {$path} ({$source})";
                    }

                    break;
                case 'proposed':
                    if ($path !== '') {
                        $violations[] = "{$at}.source_path: a proposed figure has no source_path";
                    }

                    break;
                case 'undetermined':
                    if ($value !== '') {
                        $violations[] = "{$at}.value: an undetermined figure leaves the value empty";
                    }

                    if ($path !== '') {
                        $violations[] = "{$at}.source_path: an undetermined figure has no source_path";
                    }

                    break;
                default:
                    $violations[] = "{$at}.origin: must be one of ".implode(', ', ProtagonistProfile::ORIGINS);
            }
        }

        foreach (ProtagonistProfile::INHERITED as $path => $quantity) {
            $source = $this->foundationValue($foundation, $path);

            if ((is_int($source) || is_float($source)) && ($seen[$quantity] ?? null) !== 'inherited') {
                $violations[] = "protagonist_profile.figures: {$quantity} must be inherited from {$path}";
            }
        }

        return $violations;
    }

    private function figureValueViolation(string $quantity, string $value): ?string
    {
        if (! is_numeric($value)) {
            return "{$value} is not a number";
        }

        $number = (float) $value;

        if (! is_finite($number)) {
            return "{$value} is not a finite number";
        }

        if (! array_key_exists($quantity, ProtagonistProfile::COUNT_MINIMUMS)) {
            return $number > 0 ? null : "{$quantity} must be greater than 0";
        }

        if (preg_match('/^\d+$/', $value) !== 1) {
            return "{$quantity} is a count and must be a whole number, not {$value}";
        }

        $minimum = ProtagonistProfile::COUNT_MINIMUMS[$quantity];

        return (int) $value >= $minimum ? null : "{$quantity} must be at least {$minimum}";
    }

    /**
     * @param  array<string, mixed>  $foundation
     */
    private function foundationValue(array $foundation, string $path): mixed
    {
        $node = $foundation;

        foreach ($path === '' ? [] : explode('.', $path) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return $path === '' ? null : $node;
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    private function sceneRuleViolations(array $screenplay, array $profile, string $contract): array
    {
        $beats = in_array($contract, self::BEAT_CONTRACTS, true);

        return array_merge(
            $this->subjectLimitViolations($screenplay, $profile),
            $this->speakerViolations($screenplay),
            $beats ? $this->subjectStateViolations($screenplay, $profile) : $this->buildStateViolations($screenplay),
            $beats ? $this->beatViolations($screenplay) : [],
            $this->coverageViolations($screenplay, $profile),
        );
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @return list<string>
     */
    private function beatViolations(array $screenplay): array
    {
        $violations = [];

        foreach ($this->rowsOf($screenplay, 'scenes') as $scene) {
            if (! is_array($scene)) {
                continue;
            }

            $id = $this->sceneLabel($scene);
            $beats = $scene['beats'] ?? null;

            if (! is_array($beats) || ! array_is_list($beats) || $beats === []) {
                $violations[] = "{$id}.beats: must be a nonempty list";

                continue;
            }

            $mode = $scene['scene_mode'] ?? null;

            if ($mode === SceneBeats::MONTAGE && count($beats) < 2) {
                $violations[] = "{$id}.beats: a montage carries at least two beats";
            }

            foreach ($beats as $index => $beat) {
                $where = "{$id}.beats[{$index}]";

                if (! is_array($beat)) {
                    $violations[] = "{$where}: must be an object";

                    continue;
                }

                foreach (array_diff(array_keys($beat), ['id', 'action', 'visible_result', 'time_jump']) as $extra) {
                    $violations[] = "{$where}.{$extra}: is not part of the contract";
                }

                if (($beat['id'] ?? null) !== 'b'.($index + 1)) {
                    $violations[] = "{$where}.id: must be b".($index + 1).', beats are numbered in order from b1';
                }

                foreach (self::BEAT_TEXT_LIMITS as $field => [$min, $max]) {
                    $violations = is_string($beat[$field] ?? null)
                        ? array_merge($violations, $this->lengthViolations("{$where}.{$field}", $beat[$field], $min, $max))
                        : [...$violations, "{$where}.{$field}: must be a string"];
                }

                if (! array_key_exists('time_jump', $beat)) {
                    $violations[] = "{$where}.time_jump: is required, null when no time passes";

                    continue;
                }

                $jump = $beat['time_jump'];

                if ($jump !== null && (! is_string($jump) || trim($jump) === '' || mb_strlen($jump) > 200)) {
                    $violations[] = "{$where}.time_jump: must be null or text of at most 200 characters";
                } elseif ($jump !== null && $index === 0) {
                    $violations[] = "{$where}.time_jump: the first beat opens the scene and carries no time jump";
                } elseif ($jump !== null && $mode !== SceneBeats::MONTAGE) {
                    $violations[] = "{$where}.time_jump: only a montage jumps in time between beats";
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    private function subjectStateViolations(array $screenplay, array $profile = []): array
    {
        $components = is_array($profile[VesselDesign::CONFIGURATION_COMPONENTS_KEY] ?? null)
            ? array_column($profile[VesselDesign::CONFIGURATION_COMPONENTS_KEY], 'component_id')
            : null;
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

            if (! array_key_exists('subject_state', $scene)) {
                $violations[] = "{$id}.subject_state: must be present, null only when the subject is not shown";

                continue;
            }

            $state = $scene['subject_state'];

            if ($state === null) {
                continue;
            }

            if (! is_array($state) || array_is_list($state)) {
                $violations[] = "{$id}.subject_state: must be an object or null";

                continue;
            }

            foreach (array_diff(array_keys($state), ['subject_id', 'start', 'end']) as $extra) {
                $violations[] = "{$id}.subject_state.{$extra}: is not part of the contract";
            }

            if (! in_array($state['subject_id'] ?? null, $objects, true)) {
                $violations[] = "{$id}.subject_state.subject_id: must name a declared character whose kind is object";
            }

            foreach (['start', 'end'] as $side) {
                $violations = array_merge(
                    $violations,
                    $this->subjectMomentViolations("{$id}.subject_state.{$side}", $state[$side] ?? null),
                );

                if ($components === null || ! is_array($state[$side]['configuration'] ?? null)) {
                    continue;
                }

                foreach ($state[$side]['configuration'] as $index => $item) {
                    $part = is_array($item) ? ($item['part'] ?? null) : null;

                    if (is_string($part) && ! in_array(trim($part), $components, true)) {
                        $violations[] = "{$id}.subject_state.{$side}.configuration[{$index}].part: ".$this->label($part)
                            .' is not a component_id of the design\'s configuration states';
                    }
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function subjectMomentViolations(string $where, mixed $moment, bool $progressRequired = true): array
    {
        if (! is_array($moment) || array_is_list($moment)) {
            return ["{$where}: must be an object with progress and configuration"];
        }

        $violations = [];

        foreach (array_diff(array_keys($moment), ['progress', 'configuration']) as $extra) {
            $violations[] = "{$where}.{$extra}: is not part of the contract";
        }

        $progress = $moment['progress'] ?? null;

        if (is_string($progress)) {
            $violations = array_merge($violations, $this->lengthViolations("{$where}.progress", $progress, ...self::PROGRESS_LIMITS));
        } elseif ($progress !== null || $progressRequired) {
            $violations[] = "{$where}.progress: must be ".($progressRequired ? 'a string' : 'a string or null');
        }

        $configuration = $moment['configuration'] ?? null;

        if (! is_array($configuration) || ! array_is_list($configuration)) {
            return [...$violations, "{$where}.configuration: must be a list"];
        }

        $parts = [];

        foreach ($configuration as $index => $item) {
            $at = "{$where}.configuration[{$index}]";

            if (! is_array($item)) {
                $violations[] = "{$at}: must be an object";

                continue;
            }

            foreach (array_diff(array_keys($item), ['part', 'state']) as $extra) {
                $violations[] = "{$at}.{$extra}: is not part of the contract";
            }

            foreach (self::CONFIGURATION_LIMITS as $field => [$min, $max]) {
                $violations = is_string($item[$field] ?? null)
                    ? array_merge($violations, $this->lengthViolations("{$at}.{$field}", $item[$field], $min, $max))
                    : [...$violations, "{$at}.{$field}: must be a string"];
            }

            $part = is_string($item['part'] ?? null) ? trim($item['part']) : null;

            if ($part !== null && isset($parts[$part])) {
                $violations[] = "{$at}.part: {$part} is already listed";
            }

            if ($part !== null) {
                $parts[$part] = true;
            }
        }

        return $violations;
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

        return array_merge($violations, $this->collectionShapeViolations($screenplay, $contract));
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @return list<string>
     */
    private function collectionShapeViolations(array $screenplay, string $contract): array
    {
        $violations = [];
        $collections = ['characters', 'locations', 'scenes'];

        if (in_array($contract, self::SCENE_CONTRACTS, true)) {
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
                    $this->fieldViolations($key, $path, $row, $this->fieldTypes($key, $contract), $contract),
                    $this->placeRowViolations($key, $path, $row, $contract),
                );

                if ($key === 'characters' && array_key_exists(ProtagonistProfile::CHARACTER_KEY, $row)
                    && (! is_array($row[ProtagonistProfile::CHARACTER_KEY])
                        || ($row['role'] ?? null) !== 'protagonist'
                        || ($row['kind'] ?? null) !== 'object')) {
                    $violations[] = "{$path}.profile: only the protagonist of kind object carries a profile object";
                }

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

    /** @return array<string, string> */
    private function fieldTypes(string $collection, string $contract): array
    {
        $types = self::FIELD_TYPES[$collection];

        if (in_array($contract, self::PLACE_CONTRACTS, true)) {
            $types += self::PLACE_FIELD_TYPES[$collection] ?? [];
        }

        if (in_array($contract, self::BEAT_CONTRACTS, true)) {
            $types += self::BEAT_FIELD_TYPES[$collection] ?? [];
        }

        return $types;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function placeRowViolations(string $collection, string $path, array $row, string $contract): array
    {
        if (! in_array($contract, self::PLACE_CONTRACTS, true) || ! isset(self::PLACE_FIELD_TYPES[$collection])) {
            return [];
        }

        $violations = [];

        foreach (self::PLACE_TEXT_LIMITS as $key => [$min, $max]) {
            [$owner, $field] = explode('.', $key);

            if ($owner === $collection && is_string($row[$field] ?? null)) {
                $violations = array_merge($violations, $this->lengthViolations("{$path}.{$field}", $row[$field], $min, $max));
            }
        }

        foreach (self::PLACE_ITEM_LIMITS as $key => [$min, $max]) {
            [$owner, $field] = explode('.', $key);
            $items = $row[$field] ?? null;

            if ($owner !== $collection || ! is_array($items) || ! array_is_list($items)) {
                continue;
            }

            foreach ($items as $index => $item) {
                $violations = is_string($item)
                    ? array_merge($violations, $this->lengthViolations("{$path}.{$field}[{$index}]", $item, $min, $max))
                    : [...$violations, "{$path}.{$field}[{$index}]: must be a string"];
            }
        }

        $connections = $collection === 'locations' ? ($row['connections'] ?? null) : null;

        if (is_array($connections) && array_is_list($connections)) {
            foreach ($connections as $index => $connection) {
                $where = "{$path}.connections[{$index}]";

                if (! is_array($connection)) {
                    $violations[] = "{$where}: must be an object";

                    continue;
                }

                foreach (array_diff(array_keys($connection), ['target_location_id', 'via']) as $extra) {
                    $violations[] = "{$where}.{$extra}: is not part of the contract";
                }

                if (! is_string($connection['target_location_id'] ?? null)) {
                    $violations[] = "{$where}.target_location_id: must be a string";
                }

                if (! is_string($connection['via'] ?? null)) {
                    $violations[] = "{$where}.via: must be a string";
                } else {
                    $violations = array_merge(
                        $violations,
                        $this->lengthViolations("{$where}.via", $connection['via'], ...self::CONNECTION_VIA_LIMITS),
                    );
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function lengthViolations(string $where, string $value, int $min, int $max): array
    {
        $length = mb_strlen(trim($value));

        if ($length < $min) {
            return ["{$where}: must hold at least {$min} characters, holds {$length}"];
        }

        return $length > $max ? ["{$where}: must hold at most {$max} characters, holds {$length}"] : [];
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @return list<string>
     */
    private function placeLinkViolations(array $screenplay, string $contract): array
    {
        if (! in_array($contract, self::PLACE_CONTRACTS, true)) {
            return [];
        }

        return $this->locationLinkViolations(
            $this->rowsOf($screenplay, 'locations'),
            $this->rowsOf($screenplay, 'characters'),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $locations
     * @param  list<array<string, mixed>>  $characters
     * @return list<string>
     */
    public function locationLinkViolations(array $locations, array $characters): array
    {
        $violations = [];
        $locationIds = array_column(array_filter($locations, 'is_array'), 'id');
        $objects = [];

        foreach ($characters as $character) {
            if (is_array($character) && ($character['kind'] ?? null) === 'object' && is_string($character['id'] ?? null)) {
                $objects[] = $character['id'];
            }
        }

        foreach ($locations as $index => $location) {
            if (! is_array($location)) {
                continue;
            }

            $id = is_string($location['id'] ?? null) ? $location['id'] : "locations[{$index}]";
            $relation = $location['spatial_relation'] ?? null;
            $subject = $location['subject_id'] ?? null;

            if ($relation === LocationProfile::EXTERNAL && $subject !== null) {
                $violations[] = "{$id}.subject_id: an external location belongs to no subject and must be null";
            }

            if ($relation === LocationProfile::SUBJECT_PART && ! in_array($subject, $objects, true)) {
                $violations[] = "{$id}.subject_id: a subject_part location must name a declared character of kind object, names "
                    .$this->label($subject);
            }

            $targets = [];

            foreach ($this->rowsOf($location, 'connections') as $position => $connection) {
                $target = is_array($connection) ? ($connection['target_location_id'] ?? null) : null;

                if ($target === $id) {
                    $violations[] = "{$id}.connections[{$position}]: a location does not connect to itself";
                } elseif (! in_array($target, $locationIds, true)) {
                    $violations[] = "{$id}.connections[{$position}]: target ".$this->label($target).' is not a declared location';
                } elseif (isset($targets[$target])) {
                    $violations[] = "{$id}.connections[{$position}]: {$target} is already connected";
                }

                if (is_string($target)) {
                    $targets[$target] = true;
                }
            }
        }

        return $violations;
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
        if (! in_array($profile['contract_version'] ?? null, self::SCENE_CONTRACTS, true)) {
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
        $spaceOf = [];
        $offScreen = [];

        foreach ($this->rowsOf($profile, 'coverage') as $item) {
            if (is_array($item) && is_string($item['id'] ?? null)) {
                $expected[$item['id']] = $item['level'] ?? null;

                if (is_string($item[FilmBrief::COVERAGE_SPACE_KEY] ?? null)) {
                    $spaceOf[$item['id']] = $item[FilmBrief::COVERAGE_SPACE_KEY];
                }

                if (($item[FilmBrief::COVERAGE_OFF_SCREEN_KEY] ?? false) === true) {
                    $offScreen[$item['id']] = true;
                }
            }
        }

        $placeSpace = [];

        foreach ($this->rowsOf($screenplay, 'locations') as $location) {
            if (is_array($location) && is_string($location['id'] ?? null)) {
                $placeSpace[$location['id']] = $location[FilmBrief::COVERAGE_SPACE_KEY] ?? null;
            }
        }

        $sceneSpace = [];

        foreach ($this->rowsOf($screenplay, 'scenes') as $scene) {
            if (is_array($scene) && is_string($scene['id'] ?? null)) {
                $sceneSpace[$scene['id']] = $placeSpace[$scene['location_id'] ?? ''] ?? null;
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

            if ($mode === 'shown' && isset($offScreen[$id])) {
                $violations[] = "{$path}.mode: the project brief leaves {$id} off screen, so it is never shown";
            }

            if ($mode === 'shown' && isset($spaceOf[$id]) && ! in_array($spaceOf[$id], array_map(
                static fn (mixed $sceneId): mixed => is_string($sceneId) ? ($sceneSpace[$sceneId] ?? null) : null,
                $sceneIds,
            ), true)) {
                $violations[] = "{$path}.scene_ids: {$id} is shown only by a scene at the location of {$spaceOf[$id]}";
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

        return $texts + $this->sceneTexts($screenplay, $contract);
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @return array<string, string>
     */
    private function sceneTexts(array $screenplay, string $contract): array
    {
        $texts = [];

        foreach ($screenplay['characters'] ?? [] as $character) {
            foreach (['name', 'description', 'personality', 'appearance'] as $key) {
                $texts["{$character['id']}.{$key}"] = (string) ($character[$key] ?? '');
            }
        }

        $placed = in_array($contract, self::PLACE_CONTRACTS, true);

        foreach ($screenplay['locations'] ?? [] as $location) {
            foreach ($placed ? ['name', 'description', 'story_use', 'layout'] : ['name', 'description'] as $key) {
                $texts["{$location['id']}.{$key}"] = (string) ($location[$key] ?? '');
            }

            if (! $placed) {
                continue;
            }

            foreach ($this->rowsOf($location, 'connections') as $index => $connection) {
                $texts["{$location['id']}.connections[{$index}].via"] = is_array($connection) ? (string) ($connection['via'] ?? '') : '';
            }

            foreach (['fixed_features', 'light_sources'] as $key) {
                foreach ($this->rowsOf($location, $key) as $index => $item) {
                    $texts["{$location['id']}.{$key}[{$index}]"] = is_string($item) ? $item : '';
                }
            }
        }

        foreach ($screenplay['scenes'] ?? [] as $scene) {
            $id = (string) ($scene['id'] ?? '?');
            $texts["{$id}.action"] = (string) ($scene['action'] ?? '');

            if ($placed) {
                $texts["{$id}.light_and_weather"] = (string) ($scene['light_and_weather'] ?? '');

                foreach ($this->rowsOf($scene, 'props') as $index => $item) {
                    $texts["{$id}.props[{$index}]"] = is_string($item) ? $item : '';
                }
            }

            $texts["{$id}.sound"] = (string) ($scene['sound'] ?? '');
            $texts["{$id}.why_it_cannot_be_cut"] = (string) ($scene['why_it_cannot_be_cut'] ?? '');
            $texts["{$id}.leads_to"] = (string) ($scene['leads_to'] ?? '');

            foreach ($scene['dialogue'] ?? [] as $index => $line) {
                $texts["{$id}.dialogue[{$index}]"] = (string) ($line['line'] ?? '');
            }

            if (in_array($contract, self::SCENE_CONTRACTS, true) && is_array($scene['build_state'] ?? null)) {
                $texts["{$id}.build_state.state"] = (string) ($scene['build_state']['state'] ?? '');
            }

            if (! in_array($contract, self::BEAT_CONTRACTS, true)) {
                continue;
            }

            foreach ($this->rowsOf($scene, 'beats') as $index => $beat) {
                foreach (['action', 'visible_result', 'time_jump'] as $key) {
                    $texts["{$id}.beats[{$index}].{$key}"] = is_array($beat) && is_string($beat[$key] ?? null) ? $beat[$key] : '';
                }
            }

            $state = is_array($scene['subject_state'] ?? null) ? $scene['subject_state'] : [];

            foreach (['start', 'end'] as $side) {
                $moment = is_array($state[$side] ?? null) ? $state[$side] : [];
                $texts["{$id}.subject_state.{$side}.progress"] = is_string($moment['progress'] ?? null) ? $moment['progress'] : '';

                foreach ($this->rowsOf($moment, 'configuration') as $index => $item) {
                    $texts["{$id}.subject_state.{$side}.configuration[{$index}]"] = is_array($item)
                        ? trim((string) ($item['part'] ?? '').' '.(string) ($item['state'] ?? ''))
                        : '';
                }
            }
        }

        if (in_array($contract, self::SCENE_CONTRACTS, true)) {
            foreach ($this->rowsOf($screenplay, 'coverage') as $index => $item) {
                if (is_array($item)) {
                    $texts["coverage[{$index}].evidence"] = (string) ($item['evidence'] ?? '');
                }
            }
        }

        return $texts;
    }
}
