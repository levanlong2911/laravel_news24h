<?php

namespace Tests\Feature\Video;

use App\Enums\VideoShotStatus;
use App\Models\VideoProject;
use App\Models\VideoSession;
use App\Models\VideoShot;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class VideoReclaimExpiredLeasesCommandTest extends TestCase
{
    use DatabaseTransactions;

    private function expiredShot(): VideoShot
    {
        $project = VideoProject::create(['title' => 'TEST reclaim cmd '.uniqid()]);
        $session = VideoSession::create([
            'project_id' => $project->id,
            'code' => 'test_reclaim_cmd_'.uniqid(),
            'status' => 'rendering',
        ]);

        return VideoShot::create([
            'session_id' => $session->id, 'beat' => 'b1', 'shot_code' => 's1', 'kind' => 'motion',
            'shot_type' => 'establish', 'spec_json' => [], 'compiled_prompt' => 'p',
            'status' => VideoShotStatus::CLAIMED->value,
            'worker_id' => 'dead-worker', 'claim_token' => 'dead-token',
            'claimed_at' => now()->subHours(2), 'lease_expires_at' => now()->subMinutes(5),
        ]);
    }

    public function test_requeues_a_shot_with_an_expired_lease(): void
    {
        $shot = $this->expiredShot();

        $this->artisan('video:reclaim-expired-leases')->assertExitCode(0);

        $fresh = $shot->fresh();
        $this->assertSame(VideoShotStatus::QUEUED->value, $fresh->status);
        $this->assertNull($fresh->worker_id);
        $this->assertNull($fresh->claim_token);
    }

    public function test_logs_when_shots_were_requeued(): void
    {
        Log::spy();
        $this->expiredShot();

        $this->artisan('video:reclaim-expired-leases');

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'video:reclaim-expired-leases')
                && ($context['requeued'] ?? 0) >= 1)
            ->once();
    }

    public function test_does_not_log_when_nothing_was_expired(): void
    {
        Log::spy();

        $this->artisan('video:reclaim-expired-leases');

        Log::shouldNotHaveReceived('info');
    }
}
