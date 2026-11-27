<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Models\VideoArtifact;
use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Models\VideoSession;
use App\Models\VideoShot;
use App\Repositories\Interfaces\VideoArtifactRepositoryInterface;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Repositories\Interfaces\VideoProviderSubmissionReceiptRepositoryInterface;
use App\Repositories\Interfaces\VideoRenderRepositoryInterface;
use App\Repositories\Interfaces\VideoSessionEventRepositoryInterface;
use App\Repositories\Interfaces\VideoShotRepositoryInterface;
use App\Video\Media\FfmpegRunner;
use App\Video\Media\GeminiOmniVideoClient;
use App\Video\Media\VideoModelRegistry;
use App\Video\Moto\MotoClipPlanBuilder;
use App\Video\Moto\MotoClipPrompt;
use App\Video\Moto\MotoContract;
use App\Video\Queue\VideoQueues;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\Video\SceneClipDispatchService;
use App\Video\Render\Video\SceneShotFactory;
use App\Video\Render\Video\VideoRenderExecutionService;
use App\Video\Render\VideoProviderCheckpointService;
use App\Video\Scene\Services\ShotIntentService;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dispatches one shot of the approved plan through the shared render path. Every check runs before the request is frozen, and the transaction ends before the provider is called.
 */
final class MotoClipRenderService
{
    public const SHOT_TYPE = 'moto_clip';

    public const REQUEST_VERSION = 'moto-clip-request-v4';

    /** @var string the request of a clip of an execution plan (MotoContract::CLIP_PLAN_FORMAT_V3): it freezes how the clip is moved, the images it sends with their roles, the settings revision, the keyframe set, the length of the file and the part kept, the audio rule and the file checks, and only ever becomes a candidate when it finishes */
    public const REQUEST_VERSION_V3 = 'moto-clip-request-v5';

    /** @var string what a refusal says when what the page shows is no longer what would be sent */
    public const RELOAD = 'Prompt, model, keyframe hoặc nguồn đã đổi so với bản đang xem — tải lại trang để xem bản mới, chưa có gì được gửi.';

    /** @var string how render() answers an operation of an execution plan sent again: its attempt exists, and nothing was sent or run for this call */
    public const REPLAYED = 'replayed';

    /** @var list<RenderStatus> the statuses of an attempt that never reached the provider, so replacing it costs nothing */
    private const UNSENT = [RenderStatus::QUEUED, RenderStatus::RETRY_WAIT];

    /** @var list<RenderStatus> the statuses of an attempt that is over, which a new render of its shot leaves alone */
    private const SETTLED = [RenderStatus::SUCCEEDED, RenderStatus::FAILED, RenderStatus::CANCELLED];

    /** @var string retirement(): the attempt is a row of the local processor no worker holds, closed as lost by the next render of its shot */
    public const RETIRE_CLOSE = 'close';

    /** @var string retirement(): the attempt never left this machine, cancelled by the next render of its shot */
    public const RETIRE_CANCEL = 'cancel';

    /** @var string retirement(): the attempt is on its way or its outcome is unknown, and stops a new render of its shot until it is settled */
    public const RETIRE_WAIT = 'wait';

    public function __construct(
        private readonly MotoClipPlanService $plans,
        private readonly SceneShotFactory $factory,
        private readonly ShotIntentService $intents,
        private readonly SceneClipDispatchService $clips,
        private readonly VideoRenderExecutionService $execution,
        private readonly VideoProviderCheckpointService $checkpoints,
        private readonly FilesystemFactory $storage,
        private readonly FfmpegRunner $ffmpeg,
        private readonly MotoDynamicCropService $crops,
        private readonly VideoProjectRepositoryInterface $projects,
        private readonly VideoRenderRepositoryInterface $renders,
        private readonly VideoArtifactRepositoryInterface $artifacts,
        private readonly VideoShotRepositoryInterface $shots,
        private readonly VideoSessionEventRepositoryInterface $events,
        private readonly VideoProviderSubmissionReceiptRepositoryInterface $receipts,
    ) {}

