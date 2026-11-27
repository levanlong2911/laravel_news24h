<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Models\VideoPlanningStage;
use App\Repositories\Interfaces\AiPhaseRepositoryInterface;
use App\Repositories\Interfaces\VideoArtifactRepositoryInterface;
use App\Repositories\Interfaces\VideoPlanningStageRepositoryInterface;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Repositories\Interfaces\VideoReviewDecisionRepositoryInterface;
use App\Video\Llm\LlmClient;
use App\Video\Moto\MotoClipPlanBuilder;
use App\Video\Moto\MotoClipPrompt;
use App\Video\Moto\MotoContract;
use App\Video\Script\ScriptEvaluation;
use App\Video\Superyacht\Spec\PhaseResolver;
use RuntimeException;

final class ClipPromptWriterService extends ShotPromptWriterService
{
    /** @var string the key of the project metadata that holds, by shot plan and shot, the video prompt a person approved */
    public const SELECTION = 'moto_clip_prompts';

    public const ENTITY_TYPE = 'clip_prompt';

    protected const STAGE = PlanningStageName::CLIP_PROMPT;

    protected const STEP = 'clip_prompts';

    protected const LABEL = 'viết prompt video';

    protected const NOUN = 'prompt video';

    protected const TASK = 'clip prompt';

    protected const PHASE = 'Phase 10';

    protected const WORKFLOWS = 'video.clip_prompts.workflows';

    protected const ADAPTER = MotoClipPrompt::class;

    public const TEXT_KEY = 'video_prompt';

    /** @var array<string, array{source: array<string, mixed>, keyframes: array<string, array<string, mixed>>, inputs: ?array<string, array<string, mixed>>}> by project, the shot plan, keyframes and reference inputs a screen has already read, so a stored run is verified from them and not read again */
    private array $given = [];

    /** @var array<string, array<string, mixed>> the package of a stored run by project, shot and the contract the run pinned; a package of a new run is never kept here */
    private array $stored = [];

    /** @var array<string, ?string> by approved shot plan, why its pinned chain of sources no longer holds, null when it holds */
    private array $chains = [];

    public function __construct(
        PlanningStageStore $stageStore,
        LlmClient $llm,
        private readonly MotoClipSourceService $clips,
        PromptSelectionReader $selections,
        VideoProjectRepositoryInterface $projects,
        VideoPlanningStageRepositoryInterface $stages,
        VideoReviewDecisionRepositoryInterface $decisions,
        VideoArtifactRepositoryInterface $artifacts,
        AiPhaseRepositoryInterface $phases,
        PhaseResolver $resolver,
        ScriptEvaluation $evaluation,
    ) {
        parent::__construct($stageStore, $llm, $selections, $projects, $stages, $decisions, $artifacts, $phases, $resolver, $evaluation);
    }

    /**
     * @param  array<string, mixed>  $source  MotoClipSourceService::source()
     * @param  array<string, array<string, mixed>>  $keyframes  MotoClipSourceService::keyframes() of that source
     * @param  array<string, array<string, mixed>>  $selected  selected() of that source
     * @return array<string, mixed> what the prompt column of the render-video screen shows for the shot; reading it writes nothing and calls no model
     */
    public function screen(string $projectId, array $source, array $keyframes, array $selected, string $shotId, ?array $inputs = null): array
    {
        $this->release($projectId);
        $this->given[$projectId] = ['source' => $source, 'keyframes' => $keyframes, 'inputs' => $inputs];

        try {
            return $this->promptScreen($projectId, (string) $source['stage_id'], $shotId, $selected[$shotId] ?? null, fn (): array => $this->package($projectId, $shotId, $source, $keyframes, $inputs));
        } finally {
            $this->release($projectId);
        }
    }

    /** @return void what one reading of the project was given, and the packages and chains verified from it, are dropped: they live for that one reading only */
    private function release(string $projectId): void
    {
        unset($this->given[$projectId]);
        $this->stored = [];
        $this->chains = [];
    }

    /** @return void nothing of the project is kept: an approval, a new run, a queued run and the prompts a clip may send read every source again */
    protected function forget(string $projectId): void
    {
        $this->release($projectId);
        parent::forget($projectId);
    }

