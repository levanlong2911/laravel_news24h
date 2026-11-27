<?php

namespace App\Video\Render\Video;

use App\Models\VideoRender;
use App\Repositories\Interfaces\VideoProviderSubmissionReceiptRepositoryInterface;
use App\Repositories\Interfaces\VideoRenderAttemptRepositoryInterface;
use App\Repositories\Interfaces\VideoRenderRepositoryInterface;
use App\Repositories\Interfaces\VideoShotRepositoryInterface;
use App\Video\Media\FfmpegRunner;
use App\Video\Media\GeminiOmniVideoClient;
use App\Video\Media\GeminiVeoVideoClient;
use App\Video\Media\Mp4Probe;
use App\Video\Concept\Support\Clock;
use App\Video\Media\VideoModelRegistry;
use App\Video\Moto\MotoClipPlanBuilder;
use App\Video\Moto\MotoContract;
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

    /** @var string the evidence a file checked against a media policy moto-clip-media-policy-v2 keeps with its render: what was measured, the decode of the whole file and the verdict */
    public const MEDIA_CHECK_VERSION = 'moto-clip-media-check-v1';

    /** @var string the proof of a file checked that way: ffprobe measured it, ffmpeg decoded all of it and its bytes were hashed */
    public const PROOF_DECODED = 'ffprobe_decode_sha256';

    /** @var list<string> the arguments ffmpeg decodes a whole file with, the file going after -i; any error it prints fails the file */
    private const DECODE = ['-v', 'error', '-nostdin', '-i', '{file}', '-f', 'null', '-'];

    /** @var list<RenderFailureClass> the failures of a poll request that say nothing of the job: the provider was unreachable, rate limited or answered with a server error while the operation was not finished, so the job may still be running and the poll is asked again later */
    private const POLL_RETRY_LATER = [RenderFailureClass::TRANSIENT_NETWORK, RenderFailureClass::RATE_LIMIT, RenderFailureClass::PROVIDER_5XX];

    public function __construct(
        private readonly RenderClaimService $claims,
        private readonly RenderAttemptService $attempts,
        private readonly VideoProviderCheckpointService $provider,
        private readonly RenderCheckpointService $checkpoint,
        private readonly RenderFailureService $failures,
        private readonly VideoShotCheckpointService $shots,
        private readonly VideoModelRegistry $registry,
        private readonly GeminiVeoVideoClient $veo,
        private readonly GeminiOmniVideoClient $omni,
        private readonly Mp4Probe $probe,
        private readonly FilesystemFactory $storage,
        private readonly RenderLeaseService $lease,
        private readonly Clock $clock,
        private readonly FfmpegRunner $ffmpeg,
        private readonly VideoRenderRepositoryInterface $renders,
        private readonly VideoRenderAttemptRepositoryInterface $renderAttempts,
        private readonly VideoProviderSubmissionReceiptRepositoryInterface $receipts,
        private readonly VideoShotRepositoryInterface $videoShots,
    ) {}

    /** @return array{0: bool, 1: string} [$ok, $reason]; a row of a local processor (MotoClipPlanBuilder::LOCAL) is never claimed or sent here: local_processor */
    public function submit(string $renderId): array
    {
        if ($this->renders->findProviderOf($renderId) === MotoClipPlanBuilder::LOCAL) {
            return [false, 'local_processor'];
        }

        // Thieu binary la loi may, khong duoc dot mot attempt vi no.
        if (! $this->probe->available()) {
            return [false, 'ffprobe_missing'];
        }

        $claim = $this->claims->claimById($renderId, self::WORKER);

        if ($claim === null) {
            return [false, 'not_claimable'];
        }

        $render = $this->renders->findRenderOrFail($renderId);
        $spec = json_decode($claim->renderRequestJson, true);

        if (! is_array($spec)) {
            return $this->failOrLose($renderId, $claim->claimToken,
                RenderFailureClass::INVALID_REQUEST, 'bad_request_json', 'render_request_json hong.');
        }

        $omni = $this->isOmni($spec);
        $entry = $render->render_kind !== 'video' ? null : $this->entryFor($spec);

        $blocked = match (true) {
            $render->render_kind !== 'video' => 'render nay khong phai video',
            $entry === null => 'model ngoai registry',
            ($spec['provider_payload_version'] ?? null) !== ($omni
                ? GeminiOmniVideoClient::PAYLOAD_VERSION
                : SceneClipDispatchService::PROVIDER_PAYLOAD_VERSION)
                => 'provider payload contract khong duoc ho tro',
            $omni !== (($entry['method'] ?? null) === \App\Video\Media\VideoModelRegistry::INTERACTIONS)
                => 'hop dong payload khong khop cach goi cua model',
            ! hash_equals($claim->requestHash, hash('sha256', $claim->renderRequestJson)) => 'request hash lech',
            default => null,
        };

        if ($blocked !== null) {
            return $this->failOrLose($renderId, $claim->claimToken,
                RenderFailureClass::INVALID_REQUEST, 'precondition_failed', $blocked);
        }

        if ($omni) {
            return $this->submitFilm($render, $claim->claimToken, $spec);
        }

        $source = $this->frame($spec, 'source_artifact');

        if (! $source['ok']) {
            return $this->failOrLose($renderId, $claim->claimToken,
                RenderFailureClass::ARTIFACT_INTEGRITY, 'source_artifact_invalid', (string) $source['error']);
        }

        // `end_frame` la tuy chon trong hop dong v5, nhung neu spec co khai thi no
        // phai doc lai duoc y nguyen. Mot anh cuoi hong ma van gui di se tra tien
        // cho mot clip khong bao gio ket thuc dung cho.
        $end = ($spec['end_frame'] ?? null) === null
            ? null
            : $this->frame($spec, 'end_frame');

        if ($end !== null && ! $end['ok']) {
            return $this->failOrLose($renderId, $claim->claimToken,
                RenderFailureClass::ARTIFACT_INTEGRITY, 'end_frame_invalid', (string) $end['error']);
        }

        try {
            $this->attempts->markPreparing($renderId, $claim->claimToken);
            $this->attempts->markSubmitting($renderId, $claim->claimToken);
        } catch (Throwable) {
            return [false, 'lease_lost_before_submit'];
        }

        return $this->accepted($render, $claim->claimToken, $this->veo->submit($this->target($spec), $this->payload($spec, $source, $end)));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{0: bool, 1: string}
     */
    private function accepted(VideoRender $render, string $claimToken, array $result): array
    {
        $renderId = (string) $render->id;

        if ($result['ambiguous']) {
            try {
                $this->attempts->markAmbiguous($renderId, $claimToken,
                    'provider_unknown', (string) $result['error']);
            } catch (Throwable) {
                return [false, 'lease_lost_before_ambiguous'];
            }

            return [false, 'provider_unknown'];
        }

        if (! $result['ok']) {
            return $this->failOrLose($renderId, $claimToken,
                $result['failure'], 'submit_failed', (string) $result['error']);
        }

        // Provider da nhan job. Tu day tro di khong duoc de bat ky loi nao lam mat
        // operation id: mat no la mat duong poll toi thu da tra tien.
        if (! $this->keepJobId($render, $claimToken, $result)) {
            return [false, 'ownership_lost_after_submit'];
        }

        try {
            $this->lease->heartbeat($renderId, $claimToken, self::WORKER);
            $this->provider->markProviderRunning($render, $claimToken,
                (string) $result['job_id'], $result['request_id'], $result['response']);
        } catch (Throwable) {
            return [false, 'checkpoint_failed_after_submit'];
        }

        return [true, 'provider_running'];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{0: bool, 1: string}
     */
    private function submitFilm(VideoRender $render, string $claimToken, array $spec): array
    {
        $images = [];

        foreach ((array) ($spec['references'] ?? []) as $index => $reference) {
            $image = $this->filmReference(is_array($reference) ? $reference : [], (array) ($spec['derivative_policy'] ?? []));

            if ($image['error'] !== null) {
                return $this->failOrLose((string) $render->id, $claimToken, RenderFailureClass::ARTIFACT_INTEGRITY,
                    'reference_invalid', 'anh tham chieu '.($index + 1).': '.$image['error']);
            }

            $images[] = ['mime' => $image['mime'], 'base64' => $image['base64']];
        }

        try {
            $this->attempts->markPreparing((string) $render->id, $claimToken);
            $this->attempts->markSubmitting((string) $render->id, $claimToken);
        } catch (Throwable) {
            return [false, 'lease_lost_before_submit'];
        }

        return $this->accepted($render, $claimToken, $this->omni->submit($this->target($spec), GeminiOmniVideoClient::specPayload($spec, $images)));
    }

    /**
     * @param  array<string, mixed>  $reference
     * @param  array<string, mixed>  $policy
     * @return array{error: ?string, mime: ?string, base64: ?string}
     */
    private function filmReference(array $reference, array $policy): array
    {
        $disk = (string) ($reference['disk'] ?? '');
        $path = (string) ($reference['path'] ?? '');

        if (! in_array($disk, (array) config('video.veo.source_disks', ['video_artifacts']), true)) {
            return ['error' => 'disk ngoai danh sach cho phep: '.$disk, 'mime' => null, 'base64' => null];
        }

        $filesystem = $this->storage->disk($disk);

        if ($path === '' || ! $filesystem->exists($path)) {
            return ['error' => 'file khong con tren dia: '.$path, 'mime' => null, 'base64' => null];
        }

        $bytes = (string) $filesystem->get($path);

        if (! hash_equals((string) ($reference['sha256'] ?? ''), hash('sha256', $bytes))) {
            return ['error' => 'file khac hash da dong bang', 'mime' => null, 'base64' => null];
        }

        $sent = GeminiOmniVideoClient::reference($bytes, (string) ($reference['mime'] ?? ''), [
            'max_long_edge' => (int) ($policy['max_long_edge'] ?? 0),
            'max_bytes' => (int) ($policy['max_bytes'] ?? 0),
            'jpeg_quality' => (int) ($policy['jpeg_quality'] ?? 0),
            'processor' => (string) ($policy['processor'] ?? ''),
        ]);

        if (! $sent['ok']) {
            return ['error' => (string) $sent['error'], 'mime' => null, 'base64' => null];
        }

        if (! hash_equals((string) ($reference['sent_sha256'] ?? ''), (string) $sent['sent_sha256'])) {
            return ['error' => 'ban dan xuat khac sha256 da dong bang', 'mime' => null, 'base64' => null];
        }

        return ['error' => null, 'mime' => (string) $sent['mime'], 'base64' => (string) $sent['base64']];
    }

    /**
     * @param  array<string, mixed>  $probed
     * @param  array<string, mixed>  $spec
     */
    private function filmOutputMismatch(array $probed, array $spec, int $expectedMs, int $tolerance): ?string
    {
        $policy = $this->mediaPolicy($spec);
        $size = $policy !== null
            ? ($policy['output_size'] ?? null)
            : config('video.omni.output_sizes.'.($spec['aspect_ratio'] ?? '').'.'.($spec['resolution'] ?? ''));

        if ($policy === null && (! is_array($size) || count($size) !== 2)) {
            return 'khong co kich thuoc dau ra cho '.($spec['aspect_ratio'] ?? '?').' '.($spec['resolution'] ?? '?');
        }

        if ($size !== null && ((int) $probed['width'] !== (int) $size[0] || (int) $probed['height'] !== (int) $size[1])) {
            return sprintf('kich thuoc %dx%d, yeu cau %dx%d', (int) $probed['width'], (int) $probed['height'], (int) $size[0], (int) $size[1]);
        }

        if ((int) ($probed['video_count'] ?? 0) !== 1 || ! is_numeric($probed['video']['duration'] ?? null)) {
            return 'can dung mot luong hinh co thoi luong doc duoc, file co '.(int) ($probed['video_count'] ?? 0);
        }

        $videoMs = (int) round((float) $probed['video']['duration'] * 1000);

        if (abs($videoMs - $expectedMs) > $tolerance) {
            return sprintf('luong hinh dai %dms, yeu cau %dms', $videoMs, $expectedMs);
        }

        if ($policy !== null && min($videoMs, (int) $probed['duration_ms']) < (int) $policy['used_end_ms']) {
            return sprintf('file dai %dms, doan phim giu lai ket thuc o %dms', min($videoMs, (int) $probed['duration_ms']), (int) $policy['used_end_ms']);
        }

        if (in_array($spec['audio'] ?? null, ['ambient_only', 'music_and_effects'], true)) {
            $audioMs = is_numeric($probed['audio']['duration'] ?? null) ? (int) round((float) $probed['audio']['duration'] * 1000) : null;
            $drift = (int) ($policy['av_drift_ms'] ?? config('video.omni.target.av_drift_tolerance_ms', 100));

            if ((int) ($probed['audio_count'] ?? 0) !== 1 || $audioMs === null) {
                return 'can dung mot luong tieng co thoi luong doc duoc, file co '.(int) ($probed['audio_count'] ?? 0);
            }

            if (abs($audioMs - $videoMs) > $drift) {
                return sprintf('luong tieng dai %dms, luong hinh %dms, lech qua %dms', $audioMs, $videoMs, $drift);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return ?array<string, mixed> the file checks the request froze when it was made, which a later change of config or registry cannot loosen; null for a request that froze none and reads them from config
     */
    private function mediaPolicy(array $spec): ?array
    {
        return is_array($spec['media_policy'] ?? null) ? $spec['media_policy'] : null;
    }

    /** @param array<string, mixed> $spec */
    private function isFilm(array $spec): bool
    {
        return ($spec['kind'] ?? null) === SceneClipDispatchService::FILM_TASK;
    }

    /** @param array<string, mixed> $spec */
    private function isOmni(array $spec): bool
    {
        return $this->isFilm($spec) || ($spec['provider_payload_version'] ?? null) === GeminiOmniVideoClient::PAYLOAD_VERSION;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>|null
     */
    private function entryFor(array $spec): ?array
    {
        return $this->registry->find(
            $this->isFilm($spec) ? SceneClipDispatchService::FILM_TASK : SceneClipDispatchService::TASK,
            ($spec['provider'] ?? '').':'.($spec['model'] ?? ''),
        );
    }

    /** @return array{0: bool, 1: string} [$ok, $reason]; a row of a local processor has no provider job to ask for: not_running */
    public function poll(string $renderId): array
    {
        $render = $this->renders->findRenderOrFail($renderId);

        if ($render->provider === MotoClipPlanBuilder::LOCAL) {
            return [false, 'not_running'];
        }

        if ($render->execution_status !== RenderStatus::PROVIDER_RUNNING) {
            // Job da gui thanh cong nhung checkpoint hong thi render ket o submitting
            // hoac provider_unknown. Vot no lai bang chinh job id da giu.
            $render = $this->provider->adoptKnownJob($render);

            if ($render === null) {
                return [false, 'not_running'];
            }
        }

        $spec = (array) json_decode((string) $render->render_request_json, true);

        $entry = $this->entryFor($spec);

        if ($entry === null && $this->mediaPolicy($spec) === null) {
            return [false, 'model_gone_from_registry'];
        }

        $token = (string) Str::uuid();
        $render = $this->provider->claimPoll($render, $token, self::WORKER);

        $poll = $this->isOmni($spec)
            ? $this->omni->poll($this->target($spec), (string) $render->provider_job_id)
            : $this->veo->poll($this->target($spec), (string) $render->provider_job_id);

        if (! $poll['ok'] && ! $poll['done'] && in_array($poll['failure'], self::POLL_RETRY_LATER, true)) {
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

        $written = $this->renders->recordSubmittedJobIfLeaseHeld(
            $render->id,
            $claimToken,
            self::WORKER,
            $this->clock->now(),
            (string) $result['job_id'],
            json_encode($result['response'], JSON_UNESCAPED_UNICODE),
        );

        if ($written === 1) {
            return true;
        }

        // Mat quyen ghi trang thai — nhung bien lai o tren da ghi roi, nen job khong
        // mat. Attempt van duoc va them mot lan neu no con la cua minh, de nguoi doc
        // hang thay ngay ma khong phai tra cuu bien lai.
        $this->renderAttempts->recordJobOnOwnAttemptIfMissing(
            $render->id,
            $render->attempt_count,
            $claimToken,
            (string) $result['job_id'],
            json_encode($result['response'], JSON_UNESCAPED_UNICODE),
        );

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
        $attempt = $this->renderAttempts->findAttemptForClaim($render->id, $render->attempt_count, $claimToken, $render->claim_generation);

        if ($attempt === null) {
            return false;
        }

        $jobId = (string) $result['job_id'];

        // `insertOrIgnore` nuot MOI loi chu khong rieng trung khoa, nen khong duoc
        // coi no la da ghi: phai doc lai moi biet bien lai co that hay khong.
        $this->receipts->insertReceiptOrIgnore([
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

        $receipt = $this->receipts->findReceiptForAttemptJob($attempt->id, $jobId);

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
        $file = $this->isOmni($spec) ? $this->omni->download($uri, $path) : $this->veo->download($uri, $path);

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

        if (($this->mediaPolicy($spec)['version'] ?? null) === MotoClipPlanBuilder::POLICY_VERSION_V2) {
            return $this->storeCheckedFile($render, $token, $spec, $file, $path);
        }

        $probed = $this->probe->inspect((string) $file['local_path']);
        @unlink((string) $file['local_path']);

        $expectedMs = (int) round(((float) ($spec['duration_seconds'] ?? 0)) * 1000);
        $policy = $this->mediaPolicy($spec);
        $tolerance = match (true) {
            $policy !== null => (int) $policy['duration_tolerance_ms'],
            $this->isOmni($spec) => (int) config('video.omni.target.duration_tolerance_ms', 500),
            default => (int) config('video.veo.duration_tolerance_ms', 1500),
        };

        if (! $probed['ok']) {
            $this->storage->disk((string) config('video.veo.disk'))->delete($path);

            return $this->failOrLose($render->id, $token, RenderFailureClass::ARTIFACT_INTEGRITY,
                'probe_failed', (string) $probed['error']);
        }

        $mismatch = $this->isOmni($spec) || $policy !== null ? $this->filmOutputMismatch($probed, $spec, $expectedMs, $tolerance) : null;

        if ($mismatch !== null) {
            return $this->failOrLose($render->id, $token, RenderFailureClass::ARTIFACT_INTEGRITY,
                'output_contract_mismatch', $mismatch.' — file da tra phi giu lai o '.$path.' de doi soat, khong duoc chon.');
        }

        if ($expectedMs > 0 && abs((int) $probed['duration_ms'] - $expectedMs) > $tolerance) {
            if ($policy !== null) {
                return $this->failOrLose($render->id, $token, RenderFailureClass::ARTIFACT_INTEGRITY,
                    'output_contract_mismatch',
                    sprintf('duration lech: file %dms, yeu cau %dms — file da tra phi giu lai o %s de doi soat, khong duoc chon.', (int) $probed['duration_ms'], $expectedMs, $path));
            }

            $this->storage->disk((string) config('video.veo.disk'))->delete($path);

            return $this->failOrLose($render->id, $token, RenderFailureClass::ARTIFACT_INTEGRITY,
                'duration_mismatch',
                sprintf('duration lech: file %dms, yeu cau %dms', (int) $probed['duration_ms'], $expectedMs));
        }

        $written = $this->renders->recordArtifactIfLeaseHeld($render->id, $token, self::WORKER, $this->clock->now(), [
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

        $render = $this->renders->refreshRender($render);

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
                ] + ($policy === null ? [] : [
                    'audio_count' => (int) ($probed['audio_count'] ?? 0),
                    'has_audio' => (int) ($probed['audio_count'] ?? 0) > 0,
                ])],
            ],
            usage: new RenderAttemptUsage(null, null, null, null, null),
        );

        $render = $this->renders->refreshRender($render);
        $shot = $this->videoShots->loadShotOfRender($render);

        if ($shot !== null) {
            $this->shots->markVideoReady($shot, $render, (string) ($spec['motion_spec_hash'] ?? ''));
        }

        return [true, 'succeeded'];
    }

    /**
     * The file of a render whose request froze a media policy moto-clip-media-policy-v2 is measured, decoded whole and judged by that policy alone. A file that fails stays on its disk, its evidence and why written to the render, which fails and is never selectable; a file that passes is checkpointed with its evidence and handed to the shot, which takes it as the clip in use only when the request allows it. A local processor calls this the same way once it wrote its file.
     *
     * @param  VideoRender  $render  the render this worker (WORKER) holds by $token, in a state that may go on to checkpointing or failed (artifact_writing)
     * @param  array<string, mixed>  $spec  its frozen request
     * @param  array{sha256: string, bytes: int, mime: string, local_path: string}  $file  the file as written to the video disk at $path, and a local copy of it, which this removes
     * @param  string  $path  where the file is on the video disk: video/{render id}/clip.mp4
     * @return array{0: bool, 1: string} [$ok, $reason]
     */
    public function storeCheckedFile(VideoRender $render, string $token, array $spec, array $file, string $path): array
    {
        $check = $this->checkClipFile((string) $file['local_path'], (array) $spec['media_policy'], ['path' => $path, 'sha256' => (string) $file['sha256'], 'bytes' => (int) $file['bytes'], 'mime' => (string) $file['mime']]);
        @unlink((string) $file['local_path']);
        $disk = $this->storage->disk((string) config('video.veo.disk'));
        $measured = $check['measured'];
        $entry = [
            'kind' => 'generated_video',
            'path' => $path,
            'sha256' => (string) $file['sha256'],
            'bytes' => (int) $file['bytes'],
            'mime' => (string) $file['mime'],
            'duration_ms' => $measured['duration_ms'],
            'width' => $measured['width'],
            'height' => $measured['height'],
            'audio_count' => $measured['audio_streams'],
            'has_audio' => $measured['has_audio'],
            'media_check' => $check,
        ];
        $manifest = ['render_id' => (string) $render->id, 'request_hash' => (string) $render->request_hash, 'canonical_hash' => $render->canonical_hash, 'artifacts' => [$entry]];

        if (! $this->renew($render->id, $token)) {
            $disk->delete($path);

            return [false, 'lease_lost_before_artifact'];
        }

        if (! $check['passed']) {
            $this->renders->recordArtifactIfLeaseHeld($render->id, $token, self::WORKER, $this->clock->now(), [
                'artifact_manifest' => json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            return $this->failOrLose($render->id, $token, RenderFailureClass::ARTIFACT_INTEGRITY, (string) $check['code'],
                $check['reason'].' — file đã trả phí giữ lại ở '.$path.' (sha256 '.$file['sha256'].') để đối soát, không được chọn.');
        }

        $written = $this->renders->recordArtifactIfLeaseHeld($render->id, $token, self::WORKER, $this->clock->now(), [
            'artifact_path' => $path,
            'artifact_dir' => dirname($path),
            'bytes' => (int) $file['bytes'],
            'duration_ms' => $measured['duration_ms'],
            'width' => $measured['width'],
            'height' => $measured['height'],
            'proof_method' => self::PROOF_DECODED,
            'proof_verified' => true,
        ]);

        if ($written !== 1) {
            $disk->delete($path);

            return [false, 'lease_lost_before_artifact'];
        }

        $render = $this->renders->refreshRender($render);
        $this->checkpoint->complete(
            renderId: $render->id,
            claimToken: $token,
            requestHash: (string) $render->request_hash,
            providerRequestId: $render->provider_request_id,
            artifactManifest: $manifest,
            usage: new RenderAttemptUsage(null, null, null, null, null),
        );
        $render = $this->renders->refreshRender($render);
        $shot = $this->videoShots->loadShotOfRender($render);

        if ($shot !== null) {
            $this->shots->markVideoReady($shot, $render, (string) ($spec['motion_spec_hash'] ?? ''));
        }

        return [true, 'succeeded'];
    }

    /**
     * @param  string  $localFile  a local copy of the file
     * @param  array<string, mixed>  $policy  the media policy moto-clip-media-policy-v2 the request froze
     * @param  array{path: string, sha256: string, bytes: int, mime: string}  $file  the file as stored
     * @return array{version: string, passed: bool, code: ?string, reason: ?string, policy: array<string, mixed>, file: array<string, mixed>, measured: array<string, mixed>, decode: array{command: string, ok: bool, exit_code: ?int, errors: ?string}} the evidence of the file: what ffprobe measured, whether ffmpeg decoded all of it without an error, and why it fails the policy (probe_failed, decode_failed or output_contract_mismatch) or null when it passes
     */
    public function checkClipFile(string $localFile, array $policy, array $file): array
    {
        $probed = $this->probe->inspect($localFile);
        $decode = $probed['ok'] ? $this->decode($localFile) : ['command' => self::decodeCommand(), 'ok' => false, 'exit_code' => null, 'errors' => 'không giải mã vì ffprobe không đọc được file'];
        $measured = self::measured($probed);
        $problem = $probed['ok'] && $decode['ok'] ? self::mediaProblem($measured, $policy) : null;
        [$code, $reason] = match (true) {
            ! $probed['ok'] => ['probe_failed', 'không đọc được file video: '.$probed['error']],
            ! $decode['ok'] => ['decode_failed', 'giải mã toàn bộ file báo lỗi: '.$decode['errors']],
            $problem !== null => ['output_contract_mismatch', $problem],
            default => [null, null],
        };

        return ['version' => self::MEDIA_CHECK_VERSION, 'passed' => $code === null, 'code' => $code, 'reason' => $reason, 'policy' => $policy, 'file' => $file, 'measured' => $measured, 'decode' => $decode];
    }

    /**
     * @param  array<string, mixed>  $measured  measured() of a file
     * @param  array<string, mixed>  $policy  a media policy moto-clip-media-policy-v2
     * @return ?string why the file fails the policy: not one video stream with a readable length, a display rotation that cannot be read, another size as the file is shown once its rotation is applied, a length of file off the one asked for beyond the tolerance, a part kept that is not inside the file, more than one audio stream, under require_audio no readable audio stream, a stream that does not say when it starts or a sound that starts or ends off the picture beyond the drift, or the frame rate, frames, codec, pixel format or pixel shape the policy declares; null when it passes
     */
    public static function mediaProblem(array $measured, array $policy): ?string
    {
        $wanted = (int) $policy['duration_ms'];
        $tolerance = (int) $policy['duration_tolerance_ms'];
        $video = $measured['video_duration_ms'];
        $size = is_array($policy['output_size'] ?? null) ? array_map('intval', array_values($policy['output_size'])) : null;
        $turn = self::quarterTurns($measured['rotation'] ?? null);
        $shown = $turn === null ? null : ($turn % 2 === 1 ? [$measured['height'], $measured['width']] : [$measured['width'], $measured['height']]);
        [$start, $end] = [(int) $policy['used_start_ms'], (int) $policy['used_end_ms']];
        $audio = $policy['audio'] ?? null;
        $require = $audio === MotoContract::AUDIO_REQUIRE;
        $drift = $policy['av_drift_ms'] ?? null;
        $declared = static fn (string $key): bool => ($policy[$key] ?? null) !== null;

        return match (true) {
            ! in_array($audio, MotoClipPlanBuilder::AUDIO_POLICIES, true) => 'chính sách âm thanh '.json_encode($audio).' không phải chính sách code biết',
            $measured['video_streams'] !== 1 || $video === null => sprintf('cần đúng một luồng hình có thời lượng đọc được, file có %d luồng hình', $measured['video_streams']),
            $shown === null => sprintf('không đọc được hướng hiển thị của luồng hình (rotation %s)', json_encode($measured['rotation'] ?? null, JSON_UNESCAPED_UNICODE)),
            $size !== null && $shown !== $size && $turn === 0 => sprintf('kích thước %dx%d, yêu cầu %dx%d', (int) $measured['width'], (int) $measured['height'], $size[0], $size[1]),
            $size !== null && $shown !== $size => sprintf('kích thước hiển thị %dx%d (file %dx%d, xoay %d°), yêu cầu %dx%d', (int) $shown[0], (int) $shown[1], (int) $measured['width'], (int) $measured['height'], $turn * 90, $size[0], $size[1]),
            abs($video - $wanted) > $tolerance => sprintf('luồng hình dài %d ms, yêu cầu file %d ms (±%d ms)', $video, $wanted, $tolerance),
            abs((int) $measured['duration_ms'] - $wanted) > $tolerance => sprintf('file dài %d ms, yêu cầu %d ms (±%d ms)', (int) $measured['duration_ms'], $wanted, $tolerance),
            $start < 0 || $end <= $start || min($video, (int) $measured['duration_ms']) < $end => sprintf('file dài %d ms, phần phim giữ %d–%d ms không nằm trọn trong file', min($video, (int) $measured['duration_ms']), $start, $end),
            $measured['audio_streams'] > 1 => sprintf('file có %d luồng tiếng, cần nhiều nhất một', $measured['audio_streams']),
            $require && ($measured['audio_streams'] !== 1 || $measured['audio_duration_ms'] === null) => sprintf('chính sách require_audio cần đúng một luồng tiếng đọc được, file có %d luồng tiếng', $measured['audio_streams']),
            $require && ! is_int($drift) => 'chính sách require_audio không khai giới hạn lệch hình/tiếng (av_drift_ms)',
            $require && ! is_int($measured['av_drift_ms'] ?? null) => 'chính sách require_audio cần thời điểm bắt đầu của luồng hình và luồng tiếng, file không khai đủ',
            $require && abs($measured['av_drift_ms']) > $drift => sprintf('luồng tiếng %d–%d ms, luồng hình %d–%d ms, lệch quá %d ms', (int) $measured['audio_start_ms'], (int) $measured['audio_start_ms'] + (int) $measured['audio_duration_ms'], (int) $measured['video_start_ms'], (int) $measured['video_start_ms'] + $video, $drift),
            $declared('fps') && (! self::rateIs($measured['fps'], (int) $policy['fps']) || ! self::rateIs($measured['avg_frame_rate'], (int) $policy['fps'])) => sprintf('nhịp khung %s (trung bình %s), yêu cầu %d fps', (string) ($measured['fps'] ?? '?'), (string) ($measured['avg_frame_rate'] ?? '?'), (int) $policy['fps']),
            $declared('frames') && $measured['frames'] !== (int) $policy['frames'] => sprintf('file có %s frame, yêu cầu %d', $measured['frames'] === null ? 'không rõ' : (string) $measured['frames'], (int) $policy['frames']),
            $declared('codec') && $measured['codec'] !== $policy['codec'] => sprintf('codec %s, yêu cầu %s', (string) ($measured['codec'] ?? '?'), (string) $policy['codec']),
            $declared('pixel_format') && $measured['pixel_format'] !== $policy['pixel_format'] => sprintf('pixel format %s, yêu cầu %s', (string) ($measured['pixel_format'] ?? '?'), (string) $policy['pixel_format']),
            $declared('sar') && $measured['sar'] !== $policy['sar'] => sprintf('SAR %s, yêu cầu %s', (string) ($measured['sar'] ?? '?'), (string) $policy['sar']),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $probed  Mp4Probe::inspect()
     * @return array{duration_ms: ?int, video_duration_ms: ?int, audio_duration_ms: ?int, video_start_ms: ?int, audio_start_ms: ?int, av_start_offset_ms: ?int, av_end_offset_ms: ?int, av_drift_ms: ?int, width: ?int, height: ?int, video_streams: int, audio_streams: int, other_streams: int, has_audio: bool, fps: ?string, avg_frame_rate: ?string, frames: ?int, codec: ?string, pixel_format: ?string, sar: ?string, rotation: ?string, audio_codec: ?string} what ffprobe read of the file, the start and the length of each stream in whole milliseconds; how far the sound starts and ends after the picture (negative when before), and av_drift_ms, the larger of the two, null when a stream gives no start or no length
     */
    private static function measured(array $probed): array
    {
        $video = (array) ($probed['video'] ?? []);
        $audio = (array) ($probed['audio'] ?? []);
        $ms = static fn (mixed $seconds): ?int => is_numeric($seconds) ? (int) round((float) $seconds * 1000) : null;
        $picture = $ms($video['duration'] ?? null);
        $sound = $ms($audio['duration'] ?? null);
        $pictureAt = $ms($video['start_time'] ?? null);
        $soundAt = $ms($audio['start_time'] ?? null);
        $timed = $picture !== null && $sound !== null && $pictureAt !== null && $soundAt !== null;
        $opens = $timed ? $soundAt - $pictureAt : null;
        $closes = $timed ? ($soundAt + $sound) - ($pictureAt + $picture) : null;

        return [
            'duration_ms' => $probed['duration_ms'],
            'video_duration_ms' => $picture,
            'audio_duration_ms' => $sound,
            'video_start_ms' => $pictureAt,
            'audio_start_ms' => $soundAt,
            'av_start_offset_ms' => $opens,
            'av_end_offset_ms' => $closes,
            'av_drift_ms' => $timed ? (abs($opens) >= abs($closes) ? $opens : $closes) : null,
            'width' => $probed['width'],
            'height' => $probed['height'],
            'video_streams' => (int) $probed['video_count'],
            'audio_streams' => (int) $probed['audio_count'],
            'other_streams' => (int) $probed['other_count'],
            'has_audio' => (int) $probed['audio_count'] > 0,
            'fps' => $video['r_frame_rate'] ?? null,
            'avg_frame_rate' => $video['avg_frame_rate'] ?? null,
            'frames' => $video['nb_frames'] ?? null,
            'codec' => $video['codec_name'] ?? null,
            'pixel_format' => $video['pix_fmt'] ?? null,
            'sar' => $video['sample_aspect_ratio'] ?? null,
            'rotation' => $video['rotation'] ?? null,
            'audio_codec' => $audio['codec_name'] ?? null,
        ];
    }

    /**
     * @param  ?string  $rotation  the display rotation of the video stream as Mp4Probe read it, null when the file states none
     * @return ?int how many quarter turns the picture is shown by, 0 to 3: 0 when the file states no rotation; null when what it states is not a whole number of quarter turns in degrees, such as a display matrix written out, which this check does not interpret
     */
    private static function quarterTurns(?string $rotation): ?int
    {
        if ($rotation === null) {
            return 0;
        }

        if (! is_numeric($rotation) || fmod((float) $rotation, 90.0) !== 0.0) {
            return null;
        }

        return (((int) round((float) $rotation / 90)) % 4 + 4) % 4;
    }

    /** @return array{command: string, ok: bool, exit_code: ?int, errors: ?string} whether ffmpeg decoded every frame of the file it picks with no error printed */
    private function decode(string $file): array
    {
        $result = $this->ffmpeg->run(array_map(static fn (string $arg): string => $arg === '{file}' ? $file : $arg, self::DECODE));
        $errors = trim($result->stderr);

        return ['command' => self::decodeCommand(), 'ok' => $result->successful && $errors === '', 'exit_code' => $result->exitCode, 'errors' => $errors === '' ? ($result->successful ? null : 'ffmpeg thoát mã '.var_export($result->exitCode, true)) : Str::limit($errors, 2000)];
    }

    private static function decodeCommand(): string
    {
        return 'ffmpeg '.implode(' ', self::DECODE);
    }

    /** @return bool whether a frame rate ffprobe wrote as num/den is exactly that many frames a second */
    private static function rateIs(?string $rate, int $fps): bool
    {
        return is_string($rate) && preg_match('#^([0-9]+)/([1-9][0-9]*)$#', $rate, $parts) === 1 && (int) $parts[1] === $fps * (int) $parts[2];
    }

    /**
     * Doc lai mot khung hinh DA DONG BANG trong spec va kiem no chua doi.
     *
     * Dung chung cho ca anh dau lan anh cuoi: hai khung di cung mot request thi phai
     * chiu cung mot muc kiem, khong duoc mot cai nghiem mot cai long.
     *
     * @param  array<string, mixed>  $spec
     * @return array{ok: bool, mime: ?string, data: ?string, error: ?string}
     */
    private function frame(array $spec, string $key): array
    {
        $source = $spec[$key] ?? null;

        if (! is_array($source)) {
            return $this->noSource('spec khong co '.$key.' da dong bang');
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

        $width = is_array($info) ? (int) ($info[0] ?? 0) : 0;
        $height = is_array($info) ? (int) ($info[1] ?? 0) : 0;

        if ($width !== (int) ($source['width'] ?? 0) || $height !== (int) ($source['height'] ?? 0)) {
            return $this->noSource(sprintf(
                'kich thuoc khac luc dong bang: %dx%d, spec ghi %dx%d',
                $width, $height, (int) ($source['width'] ?? 0), (int) ($source['height'] ?? 0),
            ));
        }

        return ['ok' => true, 'mime' => $mime, 'data' => base64_encode($bytes), 'error' => null];
    }

    /** @return array{ok: bool, mime: ?string, data: ?string, error: ?string} */
    private function noSource(string $message): array
    {
        return ['ok' => false, 'mime' => null, 'data' => null, 'error' => $message];
    }

    /**
     * Dia chi goi lay tu spec DA DONG BANG chu khong phai entry registry hom nay:
     * registry la cai CHO PHEP, con spec la cai DA CHOT. Hai thu lech nhau thi
     * request_hash khong con phu duoc len thu that su duoc gui.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function target(array $spec): array
    {
        return [
            'api_version' => (string) $spec['api_version'],
            'model' => (string) $spec['model'],
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array{ok: bool, mime: ?string, data: ?string, error: ?string}  $source
     * @param  array{ok: bool, mime: ?string, data: ?string, error: ?string}|null  $end
     * @return array<string, mixed>
     */
    private function payload(array $spec, array $source, ?array $end = null): array
    {
        // Canary that bi Veo tu choi da chung minh `inlineData` khong duoc nhan
        // o predictLongRunning. Endpoint nay nhan image Vertex-style.
        //   - anh di trong `image.bytesBase64Encoded`, kem `mimeType`
        //   - `durationSeconds` la so nguyen; canary thu hai da bi tu choi khi gui chuoi
        //   - khong co `sampleCount`: moi request tra ve dung mot video
        //   - image-to-video chi nhan `personGeneration = allow_adult`
        $instance = [
            'prompt' => (string) $spec['compiled_prompt']['prompt'],
            'image' => [
                'bytesBase64Encoded' => $source['data'],
                'mimeType' => $source['mime'],
            ],
        ];

        // `lastFrame` di CUNG CHO voi `image`, trong instances[0].
        //
        // Tai lieu Veo minh hoa `lastFrame.inlineData` — dung cai hinh dang ma chinh
        // endpoint nay DA TU CHOI cho `image` hom 2026-09-15. Nen o day dung lai hinh
        // dang da co bang chung (`bytesBase64Encoded`), va canary la thu quyet dinh no
        // dung hay sai — khong phai tai lieu.
        if ($end !== null) {
            $instance['lastFrame'] = [
                'bytesBase64Encoded' => $end['data'],
                'mimeType' => $end['mime'],
            ];
        }

        return [
            'instances' => [$instance],
            'parameters' => [
                'aspectRatio' => (string) $spec['aspect_ratio'],
                'resolution' => (string) $spec['resolution'],
                'durationSeconds' => (int) $spec['duration_seconds'],
                'personGeneration' => (string) $spec['person_generation'],
            ],
        ];
    }
}