    /**
     * @param  array{model_id: string, resolution: string, aspect_ratio: string, duration_seconds: int}  $controls  for a dynamic crop, model_id is the processor of the clip (render.processor)
     * @param  string  $expectedHash  the hash of the clip the page shows
     * @param  ?int  $expectedSettings  under an execution plan, the revision of the shot settings the page holds; null leaves it to the page hash, which covers the revision too
     * @param  ?string  $requesterId  who asked, which names the queue lane of a dynamic crop where the video queue is on
     * @return array{0: VideoRender, 1: bool, 2: string} the attempt with how its submit went: the same operation sent again returns its own attempt and is never sent twice. For a clip of an execution plan that second call neither submits nor runs anything, whatever the attempt is doing: it answers [the attempt as it stands, true, REPLAYED], and the caller reads the state from the row. A dynamic crop is never submitted to a provider: where the video queue is on its job is queued with the row (queued), else this request makes the file itself after the row is committed (succeeded or the failure code)
     *
     * @throws RuntimeException when the clip cannot be sent; nothing was charged
     */
    public function render(string $projectId, string $shotId, array $controls, int $expectedIntent, string $operationId, string $expectedHash, ?int $expectedSettings = null, ?string $requesterId = null): array
    {
        if (! Str::isUuid($operationId)) {
            throw new RuntimeException('Mã thao tác render không hợp lệ — tải lại trang.');
        }

        [$render, $replayed] = DB::transaction(fn (): array => $this->dispatch($projectId, $shotId, $controls, $expectedIntent, $operationId, $expectedHash, $expectedSettings, $requesterId), attempts: 3);

        if ($replayed && self::executionRow($render)) {
            return [$render, true, self::REPLAYED];
        }

        if ($render->provider === MotoClipPlanBuilder::LOCAL) {
            [$ok, $reason] = match (true) {
                $render->execution_status !== RenderStatus::QUEUED => [! in_array($render->execution_status, [RenderStatus::FAILED, RenderStatus::CANCELLED], true), $render->execution_status->value],
                VideoQueues::enabled() => [true, 'queued'],
                default => $this->crops->run((string) $render->id),
            };

            return [$this->renders->refreshRender($render), $ok, $reason];
        }

        [$ok, $reason] = $this->execution->submit((string) $render->id);

        return [$this->renders->refreshRender($render), $ok, $reason];
    }

    /**
     * @return array{0: bool, 1: string} one round of following an attempt, the same from the screen and from the background: a worker that died holding it is released, then the provider is asked once. It only ever reads what was sent and never sends again
     */
    public function track(string $renderId): array
    {
        $render = $this->renders->findRenderOrFail($renderId);
        $this->checkpoints->releaseStalledPoll($render);

        try {
            return $this->execution->poll($renderId);
        } catch (RuntimeException $e) {
            Log::info('Chưa hỏi được provider cho lượt clip', ['render_id' => $renderId, 'reason' => $e->getMessage()]);

            return [false, 'poll_busy'];
        }
    }

    /**
     * @return VideoRender the attempt closed as failed after a person checked that the provider holds no job for it, which frees the shot for a new click; the row stays in the history
     *
     * @throws RuntimeException when its outcome is not unknown or a job id is known, since that job can still be followed
     */
    public function abandon(string $renderId, string $note): VideoRender
    {
        return DB::transaction(function () use ($renderId, $note): VideoRender {
            $render = $this->renders->lockRenderOrFail($renderId);

            if ($render->execution_status !== RenderStatus::PROVIDER_UNKNOWN) {
                throw new RuntimeException('Chỉ đóng được lượt chưa rõ kết quả; lượt này đang ở '.$render->execution_status?->value.'.');
            }

            if ($this->checkpoints->knownJobId($render) !== null) {
                throw new RuntimeException('Lượt này có job đã biết — để vòng theo dõi đọc kết quả, không đóng tay.');
            }

            $this->renders->saveRender($render, [
                'execution_status' => RenderStatus::FAILED,
                'failure_code' => 'abandoned_after_reconcile',
                'failure_message' => 'Đóng tay sau khi đối soát: '.$note,
                'execution_completed_at' => now(),
                'execution_version' => (int) $render->execution_version + 1,
            ]);

            return $render;
        }, attempts: 3);
    }

    /**
     * @return bool whether the row is an attempt of a clip of an execution plan (REQUEST_VERSION_V3), made by a provider or by the local processor
     */
    private static function executionRow(VideoRender $render): bool
    {
        return (((array) json_decode((string) $render->render_request_json, true))['moto_clip']['version'] ?? null) === self::REQUEST_VERSION_V3;
    }

