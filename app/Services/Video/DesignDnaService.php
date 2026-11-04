<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\AiPhase;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Services\VideoProjectService;
use App\Video\DesignDna\DnaCheck;
use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Llm\JsonAnswer;
use App\Video\Llm\LlmClient;
use App\Video\Moto\MotoContract;
use App\Video\Moto\MotoDnaCheck;
use App\Video\Superyacht\Spec\PhaseResolver;
use Illuminate\Support\Str;
use RuntimeException;

final class DesignDnaService implements QueuedStageService
{
    public const EXECUTION = 'db-phase-2-v1';

    public const EXECUTION_V2 = 'db-phase-2-v2';

    public const ACTION = 'spec.dna';

    public const SCHEMA_HEADING = 'Phase 2 output JSON Schema';

    public const PHASE_CODE = 'phase-2';

    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly VideoProjectRepositoryInterface $projects,
        private readonly VideoProjectService $videoProjects,
        private readonly LlmClient $writer,
    ) {}

    /**
     * @param  ?array{id: string, name: string}  $requester  who sent the run
     * @return array{0: ?VideoPlanningStage, 1: string} reason queued when a worker runs it later
     */
    public function run(string $projectId, bool $regenerate, string $operationId, ?array $requester = null): array
    {
        return (new StageQueue($this->stageStore))->submit(
            'dna',
            $projectId,
            PlanningStageName::DESIGN_DNA,
            fn (): array => $this->prepare($this->project($projectId)),
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
        );
    }

    /** @return array{0: ?VideoPlanningStage, 1: string} what the queued run became when a worker took it */
    public function execute(string $stageId, string $token): array
    {
        $stage = VideoPlanningStage::query()->withoutSnapshot()->find($stageId);

        if ($stage === null) {
            return [null, 'Lượt tạo DNA không còn — bỏ qua.'];
        }

        $history = is_array($stage->input_json) ? ($stage->input_json['concept_history_stage_ids'] ?? null) : null;

        return (new StageQueue($this->stageStore))->execute(
            $stageId,
            $token,
            fn (): array => $this->prepare($this->project((string) $stage->project_id), is_array($history) ? $history : null),
            $this->perform(),
            'tạo DNA',
            'design dna',
            notCalled: 'Chưa gọi model DNA: ',
        );
    }

    /** @return \Closure(VideoPlanningStage, string, array<string, mixed>, ?\Closure(\Throwable): ?array): array{0: ?VideoPlanningStage, 1: string} */
    private function perform(): \Closure
    {
        return (new StageQueue($this->stageStore))->singleCall($this->writer, PlanningStageName::DESIGN_DNA, fn (array $run, string $raw): array => $this->output($run, $raw), 'tạo DNA', 'design dna');
    }

    private function project(string $projectId): VideoProject
    {
        $project = $this->projects->getById($projectId);

        return $project !== null && $project->article !== null ? $project : throw new RuntimeException('Không tìm thấy dự án hoặc dự án không gắn bài viết.');
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

    /** @return array<string, mixed> */
    public function panel(string $projectId): array
    {
        $project = $this->projects->getById($projectId);
        $run = null;
        $runError = null;

        try {
            if ($project === null) {
                throw new RuntimeException('Không tìm thấy dự án.');
            }

            $run = $this->prepare($project);
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
        $served = $run === null ? null : $this->stageStore->latestSucceededForInput($projectId, PlanningStageName::DESIGN_DNA, $run['input']);
        $canRun = $run !== null && $served === null;
        $canRegenerate = $run !== null && $served !== null && $this->replaceable($served, $run);
        $empty = ['stage_id' => null, 'analysed' => false, 'status' => null, 'running' => false, 'pending' => false, 'stuck' => false, 'error' => null, 'stream' => null, 'task' => null,
            'source' => null, 'current' => false, 'check' => null, 'novelty' => null, 'phase3_ready' => false, 'json' => null, 'json_error' => null];

        if ($latest === null) {
            return ['can_run' => $canRun, 'can_regenerate' => false, 'prompt' => $next, 'run_error' => $runError, 'operation_id' => (string) Str::uuid()] + $empty;
        }

        $succeeded = $latest->status === VideoPlanningStageStatus::SUCCEEDED->value;
        $claimed = $latest->status === VideoPlanningStageStatus::RUNNING->value;
        $stored = $succeeded && is_array($latest->output_json) ? $latest->output_json : [];
        $raw = (string) ($stored['raw_text'] ?? '');
        $current = $succeeded && $matchesInput && $run !== null;
        $check = $current
            ? self::checkOf($latest, $run)
            : (is_array($stored['check'] ?? null) ? $stored['check'] : null);
        [$parsed, $parseError] = $succeeded ? JsonAnswer::parse($raw) : [null, null];
        $recorded = is_array($latest->input_json) ? $latest->input_json : [];
        $phase3Ready = $current && ($check['verdict'] ?? null) === DnaCheck::PASS;

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
            'novelty' => $phase3Ready ? self::ownNovelty($raw) : null,
            'can_regenerate' => $canRegenerate,
            'phase3_ready' => $phase3Ready,
            'json' => $succeeded && $parseError === null
                ? (string) json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
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
     * @return array{prompt: AiPhase, system: string, manifest: array<string, mixed>, payload: string, prompt_version: string, settings: array<string, mixed>, input: array<string, mixed>, metadata: array<string, mixed>, inspiration: array<mixed>, flags: list<string>, brief: array<string, mixed>}
     */
    private function prepare(VideoProject $project, ?array $queuedHistory = null): array
    {
        $phaseOne = $this->videoProjects->latestInspiration((string) $project->id);

        if (! $phaseOne['source_passed'] || ! is_string($phaseOne['stage_id'])) {
            throw new RuntimeException('Phase 1 chưa đạt đánh giá nguồn, hoặc lượt đã ghim không còn khớp bài viết và prompt mà nó đã dùng.');
        }

        $category = $project->article?->category ?? throw new RuntimeException('Bài viết của dự án không có chủ đề.');
        $workflow = config('video.superyacht.contracts.categories')[$category->slug] ?? null;

        if (! is_string($workflow) || $workflow === '') {
            throw new RuntimeException("Chủ đề {$category->slug} chưa cấu hình workflow.");
        }

        $moto = MotoContract::is($workflow);
        $stage = VideoPlanningStage::query()->findOrFail($phaseOne['stage_id']);
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

        $bundle = PhaseResolver::fromConfig((array) config('video.superyacht'))->forProject((string) $project->id, self::ACTION);

        if (! $bundle->ready()) {
            throw new RuntimeException('Hợp đồng '.self::ACTION.' chưa sẵn sàng: '.implode('; ', $bundle->mismatches()).' — phát hành gói '.($moto ? 'moto-phase-2-dna-v1' : 'phase-2-design-dna-v4').' cho chủ đề này trước, không gọi model.');
        }

        $manifest = $bundle->manifest();
        $prompt = AiPhase::query()->findOrFail($manifest['phase']['id']);
        $markers = $moto
            ? [MotoContract::DNA_FORMAT, 'Input format: `'.MotoContract::DNA_INPUT.'`']
            : [DnaCheck::FORMAT_V2, 'Input format: `'.DnaCheck::INPUT_FORMAT.'`'];

        foreach ($markers as $marker) {
            if (! str_contains((string) $prompt->prompt_content, $marker)) {
                throw new RuntimeException("Prompt phase-2 revision {$prompt->revision} không khai {$marker} mà code đang dùng — phát hành revision tương thích trước, không gọi model.");
            }
        }

        $schemas = array_map(
            static fn (array $schema): string => '## '.self::SCHEMA_HEADING." — {$schema['label']}\n\n```json\n".trim($schema['content'])."\n```",
            $bundle->outputSchemas(),
        );
        $flags = array_values(array_filter((array) ($phaseOne['source_check']['flags'] ?? []), 'is_string'));
        $brief = $moto ? self::motoBrief() : [];
        [$history, $historyStages] = $moto
            ? self::conceptHistory($project, ['phase_id' => (string) $prompt->id, 'bundle_hash' => $bundle->bundleHash(), 'inspiration_stage_id' => (string) $stage->id], $queuedHistory)
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
     * @return array<string, mixed> the check of the contract the stage pinned; the answer's own format never chooses it
     */
    private static function checkOf(VideoPlanningStage $stage, array $run): array
    {
        $raw = (string) (is_array($stage->output_json) ? ($stage->output_json['raw_text'] ?? '') : '');
        $input = is_array($stage->input_json) ? $stage->input_json : [];
        $execution = (string) ($input['execution'] ?? '');

        if (! in_array($execution, [self::EXECUTION_V2, MotoContract::DNA_EXECUTION], true)) {
            return DnaCheck::evaluate(JsonAnswer::object($raw), $run['inspiration'], $run['flags'], null, DnaCheck::FORMAT);
        }

        return self::checkV2($raw, $run, PlanningStageStore::metadataOf($input)['manifest'] ?? null, $execution);
    }

    /**
     * @param  array{inspiration: array<mixed>, flags: list<string>, brief: array<string, mixed>, history: list<array<string, mixed>>}  $run
     * @param  mixed  $manifest  the contract the run pinned
     * @param  string  $execution  the execution the run pinned; a moto run is checked as moto-dna-v1
     * @return array<string, mixed>
     */
    private static function checkV2(string $raw, array $run, mixed $manifest, string $execution): array
    {
        try {
            [$decoded] = JsonAnswer::parse($raw);
            $schemas = PhaseResolver::fromConfig((array) config('video.superyacht'))
                ->fromManifest(is_array($manifest) ? $manifest : throw new RuntimeException('lượt này không lưu manifest hợp đồng'))
                ->outputSchemas();
            $errors = is_object($decoded) ? PhaseOneEvidence::schemaErrors($decoded, $schemas) : ['phản hồi không phải một object JSON'];
        } catch (\Throwable $e) {
            $errors = ['không kiểm được JSON Schema đã ghim: '.$e->getMessage()];
        }

        return $execution === MotoContract::DNA_EXECUTION
            ? MotoDnaCheck::evaluate(JsonAnswer::object($raw), $run['inspiration'], $run['flags'], $run['brief'], $run['history'], $errors)
            : DnaCheck::evaluate(JsonAnswer::object($raw), $run['inspiration'], $run['flags'], $errors, DnaCheck::FORMAT_V2);
    }

    /** @return array<string, mixed> the brief the user fixed for a moto build; a null value is left to the model */
    private static function motoBrief(): array
    {
        $config = (array) config('video.moto.brief');
        $brief = [];

        foreach (['platform', 'keep', 'change_scope', 'rider', 'purpose', 'limits'] as $key) {
            $brief[$key] = $config[$key] ?? null;
        }

        return $brief;
    }

    /**
     * @param  array{phase_id: string, bundle_hash: string, inspiration_stage_id: string}  $contract  what this DNA run is pinned to
     * @param  ?list<string>  $queued  the history stages a queued run was sent with; they win over every other source
     * @return array{0: list<array{id: string, theme: string, silhouette: string, features: list<string>, motifs: list<string>}>, 1: list<string>} the passed moto concepts of other projects with the DNA stages they come from: the snapshot a queued run or a passed run of this project and contract was sent with, or, while there is none, every concept passed before now, newest first
     */
    private static function conceptHistory(VideoProject $project, array $contract, ?array $queued = null): array
    {
        $passed = static fn () => VideoPlanningStage::query()
            ->where('stage', PlanningStageName::DESIGN_DNA->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->where('input_json->execution', MotoContract::DNA_EXECUTION)
            ->whereNull('input_json->orphan_of_stage_id')
            ->where('output_json->check->verdict', DnaCheck::PASS);
        $pinned = $queued ?? $passed()
            ->where('project_id', $project->id)
            ->where('input_json->phase_id', $contract['phase_id'])
            ->where('input_json->bundle_hash', $contract['bundle_hash'])
            ->where('input_json->inspiration_stage_id', $contract['inspiration_stage_id'])
            ->orderBy('created_at')
            ->first()?->input_json['concept_history_stage_ids'] ?? null;
        $stages = is_array($pinned)
            ? VideoPlanningStage::query()->whereIn('id', $pinned)->get()->sortBy(static fn (VideoPlanningStage $stage): int => (int) array_search((string) $stage->id, $pinned, true))->values()
            : $passed()->where('project_id', '!=', $project->id)->orderByDesc('finished_at')->get();
        $history = [];
        $sources = [];

        foreach ($stages as $stage) {
            $dna = JsonAnswer::object((string) ($stage->output_json['raw_text'] ?? ''))['dna'] ?? null;

            if (isset($history[$stage->project_id]) || ! is_array($dna)) {
                continue;
            }

            $history[$stage->project_id] = [
                'id' => sprintf('H%02d', count($history) + 1),
                'theme' => (string) $dna['theme'],
                'silhouette' => (string) $dna['silhouette'],
                'features' => array_map(static fn (array $feature): string => "{$feature['name']}: {$feature['what']}", (array) $dna['features']),
                'motifs' => array_values(array_column((array) $dna['departures'], 'result')),
            ];
            $sources[] = (string) $stage->id;

            if (count($history) >= (int) config('video.moto.concept_history_limit')) {
                break;
            }
        }

        return [array_values($history), $sources];
    }

    /** @param array{inspiration: array<mixed>, flags: list<string>, brief: array<string, mixed>, history: list<array<string, mixed>>} $run */
    private function replaceable(VideoPlanningStage $served, array $run): bool
    {
        return self::checkOf($served, $run)['verdict'] !== DnaCheck::PASS;
    }

    /** @return array{judge_score: mixed, closest_known: mixed} */
    private static function ownNovelty(string $raw): array
    {
        $novelty = JsonAnswer::object($raw)['novelty'] ?? [];

        return ['judge_score' => $novelty['judge_score'] ?? null, 'closest_known' => $novelty['closest_known'] ?? null];
    }

    /**
     * @param  array{input: array<string, mixed>, manifest: array<string, mixed>, inspiration: array<mixed>, flags: list<string>, brief: array<string, mixed>}  $run
     * @return array<string, mixed>
     */
    private function output(array $run, string $raw): array
    {
        [, $error] = JsonAnswer::parse($raw);

        return [
            'execution' => (string) $run['input']['execution'],
            'prompt' => array_intersect_key($run['input'], array_flip(['category_id', 'workflow', 'phase_id', 'phase_revision', 'prompt_sha256', 'bundle_hash'])),
            'inspiration_stage_id' => $run['input']['inspiration_stage_id'],
            'flags' => $run['flags'],
            'raw_text' => $raw,
            'json_parse_error' => $error,
            'check' => self::checkV2($raw, $run, $run['manifest'], (string) $run['input']['execution']),
        ];
    }

    private static function label(int $revision, string $workflow): string
    {
        return 'Prompt '.self::PHASE_CODE.' revision '.$revision.' ('.$workflow.')';
    }
}
