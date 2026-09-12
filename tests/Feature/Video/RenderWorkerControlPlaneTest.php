<?php

namespace Tests\Feature\Video;

use App\Models\VideoCostEntry;
use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Models\VideoRenderAttempt;
use App\Models\VideoSession;
use App\Video\Render\Claims\ExpiredRenderLeaseRecovery;
use App\Video\Render\Claims\RenderClaimService;
use App\Video\Render\DTO\RenderAttemptUsage;
use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderFailureClass;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\RenderCheckpointService;
use App\Video\Render\RenderDispatchService;
use App\Video\Render\RenderRetryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

class RenderWorkerControlPlaneTest extends TestCase
{
    use DatabaseTransactions;

    public function test_render_can_only_be_claimed_once(): void
    {
        $render = $this->queuedRender();

        $first = app(RenderClaimService::class)->claimNext('worker-A');
        $second = app(RenderClaimService::class)->claimNext('worker-B');

        self::assertNotNull($first);
        self::assertNull($second);

        $render->refresh();

        self::assertSame('worker-A', $render->claimed_by);
    }

    public function test_stale_claim_token_cannot_complete_render(): void
    {
        $render = $this->submittedRender(claimToken: '11111111-1111-1111-1111-111111111111');

        $render->forceFill([
            'claim_token' => '22222222-2222-2222-2222-222222222222',
        ])->save();

        $this->expectException(RuntimeException::class);

        app(RenderCheckpointService::class)->complete(
            renderId: $render->id,
            claimToken: '11111111-1111-1111-1111-111111111111',
            requestHash: $render->request_hash,
            providerRequestId: null,
            artifactManifest: $this->validManifest($render),
            usage: $this->emptyUsage(),
        );
    }

    public function test_complete_callback_can_be_replayed(): void
    {
        $render = $this->submittedRender();
        $usage = new RenderAttemptUsage(
            inputTokens: 100,
            outputTokens: 50,
            imageInputTokens: null,
            textInputTokens: 100,
            providerCostUsd: '0.02500000',
        );
        $service = app(RenderCheckpointService::class);

        $service->complete(
            renderId: $render->id,
            claimToken: $render->claim_token,
            requestHash: $render->request_hash,
            providerRequestId: 'provider-123',
            artifactManifest: $this->validManifest($render),
            usage: $usage,
        );

        $second = $service->complete(
            renderId: $render->id,
            claimToken: 'old-token',
            requestHash: $render->request_hash,
            providerRequestId: 'provider-123',
            artifactManifest: $this->validManifest($render),
            usage: $usage,
        );

        self::assertSame(RenderStatus::SUCCEEDED, $second->execution_status);
        self::assertSame(1, VideoCostEntry::query()->where('entity_type', 'render_attempt')->count());
    }

    public function test_idempotency_key_cannot_reference_different_request(): void
    {
        $session = $this->videoSession();
        $service = app(RenderDispatchService::class);
        $this->dispatch($service, $session->id, 'master:1', str_repeat('a', 64), '{"x":1}');

        $this->expectException(RuntimeException::class);

        $this->dispatch($service, $session->id, 'master:1', str_repeat('b', 64), '{"x":2}');
    }

    public function test_replayed_completion_does_not_duplicate_cost(): void
    {
        $this->test_complete_callback_can_be_replayed();
    }

    public function test_infrastructure_retry_preserves_exact_request(): void
    {
        $render = $this->queuedRender([
            'execution_status' => RenderStatus::RETRY_WAIT,
            'attempt_count' => 1,
            'next_retry_at' => now()->subSecond(),
        ]);
        $originalHash = $render->request_hash;

        $claim = app(RenderClaimService::class)->claimNext('worker-2');

        self::assertSame($originalHash, $claim->requestHash);
        self::assertSame(2, $claim->attemptNo);
    }

    public function test_retry_budget_exhaustion_marks_failed(): void
    {
        $render = $this->submittedRender([
            'attempt_count' => 3,
            'max_attempts' => 3,
        ]);

        app(RenderRetryService::class)->schedule(
            renderId: $render->id,
            claimToken: $render->claim_token,
            failureClass: RenderFailureClass::RATE_LIMIT,
            errorCode: 'rate_limit',
            message: 'Too many requests',
            retryAfterSeconds: 5,
        );

        $render->refresh();

        self::assertSame(RenderStatus::FAILED, $render->execution_status);
    }

