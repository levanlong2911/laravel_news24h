<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Media\VideoModelRegistry;
use App\Video\Moto\MotoClipPlanBuilder;
use App\Video\Moto\MotoShotPrompt;
use App\Video\Render\Video\SceneClipDispatchService;
use App\Video\Superyacht\Spec\PhaseResolver;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reads the template, the source and the registry for the clip plan. A preview writes nothing; only a dispatch saves the plan it sends.
 */
final class MotoClipPlanService
{
    public function __construct(
        private readonly MotoClipSourceService $sources,
        private readonly VideoModelRegistry $registry,
        private readonly PlanningStageStore $stages,
    ) {}

    /**
     * @return array{contract: array<string, mixed>, template: string, schemas: list<array<string, mixed>>} the current phase-10 revision as the resolver gives it for the project
     *
     * @throws RuntimeException when the contract is not ready or its template breaks the field contract
     */
    public function contract(string $projectId): array
    {
        $flow = $this->sources->workflowFor($projectId);
        $resolved = PhaseResolver::fromConfig((array) config('video.superyacht'))->forProject($projectId, $flow['action']);

        if (! $resolved->ready()) {
            throw new RuntimeException('Hợp đồng Phase 10 chưa sẵn sàng: '.implode('; ', $resolved->mismatches()).'.');
        }

        $manifest = $resolved->manifest();
        $template = (string) (collect($resolved->parts())->firstWhere('code', $manifest['phase']['code'])['content'] ?? '');
        $problem = MotoShotPrompt::clipTemplateProblem($template);

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }

        $audio = (array) ($flow['settings']['audio'] ?? []);

        if (! is_string($audio['policy'] ?? null) || ! is_string($audio['line'] ?? null) || count(array_keys(preg_split('/\R/', $template) ?: [], $audio['line'], true)) !== 1) {
            throw new RuntimeException('Mẫu prompt clip không nói đúng một lần chính sách âm thanh đã chốt ('.($audio['line'] ?? 'chưa cấu hình').') — sửa bằng một revision mới của phase-10 cùng cấu hình âm thanh.');
        }

        return [
            'audio' => ['policy' => $audio['policy'], 'line' => $audio['line']],
            'contract' => [
                'phase_id' => (string) $manifest['phase']['id'],
                'phase_revision' => (int) $manifest['phase']['revision'],
                'prompt_version' => $flow['workflow'].'/'.$manifest['phase']['code'].'@rev'.$manifest['phase']['revision'],
                'prompt_sha256' => (string) $manifest['phase']['sha256'],
                'bundle_hash' => $resolved->bundleHash(),
                'compiler' => MotoShotPrompt::CLIP_COMPILER,
                'builder' => MotoClipPlanBuilder::VERSION,
                'audio' => ['policy' => $audio['policy'], 'line' => $audio['line']],
            ],
            'template' => $template,
            'schemas' => $resolved->outputSchemas(),
        ];
    }

    /** @return array<string, array<string, mixed>> the file checks by way of calling a model, read from config now; a plan pins them per clip so the page hash covers them and a later change of config shows as a page to reload */
    private static function media(): array
    {
        return [
            'interactions' => [
                'duration_tolerance_ms' => (int) config('video.omni.target.duration_tolerance_ms'),
                'av_drift_ms' => (int) config('video.omni.target.av_drift_tolerance_ms'),
                'output_sizes' => (array) config('video.omni.output_sizes'),
            ],
            'long_running' => [
                'duration_tolerance_ms' => (int) config('video.veo.duration_tolerance_ms'),
                'av_drift_ms' => (int) config('video.omni.target.av_drift_tolerance_ms'),
            ],
        ];
    }

    /** @return list<array<string, mixed>> the models the project's clip flow may send, as the registry describes them; none when the registry cannot be read */
    public function models(string $projectId): array
    {
        $allowed = (array) ($this->sources->workflowFor($projectId)['settings']['models'] ?? []);

        try {
            return array_values(array_filter($this->registry->forTask(SceneClipDispatchService::TASK), static fn (array $entry): bool => in_array($entry['id'], $allowed, true)));
        } catch (InvalidArgumentException $e) {
            Log::warning('Registry clip hỏng: '.$e->getMessage());

            return [];
        }
    }

    /**
     * @return array{source: array<string, mixed>, plan: array<string, mixed>, hash: string, keyframes: array<string, array<string, mixed>>, models: list<array<string, mixed>>, audio: array{policy: string, line: string}} the plan the sources build now, as the schema of the contract accepts it
     *
     * @throws RuntimeException when the contract or the shot plan cannot be used, or the plan breaks its schema
     */
    public function preview(string $projectId): array
    {
        $contract = $this->contract($projectId);
        $source = $this->sources->source($projectId);
        $keyframes = $this->sources->keyframes($projectId, $source, $this->sources->workflowFor($projectId)['settings']);
        $models = $this->models($projectId);
        $plan = MotoClipPlanBuilder::plan($projectId, $contract['contract'], $contract['template'], $source, $keyframes, $models, self::media());
        $errors = PhaseOneEvidence::schemaErrors(json_decode((string) json_encode($plan, JSON_THROW_ON_ERROR)), $contract['schemas']);

        if ($errors !== []) {
            throw new RuntimeException('Kế hoạch clip không đạt schema '.MotoClipPlanBuilder::SCHEMA.': '.implode('; ', array_slice($errors, 0, 3)).'.');
        }

        return ['source' => $source, 'plan' => $plan, 'hash' => CanonicalJson::hash(['plan' => $plan]), 'keyframes' => $keyframes, 'models' => $models, 'audio' => $contract['audio']];
    }

    /**
     * @param  array<string, mixed>  $preview  preview()
     * @return VideoPlanningStage the saved plan with this hash, saved once however often it is sent: a change of source or contract makes a new version and a request already frozen keeps its own copy
     *
     * @throws RuntimeException
     */
    public function save(string $projectId, array $preview): VideoPlanningStage
    {
        [$stage, $token, $reason] = $this->stages->claimProjectStage($projectId, PlanningStageName::CLIP_PLAN,
            ['execution' => MotoClipPlanBuilder::VERSION, 'fingerprint' => $preview['hash']], false);

        if ($token !== null) {
            $saved = ['plan' => $preview['plan'], 'hash' => $preview['hash']];

            if (! $this->stages->finishSucceeded((string) $stage->id, $token, (string) json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $saved)) {
                throw new RuntimeException('Không lưu được kế hoạch clip.');
            }
        } elseif ($stage === null || $stage->status !== VideoPlanningStageStatus::SUCCEEDED->value) {
            throw new RuntimeException('Kế hoạch clip đang được xử lý: '.$reason);
        }

        return $stage->fresh();
    }
}
