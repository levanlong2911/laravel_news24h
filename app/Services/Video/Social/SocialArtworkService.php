<?php

declare(strict_types=1);

namespace App\Services\Video\Social;

use App\Models\VideoArtifact;
use App\Models\VideoSocialEvent;
use App\Models\VideoSocialPackage;
use App\Video\Media\FfmpegRunner;
use App\Video\Moto\Film\MotoFilmFont;
use App\Video\Social\SocialArtwork;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The cover of a package: a frame of the approved film, or a picture the film's sources list, cut to the shape the
 * format asks for and lettered by code. The file is a row of video_artifacts with the recipe it was made by — the hash
 * of its source, the time or the image, the crop, the words, the font and the tool — and a package revision that
 * shows it keeps that row from being deleted.
 *
 * ffmpeg runs outside every transaction. A cover made while the package moved on is kept and offered, never attached
 * to a revision it was not made for. Whether a platform lets a cover be set through its API is another question,
 * answered by the platform policy when a posting is prepared.
 */
final class SocialArtworkService
{
    public const ROLE = 'social_cover';

    public function __construct(
        private readonly SocialSourceService $sources,
        private readonly SocialPackageService $packages,
        private readonly FfmpegRunner $ffmpeg,
    ) {}

    /**
     * A frame of the approved film at a time, small, for a person to look at before choosing. Nothing is stored.
     *
     * @return string the path of a JPEG the caller sends and deletes
     *
     * @throws RuntimeException when the time is outside the film, the film is no longer the one the package is about, or ffmpeg cannot read it
     */
    public function frame(string $projectId, string $packageId, int $atMs): string
    {
        $package = $this->packages->owned($projectId, $packageId);
        [$path, $media] = $this->film($projectId, $package);
        $frame = self::frameOf($atMs, $media);
        $directory = $this->workspace('frames');

        // A frame a broken connection never got to delete is swept on a later request.
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.jpg') ?: [] as $stale) {
            if ((int) @filemtime($stale) < time() - 3600) {
                @unlink($stale);
            }
        }

        $output = $directory.DIRECTORY_SEPARATOR.Str::uuid().'.jpg';
        $seek = number_format($frame === 0 ? 0 : ($frame - 0.5) / (float) $media['fps'], 6, '.', '');
        $run = $this->ffmpeg->run(['-hide_banner', '-nostdin', '-v', 'error', '-ss', $seek, '-i', $path, '-frames:v', '1', '-vf', 'scale=-2:640:out_range=pc,format=yuvj420p', '-q:v', '4', '-y', $output], 60);

        if (! $run->successful || ! is_file($output)) {
            throw new RuntimeException('Không trích được frame: '.Str::limit($run->tail(300), 300));
        }

