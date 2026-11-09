<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Models\VideoReviewDecision;
use App\Services\VideoProjectService;
use App\Video\Media\OpenAiImagePricing;
use App\Video\Queue\VideoQueues;
use App\Video\Scene\Services\ShotIntentService;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class MotoKeyframeService implements QueuedImageService
{
    public const STEP = 'moto_keyframe';

    public const SPEC_VERSION = 'moto-keyframe-v1';

    /** @var array<string, array{0: string, 1: string}> the tone and label of every state of a shot or a keyframe */
    public const STATES = [
        'approved' => ['ok', 'Đã duyệt'],
        'broken' => ['dg', 'File ảnh lỗi'],
        'stale' => ['dg', 'Nguồn cũ'],
        'rendering' => ['blue', 'Đang render'],
        'queued' => ['blue', 'Đang chờ hàng đợi'],
        'awaiting' => ['amber', 'Chờ duyệt'],
        'failed' => ['dg', 'Render lỗi'],
        'missing' => ['amber', 'Thiếu nguồn'],
        'ready' => ['mute', 'Sẵn sàng render'],
        'superseded' => ['mute', 'Bản cũ'],
    ];

    /** @var array<string, list<string>> the groups of the Planning column and the fields of a shot each one holds */
    private const PLANNING = [
        'Shot' => ['id', 'scene', 'purpose', 'state', 'reveal', 'tl_in_ms', 'tl_out_ms', 'render_duration_ms', 'mode', 'used_start_ms', 'used_duration_ms', 'fingerprint'],
        'Khung hình' => ['first_frame', 'action', 'result', 'end_frame'],
        'Beat và coverage' => ['coverage', 'coverage_spans'],
        'Part' => ['parts_named', 'parts', 'configurations'],
        'Công việc và vị trí' => ['work', 'work_items', 'held_parts', 'places', 'absent_parts'],
        'Nhân vật' => ['people'],
        'Camera' => ['camera'],
        'Bối cảnh' => ['environment', 'set_brief', 'transition_in'],
        'Continuity' => ['continuity', 'continuity_start', 'continuity_end', 'carried_from'],
        'Liên kết, giới hạn, giả định' => ['links', 'limits', 'assumptions'],
        'Thẻ chữ' => ['cards'],
        'Nhu cầu ảnh' => ['reference_needs'],
    ];

    /** @var list<string> the groups of the Planning column shown open */
    private const OPEN_GROUPS = ['Shot', 'Khung hình'];

    /** @var ?list<array<string, mixed>> the image models of the registry, read once for each instance */
    private ?array $models = null;

    public function __construct(
        private readonly MotoKeyframeSourceService $sources,
        private readonly ShotRenderPlanService $plans,
        private readonly DesignImageStore $store,
        private readonly OpenAiImagePricing $pricing,
    ) {}

    /** @return array<string, mixed> everything the Keyframes screen shows; reading it writes nothing */
    public function panel(string $projectId): array
    {
        try {
            $context = $this->sources->context($projectId);
        } catch (RuntimeException $e) {
            return ['error' => $e->getMessage(), 'stage' => null, 'token' => null, 'aspect_ratio' => null, 'rows' => [], 'counts' => [], 'states' => self::STATES];
        }

        $rows = array_values(array_map(fn (array $shot): array => $this->row($context, $shot), $context['shots']));

        return [
            'error' => null,
            'stage' => $context['stage'],
            'token' => $context['approved']['token'],
            'aspect_ratio' => $context['aspect_ratio'],
            'rows' => $rows,
            'counts' => array_count_values(array_column($rows, 'state')),
            'states' => self::STATES,
        ];
    }

    /**
     * @param  array<string, mixed>  $shot  a stored shot
     * @return list<array{title: string, open: bool, lines: list<array{key: string, text: string}>}> every stored value of the shot except its prompts, word for word, grouped; a field with no value reads "—"
     */
    public static function planning(array $shot): array
    {
        $groups = [];
        $known = ['prompts' => true];

        foreach (self::PLANNING as $title => $keys) {
            $lines = [];

            foreach ($keys as $key) {
                $known[$key] = true;

                if (array_key_exists($key, $shot)) {
                    $lines = [...$lines, ...self::lines($key, $shot[$key])];
                }
            }

            $groups[] = ['title' => $title, 'open' => in_array($title, self::OPEN_GROUPS, true), 'lines' => $lines];
        }

        $others = array_diff_key($shot, $known);

        if ($others !== []) {
            $lines = [];

            foreach ($others as $key => $value) {
                $lines = [...$lines, ...self::lines((string) $key, $value)];
            }

            $groups[] = ['title' => 'Khác', 'open' => false, 'lines' => $lines];
        }

        return $groups;
    }

    /**
     * @param  ?array{id: string, name: string}  $requester
     * @return array{0: ?VideoDesignImage, 1: string} reason queued, rendered, failed, already_exists, or why nothing was sent
     */
    public function render(string $projectId, string $shotId, string $previewHash, string $operation, ?array $requester = null): array
    {
        if (! Str::isUuid($operation)) {
            return [null, 'Mã lượt render không hợp lệ.'];
        }

        $create = function () use ($projectId, $shotId, $previewHash, $operation): array {
            VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $identity = hash('sha256', 'moto_keyframe|'.$projectId.'|'.$operation);
            $existing = VideoDesignImage::query()->where('project_id', $projectId)->where('prompt_sha256', $identity)->first();

            if ($existing !== null) {
                return hash_equals((string) ($existing->prompt_spec_json['request_hash'] ?? ''), $previewHash)
                    ? [$existing, 'already_exists']
                    : [null, 'Mã thao tác đã dùng cho một lượt render khác — tải lại trang.'];
            }

            $context = $this->sources->context($projectId);
            $shot = $context['shots'][$shotId] ?? null;

            if ($shot === null) {
                return [null, "Shot {$shotId} không có trong kế hoạch shot đã duyệt."];
            }

            $row = $this->row($context, $shot, true);

            if ($row['problems'] !== []) {
                return [null, implode(' · ', $row['problems'])];
            }

            if (! hash_equals($row['preview_hash'], $previewHash)) {
                return [null, 'Nguồn hoặc cấu hình đã đổi so với lúc xem — tải lại trang, chưa render.'];
            }

            if ($row['busy']) {
                return [null, "Shot {$shotId} đang có một lượt chờ hoặc đang render."];
            }

            $scene = $this->plans->ensure($projectId, $context['approved'], $shotId);

            return [$this->store->createSceneCandidate($scene, [
                'project_id' => $projectId,
                'operation' => 'scene_keyframe',
                'spec_version' => self::SPEC_VERSION,
                'shot_id' => $shotId,
                'shot_stage_id' => (string) $context['stage']->id,
                'planning_revision' => (int) $context['stage']->planning_revision,
                'source_token' => $context['approved']['token'],
                'render_scene_id' => (string) $scene->id,
                'prompt' => (string) $shot['prompts']['image'],
                'image_sha256' => (string) $shot['prompts']['image_sha256'],
                'composer' => (string) $shot['prompts']['composer'],
                'sources' => $row['sources'],
                'reference_manifest_hash' => CanonicalJson::hash($row['sources']),
                'provider' => 'openai',
                'model' => $row['settings']['model'],
                'quality' => $row['settings']['quality'],
                'size' => $row['settings']['size'],
                'variations' => 1,
                'pricing' => $row['settings']['pricing'],
                'operation_id' => $operation,
                'request_hash' => $previewHash,
            ], $identity), 'created'];
        };

        try {
            if (VideoQueues::enabled()) {
                return app(ImageRenderQueue::class)->createQueued(
                    self::STEP,
                    $create,
                    static fn (VideoDesignImage $image, string $reason): ?array => $reason === 'already_exists' && $image->status !== DesignImageStatus::CANDIDATE->value ? [$image, 'already_exists'] : null,
                    $requester,
                );
            }

            [$image, $reason] = DB::transaction($create);
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }

        if ($image === null || $reason === 'already_exists') {
            return [$image, $reason];
        }

        $problem = $this->checkQueued($image);

        if ($problem !== null) {
            app(DesignImageQueue::class)->refuse((string) $image->id, $problem);

            return [null, $problem];
        }

        return array_slice($this->sendQueued($image), 0, 2);
    }

    public function checkQueued(VideoDesignImage $image): ?string
    {
        try {
            $problem = $this->sources->imageProblem($this->sources->context((string) $image->project_id), $image, true);
        } catch (RuntimeException $e) {
            $problem = $e->getMessage();
        }

        return $problem === null ? null : $problem.' — không gửi render.';
    }

    public function sendQueued(VideoDesignImage $image): array
    {
        return app(DesignImageDirectRenderer::class)->renderNow((string) $image->id);
    }

    /**
     * @param  ?string  $from  the master the new one takes the place of
     *
     * @throws RuntimeException when the image cannot stand in that card
     */
    public function saveSource(string $projectId, string $shotId, string $kind, ?string $from, string $to): void
    {
        DB::transaction(function () use ($projectId, $shotId, $kind, $from, $to): void {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $context = $this->sources->context($projectId);
            $shot = $context['shots'][$shotId] ?? throw new RuntimeException("Shot {$shotId} không có trong kế hoạch shot đã duyệt.");
            $cards = collect($this->sources->cards($context, $shot)['cards'])->where('kind', $kind)->values();
            $card = $kind === 'environment' ? $cards->first() : $cards->first(static fn (array $row): bool => ($row['source']['artifact_id'] ?? null) === $from);

            if ($card === null || ! in_array($to, array_column($card['options'], 'artifact_id'), true)) {
                throw new RuntimeException('Ảnh này không dùng được cho thẻ này — tải lại trang.');
            }

            $meta = $project->metadata_json ?? [];
            $value = $to;

            if ($kind === 'design') {
                $kept = array_values(array_diff(array_filter($cards->map(static fn (array $row): ?string => $row['source']['artifact_id'] ?? null)->all()), [$from]));
                $value = array_values(array_unique([...$kept, $to]));
            }

            $meta[MotoKeyframeSourceService::CHOICES][(string) $context['stage']->id][$shotId]['sources'][$kind] = $value;
            $project->forceFill(['metadata_json' => $meta])->save();
        });
    }

    /** @throws RuntimeException when the model does not offer the size or the quality for the frame of the film */
    public function saveSettings(string $projectId, string $shotId, string $model, string $quality, string $size): void
    {
        DB::transaction(function () use ($projectId, $shotId, $model, $quality, $size): void {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $context = $this->sources->context($projectId);

            if (! isset($context['shots'][$shotId])) {
                throw new RuntimeException("Shot {$shotId} không có trong kế hoạch shot đã duyệt.");
            }

            $entry = collect($this->modelsFor($context['aspect_ratio']))->firstWhere('model', $model);

            if ($entry === null || ! in_array($size, $entry['sizes'], true) || ! in_array($quality, $entry['qualities'], true)) {
                throw new RuntimeException('Model, chất lượng hoặc size không hợp lệ cho khung '.$context['aspect_ratio'].'.');
            }

            $meta = $project->metadata_json ?? [];
            $saved = (array) ($meta[MotoKeyframeSourceService::CHOICES][(string) $context['stage']->id][$shotId] ?? []);
            $meta[MotoKeyframeSourceService::CHOICES][(string) $context['stage']->id][$shotId] = ['model' => $model, 'quality' => $quality, 'size' => $size] + $saved;
            $project->forceFill(['metadata_json' => $meta])->save();
        });
    }

    /**
     * @param  ?string  $reviewer  who decides
     * @return void the keyframe is the one the shot uses now, the one before it stays in the history, other shots do not change
     *
     * @throws RuntimeException when the keyframe is not of this shot, was made from what is no longer in use or its file cannot be read
     */
    public function approve(string $projectId, string $shotId, string $artifactId, ?string $reviewer): void
    {
        DB::transaction(function () use ($projectId, $shotId, $artifactId, $reviewer): void {
            VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $context = $this->sources->context($projectId);
            [$artifact, $image] = $this->candidate($context, $shotId, $artifactId);
            $problem = $this->sources->imageProblem($context, $image, true);

            if ($problem !== null) {
                throw new RuntimeException($problem.' — không duyệt được.');
            }

            $file = $this->sources->outputProblem($artifact, (array) $image->prompt_spec_json, true);

            if ($file !== null) {
                throw new RuntimeException($file.' — không duyệt được.');
            }

            $previous = isset($context['current'][$shotId]) ? (string) $context['current'][$shotId]->selected_artifact_id : null;

            if ($image->status === DesignImageStatus::SUPERSEDED->value) {
                $image->forceFill(['status' => DesignImageStatus::RENDERED->value])->save();
            }

            [$approved, $reason] = $this->store->approve($projectId, $artifactId, $reviewer, (string) $image->id, DesignImageStore::SCENE_KEYFRAME_TYPE, (string) $image->render_scene_id);

            if (! $approved) {
                throw new RuntimeException('Không duyệt được ảnh này ('.$reason.').');
            }

            $this->decide($context, $shotId, $artifact, $image, 'approved', $reviewer, ['previous_artifact_id' => $previous]);
        });
    }

    /**
     * @param  ?string  $reviewer  who decides
     * @return void the shot uses no keyframe now; the image and every past decision stay, and an earlier keyframe does not come back by itself
     *
     * @throws RuntimeException when the keyframe is not the one approved for this shot
     */
    public function revoke(string $projectId, string $shotId, string $artifactId, ?string $reviewer): void
    {
        DB::transaction(function () use ($projectId, $shotId, $artifactId, $reviewer): void {
            VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $context = $this->sources->context($projectId);
            [$artifact, $image] = $this->candidate($context, $shotId, $artifactId);

            if ($image->status !== DesignImageStatus::APPROVED->value || (string) $image->selected_artifact_id !== $artifactId) {
                throw new RuntimeException('Chỉ thu hồi được keyframe đang duyệt của shot này.');
            }

            $image->forceFill(['status' => DesignImageStatus::RENDERED->value, 'selected_artifact_id' => null, 'approved_at' => null, 'approved_by' => null])->save();
            app(ShotIntentService::class)->invalidateForKeyframe((string) $image->render_scene_id);
            $this->decide($context, $shotId, $artifact, $image, 'revoked', $reviewer, []);
        });
    }

    /** @return ?array{image_id: string, artifact_id: string, sha256: string, render_scene_id: string} the keyframe a later step may use for the shot, null when none is approved, its file is gone, changed, unreadable or the wrong size, or it was made from what is no longer in use; every file is read and hashed */
    public function approvedKeyframe(string $projectId, string $shotId): ?array
    {
        $context = $this->sources->context($projectId);
        $image = $context['current'][$shotId] ?? null;

        if ($image === null || $this->sources->keyframeProblem($context, $image) !== null) {
            return null;
        }

        $artifact = $image->artifacts->firstWhere('id', (string) $image->selected_artifact_id);

        return $artifact === null ? null : ['image_id' => (string) $image->id, 'artifact_id' => (string) $artifact->id, 'sha256' => (string) $artifact->sha256, 'render_scene_id' => (string) $image->render_scene_id];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $shot
     * @return array<string, mixed> one row of the screen: the shot, its image cards and manifest, its settings, why it cannot render, the hash of what a render would send, its keyframes and its state
     */
    private function row(array $context, array $shot, bool $deep = false): array
    {
        $built = $this->sources->cards($context, $shot, $deep);
        $settings = $this->settings($context, $shot);
        $prompt = (string) ($shot['prompts']['image'] ?? '');
        $problems = [...$built['problems'], ...$settings['problems']];

        if ($prompt === '' || ! hash_equals((string) ($shot['prompts']['image_sha256'] ?? ''), CanonicalJson::hashString($prompt))) {
            $problems[] = 'Prompt ảnh của shot rỗng hoặc hash không khớp bản đã duyệt.';
        }

        $candidates = $this->candidates($context, $shot);
        $states = array_column($candidates, 'state');
        $inUse = array_column(array_filter($candidates, static fn (array $row): bool => $row['image']->status === DesignImageStatus::APPROVED->value), 'state');
        $busy = array_intersect($states, ['queued', 'rendering']) !== [];
        $state = match (true) {
            in_array('approved', $inUse, true) => 'approved',
            in_array('broken', $inUse, true) => 'broken',
            in_array('stale', $inUse, true) => 'stale',
            in_array('rendering', $states, true) => 'rendering',
            in_array('queued', $states, true) => 'queued',
            in_array('awaiting', $states, true) => 'awaiting',
            ($states[0] ?? null) === 'failed' => 'failed',
            $problems !== [] => 'missing',
            default => 'ready',
        };

        return [
            'shot' => $shot,
            'planning' => self::planning($shot),
            'cards' => $built['cards'],
            'slots' => $built['slots'],
            'sources' => $built['sources'],
            'settings' => $settings,
            'price' => $settings['model'] === '' ? null : $this->pricing->unitFor($settings['model'], $settings['quality']),
            'problems' => $problems,
            'preview_hash' => CanonicalJson::hash([
                'token' => $context['approved']['token'],
                'shot_id' => (string) $shot['id'],
                'image_sha256' => (string) ($shot['prompts']['image_sha256'] ?? ''),
                'sources' => $built['sources'],
                'model' => $settings['model'],
                'quality' => $settings['quality'],
                'size' => $settings['size'],
                'variations' => 1,
            ]),
            'candidates' => $candidates,
            'state' => $state,
            'busy' => $busy,
            'renderable' => $problems === [] && ! $busy,
            'has_candidates' => $candidates !== [],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $shot
     * @return list<array<string, mixed>> every keyframe of the shot in this shot plan, the one in use first and then the newest, with its state and whether it can be approved or revoked
     */
    private function candidates(array $context, array $shot): array
    {
        $list = [];

        foreach ($context['images'][$shot['id']] ?? [] as $image) {
            $artifact = $image->artifacts->firstWhere('id', (string) $image->selected_artifact_id) ?? $image->artifacts->sortByDesc('created_at')->first();
            $spec = (array) $image->prompt_spec_json;
            $task = ImageRenderQueue::taskOf($image);
            $finished = in_array($image->status, [DesignImageStatus::RENDERED->value, DesignImageStatus::APPROVED->value, DesignImageStatus::SUPERSEDED->value], true);
            $problem = $finished ? $this->sources->imageProblem($context, $image) : null;
            $file = ! $finished ? null : ($artifact === null ? 'File ảnh không còn' : $this->sources->outputProblem($artifact, $spec, $image->status === DesignImageStatus::APPROVED->value));
            $state = match (true) {
                ($task['status'] ?? null) === 'pending' => 'queued',
                ($task['status'] ?? null) === 'running' => 'rendering',
                $image->status === DesignImageStatus::FAILED->value, $image->status === DesignImageStatus::CANDIDATE->value, $image->status === DesignImageStatus::QUEUED->value => 'failed',
                $file !== null => 'broken',
                $problem !== null && $image->status !== DesignImageStatus::SUPERSEDED->value => 'stale',
                $image->status === DesignImageStatus::APPROVED->value => 'approved',
                $image->status === DesignImageStatus::RENDERED->value => 'awaiting',
                default => 'superseded',
            };
            $error = $state !== 'failed' ? null : ((string) $image->render_error !== '' ? (string) $image->render_error : 'Lượt render không còn trong hàng đợi, chưa gửi provider — bấm Tạo bản khác.');
            $list[] = [
                'image' => $image,
                'artifact' => $artifact,
                'spec' => $spec,
                'task' => $task,
                'state' => $state,
                'error' => $error,
                'problem' => $problem,
                'file_problem' => $file,
                'approvable' => $artifact !== null && $problem === null && $file === null && in_array($state, ['awaiting', 'superseded'], true),
                'revocable' => $artifact !== null && $image->status === DesignImageStatus::APPROVED->value,
            ];
        }

        usort($list, static fn (array $a, array $b): int => (int) ($b['image']->status === DesignImageStatus::APPROVED->value) <=> (int) ($a['image']->status === DesignImageStatus::APPROVED->value));

        return $list;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{0: VideoArtifact, 1: VideoDesignImage}
     *
     * @throws RuntimeException when the artifact is not a keyframe of this shot in the approved shot plan
     */
    private function candidate(array $context, string $shotId, string $artifactId): array
    {
        foreach ($context['images'][$shotId] ?? [] as $image) {
            $artifact = $image->artifacts->firstWhere('id', $artifactId);

            if ($artifact !== null) {
                return [$artifact, $image];
            }
        }

        throw new RuntimeException("Ảnh không phải keyframe của shot {$shotId} trong kế hoạch đang duyệt.");
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extra
     */
    private function decide(array $context, string $shotId, VideoArtifact $artifact, VideoDesignImage $image, string $decision, ?string $reviewer, array $extra): void
    {
        VideoReviewDecision::create([
            'project_id' => $context['project_id'],
            'entity_type' => DesignImageStore::SCENE_KEYFRAME_TYPE,
            'entity_id' => (string) $artifact->id,
            'decision' => $decision,
            'revision' => 1 + (int) VideoReviewDecision::query()->where('entity_type', DesignImageStore::SCENE_KEYFRAME_TYPE)->where('entity_id', (string) $artifact->id)->max('revision'),
            'reviewer_id' => $reviewer,
            'metadata_json' => ['shot_id' => $shotId, 'stage_id' => (string) $context['stage']->id, 'image_id' => (string) $image->id, 'sha256' => (string) $artifact->sha256] + $extra,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $shot
     * @return array{model: string, quality: string, size: string, pricing: string, models: list<array<string, mixed>>, sizes: list<string>, qualities: list<string>, problems: list<string>} the settings saved for the shot, or the defaults; a saved value the model no longer offers falls back to a default
     */
    private function settings(array $context, array $shot): array
    {
        $models = $this->modelsFor($context['aspect_ratio']);
        $saved = (array) ($context['choices'][$shot['id']] ?? []);
        $entry = collect($models)->firstWhere('model', $saved['model'] ?? null) ?? collect($models)->firstWhere('default', true) ?? ($models[0] ?? null);

        if ($entry === null) {
            return ['model' => '', 'quality' => '', 'size' => '', 'pricing' => '', 'models' => [], 'sizes' => [], 'qualities' => [], 'problems' => ['Không có model ảnh dùng được.']];
        }

        $size = in_array($saved['size'] ?? null, $entry['sizes'], true) ? (string) $saved['size'] : (in_array($entry['default_size'], $entry['sizes'], true) ? (string) $entry['default_size'] : (string) ($entry['sizes'][0] ?? ''));
        $quality = in_array($saved['quality'] ?? null, $entry['qualities'], true) ? (string) $saved['quality'] : (in_array($entry['default_quality'], $entry['qualities'], true) ? (string) $entry['default_quality'] : (string) ($entry['qualities'][0] ?? ''));

        return [
            'model' => (string) $entry['model'],
            'quality' => $quality,
            'size' => $size,
            'pricing' => (string) $entry['pricing'],
            'models' => $models,
            'sizes' => $entry['sizes'],
            'qualities' => $entry['qualities'],
            'problems' => $size === '' ? ['Model '.$entry['model'].' không có size cho khung '.$context['aspect_ratio'].'.'] : [],
        ];
    }

    /** @return list<array<string, mixed>> the image models of the keyframe task, each with only the sizes of this frame and the qualities it supports */
    private function modelsFor(string $aspectRatio): array
    {
        if ($this->models === null) {
            [$models] = app(VideoProjectService::class)->sceneKeyframeModels();
            $this->models = $models;
        }

        return array_map(static function (array $entry) use ($aspectRatio): array {
            $model = ImageModel::tryFrom((string) $entry['model']);

            return [
                'model' => (string) $entry['model'],
                'label' => (string) $entry['label'],
                'default' => ($entry['default'] ?? false) === true,
                'pricing' => (string) $entry['pricing'],
                'default_size' => (string) $entry['controls']['default_size'],
                'default_quality' => (string) $entry['controls']['default_quality'],
                'sizes' => array_values(array_filter((array) $entry['controls']['sizes'], static fn (string $size): bool => self::ratio($size) === $aspectRatio)),
                'qualities' => array_values(array_filter((array) $entry['controls']['qualities'], static fn (string $quality): bool => $model !== null && ImageQuality::tryFrom($quality) !== null && $model->supports(ImageQuality::from($quality)))),
            ];
        }, $this->models);
    }

    /** @return string the size as a frame in lowest terms: 1152x2048 is 9:16 */
    private static function ratio(string $size): string
    {
        [$width, $height] = array_map('intval', explode('x', $size) + [1 => 0]);

        if ($width <= 0 || $height <= 0) {
            return '';
        }

        [$a, $b] = [$width, $height];

        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return intdiv($width, $a).':'.intdiv($height, $a);
    }

    /** @return list<array{key: string, text: string}> one line for a value, one for each item of a list */
    private static function lines(string $key, mixed $value): array
    {
        if (is_array($value) && $value !== [] && array_is_list($value)) {
            return array_map(static fn (mixed $item): array => ['key' => $key, 'text' => self::text($item)], $value);
        }

        return [['key' => $key, 'text' => self::text($value)]];
    }

    /** @return string the value word for word, every nested key with its value; no value reads "—" */
    private static function text(mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (! is_array($value)) {
            return (string) $value;
        }

        if (array_is_list($value)) {
            return implode(' | ', array_map(self::text(...), $value));
        }

        return implode(' · ', array_map(static fn (string|int $key, mixed $item): string => $key.': '.(is_array($item) && $item !== [] ? '{'.self::text($item).'}' : self::text($item)), array_keys($value), $value));
    }
}
