<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Models\VideoRender;
use App\Repositories\Interfaces\VideoRenderRepositoryInterface;
use App\Video\Media\FfmpegRunner;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The frames of a clip a person looks at before approving it (spec §13): JPEG stills cut from the very file of a render at the marks of its pinned shot — its opening, the end of each action step, the end of each camera segment, and the last real frame before the end of the part the film keeps — each with its time and the requirement of the shot at that mark, word for word, with the field it comes from. The stills are cached on the disk of the clip files by the hash of the video, the part kept, the marks and the version of this processor; a cached strip runs no FFmpeg. It never judges a picture: it only shows what the file holds at those times.
 */
final class MotoClipReviewFramesService
{
    public const VERSION = 'moto-review-frames-v1';

    /** @var string the folder of the stills on the disk of the clip files */
    public const FOLDER = 'video-review-frames';

    /** @var int the longest one FFmpeg run of a strip may take */
    private const SECONDS = 60;

    public function __construct(
        private readonly FfmpegRunner $ffmpeg,
        private readonly FilesystemFactory $storage,
        private readonly ShotSourceService $shots,
        private readonly VideoRenderRepositoryInterface $renders,
    ) {}

    /**
     * @return array{version: string, render_id: string, shot_id: string, video_sha256: string, used: array{start_ms: int, duration_ms: int}, key: string, cached: bool, disk: string, stills: list<array<string, mixed>>} the strip of a render of this project: frames() of its file at the marks of the shot its request pinned, read under the shot plan that request pinned
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException when the render is not a clip of this project
     * @throws RuntimeException when the render has no checked file, its request pins no shot plan this project still holds, or its file changed
     */
    public function strip(string $projectId, string $renderId): array
    {
        $render = $this->renders->findOwnedRender($projectId, $renderId);
        $moto = (array) (json_decode((string) $render->render_request_json, true)['moto_clip'] ?? []);
        $source = (array) ($moto['source'] ?? []);
        $stage = $this->shots->shotStage($projectId, (string) ($source['planning_stage_id'] ?? ''));

        if ($moto === [] || $stage === null) {
            throw new RuntimeException('Lượt render này không ghim kế hoạch shot nào dự án còn giữ — không trích được ảnh duyệt.');
        }

        [$evaluation, $problem] = $this->shots->inspect($projectId, $stage);

        if ($problem !== null || ! hash_equals((string) ($source['token']['content_hash'] ?? ''), (string) ($evaluation['content_hash'] ?? ''))) {
            throw new RuntimeException('Kế hoạch shot lượt render đã ghim không còn đọc được đúng nội dung đã ghim'.($problem === null ? '' : ': '.$problem).' — không trích được ảnh duyệt.');
        }

        $row = collect((array) ($evaluation['derived']['shots'] ?? []))->firstWhere('id', $moto['shot_id'] ?? null);

        if (! is_array($row)) {
            throw new RuntimeException('Shot '.($moto['shot_id'] ?? '?').' không có trong kế hoạch shot lượt render đã ghim.');
        }

        return $this->frames($render, $row, (array) ($moto['used'] ?? []));
    }

