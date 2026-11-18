<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Video\Llm\LlmClient;
use App\Video\Moto\MotoKeyframePrompt;
use RuntimeException;

final class KeyframePromptWriterService extends ShotPromptWriterService
{
    /** @var string the key of the project metadata that holds, by shot plan and shot, the keyframe prompt a person approved */
    public const SELECTION = 'moto_keyframe_prompts';

    public const ENTITY_TYPE = 'keyframe_prompt';

    protected const STAGE = PlanningStageName::KEYFRAME_PROMPT;

    protected const STEP = 'keyframe_prompts';

    protected const LABEL = 'viết prompt ảnh';

    protected const NOUN = 'prompt ảnh';

    protected const TASK = 'keyframe prompt';

    protected const PHASE = 'Phase 9';

    protected const WORKFLOWS = 'video.keyframe_prompts.workflows';

    protected const ADAPTER = MotoKeyframePrompt::class;

    protected const TEXT_KEY = 'image_prompt';

    /** @var array<string, array{context: array<string, mixed>, built: array<string, mixed>, frame: array<string, mixed>}> by project and shot, the keyframe context, the cards and the image settings a screen has already read, so the stored runs of the shot are verified from them and not read again */
    private array $given = [];

    /** @var array<string, array<string, mixed>> the package of a stored run by project, shot and the contract the run pinned, kept for one reading of the screen only */
    private array $stored = [];

    public function __construct(
        PlanningStageStore $stageStore,
        LlmClient $llm,
        private readonly MotoKeyframeSourceService $frames,
    ) {
        parent::__construct($stageStore, $llm);
    }

    /**
     * @param  array<string, mixed>  $context  MotoKeyframeSourceService::context()
     * @param  array<string, mixed>  $shot  a stored shot
     * @param  array<string, mixed>  $built  MotoKeyframeSourceService::cards() of the shot
     * @param  array<string, mixed>  $frame  the image settings of the shot, MotoKeyframeService::settingsFor()
     * @return array<string, mixed> what the Prompt column shows for the shot: the approved prompt and whether its sources still hold, the newest run and the run shown with its check, its history, what a new run sends and costs, and why one cannot be sent; reading it writes nothing
     */
    public function screen(string $projectId, array $context, array $shot, array $built, array $frame): array
    {
        $shotId = (string) $shot['id'];
        $this->given = ["{$projectId}|{$shotId}" => ['context' => $context, 'built' => $built, 'frame' => $frame]];
        $this->stored = [];

        try {
            return $this->promptScreen($projectId, (string) $context['stage']->id, $shotId, $context['prompts'][$shotId] ?? null, fn (): array => $this->package($projectId, $shotId, $context, $built, $frame));
        } finally {
            $this->given = [];
            $this->stored = [];
        }
    }

    /** What a screen has already read of the shot is used for the package, and the package is kept for that one reading; an approval and a queued run read every source again. */
    protected function contractPackage(string $projectId, string $shotId, array $contract): array
    {
        $given = $this->given["{$projectId}|{$shotId}"] ?? null;

        if ($given === null) {
            return $this->package($projectId, $shotId, null, null, null, $contract);
        }

        $key = implode('|', [$projectId, $shotId, (string) $contract['inputs'], (string) $contract['phase_id'], (string) $contract['prompt_sha256']]);

        return $this->stored[$key] ??= $this->package($projectId, $shotId, $given['context'], $given['built'], $given['frame'], $contract);
    }

    /** @return void nothing of the project is kept: an approval, a new run and a queued run read every source again */
    protected function forget(string $projectId): void
    {
        $this->given = [];
        $this->stored = [];
        parent::forget($projectId);
    }

    protected function shotStageOf(string $projectId, string $shotId): ?string
    {
        $context = $this->frames->context($projectId);

        return isset($context['shots'][$shotId]) ? (string) $context['stage']->id : null;
    }