    /**
     * @param  array{model_id: string, resolution: string, aspect_ratio: string, duration_seconds: int}  $controls
     * @return array{0: VideoRender, 1: bool} the attempt of the operation and whether the operation had already made it
     */
    private function dispatch(string $projectId, string $shotId, array $controls, int $expectedIntent, string $operationId, string $expectedHash, ?int $expectedSettings, ?string $requesterId = null): array
    {
        $project = $this->projects->lockProject($projectId);
        $replayed = $this->replayed($projectId, $shotId, $controls, $operationId, $expectedHash);

        if ($replayed !== null) {
            return [$replayed, true];
        }

        $preview = $this->plans->preview($projectId);
        $clip = collect($preview['plan']['clips'])->firstWhere('shot_id', $shotId) ?? throw new RuntimeException("Shot {$shotId} không thuộc kế hoạch shot đã duyệt.");

        if (! hash_equals((string) $clip['hash'], $expectedHash)) {
            throw new RuntimeException(self::RELOAD);
        }

        if ($clip['blocked'] !== null) {
            throw new RuntimeException((string) $clip['blocked']['message']);
        }

        if (($preview['plan']['format'] ?? null) === MotoContract::CLIP_PLAN_FORMAT_V3) {
            return [$this->dispatchExecution($project, $shotId, $controls, $expectedIntent, $operationId, $preview, $clip, $expectedSettings, $requesterId), false];
        }

        $entry = collect($preview['models'])->firstWhere('id', $controls['model_id']);

        if ($entry === null || $controls['model_id'] !== $clip['render']['model']) {
            throw new RuntimeException('Model '.$controls['model_id'].' không phải model của clip này.');
        }

        // Every setting sent is one the hash of the page covers: a setting other than the plan's is refused, never sent.
        if ($controls['duration_seconds'] * 1000 !== $clip['render']['duration_ms'] || $controls['aspect_ratio'] !== $clip['render']['aspect_ratio'] || $controls['resolution'] !== $clip['render']['resolution']) {
            throw new RuntimeException('Thiết lập render khác bản đang xem — tải lại trang, chưa có gì được gửi.');
        }

        $source = $preview['source'];
        $row = collect($source['plan']['shots'])->firstWhere('id', $shotId);
        $session = $this->factory->sessionFor($projectId);
        $shot = $this->shot($session, $source, $row, $clip, $controls['duration_seconds'], $expectedIntent);
        $input = $clip['input_mode'] === MotoClipPlanBuilder::REFERENCES ? $this->references($projectId, $clip) : null;
        $artifact = $input === null
            ? $this->artifacts->findArtifactOrFail($clip['keyframe']['artifact_id'])
            : (collect($input['references'])->first(static fn (array $reference): bool => $reference['artifact']->render_id !== null)['artifact'] ?? $input['references'][0]['artifact']);
        $stage = $this->plans->save($projectId, $preview);

        return [$this->clips->create($shot, $artifact, (string) $entry['id'], [
            'resolution' => $controls['resolution'],
            'aspect_ratio' => $controls['aspect_ratio'],
            'duration_seconds' => $controls['duration_seconds'],
        ], (int) $shot->intent_version, $operationId, $this->frozen($projectId, $operationId, $preview['plan'], $clip, $entry, (string) $stage->id, $preview['audio']), $input), false];
    }

    /**
     * @param  array<string, mixed>  $clip  a clip made from reference images
     * @return array{task: string, references: list<array{artifact: VideoArtifact, role: string, label: string}>} the reference images of the clip in the order the plan gives them, each the very file the plan pins, sent with the task MotoContract::REFERENCE_TO_VIDEO so the provider takes every image as a reference and none as a frame of the clip
     *
     * @throws RuntimeException when an image is no longer the file the plan pinned
     */
    private function references(string $projectId, array $clip): array
    {
        $artifacts = $this->artifacts->findOwnedArtifactsByIds($projectId, array_column($clip['images'], 'artifact_id'))->keyBy('id');
        $references = [];

        foreach ($clip['images'] as $image) {
            $artifact = $artifacts->get($image['artifact_id']);

            if ($artifact === null || ! hash_equals((string) $image['sha256'], (string) $artifact->sha256)) {
                throw new RuntimeException('Ảnh tham chiếu '.((int) $image['position'] + 1).' của shot '.$clip['shot_id'].' không còn là file đã ghim — tải lại trang, chưa có gì được gửi.');
            }

            $references[] = ['artifact' => $artifact, 'role' => (string) $image['role'], 'label' => 'Image '.((int) $image['position'] + 1).' · '.$image['kind']];
        }

        return ['task' => MotoContract::REFERENCE_TO_VIDEO, 'references' => $references];
    }