    /**
     * @param  VideoRender  $render  a render that succeeded with a checked file
     * @param  array<string, mixed>  $row  the shot as the request of the render pinned it
     * @param  array<string, mixed>  $used  the part of the file the film keeps, {start_ms, duration_ms}
     * @return array{version: string, render_id: string, shot_id: string, video_sha256: string, used: array{start_ms: int, duration_ms: int}, key: string, cached: bool, disk: string, stills: list<array{index: int, frame: int, at_ms: int, file_ms: int, seek_ms: int, marks: list<int>, path: string, sha256: string, requirements: list<array{mark_ms: int, kind: string, source: string, id: ?string, field: string, text: string}>}>} the stills in time order: index (what still() takes), frame (its number in the file), at_ms (its time from the start of the part kept), file_ms and seek_ms (its time in the file and a time inside it for a player to seek to), the marks it stands for, its path on the disk, its hash, and the requirements of those marks
     *
     * @throws RuntimeException when the render has no checked file, the file is gone or changed, or FFmpeg cannot read it
     */
    public function frames(VideoRender $render, array $row, array $used): array
    {
        $video = (string) $render->primary_artifact_hash;
        $path = (string) $render->artifact_path;
        $span = ['start_ms' => (int) ($used['start_ms'] ?? 0), 'duration_ms' => (int) ($used['duration_ms'] ?? 0)];

        if ($render->execution_status !== RenderStatus::SUCCEEDED || $path === '' || preg_match('/^[a-f0-9]{64}$/', $video) !== 1 || $span['start_ms'] < 0 || $span['duration_ms'] < 1) {
            throw new RuntimeException('Lượt render này chưa có file clip đã kiểm hoặc không ghim phần phim giữ lại — không trích được ảnh duyệt.');
        }

        $diskName = (string) config('video.veo.disk');
        $disk = $this->storage->disk($diskName);

        if (! $disk->exists($path) || ! hash_equals($video, $this->hashOf($disk, $path))) {
            throw new RuntimeException('File clip của lượt render này không còn hoặc khác hash đã ghi — không trích được ảnh duyệt.');
        }

        $marks = self::marks($row, $span);
        $key = self::key($video, $span, array_column($marks, 'mark_ms'));
        $folder = self::FOLDER.'/'.$key;
        $manifest = $this->cached($disk, $folder, $key, $video);
        $cached = $manifest !== null;
        $manifest ??= $this->extract($disk, $path, $folder, $key, $video, $span, $marks);
        $requirements = [];

        foreach ($marks as $mark) {
            $requirements[$mark['mark_ms']] = $mark['requirements'];
        }

        return [
            'version' => self::VERSION,
            'render_id' => (string) $render->id,
            'shot_id' => (string) ($row['id'] ?? ''),
            'video_sha256' => $video,
            'used' => $span,
            'key' => $key,
            'cached' => $cached,
            'disk' => $diskName,
            'stills' => array_map(static fn (array $still): array => $still + ['requirements' => array_merge([], ...array_map(static fn (int $ms): array => $requirements[$ms] ?? [], $still['marks']))], $manifest['stills']),
        ];
    }

    /**
     * @return array{disk: string, path: string, sha256: string, mime: string} where one still of a strip of a render of this project is, for a controller to stream: the strip must be of the file of that very render and the still must still be the bytes the strip recorded
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException when the render is not a clip of this project
     * @throws RuntimeException when the key is not one, the strip is not of the file of the render, or the still is gone or changed
     */
    public function still(string $projectId, string $renderId, string $key, int $index): array
    {
        $render = $this->renders->findOwnedRender($projectId, $renderId);

        if (preg_match('/^[a-f0-9]{64}$/', $key) !== 1) {
            throw new RuntimeException('Mã dải ảnh không hợp lệ.');
        }

        $diskName = (string) config('video.veo.disk');
        $disk = $this->storage->disk($diskName);
        $manifest = $this->cached($disk, self::FOLDER.'/'.$key, $key, (string) $render->primary_artifact_hash);
        $still = collect((array) ($manifest['stills'] ?? []))->firstWhere('index', $index);

        if ($manifest === null || ! is_array($still)) {
            throw new RuntimeException('Ảnh này không thuộc dải ảnh của file clip lượt render này.');
        }

        return ['disk' => $diskName, 'path' => (string) $still['path'], 'sha256' => (string) $still['sha256'], 'mime' => 'image/jpeg'];
    }

