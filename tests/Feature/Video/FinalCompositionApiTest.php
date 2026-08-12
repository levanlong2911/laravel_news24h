<?php

namespace Tests\Feature\Video;

use App\Models\VideoFinal;
use App\Models\VideoFinalRender;
use App\Models\VideoProject;
use App\Models\VideoSession;
use App\Models\VideoShot;
use App\Services\VideoSessionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FinalCompositionApiTest extends TestCase
{
    use DatabaseTransactions;

    private VideoSession $session;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['video.api_token' => 'final-api-test-token']);
        $this->headers = ['X-Video-Token' => 'final-api-test-token'];

        $project = VideoProject::create(['name' => 'TEST final API '.uniqid()]);
        $this->session = VideoSession::create([
            'project_id' => $project->id,
            'code' => 'test_final_api_'.uniqid(),
            'status' => 'done',
            'renderplan_json' => [
                'timeline' => [['scene_id' => 'scene_a', 'start_sec' => 0, 'end_sec' => 5]],
            ],
        ]);
    }

    public function test_composing_endpoint_requires_token(): void
    {
        $this->getJson('/api/video-finals/composing?session_code='.$this->session->code)
            ->assertStatus(401);
    }

    public function test_composing_endpoint_returns_ordered_plan(): void
    {
        $shot = VideoShot::create([
            'session_id' => $this->session->id,
            'beat' => 'scene_a', 'shot_code' => 'scene_a', 'shot_type' => 'establish',
            'kind' => 'motion', 'spec_json' => [], 'compiled_prompt' => 'p',
        ]);
        app(VideoSessionService::class)->reportShotResult($shot->id, true, '/renders/shots/a/x.mp4', 0.18, [
            'render_kind' => 'video', 'provider' => 'fal', 'model' => 'veo',
            'sent_prompt' => 'a', 'prompt_sha256' => hash('sha256', 'a'), 'duration_ms' => 6000,
        ]);
        $final = VideoFinal::create(['session_id' => $this->session->id, 'status' => 'composing']);

        $response = $this->getJson(
            '/api/video-finals/composing?session_code='.$this->session->code,
            $this->headers,
        );

        $response->assertOk()
            ->assertJson(['status' => 'ok', 'final_id' => $final->id])
            ->assertJsonPath('clips.0.path', '/renders/shots/a/x.mp4');
    }

    public function test_result_endpoint_requires_token(): void
    {
        $final = VideoFinal::create(['session_id' => $this->session->id, 'status' => 'composing']);

        $this->patchJson("/api/video-finals/{$final->id}/result", ['success' => true])
            ->assertStatus(401);
    }

    public function test_result_endpoint_marks_final_ready_and_writes_cuts(): void
    {
        $shot = VideoShot::create([
            'session_id' => $this->session->id,
            'beat' => 'scene_a', 'shot_code' => 'scene_a', 'shot_type' => 'establish',
            'kind' => 'motion', 'spec_json' => [], 'compiled_prompt' => 'p',
        ]);
        app(VideoSessionService::class)->reportShotResult($shot->id, true, '/renders/shots/a/x.mp4', 0.18, [
            'render_kind' => 'video', 'provider' => 'fal', 'model' => 'veo',
            'sent_prompt' => 'a', 'prompt_sha256' => hash('sha256', 'a'), 'duration_ms' => 6000,
        ]);
        $final = VideoFinal::create(['session_id' => $this->session->id, 'status' => 'composing']);
        app(VideoSessionService::class)->buildFinalCompositionPlan($this->session->code);

        $response = $this->patchJson("/api/video-finals/{$final->id}/result", [
            'success' => true,
            'video_path' => '/renders/finals/x/x.mp4',
            'duration_ms' => 6000,
        ], $this->headers);

        $response->assertOk()->assertJson(['status' => 'ready']);
        $this->assertSame('ready', $final->fresh()->status);
        $this->assertCount(1, VideoFinalRender::where('final_id', $final->id)->get());
    }

    public function test_result_endpoint_marks_final_failed_with_error_message(): void
    {
        $final = VideoFinal::create(['session_id' => $this->session->id, 'status' => 'composing']);

        $response = $this->patchJson("/api/video-finals/{$final->id}/result", [
            'success' => false,
            'error' => 'ffmpeg: khong tim thay file',
        ], $this->headers);

        $response->assertOk()->assertJson(['status' => 'failed']);
        $this->assertSame('ffmpeg: khong tim thay file', $final->fresh()->error_message);
    }

    public function test_result_endpoint_rejects_success_without_video_path(): void
    {
        // #4: success=true nhung khong co video_path la du lieu vo nghia — phai
        // bi chan o tang validation, khong duoc lot vao service.
        $final = VideoFinal::create(['session_id' => $this->session->id, 'status' => 'composing']);

        $response = $this->patchJson("/api/video-finals/{$final->id}/result", [
            'success' => true,
        ], $this->headers);

        $response->assertStatus(422)->assertJsonValidationErrors(['video_path', 'duration_ms']);
        $this->assertSame('composing', $final->fresh()->status);
    }
}
