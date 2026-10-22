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
use App\Video\Llm\JsonAnswer;
use App\Video\Prompt\Exceptions\TextCompletionException;
use App\Video\Prompt\TextCompletionAccounting;
use App\Video\Prompt\TextCompletionClient;
use App\Video\Superyacht\Spec\PhaseResolver;
use App\Video\Superyacht\Support\CanonicalJson;
use App\Video\YachtDesign\BuildStateText;
use App\Video\YachtDesign\YachtDesignCheck;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class YachtDesignService
{
    public const EXECUTION = 'db-phase-3-v1';

    public const PHASE_CODE = 'phase-3';

    public const ACTION = 'spec.design';

    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly VideoProjectRepositoryInterface $projects,
        private readonly VideoProjectService $videoProjects,
        private readonly DesignDnaService $dna,
        private readonly TextCompletionClient $writer,
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

        $replaceFailing = fn (VideoPlanningStage $served): bool => $regenerate && $this->fails($served, $run);
        [$stage, $token, $reason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::YACHT_DESIGN, $run['input'], $replaceFailing, [
            'manifest' => $run['manifest'],
            'judge_gate' => DesignDnaService::judgesRequired() ? 'rule_and_judges' : 'rule_only',
        ]);

        if ($token === null) {
            return match (true) {
                $reason === 'already_succeeded' && $this->fails($stage, $run) => [null, 'Bản thiết kế đã lưu cho đúng DNA và prompt này chưa đạt kiểm RULE — bấm "Thiết kế lại" để tạo bản mới. Không gọi model.'],
                $reason === 'already_succeeded' => [$stage->output_json, 'cached'],
                $reason === 'claimed_by_other' => [null, 'Đang có một lượt thiết kế chạy cho dự án này — đợi xong rồi thử lại.'],
                default => [null, 'Không tìm thấy dự án.'],
            };
        }

        $model = (string) $run['input']['model'];
        $version = self::instructionVersion($run['prompt']);

        try {
            $response = $this->writer->complete(
                $model,
                $run['system'],
                $run['payload'],
                (int) $run['input']['max_tokens'],
            );
            [$usage, $pricing] = self::accounting($response->usage, $response->model, $response->inputTokens, $response->outputTokens, $model, $version);

            if ($response->wasTruncated()) {
                $this->stageStore->finishFailed($stage->id, $token, 'Bản thiết kế bị cắt ở trần token đầu ra — kết quả không hoàn chỉnh.', $usage, $response->text, [], $pricing);

                return [null, 'Bản thiết kế bị cắt ở trần token đầu ra — kết quả không hoàn chỉnh.'];
            }

            $output = $this->output($run, $response->text);

            if (! $this->stageStore->finishSucceeded($stage->id, $token, $response->text, $output, $usage, $pricing)) {
                try {
                    $this->stageStore->recordOrphanAttempt($projectId, PlanningStageName::YACHT_DESIGN, $run['input'], [], (string) $stage->id, $token, 'claim_lost', $usage, $response->text, $output);
                } catch (\Throwable $e) {
                    Log::warning('yacht design: recordOrphanAttempt threw', ['stage_id' => $stage->id, 'exception' => $e]);
                }

                return [null, 'Lượt thiết kế bị giành mất khi lưu — kết quả đã trả tiền không được dùng. Tải lại trang rồi bấm lại nếu cần.'];
            }

            return [$output, 'ok'];
        } catch (\Throwable $e) {
            Log::error('yacht design: the run failed', ['stage_id' => $stage->id, 'exception' => $e]);
            $billed = $e instanceof TextCompletionException ? $e : null;
            [$usage, $pricing] = self::accounting(
                $billed?->usage ?? [],
                (string) ($billed?->model ?? $model),
                (int) ($billed?->usage['prompt_tokens'] ?? 0),
                (int) ($billed?->usage['completion_tokens'] ?? 0),
                $model,
                $version,
            );
            $this->stageStore->finishFailed($stage->id, $token, $e->getMessage(), $usage, (string) ($billed?->raw ?? ''), [], $pricing);

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

        [$latest, $matchesInput] = $this->stageStore->latestStageForProject($projectId, PlanningStageName::YACHT_DESIGN, $run['input'] ?? [], skipOrphans: true);
        $served = $run === null ? null : $this->stageStore->latestSucceededForInput($projectId, PlanningStageName::YACHT_DESIGN, $run['input']);
        $empty = ['stage_id' => null, 'analysed' => false, 'status' => null, 'running' => false, 'stuck' => false, 'error' => null,
            'current' => false, 'check' => null, 'name' => null, 'loa' => null, 'yacht_anchor' => null, 'json' => null, 'json_error' => null];
        $base = [
            'can_run' => $run !== null && $served === null,
            'can_regenerate' => $run !== null && $served !== null && $this->fails($served, $run),
            'prompt' => $run === null ? null : self::label((int) $run['prompt']->revision),
            'run_error' => $runError,
        ];

        if ($latest === null) {
            return $base + $empty;
        }

        $succeeded = $latest->status === VideoPlanningStageStatus::SUCCEEDED->value;
        $claimed = $latest->status === VideoPlanningStageStatus::RUNNING->value;
        $stored = $succeeded && is_array($latest->output_json) ? $latest->output_json : [];
        $current = $succeeded && $matchesInput && $run !== null;
        [$filled, $check] = $current
            ? $this->evaluate((string) ($stored['raw_text'] ?? ''), $run, self::manifestOf($latest))
            : [null, is_array($stored['check'] ?? null) ? $stored['check'] : null];
        $data = is_object($filled) ? json_decode((string) json_encode($filled->data ?? null), true) : null;
        [, $parseError] = $succeeded ? JsonAnswer::parse((string) ($stored['raw_text'] ?? '')) : [null, null];

        return $base + [
            'stage_id' => (string) $latest->id,
            'analysed' => $succeeded,
            'status' => $latest->status,
            'running' => $claimed && $latest->lease_expires_at?->isFuture() === true,
            'stuck' => $claimed && $latest->lease_expires_at?->isFuture() !== true,
            'error' => $latest->error_message,
            'current' => $current,
            'check' => $check,
            'name' => is_array($data) ? ($data['yacht_bible']['name'] ?? null) : null,
            'loa' => is_array($data) ? ($data['yacht_bible']['specs']['loa']['value'] ?? null) : null,
            'yacht_anchor' => is_array($data) ? ($data['anchors']['yacht'] ?? null) : null,
            'json' => is_object($filled) ? (string) json_encode($filled, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
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
            throw new RuntimeException(DesignDnaService::judgesRequired()
                ? 'Design DNA chưa đạt đủ RULE, novelty và plausibility cho đúng Phase 1 và prompt hiện tại.'
                : 'Design DNA chưa đạt kiểm RULE cho đúng Phase 1 và prompt hiện tại.');
        }

        $dnaStage = VideoPlanningStage::query()->findOrFail($dnaPanel['stage_id']);
        $dna = JsonAnswer::object((string) ($dnaStage->output_json['raw_text'] ?? ''))['dna'] ?? null;
        $inspiration = $this->videoProjects->latestInspiration((string) $project->id);
        $phaseOne = is_string($inspiration['stage_id']) ? VideoPlanningStage::query()->find($inspiration['stage_id']) : null;
        $identity = JsonAnswer::object((string) ($phaseOne?->output_json['raw_text'] ?? ''))['inspiration']['source_identity'] ?? null;

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

        if (! str_contains((string) $prompt->prompt_content, YachtDesignCheck::FORMAT)) {
            throw new RuntimeException("Prompt phase-3 revision {$prompt->revision} chưa yêu cầu định dạng ".YachtDesignCheck::FORMAT.' — cần publish revision tương thích trước khi thiết kế.');
        }

        $payload = (string) json_encode([
            'project_id' => (string) $project->id,
            'prompt_version' => self::instructionVersion($prompt),
            'variables' => (array) config('video.superyacht.variables'),
            'dna' => $dna,
            'novelty' => $dnaPanel['novelty'],
            'inspiration' => ['source_identity' => $identity],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'prompt' => $prompt,
            'system' => implode("\n\n", array_column($bundle->parts(), 'content')),
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
            return [null, YachtDesignCheck::evaluate(null, $run['project_id'], $run['dna'], $run['source_identity'], [], [], $text)];
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

        try {
            $result = PhaseResolver::fromConfig((array) config('video.superyacht'))
                ->fromManifest($manifest ?? throw new RuntimeException('lượt này không lưu manifest hợp đồng'))
                ->checkOutput((string) json_encode($decoded), $run['project_id'], 'draft');

            foreach ($result['report']->violations as $violation) {
                $schemaIssues[] = trim($violation->path.' '.$violation->message);
            }
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
        )];
    }

    /** @param array{prompt: AiPhase, system: string, manifest: array<string, mixed>, payload: string, input: array<string, mixed>, dna: array<mixed>, source_identity: array<mixed>, project_id: string} $run */
    private function fails(VideoPlanningStage $served, array $run): bool
    {
        return $this->evaluate((string) (is_array($served->output_json) ? ($served->output_json['raw_text'] ?? '') : ''), $run, self::manifestOf($served))[1]['verdict'] !== YachtDesignCheck::PASS;
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

    /**
     * @param  array<string, mixed>  $raw
     * @return array{0: array<string, mixed>, 1: array{pricing: string, pricing_version: ?string}}
     */
    private static function accounting(array $raw, string $providerModel, int $input, int $output, string $model, string $version): array
    {
        [$usage, $pricing] = TextCompletionAccounting::measure($raw, $providerModel, $input, $output, (array) config('image_prompt.text_pricing'));

        return [$usage + ['model' => $model, 'instruction_version' => $version], $pricing];
    }

    private static function label(int $revision): string
    {
        return 'Prompt '.self::PHASE_CODE.' revision '.$revision;
    }
}