    /**
     * @return array{version: string, key: string, video_sha256: string, used: array<string, mixed>, stills: list<array{index: int, frame: int, at_ms: int, file_ms: int, marks: list<int>, sha256: string}>} the strip of this key as a decision records it: the stills a page showed beside the player, read from the cached strip with no FFmpeg run; it says what was offered to look at, never that each frame was looked at
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException when the render is not a clip of this project
     * @throws RuntimeException when the key is not one or no cached strip of that key is of the file of the render
     */
    public function shown(string $projectId, string $renderId, string $key): array
    {
        $render = $this->renders->findOwnedRender($projectId, $renderId);

        if (preg_match('/^[a-f0-9]{64}$/', $key) !== 1) {
            throw new RuntimeException('Mã dải ảnh duyệt không hợp lệ — tải lại trang.');
        }

        $manifest = $this->cached($this->storage->disk((string) config('video.veo.disk')), self::FOLDER.'/'.$key, $key, (string) $render->primary_artifact_hash);

        if ($manifest === null) {
            throw new RuntimeException('Dải ảnh duyệt đã xem không còn hoặc không thuộc file clip của lượt render này — tải lại trang trước khi duyệt.');
        }

        return [
            'version' => self::VERSION,
            'key' => $key,
            'video_sha256' => (string) $manifest['video_sha256'],
            'used' => (array) $manifest['used'],
            'stills' => array_map(static fn (array $still): array => ['index' => (int) $still['index'], 'frame' => (int) $still['frame'], 'at_ms' => (int) $still['at_ms'], 'file_ms' => (int) $still['file_ms'], 'marks' => array_map('intval', (array) $still['marks']), 'sha256' => (string) $still['sha256']], array_values($manifest['stills'])),
        ];
    }

    /**
     * @param  array<string, mixed>  $row  the shot as a request pinned it
     * @param  array{start_ms: int, duration_ms: int}  $used  the part of the file the film keeps
     * @return list<array{mark_ms: int, requirements: list<array{mark_ms: int, kind: string, source: string, id: ?string, field: string, text: string}>}> the marks of the shot from the start of the part kept, in time order with equal marks merged: 0 with first_frame, the end of every action step with its result and its action, the end of every camera segment with its description, and the end of the part kept with end_frame; a mark past the part kept stands at its end. Every text is the field of the shot word for word; a field that holds no text adds no requirement and keeps its mark
     */
    public static function marks(array $row, array $used): array
    {
        $length = (int) $used['duration_ms'];
        $marks = [0 => [], $length => []];
        $add = static function (mixed $ms, string $kind, string $source, ?string $id, string $field, mixed $text) use (&$marks, $length): void {
            $at = max(0, min(is_int($ms) ? $ms : $length, $length));
            $marks[$at] ??= [];

            if (is_string($text) && trim($text) !== '') {
                $marks[$at][] = ['mark_ms' => $at, 'kind' => $kind, 'source' => $source, 'id' => $id, 'field' => $field, 'text' => $text];
            }
        };

        $add(0, 'first_frame', 'first_frame', null, 'first_frame', $row['first_frame'] ?? null);

        foreach (array_filter((array) ($row['action_timeline'] ?? []), 'is_array') as $index => $step) {
            $id = is_string($step['id'] ?? null) ? $step['id'] : (string) $index;
            $add($step['end_ms'] ?? null, 'action_step', 'action_timeline.'.$id.'.result', $id, 'result', $step['result'] ?? null);
            $add($step['end_ms'] ?? null, 'action_step', 'action_timeline.'.$id.'.action', $id, 'action', $step['action'] ?? null);
        }

        foreach (array_filter((array) ($row['camera']['path'] ?? []), 'is_array') as $index => $segment) {
            $id = is_string($segment['id'] ?? null) ? $segment['id'] : (string) $index;
            $add($segment['end_ms'] ?? null, 'camera_segment', 'camera.path.'.$id.'.description', $id, 'description', $segment['description'] ?? null);
        }

        $add($length, 'end_frame', 'end_frame', null, 'end_frame', $row['end_frame'] ?? null);
        ksort($marks);

        return array_values(array_map(static fn (int $ms, array $requirements): array => ['mark_ms' => $ms, 'requirements' => $requirements], array_keys($marks), $marks));
    }

