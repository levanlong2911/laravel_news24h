<?php

namespace App\Video\Render\Video;

use App\Models\VideoProviderSubmissionReceipt;
use App\Models\VideoRender;
use App\Models\VideoRenderAttempt;
use App\Video\Media\GeminiVeoVideoClient;
use App\Video\Media\Mp4Probe;
use App\Video\Concept\Support\Clock;
use App\Video\Media\VideoModelRegistry;
use App\Video\Render\Attempts\RenderAttemptService;
use App\Video\Render\Claims\RenderClaimService;
use App\Video\Render\Claims\RenderLeaseService;
use App\Video\Render\DTO\RenderAttemptUsage;
use App\Video\Render\Enums\RenderFailureClass;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\RenderCheckpointService;
use App\Video\Render\RenderFailureService;
use App\Video\Render\VideoProviderCheckpointService;
use App\Video\Scene\Services\VideoShotCheckpointService;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Hai loi vao thu cong: submit gui mot lan roi tra trang, poll hoi mot lan.
 * Khong ngu, khong vong lap, khong tu gui lai khi ket qua mo ho.
 *
 * Service nay KHONG tu quan ly trang thai: no goi lai dung chuoi checkpoint da co
 * (claim -> preparing -> submitting -> provider_running -> polling ->
 * artifact_writing -> checkpointing -> succeeded).
 */
final class VideoRenderExecutionService
{
    public const WORKER = 'laravel:direct';

    public function __construct(
        private readonly RenderClaimService $claims,
        private readonly RenderAttemptService $attempts,
        private readonly VideoProviderCheckpointService $provider,
        private readonly RenderCheckpointService $checkpoint,
        private readonly RenderFailureService $failures,
        private readonly VideoShotCheckpointService $shots,
        private readonly VideoModelRegistry $registry,
        private readonly GeminiVeoVideoClient $veo,
        private readonly Mp4Probe $probe,
        private readonly FilesystemFactory $storage,
        private readonly RenderLeaseService $lease,
        private readonly Clock $clock,
    ) {}

    /** @return array{0: bool, 1: string} [$ok, $reason] */
    public function submit(string $renderId): array
    {
        // Thieu binary la loi may, khong duoc dot mot attempt vi no.
        if (! $this->probe->available()) {
            return [false, 'ffprobe_missing'];
        }

        $claim = $this->claims->claimById($renderId, self::WORKER);

        if ($claim === null) {
            return [false, 'not_claimable'];
        }

        $render = VideoRender::query()->whereKey($renderId)->firstOrFail();
        $spec = json_decode($claim->renderRequestJson, true);

        if (! is_array($spec)) {
            return $this->failOrLose($renderId, $claim->claimToken,
                RenderFailureClass::INVALID_REQUEST, 'bad_request_json', 'render_request_json hong.');
        }

        $entry = $render->render_kind !== 'video'
            ? null
            : $this->registry->find(
                SceneClipDispatchService::TASK,
                ($spec['provider'] ?? '').':'.($spec['model'] ?? ''),
            );

        $blocked = match (true) {
            $render->render_kind !== 'video' => 'render nay khong phai video',
            $entry === null => 'model ngoai registry',
            ! hash_equals($claim->requestHash, hash('sha256', $claim->renderRequestJson)) => 'request hash lech',
            default => null,
        };

        if ($blocked !== null) {
            return $this->failOrLose($renderId, $claim->claimToken,
                RenderFailureClass::INVALID_REQUEST, 'precondition_failed', $blocked);
        }

        $source = $this->sourceImage($spec);

        if (! $source['ok']) {
            return $this->failOrLose($renderId, $claim->claimToken,
                RenderFailureClass::ARTIFACT_INTEGRITY, 'source_artifact_invalid', (string) $source['error']);
        }

        try {
            $this->attempts->markPreparing($renderId, $claim->claimToken);
            $this->attempts->markSubmitting($renderId, $claim->claimToken);
        } catch (Throwable) {
            return [false, 'lease_lost_before_submit'];
        }

        $result = $this->veo->submit($entry, $this->payload($spec, $source));

        if ($result['ambiguous']) {
            try {
                $this->attempts->markAmbiguous($renderId, $claim->claimToken,
                    'provider_unknown', (string) $result['error']);
            } catch (Throwable) {
                return [false, 'lease_lost_before_ambiguous'];
            }

            return [false, 'provider_unknown'];
        }

        if (! $result['ok']) {
            return $this->failOrLose($renderId, $claim->claimToken,
                $result['failure'], 'submit_failed', (string) $result['error']);
        }

        // Provider da nhan job. Tu day tro di khong duoc de bat ky loi nao lam mat
        // operation id: mat no la mat duong poll toi thu da tra tien.
        if (! $this->keepJobId($render, $claim->claimToken, $result)) {
            return [false, 'ownership_lost_after_submit'];
        }

        try {
            $this->lease->heartbeat($renderId, $claim->claimToken, self::WORKER);
            $this->provider->markProviderRunning($render, $claim->claimToken,
                (string) $result['job_id'], $result['request_id'], $result['response']);
        } catch (Throwable $e) {
            return [false, 'checkpoint_failed_after_submit'];
        }

        return [true, 'provider_running'];
    }

