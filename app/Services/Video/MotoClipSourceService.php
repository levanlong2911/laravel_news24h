<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Video\Moto\MotoShotPrompt;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use RuntimeException;

/**
 * What a clip of the shot flow comes from: the approved shot plan and the keyframe Phase 9 approved for each shot.
 * A keyframe is found through the link Phase 9 stores on it, never through a scene row, and one that fails a check is reported and never replaced by a similar one.
 *
 * The contract Phase 9 writes to: a video_design_images row of the project with image_type scene_keyframe, render_scene_id null, state slot(), status approved with approved_at and selected_artifact_id set through DesignImageStore::approve, and metadata_json moto_shot {version: LINK_VERSION, shot_plan_stage_id, shot_id, source_token {stage_id, content_hash}, prompt {version, surface_rule, sha256} as MotoShotPrompt::keyframe gives them}. Its selected video_artifacts row carries design_image_id, a render_id, a disk of video.veo.source_disks and the sha256 of a PNG or JPEG in the frame of the film.
 */
final class MotoClipSourceService
{
    /** @var string the key of metadata_json on a keyframe that ties it to a shot */
    public const LINK_KEY = 'moto_shot';

    public const LINK_VERSION = 'moto-keyframe-link-v1';

    public function __construct(
        private readonly ShotSourceService $shots,
        private readonly FilesystemFactory $storage,
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

    /** @return string the slot of the keyframe of a shot in a revision of the plan: approving a keyframe replaces only the one in its own slot */
    public static function slot(string $shotId, int $revision): string
    {
        return "{$shotId}@r{$revision}";
    }

    /**
     * @param  array<string, mixed>  $source  source()
     * @param  array<string, mixed>  $settings  the settings of the clip flow
     * @return array<string, array{keyframe: ?array<string, mixed>, url: ?string, blocked: ?array{code: string, message: string}}> for every shot of the plan by id, its keyframe to pin or why it has none it can use
     */
    public function keyframes(string $projectId, array $source, array $settings): array
    {
        $images = VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->whereNull('render_scene_id')
            ->where('status', DesignImageStatus::APPROVED->value)
            ->get()
            ->filter(static fn (VideoDesignImage $image): bool => is_array($image->metadata_json[self::LINK_KEY] ?? null))
            ->groupBy(static fn (VideoDesignImage $image): string => (string) ($image->metadata_json[self::LINK_KEY]['shot_id'] ?? ''));
        $result = [];

        foreach ((array) $source['plan']['shots'] as $row) {
            $shotId = (string) $row['id'];
            $all = $images->get($shotId, collect());
            $slot = self::slot($shotId, $source['revision']);
            $own = $all->filter(static fn (VideoDesignImage $image): bool => $image->state === $slot)->values();
            $result[$shotId] = match (true) {
                $own->count() > 1 => self::blocked('keyframe_ambiguous', "Shot {$shotId} có {$own->count()} keyframe đã duyệt — không tự chọn."),
                $own->count() === 1 => $this->checked($projectId, $own->first(), $row, $source, $settings),
                $all->isNotEmpty() => self::blocked('keyframe_source_changed', "Keyframe của shot {$shotId} được duyệt cho bản kế hoạch khác — duyệt lại keyframe cho bản hiện hành."),
                default => self::blocked('keyframe_missing', "Shot {$shotId} chưa có keyframe đã duyệt."),
            };
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $row  the shot
     * @param  array<string, mixed>  $source  source()
     * @param  array<string, mixed>  $settings
     * @return array{keyframe: ?array<string, mixed>, url: ?string, blocked: ?array{code: string, message: string}}
     */
    private function checked(string $projectId, VideoDesignImage $image, array $row, array $source, array $settings): array
    {
        $shotId = (string) $row['id'];
        $link = (array) $image->metadata_json[self::LINK_KEY];
        $token = (array) ($link['source_token'] ?? []);
        $pinned = (array) ($link['prompt'] ?? []);

        if (($link['version'] ?? null) !== self::LINK_VERSION) {
            return self::blocked('keyframe_link_invalid', "Keyframe của shot {$shotId} không theo hợp đồng Phase 9 hiện hành.");
        }

        if (($link['shot_plan_stage_id'] ?? null) !== $source['stage_id'] || ($token['stage_id'] ?? null) !== $source['token']['stage_id'] || ($token['content_hash'] ?? null) !== $source['token']['content_hash']) {
            return self::blocked('keyframe_source_changed', "Keyframe của shot {$shotId} được làm từ nguồn khác bản kế hoạch đã duyệt.");
        }

        $prompt = MotoShotPrompt::keyframe($row, $source['context']);

        if (($pinned['surface_rule'] ?? null) !== $prompt['surface_rule']) {
            return self::blocked('keyframe_old_surface_rule', "Keyframe của shot {$shotId} làm theo luật bề mặt cũ.");
        }

        if (($pinned['version'] ?? null) !== $prompt['version'] || ($pinned['sha256'] ?? null) !== $prompt['sha256']) {
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

        return [
            'keyframe' => [
                'image_id' => (string) $image->id,
                'artifact_id' => (string) $artifact->id,
                'sha256' => (string) $artifact->sha256,
                'source_token' => ['stage_id' => (string) $token['stage_id'], 'content_hash' => (string) $token['content_hash']],
                'prompt' => ['version' => (string) $pinned['version'], 'surface_rule' => (string) $pinned['surface_rule'], 'sha256' => (string) $pinned['sha256']],
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
