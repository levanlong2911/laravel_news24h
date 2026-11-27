<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Repositories\Interfaces\VideoPlanningStageRepositoryInterface;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Media\VideoModelRegistry;
use App\Video\Moto\MotoClipPlanBuilder;
use App\Video\Moto\MotoClipPrompt;
use App\Video\Moto\MotoContract;
use App\Video\Render\Video\SceneClipDispatchService;
use App\Video\Superyacht\Spec\PhaseResolver;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reads the approved video prompts, the source and the registry for the clip plan. A preview writes nothing and calls no model; only a dispatch saves the plan it sends.
 */
final class MotoClipPlanService
{
    public function __construct(
        private readonly MotoClipSourceService $sources,
        private readonly VideoModelRegistry $registry,
        private readonly PlanningStageStore $stages,
        private readonly ClipPromptWriterService $writer,
        private readonly PhaseResolver $resolver,
        private readonly VideoProjectRepositoryInterface $projects,
        private readonly VideoPlanningStageRepositoryInterface $planningStages,
    ) {}

    /**
     * @return array{audio: array{policy: string, prefix: string, closing: list<string>}, contract: array<string, mixed>, schemas: list<array<string, mixed>>, execution: ?array<string, mixed>} the current phase-10 revision as the resolver gives it for the project, the sound policy its prompts follow, the schema of the clip plan it pins and the execution policy of the release, null for a release whose plan is of an earlier format
     *
     * @throws RuntimeException when the contract is not ready, the current revision is not the video prompt writer this code reads, the sound policy is not configured, or the plan schema the revision pins and the execution policy of the release do not go together
     */
    public function contract(string $projectId): array
    {
        $flow = $this->sources->workflowFor($projectId);
        $resolved = $this->resolver->forProject($projectId, $flow['action']);

        if (! $resolved->ready()) {
            throw new RuntimeException('Hợp đồng Phase 10 chưa sẵn sàng: '.implode('; ', $resolved->mismatches()).'.');
        }

        $manifest = $resolved->manifest();
        $text = (string) (collect($resolved->parts())->firstWhere('code', $manifest['phase']['code'])['content'] ?? '');

        $project = $this->projects->findProject($projectId);
        $inputFormat = $project === null ? MotoContract::CLIP_PROMPT_INPUT : \App\Video\Moto\MotoClipPrompt::inputFormatFor($project);

        if (! str_contains($text, MotoContract::CLIP_PROMPT_FORMAT) || ! str_contains($text, 'Input format: `'.$inputFormat.'`')) {
            throw new RuntimeException('Phase 10 revision '.$manifest['phase']['revision'].' không phải bản AI viết prompt video ('.MotoContract::CLIP_PROMPT_FORMAT.', đầu vào '.$inputFormat.') code đang dùng — chạy php artisan db:seed --class=AiPhaseSeeder.');
        }

        $audio = MotoClipPrompt::soundPolicy((array) ($flow['settings']['audio'] ?? []));

        if ($audio === null) {
            throw new RuntimeException('Chính sách âm thanh của luồng clip chưa cấu hình đúng (video.clips.workflows.*.audio: policy, prefix, closing).');
        }

        $schemas = array_values(array_filter($resolved->outputSchemas(), static fn (array $schema): bool => str_starts_with((string) $schema['label'], 'moto/MotoClipPlan@')));
        $execution = $this->sources->execution($projectId);
        $pinned = in_array('moto/'.MotoContract::CLIP_PLAN_SCHEMA_V3, array_column($schemas, 'label'), true);

        if ($execution !== null && ! $pinned) {
            throw new RuntimeException('Bản phát hành khai chính sách thực thi clip nhưng Phase 10 revision '.$manifest['phase']['revision'].' ghim '.implode(', ', array_column($schemas, 'label')).' — cần revision ghim '.MotoContract::CLIP_PLAN_SCHEMA_V3.'.');
        }

        if ($execution === null && $pinned) {
            throw new RuntimeException('Phase 10 revision '.$manifest['phase']['revision'].' ghim '.MotoContract::CLIP_PLAN_SCHEMA_V3.' nhưng bản phát hành không khai chính sách thực thi clip (policy.clip_execution).');
        }

        return [
            'audio' => $audio,
            'contract' => [
                'phase_id' => (string) $manifest['phase']['id'],
                'phase_revision' => (int) $manifest['phase']['revision'],
                'prompt_version' => $flow['workflow'].'/'.$manifest['phase']['code'].'@rev'.$manifest['phase']['revision'],
                'prompt_sha256' => (string) $manifest['phase']['sha256'],
                'bundle_hash' => $resolved->bundleHash(),
                'writer' => MotoClipPrompt::checkVersionFor($inputFormat),
                'builder' => $execution === null ? MotoClipPlanBuilder::VERSION : MotoClipPlanBuilder::VERSION_EXECUTION,
                'audio' => $audio,
            ],
            'schemas' => $schemas,
            'execution' => $execution,
        ];
    }

