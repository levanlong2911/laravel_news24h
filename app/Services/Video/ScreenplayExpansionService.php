<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Video\Prompt\Exceptions\TextCompletionException;
use App\Video\Screenplay\ScreenplayAuthor;
use App\Video\Screenplay\ScreenplayFailure;
use App\Video\Screenplay\ScreenplayResult;
use App\Video\Screenplay\ScreenplayValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

final class ScreenplayExpansionService
{
    public const CURL_OPERATION_TIMEDOUT = 28;

    /** @var list<string> */
    private const SCENE_SECTIONS = ['characters', 'locations', 'scenes', 'coverage'];

    /** @var list<string> */
    private const FOUNDATION_SECTIONS = [
        'logline', 'design_thesis', 'principal_dimensions', 'premise',
        'synopsis', 'stage_treatments', 'ending',
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
    ) {}

    /** @return array{0: ?array<string, mixed>, 1: string} */
    public function authorCharacters(string $projectId, ?string $foundationStageId, bool $force = false): array
    {
        return $this->authorCast($projectId, 'characters', $foundationStageId, $force);
    }

    /** @return array{0: ?array<string, mixed>, 1: string} */
    public function authorLocations(string $projectId, ?string $foundationStageId, bool $force = false): array
    {
        return $this->authorCast($projectId, 'locations', $foundationStageId, $force);
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

        $locations = $this->selectedCast($projectId, 'locations', $locationsStageId, $context['foundation_hash']);

        if ($locations === null) {
            return [null, 'screenplay_locations_not_selectable'];
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

        $input = [
            'contract_version' => $contract,
            'target_schema_version' => $assembledVersion,
            'foundation_content_hash' => $context['foundation_hash'],
            'characters_content_hash' => self::contentHash($fixed['characters']),
            'locations_content_hash' => self::contentHash($fixed['locations']),
            'output_schema_hash' => self::contentHash($constrained),
            'fingerprint' => $author->fingerprint($context['foundation'], $context['profile'], $context['requirements'], $fixed),
        ];

        $meta = $context['meta'] + [
            'characters_stage_id' => $characters->id,
            'locations_stage_id' => $locations->id,
        ];

        $validator = new ScreenplayValidator;

        return $this->runStep(
            $projectId,
            PlanningStageName::SCREENPLAY,
            'scenes',
            $author,
            $context,
            $fixed,
            $input,
            $meta,
            $force,
            function (ScreenplayResult $result) use ($validator, $context, $contract, $assembledVersion, $fixed, $input, $characters, $locations, $silence): array {
                $violations = [];

                foreach (array_keys($fixed) as $supplied) {
                    if (array_key_exists($supplied, $result->screenplay)) {
                        $violations[] = "{$supplied}: this step does not produce it";
                    }
                }

                $expansion = $fixed + $result->screenplay;

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
                    'author_model' => $result->authorModel,
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
                ], $warnings === [] ? 'ok' : 'ok_needs_review'];
            },
            $constrained,
        );
    }

    /**
     * @return array{rows: ?list<array<string, mixed>>, stage_id: ?string, revision: ?int, running: bool,
     *               error: ?string, written_at: ?string, foundation_stage_id: ?string, foundation_revision: ?int,
     *               usable: bool}
     */
    public function latestCast(string $projectId, string $part, ?string $foundationStageId = null): array
    {
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
            'usable' => $rows !== null && $this->usableFor($projectId, $part, $latest->id, $foundationStageId),
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
        $previous = $e->getPrevious();

        if ($previous instanceof \GuzzleHttp\Exception\ConnectException) {
            $errno = $previous->getHandlerContext()['errno'] ?? null;

            if (is_int($errno)) {
                return $errno;
            }
        }

        return preg_match('/cURL error (\d+)/', $e->getMessage(), $match) === 1
            ? (int) $match[1]
            : null;
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
        try {
            $written = $this->stageStore->finishFailed($stageId, $token, $error, $usage, $rawResponse);
        } catch (\Throwable $storage) {
            Log::error('screenplay: writing the failed attempt threw, stored state unknown', [
                'project_id' => $projectId,
                'stage_id' => $stageId,
                'failure' => $error,
                'exception' => $storage,
            ]);

            return 'screenplay_result_not_stored';
        }

        if (! $written) {
            Log::warning('screenplay: claim no longer held, failure not recorded', [
                'project_id' => $projectId,
                'stage_id' => $stageId,
                'failure' => $error,
            ]);

            return 'screenplay_claim_lost';
        }

        return $reason;
    }

    /** @return array{0: ?array<string, mixed>, 1: string} */
    private function authorCast(string $projectId, string $part, ?string $foundationStageId, bool $force): array
    {
        [$context, $reason] = $this->prepare($projectId, $foundationStageId, self::CAST[$part]['author'], false);

        if ($context === null) {
            return [null, $reason];
        }

        $author = $context['author'];
        $contract = $context['contract'];

        $input = [
            'contract_version' => $contract,
            'foundation_content_hash' => $context['foundation_hash'],
            'fingerprint' => $author->fingerprint($context['foundation'], $context['profile'], $context['requirements']),
        ];

        $validator = new ScreenplayValidator;

        return $this->runStep(
            $projectId,
            self::CAST[$part]['stage'],
            $part,
            $author,
            $context,
            [],
            $input,
            $context['meta'],
            $force,
            function (ScreenplayResult $result) use ($validator, $context, $contract, $part): array {
                $violations = $validator->structural(
                    $result->screenplay, $context['profile'], $contract, $context['excluded'],
                );

                if ($violations !== []) {
                    return [ucfirst($part).' failed validation: '.implode('; ', $violations), null, ''];
                }

                return [null, [
                    $part => $result->screenplay[$part],
                    'schema_version' => $contract,
                    'author_model' => $result->authorModel,
                    'source_foundation' => $context['source_foundation'],
                ], 'ok'];
            },
        );
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

        $loaded = $this->screenplayProfile((string) ($project->article->category?->slug ?? ''));

        if ($loaded === null) {
            return [null, 'no_screenplay_profile'];
        }

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

        $contract = $author->contractVersion();
        $assembledVersion = (string) config('video.screenplay.scenes.assembled_version');
        $profile = $loaded;
        $profile['contract_version'] = $contract;
        $assembledProfile = $loaded;
        $assembledProfile['contract_version'] = $assembledVersion;
        $assembledProfile['dimension_bounds'] = config('video.screenplay.foundation.dimension_bounds');

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
            'requirements' => ['aspect_ratio' => (string) config('video.screenplay.aspect_ratio', '9:16')],
            'excluded' => array_values(array_map(
                static fn (array $item): string => (string) ($item['value'] ?? ''),
                is_array($brief) ? ($brief['excluded_context'] ?? []) : [],
            )),
            'meta' => [
                'foundation_stage_id' => $foundationStage->id,
                'foundation_revision' => $foundationStage->planning_revision,
                'foundation_output_hash' => $foundationStage->output_hash,
            ],
            'source_foundation' => [
                'stage_id' => $foundationStage->id,
                'revision' => $foundationStage->planning_revision,
                'content_hash' => $foundationHash,
            ],
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
        [$claimed, $token, $claimReason] = $this->stageStore->claimProjectStage(
            $projectId, $stage, $input, $force, $meta,
        );

        if ($claimReason === 'already_succeeded') {
            return [$claimed->output_json ?? [], 'cached'];
        }

        if ($token === null) {
            return [null, 'screenplay_running'];
        }

        $fail = function (string $error, string $reason, array $usage = [], string $raw = '') use (
            $projectId, $stage, $label, $input, $meta, $claimed, $token,
        ): string {
            $recorded = $this->recordFailure($projectId, $claimed->id, $token, $error, $reason, $usage, $raw);

            return $recorded === 'screenplay_claim_lost' && ($usage !== [] || $raw !== '')
                ? $this->keepLostAttempt($projectId, $stage, $label, $input, $meta, $claimed->id, $token, $error, $usage, $raw)
                : $recorded;
        };

        $startedAt = microtime(true);

        try {
            $result = $author->author(
                $context['foundation'], $context['profile'], $context['requirements'], $fixed, $constrainedSchema,
            );
        } catch (ScreenplayFailure $e) {
            Log::error("screenplay {$label}: author failed after a paid response", [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'kind' => $e->kind,
                'waited_seconds' => round(microtime(true) - $startedAt, 1),
                'exception' => $e,
            ]);

            return [null, $fail(
                $e->getMessage(),
                $e->kind === ScreenplayFailure::TRUNCATED ? 'screenplay_truncated' : 'screenplay_author_failed',
                $e->usage, $e->rawResponse,
            )];
        } catch (ConnectionException $e) {
            $errno = $this->curlErrorNumber($e);

            Log::error("screenplay {$label}: the request never reached a response", [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'waited_seconds' => round(microtime(true) - $startedAt, 1),
                'curl_errno' => $errno,
                'exception' => $e,
            ]);

            return [null, $this->recordFailure(
                $projectId, $claimed->id, $token, $e->getMessage(),
                $errno === self::CURL_OPERATION_TIMEDOUT ? 'screenplay_timeout' : 'screenplay_connection_failed',
            )];
        } catch (\Throwable $e) {
            Log::error("screenplay {$label}: author failed before any response", [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'waited_seconds' => round(microtime(true) - $startedAt, 1),
                'exception' => $e,
            ]);

            return [null, $this->recordFailure(
                $projectId, $claimed->id, $token, $e->getMessage(), 'screenplay_call_failed',
            )];
        }

        try {
            [$error, $output, $okReason] = $finish($result);
        } catch (\Throwable $e) {
            Log::error("screenplay {$label}: failed after a paid response", [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'exception' => $e,
            ]);

            return [null, $fail(
                $e->getMessage(), 'screenplay_after_response_failed', $result->usage, $result->rawResponse,
            )];
        }

        if ($error !== null || $output === null) {
            return [null, $fail((string) $error, 'screenplay_invalid', $result->usage, $result->rawResponse)];
        }

        try {
            $recorded = $this->stageStore->finishSucceeded(
                $claimed->id, $token, $result->rawResponse, $output, $result->usage,
            );
        } catch (\Throwable $e) {
            Log::error("screenplay {$label}: writing the paid result threw, stored state unknown", [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'exception' => $e,
            ]);

            return [null, 'screenplay_result_not_stored'];
        }

        if (! $recorded) {
            return [null, $this->keepLostAttempt(
                $projectId, $stage, $label, $input, $meta, $claimed->id, $token,
                'claim_lost', $result->usage, $result->rawResponse, $output,
            )];
        }

        return [$output, $okReason];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $output
     */
    private function keepLostAttempt(
        string $projectId,
        PlanningStageName $stage,
        string $label,
        array $input,
        array $meta,
        string $stageId,
        string $token,
        string $error,
        array $usage,
        string $rawResponse,
        array $output = [],
    ): string {
        $kept = false;

        try {
            $kept = $this->stageStore->recordOrphanAttempt(
                $projectId, $stage, $input, $meta, $stageId, $token, $error, $usage, $rawResponse, $output,
            );
        } catch (\Throwable $e) {
            Log::error("screenplay {$label}: keeping the lost paid attempt threw", [
                'project_id' => $projectId,
                'stage_id' => $stageId,
                'exception' => $e,
            ]);
        }

        Log::warning("screenplay {$label}: claim lost after a paid response", [
            'project_id' => $projectId,
            'stage_id' => $stageId,
            'orphan_recorded' => $kept,
        ]);

        return 'screenplay_claim_lost';
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
            ->first(['id', 'planning_revision', 'output_hash', 'output_json']);

        $version = is_array($stage?->output_json) ? ($stage->output_json['schema_version'] ?? null) : null;

        return $version === config('video.screenplay.scenes.foundation_version') ? $stage : null;
    }

    private function usableFor(string $projectId, string $part, string $castStageId, ?string $foundationStageId): bool
    {
        $foundation = $this->selectableFoundation($projectId, $foundationStageId);

        return $foundation !== null && $this->selectedCast(
            $projectId, $part, $castStageId, self::contentHash(self::foundationContent($foundation->output_json)),
        ) !== null;
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
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private static function foundationContent(array $output): array
    {
        $content = [];

        foreach (self::FOUNDATION_SECTIONS as $section) {
            $content[$section] = $output[$section] ?? null;
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
        $scene['build_state']['properties']['subject_id'] = $only(
            $scene['build_state']['properties']['subject_id'], $ids($characters, 'object'),
        );

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

    private static function contentHash(mixed $value): string
    {
        return hash('sha256', json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }
}
