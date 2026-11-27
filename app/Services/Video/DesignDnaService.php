<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\AiPhase;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Repositories\Interfaces\VideoPlanningStageRepositoryInterface;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Services\VideoProjectService;
use App\Video\DesignDna\DnaCheck;
use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Llm\JsonAnswer;
use App\Video\Llm\LlmClient;
use App\Video\Moto\MotoCheckRules;
use App\Video\Moto\MotoContract;
use App\Video\Moto\MotoDnaCheck;
use App\Video\Moto\MotoPipelineRelease;
use App\Video\Superyacht\Spec\PhaseResolver;
use App\Video\Superyacht\Spec\ResolvedPhase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class DesignDnaService implements QueuedStageService
{
    public const EXECUTION_V2 = 'db-phase-2-v2';

    public const ACTION = 'spec.dna';

    public const SCHEMA_HEADING = 'Phase 2 output JSON Schema';

    public const PHASE_CODE = 'phase-2';

    /** @var string the key of the project metadata that holds the Phase 2 contract a moto project keeps once a design is built from its DNA: the manifest of that DNA run, so a later Phase 2 revision of its release never asks the project for another DNA */
    public const CONTRACT_KEY = 'phase_2_contract';

    /** @var int how many passed DNA runs of other projects one read of the concept history takes; it reads on, batch after batch, until it holds enough concepts or no run is left, so this bounds a read and never the history */
    private const HISTORY_BATCH = 200;

    /** @var string the index a read of the concept history is held to: with a row limit the database otherwise scans the whole table, every large column of every run included, which measured about eighty times slower */
    private const HISTORY_INDEX = 'video_planning_stages_project_revision_stage_unique';

    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly VideoProjectRepositoryInterface $projects,
        private readonly VideoProjectService $videoProjects,
        private readonly LlmClient $writer,
        private readonly DesignApprovalLock $approval,
        private readonly VideoPlanningStageRepositoryInterface $stages,
        private readonly PhaseResolver $resolver,
    ) {}

    /**
     * @param  ?array{id: string, name: string}  $requester  who sent the run
     * @param  ?VideoProject  $loaded  that project when the request already read it, with its article and category, to check who may run it: the run is prepared from it and it is not read again; what the claim stores is still written from the project read under the lock
     * @return array{0: ?VideoPlanningStage, 1: string} reason queued when a worker runs it later
     */
    public function run(string $projectId, bool $regenerate, string $operationId, ?array $requester = null, ?VideoProject $loaded = null): array
    {
        $held = null;

        return (new StageQueue($this->stageStore, $this->stages))->submit(
            'dna',
            $projectId,
            PlanningStageName::DESIGN_DNA,
            function () use ($projectId, $loaded): array {
                $project = $loaded !== null && (string) $loaded->id === $projectId && $loaded->article !== null ? $loaded : $this->project($projectId);
                $release = $this->release($project);

                return $this->prepare($release === null ? $project : self::under($project, $release), release: $release);
            },
            fn (VideoPlanningStage $served, array $run): bool => $regenerate && $this->replaceable($served, $run),
            $operationId,
            $requester,
            $this->perform(),
            'tạo DNA',
            'design dna',
            fn (?VideoPlanningStage $stage, string $reason, array $run): ?array => $reason === 'already_succeeded' && $stage !== null && $this->replaceable($stage, $run)
                ? [null, 'DNA đã lưu cho đúng Phase 1 và prompt này chưa đạt kiểm kỹ thuật — bấm "Tạo lại DNA" để tạo bản mới. Không gọi model.']
                : null,
            'Chưa gọi model DNA: ',
            function (array $run) use ($projectId, &$held): ?string {
                return $this->refusal($projectId) ?? $this->moved($projectId, $run, $held);
            },
            static function (array $run) use (&$held): void {
                if ($held !== null && is_string($run['release'] ?? null)) {
                    MotoPipelineRelease::keep($held, $run['release']);
                }
            },
        );
    }

    /** @return ?string why no new DNA may start: once the design is approved with its model sheet, a new DNA could only cost money and mark that design as old */
    public function refusal(string $projectId): ?string
    {
        return $this->approval->holds($projectId) ? DesignApprovalLock::MESSAGE : null;
    }

    /** @return array{0: ?VideoPlanningStage, 1: string} what the queued run became when a worker took it */
    public function execute(string $stageId, string $token): array
    {
        $stage = VideoPlanningStage::query()->withoutSnapshot()->find($stageId);

        if ($stage === null) {
            return [null, 'Lượt tạo DNA không còn — bỏ qua.'];
        }

        $history = is_array($stage->input_json) ? ($stage->input_json['concept_history_stage_ids'] ?? null) : null;
        $queued = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null)['manifest'] ?? null;

        return (new StageQueue($this->stageStore, $this->stages))->execute(
            $stageId,
            $token,
            function () use ($stage, $history, $queued): array {
                if ($this->approval->holds((string) $stage->project_id)) {
                    throw new RuntimeException(DesignApprovalLock::MESSAGE);
                }

                return $this->prepare($this->project((string) $stage->project_id), is_array($history) ? $history : null, null, is_array($queued) ? $queued : null);
            },
            $this->perform(),
            'tạo DNA',
            'design dna',
            notCalled: 'Chưa gọi model DNA: ',
        );
    }

    /** @return \Closure(VideoPlanningStage, string, array<string, mixed>, ?\Closure(\Throwable): ?array): array{0: ?VideoPlanningStage, 1: string} */
    private function perform(): \Closure
    {
        return (new StageQueue($this->stageStore, $this->stages))->singleCall($this->writer, PlanningStageName::DESIGN_DNA, fn (array $run, string $raw): array => $this->output($run, $raw), 'tạo DNA', 'design dna');
    }

    private function project(string $projectId): VideoProject
    {
        $project = $this->projects->getById($projectId);

        return $project !== null && $project->article !== null ? $project : throw new RuntimeException('Không tìm thấy dự án hoặc dự án không gắn bài viết.');
    }

    /**
     * @return ?string the release a new Phase 2 run of the project is prepared under, read without storing it: the one a moto project pinned, else the one it would keep from its first run on; null for any other workflow. A project that pinned one costs no query
     *
     * @throws RuntimeException when the configured release of new projects is not declared
     */
    private function release(VideoProject $project): ?string
    {
        $workflow = config('video.superyacht.contracts.categories')[$project->article?->category?->slug ?? ''] ?? null;

        return MotoContract::is($workflow)
            ? MotoPipelineRelease::chosen($project, static fn (): bool => VideoPlanningStage::query()->where('project_id', $project->id)->where('stage', PlanningStageName::DESIGN_DNA->value)->exists())
            : null;
    }

    /**
     * @param  string  $release  what release() gave for the project
     * @return VideoProject a copy of the project that reads as holding that release, for preparing a run only: every step of the preparation resolves its revisions under the release the claim will store. The copy is never saved; the claim writes the release from the project it reads under the lock
     */
    private static function under(VideoProject $project, string $release): VideoProject
    {
        $copy = clone $project;
        $copy->setAttribute('metadata_json', [...(array) $project->metadata_json, MotoPipelineRelease::KEY => $release]);

        return $copy;
    }

    /**
     * @param  array<string, mixed>  $run  the prepared run, with the release and the contract it was prepared under
     * @param  ?VideoProject  $held  receives the project as it is stored now, read inside the claim transaction under its lock, so what the claim stores is written from this copy and never from the one the run was prepared with
     * @return ?string why the run may not open: the release of the project, or the Phase 2 contract it pinned, is no longer the one the run was prepared under; null when both still are, and for a workflow without releases. Reads only
     */
    private function moved(string $projectId, array $run, ?VideoProject &$held): ?string
    {
        if (! is_string($run['release'] ?? null)) {
            return null;
        }

        $held = VideoProject::query()->with('article.category')->find($projectId);

        if ($held === null) {
            return 'Không tìm thấy dự án.';
        }

        $now = $this->release($held);

        if ($now !== $run['release']) {
            return "Bản phát hành của dự án đã đổi từ {$run['release']} sang {$now} trong lúc chuẩn bị — bấm lại để chạy theo bản hiện tại.";
        }

        $pinned = $held->metadata_json[self::CONTRACT_KEY]['bundle_hash'] ?? null;

        return is_string($pinned) && ! hash_equals($pinned, (string) $run['input']['bundle_hash'])
            ? 'Hợp đồng Phase 2 của dự án vừa được ghim khác với hợp đồng lượt này đã chuẩn bị — bấm lại để chạy theo hợp đồng đã ghim.'
            : null;
    }

    /**
     * @param  ?array<string, mixed>  $queued  the manifest a queued run was sent with, so a worker prepares it under the contract it was queued with
     * @return ResolvedPhase the Phase 2 contract a run is prepared under: for a worker, the one the queued run was sent with, whatever the project pinned since; otherwise the one the project pinned, else the one its release lists now
     *
     * @throws RuntimeException when a pinned or queued contract can no longer be replayed; the project is never moved to another revision by itself
     */
    private function contract(VideoProject $project, ?array $queued = null): ResolvedPhase
    {
        $pinned = $project->metadata_json[self::CONTRACT_KEY]['manifest'] ?? null;
        $manifest = $queued ?? (is_array($pinned) ? $pinned : null);

        if (! is_array($manifest)) {
            return $this->resolver->forProject($project, self::ACTION);
        }

        try {
            return $this->resolver->fromManifest($manifest);
        } catch (RuntimeException $e) {
            throw new RuntimeException('Hợp đồng Phase 2 '.($queued !== null ? 'của lượt đã xếp hàng' : 'đã ghim của dự án').' không còn dựng lại được: '.$e->getMessage().' — không tự chuyển sang revision khác, không gọi model.', 0, $e);
        }
    }

    /**
     * @param  string  $dnaStageId  the DNA run a design of the project is built from
     * @return array{0: ?array{manifest: array<string, mixed>, bundle_hash: string, phase_revision: int, dna_stage_id: string}, 1: ?string} the Phase 2 contract a moto project would keep from that DNA run, or why it cannot: the run is a passed, usable DNA run of this very project and its manifest still replays to the rows it names. Reads only; the preview of the command and the pin itself decide from this one answer
     */
    public function contractFrom(string $projectId, string $dnaStageId): array
    {
        $project = VideoProject::query()->with('article.category')->find($projectId);

        if ($project === null) {
            return [null, 'Không tìm thấy dự án.'];
        }

        if (! MotoContract::is(config('video.superyacht.contracts.categories')[$project->article?->category?->slug ?? ''] ?? null)) {
            return [null, 'Dự án không thuộc luồng moto — không ghim.'];
        }

        $stage = VideoPlanningStage::query()->withoutSnapshot()->whereKey($dnaStageId)->where('project_id', $projectId)
            ->where('stage', PlanningStageName::DESIGN_DNA->value)->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->first();

        if ($stage === null) {
            return [null, 'Lượt DNA nguồn của thiết kế không còn, chưa chạy xong hoặc không thuộc dự án.'];
        }

        if (! MotoCheckRules::usable((array) ($stage->output_json['check'] ?? []))) {
            return [null, 'Lượt DNA nguồn chưa đạt kiểm kỹ thuật hoặc còn điều kiện chưa đạt.'];
        }

        $manifest = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null)['manifest'] ?? null;

        if (! is_array($manifest)) {
            return [null, 'Lượt DNA nguồn không lưu manifest.'];
        }

        [$bundleHash, $problem] = $this->replay($manifest);

        return $problem !== null
            ? [null, 'Manifest của lượt DNA nguồn không còn dựng lại được: '.$problem]
            : [['manifest' => $manifest, 'bundle_hash' => $bundleHash, 'phase_revision' => (int) $manifest['phase']['revision'], 'dna_stage_id' => (string) $stage->id], null];
    }

    /**
     * @param  array<string, mixed>  $manifest  the manifest of a Phase 2 run
     * @return array{0: ?string, 1: ?string} the bundle hash the manifest replays to, or why it no longer replays to the rows it names
     */
    public function replay(array $manifest): array
    {
        try {
            return [$this->resolver->fromManifest($manifest)->bundleHash(), null];
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }
    }

    /**
     * @param  string  $dnaStageId  the DNA run a design of the project is built from
     * @return ?string why the contract was not pinned, null when the project keeps one: the one it already pinned, or now the contract contractFrom() reads from that DNA run. Pinned once, under the lock of the project; nothing else of the project is touched
     */
    public function pinContract(string $projectId, string $dnaStageId): ?string
    {
        return DB::transaction(function () use ($projectId, $dnaStageId): ?string {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->first();

            if ($project !== null && is_array($project->metadata_json[self::CONTRACT_KEY]['manifest'] ?? null)) {
                return null;
            }

            [$contract, $problem] = $this->contractFrom($projectId, $dnaStageId);

            if ($contract === null) {
                return $problem;
            }

            $project->forceFill(['metadata_json' => [...(array) $project->metadata_json, self::CONTRACT_KEY => $contract + ['pinned_at' => now()->toIso8601String()]]])->save();

            return null;
        });
    }

    /** @return array{model: string, effort: string, max_tokens: int, timeout_seconds: int, stream: bool, retry_times: int} */
    public static function settings(): array
    {
        $dna = (array) config('video.superyacht.dna');

        return [
            'model' => (string) $dna['model'],
            'effort' => (string) $dna['effort'],
            'max_tokens' => (int) $dna['max_tokens'],
            'timeout_seconds' => (int) $dna['timeout_seconds'],
            'stream' => (bool) $dna['stream'],
            'retry_times' => (int) $dna['retry_times'],
        ];
    }

    /**
     * @param  ?array<string, mixed>  $inspiration  the Phase 1 panel this page already built, so it is not built again
     * @return array<string, mixed> the DNA of the project as the screen and Phase 3 read it: its newest run, except for a project that pinned its Phase 2 contract, which goes on from the passed DNA of that contract — a later run written to another contract stays in the history and never takes its place once it is no longer waiting or running
     */
    public function panel(string $projectId, ?array $inspiration = null): array
    {
        $project = $this->projects->getById($projectId);
        $run = null;
        $runError = null;

        try {
            if ($project === null) {
                throw new RuntimeException('Không tìm thấy dự án.');
            }

            $run = $this->prepare($project, null, $inspiration);
        } catch (\Throwable $e) {
            $runError = $e->getMessage();
        }

        [$latest, $matchesInput] = $this->stageStore->latestStageForProject(
            $projectId,
            PlanningStageName::DESIGN_DNA,
            $run['input'] ?? [],
            skipOrphans: true,
        );
        $next = $run === null ? null : self::label((int) $run['prompt']->revision, (string) $run['input']['workflow']);
        $served = match (true) {
            $run === null => null,
            $matchesInput && $latest?->status === VideoPlanningStageStatus::SUCCEEDED->value => $latest,
            default => $this->stageStore->latestSucceededForInput($projectId, PlanningStageName::DESIGN_DNA, $run['input']),
        };

        if ($served !== null && ! $matchesInput && isset($project?->metadata_json[self::CONTRACT_KEY]) && ! in_array($latest?->status, [VideoPlanningStageStatus::PENDING->value, VideoPlanningStageStatus::RUNNING->value], true)) {
            [$latest, $matchesInput] = [$served, true];
        }

        $shown = $served !== null && $latest !== null && (string) $served->id === (string) $latest->id;
        $parsed = $latest?->status === VideoPlanningStageStatus::SUCCEEDED->value ? JsonAnswer::parse((string) ($latest->output_json['raw_text'] ?? '')) : null;
        $servedCheck = $served === null ? null : $this->checkOf($served, $run, $shown ? $parsed : null);
        $locked = $this->approval->holds($projectId);
        $canRun = ! $locked && $run !== null && $served === null;
        $canRegenerate = ! $locked && $servedCheck !== null && ! MotoCheckRules::usable($servedCheck);
        $empty = ['stage_id' => null, 'analysed' => false, 'status' => null, 'running' => false, 'pending' => false, 'stuck' => false, 'error' => null, 'stream' => null, 'task' => null,
            'source' => null, 'current' => false, 'check' => null, 'novelty' => null, 'phase3_ready' => false, 'json' => null, 'json_error' => null];

        if ($latest === null) {
            return ['can_run' => $canRun, 'can_regenerate' => false, 'locked' => $locked, 'prompt' => $next, 'run_error' => $runError, 'operation_id' => (string) Str::uuid()] + $empty;
        }

        $succeeded = $latest->status === VideoPlanningStageStatus::SUCCEEDED->value;
        $claimed = $latest->status === VideoPlanningStageStatus::RUNNING->value;
        $stored = $succeeded && is_array($latest->output_json) ? $latest->output_json : [];
        $current = $succeeded && $matchesInput && $run !== null;
        $check = match (true) {
            $current && $shown => $servedCheck,
            $current => $this->checkOf($latest, $run, $parsed),
            default => is_array($stored['check'] ?? null) ? $stored['check'] : null,
        };
        [$decoded, $parseError] = $parsed ?? [null, null];
        $recorded = is_array($latest->input_json) ? $latest->input_json : [];
        $phase3Ready = $current && MotoCheckRules::usable((array) $check);

        return [
            'stage_id' => (string) $latest->id,
            'analysed' => $succeeded,
            'status' => $latest->status,
            'running' => $claimed && $latest->lease_expires_at?->isFuture() === true,
            'pending' => $latest->status === VideoPlanningStageStatus::PENDING->value,
            'stuck' => $claimed && $latest->lease_expires_at?->isFuture() !== true,
            'error' => $latest->error_message,
            'stream' => PlanningStageStore::streamOf($latest),
            'task' => PlanningStageStore::taskOf($latest),
            'can_run' => $canRun,
            'prompt' => $next,
            'run_error' => $runError,
            'operation_id' => (string) Str::uuid(),
            'source' => isset($recorded['phase_revision']) ? self::label((int) $recorded['phase_revision'], (string) ($recorded['workflow'] ?? '')) : null,
            'current' => $current,
            'check' => $check,
            'novelty' => $phase3Ready ? self::ownNovelty($parsed) : null,
            'can_regenerate' => $canRegenerate,
            'locked' => $locked,
            'phase3_ready' => $phase3Ready,
            'json' => $succeeded && $parseError === null
                ? (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'json_error' => $parseError,
        ];
    }

    /** @return array{0: bool, 1: string} reason pending when a queued run was cancelled */
    public function reset(string $projectId): array
    {
        return $this->stageStore->resetLatest($projectId, PlanningStageName::DESIGN_DNA, 'Chưa có lượt tạo DNA nào.');
    }

    public static function instructionVersion(AiPhase $prompt): string
    {
        return "spec-v6.3-phase-2@rev{$prompt->revision}";
    }

    /**
     * @param  ?list<string>  $queuedHistory  the concept history stages a queued run was sent with, so a concept passed by another project while it waited changes nothing
     * @param  ?array<string, mixed>  $inspiration  the Phase 1 panel when the caller already read it
     * @param  ?array<string, mixed>  $queuedManifest  the manifest a queued run was sent with, so the worker prepares it under that contract
     * @param  ?string  $release  the release a new run of a moto project was prepared under, as release() read it; it is returned with the run so the claim checks and stores that very one
     * @return array{prompt: AiPhase, system: string, manifest: array<string, mixed>, schemas: list<array<string, string>>, release: ?string, payload: string, prompt_version: string, settings: array<string, mixed>, input: array<string, mixed>, metadata: array<string, mixed>, inspiration: array<mixed>, flags: list<string>, brief: array<string, mixed>}
     */
    private function prepare(VideoProject $project, ?array $queuedHistory = null, ?array $inspiration = null, ?array $queuedManifest = null, ?string $release = null): array
    {
        $phaseOne = $inspiration ?? $this->videoProjects->latestInspiration((string) $project->id, $project);

        if (! $phaseOne['source_passed'] || ! is_string($phaseOne['stage_id'])) {
            throw new RuntimeException('Phase 1 chưa đạt đánh giá nguồn, hoặc lượt đã ghim không còn khớp bài viết và prompt mà nó đã dùng.');
        }

        $category = $project->article?->category ?? throw new RuntimeException('Bài viết của dự án không có chủ đề.');
        $workflow = config('video.superyacht.contracts.categories')[$category->slug] ?? null;

        if (! is_string($workflow) || $workflow === '') {
            throw new RuntimeException("Chủ đề {$category->slug} chưa cấu hình workflow.");
        }

        $moto = MotoContract::is($workflow);
        $stage = $this->stages->findStageOutputOrFail($phaseOne['stage_id']);
        $inspiration = PhaseOneEvidence::inspiration(JsonAnswer::object((string) ($stage->output_json['raw_text'] ?? '')));

        if (! is_array($inspiration) || array_is_list($inspiration)) {
            throw new RuntimeException('Không đọc được khối inspiration của lượt Phase 1 đã đạt.');
        }

        if ($moto && MotoDnaCheck::departureIds($inspiration) === []) {
            throw new RuntimeException('Kết quả Phase 1 không có mục signature, styling, chassis, powertrain, technology hay visual nào mang ID để departure — không gọi model.');
        }

        if (! $moto && DnaCheck::departureIds($inspiration) === []) {
            throw new RuntimeException('Kết quả Phase 1 không có mục exterior, interior, technology, visual hay signature nào mang ID để departure — không gọi model.');
        }

        $bundle = $this->contract($project, $queuedManifest);

        if (! $bundle->ready()) {
            throw new RuntimeException('Hợp đồng '.self::ACTION.' chưa sẵn sàng: '.implode('; ', $bundle->mismatches()).' — dữ liệu phát hành Phase 2 của chủ đề này còn thiếu (prompt và hash vẫn khớp, chỉ thiếu schema hoặc liên kết schema): '.($moto ? 'chạy php artisan db:seed --class=AiPhaseSeeder' : 'phát hành gói phase-2-design-dna-v4').' trước, không gọi model.');
        }

        $manifest = $bundle->manifest();
        $prompt = $bundle->phase();
        $themed = $moto && MotoContract::generation($manifest, 'MotoDna') >= 2;
        $markers = match (true) {
            $themed => [MotoContract::DNA_FORMAT_V2, 'Input format: `'.MotoContract::DNA_INPUT_V2.'`'],
            $moto => [MotoContract::DNA_FORMAT, 'Input format: `'.MotoContract::DNA_INPUT.'`'],
            default => [DnaCheck::FORMAT_V2, 'Input format: `'.DnaCheck::INPUT_FORMAT.'`'],
        };

        foreach ($markers as $marker) {
            if (! str_contains((string) $prompt->prompt_content, $marker)) {
                throw new RuntimeException("Prompt phase-2 revision {$prompt->revision} không khai {$marker} mà code đang dùng — phát hành revision tương thích trước, không gọi model.");
            }
        }

        $outputSchemas = $bundle->outputSchemas();
        $schemas = array_map(
            static fn (array $schema): string => '## '.self::SCHEMA_HEADING." — {$schema['label']}\n\n```json\n".trim($schema['content'])."\n```",
            $outputSchemas,
        );
        $flags = array_values(array_filter((array) ($phaseOne['source_check']['flags'] ?? []), 'is_string'));
        $brief = $moto ? self::motoBrief($themed ? $project : null) : [];
        [$history, $historyStages] = $moto
            ? $this->conceptHistory($project, ['phase_id' => (string) $prompt->id, 'bundle_hash' => $bundle->bundleHash(), 'inspiration_stage_id' => (string) $stage->id], $queuedHistory)
            : [[], []];
        $payload = (string) json_encode(
            $moto
                ? ['inspiration' => $inspiration, 'flags' => $flags, 'brief' => $brief, 'concept_history' => $history]
                : ['inspiration' => $inspiration, 'flags' => $flags],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return [
            'prompt' => $prompt,
            'system' => implode("\n\n", [...array_column($bundle->parts(), 'content'), ...$schemas]),
            'manifest' => $manifest,
            'schemas' => $outputSchemas,
            'release' => $release,
            'payload' => $payload,
            'prompt_version' => self::instructionVersion($prompt),
            'settings' => self::settings(),
            'metadata' => ['manifest' => $manifest],
            'inspiration' => $inspiration,
            'flags' => $flags,
            'brief' => $brief,
            'history' => $history,
            'input' => [
                'execution' => $moto ? MotoContract::DNA_EXECUTION : self::EXECUTION_V2,
                'article_id' => (string) $project->article->id,
                'category_id' => (string) $category->id,
                'workflow' => $workflow,
                'bundle_hash' => $bundle->bundleHash(),
                'phase_id' => (string) $prompt->id,
                'phase_revision' => (int) $prompt->revision,
                'prompt_sha256' => (string) $prompt->content_sha256,
                'inspiration_stage_id' => (string) $stage->id,
                'payload_sha256' => hash('sha256', $payload),
            ] + ($moto ? ['concept_history_stage_ids' => $historyStages] : []) + self::settings(),
        ];
    }

    /**
     * @param  array{inspiration: array<mixed>, flags: list<string>, brief: array<string, mixed>, history: list<array<string, mixed>>}  $run
     * @param  ?array{0: mixed, 1: ?string}  $parsed  the answer of the stage as JsonAnswer::parse() already read it in this request
     * @return array<string, mixed> the check of the contract the stage pinned; the answer's own format never chooses it
     */
    private function checkOf(VideoPlanningStage $stage, array $run, ?array $parsed = null): array
    {
        $raw = (string) (is_array($stage->output_json) ? ($stage->output_json['raw_text'] ?? '') : '');
        $input = is_array($stage->input_json) ? $stage->input_json : [];
        $execution = (string) ($input['execution'] ?? '');
        $parsed ??= JsonAnswer::parse($raw);

        if (! in_array($execution, [self::EXECUTION_V2, MotoContract::DNA_EXECUTION], true)) {
            return DnaCheck::evaluate(JsonAnswer::objectOf($parsed), $run['inspiration'], $run['flags'], null, DnaCheck::FORMAT);
        }

        return $this->checkV2($parsed, $run, PlanningStageStore::metadataOf($input)['manifest'] ?? null, $execution);
    }

    /**
     * A fault of the check itself — a schema that cannot be read or compiled — leaves a moto answer unscored with that cause; it is never a verdict on the answer.
     *
     * @param  array{0: mixed, 1: ?string}  $parsed  the answer as JsonAnswer::parse() read it
     * @param  array{manifest: array<string, mixed>, schemas: list<array<string, string>>, inspiration: array<mixed>, flags: list<string>, brief: array<string, mixed>, history: list<array<string, mixed>>}  $run
     * @param  mixed  $manifest  the contract the run pinned; when it is the very contract $run was prepared under, the schemas already read for $run are used and the manifest is not replayed again
     * @param  string  $execution  the execution the run pinned; a moto run is checked as moto-dna-v1
     * @return array<string, mixed>
     */
    private function checkV2(array $parsed, array $run, mixed $manifest, string $execution): array
    {
        $moto = $execution === MotoContract::DNA_EXECUTION;
        $schemas = [];
        $fault = null;
        $answer = JsonAnswer::objectOf($parsed);

        try {
            [$decoded] = $parsed;
            $schemas = is_array($manifest) && $manifest == $run['manifest']
                ? $run['schemas']
                : $this->resolver
                    ->fromManifest(is_array($manifest) ? $manifest : throw new RuntimeException('lượt này không lưu manifest hợp đồng'))
                    ->outputSchemas();
            $errors = is_object($decoded) ? ($moto ? MotoContract::structureErrors($decoded, $schemas) : PhaseOneEvidence::schemaErrors($decoded, $schemas)) : ['phản hồi không phải một object JSON'];
        } catch (\Throwable $e) {
            $fault = $moto ? MotoContract::structureFault($e, $schemas) : null;
            $errors = ['không kiểm được JSON Schema đã ghim: '.$e->getMessage()];
        }

        if (! $moto) {
            return DnaCheck::evaluate($answer, $run['inspiration'], $run['flags'], $errors, DnaCheck::FORMAT_V2);
        }

        $format = MotoContract::generation(is_array($manifest) ? $manifest : [], 'MotoDna') >= 2 ? MotoContract::DNA_FORMAT_V2 : MotoContract::DNA_FORMAT;
        $check = $fault === null
            ? MotoCheckRules::apply(MotoDnaCheck::evaluate($answer, $run['inspiration'], $run['flags'], $run['brief'], $run['history'], $errors, $format))
            : ['error' => $fault, 'config_fault' => true] + MotoDnaCheck::evaluate(null, $run['inspiration'], $run['flags'], $run['brief'], $run['history'], [], $format);

        return $check + ['technical_schema' => MotoContract::TECHNICAL_SCHEMA_VERSION];
    }

    /**
     * @param  ?VideoProject  $themed  the project of a run under the cinematic release, null for a run under the earlier contract
     * @return array<string, mixed> the brief config fixes for a moto build; under the cinematic release also brief.direction with every field null, as its prompt reads it, so the model decides the whole direction itself
     */
    private static function motoBrief(?VideoProject $themed = null): array
    {
        $config = (array) config('video.moto.brief');
        $brief = [];

        foreach (['platform', 'keep', 'change_scope', 'rider', 'purpose', 'limits'] as $key) {
            $brief[$key] = $config[$key] ?? null;
        }

        return $themed === null ? $brief : $brief + ['direction' => array_fill_keys(MotoPipelineRelease::DIRECTION_FIELDS, null)];
    }

    /**
     * @param  array{phase_id: string, bundle_hash: string, inspiration_stage_id: string}  $contract  what this DNA run is pinned to
     * @param  ?list<string>  $queued  the history stages a queued run was sent with; they win over every other source
     * @return array{0: list<array{id: string, theme: string, silhouette: string, features: list<string>, motifs: list<string>}>, 1: list<string>} the passed moto concepts of other projects with the DNA stages they come from: the snapshot a queued run or a passed run of this project and contract was sent with, in its stored order, or, while there is none, the newest concept of every other project passed before now, newest first, up to the configured number. Those are read HISTORY_BATCH runs at a time, newest finished first and by id among runs finished together, with only the columns the history is built from; a project already in the history is left out of the next read, so many runs of one project never hide the others
     */
    private function conceptHistory(VideoProject $project, array $contract, ?array $queued = null): array
    {
        $pinned = $queued ?? ($this->stages->findFirstPassedDnaRunFor((string) $project->id, MotoContract::DNA_EXECUTION, $contract['phase_id'], $contract['bundle_hash'], $contract['inspiration_stage_id'])
            ?->input_json['concept_history_stage_ids'] ?? null);
        $limit = (int) config('video.moto.concept_history_limit');
        $history = [];
        $sources = [];
        $take = static function (VideoPlanningStage $stage, mixed $output) use (&$history, &$sources, $limit): bool {
            $dna = JsonAnswer::object((string) (is_array($output) ? ($output['raw_text'] ?? '') : ''))['dna'] ?? null;

            if (isset($history[$stage->project_id]) || ! is_array($dna)) {
                return false;
            }

            $history[$stage->project_id] = [
                'id' => sprintf('H%02d', count($history) + 1),
                'theme' => (string) $dna['theme'],
                'silhouette' => (string) $dna['silhouette'],
                'features' => array_map(static fn (array $feature): string => "{$feature['name']}: {$feature['what']}", (array) $dna['features']),
                'motifs' => array_values(array_column((array) $dna['departures'], 'result')),
            ];
            $sources[] = (string) $stage->id;

            return count($history) >= $limit;
        };

        if (is_array($pinned)) {
            $stages = $this->stages->findStagesByIdsWithColumns($pinned, ['id', 'project_id', 'output_json'])
                ->sortBy(static fn (VideoPlanningStage $stage): int => (int) array_search((string) $stage->id, $pinned, true));

            foreach ($stages as $stage) {
                if ($take($stage, $stage->output_json)) {
                    break;
                }
            }

            return [array_values($history), $sources];
        }

        $after = null;

        do {
            $batch = $this->stages->findPassedDnaBatch(MotoContract::DNA_EXECUTION, self::HISTORY_INDEX, (string) $project->id, array_keys($history), $after, self::HISTORY_BATCH)
                ->values();
            $outputs = [];

            foreach ($batch as $position => $stage) {
                if (isset($history[$stage->project_id])) {
                    continue;
                }

                if (! array_key_exists((string) $stage->id, $outputs)) {
                    $next = $batch->slice($position)
                        ->reject(static fn (VideoPlanningStage $row): bool => isset($history[$row->project_id]) || array_key_exists((string) $row->id, $outputs))
                        ->unique('project_id')
                        ->pluck('id');

                    foreach ($this->stages->findStagesByIdsWithColumns($next, ['id', 'output_json']) as $row) {
                        $outputs[(string) $row->id] = $row->output_json;
                    }
                }

                if ($take($stage, $outputs[(string) $stage->id] ?? null)) {
                    break 2;
                }
            }

            $last = $batch->last();
            $after = $last === null ? null : [$last->getRawOriginal('finished_at'), (string) $last->id];
        } while ($batch->count() === self::HISTORY_BATCH);

        return [array_values($history), $sources];
    }

    /** @param array{inspiration: array<mixed>, flags: list<string>, brief: array<string, mixed>, history: list<array<string, mixed>>} $run */
    private function replaceable(VideoPlanningStage $served, array $run): bool
    {
        return ! MotoCheckRules::usable($this->checkOf($served, $run));
    }

    /**
     * @param  array{0: mixed, 1: ?string}  $parsed  the answer as JsonAnswer::parse() read it
     * @return array{judge_score: mixed, closest_known: mixed} the novelty a yacht answer scored itself, which the yacht design reads from the panel
     */
    private static function ownNovelty(array $parsed): array
    {
        $novelty = JsonAnswer::objectOf($parsed)['novelty'] ?? [];

        return ['judge_score' => $novelty['judge_score'] ?? null, 'closest_known' => $novelty['closest_known'] ?? null];
    }

    /**
     * @param  array{input: array<string, mixed>, manifest: array<string, mixed>, schemas: list<array<string, string>>, inspiration: array<mixed>, flags: list<string>, brief: array<string, mixed>}  $run
     * @return array<string, mixed>
     */
    private function output(array $run, string $raw): array
    {
        $parsed = JsonAnswer::parse($raw);

        return [
            'execution' => (string) $run['input']['execution'],
            'prompt' => array_intersect_key($run['input'], array_flip(['category_id', 'workflow', 'phase_id', 'phase_revision', 'prompt_sha256', 'bundle_hash'])),
            'inspiration_stage_id' => $run['input']['inspiration_stage_id'],
            'flags' => $run['flags'],
            'raw_text' => $raw,
            'json_parse_error' => $parsed[1],
            'check' => $this->checkV2($parsed, $run, $run['manifest'], (string) $run['input']['execution']),
        ];
    }

    private static function label(int $revision, string $workflow): string
    {
        return 'Prompt '.self::PHASE_CODE.' revision '.$revision.' ('.$workflow.')';
    }
}
