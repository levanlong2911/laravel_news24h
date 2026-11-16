<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Video\Moto\MotoKeyframePrompt;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use RuntimeException;

/**
 * What a clip of the shot flow comes from: the approved shot plan and the keyframe approved for each shot on the Keyframes screen.
 * A keyframe is read the way that screen reads it, through the render row of its shot, and one that fails a check is reported and never replaced by a similar one.
 *
 * The contract the Keyframes screen writes to: a video_design_images row of the project with image_type scene_keyframe, render_scene_id the row ShotRenderPlanService keeps for the shot in the approved plan, status approved with approved_at and selected_artifact_id set through DesignImageStore::approve, and prompt_spec_json {shot_id, shot_stage_id, source_token, prompt, image_sha256, composer, prompt_source, sources} as MotoKeyframeService::render stores them, its prompt the one approved for the shot in Phase 9. Its selected video_artifacts row carries design_image_id, a render_id, a disk of video.veo.source_disks and the sha256 of a PNG or JPEG in the frame of the film. Every check MotoKeyframeSourceService::keyframeProblem makes holds too.
 */
final class MotoClipSourceService
{
    /** @var string the key of the project metadata that holds, by shot plan and shot, how a person asked the clip of the shot to be made and the reference images they left out */
    public const INPUTS = 'moto_clip_inputs';

    public const KEYFRAME = 'keyframe';

    public const REFERENCES = 'references';

    /** @var list<string> the kinds of image of the Keyframes screen a clip made from reference images sends; the keyframe of another shot is no reference */
    private const REFERENCE_KINDS = ['environment', 'identity', 'design', 'character'];

    public function __construct(
        private readonly ShotSourceService $shots,
        private readonly FilesystemFactory $storage,
        private readonly MotoKeyframeSourceService $frames,
    ) {}

    /**
     * @return array{workflow: string, action: string, settings: array<string, mixed>} the clip flow of the project's workflow
     *
     * @throws RuntimeException when the project does not render clips by shot
     */
    public function workflowFor(string $projectId): array
    {
        $project = VideoProject::query()->with('article.category')->find($projectId) ?? throw new RuntimeException('Không tìm thấy dự án.');
        $workflow = (string) (config('video.superyacht.contracts.categories')[$project->article?->category?->slug ?? ''] ?? '');
        $entry = $workflow === '' ? null : (config('video.clips.workflows')[$workflow] ?? null);

        if (! is_array($entry)) {
            throw new RuntimeException('Dự án này không render clip theo shot.');
        }

        return ['workflow' => $workflow, 'action' => (string) $entry['action'], 'settings' => $entry];
    }

