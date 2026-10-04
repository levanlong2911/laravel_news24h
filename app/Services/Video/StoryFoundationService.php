<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Video\Prompt\Exceptions\TextCompletionException;
use App\Video\Screenplay\FilmBrief;
use App\Video\Screenplay\ScreenplayResult;
use App\Video\Screenplay\ScreenplayValidator;
use App\Video\Screenplay\VesselDesign;
use Illuminate\Support\Facades\Log;

final class StoryFoundationService
{
    /** @var list<string> */
    private const DESIGN_ONLY_PROFILE_KEYS = [
        'design_requirements', 'identity_dimensions', 'concept_antipatterns', 'concept_forbidden_terms', 'dimension_bounds',
    ];

    private const CAST_CONTRACT = 'screenplay_characters_v2';

    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly ScreenplayStepRunner $runner,
        private readonly VesselDesignService $designs,
        private readonly VideoProjectRepositoryInterface $projects,
    ) {}

    /** @return array{0: ?array<string, mixed>, 1: string} */
    public function author(string $projectId, bool $force = false): array
    {
        $project = $this->projects->getById($projectId);

        if ($project === null) {
            return [null, 'project_not_found'];
        }

        if (! VesselDesign::isDesignFirst($project)) {
            return [null, 'workflow_not_design_first'];
        }

        $lock = $this->designs->lock($project);

        if ($lock === null) {
            return [null, 'design_anchor_not_approved'];
        }

        [$design, , $reason] = $this->designs->lockedSource($projectId, $lock);

        if ($design === null) {
            return [null, $reason];
        }

        $snapshot = PlanningStageStore::metadataOf($design->input_json)[ScreenplayExpansionService::PROFILE_SNAPSHOT_KEY] ?? null;

        if (! is_array($snapshot) || $snapshot === []) {
            return [null, 'screenplay_profile_unknown'];
        }

        [$filmBrief, $briefErrors] = FilmBrief::ofProfile($snapshot);

        if ($briefErrors !== []) {
            return [null, 'screenplay_film_brief_invalid'];
        }

        try {
            $author = app('video.screenplay.story_author');
            $author->assertSchemaMatchesContract();
        } catch (TextCompletionException $e) {
            Log::error('story foundation: the author is not configured, no model call made', [
                'project_id' => $projectId,
                'reason' => $e->getMessage(),
            ]);

            return [null, 'screenplay_contract_unsupported'];
        }

        $profile = FilmBrief::applyToProfile(self::storyShape($snapshot), $filmBrief);
        $validator = new ScreenplayValidator;

        if ($validator->profileViolations($profile, VesselDesign::STORY_CONTRACT) !== []) {
            return [null, 'screenplay_profile_invalid'];
        }

        $merged = $profile;
        $merged['contract_version'] = VesselDesign::FOUNDATION_CONTRACT;
        $merged['dimension_bounds'] = PlanningStageStore::metadataOf($design->input_json)['profile']['dimension_bounds']
            ?? config('video.screenplay.foundation.dimension_bounds');

        $source = VesselDesign::content((array) $design->output_json);
        $requirements = ['aspect_ratio' => (string) config('video.screenplay.aspect_ratio', '9:16')]
            + ($filmBrief === null ? [] : [FilmBrief::REQUIREMENT_KEY => $filmBrief]);
        $brief = $this->stageStore->latestOutputForProject($projectId, PlanningStageName::INSPIRATION);
        $excluded = VesselDesignService::excludedNames(is_array($brief) ? $brief : []);
        $sourceDesign = [
            'stage_id' => (string) $design->id,
            'revision' => (int) $design->planning_revision,
            'content_hash' => (string) $lock['design_content_hash'],
        ];
        $sourceAnchor = array_intersect_key($lock, array_flip([
            'image_id', 'prompt_sha256', 'artifact_id', 'artifact_sha256', 'subject_key', 'approved_at',
        ]));

        $schema = self::storySchema($author->contractSchema(), $profile);

        $input = [
            'contract_version' => VesselDesign::STORY_CONTRACT,
            'design_content_hash' => $sourceDesign['content_hash'],
            'anchor_artifact_sha256' => (string) ($lock['artifact_sha256'] ?? ''),
            'anchor_prompt_sha256' => (string) ($lock['prompt_sha256'] ?? ''),
            'fingerprint' => $author->fingerprint($source, $profile, $requirements),
        ];

        $meta = [
            'profile' => $merged,
            'requirements' => $requirements,
            ScreenplayExpansionService::PROFILE_SNAPSHOT_KEY => $snapshot,
            'prompt' => $author->promptLineage(),
            'output_schema_sha256' => hash('sha256', (string) json_encode($schema)),
            VesselDesign::SOURCE_DESIGN_KEY => $sourceDesign,
            VesselDesign::SOURCE_ANCHOR_KEY => $sourceAnchor,
        ];

        [$output, $reason] = $this->runner->run(
            $projectId,
            PlanningStageName::SCREENPLAY_FOUNDATION,
            'story foundation',
            $author,
            $source,
            $profile,
            $requirements,
            [],
            $input,
            $meta,
            $force,
            static fn (ScreenplayResult $result): array => self::assemble(
                self::listTreatments($result->screenplay, $profile),
                $result->authorModel, $validator, $profile, $merged, $source, $excluded, $sourceDesign, $sourceAnchor,
            ),
            $schema,
        );

        if ($output === null) {
            return [null, $reason];
        }

        $foundationStage = $this->latestStoryFoundation($projectId);

        if ($foundationStage !== null) {
            $this->ensureCast($projectId, $foundationStage);
        }

        return [$output, $reason];
    }

    /** @return array{0: ?VideoPlanningStage, 1: string} */
    public function ensureCast(string $projectId, VideoPlanningStage $foundationStage): array
    {
        $output = (array) $foundationStage->output_json;
        $sourceDesign = $output[VesselDesign::SOURCE_DESIGN_KEY] ?? null;

        if (($output['schema_version'] ?? null) !== VesselDesign::FOUNDATION_CONTRACT || ! is_array($sourceDesign)) {
            return [null, 'foundation_not_design_first'];
        }

        $design = $this->designs->stageById($projectId, (string) ($sourceDesign['stage_id'] ?? ''));

        if ($design === null
            || ! hash_equals((string) ($sourceDesign['content_hash'] ?? ''), VesselDesign::contentHash((array) $design->output_json))) {
            return [null, 'design_changed_since_anchor'];
        }

        $foundationHash = ScreenplayExpansionService::contentHash(ScreenplayExpansionService::foundationContent($output));
        $row = VesselDesign::vesselRow((array) $design->output_json);
        $sourceFoundation = [
            'stage_id' => (string) $foundationStage->id,
            'revision' => (int) $foundationStage->planning_revision,
            'content_hash' => $foundationHash,
        ];

        $input = [
            'contract_version' => self::CAST_CONTRACT,
            'foundation_content_hash' => $foundationHash,
            'design_content_hash' => (string) $sourceDesign['content_hash'],
            'author_model' => VesselDesign::AUTHOR_MODEL,
        ];

        $meta = [
            'foundation_stage_id' => (string) $foundationStage->id,
            'foundation_revision' => (int) $foundationStage->planning_revision,
            'foundation_output_hash' => $foundationStage->output_hash,
            'profile_source' => 'snapshot',
            'design_stage_id' => (string) $design->id,
        ];

        [$claimed, $token, $claimReason] = $this->stageStore->claimProjectStage(
            $projectId, PlanningStageName::SCREENPLAY_CHARACTERS, $input, false, $meta,
        );

        if ($claimReason === 'already_succeeded') {
            return [$claimed, 'cached'];
        }

        if ($token === null) {
            return [null, 'screenplay_running'];
        }

        $cast = [
            'characters' => [$row],
            'schema_version' => self::CAST_CONTRACT,
            'author_model' => VesselDesign::AUTHOR_MODEL,
            'source_foundation' => $sourceFoundation,
            VesselDesign::SOURCE_DESIGN_KEY => $sourceDesign,
        ];

        $recorded = $this->stageStore->finishSucceeded(
            $claimed->id,
            $token,
            (string) json_encode($cast, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $cast,
            ['model' => VesselDesign::AUTHOR_MODEL, 'tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0],
        );

        return $recorded ? [$claimed->refresh(), 'ok'] : [null, 'screenplay_claim_lost'];
    }

    public function latestStoryFoundation(string $projectId): ?VideoPlanningStage
    {
        return VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::SCREENPLAY_FOUNDATION->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->orderByDesc('planning_revision')
            ->get()
            ->first(static fn (VideoPlanningStage $stage): bool => is_array($stage->output_json)
                && ($stage->output_json['schema_version'] ?? null) === VesselDesign::FOUNDATION_CONTRACT);
    }

    /**
     * @return array{0: ?VideoPlanningStage, 1: string, 2: array<string, mixed>}
     */
    public function recoverFailed(string $stageId, bool $write = false): array
    {
        $failed = VideoPlanningStage::query()
            ->whereKey($stageId)
            ->where('stage', PlanningStageName::SCREENPLAY_FOUNDATION->value)
            ->where('status', VideoPlanningStageStatus::FAILED->value)
            ->first();

        if ($failed === null) {
            return [null, 'stage_not_a_failed_foundation', []];
        }

        $projectId = (string) $failed->project_id;
        $project = $this->projects->getById($projectId);
        $story = json_decode((string) $failed->raw_response, true);
        $meta = PlanningStageStore::metadataOf($failed->input_json);
        $merged = $meta['profile'] ?? null;
        $sourceDesign = $meta[VesselDesign::SOURCE_DESIGN_KEY] ?? null;
        $sourceAnchor = $meta[VesselDesign::SOURCE_ANCHOR_KEY] ?? null;
        $lock = $this->designs->lock($project);

        if (! VesselDesign::isDesignFirst($project)) {
            return [null, 'workflow_not_design_first', []];
        }

        if (! is_array($story) || ! is_array($merged) || ! is_array($sourceDesign) || ! is_array($sourceAnchor)) {
            return [null, 'raw_response_unusable', []];
        }

        if ($lock === null
            || ($lock['design_stage_id'] ?? null) !== ($sourceDesign['stage_id'] ?? null)
            || ($lock['artifact_id'] ?? null) !== ($sourceAnchor['artifact_id'] ?? null)) {
            return [null, 'design_changed_since_anchor', []];
        }

        [$design, , $reason] = $this->designs->lockedSource($projectId, $lock);

        if ($design === null) {
            return [null, $reason, []];
        }

        $newer = VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::SCREENPLAY_FOUNDATION->value)
            ->whereIn('status', [VideoPlanningStageStatus::SUCCEEDED->value, VideoPlanningStageStatus::RUNNING->value])
            ->where('created_at', '>', $failed->created_at)
            ->exists();

        if ($newer) {
            return [null, 'newer_foundation_exists', []];
        }

        $profile = $merged;
        $profile['contract_version'] = VesselDesign::STORY_CONTRACT;
        unset($profile['dimension_bounds']);
        $story = self::listTreatments($story, $profile);

        $arcStages = array_values(array_filter((array) ($profile['arc_stages'] ?? []), 'is_string'));
        $kept = [];
        $dropped = [];

        foreach ((array) ($story['stage_treatments'] ?? []) as $treatment) {
            if (is_array($treatment) && in_array($treatment['stage'] ?? null, $arcStages, true)) {
                $kept[] = $treatment;
            } else {
                $dropped[] = $treatment;
            }
        }

        $story['stage_treatments'] = $kept;
        $inspiration = VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::INSPIRATION->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->where('created_at', '<=', $failed->created_at)
            ->orderByDesc('created_at')
            ->first();
        $excluded = VesselDesignService::excludedNames(is_array($inspiration?->output_json) ? $inspiration->output_json : []);
        $details = ['dropped_stage_treatments' => $dropped, 'ending' => $story['ending'] ?? null];

        [$error, $output] = self::assemble(
            $story,
            (string) ($failed->provider_model ?? $failed->model),
            new ScreenplayValidator,
            $profile,
            $merged,
            VesselDesign::content((array) $design->output_json),
            $excluded,
            $sourceDesign,
            $sourceAnchor,
        );

        if ($error !== null) {
            return [null, 'still_invalid', $details + ['error' => $error]];
        }

        if (! $write) {
            return [null, 'valid_dry_run', $details];
        }

        [$claimed, $token, $claimReason] = $this->stageStore->claimProjectStage(
            $projectId,
            PlanningStageName::SCREENPLAY_FOUNDATION,
            array_diff_key((array) $failed->input_json, [PlanningStageStore::METADATA_KEY => true]),
            true,
            $meta + [
                'recovered_from_stage_id' => (string) $failed->id,
                'recovered_at' => now()->toIso8601String(),
                'normalization' => ['dropped_stage_treatments' => $dropped],
            ],
        );

        if ($token === null) {
            return [null, $claimReason === 'claimed_by_other' ? 'screenplay_running' : $claimReason, $details];
        }

        $recorded = $this->stageStore->finishSucceeded(
            $claimed->id,
            $token,
            (string) $failed->raw_response,
            $output,
            [
                'model' => $failed->model,
                'provider_model' => $failed->provider_model,
                'instruction_version' => $failed->instruction_version,
                'tokens_in' => 0,
                'tokens_out' => 0,
                'cost_usd' => 0,
            ],
        );

        if (! $recorded) {
            return [null, 'screenplay_claim_lost', $details];
        }

        $claimed->refresh();
        $this->ensureCast($projectId, $claimed);

        return [$claimed, 'recovered', $details];
    }

    /**
     * @param  array<string, mixed>  $story
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $merged
     * @param  array<string, mixed>  $source
     * @param  list<string>  $excluded
     * @param  array<string, mixed>  $sourceDesign
     * @param  array<string, mixed>  $sourceAnchor
     * @return array{0: ?string, 1: ?array<string, mixed>, 2: string}
     */
    private static function assemble(
        array $story,
        string $authorModel,
        ScreenplayValidator $validator,
        array $profile,
        array $merged,
        array $source,
        array $excluded,
        array $sourceDesign,
        array $sourceAnchor,
    ): array {
        $violations = $validator->structural($story, $profile, VesselDesign::STORY_CONTRACT, $excluded);

        if ($violations !== []) {
            return ['Story foundation failed validation: '.implode('; ', $violations), null, ''];
        }

        $foundation = array_intersect_key($story, array_flip(VesselDesign::STORY_KEYS))
            + array_intersect_key($source, array_flip(VesselDesign::FIXED_FOUNDATION_KEYS));
        $assembled = $validator->structural($foundation, $merged, VesselDesign::FOUNDATION_CONTRACT, $excluded);

        if ($assembled !== []) {
            return ['Assembled foundation failed validation: '.implode('; ', $assembled), null, ''];
        }

        return [null, $foundation + [
            'schema_version' => VesselDesign::FOUNDATION_CONTRACT,
            'author_model' => $authorModel,
            VesselDesign::SOURCE_DESIGN_KEY => $sourceDesign,
            VesselDesign::SOURCE_ANCHOR_KEY => $sourceAnchor,
        ], 'ok'];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public static function storySchema(array $schema, array $profile): array
    {
        $stages = self::arcStages($profile);
        $item = $schema['properties']['stage_treatments']['items'] ?? null;

        if ($stages === [] || ! is_array($item) || ! isset($item['properties']['stage'])) {
            return $schema;
        }

        unset($item['properties']['stage']);
        $item['required'] = array_values(array_diff((array) ($item['required'] ?? []), ['stage']));

        $schema['properties']['stage_treatments'] = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $stages,
            'properties' => array_fill_keys($stages, $item),
        ];

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $story
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public static function listTreatments(array $story, array $profile): array
    {
        $treatments = $story['stage_treatments'] ?? null;

        if (! is_array($treatments) || array_is_list($treatments)) {
            return $story;
        }

        $listed = [];

        foreach (self::arcStages($profile) as $stage) {
            if (is_array($treatments[$stage] ?? null)) {
                $listed[] = ['stage' => $stage] + $treatments[$stage];
            }
        }

        foreach (array_diff_key($treatments, array_flip(self::arcStages($profile))) as $stage => $treatment) {
            $listed[] = ['stage' => (string) $stage] + (is_array($treatment) ? $treatment : []);
        }

        $story['stage_treatments'] = $listed;

        return $story;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    private static function arcStages(array $profile): array
    {
        return array_values(array_filter(
            (array) ($profile['arc_stages'] ?? []),
            static fn (mixed $stage): bool => is_string($stage) && $stage !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private static function storyShape(array $snapshot): array
    {
        $shape = ScreenplayExpansionService::foundationShape($snapshot);

        foreach (self::DESIGN_ONLY_PROFILE_KEYS as $key) {
            unset($shape[$key]);
        }

        $shape['contract_version'] = VesselDesign::STORY_CONTRACT;
        $downstream = VesselDesign::downstreamProfile($snapshot);

        foreach ([VesselDesign::SCREENPLAY_DEPENDENCY_KEY, 'subject_policy'] as $key) {
            if (isset($downstream[$key])) {
                $shape[$key] = $downstream[$key];
            }
        }

        return $shape;
    }
}
