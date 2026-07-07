<?php

namespace Tests\Unit\AI\Events;

use App\Events\AI\ArtifactStored;
use App\Events\AI\LoggableRenderEvent;
use App\Events\AI\RenderCompleted;
use App\Events\AI\RenderFailed;
use App\Events\AI\VideoSubmitted;
use PHPUnit\Framework\TestCase;

class RenderEventsTest extends TestCase
{
    // VideoSubmitted

    public function test_video_submitted_implements_loggable(): void
    {
        $this->assertInstanceOf(LoggableRenderEvent::class, $this->makeVideoSubmitted());
    }

    public function test_video_submitted_to_log(): void
    {
        $log = $this->makeVideoSubmitted()->toLog();

        $this->assertSame(1, $log['event_version']);
        $this->assertSame('run-1', $log['pipeline_run_id']);
        $this->assertSame('kling', $log['provider']);
        $this->assertSame('task-1', $log['provider_task_id']);
        $this->assertSame('2024-01-01T00:00:00+00:00', $log['submitted_at']);
    }

    // ArtifactStored

    public function test_artifact_stored_implements_loggable(): void
    {
        $this->assertInstanceOf(LoggableRenderEvent::class, $this->makeArtifactStored());
    }

    public function test_artifact_stored_to_log(): void
    {
        $log = $this->makeArtifactStored()->toLog();

        $this->assertSame(1, $log['event_version']);
        $this->assertSame('run-2', $log['pipeline_run_id']);
        $this->assertSame('task-2', $log['provider_task_id']);
        $this->assertSame('local', $log['storage_disk']);
        $this->assertSame('renders/run-2/task-2.mp4', $log['storage_path']);
        $this->assertSame('abc123', $log['checksum_sha256']);
        $this->assertSame(1048576, $log['file_size_bytes']);
        $this->assertSame('2024-06-01T10:00:00+00:00', $log['stored_at']);
    }

    // RenderCompleted

    public function test_render_completed_implements_loggable(): void
    {
        $this->assertInstanceOf(LoggableRenderEvent::class, $this->makeRenderCompleted());
    }

    public function test_render_completed_to_log_with_correlation_ids(): void
    {
        $event = new RenderCompleted(
            pipelineRunId:  'run-3',
            finalizedAt:    '2024-06-01T12:00:00+00:00',
            providerTaskId: 'task-3',
            provider:       'kling',
        );
        $log = $event->toLog();

        $this->assertSame(1, $log['event_version']);
        $this->assertSame('run-3', $log['pipeline_run_id']);
        $this->assertSame('task-3', $log['provider_task_id']);
        $this->assertSame('kling', $log['provider']);
        $this->assertSame('2024-06-01T12:00:00+00:00', $log['finalized_at']);
    }

    public function test_render_completed_correlation_ids_are_nullable(): void
    {
        $event = new RenderCompleted(pipelineRunId: 'run-3', finalizedAt: '2024-06-01T12:00:00+00:00');
        $log   = $event->toLog();

        $this->assertArrayHasKey('provider_task_id', $log);
        $this->assertNull($log['provider_task_id']);
        $this->assertNull($log['provider']);
    }

    // RenderFailed

    public function test_render_failed_implements_loggable(): void
    {
        $this->assertInstanceOf(LoggableRenderEvent::class, $this->makeRenderFailed());
    }

    public function test_render_failed_to_log_with_correlation_ids(): void
    {
        $event = new RenderFailed(
            pipelineRunId:  'run-4',
            reason:         'Something went wrong',
            failedAt:       '2024-06-01T15:00:00+00:00',
            jobClass:       'App\Jobs\AI\FinalizeRenderJob',
            providerTaskId: 'task-4',
            provider:       'kling',
        );
        $log = $event->toLog();

        $this->assertSame(1, $log['event_version']);
        $this->assertSame('run-4', $log['pipeline_run_id']);
        $this->assertSame('task-4', $log['provider_task_id']);
        $this->assertSame('kling', $log['provider']);
        $this->assertSame('Something went wrong', $log['reason']);
        $this->assertSame('2024-06-01T15:00:00+00:00', $log['failed_at']);
        $this->assertSame('App\Jobs\AI\FinalizeRenderJob', $log['job_class']);
    }

    public function test_render_failed_correlation_ids_are_nullable(): void
    {
        $log = $this->makeRenderFailed()->toLog();

        $this->assertArrayHasKey('provider_task_id', $log);
        $this->assertNull($log['provider_task_id']);
        $this->assertNull($log['provider']);
    }

    // VERSION constant

    public function test_all_events_expose_version_constant(): void
    {
        $this->assertSame(1, VideoSubmitted::VERSION);
        $this->assertSame(1, ArtifactStored::VERSION);
        $this->assertSame(1, RenderCompleted::VERSION);
        $this->assertSame(1, RenderFailed::VERSION);
    }

    // LogRenderEvent listener

    public function test_log_render_event_logs_via_class_basename(): void
    {
        $this->assertSame('VideoSubmitted', class_basename(VideoSubmitted::class));
        $this->assertSame('ArtifactStored', class_basename(ArtifactStored::class));
        $this->assertSame('RenderCompleted', class_basename(RenderCompleted::class));
        $this->assertSame('RenderFailed', class_basename(RenderFailed::class));
    }

    // Helpers

    private function makeVideoSubmitted(): VideoSubmitted
    {
        return new VideoSubmitted(
            pipelineRunId: 'run-1',
            provider:      'kling',
            taskId:        'task-1',
            submittedAt:   '2024-01-01T00:00:00+00:00',
        );
    }

    private function makeArtifactStored(): ArtifactStored
    {
        return new ArtifactStored(
            pipelineRunId: 'run-2',
            taskId:        'task-2',
            storageDisk:   'local',
            storagePath:   'renders/run-2/task-2.mp4',
            checksum:      'abc123',
            fileSizeBytes: 1048576,
            storedAt:      '2024-06-01T10:00:00+00:00',
        );
    }

    private function makeRenderCompleted(): RenderCompleted
    {
        return new RenderCompleted(pipelineRunId: 'run-3', finalizedAt: '2024-06-01T12:00:00+00:00');
    }

    private function makeRenderFailed(): RenderFailed
    {
        return new RenderFailed(
            pipelineRunId: 'run-4',
            reason:        'Something went wrong',
            failedAt:      '2024-06-01T15:00:00+00:00',
            jobClass:      'App\Jobs\AI\FinalizeRenderJob',
        );
    }
}
