<?php

declare(strict_types=1);

namespace App\Video\Render\Claims;

use App\Repositories\Interfaces\VideoRenderAttemptRepositoryInterface;
use App\Repositories\Interfaces\VideoRenderRepositoryInterface;
use App\Video\Concept\Support\Clock;
use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderFailureClass;
use App\Video\Render\Enums\RenderStatus;
use Illuminate\Support\Facades\DB;

final class ExpiredRenderLeaseRecovery
{
    public function __construct(
        private readonly Clock $clock,
        private readonly VideoRenderRepositoryInterface $renders,
        private readonly VideoRenderAttemptRepositoryInterface $attempts,
    ) {
    }

    public function recover(int $limit = 100): int
    {
        $now = $this->clock->now();
        $ids = $this->renders->findExpiredLeaseIds($now, $limit);

        $count = 0;

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $now, &$count): void {
                $render = $this->renders->lockRender($id);

                if ($render === null || $render->lease_expires_at === null || $render->lease_expires_at->greaterThan($now)) {
                    return;
                }

                $safeBeforeSubmission = in_array($render->execution_status, [
                    RenderStatus::CLAIMED,
                    RenderStatus::PREPARING,
                ], true);

                $attempt = $this->attempts->lockCurrentAttempt($render->id, $render->attempt_count);

                if ($safeBeforeSubmission) {
                    if ($attempt !== null) {
                        $this->attempts->saveAttempt($attempt, [
                            'status' => RenderAttemptStatus::ABANDONED,
                            'error_code' => 'worker_lease_expired',
                            'error_message' => 'Worker lease expired before provider submission.',
                            'completed_at' => $now,
                        ]);
                    }

                    $this->renders->saveRender($render, [
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
                    ]);

                    $count++;

                    return;
                }

                if ($attempt !== null) {
                    $this->attempts->saveAttempt($attempt, [
                        'status' => RenderAttemptStatus::AMBIGUOUS,
                        'failure_class' => RenderFailureClass::AMBIGUOUS_PROVIDER_OUTCOME,
                        'error_code' => 'worker_lease_expired_post_submit',
                        'error_message' => 'Worker disappeared after provider submission may have occurred.',
                        'completed_at' => $now,
                    ]);
                }

                $this->renders->saveRender($render, [
                    'execution_status' => RenderStatus::PROVIDER_UNKNOWN,
                    'failure_class' => RenderFailureClass::AMBIGUOUS_PROVIDER_OUTCOME,
                    'failure_code' => 'provider_outcome_unknown',
                    'claim_token' => null,
                    'claimed_by' => null,
                    'claimed_at' => null,
                    'heartbeat_at' => null,
                    'lease_expires_at' => null,
                    'execution_version' => $render->execution_version + 1,
                ]);

                $count++;
            });
        }

        return $count;
    }
}

