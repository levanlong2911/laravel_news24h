<?php

namespace Tests\Feature\Video;

use App\Enums\VideoShotStatus;
use App\Models\VideoProject;
use App\Models\VideoSession;
use App\Models\VideoShot;
use App\Repositories\Interfaces\VideoShotRepositoryInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class VideoShotClaimTest extends TestCase
{
    use DatabaseTransactions;

    private VideoShotRepositoryInterface $shots;

    private VideoSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shots = app(VideoShotRepositoryInterface::class);
        $project = VideoProject::create(['name' => 'TEST claim '.uniqid()]);
        $this->session = VideoSession::create([
            'project_id' => $project->id,
            'code' => 'test_claim_'.uniqid(),
            'status' => 'rendering',
        ]);
    }

    private function shot(VideoSession $session, string $code): VideoShot
    {
        return VideoShot::create([
            'session_id' => $session->id,
            'beat' => $code,
            'shot_code' => $code,
            'shot_type' => 'establish',
            'kind' => 'motion',
            'spec_json' => [],
            'compiled_prompt' => "prompt {$code}",
            'status' => VideoShotStatus::QUEUED->value,
        ]);
    }

    public function test_a_batch_claim_is_atomic_and_cannot_be_claimed_again(): void
    {
        $first = $this->shot($this->session, 'first');
        $second = $this->shot($this->session, 'second');

        $claimed = $this->shots->claimForSession(
            $this->session->id,
            'worker-a',
            '11111111-1111-4111-8111-111111111111',
            10,
            now()->addMinutes(10),
        );
        $claimedAgain = $this->shots->claimForSession(
            $this->session->id,
            'worker-b',
            '22222222-2222-4222-8222-222222222222',
            10,
            now()->addMinutes(10),
        );

        $this->assertCount(2, $claimed);
        $this->assertCount(0, $claimedAgain);
        $this->assertSame(
            [$first->id, $second->id],
            $claimed->pluck('id')->all(),
        );
        $this->assertTrue($claimed->every(
            fn (VideoShot $shot) => $shot->status === VideoShotStatus::CLAIMED->value
                && $shot->worker_id === 'worker-a'
                && $shot->claim_token === '11111111-1111-4111-8111-111111111111',
        ));
    }

    public function test_claim_respects_batch_limit_and_session_boundary(): void
    {
        $this->shot($this->session, 'first');
        $this->shot($this->session, 'second');

        $other = VideoSession::create([
            'project_id' => $this->session->project_id,
            'code' => 'test_claim_other_'.uniqid(),
            'status' => 'rendering',
        ]);
        $foreign = $this->shot($other, 'foreign');

        $claimed = $this->shots->claimForSession(
            $this->session->id,
            'worker-a',
            '33333333-3333-4333-8333-333333333333',
            1,
            now()->addMinutes(10),
        );

        $this->assertCount(1, $claimed);
        $this->assertSame($this->session->id, $claimed->first()->session_id);
        $this->assertSame(VideoShotStatus::QUEUED->value, $foreign->fresh()->status);
        $this->assertSame(1, VideoShot::query()
            ->where('session_id', $this->session->id)
            ->where('status', VideoShotStatus::QUEUED->value)
            ->count());
    }
}
