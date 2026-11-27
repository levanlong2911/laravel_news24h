<?php

declare(strict_types=1);

namespace App\Video\Render\Attempts;

use App\Models\VideoRender;
use App\Models\VideoRenderAttempt;
use App\Repositories\Interfaces\VideoRenderAttemptRepositoryInterface;
use App\Repositories\Interfaces\VideoRenderRepositoryInterface;
use App\Video\Concept\Support\Clock;
use App\Video\Render\Claims\RenderClaimLostException;
use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderFailureClass;
use App\Video\Render\Enums\RenderStatus;
use Illuminate\Support\Facades\DB;

final class RenderAttemptService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly VideoRenderRepositoryInterface $renders,
        private readonly VideoRenderAttemptRepositoryInterface $attempts,
    ) {
    }

    public function markPreparing(string $renderId, string $claimToken): void
    {
        $this->transitionAttempt($renderId, $claimToken, RenderStatus::PREPARING, RenderAttemptStatus::PREPARING);
    }

    public function markSubmitting(string $renderId, string $claimToken): void
    {
        $this->transitionAttempt($renderId, $claimToken, RenderStatus::SUBMITTING, RenderAttemptStatus::SUBMITTING);
    }

    public function markSubmitted(string $renderId, string $claimToken, ?string $providerRequestId): void
    {
        DB::transaction(function () use ($renderId, $claimToken, $providerRequestId): void {
            [$render, $attempt] = $this->lockCurrent($renderId, $claimToken);
            $now = $this->clock->now();

            $this->renders->saveRender($render, [
                'execution_status' => RenderStatus::SUBMITTED,
                'provider_request_id' => $providerRequestId,
                'execution_version' => $render->execution_version + 1,
            ]);

            $this->attempts->saveAttempt($attempt, [
                'status' => RenderAttemptStatus::SUBMITTED,
                'provider_request_id' => $providerRequestId,
                'submitted_at' => $now,
            ]);
        });
    }

    public function markAmbiguous(string $renderId, string $claimToken, string $code, string $message): void
    {
        DB::transaction(function () use ($renderId, $claimToken, $code, $message): void {
            [$render, $attempt] = $this->lockCurrent($renderId, $claimToken);
            $now = $this->clock->now();

            $this->renders->saveRender($render, [
                'execution_status' => RenderStatus::PROVIDER_UNKNOWN,
                'failure_class' => RenderFailureClass::AMBIGUOUS_PROVIDER_OUTCOME,
                'failure_code' => $code,
                'failure_message' => $message,
                'claim_token' => null,
                'claimed_by' => null,
                'lease_expires_at' => null,
                'heartbeat_at' => null,
                'execution_version' => $render->execution_version + 1,
            ]);

            $this->attempts->saveAttempt($attempt, [
                'status' => RenderAttemptStatus::AMBIGUOUS,
                'failure_class' => RenderFailureClass::AMBIGUOUS_PROVIDER_OUTCOME,
                'error_code' => $code,
                'error_message' => $message,
                'completed_at' => $now,
            ]);
        });
    }

    private function transitionAttempt(
        string $renderId,
        string $claimToken,
        RenderStatus $renderStatus,
        RenderAttemptStatus $attemptStatus,
    ): void {
        DB::transaction(function () use ($renderId, $claimToken, $renderStatus, $attemptStatus): void {
            [$render, $attempt] = $this->lockCurrent($renderId, $claimToken);

            $this->renders->saveRender($render, [
                'execution_status' => $renderStatus,
                'execution_version' => $render->execution_version + 1,
            ]);

            $this->attempts->saveAttempt($attempt, [
                'status' => $attemptStatus,
            ]);
        });
    }

    /**
     * @return array{VideoRender,VideoRenderAttempt}
     */
    private function lockCurrent(string $renderId, string $claimToken): array
    {
        $render = $this->renders->lockRenderOrFail($renderId);

        if (! hash_equals((string) $render->claim_token, $claimToken)) {
            throw new RenderClaimLostException('Claim token mismatch.');
        }

        // Doi trang thai attempt cung la mot quyen ghi: lease chet la mat quyen do.
        $now = $this->clock->now();

        if ($render->lease_expires_at === null || $render->lease_expires_at->lessThanOrEqualTo($now)) {
            throw new RenderClaimLostException('Render claim lease expired.');
        }

        $attempt = $this->attempts->lockCurrentAttemptOrFail($render->id, $render->attempt_count);

        return [$render, $attempt];
    }
}

