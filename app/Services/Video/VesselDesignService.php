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
use App\Video\Reference\ReferenceView;
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
    private const CORRECTION_VERSION_MAX = 40;

    public const REPAIR_KEY = 'design_repair';

    public const REFERENCE_SELECTION_KEY = 'design_reference_selection';

    public const REPAIR_VERSION = 'design-repair-serves-v1';

    private const REPAIR_SYSTEM = <<<'TEXT'
You correct one kind of error in a finished superyacht design. Each opening listed names, in serves, the recess, frame or other part it sits in, while serves must name the room, spatial region, surface or void behind the opening: the space it lights or the surface it opens onto.

For each opening, choose its serves from its allowed_targets only, using the opening's own rows and the profile passages given. Return one change per opening, copying its current serves into before_serves. Change nothing else and invent nothing.

When the passages do not settle which allowed target the opening serves, return no change for that opening and write one conflict line naming it.

Return only a JSON object with changes and conflicts.
TEXT;

    private const PASSAGE_LIMIT = 1200;

    /** @var list<string> */
    private const ADDABLE_PATHS = [
        '/^canonical_design\.surfaces\.\d+\.side$/',
        '/^canonical_design\.(spatial_topology|openings|surfaces|basins|routes)\.\d+\.exterior_role$/',
        '/^canonical_design\.permanent_secondary_geometry\.\d+\.form$/',
    ];

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
            function (ScreenplayResult $result, array $run = []) use ($validator, $profile, $fullProfile, $excluded): array {
                $validate = static fn (array $design): array => [
                    ...$validator->structural($design, VesselDesign::validationProfile($profile, $fullProfile), VesselDesign::CONTRACT, $excluded),
                    ...VesselDesign::newDesignViolations($design),
                ];
                $design = $result->screenplay;
                $violations = $validate($design);
                $extra = [];

                if ($violations !== []) {
                    [$design, $violations, $extra] = $this->repairServes($design, $violations, $validate, $run);
                }

                if ($violations !== []) {
                    $record = json_decode((string) ($extra['metadata'][self::REPAIR_KEY] ?? ''), true);
                    $note = is_array($record) ? ['design_repair: '.$record['result'].($record['reason'] !== null ? " ({$record['reason']})" : '')] : [];

                    return ['Vessel design failed validation: '.implode('; ', [...$violations, ...$note]), null, '', $extra];
                }

                return [null, VesselDesign::withSystemDecisions($design, $profile) + [
                    'schema_version' => VesselDesign::CONTRACT,
                    'author_model' => $result->authorModel,
                ], 'ok', $extra];
            },
            $schema,
        );
    }

    /**
     * @param  array<string, mixed>  $design
     * @param  list<string>  $violations
     * @param  callable(array<string, mixed>): list<string>  $validate
     * @param  array{stage_id?: string, lease_expires_at?: mixed}  $run
     * @return array{0: array<string, mixed>, 1: list<string>, 2: array{usage?: array<string, mixed>, metadata?: array<string, mixed>}}
     */
    private function repairServes(array $design, array $violations, callable $validate, array $run): array
    {
        $issues = VesselDesign::servesKindIssues($design);

        if ($issues === []) {
            return [$design, $violations, []];
        }

        $record = [
            'version' => self::REPAIR_VERSION, 'result' => 'skipped', 'reason' => null, 'issues' => $issues, 'targets' => [],
            'violations_before' => $violations, 'violations_after' => null, 'changes' => [], 'conflicts' => [], 'raw' => null, 'usage' => null,
        ];
        $done = static fn (array $record, array $usage = []): array => ($usage === [] ? [] : ['usage' => $usage])
            + ['metadata' => [self::REPAIR_KEY => json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]];
        $skip = static fn (string $reason) => [$design, $violations, $done(['reason' => $reason] + $record)];
        $settings = (array) config('video.screenplay.design.repair');

        if (! (bool) ($settings['enabled'] ?? false)) {
            return $skip('disabled');
        }

        if (count($issues) > (int) ($settings['max_openings'] ?? 0)) {
            return $skip('too_many_openings');
        }

        $withoutServes = $design;

        foreach ($issues as $issue) {
            $withoutServes[VesselDesign::CANONICAL_KEY]['openings'][$issue['index']]['serves'] = null;
        }

        if ($validate($withoutServes) !== []) {
            return $skip('other_violations');
        }

        $contexts = [];
        $unsettled = [];

        foreach ($issues as $issue) {
            [$context, $why] = self::servesContext($design, $issue);

            if ($context === null) {
                $unsettled[] = "{$issue['opening_id']} {$why}";

                continue;
            }

            $contexts[] = $context;
            $record['targets'][$issue['opening_id']] = array_column($context['allowed_targets'], 'id');
        }

        if ($unsettled !== []) {
            return $skip('unsettled_target: '.implode(', ', $unsettled));
        }

        $lease = $run['lease_expires_at'] ?? null;
        $needed = (int) ($settings['timeout_seconds'] ?? 0) + (int) ($settings['lease_margin_seconds'] ?? 0);

        if (! $lease instanceof \DateTimeInterface || $lease->getTimestamp() - time() < $needed) {
            return $skip('not_enough_time');
        }

        $user = "REPAIR\n\n".json_encode(['openings' => $contexts], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        try {
            $response = app('video.screenplay.design_repair_author')->repair(self::REPAIR_SYSTEM, $user, self::servesRepairSchema($record['targets']));
        } catch (\App\Video\Screenplay\ScreenplayFailure $e) {
            Log::warning('vessel design: serves repair call failed after a response', ['stage_id' => $run['stage_id'] ?? null, 'reason' => $e->getMessage()]);

            return [$design, $violations, $done(['result' => 'failed_call', 'reason' => $e->getMessage(), 'raw' => $e->rawResponse, 'usage' => $e->usage] + $record, $e->usage)];
        } catch (\Throwable $e) {
            Log::warning('vessel design: serves repair call failed', ['stage_id' => $run['stage_id'] ?? null, 'reason' => $e->getMessage()]);

            return [$design, $violations, $done(['result' => 'failed_call', 'reason' => $e->getMessage()] + $record)];
        }

        $record['raw'] = $response->rawResponse;
        $record['usage'] = $response->usage;
        [$changes, $problems, $conflicts] = self::acceptedServesChanges($response->screenplay, $issues, $record['targets']);
        $record['conflicts'] = $conflicts;

        if ($problems !== []) {
            Log::warning('vessel design: serves repair rejected', ['stage_id' => $run['stage_id'] ?? null, 'problems' => $problems]);

            return [$design, $violations, $done(['result' => 'rejected', 'reason' => implode('; ', $problems)] + $record, $response->usage)];
        }

        $patched = $design;

        foreach ($changes as $change) {
            $patched[VesselDesign::CANONICAL_KEY]['openings'][$change['index']]['serves'] = $change['after'];
        }

        $after = $validate($patched);
        $record['changes'] = array_map(static fn (array $change): array => array_diff_key($change, ['index' => true]), $changes);
        $record['violations_after'] = $after;
        $record['result'] = $after === [] ? 'repaired' : 'still_invalid';

        Log::info('vessel design: serves repair finished', ['stage_id' => $run['stage_id'] ?? null, 'result' => $record['result']]);

        return $after === []
            ? [$patched, [], $done($record, $response->usage)]
            : [$design, $after, $done($record, $response->usage)];
    }

    /**
     * @param  array<string, mixed>  $design
     * @param  array{code: string, path: string, index: int, opening_id: string, serves: string, serves_list: string}  $issue
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    private static function servesContext(array $design, array $issue): array
    {
        $canonical = $design[VesselDesign::CANONICAL_KEY];
        $opening = $canonical['openings'][$issue['index']];
        $locked = static fn (string $list): array => array_column(array_filter((array) ($canonical[$list] ?? []),
            static fn (mixed $row): bool => is_array($row) && ($row['status'] ?? null) === 'locked' && is_string($row['id'] ?? null)), null, 'id');
        $deckName = (string) (array_column((array) ($canonical['decks'] ?? []), 'name', 'id')[$opening['deck'] ?? ''] ?? '');
        $paths = array_values(array_filter((array) ($opening[VesselDesign::PROFILE_PATHS_KEY] ?? []), 'is_string'));
        $spacePaths = [];

        foreach ($paths as $path) {
            if (preg_match('/^('.preg_quote(ProtagonistProfile::INTERIOR_KEY, '/').'\.\d+)(?:\.|$)/', $path, $match) === 1) {
                $spacePaths[$match[1]] = true;
            }
        }

        $regions = $locked('spatial_topology');
        $rooms = [];

        foreach ((array) ($design[VesselDesign::GEOMETRY_LINKS_KEY] ?? []) as $link) {
            if (($link['role'] ?? null) !== 'space' || ! isset($spacePaths[$link['profile_path'] ?? ''])) {
                continue;
            }

            foreach ((array) ($link['refs'] ?? []) as $ref) {
                if (isset($regions[$ref]) && $deckName !== '' && mb_stripos((string) ($regions[$ref]['deck'] ?? ''), $deckName) !== false) {
                    $rooms[$ref] = true;
                }
            }
        }

        if (count($rooms) !== 1) {
            return [null, $rooms === [] ? 'no_room' : 'several_rooms'];
        }

        $room = (string) array_key_first($rooms);
        $targets = [['id' => $room, 'list' => 'spatial_topology', 'name' => (string) ($regions[$room]['region'] ?? $room)]];

        foreach ($locked('surfaces') as $id => $surface) {
            if (($surface['relative_to'] ?? null) === $room && ($surface['relation'] ?? null) === 'within' && ($surface['deck'] ?? null) === ($opening['deck'] ?? null)) {
                $targets[] = ['id' => (string) $id, 'list' => 'surfaces', 'name' => trim(($surface['kind'] ?? 'surface').' within '.($regions[$room]['region'] ?? $room))];
            }
        }

        $passages = [];

        foreach ($paths as $path) {
            $text = data_get($design[ProtagonistProfile::OUTPUT_KEY] ?? [], $path);
            $passages[$path] = mb_substr(is_string($text) ? $text : (string) json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, self::PASSAGE_LIMIT);
        }

        $secondary = $locked('permanent_secondary_geometry');
        $masses = $locked('primary_masses');
        $host = (string) ($opening['host'] ?? '');

        return [[
            'opening_id' => $issue['opening_id'],
            'deck' => $deckName,
            'face' => $opening['face'] ?? null,
            'kind' => $opening['kind'] ?? null,
            'host' => isset($masses[$host]) ? ['id' => $host, 'role' => $masses[$host]['role'] ?? null]
                : (isset($secondary[$host]) ? ['id' => $host, 'element' => $secondary[$host]['element'] ?? null] : ['id' => $host]),
            'current_serves' => ['id' => $issue['serves'], 'list' => $issue['serves_list']]
                + array_intersect_key($secondary[$issue['serves']] ?? [], array_flip(['element', 'position', 'description', 'form'])),
            'passages' => $passages,
            'allowed_targets' => $targets,
        ], 'ok'];
    }

    /**
     * @param  array<string, list<string>>  $targets
     * @return array<string, mixed>
     */
    private static function servesRepairSchema(array $targets): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['changes', 'conflicts'],
            'properties' => [
                'changes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['opening_id', 'before_serves', 'after_serves'],
                        'properties' => [
                            'opening_id' => ['type' => 'string', 'enum' => array_keys($targets)],
                            'before_serves' => ['type' => 'string'],
                            'after_serves' => ['type' => 'string', 'enum' => array_values(array_unique(array_merge(...array_values($targets))))],
                        ],
                    ],
                ],
                'conflicts' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $answer
     * @param  list<array{code: string, path: string, index: int, opening_id: string, serves: string, serves_list: string}>  $issues
     * @param  array<string, list<string>>  $targets
     * @return array{0: list<array{opening_id: string, path: string, index: int, before: string, after: string}>, 1: list<string>, 2: list<string>}
     */
    public static function acceptedServesChanges(array $answer, array $issues, array $targets): array
    {
        $changes = $answer['changes'] ?? null;
        $conflicts = array_values(array_filter((array) ($answer['conflicts'] ?? []), 'is_string'));
        $problems = [];

        if (! is_array($changes) || ! array_is_list($changes) || ! is_array($answer['conflicts'] ?? null) || array_diff(array_keys($answer), ['changes', 'conflicts']) !== []) {
            return [[], ['the answer is not an object of changes and conflicts only'], $conflicts];
        }

        if ($conflicts !== []) {
            $problems[] = 'the answer reports conflicts: '.implode(' | ', $conflicts);
        }

        $byId = array_column($issues, null, 'opening_id');
        $accepted = [];

        foreach ($changes as $position => $change) {
            $id = is_array($change) ? ($change['opening_id'] ?? null) : null;

            if (! is_array($change) || array_diff(array_keys($change), ['opening_id', 'before_serves', 'after_serves']) !== []) {
                $problems[] = "changes[{$position}]: carries fields beyond opening_id, before_serves and after_serves";
            } elseif (! is_string($id) || ! isset($byId[$id])) {
                $problems[] = "changes[{$position}]: ".json_encode($id).' is not an opening under repair';
            } elseif (isset($accepted[$id])) {
                $problems[] = "changes[{$position}]: repeats {$id}";
            } elseif (($change['before_serves'] ?? null) !== $byId[$id]['serves']) {
                $problems[] = "changes[{$position}]: before_serves does not match the current serves of {$id}";
            } elseif (! in_array($change['after_serves'] ?? null, $targets[$id] ?? [], true)) {
                $problems[] = "changes[{$position}]: ".json_encode($change['after_serves'] ?? null)." is not an allowed target of {$id}";
            } else {
                $accepted[$id] = ['opening_id' => $id, 'path' => $byId[$id]['path'], 'index' => $byId[$id]['index'], 'before' => $byId[$id]['serves'], 'after' => $change['after_serves']];
            }
        }

        foreach (array_diff(array_keys($byId), array_keys($accepted)) as $missing) {
            if (! in_array("changes: {$missing} is missing", $problems, true)) {
                $problems[] = "changes: {$missing} is missing";
            }
        }

        return $problems === [] ? [array_values($accepted), [], $conflicts] : [[], $problems, $conflicts];
    }

    /**
     * @return array{0: ?VideoPlanningStage, 1: string, 2: list<string>}
     */
    public function recoverFailed(string $stageId, bool $write = false, ?array $patch = null): array
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

        if ($patch !== null) {
            [$design, $patchProblem] = self::recoveryPatch($stageId, (string) $failed->raw_response, $patch);

            if ($design === null) {
                return [null, 'recovery_patch_invalid', [$patchProblem]];
            }
        }

        if (! is_array($profile) || ! is_array($snapshot)) {
            return [null, 'screenplay_profile_unknown', []];
        }

        $newer = static fn (?string $except = null): bool => VideoPlanningStage::query()
            ->where('project_id', $failed->project_id)
            ->where('stage', PlanningStageName::VESSEL_DESIGN->value)
            ->whereIn('status', [VideoPlanningStageStatus::SUCCEEDED->value, VideoPlanningStageStatus::RUNNING->value])
            ->where('planning_revision', '>', $failed->planning_revision)
            ->when($except !== null, static fn ($query) => $query->whereKeyNot($except))
            ->exists();

        if ($newer()) {
            return [null, 'newer_design_exists', []];
        }

        $inspiration = VideoPlanningStage::query()
            ->where('project_id', $failed->project_id)
            ->where('stage', PlanningStageName::INSPIRATION->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->where('created_at', '<=', $failed->created_at)
            ->orderByDesc('created_at')
            ->first();
        $excluded = self::excludedNames(is_array($inspiration?->output_json) ? $inspiration->output_json : []);

        $violations = (new ScreenplayValidator)->structural(
            $design, VesselDesign::validationProfile($profile, $snapshot), VesselDesign::CONTRACT, $excluded,
        );

        if ($violations !== []) {
            return [null, 'still_invalid', $violations];
        }

        $design = VesselDesign::withSystemDecisions($design, $profile);
        $open = array_column(VesselDesign::unresolvedDecisions($design), 'question');

        if (! $write) {
            return [null, $open === [] ? 'valid_dry_run' : 'valid_with_open_decisions', $open];
        }

        $input = array_diff_key((array) $failed->input_json, [PlanningStageStore::METADATA_KEY => true]);
        if ($patch !== null) {
            $input['recovery_patch_hash'] = ScreenplayExpansionService::contentHash($patch);
        }
        $recoveredMeta = array_replace($meta, [
            'recovered_from_stage_id' => (string) $failed->id,
            'recovered_at' => now()->toIso8601String(),
            'recovery_patch' => $patch,
        ]);

        [$claimed, $token, $reason] = $this->stageStore->claimProjectStage(
            (string) $failed->project_id, PlanningStageName::VESSEL_DESIGN, $input, true, $recoveredMeta,
        );

        if ($token === null) {
            return [null, $reason === 'claimed_by_other' ? 'screenplay_running' : $reason, []];
        }

        if ($newer((string) $claimed->id)) {
            $this->stageStore->releaseClaim($claimed->id, 'A newer design revision appeared while recovering');

            return [null, 'newer_design_exists', []];
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

        return $recorded
            ? [$claimed->refresh(), $open === [] ? 'recovered' : 'recovered_with_open_decisions', $open]
            : [null, 'screenplay_claim_lost', []];
    }

    /** @return list<array{name: string, central_idea: string, visible_difference: string}> */
    public function previousDesigns(VideoProject $project): array
    {
        // Compare across articles without exposing another administrator's designs.
        $projectIds = $project->admin_id === null
            ? [$project->id]
            : VideoProject::query()
                ->where('admin_id', $project->admin_id)
                ->where('project_type', $project->project_type)
                ->pluck('id')->all();

        $stages = VideoPlanningStage::query()
            ->whereIn('project_id', $projectIds)
            ->whereIn('stage', [PlanningStageName::VESSEL_DESIGN->value, PlanningStageName::SCREENPLAY_FOUNDATION->value])
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursor();

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
        $selected = VideoProject::query()->find($projectId)?->metadata_json[VesselDesign::SELECTION_KEY]['stage_id'] ?? null;

        return VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::VESSEL_DESIGN->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->orderByDesc('planning_revision')
            ->get()
            ->first(static fn (VideoPlanningStage $stage): bool => is_array($stage->output_json)
                && ($stage->output_json['schema_version'] ?? null) === VesselDesign::CONTRACT
                && (! self::isCorrection($stage) || (string) $stage->id === $selected));
    }

    public static function isCorrection(VideoPlanningStage $stage): bool
    {
        return is_string($stage->input_json[VesselDesign::CORRECTION_INPUT_KEY] ?? null);
    }

    /**
     * @param  array<string, mixed>  $correction
     * @return array<string, mixed>
     */
    public function correctDesign(string $stageId, array $correction, bool $write = false, ?string $actor = null): array
    {
        $report = [
            'stage' => null,
            'changes' => [],
            'missing_decisions' => [],
            'validation_source' => null,
            'patch_hash' => null,
            'violations' => [],
            'gate' => null,
            'anchor_blockers' => [],
            'passed' => false,
            'reason' => null,
            'applied' => false,
            'revision_stage_id' => null,
            'revision' => null,
        ];

        $stop = static function (string $reason, array $violations = []) use (&$report): array {
            $report['reason'] = $reason;
            $report['violations'] = $violations;

            return $report;
        };

        $source = VideoPlanningStage::query()
            ->whereKey($stageId)
            ->where('stage', PlanningStageName::VESSEL_DESIGN->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->first();

        if ($source === null || ! is_array($source->output_json)
            || ($source->output_json['schema_version'] ?? null) !== VesselDesign::CONTRACT) {
            return $stop('stage_not_a_design');
        }

        $output = $source->output_json;
        $report['stage'] = [
            'id' => (string) $source->id,
            'project_id' => (string) $source->project_id,
            'revision' => (int) $source->planning_revision,
            'output_hash' => (string) $source->output_hash,
            'content_hash' => VesselDesign::contentHash($output),
        ];

        $shapeProblem = self::correctionShapeProblem($correction);

        if ($shapeProblem !== null) {
            return $stop('correction_invalid', [$shapeProblem]);
        }

        if ($correction['source_stage_id'] !== (string) $source->id) {
            return $stop('correction_other_stage');
        }

        if (! hash_equals($correction['source_output_hash'], (string) $source->output_hash)
            || ! hash_equals($correction['source_content_hash'], $report['stage']['content_hash'])) {
            return $stop('source_changed');
        }

        $meta = PlanningStageStore::metadataOf($source->input_json);
        $profile = $meta['profile'] ?? null;
        $snapshot = $meta[ScreenplayExpansionService::PROFILE_SNAPSHOT_KEY] ?? null;

        if (! is_array($profile) || ! is_array($snapshot)) {
            return $stop('screenplay_profile_unknown');
        }

        [$corrected, $changes, $patchReason] = self::applyPatch($output, $correction['operations']);

        if ($corrected === null) {
            return $stop($patchReason);
        }

        $missing = $correction['missing_decisions'];

        if ($missing === []) {
            unset($corrected[VesselDesign::UNRESOLVED_KEY]);
        } else {
            $corrected[VesselDesign::UNRESOLVED_KEY] = $missing;
        }

        $corrected = VesselDesign::withSystemDecisions($corrected, $profile);
        $missing = VesselDesign::unresolvedDecisions($corrected);

        $report['changes'] = $changes;
        $report['missing_decisions'] = $missing;
        $report['patch_hash'] = ScreenplayExpansionService::contentHash([
            'version' => $correction['version'],
            'operations' => $correction['operations'],
            'missing_decisions' => $missing,
        ]);

        $validationSource = self::correctionValidationSource($source, $meta);

        if ($validationSource === null) {
            return $stop('validation_source_unknown');
        }

        $report['validation_source'] = $validationSource;
        $excluded = $validationSource['excluded'];
        $body = array_diff_key($corrected, array_flip(['schema_version', 'author_model', VesselDesign::UNRESOLVED_KEY]));

        $report['gate'] = VesselDesign::hasCanonical($corrected) ? VesselDesign::gate($corrected, $snapshot) : null;
        $violations = (new ScreenplayValidator)->structural(
            $body, VesselDesign::validationProfile($profile, $snapshot), VesselDesign::CONTRACT, $excluded,
        );

        if ($violations !== []) {
            return $stop('still_invalid', $violations);
        }

        $report['passed'] = true;
        $report['anchor_blockers'] = VesselDesign::anchorBlockers($corrected);

        if (! $write) {
            return $report;
        }

        $input = [
            'contract_version' => VesselDesign::CONTRACT,
            VesselDesign::CORRECTION_INPUT_KEY => $correction['version'],
            'source_stage_id' => (string) $source->id,
            'source_output_hash' => (string) $source->output_hash,
            'patch_hash' => $report['patch_hash'],
        ];

        $correctionMeta = array_replace($meta, [
            'correction' => [
                'version' => $correction['version'],
                'source_stage_id' => (string) $source->id,
                'source_revision' => (int) $source->planning_revision,
                'source_output_hash' => (string) $source->output_hash,
                'source_content_hash' => $report['stage']['content_hash'],
                'patch_hash' => $report['patch_hash'],
                'reason' => $correction['reason'],
                'changes' => $changes,
                'missing_decisions' => $missing,
                'validation_source' => $validationSource,
                'corrected_at' => now()->toIso8601String(),
                'actor' => $actor,
            ],
        ]);

        [$claimed, $token, $claimReason] = $this->stageStore->claimProjectStage(
            (string) $source->project_id, PlanningStageName::VESSEL_DESIGN, $input, false, $correctionMeta,
        );

        if ($token === null) {
            if ($claimReason !== 'already_succeeded') {
                $report['reason'] = $claimReason === 'claimed_by_other' ? 'screenplay_running' : $claimReason;

                return $report;
            }

            $report['reason'] = 'already_applied';
            $report['revision_stage_id'] = (string) $claimed->id;
            $report['revision'] = (int) $claimed->planning_revision;

            return $report;
        }

        try {
            $recorded = $this->stageStore->finishSucceeded(
                $claimed->id,
                $token,
                json_encode($corrected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $corrected,
                ['instruction_version' => $correction['version'], 'tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0],
            );
        } catch (\Throwable $e) {
            $this->stageStore->releaseClaim($claimed->id, 'Design correction could not be recorded');

            throw $e;
        }

        $report['applied'] = $recorded;
        $report['reason'] = $recorded ? 'applied' : 'screenplay_claim_lost';
        $report['revision_stage_id'] = (string) $claimed->id;
        $report['revision'] = (int) $claimed->planning_revision;

        return $report;
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    public static function recoveryPatch(string $stageId, string $raw, array $patch): array
    {
        if (($patch['source_stage_id'] ?? null) !== $stageId
            || ! is_string($patch['source_raw_sha256'] ?? null)
            || ! hash_equals(hash('sha256', $raw), $patch['source_raw_sha256'])) {
            return [null, 'recovery_source_changed'];
        }

        try {
            $design = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [null, $e->getMessage()];
        }

        $changes = $patch['changes'] ?? [];
        $extra = $patch['operations'] ?? [];

        if (! is_array($design) || ! is_array($changes) || ! is_array($extra) || ($changes === [] && $extra === [])) {
            return [null, 'recovery_patch_invalid'];
        }

        if ($extra !== [] && ($problem = self::operationsProblem($extra)) !== null) {
            return [null, $problem];
        }

        $rules = array_column($design[VesselDesign::CANONICAL_KEY]['must_preserve'] ?? [], 'id');
        $operations = [];

        foreach ($changes as $change) {
            $index = $change['index'] ?? null;
            $before = $change['before'] ?? null;
            $after = $change['after'] ?? null;

            if (! is_int($index) || ! is_array($before) || ! is_array($after)
                || ! array_is_list($before) || ! array_is_list($after) || $after === []
                || ($design[VesselDesign::CANONICAL_KEY]['must_not_introduce'][$index]['refs'] ?? null) !== $before) {
                return [null, 'recovery_before_mismatch'];
            }

            $expected = array_values(array_filter($before, static fn (mixed $ref): bool => ! in_array($ref, $rules, true)));

            if ($after !== $expected || $before === $after) {
                return [null, 'recovery_patch_must_only_remove_rule_refs'];
            }

            $operations[] = ['path' => VesselDesign::CANONICAL_KEY.".must_not_introduce.{$index}.refs", 'before' => $before, 'after' => $after];
        }

        [$patched, , $reason] = self::applyPatch($design, [...$operations, ...$extra]);

        return $patched === null ? [null, $extra === [] ? 'recovery_before_mismatch' : (string) $reason] : [$patched, null];
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  list<array{path: string, before: mixed, after: mixed}>  $operations
     * @return array{0: ?array<string, mixed>, 1: list<array{path: string, before: mixed, after: mixed}>, 2: ?string}
     */
    public static function applyPatch(array $output, array $operations): array
    {
        $changes = [];

        foreach ($operations as $operation) {
            $segments = explode('.', $operation['path']);
            $cursor = &$output;

            foreach ($segments as $position => $segment) {
                if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                    $addable = $position === count($segments) - 1 && is_array($cursor) && $cursor !== [] && ! array_is_list($cursor)
                        && self::addablePath($operation['path']) && array_key_exists('before', $operation) && $operation['before'] === null;

                    if (! $addable) {
                        unset($cursor);

                        return [null, [], "path_not_found: {$operation['path']}"];
                    }

                    $cursor[$segment] = null;
                }

                $cursor = &$cursor[$segment];
            }

            if ($cursor !== $operation['before']) {
                unset($cursor);

                return [null, [], "before_mismatch: {$operation['path']}"];
            }

            $cursor = $operation['after'];
            unset($cursor);
            $changes[] = ['path' => $operation['path'], 'before' => $operation['before'], 'after' => $operation['after']];
        }

        return [$output, $changes, null];
    }

    /**
     * @return array{stage: ?array<string, mixed>, geometry_model: ?string, result: string, violations: list<string>, notes: list<string>}
     */
    public function checkConsistency(string $stageId): array
    {
        $report = ['stage' => null, 'geometry_model' => null, 'result' => 'failed', 'violations' => [], 'notes' => []];
        $stage = VideoPlanningStage::query()
            ->whereKey($stageId)
            ->where('stage', PlanningStageName::VESSEL_DESIGN->value)
            ->whereIn('status', [VideoPlanningStageStatus::SUCCEEDED->value, VideoPlanningStageStatus::FAILED->value])
            ->first();

        if ($stage === null) {
            $report['notes'][] = 'stage_not_a_design';

            return $report;
        }

        $design = $stage->status === VideoPlanningStageStatus::FAILED->value
            ? json_decode((string) $stage->raw_response, true)
            : $stage->output_json;
        $report['stage'] = [
            'id' => (string) $stage->id,
            'project_id' => (string) $stage->project_id,
            'revision' => (int) $stage->planning_revision,
            'status' => (string) $stage->status,
        ];

        if (! is_array($design) || $design === []) {
            $report['notes'][] = 'design output is not readable';

            return $report;
        }

        $model = VesselDesign::geometryModel($design);
        $report['geometry_model'] = $model;
        $meta = PlanningStageStore::metadataOf($stage->input_json);
        $profile = $meta['profile'] ?? null;
        $snapshot = $meta[ScreenplayExpansionService::PROFILE_SNAPSHOT_KEY] ?? null;

        if (! is_array($profile) || ! is_array($snapshot)) {
            $report['result'] = 'needs_review';
            $report['notes'][] = 'the stage did not record its profile snapshot';

            return $report;
        }

        $source = self::correctionValidationSource($stage, $meta);

        if ($source === null) {
            $report['notes'][] = 'excluded context unknown: no recorded or earlier inspiration; checked without it';
        } elseif ($source['basis'] === 'created_before_source') {
            $report['notes'][] = 'excluded context from the latest inspiration created before the design, not a recorded snapshot';
        }

        $body = array_diff_key($design, array_flip(['schema_version', 'author_model', VesselDesign::UNRESOLVED_KEY]));
        $report['violations'] = (new ScreenplayValidator)->structural(
            $body, VesselDesign::validationProfile($profile, $snapshot), VesselDesign::CONTRACT, $source['excluded'] ?? [],
        );

        if ($model === VesselDesign::GEOMETRY_LEGACY) {
            $report['notes'][] = 'legacy design without typed geometry: profile and canonical consistency cannot be proven';
        }

        $gaps = VesselDesign::placementGaps($design);

        foreach ($gaps as $gap) {
            $report['notes'][] = $gap;
        }

        $report['result'] = match (true) {
            $report['violations'] !== [] || $model === VesselDesign::GEOMETRY_UNKNOWN => 'failed',
            $model === VesselDesign::GEOMETRY_LEGACY || $source === null || $gaps !== [] => 'needs_review',
            default => 'passed',
        };

        return $report;
    }

    /** @return array{0: bool, 1: string} */
    public function selectCorrection(string $stageId): array
    {
        $stage = VideoPlanningStage::query()
            ->whereKey($stageId)
            ->where('stage', PlanningStageName::VESSEL_DESIGN->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->first();

        if ($stage === null || ! self::isCorrection($stage)) {
            return [false, 'stage_not_a_correction'];
        }

        return DB::transaction(function () use ($stage): array {
            $project = VideoProject::query()->whereKey($stage->project_id)->lockForUpdate()->first();

            if ($project === null) {
                return [false, 'project_not_found'];
            }

            $newer = VideoPlanningStage::query()
                ->where('project_id', $stage->project_id)
                ->where('stage', PlanningStageName::VESSEL_DESIGN->value)
                ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
                ->where('planning_revision', '>', $stage->planning_revision)
                ->get()
                ->contains(static fn (VideoPlanningStage $later): bool => ! self::isCorrection($later)
                    && is_array($later->output_json)
                    && ($later->output_json['schema_version'] ?? null) === VesselDesign::CONTRACT);

            if ($newer) {
                return [false, 'newer_design_exists'];
            }

            $metadata = $project->metadata_json ?? [];

            if (($metadata[VesselDesign::SELECTION_KEY]['stage_id'] ?? null) === (string) $stage->id) {
                return [true, 'already_selected'];
            }

            $metadata[VesselDesign::SELECTION_KEY] = [
                'stage_id' => (string) $stage->id,
                'revision' => (int) $stage->planning_revision,
                'content_hash' => VesselDesign::contentHash((array) $stage->output_json),
                'selected_at' => now()->toIso8601String(),
            ];
            $project->metadata_json = $metadata;
            $project->save();

            return $this->currentStage((string) $project->id)?->id === $stage->id
                ? [true, 'selected']
                : throw new \LogicException('The selected design correction did not become the current design.');
        });
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{inspiration_stage_id: ?string, excluded: list<string>, basis: string}|null
     */
    private static function correctionValidationSource(VideoPlanningStage $source, array $meta): ?array
    {
        if (self::isCorrection($source)) {
            $inherited = $meta['correction']['validation_source'] ?? null;

            if (! is_array($inherited) || ! is_array($inherited['excluded'] ?? null)
                || ! array_is_list($inherited['excluded'])) {
                return null;
            }

            return [
                'inspiration_stage_id' => is_string($inherited['inspiration_stage_id'] ?? null) ? $inherited['inspiration_stage_id'] : null,
                'excluded' => array_values(array_map('strval', $inherited['excluded'])),
                'basis' => 'inherited',
            ];
        }

        $inspiration = VideoPlanningStage::query()
            ->where('project_id', $source->project_id)
            ->where('stage', PlanningStageName::INSPIRATION->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->where('created_at', '<=', $source->created_at)
            ->orderByDesc('created_at')
            ->first();

        if ($inspiration === null || ! is_array($inspiration->output_json)) {
            return null;
        }

        return [
            'inspiration_stage_id' => (string) $inspiration->id,
            'excluded' => self::excludedNames($inspiration->output_json),
            'basis' => 'created_before_source',
        ];
    }

    /** @param array<string, mixed> $correction */
    private static function correctionShapeProblem(array $correction): ?string
    {
        foreach (['version', 'reason', 'source_stage_id', 'source_output_hash', 'source_content_hash'] as $key) {
            if (! is_string($correction[$key] ?? null) || trim($correction[$key]) === '') {
                return "{$key}: required text";
            }
        }

        if (strlen($correction['version']) > self::CORRECTION_VERSION_MAX) {
            return 'version: at most '.self::CORRECTION_VERSION_MAX.' characters';
        }

        $operationProblem = self::operationsProblem($correction['operations'] ?? null);

        if ($operationProblem !== null) {
            return $operationProblem;
        }

        $missing = $correction['missing_decisions'] ?? null;

        if (! is_array($missing) || ! array_is_list($missing)) {
            return 'missing_decisions: required list';
        }

        foreach ($missing as $index => $decision) {
            foreach (['id', 'question'] as $key) {
                if (! is_array($decision) || ! is_string($decision[$key] ?? null) || trim($decision[$key]) === '') {
                    return "missing_decisions[{$index}].{$key}: required text";
                }
            }
        }

        return null;
    }

    private static function operationsProblem(mixed $operations): ?string
    {
        if (! is_array($operations) || ! array_is_list($operations) || $operations === []) {
            return 'operations: required non-empty list';
        }

        foreach ($operations as $index => $operation) {
            foreach (['path', 'before', 'after'] as $key) {
                $added = $key === 'before' && is_array($operation) && array_key_exists('before', $operation) && $operation['before'] === null
                    && is_string($operation['path'] ?? null) && self::addablePath($operation['path']);

                if (! $added && (! is_array($operation) || ! is_string($operation[$key] ?? null) || $operation[$key] === '')) {
                    return "operations[{$index}].{$key}: required text";
                }
            }
        }

        return null;
    }

    private static function addablePath(string $path): bool
    {
        foreach (self::ADDABLE_PATHS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
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
        $failed = $latest?->status === VideoPlanningStageStatus::FAILED->value ? $latest : null;
        $decoded = $failed === null ? null : json_decode((string) $failed->raw_response, true);

        return [
            'repair' => self::repairRecord($stage),
            'failed_repair' => self::repairRecord($failed),
            'repair_enabled' => (bool) config('video.screenplay.design.repair.enabled'),
            'errors' => $failed === null ? [] : self::failureLines((string) $failed->error_message),
            'failed_design' => is_array($decoded) && is_array($decoded[VesselDesign::VESSEL_KEY] ?? null) ? $decoded : null,
            'failed_at' => $failed?->finished_at?->format('d/m/Y H:i'),
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
            'anchor_selection' => $this->anchorSelection(VideoProject::query()->find($projectId)),
            'reference_selection' => $this->referenceSelection(VideoProject::query()->find($projectId)),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function repairRecord(?VideoPlanningStage $stage): ?array
    {
        $stored = $stage === null ? null : (PlanningStageStore::metadataOf($stage->input_json)[self::REPAIR_KEY] ?? null);
        $record = is_string($stored) ? json_decode($stored, true) : null;

        return is_array($record) ? $record : null;
    }

    /** @return list<string> */
    public static function failureLines(string $message): array
    {
        $body = preg_replace('/^Vessel design failed validation:\s*/u', '', $message, 1, $prefixed);

        if ($prefixed !== 1) {
            return [$message];
        }

        return array_values(array_filter(array_map('trim', preg_split('/;\s+(?=[a-z_]+[.\[:])/u', (string) $body) ?: []), static fn (string $line): bool => $line !== ''));
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
    public function anchorExtract(VideoPlanningStage $stage): array
    {
        $snapshot = PlanningStageStore::metadataOf($stage->input_json)[ScreenplayExpansionService::PROFILE_SNAPSHOT_KEY] ?? null;
        $policy = is_array($snapshot) && is_array($snapshot['prompt_compilation_policy'] ?? null)
            ? $snapshot['prompt_compilation_policy']
            : null;

        return VesselDesign::anchorDesign((array) $stage->output_json, $policy);
    }

    /** @return array<string, mixed> */
    public function anchorSource(VideoPlanningStage $stage): array
    {
        $view = ReferenceView::DESIGN_ANCHOR_VIEW;

        return [
            'source_kind' => VesselDesign::SOURCE_KIND,
            'source_version' => VesselDesign::ANCHOR_SOURCE_VERSION,
            'requested_anchor_view' => ReferenceView::anchorViewRequirements($view),
            VesselDesign::EXTERIOR_DESIGN_KEY => VesselDesign::exteriorDesign((array) $stage->output_json, $this->anchorExtract($stage), $view),
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

        $blockers = VesselDesign::anchorBlockers((array) $current->output_json);

        return $blockers === [] ? [$current, 'ok'] : [null, $blockers[0]];
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
            $metadata['design_anchor_selection'] = [
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
    public function anchorSelection(?VideoProject $project): ?array
    {
        $selection = $project?->metadata_json['design_anchor_selection']
            ?? $project?->metadata_json[VesselDesign::LOCK_KEY] ?? null;

        if (! is_array($selection) || $project === null) {
            return null;
        }

        // Legacy approvals remain usable only for their exact design revision.
        return ($selection['design_stage_id'] ?? null) === $this->currentStage((string) $project->id)?->id
            ? $selection : null;
    }

    /**
     * @param  array<string, mixed>  $anchorLock
     * @return list<array{view: string, image_id: string, artifact_id: string, artifact_sha256: string}>
     */
    public function approvedReferences(string $projectId, array $anchorLock): array
    {
        $references = [];
        $images = VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::REFERENCE_TYPE)
            ->where('status', \App\Enums\DesignImageStatus::APPROVED->value)
            ->whereNotNull('selected_artifact_id')
            ->with('artifact')
            ->orderBy('approved_at')
            ->get();

        foreach ($images as $image) {
            $spec = is_array($image->prompt_spec_json) ? $image->prompt_spec_json : [];
            $artifact = $image->artifact;

            if (($spec['source_artifact_id'] ?? null) !== ($anchorLock['artifact_id'] ?? null)
                || ($spec['source_artifact_sha256'] ?? null) !== ($anchorLock['artifact_sha256'] ?? null)
                || $artifact === null || ! is_string($artifact->sha256) || $artifact->sha256 === '') {
                continue;
            }

            $references[] = [
                'view' => (string) ($spec['view_key'] ?? ''),
                'image_id' => (string) $image->id,
                'artifact_id' => (string) $artifact->id,
                'artifact_sha256' => (string) $artifact->sha256,
            ];
        }

        return $references;
    }

    /** @return array{0: bool, 1: string} */
    public function lockReferences(string $projectId, ?string $adminId): array
    {
        return DB::transaction(function () use ($projectId, $adminId): array {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->first();

            if ($project === null) {
                return [false, 'project_not_found'];
            }

            if (! VesselDesign::isDesignFirst($project)) {
                return [false, 'workflow_not_design_first'];
            }

            $anchor = $this->anchorSelection($project);

            if ($anchor === null) {
                return [false, 'design_anchor_not_approved'];
            }

            $references = $this->approvedReferences($projectId, $anchor);

            if ($references === []) {
                return [false, 'design_references_none_approved'];
            }

            $metadata = $project->metadata_json ?? [];
            $metadata[self::REFERENCE_SELECTION_KEY] = [
                'design_stage_id' => (string) $anchor['design_stage_id'],
                'design_content_hash' => (string) $anchor['design_content_hash'],
                'anchor_image_id' => (string) $anchor['image_id'],
                'anchor_artifact_id' => (string) $anchor['artifact_id'],
                'anchor_artifact_sha256' => (string) $anchor['artifact_sha256'],
                'references' => $references,
                'approved_by' => $adminId,
                'approved_at' => now()->toIso8601String(),
            ];
            $project->metadata_json = $metadata;
            $project->save();

            return [true, 'design_references_locked'];
        });
    }

    /** @return array<string, mixed>|null */
    public function referenceSelection(?VideoProject $project): ?array
    {
        $lock = $project?->metadata_json[self::REFERENCE_SELECTION_KEY] ?? null;
        $anchor = $this->anchorSelection($project);

        if (! is_array($lock) || $anchor === null || ! is_array($lock['references'] ?? null) || $lock['references'] === []) {
            return null;
        }

        foreach (['design_stage_id' => 'design_stage_id', 'design_content_hash' => 'design_content_hash', 'anchor_image_id' => 'image_id',
            'anchor_artifact_id' => 'artifact_id', 'anchor_artifact_sha256' => 'artifact_sha256'] as $own => $anchored) {
            if (($lock[$own] ?? null) !== ($anchor[$anchored] ?? null)) {
                return null;
            }
        }

        foreach ($lock['references'] as $reference) {
            $approved = VideoDesignImage::query()
                ->whereKey((string) ($reference['image_id'] ?? ''))
                ->where('project_id', (string) $project->id)
                ->where('status', \App\Enums\DesignImageStatus::APPROVED->value)
                ->where('selected_artifact_id', (string) ($reference['artifact_id'] ?? ''))
                ->exists();
            $kept = ! $approved ? null : VideoArtifact::query()
                ->whereKey((string) ($reference['artifact_id'] ?? ''))
                ->where('design_image_id', (string) ($reference['image_id'] ?? ''))
                ->where('project_id', (string) $project->id)
                ->value('sha256');

            if (! is_string($kept) || ! hash_equals((string) ($reference['artifact_sha256'] ?? ''), $kept)) {
                return null;
            }
        }

        return $lock;
    }

    /**
     * @param  array<string, mixed>  $lock
     * @return array{0: ?VideoPlanningStage, 1: ?VideoDesignImage, 2: string}
     */
    public function selectedSource(string $projectId, array $lock): array
    {
        $stage = $this->stageById($projectId, is_string($lock['design_stage_id'] ?? null) ? $lock['design_stage_id'] : null);

        if ($stage === null) {
            return [null, null, 'design_anchor_not_approved'];
        }

        if (! hash_equals(VesselDesign::contentHash((array) $stage->output_json), (string) ($lock['design_content_hash'] ?? ''))) {
            return [null, null, 'design_changed_since_anchor'];
        }

        $image = $this->anchorBySource($projectId, $lock);

        return $image === null ? [null, null, 'design_anchor_not_approved'] : [$stage, $image, 'ok'];
    }

    /** @param array<string, mixed> $lock */
    public function anchorBySource(string $projectId, array $lock): ?VideoDesignImage
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

        if (VesselDesign::elevatorsForbidden($designProfile)
            && isset($schema['properties'][$canonical]['properties']['routes']['items']['properties']['kind']['enum'])) {
            $kinds = &$schema['properties'][$canonical]['properties']['routes']['items']['properties']['kind']['enum'];
            $kinds = array_values(array_diff($kinds, ['lift']));
            unset($kinds);
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
