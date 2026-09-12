<?php

declare(strict_types=1);

namespace App\Video\Render;

use App\Models\VideoRender;
use App\Models\VideoRenderAttempt;
use App\Video\Concept\Support\Clock;
use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderFailureClass;
use App\Video\Render\Enums\RenderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RenderRetryService
{
    public function __construct(
        private readonly Clock $clock,
    ) {
    }

    public function schedule(
        string $renderId,
        string $claimToken,
        RenderFailureClass $failureClass,
        string $errorCode,
        string $message,
        float $retryAfterSeconds,
    ): void {
        DB::transaction(function () use (
            $renderId,
            $claimToken,
            $failureClass,
            $errorCode,
            $message,
            $retryAfterSeconds,
        ): void {
            $render = VideoRender::query()->whereKey($renderId)->lockForUpdate()->firstOrFail();

            if (! hash_equals((string) $render->claim_token, $claimToken)) {
                throw new RuntimeException('Claim token mismatch.');
            }

            $attempt = VideoRenderAttempt::query()
                ->where('render_id', $render->id)
                ->where('attempt_no', $render->attempt_count)
                ->lockForUpdate()
                ->firstOrFail();

            $now = $this->clock->now();

            if ($render->attempt_count >= $render->max_attempts) {
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
                    'failure_code' => 'retry_budget_exhausted',
                    'failure_message' => $message,
                    'claim_token' => null,
                    'claimed_by' => null,
                    'heartbeat_at' => null,
                    'lease_expires_at' => null,
                    'execution_completed_at' => $now,
                    'execution_version' => $render->execution_version + 1,
                ])->save();

                return;
            }

            $attempt->forceFill([
                'status' => RenderAttemptStatus::RETRYABLE_FAILED,
                'failure_class' => $failureClass,
                'error_code' => $errorCode,
                'error_message' => $message,
                'completed_at' => $now,
            ])->save();

            $nextRetryAt = CarbonImmutable::instance($now)->addMilliseconds((int) round($retryAfterSeconds * 1000));

            $render->forceFill([
                'execution_status' => RenderStatus::RETRY_WAIT,
                'failure_class' => $failureClass,
                'failure_code' => $errorCode,
                'failure_message' => $message,
                'next_retry_at' => $nextRetryAt,
                'claim_token' => null,
                'claimed_by' => null,
                'claimed_at' => null,
                'heartbeat_at' => null,
                'lease_expires_at' => null,
                'execution_version' => $render->execution_version + 1,
            ])->save();
        });
    }
}

