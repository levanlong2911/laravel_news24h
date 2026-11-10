<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\AiPhase;
use App\Models\VideoArtifact;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Models\VideoReviewDecision;
use App\Services\Admin\ClaudeWriterService;
use App\Video\Llm\LlmClient;
use App\Video\Script\ScriptEvaluation;
use App\Video\Superyacht\Spec\PhaseResolver;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

abstract class ShotPromptWriterService implements QueuedStageService
{
    /** @var string the key of the project metadata that holds, by shot plan and shot, the prompt a person approved */
    public const SELECTION = '';

    public const ENTITY_TYPE = '';

    /** @var PlanningStageName the stage every run of the writer is stored as */
    protected const STAGE = PlanningStageName::KEYFRAME_PROMPT;

    /** @var string the key of RunPlanningStageJob::STEPS a queued run is worked under */
    protected const STEP = '';

    /** @var string what the writer does, as the screen and the errors say it */
    protected const LABEL = '';

    /** @var string what the writer writes, as the errors name it */
    protected const NOUN = '';

    /** @var string the task the stage runner logs */
    protected const TASK = '';

    /** @var string the phase the writer belongs to, as the errors name it */
    protected const PHASE = '';

    /** @var string the config key that maps a workflow to its action and adapter */
    protected const WORKFLOWS = '';

    /** @var class-string the adapter every workflow of the writer must use */
    protected const ADAPTER = '';

    /** @var string the field of the derived answer that holds the prompt sent word for word */
    protected const TEXT_KEY = '';

    /** @var array<string, array<string, mixed>> the design, script and phase each project's runs are read from, once for each instance */
    private array $pins = [];

    public function __construct(
        protected readonly PlanningStageStore $stageStore,
        protected readonly LlmClient $llm,
    ) {}

    /**
     * @return array{run: array<string, mixed>, sources: list<array<string, mixed>>, fingerprint: string} the run of one shot without its image bytes, the images it sends and the fingerprint of everything it is written from
     *
     * @throws RuntimeException when it cannot be prepared
     */
    abstract protected function package(string $projectId, string $shotId): array;

    /** @return ?string the approved shot plan the shot belongs to now, null when the shot is not in it */
    abstract protected function shotStageOf(string $projectId, string $shotId): ?string;

    /**
     * @param  ?array{id: string, name: string}  $requester
     * @return array{0: ?VideoPlanningStage, 1: string} reason queued|cached|replayed|ok|requeued, or why nothing ran
     */
    public function run(string $projectId, string $shotId, bool $regenerate, string $operationId, ?array $requester = null): array
    {
        return (new SpecStageRunner($this->stageStore))->submit(
            static::STEP,
            $projectId,
            static::STAGE,
            fn (): array => $this->prepare($projectId, $shotId),
            $regenerate,
            $operationId,
            $this->llm,
            fn (array $run, array $evaluation): array => $this->output($run, $evaluation),
            static::LABEL,
            static::TASK,
            $requester,
        );
    }

    /** @return array{0: ?VideoPlanningStage, 1: string} what the queued run became when a worker took it */
    public function execute(string $stageId, string $token): array
    {
        $stage = VideoPlanningStage::query()->withoutSnapshot()->find($stageId);

        if ($stage === null) {
            return [null, 'Lượt '.static::LABEL.' không còn — bỏ qua.'];
        }

        $projectId = (string) $stage->project_id;
        $shotId = (string) ($stage->input_json['shot_id'] ?? '');

        return (new SpecStageRunner($this->stageStore))->execute(
            $stageId,
            $token,
            fn (): array => $this->prepare($projectId, $shotId),
            $this->llm,
            fn (array $run, array $evaluation): array => $this->output($run, $evaluation),
            static::LABEL,
            static::TASK,
        );
    }

    /** @return array{0: bool, 1: string} */
    public function reset(string $projectId): array
    {
        return $this->stageStore->resetLatest($projectId, static::STAGE, 'Chưa có lượt '.static::LABEL.' nào.');
    }

