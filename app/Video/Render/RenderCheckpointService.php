<?php

declare(strict_types=1);

namespace App\Video\Render;

use App\Models\VideoRender;
use App\Models\VideoRenderAttempt;
use App\Video\Concept\Support\Clock;
use App\Video\Media\Mp4Probe;
use App\Video\Render\Cost\RenderCostAccountingService;
use App\Video\Render\DTO\RenderAttemptUsage;
use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\StateMachine\RenderStateMachine;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RenderCheckpointService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RenderCostAccountingService $costs,
        private readonly RenderStateMachine $states = new RenderStateMachine,
        private readonly ?FilesystemFactory $storage = null,
        private readonly ?Mp4Probe $probe = null,
    ) {
    }

    /**
     * Mot lease da chet la mot quyen ghi da mat: o co the dang thuoc ve luot khac.
     */
    private function assertHoldsLease(VideoRender $render, string $claimToken): void
    {
        if (! hash_equals((string) $render->claim_token, $claimToken)) {
            throw new RuntimeException('Complete claim token mismatch.');
        }

        if ($render->lease_expires_at === null || $render->lease_expires_at <= $this->clock->now()) {
            throw new RuntimeException('Complete lease expired.');
        }
    }

    public function complete(
        string $renderId,
        string $claimToken,
        string $requestHash,
        ?string $providerRequestId,
        array $artifactManifest,
        RenderAttemptUsage $usage,
    ): VideoRender {
        // Do va bam file la viec dai: mot clip hop le co the nang toi hang tram MB.
        // Lam no BEN TRONG transaction la giu khoa hang suot ca thoi gian do, va
        // lease 120 giay co the chet truoc khi commit.
        $probed = VideoRender::query()->whereKey($renderId)->firstOrFail();

        if ($probed->render_kind === 'video' && $probed->execution_status !== RenderStatus::SUCCEEDED) {
            $this->verifyVideoArtifact($probed, $artifactManifest);
        }

        return DB::transaction(function () use (
            $renderId,
            $claimToken,
            $requestHash,
            $providerRequestId,
            $artifactManifest,
            $usage,
        ): VideoRender {
            $render = VideoRender::query()->whereKey($renderId)->lockForUpdate()->firstOrFail();

            if ($render->execution_status === RenderStatus::SUCCEEDED) {
                if (! hash_equals((string) $render->request_hash, $requestHash)) {
                    throw new RuntimeException('Succeeded render replay request hash mismatch.');
                }

                return $render;
            }

            $this->assertHoldsLease($render, $claimToken);

            if (! hash_equals((string) $render->request_hash, $requestHash)) {
                throw new RuntimeException('Complete request hash mismatch.');
            }

            // Xac minh TRUOC moi lan ghi: mot transaction bi rollback thi khong hong
            // du lieu, nhung hop dong "chua kiem thi chua ghi" thi phai doc duoc
            // ngay trong thu tu cua ham nay.
            $this->verifyManifest($render, $artifactManifest);

            // Chot quyet dinh. Bam lai file co the keo dai, va lease van chay trong
            // luc do; tu day tro di moi ghi, nen day la cho cuoi cung con hoi duoc.
            $this->assertHoldsLease($render, $claimToken);

            // Recovery da dat render o CHECKPOINTING truoc khi goi vao day, nen
            // `checkpointing -> checkpointing` phai la khong-lam-gi, khong phai loi.
            if ($render->execution_status !== RenderStatus::CHECKPOINTING) {
                $this->states->assert($render->execution_status, RenderStatus::CHECKPOINTING);

                $render->forceFill([
                    'execution_status' => RenderStatus::CHECKPOINTING,
                    'execution_version' => $render->execution_version + 1,
                ])->save();
            }

            $this->states->assert(RenderStatus::CHECKPOINTING, RenderStatus::SUCCEEDED);

            $attempt = VideoRenderAttempt::query()
                ->where('render_id', $render->id)
                ->where('attempt_no', $render->attempt_count)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $attempt->provider_request_id !== null
                && $providerRequestId !== null
                && $attempt->provider_request_id !== $providerRequestId
            ) {
                throw new RuntimeException('Provider request ID mismatch.');
            }

            $now = $this->clock->now();

            $attempt->forceFill([
                'status' => RenderAttemptStatus::SUCCEEDED,
                'provider_request_id' => $providerRequestId ?? $attempt->provider_request_id,
                'artifact_manifest' => $artifactManifest,
                'input_tokens' => $usage->inputTokens,
                'output_tokens' => $usage->outputTokens,
                'image_input_tokens' => $usage->imageInputTokens,
                'text_input_tokens' => $usage->textInputTokens,
                'provider_cost_usd' => $usage->providerCostUsd,
                'completed_at' => $now,
            ])->save();

            $this->costs->record($render, $attempt, $usage);

            $render->forceFill([
                'execution_status' => RenderStatus::SUCCEEDED,
                'provider_request_id' => $providerRequestId ?? $render->provider_request_id,
                'artifact_manifest' => $artifactManifest,
                'primary_artifact_hash' => $this->primaryArtifactHash($render, $artifactManifest),
                'failure_class' => null,
                'failure_code' => null,
                'failure_message' => null,
                'claim_token' => null,
                'claimed_by' => null,
                'claimed_at' => null,
                'heartbeat_at' => null,
                'lease_expires_at' => null,
                'next_retry_at' => null,
                'execution_completed_at' => $now,
                'execution_version' => $render->execution_version + 1,
            ])->save();

            return $render;
        }, attempts: 3);
    }

    private function verifyManifest(VideoRender $render, array $manifest): void
    {
        foreach (['render_id', 'request_hash', 'canonical_hash', 'artifacts'] as $key) {
            if (! array_key_exists($key, $manifest)) {
                throw new RuntimeException('Artifact manifest missing '.$key);
            }
        }

        if ($manifest['render_id'] !== $render->id) {
            throw new RuntimeException('Artifact manifest render mismatch.');
        }

        if (! hash_equals((string) $render->request_hash, (string) $manifest['request_hash'])) {
            throw new RuntimeException('Artifact manifest request hash mismatch.');
        }

        if (! hash_equals((string) $render->canonical_hash, (string) $manifest['canonical_hash'])) {
            throw new RuntimeException('Artifact manifest canonical hash mismatch.');
        }

        if (! is_array($manifest['artifacts']) || $manifest['artifacts'] === []) {
            throw new RuntimeException('Artifact manifest has no artifacts.');
        }

        if ($render->render_kind === 'video') {
            $this->assertUnchanged($manifest);
        }
    }

    /**
     * Chay ben trong transaction, ngay truoc khi ghi `succeeded`.
     *
     * Do lai kich thuoc la chua du: mot file bi thay bang noi dung khac CUNG kich
     * thuoc trong khe giua luc do ben ngoai va luc commit se lot. Nen o day bam lai
     * theo luong — khong nap file vao bo nho, khong chay ffprobe, nen khoa hang chi
     * giu them thoi gian doc dia.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function assertUnchanged(array $manifest): void
    {
        $artifact = $this->videoArtifact($manifest);
        $disk = (string) config('video.veo.disk');
        $filesystem = ($this->storage ?? app(FilesystemFactory::class))->disk($disk);
        $path = (string) $artifact['path'];

        if (! $filesystem->exists($path)) {
            throw new RuntimeException('Video artifact vanished before checkpoint: '.$path);
        }

        if ((int) $filesystem->size($path) !== (int) $artifact['bytes']) {
            throw new RuntimeException('Video artifact changed size before checkpoint.');
        }

        $stream = $filesystem->readStream($path);

        if ($stream === false || $stream === null) {
            throw new RuntimeException('Cannot re-read the video artifact before checkpoint.');
        }

        $hash = hash_init('sha256');
        $read = 0;

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1048576);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $read += strlen($chunk);

                if ($read > (int) $artifact['bytes']) {
                    throw new RuntimeException('Video artifact grew before checkpoint.');
                }

                hash_update($hash, $chunk);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! hash_equals((string) $artifact['sha256'], hash_final($hash))) {
            throw new RuntimeException('Video artifact content changed before checkpoint.');
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function videoArtifact(array $manifest): array
    {
        foreach ((array) ($manifest['artifacts'] ?? []) as $candidate) {
            if (is_array($candidate) && ($candidate['kind'] ?? null) === 'generated_video') {
                return $candidate;
            }
        }

        throw new RuntimeException('Artifact manifest has no generated_video artifact.');
    }

    /**
     * Manifest la loi khai cua nguoi goi. Voi video, loi khai do phai doi chieu
     * duoc voi file that: thieu buoc nay thi mot callback co the danh dau thanh
     * cong bang mot manifest bia ra.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function verifyVideoArtifact(VideoRender $render, array $manifest): void
    {
        $artifact = $this->videoArtifact($manifest);

        foreach (['path', 'sha256', 'bytes', 'mime', 'duration_ms', 'width', 'height'] as $key) {
            if (($artifact[$key] ?? null) === null) {
                throw new RuntimeException('Video artifact missing '.$key);
            }
        }

        if ($artifact['mime'] !== 'video/mp4') {
            throw new RuntimeException('Video artifact mime is not video/mp4.');
        }

        foreach (['bytes', 'duration_ms', 'width', 'height'] as $key) {
            if (! is_int($artifact[$key]) || $artifact[$key] <= 0) {
                throw new RuntimeException('Video artifact '.$key.' must be a positive integer.');
            }
        }

        $disk = (string) config('video.veo.disk');
        $filesystem = ($this->storage ?? app(FilesystemFactory::class))->disk($disk);
        $path = (string) $artifact['path'];

        // File hop le cua MOT render khac van la file sai cho render nay.
        if (! str_starts_with($path, 'video/'.$render->id.'/')) {
            throw new RuntimeException('Video artifact does not belong to this render: '.$path);
        }

        if (! $filesystem->exists($path)) {
            throw new RuntimeException('Video artifact is not on disk '.$disk.': '.$path);
        }

        $temp = $this->spillToTemp($filesystem, $path);

        try {
            if ((int) filesize($temp) !== $artifact['bytes']) {
                throw new RuntimeException('Video artifact size does not match the manifest.');
            }

            if (! hash_equals((string) $artifact['sha256'], (string) hash_file('sha256', $temp))) {
                throw new RuntimeException('Video artifact checksum does not match the manifest.');
            }

            $this->assertMeasured($artifact, $temp);
        } finally {
            @unlink($temp);
        }
    }

    /**
     * Doc theo tung khuc ra file tam. Clip duoc phep nang toi VIDEO_VEO_MAX_BYTES,
     * lon hon memory_limit thuong thay, nen khong bao gio nap ca file vao bien.
     */
    private function spillToTemp(\Illuminate\Contracts\Filesystem\Filesystem $filesystem, string $path): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'ckpt_');

        if ($temp === false) {
            throw new RuntimeException('Cannot write a temp file to verify the video artifact.');
        }

        $maxBytes = (int) config('video.veo.max_bytes', 209715200);

        if ((int) $filesystem->size($path) > $maxBytes) {
            @unlink($temp);

            throw new RuntimeException('Video artifact is larger than the allowed size.');
        }

        $in = $filesystem->readStream($path);
        $out = fopen($temp, 'wb');

        if ($in === false || $in === null || $out === false) {
            @unlink($temp);

            throw new RuntimeException('Cannot read the video artifact from disk.');
        }

        $copied = 0;

        try {
            while (! feof($in)) {
                $chunk = fread($in, 1048576);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $copied += strlen($chunk);

                // `size()` co the noi doi voi mot adapter la, hoac file bi ghi them
                // trong luc doc. Dung ngay thay vi lam day dia tam.
                if ($copied > $maxBytes) {
                    throw new RuntimeException('Video artifact is larger than the allowed size.');
                }

                fwrite($out, $chunk);
            }
        } catch (RuntimeException $e) {
            fclose($out);

            if (is_resource($in)) {
                fclose($in);
            }

            @unlink($temp);

            throw $e;
        }

        fclose($out);

        if (is_resource($in)) {
            fclose($in);
        }

        return $temp;
    }

    /**
     * Duration va kich thuoc trong manifest la LOI KHAI. Do lai tu chinh file, neu
     * khong thi mot MP4 that van co the duoc khai kem so lieu bia.
     *
     * @param  array<string, mixed>  $artifact
     */
    private function assertMeasured(array $artifact, string $temp): void
    {
        $measured = ($this->probe ?? app(Mp4Probe::class))->inspect($temp);

        if (! $measured['ok']) {
            throw new RuntimeException('Video artifact cannot be measured: '.(string) $measured['error']);
        }

        $tolerance = (int) config('video.veo.duration_tolerance_ms', 1500);

        if (abs((int) $measured['duration_ms'] - (int) $artifact['duration_ms']) > $tolerance) {
            throw new RuntimeException(sprintf(
                'Video artifact duration does not match the manifest: file %dms, manifest %dms.',
                (int) $measured['duration_ms'],
                (int) $artifact['duration_ms'],
            ));
        }

        if ((int) $measured['width'] !== (int) $artifact['width']
            || (int) $measured['height'] !== (int) $artifact['height']) {
            throw new RuntimeException('Video artifact dimensions do not match the manifest.');
        }
    }

    private function primaryArtifactHash(VideoRender $render, array $manifest): string
    {
        $kind = $render->render_kind === 'video' ? 'generated_video' : 'generated_image';

        foreach ($manifest['artifacts'] as $artifact) {
            if (($artifact['kind'] ?? null) === $kind) {
                $hash = $artifact['sha256'] ?? null;

                if (is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash)) {
                    return $hash;
                }
            }
        }

        throw new RuntimeException('No '.$kind.' artifact found.');
    }
}