    public function test_process_crash_before_submit_is_requeued(): void
    {
        $render = $this->submittedRender([
            'execution_status' => RenderStatus::PREPARING,
            'lease_expires_at' => now()->subMinute(),
        ]);

        app(ExpiredRenderLeaseRecovery::class)->recover();

        $render->refresh();

        self::assertSame(RenderStatus::RETRY_WAIT, $render->execution_status);
        self::assertSame(RenderAttemptStatus::ABANDONED, $render->attempts()->first()->status);
    }

    public function test_process_crash_after_submit_is_provider_unknown(): void
    {
        $render = $this->submittedRender([
            'execution_status' => RenderStatus::SUBMITTING,
            'lease_expires_at' => now()->subMinute(),
        ]);

        app(ExpiredRenderLeaseRecovery::class)->recover();

        $render->refresh();

        self::assertSame(RenderStatus::PROVIDER_UNKNOWN, $render->execution_status);
        self::assertSame(RenderAttemptStatus::AMBIGUOUS, $render->attempts()->first()->status);
    }

    private function videoSession(): VideoSession
    {
        $project = VideoProject::create(['title' => 'Render worker '.uniqid()]);

        return VideoSession::create([
            'project_id' => $project->id,
            'code' => 'render_worker_'.uniqid(),
            'status' => 'planning',
        ]);
    }

    private function queuedRender(array $overrides = []): VideoRender
    {
        return VideoRender::create(array_merge([
            'video_session_id' => $this->videoSession()->id,
            'asset_id' => 'master',
            'render_kind' => 'image',
            'provider' => 'openai',
            'model' => 'gpt-image-2',
            'sent_prompt' => '',
            'prompt_sha256' => str_repeat('1', 64),
            'request_hash' => str_repeat('a', 64),
            'render_request_json' => '{"version":"render-request-v1"}',
            'idempotency_key' => 'master:'.uniqid(),
            'execution_status' => RenderStatus::QUEUED,
            'attempt_count' => 0,
            'max_attempts' => 3,
        ], $overrides));
    }

    private function submittedRender(array $overrides = [], string $claimToken = '11111111-1111-1111-1111-111111111111'): VideoRender
    {
        $render = $this->queuedRender(array_merge([
            'execution_status' => RenderStatus::SUBMITTED,
            'claim_token' => $claimToken,
            'claim_generation' => 1,
            'claimed_by' => 'worker-A',
            'lease_expires_at' => now()->addMinute(),
            'attempt_count' => 1,
        ], $overrides));

        VideoRenderAttempt::create([
            'render_id' => $render->id,
            'attempt_no' => $render->attempt_count,
            'status' => RenderAttemptStatus::SUBMITTED,
            'provider_key' => $render->provider,
            'model_key' => $render->model,
            'request_hash' => $render->request_hash,
            'base_semantic_hash' => $render->request_hash,
            'claim_token' => $claimToken,
            'claim_generation' => 1,
            'worker_id' => 'worker-A',
            'started_at' => now(),
        ]);

        return $render;
    }

    private function validManifest(VideoRender $render): array
    {
        return [
            'render_id' => $render->id,
            'request_hash' => $render->request_hash,
            'canonical_hash' => $render->canonical_hash,
            'artifacts' => [
                [
                    'kind' => 'generated_image',
                    'sha256' => str_repeat('b', 64),
                ],
            ],
        ];
    }

    private function emptyUsage(): RenderAttemptUsage
    {
        return new RenderAttemptUsage(null, null, null, null, null);
    }

    private function dispatch(
        RenderDispatchService $service,
        string $sessionId,
        string $idempotencyKey,
        string $requestHash,
        string $requestJson,
    ): VideoRender {
        return $service->create(
            sessionId: $sessionId,
            assetId: 'master',
            provider: 'openai',
            model: 'gpt-image-2',
            idempotencyKey: $idempotencyKey,
            requestJson: $requestJson,
            requestHash: $requestHash,
            canonicalRevisionId: '11111111-1111-1111-1111-111111111111',
            canonicalHash: str_repeat('c', 64),
            projectionHash: str_repeat('d', 64),
            constraintSetHash: str_repeat('e', 64),
            promptSpecHash: str_repeat('f', 64),
            providerPromptPlanHash: str_repeat('0', 64),
            promptHash: str_repeat('1', 64),
        );
    }
}
