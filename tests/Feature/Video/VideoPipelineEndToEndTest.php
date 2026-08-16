<?php

namespace Tests\Feature\Video;

use App\Enums\VideoSessionStatus;
use App\Enums\VideoShotStatus;
use App\Models\Admin;
use App\Models\Article;
use App\Services\VideoRenderPlanService;
use App\Services\VideoSessionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Đi hết state machine thật — planning -> composing -> reviewing -> rendering
 * -> done -> final composing -> ready — qua ĐÚNG các hàm/API mà Controller
 * và Python thật dùng, không chỉ gọi thẳng repository. Hai ranh giới duy
 * nhất bị thay: VideoRenderPlanService::build() (gọi Claude thật, tốn tiền —
 * mock giống VideoPlanningBackgroundTest) và tiến trình Python
 * (PythonRunner::spawn() tự no-op vì VIDEO_RUNNER_DIR không cấu hình trong
 * phpunit.xml, không cần mock riêng). Mọi bước còn lại — approve, queue,
 * claim, report kết quả, ghép final — đi qua HTTP thật (API Python gọi) hoặc
 * service thật (hành động admin), cùng DB thật, để bắt lệch hợp đồng giữa
 * các bước mà test đơn lẻ từng bước không thấy được.
 */
class VideoPipelineEndToEndTest extends TestCase
{
    use DatabaseTransactions;

    private array $apiHeaders;

    protected function setUp(): void
    {
        parent::setUp();
        config(['video.api_token' => 'e2e-test-token']);
        $this->apiHeaders = ['X-Video-Token' => 'e2e-test-token'];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function article(): Article
    {
        $keywordId = DB::table('keywords')->value('id');

        return Article::create([
            'keyword_id' => $keywordId,
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('', true)),
            'source_title' => 'TEST e2e source title',
            'title' => 'TEST e2e article '.uniqid(),
            'slug' => 'test-e2e-article-'.uniqid(),
            'content' => 'noi dung test e2e',
            'status' => 'pending',
        ]);
    }

    private function admin(): Admin
    {
        $roleId = DB::table('roles')->value('id');

        return Admin::create([
            'name' => 'TEST e2e admin '.uniqid(),
            'email' => 'test_e2e_'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'role_id' => $roleId,
        ]);
    }

