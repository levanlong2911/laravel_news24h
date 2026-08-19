<?php

namespace Tests\Feature\Video;

use App\Enums\VideoShotStatus;
use App\Models\VideoProject;
use App\Models\VideoSession;
use App\Models\VideoShot;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class VideoShotClaimApiTest extends TestCase
{
    use DatabaseTransactions;

    private VideoSession $session;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['video.api_token' => 'claim-api-test-token']);
        $this->headers = ['X-Video-Token' => 'claim-api-test-token'];

        $project = VideoProject::create(['title' => 'TEST claim API '.uniqid()]);
        $this->session = VideoSession::create([
            'project_id' => $project->id,
            'code' => 'test_claim_api_'.uniqid(),
            'status' => 'rendering',
        ]);
    }

    private function shot(string $code): VideoShot
    {
        return VideoShot::create([
            'session_id' => $this->session->id,
            'beat' => $code,
            'shot_code' => $code,
            'shot_type' => 'establish',
            'kind' => 'motion',
            'spec_json' => [],
            'compiled_prompt' => "prompt {$code}",
            'status' => VideoShotStatus::QUEUED->value,
        ]);
    }

    public function test_claim_endpoint_assigns_one_batch_and_a_second_worker_gets_nothing(): void
    {
        $this->shot('first');
        $this->shot('second');

        $first = $this->withHeaders($this->headers)->postJson('/api/video-shots/claim', [
            'session_code' => $this->session->code,
            'worker_id' => 'worker-a',
            'limit' => 10,
            'lease_seconds' => 600,
        ])->assertOk()->assertJsonCount(2, 'shots');

        $token = $first->json('claim_token');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);
        $this->assertSame(
            [$token],
            VideoShot::query()->where('session_id', $this->session->id)
                ->distinct()->pluck('claim_token')->all(),
        );

        $this->withHeaders($this->headers)->postJson('/api/video-shots/claim', [
            'session_code' => $this->session->code,
            'worker_id' => 'worker-b',
        ])->assertOk()->assertJsonCount(0, 'shots');
    }

    public function test_heartbeat_requires_the_current_owner_and_an_unexpired_lease(): void
    {
        $shot = $this->shot('heartbeat');
        $claim = $this->withHeaders($this->headers)->postJson('/api/video-shots/claim', [
            'session_code' => $this->session->code,
            'worker_id' => 'worker-a',
        ])->assertOk();
        $token = $claim->json('claim_token');

        $this->withHeaders($this->headers)->patchJson("/api/video-shots/{$shot->id}/heartbeat", [
            'worker_id' => 'worker-b',
            'claim_token' => $token,
        ])->assertStatus(409);

        $this->withHeaders($this->headers)->patchJson("/api/video-shots/{$shot->id}/heartbeat", [
            'worker_id' => 'worker-a',
            'claim_token' => $token,
            'lease_seconds' => 900,
        ])->assertOk()->assertJson(['status' => VideoShotStatus::RENDERING->value]);

        $fresh = $shot->fresh();
        $this->assertSame(VideoShotStatus::RENDERING->value, $fresh->status);
        $this->assertTrue($fresh->lease_expires_at->isFuture());
    }

    public function test_reclaim_returns_expired_claims_to_queue_and_clears_ownership(): void
    {
        $shot = $this->shot('expired');
        $shot->update([
            'status' => VideoShotStatus::RENDERING->value,
            'worker_id' => 'dead-worker',
            'claim_token' => '44444444-4444-4444-8444-444444444444',
            'claimed_at' => now()->subMinutes(20),
            'lease_expires_at' => now()->subMinute(),
        ]);

        $this->withHeaders($this->headers)->postJson('/api/video-shots/reclaim-expired')
            ->assertOk()
            ->assertJson(['requeued' => 1]);

        $fresh = $shot->fresh();
        $this->assertSame(VideoShotStatus::QUEUED->value, $fresh->status);
        $this->assertNull($fresh->worker_id);
        $this->assertNull($fresh->claim_token);
        $this->assertNull($fresh->claimed_at);
        $this->assertNull($fresh->lease_expires_at);
    }

    public function test_claim_endpoints_reject_a_bad_api_token(): void
    {
        $this->postJson('/api/video-shots/claim', [
            'session_code' => $this->session->code,
            'worker_id' => 'worker-a',
        ])->assertUnauthorized();

        $this->postJson('/api/video-shots/reclaim-expired')->assertUnauthorized();
    }

    public function test_result_accepts_the_current_owner_and_clears_the_claim(): void
    {
        $shot = $this->shot('owned-result');
        $claim = $this->withHeaders($this->headers)->postJson('/api/video-shots/claim', [
            'session_code' => $this->session->code,
            'worker_id' => 'worker-a',
        ])->assertOk();

        $this->withHeaders($this->headers)->patchJson("/api/video-shots/{$shot->id}/result", [
            'success' => true,
            'worker_id' => 'worker-a',
            'claim_token' => $claim->json('claim_token'),
        ])->assertOk()->assertJson(['status' => VideoShotStatus::RENDERED->value]);

        $fresh = $shot->fresh();
        $this->assertNull($fresh->worker_id);
        $this->assertNull($fresh->claim_token);
        $this->assertNull($fresh->lease_expires_at);
    }

    public function test_result_rejects_the_wrong_owner_without_mutating_the_shot(): void
    {
        $shot = $this->shot('wrong-owner');
        $claim = $this->withHeaders($this->headers)->postJson('/api/video-shots/claim', [
            'session_code' => $this->session->code,
            'worker_id' => 'worker-a',
        ])->assertOk();

        $this->withHeaders($this->headers)->patchJson("/api/video-shots/{$shot->id}/result", [
            'success' => true,
            'worker_id' => 'worker-b',
            'claim_token' => $claim->json('claim_token'),
        ])->assertStatus(409)->assertJson(['error' => 'claim_not_owned_or_expired']);

        $this->assertSame(VideoShotStatus::CLAIMED->value, $shot->fresh()->status);
    }

    public function test_result_rejects_an_expired_lease(): void
    {
        $shot = $this->shot('expired-result');
        $shot->update([
            'status' => VideoShotStatus::RENDERING->value,
            'worker_id' => 'worker-a',
            'claim_token' => '55555555-5555-4555-8555-555555555555',
            'claimed_at' => now()->subMinutes(20),
            'lease_expires_at' => now()->subMinute(),
        ]);

        $this->withHeaders($this->headers)->patchJson("/api/video-shots/{$shot->id}/result", [
            'success' => true,
            'worker_id' => 'worker-a',
            'claim_token' => $shot->claim_token,
        ])->assertStatus(409);

        $this->assertSame(VideoShotStatus::RENDERING->value, $shot->fresh()->status);
    }

    public function test_result_requires_ownership_fields_as_a_pair(): void
    {
        $shot = $this->shot('partial-owner');

        $this->withHeaders($this->headers)->patchJson("/api/video-shots/{$shot->id}/result", [
            'success' => true,
            'worker_id' => 'worker-a',
        ])->assertUnprocessable()->assertJsonValidationErrors('claim_token');
    }

    public function test_legacy_result_without_ownership_still_works_during_rollout(): void
    {
        $shot = $this->shot('legacy-result');

        $this->withHeaders($this->headers)->patchJson("/api/video-shots/{$shot->id}/result", [
            'success' => true,
        ])->assertOk()->assertJson(['status' => VideoShotStatus::RENDERED->value]);
    }

    public function test_reclaimed_shot_rejects_the_old_worker_callback(): void
    {
        $shot = $this->shot('reclaimed-result');
        $oldToken = '66666666-6666-4666-8666-666666666666';
        $shot->update([
            'status' => VideoShotStatus::RENDERING->value,
            'worker_id' => 'worker-old',
            'claim_token' => $oldToken,
            'claimed_at' => now()->subMinutes(20),
            'lease_expires_at' => now()->subMinute(),
        ]);
        $this->withHeaders($this->headers)->postJson('/api/video-shots/reclaim-expired')->assertOk();
        $newClaim = $this->withHeaders($this->headers)->postJson('/api/video-shots/claim', [
            'session_code' => $this->session->code,
            'worker_id' => 'worker-new',
        ])->assertOk();

        $this->withHeaders($this->headers)->patchJson("/api/video-shots/{$shot->id}/result", [
            'success' => true,
            'worker_id' => 'worker-old',
            'claim_token' => $oldToken,
        ])->assertStatus(409);

        $fresh = $shot->fresh();
        $this->assertSame('worker-new', $fresh->worker_id);
        $this->assertSame($newClaim->json('claim_token'), $fresh->claim_token);
        $this->assertSame(VideoShotStatus::CLAIMED->value, $fresh->status);
    }

    public function test_owned_idempotent_callback_can_retry_after_the_shot_is_terminal(): void
    {
        $shot = $this->shot('idempotent-owned-result');
        $claim = $this->withHeaders($this->headers)->postJson('/api/video-shots/claim', [
            'session_code' => $this->session->code,
            'worker_id' => 'worker-a',
        ])->assertOk();
        $payload = [
            'success' => true,
            'cost' => 0.18,
            'idempotency_key' => 'owned-attempt-one',
            'worker_id' => 'worker-a',
            'claim_token' => $claim->json('claim_token'),
        ];

        $this->withHeaders($this->headers)
            ->patchJson("/api/video-shots/{$shot->id}/result", $payload)->assertOk();
        $this->withHeaders($this->headers)
            ->patchJson("/api/video-shots/{$shot->id}/result", $payload)->assertOk();

        $this->assertEqualsWithDelta(0.18, (float) $this->session->fresh()->cost_actual, 0.0001);
        $this->assertSame(1, $shot->renders()->count());
    }
}
