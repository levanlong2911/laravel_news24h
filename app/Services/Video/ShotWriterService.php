<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\AiPhase;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Admin\ClaudeWriterService;
use App\Video\Llm\LlmClient;
use App\Video\Moto\MotoContract;
use App\Video\Script\ScriptEvaluation;
use App\Video\Superyacht\Spec\PhaseResolver;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ShotWriterService implements QueuedStageService
{
    /** @var list<string> the timing fields of a shot a hand revision may replace */
    private const REVISABLE = ['used_start_ms', 'used_duration_ms', 'render_duration_ms', 'coverage'];

    /** @var list<string> the timing fields of a coverage part a hand revision may replace */
    private const REVISABLE_COVERAGE = ['used_duration_ms', 'result_at_ms'];

    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly ShotSourceService $sources,
        private readonly LlmClient $llm,
    ) {}

    /** @return array{0: ?VideoPlanningStage, 1: string} */
    public function run(string $projectId, bool $regenerate, string $operationId, ?array $requester = null): array
    {
        return (new SpecStageRunner($this->stageStore))->submit(
            'shots',
            $projectId,
            PlanningStageName::SHOTS,
            fn (): array => $this->prepare($projectId),
            $regenerate,
            $operationId,
            $this->llm,
            fn (array $run, array $evaluation): array => $this->output($run, $evaluation),
            'lập kế hoạch shot',
            'shots',
            $requester,
        );
    }

    /** @return array{0: ?VideoPlanningStage, 1: string} what the queued run became when a worker took it */
    public function execute(string $stageId, string $token): array
    {
        $projectId = VideoPlanningStage::query()->whereKey($stageId)->value('project_id');

        if (! is_string($projectId)) {
            return [null, 'Lượt lập kế hoạch shot không còn — bỏ qua.'];
        }

        return (new SpecStageRunner($this->stageStore))->execute(
            $stageId,
            $token,
            fn (): array => $this->prepare($projectId),
            $this->llm,
            fn (array $run, array $evaluation): array => $this->output($run, $evaluation),
            'lập kế hoạch shot',
            'shots',
        );
    }

    /** @return array{0: bool, 1: string} reason pending when a run still waiting in the queue was cancelled */
    public function reset(string $projectId): array
    {
        return $this->stageStore->resetLatest($projectId, PlanningStageName::SHOTS, 'Chưa có lượt lập kế hoạch shot nào.');
    }

    /**
     * @param  array{reason?: mixed, shots?: mixed}  $patch  why the shot list is revised and, per shot id, the used_start_ms, used_duration_ms, render_duration_ms or coverage timing (one entry per coverage part, with used_duration_ms or result_at_ms) that replace its own
     * @param  ?string  $expected  the patch hash of the preview the reviewer approved; required to write
     * @return array{passed: bool, reason: string, source: ?array<string, mixed>, prompt: ?string, changes: list<array{path: string, purpose: string, first_frame: string, action: string, result: string, before: string, after: string}>, check: ?array<string, mixed>, content_hash: ?string, patch_hash: ?string, revision_stage_id: ?string, revision: ?int} the revised moto shot list checked against the current contract like a run of the model, only for a run written from the sources the project uses now and only in its timing; previewed or, with $write and the approved patch hash, stored as a new run that names the run it revises, under the project lock and only while the sources are still those it was checked against; the revised run is not approved and the run it revises stays as it was
     */
    public function revise(string $projectId, string $stageId, array $patch, bool $write, ?string $expected, ?string $actor): array
    {
        $report = ['passed' => false, 'reason' => '', 'source' => null, 'prompt' => null, 'changes' => [], 'check' => null, 'content_hash' => null, 'patch_hash' => null, 'revision_stage_id' => null, 'revision' => null];
        $stage = $this->sources->shotStage($projectId, $stageId);

        if ($stage === null) {
            return ['reason' => 'Bản kế hoạch shot này không thuộc dự án hoặc chưa chạy thành công.'] + $report;
        }

        [$evaluation, $problem] = $this->sources->inspect($projectId, $stage);

        if ($problem !== null) {
            return ['reason' => $problem] + $report;
        }

        $document = (array) $evaluation['document'];

        if (($document['format'] ?? null) !== MotoContract::SHOTS_FORMAT) {
            return ['reason' => 'Sửa tay chỉ áp dụng cho kế hoạch shot '.MotoContract::SHOTS_FORMAT.'.'] + $report;
        }

        $shots = $patch['shots'] ?? null;
        $known = array_column((array) $document['data']['shots'], null, 'id');

        if (! is_string($patch['reason'] ?? null) || trim($patch['reason']) === '' || ! is_array($shots) || $shots === [] || array_is_list($shots)) {
            return ['reason' => 'Bản sửa cần reason và shots theo mã shot.'] + $report;
        }

        foreach ($shots as $id => $fields) {
            $refused = "Bản sửa {$id}: chỉ sửa ".implode(', ', self::REVISABLE).' của một shot có trong kế hoạch; coverage giữ đúng số phần, mỗi phần chỉ sửa '.implode(', ', self::REVISABLE_COVERAGE).'.';

            if (! isset($known[$id]) || ! is_array($fields) || $fields === [] || array_is_list($fields) || array_diff(array_keys($fields), self::REVISABLE) !== []) {
                return ['reason' => $refused] + $report;
            }

            $coverage = $fields['coverage'] ?? [];

            if (! is_array($coverage) || ! array_is_list($coverage) || (isset($fields['coverage']) && count($coverage) !== count((array) $known[$id]['coverage']))) {
                return ['reason' => $refused] + $report;
            }

            foreach ($coverage as $part) {
                if (! is_array($part) || ($part !== [] && array_is_list($part)) || array_diff(array_keys($part), self::REVISABLE_COVERAGE) !== []) {
                    return ['reason' => $refused] + $report;
                }
            }
        }

        $selection = $this->sources->selection($projectId);
        $report['source'] = [
            'stage_id' => (string) $stage->id,
            'revision' => (int) $stage->planning_revision,
            'content_hash' => (string) $evaluation['content_hash'],
            'approved' => $selection !== null && $selection['stage_id'] === (string) $stage->id,
        ];

        try {
            $run = $this->prepare($projectId);
        } catch (\Throwable $e) {
            return ['reason' => 'Chưa dựng được hợp đồng và nguồn hiện hành: '.$e->getMessage()] + $report;
        }

        $pinned = $this->sources->source($stage);
        $current = $run['metadata']['source'];

        if (CanonicalJson::hash($pinned) !== CanonicalJson::hash($current)) {
            return ['reason' => "Bản rev {$stage->planning_revision} viết từ nguồn (scene, kịch bản, bối cảnh, thiết kế) khác nguồn dự án đang dùng — không chuyển kế hoạch shot sang nguồn khác bằng bản sửa tay; chạy lại để có bản mới."] + $report;
        }

        $report['prompt'] = 'Prompt '.$run['phase']->phase_code.' revision '.$run['phase']->revision;
        $revised = $document;
        $revised['prompt_version'] = $run['prompt_version'];
        $encode = static fn (mixed $value): string => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($revised['data']['shots'] as &$shot) {
            $describe = static fn (string $path, mixed $before, mixed $after): array => [
                'path' => $path,
                'purpose' => (string) $shot['purpose'],
                'first_frame' => (string) $shot['first_frame'],
                'action' => (string) $shot['action'],
                'result' => (string) $shot['result'],
                'before' => $encode($before),
                'after' => $encode($after),
            ];

            foreach ((array) ($shots[$shot['id']] ?? []) as $key => $value) {
                if ($key !== 'coverage') {
                    if ($shot[$key] !== $value) {
                        $report['changes'][] = $describe("{$shot['id']}.{$key}", $shot[$key], $value);
                        $shot[$key] = $value;
                    }

                    continue;
                }

                foreach ($value as $index => $part) {
                    foreach ($part as $field => $number) {
                        if ($shot['coverage'][$index][$field] !== $number) {
                            $report['changes'][] = $describe("{$shot['id']}.coverage[{$index}].{$field}", $shot['coverage'][$index][$field], $number);
                            $shot['coverage'][$index][$field] = $number;
                        }
                    }
                }
            }
        }

        unset($shot);

        if ($report['changes'] === []) {
            return ['reason' => 'Bản sửa không đổi trường nào của kế hoạch shot.'] + $report;
        }

        $raw = (string) json_encode($revised, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $result = ScriptEvaluation::of($raw, $run['metadata']['manifest'], $run['metadata']['context'], $current, $run['adapter']);
        $report['check'] = $result['check'];
        $report['content_hash'] = $result['content_hash'];
        $report['patch_hash'] = CanonicalJson::hash(['of' => $report['source'], 'patch' => $patch, 'contract' => $result['contract'], 'source' => $current, 'content_hash' => $result['content_hash']]);
        $report['passed'] = $result['content_hash'] !== null && ($result['check']['verdict'] ?? null) === 'PASS' && ($result['check']['status'] ?? null) === 'ok';

        if (! $report['passed']) {
            return ['reason' => 'Bản sửa chưa qua kiểm của hợp đồng hiện hành.'] + $report;
        }

        if (! $write) {
            return ['reason' => 'preview'] + $report;
        }

        if ($expected === null || ! hash_equals($report['patch_hash'], $expected)) {
            return ['reason' => 'Mã xem trước không khớp bản sửa này — xem trước lại rồi duyệt đúng mã.'] + $report;
        }

        $metadata = $run['metadata'] + ['revision' => [
            'of' => $report['source'],
            'reason' => trim($patch['reason']),
            'patch' => $patch,
            'patch_hash' => $report['patch_hash'],
            'changes' => $report['changes'],
            'actor' => $actor,
            'revised_at' => now()->toIso8601String(),
        ]];
        [$claimed, $token, $claimReason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::SHOTS, $run['input'] + ['revision_of' => (string) $stage->id, 'patch_hash' => $report['patch_hash']], false, $metadata);

        if ($token === null) {
            return $claimReason === 'already_succeeded'
                ? ['reason' => 'already_applied', 'revision_stage_id' => (string) $claimed->id, 'revision' => (int) $claimed->planning_revision] + $report
                : ['reason' => $claimReason === 'claimed_by_other' ? 'Đang có một lượt lập kế hoạch shot chạy cho dự án này — đợi xong rồi thử lại.' : $claimReason] + $report;
        }

        try {
            $recorded = DB::transaction(function () use ($projectId, $claimed, $token, $raw, $run, $result, $current): ?bool {
                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();

                if ($run['adapter']->sourceProblem($projectId, $current) !== null) {
                    return null;
                }

                return $this->stageStore->finishSucceeded((string) $claimed->id, $token, $raw, $this->output($run, $result), ['instruction_version' => $run['prompt_version'], 'tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0]);
            });
        } catch (\Throwable $e) {
            $this->stageStore->releaseClaim((string) $claimed->id, 'Bản sửa kế hoạch shot không lưu được');

            throw $e;
        }

        if ($recorded === null) {
            $this->stageStore->releaseClaim((string) $claimed->id, 'Nguồn của kế hoạch shot đổi trong lúc lưu bản sửa tay');

            return ['reason' => 'Bản chia scene đã duyệt hoặc nguồn của nó đổi trong lúc lưu bản sửa — không lưu; xem trước lại từ nguồn hiện hành.'] + $report;
        }

        return ['reason' => $recorded ? 'applied' : 'Lượt lưu bị giành mất — tải lại rồi thử lại.', 'revision_stage_id' => (string) $claimed->id, 'revision' => (int) $claimed->planning_revision] + $report;
    }

    /** @return ?array{revision: int, reason: string, changes: list<array<string, string>>} the run a hand revision revises, why and what it changed; null for a run of the model */
    private static function revisionOf(VideoPlanningStage $stage): ?array
    {
        $revision = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null)['revision'] ?? null;

        return is_array($revision) ? ['revision' => (int) $revision['of']['revision'], 'reason' => (string) $revision['reason'], 'changes' => array_values((array) $revision['changes'])] : null;
    }

    /**
     * @param  ?string  $viewStageId  a run picked on the page
     * @return array<string, mixed>
     */
    public function panel(string $projectId, ?string $viewStageId = null): array
    {
        $flow = $this->sources->workflowFor($projectId);
        $run = null;
        $runError = null;

        try {
            $run = $this->prepare($projectId);
        } catch (\Throwable $e) {
            $runError = $e->getMessage();
        }

        $stages = VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $projectId)->where('stage', PlanningStageName::SHOTS->value)
            ->whereNull('input_json->orphan_of_stage_id')->orderByDesc('planning_revision')->get();
        $latest = $stages->first();
        $served = $run === null ? null : $this->stageStore->latestSucceededForInput($projectId, PlanningStageName::SHOTS, $run['input']);
        $selection = $this->sources->selection($projectId);
        $current = $run['input']['source'] ?? null;
        $stale = static fn (VideoPlanningStage $stage): ?bool => $current === null ? null : ($stage->input_json['source'] ?? null) != $current;
        $succeeded = $stages->where('status', VideoPlanningStageStatus::SUCCEEDED->value);
        $shown = ($viewStageId === null ? null : $succeeded->firstWhere('id', $viewStageId))
            ?? ($selection === null ? null : $succeeded->firstWhere('id', $selection['stage_id']))
            ?? $succeeded->first();
        [$evaluation, $integrity] = $shown === null ? [[], null] : $this->sources->inspect($projectId, $shown);
        $check = $integrity === null && is_array($evaluation['check'] ?? null) ? $evaluation['check'] : null;
        $document = $integrity === null && is_array($evaluation['document'] ?? null) ? $evaluation['document'] : null;
        $plan = $integrity === null && is_array($evaluation['derived'] ?? null) ? $evaluation['derived'] : null;
        $derivedProblem = $integrity === null ? ($evaluation['derived_problem'] ?? null) : null;
        $resources = $plan === null ? [] : $flow['adapter']->resources($projectId, $plan);
        $sourceProblem = $shown === null ? null : $flow['adapter']->sourceProblem($projectId, $this->sources->source($shown));
        $approved = $shown !== null && $selection !== null && $selection['stage_id'] === (string) $shown->id;
        $approvedError = null;

        if ($selection !== null) {
            try {
                $this->sources->approvedSource($projectId);
            } catch (RuntimeException $e) {
                $approvedError = $e->getMessage();
            }
        }

        $settings = $run['settings'] ?? $flow['adapter']->settings();

        return [
            'run_error' => $runError,
            'can_run' => $run !== null && $served === null,
            'can_rewrite' => $run !== null && $served !== null,
            'prompt' => $run === null ? null : 'Prompt '.$run['phase']->phase_code.' revision '.$run['phase']->revision,
            'model' => $settings['model'],
            'effort' => $settings['effort'],
            'max_tokens' => $settings['max_tokens'],
            'timeout_minutes' => intdiv((int) $settings['timeout_seconds'], 60),
            'operation_id' => (string) Str::uuid(),
            'running' => $latest !== null && $latest->status === VideoPlanningStageStatus::RUNNING->value && $latest->lease_expires_at?->isFuture() === true,
            'pending' => $latest !== null && $latest->status === VideoPlanningStageStatus::PENDING->value && $latest->claim_token !== null,
            'task' => PlanningStageStore::taskOf($latest),
            'stuck' => $latest !== null && $latest->status === VideoPlanningStageStatus::RUNNING->value && $latest->lease_expires_at?->isFuture() !== true,
            'error' => $latest !== null && $latest->status === VideoPlanningStageStatus::FAILED->value ? $latest->error_message : null,
            'stream' => PlanningStageStore::streamOf($latest),
            'history' => $stages->map(static fn (VideoPlanningStage $stage): array => [
                'id' => (string) $stage->id,
                'revision' => (int) $stage->planning_revision,
                'status' => $stage->status,
                // The run on screen was checked again just now; the others show what was stored when they ran.
                'verdict' => $check !== null && (string) $stage->id === (string) $shown?->id ? $check['verdict'] : ($stage->output_json['check']['verdict'] ?? null),
                'check_version' => $check !== null && (string) $stage->id === (string) $shown?->id ? $check['version'] : null,
                'model_status' => $stage->output_json['check']['status'] ?? null,
                'approved' => $selection !== null && $selection['stage_id'] === (string) $stage->id,
                'stale' => $stage->status === VideoPlanningStageStatus::SUCCEEDED->value ? $stale($stage) : null,
                'revision_of' => PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null)['revision']['of']['revision'] ?? null,
                'cost_usd' => $stage->cost_usd === null ? null : (float) $stage->cost_usd,
            ])->values()->all(),
            'selection' => $selection,
            'selection_version' => (int) ($selection['version'] ?? 0),
            'approved_error' => $approvedError,
            'cost_note' => 'Chưa ước tính được chi phí render: registry chưa có giá cho model clip và keyframe Moto chưa chọn model ảnh.',
            'shown' => $shown === null ? null : [
                'id' => (string) $shown->id,
                'revision' => (int) $shown->planning_revision,
                'revised' => self::revisionOf($shown),
                'integrity_error' => $integrity,
                'content_hash' => $integrity === null ? ($evaluation['content_hash'] ?? null) : null,
                'check' => $check,
                'stored_check' => array_intersect_key((array) ($shown->output_json['check'] ?? []), array_flip(['verdict', 'version'])),
                'flags' => array_values(array_filter(is_array($document['flags'] ?? null) ? $document['flags'] : [], 'is_string')),
                'plan' => $plan,
                'resources' => $resources,
                'readiness' => array_count_values(array_column($resources, 'readiness')),
                'source_problem' => $sourceProblem,
                'derived_problem' => $derivedProblem,
                'approved' => $approved,
                'approvable' => ! $approved && $check !== null && $check['verdict'] === 'PASS' && $check['status'] === 'ok' && $sourceProblem === null && $derivedProblem === null,
                'tokens' => ['in' => (int) $shown->tokens_in, 'out' => (int) $shown->tokens_out],
                'cost_usd' => $shown->cost_usd === null ? null : (float) $shown->cost_usd,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function prepare(string $projectId): array
    {
        $flow = $this->sources->workflowFor($projectId);
        $adapter = $flow['adapter'];
        $bundle = PhaseResolver::fromConfig((array) config('video.superyacht'))->forProject($projectId, $flow['action']);

        if (! $bundle->ready()) {
            throw new RuntimeException("Hợp đồng {$flow['action']} chưa sẵn sàng: ".implode('; ', $bundle->mismatches()).' — chạy video:phase-release cho chủ đề này trước.');
        }

        $manifest = $bundle->manifest();
        $phase = AiPhase::query()->findOrFail($manifest['phase']['id']);

        if (! str_contains((string) $phase->prompt_content, $adapter->format())) {
            throw new RuntimeException("Prompt {$phase->phase_code} revision {$phase->revision} chưa yêu cầu định dạng {$adapter->format()} — cần publish revision tương thích trước khi lập kế hoạch shot.");
        }

        if (! str_contains((string) $phase->prompt_content, "Input format: `{$adapter->inputsVersion()}`")) {
            throw new RuntimeException("Prompt {$phase->phase_code} revision {$phase->revision} không mô tả đầu vào {$adapter->inputsVersion()} mà code đang dựng — cần phát hành revision prompt tương thích trước khi lập kế hoạch shot, không gọi model.");
        }

        $settings = $adapter->settings();

        if (! ClaudeWriterService::supports($settings['model'])) {
            throw new RuntimeException('Model lập kế hoạch shot '.$settings['model'].' không có trong ClaudeWriterService::MODEL_CATALOG.');
        }

        $project = VideoProject::query()->with('article.category')->findOrFail($projectId);
        $inputs = $adapter->inputs($project, (string) $phase->prompt_content);
        $promptVersion = "{$flow['workflow']}/{$phase->phase_code}@rev{$phase->revision}";
        $payload = (string) json_encode(['project_id' => $projectId, 'prompt_version' => $promptVersion] + $inputs['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $context = ['project_id' => $projectId, 'prompt_version' => $promptVersion] + $inputs['context'];

        if (($problem = $adapter->contextProblem($context, ScriptEvaluation::contract($manifest))) !== null) {
            throw new RuntimeException("Hợp đồng {$flow['action']} không khớp code lập shot: {$problem} — cần phát hành gói Phase 7 tương thích trước, không gọi model.");
        }

        return [
            'adapter' => $adapter,
            'phase' => $phase,
            'system' => $adapter->systemText(array_column($bundle->parts(), 'content'), $bundle->outputSchemas()),
            'payload' => $payload,
            'prompt_version' => $promptVersion,
            'settings' => $settings,
            'input' => [
                'execution' => $settings['execution'],
                'workflow' => $flow['workflow'],
                'category_id' => $flow['category_id'],
                'bundle_hash' => $bundle->bundleHash(),
                'phase_id' => (string) $phase->id,
                'phase_revision' => (int) $phase->revision,
                'prompt_sha256' => (string) $phase->content_sha256,
                'source' => $inputs['source'],
                'payload_sha256' => hash('sha256', $payload),
                'model' => $settings['model'],
                'effort' => $settings['effort'],
                'max_tokens' => $settings['max_tokens'],
                'check_version' => $adapter->checkVersion(),
                'planner_version' => $adapter->plannerVersion(),
                'inputs_version' => $adapter->inputsVersion(),
            ],
            'metadata' => [
                'manifest' => $manifest,
                'source' => $inputs['source'],
                'context' => $context,
                'payload' => $payload,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $run
     * @param  array<string, mixed>  $evaluation
     * @return array<string, mixed>
     */
    private function output(array $run, array $evaluation): array
    {
        return [
            'execution' => $run['input']['execution'],
            'format' => $run['adapter']->format(),
            'contract' => $evaluation['contract'],
            'prompt' => array_intersect_key($run['input'], array_flip(['category_id', 'workflow', 'phase_id', 'phase_revision', 'prompt_sha256'])),
            'source' => $run['metadata']['source'],
            'json_parse_error' => $evaluation['parse_error'],
            'document' => $evaluation['document'],
            'derived' => $evaluation['derived'],
            'check' => $evaluation['check'],
            'content_hash' => $evaluation['content_hash'],
        ];
    }
}