    /** @return array<string, array<string, mixed>> the file checks by way of calling a model, read from config now; a plan pins them per clip so the page hash covers them and a later change of config shows as a page to reload. reference is how an image sent through interactions is made smaller before it is sent, which the hash of an execution plan covers; output_sizes of long_running are the frame sizes a long-running model was measured to write, which only a plan of an execution policy pins */
    private static function media(): array
    {
        return [
            'interactions' => [
                'duration_tolerance_ms' => (int) config('video.omni.target.duration_tolerance_ms'),
                'av_drift_ms' => (int) config('video.omni.target.av_drift_tolerance_ms'),
                'output_sizes' => (array) config('video.omni.output_sizes'),
                'reference' => (array) config('video.omni.reference'),
            ],
            'long_running' => [
                'duration_tolerance_ms' => (int) config('video.veo.duration_tolerance_ms'),
                'av_drift_ms' => (int) config('video.omni.target.av_drift_tolerance_ms'),
                'output_sizes' => (array) config('video.veo.output_sizes'),
            ],
        ];
    }

    /** @return list<array<string, mixed>> the models the project's clip flow may send, as the registry describes them: under an execution policy the ones the policy offers, else the ones the flow allows; none when the registry cannot be read */
    public function models(string $projectId): array
    {
        $execution = $this->sources->execution($projectId);

        if ($execution !== null) {
            return $this->sources->executionModels($execution);
        }

        $allowed = (array) ($this->sources->workflowFor($projectId)['settings']['models'] ?? []);

        try {
            return array_values(array_filter($this->registry->forTask(SceneClipDispatchService::TASK), static fn (array $entry): bool => in_array($entry['id'], $allowed, true)));
        } catch (InvalidArgumentException $e) {
            Log::warning('Registry clip hỏng: '.$e->getMessage());

            return [];
        }
    }

    /**
     * @return array{source: array<string, mixed>, plan: array<string, mixed>, hash: string, keyframes: array<string, array<string, mixed>>, models: list<array<string, mixed>>, audio: array{policy: string, prefix: string, closing: list<string>}, options: ?list<array<string, mixed>>} the plan the sources build now, as the schema of the contract accepts it; under an execution policy also every offer of the policy a screen can show (MotoClipPlanBuilder::options()), null otherwise
     *
     * @throws RuntimeException when the contract or the shot plan cannot be used, or the plan breaks its schema
     */
    public function preview(string $projectId): array
    {
        $contract = $this->contract($projectId);
        $source = $this->sources->source($projectId);
        $settings = $this->sources->workflowFor($projectId)['settings'];
        $frames = $this->sources->frameContext($projectId);
        $keyframes = $this->sources->keyframes($projectId, $source, $settings, $frames);
        $inputs = $this->sources->inputs($projectId, $source, $settings, $frames);
        $prompts = $this->writer->current($projectId, $source, $keyframes, $inputs);
        $models = $this->models($projectId);
        $plan = MotoClipPlanBuilder::plan($projectId, $contract['contract'], $prompts, $source, $keyframes, $models, self::media(), $inputs, $contract['execution']);
        $errors = PhaseOneEvidence::schemaErrors(json_decode((string) json_encode($plan, JSON_THROW_ON_ERROR)), $contract['schemas']);

        if ($errors !== []) {
            throw new RuntimeException('Kế hoạch clip không đạt schema '.$plan['schema'].': '.implode('; ', array_slice($errors, 0, 3)).'.');
        }

        return ['source' => $source, 'plan' => $plan, 'hash' => CanonicalJson::hash(['plan' => $plan]), 'keyframes' => $keyframes, 'inputs' => $inputs, 'prompts' => $prompts, 'models' => $models, 'audio' => $contract['audio'],
            'options' => $contract['execution'] === null ? null : MotoClipPlanBuilder::options($contract['execution'], $models, (string) ($source['context']['aspect_ratio'] ?? ''))];
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
            ['execution' => (string) $preview['plan']['contract']['builder'], 'fingerprint' => $preview['hash']], false);

        if ($token !== null) {
            $saved = ['plan' => $preview['plan'], 'hash' => $preview['hash']];

            if (! $this->stages->finishSucceeded((string) $stage->id, $token, (string) json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $saved)) {
                throw new RuntimeException('Không lưu được kế hoạch clip.');
            }
        } elseif ($stage === null || $stage->status !== VideoPlanningStageStatus::SUCCEEDED->value) {
            throw new RuntimeException('Kế hoạch clip đang được xử lý: '.$reason);
        }

        return $this->planningStages->freshStage($stage);
    }
}
