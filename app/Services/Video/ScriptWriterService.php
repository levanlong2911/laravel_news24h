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

final class ScriptWriterService implements QueuedStageService
{
    public const BRIEF_KEY = 'script_brief';

    /** @var list<string> the beat fields a hand revision may replace */
    private const REVISABLE = ['purpose', 'visual', 'location', 'on_screen', 'design_refs', 'in_use', 'configurations', 'situation', 'link', 'sfx'];

    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly ScriptSourceService $sources,
        private readonly LlmClient $llm,
    ) {}

    /**
     * @param  ?array{id: string, name: string}  $requester  who sent the run
     * @return array{0: ?VideoPlanningStage, 1: string} reason queued when a worker runs it later
     */
    public function run(string $projectId, bool $regenerate, string $operationId, ?array $requester = null): array
    {
        return (new SpecStageRunner($this->stageStore))->submit(
            'script',
            $projectId,
            PlanningStageName::SCRIPT,
            fn (): array => $this->prepare($projectId),
            $regenerate,
            $operationId,
            $this->llm,
            fn (array $run, array $evaluation): array => $this->output($run, $evaluation),
            'viết kịch bản',
            'script',
            $requester,
        );
    }

    /** @return array{0: ?VideoPlanningStage, 1: string} what the queued run became when a worker took it */
    public function execute(string $stageId, string $token): array
    {
        $projectId = VideoPlanningStage::query()->whereKey($stageId)->value('project_id');

        if (! is_string($projectId)) {
            return [null, 'Lượt viết kịch bản không còn — bỏ qua.'];
        }

        return (new SpecStageRunner($this->stageStore))->execute(
            $stageId,
            $token,
            fn (): array => $this->prepare($projectId),
            $this->llm,
            fn (array $run, array $evaluation): array => $this->output($run, $evaluation),
            'viết kịch bản',
            'script',
        );
    }

    /**
     * @param  array{reason?: mixed, beats?: mixed}  $patch  why the script is revised and, per beat id, the fields of REVISABLE that replace its own; the act, state, time jump and duration of a beat stay as written
     * @param  ?string  $expected  the patch hash of the preview the reviewer approved; required to write
     * @return array{passed: bool, reason: string, source: ?array<string, mixed>, prompt: ?string, changes: list<array{path: string, purpose: string, visual: string, before: string, after: string}>, check: ?array<string, mixed>, content_hash: ?string, patch_hash: ?string, revision_stage_id: ?string, revision: ?int} the revised moto script checked against the current contract like a run of the model, only for a run written from the sources the project uses now; previewed or, with $write and the approved patch hash, stored as a new run that names the run it revises; the revised run is not approved and the run it revises stays as it was
     */
    public function revise(string $projectId, string $stageId, array $patch, bool $write, ?string $expected, ?string $actor): array
    {
        $report = ['passed' => false, 'reason' => '', 'source' => null, 'prompt' => null, 'changes' => [], 'check' => null, 'content_hash' => null, 'patch_hash' => null, 'revision_stage_id' => null, 'revision' => null];
        $stage = $this->sources->scriptStage($projectId, $stageId);

        if ($stage === null) {
            return ['reason' => 'Bản kịch bản này không thuộc dự án hoặc chưa chạy thành công.'] + $report;
        }

        [$evaluation, $problem] = $this->sources->inspect($projectId, $stage);

        if ($problem !== null) {
            return ['reason' => $problem] + $report;
        }

        $document = (array) $evaluation['document'];

        if (($document['format'] ?? null) !== MotoContract::SCRIPT_FORMAT) {
            return ['reason' => 'Sửa tay chỉ áp dụng cho kịch bản '.MotoContract::SCRIPT_FORMAT.'.'] + $report;
        }

        $beats = $patch['beats'] ?? null;
        $ids = array_column((array) $document['data']['beats'], 'id');

        if (! is_string($patch['reason'] ?? null) || trim($patch['reason']) === '' || ! is_array($beats) || $beats === [] || array_is_list($beats)) {
            return ['reason' => 'Bản sửa cần reason và beats theo mã beat.'] + $report;
        }

        foreach ($beats as $id => $fields) {
            if (! in_array($id, $ids, true) || ! is_array($fields) || $fields === [] || array_is_list($fields) || array_diff(array_keys($fields), self::REVISABLE) !== []) {
                return ['reason' => "Bản sửa {$id}: chỉ sửa ".implode(', ', self::REVISABLE).' của một beat có trong kịch bản.'] + $report;
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

        $pinned = (array) (PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null)['source'] ?? []);
        $current = $run['metadata']['source'];

        if (CanonicalJson::hash($pinned) !== CanonicalJson::hash($current)) {
            return ['reason' => "Bản rev {$stage->planning_revision} viết từ thiết kế ".($pinned['design_stage_id'] ?? '—').' và model sheet '.($pinned['model_sheet']['review_stage_id'] ?? '—')
                .'; dự án đang dùng thiết kế '.$current['design_stage_id'].' và model sheet '.($current['model_sheet']['review_stage_id'] ?? '—')
                .' — không chuyển kịch bản sang nguồn khác bằng bản sửa tay; viết lại để có bản mới.'] + $report;
        }

        $report['prompt'] = 'Prompt '.$run['phase']->phase_code.' revision '.$run['phase']->revision;
        $revised = $document;
        $revised['prompt_version'] = $run['prompt_version'];

        foreach ($revised['data']['beats'] as &$beat) {
            $original = $beat;

            foreach ((array) ($beats[$beat['id']] ?? []) as $key => $value) {
                if ($beat[$key] !== $value) {
                    $report['changes'][] = [
                        'path' => "{$beat['id']}.{$key}",
                        'purpose' => (string) $original['purpose'],
                        'visual' => (string) $original['visual'],
                        'before' => (string) json_encode($beat[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'after' => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ];
                    $beat[$key] = $value;
                }
            }
        }

        unset($beat);

        if ($report['changes'] === []) {
            return ['reason' => 'Bản sửa không đổi trường nào của kịch bản.'] + $report;
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
        [$claimed, $token, $claimReason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::SCRIPT, $run['input'] + ['revision_of' => (string) $stage->id, 'patch_hash' => $report['patch_hash']], false, $metadata);

        if ($token === null) {
            return $claimReason === 'already_succeeded'
                ? ['reason' => 'already_applied', 'revision_stage_id' => (string) $claimed->id, 'revision' => (int) $claimed->planning_revision] + $report
                : ['reason' => $claimReason === 'claimed_by_other' ? 'Đang có một lượt viết kịch bản chạy cho dự án này — đợi xong rồi thử lại.' : $claimReason] + $report;
        }

        try {
            $recorded = $this->stageStore->finishSucceeded((string) $claimed->id, $token, $raw, $this->output($run, $result), ['instruction_version' => $run['prompt_version'], 'tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0]);
        } catch (\Throwable $e) {
            $this->stageStore->releaseClaim((string) $claimed->id, 'Bản sửa kịch bản không lưu được');

            throw $e;
        }

        return ['reason' => $recorded ? 'applied' : 'Lượt lưu bị giành mất — tải lại rồi thử lại.', 'revision_stage_id' => (string) $claimed->id, 'revision' => (int) $claimed->planning_revision] + $report;
    }

    /** @return ?array{revision: int, reason: string, changes: list<array<string, string>>} the run a hand revision revises, why and what it changed; null for a run of the model */
    private static function revisionOf(VideoPlanningStage $stage): ?array
    {
        $revision = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null)['revision'] ?? null;

        return is_array($revision) ? ['revision' => (int) $revision['of']['revision'], 'reason' => (string) $revision['reason'], 'changes' => array_values((array) $revision['changes'])] : null;
    }

    /** @return array<string, string> the names of the rooms, zones, parts, systems, features and moments the stage's payload holds */
    private static function places(VideoPlanningStage $stage): array
    {
        $payload = json_decode((string) app(PlanningStageStore::class)->payloadOf($stage), true);
        $lists = [
            (array) ($payload['yacht_bible']['rooms'] ?? []),
            (array) ($payload['yacht_bible']['exterior_zones'] ?? []),
            (array) ($payload['design']['parts'] ?? []),
            (array) ($payload['design']['systems'] ?? []),
            (array) ($payload['design']['features'] ?? []),
            (array) ($payload['design']['motorcycle']['signature_moments'] ?? []),
        ];
        $places = [];

        foreach ($lists as $list) {
            foreach ($list as $place) {
                if (is_array($place) && is_string($place['id'] ?? null)) {
                    $places[$place['id']] = mb_substr((string) ($place['name'] ?? $place['text'] ?? $place['description'] ?? ''), 0, 60);
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
        return $this->stageStore->resetLatest($projectId, PlanningStageName::SCRIPT, 'Chưa có lượt viết kịch bản nào.');
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

        $stages = VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $projectId)->where('stage', PlanningStageName::SCRIPT->value)
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
        $sourceProblem = null;

        if ($supported) {
            $sourceProblem = $shown === null ? null : $this->sources->workflowFor($projectId)['adapter']->sourceProblem($projectId, (array) (PlanningStageStore::metadataOf(is_array($shown->input_json) ? $shown->input_json : null)['source'] ?? []));
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
            'pending' => $latest !== null && $latest->status === VideoPlanningStageStatus::PENDING->value && $latest->claim_token !== null,
            'task' => PlanningStageStore::taskOf($latest),
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
                'revision_of' => PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null)['revision']['of']['revision'] ?? null,
                'cost_usd' => $stage->cost_usd === null ? null : (float) $stage->cost_usd,
            ])->values()->all(),
            'selection' => $selection,
            'selected_revision' => $selection === null || $stages->firstWhere('id', $selection['stage_id']) === null ? null : (int) $stages->firstWhere('id', $selection['stage_id'])->planning_revision,
            'selected_error' => $selected['error'] ?? null,
            'shown' => $shown === null ? null : [
                'id' => (string) $shown->id,
                'revision' => (int) $shown->planning_revision,
                'revised' => self::revisionOf($shown),
                'source_problem' => $sourceProblem,
                'selected' => $selection !== null && $selection['stage_id'] === (string) $shown->id && ! isset($selected['error']),
                'integrity_error' => $integrity,
                'places' => self::places($shown),
                'content_hash' => $integrity === null ? ($evaluation['content_hash'] ?? null) : null,
                'check' => $check,
                'document' => $integrity === null ? ($evaluation['document'] ?? null) : null,
                'approvable' => $check !== null && $check['verdict'] === 'PASS' && $check['status'] === 'ok' && $sourceProblem === null,
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
        $wordLimits = $bundle->wordLimits();

        if ($wordLimits !== []) {
            $inputs['payload']['variables'] = (array) ($inputs['payload']['variables'] ?? []) + ['word_limits' => $wordLimits];
            $inputs['context']['word_limits'] = $wordLimits;
        }

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