    /**
     * @param  list<array{n: int, pts: int}>  $frames  the frames of the file in the order FFmpeg decodes them
     * @param  array{0: int, 1: int}  $timeBase  their time base as num/den seconds
     * @param  array{start_ms: int, duration_ms: int}  $used
     * @param  list<int>  $marks  the marks from the start of the part kept
     * @return array<int, int> for each mark the frame it is shown by: at 0 the first frame of the part kept, else the last frame strictly before the mark — at the end of the part kept, the last real frame before that end — never a frame past the part kept or the file
     */
    public static function pick(array $frames, array $timeBase, array $used, array $marks): array
    {
        [$num, $den] = $timeBase;
        $at = static fn (array $frame): int => $frame['pts'] * $num * 1000;
        $start = $used['start_ms'] * $den;
        $end = ($used['start_ms'] + $used['duration_ms']) * $den;
        $picked = [];

        foreach ($marks as $mark) {
            $target = ($used['start_ms'] + $mark) * $den;

            foreach ($frames as $frame) {
                $time = $at($frame);
                $inside = $time >= $start && $time < $end;

                if ($mark === 0 && $inside) {
                    $picked[$mark] = $frame['n'];

                    break;
                }

                if ($mark > 0 && $inside && $time < $target) {
                    $picked[$mark] = $frame['n'];
                }
            }
        }

        return $picked;
    }

    /**
     * @param  array{start_ms: int, duration_ms: int}  $used
     * @param  list<int>  $marks
     * @return string the 64 hex digits that name a strip: the hash of the video, the part kept, the marks and this processor
     */
    public static function key(string $video, array $used, array $marks): string
    {
        return substr(CanonicalJson::hash(['version' => self::VERSION, 'video_sha256' => $video, 'used' => ['start_ms' => (int) $used['start_ms'], 'duration_ms' => (int) $used['duration_ms']], 'marks' => array_values(array_map('intval', $marks))]), strlen('sha256:'));
    }

    /**
     * @param  list<array{mark_ms: int, requirements: list<array<string, mixed>>}>  $marks
     * @param  array{start_ms: int, duration_ms: int}  $used
     * @return array<string, mixed> the manifest of the strip just cut and stored: two FFmpeg runs, one to read the time of every frame, one to cut the stills
     *
     * @throws RuntimeException when FFmpeg cannot read the frames of the file or cut a still
     */
    private function extract(Filesystem $disk, string $path, string $folder, string $key, string $video, array $used, array $marks): array
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'moto_frames_'.Str::random(10);
        File::ensureDirectoryExists($dir);