    /** @return array{0: bool, 1: string} [$ok, $reason] */
    public function poll(string $renderId): array
    {
        $render = VideoRender::query()->whereKey($renderId)->firstOrFail();

        if ($render->execution_status !== RenderStatus::PROVIDER_RUNNING) {
            // Job da gui thanh cong nhung checkpoint hong thi render ket o submitting
            // hoac provider_unknown. Vot no lai bang chinh job id da giu.
            $render = $this->provider->adoptKnownJob($render);

            if ($render === null) {
                return [false, 'not_running'];
            }
        }

        $spec = (array) json_decode((string) $render->render_request_json, true);

        $entry = $this->registry->find(
            SceneClipDispatchService::TASK,
            ($spec['provider'] ?? '').':'.($spec['model'] ?? ''),
        );

        if ($entry === null) {
            return [false, 'model_gone_from_registry'];
        }

        $token = (string) Str::uuid();
        $render = $this->provider->claimPoll($render, $token, self::WORKER);

        $poll = $this->veo->poll($entry, (string) $render->provider_job_id);

        if (! $poll['ok'] && $poll['failure'] === RenderFailureClass::TRANSIENT_NETWORK) {
            $this->provider->markStillRunning($render, $token, $poll['response']);

            return [false, 'poll_retry_later'];
        }

        if (! $poll['ok']) {
            return $this->failOrLose($render->id, $token, $poll['failure'], 'poll_failed', (string) $poll['error']);
        }

        if (! $poll['done']) {
            $this->provider->markStillRunning($render, $token, $poll['response']);

            return [true, 'provider_running'];
        }

        if ($poll['video_uri'] === null) {
            return $this->failOrLose($render->id, $token, RenderFailureClass::UNSUPPORTED_CAPABILITY,
                'no_video_sample', 'operation xong nhung khong co video nao trong response.');
        }

        return $this->writeArtifact($render, $token, $spec, (string) $poll['video_uri'], $poll['response']);
    }

    /**
     * Danh that bai cung can lease. Mat lease giua chung thi khong duoc dong vao
     * hang nua — bao mat quyen, de nguoi dang giu no quyet dinh.
     *
     * @return array{0: bool, 1: string}
     */
    private function failOrLose(
        string $renderId,
        string $token,
        RenderFailureClass $class,
        string $reason,
        string $message,
    ): array {
        try {
            $this->failures->fail($renderId, $token, $class, $reason, $message, self::WORKER);
        } catch (Throwable) {
            return [false, 'lease_lost_before_failure'];
        }

        return [false, $reason];
    }