    /**
     * @param  ?string  $reviewer  who decides
     * @return void the run becomes the prompt its shot renders from; the run approved before it stays in the history
     *
     * @throws RuntimeException when the run is not a checked, usable prompt of the approved shot plan or its sources changed since it was written
     */
    public function approve(string $projectId, string $stageId, ?string $reviewer): void
    {
        DB::transaction(function () use ($projectId, $stageId, $reviewer): void {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $stage = VideoPlanningStage::query()->withoutSnapshot()->whereKey($stageId)->where('project_id', $projectId)
                ->where('stage', static::STAGE->value)->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->first()
                ?? throw new RuntimeException('Lượt viết prompt này không thuộc dự án hoặc chưa chạy xong.');
            $meta = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null);
            $adapter = $this->workflowFor($projectId)['adapter'];
            $evaluation = ScriptEvaluation::of((string) $stage->raw_response, (array) $meta['manifest'], (array) $meta['context'], (array) $meta['source'], $adapter);

            if ($evaluation['content_hash'] === null || ! hash_equals((string) $evaluation['content_hash'], (string) ($stage->output_json['content_hash'] ?? ''))) {
                throw new RuntimeException('Nội dung lượt viết prompt không khớp hash đã lưu — không duyệt được.');
            }

            if (! $adapter::usable($evaluation['check'])) {
                throw new RuntimeException('Prompt chưa qua kiểm hoặc AI còn báo thiếu dữ kiện, mâu thuẫn — không duyệt được.');
            }

            $shotId = (string) $stage->input_json['shot_id'];
            $shotStageId = $this->shotStageOf($projectId, $shotId);

            if ($shotStageId === null || $shotStageId !== (string) $stage->input_json['shot_stage_id']) {
                throw new RuntimeException('Prompt này viết cho một kế hoạch shot khác bản đang duyệt — không duyệt được.');
            }

            $fingerprint = $this->package($projectId, $shotId)['fingerprint'];

            if (! hash_equals($fingerprint, (string) $stage->input_json['source_fingerprint'])) {
                throw new RuntimeException('Nguồn đã đổi — cần viết hoặc duyệt lại '.static::NOUN.' của '.$shotId.'.');
            }

            $prompt = (string) $evaluation['derived'][static::TEXT_KEY];
            $decision = VideoReviewDecision::create([
                'project_id' => $projectId,
                'entity_type' => static::ENTITY_TYPE,
                'entity_id' => (string) $stage->id,
                'decision' => 'approved',
                'revision' => 1 + (int) VideoReviewDecision::query()->where('entity_type', static::ENTITY_TYPE)->where('entity_id', (string) $stage->id)->max('revision'),
                'reviewer_id' => $reviewer,
                'metadata_json' => ['shot_id' => $shotId, 'shot_stage_id' => $shotStageId, 'source_fingerprint' => $fingerprint, 'prompt_sha256' => CanonicalJson::hashString($prompt)],
            ]);
            $metadata = $project->metadata_json ?? [];
            $metadata[static::SELECTION][$shotStageId][$shotId] = [
                'stage_id' => (string) $stage->id,
                'decision_id' => (string) $decision->id,
                'prompt_sha256' => CanonicalJson::hashString($prompt),
                'source_fingerprint' => $fingerprint,
                'approved_at' => now()->toIso8601String(),
            ];
            $project->forceFill(['metadata_json' => $metadata])->save();
        });
    }

    /**
     * @return array<string, array{stage_id: string, decision_id: string, text: string, sha256: string, version: string, fingerprint: string}> the approved prompt of every shot of this shot plan, by shot id; one whose run, decision or text no longer matches is left out, so its shot has none
     */
    public static function selected(string $projectId, string $shotStageId): array
    {
        $selection = (array) (VideoProject::query()->whereKey($projectId)->value('metadata_json')[static::SELECTION][$shotStageId] ?? []);
        $stages = $selection === [] ? collect() : VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $projectId)
            ->where('stage', static::STAGE->value)->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->whereIn('id', array_column($selection, 'stage_id'))->get()->keyBy('id');
        $decisions = $selection === [] ? collect() : VideoReviewDecision::query()->whereIn('id', array_column($selection, 'decision_id'))
            ->where('entity_type', static::ENTITY_TYPE)->where('decision', 'approved')->get()->keyBy('id');
        $result = [];

        foreach ($selection as $shotId => $entry) {
            $stage = $stages->get($entry['stage_id'] ?? null);
            $decision = $decisions->get($entry['decision_id'] ?? null);
            $text = (string) ($stage?->output_json['derived'][static::TEXT_KEY] ?? '');

            if ($stage === null || $decision === null || (string) $decision->entity_id !== (string) $stage->id || $text === ''
                || ! hash_equals((string) ($entry['prompt_sha256'] ?? ''), CanonicalJson::hashString($text))
                || (string) ($stage->input_json['shot_id'] ?? '') !== (string) $shotId) {
                continue;
            }

            $result[(string) $shotId] = [
                'stage_id' => (string) $stage->id,
                'decision_id' => (string) $decision->id,
                'text' => $text,
                'sha256' => CanonicalJson::hashString($text),
                'version' => (string) ($stage->output_json['prompt_version'] ?? $stage->instruction_version ?? ''),
                'fingerprint' => (string) $entry['source_fingerprint'],
            ];
        }

        return $result;
    }

    /**
     * @param  ?array<string, mixed>  $selected  the approved prompt of the shot, selected()
     * @param  \Closure(): array<string, mixed>  $package  package() of the shot, read with what the caller has already read
     * @return array<string, mixed> what the prompt column shows for the shot: the approved prompt and whether its sources still hold, the newest run and the run shown with its check, its history, what a new run sends and costs, and why one cannot be sent; reading it writes nothing
     */
    protected function promptScreen(string $projectId, string $shotStageId, string $shotId, ?array $selected, \Closure $package): array
    {
        $fingerprint = null;
        $unavailable = null;
        $estimate = null;

        try {
            $prepared = $package();
            $fingerprint = $prepared['fingerprint'];
            $estimate = $this->estimate($prepared);
        } catch (RuntimeException $e) {
            $unavailable = $e->getMessage();
        }

        $adapter = static::ADAPTER;
        $stages = $this->stagesOf($projectId, $shotStageId, $shotId);
        $latest = $stages[0] ?? null;
        $succeeded = array_values(array_filter($stages, static fn (VideoPlanningStage $stage): bool => $stage->status === VideoPlanningStageStatus::SUCCEEDED->value));
        $shown = $succeeded[0] ?? null;
        $check = (array) ($shown?->output_json['check'] ?? []);
        $document = (array) ($shown?->output_json['document'] ?? []);
        $current = static fn (VideoPlanningStage $stage): bool => $fingerprint !== null && hash_equals($fingerprint, (string) ($stage->input_json['source_fingerprint'] ?? ''));

        return [
            'selected' => $selected,
            'stale' => $selected !== null && ($fingerprint === null || ! hash_equals($fingerprint, (string) $selected['fingerprint'])),
            'unavailable' => $unavailable,
            'can_write' => $unavailable === null && ! ($latest !== null && in_array($latest->status, [VideoPlanningStageStatus::RUNNING->value, VideoPlanningStageStatus::PENDING->value], true)),
            'estimate' => $estimate,
            'operation_id' => (string) Str::uuid(),
            'running' => $latest !== null && $latest->status === VideoPlanningStageStatus::RUNNING->value && $latest->lease_expires_at?->isFuture() === true,
            'pending' => $latest !== null && $latest->status === VideoPlanningStageStatus::PENDING->value && $latest->claim_token !== null,
            'task' => PlanningStageStore::taskOf($latest),
            'stream' => PlanningStageStore::streamOf($latest),
            'error' => $latest !== null && $latest->status === VideoPlanningStageStatus::FAILED->value ? $latest->error_message : null,
            'shown' => $shown === null ? null : [
                'id' => (string) $shown->id,
                'revision' => (int) $shown->planning_revision,
                'prompt' => (string) ($shown->output_json['derived'][static::TEXT_KEY] ?? ($document[static::TEXT_KEY] ?? '')),
                'status' => $document['status'] ?? null,
                'verdict' => $check['verdict'] ?? null,
                'issues' => array_values((array) ($check['issues'] ?? [])),
                'schema' => array_values((array) ($check['schema'] ?? [])),
                'error' => $check['error'] ?? null,
                'gaps' => array_values((array) ($document['gaps'] ?? [])),
                'conflicts' => array_values((array) ($document['conflicts'] ?? [])),
                'flags' => array_values((array) ($document['flags'] ?? [])),
                'source_refs' => (array) ($document['source_refs'] ?? []),
                'current' => $current($shown),
                'selected' => $selected !== null && $selected['stage_id'] === (string) $shown->id,
                'approvable' => $adapter::usable($check) && $current($shown) && ($selected === null || $selected['stage_id'] !== (string) $shown->id),
                'model' => $shown->model,
                'tokens' => ['in' => (int) $shown->tokens_in, 'out' => (int) $shown->tokens_out],
                'cost_usd' => $shown->cost_usd === null ? null : (float) $shown->cost_usd,
            ],
            'history' => array_map(static fn (VideoPlanningStage $stage): array => [
                'id' => (string) $stage->id,
                'revision' => (int) $stage->planning_revision,
                'status' => (string) $stage->status,
                'verdict' => $stage->output_json['check']['verdict'] ?? null,
                'model_status' => $stage->output_json['document']['status'] ?? null,
                'selected' => $selected !== null && $selected['stage_id'] === (string) $stage->id,
                'current' => $current($stage),
                'cost_usd' => $stage->cost_usd === null ? null : (float) $stage->cost_usd,
            ], $stages),
        ];
    }

    /**
     * @param  array<string, mixed>  $flow  workflowFor()
     * @param  array<string, mixed>  $pins  pins()
     * @param  array{payload: array<string, mixed>, context: array<string, mixed>}  $inputs  what the adapter gives the model and the check
     * @param  array<string, mixed>  $source  what the run is written from, without the hash of its input
     * @return array{fingerprint: string, run: array<string, mixed>} the run of one shot and the fingerprint of everything it is written from
     *
     * @throws RuntimeException when the pinned contract does not match the code that writes the run
     */
    protected function runOf(string $projectId, string $shotStageId, string $shotId, array $flow, array $pins, array $inputs, array $source): array
    {
        $adapter = $flow['adapter'];
        $settings = $adapter->settings();
        $source['input_sha256'] = CanonicalJson::hash(array_diff_key($inputs['payload'], ['source_fingerprint' => true]));
        $fingerprint = CanonicalJson::hash($source);
        $inputs['payload']['source_fingerprint'] = $fingerprint;
        $inputs['context']['source_fingerprint'] = $fingerprint;
        $promptVersion = "{$flow['workflow']}/{$pins['phase']->phase_code}@rev{$pins['phase']->revision}";
        $payload = (string) json_encode(['project_id' => $projectId, 'prompt_version' => $promptVersion] + $inputs['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $checkContext = ['project_id' => $projectId, 'prompt_version' => $promptVersion] + $inputs['context'];

        if (($problem = $adapter->contextProblem($checkContext, ScriptEvaluation::contract($pins['manifest']))) !== null) {
            throw new RuntimeException("Hợp đồng {$flow['action']} không khớp code ".static::LABEL.": {$problem} — phát hành gói ".static::PHASE.' tương thích trước, không gọi model.');
        }

        return [
            'fingerprint' => $fingerprint,
            'run' => [
                'adapter' => $adapter,
                'phase' => $pins['phase'],
                'system' => $pins['system'],
                'payload' => $payload,
                'prompt_version' => $promptVersion,
                'settings' => $settings,
                'input' => [
                    'execution' => $settings['execution'],
                    'workflow' => $flow['workflow'],
                    'category_id' => $flow['category_id'],
                    'bundle_hash' => $pins['bundle_hash'],
                    'phase_id' => (string) $pins['phase']->id,
                    'phase_revision' => (int) $pins['phase']->revision,
                    'prompt_sha256' => (string) $pins['phase']->content_sha256,
                    'shot_stage_id' => $shotStageId,
                    'shot_id' => $shotId,
                    'source_fingerprint' => $fingerprint,
                    'source' => $source,
                    'payload_sha256' => hash('sha256', $payload),
                    'model' => $settings['model'],
                    'effort' => $settings['effort'],
                    'max_tokens' => $settings['max_tokens'],
                    'check_version' => $adapter->checkVersion(),
                    'inputs_version' => $adapter->inputsVersion(),
                ],
                'metadata' => [
                    'manifest' => $pins['manifest'],
                    'source' => $source,
                    'context' => $checkContext,
                    'payload' => $payload,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed> the run of one shot, with the images it sends
     *
     * @throws RuntimeException when the contract, the prompt, the model, the shot or one of its sources is not ready; no model is called
     */
    private function prepare(string $projectId, string $shotId): array
    {
        $package = $this->package($projectId, $shotId);
        [$images, $sent] = $this->images($package['sources'], $package['run']['settings']);

        return $package['run'] + ['images' => $images, 'images_sent' => $sent];
    }

    /**
     * @param  array<string, mixed>  $package  package()
     * @return array{model: string, effort: string, images: int, input_tokens: int, text: string} what a run of the shot sends and about what it costs; the image tokens follow the size each image is sent at
     */
    private function estimate(array $package): array
    {
        $settings = $package['run']['settings'];
        $artifacts = VideoArtifact::query()->whereIn('id', array_column($package['sources'], 'artifact_id'))->get(['id', 'width', 'height'])->keyBy('id');
        $imageTokens = 0;

        foreach ($package['sources'] as $source) {
            $artifact = $artifacts->get($source['artifact_id']);
            [$width, $height] = self::sentSize((int) ($artifact?->width ?? 0), (int) ($artifact?->height ?? 0), (int) $settings['image_max_edge']);
            $imageTokens += (int) ceil($width * $height / 750);
        }

        $input = (int) ceil((mb_strlen((string) $package['run']['system']) + mb_strlen((string) $package['run']['payload'])) / 3.5) + $imageTokens;
        $output = (int) $settings['estimate_output_tokens'];

        return [
            'model' => (string) $settings['model'],
            'effort' => (string) $settings['effort'],
            'images' => count($package['sources']),
            'input_tokens' => $input,
            'text' => '≈ $'.number_format(ClaudeWriterService::costUsd($input, $output, (string) $settings['model']), 3)
                .', tối đa ≈ $'.number_format(ClaudeWriterService::costUsd($input, (int) $settings['max_tokens'], (string) $settings['model']), 3)
                .' (≈ '.number_format($input).' token vào, gồm '.number_format($imageTokens).' token của '.count($package['sources']).' ảnh)',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sources  the images the render sends, in order
     * @param  array<string, mixed>  $settings
     * @return array{0: list<array{label: string, media_type: string, data: string}>, 1: list<array<string, mixed>>} the images the model reads, each the approved file checked against its hash and made smaller when it is larger than the model takes, and what was sent of each
     *
     * @throws RuntimeException when a file is gone, changed or unreadable
     */
    private function images(array $sources, array $settings): array
    {
        $artifacts = VideoArtifact::query()->whereIn('id', array_column($sources, 'artifact_id'))->get()->keyBy('id');
        $images = [];
        $sent = [];

        foreach ($sources as $source) {
            $code = (string) $source['image_code'];
            $artifact = $artifacts->get($source['artifact_id']) ?? throw new RuntimeException("Ảnh tham chiếu {$code} không còn.");
            $bytes = Storage::disk((string) $artifact->storage_disk)->get((string) $artifact->storage_path);

            if (! is_string($bytes) || ! hash_equals((string) $source['sha256'], hash('sha256', $bytes)) || ! hash_equals((string) $artifact->sha256, hash('sha256', $bytes))) {
                throw new RuntimeException("File ảnh {$code} không còn hoặc khác hash đã chọn — không gửi.");
            }

            [$data, $mediaType, $width, $height] = self::forModel($bytes, $settings, $code);
            $position = (int) $source['position'] + 1;
            $images[] = ['label' => "Image {$position}", 'media_type' => $mediaType, 'data' => base64_encode($data)];
            $sent[] = ['position' => $position, 'artifact_id' => (string) $artifact->id, 'sha256' => (string) $artifact->sha256, 'sent_sha256' => hash('sha256', $data), 'media_type' => $mediaType, 'width' => $width, 'height' => $height, 'bytes' => strlen($data)];
        }

        return [$images, $sent];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{0: string, 1: string, 2: int, 3: int} the bytes the model reads, their type and size: the file itself when it fits, else a JPEG whose longest edge is the configured one
     *
     * @throws RuntimeException when the file is not an image the model reads or stays too large
     */
    private static function forModel(string $bytes, array $settings, string $code): array
    {
        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';

        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new RuntimeException("Ảnh {$code} không phải PNG, JPEG hoặc WebP.");
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $maxBytes = (int) $settings['image_max_bytes'];
        [$sentWidth, $sentHeight] = self::sentSize($width, $height, (int) $settings['image_max_edge']);

        if ($sentWidth === $width && strlen($bytes) <= $maxBytes) {
            return [$bytes, $mime, $width, $height];
        }

        $image = @imagecreatefromstring($bytes) ?: throw new RuntimeException("Không đọc được ảnh {$code} để thu nhỏ.");
        $small = imagecreatetruecolor($sentWidth, $sentHeight);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $sentWidth, $sentHeight, $width, $height);
        ob_start();
        imagejpeg($small, null, (int) $settings['image_jpeg_quality']);
        $data = (string) ob_get_clean();

        if (strlen($data) > $maxBytes) {
            throw new RuntimeException("Ảnh {$code} vẫn lớn hơn giới hạn gửi model sau khi thu nhỏ.");
        }

        return [$data, 'image/jpeg', $sentWidth, $sentHeight];
    }

    /** @return array{0: int, 1: int} the size an image is sent at: its own, or scaled so its longest edge is the limit */
    private static function sentSize(int $width, int $height, int $maxEdge): array
    {
        $edge = max($width, $height);

        if ($edge <= $maxEdge || $edge === 0) {
            return [$width, $height];
        }

        return [max(1, (int) round($width * $maxEdge / $edge)), max(1, (int) round($height * $maxEdge / $edge))];
    }

    /** @return list<VideoPlanningStage> every run of the writer for the shot in this shot plan, newest first */
    protected function stagesOf(string $projectId, string $shotStageId, string $shotId): array
    {
        return VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $projectId)
            ->where('stage', static::STAGE->value)->where('input_json->shot_stage_id', $shotStageId)
            ->where('input_json->shot_id', $shotId)->orderByDesc('planning_revision')->get()->all();
    }

    /**
     * @return array{workflow: string, category_id: string, action: string, adapter: object}
     *
     * @throws RuntimeException when the project's topic has no workflow for the writer
     */
    protected function workflowFor(string $projectId): array
    {
        $project = VideoProject::query()->with('article.category')->findOrFail($projectId);
        $category = $project->article?->category ?? throw new RuntimeException('Dự án không gắn bài viết có chủ đề — '.static::PHASE.' không áp dụng.');
        $workflow = (string) (config('video.superyacht.contracts.categories')[$category->slug] ?? '');
        $entry = config(static::WORKFLOWS)[$workflow] ?? null;

        if (! is_array($entry)) {
            throw new RuntimeException("Chủ đề {$category->slug} chưa hỗ trợ ".static::PHASE.'.');
        }

        $adapter = app((string) $entry['adapter']);
        $class = static::ADAPTER;

        if (! $adapter instanceof $class) {
            throw new RuntimeException('Adapter '.static::PHASE." của workflow {$workflow} không hợp lệ.");
        }

        return ['workflow' => $workflow, 'category_id' => (string) $category->id, 'action' => (string) $entry['action'], 'adapter' => $adapter];
    }

    /**
     * @param  array<string, mixed>  $flow  workflowFor()
     * @return array<string, mixed> the phase, bundle and system text of the runs, and the design and script they are written from, read once for each project
     *
     * @throws RuntimeException when the contract, the prompt, the model or the sources are not ready
     */
    protected function pins(string $projectId, array $flow): array
    {
        if (isset($this->pins[$projectId])) {
            return $this->pins[$projectId];
        }

        try {
            $bundle = PhaseResolver::fromConfig((array) config('video.superyacht'))->forProject($projectId, $flow['action']);
        } catch (RuntimeException $e) {
            throw new RuntimeException("Hợp đồng {$flow['action']} chưa sẵn sàng: {$e->getMessage()} — chạy php artisan db:seed --class=AiPhaseSeeder trước.", 0, $e);
        }

        if (! $bundle->ready()) {
            throw new RuntimeException("Hợp đồng {$flow['action']} chưa sẵn sàng: ".implode('; ', $bundle->mismatches()).' — chạy php artisan db:seed --class=AiPhaseSeeder trước.');
        }

        $manifest = $bundle->manifest();
        $phase = AiPhase::query()->findOrFail($manifest['phase']['id']);
        $adapter = $flow['adapter'];

        if (! str_contains((string) $phase->prompt_content, $adapter->format()) || ! str_contains((string) $phase->prompt_content, "Input format: `{$adapter->inputsVersion()}`")) {
            throw new RuntimeException("Prompt {$phase->phase_code} revision {$phase->revision} không khớp định dạng {$adapter->format()} / đầu vào {$adapter->inputsVersion()} code đang dựng — phát hành revision tương thích trước, không gọi model.");
        }

        if (! ClaudeWriterService::supports($adapter->settings()['model'])) {
            throw new RuntimeException('Model '.static::LABEL.' '.$adapter->settings()['model'].' không có trong ClaudeWriterService::MODEL_CATALOG.');
        }

        $script = app(ScriptSourceService::class)->approvedSource($projectId);
        $scriptStage = VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $projectId)->findOrFail($script['stage_id']);
        $pin = (array) (PlanningStageStore::metadataOf(is_array($scriptStage->input_json) ? $scriptStage->input_json : null)['source'] ?? []);
        $design = VideoPlanningStage::query()->where('project_id', $projectId)->find($pin['design_stage_id'] ?? null);
        $raw = (string) $design?->raw_response;

        if ($design === null || ! hash_equals((string) ($pin['design_raw_sha256'] ?? ''), hash('sha256', $raw))) {
            throw new RuntimeException('Thiết kế mà kịch bản đã duyệt ghim không còn hoặc đã đổi — không '.static::LABEL.'.');
        }

        return $this->pins[$projectId] = [
            'phase' => $phase,
            'manifest' => $manifest,
            'bundle_hash' => $bundle->bundleHash(),
            'system' => $adapter->systemText(array_column($bundle->parts(), 'content'), $bundle->outputSchemas()),
            'script' => (array) $script['document']['data'],
            'script_token' => ['stage_id' => (string) $script['token']['stage_id'], 'content_hash' => (string) $script['token']['content_hash']],
            'design' => (array) (json_decode($raw, true)['data'] ?? []),
            'design_pin' => ['stage_id' => (string) $design->id, 'raw_sha256' => hash('sha256', $raw)],
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
            'prompt_version' => $run['prompt_version'],
            'shot_id' => $run['input']['shot_id'],
            'source' => $run['metadata']['source'],
            'source_fingerprint' => $run['input']['source_fingerprint'],
            'images_sent' => $run['images_sent'] ?? [],
            'json_parse_error' => $evaluation['parse_error'],
            'document' => $evaluation['document'],
            'derived' => $evaluation['derived'],
            'check' => $evaluation['check'],
            'content_hash' => $evaluation['content_hash'],
        ];
    }
}