    /**
     * @param  array{model_id: string, resolution: string, aspect_ratio: string, duration_seconds: int}  $controls
     * @param  array<string, mixed>  $preview  the execution plan built now, under the lock of the project
     * @param  array<string, mixed>  $clip  its clip of the shot, not blocked and of the hash the page shows
     * @return VideoRender the attempt of a clip of an execution plan: exactly its images in their order, the delivery image of the keyframe set as the first frame or the references with the task of the plan, the length of file and resolution the plan asks for, and a request that freezes every pin of the clip and only makes a candidate
     *
     * @throws RuntimeException when the saved settings are not the revision the plan was built from or the page holds, the clip is made by the local processor, the choice is no longer an offer of the policy, the images are not sent by their roles, a pinned file changed, or the settings sent are not the plan's; nothing was sent
     */
    private function dispatchExecution(VideoProject $project, string $shotId, array $controls, int $expectedIntent, string $operationId, array $preview, array $clip, ?int $expectedSettings, ?string $requesterId = null): VideoRender
    {
        $projectId = (string) $project->id;
        $planned = (int) $clip['settings']['revision'];
        $saved = MotoClipSourceService::settingsOf((array) ($project->metadata_json[MotoClipSourceService::INPUTS][$preview['source']['stage_id']][$shotId] ?? []), (array) $preview['plan']['execution_policy'], [])['revision'];

        if ($saved !== $planned || ($expectedSettings !== null && $expectedSettings !== $planned)) {
            throw new RuntimeException("Thiết lập clip của shot {$shotId} đã đổi (đã lưu revision {$saved}, trang đang giữ revision ".($expectedSettings ?? $planned).') — tải lại dữ liệu của shot; chưa có gì được gửi.');
        }

        if ($clip['render']['transport'] === MotoClipPlanBuilder::LOCAL) {
            return $this->dispatchLocal($project, $shotId, $controls, $expectedIntent, $operationId, $preview, $clip, $requesterId);
        }

        $entry = collect($preview['models'])->firstWhere('id', $controls['model_id']);

        if ($entry === null || $controls['model_id'] !== $clip['render']['model']) {
            throw new RuntimeException('Model '.$controls['model_id'].' không phải model của clip này.');
        }

        if ($controls['duration_seconds'] * 1000 !== $clip['render']['duration_ms'] || $controls['aspect_ratio'] !== $clip['render']['aspect_ratio'] || $controls['resolution'] !== $clip['render']['resolution']) {
            throw new RuntimeException('Thiết lập render khác bản đang xem — tải lại trang, chưa có gì được gửi.');
        }

        $problem = self::offerProblem((array) $preview['plan']['execution_policy'], $clip, $preview['models'], (string) ($preview['source']['context']['aspect_ratio'] ?? '')) ?? self::imagesProblem($clip);

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }

        $promptInput = is_string($preview['prompts'][$shotId]['input_format'] ?? null) ? $preview['prompts'][$shotId]['input_format'] : null;

        if (! $this->ffmpeg->run(['-version'])->successful) {
            throw new RuntimeException('Không chạy được ffmpeg để giải mã kiểm file clip khi nhận về — chưa gửi, để khỏi trả tiền cho một file không xác minh được. Đặt VIDEO_FFMPEG_BIN rồi thử lại.');
        }

        $references = $clip['input_mode'] === MotoClipPlanBuilder::REFERENCES;
        $input = $references ? ['task' => $clip['render']['task']] + $this->references($projectId, $clip) : null;

        if ($references) {
            $source = collect($input['references'])->first(static fn (array $reference): bool => $reference['artifact']->render_id !== null)['artifact'] ?? $input['references'][0]['artifact'];
        } else {
            $this->pinnedFile($projectId, (array) $clip['keyframe']['master'], 'Ảnh master', $shotId);
            $source = $this->pinnedFile($projectId, (array) $clip['keyframe']['delivery'], 'Ảnh delivery', $shotId);
        }

        $row = collect($preview['source']['plan']['shots'])->firstWhere('id', $shotId);
        $shot = $this->shot($this->factory->sessionFor($projectId), $preview['source'], $row, $clip, $controls['duration_seconds'], $expectedIntent);
        $stage = $this->plans->save($projectId, $preview);

