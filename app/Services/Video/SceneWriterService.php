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
use App\Video\Script\ScriptEvaluation;
use App\Video\Superyacht\Spec\PhaseResolver;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

final class SceneWriterService implements QueuedStageService
{
    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly SceneSourceService $sources,
        private readonly LlmClient $llm,
    ) {}

    /** @return array{0: ?VideoPlanningStage, 1: string} */
    public function run(string $projectId, bool $regenerate, string $operationId, ?array $requester = null): array
    {
        return $this->approvedWhenPassed($projectId, (new SpecStageRunner($this->stageStore))->submit(
            'scenes',
            $projectId,
            PlanningStageName::SCENES,
            fn (): array => $this->prepare($projectId),
            $regenerate,
            $operationId,
            $this->llm,
            fn (array $run, array $evaluation): array => $this->output($run, $evaluation),
            'chia scene',
            'scenes',
            $requester,
        ));
    }

    /**
     * @param  array{0: ?VideoPlanningStage, 1: string}  $result  what the run became; a failed approval leaves it as it is
     * @return array{0: ?VideoPlanningStage, 1: string}
     */
    private function approvedWhenPassed(string $projectId, array $result): array
    {
        if ($result[0] !== null) {
            try {
                $this->sources->approvePassed($projectId, (string) $result[0]->id);
            } catch (\Throwable $e) {
                Log::error('scenes: could not record the rule-pass approval', ['stage_id' => $result[0]->id, 'exception' => $e]);
            }
        }

        return $result;
    }

    /** @return array{0: ?VideoPlanningStage, 1: string} what the queued run became when a worker took it */
    public function execute(string $stageId, string $token): array
    {
        $projectId = VideoPlanningStage::query()->whereKey($stageId)->value('project_id');

        if (! is_string($projectId)) {
            return [null, 'Lượt chia scene không còn — bỏ qua.'];
        }

        return $this->approvedWhenPassed($projectId, (new SpecStageRunner($this->stageStore))->execute(
            $stageId,
            $token,
            fn (): array => $this->prepare($projectId),
            $this->llm,
            fn (array $run, array $evaluation): array => $this->output($run, $evaluation),
            'chia scene',
            'scenes',
        ));
    }

    /** @return array{0: bool, 1: string} reason pending when a run still waiting in the queue was cancelled */
    public function reset(string $projectId): array
    {
        return $this->stageStore->resetLatest($projectId, PlanningStageName::SCENES, 'Chưa có lượt chia scene nào.');
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

        $stages = VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $projectId)->where('stage', PlanningStageName::SCENES->value)
            ->whereNull('input_json->orphan_of_stage_id')->orderByDesc('planning_revision')->get();
        $latest = $stages->first();
        $served = $run === null ? null : $this->stageStore->latestSucceededForInput($projectId, PlanningStageName::SCENES, $run['input']);
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
            ])->values()->all(),
            'selection' => $selection,
            'approved_error' => $approvedError,
            'shot_plan_problem' => $this->sources->shotPlanProblem($projectId),
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
                'source_problem' => $sourceProblem,
                'approved' => $approved,
                'tokens' => ['in' => (int) $shown->tokens_in, 'out' => (int) $shown->tokens_out],
                'cost_usd' => $shown->cost_usd === null ? null : (float) $shown->cost_usd,
            ],
        ];
    }

    /**
     * @param  array{reason?: mixed, scenes?: mixed}  $patch  why the scene plan is revised and, per scene id, the work, continuity_start or continuity_end that replace its own
     * @param  ?string  $expected  the patch hash of the preview the reviewer approved; required to write
     * @return array{passed: bool, reason: string, source: ?array<string, mixed>, prompt: ?string, changes: list<array{path: string, before: string, after: string}>, check: ?array<string, mixed>, content_hash: ?string, patch_hash: ?string, revision_stage_id: ?string, revision: ?int} the revised scene plan checked against the current contract and sources like a run of the model, previewed or, with $write and the approved patch hash, stored as a new run that names the run it revises; the revised run is not approved and the run it revises stays as it was
     */
    public function revise(string $projectId, string $stageId, array $patch, bool $write, ?string $expected, ?string $actor): array
    {
        $report = ['passed' => false, 'reason' => '', 'source' => null, 'prompt' => null, 'changes' => [], 'check' => null, 'content_hash' => null, 'patch_hash' => null, 'revision_stage_id' => null, 'revision' => null];
        $stage = $this->sources->sceneStage($projectId, $stageId);

        if ($stage === null) {
            return ['reason' => 'Bản chia scene này không thuộc dự án hoặc chưa chạy thành công.'] + $report;
        }

        [$evaluation, $problem] = $this->sources->inspect($projectId, $stage);

        if ($problem !== null) {
            return ['reason' => $problem] + $report;
        }

        $scenes = $patch['scenes'] ?? null;
        $document = (array) $evaluation['document'];
        $ids = array_column((array) $document['data']['scenes'], 'id');

        if (! is_string($patch['reason'] ?? null) || trim($patch['reason']) === '' || ! is_array($scenes) || $scenes === [] || array_is_list($scenes)) {
            return ['reason' => 'Bản sửa cần reason và scenes theo mã scene.'] + $report;
        }

        foreach ($scenes as $id => $fields) {
            if (! in_array($id, $ids, true) || ! is_array($fields) || $fields === [] || array_diff(array_keys($fields), ['work', 'continuity_start', 'continuity_end']) !== []) {
                return ['reason' => "Bản sửa {$id}: chỉ sửa work, continuity_start, continuity_end của một scene có trong bản chia scene."] + $report;
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

        $report['prompt'] = 'Prompt '.$run['phase']->phase_code.' revision '.$run['phase']->revision;
        $revised = $document;
        $revised['prompt_version'] = $run['prompt_version'];

        foreach ($revised['data']['scenes'] as &$scene) {
            $before = $scene;
            $assumptions = $scene['assumptions'];
            unset($scene['assumptions']);
            $scene = array_replace($scene, (array) ($scenes[$scene['id']] ?? [])) + ['work' => [], 'assumptions' => $assumptions];

            foreach (['work', 'continuity_start', 'continuity_end'] as $key) {
                if (! array_key_exists($key, $before) || $before[$key] !== $scene[$key]) {
                    $report['changes'][] = [
                        'path' => "{$scene['id']}.{$key}",
                        'before' => array_key_exists($key, $before) ? (string) json_encode($before[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '(không có)',
                        'after' => (string) json_encode($scene[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ];
                }
            }
        }

        unset($scene);
        $raw = (string) json_encode($revised, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $result = ScriptEvaluation::of($raw, $run['metadata']['manifest'], $run['metadata']['context'], $run['metadata']['source'], $run['adapter']);
        $report['check'] = $result['check'];
        $report['content_hash'] = $result['content_hash'];
        $report['patch_hash'] = CanonicalJson::hash(['of' => $report['source'], 'patch' => $patch, 'contract' => $result['contract'], 'content_hash' => $result['content_hash']]);
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
        [$claimed, $token, $claimReason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::SCENES, $run['input'] + ['revision_of' => (string) $stage->id, 'patch_hash' => $report['patch_hash']], false, $metadata);

        if ($token === null) {
            return $claimReason === 'already_succeeded'
                ? ['reason' => 'already_applied', 'revision_stage_id' => (string) $claimed->id, 'revision' => (int) $claimed->planning_revision] + $report
                : ['reason' => $claimReason === 'claimed_by_other' ? 'Đang có một lượt chia scene chạy cho dự án này — đợi xong rồi thử lại.' : $claimReason] + $report;
        }

        try {
            $recorded = $this->stageStore->finishSucceeded((string) $claimed->id, $token, $raw, $this->output($run, $result), ['instruction_version' => $run['prompt_version'], 'tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0]);
        } catch (\Throwable $e) {
            $this->stageStore->releaseClaim((string) $claimed->id, 'Bản sửa scene không lưu được');

            throw $e;
        }

        if ($recorded) {
            $this->approvedWhenPassed($projectId, [$claimed, 'applied']);
        }

        return ['reason' => $recorded ? 'applied' : 'Lượt lưu bị giành mất — tải lại rồi thử lại.', 'revision_stage_id' => (string) $claimed->id, 'revision' => (int) $claimed->planning_revision] + $report;
    }

    /** @return ?array{revision: int, reason: string, changes: list<array{path: string, before: string, after: string}>} the run a hand revision revises, why and what it changed; null for a run of the model */
    private static function revisionOf(VideoPlanningStage $stage): ?array
    {
        $revision = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null)['revision'] ?? null;

        return is_array($revision) ? ['revision' => (int) $revision['of']['revision'], 'reason' => (string) $revision['reason'], 'changes' => array_values((array) $revision['changes'])] : null;
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
            throw new RuntimeException("Prompt {$phase->phase_code} revision {$phase->revision} chưa yêu cầu định dạng {$adapter->format()} — cần publish revision tương thích trước khi chia scene.");
        }

        if (! str_contains((string) $phase->prompt_content, "Input format: `{$adapter->inputsVersion()}`")) {
            throw new RuntimeException("Prompt {$phase->phase_code} revision {$phase->revision} không mô tả đầu vào {$adapter->inputsVersion()} mà code đang dựng — cần phát hành revision prompt tương thích trước khi chia scene, không gọi model.");
        }

        $settings = $adapter->settings();

        if (! ClaudeWriterService::supports($settings['model'])) {
            throw new RuntimeException('Model chia scene '.$settings['model'].' không có trong ClaudeWriterService::MODEL_CATALOG.');
        }

        $project = VideoProject::query()->with('article.category')->findOrFail($projectId);
        $inputs = $adapter->inputs($project, (string) $phase->prompt_content);
        $promptVersion = "{$flow['workflow']}/{$phase->phase_code}@rev{$phase->revision}";
        $payload = (string) json_encode(['project_id' => $projectId, 'prompt_version' => $promptVersion] + $inputs['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $context = ['project_id' => $projectId, 'prompt_version' => $promptVersion] + $inputs['context'];

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