    private function renew(string $renderId, string $token): bool
    {
        try {
            $this->lease->heartbeat($renderId, $token, self::WORKER);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Bien lai duoc ghi TRUOC khi dong vao bat ky trang thai nao, va khong phu thuoc
     * lease: provider da nhan job thi khoan tien do ton tai du ta con quyen ghi hay
     * khong. CAS ben duoi chi la duong nhanh cap nhat trang thai hien tai.
     *
     * @param  array<string, mixed>  $result
     */
    private function keepJobId(VideoRender $render, string $claimToken, array $result): bool
    {
        if (! $this->recordReceipt($render, $claimToken, $result)) {
            // Provider da nhan job ma ta khong ghi noi bang chung. Khong duoc di tiep
            // nhu the da ghi: dung lai va noi dung ten van de.
            Log::error('Khong ghi duoc bien lai submit — job da tra tien khong co bang chung ben', [
                'render_id' => $render->id,
                'attempt_no' => $render->attempt_count,
                'claim_generation' => $render->claim_generation,
                'provider_job_id' => $result['job_id'],
            ]);

            return false;
        }

        $written = VideoRender::query()
            ->whereKey($render->id)
            ->where('claim_token', $claimToken)
            ->where('claimed_by', self::WORKER)
            ->where('lease_expires_at', '>', $this->clock->now())
            ->where('execution_status', RenderStatus::SUBMITTING->value)
            ->update([
                'provider_job_id' => (string) $result['job_id'],
                'provider_submit_response_json' => json_encode($result['response'], JSON_UNESCAPED_UNICODE),
            ]);

        if ($written === 1) {
            return true;
        }

        // Mat quyen ghi trang thai — nhung bien lai o tren da ghi roi, nen job khong
        // mat. Attempt van duoc va them mot lan neu no con la cua minh, de nguoi doc
        // hang thay ngay ma khong phai tra cuu bien lai.
        VideoRenderAttempt::query()
            ->where('render_id', $render->id)
            ->where('attempt_no', $render->attempt_count)
            ->where('claim_token', $claimToken)
            ->whereNull('provider_job_id')
            ->update([
                'provider_job_id' => (string) $result['job_id'],
                'provider_submit_response_json' => json_encode($result['response'], JSON_UNESCAPED_UNICODE),
            ]);

        Log::warning('Veo submit thanh cong nhung mat quyen ghi trang thai', [
            'render_id' => $render->id,
            'attempt_no' => $render->attempt_count,
            'claim_generation' => $render->claim_generation,
            'provider_job_id' => $result['job_id'],
        ]);

        return false;
    }

    /**
     * Idempotent theo cap (attempt_id, provider_job_id): gui lai cung mot job cho
     * cung mot attempt khong duoc de ra hai bien lai.
     *
     * @param  array<string, mixed>  $result
     */
    private function recordReceipt(VideoRender $render, string $claimToken, array $result): bool
    {
        // Bien lai phai gan vao dung luot da tra tien, nen attempt phai khop ca
        // claim_token lan generation — khong duoc lay bua attempt cung so thu tu.
        $attempt = VideoRenderAttempt::query()
            ->where('render_id', $render->id)
            ->where('attempt_no', $render->attempt_count)
            ->where('claim_token', $claimToken)
            ->where('claim_generation', $render->claim_generation)
            ->first();

        if ($attempt === null) {
            return false;
        }

        $jobId = (string) $result['job_id'];

        // `insertOrIgnore` nuot MOI loi chu khong rieng trung khoa, nen khong duoc
        // coi no la da ghi: phai doc lai moi biet bien lai co that hay khong.
        VideoProviderSubmissionReceipt::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'render_id' => $render->id,
            'attempt_id' => $attempt->id,
            'attempt_no' => (int) $render->attempt_count,
            'claim_generation' => (int) $attempt->claim_generation,
            'claim_token' => $claimToken,
            'provider' => (string) $render->provider,
            'model' => (string) $render->model,
            'provider_job_id' => $jobId,
            'provider_request_id' => $result['request_id'],
            'response_json' => json_encode($result['response'], JSON_UNESCAPED_UNICODE),
            'observed_at' => $this->clock->now(),
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);

        $receipt = VideoProviderSubmissionReceipt::query()
            ->where('attempt_id', $attempt->id)
            ->where('provider_job_id', $jobId)
            ->first();

        if ($receipt === null) {
            return false;
        }

        // Ton tai chua du: mot dong CUNG job nhung khac claim/generation/provider la
        // bien lai cua lan khac, khong phai bang chung cho lan nay.
        return $receipt->render_id === $render->id
            && $receipt->attempt_no === (int) $render->attempt_count
            && $receipt->claim_generation === (int) $attempt->claim_generation
            && hash_equals((string) $receipt->claim_token, $claimToken)
            && $receipt->provider === (string) $render->provider
            && $receipt->model === (string) $render->model;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $pollResponse
     * @return array{0: bool, 1: string}
     */
    private function writeArtifact(
        VideoRender $render,
        string $token,
        array $spec,
        string $uri,
        array $pollResponse,
    ): array {
        $render = $this->provider->markArtifactWriting($render, $token, $pollResponse);

        if (! $this->renew($render->id, $token)) {
            return [false, 'lease_lost_before_artifact'];
        }

        $dir = 'video/'.$render->id;
        $path = $dir.'/clip.mp4';
        $file = $this->veo->download($uri, $path);

        if (! $file['ok']) {
            return $this->failOrLose($render->id, $token, $file['failure'], 'download_failed', (string) $file['error']);
        }

        // Tai xong moi biet mat bao lau. Lease chet trong luc do la mat quyen ghi,
        // va thu vua tai ve phai bi don di chu khong duoc de lai tren dia.
        if (! $this->renew($render->id, $token)) {
            @unlink((string) $file['local_path']);
            $this->storage->disk((string) config('video.veo.disk'))->delete($path);

            return [false, 'lease_lost_before_artifact'];
        }

        $probed = $this->probe->inspect((string) $file['local_path']);
        @unlink((string) $file['local_path']);

        $expectedMs = (int) round(((float) ($spec['duration_seconds'] ?? 0)) * 1000);
        $tolerance = (int) config('video.veo.duration_tolerance_ms', 1500);

        if (! $probed['ok']) {
            $this->storage->disk((string) config('video.veo.disk'))->delete($path);

            return $this->failOrLose($render->id, $token, RenderFailureClass::ARTIFACT_INTEGRITY,
                'probe_failed', (string) $probed['error']);
        }

        if ($expectedMs > 0 && abs((int) $probed['duration_ms'] - $expectedMs) > $tolerance) {
            $this->storage->disk((string) config('video.veo.disk'))->delete($path);

            return $this->failOrLose($render->id, $token, RenderFailureClass::ARTIFACT_INTEGRITY,
                'duration_mismatch',
                sprintf('duration lech: file %dms, yeu cau %dms', (int) $probed['duration_ms'], $expectedMs));
        }

        $written = VideoRender::query()
            ->whereKey($render->id)
            ->where('claim_token', $token)
            ->where('claimed_by', self::WORKER)
            ->where('lease_expires_at', '>', $this->clock->now())
            ->update([
                'artifact_path' => $path,
                'artifact_dir' => $dir,
                'bytes' => $file['bytes'],
                'duration_ms' => $probed['duration_ms'],
                'width' => $probed['width'],
                'height' => $probed['height'],
                'proof_method' => 'ffprobe_sha256',
                'proof_verified' => true,
            ]);

        if ($written !== 1) {
            $this->storage->disk((string) config('video.veo.disk'))->delete($path);

            return [false, 'lease_lost_before_artifact'];
        }

        $render = $render->refresh();

        $this->checkpoint->complete(
            renderId: $render->id,
            claimToken: $token,
            requestHash: (string) $render->request_hash,
            providerRequestId: $render->provider_request_id,
            artifactManifest: [
                'render_id' => $render->id,
                'request_hash' => $render->request_hash,
                'canonical_hash' => $render->canonical_hash,
                'artifacts' => [[
                    'kind' => 'generated_video',
                    'path' => $path,
                    'sha256' => $file['sha256'],
                    'bytes' => $file['bytes'],
                    'mime' => $file['mime'],
                    'duration_ms' => $probed['duration_ms'],
                    'width' => $probed['width'],
                    'height' => $probed['height'],
                ]],
            ],
            usage: new RenderAttemptUsage(null, null, null, null, null),
        );

        $render = $render->refresh();

        if ($render->shot !== null) {
            $this->shots->markVideoReady($render->shot, $render, (string) ($spec['motion_spec_hash'] ?? ''));
        }

        return [true, 'succeeded'];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{ok: bool, mime: ?string, data: ?string, error: ?string}
     */
    private function sourceImage(array $spec): array
    {
        $source = $spec['source_artifact'] ?? null;

        if (! is_array($source)) {
            return $this->noSource('spec khong co source_artifact da dong bang');
        }

        $disk = (string) ($source['disk'] ?? '');
        $path = (string) ($source['path'] ?? '');

        if (! in_array($disk, (array) config('video.veo.source_disks', ['video_artifacts']), true)) {
            return $this->noSource('disk nguon ngoai danh sach cho phep: '.$disk);
        }

        $filesystem = $this->storage->disk($disk);

        if ($path === '' || ! $filesystem->exists($path)) {
            return $this->noSource('khung hinh nguon khong con tren dia: '.$path);
        }

        $size = (int) $filesystem->size($path);

        if ($size <= 0 || $size > (int) config('video.veo.max_source_bytes', 33554432)) {
            return $this->noSource('khung hinh nguon co kich thuoc khong hop le: '.$size);
        }

        $bytes = (string) $filesystem->get($path);

        if (! hash_equals((string) ($source['sha256'] ?? ''), hash('sha256', $bytes))) {
            return $this->noSource('file nguon khac hash da dong bang');
        }

        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';

        if ($mime === '' || $mime !== (string) ($source['mime'] ?? '')) {
            return $this->noSource('mime nguon khac luc dong bang: '.($mime !== '' ? $mime : 'khong doc duoc'));
        }

        return ['ok' => true, 'mime' => $mime, 'data' => base64_encode($bytes), 'error' => null];
    }

    /** @return array{ok: bool, mime: ?string, data: ?string, error: ?string} */
    private function noSource(string $message): array
    {
        return ['ok' => false, 'mime' => null, 'data' => null, 'error' => $message];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array{ok: bool, mime: ?string, data: ?string, error: ?string}  $source
     * @return array<string, mixed>
     */
    private function payload(array $spec, array $source): array
    {
        return [
            'instances' => [[
                'prompt' => (string) $spec['compiled_prompt']['prompt'],
                'image' => [
                    'bytesBase64Encoded' => $source['data'],
                    'mimeType' => $source['mime'],
                ],
            ]],
            'parameters' => [
                'aspectRatio' => (string) $spec['aspect_ratio'],
                'durationSeconds' => (int) $spec['duration_seconds'],
                'resolution' => (string) $spec['resolution'],
                'sampleCount' => 1,
            ],
        ];
    }
}
