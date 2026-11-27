<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Jobs\Video\RenderDynamicCropJob;
use App\Models\VideoArtifact;
use App\Models\VideoRender;
use App\Models\VideoShot;
use App\Repositories\Interfaces\VideoRenderAttemptRepositoryInterface;
use App\Repositories\Interfaces\VideoRenderRepositoryInterface;
use App\Video\Media\FfmpegRunner;
use App\Video\Media\Mp4Probe;
use App\Video\Moto\MotoClipPlanBuilder;
use App\Video\Moto\MotoContract;
use App\Video\Queue\VideoQueues;
use App\Video\Render\Attempts\RenderAttemptService;
use App\Video\Render\Claims\RenderClaimService;
use App\Video\Render\Claims\RenderLeaseService;
use App\Video\Render\Enums\RenderFailureClass;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\RenderDispatchService;
use App\Video\Render\RenderFailureService;
use App\Video\Render\Video\SceneClipDispatchService;
use App\Video\Render\Video\VideoRenderExecutionService;
use App\Video\Render\VideoProviderCheckpointService;
use App\Video\Scene\Services\ShotIntentService;
use App\Video\Scene\Services\ShotSelectionReconciler;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The local processor moto-dynamic-crop-v1 (spec §10): it moves a 9:16 window over the still 16:9 master of an approved keyframe set along the crop path of its clip and writes the clip with FFmpeg on this machine. Its row lives on the shared render, job and artifact path with provider MotoClipPlanBuilder::LOCAL and never reaches a provider or the registry; its file is checked by VideoRenderExecutionService::storeCheckedFile() against the media policy its request froze.
 *
 * How a window is sampled: frame n of the clip (0 to frames − 1) shows the window at t = n·1000/fps ms, blended linearly in time between the two points of the path around t and held after the last point. The window is sampled at its exact real position and size, never rounded to whole pixels: FFmpeg crops a whole-pixel region holding it, the perspective filter maps the exact corners of the window onto that region with bicubic interpolation at 1/256 pixel, and a Lanczos scale makes the output. All of it runs in planar RGB (gbrp), which takes odd sizes such as 1215×2160; the picture becomes yuv420p only at the even output size. PHP checks every window in exact integer arithmetic (numerators over one denominator per frame): inside the master, of the exact output ratio, never smaller than the output and holding the protected region.
 */
final class MotoDynamicCropService
{
    /** @var string what the request of a dynamic crop names itself */
    public const KIND = 'moto_dynamic_crop';

    /** @var int the longest an FFmpeg run of one clip may take, well inside the 120-second lease of the render it holds */
    public const PROCESS_SECONDS = 90;

    /** @var string the failure of a local row whose worker stopped before it finished; nothing was sent anywhere, so a new row costs nothing */
    public const WORKER_LOST = 'local_worker_lost';

    private const IDEMPOTENCY_PREFIX = 'moto-dynamic-crop:';

    /** @var array{codec: string, preset: string, crf: int, profile: string, colorspace: string, range: string} how the picture is encoded */
    private const ENCODER = ['codec' => 'libx264', 'preset' => 'medium', 'crf' => 16, 'profile' => 'high', 'colorspace' => 'bt709', 'range' => 'tv'];

    public function __construct(
        private readonly RenderDispatchService $dispatch,
        private readonly ShotIntentService $intents,
        private readonly RenderClaimService $claims,
        private readonly RenderAttemptService $attempts,
        private readonly VideoProviderCheckpointService $checkpoints,
        private readonly RenderLeaseService $lease,
        private readonly RenderFailureService $failures,
        private readonly VideoRenderExecutionService $execution,
        private readonly FilesystemFactory $storage,
        private readonly FfmpegRunner $ffmpeg,
        private readonly Mp4Probe $probe,
        private readonly VideoRenderRepositoryInterface $renders,
        private readonly VideoRenderAttemptRepositoryInterface $renderAttempts,
    ) {}

    /** @return array{version: string, sampling: string, interpolation: string, working_format: string, scale: string, encoder: array<string, mixed>} what the processor freezes about itself into every request, so the request hash names the way the file is made */
    public static function processor(): array
    {
        return [
            'version' => MotoContract::DYNAMIC_CROP_PROCESSOR,
            'sampling' => 'frame n at n*1000/fps ms; window linear in time between the two points around it, held after the last; exact, never rounded',
            'interpolation' => 'perspective cubic at 1/256 px over a whole-pixel crop holding the window',
            'working_format' => 'gbrp',
            'scale' => 'lanczos+accurate_rnd+full_chroma_int',
            'encoder' => self::ENCODER,
        ];
    }