        try {
            $file = $dir.DIRECTORY_SEPARATOR.'clip.mp4';
            $stream = $disk->readStream($path);
            $copy = fopen($file, 'wb');

            if (! is_resource($stream) || $copy === false) {
                throw new RuntimeException('Không đọc được file clip để trích ảnh duyệt.');
            }

            stream_copy_to_stream($stream, $copy);
            fclose($stream);
            fclose($copy);
            $listed = $this->ffmpeg->run(['-hide_banner', '-nostdin', '-v', 'info', '-i', $file, '-map', '0:v:0', '-vf', 'showinfo', '-f', 'null', '-'], self::SECONDS);
            [$frames, $timeBase] = self::frameList($listed->stderr);

            if (! $listed->successful || $frames === []) {
                throw new RuntimeException('ffmpeg không đọc được các khung của file clip: '.$listed->tail());
            }

            $picked = self::pick($frames, $timeBase, $used, array_column($marks, 'mark_ms'));
            $byFrame = [];

            foreach ($picked as $mark => $frame) {
                $byFrame[$frame][] = $mark;
            }

            ksort($byFrame);
            $numbers = array_keys($byFrame);
            $graph = '[0:v:0]split='.count($numbers).implode('', array_map(static fn (int $index): string => '[s'.$index.']', array_keys($numbers))).';'
                .implode(';', array_map(static fn (int $index, int $frame): string => "[s{$index}]select='eq(n,{$frame})'[o{$index}]", array_keys($numbers), $numbers));
            $outputs = array_merge([], ...array_map(static fn (int $index): array => ['-map', '[o'.$index.']', '-frames:v', '1', '-q:v', '2', '-update', '1', $dir.DIRECTORY_SEPARATOR.sprintf('still_%02d.jpg', $index)], array_keys($numbers)));
            $cut = $this->ffmpeg->run(['-v', 'error', '-nostdin', '-y', '-i', $file, '-filter_complex', $graph, ...$outputs], self::SECONDS);
            $indexed = array_column($frames, null, 'n');
            [$num, $den] = $timeBase;
            $stills = [];

            foreach ($numbers as $index => $frame) {
                $local = $dir.DIRECTORY_SEPARATOR.sprintf('still_%02d.jpg', $index);

                if (! $cut->successful || ! is_file($local) || (int) filesize($local) === 0) {
                    throw new RuntimeException('ffmpeg không cắt được ảnh duyệt ở khung '.$frame.': '.$cut->tail());
                }

                $pts = (int) $indexed[$frame]['pts'];
                $duration = (int) ($indexed[$frame]['duration'] ?? 0);
                $stored = $folder.'/'.sprintf('still_%02d.jpg', $index);
                $bytes = (string) file_get_contents($local);

                if (! $disk->put($stored, $bytes)) {
                    throw new RuntimeException('Không lưu được ảnh duyệt lên đĩa.');
                }

                $fileMs = intdiv($pts * $num * 1000, $den);
                $stills[] = [
                    'index' => $index,
                    'frame' => $frame,
                    'at_ms' => $fileMs - $used['start_ms'],
                    'file_ms' => $fileMs,
                    'seek_ms' => intdiv((2 * $pts + max(0, $duration)) * $num * 1000, 2 * $den),
                    'marks' => $byFrame[$frame],
                    'path' => $stored,
                    'sha256' => hash('sha256', $bytes),
                ];
            }

            $manifest = ['version' => self::VERSION, 'key' => $key, 'video_sha256' => $video, 'used' => $used, 'marks' => array_column($marks, 'mark_ms'), 'time_base' => $timeBase, 'frames' => count($frames), 'stills' => $stills];

            if (! $disk->put($folder.'/manifest.json', (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Không lưu được dải ảnh duyệt lên đĩa.');
            }

            return $manifest;
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * @return array{0: list<array{n: int, pts: int, duration: ?int}>, 1: array{0: int, 1: int}} the frames the showinfo filter printed, in order, and their time base; no frames when the output holds none or no time base
     */
    public static function frameList(string $stderr): array
    {
        if (preg_match('#config in time_base:\s*(\d+)/(\d+)#', $stderr, $base) !== 1 || (int) $base[1] < 1 || (int) $base[2] < 1) {
            return [[], [1, 1]];
        }

        preg_match_all('/\bn:\s*(\d+)\s+pts:\s*(-?\d+)\s+pts_time:\S+(?:\s+duration:\s*(\d+))?/', $stderr, $found, PREG_SET_ORDER);
        $frames = array_map(static fn (array $match): array => ['n' => (int) $match[1], 'pts' => (int) $match[2], 'duration' => isset($match[3]) && $match[3] !== '' ? (int) $match[3] : null], $found);

        foreach ($frames as $index => $frame) {
            if ($frame['n'] !== $index || ($index > 0 && $frame['pts'] <= $frames[$index - 1]['pts'])) {
                return [[], [1, 1]];
            }
        }

        return [$frames, [(int) $base[1], (int) $base[2]]];
    }

    /** @return ?array<string, mixed> the manifest of the strip of this key when it is of this video and every still it lists is still on the disk with its bytes; null when the strip must be cut */
    private function cached(Filesystem $disk, string $folder, string $key, string $video): ?array
    {
        $manifest = $disk->exists($folder.'/manifest.json') ? json_decode((string) $disk->get($folder.'/manifest.json'), true) : null;

        if (! is_array($manifest) || ($manifest['version'] ?? null) !== self::VERSION || ($manifest['key'] ?? null) !== $key || ! hash_equals($video, (string) ($manifest['video_sha256'] ?? '')) || ! is_array($manifest['stills'] ?? null) || $manifest['stills'] === []) {
            return null;
        }

        foreach ($manifest['stills'] as $still) {
            $path = (string) ($still['path'] ?? '');

            if (! str_starts_with($path, $folder.'/') || ! $disk->exists($path) || ! hash_equals((string) ($still['sha256'] ?? ''), hash('sha256', (string) $disk->get($path)))) {
                return null;
            }
        }

        return $manifest;
    }

    private function hashOf(Filesystem $disk, string $path): string
    {
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            return '';
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }
}
