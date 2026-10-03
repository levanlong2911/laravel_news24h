<?php

declare(strict_types=1);

namespace App\Video\Screenplay;

use App\Models\VideoProject;
use App\Services\Video\ScreenplayExpansionService;

final class VesselDesign
{
    public const WORKFLOW_KEY = 'workflow';

    public const WORKFLOW = 'design_first_v1';

    public const CONTRACT = 'vessel_design_v1';

    public const STORY_CONTRACT = 'screenplay_foundation_story_v1';

    public const FOUNDATION_CONTRACT = 'screenplay_foundation_v4';

    public const SOURCE_KIND = 'vessel_design';

    public const VESSEL_KEY = 'vessel';

    public const VESSEL_ID = 'ch_vessel';

    public const LOCK_KEY = 'design_anchor';

    public const SOURCE_DESIGN_KEY = 'source_design';

    public const SOURCE_ANCHOR_KEY = 'source_anchor';

    public const AUTHOR_MODEL = 'application';

    public const SUBJECT_PREFIX = 'vd_';

    public const CANONICAL_KEY = 'canonical_design';

    public const CONFIGURATION_COMPONENTS_KEY = 'configuration_components';

    public const DESIGN_GEOMETRY_KEY = 'design_geometry';

    public const PREVIOUS_DESIGNS_KEY = 'previous_designs';

    public const PREVIOUS_DESIGN_LIMIT = 8;

    public const SCREENPLAY_DEPENDENCY_KEY = 'screenplay_dependency';

    /** @var array<string, array{0: int, 1: int}> */
    public const VESSEL_FIELDS = [
        'name' => [2, 60],
        'description' => [20, 600],
        'appearance' => [20, 800],
    ];

    /** @var list<string> */
    public const CONTENT_KEYS = ['vessel', 'design_thesis', 'principal_dimensions', ProtagonistProfile::OUTPUT_KEY];

    /** @var list<string> */
    public const FIXED_FOUNDATION_KEYS = ['design_thesis', 'principal_dimensions'];

    /** @var list<string> */
    public const STORY_KEYS = ['logline', 'premise', 'synopsis', 'stage_treatments', 'ending'];

    /** @var list<string> */
    public const GATE_CHECKS = [
        'design_thesis_defined',
        'novelty_thesis_defined',
        'principal_dimensions_defined',
        'length_beam_ratio_defined',
        'global_silhouette_defined',
        'governing_lines_defined',
        'bow_topology_defined',
        'stern_topology_defined',
        'primary_masses_defined',
        'primary_voids_defined',
        'signature_geometry_defined',
        'major_topology_relationships_complete',
        'major_transitions_defined',
        'configuration_state_separated_from_permanent_geometry',
        'P0_must_preserve_defined',
        'design_specific_forbidden_interpretations_defined',
        'reference_proof_requirements_defined',
    ];

    /** @var list<string> */
    public const RESERVED_IDS = ['hull', 'waterline'];

    private const LOCKED = 'locked';

    private const UNDETERMINED = 'undetermined';

    /** @var list<string> */
    private const DESIGN_PROFILE_KEYS = [
        'design_contract_version', 'subject_class', 'objective', 'originality', 'identity_dimensions', 'design_requirements',
        'concept_forbidden_terms', ProtagonistProfile::PROFILE_FLAG, ProtagonistProfile::FOCUS_KEY,
        'design_development', 'novelty_requirements', 'canonical_design_contract', 'coordinate_and_orientation',
        'form_system_requirements', 'proportion_requirements', 'geometry_requirements', 'topology_requirements',
        'geometric_relationship_requirements', 'transition_requirements', 'signature_geometry_requirements',
        'configuration_state_requirements', 'anti_regression', 'physical_credibility_policy',
        'design_completeness_gate', 'reference_proof_policy',
    ];

    /** @var list<string> */
    private const DESIGN_RULE_KEYS = [
        'design_contract_version', 'design_development', 'novelty_requirements', 'canonical_design_contract',
        'coordinate_and_orientation', 'form_system_requirements', 'proportion_requirements', 'geometry_requirements',
        'topology_requirements', 'geometric_relationship_requirements', 'transition_requirements',
        'signature_geometry_requirements', 'anti_regression', 'physical_credibility_policy',
        'design_completeness_gate', 'design_freeze_policy', 'reference_proof_policy', 'prompt_compilation_policy',
    ];

    /** @var list<string> */
    private const DESIGN_BRIEF_FIELDS = ['spaces', 'highlights', 'limits'];

