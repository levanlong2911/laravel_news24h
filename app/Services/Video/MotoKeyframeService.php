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
use App\Video\Environment\EnvironmentPlatePrompt;
use App\Video\Media\MediaModelRegistry;
use App\Video\Media\OpenAiImagePricing;
use App\Video\Moto\MotoContract;
use App\Video\Moto\MotoKeyframePrompt;
use App\Video\Queue\VideoQueues;
use App\Video\Scene\Services\ShotIntentService;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class MotoKeyframeService implements QueuedImageService
{
    public const STEP = 'moto_keyframe';

    public const SPEC_VERSION = 'moto-keyframe-v1';

    /** @var string the render spec a release names in its keyframe policy: every candidate says how it was made, composed or edited, and pins the prompt it was sent with and the decision that approved that prompt */
    public const SPEC_VERSION_V2 = MotoKeyframeSourceService::SPEC_V2;

    /** @var array<string, string> how the screen names each fault a person may point at in a candidate */
    public const FAULT_LABELS = [
        'identity' => 'Nhận diện',
        'geometry' => 'Hình học',
        'state' => 'Trạng thái',
        'people' => 'Nhân vật / tương tác',
        'set' => 'Bối cảnh / đạo cụ',
        'lighting' => 'Ánh sáng / vật liệu',
        'camera' => 'Camera / bố cục',
    ];

    /** @var list<string> the Gemini models the keyframe screen offers next to the OpenAI ones */
    private const GEMINI_MODELS = ['gemini-nano-banana-2.1'];

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
        'prompt' => ['amber', 'Chờ prompt ảnh'],
        'ready' => ['mute', 'Sẵn sàng render'],
        'superseded' => ['mute', 'Bản cũ'],
    ];

    /** @var array<string, list<string>> the groups of the Planning column and the fields of a shot each one holds */
    private const PLANNING = [
        'Shot' => ['id', 'scene', 'purpose', 'state', 'reveal', 'tl_in_ms', 'tl_out_ms', 'render_duration_ms', 'mode', 'used_start_ms', 'used_duration_ms', 'fingerprint'],
        'Khung đầu' => ['first_frame'],
        'Diễn biến về sau — không có ở khung đầu' => ['action', 'result', 'end_frame'],
        'Beat và coverage' => ['coverage', 'coverage_spans'],
        'Part' => ['parts_named', 'parts', 'configurations'],
        'Công việc và vị trí' => ['work', 'work_items', 'held_parts', 'places', 'absent_parts'],
        'Nhân vật' => ['people'],
        'Camera' => ['camera'],
        'Bối cảnh' => ['environment', 'set_brief'],
        'Continuity' => ['continuity', 'carried_from'],
        'Liên kết, giới hạn, giả định' => ['links', 'limits', 'assumptions'],
        'Thẻ chữ' => ['cards'],
        'Nhu cầu ảnh' => ['reference_needs'],
    ];

    /** @var list<string> the fields the shot plan copies from the scene and Phase 9 does not read, kept out of the Planning column */
    private const SCENE_COPIES = ['continuity_start', 'continuity_end', 'transition_in'];

    /** @var list<string> the groups of the Planning column shown open */
    private const OPEN_GROUPS = ['Shot', 'Khung đầu', 'Diễn biến về sau — không có ở khung đầu'];

    /** @var ?list<array<string, mixed>> the image models of the registry, read once for each instance */
    private ?array $models = null;

    /** @var ?KeyframePromptWriterService the Phase 9 writer the rows read their prompt state from, taken once for each instance */
    private ?KeyframePromptWriterService $writer = null;

    public function __construct(
        private readonly MotoKeyframeSourceService $sources,
        private readonly ShotRenderPlanService $plans,
        private readonly DesignImageStore $store,
        private readonly OpenAiImagePricing $pricing,
        private readonly MotoKeyframeFramingService $framing,
    ) {}

    /**
     * @param  ?string  $onlyShot  the one shot whose row is read, for a part of the screen that is drawn again alone; null reads every shot
     * @return array<string, mixed> everything the Keyframes screen shows; reading it writes nothing
     */
    public function panel(string $projectId, ?string $onlyShot = null): array
    {
        try {
            $context = $this->sources->context($projectId);
        } catch (RuntimeException $e) {
            return ['error' => $e->getMessage(), 'stage' => null, 'token' => null, 'aspect_ratio' => null, 'frame_ratio' => null, 'framing' => null, 'rows' => [], 'counts' => [], 'states' => self::STATES];
        }

        $shots = $onlyShot === null ? $context['shots'] : array_intersect_key($context['shots'], [$onlyShot => true]);
        $rows = array_values(array_map(fn (array $shot): array => $this->row($context, $shot), $shots));

        return [
            'error' => null,
            'stage' => $context['stage'],
            'token' => $context['approved']['token'],
            'aspect_ratio' => $context['aspect_ratio'],
            'frame_ratio' => $context['frame_ratio'],
            'framing' => $context['framing'],
            'rows' => $rows,
            'counts' => array_count_values(array_column($rows, 'state')),
            'states' => self::STATES,
        ];
    }

    /**
     * @param  array<string, mixed>  $shot  a stored shot
     * @return list<array{title: string, open: bool, lines: list<array{key: string, text: string}>}> every stored value of the shot except its prompts and the fields copied from the scene, word for word, grouped; a field with no value reads "—"
     */
    public static function planning(array $shot): array
    {
        $groups = [];
        $known = ['prompts' => true] + array_fill_keys(self::SCENE_COPIES, true);

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
     * @param  ?array{model: string, quality: string, size: string}  $settings  the model, quality and size sent with the render, saved for the shot before it renders; null renders with the saved ones
     * @return array{0: ?VideoDesignImage, 1: string} reason queued, rendered, failed, already_exists, or why nothing was sent
     */
    public function render(string $projectId, string $shotId, string $previewHash, string $operation, ?array $requester = null, ?array $settings = null): array
    {
        if (! Str::isUuid($operation)) {
            return [null, 'Mã lượt render không hợp lệ.'];
        }

        if ($settings !== null) {
            try {
                $this->saveSettings($projectId, $shotId, $settings['model'], $settings['quality'], $settings['size']);
            } catch (RuntimeException $e) {
                return [null, $e->getMessage()];
            }
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
                return [null, 'Nguồn đã đổi so với lúc xem — tải lại trang, chưa render.'];
            }

            if ($row['busy']) {
                return [null, "Shot {$shotId} đang có một lượt chờ hoặc đang render."];
            }

            $scene = $this->plans->ensure($projectId, $context['approved'], $shotId);

            return [$this->store->createSceneCandidate($scene, self::spec($context, $row, (string) $scene->id, $row['prompt'], $row['sources'], $operation, $previewHash)
                + ($context['spec_version'] === self::SPEC_VERSION_V2 ? ['creation_mode' => MotoKeyframePrompt::COMPOSE] : []), $identity), 'created'];
        };

        return $this->send($create, $requester);
    }

    /**
     * @param  string  $artifactId  the rendered file of the candidate to edit
     * @param  string  $stageId  the edit prompt a person approved for that candidate, as the screen showed it
     * @param  ?array{id: string, name: string}  $requester
     * @return array{0: ?VideoDesignImage, 1: string} reason queued, rendered, failed, already_exists, or why nothing was sent. The result is a new candidate that names the one it was edited from; the candidate edited, its file and the keyframe in use are not written
     */
    public function renderEdit(string $projectId, string $shotId, string $artifactId, string $stageId, string $previewHash, string $operation, ?array $requester = null): array
    {
        if (! Str::isUuid($operation)) {
            return [null, 'Mã lượt render không hợp lệ.'];
        }

        $create = function () use ($projectId, $shotId, $artifactId, $stageId, $previewHash, $operation): array {
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
            $edit = collect($row['candidates'])->first(static fn (array $candidate): bool => (string) $candidate['artifact']?->id === $artifactId)['edit'] ?? null;

            if ($edit === null) {
                return [null, 'Ảnh này không sửa được: không phải ứng viên còn hiệu lực của shot.'];
            }

            if ($edit['problems'] !== []) {
                return [null, implode(' · ', $edit['problems'])];
            }

            if (($edit['prompt']['stage_id'] ?? null) !== $stageId || ! hash_equals((string) $edit['preview_hash'], $previewHash)) {
                return [null, 'Prompt sửa hoặc nguồn đã đổi so với lúc xem — tải lại trang, chưa render.'];
            }

            if ($row['busy']) {
                return [null, "Shot {$shotId} đang có một lượt chờ hoặc đang render."];
            }

            $scene = $this->plans->ensure($projectId, $context['approved'], $shotId);

            return [$this->store->createSceneCandidate($scene, self::spec($context, $row, (string) $scene->id, $edit['prompt'], $edit['sources'], $operation, $previewHash) + [
                'creation_mode' => MotoKeyframePrompt::EDIT,
                'edit' => ['base_image_id' => (string) $edit['sources'][0]['image_id'], 'base_artifact_id' => (string) $edit['sources'][0]['artifact_id'], 'base_sha256' => (string) $edit['sources'][0]['sha256'], 'request' => $edit['request']],
            ], $identity), 'created'];
        };

        return $this->send($create, $requester);
    }

    /**
     * @param  array<string, mixed>  $context  MotoKeyframeSourceService::context()
     * @param  array<string, mixed>  $row  the keyframe row being rendered
     * @param  array<string, mixed>  $prompt  the approved prompt the render sends word for word, with its run, its decision and the fingerprint of its sources
     * @param  list<array<string, mixed>>  $sources  the manifest the render sends, in order
     * @return array<string, mixed> the render spec of one candidate, frozen with the request: the shot plan it is made from, the prompt and the decision that approved it, the manifest, the model, quality and size, and the framing of the release when it has one
     */
    private static function spec(array $context, array $row, string $sceneId, array $prompt, array $sources, string $operation, string $previewHash): array
    {
        return [
            'project_id' => (string) $context['project_id'],
            'operation' => 'scene_keyframe',
            'spec_version' => (string) $context['spec_version'],
            'shot_id' => (string) $row['shot']['id'],
            'shot_stage_id' => (string) $context['stage']->id,
            'planning_revision' => (int) $context['stage']->planning_revision,
            'source_token' => $context['approved']['token'],
            'render_scene_id' => $sceneId,
            'prompt' => (string) $prompt['text'],
            'image_sha256' => (string) $prompt['sha256'],
            'composer' => (string) $prompt['version'],
            'prompt_source' => ['stage_id' => (string) $prompt['stage_id'], 'decision_id' => (string) $prompt['decision_id'], 'source_fingerprint' => (string) $prompt['fingerprint']],
            'sources' => $sources,
            'reference_manifest_hash' => CanonicalJson::hash($sources),
            'model' => $row['settings']['model'],
            'variations' => 1,
            'pricing' => $row['settings']['pricing'],
            'operation_id' => $operation,
            'request_hash' => $previewHash,
        ] + self::providerSpec($row, (string) $context['frame_ratio']) + ($context['framing'] === null ? [] : ['framing' => $context['framing']]);
    }

    /**
     * @param  \Closure(): array{0: ?VideoDesignImage, 1: string}  $create  what stores the candidate of one operation, or says why none is stored
     * @param  ?array{id: string, name: string}  $requester
     * @return array{0: ?VideoDesignImage, 1: string} the candidate queued for a worker, or rendered in this request when no queue runs
     */
    private function send(\Closure $create, ?array $requester): array
    {
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
            $built = $this->sources->cards($context, $shot);
            $cards = collect($kind === 'environment' ? $built['slots'] : $built['cards'])->where('kind', $kind)->values();
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

    /**
     * @return void the choice a person made for one kind of image of the shot is dropped: the shot sends what its plan gives again, and a background added to a shot planned without one is no longer sent
     *
     * @throws RuntimeException when the shot is not in the approved plan or nothing was chosen for that kind
     */
    public function resetSource(string $projectId, string $shotId, string $kind): void
    {
        DB::transaction(function () use ($projectId, $shotId, $kind): void {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $context = $this->sources->context($projectId);

            if (! isset($context['shots'][$shotId])) {
                throw new RuntimeException("Shot {$shotId} không có trong kế hoạch shot đã duyệt.");
            }

            $meta = $project->metadata_json ?? [];
            $stageId = (string) $context['stage']->id;

            if (! array_key_exists($kind, (array) ($meta[MotoKeyframeSourceService::CHOICES][$stageId][$shotId]['sources'] ?? []))) {
                throw new RuntimeException('Thẻ này đang dùng ảnh của kế hoạch shot — không có lựa chọn nào để bỏ.');
            }

            unset($meta[MotoKeyframeSourceService::CHOICES][$stageId][$shotId]['sources'][$kind]);
            $project->forceFill(['metadata_json' => $meta])->save();
        });
    }

    /**
     * @param  bool  $leaveOut  true leaves the master out of what the shot sends, false sends it again
     * @return void the choice is stored for the shot of the approved plan; the master stays on the screen with the parts that lose their detail image, and nothing is sent
     *
     * @throws RuntimeException when the shot is not in the approved plan or the master is not a part master of the shot
     */
    public function leaveOutMaster(string $projectId, string $shotId, string $artifactId, bool $leaveOut): void
    {
        DB::transaction(function () use ($projectId, $shotId, $artifactId, $leaveOut): void {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $context = $this->sources->context($projectId);
            $shot = $context['shots'][$shotId] ?? throw new RuntimeException("Shot {$shotId} không có trong kế hoạch shot đã duyệt.");
            $card = collect($this->sources->cards($context, $shot)['cards'])->first(static fn (array $row): bool => $row['kind'] === 'design' && ($row['source']['artifact_id'] ?? null) === $artifactId);

            if ($card === null || ($card['left_out'] ?? false) === $leaveOut) {
                throw new RuntimeException('Master này không phải Reference master đang '.($leaveOut ? 'gửi' : 'bỏ').' của shot — tải lại trang.');
            }

            $meta = $project->metadata_json ?? [];
            $stageId = (string) $context['stage']->id;
            $off = array_map('strval', (array) ($meta[MotoKeyframeSourceService::CHOICES][$stageId][$shotId]['sources']['design_off'] ?? []));
            $off = $leaveOut ? array_values(array_unique([...$off, $artifactId])) : array_values(array_diff($off, [$artifactId]));

            if ($off === []) {
                unset($meta[MotoKeyframeSourceService::CHOICES][$stageId][$shotId]['sources']['design_off']);
            } else {
                $meta[MotoKeyframeSourceService::CHOICES][$stageId][$shotId]['sources']['design_off'] = $off;
            }

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

            $entry = collect($this->modelsFor($context['frame_ratio'], $context['framing']))->firstWhere('model', $model);

            if ($entry === null || ! in_array($size, $entry['sizes'], true) || ($entry['qualities'] === [] ? $quality !== '' : ! in_array($quality, $entry['qualities'], true))) {
                throw new RuntimeException('Model, chất lượng hoặc size không hợp lệ cho khung '.$context['frame_ratio'].'.');
            }

            $meta = $project->metadata_json ?? [];
            $saved = (array) ($meta[MotoKeyframeSourceService::CHOICES][(string) $context['stage']->id][$shotId] ?? []);
            $meta[MotoKeyframeSourceService::CHOICES][(string) $context['stage']->id][$shotId] = ['model' => $model, 'quality' => $quality, 'size' => $size] + $saved;
            $project->forceFill(['metadata_json' => $meta])->save();
        });
    }

    /**
     * @param  ?string  $reviewer  who decides
     * @param  ?string  $deliveryId  for a candidate rendered under a framing, the image cut from it that the person approves with it; the set of the two is what the shot uses from now on
     * @param  ?string  $expectedInUse  the decision that held the keyframe of the shot when the person looked, an empty string for none; null does not ask
     * @return void the keyframe is the one the shot uses now, the one before it stays in the history, other shots do not change. Every file, hash and source of the set is read again before the decision is stored, and a prompt, an edit or a window that was only tried never takes the place of the set in use
     *
     * @throws RuntimeException when the keyframe is not of this shot, was made from what is no longer in use, its file cannot be read, a candidate rendered under a framing comes without a delivery image that stands, or the keyframe in use changed since the person looked
     */
    public function approve(string $projectId, string $shotId, string $artifactId, ?string $reviewer, ?string $deliveryId = null, ?string $expectedInUse = null): void
    {
        DB::transaction(function () use ($projectId, $shotId, $artifactId, $reviewer, $deliveryId, $expectedInUse): void {
            VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $context = $this->sources->context($projectId);
            [$artifact, $image] = $this->candidate($context, $shotId, $artifactId);
            $before = $this->sources->approvedFrame($context, $shotId);

            if ($expectedInUse !== null && $expectedInUse !== (string) ($before['approval']['decision_id'] ?? '')) {
                throw new RuntimeException("Bộ keyframe đang dùng của {$shotId} đã đổi ở nơi khác — tải lại rồi duyệt.");
            }

            $problem = $this->sources->imageProblem($context, $image, true);

            if ($problem !== null) {
                throw new RuntimeException($problem.' — không duyệt được.');
            }

            $spec = (array) $image->prompt_spec_json;
            $file = $this->sources->outputProblem($artifact, $spec, true);

            if ($file !== null) {
                throw new RuntimeException($file.' — không duyệt được.');
            }

            $delivery = null;

            if (is_array($spec['framing'] ?? null)) {
                $delivery = collect(MotoKeyframeFramingService::deliveries($image, $artifact))->first(static fn (VideoArtifact $cut): bool => (string) $cut->id === $deliveryId)
                    ?? throw new RuntimeException('Chưa có ảnh video của ảnh này — bấm "Áp dụng khung" rồi duyệt bộ ảnh.');
                $cut = $this->framing->problem($delivery, $artifact, $spec['framing'], true);

                if ($cut !== null) {
                    throw new RuntimeException($cut.' — không duyệt được.');
                }
            }

            $previous = isset($context['current'][$shotId]) ? (string) $context['current'][$shotId]->selected_artifact_id : null;

            if ($image->status === DesignImageStatus::SUPERSEDED->value) {
                $image->forceFill(['status' => DesignImageStatus::RENDERED->value])->save();
            }

            [$approved, $reason] = $this->store->approve($projectId, $artifactId, $reviewer, (string) $image->id, DesignImageStore::SCENE_KEYFRAME_TYPE, (string) $image->render_scene_id);

            if (! $approved) {
                throw new RuntimeException('Không duyệt được ảnh này ('.$reason.').');
            }

            if ($delivery !== null && $previous === (string) $artifact->id && (string) ($before['delivery']?->id ?? '') !== (string) $delivery->id) {
                app(ShotIntentService::class)->invalidateForKeyframe((string) $image->render_scene_id);
            }

            $this->decide($context, $shotId, $artifact, $image, 'approved', $reviewer, ['previous_artifact_id' => $previous]
                + (($spec['spec_version'] ?? null) === self::SPEC_VERSION_V2 ? ['spec_version' => self::SPEC_VERSION_V2, 'source_token' => $context['approved']['token'], 'prompt' => (array) ($spec['prompt_source'] ?? []) + ['sha256' => $spec['image_sha256'] ?? null], 'master' => ['artifact_id' => (string) $artifact->id, 'sha256' => (string) $artifact->sha256]] : [])
                + ($delivery === null ? [] : ['delivery' => ['artifact_id' => (string) $delivery->id, 'sha256' => (string) $delivery->sha256, 'width' => (int) $delivery->width, 'height' => (int) $delivery->height], 'crop' => $delivery->metadata_json['crop'] ?? null, 'crop_hash' => $delivery->metadata_json['crop_hash'] ?? null]));
        });
    }

    /**
     * @param  string  $artifactId  the rendered file of a candidate of the shot
     * @param  array{x: int, y: int, width: int, height: int}  $rectangle  the window of the delivery frame, in pixels of that file
     * @return VideoArtifact the image handed to the video for that window, made now or already made from the same file and window; it is a candidate until a person approves the set, and the keyframe in use is not written. No model is called
     *
     * @throws RuntimeException when the candidate was not rendered under a framing, was made from what is no longer in use, its file cannot be read, or the window cannot be cut from it
     */
    public function applyFrame(string $projectId, string $shotId, string $artifactId, array $rectangle): VideoArtifact
    {
        return DB::transaction(function () use ($projectId, $shotId, $artifactId, $rectangle): VideoArtifact {
            VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $context = $this->sources->context($projectId);
            [$artifact, $image] = $this->candidate($context, $shotId, $artifactId);
            $spec = (array) $image->prompt_spec_json;

            if (! is_array($spec['framing'] ?? null)) {
                throw new RuntimeException('Ảnh này không render theo chính sách khung — không có ảnh video riêng.');
            }

            if (! in_array($image->status, [DesignImageStatus::RENDERED->value, DesignImageStatus::APPROVED->value, DesignImageStatus::SUPERSEDED->value], true)) {
                throw new RuntimeException('Ảnh chưa render xong — chưa chọn khung được.');
            }

            $problem = $this->sources->imageProblem($context, $image, true) ?? $this->sources->outputProblem($artifact, $spec, true);

            if ($problem !== null) {
                throw new RuntimeException($problem.' — không tạo ảnh video.');
            }

            return $this->framing->apply($image, $artifact, $rectangle, $spec['framing']);
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
        $frame = $this->sources->approvedFrame($context, $shotId);

        if ($frame === null || $frame['handoff'] === null || $this->sources->keyframeProblem($context, $frame['image']) !== null) {
            return null;
        }

        return ['image_id' => (string) $frame['image']->id, 'artifact_id' => (string) $frame['handoff']->id, 'sha256' => (string) $frame['handoff']->sha256, 'render_scene_id' => (string) $frame['image']->render_scene_id];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $shot
     * @return array<string, mixed> one row of the screen: the shot, its image cards and manifest, its settings, why it cannot render, the hash of what a render would send, its keyframes and its state
     */
    private function row(array $context, array $shot, bool $deep = false): array
    {
        $built = $this->sources->cards($context, $shot, $deep);
        $settings = $this->settingsFor($context, $shot);
        $prompt = $context['prompts'][$shot['id']] ?? null;
        $this->writer ??= app(KeyframePromptWriterService::class);
        $writer = $this->writer->screen((string) $context['project_id'], $context, $shot, $built, $settings);
        $problems = [...$built['problems'], ...$settings['problems']];
        $sourced = $problems === [];

        $contract = $prompt === null ? null : ($context['prompt_contracts'][$prompt['stage_id']] ?? null);
        $framed = is_array($context['framing'] ?? null) ? array_intersect_key($context['framing'], array_flip(['version', 'master_aspect_ratio', 'delivery_aspect_ratio', 'delivery_width', 'delivery_height'])) : null;

        if ($prompt === null) {
            $problems[] = 'Chưa có prompt ảnh đã duyệt — viết prompt ảnh bằng AI rồi duyệt trước khi render.';
        } elseif ($writer['stale']) {
            $problems[] = 'Nguồn đã đổi — cần viết hoặc duyệt lại prompt ảnh.';
        } elseif ($framed !== null && (($contract['inputs'] ?? null) !== MotoContract::KEYFRAME_PROMPT_INPUT_V6 || ($contract['framing'] ?? null) != $framed)) {
            $problems[] = 'Prompt ảnh đã duyệt viết theo đầu vào '.($contract['inputs'] ?? '—').' cho khung của phim, chưa theo khung ảnh gốc '.$framed['master_aspect_ratio'].' / ảnh video '.$framed['delivery_aspect_ratio'].' — viết lại prompt ảnh rồi duyệt trước khi render.';
        }

        $candidates = $this->candidates($context, $shot, $built, $settings, $deep);
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
            $problems !== [] && $sourced => 'prompt',
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
            'prompt' => $prompt,
            'writer' => $writer,
            'price' => $this->priceFor($settings),
            'problems' => $problems,
            'preview_hash' => CanonicalJson::hash([
                'token' => $context['approved']['token'],
                'shot_id' => (string) $shot['id'],
                'prompt' => $prompt === null ? null : ['stage_id' => $prompt['stage_id'], 'sha256' => $prompt['sha256']],
                'sources' => $built['sources'],
                'variations' => 1,
            ] + ($context['spec_version'] === self::SPEC_VERSION ? [] : ['spec_version' => $context['spec_version'], 'framing' => $context['framing']])),
            'candidates' => $candidates,
            'state' => $state,
            'busy' => $busy,
            'renderable' => $problems === [] && ! $busy,
            'has_candidates' => $candidates !== [],
            'in_use_decision' => (string) ($this->sources->approvedFrame($context, (string) $shot['id'])['approval']['decision_id'] ?? ''),
            'edit_form' => $this->editable($context) ? [
                'faults' => self::FAULT_LABELS,
                'targets' => self::targets($context, $shot, $built),
                'references' => array_map(static fn (array $source): array => ['artifact_id' => (string) $source['artifact_id'], 'code' => (string) $source['image_code'], 'kind' => (string) $source['kind']], $built['sources']),
                'limit' => (int) $context['limit'],
            ] : null,
        ];
    }

    /** @param  array<string, mixed>  $context */
    private function editable(array $context): bool
    {
        return ($context['edits'] ?? false) && $context['spec_version'] === self::SPEC_VERSION_V2;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $shot
     * @param  array<string, mixed>  $built  the cards of the shot
     * @return list<array{type: string, id: string, label: string}> what a fault of an edit may point at in this shot: every part it names, every person of the clip and the background it sends, each by its id
     */
    private static function targets(array $context, array $shot, array $built): array
    {
        $environment = collect($built['sources'])->firstWhere('kind', 'environment');

        return [
            ...array_map(static fn (string $id): array => ['type' => 'part', 'id' => $id, 'label' => trim($id.' '.($context['part_names'][$id] ?? ''))], MotoKeyframePrompt::partIds($shot, (array) $context['configurations'])),
            ...array_map(static fn (string $id): array => ['type' => 'character', 'id' => $id, 'label' => $id], array_values(array_unique(array_map('strval', array_column((array) ($shot['people'] ?? []), 'character'))))),
            ...($environment === null ? [] : [['type' => 'background', 'id' => (string) $environment['target'], 'label' => trim($environment['target'].' '.($environment['title'] ?? ''))]]),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $shot
     * @param  array<string, mixed>  $built  the cards of the shot
     * @param  array<string, mixed>  $settings  the image settings of the shot
     * @return list<array<string, mixed>> every keyframe of the shot in this shot plan, the one in use first and then the newest, with its state and whether it can be approved or revoked; a candidate rendered under a framing with the windows cut from it, and a candidate that still stands with the edit a person may ask of it
     */
    private function candidates(array $context, array $shot, array $built, array $settings, bool $deep = false): array
    {
        $list = [];

        foreach ($context['images'][$shot['id']] ?? [] as $image) {
            $artifact = MotoKeyframeFramingService::master($image);
            $spec = (array) $image->prompt_spec_json;
            $task = ImageRenderQueue::taskOf($image);
            $finished = in_array($image->status, [DesignImageStatus::RENDERED->value, DesignImageStatus::APPROVED->value, DesignImageStatus::SUPERSEDED->value], true);
            $problem = $finished ? $this->sources->imageProblem($context, $image) : null;
            $rendered = ! $finished || $artifact === null ? null : $this->sources->outputProblem($artifact, $spec, $image->status === DesignImageStatus::APPROVED->value);
            $file = ! $finished ? null : ($artifact === null ? 'File ảnh không còn' : ($image->status === DesignImageStatus::APPROVED->value ? $this->sources->frameProblem($image) : $rendered));
            $stands = $finished && $artifact !== null && $problem === null && $rendered === null;
            $framing = is_array($spec['framing'] ?? null) ? $spec['framing'] : null;
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
                'frame' => $framing === null || $artifact === null || ! $finished ? null : $this->frame($context, $image, $artifact, $framing, $stands),
                'edit' => $stands && $this->editable($context) && ($spec['framing'] ?? null) == $context['framing'] ? $this->edit($context, $shot, $built, $settings, $image, $artifact, $deep) : null,
            ];
        }

        usort($list, static fn (array $a, array $b): int => (int) ($b['image']->status === DesignImageStatus::APPROVED->value) <=> (int) ($a['image']->status === DesignImageStatus::APPROVED->value));

        return $list;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $framing  the framing the candidate was rendered under
     * @param  bool  $stands  whether the candidate was made from what is still in use and its rendered file reads as stored
     * @return array<string, mixed> what the screen shows of the window handed to the video: the size of the rendered file, the unit and the smallest and largest multiple of a window, the centred window, whether a window can be cut now, the delivery image the approved set uses, and every delivery image cut from the file with its window, whether it still stands and whether a person may approve the set with it
     */
    private function frame(array $context, VideoDesignImage $image, VideoArtifact $master, array $framing, bool $stands): array
    {
        $shotId = (string) ($image->prompt_spec_json['shot_id'] ?? '');
        $current = $context['current'][$shotId] ?? null;
        $inUse = $current !== null && (string) $current->id === (string) $image->id ? ($this->sources->approvedFrame($context, $shotId)['delivery'] ?? null) : null;
        $default = MotoKeyframeFramingService::defaultRectangle((int) $master->width, (int) $master->height, $framing);

        return [
            'width' => (int) $master->width,
            'height' => (int) $master->height,
            'unit' => $framing['unit'],
            'k_min' => (int) $framing['k_min'],
            'k_max' => $default === null ? 0 : intdiv($default['width'], $framing['unit'][0]),
            'default' => $default,
            'delivery_size' => $framing['delivery_width'].'x'.$framing['delivery_height'],
            'can_apply' => $stands && $default !== null,
            'in_use' => $inUse === null ? null : (string) $inUse->id,
            'deliveries' => array_map(function (VideoArtifact $delivery) use ($master, $framing, $stands, $inUse): array {
                $problem = $this->framing->problem($delivery, $master, $framing, false);

                return [
                    'artifact' => $delivery,
                    'crop' => (array) ($delivery->metadata_json['crop'] ?? []),
                    'problem' => $problem,
                    'in_use' => $inUse !== null && (string) $inUse->id === (string) $delivery->id,
                    'approvable' => $stands && $problem === null && ! ($inUse !== null && (string) $inUse->id === (string) $delivery->id),
                ];
            }, MotoKeyframeFramingService::deliveries($image, $master)),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $shot
     * @param  array<string, mixed>  $built  the cards of the shot
     * @param  array<string, mixed>  $settings  the image settings of the shot
     * @return array<string, mixed> what the screen shows of the edit of a candidate: the edit runs written for it and the one approved, the request that one was written for, the manifest a render of it sends — the candidate first, then the reference images the person kept — why it cannot render, and the hash of what a render would send
     */
    private function edit(array $context, array $shot, array $built, array $settings, VideoDesignImage $image, VideoArtifact $master, bool $deep): array
    {
        $shotId = (string) $shot['id'];
        $artifactId = (string) $master->id;
        $screen = $this->writer->editScreen((string) $context['project_id'], $context, $shot, $built, $settings, $artifactId);
        $prompt = $context['edit_prompts'][$shotId][$artifactId] ?? null;
        $manifest = $prompt === null ? ['sources' => [], 'problems' => []] : $this->sources->editSources($context, $shot, $image, $built, array_values((array) ($prompt['request']['references'] ?? [])), $deep);
        $problems = [
            ...$settings['problems'],
            ...($prompt === null ? ['Chưa có prompt sửa đã duyệt cho ảnh này — viết yêu cầu sửa rồi duyệt prompt trước khi render.'] : ($screen['stale'] ? ['Nguồn đã đổi — cần viết hoặc duyệt lại prompt sửa.'] : [])),
            ...$manifest['problems'],
        ];

        return [
            'screen' => $screen,
            'prompt' => $prompt,
            'request' => $prompt === null ? null : array_intersect_key((array) $prompt['request'], array_flip(['faults', 'targets', 'note'])),
            'sources' => $manifest['sources'],
            'problems' => $problems,
            'renderable' => $problems === [],
            'preview_hash' => CanonicalJson::hash([
                'token' => $context['approved']['token'],
                'shot_id' => $shotId,
                'edit_of' => $artifactId,
                'prompt' => $prompt === null ? null : ['stage_id' => $prompt['stage_id'], 'sha256' => $prompt['sha256']],
                'sources' => $manifest['sources'],
                'settings' => array_intersect_key($settings, array_flip(['model', 'quality', 'size'])),
                'variations' => 1,
                'spec_version' => $context['spec_version'],
                'framing' => $context['framing'],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{0: VideoArtifact, 1: VideoDesignImage} the rendered file of a candidate of the shot and its image; an image cut from a rendered file is never a candidate of its own
     *
     * @throws RuntimeException when the artifact is not a keyframe of this shot in the approved shot plan
     */
    private function candidate(array $context, string $shotId, string $artifactId): array
    {
        foreach ($context['images'][$shotId] ?? [] as $image) {
            $artifact = $image->artifacts->first(static fn (VideoArtifact $file): bool => (string) $file->id === $artifactId && $file->role !== MotoKeyframeFramingService::DELIVERY_ROLE);

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
    public function settingsFor(array $context, array $shot): array
    {
        $ratio = (string) ($context['frame_ratio'] ?? $context['aspect_ratio']);
        $framing = $context['framing'] ?? null;
        $models = $this->modelsFor($ratio, $framing);
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
            'problems' => $size === '' ? ['Model '.$entry['model'].' không có size cho khung '.$ratio.($framing === null ? '' : ' đủ tạo ảnh video '.$framing['delivery_width'].'x'.$framing['delivery_height']).'.'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $row  the keyframe row being rendered
     * @return array<string, mixed> the fields of the render spec that belong to the provider of the chosen model: an OpenAI size and quality, or a Gemini frame, image size, API version and the price frozen from the registry
     */
    private static function providerSpec(array $row, string $aspectRatio): array
    {
        $settings = $row['settings'];
        $entry = collect($settings['models'])->firstWhere('model', $settings['model']);

        if (($entry['provider'] ?? null) !== 'gemini') {
            return ['provider' => 'openai', 'quality' => $settings['quality'], 'size' => $settings['size']];
        }

        return [
            'provider' => 'gemini',
            'task' => EnvironmentPlatePrompt::TASK,
            'quality' => null,
            'size' => $aspectRatio.'@'.$settings['size'],
            'aspect_ratio' => $aspectRatio,
            'image_size' => $settings['size'],
            'api_version' => $entry['api_version'],
            'shape' => $entry['shape'],
            'unit_cost_usd' => $row['price']['usd'] ?? null,
            'pricing_version' => $row['price']['version'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings  the settings of a shot
     * @return ?array{usd: float, version: string} the price of one image: a Gemini model's frozen price of the chosen size, an OpenAI model's price of the chosen quality, null when there is none
     */
    private function priceFor(array $settings): ?array
    {
        $entry = collect($settings['models'])->firstWhere('model', $settings['model']);

        if ($entry === null) {
            return null;
        }

        if ($entry['provider'] === 'gemini') {
            $usd = $entry['prices'][$settings['size']] ?? null;

            return (is_int($usd) || is_float($usd)) && is_string($entry['pricing_version']) ? ['usd' => (float) $usd, 'version' => $entry['pricing_version']] : null;
        }

        return $this->pricing->unitFor($settings['model'], $settings['quality']);
    }

    /**
     * @param  ?array<string, mixed>  $framing  the framing of the release, whose master sizes are the only ones offered for a provider it names
     * @return list<array<string, mixed>> the image models of the keyframe task, each with only the sizes of this frame and the qualities it supports; a Gemini model has no quality, its sizes are the image sizes when it offers the frame, with their prices
     */
    private function modelsFor(string $aspectRatio, ?array $framing = null): array
    {
        $held = static fn (string $provider, array $sizes): array => isset($framing['master_sizes'][$provider]) ? array_values(array_intersect($sizes, $framing['master_sizes'][$provider])) : $sizes;

        if ($this->models === null) {
            try {
                $this->models = array_values(array_filter(
                    app(MediaModelRegistry::class)->forTask(EnvironmentPlatePrompt::TASK),
                    static fn (array $entry): bool => $entry['provider'] === 'openai' || in_array($entry['model'], self::GEMINI_MODELS, true),
                ));
            } catch (InvalidArgumentException $e) {
                Log::error('moto-keyframe: registry model hong', ['error' => $e->getMessage()]);
                $this->models = [];
            }
        }

        return array_map(static function (array $entry) use ($aspectRatio, $held): array {
            if ($entry['provider'] === 'gemini') {
                $controls = (array) $entry['controls'];

                return [
                    'provider' => 'gemini',
                    'model' => (string) $entry['model'],
                    'label' => (string) $entry['label'],
                    'default' => ($entry['default'] ?? false) === true,
                    'pricing' => (string) $entry['pricing'],
                    'default_size' => (string) $controls['default_image_size'],
                    'default_quality' => '',
                    'sizes' => in_array($aspectRatio, (array) $controls['aspect_ratios'], true) ? $held('gemini', array_values((array) $controls['image_sizes'])) : [],
                    'qualities' => [],
                    'prices' => (array) ($controls['prices'] ?? []),
                    'pricing_version' => $entry['pricing_version'] ?? null,
                    'api_version' => (string) $entry['api_version'],
                    'shape' => (string) $entry['shape'],
                ];
            }

            $model = ImageModel::tryFrom((string) $entry['model']);

            return [
                'provider' => 'openai',
                'model' => (string) $entry['model'],
                'label' => (string) $entry['label'],
                'default' => ($entry['default'] ?? false) === true,
                'pricing' => (string) $entry['pricing'],
                'default_size' => (string) $entry['controls']['default_size'],
                'default_quality' => (string) $entry['controls']['default_quality'],
                'sizes' => $held('openai', array_values(array_filter((array) $entry['controls']['sizes'], static fn (string $size): bool => self::ratio($size) === $aspectRatio))),
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
