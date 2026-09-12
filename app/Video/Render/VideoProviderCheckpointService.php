<?php

declare(strict_types=1);

namespace App\Video\Render;

use App\Models\VideoRender;
use App\Models\VideoRenderAttempt;
use App\Video\Concept\Support\Clock;
use App\Video\Render\Enums\RenderStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class VideoProviderCheckpointService
{
    public function __construct(private readonly Clock $clock)
    {
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

            if (! hash_equals((string) $render->claim_token, $claimToken)) {
                throw new RuntimeException('Provider-running claim token mismatch.');
            }

            VideoRenderAttempt::query()
                ->where('render_id', $render->id)
                ->where('attempt_no', $render->attempt_count)
                ->update([
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

            if (! hash_equals((string) $render->claim_token, $claimToken)) {
                throw new RuntimeException('Poll claim token mismatch.');
            }

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
}
