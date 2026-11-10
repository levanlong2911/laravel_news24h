<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Video\Llm\LlmClient;
use App\Video\Moto\MotoContract;
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

        return $this->promptScreen($projectId, (string) $context['stage']->id, $shotId, $context['prompts'][$shotId] ?? null, fn (): array => $this->package($projectId, $shotId, $context, $built, $frame));
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
     * @return array{run: array<string, mixed>, sources: list<array<string, mixed>>, fingerprint: string} the run of one shot without its image bytes, the images it sends and the fingerprint of everything it is written from
     *
     * @throws RuntimeException when it cannot be prepared
     */
    protected function package(string $projectId, string $shotId, ?array $context = null, ?array $built = null, ?array $frame = null): array
    {
        $flow = $this->workflowFor($projectId);
        $pins = $this->pins($projectId, $flow);
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
        $inputs = MotoKeyframePrompt::inputs($shot, $pinned, $pins['design'], $pins['script'], $images, $policy, '', (int) $settings['max_prompt_chars']);
        $source = [
            'inputs' => MotoContract::KEYFRAME_PROMPT_INPUT,
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
