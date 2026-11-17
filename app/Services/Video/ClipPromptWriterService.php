<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Models\VideoPlanningStage;
use App\Video\Llm\LlmClient;
use App\Video\Moto\MotoClipPrompt;
use App\Video\Moto\MotoContract;
use App\Video\Superyacht\Support\CanonicalJson;
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

    protected const TEXT_KEY = 'video_prompt';

    public function __construct(
        PlanningStageStore $stageStore,
        LlmClient $llm,
        private readonly MotoClipSourceService $clips,
    ) {
        parent::__construct($stageStore, $llm);
    }

    /**
     * @param  array<string, mixed>  $source  MotoClipSourceService::source()
     * @param  array<string, array<string, mixed>>  $keyframes  MotoClipSourceService::keyframes() of that source
     * @param  array<string, array<string, mixed>>  $selected  selected() of that source
     * @return array<string, mixed> what the prompt column of the render-video screen shows for the shot; reading it writes nothing and calls no model
     */
    public function screen(string $projectId, array $source, array $keyframes, array $selected, string $shotId, ?array $inputs = null): array
    {
        return $this->promptScreen($projectId, (string) $source['stage_id'], $shotId, $selected[$shotId] ?? null, fn (): array => $this->package($projectId, $shotId, $source, $keyframes, $inputs));
    }

    /**
     * @param  array<string, mixed>  $source  MotoClipSourceService::source()
     * @param  array<string, array<string, mixed>>  $keyframes  MotoClipSourceService::keyframes() of that source
     * @return array<string, array{prompt: ?array<string, mixed>, blocked: ?array{code: string, message: string}}> for every shot of the plan by id, the approved video prompt the clip plan pins, or why it has none it can send: none approved, or one whose sources changed since it was written; reading it calls no model
     */
    public function current(string $projectId, array $source, array $keyframes, ?array $inputs = null): array
    {
        $selected = static::selected($projectId, (string) $source['stage_id']);
        $result = [];

        foreach ((array) $source['plan']['shots'] as $row) {
            $shotId = (string) $row['id'];
            $entry = $selected[$shotId] ?? null;

            if ($entry === null) {
                $result[$shotId] = ['prompt' => null, 'blocked' => ['code' => 'prompt_missing', 'message' => "Shot {$shotId} chưa có prompt video đã duyệt — viết và duyệt ở cột Prompt clip."]];

                continue;
            }

            try {
                $prepared = $this->package($projectId, $shotId, $source, $keyframes, $inputs);
            } catch (RuntimeException $e) {
                $result[$shotId] = ['prompt' => null, 'blocked' => ['code' => 'prompt_source_changed', 'message' => "Prompt video đã duyệt của shot {$shotId} không còn đọc lại được nguồn: {$e->getMessage()}"]];

                continue;
            }

            if (! $this->stillHolds($entry, $prepared)) {
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
            ], 'blocked' => null];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $selected  the approved prompt of the shot, selected()
     * @param  array<string, mixed>  $prepared  package() of the shot now
     * @return bool whether what the approved run was written from is what the shot gives now: the shot, the keyframe, the script, the design, the sound policy and the input, read under the contract the run pinned; a later phase-10 revision alone writes new runs and never takes an approved prompt out of use, while a new input format or sound policy does
     */
    protected function stillHolds(array $selected, array $prepared): bool
    {
        $pinned = (array) (VideoPlanningStage::query()->withoutSnapshot()->find((string) $selected['stage_id'], ['id', 'input_json'])?->input_json['source'] ?? []);

        return $pinned !== [] && hash_equals(self::content($pinned), self::content((array) $prepared['run']['input']['source']));
    }

    /**
     * @param  array<string, mixed>  $source  the source a run pins
     * @return string the hash of what the run is written from, without the phase revision it is written to
     */
    private static function content(array $source): string
    {
        return CanonicalJson::hash(array_diff_key($source, ['phase' => true]));
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
     * @return array{run: array<string, mixed>, sources: list<array<string, mixed>>, fingerprint: string} the run of one shot without the bytes of its images, the images the model sees — the keyframe as Image 1, or the reference images the clip sends in their order — and the fingerprint of everything it is written from
     *
     * @throws RuntimeException when it cannot be prepared
     */
    protected function package(string $projectId, string $shotId, ?array $source = null, ?array $keyframes = null, ?array $inputs = null): array
    {
        $flow = $this->workflowFor($projectId);
        $pins = $this->pins($projectId, $flow);
        $settings = $flow['adapter']->settings();
        $clipFlow = $this->clips->workflowFor($projectId);
        $source ??= $this->clips->source($projectId);
        $shot = collect((array) $source['plan']['shots'])->firstWhere('id', $shotId) ?? throw new RuntimeException("Shot {$shotId} không có trong kế hoạch shot đã duyệt.");
        $inputs ??= $this->clips->inputs($projectId, $source, (array) $clipFlow['settings']);
        $input = (array) ($inputs[$shotId] ?? []);
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
        }

        $images = $references ? array_values((array) $input['images']) : [];
        $audio = MotoClipPrompt::soundPolicy((array) ($clipFlow['settings']['audio'] ?? []));

        if ($audio === null) {
            throw new RuntimeException('Chính sách âm thanh của luồng clip chưa cấu hình đúng (policy, prefix, closing) — không viết prompt video.');
        }

        $sources = $references
            ? array_map(static fn (array $image): array => ['position' => (int) $image['position'], 'artifact_id' => (string) $image['artifact_id'], 'sha256' => (string) $image['sha256'], 'image_code' => (string) ($image['image_code'] ?? '')], $images)
            : [['position' => 0, 'artifact_id' => (string) $keyframe['artifact_id'], 'sha256' => (string) $keyframe['sha256'], 'image_code' => 'keyframe '.$shotId]];
        $order = array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'scene' => (string) ($row['scene'] ?? '')], array_values((array) $source['plan']['shots']));
        $payload = MotoClipPrompt::inputs($shot, ['shot_order' => $order] + (array) $source['context'], $pins['design'], $pins['script'], ['mode' => $references ? MotoClipSourceService::REFERENCES : MotoClipSourceService::KEYFRAME, 'keyframe' => $keyframe, 'images' => $images], $audio, '', (int) $settings['max_prompt_chars'], $flow['adapter']->inputsVersion());
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
        ];

        return ['sources' => $sources] + $this->runOf($projectId, (string) $source['stage_id'], $shotId, $flow, $pins, $payload, $pin);
    }
}
