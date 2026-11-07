<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\AiPhase;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Llm\JsonAnswer;
use App\Video\Llm\LlmClient;
use App\Video\ModelSheet\ModelSheetMaster;
use App\Video\Moto\MotoContract;
use App\Video\Superyacht\Spec\PhaseResolver;
use App\Video\YachtDesign\DesignProse;
use App\Video\YachtDesign\DesignRunPolicy;
use App\Video\YachtDesign\DesignWorkflow;
use App\Video\YachtDesign\SuperyachtDesign;
use App\Video\YachtDesign\YachtDesignCheck;
use Illuminate\Support\Str;
use RuntimeException;

final class YachtDesignService implements QueuedStageService
{
    public const EXECUTION = 'db-phase-3-v2';

    public const PHASE_CODE = 'phase-3';

    public const ACTION = 'spec.design';

    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly VideoProjectRepositoryInterface $projects,
        private readonly DesignDnaService $dna,
        private readonly LlmClient $writer,
        private readonly DesignApprovalLock $approval,
    ) {}

    /**
     * @param  ?array{id: string, name: string}  $requester  who sent the run
     * @return array{0: ?VideoPlanningStage, 1: string} reason queued when a worker runs it later
     */
    public function run(string $projectId, bool $regenerate, string $operationId, ?array $requester = null): array
    {
        return (new StageQueue($this->stageStore))->submit(
            'design',
            $projectId,
            PlanningStageName::YACHT_DESIGN,
            fn (): array => $this->prepare($this->project($projectId)),
            fn (VideoPlanningStage $served, array $run): bool => $this->mayReplace($served, $run, $regenerate),
            $operationId,
            $requester,
            $this->perform(),
            'thiết kế',
            'yacht design',
            fn (?VideoPlanningStage $stage, string $reason, array $run): ?array => $reason === 'already_succeeded' && $stage !== null && $this->replaceable($stage, $run)
                ? [null, 'Bản thiết kế đã lưu cho đúng DNA và prompt này chưa đạt kiểm RULE hoặc còn cảnh báo — bấm "Thiết kế lại" nếu muốn tạo bản mới. Không gọi model.']
                : null,
            'Chưa gọi model thiết kế: ',
            fn (array $run): ?string => $this->refusal($projectId, $run, $regenerate),
        );
    }

    /**
     * @param  array{input: array<string, mixed>, payload: string}  $run  the prepared run
     * @param  bool  $regenerate  the person chose the paid action that replaces a kept design
     * @return ?string why this run may not start; null leaves it to the claim, which serves a valid design of the same input as it is
     */
    public function refusal(string $projectId, array $run, bool $regenerate): ?string
    {
        $decision = $this->decision($projectId, $run);

        return match (true) {
            $decision['status'] === DesignRunPolicy::LOCKED => DesignApprovalLock::MESSAGE,
            $decision['create'], $decision['status'] === DesignRunPolicy::CURRENT => null,
            $decision['regenerate'] => $regenerate ? null : 'Bản thiết kế đang có vẫn được giữ — bấm "Thiết kế lại (tính phí)" nếu muốn tạo bản mới.',
            default => 'Bản thiết kế đang có vẫn dùng được; thay đổi cấu hình chỉ áp dụng cho lượt sau.',
        };
    }

    /** @param array<string, mixed> $run */
    public function mayReplace(VideoPlanningStage $served, array $run, bool $regenerate): bool
    {
        return $regenerate && $this->replaceable($served, $run);
    }

    /**
     * What the stored design and the prepared run allow, from the rows as they are now: no model call and no write.
     *
     * @param  ?array{input: array<string, mixed>, payload: string}  $run
     * @return array{design: ?VideoPlanningStage, locked: bool, exact: bool, integrity_error: ?string, changes: array{design: list<string>, next_sheet: list<string>, next_run: list<string>, payload_unread: bool}, history_unverified: bool, evaluated: ?array{0: ?object, 1: array<string, mixed>}, create: bool, regenerate: bool, status: string}
     */
    public function decision(string $projectId, ?array $run): array
    {
        return $this->decisionFor($projectId, $run, self::workflowOf($this->projects->getById($projectId)), false);
    }

    /**
     * @param  ?array<string, mixed>  $run
     * @param  bool  $live  check a design of the current input again with the current code, as the page does; the claim leaves that to its force closure
     * @return array{design: ?VideoPlanningStage, locked: bool, exact: bool, integrity_error: ?string, changes: array{design: list<string>, next_sheet: list<string>, next_run: list<string>, payload_unread: bool}, history_unverified: bool, evaluated: ?array{0: ?object, 1: array<string, mixed>}, create: bool, regenerate: bool, status: string}
     */
    private function decisionFor(string $projectId, ?array $run, string $workflow, bool $live): array
    {
        $design = $this->approval->currentDesign($projectId);
        $locked = $design !== null && $this->approval->approves($projectId, $design);
        $exact = $design !== null && $run !== null && hash_equals((string) $design->input_hash, $this->stageStore->inputHash($run['input']));
        $integrity = $design === null ? null : $this->approval->integrityProblem($projectId, $design, $workflow);
        $check = $design !== null && is_array($design->output_json['check'] ?? null) ? $design->output_json['check'] : [];
        $usable = $integrity === null && ($check['verdict'] ?? null) === YachtDesignCheck::PASS;
        $clean = ($check['warnings'] ?? []) === [];
        $evaluated = null;

        if ($exact && $live && ! $locked) {
            $evaluated = $this->evaluate((string) ($design->output_json['raw_text'] ?? ''), $run, self::manifestOf($design));
            $clean = $evaluated[1]['verdict'] === YachtDesignCheck::PASS && $evaluated[1]['warnings'] === [];
            $usable = $integrity === null && $clean;
        }

        $stored = $design === null || $exact ? null : self::payloadOf($this->stageStore->snapshotOf((string) $design->id));
        $changes = $design === null || $run === null ? DesignRunPolicy::NO_CHANGES : DesignRunPolicy::changes(is_array($design->input_json) ? $design->input_json : [], $run['input'], $stored, (string) $run['payload']);

        return [
            'design' => $design,
            'locked' => $locked,
            'exact' => $exact,
            'integrity_error' => $integrity,
            'changes' => $changes,
            'history_unverified' => $design !== null && ! $exact && $stored === null,
            'evaluated' => $evaluated,
        ] + DesignRunPolicy::decide($run !== null, $design !== null, $locked, $exact, $usable, $clean, $changes);
    }

    private static function workflowOf(?VideoProject $project): string
    {
        return (string) (config('video.superyacht.contracts.categories')[$project?->article?->category?->slug ?? ''] ?? '');
    }

    /** @return ?string the payload a stored request snapshot kept */
    private static function payloadOf(?string $snapshot): ?string
    {
        $decoded = $snapshot === null ? null : json_decode($snapshot, true);

        return is_array($decoded) && is_string($decoded['payload'] ?? null) ? $decoded['payload'] : null;
    }

    /** @return array{0: ?VideoPlanningStage, 1: string} what the queued run became when a worker took it */
    public function execute(string $stageId, string $token): array
    {
        $projectId = VideoPlanningStage::query()->whereKey($stageId)->value('project_id');

        if (! is_string($projectId)) {
            return [null, 'Lượt thiết kế không còn — bỏ qua.'];
        }

        return (new StageQueue($this->stageStore))->execute(
            $stageId,
            $token,
            function () use ($projectId): array {
                if ($this->approval->holds($projectId)) {
                    throw new RuntimeException(DesignApprovalLock::MESSAGE);
                }

                return $this->prepare($this->project($projectId));
            },
            $this->perform(),
            'thiết kế',
            'yacht design',
            notCalled: 'Chưa gọi model thiết kế: ',
        );
    }

    /** @return \Closure(VideoPlanningStage, string, array<string, mixed>, ?\Closure(\Throwable): ?array): array{0: ?VideoPlanningStage, 1: string} */
    private function perform(): \Closure
    {
        return (new StageQueue($this->stageStore))->singleCall($this->writer, PlanningStageName::YACHT_DESIGN, fn (array $run, string $raw): array => $this->output($run, $raw), 'thiết kế', 'yacht design');
    }

    private function project(string $projectId): VideoProject
    {
        $project = $this->projects->getById($projectId);

        return $project !== null && $project->article !== null ? $project : throw new RuntimeException('Không tìm thấy dự án hoặc dự án không gắn bài viết.');
    }

    /**
     * @param  ?array<string, mixed>  $dnaPanel  the DNA panel this page already built, so it is not built again
     * @return array<string, mixed>
     */
    public function panel(string $projectId, ?array $dnaPanel = null): array
    {
        $project = $this->projects->getById($projectId);
        $run = null;
        $runError = null;

        try {
            if ($project === null) {
                throw new RuntimeException('Không tìm thấy dự án.');
            }

            $run = $this->prepare($project, $dnaPanel);
        } catch (\Throwable $e) {
            $runError = $e->getMessage();
        }

        [$latest] = $this->stageStore->latestStageForProject($projectId, PlanningStageName::YACHT_DESIGN, $run['input'] ?? [], skipOrphans: true);
        $workflow = self::workflowOf($project);
        $decision = $this->decisionFor($projectId, $run, $workflow, true);
        $empty = ['stage_id' => null, 'analysed' => false, 'status' => null, 'running' => false, 'pending' => false, 'stuck' => false, 'error' => null, 'task' => null,
            'current' => false, 'dna_stale' => false, 'check' => null, 'name' => null, 'loa' => null, 'yacht_anchor' => null, 'prose' => null, 'json_error' => null];
        $base = [
            'moto' => MotoContract::is($workflow),
            'can_run' => $decision['create'],
            'can_regenerate' => $decision['regenerate'],
            'locked' => $decision['locked'],
            'input_matches' => $decision['exact'],
            'changes' => $decision['changes'],
            'history_unverified' => $decision['history_unverified'],
            'integrity_error' => $decision['integrity_error'],
            'blocked_reason' => match ($decision['status']) {
                DesignRunPolicy::LOCKED => 'Bản thiết kế này đã duyệt cùng model sheet',
                DesignRunPolicy::KEPT => 'Bản thiết kế đang có vẫn dùng được; thay đổi cấu hình chỉ áp dụng cho lượt sau',
                DesignRunPolicy::CURRENT => 'Đã có bản thiết kế đạt kiểm RULE cho đúng DNA và prompt hiện tại',
                default => $runError,
            },
            'prompt' => $run === null || $decision['locked'] ? null : self::label((int) $run['prompt']->revision),
            'run_model' => $run === null ? null : $run['settings']['model'].' (effort '.$run['settings']['effort'].')',
            'last_cost_usd' => $decision['design']?->cost_usd === null ? null : (float) $decision['design']->cost_usd,
            'run_error' => $decision['locked'] ? null : $runError,
            'operation_id' => (string) Str::uuid(),
        ];

        if ($latest === null) {
            return $base + $empty;
        }

        $claimed = $latest->status === VideoPlanningStageStatus::RUNNING->value;
        // Noi dung hien thi: ban thiet ke thanh cong moi nhat, doc tu output da luu. Prompt Phase 3
        // len revision moi chi doi luot Create design moi; khong an noi dung ban da chay. Mot luot
        // moi hon bi loi hoac dang chay cung khong thay noi dung ban da co.
        $shown = $decision['design'];
        $succeeded = $shown !== null;
        $stored = $succeeded && is_array($shown->output_json) ? $shown->output_json : [];
        $current = $decision['exact'];
        [$filled, $check] = $current
            ? ($decision['evaluated'] ?? $this->evaluate((string) ($stored['raw_text'] ?? ''), $run, self::manifestOf($shown)))
            : [null, is_array($stored['check'] ?? null) ? $stored['check'] : null];
        $data = is_object($filled)
            ? json_decode((string) json_encode($filled->data ?? null), true)
            : (is_array($stored['design']['data'] ?? null) ? $stored['design']['data'] : null);
        [, $parseError] = $succeeded ? JsonAnswer::parse((string) ($stored['raw_text'] ?? '')) : [null, null];

        return $base + [
            'stage_id' => $succeeded ? (string) $shown->id : null,
            'analysed' => $succeeded,
            'status' => $latest->status,
            'running' => $claimed && $latest->lease_expires_at?->isFuture() === true,
            'pending' => $latest->status === VideoPlanningStageStatus::PENDING->value,
            'stuck' => $claimed && $latest->lease_expires_at?->isFuture() !== true,
            'error' => $latest->status === VideoPlanningStageStatus::FAILED->value ? $latest->error_message : null,
            'task' => PlanningStageStore::taskOf($latest),
            'current' => $current,
            // Chi DNA nguon doi moi lam ban thiet ke cu; prompt Phase 3 doi revision thi khong.
            'dna_stale' => $succeeded && $run !== null && ($shown->input_json['dna_stage_id'] ?? null) !== $run['input']['dna_stage_id'],
            'check' => $check,
            'name' => is_array($data) ? ($data['yacht_bible']['name'] ?? $data['vessel']['name'] ?? $data['motorcycle']['name'] ?? null) : null,
            'loa' => is_array($data) ? ($data['yacht_bible']['specs']['loa']['value'] ?? $data['vessel']['specs']['loa']['value'] ?? null) : null,
            'yacht_anchor' => is_array($data) ? ($data['anchors']['yacht'] ?? null) : null,
            'prose' => is_array($data) ? DesignProse::text($data) : null,
            'json_error' => $parseError,
        ];
    }

    /** @return array{0: bool, 1: string} reason pending when a queued run was cancelled */
    public function reset(string $projectId): array
    {
        return $this->stageStore->resetLatest($projectId, PlanningStageName::YACHT_DESIGN, 'Chưa có lượt thiết kế nào.');
    }

    public static function instructionVersion(AiPhase $prompt): string
    {
        return "spec-v6.3-phase-3@rev{$prompt->revision}";
    }

    /** @return array{prompt: AiPhase, system: string, manifest: array<string, mixed>, payload: string, prompt_version: string, settings: array<string, mixed>, input: array<string, mixed>, metadata: array<string, mixed>, dna: array<mixed>, source_identity: array<mixed>, project_id: string, model_sheet?: array<string, mixed>, adapter?: DesignWorkflow} */
    private function prepare(VideoProject $project, ?array $dnaPanel = null): array
    {
        $dnaPanel ??= $this->dna->panel((string) $project->id);

        if (! $dnaPanel['phase3_ready'] || ! is_string($dnaPanel['stage_id'])) {
            throw new RuntimeException('Design DNA chưa đạt kiểm kỹ thuật cho đúng Phase 1 và prompt hiện tại.');
        }

        $dnaStage = VideoPlanningStage::query()->findOrFail($dnaPanel['stage_id']);
        $dna = JsonAnswer::object((string) ($dnaStage->output_json['raw_text'] ?? ''))['dna'] ?? null;
        // A DNA that is phase3_ready ran on the current Phase 1 run: its input names that run, and it was read just now.
        $phaseOne = VideoPlanningStage::query()->find($dnaStage->input_json['inspiration_stage_id'] ?? null);
        $source = PhaseOneEvidence::inspiration(JsonAnswer::object((string) ($phaseOne?->output_json['raw_text'] ?? '')));
        $identity = $source['source_identity'] ?? null;

        if (! is_array($dna) || ! is_array($identity)) {
            throw new RuntimeException('Không đọc được dna hoặc source_identity đã đạt.');
        }

        $category = $project->article?->category ?? throw new RuntimeException('Bài viết của dự án không có chủ đề.');
        $workflow = (string) (config('video.superyacht.contracts.categories')[$category->slug] ?? '');
        $adapter = self::adapter($workflow);
        $bundle = PhaseResolver::fromConfig((array) config('video.superyacht'))->forProject((string) $project->id, self::ACTION);

        if (! $bundle->ready()) {
            throw new RuntimeException('Hợp đồng '.self::ACTION.' chưa sẵn sàng: '.implode('; ', $bundle->mismatches()).'.');
        }

        $manifest = $bundle->manifest();
        $prompt = AiPhase::query()->findOrFail($manifest['phase']['id']);

        foreach ($adapter->markers() as $marker) {
            if (! str_contains((string) $prompt->prompt_content, $marker)) {
                throw new RuntimeException("Prompt phase-3 revision {$prompt->revision} không khai {$marker} mà code đang dùng — phát hành revision tương thích trước khi thiết kế, không gọi model.");
            }
        }

        $modelSheet = self::modelSheet($adapter->modelSheetConfig());
        $payload = (string) json_encode(
            $adapter->payload((string) $project->id, self::instructionVersion($prompt), $dna, $source, $dnaPanel, $modelSheet),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $heading = (string) config('video.superyacht.design.schema_heading');
        $schemas = array_map(
            static fn (array $schema): string => "## {$heading} — {$schema['label']}\n\n```json\n".$adapter->schemaText((string) $schema['content'])."\n```",
            $bundle->outputSchemas(),
        );
        $settings = array_replace(self::settings(), array_intersect_key($adapter->runSettings(), self::settings()));

        return [
            'prompt' => $prompt,
            'system' => implode("\n\n", [...array_column($bundle->parts(), 'content'), ...$schemas]),
            'manifest' => $manifest,
            'payload' => $payload,
            'prompt_version' => self::instructionVersion($prompt),
            'settings' => $settings,
            'metadata' => ['manifest' => $manifest],
            'dna' => $dna,
            'source_identity' => $identity,
            'project_id' => (string) $project->id,
            'model_sheet' => $modelSheet,
            'adapter' => $adapter,
            'input' => [
                'execution' => $adapter->execution(),
                'article_id' => (string) $project->article->id,
                'category_id' => (string) $category->id,
                'workflow' => $workflow,
                'bundle_hash' => $bundle->bundleHash(),
                'phase_id' => (string) $prompt->id,
                'phase_revision' => (int) $prompt->revision,
                'prompt_sha256' => (string) $prompt->content_sha256,
                'dna_stage_id' => (string) $dnaStage->id,
                'payload_sha256' => hash('sha256', $payload),
            ] + ($adapter->checkVersion() === null ? [] : ['check_version' => $adapter->checkVersion()]) + $settings,
        ];
    }

    /**
     * @param  array<string, mixed>  $design  a Phase 3 answer with its canonical hash and build-state texts filled in
     * @return list<string> what keeps the model sheet from opening this design, by the model sheet's own blocking checks
     */
    public static function handoff(array $design): array
    {
        return SuperyachtDesign::handoff($design);
    }

    public static function adapter(string $workflow): DesignWorkflow
    {
        $class = config('video.superyacht.design.adapters')[$workflow] ?? null;
        $adapter = is_string($class) ? app($class) : null;

        return $adapter instanceof DesignWorkflow ? $adapter : throw new RuntimeException("Workflow {$workflow} chưa có adapter thiết kế (video.superyacht.design.adapters).");
    }

    /**
     * @param  array<string, mixed>  $config  the model-sheet config of the category
     * @return array<string, mixed> the pinned model-sheet presentation the design writes its model sheet for
     */
    private static function modelSheet(array $config): array
    {
        return [
            'version' => (string) $config['master']['execution'],
            'cameras' => (array) $config['cameras'],
            'camera_convention' => (string) $config['camera_convention'],
        ] + ModelSheetQaService::fixedRules([
            'views' => ModelSheetService::views($config, new ModelSheetMaster((array) $config['master'])),
            'layout_guide' => ($config['master']['layout_guide'] ?? false) === true,
        ], $config);
    }

    /** @return array{model: string, effort: string, max_tokens: int, timeout_seconds: int, stream: bool, retry_times: int} */
    public static function settings(): array
    {
        $design = (array) config('video.superyacht.design');

        return [
            'model' => (string) $design['model'],
            'effort' => (string) $design['effort'],
            'max_tokens' => (int) $design['max_tokens'],
            'timeout_seconds' => (int) $design['timeout_seconds'],
            'stream' => (bool) $design['stream'],
            'retry_times' => (int) $design['retry_times'],
        ];
    }

    /**
     * @param  array{prompt: AiPhase, system: string, manifest: array<string, mixed>, payload: string, input: array<string, mixed>, dna: array<mixed>, source_identity: array<mixed>, project_id: string, model_sheet?: array<string, mixed>, adapter?: DesignWorkflow}  $run
     * @param  ?array<string, mixed>  $manifest  the bundle the stage was sent with
     * @return array{0: ?object, 1: array<string, mixed>}
     */
    private function evaluate(string $raw, array $run, ?array $manifest): array
    {
        $adapter = is_string($manifest['workflow'] ?? null) ? self::adapter($manifest['workflow']) : $run['adapter'];

        return $adapter->evaluate($raw, [
            'prompt_version' => self::instructionVersion($run['prompt']),
            'forbidden' => self::forbiddenWords((string) $run['prompt']->prompt_content),
            'model_sheet' => $run['model_sheet'] ?? self::modelSheet($adapter->modelSheetConfig()),
        ] + $run, $manifest);
    }

    /** @param array{prompt: AiPhase, system: string, manifest: array<string, mixed>, payload: string, input: array<string, mixed>, dna: array<mixed>, source_identity: array<mixed>, project_id: string, model_sheet?: array<string, mixed>, adapter?: DesignWorkflow} $run */
    private function replaceable(VideoPlanningStage $served, array $run): bool
    {
        if ($this->approval->integrityProblem((string) $served->project_id, $served, (string) ($run['input']['workflow'] ?? '')) !== null) {
            return true;
        }

        $check = $this->evaluate((string) (is_array($served->output_json) ? ($served->output_json['raw_text'] ?? '') : ''), $run, self::manifestOf($served))[1];

        return $check['verdict'] !== YachtDesignCheck::PASS || $check['warnings'] !== [];
    }

    /** @return ?array<string, mixed> */
    private static function manifestOf(VideoPlanningStage $stage): ?array
    {
        $manifest = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null)['manifest'] ?? null;

        return is_array($manifest) ? $manifest : null;
    }

    /**
     * @param  array{prompt: AiPhase, system: string, manifest: array<string, mixed>, payload: string, input: array<string, mixed>, dna: array<mixed>, source_identity: array<mixed>, project_id: string, model_sheet?: array<string, mixed>, adapter?: DesignWorkflow}  $run
     * @return array<string, mixed>
     */
    private function output(array $run, string $raw): array
    {
        [$filled, $check] = $this->evaluate($raw, $run, $run['manifest']);
        [, $error] = JsonAnswer::parse($raw);

        return [
            'execution' => (string) $run['input']['execution'],
            'prompt' => array_intersect_key($run['input'], array_flip(['category_id', 'workflow', 'phase_id', 'phase_revision', 'prompt_sha256'])),
            'dna_stage_id' => $run['input']['dna_stage_id'],
            'raw_text' => $raw,
            'json_parse_error' => $error,
            'design' => $filled,
            'check' => $check,
        ];
    }

    /** @return list<string> */
    private static function forbiddenWords(string $prompt): array
    {
        if (preg_match('/\*\*Forbidden words\*\*[^:\n]*:\s*([^\n]+)/u', $prompt, $match) !== 1) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $word): string => trim($word, " .\t"),
            explode(',', $match[1]),
        )));
    }

    private static function label(int $revision): string
    {
        return 'Prompt '.self::PHASE_CODE.' revision '.$revision;
    }
}