    /** @var list<string> */
    private const THESIS_FIELDS = ['central_idea', 'visible_difference', 'spatial_consequence', 'coherence', 'realization'];

    /** @var array<string, list<string>> */
    private const SECTION_FIELDS = [
        'design_identity' => ['novelty_thesis', 'conventional_patterns', 'replacement_principle', 'superyacht_reading'],
        'proportion_system' => ['overall', 'masses', 'signature_regions'],
        'global_silhouette' => ['profile_view', 'plan_view', 'silhouette_test'],
        'hull_geometry' => ['stem_and_bow', 'sheer', 'freeboard_distribution', 'midbody', 'stern_and_transom', 'hull_superstructure_relationship'],
        'superstructure_geometry' => ['primary_mass_organization', 'forward_to_aft_massing', 'rooflines', 'setbacks_or_transitions', 'relationship_to_hull'],
        'bow_geometry' => ['stem_type', 'rake_or_plumb', 'bow_volume', 'relationship_to_forward_superstructure'],
        'stern_geometry' => ['aft_silhouette', 'transom_closure', 'relationship_to_waterline', 'relationship_to_superstructure'],
    ];

    /** @var list<string> */
    private const CORE_SECTIONS = [
        'design_identity', 'proportion_system', 'global_silhouette', 'hull_geometry',
        'superstructure_geometry', 'bow_geometry', 'stern_geometry',
    ];

    /** @var list<string> */
    private const SCREENPLAY_EXCLUDED_SECTIONS = [
        'design_identity', 'must_not_introduce', 'reference_proof_requirements', 'configuration_states',
    ];

    /** @var array<string, string> */
    private const ID_LISTS = [
        'governing_lines' => 'id',
        'primary_masses' => 'id',
        'primary_voids' => 'id',
        'spatial_topology' => 'id',
        'permanent_secondary_geometry' => 'id',
        'signature_regions' => 'feature_id',
        'configuration_states' => 'component_id',
    ];

    /** @var list<string> */
    private const ANCHOR_OBJECTS = [
        'design_identity', 'proportion_system', 'global_silhouette', 'hull_geometry',
        'superstructure_geometry', 'stern_geometry', 'bow_geometry',
    ];

    /** @var list<string> */
    private const ANCHOR_LISTS = [
        'governing_lines', 'primary_masses', 'primary_voids', 'signature_regions', 'spatial_topology',
        'geometric_relationships', 'transitions', 'permanent_secondary_geometry',
    ];

    /** @var list<string> */
    private const ANCHOR_CONFIGURATION_FIELDS = ['component_id', 'feature_id', 'fixed_geometry', 'canonical_state'];

    /** @var list<string> */
    private const ANCHOR_POLICY_FIELDS = ['include_for_identity_anchor', 'exclude_unless_visually_relevant', 'conflict_priority'];

