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
use App\Video\Inspiration\ClaudeInspirationAnalyst;
use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Llm\JsonAnswer;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Llm\LlmUnavailable;
use App\Video\ModelSheet\ModelSheetFacts;
use App\Video\ModelSheet\ModelSheetMaster;
use App\Video\Superyacht\Spec\PhaseResolver;
use App\Video\Superyacht\Support\CanonicalJson;
use App\Video\YachtDesign\BuildStateText;
use App\Video\YachtDesign\DesignProse;
use App\Video\YachtDesign\YachtDesignCheck;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class YachtDesignService
{
    public const EXECUTION = 'db-phase-3-v2';

    public const PHASE_CODE = 'phase-3';

    public const ACTION = 'spec.design';

    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly VideoProjectRepositoryInterface $projects,
        private readonly VideoProjectService $videoProjects,
        private readonly DesignDnaService $dna,
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
            Log::error('yacht design: prompt or input unavailable, no model call made', ['project_id' => $projectId, 'exception' => $e]);

            return [null, 'Chưa gọi model thiết kế: '.$e->getMessage()];
        }

        $replaceFailing = fn (VideoPlanningStage $served): bool => $regenerate && $this->replaceable($served, $run);
        [$stage, $token, $reason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::YACHT_DESIGN, $run['input'], $replaceFailing, [
            'manifest' => $run['manifest'],
        ]);

        if ($token === null) {
            return match (true) {
                $reason === 'already_succeeded' && $this->replaceable($stage, $run) => [null, 'Bản thiết kế đã lưu cho đúng DNA và prompt này chưa đạt kiểm RULE hoặc còn cảnh báo — bấm "Thiết kế lại" nếu muốn tạo bản mới. Không gọi model.'],
                $reason === 'already_succeeded' => [$stage->output_json, 'cached'],
                $reason === 'claimed_by_other' => [null, 'Đang có một lượt thiết kế chạy cho dự án này — đợi xong rồi thử lại.'],
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
                Log::error('yacht design: the check threw on a received answer', ['stage_id' => $stageId, 'exception' => $e]);
                $this->stageStore->finishFailed($stageId, $token, 'Bộ kiểm lỗi khi đọc phản hồi đã nhận: '.$e->getMessage(), $usage, $response->text);

                return [null, 'Bộ kiểm lỗi khi đọc phản hồi đã nhận — phản hồi và usage đã được lưu: '.$e->getMessage()];
            }

            if (! $this->stageStore->finishSucceeded($stageId, $token, $response->text, $output, $usage)) {
                try {
                    $this->stageStore->recordOrphanAttempt($projectId, PlanningStageName::YACHT_DESIGN, $run['input'], [], $stageId, $token, 'claim_lost', $usage, $response->text, $output);
                } catch (\Throwable $e) {
                    Log::warning('yacht design: recordOrphanAttempt threw', ['stage_id' => $stageId, 'exception' => $e]);
                }

                return [null, 'Lượt thiết kế bị giành mất khi lưu — kết quả đã trả tiền không được dùng. Tải lại trang rồi bấm lại nếu cần.'];
            }

            return [$output, 'ok'];
        } catch (\Throwable $e) {
            Log::error('yacht design: the run failed', ['stage_id' => $stageId, 'exception' => $e]);
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

        [$latest] = $this->stageStore->latestStageForProject($projectId, PlanningStageName::YACHT_DESIGN, $run['input'] ?? [], skipOrphans: true);
        $served = $run === null ? null : $this->stageStore->latestSucceededForInput($projectId, PlanningStageName::YACHT_DESIGN, $run['input']);
        $empty = ['stage_id' => null, 'analysed' => false, 'status' => null, 'running' => false, 'stuck' => false, 'error' => null,
            'current' => false, 'dna_stale' => false, 'check' => null, 'name' => null, 'loa' => null, 'yacht_anchor' => null, 'prose' => null, 'json_error' => null];
        $base = [
            'can_run' => $run !== null && $served === null,
            'can_regenerate' => $run !== null && $served !== null && $this->replaceable($served, $run),
            'prompt' => $run === null ? null : self::label((int) $run['prompt']->revision),
            'run_error' => $runError,
        ];

        if ($latest === null) {
            return $base + $empty;
        }

        $claimed = $latest->status === VideoPlanningStageStatus::RUNNING->value;
        // Noi dung hien thi: ban thiet ke thanh cong moi nhat, doc tu output da luu. Prompt Phase 3
        // len revision moi chi doi luot Create design moi; khong an noi dung ban da chay. Mot luot
        // moi hon bi loi hoac dang chay cung khong thay noi dung ban da co.
        $shown = $latest->status === VideoPlanningStageStatus::SUCCEEDED->value ? $latest : VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::YACHT_DESIGN->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->whereNull('input_json->orphan_of_stage_id')
            ->orderByDesc('planning_revision')
            ->first();
        $succeeded = $shown !== null;
        $stored = $succeeded && is_array($shown->output_json) ? $shown->output_json : [];
        $current = $succeeded && $served !== null && (string) $served->id === (string) $shown->id;
        [$filled, $check] = $current
            ? $this->evaluate((string) ($stored['raw_text'] ?? ''), $run, self::manifestOf($shown))
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
            'stuck' => $claimed && $latest->lease_expires_at?->isFuture() !== true,
            'error' => $latest->status === VideoPlanningStageStatus::FAILED->value ? $latest->error_message : null,
            'current' => $current,
            // Chi DNA nguon doi moi lam ban thiet ke cu; prompt Phase 3 doi revision thi khong.
            'dna_stale' => $succeeded && $run !== null && ($shown->input_json['dna_stage_id'] ?? null) !== $run['input']['dna_stage_id'],
            'check' => $check,
            'name' => is_array($data) ? ($data['yacht_bible']['name'] ?? $data['vessel']['name'] ?? null) : null,
            'loa' => is_array($data) ? ($data['yacht_bible']['specs']['loa']['value'] ?? $data['vessel']['specs']['loa']['value'] ?? null) : null,
            'yacht_anchor' => is_array($data) ? ($data['anchors']['yacht'] ?? null) : null,
            'prose' => is_array($data) ? DesignProse::text($data) : null,
            'json_error' => $parseError,
        ];
    }

    /** @return array{0: bool, 1: string} */
    public function reset(string $projectId): array
    {
        [$latest] = $this->stageStore->latestStageForProject($projectId, PlanningStageName::YACHT_DESIGN, [], skipOrphans: true);

        if ($latest === null) {
            return [false, 'Chưa có lượt thiết kế nào.'];
        }

        return $this->stageStore->releaseClaim((string) $latest->id, 'Người dùng reset thủ công')
            ? [true, 'ok']
            : [false, 'Lượt này không còn giữ claim — không có gì để reset.'];
    }

    public static function instructionVersion(AiPhase $prompt): string
    {
        return "spec-v6.3-phase-3@rev{$prompt->revision}";
    }

    /** @return array{prompt: AiPhase, system: string, manifest: array<string, mixed>, payload: string, input: array<string, mixed>, dna: array<mixed>, source_identity: array<mixed>, project_id: string} */
    private function prepare(VideoProject $project): array
    {
        $dnaPanel = $this->dna->panel((string) $project->id);

        if (! $dnaPanel['phase3_ready'] || ! is_string($dnaPanel['stage_id'])) {
            throw new RuntimeException('Design DNA chưa đạt kiểm kỹ thuật cho đúng Phase 1 và prompt hiện tại.');
        }

        $dnaStage = VideoPlanningStage::query()->findOrFail($dnaPanel['stage_id']);
        $dna = JsonAnswer::object((string) ($dnaStage->output_json['raw_text'] ?? ''))['dna'] ?? null;
        $inspiration = $this->videoProjects->latestInspiration((string) $project->id);
        $phaseOne = is_string($inspiration['stage_id']) ? VideoPlanningStage::query()->find($inspiration['stage_id']) : null;
        $identity = PhaseOneEvidence::inspiration(JsonAnswer::object((string) ($phaseOne?->output_json['raw_text'] ?? '')))['source_identity'] ?? null;

        if (! is_array($dna) || ! is_array($identity)) {
            throw new RuntimeException('Không đọc được dna hoặc source_identity đã đạt.');
        }

        $category = $project->article?->category ?? throw new RuntimeException('Bài viết của dự án không có chủ đề.');
        $workflow = (string) (config('video.superyacht.contracts.categories')[$category->slug] ?? '');
        $bundle = PhaseResolver::fromConfig((array) config('video.superyacht'))->forProject((string) $project->id, self::ACTION);

        if (! $bundle->ready()) {
            throw new RuntimeException('Hợp đồng '.self::ACTION.' chưa sẵn sàng: '.implode('; ', $bundle->mismatches()).'.');
        }

        $manifest = $bundle->manifest();
        $prompt = AiPhase::query()->findOrFail($manifest['phase']['id']);

        foreach ([YachtDesignCheck::FORMAT_V3, 'Input format: `'.YachtDesignCheck::INPUT_FORMAT_V2.'`'] as $marker) {
            if (! str_contains((string) $prompt->prompt_content, $marker)) {
                throw new RuntimeException("Prompt phase-3 revision {$prompt->revision} không khai {$marker} mà code đang dùng — phát hành revision tương thích trước khi thiết kế, không gọi model.");
            }
        }

        $payload = (string) json_encode([
            'project_id' => (string) $project->id,
            'prompt_version' => self::instructionVersion($prompt),
            'variables' => (array) config('video.superyacht.variables'),
            'dna' => $dna,
            'novelty' => $dnaPanel['novelty'],
            'inspiration' => ['source_identity' => $identity],
            'model_sheet' => self::modelSheet(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $heading = (string) config('video.superyacht.design.schema_heading');
        $schemas = array_map(
            static fn (array $schema): string => "## {$heading} — {$schema['label']}\n\n```json\n".trim($schema['content'])."\n```",
            $bundle->outputSchemas(),
        );

        return [
            'prompt' => $prompt,
            'system' => implode("\n\n", [...array_column($bundle->parts(), 'content'), ...$schemas]),
            'manifest' => $manifest,
            'payload' => $payload,
            'dna' => $dna,
            'source_identity' => $identity,
            'project_id' => (string) $project->id,
            'input' => [
                'execution' => self::EXECUTION,
                'article_id' => (string) $project->article->id,
                'category_id' => (string) $category->id,
                'workflow' => $workflow,
                'bundle_hash' => $bundle->bundleHash(),
                'phase_id' => (string) $prompt->id,
                'phase_revision' => (int) $prompt->revision,
                'prompt_sha256' => (string) $prompt->content_sha256,
                'dna_stage_id' => (string) $dnaStage->id,
                'payload_sha256' => hash('sha256', $payload),
            ] + self::settings(),
        ];
    }

    /** @return array<string, mixed> the pinned model-sheet presentation the design writes its model sheet for, from the model-sheet config */
    private static function modelSheet(): array
    {
        $config = (array) config('video.superyacht.model_sheet');
        $cameras = ModelSheetQaService::cameras();

        return [
            'version' => (string) $config['master']['execution'],
            'cameras' => $cameras['views'],
            'camera_convention' => $cameras['convention'],
        ] + ModelSheetQaService::fixedRules([
            'views' => ModelSheetService::views($config, ModelSheetMaster::fromConfig()),
            'layout_guide' => ($config['master']['layout_guide'] ?? false) === true,
        ]);
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
     * @param  array{prompt: AiPhase, system: string, manifest: array<string, mixed>, payload: string, input: array<string, mixed>, dna: array<mixed>, source_identity: array<mixed>, project_id: string}  $run
     * @param  ?array<string, mixed>  $manifest  the bundle the stage was sent with
     * @return array{0: ?object, 1: array<string, mixed>}
     */
    private function evaluate(string $raw, array $run, ?array $manifest): array
    {
        [$decoded, $error] = JsonAnswer::parse($raw);
        $text = BuildStateText::fromConfig();

        if ($error !== null || ! is_object($decoded)) {
            return [null, YachtDesignCheck::evaluate(null, $run['project_id'], $run['dna'], $run['source_identity'], [], [], $text, self::limits())];
        }

        $canonical = $decoded->data->canonical ?? null;

        if (is_object($canonical)) {
            foreach (is_array($canonical->build_states ?? null) ? $canonical->build_states : [] as $state) {
                if (is_object($state)) {
                    $state->compiled_text = $text->compile((array) json_decode((string) json_encode($state), true)) ?? '';
                }
            }

            $canonical->hash = CanonicalJson::hash($canonical, ['hash']);
        }

        $schemaIssues = [];
        $wordLimits = [];

        try {
            $bundle = PhaseResolver::fromConfig((array) config('video.superyacht'))
                ->fromManifest($manifest ?? throw new RuntimeException('lượt này không lưu manifest hợp đồng'));

            if (($decoded->format ?? null) === YachtDesignCheck::FORMAT_V3) {
                $schemaIssues = PhaseOneEvidence::schemaErrors($decoded, $bundle->outputSchemas());
            } else {
                $result = $bundle->checkOutput((string) json_encode($decoded), $run['project_id'], 'draft');

                foreach ($result['report']->violations as $violation) {
                    $schemaIssues[] = trim($violation->path.' '.$violation->message);
                }
            }

            $wordLimits = $bundle->wordLimits();
        } catch (\Throwable $e) {
            $schemaIssues[] = 'không kiểm được schema: '.$e->getMessage();
        }

        $response = json_decode((string) json_encode($decoded), true);

        return [$decoded, YachtDesignCheck::evaluate(
            is_array($response) ? $response : null,
            $run['project_id'],
            $run['dna'],
            $run['source_identity'],
            self::forbiddenWords((string) $run['prompt']->prompt_content),
            $schemaIssues,
            $text,
            self::limits(),
            $wordLimits,
            is_array($response) && ($response['format'] ?? null) === YachtDesignCheck::FORMAT ? self::handoff($response) : [],
            self::instructionVersion($run['prompt']),
        )];
    }

    /**
     * @param  array<string, mixed>  $design  a Phase 3 answer with its canonical hash and build-state texts filled in
     * @return list<string> what keeps the model sheet from opening this design, by the model sheet's own blocking checks
     */
    public static function handoff(array $design): array
    {
        $config = (array) config('video.superyacht.model_sheet');

        try {
            $facts = new ModelSheetFacts(
                $design,
                array_map(static fn (array $view): array => ['id' => (string) $view['id'], 'view' => (string) $view['view']], array_values((array) $config['views'])),
                (array) $config['cameras'],
            );

            return ModelSheetFacts::blocking($facts->snapshot());
        } catch (\Throwable $e) {
            return ['không dựng được bảng dữ kiện model sheet: '.$e->getMessage()];
        }
    }

    /** @return array{profile_margin_m: float, near_x: float, near_z_m: float} */
    private static function limits(): array
    {
        $limits = (array) config('video.superyacht.design_check');

        return [
            'profile_margin_m' => (float) $limits['profile_margin_m'],
            'near_x' => (float) $limits['near_x'],
            'near_z_m' => (float) $limits['near_z_m'],
        ];
    }

    /** @param array{prompt: AiPhase, system: string, manifest: array<string, mixed>, payload: string, input: array<string, mixed>, dna: array<mixed>, source_identity: array<mixed>, project_id: string} $run */
    private function replaceable(VideoPlanningStage $served, array $run): bool
    {
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
     * @param  array{prompt: AiPhase, system: string, manifest: array<string, mixed>, payload: string, input: array<string, mixed>, dna: array<mixed>, source_identity: array<mixed>, project_id: string}  $run
     * @return array<string, mixed>
     */
    private function output(array $run, string $raw): array
    {
        [$filled, $check] = $this->evaluate($raw, $run, $run['manifest']);
        [, $error] = JsonAnswer::parse($raw);

        return [
            'execution' => self::EXECUTION,
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
