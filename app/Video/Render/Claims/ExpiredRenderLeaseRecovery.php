<?php

declare(strict_types=1);

namespace App\Video\Render\Claims;

use App\Models\VideoRender;
use App\Models\VideoRenderAttempt;
use App\Video\Concept\Support\Clock;
use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderFailureClass;
use App\Video\Render\Enums\RenderStatus;
use Illuminate\Support\Facades\DB;

final class ExpiredRenderLeaseRecovery
{
    public function __construct(
        private readonly Clock $clock,
    ) {
    }

    public function recover(int $limit = 100): int
    {
        $now = $this->clock->now();
        $ids = VideoRender::query()
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', $now)
            ->whereIn('execution_status', [
                RenderStatus::CLAIMED->value,
                RenderStatus::PREPARING->value,
                RenderStatus::SUBMITTING->value,
                RenderStatus::SUBMITTED->value,
            ])
            ->limit($limit)
            ->pluck('id');

        $count = 0;

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $now, &$count): void {
                $render = VideoRender::query()->whereKey($id)->lockForUpdate()->first();

                if ($render === null || $render->lease_expires_at === null || $render->lease_expires_at->greaterThan($now)) {
                    return;
                }

                $safeBeforeSubmission = in_array($render->execution_status, [
                    RenderStatus::CLAIMED,
                    RenderStatus::PREPARING,
                ], true);

                $attempt = VideoRenderAttempt::query()
                    ->where('render_id', $render->id)
                    ->where('attempt_no', $render->attempt_count)
                    ->lockForUpdate()
                    ->first();

                if ($safeBeforeSubmission) {
                    if ($attempt !== null) {
                        $attempt->forceFill([
                            'status' => RenderAttemptStatus::ABANDONED,
                            'error_code' => 'worker_lease_expired',
                            'error_message' => 'Worker lease expired before provider submission.',
                            'completed_at' => $now,
                        ])->save();
                    }

                    $render->forceFill([
                        'execution_status' => RenderStatus::RETRY_WAIT,
                        'claim_token' => null,
                        'claimed_by' => null,
                        'claimed_at' => null,
                        'heartbeat_at' => null,
                        'lease_expires_at' => null,
                        'next_retry_at' => $now,
                        'failure_class' => RenderFailureClass::TRANSIENT_NETWORK,
                        'failure_code' => 'worker_lease_expired_pre_submit',
                        'execution_version' => $render->execution_version + 1,
                    ])->save();

                    $count++;

                    return;
                }

                if ($attempt !== null) {
                    $attempt->forceFill([
                        'status' => RenderAttemptStatus::AMBIGUOUS,
                        'failure_class' => RenderFailureClass::AMBIGUOUS_PROVIDER_OUTCOME,
                        'error_code' => 'worker_lease_expired_post_submit',
                        'error_message' => 'Worker disappeared after provider submission may have occurred.',
                        'completed_at' => $now,
                    ])->save();
                }

                $render->forceFill([
                    'execution_status' => RenderStatus::PROVIDER_UNKNOWN,
                    'failure_class' => RenderFailureClass::AMBIGUOUS_PROVIDER_OUTCOME,
                    'failure_code' => 'provider_outcome_unknown',
                    'claim_token' => null,
                    'claimed_by' => null,
                    'claimed_at' => null,
                    'heartbeat_at' => null,
                    'lease_expires_at' => null,
                    'execution_version' => $render->execution_version + 1,
                ])->save();

                $count++;
            });
        }

        return $count;
    }
}