    public function test_full_pipeline_from_planning_to_ready_final(): void
    {
        // ---- 1. Tạo video: nút bấm của admin, service thật (VideoRenderPlanService bị mock — ranh giới Claude) ----
        $article = $this->article();
        $admin = $this->admin();

        $claudeRenderPlan = ['scenes' => [['id' => 'beat1']]];
        $mockRenderPlanService = Mockery::mock(VideoRenderPlanService::class);
        $mockRenderPlanService->shouldReceive('build')->once()->andReturn($claudeRenderPlan);
        $this->app->instance(VideoRenderPlanService::class, $mockRenderPlanService);
        $service = app(VideoSessionService::class);

        [$session, , $reason] = $service->startVideoPlanning($article->id, $admin->id);
        $this->assertSame('ok', $reason);
        $this->assertSame(VideoSessionStatus::PLANNING->value, $session->status);

        // ---- 2. video:build-plan chạy nền: gọi Claude (mock), lưu renderplan_json, chuyển composing ----
        $ok = $service->runVideoPlanningPipeline($session->code);
        $this->assertTrue($ok);
        $session->refresh();
        $this->assertSame(VideoSessionStatus::COMPOSING->value, $session->status);
        $this->assertSame($claudeRenderPlan, $session->renderplan_json);

        // ---- 3. session_runner.py biên soạn prompt xong, đẩy renderplan + shots qua API thật ----
        $compiledRenderPlan = ['timeline' => [['scene_id' => 'beat1']]];
        $storeResponse = $this->withHeaders($this->apiHeaders)->postJson('/api/render-plans', [
            'project' => 'TEST e2e project',
            'code' => $session->code,
            'renderplan' => $compiledRenderPlan,
            'shots' => [[
                'shot_code' => 'shot1',
                'kind' => 'motion',
                'beat' => 'beat1',
                'shot_type' => 'establish',
                'spec' => ['x' => 1],
                'compiled_prompt' => 'a test motion prompt',
                'render_plan' => ['cost_estimate' => 0.42],
            ]],
        ])->assertOk();
        $this->assertSame(1, $storeResponse->json('shots'));

        $session->refresh();
        $this->assertSame(VideoSessionStatus::REVIEWING->value, $session->status);
        $this->assertSame($compiledRenderPlan, $session->renderplan_json);
        $this->assertEqualsWithDelta(0.42, (float) $session->cost_estimate_total, 0.0001);

        $shot = $session->shots()->where('shot_code', 'shot1')->firstOrFail();
        $this->assertSame(VideoShotStatus::DRAFT->value, $shot->status);

        // ---- 4. admin duyệt + bấm Render: service thật ----
        $service->approveSelectedShots($session->id, [$shot->id]);
        $this->assertSame(VideoShotStatus::APPROVED->value, $shot->fresh()->status);

        [$queued] = $service->queueApproved($session->id);
        $this->assertSame(1, $queued);
        $shot->refresh();
        $this->assertSame(VideoShotStatus::QUEUED->value, $shot->status);
        $this->assertSame(VideoSessionStatus::RENDERING->value, $session->fresh()->status);

        // ---- 5. render_queued_shots.py claim qua API thật ----
        $claim = $this->withHeaders($this->apiHeaders)->postJson('/api/video-shots/claim', [
            'session_code' => $session->code,
            'worker_id' => 'e2e-worker',
            'limit' => 10,
            'lease_seconds' => 600,
        ])->assertOk()->assertJsonCount(1, 'shots');
        $claimToken = $claim->json('claim_token');
        $this->assertIsString($claimToken);
        $shot->refresh();
        $this->assertSame(VideoShotStatus::CLAIMED->value, $shot->status);

        // ---- 6. runner báo kết quả render (Veo mock ở phía Python) qua API thật ----
        $this->withHeaders($this->apiHeaders)->patchJson("/api/video-shots/{$shot->id}/result", [
            'success' => true,
            'artifact_path' => '/renders/'.$shot->id.'.mp4',
            'cost' => 0.75,
            'worker_id' => 'e2e-worker',
            'claim_token' => $claimToken,
            'render' => [
                'render_kind' => 'video',
                'provider' => 'test-provider',
                'model' => 'test-veo',
                'sent_prompt' => 'a test motion prompt',
                'prompt_sha256' => hash('sha256', 'a test motion prompt'),
                'duration_ms' => 4000,
            ],
        ])->assertOk()->assertJson(['status' => VideoShotStatus::RENDERED->value]);

        $shot->refresh();
        $this->assertSame(VideoShotStatus::RENDERED->value, $shot->status);
        $this->assertNull($shot->worker_id);
        $session->refresh();
        // Moi shot render xong KHONG duoc tu dong bao session "done" — final
        // video chua he ton tai o buoc nay (2026-08-13, xem docblock
        // VideoSessionStatus::RENDERING).
        $this->assertSame(VideoSessionStatus::RENDERING->value, $session->status);
        $this->assertEqualsWithDelta(0.75, (float) $session->cost_actual, 0.0001);

        // ---- 7. admin bấm Ghép video: service thật, chỉ tạo được khi mọi cảnh đã render ----
        $readiness = $service->finalCompositionReadiness($session);
        $this->assertTrue($readiness['ready'], 'readiness missing: '.implode(', ', $readiness['missing']));

        [$final, , $finalReason] = $service->startFinalComposition($session->id);
        $this->assertContains($finalReason, ['ok', 'spawn_failed']);
        $this->assertNotNull($final);
        $this->assertSame('composing', $final->status);
        $this->assertSame(VideoSessionStatus::COMPOSING_FINAL->value, $session->fresh()->status);

        // ---- 8. compose_final.py kéo kế hoạch ghép qua API thật ----
        $composingPlan = $this->withHeaders($this->apiHeaders)
            ->getJson('/api/video-finals/composing?session_code='.$session->code)
            ->assertOk();
        $this->assertSame('ok', $composingPlan->json('status'));
        $this->assertCount(1, $composingPlan->json('clips'));

        $final->refresh();
        $this->assertIsArray($final->plan_json);
        $this->assertSame($final->id, $composingPlan->json('final_id'));

        // ---- 9. compose_final.py báo kết quả FFmpeg (mock, không gọi vendor) qua API thật ----
        $finalResult = $this->withHeaders($this->apiHeaders)->patchJson("/api/video-finals/{$final->id}/result", [
            'success' => true,
            'video_path' => '/renders/finals/'.$session->code.'/final.mp4',
            'duration_ms' => 4000,
        ])->assertOk();
        $this->assertSame('ready', $finalResult->json('status'));

        $final->refresh();
        $this->assertSame('ready', $final->status);
        $this->assertSame('/renders/finals/'.$session->code.'/final.mp4', $final->video_path);
        $this->assertSame(4, $final->duration_seconds);
        $this->assertEqualsWithDelta(0.75, (float) $final->cost_total, 0.0001);
        $this->assertSame(1, $final->cuts()->count());

        // session chi duoc bao "done" TU DAY — sau khi final that su ready.
        $this->assertSame(VideoSessionStatus::DONE->value, $session->fresh()->status);
    }
}