    /**
     * @param  array<string, mixed>  $clip  a clip of an execution plan made by a dynamic crop
     * @param  array<string, mixed>  $execution  the execution policy the plan pinned
     * @return ?string why the clip is not one the local processor makes: not a dynamic crop on the local path, no approved framed set, not exactly the master of that set as its one crop source, no crop path whose first window is the approved crop, a prompt, a rule that needs sound, or file checks other than the output the policy declares; null when it is
     */
    public static function clipProblem(array $clip, array $execution): ?string
    {
        $subject = 'Shot '.$clip['shot_id'];
        $output = (array) $execution['dynamic_crop'];
        $pin = is_array($clip['keyframe'] ?? null) ? $clip['keyframe'] : null;
        $images = array_values((array) ($clip['images'] ?? []));
        $image = count($images) === 1 ? $images[0] : null;
        $path = array_values((array) ($clip['crop_plan']['path'] ?? []));
        $content = (int) $clip['content_duration_ms'];
        $expected = ['version' => MotoClipPlanBuilder::POLICY_VERSION_V2, 'duration_ms' => $content, 'output_size' => [(int) $output['width'], (int) $output['height']], 'av_drift_ms' => null, 'used_start_ms' => 0, 'used_end_ms' => $content, 'audio' => MotoContract::AUDIO_ALLOW_SILENT, 'fps' => (int) $output['fps'], 'frames' => (int) $output['frames'], 'codec' => (string) $output['codec'], 'pixel_format' => (string) $output['pixel_format'], 'sar' => (string) $output['sar']];
        $media = array_intersect_key((array) ($clip['media_policy'] ?? []), $expected);
        ksort($media);
        ksort($expected);

        return match (true) {
            $clip['motion_method'] !== MotoContract::MOTION_DYNAMIC_CROP || $clip['input_mode'] !== 'keyframe' || ($clip['render']['transport'] ?? null) !== MotoClipPlanBuilder::LOCAL
                || ($clip['render']['processor'] ?? null) !== MotoContract::DYNAMIC_CROP_PROCESSOR || ($clip['render']['model'] ?? null) !== null => $subject.' không phải clip Dynamic Crop của bộ xử lý '.MotoContract::DYNAMIC_CROP_PROCESSOR.' — chưa có gì được tạo.',
            $pin === null || ($pin['framed'] ?? false) !== true || ! is_array($pin['crop'] ?? null) => $subject.': Dynamic Crop cần bộ keyframe đã duyệt có ảnh master 16:9 và vùng crop — chưa có gì được tạo.',
            $image === null || ($image['position'] ?? null) !== 0 || ($image['send_as'] ?? null) !== 'crop_source' || ($image['kind'] ?? null) !== 'keyframe'
                || ($image['artifact_id'] ?? null) !== $pin['artifact_id'] || ($image['artifact_id'] ?? null) !== ($pin['master']['artifact_id'] ?? null)
                || ($image['sha256'] ?? null) !== $pin['sha256'] || ($image['sha256'] ?? null) !== ($pin['master']['sha256'] ?? null) => $subject.': Dynamic Crop lấy mẫu đúng một ảnh — ảnh master của bộ keyframe đã duyệt; không dùng ảnh delivery hay ảnh tham chiếu.',
            ($clip['crop_plan']['version'] ?? null) !== MotoContract::DYNAMIC_CROP_PROCESSOR || $path === [] || array_intersect_key((array) $path[0], $pin['crop']) != $pin['crop'] || ($path[0]['at_ms'] ?? null) !== 0 => $subject.': đường crop không mở bằng đúng vùng crop đã duyệt cùng bộ keyframe — chưa có gì được tạo.',
            $clip['prompt'] !== null => $subject.': clip Dynamic Crop không dùng prompt video.',
            $clip['audio_policy'] !== MotoContract::AUDIO_ALLOW_SILENT => $subject.': Dynamic Crop không tạo âm thanh nên chỉ làm được clip theo allow_silent — không thêm luồng tiếng im lặng để qua kiểm.',
            $media !== $expected => $subject.': chính sách kiểm file của clip không phải đầu ra Dynamic Crop mà chính sách thực thi khai — tải lại trang, chưa có gì được tạo.',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $cropPlan  the crop plan of the clip: version, path of {at_ms, x, y, width, height}, protected region or null
     * @param  array<string, mixed>  $output  the dynamic crop output of the execution policy: width, height, fps and frames
     * @param  int  $contentMs  the length of the content, which the frames make
     * @return array{samples: list<array{frame: int, den: int, x: int, y: int, width: int, height: int}>, problem: ?string} the window of every frame as numerators over one denominator per frame, exactly, and why one of them cannot be sampled: outside the master, off the output ratio, smaller than the output or without the whole protected region; the path itself must start at 0 ms and run forward
     */
    public static function samples(array $cropPlan, array $output, int $contentMs, int $masterWidth, int $masterHeight): array
    {
        $path = array_values(array_filter((array) ($cropPlan['path'] ?? []), 'is_array'));
        $fps = (int) $output['fps'];
        $frames = (int) $output['frames'];
        [$width, $height] = [(int) $output['width'], (int) $output['height']];
        $protected = is_array($cropPlan['protected'] ?? null) ? $cropPlan['protected'] : null;
        $refuse = static fn (string $why): array => ['samples' => [], 'problem' => 'Đường crop không lấy mẫu được: '.$why.' — chưa có gì được tạo.'];

        if ($fps < 1 || $frames < 1 || $frames * 1000 !== $contentMs * $fps) {
            return $refuse("{$frames} frame ở {$fps} fps không bằng {$contentMs} ms nội dung");
        }

        if ($path === [] || ($path[0]['at_ms'] ?? null) !== 0) {
            return $refuse('đường crop phải có điểm đầu ở 0 ms');
        }

        foreach ($path as $index => $point) {
            if (array_keys($point) !== ['at_ms', 'x', 'y', 'width', 'height'] || array_filter($point, static fn (mixed $value): bool => ! is_int($value) || $value < 0) !== []
                || ($index > 0 && $point['at_ms'] <= $path[$index - 1]['at_ms'])) {
                return $refuse('điểm '.($index + 1).' không phải mốc tăng dần với vùng số nguyên');
            }
        }

        $samples = [];
        $segment = 0;

        for ($frame = 0; $frame < $frames; $frame++) {
            $time = $frame * 1000;

            while ($segment + 1 < count($path) && $path[$segment + 1]['at_ms'] * $fps <= $time) {
                $segment++;
            }

            $from = $path[$segment];
            $to = $path[$segment + 1] ?? null;
            $den = $to === null ? 1 : $fps * ($to['at_ms'] - $from['at_ms']);
            $step = $to === null ? 0 : $time - $fps * $from['at_ms'];
            $at = static fn (string $key): int => $to === null ? (int) $from[$key] : (int) $from[$key] * ($den - $step) + (int) $to[$key] * $step;
            $window = ['frame' => $frame, 'den' => $den, 'x' => $at('x'), 'y' => $at('y'), 'width' => $at('width'), 'height' => $at('height')];
            $named = 'frame '.$frame.' ('.self::decimal($window['x'], $den).', '.self::decimal($window['y'], $den).', '.self::decimal($window['width'], $den).'×'.self::decimal($window['height'], $den).')';

            $problem = match (true) {
                $window['x'] + $window['width'] > $masterWidth * $den || $window['y'] + $window['height'] > $masterHeight * $den => "{$named} vượt biên ảnh master {$masterWidth}×{$masterHeight}",
                $window['width'] * $height !== $window['height'] * $width => "{$named} không đúng tỷ lệ {$width}×{$height}",
                $window['width'] < $width * $den || $window['height'] < $height * $den => "{$named} nhỏ hơn {$width}×{$height} — không phóng lớn ảnh",
                $protected !== null && ($window['x'] > (int) $protected['x'] * $den || $window['y'] > (int) $protected['y'] * $den
                    || $window['x'] + $window['width'] < ((int) $protected['x'] + (int) $protected['width']) * $den
                    || $window['y'] + $window['height'] < ((int) $protected['y'] + (int) $protected['height']) * $den) => "{$named} không giữ trọn vùng bảo vệ chủ thể ({$protected['x']}, {$protected['y']}, {$protected['width']}×{$protected['height']})",
                default => null,
            };

            if ($problem !== null) {
                return $refuse($problem);
            }

            $samples[] = $window;
        }

        return ['samples' => $samples, 'problem' => null];
    }

    /**
     * @param  array<string, mixed>  $cropPlan  the crop plan of the clip
     * @param  array<string, mixed>  $output  the dynamic crop output of the execution policy
     * @return string the FFmpeg filter graph that makes the clip from a master of that size: a whole-pixel crop holding the window of each frame, the perspective filter mapping the exact window onto it, the Lanczos scale to the output and yuv420p only at the end. A path of one window crops exactly that window
     */
    public static function filterGraph(array $cropPlan, array $output, int $masterWidth, int $masterHeight): string
    {
        $path = array_values((array) $cropPlan['path']);
        $fps = (int) $output['fps'];
        $still = count(array_unique(array_map(static fn (array $point): string => $point['x'].':'.$point['y'].':'.$point['width'].':'.$point['height'], $path))) === 1;
        $cropWidth = min($masterWidth, max(array_column($path, 'width')) + ($still ? 0 : 1));
        $cropHeight = min($masterHeight, max(array_column($path, 'height')) + ($still ? 0 : 1));
        $value = static fn (string $key, string $frame): string => self::piecewise($path, $key, $frame, $fps);
        $left = static fn (string $frame): string => 'min(floor('.$value('x', $frame).'),'.($masterWidth - $cropWidth).')';
        $top = static fn (string $frame): string => 'min(floor('.$value('y', $frame).'),'.($masterHeight - $cropHeight).')';
        $shown = '(on-1)';
        $x0 = '('.$value('x', $shown).')-('.$left($shown).')+0.5*('.$value('width', $shown).')/'.$cropWidth.'-0.5';
        $y0 = '('.$value('y', $shown).')-('.$top($shown).')+0.5*('.$value('height', $shown).')/'.$cropHeight.'-0.5';
        $x1 = $x0.'+('.$value('width', $shown).')';
        $y2 = $y0.'+('.$value('height', $shown).')';

        return '[0:v]format=gbrp,'
            ."crop=w={$cropWidth}:h={$cropHeight}:x='".$left('n')."':y='".$top('n')."',"
            ."perspective=x0='{$x0}':y0='{$y0}':x1='{$x1}':y1='{$y0}':x2='{$x0}':y2='{$y2}':x3='{$x1}':y3='{$y2}':interpolation=cubic:sense=source:eval=frame,"
            .'scale=w='.(int) $output['width'].':h='.(int) $output['height'].':flags=lanczos+accurate_rnd+full_chroma_int:out_color_matrix='.self::ENCODER['colorspace'].':out_range='.self::ENCODER['range'].','
            .'setparams=color_primaries='.self::ENCODER['colorspace'].':color_trc='.self::ENCODER['colorspace'].','
            .'setsar=1,format='.(string) $output['pixel_format'].'[v]';
    }

    /**
     * @param  list<array<string, int>>  $path
     * @return string an FFmpeg expression of the value of $key at frame $frame: linear in time between two points, held after the last
     */
    private static function piecewise(array $path, string $key, string $frame, int $fps): string
    {
        $time = '('.$frame.'*1000/'.$fps.')';
        $last = count($path) - 1;
        $expression = (string) $path[$last][$key];

        for ($index = $last - 1; $index >= 0; $index--) {
            [$from, $to] = [$path[$index], $path[$index + 1]];
            $expression = 'if(lt('.$time.','.$to['at_ms'].'),'.$from[$key].'+('.($to[$key] - $from[$key]).')*('.$time.'-'.$from['at_ms'].')/'.($to['at_ms'] - $from['at_ms']).','.$expression.')';
        }

        return $expression;
    }

    private static function decimal(int $numerator, int $denominator): string
    {
        return rtrim(rtrim(number_format($numerator / $denominator, 3, '.', ''), '0'), '.');
    }

    /**
     * @param  array<string, mixed>  $spec  the frozen request of a dynamic crop
     * @return list<string> the FFmpeg arguments that write the clip of that request from the master at $master to $target: the still looped at the frame rate, the frozen filter graph, exactly the frames of the output, H.264 and no audio stream
     */
    public static function arguments(array $spec, string $master, string $target): array
    {
        $output = (array) $spec['output'];
        $encoder = (array) $spec['processor']['encoder'];

        return [
            '-v', 'error', '-nostdin', '-y',
            '-f', 'image2', '-loop', '1', '-framerate', (string) (int) $output['fps'], '-i', $master,
            '-filter_complex', (string) $spec['filter_graph'],
            '-map', '[v]', '-frames:v', (string) (int) $output['frames'],
            '-c:v', (string) $encoder['codec'], '-preset', (string) $encoder['preset'], '-crf', (string) (int) $encoder['crf'], '-profile:v', (string) $encoder['profile'],
            '-pix_fmt', (string) $output['pixel_format'], '-colorspace', (string) $encoder['colorspace'], '-color_primaries', (string) $encoder['colorspace'], '-color_trc', (string) $encoder['colorspace'], '-color_range', (string) $encoder['range'],
            '-an', '-movflags', '+faststart', $target,
        ];
    }

    /**
     * @return ?string why this machine cannot make a dynamic crop now: ffprobe or ffmpeg does not run, or — where the video queue is on — its connection cannot hold the job with its row or no supervisor runs; null when a crop would be made
     */
    public function problem(): ?string
    {
        if (($missing = $this->probe->problem()) !== null) {
            return 'ffprobe không chạy được — '.$missing;
        }

        if (! $this->ffmpeg->run(['-version'])->successful) {
            return 'Không chạy được ffmpeg để tạo clip Dynamic Crop — đặt VIDEO_FFMPEG_BIN rồi thử lại; chưa có gì được tạo.';
        }

        if (! VideoQueues::enabled()) {
            return null;
        }

        if (($queue = VideoQueues::problem(RenderDynamicCropJob::seconds())) !== null) {
            return 'Chưa nhận lượt Dynamic Crop: '.$queue;
        }

        $heartbeat = json_decode((string) @file_get_contents(VideoQueues::heartbeatPath()), true);
        $age = is_array($heartbeat) ? time() - (int) ($heartbeat['at'] ?? 0) : null;

        if ($age === null || $age > (int) config('video.async.supervisor.heartbeat_stale_seconds') || ($heartbeat['mode'] ?? null) !== 'run' || isset($heartbeat['error'])) {
            return 'Chưa nhận lượt Dynamic Crop: bộ điều phối hàng đợi (video:queue-supervisor) không chạy'.($age === null ? '' : ' — nhịp tim cách đây '.$age.' s').'; xem php artisan video:queue-check.';
        }

        return null;
    }

    /**
     * Makes the row of a dynamic crop. It runs inside the transaction of the dispatch, which holds the project: it freezes the master and the processor into the request, records the dispatch and, where the video queue is on, queues the job on the ffmpeg lane in the same transaction. Nothing is rendered here.
     *
     * @param  VideoShot  $shot  the shot of the clip, at the intent version the dispatch holds
     * @param  VideoArtifact  $master  the master of the approved keyframe set, its file just checked against the pin of the clip
     * @param  array<string, mixed>  $frozen  MotoClipRenderService::executionRequest() of the clip with no registry entry
     * @param  array<string, mixed>  $clip  the clip of the execution plan, which clipProblem() takes
     * @param  array<string, mixed>  $execution  the execution policy the plan pinned
     * @return VideoRender the row, queued: a candidate when it finishes (the request says review_before_use)
     *
     * @throws RuntimeException when the master no longer reads as the pinned file of its size, a window of the path cannot be sampled, or the dispatch was refused; nothing was made
     */
    public function create(VideoShot $shot, VideoArtifact $master, int $expectedIntent, string $operationId, array $frozen, array $clip, array $execution, ?string $requesterId = null): VideoRender
    {
        $output = (array) $execution['dynamic_crop'];
        $source = $this->freeze($master);

        if ($source['width'] !== (int) $clip['keyframe']['master']['width'] || $source['height'] !== (int) $clip['keyframe']['master']['height'] || ! hash_equals((string) $clip['keyframe']['master']['sha256'], $source['sha256'])) {
            throw new RuntimeException('Ảnh master của bộ keyframe shot '.$clip['shot_id'].' không còn là file đã ghim — tải lại trang, chưa có gì được tạo.');
        }

        $checked = self::samples((array) $clip['crop_plan'], $output, (int) $clip['content_duration_ms'], $source['width'], $source['height']);

        if ($checked['problem'] !== null) {
            throw new RuntimeException('Shot '.$clip['shot_id'].': '.$checked['problem']);
        }

        $request = [
            'kind' => self::KIND,
            'provider_payload_version' => MotoContract::DYNAMIC_CROP_PROCESSOR,
            'provider' => MotoClipPlanBuilder::LOCAL,
            'model' => MotoContract::DYNAMIC_CROP_PROCESSOR,
            'processor' => self::processor(),
            'duration_seconds' => intdiv((int) $clip['render']['duration_ms'], 1000),
            'aspect_ratio' => (string) $clip['render']['aspect_ratio'],
            'resolution' => (string) $clip['render']['resolution'],
            'crop_source' => $source,
            'output' => $output,
            'filter_graph' => self::filterGraph((array) $clip['crop_plan'], $output, $source['width'], $source['height']),
            'samples' => ['count' => count($checked['samples']), 'hash' => CanonicalJson::hash($checked['samples'])],
            'motion_spec_hash' => ShotSelectionReconciler::motionSpecHash($shot),
        ] + $frozen;
        $json = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $json);
        $keyframe = $master->render_id === null ? null : $this->renders->findRender($master->render_id);
        $payloadHash = hash('sha256', json_encode(['shot_id' => (string) $shot->id, 'source_artifact_id' => (string) $master->id, 'processor' => MotoContract::DYNAMIC_CROP_PROCESSOR, 'clip_hash' => (string) $clip['hash'], 'expected_intent_version' => $expectedIntent], JSON_THROW_ON_ERROR));

        [$updated, $render, $reason] = $this->intents->dispatch($shot, $expectedIntent, $operationId, $payloadHash, fn (VideoShot $locked): VideoRender => $this->dispatch->create(
            sessionId: null,
            assetId: (string) $locked->shot_code,
            provider: MotoClipPlanBuilder::LOCAL,
            model: MotoContract::DYNAMIC_CROP_PROCESSOR,
            idempotencyKey: self::IDEMPOTENCY_PREFIX.$locked->id.':'.$hash,
            requestJson: $json,
            requestHash: $hash,
            canonicalRevisionId: (string) ($keyframe?->canonical_concept_revision_id ?? ''),
            canonicalHash: (string) ($keyframe?->canonical_hash ?? ''),
            projectionHash: (string) ($keyframe?->projection_hash ?? ''),
            constraintSetHash: (string) ($keyframe?->constraint_set_hash ?? ''),
            promptSpecHash: (string) ($keyframe?->prompt_spec_hash ?? ''),
            providerPromptPlanHash: (string) ($keyframe?->provider_prompt_plan_hash ?? ''),
            promptHash: hash('sha256', ''),
            maxAttempts: 1,
            renderKind: 'video',
            shotId: (string) $locked->id,
            executionPurpose: SceneClipDispatchService::PURPOSE_PRODUCTION,
        ));

        if ($updated === null || $render === null) {
            throw new RuntimeException($reason);
        }

        $shot->setRawAttributes($updated->getAttributes(), true);

        if ($reason === 'dispatched' && VideoQueues::enabled()) {
            RenderDynamicCropJob::dispatch((string) $render->id)
                ->onConnection(VideoQueues::CONNECTION)
                ->onQueue(VideoQueues::name(VideoQueues::FFMPEG, $requesterId))
                ->beforeCommit();
        }

        return $render;
    }

    /**
     * The worker body, the same inline and from the queue job: it takes the row once, reads the master again by its frozen hash, samples every window again and requires the frozen samples and filter graph, writes the clip with FFmpeg outside any transaction and hands the file to storeCheckedFile(), which measures, decodes and judges it by the frozen media policy and keeps the evidence. Nothing is sent anywhere.
     *
     * @return array{0: bool, 1: string} [$ok, $reason]: succeeded, or why not — not_local, a tool missing (the row is left for a later run), not_claimable (another run took it, or it was cancelled), or the failure code written to the row
     */
    public function run(string $renderId): array
    {
        $render = $this->renders->findRender($renderId);

        if ($render === null || $render->provider !== MotoClipPlanBuilder::LOCAL || $render->render_kind !== 'video') {
            return [false, 'not_local'];
        }

        if (! $this->probe->available()) {
            return [false, 'ffprobe_missing'];
        }

        if (! $this->ffmpeg->run(['-version'])->successful) {
            return [false, 'ffmpeg_missing'];
        }

        $claim = $this->claims->claimById($renderId, VideoRenderExecutionService::WORKER);

        if ($claim === null) {
            return [false, 'not_claimable'];
        }

        $token = $claim->claimToken;
        $dir = null;

        try {
            $spec = json_decode($claim->renderRequestJson, true);
            $blocked = match (true) {
                ! is_array($spec) => 'render_request_json hỏng',
                ! hash_equals($claim->requestHash, hash('sha256', $claim->renderRequestJson)) => 'request hash lệch',
                ($spec['kind'] ?? null) !== self::KIND || ($spec['provider_payload_version'] ?? null) !== MotoContract::DYNAMIC_CROP_PROCESSOR || ($spec['processor'] ?? null) !== self::processor() => 'yêu cầu không phải của bộ xử lý '.MotoContract::DYNAMIC_CROP_PROCESSOR.' đang cài',
                ($spec['media_policy']['version'] ?? null) !== MotoClipPlanBuilder::POLICY_VERSION_V2 => 'yêu cầu không đóng băng chính sách kiểm file '.MotoClipPlanBuilder::POLICY_VERSION_V2,
                default => null,
            };

            if ($blocked !== null) {
                return $this->fail($renderId, $token, RenderFailureClass::INVALID_REQUEST, 'precondition_failed', $blocked);
            }

            $this->attempts->markPreparing($renderId, $token);
            [$bytes, $error] = $this->read((array) $spec['crop_source'], true);

            if ($bytes === null) {
                return $this->fail($renderId, $token, RenderFailureClass::ARTIFACT_INTEGRITY, 'source_artifact_invalid', 'Ảnh master: '.$error);
            }

            $source = (array) $spec['crop_source'];
            $cropPlan = (array) $spec['moto_clip']['crop_plan'];
            $checked = self::samples($cropPlan, (array) $spec['output'], (int) $spec['moto_clip']['content_duration_ms'], (int) $source['width'], (int) $source['height']);

            if ($checked['problem'] !== null || CanonicalJson::hash($checked['samples']) !== ($spec['samples']['hash'] ?? null) || self::filterGraph($cropPlan, (array) $spec['output'], (int) $source['width'], (int) $source['height']) !== ($spec['filter_graph'] ?? null)) {
                return $this->fail($renderId, $token, RenderFailureClass::INVALID_REQUEST, 'crop_plan_invalid', $checked['problem'] ?? 'các khung lấy mẫu hoặc filter graph khác bản đã đóng băng trong yêu cầu');
            }

            $this->attempts->markSubmitting($renderId, $token);
            $this->attempts->markSubmitted($renderId, $token, null);
            $render = $this->checkpoints->markArtifactWriting($render, $token, ['processor' => MotoContract::DYNAMIC_CROP_PROCESSOR, 'samples' => count($checked['samples'])]);
            $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'moto_crop_'.$renderId.'_'.Str::random(6);
            File::ensureDirectoryExists($dir);
            $master = $dir.DIRECTORY_SEPARATOR.'master.'.($source['mime'] === 'image/jpeg' ? 'jpg' : 'png');
            $clip = $dir.DIRECTORY_SEPARATOR.'clip.mp4';
            File::put($master, $bytes);
            $this->lease->heartbeat($renderId, $token, VideoRenderExecutionService::WORKER);
            $made = $this->ffmpeg->run(self::arguments($spec, $master, $clip), self::PROCESS_SECONDS);

            if (! $made->successful || ! is_file($clip) || (int) filesize($clip) === 0) {
                return $this->fail($renderId, $token, RenderFailureClass::ARTIFACT_INTEGRITY, 'processor_failed', 'ffmpeg không tạo được clip Dynamic Crop: '.($made->tail() !== '' ? $made->tail() : 'mã thoát '.var_export($made->exitCode, true)));
            }

            $this->lease->heartbeat($renderId, $token, VideoRenderExecutionService::WORKER);
            $path = 'video/'.$renderId.'/clip.mp4';
            $stream = fopen($clip, 'rb');
            $written = $stream !== false && $this->storage->disk((string) config('video.veo.disk'))->put($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if (! $written) {
                return $this->fail($renderId, $token, RenderFailureClass::ARTIFACT_INTEGRITY, 'artifact_write_failed', 'Không ghi được file clip lên đĩa video.');
            }

            return $this->execution->storeCheckedFile($this->renders->refreshRender($render), $token, $spec, ['sha256' => (string) hash_file('sha256', $clip), 'bytes' => (int) filesize($clip), 'mime' => 'video/mp4', 'local_path' => $clip], $path);
        } catch (Throwable $e) {
            if ($this->renders->findExecutionStatus($renderId) === RenderStatus::SUCCEEDED->value) {
                return [true, 'succeeded'];
            }

            return $this->fail($renderId, $token, RenderFailureClass::INTERNAL, 'processor_error', Str::limit(get_class($e).': '.$e->getMessage(), 500));
        } finally {
            if ($dir !== null) {
                File::deleteDirectory($dir);
            }
        }
    }

    /**
     * Closes a local row whose worker stopped without finishing it: nothing was sent anywhere, so closing it costs nothing and frees the shot for a new click. A finished or closed row is left as it is.
     */
    public function abandon(string $renderId, string $reason): void
    {
        DB::transaction(function () use ($renderId, $reason): void {
            $render = $this->renders->lockRender($renderId);

            if ($render === null || $render->provider !== MotoClipPlanBuilder::LOCAL || $render->isTerminal()) {
                return;
            }

            $this->close($render, $reason);
        }, attempts: 3);
    }

    /**
     * @param  VideoRender  $render  a local row the caller holds locked, not finished
     * @return void the row closed as WORKER_LOST with its open attempt, outside the lease: no provider holds anything for it
     */
    public function close(VideoRender $render, string $reason): void
    {
        $this->renderAttempts->abandonOpenAttempt($render->id, $render->attempt_count, self::WORKER_LOST, Str::limit($reason, 500));
        $this->renders->saveRender($render, [
            'execution_status' => RenderStatus::FAILED,
            'failure_class' => RenderFailureClass::INTERNAL,
            'failure_code' => self::WORKER_LOST,
            'failure_message' => Str::limit('Bộ xử lý local dừng giữa chừng, lượt này không gửi gì ra ngoài: '.$reason, 1000),
            'claim_token' => null,
            'claimed_by' => null,
            'claimed_at' => null,
            'heartbeat_at' => null,
            'lease_expires_at' => null,
            'execution_completed_at' => now(),
            'execution_version' => (int) $render->execution_version + 1,
        ]);
    }

    /**
     * @return array{artifact_id: string, disk: string, path: string, sha256: string, mime: string, bytes: int, width: int, height: int} the master frozen as evidence: reading this file from this disk again must give these bytes
     *
     * @throws RuntimeException when the file is not on an allowed disk, is gone, too large, of another hash than its artifact or no PNG/JPEG
     */
    private function freeze(VideoArtifact $master): array
    {
        $disk = (string) $master->storage_disk;
        $path = (string) $master->storage_path;
        $frozen = ['artifact_id' => (string) $master->id, 'disk' => $disk, 'path' => $path, 'sha256' => (string) $master->sha256, 'mime' => (string) $master->mime_type, 'bytes' => (int) $master->file_size, 'width' => 0, 'height' => 0];
        [$bytes, $error, $info] = $this->read($frozen, false);

        if ($bytes === null) {
            throw new RuntimeException('Ảnh master của bộ keyframe: '.$error.' — chưa có gì được tạo.');
        }

        return array_replace($frozen, ['mime' => $info['mime'], 'bytes' => strlen($bytes), 'width' => $info['width'], 'height' => $info['height']]);
    }

    /**
     * @param  array<string, mixed>  $source  artifact_id, disk, path, sha256 and, when $strict (the master as a request froze it), mime, width and height
     * @return array{0: ?string, 1: ?string, 2: array{mime: string, width: int, height: int}} the bytes when the file still is that file — same hash and, when strict, same type and size — else null with why, and what the image reads as
     */
    private function read(array $source, bool $strict): array
    {
        $disk = (string) ($source['disk'] ?? '');
        $path = (string) ($source['path'] ?? '');
        $none = ['mime' => '', 'width' => 0, 'height' => 0];

        if (! in_array($disk, (array) config('video.veo.source_disks', ['video_artifacts']), true)) {
            return [null, 'nằm trên disk không được phép: '.$disk, $none];
        }

        $filesystem = $this->storage->disk($disk);

        if ($path === '' || ! $filesystem->exists($path)) {
            return [null, 'file không còn trên đĩa: '.$path, $none];
        }

        $size = (int) $filesystem->size($path);

        if ($size <= 0 || $size > (int) config('video.veo.max_source_bytes', 33554432)) {
            return [null, 'file có kích thước không hợp lệ: '.$size, $none];
        }

        $bytes = (string) $filesystem->get($path);

        if (! hash_equals((string) ($source['sha256'] ?? ''), hash('sha256', $bytes))) {
            return [null, 'file khác hash đã ghim', $none];
        }

        $read = @getimagesizefromstring($bytes);
        $info = ['mime' => is_array($read) ? (string) ($read['mime'] ?? '') : '', 'width' => is_array($read) ? (int) $read[0] : 0, 'height' => is_array($read) ? (int) $read[1] : 0];

        if (! in_array($info['mime'], ['image/png', 'image/jpeg'], true) || $info['width'] < 1 || $info['height'] < 1) {
            return [null, 'file không đọc được thành ảnh PNG/JPEG', $none];
        }

        if ($strict && ($info['mime'] !== ($source['mime'] ?? null) || $info['width'] !== (int) ($source['width'] ?? 0) || $info['height'] !== (int) ($source['height'] ?? 0))) {
            return [null, sprintf('ảnh đọc ra %s %dx%d, yêu cầu đã ghim %s %dx%d', $info['mime'], $info['width'], $info['height'], (string) ($source['mime'] ?? '?'), (int) ($source['width'] ?? 0), (int) ($source['height'] ?? 0)), $none];
        }

        return [$bytes, null, $info];
    }

    /** @return array{0: bool, 1: string} the row failed with that code by the worker that holds it; a lost lease leaves it to the one that took it */
    private function fail(string $renderId, string $token, RenderFailureClass $class, string $code, string $message): array
    {
        try {
            $this->failures->fail($renderId, $token, $class, $code, $message, VideoRenderExecutionService::WORKER);
        } catch (Throwable) {
            return [false, 'lease_lost_before_failure'];
        }

        return [false, $code];
    }
}