        return $output;
    }

    /**
     * Makes a cover and puts it on a new revision of the package.
     *
     * @param  array<string, mixed>  $input  `at_ms` for a frame of the film or `asset_id` for a picture of the sources, then focus_x, focus_y, zoom, text, position, size
     * @return array{0: ?VideoSocialPackage, 1: string, 2: VideoArtifact} the new revision and `attached`, or null with why the cover was made and not attached; the cover either way
     *
     * @throws RuntimeException when the cover cannot be made as chosen; no file is kept
     */
    public function cover(string $projectId, string $packageId, string $expectedHash, array $input, ?string $actorId): array
    {
        $package = $this->packages->owned($projectId, $packageId);

        if (! hash_equals((string) $package->package_hash, $expectedHash)) {
            throw new RuntimeException('Gói trên trang không phải bản này — tải lại trang.');
        }

        $rules = (array) $package->source_json['policy']['rules']['cover'];
        [$file, $source, $picture, $fps] = $this->picture($projectId, $package, $input);
        $pin = (array) config('video.social.artwork.font');
        $text = trim((string) ($input['text'] ?? ''));
        $fontPath = base_path((string) $pin['path']);
        $font = $text === '' ? null : MotoFilmFont::open($fontPath, (string) $pin['sha256']);
        $recipe = SocialArtwork::recipe($source, $picture, $rules, $input, $font, ['family' => (string) $pin['family'], 'sha256' => (string) $pin['sha256']], (int) config('video.social.artwork.jpeg_quality'));
        $directory = $this->workspace();
        $output = $directory.DIRECTORY_SEPARATOR.'cover.jpg';
        $lines = [];

        foreach ((array) ($recipe['text']['lines'] ?? []) as $index => $line) {
            $lines[$index] = $directory.DIRECTORY_SEPARATOR."line-{$index}.txt";
            file_put_contents($lines[$index], $line['text']);
        }

        try {
            $run = $this->ffmpeg->run(SocialArtwork::arguments($recipe, $file, $output, $fontPath, $lines, (int) round($fps)), 120);
            $size = is_file($output) ? @getimagesize($output) : false;

            if (! $run->successful || $size === false) {
                throw new RuntimeException('ffmpeg không dựng được cover: '.Str::limit($run->tail(300), 300));
            }

            if ($size[0] !== $rules['width'] || $size[1] !== $rules['height']) {
                throw new RuntimeException("Cover ra {$size[0]}×{$size[1]}, chính sách cần {$rules['width']}×{$rules['height']}.");
            }

            if (is_int($rules['max_bytes']) && filesize($output) > $rules['max_bytes']) {
                throw new RuntimeException('Cover nặng '.filesize($output)." byte, vượt {$rules['max_bytes']} byte của định dạng.");
            }

            $artifact = $this->keep($projectId, $package, $output, $recipe, $actorId);
        } finally {
            foreach ([...$lines, $output] as $temporary) {
                @unlink($temporary);
            }

            @rmdir($directory);
        }

        try {
            [$revision] = $this->packages->withArtwork($projectId, $packageId, $expectedHash, self::record($artifact), $actorId);
        } catch (RuntimeException $e) {
            // The package moved on, or the film it is about is no longer the approved one: the cover is kept to look at, and no revision carries it.
            VideoSocialEvent::record($projectId, 'cover_not_applied', (string) $package->id, null, $actorId, ['artifact_id' => (string) $artifact->id, 'reason' => $e->getMessage()]);

            return [null, 'Cover đã dựng và lưu để xem lại, nhưng chưa gắn vào gói: '.$e->getMessage(), $artifact];
        }

        return [$revision, 'attached', $artifact];
    }

    /**
     * Puts a cover made earlier for this package on a new revision of it.
     *
     * @return array{0: VideoSocialPackage, 1: string}
     *
     * @throws RuntimeException when the cover is not one of this package, was cut from another film than the one the package is about, or its file is gone or changed
     */
    public function select(string $projectId, string $packageId, string $expectedHash, string $artifactId, ?string $actorId): array
    {
        $package = $this->packages->owned($projectId, $packageId);
        $artifact = $this->covers($package)->firstWhere('id', $artifactId) ?? throw new RuntimeException('Cover này không thuộc gói.');
        $source = (array) $artifact->metadata_json['recipe']['source'];

        if ($source['kind'] === 'film_frame' && ! hash_equals((string) $package->source_json['pins']['artifact_sha256'], (string) $source['sha256'])) {
            throw new RuntimeException('Cover này cắt từ bản phim khác với phim của gói — dựng cover mới từ phim hiện tại.');
        }

        if (self::problem($artifact) !== null) {
            throw new RuntimeException('File cover không còn hoặc đã đổi nội dung — dựng lại cover.');
        }

        return $this->packages->withArtwork($projectId, $packageId, $expectedHash, self::record($artifact), $actorId);
    }

    /** @return Collection<int, VideoArtifact> the covers made for the package, newest first */
    public function covers(VideoSocialPackage $package): Collection
    {
        return VideoArtifact::query()
            ->where('project_id', $package->project_id)
            ->where('role', self::ROLE)
            ->where('metadata_json->package_group_id', (string) $package->package_group_id)
            ->orderByDesc('created_at')
            ->limit(24)
            ->get();
    }

    /**
     * @return VideoArtifact a cover of this project
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function owned(string $projectId, string $artifactId): VideoArtifact
    {
        return VideoArtifact::query()->whereKey($artifactId)->where('project_id', $projectId)->where('role', self::ROLE)->firstOrFail();
    }

    /** @return ?string the absolute path of the file of a cover, null when it is not on its disk */
    public static function path(VideoArtifact $artifact): ?string
    {
        $disk = Storage::disk((string) $artifact->storage_disk);

        return $disk->exists((string) $artifact->storage_path) ? $disk->path((string) $artifact->storage_path) : null;
    }

    /** @return ?string why the file of a cover cannot be used: it is gone, or its bytes are not the ones recorded; null when it is the file that was made */
    public static function problem(VideoArtifact $artifact): ?string
    {
        $path = self::path($artifact);

        return match (true) {
            $path === null => 'file cover không còn trên đĩa',
            ! hash_equals((string) $artifact->sha256, (string) hash_file('sha256', $path)) => 'file cover đã đổi nội dung',
            default => null,
        };
    }

    /** @return array<string, mixed> a cover as a package revision carries it */
    public static function record(VideoArtifact $artifact): array
    {
        return [
            'artifact_id' => (string) $artifact->id,
            'sha256' => (string) $artifact->sha256,
            'width' => (int) $artifact->width,
            'height' => (int) $artifact->height,
            'recipe_hash' => (string) $artifact->metadata_json['recipe_hash'],
            'recipe' => (array) $artifact->metadata_json['recipe'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: string, 1: array<string, mixed>, 2: array{width: int, height: int}, 3: float} the file the cover is cut from, what the recipe says of it, its size, and the frame rate of a film
     */
    private function picture(string $projectId, VideoSocialPackage $package, array $input): array
    {
        $asset = is_string($input['asset_id'] ?? null) && $input['asset_id'] !== '' ? collect((array) $package->source_json['assets'])->firstWhere('id', $input['asset_id']) : null;

        if (is_string($input['asset_id'] ?? null) && $input['asset_id'] !== '' && $asset === null) {
            throw new RuntimeException('Ảnh nguồn này không nằm trong danh sách tài sản của gói.');
        }

        if ($asset === null || $asset['kind'] === 'film_frame') {
            [$path, $media] = $this->film($projectId, $package);
            $at = $asset === null ? $input['at_ms'] ?? null : $asset['at_ms'];

            if (! is_numeric($at)) {
                throw new RuntimeException('Chọn thời điểm của frame hoặc một ảnh nguồn.');
            }

            return [
                $path,
                ['kind' => 'film_frame', 'sha256' => (string) $package->source_json['pins']['artifact_sha256'], 'at_ms' => (int) $at, 'frame' => self::frameOf((int) $at, $media)],
                ['width' => (int) $media['width'], 'height' => (int) $media['height']],
                (float) $media['fps'],
            ];
        }

        $artifact = VideoArtifact::query()->whereKey($asset['artifact_id'])->where('project_id', $projectId)->where('artifact_type', 'image')->first();
        $path = $artifact === null ? null : self::path($artifact);
        $size = $path === null ? false : @getimagesize($path);

        if ($path === null || $size === false || ! hash_equals((string) $asset['sha256'], (string) hash_file('sha256', $path))) {
            throw new RuntimeException('Ảnh nguồn '.$asset['id'].' không còn hoặc không còn đúng file mà nguồn của gói đã ghi.');
        }

        return [$path, ['kind' => (string) $asset['kind'], 'asset_id' => (string) $asset['id'], 'artifact_id' => (string) $asset['artifact_id'], 'sha256' => (string) $asset['sha256']], ['width' => (int) $size[0], 'height' => (int) $size[1]], 0.0];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>} the file of the film the package is about and what was measured on it
     *
     * @throws RuntimeException when the approved film of the project is no longer that film
     */
    private function film(string $projectId, VideoSocialPackage $package): array
    {
        $read = $this->sources->read($projectId);

        if (! hash_equals((string) $package->source_hash, (string) $read['snapshot']['hash'])) {
            throw new RuntimeException('Phim đã duyệt của dự án không còn là phim gói này viết cho — chuyển gói sang phim hiện hành trước khi làm cover.');
        }

        $media = (array) $read['snapshot']['film']['media'];

        if (! is_numeric($media['fps'] ?? null) || (float) $media['fps'] <= 0) {
            throw new RuntimeException('Không đọc được tốc độ khung hình của phim — không xác định được frame.');
        }

        return [$read['path'], $media];
    }

    /**
     * @param  array<string, mixed>  $media
     *
     * @throws RuntimeException for a time outside the film
     */
    private static function frameOf(int $atMs, array $media): int
    {
        if ($atMs < 0 || $atMs >= (int) $media['duration_ms']) {
            throw new RuntimeException('Thời điểm '.$atMs.' ms nằm ngoài phim (0–'.((int) $media['duration_ms'] - 1).' ms).');
        }

        return SocialArtwork::frameAt($atMs, (float) $media['fps']);
    }

    /**
     * @param  array<string, mixed>  $recipe
     * @return VideoArtifact the cover on its disk, with its hash and the recipe it was made by
     */
    private function keep(string $projectId, VideoSocialPackage $package, string $file, array $recipe, ?string $actorId): VideoArtifact
    {
        $disk = (string) config('video.social.artwork.disk');
        $path = 'social/'.$projectId.'/'.$package->package_group_id.'/'.Str::uuid().'.jpg';
        $bytes = (string) file_get_contents($file);
        $size = getimagesize($file);

        if (! Storage::disk($disk)->put($path, $bytes)) {
            throw new RuntimeException('Không ghi được file cover vào kho artifact.');
        }

        return VideoArtifact::create([
            'project_id' => $projectId,
            'artifact_type' => 'image',
            'role' => self::ROLE,
            'storage_disk' => $disk,
            'storage_path' => $path,
            'mime_type' => 'image/jpeg',
            'file_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'width' => (int) $size[0],
            'height' => (int) $size[1],
            'metadata_json' => [
                'package_group_id' => (string) $package->package_group_id,
                'made_for_revision_id' => (string) $package->id,
                'recipe' => $recipe,
                'recipe_hash' => SocialArtwork::hash($recipe),
                'made_by' => $actorId,
            ],
        ]);
    }

    /** @return string a folder for one run, or the named folder several runs share */
    private function workspace(?string $shared = null): string
    {
        $directory = rtrim((string) config('video.veo.compose_tmp_dir'), '/\\').DIRECTORY_SEPARATOR.'social-artwork'.DIRECTORY_SEPARATOR.($shared ?? Str::uuid());

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Không tạo được thư mục tạm để dựng ảnh.');
        }

        return $directory;
    }
}
