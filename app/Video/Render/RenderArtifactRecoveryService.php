<?php

declare(strict_types=1);

namespace App\Video\Render;

use App\Models\VideoRender;
use App\Models\VideoRenderAttempt;
use App\Video\Concept\Support\Clock;
use App\Video\Render\DTO\RenderRecoveryClaim;
use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\StateMachine\RenderStateMachine;
use DateInterval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class RenderArtifactRecoveryService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly int $leaseSeconds = 120,
        private readonly RenderStateMachine $states = new RenderStateMachine,
    ) {
    }

    public function claim(
        string $renderId,
        int $attemptNo,
        string $requestHash,
        string $workerId,
    ): RenderRecoveryClaim {
        return DB::transaction(function () use ($renderId, $attemptNo, $requestHash, $workerId): RenderRecoveryClaim {
            $render = VideoRender::query()->whereKey($renderId)->lockForUpdate()->firstOrFail();

            if ($render->execution_status === RenderStatus::SUCCEEDED) {
                throw new RuntimeException('Render already succeeded.');
            }

            if (! hash_equals((string) $render->request_hash, $requestHash)) {
                throw new RuntimeException('Recovery request hash mismatch.');
            }

            // Lease con song nghia la co tien trinh khac dang lam viec tren o nay.
            if ($render->lease_expires_at !== null && $render->lease_expires_at > $this->clock->now()) {
                throw new RuntimeException('Render dang co nguoi giu lease.');
            }

            $this->states->assert($render->execution_status, RenderStatus::CHECKPOINTING);

            $attempt = VideoRenderAttempt::query()
                ->where('render_id', $render->id)
                ->where('attempt_no', $attemptNo)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($attempt->status, [RenderAttemptStatus::SUBMITTED, RenderAttemptStatus::AMBIGUOUS], true)) {
                throw new RuntimeException('Attempt is not eligible for artifact recovery.');
            }

            $now = $this->clock->now();
            $token = (string) Str::uuid();
            $claimGeneration = $render->claim_generation + 1;
            $expires = $now->add(new DateInterval('PT'.$this->leaseSeconds.'S'));

            $render->forceFill([
                'claim_token' => $token,
                'claim_generation' => $claimGeneration,
                'claimed_by' => $workerId,
                'claimed_at' => $now,
                'heartbeat_at' => $now,
                'lease_expires_at' => $expires,
                'execution_status' => RenderStatus::CHECKPOINTING,
                'execution_version' => $render->execution_version + 1,
            ])->save();

            return new RenderRecoveryClaim(
                renderId: $render->id,
                attemptNo: $attemptNo,
                claimToken: $token,
                claimGeneration: $claimGeneration,
                leaseExpiresAt: $expires,
            );
        });
    }
}