    public function supports(string $projectId): bool
    {
        try {
            $this->workflowFor($projectId);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @return array{stage_id: string, revision: int, token: array<string, mixed>, plan: array<string, mixed>, context: array<string, mixed>} the approved shot plan with the check context pinned with its run
     *
     * @throws RuntimeException when no shot plan is approved or the approved one is no longer valid
     */
    public function source(string $projectId): array
    {
        $approved = $this->shots->approvedSource($projectId);
        $stage = $this->shots->shotStage($projectId, $approved['stage_id']) ?? throw new RuntimeException('Kế hoạch shot đã duyệt không còn hoặc không thuộc dự án.');

        return [
            'stage_id' => $approved['stage_id'],
            'revision' => (int) $stage->planning_revision,
            'token' => $approved['token'],
            'plan' => $approved['plan'],
            'context' => (array) (PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null)['context'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $source  source()
     * @param  array<string, mixed>  $settings  the settings of the clip flow
     * @param  ?array<string, mixed>  $context  frameContext(), read again when not given
     * @return array<string, array{keyframe: ?array<string, mixed>, url: ?string, blocked: ?array{code: string, message: string}}> for every shot of the plan by id, its keyframe to pin or why it has none it can use
     */
    public function keyframes(string $projectId, array $source, array $settings, ?array $context = null): array
    {
        $context ??= $this->frames->context($projectId);
        $result = [];

        foreach ((array) $source['plan']['shots'] as $row) {
            $shotId = (string) $row['id'];
            $approved = array_values(array_filter((array) ($context['images'][$shotId] ?? []), static fn (VideoDesignImage $image): bool => $image->status === DesignImageStatus::APPROVED->value));
            $result[$shotId] = match (true) {
                count($approved) > 1 => self::blocked('keyframe_ambiguous', 'Shot '.$shotId.' có '.count($approved).' keyframe đã duyệt — không tự chọn.'),
                count($approved) === 1 => $this->checked($projectId, $context, $approved[0], $row, $source, $settings),
                default => self::blocked('keyframe_missing', "Shot {$shotId} chưa có keyframe đã duyệt ở màn Keyframes."),
            };
        }

        return $result;
    }

    /** @return array<string, mixed> what the Keyframes screen reads for the project, for keyframes() and inputs() to share one reading */
    public function frameContext(string $projectId): array
    {
        return $this->frames->context($projectId);
    }

    /**
     * @param  array<string, mixed>  $source  source()
     * @param  array<string, mixed>  $settings  the settings of the clip flow
     * @param  ?array<string, mixed>  $context  frameContext(), read again when not given
     * @return array<string, array{mode: string, images: list<array<string, mixed>>, cards: list<array<string, mixed>>, limit: int, blocked: ?array{code: string, message: string}}> for every shot of the plan by id, how its clip is made: from its approved keyframe, or from the reference images the Keyframes screen builds for the shot, in their order, without the ones a person left out; the cards are every reference image of the shot for the screen, a master of parts with how each of its parts stands in the shot; limit is how many images the model of the flow takes, and blocked says why the reference images cannot be sent
     */
    public function inputs(string $projectId, array $source, array $settings, ?array $context = null): array
    {
        $context ??= $this->frames->context($projectId);
        $stored = (array) (VideoProject::query()->whereKey($projectId)->value('metadata_json')[self::INPUTS][$source['stage_id']] ?? []);
        $limit = (int) ($settings['reference_limit'] ?? 0);
        $default = ($settings['default_input'] ?? null) === self::REFERENCES ? self::REFERENCES : self::KEYFRAME;
        $result = [];

        foreach ((array) $source['plan']['shots'] as $row) {
            $shotId = (string) $row['id'];
            $choice = (array) ($stored[$shotId] ?? []);
            $mode = in_array($choice['mode'] ?? null, [self::KEYFRAME, self::REFERENCES], true) ? $choice['mode'] : $default;
            $excluded = array_map('strval', (array) ($choice['excluded'] ?? []));
            $built = $this->frames->cards($context, (array) ($context['shots'][$shotId] ?? $row), false, false);
            $own = array_values(array_filter($built['cards'], static fn (array $card): bool => in_array($card['kind'], self::REFERENCE_KINDS, true)));
            $images = [];

            foreach ($built['sources'] as $image) {
                if (in_array($image['kind'], self::REFERENCE_KINDS, true) && ! in_array($image['artifact_id'], $excluded, true)) {
                    $images[] = ['position' => count($images), 'usage' => 'reference_only'] + array_diff_key($image, ['position' => true, 'chosen' => true, 'candidate_id' => true]);
                }
            }

            $broken = array_values(array_filter($own, static fn (array $card): bool => $card['problem'] !== null));
            $result[$shotId] = [
                'mode' => $mode,
                'images' => $mode === self::REFERENCES ? $images : [],
                'cards' => array_map(static fn (array $card): array => [
                    'name' => (string) $card['name'],
                    'kind' => (string) $card['kind'],
                    'artifact_id' => $card['source']['artifact_id'] ?? null,
                    'code' => $card['source']['code'] ?? null,
                    'url' => isset($card['source']['artifact_id']) ? route('video-artifacts.show', (string) $card['source']['artifact_id']) : null,
                    'problem' => $card['problem'],
                    'included' => isset($card['source']['artifact_id']) && $card['problem'] === null && ! in_array($card['source']['artifact_id'], $excluded, true),
                    'parts' => $card['kind'] === 'design' ? (array) ($card['presentations'] ?? []) : [],
                ], $own),
                'limit' => $limit,
                'blocked' => match (true) {
                    $mode !== self::REFERENCES => null,
                    $broken !== [] => ['code' => 'reference_problem', 'message' => "Ảnh tham chiếu của shot {$shotId} chưa dùng được: ".implode(' · ', array_map(static fn (array $card): string => $card['name'].': '.$card['problem'], $broken)).'.'],
                    $images === [] => ['code' => 'references_missing', 'message' => "Shot {$shotId} chưa có ảnh tham chiếu nào để gửi — giữ ít nhất một ảnh ở cột Ảnh đầu vào."],
                    count($images) > $limit => ['code' => 'references_limit', 'message' => "Shot {$shotId} gửi ".count($images)." ảnh tham chiếu, vượt giới hạn {$limit} ảnh của model — bỏ bớt ảnh ở cột Ảnh đầu vào."],
                    default => null,
                },
            ];
        }

        return $result;
    }

    /**
     * @return list<array{shot_id: string, mode: string, added: list<string>, removed: list<string>, missing: list<string>, images: int, expected: int, limit: int, over: bool, image_prompt: ?string, video_prompt: ?string}> for every shot of the approved plan, what the Reference plan built now changes against the one saved last: the part masters the shot sends now and no longer sends, by image code, with the masters a person chose or left out for the way its clip is made; the parts in its frame with no approved image; how many images it sends now and at least how many once every source is there; the limit of that way; and, for the approved keyframe prompt and the approved video prompt of the shot, whether the check of its own screen still takes it, null when the shot has none; reading it writes nothing
     *
     * @throws RuntimeException when no shot plan is approved
     */
    public function impact(string $projectId): array
    {
        $source = $this->source($projectId);
        $settings = $this->workflowFor($projectId)['settings'];
        $context = $this->frames->context($projectId);
        $inputs = $this->inputs($projectId, $source, $settings, $context);
        $before = app(PartsDetailService::class)->savedPartsByShot($projectId);
        $video = app(ClipPromptWriterService::class)->current($projectId, $source, $this->keyframes($projectId, $source, $settings, $context), $inputs);
        $frames = app(MotoKeyframeService::class);
        $imageWriter = app(KeyframePromptWriterService::class);
        $codes = array_column((array) $context['masters'], 'code', 'artifact_id');
        $named = static fn (array $ids): array => array_values(array_map(static fn (string $id): string => (string) ($codes[$id] ?? $id), $ids));
        $mastersOn = static fn (array $built): array => array_values(array_unique(array_filter(array_map(static fn (array $card): ?string => $card['kind'] === 'design' && ! ($card['left_out'] ?? false) ? ($card['source']['artifact_id'] ?? null) : null, $built['cards']))));
        $rows = [];

        foreach ((array) $source['plan']['shots'] as $row) {
            $shotId = (string) $row['id'];
            $shot = (array) ($context['shots'][$shotId] ?? $row);
            $byReferences = $inputs[$shotId]['mode'] === self::REFERENCES;
            $built = $this->frames->cards($context, $shot, false, ! $byReferences);
            $earlier = $this->frames->cards($context, ['id' => $shotId, 'reference_needs' => [], 'parts' => array_map(static fn (string $part): array => ['part' => $part, 'presentation' => ''], (array) ($before[$shotId] ?? []))], false, ! $byReferences);
            $left = $byReferences ? array_column(array_filter($inputs[$shotId]['cards'], static fn (array $card): bool => $card['kind'] === 'design' && $card['artifact_id'] !== null && ! $card['included']), 'artifact_id') : [];
            $now = array_values(array_diff($mastersOn($built), $left));
            $was = array_values(array_diff($mastersOn($earlier), $left));
            $cards = array_values(array_filter($built['cards'], static fn (array $card): bool => ! ($card['left_out'] ?? false) && (! $byReferences || in_array($card['kind'], self::REFERENCE_KINDS, true))));
            $images = $byReferences ? count($inputs[$shotId]['images']) : count($built['sources']);
            $waiting = count(array_filter($cards, static fn (array $card): bool => $card['source'] === null));
            $limit = $byReferences ? (int) $inputs[$shotId]['limit'] : (int) $context['limit'];
            $added = array_values(array_diff($now, $was));
            $removed = array_values(array_diff($was, $now));
            $missing = array_merge([], ...array_map(static fn (array $card): array => $card['kind'] === 'design' && $card['source'] === null ? (array) $card['parts'] : [], $built['cards']));
            $imagePrompt = ($context['prompts'][$shotId] ?? null) === null ? null
                : ($imageWriter->screen($projectId, $context, $shot, $this->frames->cards($context, $shot), $frames->settingsFor($context, $shot))['stale'] ? 'nguồn đã đổi — cần viết hoặc duyệt lại' : 'dùng được');
            $videoPrompt = match ($video[$shotId]['blocked']['code'] ?? null) {
                null => 'dùng được',
                'prompt_missing' => null,
                default => 'nguồn đã đổi — cần viết hoặc duyệt lại',
            };
            $rows[] = [
                'shot_id' => $shotId,
                'mode' => (string) $inputs[$shotId]['mode'],
                'added' => $named($added),
                'removed' => $named($removed),
                'missing' => $missing,
                'images' => $images,
                'expected' => $images + $waiting,
                'limit' => $limit,
                'over' => $images + $waiting > $limit,
                'image_prompt' => $imagePrompt,
                'video_prompt' => $videoPrompt,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $included  the artifacts of the reference images the person keeps
     * @return void the way the clip of the shot is made and the reference images left out are stored for the approved shot plan; nothing is sent and no prompt is written
     *
     * @throws RuntimeException when the shot is not in the approved plan or the mode is not one this flow has
     */
    public function saveInput(string $projectId, string $shotId, string $mode, array $included): void
    {
        if (! in_array($mode, [self::KEYFRAME, self::REFERENCES], true)) {
            throw new RuntimeException('Cách render không hợp lệ.');
        }

        $source = $this->source($projectId);
        $inputs = $this->inputs($projectId, $source, $this->workflowFor($projectId)['settings']);

        if (! isset($inputs[$shotId])) {
            throw new RuntimeException("Shot {$shotId} không có trong kế hoạch shot đã duyệt.");
        }

        $offered = array_values(array_filter(array_column($inputs[$shotId]['cards'], 'artifact_id'), 'is_string'));
        $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
        $metadata = (array) ($project->metadata_json ?? []);
        $metadata[self::INPUTS][$source['stage_id']][$shotId] = [
            'mode' => $mode,
            'excluded' => array_values(array_diff($offered, array_map('strval', $included))),
        ];
        $project->forceFill(['metadata_json' => $metadata])->save();
    }

    /**
     * @param  array<string, mixed>  $context  MotoKeyframeSourceService::context()
     * @param  array<string, mixed>  $row  the shot
     * @param  array<string, mixed>  $source  source()
     * @param  array<string, mixed>  $settings
     * @return array{keyframe: ?array<string, mixed>, url: ?string, blocked: ?array{code: string, message: string}}
     */
    private function checked(string $projectId, array $context, VideoDesignImage $image, array $row, array $source, array $settings): array
    {
        $shotId = (string) $row['id'];
        $spec = (array) $image->prompt_spec_json;
        $token = (array) ($spec['source_token'] ?? []);

        if (($spec['shot_id'] ?? null) !== $shotId || ! is_string($spec['composer'] ?? null) || ! is_string($spec['image_sha256'] ?? null)) {
            return self::blocked('keyframe_link_invalid', "Keyframe của shot {$shotId} không theo hợp đồng của màn Keyframes.");
        }

        if (($spec['shot_stage_id'] ?? null) !== $source['stage_id'] || ($token['stage_id'] ?? null) !== $source['token']['stage_id'] || ($token['content_hash'] ?? null) !== $source['token']['content_hash']) {
            return self::blocked('keyframe_source_changed', "Keyframe của shot {$shotId} được làm từ nguồn khác bản kế hoạch đã duyệt.");
        }

        if ($spec['image_sha256'] !== ($context['prompts'][$shotId]['sha256'] ?? null) || ($spec['prompt_source']['stage_id'] ?? null) !== ($context['prompts'][$shotId]['stage_id'] ?? null)) {
            return self::blocked('keyframe_prompt_changed', "Prompt của keyframe shot {$shotId} khác prompt shot hiện hành — render và duyệt lại keyframe.");
        }

        if ($image->approved_at === null || $image->selected_artifact_id === null) {
            return self::blocked('keyframe_unapproved', "Keyframe của shot {$shotId} chưa có quyết định duyệt cho một file.");
        }

        $artifact = VideoArtifact::query()->whereKey($image->selected_artifact_id)->where('project_id', $projectId)->where('design_image_id', $image->id)->first();

        if ($artifact === null || $artifact->render_id === null) {
            return self::blocked('keyframe_artifact_missing', "File keyframe đã duyệt của shot {$shotId} không còn hoặc không gắn lượt render nào.");
        }

        $problem = $this->fileProblem($artifact, (string) ($source['context']['aspect_ratio'] ?? ''), (float) ($settings['aspect_tolerance'] ?? 0));

        if ($problem !== null) {
            return self::blocked($problem[0], "Keyframe của shot {$shotId}: {$problem[1]}");
        }

        $stale = $this->frames->keyframeProblem($context, $image);

        if ($stale !== null) {
            return self::blocked('keyframe_source_changed', "Keyframe của shot {$shotId}: {$stale}.");
        }

        return [
            'keyframe' => [
                'image_id' => (string) $image->id,
                'artifact_id' => (string) $artifact->id,
                'sha256' => (string) $artifact->sha256,
                'source_token' => ['stage_id' => (string) $token['stage_id'], 'content_hash' => (string) $token['content_hash']],
                'prompt' => ['version' => $spec['composer'], 'surface_rule' => MotoKeyframePrompt::SURFACE_RULE, 'sha256' => $spec['image_sha256']],
            ],
            'url' => route('video-artifacts.show', (string) $artifact->id),
            'blocked' => null,
        ];
    }

    /** @return ?array{0: string, 1: string} why the file of a keyframe cannot be sent: where it is stored, its bytes against the hash the artifact records, its format or its shape against the frame of the film */
    private function fileProblem(VideoArtifact $artifact, string $aspect, float $tolerance): ?array
    {
        $disk = (string) $artifact->storage_disk;
        $path = (string) $artifact->storage_path;

        if (! in_array($disk, (array) config('video.veo.source_disks', ['video_artifacts']), true)) {
            return ['keyframe_disk_refused', 'file nằm trên disk không được phép: '.$disk.'.'];
        }

        $filesystem = $this->storage->disk($disk);

        if ($path === '' || ! $filesystem->exists($path)) {
            return ['keyframe_file_missing', 'không còn file trên disk.'];
        }

        $size = (int) $filesystem->size($path);

        if ($size <= 0 || $size > (int) config('video.veo.max_source_bytes', 33554432)) {
            return ['keyframe_file_size', 'kích thước file không hợp lệ: '.$size.'.'];
        }

        $bytes = (string) $filesystem->get($path);

        if (! hash_equals((string) $artifact->sha256, hash('sha256', $bytes))) {
            return ['keyframe_hash_mismatch', 'file khác hash đã duyệt.'];
        }

        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        $width = is_array($info) ? (int) $info[0] : 0;
        $height = is_array($info) ? (int) $info[1] : 0;

        if (! in_array($mime, ['image/png', 'image/jpeg'], true) || $width <= 0 || $height <= 0) {
            return ['keyframe_format', 'file không đọc được như PNG hoặc JPEG.'];
        }

        if (preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/', $aspect, $ratio) !== 1) {
            return ['keyframe_ratio', 'kế hoạch không ghi tỉ lệ khung hình để so.'];
        }

        $wanted = (int) $ratio[1] / (int) $ratio[2];

        return abs($width / $height - $wanted) / $wanted > $tolerance
            ? ['keyframe_ratio', "ảnh {$width}×{$height} lệch khung {$aspect}."]
            : null;
    }

    /** @return array{keyframe: null, url: null, blocked: array{code: string, message: string}} */
    private static function blocked(string $code, string $message): array
    {
        return ['keyframe' => null, 'url' => null, 'blocked' => ['code' => $code, 'message' => $message]];
    }
}
