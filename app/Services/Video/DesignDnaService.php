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
use App\Video\Inspiration\ClaudeInspirationAnalyst;
use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Llm\JsonAnswer;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Llm\LlmUnavailable;
use App\Video\Superyacht\Spec\PhaseResolver;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class DesignDnaService
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

    /** @return array{0: ?array<string, mixed>, 1: string} */
    public function run(string $projectId, bool $regenerate = false): array
    {
        $project = $this->projects->getById($projectId);

        if ($project === null || $project->article === null) {
            return [null, 'Không tìm thấy dự án hoặc dự án không gắn bài viết.'];
        }

        try {
            $run = $this->prepare($project);
        } catch (\Throwable $e) {
            Log::error('design dna: prompt or input unavailable, no model call made', ['project_id' => $projectId, 'exception' => $e]);

            return [null, 'Chưa gọi model DNA: '.$e->getMessage()];
        }

        $replaceFailing = fn (VideoPlanningStage $served): bool => $regenerate && $this->replaceable($served, $run);

        try {
            [$stage, $token, $reason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::DESIGN_DNA, $run['input'], $replaceFailing, ['manifest' => $run['manifest']]);
        } catch (\Throwable $e) {
            Log::error('design dna: could not open the run', ['project_id' => $projectId, 'exception' => $e]);

            return [null, 'Không mở được lượt tạo DNA — xem log.'];
        }

        if ($token === null) {
            return match (true) {
                $reason === 'already_succeeded' && $this->replaceable($stage, $run) => [null, 'DNA đã lưu cho đúng Phase 1 và prompt này chưa đạt kiểm kỹ thuật — bấm "Tạo lại DNA" để tạo bản mới. Không gọi model.'],
                $reason === 'already_succeeded' => [$stage->output_json, 'cached'],
                $reason === 'claimed_by_other' => [null, 'Đang có một lượt tạo DNA chạy cho dự án này — đợi xong rồi thử lại.'],
                default => [null, 'Không tìm thấy dự án.'],
            };
        }

        $model = (string) $run['input']['model'];
        $version = self::instructionVersion($run['prompt']);
        $stageId = (string) $stage->id;

        try {
            $response = $this->writer->complete(new LlmRequest(
                $run['system'],
                $run['payload'],
                $version,
                $model,
                (int) $run['input']['max_tokens'],
                null,
                (string) $run['input']['effort'],
                (int) $run['input']['timeout_seconds'],
                1,
                fn (array $state): bool => $this->stageStore->recordStream($stageId, $token, $state),
            ));
            $usage = ClaudeInspirationAnalyst::usageOf($response, $version);

            try {
                $output = $this->output($run, $response->text);
            } catch (\Throwable $e) {
                Log::error('design dna: the check threw on a received answer', ['stage_id' => $stageId, 'exception' => $e]);
                $this->stageStore->finishFailed($stageId, $token, 'Bộ kiểm lỗi khi đọc phản hồi đã nhận: '.$e->getMessage(), $usage, $response->text);

                return [null, 'Bộ kiểm lỗi khi đọc phản hồi đã nhận — phản hồi và usage đã được lưu: '.$e->getMessage()];
            }

            if (! $this->stageStore->finishSucceeded($stageId, $token, $response->text, $output, $usage)) {
                return $this->claimLost($projectId, PlanningStageName::DESIGN_DNA, $run['input'], $stageId, $token, $usage, $response->text, $output);
            }

            return [$output, 'ok'];
        } catch (\Throwable $e) {
            Log::error('design dna: the run failed', ['stage_id' => $stageId, 'exception' => $e]);
            $billed = $e instanceof LlmUnavailable ? $e->billed : null;
            $this->stageStore->finishFailed(
                $stageId,
                $token,
                $e->getMessage(),
                $billed === null ? ['model' => $model, 'instruction_version' => $version] : ClaudeInspirationAnalyst::usageOf($billed, $version),
                $billed?->text ?? '',
            );

            return [null, $e->getMessage()];
        }
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
        $empty = ['stage_id' => null, 'analysed' => false, 'status' => null, 'running' => false, 'stuck' => false, 'error' => null, 'stream' => null,
            'source' => null, 'current' => false, 'check' => null, 'novelty' => null, 'phase3_ready' => false, 'json' => null, 'json_error' => null];

        if ($latest === null) {
            return ['can_run' => $canRun, 'can_regenerate' => false, 'prompt' => $next, 'run_error' => $runError] + $empty;
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
            'stuck' => $claimed && $latest->lease_expires_at?->isFuture() !== true,
            'error' => $latest->error_message,
            'stream' => PlanningStageStore::streamOf($latest),
            'can_run' => $canRun,
            'prompt' => $next,
            'run_error' => $runError,
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

    /** @return array{0: bool, 1: string} */
    public function reset(string $projectId): array
    {
        [$latest] = $this->stageStore->latestStageForProject($projectId, PlanningStageName::DESIGN_DNA, [], skipOrphans: true);

        if ($latest === null) {
            return [false, 'Chưa có lượt tạo DNA nào.'];
        }

        return $this->stageStore->releaseClaim((string) $latest->id, 'Người dùng reset thủ công')
            ? [true, 'ok']
            : [false, 'Lượt này không còn giữ claim — không có gì để reset.'];
    }

    public static function instructionVersion(AiPhase $prompt): string
    {
        return "spec-v6.3-phase-2@rev{$prompt->revision}";
    }

    /** @return array{prompt: AiPhase, system: string, manifest: array<string, mixed>, payload: string, input: array<string, mixed>, inspiration: array<mixed>, flags: list<string>} */
    private function prepare(VideoProject $project): array
    {
        $phaseOne = $this->videoProjects->latestInspiration((string) $project->id);

        if (! $phaseOne['source_passed'] || ! is_string($phaseOne['stage_id'])) {
            throw new RuntimeException('Phase 1 chưa đạt đánh giá nguồn, hoặc lượt đã ghim không còn khớp bài viết và prompt mà nó đã dùng.');
        }

        $stage = VideoPlanningStage::query()->findOrFail($phaseOne['stage_id']);
        $inspiration = PhaseOneEvidence::inspiration(JsonAnswer::object((string) ($stage->output_json['raw_text'] ?? '')));

        if (! is_array($inspiration) || array_is_list($inspiration)) {
            throw new RuntimeException('Không đọc được khối inspiration của lượt Phase 1 đã đạt.');
        }

        if (DnaCheck::departureIds($inspiration) === []) {
            throw new RuntimeException('Kết quả Phase 1 không có mục exterior, interior, technology, visual hay signature nào mang ID để departure — không gọi model.');
        }

        $category = $project->article?->category ?? throw new RuntimeException('Bài viết của dự án không có chủ đề.');
        $workflow = config('video.superyacht.contracts.categories')[$category->slug] ?? null;

        if (! is_string($workflow) || $workflow === '') {
            throw new RuntimeException("Chủ đề {$category->slug} chưa cấu hình workflow.");
        }

        $bundle = PhaseResolver::fromConfig((array) config('video.superyacht'))->forProject((string) $project->id, self::ACTION);

        if (! $bundle->ready()) {
            throw new RuntimeException('Hợp đồng '.self::ACTION.' chưa sẵn sàng: '.implode('; ', $bundle->mismatches()).' — phát hành gói phase-2-design-dna-v4 cho chủ đề này trước, không gọi model.');
        }

        $manifest = $bundle->manifest();
        $prompt = AiPhase::query()->findOrFail($manifest['phase']['id']);

        foreach ([DnaCheck::FORMAT_V2, 'Input format: `'.DnaCheck::INPUT_FORMAT.'`'] as $marker) {
            if (! str_contains((string) $prompt->prompt_content, $marker)) {
                throw new RuntimeException("Prompt phase-2 revision {$prompt->revision} không khai {$marker} mà code đang dùng — phát hành revision tương thích trước, không gọi model.");
            }
        }

        $schemas = array_map(
            static fn (array $schema): string => '## '.self::SCHEMA_HEADING." — {$schema['label']}\n\n```json\n".trim($schema['content'])."\n```",
            $bundle->outputSchemas(),
        );
        $flags = array_values(array_filter((array) ($phaseOne['source_check']['flags'] ?? []), 'is_string'));
        $payload = (string) json_encode(['inspiration' => $inspiration, 'flags' => $flags], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'prompt' => $prompt,
            'system' => implode("\n\n", [...array_column($bundle->parts(), 'content'), ...$schemas]),
            'manifest' => $manifest,
            'payload' => $payload,
            'inspiration' => $inspiration,
            'flags' => $flags,
            'input' => [
                'execution' => self::EXECUTION_V2,
                'article_id' => (string) $project->article->id,
                'category_id' => (string) $category->id,
                'workflow' => $workflow,
                'bundle_hash' => $bundle->bundleHash(),
                'phase_id' => (string) $prompt->id,
                'phase_revision' => (int) $prompt->revision,
                'prompt_sha256' => (string) $prompt->content_sha256,
                'inspiration_stage_id' => (string) $stage->id,
                'payload_sha256' => hash('sha256', $payload),
            ] + self::settings(),
        ];
    }

    /**
     * @param  array{inspiration: array<mixed>, flags: list<string>}  $run
     * @return array<string, mixed> the check of the contract the stage pinned; the answer's own format never chooses it
     */
    private static function checkOf(VideoPlanningStage $stage, array $run): array
    {
        $raw = (string) (is_array($stage->output_json) ? ($stage->output_json['raw_text'] ?? '') : '');
        $input = is_array($stage->input_json) ? $stage->input_json : [];

        if (($input['execution'] ?? null) !== self::EXECUTION_V2) {
            return DnaCheck::evaluate(JsonAnswer::object($raw), $run['inspiration'], $run['flags'], null, DnaCheck::FORMAT);
        }

        return self::checkV2($raw, $run, PlanningStageStore::metadataOf($input)['manifest'] ?? null);
    }

    /**
     * @param  array{inspiration: array<mixed>, flags: list<string>}  $run
     * @param  mixed  $manifest  the contract the run pinned
     * @return array<string, mixed>
     */
    private static function checkV2(string $raw, array $run, mixed $manifest): array
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

        return DnaCheck::evaluate(JsonAnswer::object($raw), $run['inspiration'], $run['flags'], $errors, DnaCheck::FORMAT_V2);
    }

    /** @param array{inspiration: array<mixed>, flags: list<string>} $run */
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
     * @param  array{input: array<string, mixed>, manifest: array<string, mixed>, inspiration: array<mixed>, flags: list<string>}  $run
     * @return array<string, mixed>
     */
    private function output(array $run, string $raw): array
    {
        [, $error] = JsonAnswer::parse($raw);

        return [
            'execution' => self::EXECUTION_V2,
            'prompt' => array_intersect_key($run['input'], array_flip(['category_id', 'workflow', 'phase_id', 'phase_revision', 'prompt_sha256', 'bundle_hash'])),
            'inspiration_stage_id' => $run['input']['inspiration_stage_id'],
            'flags' => $run['flags'],
            'raw_text' => $raw,
            'json_parse_error' => $error,
            'check' => self::checkV2($raw, $run, $run['manifest']),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $output
     * @return array{0: null, 1: string}
     */
    private function claimLost(string $projectId, PlanningStageName $stageName, array $input, string $stageId, string $token, array $usage, string $raw, array $output): array
    {
        try {
            $this->stageStore->recordOrphanAttempt($projectId, $stageName, $input, [], $stageId, $token, 'claim_lost', $usage, $raw, $output);
        } catch (\Throwable $e) {
            Log::warning('design dna: recordOrphanAttempt threw', ['stage_id' => $stageId, 'exception' => $e]);
        }

        return [null, 'Lượt chạy bị giành mất khi lưu — kết quả đã trả tiền không được dùng. Tải lại trang rồi bấm lại nếu cần.'];
    }

    private static function label(int $revision, string $workflow): string
    {
        return 'Prompt '.self::PHASE_CODE.' revision '.$revision.' ('.$workflow.')';
    }
}
