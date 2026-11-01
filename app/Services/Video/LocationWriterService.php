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

    /**
     * @param  ?string  $effort  the effort of this one run, one of the configured efforts; null keeps the default
     * @return array{0: ?VideoPlanningStage, 1: string}
     */
    public function run(string $projectId, bool $regenerate, string $operationId, ?string $effort = null): array
    {
        return (new SpecStageRunner($this->stageStore))->run(
            $projectId,
            PlanningStageName::LOCATIONS,
            fn (): array => $this->prepare($projectId, $effort),
            $regenerate,
            $operationId,
            $this->llm,
            fn (array $run, array $evaluation): array => $this->output($run, $evaluation),
            'tạo địa điểm và bối cảnh',
            'locations',
        );
    }

    /** @return array{0: bool, 1: string} */
    public function reset(string $projectId): array
    {
        [$latest] = $this->stageStore->latestStageForProject($projectId, PlanningStageName::LOCATIONS, [], skipOrphans: true);

        if ($latest === null) {
            return [false, 'Chưa có lượt tạo địa điểm và bối cảnh nào.'];
        }

        return $this->stageStore->releaseClaim((string) $latest->id, 'Người dùng reset thủ công')
            ? [true, 'ok']
            : [false, 'Lượt này không còn giữ claim — không có gì để reset.'];
    }

    /**
     * @param  ?string  $viewStageId  a run picked on the page
     * @return array<string, mixed>
     */
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
        [$text, $textError] = $this->approvedText($projectId, $selection);
        $succeeded = $stages->where('status', VideoPlanningStageStatus::SUCCEEDED->value);
        $shown = ($viewStageId === null ? null : $succeeded->firstWhere('id', $viewStageId))
            ?? ($text === null ? null : $succeeded->firstWhere('id', (string) $text['stage']->id))
            ?? $succeeded->first();
        [$evaluation, $integrity] = $shown === null ? [[], null] : $this->sources->inspect($projectId, $shown);
        $check = $integrity === null && is_array($evaluation['check'] ?? null) ? $evaluation['check'] : null;
        $legacy = $integrity === null ? ($evaluation['legacy'] ?? null) : null;
        $document = $integrity === null && is_array($evaluation['document'] ?? null) ? $evaluation['document'] : null;
        $readable = $document !== null && $check !== null && $check['schema'] === [] && $check['verdict'] !== 'UNSCORED' && $check['status'] !== 'fail';
        $plan = $legacy === null && $integrity === null && is_array($evaluation['derived'] ?? null) ? $evaluation['derived'] : null;
        $settings = $run['settings'] ?? null;
        $approvedShown = $shown !== null && $text !== null && (string) $text['stage']->id === (string) $shown->id;
        $preview = $plan !== null && $supported ? $this->preview($projectId, $plan) : ['rows' => [], 'hash' => null, 'error' => null];
        $source = null;
        $sourceError = null;

        if ($text !== null) {
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
            'model' => $settings === null ? null : $settings['model'],
            'effort' => $settings['effort'] ?? null,
            'efforts' => $settings['efforts'] ?? [],
            'max_tokens' => $settings['max_tokens'] ?? null,
            'timeout_minutes' => $settings === null ? null : intdiv((int) $settings['timeout_seconds'], 60),
            'semantic_review' => $settings['semantic_review'] ?? null,
            'operation_id' => (string) Str::uuid(),
            'running' => $latest !== null && $latest->status === VideoPlanningStageStatus::RUNNING->value && $latest->lease_expires_at?->isFuture() === true,
            'stuck' => $latest !== null && $latest->status === VideoPlanningStageStatus::RUNNING->value && $latest->lease_expires_at?->isFuture() !== true,
            'error' => $latest !== null && $latest->status === VideoPlanningStageStatus::FAILED->value ? $latest->error_message : null,
            'stream' => PlanningStageStore::streamOf($latest),
            'history' => $stages->map(static fn (VideoPlanningStage $stage): array => [
                'id' => (string) $stage->id,
                'revision' => (int) $stage->planning_revision,
                'status' => $stage->status,
                'effort' => is_string($stage->input_json['effort'] ?? null) ? $stage->input_json['effort'] : null,
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
                'legacy' => $legacy,
                'locations' => $readable ? array_values(array_filter((array) ($document['data']['locations'] ?? []), 'is_array')) : [],
                'flags' => array_values(array_filter(is_array($document['flags'] ?? null) ? $document['flags'] : [], 'is_string')),
                'plan' => $plan,
                'images' => $preview['rows'],
                'images_hash' => $preview['hash'],
                'images_error' => $preview['error'],
                'cells' => $preview['rows'] === [] ? [] : collect($this->images->locationCellsFor($projectId, (string) $shown->id))->groupBy('environment_key')->map(static fn ($cells): array => $cells->values()->all())->all(),
                'renderable' => $legacy === null && $check !== null && $check['verdict'] === 'PASS' && in_array($check['status'], ['ok', 'partial'], true) && $preview['error'] === null && $preview['rows'] !== [],
                'text_approved' => $approvedShown,
                'stale' => $approvedShown ? $text['stale'] : [],
                'tokens' => ['in' => (int) $shown->tokens_in, 'out' => (int) $shown->tokens_out],
                'cost_usd' => $shown->cost_usd === null ? null : (float) $shown->cost_usd,
            ],
            'text_error' => $textError,
            'pinned' => $text === null ? null : ['id' => (string) $text['stage']->id, 'revision' => (int) $text['stage']->planning_revision],
            'references' => $text === null ? [] : $this->references($projectId, $text),
            'media' => $this->media(),
            'set_ready' => $source !== null,
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
     * @param  array<string, mixed>  $plan
     * @return array{rows: list<array{request: array<string, mixed>, prompt: string, sha256: string}>, hash: ?string, error: ?string} every background with the whole prompt a render sends and its hash, and the hash of that prompt set the page sends back with a render
     */
    private function preview(string $projectId, array $plan): array
    {
        try {
            $prompts = LocationSourceService::prompts($plan, (array) $this->sources->workflowFor($projectId)['adapter']->settings()['reference']);
        } catch (RuntimeException $e) {
            return ['rows' => [], 'hash' => null, 'error' => 'Chưa ghép được prompt: '.$e->getMessage()];
        }

        return [
            'rows' => array_map(static fn (array $request): array => ['request' => $request, 'prompt' => $prompts['images'][$request['id']]['prompt'], 'sha256' => $prompts['images'][$request['id']]['sha256']], (array) $plan['images']),
            'hash' => $prompts['hash'],
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $text
     * @return list<array<string, mixed>>
     */
    private function references(string $projectId, array $text): array
    {
        $cells = collect($this->images->locationCellsFor($projectId, (string) $text['stage']->id))->groupBy('environment_key');
        $rows = [];

        foreach ($this->sources->manifest($text) as $row) {
            $id = (string) $row['request']['id'];
            $stale = in_array($id, $text['stale'], true);

            $rows[] = $row + [
                'cells' => $cells->get($id, collect())->values()->all(),
                'can_render' => ! $stale && ! $row['request']['blocked'] && $row['prompt'] !== '',
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
    private function prepare(string $projectId, ?string $effort = null): array
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
            throw new RuntimeException("Prompt {$phase->phase_code} revision {$phase->revision} chưa yêu cầu định dạng {$adapter->format()} — cần publish revision tương thích trước khi tạo địa điểm và bối cảnh.");
        }

        if (! str_contains((string) $phase->prompt_content, "Input format: `{$adapter->inputsVersion()}`")) {
            throw new RuntimeException("Prompt {$phase->phase_code} revision {$phase->revision} không mô tả đầu vào {$adapter->inputsVersion()} mà code đang dựng — cần phát hành revision prompt tương thích trước khi tạo địa điểm và bối cảnh, không gọi model.");
        }

        $settings = $adapter->settings();

        if ($effort !== null) {
            if (! in_array($effort, $settings['efforts'], true)) {
                throw new RuntimeException("Effort {$effort} không nằm trong danh sách cho phép của Phase 5: ".implode(', ', $settings['efforts']).'.');
            }

            $settings['effort'] = $effort;
        }

        if (! ClaudeWriterService::supports($settings['model'])) {
            throw new RuntimeException('Model tạo địa điểm và bối cảnh '.$settings['model'].' không có trong ClaudeWriterService::MODEL_CATALOG.');
        }

        $schemas = array_map(
            static fn (array $schema): string => "## {$settings['schema_heading']} — {$schema['label']}\n\n```json\n".trim($schema['content'])."\n```",
            $bundle->outputSchemas(),
        );
        $project = VideoProject::query()->with('article.category')->findOrFail($projectId);
        $inputs = $adapter->inputs($project, (string) $phase->prompt_content);
        $promptVersion = "{$flow['workflow']}/{$phase->phase_code}@rev{$phase->revision}";
        $payload = (string) json_encode(['project_id' => $projectId, 'prompt_version' => $promptVersion] + $inputs['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $context = ['project_id' => $projectId, 'prompt_version' => $promptVersion] + $inputs['context'];

        return [
            'adapter' => $adapter,
            'phase' => $phase,
            'system' => implode("\n\n", [...array_column($bundle->parts(), 'content'), ...$schemas]),
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
