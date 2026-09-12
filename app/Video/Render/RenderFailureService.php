<?php

declare(strict_types=1);

namespace App\Video\Render;

use App\Models\VideoRender;
use App\Models\VideoRenderAttempt;
use App\Video\Concept\Support\Clock;
use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderFailureClass;
use App\Video\Render\Enums\RenderStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RenderFailureService
{
    public function __construct(
        private readonly Clock $clock,
    ) {
    }

    public function fail(
        string $renderId,
        string $claimToken,
        RenderFailureClass $failureClass,
        string $errorCode,
        string $message,
    ): void {
        DB::transaction(function () use ($renderId, $claimToken, $failureClass, $errorCode, $message): void {
            $render = VideoRender::query()->whereKey($renderId)->lockForUpdate()->firstOrFail();

            if ($render->isTerminal()) {
                return;
            }

            if (! hash_equals((string) $render->claim_token, $claimToken)) {
                throw new RuntimeException('Claim token mismatch.');
            }

            $now = $this->clock->now();
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