    /**
     * @param  ?array<string, mixed>  $context  MotoKeyframeSourceService::context(), read again when not given
     * @param  ?array<string, mixed>  $built  the cards of the shot, built again when not given
     * @param  ?array<string, mixed>  $frame  the image settings of the shot, read again when not given
     * @param  ?array{inputs: string, phase_id: string, prompt_sha256: string, bundle_hash: string, manifest: array<string, mixed>}  $contract  the contract a stored run pinned, to prepare its package again; null prepares a new run under the current revision and input format
     * @return array{run: array<string, mixed>, sources: list<array<string, mixed>>, fingerprint: string} the run of one shot without its image bytes, the images it sends and the fingerprint of everything it is written from
     *
     * @throws RuntimeException when it cannot be prepared, or the stored run pinned an input format this code no longer prepares
     */
    protected function package(string $projectId, string $shotId, ?array $context = null, ?array $built = null, ?array $frame = null, ?array $contract = null): array
    {
        $flow = $this->workflowFor($projectId);

        if ($contract === null) {
            $pins = $this->pins($projectId, $flow);
        } else {
            if (! in_array($contract['inputs'], MotoKeyframePrompt::INPUT_FORMATS, true)) {
                throw new RuntimeException("Lượt này viết theo đầu vào {$contract['inputs']} mà code không còn dựng lại — không xác minh được nguồn.");
            }

            $flow['adapter'] = $flow['adapter']->reading((string) $contract['inputs']);
            $pins = $this->pinsUnder($this->sourcePins($projectId), $contract, $flow['adapter']);
        }

        $settings = $flow['adapter']->settings();
        $context ??= $this->frames->context($projectId);
        $shot = $context['shots'][$shotId] ?? throw new RuntimeException("Shot {$shotId} không có trong kế hoạch shot đã duyệt.");
        $built ??= $this->frames->cards($context, $shot);

        if ($built['problems'] !== []) {
            throw new RuntimeException('Chưa đủ nguồn ảnh của shot: '.implode(' · ', $built['problems']));
        }

        $frame ??= app(MotoKeyframeService::class)->settingsFor($context, $shot);

        if ((string) ($frame['model'] ?? '') === '' || (string) ($frame['size'] ?? '') === '') {
            throw new RuntimeException('Shot chưa có model và size ảnh dùng được — chọn ở cột References trước.');
        }

        $sources = array_values((array) $built['sources']);
        $images = array_map(static fn (array $image): array => ['position' => (int) $image['position'] + 1, 'kind' => (string) $image['kind'], 'role' => (string) $image['role'], 'usage' => (string) $image['usage'], 'chosen_by' => (string) $image['chosen'], 'name' => (string) $image['image_code'], 'artifact_id' => (string) $image['artifact_id'], 'sha256' => (string) $image['sha256']]
            + ($image['kind'] === 'environment' ? ['background' => (string) $image['target'], 'background_name' => (string) ($image['title'] ?? '')] : [])
            + array_intersect_key($image, array_flip(['parts', 'shot', 'character', 'view', 'cell_code', 'cell_row', 'cell_column', 'items'])), $sources);
        $policy = (array) $settings['composition_policy'];
        $pinned = (array) (PlanningStageStore::metadataOf(is_array($context['stage']->input_json) ? $context['stage']->input_json : null)['context'] ?? []);
        $inputs = MotoKeyframePrompt::inputs($shot, $pinned, $pins['design'], $pins['script'], $images, $policy, '', (int) $settings['max_prompt_chars'], $flow['adapter']->inputsVersion());
        $source = [
            'inputs' => $flow['adapter']->inputsVersion(),
            'phase' => ['id' => (string) $pins['phase']->id, 'prompt_sha256' => (string) $pins['phase']->content_sha256],
            'shots' => ['stage_id' => (string) $context['approved']['token']['stage_id'], 'content_hash' => (string) $context['approved']['token']['content_hash']],
            'shot_id' => $shotId,
            'script' => $pins['script_token'],
            'design' => $pins['design_pin'],
            'images' => $images,
            'policy' => $policy,
        ];

        return ['sources' => $sources] + $this->runOf($projectId, (string) $context['stage']->id, $shotId, $flow, $pins, $inputs, $source);
    }
}
