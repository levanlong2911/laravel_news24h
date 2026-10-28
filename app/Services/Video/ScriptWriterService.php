<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\AiPhase;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Admin\ClaudeWriterService;
use App\Video\Inspiration\ClaudeInspirationAnalyst;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Llm\LlmUnavailable;
use App\Video\Script\ScriptEvaluation;
use App\Video\Superyacht\Spec\PhaseResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

final class ScriptWriterService
{
    public const BRIEF_KEY = 'script_brief';

    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly ScriptSourceService $sources,
        private readonly LlmClient $llm,
    ) {}

    /** @return array{0: ?VideoPlanningStage, 1: string} */
    public function run(string $projectId, bool $regenerate, string $operationId): array
    {
        if (! Str::isUuid($operationId)) {
            return [null, 'Mã thao tác không hợp lệ — tải lại trang.'];
        }

        $replayed = VideoPlanningStage::query()->where('project_id', $projectId)->where('stage', PlanningStageName::SCRIPT->value)
            ->where('input_json->'.PlanningStageStore::METADATA_KEY.'->operation_id', $operationId)->orderByDesc('planning_revision')->first();

        if ($replayed !== null) {
            return self::replay($replayed);
        }

        try {
            $run = $this->prepare($projectId);
        } catch (\Throwable $e) {
            Log::error('script: prompt, contract or approved source unavailable, no model call made', ['project_id' => $projectId, 'exception' => $e]);

            return [null, 'Chưa gọi model: '.$e->getMessage()];
        }

        [$stage, $token, $reason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::SCRIPT, $run['input'], $regenerate, $run['metadata'] + ['operation_id' => $operationId]);

        if ($token === null) {
            return match ($reason) {
                'operation_replayed' => self::replay($stage),
                'already_succeeded' => [$stage, 'cached'],
                'claimed_by_other' => [null, 'Đang có một lượt viết kịch bản chạy cho dự án này — đợi xong rồi thử lại.'],
                default => [null, 'Không tìm thấy dự án.'],
            };
        }

        $settings = $run['settings'];
        $version = $run['prompt_version'];
        $usage = [];
        $raw = '';

        $stageId = (string) $stage->id;
        $onProgress = fn (array $state): bool => $this->stageStore->recordStream($stageId, $token, $state);

        try {
            $response = $this->llm->complete(new LlmRequest(
                $run['system'],
                $run['payload'],
                $version,
                $settings['model'],
                $settings['max_tokens'],
                null,
                $settings['effort'],
                $settings['timeout_seconds'],
                1,
                $onProgress,
            ));
            $raw = $response->text;
            $usage = ClaudeInspirationAnalyst::usageOf($response, $version);
            $evaluation = ScriptEvaluation::of($raw, $run['metadata']['manifest'], $run['metadata']['context'], $run['metadata']['source'], $run['adapter']);

            if ($evaluation['document'] === null) {
                $this->stageStore->finishFailed((string) $stage->id, $token, 'Phản hồi không đọc được JSON: '.$evaluation['parse_error'], $usage, $raw, $this->output($run, $evaluation));

                return [null, 'Phản hồi không đọc được JSON — phản hồi và usage đã được lưu.'];
            }

            $output = $this->output($run, $evaluation);

            if (! $this->stageStore->finishSucceeded((string) $stage->id, $token, $raw, $output, $usage)) {
                try {
                    $this->stageStore->recordOrphanAttempt($projectId, PlanningStageName::SCRIPT, $run['input'], $run['metadata'], (string) $stage->id, $token, 'claim_lost', $usage, $raw, $output);
                } catch (\Throwable $e) {
                    Log::warning('script: recordOrphanAttempt threw', ['stage_id' => $stage->id, 'exception' => $e]);
                }

                return [null, 'Lượt viết kịch bản bị giành mất khi lưu — kết quả đã trả tiền không được dùng. Tải lại trang.'];
            }

            return [VideoPlanningStage::query()->find($stage->id), 'ok'];
        } catch (\Throwable $e) {
            Log::error('script: the run failed', ['stage_id' => $stage->id, 'exception' => $e]);
            $billed = $e instanceof LlmUnavailable ? $e->billed : null;

            $this->stageStore->finishFailed(
                (string) $stage->id,
                $token,
                $e->getMessage(),
                $usage !== [] ? $usage : ($billed === null ? ['model' => $settings['model'], 'instruction_version' => $version] : ClaudeInspirationAnalyst::usageOf($billed, $version)),
                $raw !== '' ? $raw : ($billed?->text ?? ''),
            );

            return [null, $e->getMessage()];
        }
    }

    /** @return array{0: ?VideoPlanningStage, 1: string} what a request carrying an operation id that already ran gets back */
    private static function replay(VideoPlanningStage $stage): array
    {
        return match ($stage->status) {
            VideoPlanningStageStatus::SUCCEEDED->value => [$stage, 'replayed'],
            VideoPlanningStageStatus::RUNNING->value => [null, 'Thao tác này đang chạy — đợi xong rồi tải lại trang.'],
            default => [null, 'Thao tác này đã chạy và lỗi: '.$stage->error_message.' — tải lại trang để thử lại.'],
        };
    }

    /** @return array<string, string> room and exterior zone names from the payload the stage was written with */
    private static function places(VideoPlanningStage $stage): array
    {
        $payload = json_decode((string) (PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null)['payload'] ?? ''), true);
        $places = [];

        foreach (['rooms', 'exterior_zones'] as $list) {
            foreach ((array) ($payload['yacht_bible'][$list] ?? []) as $place) {
                if (is_array($place) && is_string($place['id'] ?? null)) {
                    $places[$place['id']] = mb_substr((string) ($place['name'] ?? $place['text'] ?? ''), 0, 60);
                }
            }
        }

        return $places;
    }

    /**
     * @param  array{video_length?: mixed, aspect_ratio?: mixed, platform?: mixed, tone_notes?: mixed}  $brief  empty values fall back to the default
     * @return array{0: bool, 1: string}
     */
    public function saveBrief(string $projectId, array $brief): array
    {
        try {
            $settings = $this->sources->workflowFor($projectId)['adapter']->settings();
        } catch (RuntimeException $e) {
            return [false, $e->getMessage()];
        }

        $override = array_filter(
            array_intersect_key($brief, $settings['brief']),
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        if (isset($override['video_length']) && is_numeric($override['video_length']) && (string) (int) $override['video_length'] === (string) $override['video_length']) {
            $override['video_length'] = (int) $override['video_length'];
        }

        $problems = self::briefProblems($override + $settings['brief'], $settings['brief_options']);

        if ($problems !== []) {
            return [false, 'Brief không hợp lệ: '.implode('; ', $problems).'.'];
        }

        return DB::transaction(function () use ($projectId, $override): array {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->first();

            if ($project === null) {
                return [false, 'Không tìm thấy dự án.'];
            }

            $metadata = $project->metadata_json ?? [];

            if ($override === []) {
                unset($metadata[self::BRIEF_KEY]);
            } else {
                $metadata[self::BRIEF_KEY] = $override;
            }

            $project->metadata_json = $metadata;
            $project->save();

            return [true, 'ok'];
        });
    }

    /**
     * @param  array<string, mixed>  $settings  ScriptWorkflow::settings()
     * @return array{video_length: int, aspect_ratio: string, platform: string, tone_notes: string}
     */
    public static function brief(VideoProject $project, array $settings): array
    {
        $override = $project->metadata_json[self::BRIEF_KEY] ?? [];
        $brief = (is_array($override) ? array_intersect_key($override, $settings['brief']) : []) + $settings['brief'];
        $problems = self::briefProblems($brief, $settings['brief_options']);

        if ($problems !== []) {
            throw new RuntimeException('Brief của dự án không hợp lệ: '.implode('; ', $problems).' — sửa brief trước khi viết kịch bản.');
        }

        return $brief;
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    private static function briefProblems(array $brief, array $options): array
    {
        [$min, $max] = $options['video_length'];
        $problems = [];

        if (! is_int($brief['video_length'] ?? null) || $brief['video_length'] < $min || $brief['video_length'] > $max) {
            $problems[] = $min === $max ? "phim final cố định {$min} giây" : "thời lượng phải là số nguyên {$min}–{$max} giây";
        }

        if (! in_array($brief['aspect_ratio'] ?? null, $options['aspect_ratios'], true)) {
            $problems[] = 'tỷ lệ khung phải là '.implode(', ', $options['aspect_ratios']);
        }

        if (! is_string($brief['platform'] ?? null) || trim($brief['platform']) === '' || mb_strlen($brief['platform']) > (int) $options['platform_max']) {
            $problems[] = "nền tảng phải có chữ, tối đa {$options['platform_max']} ký tự";
        }

        if (! is_string($brief['tone_notes'] ?? null) || mb_strlen($brief['tone_notes']) > (int) $options['tone_notes_max']) {
            $problems[] = "ghi chú giọng điệu tối đa {$options['tone_notes_max']} ký tự";
        }

        return $problems;
    }

    /** @return array{0: bool, 1: string} */
    public function reset(string $projectId): array
    {
        [$latest] = $this->stageStore->latestStageForProject($projectId, PlanningStageName::SCRIPT, [], skipOrphans: true);

        if ($latest === null) {
            return [false, 'Chưa có lượt viết kịch bản nào.'];
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

        $stages = VideoPlanningStage::query()->where('project_id', $projectId)->where('stage', PlanningStageName::SCRIPT->value)
            ->whereNull('input_json->orphan_of_stage_id')->orderByDesc('planning_revision')->get();
        $latest = $stages->first();
        $served = $run === null ? null : $this->stageStore->latestSucceededForInput($projectId, PlanningStageName::SCRIPT, $run['input']);
        $selection = $this->sources->selection($projectId);
        $succeeded = $stages->where('status', VideoPlanningStageStatus::SUCCEEDED->value);
        $shown = ($viewStageId === null ? null : $succeeded->firstWhere('id', $viewStageId))
            ?? ($selection === null ? null : $succeeded->firstWhere('id', $selection['stage_id']))
            ?? $succeeded->first();
        $selected = null;

        if ($selection !== null) {
            try {
                $selected = $this->sources->approvedSource($projectId);
            } catch (RuntimeException $e) {
                $selected = ['error' => $e->getMessage()];
            }
        }

        $settings = $run['settings'] ?? null;
        [$evaluation, $integrity] = $shown === null ? [[], null] : $this->sources->inspect($projectId, $shown);
        $briefForm = null;

        if ($supported) {
            $defaults = $this->sources->workflowFor($projectId)['adapter']->settings();
            $override = VideoProject::query()->find($projectId)?->metadata_json[self::BRIEF_KEY] ?? null;
            $briefForm = [
                'values' => (is_array($override) ? array_intersect_key($override, $defaults['brief']) : []) + $defaults['brief'],
                'custom' => is_array($override),
                'options' => $defaults['brief_options'],
            ];
        }
        $check = $integrity === null && is_array($evaluation['check'] ?? null) ? $evaluation['check'] : null;

        return [
            'run_error' => $runError,
            'can_run' => $run !== null && $served === null,
            'can_rewrite' => $run !== null && $served !== null,
            'prompt' => $run === null ? null : 'Prompt '.$run['phase']->phase_code.' revision '.$run['phase']->revision,
            'model' => $settings === null ? null : $settings['model'].' (effort '.$settings['effort'].')',
            'max_tokens' => $settings['max_tokens'] ?? null,
            'timeout_minutes' => $settings === null ? null : intdiv((int) $settings['timeout_seconds'], 60),
            'brief' => $run['metadata']['brief'] ?? null,
            'brief_form' => $briefForm,
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
                'model_status' => $stage->output_json['check']['status'] ?? null,
                'selected' => $selection !== null && $selection['stage_id'] === (string) $stage->id,
                'cost_usd' => $stage->cost_usd === null ? null : (float) $stage->cost_usd,
            ])->values()->all(),
            'selection' => $selection,
            'selected_error' => $selected['error'] ?? null,
            'shown' => $shown === null ? null : [
                'id' => (string) $shown->id,
                'revision' => (int) $shown->planning_revision,
                'selected' => $selection !== null && $selection['stage_id'] === (string) $shown->id && ! isset($selected['error']),
                'integrity_error' => $integrity,
                'places' => self::places($shown),
                'content_hash' => $integrity === null ? ($evaluation['content_hash'] ?? null) : null,
                'check' => $check,
                'document' => $integrity === null ? ($evaluation['document'] ?? null) : null,
                'approvable' => $check !== null && $check['verdict'] === 'PASS' && $check['status'] === 'ok',
                'tokens' => ['in' => (int) $shown->tokens_in, 'out' => (int) $shown->tokens_out],
                'cost_usd' => $shown->cost_usd === null ? null : (float) $shown->cost_usd,
            ],
            'selection_version' => (int) ($selection['version'] ?? 0),
        ];
    }

    /** @return array{adapter: \App\Video\Script\ScriptWorkflow, phase: AiPhase, system: string, payload: string, prompt_version: string, settings: array<string, mixed>, input: array<string, mixed>, metadata: array<string, mixed>} */
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
            throw new RuntimeException("Prompt {$phase->phase_code} revision {$phase->revision} chưa yêu cầu định dạng {$adapter->format()} — cần publish revision tương thích trước khi viết kịch bản.");
        }

        if (! ClaudeWriterService::supports($adapter->settings()['model'])) {
            throw new RuntimeException('Model viết kịch bản '.$adapter->settings()['model'].' không có trong ClaudeWriterService::MODEL_CATALOG.');
        }

        $settings = $adapter->settings();
        $project = VideoProject::query()->with('article.category')->findOrFail($projectId);
        $settings['brief'] = self::brief($project, $settings);

        if ($settings['history_enabled']) {
            throw new RuntimeException('Lịch sử kênh đang bật nhưng dự án chưa có nguồn xác định kênh — giữ video.superyacht.script.history.enabled = false; không dùng cùng chủ đề thay cho cùng kênh.');
        }

        $history = [];
        $inputs = $adapter->inputs($project, $settings['brief'], $history, (string) $phase->prompt_content);
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
                'projection_version' => $adapter->projectionVersion(),
            ],
            'metadata' => [
                'manifest' => $manifest,
                'source' => $inputs['source'],
                'context' => $context,
                'brief' => $settings['brief'],
                'history' => $history,
                'history_scope' => 'disabled',
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
