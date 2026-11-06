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
use App\Video\Moto\MotoReference;
use App\Video\Script\ScriptEvaluation;
use App\Video\Superyacht\Spec\PhaseResolver;
use Illuminate\Support\Str;
use RuntimeException;

final class ReferenceWriterService implements QueuedStageService
{
    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly LlmClient $llm,
    ) {}

    /**
     * @param  list<string>  $cellIds  the cells of one master whose prompt the run checks
     * @param  ?array{id: string, name: string}  $requester
     * @return array{0: ?VideoPlanningStage, 1: string} reason queued|cached|replayed|ok|requeued, or why nothing ran
     */
    public function run(string $projectId, string $planId, array $cellIds, bool $regenerate, string $operationId, ?array $requester = null): array
    {
        return (new SpecStageRunner($this->stageStore))->submit(
            'references',
            $projectId,
            PlanningStageName::REFERENCE_REVIEW,
            fn (): array => $this->prepare($projectId, $planId, $cellIds),
            $regenerate,
            $operationId,
            $this->llm,
            fn (array $run, array $evaluation): array => $this->output($run, $evaluation),
            'kiểm prompt Reference',
            'reference review',
            $requester,
        );
    }

    /** @return array{0: ?VideoPlanningStage, 1: string} what the queued run became when a worker took it */
    public function execute(string $stageId, string $token): array
    {
        $stage = VideoPlanningStage::query()->withoutSnapshot()->find($stageId);

        if ($stage === null) {
            return [null, 'Lượt kiểm prompt Reference không còn — bỏ qua.'];
        }

        $projectId = (string) $stage->project_id;
        $planId = (string) ($stage->input_json['plan_id'] ?? '');
        $cellIds = array_values(array_map(static fn (array $cell): string => (string) $cell['id'], (array) ($stage->input_json['cells'] ?? [])));

        return (new SpecStageRunner($this->stageStore))->execute(
            $stageId,
            $token,
            fn (): array => $this->prepare($projectId, $planId, $cellIds),
            $this->llm,
            fn (array $run, array $evaluation): array => $this->output($run, $evaluation),
            'kiểm prompt Reference',
            'reference review',
        );
    }

    /** @return array{0: bool, 1: string} */
    public function reset(string $projectId): array
    {
        return $this->stageStore->resetLatest($projectId, PlanningStageName::REFERENCE_REVIEW, 'Chưa có lượt kiểm prompt Reference nào.');
    }

    /**
     * @param  list<string>  $cellIds
     * @return ?array{model: string, effort: string, text: string} what a run of these cells sends and costs, null when it cannot be prepared
     */
    public function estimate(string $projectId, string $planId, array $cellIds): ?array
    {
        try {
            $run = $this->prepare($projectId, $planId, $cellIds);
        } catch (\Throwable) {
            return null;
        }

        $settings = $run['settings'];
        $input = (int) ceil((mb_strlen($run['system']) + mb_strlen($run['payload'])) / 3.5);
        $output = count($cellIds) * (int) $settings['estimate_output_tokens_per_cell'];

        return [
            'model' => (string) $settings['model'],
            'effort' => (string) $settings['effort'],
            'text' => '≈ $'.number_format(ClaudeWriterService::costUsd($input, $output, (string) $settings['model']), 3)
                .', tối đa ≈ $'.number_format(ClaudeWriterService::costUsd($input, (int) $settings['max_tokens'], (string) $settings['model']), 3)
                .' (≈ '.number_format($input).' token vào)',
        ];
    }

    /** @return array<string, mixed> the newest review run of the project, for the page */
    public function summary(string $projectId): array
    {
        $latest = VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $projectId)->where('stage', PlanningStageName::REFERENCE_REVIEW->value)
            ->orderByDesc('planning_revision')->first();

        return [
            'operation_id' => (string) Str::uuid(),
            'running' => $latest !== null && $latest->status === VideoPlanningStageStatus::RUNNING->value && $latest->lease_expires_at?->isFuture() === true,
            'pending' => $latest !== null && $latest->status === VideoPlanningStageStatus::PENDING->value && $latest->claim_token !== null,
            'task' => PlanningStageStore::taskOf($latest),
            'stream' => PlanningStageStore::streamOf($latest),
            'error' => $latest !== null && $latest->status === VideoPlanningStageStatus::FAILED->value ? $latest->error_message : null,
            'latest' => $latest === null ? null : [
                'id' => (string) $latest->id,
                'revision' => (int) $latest->planning_revision,
                'status' => (string) $latest->status,
                'verdict' => $latest->output_json['check']['verdict'] ?? null,
                'issues' => array_values((array) ($latest->output_json['check']['issues'] ?? [])),
                'cells' => array_values(array_map(static fn (array $cell): string => (string) $cell['id'], (array) ($latest->input_json['cells'] ?? []))),
                'tokens' => ['in' => (int) $latest->tokens_in, 'out' => (int) $latest->tokens_out],
                'cost_usd' => $latest->cost_usd === null ? null : (float) $latest->cost_usd,
                'model' => $latest->model,
            ],
        ];
    }

    /**
     * @param  list<string>  $cellIds
     * @return array<string, mixed>
     *
     * @throws RuntimeException when the contract, the prompt, the model or the cells are not ready; no model is called
     */
    private function prepare(string $projectId, string $planId, array $cellIds): array
    {
        $project = VideoProject::query()->with('article.category')->findOrFail($projectId);
        $category = $project->article?->category ?? throw new RuntimeException('Dự án không gắn bài viết có chủ đề — Phase 8 không áp dụng.');
        $workflow = (string) (config('video.superyacht.contracts.categories')[$category->slug] ?? '');
        $entry = config('video.references.workflows')[$workflow] ?? null;

        if (! is_array($entry)) {
            throw new RuntimeException("Chủ đề {$category->slug} chưa hỗ trợ Phase 8.");
        }

        $adapter = app((string) $entry['adapter']);

        if (! $adapter instanceof MotoReference) {
            throw new RuntimeException("Adapter Phase 8 của workflow {$workflow} không hợp lệ.");
        }

        $bundle = PhaseResolver::fromConfig((array) config('video.superyacht'))->forProject($projectId, (string) $entry['action']);

        if (! $bundle->ready()) {
            throw new RuntimeException("Hợp đồng {$entry['action']} chưa sẵn sàng: ".implode('; ', $bundle->mismatches()).' — chạy php artisan db:seed --class=AiPhaseSeeder trước.');
        }

        $manifest = $bundle->manifest();
        $phase = AiPhase::query()->findOrFail($manifest['phase']['id']);

        if (! str_contains((string) $phase->prompt_content, $adapter->format()) || ! str_contains((string) $phase->prompt_content, "Input format: `{$adapter->inputsVersion()}`")) {
            throw new RuntimeException("Prompt {$phase->phase_code} revision {$phase->revision} không khớp định dạng {$adapter->format()} / đầu vào {$adapter->inputsVersion()} code đang dựng — phát hành revision tương thích trước, không gọi model.");
        }

        $settings = $adapter->settings();

        if (! ClaudeWriterService::supports($settings['model'])) {
            throw new RuntimeException('Model kiểm prompt Reference '.$settings['model'].' không có trong ClaudeWriterService::MODEL_CATALOG.');
        }

        $cells = app(PartsDetailService::class)->reviewCells($projectId, $planId, $cellIds);
        $design = VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $projectId)->findOrFail($cells['preview']['source']['design_stage_id']);
        $palette = array_column((array) (json_decode((string) $design->raw_response, true)['data']['motorcycle']['palette'] ?? []), null, 'id');
        $inputs = MotoReference::inputs($cells['references'], $cells['regime'], $palette);
        $promptVersion = "{$workflow}/{$phase->phase_code}@rev{$phase->revision}";
        $payload = (string) json_encode(['project_id' => $projectId, 'prompt_version' => $promptVersion] + $inputs['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $context = ['project_id' => $projectId, 'prompt_version' => $promptVersion] + $inputs['context'];

        if (($problem = $adapter->contextProblem($context, ScriptEvaluation::contract($manifest))) !== null) {
            throw new RuntimeException("Hợp đồng {$entry['action']} không khớp code kiểm Reference: {$problem} — phát hành gói Phase 8 tương thích trước, không gọi model.");
        }

        $pinned = array_map(static fn (array $reference): array => ['id' => (string) $reference['id'], 'fingerprint' => (string) $reference['fingerprint']], $cells['references']);
        $source = ['plan_id' => $planId, 'plan_hash' => $cells['preview']['hash'], 'regime' => $cells['regime'], 'cells' => $pinned];

        return [
            'adapter' => $adapter,
            'phase' => $phase,
            'system' => $adapter->systemText(array_column($bundle->parts(), 'content'), $bundle->outputSchemas()),
            'payload' => $payload,
            'prompt_version' => $promptVersion,
            'settings' => $settings,
            'input' => [
                'execution' => $settings['execution'],
                'workflow' => $workflow,
                'category_id' => (string) $category->id,
                'bundle_hash' => $bundle->bundleHash(),
                'phase_id' => (string) $phase->id,
                'phase_revision' => (int) $phase->revision,
                'prompt_sha256' => (string) $phase->content_sha256,
                'plan_id' => $planId,
                'cells' => $pinned,
                'source' => $source,
                'payload_sha256' => hash('sha256', $payload),
                'model' => $settings['model'],
                'effort' => $settings['effort'],
                'max_tokens' => $settings['max_tokens'],
                'check_version' => $adapter->checkVersion(),
                'inputs_version' => $adapter->inputsVersion(),
            ],
            'metadata' => [
                'manifest' => $manifest,
                'source' => $source,
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
