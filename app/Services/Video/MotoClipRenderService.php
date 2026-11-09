<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Models\VideoArtifact;
use App\Models\VideoProject;
use App\Models\VideoProviderSubmissionReceipt;
use App\Models\VideoRender;
use App\Models\VideoSession;
use App\Models\VideoSessionEvent;
use App\Models\VideoShot;
use App\Video\Media\GeminiOmniVideoClient;
use App\Video\Media\VideoModelRegistry;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\Video\SceneClipDispatchService;
use App\Video\Render\Video\SceneShotFactory;
use App\Video\Render\Video\VideoRenderExecutionService;
use App\Video\Render\VideoProviderCheckpointService;
use App\Video\Scene\Services\ShotIntentService;
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

    public const REQUEST_VERSION = 'moto-clip-request-v1';

    /** @var string what a refusal says when what the page shows is no longer what would be sent */
    public const RELOAD = 'Prompt, model, keyframe hoặc nguồn đã đổi so với bản đang xem — tải lại trang để xem bản mới, chưa có gì được gửi.';

    /** @var list<RenderStatus> the statuses of an attempt that never reached the provider, so replacing it costs nothing */
    private const UNSENT = [RenderStatus::QUEUED, RenderStatus::RETRY_WAIT];

    public function __construct(
        private readonly MotoClipPlanService $plans,
        private readonly SceneShotFactory $factory,
        private readonly ShotIntentService $intents,
        private readonly SceneClipDispatchService $clips,
        private readonly VideoRenderExecutionService $execution,
        private readonly VideoProviderCheckpointService $checkpoints,
    ) {}

    /**
     * @param  array{model_id: string, resolution: string, aspect_ratio: string, duration_seconds: int}  $controls
     * @param  string  $expectedHash  the hash of the clip the page shows
     * @return array{0: VideoRender, 1: bool, 2: string} the attempt with how its submit went: the same operation sent again returns its own attempt and is never sent twice
     *
     * @throws RuntimeException when the clip cannot be sent; nothing was charged
     */
    public function render(string $projectId, string $shotId, array $controls, int $expectedIntent, string $operationId, string $expectedHash): array
    {
        if (! Str::isUuid($operationId)) {
            throw new RuntimeException('Mã thao tác render không hợp lệ — tải lại trang.');
        }

        $render = DB::transaction(fn (): VideoRender => $this->dispatch($projectId, $shotId, $controls, $expectedIntent, $operationId, $expectedHash), attempts: 3);
        [$ok, $reason] = $this->execution->submit((string) $render->id);

        return [$render->refresh(), $ok, $reason];
    }

    /**
     * @return array{0: bool, 1: string} one round of following an attempt, the same from the screen and from the background: a worker that died holding it is released, then the provider is asked once. It only ever reads what was sent and never sends again
     */
    public function track(string $renderId): array
    {
        $render = VideoRender::query()->whereKey($renderId)->firstOrFail();
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
            $render = VideoRender::query()->whereKey($renderId)->lockForUpdate()->firstOrFail();

            if ($render->execution_status !== RenderStatus::PROVIDER_UNKNOWN) {
                throw new RuntimeException('Chỉ đóng được lượt chưa rõ kết quả; lượt này đang ở '.$render->execution_status?->value.'.');
            }

            if ($this->checkpoints->knownJobId($render) !== null) {
                throw new RuntimeException('Lượt này có job đã biết — để vòng theo dõi đọc kết quả, không đóng tay.');
            }

            $render->forceFill([
                'execution_status' => RenderStatus::FAILED,
                'failure_code' => 'abandoned_after_reconcile',
                'failure_message' => 'Đóng tay sau khi đối soát: '.$note,
                'execution_completed_at' => now(),
                'execution_version' => (int) $render->execution_version + 1,
            ])->save();

            return $render;
        }, attempts: 3);
    }

    /** @param  array{model_id: string, resolution: string, aspect_ratio: string, duration_seconds: int}  $controls */
    private function dispatch(string $projectId, string $shotId, array $controls, int $expectedIntent, string $operationId, string $expectedHash): VideoRender
    {
        VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
        $replayed = $this->replayed($projectId, $shotId, $controls, $operationId, $expectedHash);

        if ($replayed !== null) {
            return $replayed;
        }

        $preview = $this->plans->preview($projectId);
        $clip = collect($preview['plan']['clips'])->firstWhere('shot_id', $shotId) ?? throw new RuntimeException("Shot {$shotId} không thuộc kế hoạch shot đã duyệt.");

        if (! hash_equals((string) $clip['hash'], $expectedHash)) {
            throw new RuntimeException(self::RELOAD);
        }

        if ($clip['blocked'] !== null) {
            throw new RuntimeException((string) $clip['blocked']['message']);
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
        $artifact = VideoArtifact::query()->whereKey($clip['keyframe']['artifact_id'])->firstOrFail();
        $stage = $this->plans->save($projectId, $preview);

        return $this->clips->create($shot, $artifact, (string) $entry['id'], [
            'resolution' => $controls['resolution'],
            'aspect_ratio' => $controls['aspect_ratio'],
            'duration_seconds' => $controls['duration_seconds'],
        ], (int) $shot->intent_version, $operationId, $this->frozen($projectId, $operationId, $preview['plan'], $clip, $entry, (string) $stage->id, $preview['audio']));
    }

    /**
     * @param  array{model_id: string, resolution: string, aspect_ratio: string, duration_seconds: int}  $controls
     * @return ?VideoRender the attempt this operation already made
     *
     * @throws RuntimeException when the operation was used for something else
     */
    private function replayed(string $projectId, string $shotId, array $controls, string $operationId, string $expectedHash): ?VideoRender
    {
        $event = VideoSessionEvent::query()
            ->whereIn('session_id', VideoSession::query()->where('project_id', $projectId)->select('id'))
            ->where('operation_id', $operationId)
            ->first();

        if ($event === null) {
            return null;
        }

        $render = $event->event_type === 'shot_render_dispatched'
            ? VideoRender::query()->whereKey($event->operation_result_json['render_id'] ?? null)->first()
            : null;
        $request = $render === null ? [] : (array) json_decode((string) $render->render_request_json, true);
        $moto = (array) ($request['moto_clip'] ?? []);

        if (($moto['shot_id'] ?? null) !== $shotId || ($moto['clip_hash'] ?? null) !== $expectedHash
            || ($moto['capability']['model_id'] ?? null) !== $controls['model_id'] || ($request['resolution'] ?? null) !== $controls['resolution']
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
        $shot = VideoShot::query()
            ->where('session_id', $session->id)
            ->where('plan_revision', $source['revision'])
            ->where('shot_code', $row['id'])
            ->where('kind', SceneShotFactory::KIND)
            ->lockForUpdate()
            ->first();

        // The row is found by its unique key; it is this shot only when its own metadata names the shot plan in force.
        if ($shot !== null &&($shot->metadata_json['moto_clip']['shot_plan_stage_id'] ?? null) !== $source['stage_id']) {
            throw new RuntimeException("Shot {$row['id']} ở revision này đang gắn với một kế hoạch shot khác — không ghi đè, chưa có gì được gửi.");
        }

        if ((int) ($shot?->intent_version ?? 0) !== $expectedIntent) {
            throw new RuntimeException('Clip đã thay đổi ở thao tác khác. Tải lại trang trước khi render.');
        }

        $inputs = [
            'compiled_prompt' => (string) $clip['prompt']['text'],
            'spec_json' => SceneShotFactory::specOf(['title' => $row['id'], 'purpose' => $row['purpose'] ?? null, 'motion' => $row['action'] ?? null, 'camera' => $row['camera'] ?? null, 'duration_seconds' => $seconds]),
            'metadata_json' => ['moto_clip' => ['project_id' => $session->project_id, 'shot_plan_stage_id' => $source['stage_id'], 'shot_id' => $row['id'], 'scene_id' => $row['scene'], 'token' => $source['token']]],
            'shot_index' => (int) $clip['order'],
        ];

        if ($shot === null) {
            return VideoShot::create($inputs + [
                'session_id' => $session->id,
                'beat' => mb_substr((string) $row['id'], 0, 40),
                'shot_code' => (string) $row['id'],
                'shot_type' => self::SHOT_TYPE,
                'kind' => SceneShotFactory::KIND,
                'plan_revision' => $source['revision'],
                'scene_status' => 'keyframe_ready',
            ])->refresh();
        }

        $this->releaseAttempts($shot);

        return $this->intents->replaceInputs($shot, $inputs);
    }

    /**
     * A row that never left the machine, left by a process that died before its submit, is cancelled so the click that replaces it can go. Nothing else is retired here: a job the provider may have taken blocks a new one until it is settled.
     *
     * @throws RuntimeException when an attempt is on its way or its outcome is unknown
     */
    private function releaseAttempts(VideoShot $shot): void
    {
        $open = VideoRender::query()
            ->where('shot_id', $shot->id)
            ->where('execution_purpose', SceneClipDispatchService::PURPOSE_PRODUCTION)
            ->whereNotIn('execution_status', [RenderStatus::SUCCEEDED->value, RenderStatus::FAILED->value, RenderStatus::CANCELLED->value])
            ->lockForUpdate()
            ->get();

        foreach ($open as $render) {
            $receipts = VideoProviderSubmissionReceipt::query()->where('render_id', $render->id)->exists();

            if (! in_array($render->execution_status, self::UNSENT, true) || $receipts) {
                throw new RuntimeException($render->execution_status === RenderStatus::PROVIDER_UNKNOWN
                    ? 'Lượt trước chưa rõ provider đã nhận hay chưa — không gửi lại để tránh trả tiền hai lần. Bấm Kiểm tra lại, hoặc đối soát rồi đóng lượt đó.'
                    : 'Lượt trước của shot này đang chạy — chờ kết quả rồi hãy gửi lượt mới.');
            }

            $render->forceFill([
                'execution_status' => RenderStatus::CANCELLED,
                'failure_code' => 'replaced_before_submit',
                'failure_message' => 'Lượt này chưa gửi đi và được thay bằng một lượt mới.',
                'execution_completed_at' => now(),
                'execution_version' => (int) $render->execution_version + 1,
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $clip
     * @param  array<string, mixed>  $entry  the model as the registry describes it now
     * @param  array{policy: string, line: string}  $audio  the sound policy and the line of the template that asks for it, checked to agree
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
                'prompt' => ['version' => $clip['prompt']['version'], 'sha256' => $clip['prompt']['sha256'], 'compiler' => $plan['contract']['compiler']],
                'contract' => $plan['contract'],
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
