<?php

declare(strict_types=1);

namespace App\Video\Render;

use App\Models\VideoProviderSubmissionReceipt;
use App\Models\VideoRender;
use App\Models\VideoRenderAttempt;
use App\Video\Concept\Support\Clock;
use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\StateMachine\RenderStateMachine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class VideoProviderCheckpointService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RenderStateMachine $states = new RenderStateMachine,
    ) {
    }

    /**
     * Lease chet la quyen ghi da mat. Chi so claim_token thi mot request cham van
     * ghi de duoc len o da thuoc ve luot khac.
     */
    private function assertHoldsLease(VideoRender $render, string $claimToken): void
    {
        if (! hash_equals((string) $render->claim_token, $claimToken)) {
            throw new RuntimeException('Claim token mismatch.');
        }

        if ($render->lease_expires_at === null || $render->lease_expires_at <= $this->clock->now()) {
            throw new RuntimeException('Lease da het han — khong con quyen ghi.');
        }
    }

    /** @param array<string, mixed> $submitResponse */
    public function markProviderRunning(
        VideoRender $render,
        string $claimToken,
        string $providerJobId,
        ?string $providerRequestId,
        array $submitResponse,
    ): VideoRender {
        return DB::transaction(function () use (
            $render,
            $claimToken,
            $providerJobId,
            $providerRequestId,
            $submitResponse,
        ): VideoRender {
            $render = VideoRender::query()->whereKey($render->id)->lockForUpdate()->firstOrFail();

            $this->assertHoldsLease($render, $claimToken);
            $this->states->assert($render->execution_status, RenderStatus::PROVIDER_RUNNING);

            VideoRenderAttempt::query()
                ->where('render_id', $render->id)
                ->where('attempt_no', $render->attempt_count)
                ->update([
                    'status' => RenderAttemptStatus::SUBMITTED,
                    'provider_job_id' => $providerJobId,
                    'provider_request_id' => $providerRequestId,
                    'provider_submit_response_json' => $submitResponse,
                    'submitted_at' => $this->clock->now(),
                ]);

            $render->forceFill([
                'execution_status' => RenderStatus::PROVIDER_RUNNING,
                'provider_job_id' => $providerJobId,
                'provider_request_id' => $providerRequestId ?? $render->provider_request_id,
                'provider_submit_response_json' => $submitResponse,
                'claim_token' => null,
                'claimed_by' => null,
                'claimed_at' => null,
                'heartbeat_at' => null,
                'lease_expires_at' => null,
                'provider_next_poll_at' => $this->clock->now()->modify('+30 seconds'),
                'execution_version' => $render->execution_version + 1,
            ])->save();

            return $render;
        }, attempts: 3);
    }

    public function claimPoll(VideoRender $render, string $claimToken, string $workerId): VideoRender
    {
        return DB::transaction(function () use ($render, $claimToken, $workerId): VideoRender {
            $render = VideoRender::query()->whereKey($render->id)->lockForUpdate()->firstOrFail();

            if ($render->execution_status !== RenderStatus::PROVIDER_RUNNING) {
                throw new RuntimeException('Only provider-running renders can be polled.');
            }

            if ($render->lease_expires_at !== null && $render->lease_expires_at > $this->clock->now()) {
                throw new RuntimeException('Render dang co nguoi giu lease.');
            }

            $this->states->assert($render->execution_status, RenderStatus::POLLING);

            if (! is_string($render->provider_job_id) || $render->provider_job_id === '') {
                throw new RuntimeException('Provider job id is missing.');
            }

            $now = $this->clock->now();

            $render->forceFill([
                'execution_status' => RenderStatus::POLLING,
                'claim_token' => $claimToken,
                'claimed_by' => $workerId,
                'claimed_at' => $now,
                'lease_expires_at' => $now->modify('+2 minutes'),
                'execution_version' => $render->execution_version + 1,
            ])->save();

            return $render;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $pollResponse */
    public function markStillRunning(VideoRender $render, string $claimToken, array $pollResponse): VideoRender
    {
        return DB::transaction(function () use ($render, $claimToken, $pollResponse): VideoRender {
            $render = VideoRender::query()->whereKey($render->id)->lockForUpdate()->firstOrFail();

            $this->assertHoldsLease($render, $claimToken);
            $this->states->assert($render->execution_status, RenderStatus::PROVIDER_RUNNING);

            $now = $this->clock->now();

            $render->forceFill([
                'execution_status' => RenderStatus::PROVIDER_RUNNING,
                'provider_poll_count' => $render->provider_poll_count + 1,
                'provider_last_polled_at' => $now,
                'provider_next_poll_at' => $now->modify('+30 seconds'),
                'provider_last_poll_response_json' => $pollResponse,
                'claim_token' => null,
                'claimed_by' => null,
                'claimed_at' => null,
                'heartbeat_at' => null,
                'lease_expires_at' => null,
                'execution_version' => $render->execution_version + 1,
            ])->save();

            return $render;
        }, attempts: 3);
    }

    /**
     * Provider da nhan job nhung ta mat quyen ghi truoc khi kip danh dau. Job id da
     * duoc giu lai, nen day la duong DUY NHAT dua no tro lai vong poll — va chi khi
     * khong con ai giu lease.
     */
    public function adoptKnownJob(VideoRender $render): ?VideoRender
    {
        return DB::transaction(function () use ($render): ?VideoRender {
            $render = VideoRender::query()->whereKey($render->id)->lockForUpdate()->firstOrFail();

            if ($render->lease_expires_at !== null && $render->lease_expires_at > $this->clock->now()) {
                return null;
            }

            if (! in_array($render->execution_status, [
                RenderStatus::SUBMITTING,
                RenderStatus::SUBMITTED,
                RenderStatus::PROVIDER_UNKNOWN,
            ], true)) {
                return null;
            }

            // Mot luot moi hon dang chay thi job cu khong con la viec cua hang nay.
            $newer = VideoRenderAttempt::query()
                ->where('render_id', $render->id)
                ->where('attempt_no', '>', $render->attempt_count)
                ->exists();

            if ($newer) {
                return null;
            }

            $attempt = VideoRenderAttempt::query()
                ->where('render_id', $render->id)
                ->where('attempt_no', $render->attempt_count)
                ->lockForUpdate()
                ->first();

            // Cot tren render chi co khi CAS chinh da thanh cong. Khi no that bai,
            // bien lai append-only la nguon su that: no dinh danh dung attempt va
            // dung generation da tra tien.
            if ($attempt === null) {
                return null;
            }

            $jobId = is_string($render->provider_job_id) && $render->provider_job_id !== ''
                ? $render->provider_job_id
                : $this->jobFromReceipts($render, $attempt);

            if (! is_string($jobId) || $jobId === '') {
                return null;
            }

            $this->states->assert($render->execution_status, RenderStatus::PROVIDER_RUNNING);

            $attempt?->forceFill([
                'status' => RenderAttemptStatus::SUBMITTED,
                'provider_job_id' => $jobId,
                'submitted_at' => $attempt->submitted_at ?? $this->clock->now(),
            ])->save();

            $render->forceFill([
                'execution_status' => RenderStatus::PROVIDER_RUNNING,
                'provider_job_id' => $jobId,
                'claim_token' => null,
                'claimed_by' => null,
                'claimed_at' => null,
                'heartbeat_at' => null,
                'lease_expires_at' => null,
                'provider_next_poll_at' => $this->clock->now(),
                'execution_version' => $render->execution_version + 1,
            ])->save();

            return $render;
        }, attempts: 3);
    }

    /**
     * Mot generation chi duoc co dung MOT job. Nhieu job mau thuan nghia la co hai
     * khoan tien da tieu ma ta khong biet chon cai nao — khong duoc doan, phai de
     * nguoi doi soat.
     */
    private function jobFromReceipts(VideoRender $render, VideoRenderAttempt $attempt): ?string
    {
        if (is_string($attempt->provider_job_id) && $attempt->provider_job_id !== '') {
            return $attempt->provider_job_id;
        }

        // Generation lay tu ATTEMPT: `$render->claim_generation` da tang moi lan co
        // nguoi claim lai, nen no khong con noi ve luot da tra tien.
        $jobs = VideoProviderSubmissionReceipt::query()
            ->where('render_id', $render->id)
            ->where('attempt_id', $attempt->id)
            ->where('claim_generation', $attempt->claim_generation)
            ->pluck('provider_job_id')
            ->unique()
            ->values();

        return $jobs->count() === 1 ? (string) $jobs->first() : null;
    }

    /**
     * Giu lease de tai file. Response cua luot poll cuoi la thu duy nhat chung minh
     * URI den tu dau, nen no phai duoc luu TRUOC khi tai.
     *
     * @param  array<string, mixed>  $pollResponse
     */
    public function markArtifactWriting(VideoRender $render, string $claimToken, array $pollResponse): VideoRender
    {
        return DB::transaction(function () use ($render, $claimToken, $pollResponse): VideoRender {
            $render = VideoRender::query()->whereKey($render->id)->lockForUpdate()->firstOrFail();

            $this->assertHoldsLease($render, $claimToken);
            $this->states->assert($render->execution_status, RenderStatus::ARTIFACT_WRITING);

            $now = $this->clock->now();

            $render->forceFill([
                'execution_status' => RenderStatus::ARTIFACT_WRITING,
                'provider_poll_count' => $render->provider_poll_count + 1,
                'provider_last_polled_at' => $now,
                'provider_last_poll_response_json' => $pollResponse,
                'execution_version' => $render->execution_version + 1,
            ])->save();

            return $render;
        }, attempts: 3);
    }
}