    /**
     * @param  array<string, mixed>  $source  MotoClipSourceService::source()
     * @param  array<string, array<string, mixed>>  $keyframes  MotoClipSourceService::keyframes() of that source
     * @return array<string, array{prompt: ?array<string, mixed>, blocked: ?array{code: string, message: string}, input_format?: string}> for every shot of the plan by id, the approved video prompt the clip plan pins, or why it has none it can send: none approved, or one whose sources changed since it was written; beside a prompt it can send, the input format the run that wrote it pinned (input_format), which names the check that accepted it; reading it calls no model
     */
    public function current(string $projectId, array $source, array $keyframes, ?array $inputs = null): array
    {
        $this->forget($projectId);
        $this->given[$projectId] = ['source' => $source, 'keyframes' => $keyframes, 'inputs' => $inputs];

        try {
            return $this->held($projectId, $source);
        } finally {
            $this->release($projectId);
        }
    }

    /**
     * @param  array<string, mixed>  $source  MotoClipSourceService::source()
     * @return array<string, array{prompt: ?array<string, mixed>, blocked: ?array{code: string, message: string}}> current() of the shot plan, read from what the caller gave
     */
    private function held(string $projectId, array $source): array
    {
        $selected = $this->selected($projectId, (string) $source['stage_id']);
        $result = [];

        foreach ((array) $source['plan']['shots'] as $row) {
            $shotId = (string) $row['id'];
            $entry = $selected[$shotId] ?? null;

            if ($entry === null) {
                $result[$shotId] = ['prompt' => null, 'blocked' => ['code' => 'prompt_missing', 'message' => "Shot {$shotId} chưa có prompt video đã duyệt — viết và duyệt ở cột Prompt clip."]];

                continue;
            }

            $stage = $this->stages->findStageForProject($projectId, $entry['stage_id']);

            try {
                $held = $stage !== null && hash_equals($this->storedPackage($projectId, $stage)['fingerprint'], (string) ($stage->input_json['source_fingerprint'] ?? ''));
            } catch (RuntimeException $e) {
                $result[$shotId] = ['prompt' => null, 'blocked' => ['code' => 'prompt_source_changed', 'message' => "Prompt video đã duyệt của shot {$shotId} không còn đọc lại được nguồn: {$e->getMessage()}"]];

                continue;
            }

            if (! $held) {
                $result[$shotId] = ['prompt' => null, 'blocked' => ['code' => 'prompt_source_changed', 'message' => "Nguồn của prompt video shot {$shotId} đã đổi sau khi duyệt (lượt ".substr($entry['stage_id'], 0, 8).') — viết hoặc duyệt lại ở cột Prompt clip.']];

                continue;
            }

            $result[$shotId] = ['prompt' => [
                'text' => $entry['text'],
                'version' => $entry['version'],
                'sha256' => hash('sha256', $entry['text']),
                'stage_id' => $entry['stage_id'],
                'decision_id' => $entry['decision_id'],
                'source_fingerprint' => $entry['fingerprint'],
            ], 'blocked' => null, 'input_format' => (string) ($stage->input_json['source']['inputs'] ?? '')];
        }

        return $result;
    }

    /** The shot plan, keyframes and reference inputs a screen has already read are used for the package, and the package is kept for that one reading. */
    protected function contractPackage(string $projectId, string $shotId, array $contract): array
    {
        $given = $this->given[$projectId] ?? null;

        if ($given === null) {
            return $this->package($projectId, $shotId, null, null, null, $contract);
        }

        $key = implode('|', [$projectId, $shotId, (string) $contract['inputs'], (string) $contract['phase_id'], (string) $contract['prompt_sha256']]);

        return $this->stored[$key] ??= $this->package($projectId, $shotId, $given['source'], $given['keyframes'], $given['inputs'], $contract);
    }

