<?php

declare(strict_types=1);

namespace App\Video\Render\Claims;

use App\Models\VideoRender;
use App\Models\VideoRenderAttempt;
use App\Video\Concept\Support\Clock;
use App\Video\Render\DTO\RenderClaim;
use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\StateMachine\RenderStateMachine;
use DateInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class RenderClaimService
{
    public function __construct(
        private readonly RenderStateMachine $states,
        private readonly Clock $clock,
        private readonly int $leaseSeconds = 120,
    ) {
        if ($this->leaseSeconds < 30) {
            throw new RuntimeException('Render lease must be at least 30 seconds.');
        }
    }

    public function claimNext(string $workerId): ?RenderClaim
    {
        return DB::transaction(function () use ($workerId): ?RenderClaim {
            $render = $this->claimable()
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            return $render === null ? null : $this->claimRow($render, $workerId);
        }, attempts: 3);
    }

    /**
     * Che do dong bo goi thang dich vu nay chu khong qua HTTP, va no da biet
     * TRUOC minh vua tao hang nao. `claimNext()` lay hang cu nhat nen se cuop
     * mat hang cua luot khac dang doi.
     */
    public function claimById(string $renderId, string $workerId): ?RenderClaim
    {
        return DB::transaction(function () use ($renderId, $workerId): ?RenderClaim {
            $render = $this->claimable()
                ->whereKey($renderId)
                ->lockForUpdate()
                ->first();

            return $render === null ? null : $this->claimRow($render, $workerId);
        }, attempts: 3);
    }

    private function claimable(): Builder
    {
        $now = $this->clock->now();

        return VideoRender::query()
            ->where(function ($query) use ($now): void {
                $query
                    ->where('execution_status', RenderStatus::QUEUED->value)
                    ->orWhere(function ($retry) use ($now): void {
                        $retry
                            ->where('execution_status', RenderStatus::RETRY_WAIT->value)
                            ->where('next_retry_at', '<=', $now);
                    });
            })
            ->whereNotNull('request_hash')
            ->whereNotNull('render_request_json')
            ->where('attempt_count', '<', DB::raw('max_attempts'));
    }

    private function claimRow(VideoRender $render, string $workerId): RenderClaim
    {
        $now = $this->clock->now();

        $this->states->assert($render->execution_status, RenderStatus::CLAIMED);

        $attemptNo = $render->attempt_count + 1;
        $claimToken = (string) Str::uuid();
        $claimGeneration = $render->claim_generation + 1;
        $leaseExpiresAt = $now->add(new DateInterval('PT'.$this->leaseSeconds.'S'));

        $render->forceFill([
            'execution_status' => RenderStatus::CLAIMED,
            'claim_token' => $claimToken,
            'claim_generation' => $claimGeneration,
            'claimed_by' => $workerId,
            'claimed_at' => $now,
            'heartbeat_at' => $now,
            'lease_expires_at' => $leaseExpiresAt,
            'attempt_count' => $attemptNo,
            'next_retry_at' => null,
            'execution_started_at' => $render->execution_started_at ?? $now,
            'execution_version' => $render->execution_version + 1,
        ])->save();

        VideoRenderAttempt::query()->create([
            'render_id' => $render->id,
            'attempt_no' => $attemptNo,
            'status' => RenderAttemptStatus::CREATED,
            'provider_key' => $render->provider,
            'model_key' => $render->model,
            'request_hash' => $render->request_hash,
            'base_semantic_hash' => $render->request_hash,
            'claim_token' => $claimToken,
            'claim_generation' => $claimGeneration,
            'worker_id' => $workerId,
            'started_at' => $now,
        ]);

        return new RenderClaim(
            renderId: $render->id,
            claimToken: $claimToken,
            workerId: $workerId,
            attemptNo: $attemptNo,
            claimGeneration: $claimGeneration,
            leaseExpiresAt: $leaseExpiresAt,
            renderRequestJson: (string) $render->render_request_json,
            requestHash: (string) $render->request_hash,
        );
    }
}
