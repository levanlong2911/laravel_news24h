<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\AiPhase;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Services\Admin\ClaudeWriterService;
use App\Services\VideoProjectService;
use App\Video\DesignDna\DnaCheck;
use App\Video\Inspiration\ClaudeInspirationAnalyst;
use App\Video\Llm\JsonAnswer;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Llm\LlmUnavailable;
use App\Video\Prompt\Exceptions\TextCompletionException;
use App\Video\Prompt\TextCompletionAccounting;
use App\Video\Prompt\TextCompletionClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class DesignDnaService
{
    public const EXECUTION = 'db-phase-2-v1';

    public const JUDGE_EXECUTION = 'db-phase-2-judge-v1';

    public const PHASE_CODE = 'phase-2';

    public const MODEL = 'sonnet55';

    public const EFFORT = 'high';

    public const MAX_TOKENS = 32000;

    public const TIMEOUT_SECONDS = 300;

    /** @var array<string, PlanningStageName> */
    public const JUDGES = ['novelty' => PlanningStageName::DNA_NOVELTY, 'plausibility' => PlanningStageName::DNA_PLAUSIBILITY];

    private const RUBRIC_HEADING = '### Judge rubric format';

    private const RULES_HEADING = '### Absolute rules';

    public const WEB_SEARCH = 'not_run';

    private const SCORE_TOLERANCE = 0.1;

    public const TOTAL_RULE = '`total` is the arithmetic mean of the criterion scores, rounded to one decimal';

    /** @var array<string, mixed> */
    public const JUDGE_SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['criteria', 'total', 'closest_known'],
        'properties' => [
            'criteria' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['criterion', 'score', 'failing_element', 'suggested_fix'],
                    'properties' => [
                        'criterion' => ['type' => 'string'],
                        'score' => ['type' => 'number'],
                        'failing_element' => ['type' => ['string', 'null']],
                        'suggested_fix' => ['type' => ['string', 'null']],
                    ],
                ],
            ],
            'total' => ['type' => 'number'],
            'closest_known' => ['type' => ['string', 'null']],
        ],
    ];

    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly VideoProjectRepositoryInterface $projects,
        private readonly VideoProjectService $videoProjects,
        private readonly LlmClient $llm,
        private readonly TextCompletionClient $judge,
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

            return [null, 'Chưa gọi Sonnet: '.$e->getMessage()];
        }

        $replaceFailing = fn (VideoPlanningStage $served): bool => $regenerate && $this->replaceable($projectId, $served, $run);

        try {
            [$stage, $token, $reason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::DESIGN_DNA, $run['input'], $replaceFailing);
        } catch (\Throwable $e) {
            Log::error('design dna: could not open the run', ['project_id' => $projectId, 'exception' => $e]);

            return [null, 'Không mở được lượt tạo DNA — xem log.'];
        }

        if ($token === null) {
            return match (true) {
                $reason === 'already_succeeded' && $this->replaceable($projectId, $stage, $run) => [null, 'DNA đã lưu cho đúng Phase 1 và prompt này chưa đạt kiểm RULE hoặc JUDGE — bấm "Tạo lại DNA" để tạo bản mới. Không gọi Sonnet.'],
                $reason === 'already_succeeded' => [$stage->output_json, 'cached'],
                $reason === 'claimed_by_other' => [null, 'Đang có một lượt tạo DNA chạy cho dự án này — đợi xong rồi thử lại.'],
                default => [null, 'Không tìm thấy dự án.'],
            };
        }

        $version = self::instructionVersion($run['prompt']);
        $usage = [];
        $raw = '';

        try {
            $response = $this->llm->complete(new LlmRequest(
                (string) $run['prompt']->prompt_content,
                $run['payload'],
                $version,
                self::MODEL,
                self::MAX_TOKENS,
                null,
                self::EFFORT,
                self::TIMEOUT_SECONDS,
                1,
            ));
            $raw = $response->text;
            $usage = ClaudeInspirationAnalyst::usageOf($response, $version);
            $output = $this->output($run, $raw);

            if (! $this->stageStore->finishSucceeded($stage->id, $token, $raw, $output, $usage)) {
                return $this->claimLost($projectId, PlanningStageName::DESIGN_DNA, $run['input'], (string) $stage->id, $token, $usage, $raw, $output);
            }

            return [$output, 'ok'];
        } catch (\Throwable $e) {
            Log::error('design dna: the run failed', ['stage_id' => $stage->id, 'exception' => $e]);
            $billed = $e instanceof LlmUnavailable ? $e->billed : null;

            $this->stageStore->finishFailed(
                $stage->id,
                $token,
                $e->getMessage(),
                $usage !== [] ? $usage : ($billed === null ? ['model' => self::MODEL, 'instruction_version' => $version] : ClaudeInspirationAnalyst::usageOf($billed, $version)),
                $raw !== '' ? $raw : ($billed?->text ?? ''),
            );

            return [null, $e->getMessage()];
        }
    }

    /** @return array{0: ?array<string, mixed>, 1: string} */
    public function judge(string $projectId, string $kind, bool $rejudge = false): array
    {
        if (! self::judgesRequired()) {
            return [null, 'JUDGE đang tạm tắt — không chấm và không gọi model.'];
        }

        $stageName = self::JUDGES[$kind] ?? null;
        $project = $this->projects->getById($projectId);

        if ($stageName === null || $project === null || $project->article === null) {
            return [null, 'Không tìm thấy dự án hoặc loại JUDGE không hợp lệ.'];
        }

        try {
            $run = $this->prepare($project);
            $served = $this->stageStore->latestSucceededForInput($projectId, PlanningStageName::DESIGN_DNA, $run['input'])
                ?? throw new RuntimeException('chưa có DNA cho đúng Phase 1 và prompt hiện tại.');

            if ($this->ruleVerdict($served, $run) !== DnaCheck::PASS) {
                throw new RuntimeException('DNA hiện hành chưa đạt kiểm RULE.');
            }

            $judgeRun = $this->judgeRun($run, $served, $kind);
        } catch (\Throwable $e) {
            Log::error('design dna judge: input unavailable, no model call made', ['project_id' => $projectId, 'kind' => $kind, 'exception' => $e]);

            return [null, 'Chưa gọi JUDGE: '.$e->getMessage()];
        }

        $unreadable = static fn (VideoPlanningStage $judged): bool => self::judgeOutput($kind, $served, (string) $judged->raw_response, $judgeRun['threshold'])['verdict'] === DnaCheck::UNSCORED;
        [$stage, $token, $reason] = $this->stageStore->claimProjectStage(
            $projectId,
            $stageName,
            $judgeRun['input'],
            static fn (VideoPlanningStage $judged): bool => $rejudge && $unreadable($judged),
        );

        if ($token === null) {
            return match (true) {
                $reason === 'already_succeeded' && $unreadable($stage) => [null, "Kết quả JUDGE {$kind} đã lưu không đọc được — bấm \"Chấm lại\" để chấm bản mới. Không gọi model."],
                $reason === 'already_succeeded' => [$stage->output_json, 'cached'],
                $reason === 'claimed_by_other' => [null, "Đang có một lượt JUDGE {$kind} chạy cho dự án này — đợi xong rồi thử lại."],
                default => [null, 'Không tìm thấy dự án.'],
            };
        }

        $model = (string) $judgeRun['input']['model'];
        $version = "spec-v6.3-p2-judge-{$kind}@rev{$run['prompt']->revision}";

        try {
            $response = $this->judge->complete(
                $model,
                $judgeRun['system'],
                $judgeRun['user'],
                (int) $judgeRun['input']['max_tokens'],
                self::JUDGE_SCHEMA,
            );
            [$usage, $pricing] = self::accounting($response->usage, $response->model, $response->inputTokens, $response->outputTokens, $model, $version);

            if ($response->wasTruncated()) {
                $this->stageStore->finishFailed($stage->id, $token, 'JUDGE bị cắt ở trần token đầu ra — kết quả không hoàn chỉnh.', $usage, $response->text, [], $pricing);

                return [null, 'JUDGE bị cắt ở trần token đầu ra — kết quả không hoàn chỉnh.'];
            }

            $output = self::judgeOutput($kind, $served, $response->text, $judgeRun['threshold']);

            if (! $this->stageStore->finishSucceeded($stage->id, $token, $response->text, $output, $usage, $pricing)) {
                return $this->claimLost($projectId, $stageName, $judgeRun['input'], (string) $stage->id, $token, $usage, $response->text, $output);
            }

            return [$output, 'ok'];
        } catch (\Throwable $e) {
            Log::error('design dna judge: the run failed', ['stage_id' => $stage->id, 'kind' => $kind, 'exception' => $e]);
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

        [$latest, $matchesInput] = $this->stageStore->latestStageForProject(
            $projectId,
            PlanningStageName::DESIGN_DNA,
            $run['input'] ?? [],
            skipOrphans: true,
        );
        $next = $run === null ? null : self::label((int) $run['prompt']->revision, (string) $run['input']['workflow']);
        $served = $run === null ? null : $this->stageStore->latestSucceededForInput($projectId, PlanningStageName::DESIGN_DNA, $run['input']);
        $canRun = $run !== null && $served === null;
        $canRegenerate = $run !== null && $served !== null && $this->replaceable($projectId, $served, $run);
        $empty = ['stage_id' => null, 'analysed' => false, 'status' => null, 'running' => false, 'stuck' => false, 'error' => null,
            'source' => null, 'current' => false, 'check' => null, 'judges' => [], 'judges_required' => self::judgesRequired(), 'novelty' => null, 'phase3_ready' => false, 'json' => null, 'json_error' => null];

        if ($latest === null) {
            return ['can_run' => $canRun, 'can_regenerate' => false, 'prompt' => $next, 'run_error' => $runError] + $empty;
        }

        $succeeded = $latest->status === VideoPlanningStageStatus::SUCCEEDED->value;
        $claimed = $latest->status === VideoPlanningStageStatus::RUNNING->value;
        $stored = $succeeded && is_array($latest->output_json) ? $latest->output_json : [];
        $raw = (string) ($stored['raw_text'] ?? '');
        $current = $succeeded && $matchesInput && $run !== null;
        $judged = self::judgesRequired();
        $judges = $judged && $current && $this->ruleVerdict($latest, $run) === DnaCheck::PASS ? $this->judgePanel($projectId, $latest, $run) : [];
        $check = $current
            ? DnaCheck::evaluate(JsonAnswer::object($raw), $run['inspiration'], $run['flags'], $judges['novelty']['verdict'] ?? DnaCheck::NOT_CHECKED, $judges['plausibility']['verdict'] ?? DnaCheck::NOT_CHECKED)
            : (is_array($stored['check'] ?? null) ? $stored['check'] : null);
        [$parsed, $parseError] = $succeeded ? JsonAnswer::parse($raw) : [null, null];
        $recorded = is_array($latest->input_json) ? $latest->input_json : [];
        $phase3Ready = $current && ($judged ? ($check['phase3_ready'] ?? false) === true : ($check['verdict'] ?? null) === DnaCheck::PASS);
        $novelty = match (true) {
            ! $phase3Ready => null,
            $judged => ['judge_score' => $judges['novelty']['total'], 'closest_known' => $judges['novelty']['closest_known']],
            default => self::ownNovelty($raw),
        };

        return [
            'stage_id' => (string) $latest->id,
            'analysed' => $succeeded,
            'status' => $latest->status,
            'running' => $claimed && $latest->lease_expires_at?->isFuture() === true,
            'stuck' => $claimed && $latest->lease_expires_at?->isFuture() !== true,
            'error' => $latest->error_message,
            'can_run' => $canRun,
            'prompt' => $next,
            'run_error' => $runError,
            'source' => isset($recorded['phase_revision']) ? self::label((int) $recorded['phase_revision'], (string) ($recorded['workflow'] ?? '')) : null,
            'current' => $current,
            'check' => $check,
            'judges' => $judges,
            'judges_required' => $judged,
            'novelty' => $novelty,
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

    /** @return array{prompt: AiPhase, payload: string, input: array<string, mixed>, inspiration: array<mixed>, flags: list<string>} */
    private function prepare(VideoProject $project): array
    {
        $phaseOne = $this->videoProjects->latestInspiration((string) $project->id);

        if (! $phaseOne['source_passed'] || ! is_string($phaseOne['stage_id'])) {
            throw new RuntimeException('Phase 1 chưa đạt đánh giá nguồn, hoặc đánh giá không còn khớp bài và prompt hiện tại.');
        }

        $stage = VideoPlanningStage::query()->findOrFail($phaseOne['stage_id']);
        $inspiration = JsonAnswer::object((string) ($stage->output_json['raw_text'] ?? ''))['inspiration'] ?? null;

        if (! is_array($inspiration) || array_is_list($inspiration)) {
            throw new RuntimeException('Không đọc được khối inspiration của lượt Phase 1 đã đạt.');
        }

        $category = $project->article?->category ?? throw new RuntimeException('Bài viết của dự án không có chủ đề.');
        $workflow = config('video.superyacht.contracts.categories')[$category->slug] ?? null;

        if (! is_string($workflow) || $workflow === '') {
            throw new RuntimeException("Chủ đề {$category->slug} chưa cấu hình workflow.");
        }

        $prompt = AiPhase::currentFor((string) $category->id, $workflow, self::PHASE_CODE);

        if (! str_contains((string) $prompt->prompt_content, DnaCheck::FORMAT)) {
            throw new RuntimeException("Prompt phase-2 revision {$prompt->revision} chưa yêu cầu định dạng ".DnaCheck::FORMAT.' — cần publish revision tương thích trước khi tạo DNA.');
        }

        $flags = array_values(array_filter((array) ($phaseOne['source_check']['flags'] ?? []), 'is_string'));
        $payload = (string) json_encode(['inspiration' => $inspiration, 'flags' => $flags], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'prompt' => $prompt,
            'payload' => $payload,
            'inspiration' => $inspiration,
            'flags' => $flags,
            'input' => [
                'execution' => self::EXECUTION,
                'article_id' => (string) $project->article->id,
                'category_id' => (string) $category->id,
                'workflow' => $workflow,
                'phase_id' => (string) $prompt->id,
                'phase_revision' => (int) $prompt->revision,
                'prompt_sha256' => (string) $prompt->content_sha256,
                'inspiration_stage_id' => (string) $stage->id,
                'payload_sha256' => hash('sha256', $payload),
                'model' => self::MODEL,
                'provider_model' => ClaudeWriterService::modelId(self::MODEL),
                'effort' => self::EFFORT,
                'max_tokens' => self::MAX_TOKENS,
            ],
        ];
    }

    /**
     * @param  array{prompt: AiPhase, payload: string, input: array<string, mixed>, inspiration: array<mixed>, flags: list<string>}  $run
     * @return array{system: string, user: string, threshold: float, input: array<string, mixed>}
     */
    private function judgeRun(array $run, VideoPlanningStage $dna, string $kind): array
    {
        $standards = AiPhase::currentFor((string) $run['input']['category_id'], (string) $run['input']['workflow'], 'common-standards');
        $rubric = self::section((string) $standards->prompt_content, self::RUBRIC_HEADING)
            ?? throw new RuntimeException('common-standards revision '.$standards->revision.' không có mục "Judge rubric format".');

        if (! str_contains($rubric, self::TOTAL_RULE)) {
            throw new RuntimeException('common-standards revision '.$standards->revision.' chưa ghi cách tính total (trung bình cộng) trong "Judge rubric format" — cần publish revision tương thích trước khi chấm.');
        }
        $rules = self::section((string) $run['prompt']->prompt_content, self::RULES_HEADING)
            ?? throw new RuntimeException("prompt phase-2 revision {$run['prompt']->revision} không có mục \"Absolute rules\".");
        $gate = self::gateLine((string) $run['prompt']->prompt_content, $kind)
            ?? throw new RuntimeException("prompt phase-2 revision {$run['prompt']->revision} không có dòng JUDGE {$kind}.");

        if (preg_match('/≥\s*(\d+(?:\.\d+)?)\s*\/\s*10/u', $gate, $match) !== 1) {
            throw new RuntimeException("dòng JUDGE {$kind} không ghi ngưỡng điểm.");
        }

        $raw = (string) (is_array($dna->output_json) ? ($dna->output_json['raw_text'] ?? '') : '');
        $user = (string) json_encode([
            'rubric' => $gate,
            'phase_rules' => $rules,
            'output' => JsonAnswer::object($raw),
        ] + ($kind === 'novelty' ? ['web_search' => self::WEB_SEARCH] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'system' => $rubric,
            'user' => $user,
            'threshold' => (float) $match[1],
            'input' => [
                'execution' => self::JUDGE_EXECUTION,
                'kind' => $kind,
                'dna_stage_id' => (string) $dna->id,
                'dna_sha256' => hash('sha256', $raw),
                'phase_id' => (string) $run['prompt']->id,
                'phase_revision' => (int) $run['prompt']->revision,
                'prompt_sha256' => (string) $run['prompt']->content_sha256,
                'standards_id' => (string) $standards->id,
                'standards_revision' => (int) $standards->revision,
                'standards_sha256' => (string) $standards->content_sha256,
                'user_sha256' => hash('sha256', $user),
                'schema_sha256' => hash('sha256', (string) json_encode(self::JUDGE_SCHEMA)),
            ] + self::judgeSettings(),
        ];
    }

    /**
     * @param  array{prompt: AiPhase, payload: string, input: array<string, mixed>, inspiration: array<mixed>, flags: list<string>}  $run
     * @return array<string, array<string, mixed>>
     */
    private function judgePanel(string $projectId, VideoPlanningStage $dna, array $run): array
    {
        $panel = [];

        foreach (self::JUDGES as $kind => $stageName) {
            try {
                $judgeRun = $this->judgeRun($run, $dna, $kind);
            } catch (\Throwable $e) {
                $panel[$kind] = ['verdict' => DnaCheck::NOT_CHECKED, 'total' => null, 'threshold' => null, 'closest_known' => null, 'web_search' => null, 'failing' => [],
                    'can_run' => false, 'can_rejudge' => false, 'running' => false, 'error' => null, 'run_error' => $e->getMessage()];

                continue;
            }

            [$latest, $matches] = $this->stageStore->latestStageForProject($projectId, $stageName, $judgeRun['input'], skipOrphans: true);
            $served = $this->stageStore->latestSucceededForInput($projectId, $stageName, $judgeRun['input']);
            $result = $served === null ? null : self::judgeOutput($kind, $dna, (string) $served->raw_response, $judgeRun['threshold']);
            $running = $latest !== null && $matches && $latest->status === VideoPlanningStageStatus::RUNNING->value && $latest->lease_expires_at?->isFuture() === true;

            $panel[$kind] = [
                'verdict' => $result === null ? DnaCheck::NOT_CHECKED : ($result['verdict'] === DnaCheck::UNSCORED ? DnaCheck::NOT_CHECKED : $result['verdict']),
                'total' => $result['total'] ?? null,
                'threshold' => $judgeRun['threshold'],
                'closest_known' => $result['closest_known'] ?? null,
                'web_search' => $result['web_search'] ?? null,
                'failing' => $result['failing'] ?? [],
                'can_run' => $served === null && ! $running,
                'can_rejudge' => $result !== null && $result['verdict'] === DnaCheck::UNSCORED && ! $running,
                'running' => $running,
                'error' => $result['error'] ?? ($latest !== null && $matches && $latest->status === VideoPlanningStageStatus::FAILED->value ? $latest->error_message : null),
                'run_error' => null,
            ];
        }

        return $panel;
    }

    /** @return array<string, mixed> */
    private static function judgeOutput(string $kind, VideoPlanningStage $dna, string $raw, float $threshold): array
    {
        $answer = JsonAnswer::object($raw);
        $criteria = $answer['criteria'] ?? null;
        $inRange = static fn (mixed $score): bool => is_numeric($score) && (float) $score >= 0.0 && (float) $score <= 10.0;
        $shaped = is_array($criteria) && $criteria !== [] && array_is_list($criteria)
            && $inRange($answer['total'] ?? null)
            && array_key_exists('closest_known', $answer ?? []) && ($answer['closest_known'] === null || is_string($answer['closest_known']))
            && array_filter($criteria, static fn (mixed $item): bool => ! is_array($item) || ! is_string($item['criterion'] ?? null) || ! $inRange($item['score'] ?? null)) === [];
        $mean = $shaped ? round(array_sum(array_map(static fn (array $item): float => (float) $item['score'], $criteria)) / count($criteria), 1) : null;
        $valid = $shaped && abs((float) $answer['total'] - $mean) <= self::SCORE_TOLERANCE;
        $total = $valid ? $mean : null;
        $error = match (true) {
            $valid => null,
            $shaped => 'Tổng '.$answer['total'].' không khớp trung bình các điểm thành phần ('.$mean.').',
            default => 'Phản hồi JUDGE không đúng cấu trúc hoặc có điểm ngoài khoảng 0–10.',
        };
        $failing = [];

        foreach ($valid ? $criteria : [] as $item) {
            if (is_string($item['failing_element'] ?? null) && trim($item['failing_element']) !== '') {
                $failing[] = $item['criterion'].' ('.$item['score'].'/10): '.$item['failing_element'].(is_string($item['suggested_fix'] ?? null) ? ' — '.$item['suggested_fix'] : '');
            }
        }

        return [
            'execution' => self::JUDGE_EXECUTION,
            'kind' => $kind,
            'dna_stage_id' => (string) $dna->id,
            'threshold' => $threshold,
            'verdict' => $total === null ? DnaCheck::UNSCORED : ($total >= $threshold ? DnaCheck::PASS : DnaCheck::FAIL),
            'total' => $total,
            'closest_known' => $valid ? $answer['closest_known'] : null,
            'web_search' => $kind === 'novelty' ? self::WEB_SEARCH : null,
            'failing' => $failing,
            'raw_text' => $raw,
            'error' => $error,
        ];
    }

    /** @param array{prompt: AiPhase, payload: string, input: array<string, mixed>, inspiration: array<mixed>, flags: list<string>} $run */
    private function replaceable(string $projectId, VideoPlanningStage $served, array $run): bool
    {
        if ($this->ruleVerdict($served, $run) !== DnaCheck::PASS) {
            return true;
        }

        if (! self::judgesRequired()) {
            return false;
        }

        foreach ($this->judgePanel($projectId, $served, $run) as $judge) {
            if ($judge['verdict'] === DnaCheck::FAIL) {
                return true;
            }
        }

        return false;
    }

    public static function judgesRequired(): bool
    {
        return (bool) config('video.superyacht.phase3_requires_judges');
    }

    /** @return array{model: string, effort: string, max_tokens: int, timeout_seconds: int, stream: bool, retry_times: int} */
    public static function judgeSettings(): array
    {
        $judge = (array) config('video.superyacht.judge');

        return [
            'model' => (string) $judge['model'],
            'effort' => (string) $judge['effort'],
            'max_tokens' => (int) $judge['max_tokens'],
            'timeout_seconds' => (int) $judge['timeout_seconds'],
            'stream' => (bool) $judge['stream'],
            'retry_times' => (int) $judge['retry_times'],
        ];
    }

    /** @return array{judge_score: mixed, closest_known: mixed} */
    private static function ownNovelty(string $raw): array
    {
        $novelty = JsonAnswer::object($raw)['novelty'] ?? [];

        return ['judge_score' => $novelty['judge_score'] ?? null, 'closest_known' => $novelty['closest_known'] ?? null];
    }

    /** @param array{prompt: AiPhase, payload: string, input: array<string, mixed>, inspiration: array<mixed>, flags: list<string>} $run */
    private function ruleVerdict(VideoPlanningStage $served, array $run): string
    {
        $raw = (string) (is_array($served->output_json) ? ($served->output_json['raw_text'] ?? '') : '');

        return DnaCheck::evaluate(JsonAnswer::object($raw), $run['inspiration'], $run['flags'])['verdict'];
    }

    /**
     * @param  array{prompt: AiPhase, payload: string, input: array<string, mixed>, inspiration: array<mixed>, flags: list<string>}  $run
     * @return array<string, mixed>
     */
    private function output(array $run, string $raw): array
    {
        [, $error] = JsonAnswer::parse($raw);

        return [
            'execution' => self::EXECUTION,
            'prompt' => array_intersect_key($run['input'], array_flip(['category_id', 'workflow', 'phase_id', 'phase_revision', 'prompt_sha256'])),
            'inspiration_stage_id' => $run['input']['inspiration_stage_id'],
            'flags' => $run['flags'],
            'raw_text' => $raw,
            'json_parse_error' => $error,
            'check' => DnaCheck::evaluate(JsonAnswer::object($raw), $run['inspiration'], $run['flags']),
        ];
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

    private static function section(string $text, string $heading): ?string
    {
        $start = mb_strpos($text, $heading);

        if ($start === false) {
            return null;
        }

        $rest = mb_substr($text, $start);

        return preg_match('/\A(.+?)(?=\n#{2,3} |\z)/s', $rest, $match) === 1 ? trim($match[1]) : null;
    }

    private static function gateLine(string $prompt, string $kind): ?string
    {
        foreach (preg_split('/\R/u', $prompt) ?: [] as $line) {
            if (mb_stripos($line, '**JUDGE — '.$kind) !== false) {
                return trim($line);
            }
        }

        return null;
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
