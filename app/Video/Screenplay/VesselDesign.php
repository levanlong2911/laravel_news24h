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

    public const SELECTION_KEY = 'design_selection';

    public const UNRESOLVED_KEY = 'unresolved_decisions';

    public const CORRECTION_INPUT_KEY = 'correction_version';

    public const POLICY_KEY = 'prompt_compilation_policy';

    public const GEOMETRY_MODEL_KEY = 'geometry_model';

    public const GEOMETRY_TYPED = 'typed';

    public const GEOMETRY_LEGACY = 'legacy';

    public const GEOMETRY_UNKNOWN = 'unknown';

    public const GEOMETRY_LINKS_KEY = 'geometry_links';

    public const INTERIOR_POLICY_KEY = 'interior_policy';

    public const FURNISHED_ROOMS_POLICY = 'furnished_rooms_v1';

    public const PROFILE_PATHS_KEY = 'profile_paths';

    /** @var list<string> */
    public const SERVES_TARGET_LISTS = ['surfaces', 'primary_voids', 'spatial_topology'];

    public const SERVES_WRONG_KIND = 'opening_serves_wrong_kind';

    /** @var list<string> */
    private const NON_DESCRIBING_FIELDS = ['space', 'deck', 'name', 'origin', 'kind', 'region', 'undetermined'];

    /** @var list<string> */
    public const TYPED_LISTS = ['decks', 'openings', 'surfaces', 'basins', 'routes'];

    /** @var array<string, list<string>> */
    private const LINK_ROLES = [
        'deck' => ['decks'],
        'opening' => ['openings'],
        'surface' => ['surfaces'],
        'basin' => ['basins'],
        'route' => ['routes'],
        'region' => ['signature_regions'],
        'mass' => ['primary_masses'],
        'void' => ['primary_voids'],
        'space' => ['spatial_topology'],
    ];

    /** @var list<string> */
    private const LEVEL_CHANGING_ROUTES = ['stair', 'ramp', 'lift'];

    /** @var array<string, string> */
    private const LINK_OBLIGATIONS = ['openings' => 'opening', 'routes' => 'route', 'basins' => 'basin'];

    public const EXTERIOR_ROLE_KEY = 'exterior_role';

    public const INTERIOR_ONLY = 'interior_only';

    /** @var list<string> */
    public const EXTERIOR_ROLES = ['exterior', 'interior_affects_exterior', self::INTERIOR_ONLY, 'undetermined'];

    /** @var list<string> */
    public const ROLE_CLASSIFIED_LISTS = ['spatial_topology', 'openings', 'surfaces', 'basins', 'routes'];

    public const SIDE_KEY = 'side';

    public const SIDE_UNSPECIFIED = 'unspecified';

    public const PLACEMENT_DECISION_PREFIX = 'surface_side_';

    public const SECONDARY_FORM_KEY = 'form';

    public const OPENING_HOST_FORM = 'enclosure';

    /** @var list<string> */
    public const SECONDARY_FORMS = [self::OPENING_HOST_FORM, 'open_structure', 'surface_detail'];

    public const EQUIPMENT_KIND_KEY = 'equipment_kind';

    public const RADAR_SCANNER = 'radar_scanner';

    /** @var list<string> */
    public const EQUIPMENT_KINDS = [self::RADAR_SCANNER, 'satellite_dome', 'navigation_light', 'horn', 'whip_antenna', 'other'];

    public const EQUIPMENT_EXCLUDED_REASON = 'finishing equipment: not installed in the reference state';

    public const EQUIPMENT_POLICY_KEY = 'design_equipment_policy';

    public const EQUIPMENT_POLICY_VERSION = 1;

    public const EQUIPMENT_FORBIDDEN = 'forbidden';

    /** @var list<string> */
    public const EQUIPMENT_POLICY_VALUES = [self::EQUIPMENT_FORBIDDEN, 'allowed'];

    public const EQUIPMENT_DECISION_PREFIX = 'equipment_review_';

    public const SYSTEM_DECISION_SOURCE = 'system';

    public const PLACEMENT_RULE = 'surface_side';

    public const EQUIPMENT_RULE = 'equipment';

    /** @var list<string> */
    private const EQUIPMENT_SCAN_SECTIONS = [self::VESSEL_KEY, 'design_thesis', ProtagonistProfile::OUTPUT_KEY, self::CANONICAL_KEY];

    /** @var list<string> */
    private const EQUIPMENT_SKIPPED_KEYS = [
        'id', 'status', 'kind', 'face', 'side', self::EXTERIOR_ROLE_KEY, 'relation', 'orientation', 'water_level', 'direction',
        'priority', 'refs', 'between', 'subject', 'object', 'feature_id', 'component_id', 'host', 'serves', 'surface',
        'relative_to', 'access_routes', 'place', 'body', 'level', self::GEOMETRY_MODEL_KEY, self::SECONDARY_FORM_KEY, self::EQUIPMENT_KIND_KEY,
        self::PROFILE_PATHS_KEY,
    ];

    /** @var list<string> */
    private const MAST_SKIPPED_KEYS = [...self::EQUIPMENT_SKIPPED_KEYS, 'interpretation', 'forbidden_interpretations', 'conventional_patterns'];

    private const MAST_TERMS = '/\bmasts?\b|\b(?:radar|signal|antenna|mast)\s+arch(?:es)?\b/iu';

    private const FORWARD_OF_HOOD = '/\bforward\s+of\s+(?:the\s+)?(?:[\w-]+\s+){0,3}stair\s+hood\b/iu';

    /** @var array<string, string> */
    private const RADAR_MISPLACEMENTS = [
        '/\b(?:on|atop|above|over)\s+(?:the\s+)?(?:(?:roof|top)\s+of\s+(?:the\s+)?)?(?:[\w-]+\s+){0,3}stair\s+hood\b/iu' => 'puts the radar on the stair hood; it stands on the highest deck itself',
        '/\b(?:offset|off)\s+(?:to\s+)?(?:port|starboard)\b|\b(?:port|starboard)\s+of\s+(?:the\s+)?cent(?:er|re)line\b|\b(?:to|on)\s+(?:the\s+)?(?:port|starboard)(?:\s+side)?\b/iu' => 'moves the radar off the centerline',
        '/\baft\s+(?:end|part|edge|of)\b|\bat\s+the\s+stern\b/iu' => 'moves the radar aft; it stands at the forward part of the highest deck',
    ];

    private const ELEVATOR_TERMS = '/\b(?:elevators?|dumbwaiters?|(?:passenger|guest|service|crew|goods|cargo|freight|food|galley|luggage|owner\'?s?|private|glass|panoramic|inter-?deck|vertical)\s+lifts?|lifts?\s+(?:shafts?|cores?|cars?|cabins?|lobb(?:y|ies)|wells?|doors?|stops?|landings?|towers?|trunks?|banks?)|vertical\s+(?:transport|transportation|conveyance|conveyors?)|(?:stairs?|staircases?|ramps?)\s+(?:and|or)\s+lifts?|lifts?\s+(?:and|or)\s+(?:stairs?|staircases?|ramps?)|lifts?\s+(?:serves?|serving|connects?|connecting|links?|linking|stops?\s+at|runs?\s+(?:from|between|up|down|through|vertically)|rises?\s+through|reaches?|reaching))\b/iu';

    private const LIFT_NOUN = '/\b(?:a|an|the|its|their|one|two|each|every|this|that|by|via|own)\s+lifts?\b/iu';

    private const HOIST_TERM = '/\bhoists?\b/iu';

    private const CARRIED_BETWEEN_DECKS = '/\b(?:carr(?:y|ies|ied)|transports?|conveys?|ferr(?:y|ies)|raises?|lowers?)\b.*\b(?:guests?|passengers?|people|crew|goods|cargo|food|luggage|provisions|supplies)\b.*\b(?:between\s+(?:the\s+)?(?:decks?|levels?|floors?)|from\s+deck\s+to\s+deck)\b/iu';

    private const STAIR_WORDS = '/\b(?:stairs?|staircases?|ramps?|steps|flights?)\b/iu';

    private const CARRIER_WORDS = '/\b(?:cabins?|capsules?|cars?|pods?|platforms?|cages?|boxes|box|lifts?|tubes?|shafts?|conveyors?)\b/iu';

    private const NEGATION = '/\b(?:no|without|not|never|neither|nor|none)\b|\bfree\s+of\b/iu';

    private const NEGATED_LIST_START = '/^(?:no|without|neither|never|not\s+any|none\s+of)\b/iu';

    private const INDEPENDENT_START = '/^(?:a|an|the|this|that|these|those|its|their|it|they|there|each|every|which|where)\b/iu';

    /** @var list<string> */
    public const SIDES = ['port', 'starboard', 'centerline', 'both', self::SIDE_UNSPECIFIED];

    public const ANCHOR_SCOPE_VERSION = 'anchor-scope-v2';

    public const ANCHOR_SOURCE_VERSION = 'anchor-source-v5';

    public const TEXT_DAMAGED = 'damaged';

    public const TEXT_FOREIGN = 'foreign';

    private const DAMAGED_CHARACTERS = '/[\x{FFFD}\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}]/u';

    private const FOREIGN_LETTERS = '/\p{Greek}(?=\p{L})|(?<=\p{L})\p{Greek}|(?!\p{Greek})(?=\p{L})\P{Latin}/u';

    private const GUARD_TERMS ='/\b(?:rails?|railings?|guards?|guarding|guardrails?|balustrades?)\b/iu';

    /** @var list<string> */
    public const MOVABLE_OPENING_KINDS = ['movable_closure', 'hatch'];

    public const STANDARD_STATE_KEY = 'standard_state';

    public const MOVABLE_STANDARD_STATE = 'closed';

    public const EXTERIOR_DESIGN_KEY = 'exterior_design';

    public const STORY_SOURCE_KEY = 'story_source';

    public const LOCATION_SOURCE_KEY = 'location_source';

    public const SCENE_SOURCE_KEY = 'scene_source';

    public const LANDMARK_KEY = 'landmark';

    public const ANCHOR_ROLE_KEY = 'anchor_role';

    public const ANCHOR_SUPPORT = 'support';

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
        'design_completeness_gate', 'reference_proof_policy', self::EQUIPMENT_POLICY_KEY,
    ];

    /** @var list<string> */
    private const DESIGN_RULE_KEYS = [
        'design_contract_version', 'design_development', 'novelty_requirements', 'canonical_design_contract',
        'coordinate_and_orientation', 'form_system_requirements', 'proportion_requirements', 'geometry_requirements',
        'topology_requirements', 'geometric_relationship_requirements', 'transition_requirements',
        'signature_geometry_requirements', 'anti_regression', 'physical_credibility_policy',
        'design_completeness_gate', 'design_freeze_policy', 'reference_proof_policy', 'prompt_compilation_policy',
        self::EQUIPMENT_POLICY_KEY,
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

    private const TRANSITIONS_KEY = 'transitions';

    /** @var array<string, string> */
    private const ID_LISTS = [
        'governing_lines' => 'id',
        'primary_masses' => 'id',
        'primary_voids' => 'id',
        'spatial_topology' => 'id',
        'permanent_secondary_geometry' => 'id',
        'signature_regions' => 'feature_id',
        'configuration_states' => 'component_id',
        'decks' => 'id',
        'openings' => 'id',
        'surfaces' => 'id',
        'basins' => 'id',
        'routes' => 'id',
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

    /** @var list<string> */
    private const STORY_PROFILE_FIELDS = [
        'deck_organization', 'spatial_layout', 'amenities', 'wellness_and_relaxation', 'capacity', 'transformable_spaces',
        'construction_new_build_and_refit', 'materials_and_finishes', 'windows_and_glazing', ProtagonistProfile::INTERIOR_KEY,
    ];

    /** @var array<string, list<string>|null> */
    private const STORY_OBJECT_FIELDS = [
        'design_identity' => ['novelty_thesis', 'replacement_principle', 'superyacht_reading'],
        'hull_geometry' => null,
        'bow_geometry' => null,
        'stern_geometry' => null,
        'superstructure_geometry' => null,
    ];

    /** @var array<string, list<string>|null> */
    private const STORY_LIST_FIELDS = [
        'decks' => ['id', 'name', 'level', 'body'],
        'primary_masses' => null,
        'primary_voids' => null,
        'signature_regions' => ['feature_id', 'feature', 'kind', 'priority', 'description', 'topology', 'relationships', 'must_preserve'],
        'spatial_topology' => null,
        'routes' => null,
        'permanent_secondary_geometry' => null,
        'must_preserve' => null,
        'must_not_introduce' => null,
    ];

    /** @var list<string> */
    private const STORY_CONFIGURATION_FIELDS = ['component_id', 'feature_id', 'fixed_geometry', 'canonical_state', 'alternate_states', 'human_use_by_state'];

    /** @var array<string, array{hull: list<string>, objects: list<string>, faces: list<string>, all_surfaces: bool, side: ?string}> */
    private const REFERENCE_ASPECTS = [
        'forward' => ['hull' => ['stem_and_bow', 'waterline_entry', 'forefoot', 'sheer', 'underbody_and_appendages'], 'objects' => ['bow_geometry'], 'faces' => ['forward'], 'all_surfaces' => true, 'side' => null],
        'aft' => ['hull' => ['stern_and_transom', 'freeboard_distribution', 'underbody_and_appendages'], 'objects' => ['stern_geometry'], 'faces' => ['aft'], 'all_surfaces' => true, 'side' => null],
        'port' => ['hull' => ['hull_side_organization', 'sheer', 'freeboard_distribution', 'midbody', 'hull_superstructure_relationship', 'design_waterline'], 'objects' => [], 'faces' => ['port'], 'all_surfaces' => false, 'side' => 'port'],
        'starboard' => ['hull' => ['hull_side_organization', 'sheer', 'freeboard_distribution', 'midbody', 'hull_superstructure_relationship', 'design_waterline'], 'objects' => [], 'faces' => ['starboard'], 'all_surfaces' => false, 'side' => 'starboard'],
        'upward' => ['hull' => [], 'objects' => [], 'faces' => ['upward'], 'all_surfaces' => true, 'side' => null],
        'below' => ['hull' => ['forefoot', 'waterline_entry', 'freeboard_distribution', 'midbody', 'design_waterline', 'underbody_and_appendages'], 'objects' => [], 'faces' => ['downward'], 'all_surfaces' => false, 'side' => null],
    ];

    /** @var list<string> */
    private const REFERENCE_COMMON_OBJECTS = ['proportion_system', 'global_silhouette', 'superstructure_geometry'];

    /** @var list<string> */
    private const REFERENCE_SIGNATURE_FIELDS = ['feature_id', 'feature', 'kind', 'priority', 'description', 'topology', 'relationships', 'must_preserve'];

    /** @var list<string> */
    private const SCENE_CONFIGURATION_FIELDS = [
        'component_id', 'feature_id', 'fixed_geometry', 'canonical_state', 'alternate_states',
        'mechanism_location', 'stowage', 'swept_volume', 'human_use_by_state',
    ];

    /** @var list<string> */
    private const SCENE_PROFILE_EXCLUDED_FIELDS = ['figures', 'size_and_dimensions', 'form_and_proportions'];

    /** @var array<string, list<string>|null> */
    private const SCENE_OBJECT_FIELDS = [
        'hull_geometry' => null,
        'bow_geometry' => null,
        'stern_geometry' => null,
        'superstructure_geometry' => null,
    ];

    /** @var array<string, list<string>|null> */
    private const SCENE_LIST_FIELDS = [
        'decks' => ['id', 'name', 'level', 'body'],
        'routes' => null,
        'openings' => null,
        'surfaces' => null,
        'basins' => null,
        'spatial_topology' => null,
        'primary_masses' => null,
        'primary_voids' => null,
        'signature_regions' => ['feature_id', 'feature', 'kind', 'description', 'topology', 'relationships', 'must_preserve'],
        'transitions' => null,
        'permanent_secondary_geometry' => null,
        'must_preserve' => null,
        'must_not_introduce' => null,
    ];

    /** @var list<string> */
    private const LOCATION_PROFILE_FIELDS = [
        ProtagonistProfile::INTERIOR_KEY, 'materials_and_finishes', 'windows_and_glazing', 'spatial_layout', 'deck_organization',
        'amenities', 'wellness_and_relaxation', 'transformable_spaces',
    ];

    /** @var array<string, list<string>|null> */
    private const LOCATION_OBJECT_FIELDS = [
        'global_silhouette' => ['plan_view'],
        'hull_geometry' => ['hull_superstructure_relationship', 'freeboard_distribution', 'hull_side_organization', 'stern_and_transom'],
        'bow_geometry' => ['relationship_to_forward_superstructure'],
        'stern_geometry' => ['aft_silhouette', 'transom_closure', 'platform_terrace_or_recess_topology', 'relationship_to_waterline', 'relationship_to_superstructure'],
        'superstructure_geometry' => null,
    ];

    /** @var array<string, list<string>|null> */
    private const LOCATION_LIST_FIELDS = [
        'decks' => ['id', 'name', 'level', 'body'],
        'primary_masses' => null,
        'primary_voids' => null,
        'signature_regions' => ['feature_id', 'feature', 'description', 'topology', 'relationships'],
        'spatial_topology' => null,
        'surfaces' => null,
        'openings' => null,
        'routes' => null,
        'basins' => null,
        'permanent_secondary_geometry' => null,
        'must_preserve' => null,
        'must_not_introduce' => null,
    ];

    /** @var array<string, list<string>|null> */
    private const EXTERIOR_OBJECT_FIELDS = [
        'proportion_system' => null,
        'global_silhouette' => ['profile_view', 'plan_view'],
        'hull_geometry' => null,
        'bow_geometry' => null,
        'stern_geometry' => null,
        'superstructure_geometry' => null,
    ];

    /** @var array<string, list<string>> */
    private const EXTERIOR_LIST_FIELDS = [
        'decks' => ['id', 'name', 'level', 'body'],
        'governing_lines' => ['id', 'line', 'runs_from', 'runs_to', 'governs'],
        'primary_masses' => ['id', 'role', 'position', 'relative_length', 'relative_width', 'relative_height', 'connections', 'transition_to_adjacent_geometry', self::EXTERIOR_ROLE_KEY, self::ANCHOR_ROLE_KEY],
        'primary_voids' => ['id', 'role', 'position', 'relative_extent', 'boundaries', 'open_or_enclosed_state', 'relationship_to_surrounding_masses', self::EXTERIOR_ROLE_KEY, self::ANCHOR_ROLE_KEY],
        'signature_regions' => ['feature_id', 'feature', 'priority', 'description', 'topology', 'relationships', 'must_preserve'],
        'spatial_topology' => ['id', 'region', 'deck', 'boundaries', self::EXTERIOR_ROLE_KEY, self::ANCHOR_ROLE_KEY],
        'openings' => ['id', 'deck', 'face', 'kind', 'host', 'serves', self::EXTERIOR_ROLE_KEY, self::ANCHOR_ROLE_KEY],
        'surfaces' => ['id', 'deck', 'kind', 'relative_to', 'relation', self::SIDE_KEY, self::EXTERIOR_ROLE_KEY, self::ANCHOR_ROLE_KEY],
        'basins' => ['id', 'surface', 'orientation', 'water_level', 'access_routes', self::EXTERIOR_ROLE_KEY, self::ANCHOR_ROLE_KEY],
        'routes' => ['id', 'kind', 'from', 'to', 'direction', 'support', self::EXTERIOR_ROLE_KEY, self::ANCHOR_ROLE_KEY],
        'geometric_relationships' => ['subject', 'relation', 'object', 'statement'],
        'transitions' => ['id', 'kind', 'between', 'description'],
        'permanent_secondary_geometry' => ['id', 'element', 'position', 'description', self::SECONDARY_FORM_KEY],
        'configuration_states' => ['component_id', 'feature_id', 'fixed_geometry', 'canonical_state'],
        'must_preserve' => ['id', 'priority', 'statement', 'refs'],
        'must_not_introduce' => ['id', 'interpretation', 'instead', 'refs'],
    ];

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

        if (self::unresolvedDecisions($output) !== []) {
            $content[self::UNRESOLVED_KEY] = $output[self::UNRESOLVED_KEY];
        }

        if (array_key_exists(self::GEOMETRY_LINKS_KEY, $output)) {
            $content[self::GEOMETRY_LINKS_KEY] = $output[self::GEOMETRY_LINKS_KEY];
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @return array<string, mixed>|null
     */
    public static function surfacePlacement(array $canonical, string $id): ?array
    {
        $surfaces = [];
        $decks = [];

        foreach (self::rows($canonical['surfaces'] ?? null) as $row) {
            if (is_string($row['id'] ?? null)) {
                $surfaces[$row['id']] = $row;
            }
        }

        foreach (self::rows($canonical['decks'] ?? null) as $row) {
            if (is_string($row['id'] ?? null)) {
                $decks[$row['id']] = mb_strtolower(is_string($row['name'] ?? null) && trim($row['name']) !== '' ? $row['name'] : $row['id']);
            }
        }

        $surface = $surfaces[$id] ?? null;

        if ($surface === null) {
            return null;
        }

        $deckName = static fn (mixed $deck): string => is_string($deck) ? ($decks[$deck] ?? $deck) : '';
        $relation = ($surface['relation'] ?? 'none') === 'none' ? null : (string) $surface['relation'];
        $relative = $surface['relative_to'] ?? null;
        $reference = null;
        $regions = array_column(self::rows($canonical['spatial_topology'] ?? null), null, 'id');
        $basins = array_column(self::rows($canonical['basins'] ?? null), null, 'id');
        $secondaries = array_column(self::rows($canonical['permanent_secondary_geometry'] ?? null), null, 'id');

        if ($relation !== null && is_string($relative)) {
            $reference = match (true) {
                isset($surfaces[$relative]) => ['deck' => $deckName($surfaces[$relative]['deck'] ?? null), 'kind' => (string) ($surfaces[$relative]['kind'] ?? '')]
                    + (self::declaredSide($surfaces[$relative]) === null ? [] : ['side' => self::declaredSide($surfaces[$relative])]),
                $relative === 'hull' => 'hull',
                isset($regions[$relative]) => ['type' => 'region', 'name' => (string) ($regions[$relative]['region'] ?? '')],
                isset($secondaries[$relative]) => ['type' => 'secondary', 'name' => (string) ($secondaries[$relative]['element'] ?? '')],
                isset($basins[$relative]) => [
                    'type' => 'basin',
                    'deck' => $deckName($surfaces[$basins[$relative]['surface'] ?? '']['deck'] ?? null),
                    'orientation' => (string) ($basins[$relative]['orientation'] ?? ''),
                ],
                default => 'unnamed',
            };
        }

        return [
            'deck' => $deckName($surface['deck'] ?? null),
            'kind' => (string) ($surface['kind'] ?? ''),
            'side' => self::declaredSide($surface),
            'relation' => $relation,
            'reference' => $reference,
        ];
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @return list<array{0: string, 1: string}>
     */
    public static function indistinctSurfaces(array $canonical): array
    {
        $seen = [];
        $pairs = [];

        foreach (self::rows($canonical['surfaces'] ?? null) as $surface) {
            $id = $surface['id'] ?? null;

            if (! is_string($id)) {
                continue;
            }

            $words = json_encode(self::surfacePlacement($canonical, $id));

            if (isset($seen[$words])) {
                $pairs[] = [$seen[$words], $id];
            } else {
                $seen[$words] = $id;
            }
        }

        return $pairs;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<string>
     */
    public static function placementGaps(array $output): array
    {
        $canonical = self::lockedDesign($output, null)[self::CANONICAL_KEY] ?? [];

        return array_map(
            static fn (array $pair): string => self::CANONICAL_KEY.".surfaces: {$pair[0]} and {$pair[1]} have the same deck, kind, relation"
                .' and reference description in words; a locating fact that tells them apart is missing',
            self::indistinctSurfaces($canonical),
        );
    }

    /** @param array<string, mixed> $output */
    public static function geometryModel(array $output): string
    {
        $canonical = $output[self::CANONICAL_KEY] ?? null;

        if (! is_array($canonical) || ! array_key_exists(self::GEOMETRY_MODEL_KEY, $canonical)) {
            return self::GEOMETRY_LEGACY;
        }

        return $canonical[self::GEOMETRY_MODEL_KEY] === self::GEOMETRY_TYPED ? self::GEOMETRY_TYPED : self::GEOMETRY_UNKNOWN;
    }

    /**
     * @param  array<string, mixed>  $design
     * @return list<string>
     */
    public static function newDesignViolations(array $design): array
    {
        if (self::geometryModel($design) === self::GEOMETRY_LEGACY) {
            return [self::CANONICAL_KEY.'.'.self::GEOMETRY_MODEL_KEY.': a new design must use the typed geometry model'];
        }

        $violations = [];

        foreach (self::rows($design[self::CANONICAL_KEY]['surfaces'] ?? null) as $index => $surface) {
            if (! array_key_exists(self::SIDE_KEY, $surface)) {
                $violations[] = self::CANONICAL_KEY.".surfaces[{$index}].".self::SIDE_KEY.': a new design declares the side of every surface, unspecified when the design does not settle it';
            }
        }

        foreach (self::rows($design[self::CANONICAL_KEY]['permanent_secondary_geometry'] ?? null) as $index => $row) {
            if (! array_key_exists(self::SECONDARY_FORM_KEY, $row)) {
                $violations[] = self::CANONICAL_KEY.".permanent_secondary_geometry[{$index}].".self::SECONDARY_FORM_KEY.': a new design declares the form of every secondary element';
            }

            if (! array_key_exists(self::EQUIPMENT_KIND_KEY, $row)) {
                $violations[] = self::CANONICAL_KEY.".permanent_secondary_geometry[{$index}].".self::EQUIPMENT_KIND_KEY.': a new design declares the equipment_kind of every secondary element, null for structure';
            }
        }

        foreach (array_keys(self::LINK_OBLIGATIONS) as $list) {
            foreach (self::rows($design[self::CANONICAL_KEY][$list] ?? null) as $index => $row) {
                if (! array_key_exists(self::PROFILE_PATHS_KEY, $row)) {
                    $violations[] = self::CANONICAL_KEY.".{$list}[{$index}].".self::PROFILE_PATHS_KEY.': a new design names the '
                        .ProtagonistProfile::OUTPUT_KEY.' passages that describe every opening, route and basin';
                }
            }
        }

        $canonical = is_array($design[self::CANONICAL_KEY] ?? null) ? $design[self::CANONICAL_KEY] : [];

        return [
            ...$violations,
            ...self::filledViolations(self::CANONICAL_KEY.'.proportion_system', $canonical['proportion_system'] ?? null, ['vertical']),
            ...self::filledViolations(self::CANONICAL_KEY.'.hull_geometry', $canonical['hull_geometry'] ?? null, ['design_waterline', 'underbody_and_appendages']),
            ...self::equipmentRuleViolations($canonical),
            ...array_map(static fn (array $finding): string => "{$finding['path']}: a new design has no mast, radar arch or signal arch: \"{$finding['text']}\"", self::mastFindings($design)),
            ...array_map(static fn (array $finding): string => $finding['kind'] === self::TEXT_DAMAGED
                ? "{$finding['path']}: this text holds a damaged character (U+FFFD or a control character): \"{$finding['text']}\""
                : "{$finding['path']}: a design is written in Latin script only, this text holds other letters: \"{$finding['text']}\"", self::scriptFindings($design)),
        ];
    }

    /**
     * @param  array<string, mixed>  $design
     * @return list<array{path: string, kind: string, text: string}>
     */
    public static function scriptFindings(array $design): array
    {
        $findings = [];

        foreach ($design as $section => $value) {
            foreach (self::textPaths($value, (string) $section) as $path => $text) {
                foreach ([self::TEXT_DAMAGED => self::DAMAGED_CHARACTERS, self::TEXT_FOREIGN => self::FOREIGN_LETTERS] as $kind => $pattern) {
                    if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) === 1) {
                        $findings[] = ['path' => $path, 'kind' => $kind, 'text' => trim(mb_strcut($text, max(0, $match[0][1] - 40), 90))];

                        break;
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @return list<string>
     */
    public static function equipmentRuleViolations(array $canonical): array
    {
        $at = self::CANONICAL_KEY.'.permanent_secondary_geometry';
        $rows = self::rows($canonical['permanent_secondary_geometry'] ?? null);
        $equipment = [];
        $radars = [];
        $violations = [];

        foreach ($rows as $index => $row) {
            if (! is_string($row[self::EQUIPMENT_KIND_KEY] ?? null)) {
                continue;
            }

            if (is_string($row['id'] ?? null)) {
                $equipment[$row['id']] = true;
            }

            if (($row[self::SECONDARY_FORM_KEY] ?? null) === self::OPENING_HOST_FORM) {
                $violations[] = "{$at}[{$index}].".self::SECONDARY_FORM_KEY.': a piece of equipment is never an enclosure';
            }

            if ($row[self::EQUIPMENT_KIND_KEY] === self::RADAR_SCANNER) {
                $radars[$index] = $row;
            }
        }

        if (count($radars) !== 1) {
            $violations[] = "{$at}: a new design declares exactly one radar_scanner row, found ".count($radars);
        }

        foreach ($radars as $index => $radar) {
            if (($radar['status'] ?? null) !== self::LOCKED) {
                $violations[] = "{$at}[{$index}].status: the radar is part of the finished design and must be locked, or the film never receives it";
            }

            $violations = [...$violations, ...self::radarPlacementViolations("{$at}[{$index}].position", (string) ($radar['position'] ?? ''), $canonical)];
        }

        $named = static function (mixed $id, string $where) use ($equipment, &$violations): void {
            if (is_string($id) && isset($equipment[$id])) {
                $violations[] = "{$where} names {$id}, a piece of equipment; equipment is fitted at finishing and stays out of rules, transitions, proofs, openings and surfaces";
            }
        };

        foreach (['must_preserve', 'must_not_introduce'] as $key) {
            foreach (self::rows($canonical[$key] ?? null) as $index => $row) {
                foreach ((array) ($row['refs'] ?? []) as $ref) {
                    $named($ref, self::CANONICAL_KEY.".{$key}[{$index}].refs");
                }
            }
        }

        foreach (self::rows($canonical[self::TRANSITIONS_KEY] ?? null) as $index => $row) {
            foreach ((array) ($row['between'] ?? []) as $id) {
                $named($id, self::CANONICAL_KEY.".transitions[{$index}].between");
            }
        }

        foreach (self::rows($canonical['reference_proof_requirements'] ?? null) as $index => $row) {
            $named($row['target'] ?? null, self::CANONICAL_KEY.".reference_proof_requirements[{$index}].target");
        }

        foreach (self::rows($canonical['configuration_states'] ?? null) as $index => $row) {
            $named($row['feature_id'] ?? null, self::CANONICAL_KEY.".configuration_states[{$index}].feature_id");
        }

        foreach (self::rows($canonical['openings'] ?? null) as $index => $row) {
            $named($row['host'] ?? null, self::CANONICAL_KEY.".openings[{$index}].host");
        }

        foreach (self::rows($canonical['surfaces'] ?? null) as $index => $row) {
            $named($row['relative_to'] ?? null, self::CANONICAL_KEY.".surfaces[{$index}].relative_to");
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @return list<string>
     */
    private static function radarPlacementViolations(string $where, string $position, array $canonical): array
    {
        $highest = null;

        foreach (self::rows($canonical['decks'] ?? null) as $deck) {
            if (is_int($deck['level'] ?? null) && ($highest === null || $deck['level'] > $highest['level'])) {
                $highest = $deck;
            }
        }

        $violations = [];
        $name = is_string($highest['name'] ?? null) ? trim($highest['name']) : '';

        if ($name !== '' && mb_stripos($position, $name) === false) {
            $violations[] = "{$where}: must name the highest deck, {$name}, where the radar stands";
        }

        if (preg_match('/\bcent(?:er|re)line\b/iu', $position) !== 1) {
            $violations[] = "{$where}: must place the radar on the centerline";
        }

        if (preg_match('/\bforward\b/iu', $position) !== 1) {
            $violations[] = "{$where}: must place the radar at the forward part of the highest deck";
        }

        foreach (self::RADAR_MISPLACEMENTS as $pattern => $problem) {
            if (preg_match($pattern, $position, $match) === 1) {
                $violations[] = "{$where}: \"".trim($match[0])."\" {$problem}";
            }
        }

        foreach (self::rows($canonical['permanent_secondary_geometry'] ?? null) as $row) {
            $element = (string) ($row['element'] ?? '');

            $byId = is_string($row['id'] ?? null) && preg_match('/\bforward\s+of\s+(?:the\s+)?'.preg_quote($row['id'], '/').'\b/iu', $position) === 1;

            if (($row[self::EQUIPMENT_KIND_KEY] ?? null) === null && preg_match('/\bstair\s+hood\b/iu', $element) === 1
                && ! $byId && preg_match(self::FORWARD_OF_HOOD, $position) !== 1) {
                $violations[] = "{$where}: must place the radar forward of the {$element}";

                break;
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @param  list<string>  $views
     * @return list<array{target: string, proof_condition: string}>
     */
    public static function proofsForViews(array $canonical, array $views): array
    {
        $proofs = [];

        foreach (self::rows($canonical['reference_proof_requirements'] ?? null) as $row) {
            if (is_string($row['target'] ?? null) && array_intersect($views, (array) ($row['views'] ?? [])) !== []) {
                $proofs[] = ['target' => $row['target'], 'proof_condition' => (string) ($row['proof_condition'] ?? '')];
            }
        }

        foreach (self::rows($canonical['signature_regions'] ?? null) as $row) {
            if (is_string($row['feature_id'] ?? null) && array_intersect($views, (array) ($row['proof_views'] ?? [])) !== []) {
                $proofs[] = ['target' => $row['feature_id'], 'proof_condition' => (string) ($row['proof_conditions'] ?? '')];
            }
        }

        $unique = [];

        foreach ($proofs as $proof) {
            $unique[$proof['target']."\n".mb_strtolower(trim($proof['proof_condition']))] ??= $proof;
        }

        return array_values($unique);
    }

    /**
     * @param  array<string, mixed>  $design
     * @return list<string>
     */
    /**
     * @param  array<string, mixed>  $canonical
     * @return array{0: array<string, string>, 1: array<string, array<string, array<string, mixed>>>, 2: list<string>}
     */
    private static function idTable(array $canonical): array
    {
        $types = [];
        $rows = [];
        $duplicates = [];

        foreach (self::ID_LISTS as $list => $field) {
            foreach (self::rows($canonical[$list] ?? null) as $index => $row) {
                $id = $row[$field] ?? null;

                if (! is_string($id)) {
                    continue;
                }

                if (isset($types[$id])) {
                    $duplicates[] = self::CANONICAL_KEY.".{$list}[{$index}].{$field}: {$id} is already declared in {$types[$id]}";

                    continue;
                }

                $types[$id] = $list;
                $rows[$list][$id] = $row;
            }
        }

        return [$types, $rows, $duplicates];
    }

    /**
     * @param  array<string, mixed>  $design
     * @return list<array{code: string, path: string, index: int, opening_id: string, serves: string, serves_list: string}>
     */
    public static function servesKindIssues(array $design): array
    {
        if (self::geometryModel($design) !== self::GEOMETRY_TYPED) {
            return [];
        }

        [$types] = self::idTable($design[self::CANONICAL_KEY]);
        $issues = [];

        foreach (self::rows($design[self::CANONICAL_KEY]['openings'] ?? null) as $index => $row) {
            $serves = $row['serves'] ?? null;

            if (is_string($row['id'] ?? null) && is_string($serves) && isset($types[$serves])
                && ! in_array($types[$serves], self::SERVES_TARGET_LISTS, true)) {
                $issues[] = [
                    'code' => self::SERVES_WRONG_KIND,
                    'path' => self::CANONICAL_KEY.".openings[{$index}].serves",
                    'index' => $index,
                    'opening_id' => $row['id'],
                    'serves' => $serves,
                    'serves_list' => $types[$serves],
                ];
            }
        }

        return $issues;
    }

    public static function typedGeometryViolations(array $design): array
    {
        $model = self::geometryModel($design);
        $at = self::CANONICAL_KEY;

        if ($model === self::GEOMETRY_LEGACY) {
            return [];
        }

        if ($model === self::GEOMETRY_UNKNOWN) {
            return ["{$at}.".self::GEOMETRY_MODEL_KEY.': '.self::label($design[$at][self::GEOMETRY_MODEL_KEY]).' is not a known geometry model'];
        }

        $canonical = $design[$at];
        $violations = [];

        foreach (self::TYPED_LISTS as $list) {
            if (! is_array($canonical[$list] ?? null) || ! array_is_list($canonical[$list])) {
                $violations[] = "{$at}.{$list}: must be a list";
            }
        }

        if ($violations !== []) {
            return $violations;
        }

        foreach (self::ROLE_CLASSIFIED_LISTS as $list) {
            foreach (self::rows($canonical[$list] ?? null) as $index => $row) {
                if (array_key_exists(self::EXTERIOR_ROLE_KEY, $row) && ! in_array($row[self::EXTERIOR_ROLE_KEY], self::EXTERIOR_ROLES, true)) {
                    $violations[] = "{$at}.{$list}[{$index}].".self::EXTERIOR_ROLE_KEY.': '.self::label($row[self::EXTERIOR_ROLE_KEY])
                        .' is not one of '.implode(', ', self::EXTERIOR_ROLES);
                }
            }
        }

        foreach (self::rows($canonical['surfaces']) as $index => $row) {
            if (array_key_exists(self::SIDE_KEY, $row) && ! in_array($row[self::SIDE_KEY], self::SIDES, true)) {
                $violations[] = "{$at}.surfaces[{$index}].".self::SIDE_KEY.': '.self::label($row[self::SIDE_KEY]).' is not one of '.implode(', ', self::SIDES);
            }
        }

        [$types, $rows, $duplicates] = self::idTable($canonical);
        $violations = [...$violations, ...$duplicates];
        $statuses = self::statuses($canonical);
        $check = static function (string $where, mixed $id, array $lists, string $owner, bool $nullable = false, bool $hull = false) use ($types, $statuses): array {
            if ($id === null && $nullable) {
                return [];
            }

            if ($hull && $id === 'hull') {
                return [];
            }

            if (! is_string($id) || ! isset($types[$id])) {
                return ["{$where}: ".self::label($id).' is not a declared id'];
            }

            if (! in_array($types[$id], $lists, true)) {
                return ["{$where}: {$id} is a {$types[$id]} row, not ".implode(' or ', $lists)];
            }

            if ($owner === self::LOCKED && ($statuses[$id] ?? null) !== self::LOCKED) {
                return ["{$where}: a locked row names {$id}, which is ".self::label($statuses[$id] ?? null)];
            }

            return [];
        };

        $levels = [];

        foreach (self::rows($canonical['decks']) as $index => $deck) {
            $where = "{$at}.decks[{$index}]";
            $owner = (string) ($deck['status'] ?? '');

            if (! is_int($deck['level'] ?? null)) {
                $violations[] = "{$where}.level: must be an integer";
            } elseif (isset($levels[$deck['level']])) {
                $violations[] = "{$where}.level: {$deck['level']} is already the level of {$levels[$deck['level']]}";
            } else {
                $levels[$deck['level']] = (string) ($deck['id'] ?? '');
            }

            $violations = [...$violations, ...$check("{$where}.body", $deck['body'] ?? null, ['primary_masses'], $owner, false, true)];
        }

        foreach (self::rows($canonical['permanent_secondary_geometry'] ?? null) as $index => $row) {
            if (array_key_exists(self::SECONDARY_FORM_KEY, $row) && ! in_array($row[self::SECONDARY_FORM_KEY], self::SECONDARY_FORMS, true)) {
                $violations[] = "{$at}.permanent_secondary_geometry[{$index}].".self::SECONDARY_FORM_KEY.': '.self::label($row[self::SECONDARY_FORM_KEY])
                    .' is not one of '.implode(', ', self::SECONDARY_FORMS);
            }

            $kind = $row[self::EQUIPMENT_KIND_KEY] ?? null;

            if ($kind !== null && ! in_array($kind, self::EQUIPMENT_KINDS, true)) {
                $violations[] = "{$at}.permanent_secondary_geometry[{$index}].".self::EQUIPMENT_KIND_KEY.': '.self::label($kind)
                    .' is not null or one of '.implode(', ', self::EQUIPMENT_KINDS);
            }
        }

        foreach (self::rows($canonical['openings']) as $index => $opening) {
            $where = "{$at}.openings[{$index}]";
            $owner = (string) ($opening['status'] ?? '');
            $host = $opening['host'] ?? null;
            $violations = [
                ...$violations,
                ...$check("{$where}.deck", $opening['deck'] ?? null, ['decks'], $owner),
                ...$check("{$where}.host", $host, ['primary_masses', 'permanent_secondary_geometry'], $owner, false, true),
                ...$check("{$where}.serves", $opening['serves'] ?? null, self::SERVES_TARGET_LISTS, $owner, true),
            ];
            $secondary = is_string($host) ? ($rows['permanent_secondary_geometry'][$host] ?? null) : null;

            if ($secondary !== null && ($secondary[self::SECONDARY_FORM_KEY] ?? null) !== self::OPENING_HOST_FORM) {
                $form = $secondary[self::SECONDARY_FORM_KEY] ?? null;
                $violations[] = "{$where}.host: {$host} is a permanent_secondary_geometry row whose form is "
                    .(is_string($form) ? self::label($form) : 'not declared').'; only an '.self::OPENING_HOST_FORM.' carries openings';
            }
        }

        foreach (self::rows($canonical['surfaces']) as $index => $surface) {
            $where = "{$at}.surfaces[{$index}]";
            $owner = (string) ($surface['status'] ?? '');
            $relative = $surface['relative_to'] ?? null;
            $violations = [...$violations, ...$check("{$where}.deck", $surface['deck'] ?? null, ['decks'], $owner)];

            if ($relative === null) {
                if (($surface['relation'] ?? null) !== 'none') {
                    $violations[] = "{$where}.relation: ".self::label($surface['relation'] ?? null).' needs a relative_to, which is null';
                }

                continue;
            }

            if ($relative === ($surface['id'] ?? null)) {
                $violations[] = "{$where}.relative_to: a surface cannot be placed against itself";

                continue;
            }

            if (($surface['relation'] ?? null) === 'none') {
                $violations[] = "{$where}.relation: none contradicts relative_to {$relative}";
            }

            $violations = [...$violations, ...$check("{$where}.relative_to", $relative, ['surfaces', 'primary_masses', 'primary_voids', 'spatial_topology', 'basins', 'permanent_secondary_geometry'], $owner, false, true)];

            if (is_string($relative) && isset($rows['spatial_topology'][$relative]) && ($surface['relation'] ?? null) === 'within') {
                // Only an exact deck name is resolvable; multi-deck prose is not guessed.
                $regionDeck = $rows['spatial_topology'][$relative]['deck'] ?? null;
                $matches = array_keys(array_filter($rows['decks'] ?? [], static fn (array $d): bool => ($d['name'] ?? null) === $regionDeck));
                if (count($matches) === 1 && $matches[0] !== ($surface['deck'] ?? null)) {
                    $violations[] = "{$where}: within {$relative} contradicts its declared deck {$matches[0]}";
                }
            }
            if (is_string($relative) && isset($rows['basins'][$relative]) && ($surface['relation'] ?? null) === 'within') {
                $violations[] = "{$where}.relation: within a basin is not a supported surface placement";
            }
        }

        $deckOf = static fn (mixed $surface): ?string => is_string($surface) && is_string($rows['surfaces'][$surface]['deck'] ?? $rows['surfaces'][$rows['basins'][$surface]['surface'] ?? '']['deck'] ?? null)
            ? ($rows['surfaces'][$surface]['deck'] ?? $rows['surfaces'][$rows['basins'][$surface]['surface']]['deck'])
            : null;
        $levelOf = static fn (?string $deck): ?int => is_string($deck) && is_int($rows['decks'][$deck]['level'] ?? null)
            ? $rows['decks'][$deck]['level']
            : null;

        foreach (self::surfaceCollisions($canonical) as $collision) {
            if ($collision['verdict'] === 'same') {
                $violations[] = "{$at}.surfaces[{$collision['index']}]: ".self::label($collision['id'])
                    ." has the same deck, kind, relation, reference and side as {$collision['other']}; nothing tells them apart";
            }
        }

        $surfaceRows = $rows['surfaces'] ?? [];
        $opposed = ['forward_of', 'aft_of', 'above', 'below', 'within', 'around'];

        foreach (self::rows($canonical['surfaces']) as $index => $surface) {
            $id = $surface['id'] ?? null;
            $relative = $surface['relative_to'] ?? null;
            $relation = $surface['relation'] ?? null;
            $back = is_string($relative) ? ($surfaceRows[$relative] ?? null) : null;

            if (is_string($id) && $back !== null && ($back['relative_to'] ?? null) === $id && in_array($relation, $opposed, true)
                && ($back['relation'] ?? null) === $relation && strcmp($id, (string) $relative) < 0) {
                $violations[] = "{$at}.surfaces[{$index}].relation: {$id} is {$relation} {$relative} while {$relative} is {$relation} {$id}; the two placements contradict each other";
            }
        }

        foreach (self::rows($canonical['surfaces']) as $index => $surface) {
            $relation = $surface['relation'] ?? null;

            if (! in_array($relation, ['above', 'below'], true)) {
                continue;
            }

            $own = $levelOf(is_string($surface['deck'] ?? null) ? $surface['deck'] : null);
            $other = $levelOf($deckOf($surface['relative_to'] ?? null));

            if ($own !== null && $other !== null && ($relation === 'above' ? $own <= $other : $own >= $other)) {
                $violations[] = "{$at}.surfaces[{$index}].relation: {$relation} ".self::label($surface['relative_to'] ?? null)
                    ." contradicts the deck levels ({$surface['deck']} is level {$own}, ".$deckOf($surface['relative_to'] ?? null)." is level {$other})";
            }
        }

        foreach (self::rows($canonical['routes']) as $index => $route) {
            $where = "{$at}.routes[{$index}]";
            $owner = (string) ($route['status'] ?? '');
            $ends = [];

            foreach (['from', 'to'] as $end) {
                $point = is_array($route[$end] ?? null) ? $route[$end] : [];
                $ends[$end] = $point;
                $violations = [
                    ...$violations,
                    ...$check("{$where}.{$end}.deck", $point['deck'] ?? null, ['decks'], $owner),
                    ...$check("{$where}.{$end}.place", $point['place'] ?? null, ['surfaces'], $owner, true),
                ];

                $placeDeck = $deckOf($point['place'] ?? null);

                if ($placeDeck !== null && $placeDeck !== ($point['deck'] ?? null)) {
                    $violations[] = "{$where}.{$end}.place: {$point['place']} lies on {$placeDeck}, not on ".self::label($point['deck'] ?? null);
                }
            }

            $fromDeck = $ends['from']['deck'] ?? null;
            $toDeck = $ends['to']['deck'] ?? null;

            if ($ends['from'] === $ends['to']) {
                $violations[] = "{$where}: starts and arrives at the same place";
            } elseif (in_array($route['kind'] ?? null, self::LEVEL_CHANGING_ROUTES, true) && $fromDeck === $toDeck) {
                $violations[] = "{$where}: a ".self::label($route['kind'] ?? null).' joins two different decks, but from and to are both '.self::label($fromDeck);
            } elseif (($route['kind'] ?? null) === 'walkway' && $fromDeck !== $toDeck) {
                $violations[] = "{$where}: a walkway stays on one deck, but it runs from ".self::label($fromDeck).' to '.self::label($toDeck);
            }
        }

        foreach (self::rows($canonical['basins']) as $index => $basin) {
            $where = "{$at}.basins[{$index}]";
            $owner = (string) ($basin['status'] ?? '');
            $violations = [...$violations, ...$check("{$where}.surface", $basin['surface'] ?? null, ['surfaces'], $owner)];
            $access = is_array($basin['access_routes'] ?? null) ? $basin['access_routes'] : [];
            $basinDeck = $deckOf($basin['surface'] ?? null);

            if ($access === []) {
                $violations[] = "{$where}.access_routes: a basin is reached by at least one route";
            }

            foreach ($access as $position => $routeId) {
                $found = $check("{$where}.access_routes[{$position}]", $routeId, ['routes'], $owner);
                $violations = [...$violations, ...$found];
                $route = $found === [] ? ($rows['routes'][$routeId] ?? []) : [];

                if ($route !== [] && $basinDeck !== null
                    && ($route['from']['deck'] ?? null) !== $basinDeck && ($route['to']['deck'] ?? null) !== $basinDeck) {
                    $violations[] = "{$where}.access_routes[{$position}]: {$routeId} does not reach {$basinDeck}, the deck of ".self::label($basin['surface'] ?? null);
                }
            }
        }

        $profileKinds = [];

        foreach (self::rows($design[ProtagonistProfile::OUTPUT_KEY]['signature_features'] ?? null) as $index => $feature) {
            if (is_string($feature['name'] ?? null)) {
                $profileKinds[$feature['name']] = [$index, $feature['kind'] ?? null];
            }
        }

        foreach (self::rows($canonical['signature_regions'] ?? null) as $index => $region) {
            $kind = $region['kind'] ?? null;

            if (! in_array($kind, ['fixed', 'transforming'], true)) {
                $violations[] = "{$at}.signature_regions[{$index}].kind: must be fixed or transforming";

                continue;
            }

            [$featureIndex, $featureKind] = $profileKinds[$region['feature'] ?? ''] ?? [null, null];

            if ($featureIndex !== null && $featureKind !== $kind) {
                $violations[] = "{$at}.signature_regions[{$index}].kind ({$kind}) conflicts with "
                    .ProtagonistProfile::OUTPUT_KEY.".signature_features[{$featureIndex}].kind (".self::label($featureKind).')';
            }
        }

        return [...$violations, ...self::linkViolations($design, $types, $rows)];
    }

    /** @param array<string, mixed> $profile */
    private static function describingPassage(array $profile, string $path): bool
    {
        if (preg_match('/^(?:'.preg_quote(ProtagonistProfile::INTERIOR_KEY, '/').'|signature_features)\.\d+$/', $path) === 1) {
            return true;
        }

        $segments = explode('.', $path);

        if ($segments[0] === 'figures' || in_array(end($segments), self::NON_DESCRIBING_FIELDS, true)) {
            return false;
        }

        $node = $profile;

        foreach ($segments as $segment) {
            $node = $node[ctype_digit($segment) ? (int) $segment : $segment] ?? null;
        }

        return is_string($node) && trim($node) !== '';
    }

    private static function linkTarget(string $path): string
    {
        return preg_match('/^('.preg_quote(ProtagonistProfile::INTERIOR_KEY, '/').'\.\d+)\./', $path, $match) === 1 ? $match[1] : $path;
    }

    /**
     * @param  array<string, mixed>  $design
     * @param  array<string, string>  $types
     * @param  array<string, array<string, array<string, mixed>>>  $rows
     * @return list<string>
     */
    private static function linkViolations(array $design, array $types, array $rows): array
    {
        $links = $design[self::GEOMETRY_LINKS_KEY] ?? null;

        if (! is_array($links) || ! array_is_list($links)) {
            return [self::GEOMETRY_LINKS_KEY.': must be a list'];
        }

        $profile = is_array($design[ProtagonistProfile::OUTPUT_KEY] ?? null) ? $design[ProtagonistProfile::OUTPUT_KEY] : [];
        $violations = [];
        $linked = [];

        foreach ($links as $index => $link) {
            $where = self::GEOMETRY_LINKS_KEY."[{$index}]";
            $path = is_array($link) ? ($link['profile_path'] ?? null) : null;
            $role = is_array($link) ? ($link['role'] ?? null) : null;
            $refs = is_array($link) ? ($link['refs'] ?? null) : null;

            if (! is_string($path) || ! self::profilePathExists($profile, $path)) {
                $violations[] = "{$where}.profile_path: ".self::label($path).' is not a path in '.ProtagonistProfile::OUTPUT_KEY;

                continue;
            }

            if (! is_string($role) || ! isset(self::LINK_ROLES[$role])) {
                $violations[] = "{$where}.role: ".self::label($role).' is not a link role';

                continue;
            }

            if (! is_array($refs) || ! array_is_list($refs) || $refs === []) {
                $violations[] = "{$where}.refs: must name at least one id";

                continue;
            }

            if (isset($linked[$path][$role])) {
                $violations[] = "{$where}: {$path} already has a {$role} link";

                continue;
            }

            foreach ($refs as $position => $ref) {
                if (! is_string($ref) || ! isset($types[$ref])) {
                    $violations[] = "{$where}.refs[{$position}]: ".self::label($ref).' is not a declared id';
                } elseif (! in_array($types[$ref], self::LINK_ROLES[$role], true)) {
                    $fitting = array_keys(array_filter(self::LINK_ROLES, static fn (array $lists): bool => in_array($types[$ref], $lists, true)));
                    $violations[] = "{$where}.refs[{$position}]: {$ref} belongs to {$types[$ref]}, which role {$role} does not link; "
                        .($fitting === [] ? 'no link role links it' : 'use role '.implode(' or ', $fitting));
                }
            }

            $linked[$path][$role] = array_values(array_filter($refs, static fn (mixed $ref): bool => is_string($ref) && isset($types[$ref])));
        }

        foreach (self::LINK_OBLIGATIONS as $list => $role) {
            foreach (self::rows($design[self::CANONICAL_KEY][$list] ?? null) as $index => $row) {
                $id = $row['id'] ?? null;

                if (! array_key_exists(self::PROFILE_PATHS_KEY, $row) || ! is_string($id)) {
                    continue;
                }

                $at = self::CANONICAL_KEY.".{$list}[{$index}].".self::PROFILE_PATHS_KEY;
                $paths = $row[self::PROFILE_PATHS_KEY];

                if (! is_array($paths) || ! array_is_list($paths) || $paths === []) {
                    $violations[] = "{$at}: must list at least one ".ProtagonistProfile::OUTPUT_KEY.' path';

                    continue;
                }

                $seen = [];

                foreach ($paths as $position => $path) {
                    if (! is_string($path) || trim($path) === '') {
                        $violations[] = "{$at}[{$position}]: must be a ".ProtagonistProfile::OUTPUT_KEY.' path';
                    } elseif (isset($seen[$path])) {
                        $violations[] = "{$at}[{$position}]: repeats {$path}";
                    } elseif (! self::profilePathExists($profile, $path)) {
                        $violations[] = "{$at}[{$position}]: ".self::label($path).' is not a path in '.ProtagonistProfile::OUTPUT_KEY;
                    } elseif (! self::describingPassage($profile, $path)) {
                        $violations[] = "{$at}[{$position}]: {$path} is not a passage that describes a part; name a text passage, "
                            .'one interior space or one signature feature';
                    } else {
                        $seen[$path] = true;
                        $target = self::linkTarget($path);
                        $linked[$target][$role] = array_values(array_unique([...($linked[$target][$role] ?? []), $id]));
                    }
                }
            }
        }

        $referenced = [];

        foreach ($linked as $roles) {
            foreach ($roles as $role => $ids) {
                foreach ($ids as $id) {
                    $referenced[$role][$id] = true;
                }
            }
        }

        foreach (self::LINK_OBLIGATIONS as $list => $role) {
            foreach (self::rows($design[self::CANONICAL_KEY][$list] ?? null) as $index => $row) {
                $id = $row['id'] ?? null;

                if (($row['status'] ?? null) === self::LOCKED && is_string($id) && ! isset($referenced[$role][$id])) {
                    $violations[] = self::CANONICAL_KEY.".{$list}[{$index}]: {$id} is locked but no protagonist_profile passage links it with the {$role} role";
                }
            }
        }

        foreach (self::rows($profile[ProtagonistProfile::INTERIOR_KEY] ?? null) as $index => $space) {
            $path = ProtagonistProfile::INTERIOR_KEY.".{$index}";
            $where = ProtagonistProfile::OUTPUT_KEY.".{$path}";
            $decks = $linked[$path]['deck'] ?? [];

            if ($decks === []) {
                $violations[] = "{$where}: has no geometry link to its deck";

                continue;
            }

            foreach ($linked[$path]['opening'] ?? [] as $opening) {
                $deck = $rows['openings'][$opening]['deck'] ?? null;

                if (! in_array($deck, $decks, true)) {
                    $violations[] = "{$where}: links opening {$opening} on ".self::label($deck).', but the space lies on '.implode(', ', $decks);
                }
            }

            foreach ($linked[$path]['basin'] ?? [] as $basin) {
                $surface = $rows['basins'][$basin]['surface'] ?? null;
                $deck = is_string($surface) ? ($rows['surfaces'][$surface]['deck'] ?? null) : null;

                if (! in_array($deck, $decks, true)) {
                    $violations[] = "{$where}: links basin {$basin} on ".self::label($deck).', but the space lies on '.implode(', ', $decks);
                }
            }

            foreach ($linked[$path]['route'] ?? [] as $route) {
                $from = $rows['routes'][$route]['from']['deck'] ?? null;
                $to = $rows['routes'][$route]['to']['deck'] ?? null;

                if (! in_array($from, $decks, true) && ! in_array($to, $decks, true)) {
                    $violations[] = "{$where}: links route {$route} between ".self::label($from).' and '.self::label($to)
                        .', neither of which is '.implode(', ', $decks);
                }
            }

            if (is_string($space['space'] ?? null) && str_contains($space['space'], 'pool') && ($linked[$path]['basin'] ?? []) === []) {
                $violations[] = "{$where}: the {$space['space']} space has no geometry link to its basin";
            }
        }

        foreach (self::rows($profile['signature_features'] ?? null) as $index => $feature) {
            $path = "signature_features.{$index}";
            $regions = $linked[$path]['region'] ?? [];
            $name = $feature['name'] ?? null;

            if ($regions === []) {
                $violations[] = ProtagonistProfile::OUTPUT_KEY.".{$path}: has no geometry link to its signature region";

                continue;
            }

            foreach ($regions as $region) {
                $owner = $rows['signature_regions'][$region]['feature'] ?? null;

                if ($owner !== $name) {
                    $violations[] = ProtagonistProfile::OUTPUT_KEY.".{$path}: links region {$region}, which belongs to ".self::label($owner);
                }
            }
        }

        return $violations;
    }

    /** @param array<string, mixed> $profile */
    private static function profilePathExists(array $profile, string $path): bool
    {
        $node = $profile;

        foreach (explode('.', $path) as $segment) {
            $key = ctype_digit($segment) ? (int) $segment : $segment;

            if (! is_array($node) || ! array_key_exists($key, $node)) {
                return false;
            }

            $node = $node[$key];
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<string>
     */
    public static function incompleteTexts(array $output): array
    {
        return [
            ...ProtagonistProfile::incompleteTexts($output[ProtagonistProfile::OUTPUT_KEY] ?? null, ProtagonistProfile::OUTPUT_KEY),
            ...self::incompleteVesselTexts($output),
        ];
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<string>
     */
    public static function incompleteVesselTexts(array $output): array
    {
        $violations = [];

        foreach (['description', 'appearance'] as $field) {
            $text = $output[self::VESSEL_KEY][$field] ?? null;

            if (is_string($text) && trim($text) !== '' && ! ProtagonistProfile::endsAsSentence($text)) {
                $violations[] = self::VESSEL_KEY.".{$field}: ends mid-sentence, the text is incomplete";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>
     */
    public static function validationProfile(array $profile, ?array $snapshot): array
    {
        $policy = $snapshot[self::POLICY_KEY] ?? null;

        return array_replace($profile, [self::POLICY_KEY => is_array($policy) ? $policy : null]);
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<array<string, mixed>>
     */
    public static function unresolvedDecisions(array $output): array
    {
        $decisions = $output[self::UNRESOLVED_KEY] ?? null;

        return is_array($decisions) && array_is_list($decisions) ? $decisions : [];
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
     * @param  array<string, mixed>  $stamp
     * @param  array<string, mixed>  $vessel
     */
    public static function stampedFor(array $stamp, array $vessel): bool
    {
        return is_string($stamp['design_stage_id'] ?? null) && is_string($vessel['design_stage_id'] ?? null)
            && $stamp['design_stage_id'] === $vessel['design_stage_id']
            && is_string($stamp['design_content_hash'] ?? null) && is_string($vessel['design_content_hash'] ?? null)
            && hash_equals($vessel['design_content_hash'], $stamp['design_content_hash']);
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

        return [...$violations, ...self::equipmentPolicyViolations($profile)];
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
        return self::textPaths($design[self::CANONICAL_KEY] ?? null, self::CANONICAL_KEY);
    }

    /**
     * @param  list<string>  $skip
     * @return array<string, string>
     */
    public static function textPaths(mixed $node, string $path, array $skip = []): array
    {
        $texts = [];
        $walk = static function (mixed $node, string $path) use (&$walk, &$texts, $skip): void {
            if (is_string($node)) {
                $texts[$path] = $node;
            } elseif (is_array($node)) {
                foreach ($node as $key => $child) {
                    if (! in_array($key, $skip, true)) {
                        $walk($child, "{$path}.{$key}");
                    }
                }
            }
        };

        $walk($node, $path);

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
        return self::scoped($output, $policy)[0];
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array{version: string, design_content_hash: string, classified: bool, rows: list<array{list: string, id: string, exterior_role: ?string, decision: string, reason: string}>}
     */
    public static function anchorScope(array $output): array
    {
        return self::scoped($output, null)[1];
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $extract
     * @return array<string, mixed>
     */
    public static function exteriorDesign(array $output, array $extract, ?string $view): array
    {
        $canonical = is_array($extract[self::CANONICAL_KEY] ?? null) ? $extract[self::CANONICAL_KEY] : [];
        $sheet = [
            'vessel' => ['name' => $output[self::VESSEL_KEY]['name'] ?? null],
            'principal_dimensions' => [
                'length_m' => $output['principal_dimensions']['length_m'] ?? null,
                'beam_m' => $output['principal_dimensions']['beam_m'] ?? null,
                'length_beam_ratio' => self::lengthBeamRatio($output),
            ],
        ];

        foreach (self::EXTERIOR_OBJECT_FIELDS as $key => $fields) {
            if (is_array($canonical[$key] ?? null)) {
                $sheet[$key] = $fields === null ? $canonical[$key] : array_intersect_key($canonical[$key], array_flip($fields));
            }
        }

        $covered = [];

        foreach (self::rows($canonical['must_not_introduce'] ?? null) as $row) {
            foreach ((array) ($row['refs'] ?? []) as $ref) {
                $covered[$ref] = true;
            }
        }

        foreach (self::EXTERIOR_LIST_FIELDS as $key => $fields) {
            if (! is_array($canonical[$key] ?? null)) {
                continue;
            }

            $sheet[$key] = array_map(static function (array $row) use ($key, $fields, $covered): array {
                $kept = array_intersect_key($row, array_flip($fields));

                if ($key === 'signature_regions' && ! isset($covered[$row['feature_id'] ?? null]) && isset($row['forbidden_interpretations'])) {
                    $kept['forbidden_interpretations'] = $row['forbidden_interpretations'];
                }

                return $kept;
            }, self::rows($canonical[$key]));
        }

        if (isset($sheet['must_preserve'])) {
            $order = array_flip(['P0', 'P1', 'P2']);
            $rows = $sheet['must_preserve'];
            uksort($rows, static fn (int $a, int $b): int => [$order[$rows[$a]['priority'] ?? ''] ?? 3, $a] <=> [$order[$rows[$b]['priority'] ?? ''] ?? 3, $b]);
            $sheet['must_preserve'] = array_values($rows);
        }

        $sheet['proof_for_view'] = $view === null ? [] : self::proofsForViews($canonical, [$view]);
        $bases = self::fittedLaterIds(is_array($output[self::CANONICAL_KEY] ?? null) ? $output[self::CANONICAL_KEY] : [], $canonical);

        if ($bases !== []) {
            $sheet['permanent_secondary_geometry'] = array_values(array_filter($sheet['permanent_secondary_geometry'] ?? [],
                static fn (array $row): bool => ! isset($bases[$row['id'] ?? null])));
            $sheet['geometric_relationships'] = array_values(array_filter($sheet['geometric_relationships'] ?? [],
                static fn (array $row): bool => ! isset($bases[$row['subject'] ?? null]) && ! isset($bases[$row['object'] ?? null])));
        }

        if (isset($sheet['spatial_topology'])) {
            $sheet['spatial_topology'] = array_map(static fn (array $row): array => ($row[self::EXTERIOR_ROLE_KEY] ?? null) === 'exterior'
                ? $row : array_diff_key($row, ['boundaries' => true]), $sheet['spatial_topology']);
        }

        if (($sheet['configuration_states'] ?? null) === []) {
            unset($sheet['configuration_states']);
        }

        if (isset($sheet['openings'])) {
            $sheet['openings'] = self::withStandardState($sheet['openings']);
        }

        return $sheet;
    }

    /**
     * @param  list<array<string, mixed>>  $openings
     * @return list<array<string, mixed>>
     */
    private static function withStandardState(array $openings): array
    {
        return array_map(static fn (array $row): array => in_array($row['kind'] ?? null, self::MOVABLE_OPENING_KINDS, true)
            ? $row + [self::STANDARD_STATE_KEY => self::MOVABLE_STANDARD_STATE] : $row, $openings);
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $kept
     * @return array<string, true>
     */
    private static function fittedLaterIds(array $source, array $kept): array
    {
        $secondary = self::rows($source['permanent_secondary_geometry'] ?? null);
        $positions = array_map(static fn (array $row): string => mb_strtolower((string) ($row['position'] ?? '')),
            array_filter($secondary, static fn (array $row): bool => is_string($row[self::EQUIPMENT_KIND_KEY] ?? null)));
        $bases = [];

        foreach ($secondary as $row) {
            $id = $row['id'] ?? null;
            $element = mb_strtolower(trim((string) ($row['element'] ?? '')));

            if (! is_string($id) || ($row[self::EQUIPMENT_KIND_KEY] ?? null) !== null) {
                continue;
            }

            if (preg_match(self::GUARD_TERMS, $element.' '.str_replace('_', ' ', $id)) === 1) {
                $bases[$id] = true;

                continue;
            }

            $standsOn = '/\bon\s+(?:the\s+)?(?:'.preg_quote(mb_strtolower($id), '/').($element === '' ? '' : '|'.preg_quote($element, '/')).')\b/u';

            foreach ($positions as $position) {
                if (preg_match($standsOn, $position) === 1) {
                    $bases[$id] = true;

                    break;
                }
            }
        }

        foreach (['must_preserve', 'must_not_introduce'] as $key) {
            foreach (self::rows($kept[$key] ?? null) as $row) {
                foreach ((array) ($row['refs'] ?? []) as $ref) {
                    unset($bases[$ref]);
                }
            }
        }

        foreach (self::rows($kept[self::TRANSITIONS_KEY] ?? null) as $row) {
            foreach ((array) ($row['between'] ?? []) as $ref) {
                unset($bases[$ref]);
            }
        }

        foreach (self::rows($kept['openings'] ?? null) as $row) {
            unset($bases[$row['host'] ?? '']);
        }

        foreach (self::rows($kept['surfaces'] ?? null) as $row) {
            unset($bases[$row['relative_to'] ?? '']);
        }

        return $bases;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public static function storyDesign(array $output): array
    {
        return self::storyExtract($output)[0];
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<string>
     */
    public static function storyReferenceGaps(array $output): array
    {
        return self::storyExtract($output)[1];
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function storyExtract(array $output): array
    {
        $profile = is_array($output[ProtagonistProfile::OUTPUT_KEY] ?? null) ? $output[ProtagonistProfile::OUTPUT_KEY] : [];
        $story = [
            self::VESSEL_KEY => $output[self::VESSEL_KEY] ?? null,
            'design_thesis' => $output['design_thesis'] ?? null,
            'principal_dimensions' => $output['principal_dimensions'] ?? null,
            ProtagonistProfile::OUTPUT_KEY => array_intersect_key($profile, array_flip(self::STORY_PROFILE_FIELDS)),
        ];

        if (! self::hasCanonical($output)) {
            return [$story, []];
        }

        [$canonical, $gaps] = self::canonicalExtract($output, self::STORY_OBJECT_FIELDS, self::STORY_LIST_FIELDS);

        return [$story + [self::CANONICAL_KEY => $canonical], $gaps];
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public static function locationDesign(array $output): array
    {
        return self::hasCanonical($output) ? self::canonicalExtract($output, self::LOCATION_OBJECT_FIELDS, self::LOCATION_LIST_FIELDS)[0] : [];
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<string>
     */
    public static function locationReferenceGaps(array $output): array
    {
        return self::hasCanonical($output) ? self::canonicalExtract($output, self::LOCATION_OBJECT_FIELDS, self::LOCATION_LIST_FIELDS)[1] : [];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public static function locationProfile(array $profile): array
    {
        return array_intersect_key($profile, array_flip(self::LOCATION_PROFILE_FIELDS));
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public static function sceneDesign(array $output): array
    {
        return self::hasCanonical($output)
            ? self::canonicalExtract($output, self::SCENE_OBJECT_FIELDS, self::SCENE_LIST_FIELDS, self::SCENE_CONFIGURATION_FIELDS)[0]
            : [];
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<string>
     */
    public static function sceneReferenceGaps(array $output): array
    {
        return self::hasCanonical($output)
            ? self::canonicalExtract($output, self::SCENE_OBJECT_FIELDS, self::SCENE_LIST_FIELDS, self::SCENE_CONFIGURATION_FIELDS)[1]
            : [];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public static function sceneProfile(array $profile): array
    {
        return array_diff_key($profile, array_flip(self::SCENE_PROFILE_EXCLUDED_FIELDS));
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array{near_end: ?string, side: ?string, aspects: list<string>}  $frame
     * @param  list<string>  $proofViews
     * @return array<string, mixed>
     */
    public static function referenceDesign(array $output, string $view, array $frame, array $proofViews): array
    {
        return self::referenceExtract($output, $view, $frame, $proofViews)[0];
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array{near_end: ?string, side: ?string, aspects: list<string>}  $frame
     * @param  list<string>  $proofViews
     * @return list<string>
     */
    public static function referenceDesignGaps(array $output, string $view, array $frame, array $proofViews): array
    {
        return self::referenceExtract($output, $view, $frame, $proofViews)[1];
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array{near_end: ?string, side: ?string, aspects: list<string>}  $frame
     * @param  list<string>  $proofViews
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function referenceExtract(array $output, string $view, array $frame, array $proofViews): array
    {
        $extract = [
            'view' => $view,
            'camera' => ['near_end' => $frame['near_end'], 'side' => $frame['side']],
            'identity' => [
                'name' => $output[self::VESSEL_KEY]['name'] ?? null,
                'length_m' => $output['principal_dimensions']['length_m'] ?? null,
                'beam_m' => $output['principal_dimensions']['beam_m'] ?? null,
                'length_beam_ratio' => self::lengthBeamRatio($output),
            ],
        ];

        if (! self::hasCanonical($output)) {
            return [$extract, []];
        }

        $scoped = self::anchorDesign($output, null)[self::CANONICAL_KEY] ?? [];
        $scoped['openings'] = self::withStandardState(self::rows($scoped['openings'] ?? null));
        $bases = self::fittedLaterIds(is_array($output[self::CANONICAL_KEY] ?? null) ? $output[self::CANONICAL_KEY] : [], $scoped);

        if ($bases !== []) {
            $scoped['permanent_secondary_geometry'] = array_values(array_filter(self::rows($scoped['permanent_secondary_geometry'] ?? null),
                static fn (array $row): bool => ! isset($bases[$row['id'] ?? null])));
            $scoped['geometric_relationships'] = array_values(array_filter(self::rows($scoped['geometric_relationships'] ?? null),
                static fn (array $row): bool => ! isset($bases[$row['subject'] ?? null]) && ! isset($bases[$row['object'] ?? null])));
        }

        $hullFields = [];
        $objects = self::REFERENCE_COMMON_OBJECTS;
        $faces = [];
        $sides = [];
        $allSurfaces = false;

        foreach ($frame['aspects'] as $aspect) {
            $rule = self::REFERENCE_ASPECTS[$aspect] ?? null;

            if ($rule === null) {
                continue;
            }

            $hullFields = [...$hullFields, ...$rule['hull']];
            $objects = [...$objects, ...$rule['objects']];
            $faces = [...$faces, ...$rule['faces']];
            $allSurfaces = $allSurfaces || $rule['all_surfaces'];

            if ($rule['side'] !== null) {
                $sides[] = $rule['side'];
            }
        }

        $selected = [];

        foreach (array_unique($objects) as $key) {
            if (is_array($scoped[$key] ?? null)) {
                $selected[$key] = $scoped[$key];
            }
        }

        if ($hullFields !== [] && is_array($scoped['hull_geometry'] ?? null)) {
            $selected['hull_geometry'] = array_intersect_key($scoped['hull_geometry'], array_flip($hullFields));
        }

        $selected['decks'] = array_map(static fn (array $row): array => array_intersect_key($row, array_flip(['id', 'name', 'level', 'body'])), self::rows($scoped['decks'] ?? null));
        $selected['primary_masses'] = self::rows($scoped['primary_masses'] ?? null);
        $selected['primary_voids'] = self::rows($scoped['primary_voids'] ?? null);
        $selected['signature_regions'] = array_map(static fn (array $row): array => array_intersect_key($row, array_flip(self::REFERENCE_SIGNATURE_FIELDS)), self::rows($scoped['signature_regions'] ?? null));
        $selected['openings'] = array_values(array_filter(self::rows($scoped['openings'] ?? null), static fn (array $row): bool => in_array($row['face'] ?? null, $faces, true)));
        $selected['surfaces'] = array_values(array_filter(self::rows($scoped['surfaces'] ?? null), static fn (array $row): bool => $allSurfaces
            || ($sides !== [] && ! in_array($row[self::SIDE_KEY] ?? null, array_diff(self::SIDES, [...$sides, 'both', 'centerline', self::SIDE_UNSPECIFIED]), true))));
        $kept = array_flip(array_filter(array_column($selected['surfaces'], 'id'), 'is_string'));
        $selected['routes'] = array_values(array_filter(self::rows($scoped['routes'] ?? null), static function (array $row) use ($allSurfaces, $kept): bool {
            $places = array_values(array_filter([$row['from']['place'] ?? null, $row['to']['place'] ?? null], 'is_string'));

            return $allSurfaces || $places === [] || array_intersect($places, array_keys($kept)) !== [];
        }));
        $selected['basins'] = array_values(array_filter(self::rows($scoped['basins'] ?? null), static fn (array $row): bool => $allSurfaces || isset($kept[$row['surface'] ?? null])));
        $selected['permanent_secondary_geometry'] = self::rows($scoped['permanent_secondary_geometry'] ?? null);
        $selected['configuration_states'] = self::rows($scoped['configuration_states'] ?? null);
        $selected['must_preserve'] = self::rows($scoped['must_preserve'] ?? null);
        $selected['must_not_introduce'] = array_map(static fn (array $row): array => array_intersect_key($row, array_flip(['id', 'interpretation', 'instead', 'refs'])), self::rows($scoped['must_not_introduce'] ?? null));

        [$walked, $gaps] = self::withLandmarks($selected, $scoped);
        $landmarks = [];

        foreach ($walked as $list => $rows) {
            if (! is_array($rows) || ! array_is_list($rows)) {
                continue;
            }

            $walked[$list] = [];

            foreach ($rows as $row) {
                if (is_array($row) && ($row[self::LANDMARK_KEY] ?? false) === true) {
                    $landmarks[] = ['list' => $list] + array_diff_key($row, [self::LANDMARK_KEY => true]);
                } else {
                    $walked[$list][] = $row;
                }
            }
        }

        $order = array_flip(['P0', 'P1', 'P2']);
        $preserve = $walked['must_preserve'];
        usort($preserve, static fn (array $a, array $b): int => ($order[$a['priority'] ?? ''] ?? 3) <=> ($order[$b['priority'] ?? ''] ?? 3));

        $common = array_intersect_key($walked, array_flip(self::REFERENCE_COMMON_OBJECTS));
        $viewObjects = array_intersect_key($walked, array_flip(['hull_geometry', 'bow_geometry', 'stern_geometry']));

        return [$extract + [
            'common_geometry' => $common + [
                'decks' => $walked['decks'],
                'primary_masses' => $walked['primary_masses'],
                'primary_voids' => $walked['primary_voids'],
                'signature_regions' => $walked['signature_regions'],
            ],
            'view_geometry' => $viewObjects + [
                'openings' => $walked['openings'],
                'surfaces' => $walked['surfaces'],
                'routes' => $walked['routes'],
                'basins' => $walked['basins'],
                'permanent_secondary_geometry' => $walked['permanent_secondary_geometry'],
                'geometric_relationships' => $walked['geometric_relationships'] ?? [],
            ],
            'landmarks' => $landmarks,
            'configuration_states' => $walked['configuration_states'],
            'constraints' => ['must_preserve' => $preserve, 'must_not_introduce' => $walked['must_not_introduce']],
            'proof_for_view' => self::proofsForViews($scoped, $proofViews),
        ], $gaps];
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, list<string>|null>  $objectFields
     * @param  array<string, list<string>|null>  $listFields
     * @param  list<string>  $configurationFields
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function canonicalExtract(array $output, array $objectFields, array $listFields, array $configurationFields = self::STORY_CONFIGURATION_FIELDS): array
    {
        $locked = self::lockedDesign($output, null)[self::CANONICAL_KEY] ?? [];
        $source = $output[self::CANONICAL_KEY];
        $canonical = [];

        foreach ($objectFields as $key => $fields) {
            if (is_array($locked[$key] ?? null)) {
                $canonical[$key] = $fields === null ? $locked[$key] : array_intersect_key($locked[$key], array_flip($fields));
            }
        }

        if (is_array($source['appearance_identity'] ?? null) && ($source['appearance_identity']['status'] ?? null) === self::LOCKED) {
            $canonical['appearance_identity'] = array_diff_key($source['appearance_identity'], ['status' => true]);
        }

        foreach ($listFields as $key => $fields) {
            if (is_array($locked[$key] ?? null)) {
                $canonical[$key] = array_map(
                    static fn (array $row): array => $fields === null ? $row : array_intersect_key($row, array_flip($fields)),
                    self::rows($locked[$key]),
                );
            }
        }

        $components = array_flip(array_filter(array_column(self::rows($locked['configuration_states'] ?? null), 'component_id'), 'is_string'));
        $canonical['configuration_states'] = array_values(array_map(
            static fn (array $row): array => array_intersect_key($row, array_flip($configurationFields)),
            array_filter(self::rows($source['configuration_states'] ?? null), static fn (array $row): bool => isset($components[$row['component_id'] ?? null])),
        ));

        return self::withLandmarks($canonical, $locked);
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @param  array<string, mixed>  $locked
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function withLandmarks(array $canonical, array $locked): array
    {
        $index = [];

        foreach ([...self::ID_LISTS, self::TRANSITIONS_KEY => 'id'] as $list => $field) {
            foreach (self::rows($locked[$list] ?? null) as $row) {
                if (is_string($row[$field] ?? null)) {
                    $index[$row[$field]] = [$list, $row];
                }
            }
        }

        $present = array_fill_keys(self::RESERVED_IDS, true);
        $queue = [];

        foreach ($canonical as $list => $rows) {
            if (! is_array($rows) || ! array_is_list($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                $id = $row[self::ID_LISTS[$list] ?? 'id'] ?? null;

                if (is_string($id)) {
                    $present[$id] = true;
                }

                foreach (self::extractReferences($list, $row) as $ref) {
                    $queue[] = [$list, $id, $ref];
                }
            }
        }

        $gaps = [];

        while ($queue !== []) {
            [$fromList, $fromId, $ref] = array_shift($queue);

            if (isset($present[$ref])) {
                continue;
            }

            if (! isset($index[$ref])) {
                $gaps[] = "{$fromList} ".self::label($fromId).": references {$ref}, which the design does not hold as a locked part";
                $present[$ref] = true;

                continue;
            }

            [$list, $row] = $index[$ref];
            $present[$ref] = true;
            $canonical[$list][] = $row + [self::LANDMARK_KEY => true];

            foreach (self::extractReferences($list, $row) as $next) {
                $queue[] = [$list, $ref, $next];
            }
        }

        $canonical['geometric_relationships'] = array_values(array_filter(
            self::rows($locked['geometric_relationships'] ?? null),
            static fn (array $row): bool => isset($present[$row['subject'] ?? null], $present[$row['object'] ?? null]),
        ));

        return [$canonical, array_values(array_unique($gaps))];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private static function extractReferences(string $list, array $row): array
    {
        $refs = match ($list) {
            'routes' => [$row['from']['place'] ?? null, $row['to']['place'] ?? null, $row['from']['deck'] ?? null, $row['to']['deck'] ?? null],
            'surfaces' => [$row['deck'] ?? null, $row['relative_to'] ?? null],
            'basins' => [$row['surface'] ?? null, ...(array) ($row['access_routes'] ?? [])],
            'openings' => [$row['deck'] ?? null, $row['host'] ?? null, $row['serves'] ?? null],
            'decks' => [$row['body'] ?? null],
            'must_preserve', 'must_not_introduce' => (array) ($row['refs'] ?? []),
            'configuration_states' => [$row['feature_id'] ?? null],
            self::TRANSITIONS_KEY => (array) ($row['between'] ?? []),
            default => [],
        };

        return array_values(array_filter($refs, 'is_string'));
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>|null  $policy
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private static function scoped(array $output, ?array $policy): array
    {
        $extract = self::lockedDesign($output, $policy);
        $report = ['version' => self::ANCHOR_SCOPE_VERSION, 'design_content_hash' => self::contentHash($output), 'classified' => false, 'rows' => []];
        $canonical = $extract[self::CANONICAL_KEY] ?? null;

        if (! is_array($canonical)) {
            return [$extract, $report];
        }

        $classified = [];

        foreach (self::ROLE_CLASSIFIED_LISTS as $list) {
            foreach (self::rows($canonical[$list] ?? null) as $row) {
                if (is_string($row['id'] ?? null)) {
                    $role = $row[self::EXTERIOR_ROLE_KEY] ?? null;
                    $classified[$row['id']] = ['list' => $list, 'role' => in_array($role, self::EXTERIOR_ROLES, true) ? $role : null];
                    $report['classified'] = $report['classified'] || $classified[$row['id']]['role'] !== null;
                }
            }
        }

        $decisions = [];

        foreach ($classified as $id => $row) {
            $decisions[$id] = match ($row['role']) {
                null => ['render', 'unclassified: kept until the design classifies it'],
                'undetermined' => ['render', 'undetermined: kept until the design settles it'],
                'exterior' => ['render', 'exterior'],
                'interior_affects_exterior' => ['render', 'interior that shapes or shows on the exterior'],
                default => ['excluded', 'interior only'],
            };
        }

        $protect = static function (mixed $id, string $reason) use (&$decisions): void {
            if (is_string($id) && ($decisions[$id][0] ?? null) === 'excluded') {
                $decisions[$id] = ['render', $reason];
            }
        };

        foreach (['must_preserve', 'must_not_introduce'] as $key) {
            foreach (self::rows($canonical[$key] ?? null) as $row) {
                foreach ((array) ($row['refs'] ?? []) as $ref) {
                    $protect($ref, "interior only, kept: {$key} ".self::label($row['id'] ?? null).' names it');
                }
            }
        }

        foreach (self::rows($canonical['reference_proof_requirements'] ?? null) as $row) {
            $protect($row['target'] ?? null, 'interior only, kept: a reference proof requirement targets it');
        }

        foreach (self::rows($canonical['configuration_states'] ?? null) as $row) {
            $protect($row['feature_id'] ?? null, 'interior only, kept: a configuration state belongs to it');
        }

        $links = [];

        foreach (self::rows($canonical['geometric_relationships'] ?? null) as $index => $row) {
            $links[] = ['geometric_relationships', $index, [$row['subject'] ?? null, $row['object'] ?? null]];
        }

        foreach (self::rows($canonical[self::TRANSITIONS_KEY] ?? null) as $index => $row) {
            $links[] = [self::TRANSITIONS_KEY, $index, array_values((array) ($row['between'] ?? []))];
        }

        $references = static function (string $list, array $row): array {
            return match ($list) {
                'surfaces' => [$row['relative_to'] ?? null],
                'openings' => [$row['serves'] ?? null],
                'basins' => [$row['surface'] ?? null, ...(array) ($row['access_routes'] ?? [])],
                'routes' => [$row['from']['place'] ?? null, $row['to']['place'] ?? null],
                default => [],
            };
        };

        do {
            $changed = false;
            $kept = static fn (mixed $id): bool => is_string($id) && isset($decisions[$id]) && $decisions[$id][0] !== 'excluded';

            foreach (self::ROLE_CLASSIFIED_LISTS as $list) {
                foreach (self::rows($canonical[$list] ?? null) as $row) {
                    if (! $kept($row['id'] ?? null)) {
                        continue;
                    }

                    foreach ($references($list, $row) as $ref) {
                        if (is_string($ref) && ($decisions[$ref][0] ?? null) === 'excluded') {
                            $decisions[$ref] = ['support', "interior only, kept as a landmark: {$list} {$row['id']} refers to it"];
                            $changed = true;
                        }
                    }
                }
            }

            foreach ($links as [$key, $index, $ids]) {
                $anchored = array_filter($ids, static fn (mixed $id): bool => is_string($id) && ($decisions[$id][0] ?? null) === 'render');

                if ($anchored === []) {
                    continue;
                }

                foreach ($ids as $id) {
                    if (is_string($id) && ($decisions[$id][0] ?? null) === 'excluded') {
                        $decisions[$id] = ['support', "interior only, kept as a landmark: {$key}[{$index}] relates it to ".implode(', ', $anchored)];
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        $excluded = array_keys(array_filter($decisions, static fn (array $decision): bool => $decision[0] === 'excluded'));
        $equipment = [];

        foreach (self::rows($canonical['permanent_secondary_geometry'] ?? null) as $row) {
            if (is_string($row[self::EQUIPMENT_KIND_KEY] ?? null) && is_string($row['id'] ?? null)) {
                $equipment[] = $row['id'];
                $report['rows'][] = ['list' => 'permanent_secondary_geometry', 'id' => $row['id'], 'exterior_role' => null,
                    'decision' => 'excluded', 'reason' => self::EQUIPMENT_EXCLUDED_REASON];
            }
        }

        if ($equipment !== []) {
            $canonical['permanent_secondary_geometry'] = array_values(array_filter(
                self::rows($canonical['permanent_secondary_geometry']),
                static fn (array $row): bool => ! in_array($row['id'] ?? null, $equipment, true),
            ));
            $excluded = [...$excluded, ...$equipment];
        }

        foreach (self::ROLE_CLASSIFIED_LISTS as $list) {
            if (! is_array($canonical[$list] ?? null)) {
                continue;
            }

            $rows = [];

            foreach (self::rows($canonical[$list]) as $row) {
                $id = $row['id'] ?? null;

                if (in_array($id, $excluded, true)) {
                    continue;
                }

                if (is_string($id) && ($decisions[$id][0] ?? null) === 'support') {
                    $row[self::ANCHOR_ROLE_KEY] = self::ANCHOR_SUPPORT;
                }

                $rows[] = $row;
            }

            $canonical[$list] = $rows;
        }

        foreach ($links as [$key, $index, $ids]) {
            $dropped = array_values(array_intersect($ids, $excluded));

            if ($dropped !== []) {
                $report['rows'][] = ['list' => $key, 'id' => (string) ($canonical[$key][$index]['id'] ?? "{$key}[{$index}]"), 'exterior_role' => null,
                    'decision' => 'excluded', 'reason' => (array_intersect($dropped, $equipment) === [] ? 'relates interior-only parts' : 'relates finishing equipment')
                        .' that the anchor leaves out: '.implode(', ', $dropped)];
                unset($canonical[$key][$index]);
            }
        }

        foreach ([self::TRANSITIONS_KEY, 'geometric_relationships'] as $key) {
            if (is_array($canonical[$key] ?? null)) {
                $canonical[$key] = array_values($canonical[$key]);
            }
        }

        foreach ($decisions as $id => [$decision, $reason]) {
            $report['rows'][] = ['list' => $classified[$id]['list'], 'id' => (string) $id, 'exterior_role' => $classified[$id]['role'], 'decision' => $decision, 'reason' => $reason];
        }

        $extract[self::CANONICAL_KEY] = $canonical;

        return [$extract, $report];
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>|null  $policy
     * @return array<string, mixed>
     */
    public static function lockedDesign(array $output, ?array $policy): array
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

        $lists = self::geometryModel($output) === self::GEOMETRY_TYPED
            ? [...self::ANCHOR_LISTS, ...self::TYPED_LISTS]
            : self::ANCHOR_LISTS;

        foreach ($lists as $key) {
            $kept[$key] = array_values(array_map(
                static function (array $row): array {
                    unset($row['status'], $row[self::PROFILE_PATHS_KEY]);

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

        $keptJoints = self::transitionIds($kept);
        $missing = static function (mixed $id, string $where, bool $jointAllowed = false) use (&$violations, $present, $keptJoints): void {
            if (is_string($id) && (isset($present[$id]) || ($jointAllowed && isset($keptJoints[$id])))) {
                return;
            }

            $violations[] = is_string($id) && isset($keptJoints[$id])
                ? "{$where} names {$id}, a transition where a part is required"
                : "{$where} names ".self::label($id).', which the extract does not contain';
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

        foreach (self::rows($kept[self::TRANSITIONS_KEY] ?? null) as $index => $row) {
            foreach ((array) ($row['between'] ?? []) as $id) {
                $missing($id, "transitions[{$index}].between");
            }
        }

        foreach (['must_preserve', 'must_not_introduce'] as $key) {
            foreach (self::rows($kept[$key] ?? null) as $index => $row) {
                foreach ((array) ($row['refs'] ?? []) as $ref) {
                    $missing($ref, "{$key}[{$index}].refs", true);
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
     * @return array<string, true>
     */
    public static function p0Scope(array $output): array
    {
        $canonical = is_array($output[self::CANONICAL_KEY] ?? null) ? $output[self::CANONICAL_KEY] : [];
        $scope = [];

        foreach (self::rows($canonical['must_preserve'] ?? null) as $row) {
            if (($row['priority'] ?? null) !== 'P0' || ! is_string($row['id'] ?? null)) {
                continue;
            }

            $scope[$row['id']] = true;

            foreach ((array) ($row['refs'] ?? []) as $ref) {
                if (is_string($ref) && ! in_array($ref, self::RESERVED_IDS, true)) {
                    $scope[$ref] = true;
                }
            }
        }

        foreach (self::rows($canonical['signature_regions'] ?? null) as $region) {
            if (($region['priority'] ?? null) === 'P0' && is_string($region['feature_id'] ?? null)) {
                $scope[$region['feature_id']] = true;
            }
        }

        return $scope;
    }

    /**
     * @param  array<string, mixed>  $extract
     * @param  list<string>  $views
     * @return list<array{proof_id: string, target: string, index: int, proof_condition: string}>
     */
    public static function proofRequirementsFor(array $extract, array $views): array
    {
        $proofs = [];
        $used = [];

        foreach (self::rows($extract['reference_proof_requirements'] ?? null) as $index => $row) {
            $target = $row['target'] ?? null;

            if (! is_string($target) || array_intersect((array) ($row['views'] ?? []), $views) === []) {
                continue;
            }

            $proofId = isset($used[$target]) ? "{$target}_{$index}" : $target;
            $used[$target] = true;
            $proofs[] = [
                'proof_id' => $proofId,
                'target' => $target,
                'index' => $index,
                'proof_condition' => (string) ($row['proof_condition'] ?? ''),
            ];
        }

        return $proofs;
    }

    /**
     * @param  array<string, mixed>  $extract
     * @return list<string>
     */
    public static function extractIds(array $extract): array
    {
        $ids = [];

        foreach (self::ID_LISTS as $key => $field) {
            foreach (self::rows($extract[$key] ?? null) as $row) {
                if (is_string($row[$field] ?? null)) {
                    $ids[] = $row[$field];
                }
            }
        }

        foreach ([self::TRANSITIONS_KEY => 'id', 'must_preserve' => 'id'] as $key => $field) {
            foreach (self::rows($extract[$key] ?? null) as $row) {
                if (is_string($row[$field] ?? null)) {
                    $ids[] = $row[$field];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public static function screenplayGeometry(array $output): array
    {
        $kept = self::lockedDesign($output, null)[self::CANONICAL_KEY] ?? [];

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
        $names = self::profileFeatureKinds($design);
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
        $joints = self::transitionIds($canonical);
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
                $wrong = self::partViolation($id, $known, $joints, "{$path}.{$side}");

                if ($wrong !== null) {
                    $violations[] = $wrong;
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
        $joints = self::transitionIds($canonical);
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
                $wrong = self::partViolation($id, $known, $joints, "{$path}.between");

                if ($wrong !== null) {
                    $violations[] = $wrong;
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
        $known = self::protectableIds($canonical);
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
        $known = self::protectableIds($canonical);
        $ruleIds = array_column(self::rows($canonical['must_preserve'] ?? null), 'id');
        $locked = self::lockedId($canonical);
        $rows = self::rows($canonical['must_not_introduce'] ?? null);
        $violations = $rows === [] ? [self::CANONICAL_KEY.'.must_not_introduce: is empty'] : [];

        foreach ($rows as $index => $row) {
            foreach ((array) ($row['refs'] ?? []) as $ref) {
                if (is_string($ref) && ! isset($known[$ref]) && in_array($ref, $ruleIds, true)) {
                    $violations[] = self::CANONICAL_KEY.".must_not_introduce[{$index}].refs: {$ref} is a must_preserve rule id; refs must reference geometry parts or locked transitions";
                } elseif (! is_string($ref) || ! isset($known[$ref])) {
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

        $joints = [];

        foreach (array_keys(self::transitionIds($canonical)) as $id) {
            if (isset($known[$id]) || isset($joints[$id])) {
                $violations[] = self::CANONICAL_KEY.": id {$id} is used for more than one part";
            }

            $joints[$id] = true;
        }

        return [$known, $violations];
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @return array<string, true>
     */
    private static function transitionIds(array $canonical): array
    {
        $ids = [];

        foreach (self::rows($canonical[self::TRANSITIONS_KEY] ?? null) as $row) {
            if (is_string($row['id'] ?? null)) {
                $ids[$row['id']] = true;
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @return array<string, true>
     */
    private static function protectableIds(array $canonical): array
    {
        [$known] = self::knownIds($canonical);

        return $known + self::transitionIds($canonical);
    }

    /**
     * @param  array<string, true>  $known
     * @param  array<string, true>  $joints
     */
    private static function partViolation(mixed $id, array $known, array $joints, string $where): ?string
    {
        if (is_string($id) && isset($known[$id])) {
            return null;
        }

        return is_string($id) && isset($joints[$id])
            ? "{$where}: {$id} is a transition, which joins parts and cannot itself be a part"
            : "{$where}: ".self::label($id).' is not a declared id';
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

        foreach (self::rows($canonical[self::TRANSITIONS_KEY] ?? null) as $row) {
            if (is_string($row['id'] ?? null) && is_string($row['status'] ?? null) && ! isset($statuses[$row['id']])) {
                $statuses[$row['id']] = $row['status'];
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
        if (self::geometryModel($design) !== self::GEOMETRY_TYPED) {
            return self::profileFeatureKinds($design);
        }

        $kinds = [];

        foreach (self::rows($design[self::CANONICAL_KEY]['signature_regions'] ?? null) as $region) {
            if (is_string($region['feature'] ?? null)) {
                $kinds[$region['feature']] = (string) ($region['kind'] ?? '');
            }
        }

        return $kinds;
    }

    /**
     * @param  array<string, mixed>  $design
     * @return array<string, string>
     */
    private static function profileFeatureKinds(array $design): array
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

    /**
     * @param  array<string, mixed>  $canonical
     * @return list<array{index: int, id: string, other: string, verdict: string, side: ?string, other_side: ?string}>
     */
    private static function surfaceCollisions(array $canonical): array
    {
        $placements = [];
        $collisions = [];

        foreach (self::rows($canonical['surfaces'] ?? null) as $index => $surface) {
            $placement = json_encode([$surface['deck'] ?? null, $surface['kind'] ?? null, $surface['relation'] ?? null, $surface['relative_to'] ?? null]);
            $side = self::declaredSide($surface);
            $id = (string) ($surface['id'] ?? '');

            foreach ($placements[$placement] ?? [] as [$other, $otherSide]) {
                $verdict = self::sidesApart($side, $otherSide);

                if ($verdict !== 'distinct') {
                    $collisions[] = ['index' => $index, 'id' => $id, 'other' => $other, 'verdict' => $verdict, 'side' => $side, 'other_side' => $otherSide];
                }
            }

            $placements[$placement][] = [$id, $side];
        }

        return $collisions;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<string>
     */
    public static function anchorBlockers(array $output): array
    {
        return array_keys(array_filter([
            'anchor_design_unresolved' => self::unresolvedDecisions($output) !== [],
            'anchor_design_incomplete_text' => self::incompleteTexts($output) !== [],
            'anchor_design_placement_ambiguous' => self::placementGaps($output) !== [],
        ]));
    }

    /**
     * @param  array<string, mixed>  $design
     * @return list<array{id: string, question: string}>
     */
    public static function placementDecisions(array $design): array
    {
        if (self::geometryModel($design) !== self::GEOMETRY_TYPED) {
            return [];
        }

        $decisions = [];

        foreach (self::surfaceCollisions($design[self::CANONICAL_KEY]) as $collision) {
            if ($collision['verdict'] === 'same') {
                continue;
            }

            $why = $collision['verdict'] === 'overlap'
                ? 'one of them spans both sides, so their extents overlap'
                : 'the side of at least one of them is not declared';
            $decisions[] = [
                'id' => self::PLACEMENT_DECISION_PREFIX."{$collision['other']}_{$collision['id']}",
                'question' => "{$collision['other']} and {$collision['id']} share deck, kind, relation and reference, and {$why}: which side does each lie on, or are they one surface?",
                'source' => self::SYSTEM_DECISION_SOURCE,
                'rule' => self::PLACEMENT_RULE,
            ];
        }

        return $decisions;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    public static function equipmentPolicyViolations(array $profile): array
    {
        if (! array_key_exists(self::EQUIPMENT_POLICY_KEY, $profile)) {
            return [];
        }

        $policy = $profile[self::EQUIPMENT_POLICY_KEY];
        $at = self::EQUIPMENT_POLICY_KEY;

        if (! is_array($policy) || array_is_list($policy)) {
            return ["{$at}: must be an object (configuration error)"];
        }

        $violations = [];

        if (($policy['version'] ?? null) !== self::EQUIPMENT_POLICY_VERSION) {
            $violations[] = "{$at}.version: must be ".self::EQUIPMENT_POLICY_VERSION.' (configuration error)';
        }

        if (! in_array($policy['interdeck_elevators'] ?? null, self::EQUIPMENT_POLICY_VALUES, true)) {
            $violations[] = "{$at}.interdeck_elevators: must be one of ".implode(', ', self::EQUIPMENT_POLICY_VALUES).' (configuration error)';
        }

        foreach (array_diff(array_keys($policy), ['version', 'interdeck_elevators']) as $key) {
            $violations[] = "{$at}.{$key}: is not a known policy field (configuration error)";
        }

        return $violations;
    }

    /** @param array<string, mixed> $profile */
    public static function elevatorsForbidden(array $profile): bool
    {
        return self::equipmentPolicyViolations($profile) === []
            && ($profile[self::EQUIPMENT_POLICY_KEY]['interdeck_elevators'] ?? null) === self::EQUIPMENT_FORBIDDEN;
    }

    /**
     * @param  array<string, mixed>  $design
     * @return list<array{path: string, verdict: string, text: string}>
     */
    public static function equipmentFindings(array $design): array
    {
        $findings = [];

        foreach (self::EQUIPMENT_SCAN_SECTIONS as $section) {
            foreach (self::textPaths($design[$section] ?? [], $section, self::EQUIPMENT_SKIPPED_KEYS) as $path => $text) {
                foreach (preg_split('/(?<=[.!?;])\s+/u', $text) ?: [] as $sentence) {
                    $verdict = self::equipmentVerdict($sentence);

                    if ($verdict !== null) {
                        $findings[] = ['path' => $path, 'verdict' => $verdict, 'text' => trim($sentence)];
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $design
     * @return list<array{path: string, text: string}>
     */
    public static function mastFindings(array $design): array
    {
        $findings = [];

        foreach (self::EQUIPMENT_SCAN_SECTIONS as $section) {
            foreach (self::textPaths($design[$section] ?? [], $section, self::MAST_SKIPPED_KEYS) as $path => $text) {
                foreach (preg_split('/(?<=[.!?;])\s+/u', $text) ?: [] as $sentence) {
                    if (self::affirmedTerm($sentence, self::MAST_TERMS)) {
                        $findings[] = ['path' => $path, 'text' => trim($sentence)];
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<array{0: list<string>, 1: int, 2: int}>
     */
    private static function clauseSegments(string $sentence): array
    {
        $clauses = [];

        foreach (preg_split('/[;:]|,?\s(?:but|while|whereas|yet|although|though|however)\s/iu', $sentence) ?: [] as $clause) {
            $segments = array_values(array_filter(array_map('trim', explode(',', $clause)), static fn (string $segment): bool => $segment !== ''));
            $clauses[] = [$segments, ...self::negatedListSpan($segments)];
        }

        return $clauses;
    }

    private static function affirmedTerm(string $sentence, string $pattern): bool
    {
        foreach (self::clauseSegments($sentence) as [$segments, $listEnd, $carryEnd]) {
            $negatedList = false;

            foreach ($segments as $index => $segment) {
                if ($index > 0 && ($index <= $listEnd || $index <= $carryEnd)) {
                    continue;
                }

                if ($negatedList && preg_match(self::INDEPENDENT_START, $segment) !== 1) {
                    $negatedList = preg_match('/\b(?:or|nor|and)\b/iu', $segment) !== 1;

                    continue;
                }

                if (self::affirmedInList($segment, $pattern)) {
                    return true;
                }

                $negatedList = preg_match(self::NEGATION, $segment) === 1;
            }
        }

        return false;
    }

    private static function affirmedInList(string $clause, string $pattern): bool
    {
        preg_match_all($pattern, $clause, $matches, PREG_OFFSET_CAPTURE);
        $negatedEnd = null;

        foreach ($matches[0] as [$text, $offset]) {
            $continues = $negatedEnd !== null && preg_match(
                '/^\s*(?:,\s*)?(?:or|nor|and)?\s*(?:(?:a|an|the|any)\s+)?(?:[\w-]+\s+)?$/iu',
                substr($clause, $negatedEnd, $offset - $negatedEnd),
            ) === 1;

            if (! $continues && ! self::negatedAt($clause, $offset)) {
                return true;
            }

            $negatedEnd = $offset + strlen($text);
        }

        return false;
    }

    private static function negatedAt(string $clause, int $offset): bool
    {
        $before = array_slice(preg_split('/\s+/u', trim(substr($clause, 0, $offset))) ?: [], -3);

        return preg_match(self::NEGATION, implode(' ', $before)) === 1;
    }

    private static function affirmedIn(string $clause, string $pattern): bool
    {
        preg_match_all($pattern, $clause, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [, $offset]) {
            if (! self::negatedAt($clause, $offset)) {
                return true;
            }
        }

        return false;
    }

    private static function equipmentVerdict(string $sentence): ?string
    {
        $verdicts = [];

        foreach (self::clauseSegments($sentence) as [$segments, $listEnd, $carryEnd]) {
            foreach ($segments as $index => $segment) {
                $verdict = self::clauseVerdict($segment);

                if ($verdict !== null && $index > 0 && $index <= $listEnd) {
                    $verdict = null;
                } elseif ($verdict !== null && $index > 0 && $index <= $carryEnd) {
                    $verdict = 'review';
                }

                $verdicts[] = $verdict;
            }
        }

        return match (true) {
            in_array('equipment', $verdicts, true) => 'equipment',
            in_array('review', $verdicts, true) => 'review',
            default => null,
        };
    }

    /**
     * @param  list<string>  $segments
     * @return array{0: int, 1: int}
     */
    private static function negatedListSpan(array $segments): array
    {
        if (count($segments) < 2 || preg_match(self::NEGATED_LIST_START, $segments[0]) !== 1) {
            return [-1, -1];
        }

        for ($index = 1; $index < count($segments); $index++) {
            if (preg_match(self::INDEPENDENT_START, $segments[$index]) === 1) {
                return [-1, $index - 1];
            }

            if (preg_match('/\b(?:or|nor|and)\b/iu', $segments[$index]) === 1) {
                return [$index, -1];
            }
        }

        return [-1, count($segments) - 1];
    }

    private static function clauseVerdict(string $clause): ?string
    {
        $affirmed = static fn (string $pattern): bool => self::affirmedIn($clause, $pattern);

        if ($affirmed(self::ELEVATOR_TERMS)) {
            return 'equipment';
        }

        if (preg_match(self::CARRIED_BETWEEN_DECKS, $clause, $carry, PREG_OFFSET_CAPTURE) === 1 && ! self::negatedAt($clause, $carry[0][1])) {
            $subject = substr($clause, 0, $carry[0][1]);

            return match (true) {
                preg_match(self::CARRIER_WORDS, $subject) === 1 => 'equipment',
                preg_match(self::STAIR_WORDS, $subject) === 1 => null,
                default => 'review',
            };
        }

        if ($affirmed(self::LIFT_NOUN)) {
            return 'review';
        }

        if ($affirmed(self::HOIST_TERM) && preg_match('/\b(?:decks?|levels?|guests?|passengers?|people|crew)\b/iu', $clause) === 1) {
            return 'review';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    public static function equipmentViolations(array $design, array $profile): array
    {
        $violations = self::equipmentPolicyViolations($profile);

        if ($violations !== [] || ! self::elevatorsForbidden($profile)) {
            return $violations;
        }

        foreach (self::rows($design[self::CANONICAL_KEY]['routes'] ?? null) as $index => $route) {
            if (($route['kind'] ?? null) === 'lift') {
                $violations[] = self::CANONICAL_KEY.".routes[{$index}].kind: elevator equipment is forbidden by the source design policy";
            }
        }

        foreach (self::equipmentFindings($design) as $finding) {
            if ($finding['verdict'] === 'equipment') {
                $violations[] = "{$finding['path']}: elevator equipment is forbidden by the source design policy: \"{$finding['text']}\"";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>  $profile
     * @return list<array{id: string, question: string}>
     */
    public static function equipmentDecisions(array $design, array $profile): array
    {
        if (! self::elevatorsForbidden($profile)) {
            return [];
        }

        $decisions = [];

        foreach (self::equipmentFindings($design) as $finding) {
            if ($finding['verdict'] === 'review') {
                $decisions[] = [
                    'id' => self::EQUIPMENT_DECISION_PREFIX.substr(hash('sha256', $finding['path'].'|'.$finding['text']), 0, 12),
                    'question' => "{$finding['path']} may describe elevator equipment, which the source design policy forbids: \"{$finding['text']}\". Is it a lift, or ordinary wording?",
                    'source' => self::SYSTEM_DECISION_SOURCE,
                    'rule' => self::EQUIPMENT_RULE,
                ];
            }
        }

        return $decisions;
    }

    /**
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public static function withSystemDecisions(array $design, array $profile = []): array
    {
        $kept = array_values(array_filter(
            self::unresolvedDecisions($design),
            static fn (mixed $decision): bool => ! is_array($decision)
                || ($decision['source'] ?? null) !== self::SYSTEM_DECISION_SOURCE
                || ! in_array($decision['rule'] ?? null, [self::PLACEMENT_RULE, self::EQUIPMENT_RULE], true),
        ));
        $taken = array_column(array_filter($kept, 'is_array'), 'id');
        $generated = array_values(array_filter(
            [...self::placementDecisions($design), ...self::equipmentDecisions($design, $profile)],
            static fn (array $decision): bool => ! in_array($decision['id'], $taken, true),
        ));
        $decisions = [...$kept, ...$generated];

        if ($decisions === []) {
            unset($design[self::UNRESOLVED_KEY]);
        } else {
            $design[self::UNRESOLVED_KEY] = $decisions;
        }

        return $design;
    }

    /** @param array<string, mixed> $surface */
    public static function declaredSide(array $surface): ?string
    {
        $side = $surface[self::SIDE_KEY] ?? null;

        return in_array($side, self::SIDES, true) && $side !== self::SIDE_UNSPECIFIED ? $side : null;
    }

    private static function sidesApart(?string $first, ?string $second): string
    {
        return match (true) {
            $first === null || $second === null => 'unknown',
            $first === $second => 'same',
            $first === 'both' || $second === 'both' => 'overlap',
            default => 'distinct',
        };
    }
}
