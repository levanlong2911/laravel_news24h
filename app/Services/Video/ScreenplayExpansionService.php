<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Video\Prompt\Exceptions\TextCompletionException;
use App\Video\Screenplay\FilmBrief;
use App\Video\Screenplay\LocationProfile;
use App\Video\Screenplay\ProtagonistProfile;
use App\Video\Screenplay\ScreenplayAuthor;
use App\Video\Screenplay\ScreenplayFailure;
use App\Video\Screenplay\ScreenplayResult;
use App\Video\Screenplay\ScreenplayValidator;
use App\Video\Screenplay\VesselDesign;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

final class ScreenplayExpansionService
{
    public const CURL_OPERATION_TIMEDOUT = ScreenplayStepRunner::CURL_OPERATION_TIMEDOUT;

    /** @var list<string> */
    private const SCENE_SECTIONS = ['characters', 'locations', 'scenes', 'coverage'];

    /** @var list<string> */
    private const FOUNDATION_SECTIONS = [
        'logline', 'design_thesis', 'principal_dimensions', 'premise',
        'synopsis', 'stage_treatments', 'ending',
    ];

    private const SPACE_PLAN_SECTION = 'space_plan';

    public const PROFILE_SNAPSHOT_KEY = 'screenplay_profile';

    /** @var list<string> */
    private const FOUNDATION_PROFILE_KEYS = [
        'contract_version', 'subject_class', 'objective', 'arc_stages',
        'arc_required_stages', 'originality', 'identity_dimensions', 'design_requirements',
        'people_policy', 'concept_antipatterns', 'concept_forbidden_terms',
    ];