        return $this->clips->create($shot, $source, (string) $entry['id'], [
            'resolution' => $controls['resolution'],
            'aspect_ratio' => $controls['aspect_ratio'],
            'duration_seconds' => $controls['duration_seconds'],
        ], (int) $shot->intent_version, $operationId, self::executionRequest($projectId, $operationId, $preview['plan'], $clip, $entry, (string) $stage->id, $preview['audio'], $promptInput), $input, $references ? null : ['task' => $clip['render']['task']]);
    }

    /**
     * The local processor (spec §16 step 8): a clip of transport MotoClipPlanBuilder::LOCAL is made on this machine from the master of its keyframe set and its crop path, never through a provider. Its request is executionRequest() with no registry entry plus what MotoDynamicCropService freezes (the master, the processor, the output, the filter graph and the samples); its file is checked by VideoRenderExecutionService::storeCheckedFile() against the media policy it froze.
     *
     * @param  array{model_id: string, resolution: string, aspect_ratio: string, duration_seconds: int}  $controls  model_id is the processor of the clip
     * @param  array<string, mixed>  $preview  the execution plan built now, under the lock of the project
     * @param  array<string, mixed>  $clip  its clip of the shot, not blocked and of the hash the page shows
     * @return VideoRender the queued row of the crop, a candidate when it finishes; nothing is rendered inside the transaction
     *
     * @throws RuntimeException when the settings sent are not the clip's, the choice is no longer an offer of the policy, the clip is not one the processor makes, a window of its path cannot be sampled, the master changed, or the machine cannot make it now; nothing was made
     */
    private function dispatchLocal(VideoProject $project, string $shotId, array $controls, int $expectedIntent, string $operationId, array $preview, array $clip, ?string $requesterId): VideoRender
    {
        $projectId = (string) $project->id;
        $execution = (array) $preview['plan']['execution_policy'];

        if ($controls['model_id'] !== $clip['render']['processor'] || $controls['duration_seconds'] * 1000 !== $clip['render']['duration_ms'] || $controls['aspect_ratio'] !== $clip['render']['aspect_ratio'] || $controls['resolution'] !== $clip['render']['resolution']) {
            throw new RuntimeException('Thiết lập render khác bản đang xem — clip Dynamic Crop do bộ xử lý '.$clip['render']['processor'].' tạo ở '.$clip['render']['resolution'].'; tải lại trang, chưa có gì được tạo.');
        }

        $problem = self::offerProblem($execution, $clip, $preview['models'], (string) ($preview['source']['context']['aspect_ratio'] ?? '')) ?? MotoDynamicCropService::clipProblem($clip, $execution) ?? $this->crops->problem();

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }

        $master = $this->pinnedFile($projectId, (array) $clip['keyframe']['master'], 'Ảnh master', $shotId);
        $row = collect($preview['source']['plan']['shots'])->firstWhere('id', $shotId);
        $shot = $this->shot($this->factory->sessionFor($projectId), $preview['source'], $row, $clip, $controls['duration_seconds'], $expectedIntent);
        $stage = $this->plans->save($projectId, $preview);

        return $this->crops->create($shot, $master, (int) $shot->intent_version, $operationId, self::executionRequest($projectId, $operationId, $preview['plan'], $clip, null, (string) $stage->id, $preview['audio']), $clip, $execution, $requesterId);
    }

    /**
     * @param  array{artifact_id: string, sha256: string}  $pin  a file of the keyframe set as the clip pinned it
     * @return VideoArtifact the artifact of the project with that hash, whose file on its disk still holds those bytes
     *
     * @throws RuntimeException when the artifact, its recorded hash or its file is no longer the one pinned
     */
    private function pinnedFile(string $projectId, array $pin, string $what, string $shotId): VideoArtifact
    {
        $artifact = $this->artifacts->findOwnedArtifact($projectId, (string) ($pin['artifact_id'] ?? ''));
        $disk = $artifact === null || ! in_array((string) $artifact->storage_disk, (array) config('video.veo.source_disks', ['video_artifacts']), true) ? null : $this->storage->disk((string) $artifact->storage_disk);
        $stream = $disk === null || ! $disk->exists((string) $artifact->storage_path) ? null : $disk->readStream((string) $artifact->storage_path);
        $hash = null;

        if (is_resource($stream)) {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            fclose($stream);
            $hash = hash_final($context);
        }

        if ($artifact === null || $hash === null || ! hash_equals((string) ($pin['sha256'] ?? ''), (string) $artifact->sha256) || ! hash_equals((string) $artifact->sha256, $hash)) {
            throw new RuntimeException("{$what} của bộ keyframe shot {$shotId} không còn là file đã ghim — tải lại trang, chưa có gì được gửi.");
        }

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $execution  the execution policy the plan pinned
     * @param  array<string, mixed>  $clip  a clip of the execution plan
     * @param  list<array<string, mixed>>  $models  the registry models the policy offers now
     * @return ?string why the way of moving, the input, the model, the resolution, the length of file and the audio rule of the clip are not, together, an available offer of the policy now; null when they are
     */
    public static function offerProblem(array $execution, array $clip, array $models, string $aspect): ?string
    {
        $subject = 'Shot '.$clip['shot_id'];
        $checked = MotoClipPlanBuilder::capability($execution, [
            'motion_method' => $clip['motion_method'],
            'input_mode' => $clip['input_mode'],
            'model' => $clip['render']['model'],
            'resolution' => $clip['render']['resolution'],
            'audio_policy' => $clip['audio_policy'],
        ], $models, $aspect, $subject);

        return match (true) {
            $checked['blocked'] !== null => (string) $checked['blocked']['message'],
            CanonicalJson::hash($checked['render']) !== CanonicalJson::hash((array) $clip['render']) || $checked['audio_policy'] !== $clip['audio_policy'] => $subject.': cách làm, model, resolution và thời lượng file của clip không còn là một lựa chọn của chính sách thực thi — tải lại trang, chưa có gì được gửi.',
            ! is_int($clip['render']['duration_ms']) || $clip['render']['duration_ms'] % 1000 !== 0 => $subject.': thời lượng file '.json_encode($clip['render']['duration_ms']).' ms không phải số giây nguyên model nhận — chưa có gì được gửi.',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $clip  a clip of the execution plan made by an AI video
     * @return ?string why the images of the clip are not sent by their roles: from a keyframe, exactly the delivery image of the approved set as the first frame; from references, only reference images, never a keyframe, in their order; null when they are
     */
    public static function imagesProblem(array $clip): ?string
    {
        $subject = 'Shot '.$clip['shot_id'];
        $images = array_values((array) $clip['images']);

        if ($clip['motion_method'] !== MotoContract::MOTION_AI_VIDEO) {
            return $subject.' không phải AI Video — clip này không gửi qua provider.';
        }

        if ($images !== [] && array_column($images, 'position') !== range(0, count($images) - 1)) {
            return $subject.': ảnh gửi đi không theo đúng thứ tự đã ghim — tải lại trang, chưa có gì được gửi.';
        }

        if ($clip['input_mode'] === MotoClipPlanBuilder::REFERENCES) {
            $wrong = array_values(array_filter($images, static fn (array $image): bool => ($image['send_as'] ?? null) !== 'reference' || ($image['kind'] ?? null) === 'keyframe'));

            return match (true) {
                $images === [] => $subject.' chưa có ảnh tham chiếu nào để gửi.',
                $clip['keyframe'] !== null || $wrong !== [] => $subject.': clip từ ảnh tham chiếu chỉ gửi ảnh tham chiếu — không gửi keyframe như một ảnh tham chiếu.',
                default => null,
            };
        }

        $pin = is_array($clip['keyframe'] ?? null) ? $clip['keyframe'] : null;
        $image = count($images) === 1 ? $images[0] : null;

        if ($pin === null || $image === null || ($image['send_as'] ?? null) !== 'first_frame' || ($image['kind'] ?? null) !== 'keyframe'
            || ($image['artifact_id'] ?? null) !== $pin['artifact_id'] || ($image['artifact_id'] ?? null) !== ($pin['delivery']['artifact_id'] ?? null)
            || ($image['sha256'] ?? null) !== $pin['sha256'] || ($image['sha256'] ?? null) !== ($pin['delivery']['sha256'] ?? null)) {
            return $subject.': clip mở từ keyframe gửi đúng một ảnh — ảnh delivery của bộ keyframe đã duyệt, làm khung mở đầu; không gửi keyframe như một ảnh tham chiếu.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $plan  the execution plan
     * @param  array<string, mixed>  $clip  its clip
     * @param  ?array<string, mixed>  $entry  the registry entry of the model, null for the local processor
     * @param  array{policy: string, prefix: string, closing: list<string>}  $audio  the sound policy of the prompt
     * @param  ?string  $promptInput  the input format the run of the approved prompt pinned (ClipPromptWriterService::current() gives it as input_format); null for a clip with no prompt
     * @return array<string, mixed> what the request of a clip of an execution plan freezes beside the prompt and the sent images, so its hash covers every pin: the contract, the hash of the execution policy, the settings revision, the way of moving and the input, the render (transport, task, model, adapter, processor, frame, resolution, length of file), the part kept, the keyframe set (image, decision, master, delivery, crop), the images with their roles and hashes, the crop path, the audio rule, the approved prompt, the capability of the model and the file checks; it says a finished render only becomes a candidate (ShotIntentService::SELECTION_REVIEW). The writer it records for the prompt is the check that read the run that wrote it (MotoClipPrompt::checkVersionFor() of that run's input), never the one the plan writes new runs to: a prompt approved under an earlier revision keeps the name of its own check
     *
     * @throws RuntimeException when the clip has a prompt and the input its run was written from is not given
     */
    public static function executionRequest(string $projectId, string $operationId, array $plan, array $clip, ?array $entry, string $planStageId, array $audio, ?string $promptInput = null): array
    {
        if ($clip['prompt'] !== null && ($promptInput === null || $promptInput === '')) {
            throw new RuntimeException('Shot '.$clip['shot_id'].': lượt viết prompt video đã duyệt không ghi đầu vào nó được viết theo — không xác định được phép kiểm đã nhận prompt; tải lại trang, chưa có gì được gửi.');
        }

        return [
            ShotIntentService::SELECTION_KEY => ShotIntentService::SELECTION_REVIEW,
            'moto_clip' => [
                'version' => self::REQUEST_VERSION_V3,
                'project_id' => $projectId,
                'operation_id' => $operationId,
                'shot_id' => $clip['shot_id'],
                'scene_id' => $clip['scene_id'],
                'order' => $clip['order'],
                'source' => $plan['source'],
                'plan_stage_id' => $planStageId,
                'clip_hash' => $clip['hash'],
                'contract' => $plan['contract'],
                'execution_policy' => ['version' => $clip['execution_policy'], 'hash' => CanonicalJson::hash((array) $plan['execution_policy'])],
                'settings' => $clip['settings'],
                'motion_method' => $clip['motion_method'],
                'input_mode' => $clip['input_mode'],
                'content_duration_ms' => $clip['content_duration_ms'],
                'render' => $clip['render'],
                'used' => $clip['used'],
                'keyframe' => $clip['keyframe'],
                'images' => $clip['images'],
                'input' => ['mode' => $clip['input_mode'], 'images' => $clip['images']],
                'crop_plan' => $clip['crop_plan'],
                'audio_policy' => $clip['audio_policy'],
                'prompt' => $clip['prompt'] === null ? null : ['version' => $clip['prompt']['version'], 'sha256' => $clip['prompt']['sha256'], 'writer' => MotoClipPrompt::checkVersionFor($promptInput), 'stage_id' => $clip['prompt']['stage_id'], 'decision_id' => $clip['prompt']['decision_id'], 'source_fingerprint' => $clip['prompt']['source_fingerprint']],
                'capability' => [
                    'model_id' => $entry['id'] ?? null,
                    'adapter' => $clip['render']['adapter'],
                    'processor' => $clip['render']['processor'],
                    'method' => $entry['method'] ?? $clip['render']['transport'],
                    'api_version' => $entry['api_version'] ?? null,
                    'controls' => $entry['controls'] ?? null,
                ],
                'audio' => $audio,
            ],
            'media_policy' => $clip['media_policy'],
        ];
    }

    /**
     * @param  array{model_id: string, resolution: string, aspect_ratio: string, duration_seconds: int}  $controls
     * @return ?VideoRender the attempt this operation already made
     *
     * @throws RuntimeException when the operation was used for something else
     */
    private function replayed(string $projectId, string $shotId, array $controls, string $operationId, string $expectedHash): ?VideoRender
    {
        $event = $this->events->findOperationForProject($projectId, $operationId);

        if ($event === null) {
            return null;
        }

        $render = $event->event_type === 'shot_render_dispatched'
            ? $this->renders->findRender($event->operation_result_json['render_id'] ?? null)
            : null;
        $request = $render === null ? [] : (array) json_decode((string) $render->render_request_json, true);
        $moto = (array) ($request['moto_clip'] ?? []);

        if (($moto['shot_id'] ?? null) !== $shotId || ($moto['clip_hash'] ?? null) !== $expectedHash
            || ($moto['capability']['model_id'] ?? $moto['capability']['processor'] ?? null) !== $controls['model_id'] || ($request['resolution'] ?? null) !== $controls['resolution']
            || ($request['aspect_ratio'] ?? null) !== $controls['aspect_ratio'] || ($request['duration_seconds'] ?? null) !== $controls['duration_seconds']) {
            throw new RuntimeException('Mã thao tác này đã dùng cho một yêu cầu khác — tải lại trang.');
        }

        return $render;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $clip
     * @return VideoShot the shot of this revision of the plan, made here at the first dispatch and never when a page opens, with the prompt about to be sent
     *
     * @throws RuntimeException when another click changed the shot or an attempt is still on its way
     */
    private function shot(VideoSession $session, array $source, array $row, array $clip, int $seconds, int $expectedIntent): VideoShot
    {
        $shot = $this->shots->lockShotOfSession($session->id, $source['revision'], $row['id'], SceneShotFactory::KIND);

        // The row is found by its unique key; it is this shot only when its own metadata names the shot plan in force.
        if ($shot !== null &&($shot->metadata_json['moto_clip']['shot_plan_stage_id'] ?? null) !== $source['stage_id']) {
            throw new RuntimeException("Shot {$row['id']} ở revision này đang gắn với một kế hoạch shot khác — không ghi đè, chưa có gì được gửi.");
        }

        if ((int) ($shot?->intent_version ?? 0) !== $expectedIntent) {
            throw new RuntimeException('Clip đã thay đổi ở thao tác khác. Tải lại trang trước khi render.');
        }

        $inputs = [
            'compiled_prompt' => (string) ($clip['prompt']['text'] ?? ''),
            'spec_json' => SceneShotFactory::specOf(['title' => $row['id'], 'purpose' => $row['purpose'] ?? null, 'motion' => $row['action'] ?? null, 'camera' => $row['camera'] ?? null, 'duration_seconds' => $seconds]),
            'metadata_json' => ['moto_clip' => ['project_id' => $session->project_id, 'shot_plan_stage_id' => $source['stage_id'], 'shot_id' => $row['id'], 'scene_id' => $row['scene'], 'token' => $source['token']]],
            'shot_index' => (int) $clip['order'],
        ];

        if ($shot === null) {
            return $this->shots->refreshShot($this->shots->createShot($inputs + [
                'session_id' => $session->id,
                'beat' => mb_substr((string) $row['id'], 0, 40),
                'shot_code' => (string) $row['id'],
                'shot_type' => self::SHOT_TYPE,
                'kind' => SceneShotFactory::KIND,
                'plan_revision' => $source['revision'],
                'scene_status' => 'keyframe_ready',
            ]));
        }

        $this->releaseAttempts($shot);

        return $this->intents->replaceInputs($shot, $inputs);
    }

    /**
     * @param  bool  $receipts  whether a submission receipt of the attempt exists
     * @return ?string what a new render of the shot does with the attempt — the one rule the dispatch (releaseAttempts()) and the Render video screen share: null when the attempt is over and left alone; RETIRE_CLOSE for a row of the local processor that left the queue, has no receipt and whose lease ran out or was dropped; RETIRE_CANCEL for a row that never left this machine and has no receipt; RETIRE_WAIT for any other, which a provider may hold or a worker still makes
     */
    public static function retirement(VideoRender $render, bool $receipts): ?string
    {
        $unsent = in_array($render->execution_status, self::UNSENT, true);

        return match (true) {
            in_array($render->execution_status, self::SETTLED, true) => null,
            $render->provider === MotoClipPlanBuilder::LOCAL && ! $receipts && ! $unsent && ($render->lease_expires_at === null || $render->lease_expires_at->lessThanOrEqualTo(now())) => self::RETIRE_CLOSE,
            $unsent && ! $receipts => self::RETIRE_CANCEL,
            default => self::RETIRE_WAIT,
        };
    }

    /** @return ?string retirement() of the attempt with its submission receipts read now; reading it changes nothing */
    public function retirementOf(VideoRender $render): ?string
    {
        return self::retirement($render, ! in_array($render->execution_status, self::SETTLED, true) && $this->receipts->existsForRender($render->id));
    }

    /**
     * A row that never left the machine, left by a process that died before its submit, is cancelled so the click that replaces it can go, and so is a row of the local processor whose worker stopped holding it (its lease ran out or was dropped): it sent nothing anywhere. Nothing else is retired here: a job the provider may have taken blocks a new one until it is settled. Which of the three applies is retirement().
     *
     * @throws RuntimeException when an attempt is on its way or its outcome is unknown
     */
    private function releaseAttempts(VideoShot $shot): void
    {
        $open = $this->renders->lockOpenProductionRendersOfShot($shot->id, self::SETTLED);

        foreach ($open as $render) {
            $how = $this->retirementOf($render);

            if ($how === null) {
                continue;
            }

            if ($how === self::RETIRE_CLOSE) {
                $this->crops->close($render, 'không còn worker nào giữ lượt này (đang ở '.$render->execution_status->value.'); đóng để tạo lượt mới.');

                continue;
            }

            if ($how === self::RETIRE_WAIT) {
                throw new RuntimeException($render->execution_status === RenderStatus::PROVIDER_UNKNOWN
                    ? 'Lượt trước chưa rõ provider đã nhận hay chưa — không gửi lại để tránh trả tiền hai lần. Bấm Kiểm tra lại, hoặc đối soát rồi đóng lượt đó.'
                    : 'Lượt trước của shot này đang chạy — chờ kết quả rồi hãy gửi lượt mới.');
            }

            $this->renders->saveRender($render, [
                'execution_status' => RenderStatus::CANCELLED,
                'failure_code' => 'replaced_before_submit',
                'failure_message' => 'Lượt này chưa gửi đi và được thay bằng một lượt mới.',
                'execution_completed_at' => now(),
                'execution_version' => (int) $render->execution_version + 1,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $clip
     * @param  array<string, mixed>  $entry  the model as the registry describes it now
     * @param  array{policy: string, prefix: string, closing: list<string>}  $audio  the sound policy every approved video prompt follows in its own sound line
     * @return array<string, mixed> what the request freezes beside the prompt and the sent image: where the clip comes from and the parameters its execution and file checks read, so a later registry or config change cannot touch a running attempt. The file checks are the very ones the plan pinned and the page hash covered, never read again from config
     */
    private function frozen(string $projectId, string $operationId, array $plan, array $clip, array $entry, string $planStageId, array $audio): array
    {
        $interactions = ($entry['method'] ?? null) === VideoModelRegistry::INTERACTIONS;

        return [
            'moto_clip' => [
                'version' => self::REQUEST_VERSION,
                'project_id' => $projectId,
                'operation_id' => $operationId,
                'shot_id' => $clip['shot_id'],
                'scene_id' => $clip['scene_id'],
                'order' => $clip['order'],
                'source' => $plan['source'],
                'plan_stage_id' => $planStageId,
                'clip_hash' => $clip['hash'],
                'prompt' => ['version' => $clip['prompt']['version'], 'sha256' => $clip['prompt']['sha256'], 'writer' => $plan['contract']['writer'], 'stage_id' => $clip['prompt']['stage_id'], 'decision_id' => $clip['prompt']['decision_id'], 'source_fingerprint' => $clip['prompt']['source_fingerprint']],
                'contract' => $plan['contract'],
                'input' => ['mode' => $clip['input_mode'], 'images' => $clip['images']],
                'keyframe' => $clip['keyframe'],
                'capability' => [
                    'model_id' => $entry['id'],
                    'adapter' => $interactions ? GeminiOmniVideoClient::PAYLOAD_VERSION : SceneClipDispatchService::PROVIDER_PAYLOAD_VERSION,
                    'method' => $entry['method'],
                    'api_version' => $entry['api_version'],
                    'controls' => $entry['controls'],
                ],
                'audio' => $audio,
                'used' => $clip['used'],
            ],
            'media_policy' => $clip['media_policy'],
        ];
    }
}
