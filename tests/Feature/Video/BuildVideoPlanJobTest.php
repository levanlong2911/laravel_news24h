<?php

namespace Tests\Feature\Video;

use App\Jobs\BuildVideoPlanJob;
use App\Services\VideoSessionService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class BuildVideoPlanJobTest extends TestCase
{
    public function test_it_delegates_the_session_code_to_the_planning_service_once(): void
    {
        Log::spy();
        $service = Mockery::mock(VideoSessionService::class);
        $service->shouldReceive('runVideoPlanningPipeline')
            ->once()
            ->with('art_abc_260815')
            ->andReturn(true);

        (new BuildVideoPlanJob('art_abc_260815'))->handle($service);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) => $message === 'video:build-plan job: hoan tat'
                && $context['code'] === 'art_abc_260815'
                && is_int($context['duration_ms']))
            ->once();
    }

    public function test_it_does_not_retry_a_paid_pipeline_at_the_queue_layer(): void
    {
        $job = new BuildVideoPlanJob('art_abc');

        $this->assertSame(1, $job->tries);
        $this->assertSame(3600, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertGreaterThan($job->timeout, config('queue.connections.video.retry_after'));
    }
}
