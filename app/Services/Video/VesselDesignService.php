<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Models\VideoVisualIdentity;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Video\Prompt\Exceptions\TextCompletionException;
use App\Video\Screenplay\CreativeInspirationBuilder;
use App\Video\Screenplay\FilmBrief;
use App\Video\Screenplay\ProtagonistProfile;
use App\Video\Screenplay\ScreenplayAuthor;
use App\Video\Screenplay\ScreenplayResult;
use App\Video\Screenplay\ScreenplayValidator;
use App\Video\Screenplay\VesselDesign;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class VesselDesignService
{
    /** @var list<string> */
    private const PROFILE_SOURCE_FIELDS = ['form_and_proportions', 'deck_organization', 'windows_and_glazing'];

    /** @var list<string> */
    private const FEATURE_SOURCE_FIELDS = ['name', 'region', 'location', 'standard_state', 'standard_geometry'];

    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly ScreenplayStepRunner $runner,
        private readonly ScreenplayExpansionService $expansion,
        private readonly VideoProjectRepositoryInterface $projects,
        private readonly VisualIdentityStore $identities,
    ) {}

    /** @return array{0: ?array<string, mixed>, 1: string} */
    public function author(string $projectId, bool $force = false): array
    {
        $project = $this->projects->getById($projectId);

        if ($project === null || $project->article === null) {
            return [null, 'project_not_found'];
        }

        if (! VesselDesign::isDesignFirst($project)) {
            return [null, 'workflow_not_design_first'];
        }

        $brief = $this->stageStore->latestOutputForProject($projectId, PlanningStageName::INSPIRATION);

        if (! is_array($brief) || $brief === []) {
            return [null, 'no_inspiration_brief'];
        }

        $fullProfile = $this->expansion->screenplayProfile((string) ($project->article->category?->slug ?? ''));

        if ($fullProfile === null) {
            return [null, 'no_screenplay_profile'];
        }

        [$filmBrief, $briefErrors] = FilmBrief::ofProfile($fullProfile);

        if ($briefErrors !== []) {
            return [null, 'screenplay_film_brief_invalid'];
        }

        try {
            $author = app('video.screenplay.design_author');
            $author->assertSchemaMatchesContract();
        } catch (TextCompletionException $e) {
            Log::error('vessel design: the author is not configured, no model call made', [
                'project_id' => $projectId,
                'reason' => $e->getMessage(),
            ]);

            return [null, 'screenplay_contract_unsupported'];
        }

        $designBrief = VesselDesign::designBrief($filmBrief);
        $profile = FilmBrief::applyToProfile(VesselDesign::designShape($fullProfile), $designBrief);
        $validator = new ScreenplayValidator;

        if ($validator->profileViolations($profile, VesselDesign::CONTRACT) !== []) {
            return [null, 'screenplay_profile_invalid'];
        }

        [$inspiration] = (new CreativeInspirationBuilder)->build($brief);

        if ($inspiration === null) {
            return [null, 'inspiration_carries_source_facts'];
        }

        $requirements = $designBrief === null ? [] : [FilmBrief::REQUIREMENT_KEY => $designBrief];
        $excluded = self::excludedNames($brief);
        $schema = self::schemaFor($author, $filmBrief, $profile);
        $previous = $this->previousDesigns($project);
        $fixed = $previous === [] ? [] : [VesselDesign::PREVIOUS_DESIGNS_KEY => $previous];

        $input = [
            'contract_version' => VesselDesign::CONTRACT,
            'fingerprint' => $author->fingerprint($inspiration, $profile, $requirements, $fixed),
            'screenplay_profile_sha256' => ScreenplayExpansionService::contentHash($fullProfile),
        ];

        $meta = [
            'profile' => $profile,
            'requirements' => $requirements,
            VesselDesign::PREVIOUS_DESIGNS_KEY => $previous,
            ScreenplayExpansionService::PROFILE_SNAPSHOT_KEY => $fullProfile,
            'prompt' => $author->promptLineage(),
        ];

        return $this->runner->run(
            $projectId,
            PlanningStageName::VESSEL_DESIGN,
            'design',
            $author,
            $inspiration,
            $profile,
            $requirements,
            $fixed,
            $input,
            $meta,
            $force,
            static function (ScreenplayResult $result) use ($validator, $profile, $excluded): array {
                $violations = $validator->structural($result->screenplay, $profile, VesselDesign::CONTRACT, $excluded);

                if ($violations !== []) {
                    return ['Vessel design failed validation: '.implode('; ', $violations), null, ''];
                }

                return [null, $result->screenplay + [
                    'schema_version' => VesselDesign::CONTRACT,
                    'author_model' => $result->authorModel,
                ], 'ok'];
            },
            $schema,
        );
    }

    /**
     * @return array{0: ?VideoPlanningStage, 1: string, 2: list<string>}
     */
    public function recoverFailed(string $stageId, bool $write = false): array
    {
        $failed = VideoPlanningStage::query()
            ->whereKey($stageId)
            ->where('stage', PlanningStageName::VESSEL_DESIGN->value)
            ->where('status', VideoPlanningStageStatus::FAILED->value)
            ->first();

        if ($failed === null) {
            return [null, 'stage_not_a_failed_design', []];
        }

        $design = json_decode((string) $failed->raw_response, true);
        $meta = PlanningStageStore::metadataOf($failed->input_json);
        $profile = $meta['profile'] ?? null;
        $snapshot = $meta[ScreenplayExpansionService::PROFILE_SNAPSHOT_KEY] ?? null;

        if (! is_array($design) || $design === []) {
            return [null, 'raw_response_unusable', []];
        }

        if (! is_array($profile) || ! is_array($snapshot)) {
            return [null, 'screenplay_profile_unknown', []];
        }

        $newer = VideoPlanningStage::query()
            ->where('project_id', $failed->project_id)
            ->where('stage', PlanningStageName::VESSEL_DESIGN->value)
            ->whereIn('status', [VideoPlanningStageStatus::SUCCEEDED->value, VideoPlanningStageStatus::RUNNING->value])
            ->where('created_at', '>', $failed->created_at)
            ->exists();

        if ($newer) {
            return [null, 'newer_design_exists', []];
        }

        if ($this->lock(VideoProject::query()->find($failed->project_id)) !== null) {
            return [null, 'design_anchor_already_locked', []];
        }

        $inspiration = VideoPlanningStage::query()
            ->where('project_id', $failed->project_id)
            ->where('stage', PlanningStageName::INSPIRATION->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->where('created_at', '<=', $failed->created_at)
            ->orderByDesc('created_at')
            ->first();
        $excluded = self::excludedNames(is_array($inspiration?->output_json) ? $inspiration->output_json : []);

        $violations = array_values(array_unique(array_merge(
            (new ScreenplayValidator)->structural($design, $profile, VesselDesign::CONTRACT, $excluded),
            VesselDesign::extractIntegrityViolations(
                $design,
                VesselDesign::anchorDesign($design, is_array($snapshot['prompt_compilation_policy'] ?? null) ? $snapshot['prompt_compilation_policy'] : null),
            ),
        )));

        if ($violations !== []) {
            return [null, 'still_invalid', $violations];
        }

        if (! $write) {
            return [null, 'valid_dry_run', []];
        }

        $input = array_diff_key((array) $failed->input_json, [PlanningStageStore::METADATA_KEY => true]);
        $recoveredMeta = $meta + [
            'recovered_from_stage_id' => (string) $failed->id,
            'recovered_at' => now()->toIso8601String(),
        ];

        [$claimed, $token, $reason] = $this->stageStore->claimProjectStage(
            (string) $failed->project_id, PlanningStageName::VESSEL_DESIGN, $input, true, $recoveredMeta,
        );

        if ($token === null) {
            return [null, $reason === 'claimed_by_other' ? 'screenplay_running' : $reason, []];
        }

        $recorded = $this->stageStore->finishSucceeded(
            $claimed->id,
            $token,
            (string) $failed->raw_response,
            $design + [
                'schema_version' => VesselDesign::CONTRACT,
                'author_model' => (string) ($failed->provider_model ?? $failed->model),
            ],
            [
                'model' => $failed->model,
                'provider_model' => $failed->provider_model,
                'instruction_version' => $failed->instruction_version,
                'tokens_in' => 0,
                'tokens_out' => 0,
                'cost_usd' => 0,
            ],
        );

        return $recorded ? [$claimed->refresh(), 'recovered', []] : [null, 'screenplay_claim_lost', []];
    }

    /** @return list<array{name: string, central_idea: string, visible_difference: string}> */
    public function previousDesigns(VideoProject $project): array
    {
        $projectIds = $project->article_id === null
            ? [$project->id]
            : VideoProject::query()->where('article_id', $project->article_id)->pluck('id')->all();

        $stages = VideoPlanningStage::query()
            ->whereIn('project_id', $projectIds)
            ->whereIn('stage', [PlanningStageName::VESSEL_DESIGN->value, PlanningStageName::SCREENPLAY_FOUNDATION->value])
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->orderByDesc('created_at')
            ->get();

        $rows = [];

        foreach ($stages as $stage) {
            $output = is_array($stage->output_json) ? $stage->output_json : [];
            $idea = $output['design_thesis']['central_idea'] ?? null;

            if (! is_string($idea) || trim($idea) === '' || isset($rows[$idea])) {
                continue;
            }

            $rows[$idea] = [
                'name' => (string) ($output[VesselDesign::VESSEL_KEY]['name'] ?? ''),
                'central_idea' => $idea,
                'visible_difference' => (string) ($output['design_thesis']['visible_difference'] ?? ''),
            ];

            if (count($rows) >= VesselDesign::PREVIOUS_DESIGN_LIMIT) {
                break;
            }
        }

        return array_values($rows);
    }

    public function currentStage(string $projectId): ?VideoPlanningStage
    {
        return VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::VESSEL_DESIGN->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->orderByDesc('planning_revision')
            ->get()
            ->first(static fn (VideoPlanningStage $stage): bool => is_array($stage->output_json)
                && ($stage->output_json['schema_version'] ?? null) === VesselDesign::CONTRACT);
    }

    public function stageById(string $projectId, ?string $stageId): ?VideoPlanningStage
    {
        if ($stageId === null || $stageId === '') {
            return null;
        }

        $stage = VideoPlanningStage::query()
            ->whereKey($stageId)
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::VESSEL_DESIGN->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->first();

        return is_array($stage?->output_json) && ($stage->output_json['schema_version'] ?? null) === VesselDesign::CONTRACT
            ? $stage
            : null;
    }

    /**
     * @return array{design: ?array<string, mixed>, stage_id: ?string, revision: ?int, content_hash: ?string,
     *               running: bool, error: ?string, written_at: ?string, lock: ?array<string, mixed>,
     *               gate: ?array<string, list<string>>, p0: list<array{id: string, statement: string}>}
     */
    public function panel(string $projectId): array
    {
        [$latest] = $this->stageStore->latestStageForProject($projectId, PlanningStageName::VESSEL_DESIGN, []);
        $stage = $this->currentStage($projectId);
        $output = is_array($stage?->output_json) ? $stage->output_json : [];
        $snapshot = $stage === null ? null : (PlanningStageStore::metadataOf($stage->input_json)[ScreenplayExpansionService::PROFILE_SNAPSHOT_KEY] ?? null);

        return [
            'gate' => VesselDesign::hasCanonical($output) ? VesselDesign::gate($output, is_array($snapshot) ? $snapshot : []) : null,
            'p0' => VesselDesign::p0($output),
            'design' => $stage?->output_json,
            'stage_id' => $stage?->id,
            'revision' => $stage?->planning_revision,
            'content_hash' => $stage === null ? null : VesselDesign::contentHash($stage->output_json),
            'running' => $latest?->status === VideoPlanningStageStatus::RUNNING->value
                && $latest->lease_expires_at?->isFuture() === true,
            'error' => $latest?->status === VideoPlanningStageStatus::FAILED->value ? $latest->error_message : null,
            'written_at' => $stage?->finished_at?->format('d/m/Y H:i'),
            'lock' => $this->lock(VideoProject::query()->find($projectId)),
        ];
    }

    /** @return array{0: bool, 1: string} */
    public function reset(string $projectId): array
    {
        [$latest] = $this->stageStore->latestStageForProject($projectId, PlanningStageName::VESSEL_DESIGN, []);

        if ($latest === null) {
            return [false, 'Chua co luot thiet ke tau nao'];
        }

        return $this->stageStore->releaseClaim($latest->id, 'Nguoi dung reset thu cong')
            ? [true, 'ok']
            : [false, 'Luot nay khong con giu claim — khong co gi de reset'];
    }

    /** @return array<string, mixed> */
    public function vessel(VideoPlanningStage $stage): array
    {
        return VesselDesign::vesselRow((array) $stage->output_json) + [
            'design_stage_id' => (string) $stage->id,
            'design_content_hash' => VesselDesign::contentHash((array) $stage->output_json),
        ];
    }

    /** @return array<string, mixed> */
    public function anchorSource(VideoPlanningStage $stage): array
    {
        $output = (array) $stage->output_json;
        $row = VesselDesign::vesselRow($output);
        $profile = is_array($row[ProtagonistProfile::CHARACTER_KEY] ?? null) ? $row[ProtagonistProfile::CHARACTER_KEY] : [];
        $participant = array_intersect_key($row, array_flip(['id', 'name', 'role', 'kind', 'description', 'appearance']));
        $exterior = array_intersect_key($profile, array_flip(self::PROFILE_SOURCE_FIELDS));
        $exterior['signature_features'] = array_values(array_map(
            static fn (mixed $feature): array => array_intersect_key(
                is_array($feature) ? $feature : [],
                array_flip(self::FEATURE_SOURCE_FIELDS),
            ),
            (array) ($profile['signature_features'] ?? []),
        ));

        $snapshot = PlanningStageStore::metadataOf($stage->input_json)[ScreenplayExpansionService::PROFILE_SNAPSHOT_KEY] ?? null;
        $policy = is_array($snapshot) && is_array($snapshot['prompt_compilation_policy'] ?? null)
            ? $snapshot['prompt_compilation_policy']
            : null;

        return [
            'source_kind' => VesselDesign::SOURCE_KIND,
            'participant' => $participant + [ProtagonistProfile::CHARACTER_KEY => $exterior],
            'design' => [
                'design_thesis' => $output['design_thesis'] ?? null,
                'principal_dimensions' => $output['principal_dimensions'] ?? null,
            ] + VesselDesign::anchorDesign($output, $policy),
        ];
    }

    public function ensureIdentity(VideoPlanningStage $stage): ?VideoVisualIdentity
    {
        $subjectKey = VesselDesign::subjectKey((string) $stage->id);
        $existing = $this->identities->latestForProject((string) $stage->project_id, VisualIdentityStore::SUBJECT, $subjectKey);

        if ($existing !== null) {
            return $existing;
        }

        $row = VesselDesign::vesselRow((array) $stage->output_json);

        return $this->identities->freezeIdentity(
            (string) $stage->project_id,
            [
                'contract' => 'vessel-design-subject-v1',
                'character_id' => VesselDesign::VESSEL_ID,
                'kind' => 'object',
                'name' => $row['name'],
                'appearance' => $row['appearance'],
                'design_stage_id' => (string) $stage->id,
                'design_content_hash' => VesselDesign::contentHash((array) $stage->output_json),
            ],
            $row['name'] !== '' ? $row['name'] : VesselDesign::VESSEL_ID,
            VisualIdentityStore::SUBJECT,
            $subjectKey,
        );
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{0: ?VideoPlanningStage, 1: string}
     */
    public function stageForSpec(string $projectId, array $spec): array
    {
        $current = $this->currentStage($projectId);
        $stageId = $spec['design_stage_id'] ?? null;

        if ($current === null || ! is_string($stageId) || $stageId !== (string) $current->id) {
            return [null, 'anchor_prompt_other_design'];
        }

        if (! hash_equals(VesselDesign::contentHash((array) $current->output_json), (string) ($spec['design_content_hash'] ?? ''))) {
            return [null, 'anchor_prompt_other_design'];
        }

        return [$current, 'ok'];
    }

    /**
     * @param  callable(): array{0: bool, 1: string}  $approve
     * @return array{0: bool, 1: string}
     */
    public function approveAnchor(
        string $projectId,
        VideoDesignImage $image,
        string $artifactId,
        ?string $adminId,
        callable $approve,
    ): array {
        $spec = is_array($image->prompt_spec_json) ? $image->prompt_spec_json : [];
        [$stage, $reason] = $this->stageForSpec($projectId, $spec);

        if ($stage === null) {
            return [false, $reason];
        }

        $artifact = VideoArtifact::query()
            ->whereKey($artifactId)
            ->where('design_image_id', $image->id)
            ->where('project_id', $projectId)
            ->first();

        if ($artifact === null) {
            return [false, 'artifact_not_found'];
        }

        if (! is_string($artifact->sha256) || $artifact->sha256 === '') {
            return [false, 'artifact_not_verified'];
        }

        return DB::transaction(function () use ($projectId, $image, $artifact, $stage, $spec, $adminId, $approve): array {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->first();

            if ($project === null) {
                return [false, 'project_not_found'];
            }

            [$current] = $this->stageForSpec($projectId, $spec);

            if ($current === null || (string) $current->id !== (string) $stage->id) {
                return [false, 'anchor_prompt_other_design'];
            }

            [$done, $reason] = $approve();

            if (! $done) {
                return [false, $reason];
            }

            $metadata = $project->metadata_json ?? [];
            $metadata[VesselDesign::LOCK_KEY] = [
                'design_stage_id' => (string) $stage->id,
                'design_revision' => (int) $stage->planning_revision,
                'design_content_hash' => VesselDesign::contentHash((array) $stage->output_json),
                'subject_key' => VesselDesign::subjectKey((string) $stage->id),
                'image_id' => (string) $image->id,
                'prompt_sha256' => hash('sha256', (string) ($spec['prompt'] ?? '')),
                'artifact_id' => (string) $artifact->id,
                'artifact_sha256' => (string) $artifact->sha256,
                'approved_by' => $adminId,
                'approved_at' => now()->toIso8601String(),
            ];
            $project->metadata_json = $metadata;
            $project->save();

            return [true, $reason];
        });
    }

    /** @return array<string, mixed>|null */
    public function lock(?VideoProject $project): ?array
    {
        $lock = $project?->metadata_json[VesselDesign::LOCK_KEY] ?? null;

        return is_array($lock) ? $lock : null;
    }

    /**
     * @param  array<string, mixed>  $lock
     * @return array{0: ?VideoPlanningStage, 1: ?VideoDesignImage, 2: string}
     */
    public function lockedSource(string $projectId, array $lock): array
    {
        $stage = $this->stageById($projectId, is_string($lock['design_stage_id'] ?? null) ? $lock['design_stage_id'] : null);

        if ($stage === null) {
            return [null, null, 'design_anchor_not_approved'];
        }

        if (! hash_equals(VesselDesign::contentHash((array) $stage->output_json), (string) ($lock['design_content_hash'] ?? ''))) {
            return [null, null, 'design_changed_since_anchor'];
        }

        $image = $this->lockedAnchor($projectId, $lock);

        return $image === null ? [null, null, 'design_anchor_not_approved'] : [$stage, $image, 'ok'];
    }

    /** @param array<string, mixed> $lock */
    public function lockedAnchor(string $projectId, array $lock): ?VideoDesignImage
    {
        $image = VideoDesignImage::query()
            ->whereKey((string) ($lock['image_id'] ?? ''))
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::ANCHOR_TYPE)
            ->first();
        $artifact = $image === null ? null : VideoArtifact::query()
            ->whereKey((string) ($lock['artifact_id'] ?? ''))
            ->where('design_image_id', $image->id)
            ->first();

        if ($artifact === null || ! hash_equals((string) ($lock['artifact_sha256'] ?? ''), (string) $artifact->sha256)) {
            return null;
        }

        $image->selected_artifact_id = $artifact->id;
        $image->syncOriginalAttribute('selected_artifact_id');
        $image->setRelation('artifact', $artifact);

        return $image;
    }

    /**
     * @param  array<string, mixed>|null  $filmBrief
     * @param  array<string, mixed>  $designProfile
     * @return array<string, mixed>
     */
    private static function schemaFor(ScreenplayAuthor $author, ?array $filmBrief, array $designProfile): array
    {
        $schema = $author->contractSchema();
        $profile = ProtagonistProfile::OUTPUT_KEY;
        $rooms = ProtagonistProfile::INTERIOR_KEY;
        $canonical = VesselDesign::CANONICAL_KEY;
        $relations = VesselDesign::relationEnum($designProfile);

        if (isset($schema['properties'][$profile]['properties'][$rooms]['items']['properties']['space'])) {
            $schema['properties'][$profile]['properties'][$rooms]['items']['properties']['space'] = FilmBrief::spaceField(
                $schema['properties'][$profile]['properties'][$rooms]['items']['properties']['space'],
                $filmBrief,
            );
        }

        if ($relations !== null
            && isset($schema['properties'][$canonical]['properties']['geometric_relationships']['items']['properties']['relation'])) {
            $schema['properties'][$canonical]['properties']['geometric_relationships']['items']['properties']['relation']['enum'] = $relations;
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $brief
     * @return list<string>
     */
    public static function excludedNames(array $brief): array
    {
        return array_values(array_map(
            static fn (array $item): string => (string) ($item['value'] ?? ''),
            is_array($brief['excluded_context'] ?? null) ? $brief['excluded_context'] : [],
        ));
    }
}