    /** @var array<string, array{stage: PlanningStageName, author: string}> */
    private const CAST = [
        'characters' => [
            'stage' => PlanningStageName::SCREENPLAY_CHARACTERS,
            'author' => 'video.screenplay.character_author',
        ],
        'locations' => [
            'stage' => PlanningStageName::SCREENPLAY_LOCATIONS,
            'author' => 'video.screenplay.location_author',
        ],
    ];

    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly VideoProjectRepositoryInterface $projects,
        private readonly CreativeProfileResolver $creativeProfiles,
        private readonly ScreenplayStepRunner $runner,
    ) {}

    /** @return array{0: ?array<string, mixed>, 1: string} */
    public function authorCharacters(string $projectId, ?string $foundationStageId, bool $force = false): array
    {
        return $this->authorCast($projectId, 'characters', $foundationStageId, null, $force);
    }

    /** @return array{0: ?array<string, mixed>, 1: string} */
    public function authorLocations(
        string $projectId,
        ?string $foundationStageId,
        ?string $charactersStageId,
        bool $force = false,
    ): array {
        return $this->authorCast($projectId, 'locations', $foundationStageId, $charactersStageId, $force);
    }

    /** @return array{0: ?array<string, mixed>, 1: string} */
    public function authorScenes(
        string $projectId,
        ?string $foundationStageId,
        ?string $charactersStageId,
        ?string $locationsStageId,
        bool $force = false,
    ): array {
        [$context, $reason] = $this->prepare($projectId, $foundationStageId, 'video.screenplay.scene_author', true);

        if ($context === null) {
            return [null, $reason];
        }

        $characters = $this->selectedCast($projectId, 'characters', $charactersStageId, $context['foundation_hash']);

        if ($characters === null) {
            return [null, 'screenplay_characters_not_selectable'];
        }

        if ($this->charactersRuleViolations($characters->output_json['characters'], $context['profile'], $context['foundation']) !== []) {
            return [null, 'screenplay_characters_outdated'];
        }

        $locations = $this->selectedCast($projectId, 'locations', $locationsStageId, $context['foundation_hash']);

        if ($locations === null) {
            return [null, 'screenplay_locations_not_selectable'];
        }

        if (! self::builtFrom($locations, $characters)) {
            return [null, 'screenplay_locations_outdated'];
        }

        if (! LocationProfile::allProfiled($locations->output_json['locations'])) {
            return [null, 'screenplay_locations_unprofiled'];
        }

        if (FilmBrief::profileSpaces($context['profile']) !== []
            && (new ScreenplayValidator)->briefSpaceViolations($locations->output_json['locations'], $context['profile']) !== []) {
            return [null, 'screenplay_locations_without_brief_spaces'];
        }

        $author = $context['author'];
        $contract = $context['contract'];
        $assembledVersion = (string) config('video.screenplay.scenes.assembled_version');
        $fixed = [
            'characters' => $characters->output_json['characters'],
            'locations' => $locations->output_json['locations'],
        ];

        $silence = self::silenceReason($context['profile'], $fixed['characters']);
        $constrained = self::sceneSchemaFor($author->contractSchema(), $fixed['characters'], $fixed['locations'], $silence === null);
        [$sentContext, $sentFixed, $requestReason] = self::designRequest('scenes', $projectId, $context, $fixed);

        if ($sentContext === null) {
            return [null, $requestReason];
        }

        $input = [
            'contract_version' => $contract,
            'target_schema_version' => $assembledVersion,
            'foundation_content_hash' => $context['foundation_hash'],
            'characters_content_hash' => self::contentHash($fixed['characters']),
            'locations_content_hash' => self::contentHash($fixed['locations']),
            'output_schema_hash' => self::contentHash($constrained),
            'fingerprint' => $author->fingerprint($sentContext['foundation'], $sentContext['profile'], $sentContext['requirements'], $sentFixed),
        ];

        $meta = $context['meta'] + [
            'characters_stage_id' => $characters->id,
            'locations_stage_id' => $locations->id,
            VesselDesign::SCENE_SOURCE_KEY => [
                'foundation' => $sentContext['foundation'],
                'characters' => $sentFixed['characters'],
                'locations' => $sentFixed['locations'],
                'profile' => $sentContext['profile'],
                'film_requirements' => $sentContext['requirements'],
            ],
        ];

        return $this->runStep(
            $projectId,
            PlanningStageName::SCREENPLAY,
            'scenes',
            $author,
            $sentContext,
            $sentFixed,
            $input,
            $meta,
            $force,
            static fn (ScreenplayResult $result): array => self::validateAndAssembleScenes(
                $result->screenplay, $result->authorModel, $context, $contract, $assembledVersion,
                $fixed, $input, $characters, $locations,
            ),
            $constrained,
        );
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $fixed
     * @param  array<string, mixed>  $input
     * @return array{0: ?string, 1: ?array<string, mixed>, 2: string}
     */
    public static function validateAndAssembleScenes(
        array $screenplay,
        string $authorModel,
        array $context,
        string $contract,
        string $assembledVersion,
        array $fixed,
        array $input,
        VideoPlanningStage $characters,
        VideoPlanningStage $locations,
    ): array {
        $validator = new ScreenplayValidator;
        $silence = self::silenceReason($context['profile'], $fixed['characters']);
        $violations = [];

        foreach (array_keys($fixed) as $supplied) {
            if (array_key_exists($supplied, $screenplay)) {
                $violations[] = "{$supplied}: this step does not produce it";
            }
        }

        $expansion = $fixed + $screenplay;

        if ($silence !== null) {
            [$expansion, $silenced] = self::withoutDialogue($expansion, $silence);
            $violations = array_merge($violations, $silenced);
        }

        $violations = array_merge(
            $violations,
            $validator->structural($expansion, $context['profile'], $contract, $context['excluded']),
        );

        if ($violations !== []) {
            return ['Scene expansion failed validation: '.implode('; ', $violations), null, ''];
        }

        $assembled = $context['foundation'];

        foreach (self::SCENE_SECTIONS as $section) {
            $assembled[$section] = $expansion[$section];
        }

        $assembledErrors = $validator->structural(
            $assembled, $context['assembled_profile'], $assembledVersion, $context['excluded'],
        );

        if ($assembledErrors !== []) {
            return ['Assembled screenplay failed validation: '.implode('; ', $assembledErrors), null, ''];
        }

        $warnings = $validator->editorial($assembled, $context['assembled_profile']);

        return [null, $assembled + [
            'schema_version' => $assembledVersion,
            'author_model' => $authorModel,
            'source_foundation' => $context['source_foundation'],
            'source_characters' => [
                'stage_id' => $characters->id,
                'revision' => $characters->planning_revision,
                'content_hash' => $input['characters_content_hash'],
            ],
            'source_locations' => [
                'stage_id' => $locations->id,
                'revision' => $locations->planning_revision,
                'content_hash' => $input['locations_content_hash'],
            ],
            'warnings' => $warnings,
        ] + $context['design_sources'], $warnings === [] ? 'ok' : 'ok_needs_review'];
    }

    /**
     * @return array{rows: ?list<array<string, mixed>>, stage_id: ?string, revision: ?int, running: bool,
     *               error: ?string, written_at: ?string, foundation_stage_id: ?string, foundation_revision: ?int,
     *               characters_revision: ?int, usable: bool, problem: ?string}
     */
    public function latestCast(
        string $projectId,
        string $part,
        ?string $foundationStageId = null,
        ?string $charactersStageId = null,
    ): array {
        $stage = self::CAST[$part]['stage'];

        [$attempt] = $this->stageStore->latestStageForProject($projectId, $stage, [], skipOrphans: true);

        $latest = VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', $stage->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->orderByDesc('planning_revision')
            ->first();

        $rows = is_array($latest?->output_json) ? ($latest->output_json[$part] ?? null) : null;
        $rows = is_array($rows) && array_is_list($rows) && $rows !== [] ? $rows : null;
        $source = is_array($latest?->input_json) ? ($latest->input_json['_meta'] ?? []) : [];
        $problem = $rows !== null
            ? $this->castProblem($projectId, $part, $latest, $foundationStageId, $charactersStageId)
            : null;

        return [
            'rows' => $rows,
            'stage_id' => $rows !== null ? $latest->id : null,
            'revision' => $rows !== null ? (int) $latest->planning_revision : null,
            'running' => $attempt?->status === VideoPlanningStageStatus::RUNNING->value
                && $attempt->lease_expires_at?->isFuture() === true,
            'error' => $attempt?->status === VideoPlanningStageStatus::FAILED->value
                ? $attempt->error_message
                : null,
            'written_at' => $rows !== null ? $latest->finished_at?->format('d/m/Y H:i') : null,
            'foundation_stage_id' => $rows !== null ? ($source['foundation_stage_id'] ?? null) : null,
            'foundation_revision' => $rows !== null ? ($source['foundation_revision'] ?? null) : null,
            'characters_revision' => $rows !== null ? ($source['characters_revision'] ?? null) : null,
            'usable' => $rows !== null && $problem === null,
            'problem' => $problem,
        ];
    }

    /** @return array{0: bool, 1: string} */
    public function resetCast(string $projectId, string $part): array
    {
        [$latest] = $this->stageStore->latestStageForProject(
            $projectId, self::CAST[$part]['stage'], [], skipOrphans: true,
        );

        if ($latest === null) {
            return [false, 'Chua co luot nao de reset'];
        }

        return $this->stageStore->releaseClaim($latest->id, 'Nguoi dung reset thu cong')
            ? [true, 'ok']
            : [false, 'Luot nay khong con giu claim — khong co gi de reset'];
    }

    /** @return array<string, mixed>|null */
    public function screenplayProfile(string $category): ?array
    {
        $key = config("video.screenplay.profiles.{$category}");

        if (! is_string($key) || $key === '') {
            return null;
        }

        $path = rtrim((string) config('video.screenplay.profile_dir'), '/\\')
            .DIRECTORY_SEPARATOR.$key.'.json';

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return null;
        }

        $creative = $this->creativeProfiles->resolve($category);

        if ($creative === null || $creative->arcStages === []) {
            return null;
        }

        $defaults = [
            'objective' => $creative->conceptMission,
            'concept_antipatterns' => $creative->conceptAntipatterns,
            'concept_forbidden_terms' => $creative->conceptForbiddenTerms,
        ];

        if (($decoded['contract_version'] ?? null) === 'screenplay_v2') {
            $defaults['arc_stages'] = $creative->arcStages;
            $defaults['arc_required_stages'] = $creative->arcRequiredStages;
        }

        return $decoded + $defaults;
    }

    public function curlErrorNumber(ConnectionException $e): ?int
    {
        return $this->runner->curlErrorNumber($e);
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    public function recordFailure(
        string $projectId,
        string $stageId,
        string $token,
        string $error,
        string $reason,
        array $usage = [],
        string $rawResponse = '',
    ): string {
        return $this->runner->recordFailure($projectId, $stageId, $token, $error, $reason, $usage, $rawResponse);
    }

    /** @return array{0: ?array<string, mixed>, 1: string} */
    private function authorCast(
        string $projectId,
        string $part,
        ?string $foundationStageId,
        ?string $charactersStageId,
        bool $force,
    ): array {
        [$context, $reason] = $this->prepare($projectId, $foundationStageId, self::CAST[$part]['author'], false);

        if ($context === null) {
            return [null, $reason];
        }

        $author = $context['author'];
        $contract = $context['contract'];
        $withProfile = $part === 'characters' && ProtagonistProfile::enabled($context['profile']);
        $schema = self::castSchemaFor($author->contractSchema(), $part, $withProfile, $context['film_brief']);
        $fixed = [];
        $meta = $context['meta'];
        $sourceCharacters = null;

        if ($part === 'locations') {
            $characters = $this->selectedCast($projectId, 'characters', $charactersStageId, $context['foundation_hash']);

            if ($characters === null) {
                return [null, 'screenplay_characters_not_selectable'];
            }

            if ($this->charactersRuleViolations($characters->output_json['characters'], $context['profile'], $context['foundation']) !== []) {
                return [null, 'screenplay_characters_outdated'];
            }

            $fixed = ['characters' => $characters->output_json['characters']];
            $meta += [
                'characters_stage_id' => $characters->id,
                'characters_revision' => $characters->planning_revision,
            ];
            $sourceCharacters = [
                'stage_id' => $characters->id,
                'revision' => $characters->planning_revision,
                'content_hash' => self::contentHash($fixed['characters']),
            ];
        }

        $sentContext = $context;
        $sentFixed = $fixed;

        if ($part === 'locations') {
            [$sentContext, $sentFixed, $requestReason] = self::locationRequest($projectId, $context, $fixed);

            if ($sentContext === null) {
                return [null, $requestReason];
            }

            $meta[VesselDesign::LOCATION_SOURCE_KEY] = [
                'foundation' => $sentContext['foundation'],
                'characters' => $sentFixed['characters'],
                'profile' => $sentContext['profile'],
                'film_requirements' => $sentContext['requirements'],
            ];
        }

        $input = [
            'contract_version' => $contract,
            'foundation_content_hash' => $context['foundation_hash'],
        ] + ($sourceCharacters === null ? [] : [
            'characters_content_hash' => $sourceCharacters['content_hash'],
        ]) + [
            'fingerprint' => $author->fingerprint($sentContext['foundation'], $sentContext['profile'], $sentContext['requirements'], $sentFixed),
        ];

        $validator = new ScreenplayValidator;

        return $this->runStep(
            $projectId,
            self::CAST[$part]['stage'],
            $part,
            $author,
            $sentContext,
            $sentFixed,
            $input,
            $meta,
            $force,
            function (ScreenplayResult $result) use ($validator, $context, $contract, $part, $withProfile, $sourceCharacters, $fixed): array {
                $violations = $validator->structural(
                    $result->screenplay, $context['profile'], $contract, $context['excluded'],
                );

                if ($violations === [] && $part === 'locations') {
                    $violations = $validator->locationLinkViolations($result->screenplay['locations'], $fixed['characters']);
                }

                if ($violations !== []) {
                    return [ucfirst($part).' failed validation: '.implode('; ', $violations), null, ''];
                }

                $rows = $result->screenplay[$part];

                if ($withProfile) {
                    $profile = $result->screenplay[ProtagonistProfile::OUTPUT_KEY];
                    $profileViolations = $validator->protagonistProfileViolations(
                        $profile, $context['foundation'], $context['excluded'], ProtagonistProfile::focus($context['profile']),
                        FilmBrief::profileSpaces($context['profile']),
                    );

                    if ($profileViolations !== []) {
                        return ['Protagonist profile failed validation: '.implode('; ', $profileViolations), null, ''];
                    }

                    $rows[ProtagonistProfile::receiverIndex($rows)][ProtagonistProfile::CHARACTER_KEY] = $profile;
                }

                return [null, [
                    $part => $rows,
                    'schema_version' => $contract,
                    'author_model' => $result->authorModel,
                    'source_foundation' => $context['source_foundation'],
                ] + ($sourceCharacters === null ? [] : ['source_characters' => $sourceCharacters]), 'ok'];
            },
            $schema,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $fixed
     * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>, 2: string}
     */
    private static function locationRequest(string $projectId, array $context, array $fixed): array
    {
        return self::designRequest('locations', $projectId, $context, $fixed);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $fixed
     * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>, 2: string}
     */
    private static function designRequest(string $part, string $projectId, array $context, array $fixed): array
    {
        $designId = $context['design_sources'][VesselDesign::SOURCE_DESIGN_KEY]['stage_id'] ?? null;

        if (! isset($context['profile'][VesselDesign::DESIGN_GEOMETRY_KEY]) || ! is_string($designId)) {
            return [$context, $fixed, 'ok'];
        }

        $output = VideoPlanningStage::query()->whereKey($designId)->where('project_id', $projectId)->value('output_json');
        $output = is_array($output) ? $output : [];
        $scenes = $part === 'scenes';
        $gaps = $scenes ? VesselDesign::sceneReferenceGaps($output) : VesselDesign::locationReferenceGaps($output);

        if ($gaps !== []) {
            Log::error("screenplay {$part}: the {$part} extract references parts the design does not hold, no model call made", [
                'project_id' => $projectId,
                'design_stage_id' => $designId,
                'gaps' => $gaps,
            ]);

            return [null, null, $scenes ? 'scene_design_reference_missing' : 'location_design_reference_missing'];
        }

        $context['profile'][VesselDesign::DESIGN_GEOMETRY_KEY] = $scenes ? VesselDesign::sceneDesign($output) : VesselDesign::locationDesign($output);
        $fixed['characters'] = array_map(static function (mixed $row) use ($scenes): mixed {
            if (is_array($row) && ($row['id'] ?? null) === VesselDesign::VESSEL_ID && is_array($row[ProtagonistProfile::CHARACTER_KEY] ?? null)) {
                $row[ProtagonistProfile::CHARACTER_KEY] = $scenes
                    ? VesselDesign::sceneProfile($row[ProtagonistProfile::CHARACTER_KEY])
                    : VesselDesign::locationProfile($row[ProtagonistProfile::CHARACTER_KEY]);
            }

            return $row;
        }, $fixed['characters']);

        return [$context, $fixed, 'ok'];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>|null  $brief
     * @return array<string, mixed>|null
     */
    private static function castSchemaFor(array $schema, string $part, bool $withProfile, ?array $brief): ?array
    {
        if ($part === 'locations') {
            if (! isset($schema['properties']['locations']['items']['properties'][FilmBrief::COVERAGE_SPACE_KEY])) {
                return null;
            }

            $schema['properties']['locations']['items']['properties'][FilmBrief::COVERAGE_SPACE_KEY] = FilmBrief::spaceField(
                $schema['properties']['locations']['items']['properties'][FilmBrief::COVERAGE_SPACE_KEY],
                $brief,
            );

            return $schema;
        }

        if (! $withProfile) {
            unset($schema['properties'][ProtagonistProfile::OUTPUT_KEY]);
            $schema['required'] = array_values(array_diff($schema['required'], [ProtagonistProfile::OUTPUT_KEY]));

            return $schema;
        }

        $profile = ProtagonistProfile::OUTPUT_KEY;
        $rooms = ProtagonistProfile::INTERIOR_KEY;

        if (isset($schema['properties'][$profile]['properties'][$rooms]['items']['properties']['space'])) {
            $schema['properties'][$profile]['properties'][$rooms]['items']['properties']['space'] = FilmBrief::spaceField(
                $schema['properties'][$profile]['properties'][$rooms]['items']['properties']['space'],
                $brief,
            );
        }

        return $schema;
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    private function prepare(string $projectId, ?string $foundationStageId, string $authorKey, bool $assembled): array
    {
        $project = $this->projects->getById($projectId);

        if ($project === null || $project->article === null) {
            return [null, 'project_not_found'];
        }

        $foundationStage = $this->selectableFoundation($projectId, $foundationStageId);

        if ($foundationStage === null) {
            return [null, 'screenplay_foundation_not_selectable'];
        }

        $current = $this->screenplayProfile((string) ($project->article->category?->slug ?? ''));

        if ($current === null) {
            return [null, 'no_screenplay_profile'];
        }

        [$loaded, $source] = self::profileForFoundation($foundationStage, $current);

        if ($loaded === null) {
            Log::error('screenplay expansion: the profile the foundation was written with cannot be established, no model call made', [
                'project_id' => $projectId,
                'foundation_stage_id' => $foundationStage->id,
                'reason' => $source,
            ]);

            return [null, $source];
        }

        [$designContext, $designReason] = self::designContext($projectId, $foundationStage);

        if ($designContext === null) {
            return [null, $designReason];
        }

        $loaded = VesselDesign::downstreamProfile($loaded) + $designContext;

        try {
            $author = app($authorKey);
        } catch (TextCompletionException $e) {
            Log::error('screenplay expansion: configured contract is not supported, no model call made', [
                'project_id' => $projectId,
                'author' => $authorKey,
                'reason' => $e->getMessage(),
            ]);

            return [null, 'screenplay_contract_unsupported'];
        }

        $filmBrief = self::foundationBrief($foundationStage);
        $briefErrors = $filmBrief === null ? [] : FilmBrief::profileViolations($loaded, $filmBrief);

        if ($briefErrors !== []) {
            Log::error('screenplay expansion: the project brief does not fit the profile, no model call made', [
                'project_id' => $projectId,
                'author' => $authorKey,
                'violations' => $briefErrors,
            ]);

            return [null, 'screenplay_film_brief_invalid'];
        }

        $contract = $author->contractVersion();
        $assembledVersion = (string) config('video.screenplay.scenes.assembled_version');
        $profile = FilmBrief::applyToProfile($loaded, $filmBrief);
        $profile['contract_version'] = $contract;
        $assembledProfile = FilmBrief::applyToProfile($loaded, $filmBrief);
        $assembledProfile['contract_version'] = $assembledVersion;
        $assembledProfile['dimension_bounds'] = self::dimensionBoundsFor($foundationStage);

        $validator = new ScreenplayValidator;
        $profileErrors = array_merge(
            $validator->profileViolations($profile, $contract),
            $assembled ? $validator->profileViolations($assembledProfile, $assembledVersion) : [],
        );

        if ($profileErrors !== []) {
            Log::error('screenplay expansion: invalid profile, no model call made', [
                'project_id' => $projectId,
                'author' => $authorKey,
                'violations' => $profileErrors,
            ]);

            return [null, 'screenplay_profile_invalid'];
        }

        try {
            $author->assertSchemaMatchesContract();
        } catch (TextCompletionException $e) {
            Log::error('screenplay expansion: invalid schema configuration, no model call made', [
                'project_id' => $projectId,
                'author' => $authorKey,
                'reason' => $e->getMessage(),
            ]);

            return [null, 'screenplay_schema_invalid'];
        }

        $foundation = self::foundationContent($foundationStage->output_json);
        $foundationHash = self::contentHash($foundation);

        $brief = $this->stageStore->latestOutputForProject($projectId, PlanningStageName::INSPIRATION);

        return [[
            'author' => $author,
            'contract' => $contract,
            'profile' => $profile,
            'assembled_profile' => $assembledProfile,
            'foundation' => $foundation,
            'foundation_hash' => $foundationHash,
            'requirements' => ['aspect_ratio' => (string) config('video.screenplay.aspect_ratio', '9:16')]
                + ($filmBrief === null ? [] : [FilmBrief::REQUIREMENT_KEY => $filmBrief]),
            'film_brief' => $filmBrief,
            'excluded' => array_values(array_map(
                static fn (array $item): string => (string) ($item['value'] ?? ''),
                is_array($brief) ? ($brief['excluded_context'] ?? []) : [],
            )),
            'meta' => [
                'foundation_stage_id' => $foundationStage->id,
                'foundation_revision' => $foundationStage->planning_revision,
                'foundation_output_hash' => $foundationStage->output_hash,
                'profile_source' => $source,
            ],
            'source_foundation' => [
                'stage_id' => $foundationStage->id,
                'revision' => $foundationStage->planning_revision,
                'content_hash' => $foundationHash,
            ],
            'design_sources' => array_filter([
                VesselDesign::SOURCE_DESIGN_KEY => $foundationStage->output_json[VesselDesign::SOURCE_DESIGN_KEY] ?? null,
                VesselDesign::SOURCE_ANCHOR_KEY => $foundationStage->output_json[VesselDesign::SOURCE_ANCHOR_KEY] ?? null,
            ], static fn (mixed $source): bool => is_array($source)),
        ], 'ok'];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $fixed
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $meta
     * @param  callable(ScreenplayResult): array{0: ?string, 1: ?array<string, mixed>, 2: string}  $finish
     * @param  array<string, mixed>|null  $constrainedSchema
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    private function runStep(
        string $projectId,
        PlanningStageName $stage,
        string $label,
        ScreenplayAuthor $author,
        array $context,
        array $fixed,
        array $input,
        array $meta,
        bool $force,
        callable $finish,
        ?array $constrainedSchema = null,
    ): array {
        return $this->runner->run(
            $projectId, $stage, $label, $author,
            $context['foundation'], $context['profile'], $context['requirements'],
            $fixed, $input, $meta, $force, $finish, $constrainedSchema,
        );
    }

    private function selectableFoundation(string $projectId, ?string $stageId): ?VideoPlanningStage
    {
        if ($stageId === null || $stageId === '') {
            return null;
        }

        $stage = VideoPlanningStage::query()
            ->whereKey($stageId)
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::SCREENPLAY_FOUNDATION->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->first(['id', 'planning_revision', 'output_hash', 'output_json', 'input_json']);

        $version = is_array($stage?->output_json) ? ($stage->output_json['schema_version'] ?? null) : null;

        return in_array($version, (array) config('video.screenplay.scenes.foundation_versions'), true) ? $stage : null;
    }

    private function castProblem(
        string $projectId,
        string $part,
        VideoPlanningStage $stage,
        ?string $foundationStageId,
        ?string $charactersStageId,
    ): ?string {
        $foundationStage = $this->selectableFoundation($projectId, $foundationStageId);

        if ($foundationStage === null) {
            return 'foundation';
        }

        $foundation = self::foundationContent($foundationStage->output_json);
        $foundationHash = self::contentHash($foundation);

        if ($this->selectedCast($projectId, $part, $stage->id, $foundationHash) === null) {
            return 'foundation';
        }

        [$profile] = self::profileForFoundation($foundationStage, $this->projectProfile($projectId));

        if ($profile === null) {
            return 'profile_changed';
        }

        $profile = FilmBrief::applyToProfile($profile, self::foundationBrief($foundationStage));

        if ($part === 'characters') {
            return $this->charactersRuleViolations($stage->output_json['characters'], $profile, $foundation) === []
                ? null
                : 'rules';
        }

        $characters = $this->selectedCast($projectId, 'characters', $charactersStageId, $foundationHash);

        if ($characters === null
            || $this->charactersRuleViolations($characters->output_json['characters'], $profile, $foundation) !== []
            || ! self::builtFrom($stage, $characters)) {
            return 'characters';
        }

        return LocationProfile::allProfiled($stage->output_json['locations']) ? null : 'profile';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $foundation
     * @return list<string>
     */
    private function charactersRuleViolations(array $rows, array $profile, array $foundation): array
    {
        $violations = [];

        if (ProtagonistProfile::protagonistOnly($profile) && count($rows) !== 1) {
            $violations[] = 'characters: this profile declares only the protagonist';
        }

        if (! ProtagonistProfile::enabled($profile)) {
            return $violations;
        }

        $index = ProtagonistProfile::receiverIndex($rows);

        if ($index === null) {
            return [...$violations, 'characters: no protagonist carries the profile'];
        }

        return [...$violations, ...(new ScreenplayValidator)->protagonistProfileViolations(
            $rows[$index][ProtagonistProfile::CHARACTER_KEY] ?? null, $foundation, [], ProtagonistProfile::focus($profile),
            FilmBrief::profileSpaces($profile),
        )];
    }

    private static function builtFrom(VideoPlanningStage $locations, VideoPlanningStage $characters): bool
    {
        $source = is_array($locations->input_json) ? ($locations->input_json['characters_content_hash'] ?? null) : null;

        return is_string($source)
            && hash_equals(self::contentHash($characters->output_json['characters']), $source);
    }

    /** @return array<string, mixed>|null */
    private function projectProfile(string $projectId): ?array
    {
        $project = $this->projects->getById($projectId);

        return $project?->article === null
            ? null
            : $this->screenplayProfile((string) ($project->article->category?->slug ?? ''));
    }

    private function selectedCast(string $projectId, string $part, ?string $stageId, string $foundationHash): ?VideoPlanningStage
    {
        if ($stageId === null || $stageId === '') {
            return null;
        }

        $stage = VideoPlanningStage::query()
            ->whereKey($stageId)
            ->where('project_id', $projectId)
            ->where('stage', self::CAST[$part]['stage']->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->first();

        $rows = is_array($stage?->output_json) ? ($stage->output_json[$part] ?? null) : null;

        if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
            return null;
        }

        $source = is_array($stage->input_json) ? ($stage->input_json['foundation_content_hash'] ?? null) : null;

        return is_string($source) && hash_equals($foundationHash, $source) ? $stage : null;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public static function foundationShape(array $profile): array
    {
        $kept = array_intersect_key($profile, array_flip(self::FOUNDATION_PROFILE_KEYS));

        unset($kept['people_policy']['dialogue_requires_person_id'], $kept['people_policy']['dialogue_allowed']);
        $kept['contract_version'] = (string) config('video.screenplay.foundation.contract_version');
        $kept['dimension_bounds'] = config('video.screenplay.foundation.dimension_bounds');

        return $kept;
    }

    /**
     * @param  array<string, mixed>|null  $current
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    public static function profileForFoundation(VideoPlanningStage $foundation, ?array $current): array
    {
        $meta = PlanningStageStore::metadataOf($foundation->input_json);
        $snapshot = $meta[self::PROFILE_SNAPSHOT_KEY] ?? null;

        if (is_array($snapshot) && $snapshot !== []) {
            return [$snapshot, 'snapshot'];
        }

        $recorded = $meta['profile'] ?? null;

        if (! is_array($recorded) || $current === null) {
            return [null, 'screenplay_profile_unknown'];
        }

        $comparable = static function (array $shape): string {
            unset($shape['contract_version'], $shape[FilmBrief::PROFILE_SPACES_KEY]);

            return self::contentHash(self::sortedKeys($shape));
        };

        return $comparable(self::foundationShape($current)) === $comparable($recorded)
            ? [FilmBrief::applyToProfile($current, null), 'legacy']
            : [null, 'screenplay_profile_changed'];
    }

    /** @return array{0: ?array<string, mixed>, 1: string} */
    private static function designContext(string $projectId, VideoPlanningStage $foundation): array
    {
        $source = $foundation->output_json[VesselDesign::SOURCE_DESIGN_KEY] ?? null;
        $designId = is_array($source) ? ($source['stage_id'] ?? null) : null;

        if (! is_string($designId) || $designId === '') {
            return [[], 'ok'];
        }

        $design = VideoPlanningStage::query()
            ->whereKey($designId)
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::VESSEL_DESIGN->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->first();
        $output = is_array($design?->output_json) ? $design->output_json : [];

        if ($design === null || ! hash_equals((string) ($source['content_hash'] ?? ''), VesselDesign::contentHash($output))) {
            return [null, 'design_changed_since_anchor'];
        }

        if (! VesselDesign::hasCanonical($output)) {
            return [[], 'ok'];
        }

        $broken = VesselDesign::extractIntegrityViolations($output, VesselDesign::lockedDesign($output, null));

        if ($broken !== []) {
            Log::error('screenplay expansion: the locked design extract is incomplete, no model call made', [
                'project_id' => $projectId,
                'design_stage_id' => $designId,
                'violations' => $broken,
            ]);

            return [null, 'design_geometry_incomplete'];
        }

        return [[
            VesselDesign::DESIGN_GEOMETRY_KEY => VesselDesign::screenplayGeometry($output),
            VesselDesign::CONFIGURATION_COMPONENTS_KEY => VesselDesign::configurationComponents($output),
        ], 'ok'];
    }

    /** @return array<string, mixed> */
    public static function dimensionBoundsFor(VideoPlanningStage $foundation): array
    {
        $recorded = PlanningStageStore::metadataOf($foundation->input_json)['profile']['dimension_bounds'] ?? null;

        return is_array($recorded) ? $recorded : (array) config('video.screenplay.foundation.dimension_bounds');
    }

    private static function sortedKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(static fn (mixed $child): mixed => self::sortedKeys($child), $value);
    }

    /** @return array<string, mixed>|null */
    public static function foundationBrief(VideoPlanningStage $foundation): ?array
    {
        return FilmBrief::ofRequirements(
            (array) (PlanningStageStore::metadataOf($foundation->input_json)['requirements'] ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public static function foundationContent(array $output): array
    {
        $content = [];

        foreach (self::FOUNDATION_SECTIONS as $section) {
            $content[$section] = $output[$section] ?? null;
        }

        if (array_key_exists(self::SPACE_PLAN_SECTION, $output)) {
            $content[self::SPACE_PLAN_SECTION] = $output[self::SPACE_PLAN_SECTION];
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<array<string, mixed>>  $characters
     * @param  list<array<string, mixed>>  $locations
     * @return array<string, mixed>
     */
    private static function sceneSchemaFor(array $schema, array $characters, array $locations, bool $speaks): array
    {
        $ids = static fn (array $rows, ?string $kind = null): array => array_values(array_map(
            static fn (array $row): string => (string) $row['id'],
            array_filter($rows, static fn (array $row): bool => $kind === null || ($row['kind'] ?? null) === $kind),
        ));

        $only = static function (array $field, array $allowed): array {
            if ($allowed === []) {
                return $field;
            }

            unset($field['pattern']);
            $field['enum'] = $allowed;

            return $field;
        };

        $scene = $schema['properties']['scenes']['items']['properties'];

        $scene['location_id'] = $only($scene['location_id'], $ids($locations));
        $scene['character_ids']['items'] = $only($scene['character_ids']['items'], $ids($characters));

        foreach (['build_state', 'subject_state'] as $state) {
            if (isset($scene[$state]['properties']['subject_id'])) {
                $scene[$state]['properties']['subject_id'] = $only(
                    $scene[$state]['properties']['subject_id'], $ids($characters, 'object'),
                );
            }
        }

        if ($speaks) {
            $scene['dialogue']['items']['properties']['character_id'] = $only(
                $scene['dialogue']['items']['properties']['character_id'], $ids($characters, 'person'),
            );
        } else {
            unset($scene['dialogue']);
            $schema['properties']['scenes']['items']['required'] = array_values(array_diff(
                $schema['properties']['scenes']['items']['required'], ['dialogue'],
            ));
        }

        $schema['properties']['scenes']['items']['properties'] = $scene;

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  list<array<string, mixed>>  $characters
     */
    private static function silenceReason(array $profile, array $characters): ?string
    {
        if (($profile['people_policy']['dialogue_allowed'] ?? true) === false) {
            return 'the profile does not allow dialogue';
        }

        return self::hasSpeaker($characters) ? null : 'no declared person can speak in this film';
    }

    /** @param  list<array<string, mixed>>  $characters */
    private static function hasSpeaker(array $characters): bool
    {
        foreach ($characters as $character) {
            if (($character['kind'] ?? null) === 'person') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $expansion
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function withoutDialogue(array $expansion, string $reason): array
    {
        $violations = [];
        $scenes = is_array($expansion['scenes'] ?? null) ? $expansion['scenes'] : [];

        foreach ($scenes as $index => $scene) {
            if (! is_array($scene)) {
                continue;
            }

            if (($scene['dialogue'] ?? []) !== []) {
                $id = is_string($scene['id'] ?? null) ? $scene['id'] : "scenes[{$index}]";
                $violations[] = "{$id}.dialogue: {$reason}";
            }

            $scenes[$index]['dialogue'] = [];
        }

        $expansion['scenes'] = $scenes;

        return [$expansion, $violations];
    }

    public static function contentHash(mixed $value): string
    {
        return hash('sha256', json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }
}
