<?php

declare(strict_types=1);

namespace App\Video\Render;

use App\Models\VideoRender;
use App\Models\VideoRenderAttempt;
use App\Video\Concept\Support\Clock;
use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderFailureClass;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\StateMachine\RenderStateMachine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RenderFailureService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RenderStateMachine $states = new RenderStateMachine,
    ) {
    }

    public function fail(
        string $renderId,
        string $claimToken,
        RenderFailureClass $failureClass,
        string $errorCode,
        string $message,
        ?string $workerId = null,
    ): void {
        DB::transaction(function () use ($renderId, $claimToken, $failureClass, $errorCode, $message, $workerId): void {
            $render = VideoRender::query()->whereKey($renderId)->lockForUpdate()->firstOrFail();

            if ($render->isTerminal()) {
                return;
            }

            if (! hash_equals((string) $render->claim_token, $claimToken)) {
                throw new RuntimeException('Claim token mismatch.');
            }

            if ($workerId !== null && $render->claimed_by !== $workerId) {
                throw new RuntimeException('Render worker ownership mismatch.');
            }

            $now = $this->clock->now();

            // Danh that bai cung la mot quyen ghi. Lease chet thi mat quyen do, va o
            // co the da sang tay nguoi khac.
            if ($render->lease_expires_at === null || $render->lease_expires_at->lessThanOrEqualTo($now)) {
                throw new RuntimeException('Render claim lease expired.');
            }

            $this->states->assert($render->execution_status, RenderStatus::FAILED);
            $attempt = VideoRenderAttempt::query()
                ->where('render_id', $render->id)
                ->where('attempt_no', $render->attempt_count)
                ->lockForUpdate()
                ->firstOrFail();

            $attempt->forceFill([
                'status' => RenderAttemptStatus::PERMANENT_FAILED,
                'failure_class' => $failureClass,
                'error_code' => $errorCode,
                'error_message' => $message,
                'completed_at' => $now,
            ])->save();

            $render->forceFill([
                'execution_status' => RenderStatus::FAILED,
                'failure_class' => $failureClass,
                'failure_code' => $errorCode,
                'failure_message' => $message,
                'claim_token' => null,
                'claimed_by' => null,
                'claimed_at' => null,
                'heartbeat_at' => null,
                'lease_expires_at' => null,
                'execution_completed_at' => $now,
                'execution_version' => $render->execution_version + 1,
            ])->save();
        });
    }
}

