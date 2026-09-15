<?php

namespace App\Video\Render\Video;

use App\Models\VideoRender;
use App\Models\VideoShot;
use App\Video\Media\Mp4Probe;
use App\Video\Media\VideoModelRegistry;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\RenderDispatchService;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use RuntimeException;

/**
 * Clip sinh ra TU khung hinh da duyet cua chinh scene do, nen no thua ca bo hash
 * cua lan render khung hinh ay. Khong dung lai bo hash nay thi hai lan render
 * cung mot scene co the khai hai canonical khac nhau.
 *
 * Anh nguon duoc dong bang ca `disk` chu khong chi `path`: `artifact_path` cua
 * duong cu co the tro ra ngoai moi disk cua Laravel, va luc do clip khong tai lap
 * duoc — phai bao ngay o day chu khong phai sau khi da tra tien.
 */
final class SceneClipDispatchService
{
    public const TASK = 'scene_clip';

    public function __construct(
        private readonly RenderDispatchService $dispatch,
        private readonly VideoModelRegistry $registry,
        private readonly FilesystemFactory $storage,
        private readonly Mp4Probe $probe,
    ) {}

    /** @param array<string, mixed> $controls */
    public function create(VideoShot $shot, string $modelId, array $controls = []): VideoRender
    {
        // Thieu ffprobe la loi cau hinh may, khong phai loi cua render: chan o day
        // thi khong o nao bi tao ra roi danh that bai vinh vien.
        if (! $this->probe->available()) {
            throw new RuntimeException('Khong chay duoc ffprobe — khong tao clip de khoi tra tien cho file khong xac minh duoc.');
        }

        $entry = $this->registry->find(self::TASK, $modelId);

        if ($entry === null) {
            throw new RuntimeException('Model clip ngoai registry: '.$modelId);
        }

        $keyframe = $shot->scene_image_render_id === null
            ? null
            : VideoRender::query()->whereKey($shot->scene_image_render_id)->first();

        if ($keyframe === null || $keyframe->execution_status !== RenderStatus::SUCCEEDED) {
            throw new RuntimeException('Scene chua co khung hinh da render thanh cong.');
        }

        $prompt = trim((string) $shot->compiled_prompt);

        if ($prompt === '') {
            throw new RuntimeException('Shot chua co compiled_prompt cho chuyen dong.');
        }

        $request = [
            'kind' => self::TASK,
            'provider' => $entry['provider'],
            'model' => $entry['model'],
            'api_version' => $entry['api_version'],
            'compiled_prompt' => ['prompt' => $prompt],
            'duration_seconds' => $this->choice($entry, $controls, 'durations', 'default_duration', 'duration_seconds'),
            'aspect_ratio' => $this->choice($entry, $controls, 'aspect_ratios', 'default_aspect_ratio', 'aspect_ratio'),
            'resolution' => $this->choice($entry, $controls, 'resolutions', 'default_resolution', 'resolution'),
            'source_render_id' => $keyframe->id,
            'source_artifact' => $this->freezeSource($keyframe),
            'motion_spec_hash' => $this->motionSpecHash($shot),
        ];

        $requestJson = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $requestHash = hash('sha256', $requestJson);

        // CHECK `video_renders_one_owner` cho dung MOT chu so huu. Chu cua clip la
        // shot; session suy ra duoc qua shot, con dat ca hai la vi pham rang buoc.
        return $this->dispatch->create(
            sessionId: null,
            assetId: (string) ($shot->scene_id ?? $shot->shot_code),
            provider: (string) $entry['provider'],
            model: (string) $entry['model'],
            idempotencyKey: 'scene-clip:'.$shot->id.':'.$requestHash,
            requestJson: $requestJson,
            requestHash: $requestHash,
            canonicalRevisionId: (string) $keyframe->canonical_concept_revision_id,
            canonicalHash: (string) $keyframe->canonical_hash,
            projectionHash: (string) $keyframe->projection_hash,
            constraintSetHash: (string) $keyframe->constraint_set_hash,
            promptSpecHash: (string) $keyframe->prompt_spec_hash,
            providerPromptPlanHash: (string) $keyframe->provider_prompt_plan_hash,
            promptHash: hash('sha256', $prompt),
            renderKind: 'video',
            shotId: $shot->id,
        );
    }

    /** @return array{disk: string, path: string, sha256: string, mime: string, bytes: int} */
    private function freezeSource(VideoRender $keyframe): array
    {
        $path = (string) $keyframe->artifact_path;
        $maxBytes = (int) config('video.veo.max_source_bytes', 33554432);

        if ($path === '') {
            throw new RuntimeException('Khung hinh nguon khong co artifact_path.');
        }

        foreach ((array) config('video.veo.source_disks', ['video_artifacts']) as $disk) {
            $filesystem = $this->storage->disk($disk);

            if (! $filesystem->exists($path)) {
                continue;
            }

            $size = (int) $filesystem->size($path);

            if ($size <= 0 || $size > $maxBytes) {
                throw new RuntimeException('Khung hinh nguon co kich thuoc khong hop le: '.$size);
            }

            $bytes = (string) $filesystem->get($path);

            if (! hash_equals((string) $keyframe->primary_artifact_hash, hash('sha256', $bytes))) {
                throw new RuntimeException('Khung hinh nguon khac hash da ghi trong render.');
            }

            $info = @getimagesizefromstring($bytes);
            $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';

            if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
                throw new RuntimeException('Khung hinh nguon khong phai PNG/JPEG: '.($mime !== '' ? $mime : 'khong doc duoc'));
            }

            return ['disk' => (string) $disk, 'path' => $path, 'sha256' => hash('sha256', $bytes),
                'mime' => $mime, 'bytes' => $size];
        }

        throw new RuntimeException('Khong thay khung hinh nguon tren disk nao da khai bao: '.$path);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $controls
     * @return mixed
     */
    private function choice(array $entry, array $controls, string $listKey, string $defaultKey, string $input)
    {
        $allowed = $entry['controls'][$listKey];
        $value = $controls[$input] ?? $entry['controls'][$defaultKey];

        if (! in_array($value, $allowed, true)) {
            throw new RuntimeException($input.' ngoai registry: '.var_export($value, true));
        }

        return $value;
    }

    private function motionSpecHash(VideoShot $shot): string
    {
        $spec = $shot->spec_json ?? [];

        return hash('sha256', (string) json_encode([
            'motion' => $spec['motion'] ?? [],
            'camera' => $spec['camera'] ?? [],
            'duration_seconds' => $spec['duration_seconds'] ?? null,
            'from_state_id' => $shot->from_state_id,
            'to_state_id' => $shot->to_state_id,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
