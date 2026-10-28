<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\ImageQuality;
use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\AiPhase;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Admin\ClaudeWriterService;
use App\Video\Llm\LlmClient;
use App\Video\Locations\LocationReferencePrompt;
use App\Video\Media\MediaModelRegistry;
use App\Video\Superyacht\Spec\PhaseResolver;
use Illuminate\Support\Str;
use RuntimeException;

final class LocationWriterService
{
    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly LocationSourceService $sources,
        private readonly DesignImageStore $images,
        private readonly LlmClient $llm,
    ) {}

    /** @return array{0: ?VideoPlanningStage, 1: string} */
    public function run(string $projectId, bool $regenerate, string $operationId): array
    {
        return (new SpecStageRunner($this->stageStore))->run(
            $projectId,
            PlanningStageName::LOCATIONS,
            fn (): array => $this->prepare($projectId),
            $regenerate,
            $operationId,
            $this->llm,
            fn (array $run, array $evaluation): array => $this->output($run, $evaluation),
            'lập bối cảnh',
            'locations',
        );
    }

    /** @return array{0: bool, 1: string} */
    public function reset(string $projectId): array
    {
        [$latest] = $this->stageStore->latestStageForProject($projectId, PlanningStageName::LOCATIONS, [], skipOrphans: true);

        if ($latest === null) {
            return [false, 'Chưa có lượt lập bối cảnh nào.'];
        }

        return $this->stageStore->releaseClaim((string) $latest->id, 'Người dùng reset thủ công')
            ? [true, 'ok']
            : [false, 'Lượt này không còn giữ claim — không có gì để reset.'];
    }

    /** @return array<string, mixed> */
    public function panel(string $projectId, ?string $viewStageId = null): array
    {
        $run = null;
        $runError = null;
        $supported = true;

        try {
            $this->sources->workflowFor($projectId);
        } catch (RuntimeException $e) {
            $supported = false;
            $runError = $e->getMessage();
        }

        if ($supported) {
            try {
                $run = $this->prepare($projectId);
            } catch (\Throwable $e) {
                $runError = $e->getMessage();
            }
        }

        $stages = VideoPlanningStage::query()->where('project_id', $projectId)->where('stage', PlanningStageName::LOCATIONS->value)
            ->whereNull('input_json->orphan_of_stage_id')->orderByDesc('planning_revision')->get();
        $latest = $stages->first();
        $served = $run === null ? null : $this->stageStore->latestSucceededForInput($projectId, PlanningStageName::LOCATIONS, $run['input']);
        $selection = $this->sources->selection($projectId);
        $succeeded = $stages->where('status', VideoPlanningStageStatus::SUCCEEDED->value);
        $shown = ($viewStageId === null ? null : $succeeded->firstWhere('id', $viewStageId))
            ?? ($selection['text'] === null ? null : $succeeded->firstWhere('id', $selection['text']['stage_id']))
            ?? $succeeded->first();
        [$evaluation, $integrity] = $shown === null ? [[], null] : $this->sources->inspect($projectId, $shown);
        $check = $integrity === null && is_array($evaluation['check'] ?? null) ? $evaluation['check'] : null;
        $settings = $run['settings'] ?? null;
        [$text, $textError] = $this->approvedText($projectId, $selection);
        $source = null;
        $sourceError = null;

        if ($selection['set'] !== null) {
            try {
                $source = $this->sources->approvedSource($projectId);
            } catch (RuntimeException $e) {
                $sourceError = $e->getMessage();
            }
        }

        return [
            'supported' => $supported,
            'run_error' => $runError,
            'can_run' => $run !== null && $served === null,
            'can_rewrite' => $run !== null && $served !== null,
            'prompt' => $run === null ? null : 'Prompt '.$run['phase']->phase_code.' revision '.$run['phase']->revision,
            'model' => $settings === null ? null : $settings['model'].' (effort '.$settings['effort'].')',
            'max_tokens' => $settings['max_tokens'] ?? null,
            'timeout_minutes' => $settings === null ? null : intdiv((int) $settings['timeout_seconds'], 60),
            'operation_id' => (string) Str::uuid(),
            'running' => $latest !== null && $latest->status === VideoPlanningStageStatus::RUNNING->value && $latest->lease_expires_at?->isFuture() === true,
            'stuck' => $latest !== null && $latest->status === VideoPlanningStageStatus::RUNNING->value && $latest->lease_expires_at?->isFuture() !== true,
            'error' => $latest !== null && $latest->status === VideoPlanningStageStatus::FAILED->value ? $latest->error_message : null,
            'stream' => PlanningStageStore::streamOf($latest),
            'history' => $stages->map(static fn (VideoPlanningStage $stage): array => [
                'id' => (string) $stage->id,
                'revision' => (int) $stage->planning_revision,
                'status' => $stage->status,
                'verdict' => $stage->output_json['check']['verdict'] ?? null,
                'text_approved' => ($selection['text']['stage_id'] ?? null) === (string) $stage->id,
            ])->values()->all(),
            'selection' => $selection,
            'shown' => $shown === null ? null : [
                'id' => (string) $shown->id,
                'revision' => (int) $shown->planning_revision,
                'integrity_error' => $integrity,
                'content_hash' => $integrity === null ? ($evaluation['content_hash'] ?? null) : null,
                'check' => $check,
                'document' => $integrity === null ? ($evaluation['document'] ?? null) : null,
                'plan' => $integrity === null ? ($evaluation['derived'] ?? null) : null,
                'approvable' => $check !== null && $check['verdict'] === 'PASS' && $check['status'] === 'ok' && ($selection['text']['stage_id'] ?? null) !== (string) $shown->id,
                'text_approved' => $text !== null && (string) $text['stage']->id === (string) $shown->id,
                'tokens' => ['in' => (int) $shown->tokens_in, 'out' => (int) $shown->tokens_out],
                'cost_usd' => $shown->cost_usd === null ? null : (float) $shown->cost_usd,
            ],
            'text_error' => $textError,
            'references' => $text === null ? [] : $this->references($projectId, $text),
            'media' => $this->media(),
            'set_approved' => $source !== null,
            'source_error' => $sourceError,
            'source' => $source,
        ];
    }

    /**
     * @param  array<string, mixed>  $selection
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    private function approvedText(string $projectId, array $selection): array
    {
        if ($selection['text'] === null) {
            return [null, null];
        }

        try {
            return [$this->sources->approvedText($projectId), null];
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $text
     * @return list<array<string, mixed>>
     */
    private function references(string $projectId, array $text): array
    {
        $cells = collect($this->images->locationCellsFor($projectId, (string) $text['stage']->id))->groupBy('environment_key');
        $chosen = (array) $text['selection']['images'];
        $document = (array) $text['evaluation']['document'];
        $config = (array) $text['flow']['adapter']->settings()['reference'];
        $rows = [];

        foreach ($this->sources->manifest($text) as $row) {
            $request = $row['request'];
            $waiting = array_values(array_filter(
                array_map(static fn (array $input): ?string => $input['kind'] === 'request' && ! isset($chosen[$input['id']]) ? (string) $input['id'] : null, (array) $request['inputs']),
            ));

            $rows[] = $row + [
                'prompt' => LocationReferencePrompt::compose($document, $request, $config),
                'cells' => $cells->get($request['id'], collect())->values()->all(),
                'waiting_for' => $waiting,
                'can_render' => $waiting === [],
            ];
        }

        return $rows;
    }

    /** @return array{models: list<array<string, mixed>>, default: ?array<string, mixed>, error: ?string, costs: array<string, float>} */
    private function media(): array
    {
        try {
            $registry = app(MediaModelRegistry::class);

            return [
                'models' => $registry->forTask(LocationReferencePrompt::TASK),
                'default' => $registry->defaultFor(LocationReferencePrompt::TASK),
                'error' => null,
                'costs' => collect(ImageQuality::cases())->mapWithKeys(static fn (ImageQuality $quality): array => [$quality->value => $quality->estimatedCostUsd()])->all(),
            ];
        } catch (\InvalidArgumentException $e) {
            return ['models' => [], 'default' => null, 'error' => 'Danh sách model ảnh Phase 5 lỗi cấu hình: '.$e->getMessage(), 'costs' => []];
        }
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
            throw new RuntimeException("Prompt {$phase->phase_code} revision {$phase->revision} chưa yêu cầu định dạng {$adapter->format()} — cần publish revision tương thích trước khi lập bối cảnh.");
        }

        $settings = $adapter->settings();

        if (! ClaudeWriterService::supports($settings['model'])) {
            throw new RuntimeException('Model lập bối cảnh '.$settings['model'].' không có trong ClaudeWriterService::MODEL_CATALOG.');
        }

        $project = VideoProject::query()->with('article.category')->findOrFail($projectId);
        $inputs = $adapter->inputs($project, (string) $phase->prompt_content);
        $promptVersion = "{$flow['workflow']}/{$phase->phase_code}@rev{$phase->revision}";
        $payload = (string) json_encode(['project_id' => $projectId, 'prompt_version' => $promptVersion] + $inputs['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $context = ['project_id' => $projectId, 'prompt_version' => $promptVersion] + $inputs['context'];

        return [
            'adapter' => $adapter,
            'phase' => $phase,
            'system' => implode("\n\n", array_column($bundle->parts(), 'content')),
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