    /**
     * @param  array<string, mixed>  $source  MotoClipSourceService::source(): the approved shot plan
     * @param  array<string, mixed>  $pins  pins() of the project: the script and the design the prompt is written from
     * @return void the script and the design in use are the ones the approved shot plan was written from, through the scene plan it pinned; a shot plan that pins no scene plan has no chain to follow
     *
     * @throws RuntimeException when the scene plan the shot plan pinned is gone or of another project, or the script or the design in use is not the one that scene plan pinned
     */
    private function requirePinnedChain(string $projectId, array $source, array $pins): void
    {
        $shotStageId = (string) $source['stage_id'];
        $reading = isset($this->given[$projectId]);

        if ($reading && array_key_exists($shotStageId, $this->chains)) {
            $problem = $this->chains[$shotStageId];
        } else {
            [$sceneStageId, $sceneStage] = $this->pinnedSceneStage($projectId, $shotStageId);
            $problem = null;

            if ($sceneStageId !== null) {
                $pinned = (array) (PlanningStageStore::metadataOf(is_array($sceneStage?->input_json) ? $sceneStage->input_json : null)['source'] ?? []);
                $problem = match (true) {
                    $sceneStage === null => 'Bản chia scene mà kế hoạch shot đã ghim không còn hoặc không thuộc dự án',
                    (string) ($pinned['script']['stage_id'] ?? '') !== (string) $pins['script_token']['stage_id'],
                    ! hash_equals((string) ($pinned['script']['content_hash'] ?? ''), (string) $pins['script_token']['content_hash']) => 'Kịch bản đang dùng không phải kịch bản mà bản chia scene của kế hoạch shot đã ghim',
                    (string) ($pinned['design']['design_stage_id'] ?? '') !== (string) $pins['design_pin']['stage_id'],
                    ! hash_equals((string) ($pinned['design']['design_raw_sha256'] ?? ''), (string) $pins['design_pin']['raw_sha256']) => 'Thiết kế đang dùng không phải thiết kế mà bản chia scene của kế hoạch shot đã ghim',
                    default => null,
                };
            }

            if ($reading) {
                $this->chains[$shotStageId] = $problem;
            }
        }

        if ($problem !== null) {
            throw new RuntimeException($problem.' — nguồn của shot không còn đúng chuỗi đã ghim, không gọi model.');
        }
    }

    /** @return array{0: ?string, 1: ?VideoPlanningStage} the id of the scene plan the shot plan pinned, null when it pinned none, and that scene plan, null when it is gone, did not succeed or is of another project */
    private function pinnedSceneStage(string $projectId, string $shotStageId): array
    {
        $shotStage = $this->stages->findStageForProject($projectId, $shotStageId);
        $sceneStageId = PlanningStageStore::metadataOf(is_array($shotStage?->input_json) ? $shotStage->input_json : null)['source']['scenes']['stage_id'] ?? null;

        if (! is_string($sceneStageId) || $sceneStageId === '') {
            return [null, null];
        }

        return [$sceneStageId, $this->stages->findSucceededStageOfKind($projectId, $sceneStageId, PlanningStageName::SCENES)];
    }

    /**
     * @param  array<string, mixed>  $source  MotoClipSourceService::source(): the approved shot plan
     * @param  array<string, mixed>  $shot  the stored shot
     * @return array<string, mixed> the check context pinned with the shot run, holding the scene of the shot: as pinned when it is there, else read again from the scene plan the shot plan pinned, as that run stored it
     *
     * @throws RuntimeException when the context does not hold the scene and the pinned scene plan is gone, no longer the plan its run stored, or does not have the scene: the link is broken, so no model is called
     */
    private function withScene(string $projectId, array $source, array $shot): array
    {
        $context = (array) $source['context'];
        $sceneId = (string) ($shot['scene'] ?? '');

        if ($sceneId !== '' && collect((array) ($context['scenes'] ?? []))->contains('id', $sceneId)) {
            return $context;
        }

        $sceneStage = $sceneId === '' ? null : $this->pinnedSceneStage($projectId, (string) $source['stage_id'])[1];
        $output = is_array($sceneStage?->output_json) ? $sceneStage->output_json : [];
        $derived = $output['derived'] ?? null;
        $kept = is_array($derived) && (! isset($output[SceneSourceService::DERIVED_HASH_KEY]) || hash_equals((string) $output[SceneSourceService::DERIVED_HASH_KEY], SceneSourceService::derivedHash($derived)));
        $scene = $kept ? collect((array) ($derived['scenes'] ?? []))->firstWhere('id', $sceneId) : null;

        if (! is_array($scene)) {
            throw new RuntimeException("Shot {$shot['id']} thuộc scene ".($sceneId === '' ? '—' : $sceneId).', nhưng ngữ cảnh của kế hoạch shot không có scene này và không đọc lại được từ bản chia scene đã ghim — liên kết nguồn hỏng, không gọi model.');
        }

        $context['scenes'] = [...array_values((array) ($context['scenes'] ?? [])), $scene];

        return $context;
    }

    protected function shotStageOf(string $projectId, string $shotId): ?string
    {
        $source = $this->clips->source($projectId);

        return collect((array) $source['plan']['shots'])->contains('id', $shotId) ? (string) $source['stage_id'] : null;
    }

