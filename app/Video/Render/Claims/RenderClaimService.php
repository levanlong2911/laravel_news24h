<?php

declare(strict_types=1);

namespace App\Video\Render\Claims;

use App\Models\VideoRender;
use App\Repositories\Interfaces\VideoRenderAttemptRepositoryInterface;
use App\Repositories\Interfaces\VideoRenderRepositoryInterface;
use App\Video\Concept\Support\Clock;
use App\Video\Render\DTO\RenderClaim;
use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\StateMachine\RenderStateMachine;
use DateInterval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class RenderClaimService
{
    public function __construct(
        private readonly RenderStateMachine $states,
        private readonly Clock $clock,
        private readonly VideoRenderRepositoryInterface $renders,
        private readonly VideoRenderAttemptRepositoryInterface $attempts,
        private readonly int $leaseSeconds = 120,
    ) {
        if ($this->leaseSeconds < 30) {
            throw new RuntimeException('Render lease must be at least 30 seconds.');
        }
    }

    /** @return ?RenderClaim the oldest row an external worker may take; a row of a processor on this machine (provider MotoClipPlanBuilder::LOCAL) waits for its own job and is claimed only by id, never here */
    public function claimNext(string $workerId): ?RenderClaim
    {
        return DB::transaction(function () use ($workerId): ?RenderClaim {
            $render = $this->renders->lockNextClaimableForExternalWorker($this->clock->now());

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
            $render = $this->renders->lockClaimableById($renderId, $this->clock->now());

            return $render === null ? null : $this->claimRow($render, $workerId);
        }, attempts: 3);
    }

    private function claimRow(VideoRender $render, string $workerId): RenderClaim
    {
        $now = $this->clock->now();

        $this->states->assert($render->execution_status, RenderStatus::CLAIMED);

        $attemptNo = $render->attempt_count + 1;
        $claimToken = (string) Str::uuid();
        $claimGeneration = $render->claim_generation + 1;
        $leaseExpiresAt = $now->add(new DateInterval('PT'.$this->leaseSeconds.'S'));

        $this->renders->saveRender($render, [
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
        ]);

        $this->attempts->createAttempt([
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