    public static function isDesignFirst(?VideoProject $project): bool
    {
        return $project !== null
            && ($project->metadata_json[self::WORKFLOW_KEY] ?? config('video.screenplay.workflow')) === self::WORKFLOW;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public static function content(array $output): array
    {
        $content = [];

        foreach (self::CONTENT_KEYS as $key) {
            $content[$key] = $output[$key] ?? null;
        }

        if (self::hasCanonical($output)) {
            $content[self::CANONICAL_KEY] = $output[self::CANONICAL_KEY];
        }

        return $content;
    }

    /** @param array<string, mixed> $output */
    public static function contentHash(array $output): string
    {
        return ScreenplayExpansionService::contentHash(self::content($output));
    }

    /** @param array<string, mixed> $output */
    public static function hasCanonical(array $output): bool
    {
        return is_array($output[self::CANONICAL_KEY] ?? null) && $output[self::CANONICAL_KEY] !== [];
    }

    public static function subjectKey(string $designStageId): string
    {
        return self::SUBJECT_PREFIX.substr(hash('sha256', $designStageId), 0, 40);
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public static function vesselRow(array $output): array
    {
        $vessel = is_array($output[self::VESSEL_KEY] ?? null) ? $output[self::VESSEL_KEY] : [];

        return [
            'id' => self::VESSEL_ID,
            'name' => (string) ($vessel['name'] ?? ''),
            'role' => 'protagonist',
            'kind' => 'object',
            'description' => (string) ($vessel['description'] ?? ''),
            'personality' => null,
            'appearance' => (string) ($vessel['appearance'] ?? ''),
            ProtagonistProfile::CHARACTER_KEY => $output[ProtagonistProfile::OUTPUT_KEY] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public static function designShape(array $profile): array
    {
        $shape = array_intersect_key($profile, array_flip(self::DESIGN_PROFILE_KEYS));
        $shape['contract_version'] = self::CONTRACT;
        $shape['dimension_bounds'] = config('video.screenplay.foundation.dimension_bounds');

        return $shape;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public static function downstreamProfile(array $profile): array
    {
        $kept = array_diff_key($profile, array_flip(self::DESIGN_RULE_KEYS));
        $dependency = $profile['design_development']['screenplay_dependency'] ?? null;

        if (is_string($dependency) && trim($dependency) !== '') {
            $kept[self::SCREENPLAY_DEPENDENCY_KEY] = $dependency;
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>|null  $brief
     * @return array<string, mixed>|null
     */
    public static function designBrief(?array $brief): ?array
    {
        if ($brief === null) {
            return null;
        }

        $kept = ['format' => $brief['format'] ?? null, 'revision' => $brief['revision'] ?? null];

        foreach (self::DESIGN_BRIEF_FIELDS as $field) {
            $kept[$field] = $brief[$field] ?? [];
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    public static function profileRuleViolations(array $profile, ?string $expectedVersion): array
    {
        $violations = [];
        $version = $profile['design_contract_version'] ?? null;

        if ($version !== null && $expectedVersion !== null && $version !== $expectedVersion) {
            $violations[] = 'design_contract_version: '.(is_string($version) ? $version : 'value')
                ." is not the configured {$expectedVersion}";
        }

        $checks = $profile['design_completeness_gate']['checks'] ?? null;

        if ($checks !== null && (! is_array($checks) || ! array_is_list($checks))) {
            return [...$violations, 'design_completeness_gate.checks: must be a list'];
        }

        foreach ((array) $checks as $index => $check) {
            if (! in_array($check, self::GATE_CHECKS, true)) {
                $violations[] = "design_completeness_gate.checks[{$index}]: no rule implements this check";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    public static function gateChecks(array $profile): array
    {
        $checks = $profile['design_completeness_gate']['checks'] ?? null;

        return is_array($checks) && $checks !== []
            ? array_values(array_filter($checks, static fn (mixed $check): bool => in_array($check, self::GATE_CHECKS, true)))
            : self::GATE_CHECKS;
    }

    /**
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>  $profile
     * @return array<string, list<string>>
     */
    public static function gate(array $design, array $profile): array
    {
        $canonical = is_array($design[self::CANONICAL_KEY] ?? null) ? $design[self::CANONICAL_KEY] : [];
        $results = [];

        foreach (self::gateChecks($profile) as $check) {
            $results[$check] = self::checkViolations($check, $design, $canonical);
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    public static function gateViolations(array $design, array $profile): array
    {
        $violations = [];

        foreach (self::gate($design, $profile) as $check => $found) {
            foreach ($found as $violation) {
                $violations[] = "completeness gate {$check}: {$violation}";
            }
        }

        return $violations;
    }

    /** @param array<string, mixed> $design */
    public static function lengthBeamRatio(array $design): ?float
    {
        $length = $design['principal_dimensions']['length_m'] ?? null;
        $beam = $design['principal_dimensions']['beam_m'] ?? null;

        if (! is_int($length) && ! is_float($length)) {
            return null;
        }

        if (! is_int($beam) && ! is_float($beam)) {
            return null;
        }

        return $length > 0 && $beam > 0 ? round($length / $beam, 2) : null;
    }

    /**
     * @param  array<string, mixed>  $design
     * @return list<array{id: string, statement: string}>
     */
    public static function p0(array $design): array
    {
        $canonical = is_array($design[self::CANONICAL_KEY] ?? null) ? $design[self::CANONICAL_KEY] : [];
        $rows = [];

        foreach (self::rows($canonical['must_preserve'] ?? null) as $row) {
            if (($row['priority'] ?? null) === 'P0') {
                $rows[] = ['id' => (string) ($row['id'] ?? ''), 'statement' => (string) ($row['statement'] ?? '')];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return list<string>|null
     */
    public static function relationEnum(array $profile): ?array
    {
        $allowed = $profile['coordinate_and_orientation']['allowed_relationship_language'] ?? null;
        $types = $profile['geometric_relationship_requirements']['relationship_types'] ?? null;

        if (! is_array($allowed) || ! is_array($types)) {
            return null;
        }

        $relations = array_values(array_unique(array_filter(
            [...$allowed, ...$types],
            static fn (mixed $relation): bool => is_string($relation) && $relation !== '',
        )));

        return $relations === [] ? null : $relations;
    }

    /**
     * @param  array<string, mixed>  $design
     * @return array<string, string>
     */
    public static function canonicalTexts(array $design): array
    {
        $texts = [];
        $walk = static function (mixed $node, string $path) use (&$walk, &$texts): void {
            if (is_string($node)) {
                $texts[$path] = $node;
            } elseif (is_array($node)) {
                foreach ($node as $key => $child) {
                    $walk($child, "{$path}.{$key}");
                }
            }
        };

        $walk($design[self::CANONICAL_KEY] ?? null, self::CANONICAL_KEY);

        return $texts;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<array{component_id: string, canonical_state: string, alternate_states: list<string>}>
     */
    public static function configurationComponents(array $output): array
    {
        $components = [];

        foreach (self::rows($output[self::CANONICAL_KEY]['configuration_states'] ?? null) as $row) {
            if (! is_string($row['component_id'] ?? null)) {
                continue;
            }

            $components[] = [
                'component_id' => $row['component_id'],
                'canonical_state' => (string) ($row['canonical_state'] ?? ''),
                'alternate_states' => array_values(array_filter((array) ($row['alternate_states'] ?? []), 'is_string')),
            ];
        }

        return $components;
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>|null  $policy
     * @return array<string, mixed>
     */
    public static function anchorDesign(array $output, ?array $policy): array
    {
        if (! self::hasCanonical($output)) {
            return [];
        }

        $canonical = $output[self::CANONICAL_KEY];
        $locked = self::lockedId($canonical);
        $kept = [];

        foreach (self::ANCHOR_OBJECTS as $key) {
            $section = $canonical[$key] ?? null;

            if (is_array($section) && ($section['status'] ?? null) === self::LOCKED) {
                unset($section['status'], $section['conventional_patterns']);
                $kept[$key] = $section;
            }
        }

        foreach (self::ANCHOR_LISTS as $key) {
            $kept[$key] = array_values(array_map(
                static function (array $row): array {
                    unset($row['status']);

                    return $row;
                },
                array_filter(self::rows($canonical[$key] ?? null), static fn (array $row): bool => ($row['status'] ?? null) === self::LOCKED),
            ));
        }

        $kept['configuration_states'] = array_values(array_map(
            static fn (array $row): array => array_intersect_key($row, array_flip(self::ANCHOR_CONFIGURATION_FIELDS)),
            array_filter(self::rows($canonical['configuration_states'] ?? null), static fn (array $row): bool => $locked($row['feature_id'] ?? null)),
        ));

        foreach (['must_preserve', 'must_not_introduce', 'reference_proof_requirements'] as $key) {
            $kept[$key] = self::rows($canonical[$key] ?? null);
        }

        return array_filter([
            self::CANONICAL_KEY => $kept,
            'length_beam_ratio' => self::lengthBeamRatio($output),
            'compilation_policy' => is_array($policy) ? array_intersect_key($policy, array_flip(self::ANCHOR_POLICY_FIELDS)) : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $extract
     * @return list<string>
     */
    public static function extractIntegrityViolations(array $output, array $extract): array
    {
        if (! self::hasCanonical($output)) {
            return [];
        }

        $kept = is_array($extract[self::CANONICAL_KEY] ?? null) ? $extract[self::CANONICAL_KEY] : [];
        $present = array_fill_keys(self::RESERVED_IDS, true);
        $violations = [];

        foreach (self::ID_LISTS as $key => $field) {
            foreach (self::rows($kept[$key] ?? null) as $row) {
                if (is_string($row[$field] ?? null)) {
                    $present[$row[$field]] = true;
                }
            }
        }

        $invariants = [];

        foreach (self::rows($kept['must_preserve'] ?? null) as $row) {
            if (is_string($row['id'] ?? null)) {
                $invariants[$row['id']] = true;
            }
        }

        $missing = static function (mixed $id, string $where) use (&$violations, $present): void {
            if (! is_string($id) || ! isset($present[$id])) {
                $violations[] = "{$where} names ".self::label($id).', which the extract does not contain';
            }
        };

        foreach (self::CORE_SECTIONS as $key) {
            if (! is_array($kept[$key] ?? null)) {
                $violations[] = self::CANONICAL_KEY.".{$key} is missing from the extract";
            }
        }

        foreach (self::rows($kept['geometric_relationships'] ?? null) as $index => $row) {
            $missing($row['subject'] ?? null, "geometric_relationships[{$index}].subject");
            $missing($row['object'] ?? null, "geometric_relationships[{$index}].object");
        }

        foreach (self::rows($kept['transitions'] ?? null) as $index => $row) {
            foreach ((array) ($row['between'] ?? []) as $id) {
                $missing($id, "transitions[{$index}].between");
            }
        }

        foreach (['must_preserve', 'must_not_introduce'] as $key) {
            foreach (self::rows($kept[$key] ?? null) as $index => $row) {
                foreach ((array) ($row['refs'] ?? []) as $ref) {
                    $missing($ref, "{$key}[{$index}].refs");
                }
            }
        }

        foreach (self::rows($kept['reference_proof_requirements'] ?? null) as $index => $row) {
            if (! isset($invariants[$row['target'] ?? ''])) {
                $missing($row['target'] ?? null, "reference_proof_requirements[{$index}].target");
            }
        }

        foreach (self::rows($kept['configuration_states'] ?? null) as $index => $row) {
            $missing($row['feature_id'] ?? null, "configuration_states[{$index}].feature_id");
        }

        foreach (self::rows($output[self::CANONICAL_KEY]['signature_regions'] ?? null) as $region) {
            if (($region['priority'] ?? null) === 'P0') {
                $missing($region['feature_id'] ?? null, 'P0 signature region');
            }
        }

        foreach (self::p0($output) as $row) {
            if (! isset($invariants[$row['id']])) {
                $violations[] = "P0 invariant {$row['id']} is missing from the extract";
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public static function screenplayGeometry(array $output): array
    {
        $kept = self::anchorDesign($output, null)[self::CANONICAL_KEY] ?? [];

        return array_diff_key($kept, array_flip(self::SCREENPLAY_EXCLUDED_SECTIONS));
    }

    /**
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>  $canonical
     * @return list<string>
     */
    private static function checkViolations(string $check, array $design, array $canonical): array
    {
        return match ($check) {
            'design_thesis_defined' => self::filledViolations('design_thesis', $design['design_thesis'] ?? null, self::THESIS_FIELDS),
            'novelty_thesis_defined' => self::sectionViolations($canonical, 'design_identity'),
            'principal_dimensions_defined' => self::dimensionViolations($design),
            'length_beam_ratio_defined' => [
                ...(self::lengthBeamRatio($design) === null ? ['principal_dimensions: length and beam do not give a ratio'] : []),
                ...self::sectionViolations($canonical, 'proportion_system'),
            ],
            'global_silhouette_defined' => [
                ...self::sectionViolations($canonical, 'global_silhouette'),
                ...self::sectionViolations($canonical, 'hull_geometry'),
                ...self::sectionViolations($canonical, 'superstructure_geometry'),
            ],
            'governing_lines_defined' => self::listViolations($canonical, 'governing_lines'),
            'bow_topology_defined' => self::sectionViolations($canonical, 'bow_geometry'),
            'stern_topology_defined' => self::sectionViolations($canonical, 'stern_geometry'),
            'primary_masses_defined' => self::listViolations($canonical, 'primary_masses'),
            'primary_voids_defined' => self::listViolations($canonical, 'primary_voids'),
            'signature_geometry_defined' => self::signatureViolations($design, $canonical),
            'major_topology_relationships_complete' => self::relationshipViolations($canonical),
            'major_transitions_defined' => self::transitionViolations($canonical),
            'configuration_state_separated_from_permanent_geometry' => self::configurationViolations($design, $canonical),
            'P0_must_preserve_defined' => self::mustPreserveViolations($canonical),
            'design_specific_forbidden_interpretations_defined' => self::forbiddenInterpretationViolations($canonical),
            'reference_proof_requirements_defined' => self::proofViolations($canonical),
            default => ['no rule implements this check'],
        };
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    private static function filledViolations(string $path, mixed $section, array $fields): array
    {
        if (! is_array($section)) {
            return ["{$path}: is missing"];
        }

        $violations = [];

        foreach ($fields as $field) {
            if (! is_string($section[$field] ?? null) || trim($section[$field]) === '') {
                $violations[] = "{$path}.{$field}: is empty";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @return list<string>
     */
    private static function sectionViolations(array $canonical, string $key): array
    {
        $path = self::CANONICAL_KEY.".{$key}";
        $section = $canonical[$key] ?? null;
        $violations = self::filledViolations($path, $section, self::SECTION_FIELDS[$key] ?? []);

        if (is_array($section) && ($section['status'] ?? null) !== self::LOCKED) {
            $violations[] = "{$path}: is ".self::label($section['status'] ?? null).', core geometry must be locked before the design is frozen';
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @return list<string>
     */
    private static function listViolations(array $canonical, string $key): array
    {
        $rows = self::rows($canonical[$key] ?? null);
        $violations = $rows === [] ? [self::CANONICAL_KEY.".{$key}: is empty"] : [];

        foreach ($rows as $index => $row) {
            if (($row['status'] ?? null) !== self::LOCKED) {
                $violations[] = self::CANONICAL_KEY.".{$key}[{$index}]: is ".self::label($row['status'] ?? null)
                    .', a primary part must be locked; an unsettled element belongs in permanent_secondary_geometry';
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @return \Closure(mixed): bool
     */
    private static function lockedId(array $canonical): \Closure
    {
        $statuses = self::statuses($canonical);

        return static fn (mixed $id): bool => is_string($id)
            && (in_array($id, self::RESERVED_IDS, true) || ($statuses[$id] ?? null) === self::LOCKED);
    }

    /**
     * @param  array<string, mixed>  $design
     * @return list<string>
     */
    private static function dimensionViolations(array $design): array
    {
        $length = $design['principal_dimensions']['length_m'] ?? null;
        $beam = $design['principal_dimensions']['beam_m'] ?? null;
        $violations = [];

        if (! is_int($length) || $length <= 0) {
            $violations[] = 'principal_dimensions.length_m: must be a positive whole number';
        }

        if ((! is_int($beam) && ! is_float($beam)) || $beam <= 0) {
            $violations[] = 'principal_dimensions.beam_m: must be a positive number';
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>  $canonical
     * @return list<string>
     */
    private static function signatureViolations(array $design, array $canonical): array
    {
        $names = self::featureKinds($design);
        $regions = self::rows($canonical['signature_regions'] ?? null);
        $violations = [];
        $covered = [];
        $hasP0 = false;

        foreach ($regions as $index => $region) {
            $path = self::CANONICAL_KEY.".signature_regions[{$index}]";
            $feature = $region['feature'] ?? null;

            if (! is_string($feature) || ! array_key_exists($feature, $names)) {
                $violations[] = "{$path}.feature: must be the exact name of a signature feature in the protagonist profile";
            } elseif (isset($covered[$feature])) {
                $violations[] = "{$path}.feature: {$feature} already has a signature region";
            } else {
                $covered[$feature] = true;
            }

            if (($region['priority'] ?? null) === 'P0') {
                $hasP0 = true;

                if (($region['status'] ?? null) !== self::LOCKED) {
                    $violations[] = "{$path}: a P0 signature region is ".self::label($region['status'] ?? null)
                        .', it must be locked before the design is frozen';
                }
            }
        }

        foreach (array_keys($names) as $name) {
            if (! isset($covered[$name])) {
                $violations[] = "signature feature \"{$name}\" has no signature region";
            }
        }

        if (! $hasP0) {
            $violations[] = self::CANONICAL_KEY.'.signature_regions: no region has priority P0';
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @return list<string>
     */
    private static function relationshipViolations(array $canonical): array
    {
        [$known, $violations] = self::knownIds($canonical);
        $locked = self::lockedId($canonical);
        $related = [];

        foreach (self::rows($canonical['geometric_relationships'] ?? null) as $index => $relation) {
            $path = self::CANONICAL_KEY.".geometric_relationships[{$index}]";
            $status = $relation['status'] ?? null;

            if ($status === self::UNDETERMINED) {
                $violations[] = "{$path}: a relationship is never undetermined; leave out one that is not decided";

                continue;
            }

            foreach (['subject', 'object'] as $side) {
                $id = $relation[$side] ?? null;

                if (! is_string($id) || ! isset($known[$id])) {
                    $violations[] = "{$path}.{$side}: ".self::label($id).' is not a declared id';
                } elseif ($status === self::LOCKED && ! $locked($id)) {
                    $violations[] = "{$path}.{$side}: a locked relationship names {$id}, which is not locked";
                } elseif ($status === self::LOCKED) {
                    $related[$id] = true;
                }
            }
        }

        foreach (['primary_masses', 'primary_voids'] as $key) {
            foreach (self::rows($canonical[$key] ?? null) as $row) {
                if (is_string($row['id'] ?? null) && ! isset($related[$row['id']])) {
                    $violations[] = self::CANONICAL_KEY.".{$key}: {$row['id']} takes part in no locked geometric relationship";
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @return list<string>
     */
    private static function transitionViolations(array $canonical): array
    {
        [$known] = self::knownIds($canonical);
        $locked = self::lockedId($canonical);
        $violations = [];
        $joined = [];
        $lockedJoined = [];
        $hullJoint = false;

        foreach (self::rows($canonical['transitions'] ?? null) as $index => $transition) {
            $path = self::CANONICAL_KEY.".transitions[{$index}]";
            $status = $transition['status'] ?? null;

            if ($status === self::UNDETERMINED) {
                $violations[] = "{$path}: a transition is never undetermined; leave out one that is not decided";

                continue;
            }

            if (($transition['kind'] ?? null) === 'hull_superstructure' && $status === self::LOCKED) {
                $hullJoint = true;
            }

            foreach ((array) ($transition['between'] ?? []) as $id) {
                if (! is_string($id) || ! isset($known[$id])) {
                    $violations[] = "{$path}.between: ".self::label($id).' is not a declared id';
                } elseif ($status === self::LOCKED && ! $locked($id)) {
                    $violations[] = "{$path}.between: a locked transition joins {$id}, which is not locked";
                } else {
                    $joined[$id] = true;
                    $lockedJoined[$id] = ($lockedJoined[$id] ?? false) || $status === self::LOCKED;
                }
            }
        }

        if (! $hullJoint) {
            $violations[] = self::CANONICAL_KEY.'.transitions: the locked hull to superstructure transition is missing';
        }

        foreach (self::rows($canonical['signature_regions'] ?? null) as $region) {
            $feature = $region['feature_id'] ?? null;

            if (! is_string($feature)) {
                continue;
            }

            if (! isset($joined[$feature])) {
                $violations[] = self::CANONICAL_KEY.".transitions: signature region {$feature} has no transition";
            } elseif (($region['priority'] ?? null) === 'P0' && ! $lockedJoined[$feature]) {
                $violations[] = self::CANONICAL_KEY.".transitions: P0 signature region {$feature} has no locked transition";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>  $canonical
     * @return list<string>
     */
    private static function configurationViolations(array $design, array $canonical): array
    {
        $kinds = self::featureKinds($design);
        $transforming = [];
        $violations = [];

        foreach (self::rows($canonical['signature_regions'] ?? null) as $region) {
            if (is_string($region['feature_id'] ?? null) && ($kinds[$region['feature'] ?? ''] ?? null) === 'transforming') {
                $transforming[$region['feature_id']] = false;
            }
        }

        foreach (self::rows($canonical['configuration_states'] ?? null) as $index => $row) {
            $feature = $row['feature_id'] ?? null;

            if (! is_string($feature) || ! array_key_exists($feature, $transforming)) {
                $violations[] = self::CANONICAL_KEY.".configuration_states[{$index}].feature_id: "
                    .self::label($feature).' is not the region of a transforming signature feature';
            } else {
                $transforming[$feature] = true;
            }
        }

        foreach ($transforming as $feature => $described) {
            if (! $described) {
                $violations[] = self::CANONICAL_KEY.".configuration_states: transforming region {$feature} has no configuration state";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @return list<string>
     */
    private static function mustPreserveViolations(array $canonical): array
    {
        [$known] = self::knownIds($canonical);
        $locked = self::lockedId($canonical);
        $violations = [];
        $hasP0 = false;

        foreach (self::rows($canonical['must_preserve'] ?? null) as $index => $row) {
            $path = self::CANONICAL_KEY.".must_preserve[{$index}]";
            $hasP0 = $hasP0 || ($row['priority'] ?? null) === 'P0';

            foreach ((array) ($row['refs'] ?? []) as $ref) {
                if (! is_string($ref) || ! isset($known[$ref])) {
                    $violations[] = "{$path}.refs: ".self::label($ref).' is not a declared id';
                } elseif (! $locked($ref)) {
                    $violations[] = "{$path}.refs: an invariant depends on {$ref}, which is not locked";
                }
            }
        }

        if (! $hasP0) {
            $violations[] = self::CANONICAL_KEY.'.must_preserve: no entry has priority P0';
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @return list<string>
     */
    private static function forbiddenInterpretationViolations(array $canonical): array
    {
        [$known] = self::knownIds($canonical);
        $locked = self::lockedId($canonical);
        $rows = self::rows($canonical['must_not_introduce'] ?? null);
        $violations = $rows === [] ? [self::CANONICAL_KEY.'.must_not_introduce: is empty'] : [];

        foreach ($rows as $index => $row) {
            foreach ((array) ($row['refs'] ?? []) as $ref) {
                if (! is_string($ref) || ! isset($known[$ref])) {
                    $violations[] = self::CANONICAL_KEY.".must_not_introduce[{$index}].refs: ".self::label($ref).' is not a declared id';
                } elseif (! $locked($ref)) {
                    $violations[] = self::CANONICAL_KEY.".must_not_introduce[{$index}].refs: protects {$ref}, which is not locked";
                }
            }
        }

        foreach (self::rows($canonical['signature_regions'] ?? null) as $index => $region) {
            if (! is_string($region['forbidden_interpretations'] ?? null) || trim($region['forbidden_interpretations']) === '') {
                $violations[] = self::CANONICAL_KEY.".signature_regions[{$index}].forbidden_interpretations: is empty";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @return list<string>
     */
    private static function proofViolations(array $canonical): array
    {
        [$known] = self::knownIds($canonical);
        $locked = self::lockedId($canonical);
        $preserve = self::rows($canonical['must_preserve'] ?? null);
        $invariants = [];

        foreach ($preserve as $row) {
            if (is_string($row['id'] ?? null)) {
                $invariants[$row['id']] = true;
            }
        }

        $violations = [];
        $proven = [];

        foreach (self::rows($canonical['reference_proof_requirements'] ?? null) as $index => $row) {
            $target = $row['target'] ?? null;
            $path = self::CANONICAL_KEY.".reference_proof_requirements[{$index}].target";

            if (! is_string($target) || (! isset($known[$target]) && ! isset($invariants[$target]))) {
                $violations[] = "{$path}: ".self::label($target).' is not a declared id';
            } elseif (! isset($invariants[$target]) && ! $locked($target)) {
                $violations[] = "{$path}: {$target} is not locked, only canonical geometry needs proof";
            } elseif ((array) ($row['views'] ?? []) !== []) {
                $proven[$target] = true;
            }
        }

        $required = [];

        foreach (self::rows($canonical['signature_regions'] ?? null) as $region) {
            if (($region['priority'] ?? null) === 'P0' && is_string($region['feature_id'] ?? null)) {
                $required[] = $region['feature_id'];
            }
        }

        foreach ($preserve as $row) {
            if (($row['priority'] ?? null) === 'P0' && is_string($row['id'] ?? null)) {
                $required[] = $row['id'];
            }
        }

        foreach ($required as $target) {
            if (! isset($proven[$target])) {
                $violations[] = self::CANONICAL_KEY.".reference_proof_requirements: P0 target {$target} has no proof view";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @return array{0: array<string, true>, 1: list<string>}
     */
    private static function knownIds(array $canonical): array
    {
        $known = array_fill_keys(self::RESERVED_IDS, true);
        $violations = [];

        foreach (self::ID_LISTS as $key => $field) {
            foreach (self::rows($canonical[$key] ?? null) as $row) {
                $id = $row[$field] ?? null;

                if (! is_string($id)) {
                    continue;
                }

                if (isset($known[$id])) {
                    $violations[] = self::CANONICAL_KEY.": id {$id} is used for more than one part";
                }

                $known[$id] = true;
            }
        }

        return [$known, $violations];
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @return array<string, string>
     */
    private static function statuses(array $canonical): array
    {
        $statuses = [];

        foreach (self::ID_LISTS as $key => $field) {
            foreach (self::rows($canonical[$key] ?? null) as $row) {
                if (is_string($row[$field] ?? null) && is_string($row['status'] ?? null)) {
                    $statuses[$row[$field]] = $row['status'];
                }
            }
        }

        foreach (self::rows($canonical['configuration_states'] ?? null) as $row) {
            if (is_string($row['component_id'] ?? null) && is_string($row['feature_id'] ?? null)) {
                $statuses[$row['component_id']] = $statuses[$row['feature_id']] ?? self::UNDETERMINED;
            }
        }

        return $statuses;
    }

    /**
     * @param  array<string, mixed>  $design
     * @return array<string, string>
     */
    private static function featureKinds(array $design): array
    {
        $kinds = [];

        foreach (self::rows($design[ProtagonistProfile::OUTPUT_KEY]['signature_features'] ?? null) as $feature) {
            if (is_string($feature['name'] ?? null)) {
                $kinds[$feature['name']] = (string) ($feature['kind'] ?? '');
            }
        }

        return $kinds;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        return is_array($value) && array_is_list($value)
            ? array_values(array_filter($value, 'is_array'))
            : [];
    }

    private static function label(mixed $value): string
    {
        return is_string($value) ? "\"{$value}\"" : 'the value';
    }
}