    /**
     * @param  ?array<string, mixed>  $source  MotoClipSourceService::source(), read again when not given
     * @param  ?array<string, array<string, mixed>>  $keyframes  MotoClipSourceService::keyframes() of that source, read again when not given
     * @param  ?array<string, array<string, mixed>>  $inputs  MotoClipSourceService::inputs() of that source, read again when not given
     * @param  ?array{inputs: string, phase_id: string, prompt_sha256: string, bundle_hash: string, manifest: array<string, mixed>}  $contract  the contract a stored run pinned, to prepare its package again; null prepares a new run under the revision and the input format the catalogue gives now
     * @return array{run: array<string, mixed>, sources: list<array<string, mixed>>, fingerprint: string} the run of one shot without the bytes of its images, the images the model sees — the keyframe as Image 1, or the reference images the clip sends in their order — and the fingerprint of everything it is written from
     *
     * @throws RuntimeException when it cannot be prepared
     */
    protected function package(string $projectId, string $shotId, ?array $source = null, ?array $keyframes = null, ?array $inputs = null, ?array $contract = null): array
    {
        $flow = $this->workflowFor($projectId);

        if ($contract === null) {
            $pins = $this->pins($projectId, $flow);
        } else {
            $flow['adapter'] = $flow['adapter']->reading((string) $contract['inputs']);
            $pins = $this->pinsUnder($this->sourcePins($projectId), $contract, $flow['adapter']);
        }

        $settings = $flow['adapter']->settings();
        $clipFlow = $this->clips->workflowFor($projectId);
        $source ??= $this->clips->source($projectId);
        $this->requirePinnedChain($projectId, $source, $pins);
        $shot = collect((array) $source['plan']['shots'])->firstWhere('id', $shotId) ?? throw new RuntimeException("Shot {$shotId} không có trong kế hoạch shot đã duyệt.");
        $inputs ??= $this->clips->inputs($projectId, $source, (array) $clipFlow['settings']);
        $input = (array) ($inputs[$shotId] ?? []);
        $twelfth = $flow['adapter']->inputsVersion() === MotoContract::CLIP_PROMPT_INPUT_V12;
        $choice = $twelfth ? $this->executionChoice($projectId, $shotId, $source, $input) : null;
        $references = ($input['mode'] ?? null) === MotoClipSourceService::REFERENCES;
        $keyframe = null;

        if ($references && ($input['blocked'] ?? null) !== null) {
            throw new RuntimeException((string) $input['blocked']['message']);
        }

        if (! $references) {
            $keyframes ??= $this->clips->keyframes($projectId, $source, (array) $clipFlow['settings']);
            $frame = (array) ($keyframes[$shotId] ?? []);
            $keyframe = $frame['keyframe'] ?? null;

            if (! is_array($keyframe)) {
                throw new RuntimeException((string) ($frame['blocked']['message'] ?? "Shot {$shotId} chưa có keyframe đã duyệt ở màn Keyframes."));
            }

            if ($twelfth && ! is_string($frame['set']['decision_id'] ?? null)) {
                throw new RuntimeException("Bộ keyframe của shot {$shotId} chưa có quyết định duyệt nào còn hiệu lực — không viết prompt video.");
            }
        }

        $images = $references ? array_values((array) $input['images']) : [];
        $plate = $twelfth && ! $references && is_array($frame['plate'] ?? null) ? $frame['plate'] : null;
        $audio = MotoClipPrompt::soundPolicy((array) ($clipFlow['settings']['audio'] ?? []));

        if ($audio === null) {
            throw new RuntimeException('Chính sách âm thanh của luồng clip chưa cấu hình đúng (policy, prefix, closing) — không viết prompt video.');
        }

        $sources = $references
            ? array_map(static fn (array $image): array => ['position' => (int) $image['position'], 'artifact_id' => (string) $image['artifact_id'], 'sha256' => (string) $image['sha256'], 'image_code' => (string) ($image['image_code'] ?? '')], $images)
            : [['position' => 0, 'artifact_id' => (string) $keyframe['artifact_id'], 'sha256' => (string) $keyframe['sha256'], 'image_code' => 'keyframe '.$shotId]];
        $order = array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'scene' => (string) ($row['scene'] ?? '')], array_values((array) $source['plan']['shots']));
        $context = in_array($flow['adapter']->inputsVersion(), MotoClipPrompt::LINKED_FORMATS, true) ? $this->withScene($projectId, $source, $shot) : (array) $source['context'];
        $sent = array_map(static fn (array $image): array => ['image' => 'Image '.((int) $image['position'] + 1), 'send_as' => $references ? 'reference' : 'first_frame', 'artifact_id' => (string) $image['artifact_id'], 'sha256' => (string) $image['sha256']], $sources);
        $execution = $choice === null ? null : [
            'motion_method' => MotoContract::MOTION_AI_VIDEO,
            'input_mode' => $references ? MotoClipSourceService::REFERENCES : MotoClipSourceService::KEYFRAME,
            'content_duration_ms' => (int) $choice['policy']['content_duration_ms'],
            'source_duration_ms' => (int) $choice['render']['duration_ms'],
            'used' => ['start_ms' => 0, 'duration_ms' => (int) $choice['policy']['content_duration_ms']],
            'aspect_ratio' => (string) ($source['context']['aspect_ratio'] ?? ''),
            'images' => $sent,
        ];
        $payload = MotoClipPrompt::inputs($shot, ['shot_order' => $order] + $context,$pins['design'], $pins['script'], ['mode' => $references ? MotoClipSourceService::REFERENCES : MotoClipSourceService::KEYFRAME, 'keyframe' => $keyframe, 'images' => $images, 'plate' => $plate], $audio, '', (int) $settings['max_prompt_chars'], $flow['adapter']->inputsVersion(), $execution);
        $pin = [
            'inputs' => (string) $payload['payload']['input_format'],
            'phase' => ['id' => (string) $pins['phase']->id, 'prompt_sha256' => (string) $pins['phase']->content_sha256],
            'shots' => ['stage_id' => (string) $source['token']['stage_id'], 'content_hash' => (string) $source['token']['content_hash']],
            'shot_id' => $shotId,
            'script' => $pins['script_token'],
            'design' => $pins['design_pin'],
            'input_mode' => $references ? MotoClipSourceService::REFERENCES : MotoClipSourceService::KEYFRAME,
            'keyframe' => $keyframe === null ? null : ['image_id' => (string) $keyframe['image_id'], 'artifact_id' => (string) $keyframe['artifact_id'], 'sha256' => (string) $keyframe['sha256']],
            'images' => array_map(static fn (array $image): array => ['position' => (int) $image['position'], 'artifact_id' => (string) $image['artifact_id'], 'sha256' => (string) $image['sha256'], 'kind' => (string) $image['kind'], 'target' => $image['target'] ?? null], $images),
            'audio' => $audio,
        ] + ($choice === null ? [] : ['execution' => $payload['payload']['execution'], 'plate' => $plate]);

        return ['sources' => $sources] + $this->runOf($projectId, (string) $source['stage_id'], $shotId, $flow, $pins, $payload, $pin);
    }

    /**
     * @param  array<string, mixed>  $source  MotoClipSourceService::source()
     * @param  array<string, mixed>  $input  MotoClipSourceService::inputs() of the shot, with its settings
     * @return array{policy: array<string, mixed>, render: array<string, mixed>} the execution policy of the release and how the clip of the shot is sent as its settings choose it (MotoClipPlanBuilder::capability()): a video prompt of the twelfth input is written for what the writer is told of that choice — the group execution of its payload: the way of moving, the input, the length of the content, of the file and of the part kept, the frame and the images sent — and its fingerprint pins exactly that group. The model and the resolution are no part of it, so choosing another that renders a file of the same length keeps an approved prompt
     *
     * @throws RuntimeException when the release declares no execution policy, the settings cannot be read, the clip is a dynamic crop, which needs no video prompt, or the choice cannot be sent
     */
    private function executionChoice(string $projectId, string $shotId, array $source, array $input): array
    {
        $policy = $this->clips->execution($projectId) ?? throw new RuntimeException('Bản phát hành của dự án không khai chính sách thực thi clip mà đầu vào '.MotoContract::CLIP_PROMPT_INPUT_V12.' cần — không gọi model.');
        $settings = (array) ($input['settings'] ?? []);

        if (is_string($settings['problem'] ?? null)) {
            throw new RuntimeException("Shot {$shotId}: {$settings['problem']}");
        }

        if (($settings['motion_method'] ?? $policy['default_motion_method']) === MotoContract::MOTION_DYNAMIC_CROP) {
            throw new RuntimeException("Shot {$shotId} làm bằng Dynamic Crop từ ảnh master của bộ keyframe — không cần prompt video.");
        }

        $capability = MotoClipPlanBuilder::capability($policy, $settings, $this->clips->executionModels($policy), (string) ($source['context']['aspect_ratio'] ?? ''), 'Shot '.$shotId);

        if ($capability['blocked'] !== null) {
            throw new RuntimeException((string) $capability['blocked']['message']);
        }

        return ['policy' => $policy, 'render' => $capability['render']];
    }
}
