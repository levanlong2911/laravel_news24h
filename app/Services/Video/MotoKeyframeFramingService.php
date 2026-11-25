<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Models\VideoReviewDecision;
use App\Video\Moto\MotoPipelineRelease;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class MotoKeyframeFramingService
{
    public const VERSION = 'moto-keyframe-framing-v1';

    /** @var string how a delivery image is made from its master: one rectangle cut with GD and resampled to the delivery size */
    public const PROCESSING = 'gd-crop-resample-v1';

    /** @var string the role of the artifact handed to the video: the window cut from a master, never a render of its own */
    public const DELIVERY_ROLE = 'keyframe_delivery';

    /**
     * @param  string  $filmAspect  the frame of the film, as the approved shot plan pinned it
     * @return ?array{version: string, master_aspect_ratio: string, delivery_aspect_ratio: string, delivery_width: int, delivery_height: int, unit: array{0: int, 1: int}, k_min: int, master_sizes: array<string, list<string>>} the framing the release of the project declares for its keyframes — the frame the image model renders, the frame and size handed to the video, the unit of a crop rectangle and its smallest multiple — or null for a release that renders a keyframe at the frame of the film
     *
     * @throws RuntimeException when the release declares a framing this code cannot apply, or one that hands the video another frame than the film has
     */
    public static function policy(VideoProject $project, string $filmAspect): ?array
    {
        $declared = MotoPipelineRelease::policy($project, 'keyframe')['framing'] ?? null;

        if (! is_array($declared)) {
            return null;
        }

        $master = self::ratio($declared['master_aspect_ratio'] ?? null);
        $unit = self::ratio($declared['delivery_aspect_ratio'] ?? null);
        $size = preg_match('/^([1-9][0-9]*)x([1-9][0-9]*)$/', (string) ($declared['delivery_size'] ?? ''), $found) === 1 ? [(int) $found[1], (int) $found[2]] : null;

        if (($declared['version'] ?? null) !== self::VERSION || $master === null || $unit === null || $size === null
            || $size[0] % $unit[0] !== 0 || intdiv($size[0], $unit[0]) * $unit[1] !== $size[1]) {
            throw new RuntimeException('Chính sách khung keyframe của bản phát hành '.MotoPipelineRelease::of($project).' không đọc được: cần version '.self::VERSION.', hai tỷ lệ và delivery_size đúng tỷ lệ bàn giao.');
        }

        if ($declared['delivery_aspect_ratio'] !== $filmAspect) {
            throw new RuntimeException("Chính sách khung keyframe bàn giao ảnh {$declared['delivery_aspect_ratio']} trong khi phim khung {$filmAspect} — không render.");
        }

        return [
            'version' => self::VERSION,
            'master_aspect_ratio' => (string) $declared['master_aspect_ratio'],
            'delivery_aspect_ratio' => (string) $declared['delivery_aspect_ratio'],
            'delivery_width' => $size[0],
            'delivery_height' => $size[1],
            'unit' => $unit,
            'k_min' => intdiv($size[0], $unit[0]),
            'master_sizes' => array_map(static fn (mixed $sizes): array => array_values(array_map('strval', (array) $sizes)), (array) ($declared['master_sizes'] ?? [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $framing  policy()
     * @return ?array{x: int, y: int, width: int, height: int} the largest rectangle of the delivery frame the master holds, centred, in pixels of the master; null when the master is too small to give the delivery size without enlarging
     */
    public static function defaultRectangle(int $width, int $height, array $framing): ?array
    {
        [$across, $down] = $framing['unit'];
        $k = min(intdiv($width, $across), intdiv($height, $down));

        if ($k < $framing['k_min']) {
            return null;
        }

        return ['x' => intdiv($width - $across * $k, 2), 'y' => intdiv($height - $down * $k, 2), 'width' => $across * $k, 'height' => $down * $k];
    }

    /**
     * @param  array<string, mixed>  $rectangle  x, y, width and height in pixels of the master
     * @param  array<string, mixed>  $framing  policy()
     * @return ?string why the rectangle cannot be cut: a value that is no whole number, a size that is not the same whole multiple of the delivery unit, a multiple under the smallest one, or an edge outside the master; null when it can
     */
    public static function rectangleProblem(array $rectangle, int $width, int $height, array $framing): ?string
    {
        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (! is_int($rectangle[$key] ?? null) || $rectangle[$key] < 0) {
                return "Vùng khung thiếu {$key} là số nguyên không âm.";
            }
        }

        [$across, $down] = $framing['unit'];
        $k = intdiv($rectangle['width'], $across);

        if ($rectangle['width'] === 0 || $rectangle['width'] % $across !== 0 || $rectangle['height'] !== $down * $k) {
            return "Vùng khung {$rectangle['width']}×{$rectangle['height']} không đúng tỷ lệ {$framing['delivery_aspect_ratio']} theo pixel ảnh gốc.";
        }

        if ($k < $framing['k_min']) {
            return "Vùng khung {$rectangle['width']}×{$rectangle['height']} nhỏ hơn {$framing['delivery_width']}×{$framing['delivery_height']} — không phóng lớn ảnh.";
        }

        if ($rectangle['x'] + $rectangle['width'] > $width || $rectangle['y'] + $rectangle['height'] > $height) {
            return "Vùng khung vượt biên ảnh gốc {$width}×{$height}.";
        }

        return null;
    }

    /**
     * @param  array{x: int, y: int, width: int, height: int}  $rectangle
     * @param  array<string, mixed>  $framing  policy()
     * @return string what names one delivery image: the bytes of its master, the rectangle, the output size and the way it is made
     */
    public static function identity(string $masterSha, array $rectangle, array $framing): string
    {
        return CanonicalJson::hash([
            'master_sha256' => $masterSha,
            'crop' => array_intersect_key($rectangle, array_flip(['x', 'y', 'width', 'height'])),
            'output' => $framing['delivery_width'].'x'.$framing['delivery_height'],
            'processing' => self::PROCESSING,
        ]);
    }

    /** @return ?VideoArtifact the file the image model rendered for the candidate: the artifact in use when it is one, else its newest; a delivery image is never it */
    public static function master(VideoDesignImage $image): ?VideoArtifact
    {
        $rendered = $image->artifacts->filter(static fn (VideoArtifact $artifact): bool => $artifact->role !== self::DELIVERY_ROLE);

        return $rendered->firstWhere('id', (string) $image->selected_artifact_id) ?? $rendered->sortByDesc('created_at')->first();
    }

    /** @return list<VideoArtifact> the delivery images cut from this master, newest first */
    public static function deliveries(VideoDesignImage $image, VideoArtifact $master): array
    {
        return $image->artifacts->filter(static fn (VideoArtifact $artifact): bool => $artifact->role === self::DELIVERY_ROLE
            && ($artifact->metadata_json['master_artifact_id'] ?? null) === (string) $master->id
            && ($artifact->metadata_json['master_sha256'] ?? null) === (string) $master->sha256)->sortByDesc('created_at')->values()->all();
    }

    /**
     * @param  array<string, mixed>  $rectangle  x, y, width and height in pixels of the master
     * @param  array<string, mixed>  $framing  policy()
     * @return VideoArtifact the delivery image of that rectangle: the one already made from the same master, rectangle, size and processing while its file still holds, otherwise a new file written first and recorded after. No model is called and the master is not written
     *
     * @throws RuntimeException when the master cannot be read as the stored image, the rectangle cannot be cut or the file cannot be written
     */
    public function apply(VideoDesignImage $image, VideoArtifact $master, array $rectangle, array $framing): VideoArtifact
    {
        $disk = Storage::disk((string) $master->storage_disk);
        $bytes = $disk->get((string) $master->storage_path);

        if (! is_string($bytes) || ! hash_equals((string) $master->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('File ảnh gốc không còn hoặc đã bị đổi — không tạo ảnh video.');
        }

        $read = @getimagesizefromstring($bytes) ?: throw new RuntimeException('File ảnh gốc không đọc được thành ảnh — không tạo ảnh video.');
        $problem = self::rectangleProblem($rectangle, (int) $read[0], (int) $read[1], $framing);

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }

        $identity = self::identity((string) $master->sha256, $rectangle, $framing);

        foreach (self::deliveries($image, $master) as $existing) {
            if (($existing->metadata_json['identity'] ?? null) === $identity && $this->problem($existing, $master, $framing, true) === null) {
                return $existing;
            }
        }

        @ini_set('memory_limit', (string) config('video.openai_image.memory_limit', '1024M'));
        $source = @imagecreatefromstring($bytes) ?: throw new RuntimeException('Không mở được ảnh gốc để cắt khung.');
        $target = imagecreatetruecolor($framing['delivery_width'], $framing['delivery_height']);

        try {
            imagecopyresampled($target, $source, 0, 0, $rectangle['x'], $rectangle['y'], $framing['delivery_width'], $framing['delivery_height'], $rectangle['width'], $rectangle['height']);
            ob_start();
            imagepng($target);
            $cut = (string) ob_get_clean();
        } finally {
            imagedestroy($source);
            imagedestroy($target);
        }

        if ($cut === '') {
            throw new RuntimeException('Không ghi được ảnh video từ vùng khung đã chọn.');
        }

        $sha = hash('sha256', $cut);
        $path = dirname((string) $master->storage_path).'/delivery/'.substr(hash('sha256', $identity), 0, 16).'-'.substr($sha, 0, 12).'.png';

        if (! $disk->put($path, $cut)) {
            throw new RuntimeException('Không lưu được file ảnh video — bộ đang dùng giữ nguyên.');
        }

        return VideoArtifact::create([
            'project_id' => $image->project_id,
            'design_image_id' => $image->id,
            'render_id' => $master->render_id,
            'artifact_type' => 'image',
            'role' => self::DELIVERY_ROLE,
            'storage_disk' => (string) $master->storage_disk,
            'storage_path' => $path,
            'mime_type' => 'image/png',
            'file_size' => strlen($cut),
            'sha256' => $sha,
            'width' => $framing['delivery_width'],
            'height' => $framing['delivery_height'],
            'metadata_json' => [
                'kind' => self::DELIVERY_ROLE,
                'identity' => $identity,
                'framing_version' => self::VERSION,
                'processing_version' => self::PROCESSING,
                'master_artifact_id' => (string) $master->id,
                'master_sha256' => (string) $master->sha256,
                'crop' => array_intersect_key($rectangle, array_flip(['x', 'y', 'width', 'height'])),
                'crop_hash' => CanonicalJson::hash(array_intersect_key($rectangle, array_flip(['x', 'y', 'width', 'height']))),
                'delivery_width' => $framing['delivery_width'],
                'delivery_height' => $framing['delivery_height'],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $framing  policy()
     * @param  bool  $deep  read and hash the file, not only see that it is there
     * @return ?string why the delivery image cannot stand for this master: it is no delivery image of it, its rectangle, size or identity no longer follow the framing, or its file is gone, changed or another size; null while it stands
     */
    public function problem(VideoArtifact $delivery, VideoArtifact $master, array $framing, bool $deep): ?string
    {
        $meta = (array) $delivery->metadata_json;
        $rectangle = (array) ($meta['crop'] ?? []);

        if ($delivery->role !== self::DELIVERY_ROLE || ($meta['master_artifact_id'] ?? null) !== (string) $master->id || ($meta['master_sha256'] ?? null) !== (string) $master->sha256
            || (string) $delivery->design_image_id !== (string) $master->design_image_id) {
            return 'Ảnh video không được cắt từ ảnh gốc này';
        }

        if ((int) $delivery->width !== $framing['delivery_width'] || (int) $delivery->height !== $framing['delivery_height']) {
            return 'Ảnh video sai kích thước: '.$delivery->width.'x'.$delivery->height.', yêu cầu '.$framing['delivery_width'].'x'.$framing['delivery_height'];
        }

        $cut = self::rectangleProblem($rectangle, (int) $master->width, (int) $master->height, $framing);

        if ($cut !== null || ($meta['identity'] ?? null) !== self::identity((string) $master->sha256, $rectangle, $framing)) {
            return $cut ?? 'Ảnh video không còn khớp vùng khung và ảnh gốc đã ghi';
        }

        $disk = Storage::disk((string) $delivery->storage_disk);

        if (! $disk->exists((string) $delivery->storage_path)) {
            return 'File ảnh video không còn';
        }

        if (! $deep) {
            return null;
        }

        $bytes = (string) $disk->get((string) $delivery->storage_path);

        if (! hash_equals((string) $delivery->sha256, hash('sha256', $bytes))) {
            return 'File ảnh video đã bị đổi';
        }

        $read = @getimagesizefromstring($bytes) ?: [];

        return ($read[0] ?? 0) === $framing['delivery_width'] && ($read[1] ?? 0) === $framing['delivery_height'] ? null : 'File ảnh video không đọc được đúng kích thước bàn giao';
    }

    /**
     * @return ?VideoReviewDecision the decision that holds the keyframe of the image now: the newest decision on its rendered file, when that decision approves it; null when the image is not approved or its newest decision revoked it
     */
    public static function approval(VideoDesignImage $image): ?VideoReviewDecision
    {
        if ($image->status !== DesignImageStatus::APPROVED->value || $image->selected_artifact_id === null) {
            return null;
        }

        $decision = VideoReviewDecision::query()->where('project_id', $image->project_id)->where('entity_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->where('entity_id', (string) $image->selected_artifact_id)->orderByDesc('revision')->first();

        return $decision !== null && $decision->decision === 'approved' ? $decision : null;
    }

    /**
     * @param  string  $sceneId  the render scene the video is made for
     * @return bool whether the artifact is the delivery image of the keyframe set approved for that scene now: the set its newest approval names, whose master is the file in use of the approved keyframe; a delivery image that was only tried, or of a set since replaced or revoked, is not
     */
    public static function inUse(VideoArtifact $source, string $sceneId): bool
    {
        if ($source->role !== self::DELIVERY_ROLE || $source->design_image_id === null) {
            return false;
        }

        $image = VideoDesignImage::query()->whereKey($source->design_image_id)->where('render_scene_id', $sceneId)
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)->first();
        $delivery = $image === null ? null : (self::approval($image)?->metadata_json['delivery'] ?? null);

        return is_array($delivery) && ($delivery['artifact_id'] ?? null) === (string) $source->id && ($delivery['sha256'] ?? null) === (string) $source->sha256
            && ($source->metadata_json['master_artifact_id'] ?? null) === (string) $image->selected_artifact_id;
    }

    /** @return ?array{0: int, 1: int} the two whole numbers of a frame written as 16:9, null when it is not written so */
    private static function ratio(mixed $aspect): ?array
    {
        return is_string($aspect) && preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/', $aspect, $found) === 1 ? [(int) $found[1], (int) $found[2]] : null;
    }
}
