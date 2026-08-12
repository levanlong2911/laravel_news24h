<?php

namespace Tests\Feature\Video;

use App\Models\VideoFinal;
use App\Models\VideoFinalRender;
use App\Models\VideoProject;
use App\Models\VideoSession;
use App\Models\VideoShot;
use App\Services\VideoSessionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinalCompositionTest extends TestCase
{
    use DatabaseTransactions;

    private VideoSessionService $service;

    private VideoSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(VideoSessionService::class);

        $project = VideoProject::create(['name' => 'TEST final '.uniqid()]);
        $this->session = VideoSession::create([
            'project_id' => $project->id,
            'code' => 'test_final_'.uniqid(),
            'status' => 'done',
            'renderplan_json' => [
                'timeline' => [
                    ['scene_id' => 'scene_a', 'start_sec' => 0, 'end_sec' => 5],
                    ['scene_id' => 'scene_b', 'start_sec' => 5, 'end_sec' => 10],
                ],
            ],
        ]);
    }

    private function motionShot(string $beat): VideoShot
    {
        return VideoShot::create([
            'session_id' => $this->session->id,
            'beat' => $beat, 'shot_code' => $beat, 'shot_type' => 'establish',
            'kind' => 'motion', 'spec_json' => [], 'compiled_prompt' => "prompt {$beat}",
        ]);
    }

    private function renderBothScenes(): void
    {
        $a = $this->motionShot('scene_a');
        $b = $this->motionShot('scene_b');
        $this->service->reportShotResult($a->id, true, '/renders/shots/a/x.mp4', 0.18, [
            'render_kind' => 'video', 'provider' => 'fal', 'model' => 'veo',
            'sent_prompt' => 'a', 'prompt_sha256' => hash('sha256', 'a'), 'duration_ms' => 6000,
        ]);
        $this->service->reportShotResult($b->id, true, '/renders/shots/b/x.mp4', 0.20, [
            'render_kind' => 'video', 'provider' => 'fal', 'model' => 'veo',
            'sent_prompt' => 'b', 'prompt_sha256' => hash('sha256', 'b'), 'duration_ms' => 7000,
        ]);
    }

    public function test_readiness_is_false_when_a_scene_has_no_rendered_motion_shot(): void
    {
        $this->motionShot('scene_a');

        $readiness = $this->service->finalCompositionReadiness($this->session->fresh());

        $this->assertFalse($readiness['ready']);
        $this->assertSame(['scene_a', 'scene_b'], $readiness['missing']);
    }

    public function test_readiness_is_true_once_every_timeline_scene_is_rendered(): void
    {
        $this->renderBothScenes();

        $readiness = $this->service->finalCompositionReadiness($this->session->fresh());

        $this->assertTrue($readiness['ready']);
        $this->assertSame([], $readiness['missing']);
    }

    public function test_readiness_check_does_not_scale_query_count_with_scene_count(): void
    {
        // #6: eager-load latestRender — so query phai co CHAN TREN co dinh, khong
        // tang theo so canh trong timeline (truoc khi vá: 1 query/shot trong loop).
        $timeline = [];
        for ($i = 0; $i < 8; $i++) {
            $beat = "scene_{$i}";
            $timeline[] = ['scene_id' => $beat, 'start_sec' => $i * 5, 'end_sec' => ($i + 1) * 5];
            $shot = $this->motionShot($beat);
            $this->service->reportShotResult($shot->id, true, "/renders/shots/{$beat}/x.mp4", 0.18, [
                'render_kind' => 'video', 'provider' => 'fal', 'model' => 'veo',
                'sent_prompt' => $beat, 'prompt_sha256' => hash('sha256', $beat), 'duration_ms' => 6000,
            ]);
        }
        $this->session->update(['renderplan_json' => ['timeline' => $timeline]]);

        DB::enableQueryLog();
        $readiness = $this->service->finalCompositionReadiness($this->session->fresh());
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertTrue($readiness['ready']);
        $this->assertLessThan(8, $queryCount, 'so query khong duoc ti le voi so canh (N+1)');
    }

    public function test_build_plan_errors_without_a_composing_final_row(): void
    {
        $this->renderBothScenes();

        $plan = $this->service->buildFinalCompositionPlan($this->session->code);

        $this->assertSame('error', $plan['status']);
    }

    public function test_build_plan_orders_clips_by_timeline_and_caches_on_second_call(): void
    {
        $this->renderBothScenes();
        [$final] = $this->service->startFinalComposition($this->session->id);

        $plan = $this->service->buildFinalCompositionPlan($this->session->code);

        $this->assertSame('ok', $plan['status']);
        $this->assertSame($final->id, $plan['final_id']);
        $this->assertCount(2, $plan['clips']);
        $this->assertSame(0, $plan['clips'][0]['sequence_no']);
        $this->assertSame('/renders/shots/a/x.mp4', $plan['clips'][0]['path']);
        $this->assertSame(6000, $plan['clips'][0]['duration_ms']);
        $this->assertSame(1, $plan['clips'][1]['sequence_no']);
        $this->assertSame('/renders/shots/b/x.mp4', $plan['clips'][1]['path']);

        // Ghi shot a lai (bytes moi) — plan da chot KHONG duoc doi khi goi lai.
        $a = VideoShot::where('beat', 'scene_a')->where('session_id', $this->session->id)->first();
        $this->service->reportShotResult($a->id, true, '/renders/shots/a/y.mp4', 0.18, [
            'render_kind' => 'video', 'provider' => 'fal', 'model' => 'veo',
            'sent_prompt' => 'a2', 'prompt_sha256' => hash('sha256', 'a2'), 'duration_ms' => 9999,
        ]);

        $planAgain = $this->service->buildFinalCompositionPlan($this->session->code);
        $this->assertSame($plan, $planAgain);
    }

    public function test_build_plan_reports_missing_scenes_without_crashing(): void
    {
        // Khong qua startFinalComposition(): tu 2026-08-12 no tu chan khi chua
        // du canh (#5). Dung thang VideoFinal::create() de mo phong dung tinh
        // huong con lai co the xay ra — mot shot bi huy render GIUA luc final
        // dang composing — va xac nhan buildFinalCompositionPlan() van tu bao
        // loi ro rang, khong dua ra plan sai.
        $this->motionShot('scene_a');
        $final = VideoFinal::create(['session_id' => $this->session->id, 'status' => 'composing']);

        $plan = $this->service->buildFinalCompositionPlan($this->session->code);

        $this->assertSame('error', $plan['status']);
        $this->assertSame($final->id, $plan['final_id']);
        $this->assertStringContainsString('scene_a', $plan['error']);
        $this->assertStringContainsString('scene_b', $plan['error']);
    }

    public function test_start_composition_reuses_an_in_flight_composing_row_and_does_not_spawn_twice(): void
    {
        $this->renderBothScenes();

        [$first, , $reasonFirst] = $this->service->startFinalComposition($this->session->id);
        [$second, $spawnedSecond, $reasonSecond] = $this->service->startFinalComposition($this->session->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, VideoFinal::where('session_id', $this->session->id)->count());

        // #2: lan goi thu hai KHONG duoc di qua nhanh spawn() — reason phai la
        // 'already_composing', khac han nhanh 'ok'/'spawn_failed' cua lan dau.
        $this->assertContains($reasonFirst, ['ok', 'spawn_failed']);
        $this->assertSame('already_composing', $reasonSecond);
        $this->assertFalse($spawnedSecond);
    }

    public function test_start_composition_refuses_when_not_ready(): void
    {
        [$final, $spawned, $reason] = $this->service->startFinalComposition($this->session->id);

        $this->assertNull($final);
        $this->assertFalse($spawned);
        $this->assertSame('not_ready', $reason);
        $this->assertSame(0, VideoFinal::where('session_id', $this->session->id)->count());
    }

    /**
     * Gan regression vao DUNG vi tri: FinalCompositionConcurrencyTest chi
     * chung minh MariaDB/InnoDB tuan tu hoa FOR UPDATE (tu viet lai truy van,
     * khong goi startFinalComposition()) — ai xoa lockForUpdate() khoi service
     * thi test do van xanh. Test nay bat SQL THAT bang DB::listen() va doi hoi
     * chinh startFinalComposition() phai phat ra FOR UPDATE tren video_sessions.
     */
    public function test_start_composition_actually_issues_a_row_lock_on_the_session(): void
    {
        $this->renderBothScenes();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->service->startFinalComposition($this->session->id);

        DB::listen(fn () => null);

        $sessionLockQueries = array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'video_sessions') && str_contains($sql, 'for update'),
        );

        $this->assertNotEmpty(
            $sessionLockQueries,
            "startFinalComposition() phai phat mot truy van SELECT ... FOR UPDATE tren video_sessions.\n".
            'SQL da chay: '.implode("\n", $queries),
        );
    }

    public function test_recording_success_writes_ordered_final_renders_and_leaves_session_status_alone(): void
    {
        $this->renderBothScenes();
        [$final] = $this->service->startFinalComposition($this->session->id);
        $this->service->buildFinalCompositionPlan($this->session->code);

        // Dat mot trang thai KHAC 'ready'/'done' NGAY TRUOC khi ghi ket qua —
        // syncSessionRenderStatus() (chay ben trong renderBothScenes()) da tu
        // dua session ve 'done' roi, nen kiem tra "khong doi" o day chi that su
        // co rang neu gia tri truoc do khac voi bat ky gia tri nao ham co the
        // vo tinh gan vao.
        $this->session->update(['status' => 'rendering']);

        $result = $this->service->recordFinalCompositionResult(
            $final->id, true, '/renders/finals/x/x.mp4', 13000, null,
        );

        $this->assertSame('ready', $result->status);
        $this->assertSame('/renders/finals/x/x.mp4', $result->video_path);
        $this->assertSame(13, $result->duration_seconds);
        $this->assertEqualsWithDelta(0.38, (float) $result->cost_total, 0.0001);

        $cuts = VideoFinalRender::where('final_id', $final->id)->orderBy('sequence_no')->get();
        $this->assertCount(2, $cuts);
        $this->assertSame(0, $cuts[0]->start_ms);
        $this->assertSame(6000, $cuts[0]->duration_ms);
        $this->assertSame(6000, $cuts[1]->start_ms);
        $this->assertSame(7000, $cuts[1]->duration_ms);

        // video_sessions.status khong bi dong nay dung cham toi — vong doi rieng.
        $this->assertSame('rendering', $this->session->fresh()->status);
    }

    public function test_recording_failure_sets_failed_status_and_writes_no_cuts(): void
    {
        $final = VideoFinal::create(['session_id' => $this->session->id, 'status' => 'composing']);

        $result = $this->service->recordFinalCompositionResult(
            $final->id, false, null, null, 'ffmpeg loi: thieu file clip',
        );

        $this->assertSame('failed', $result->status);
        $this->assertSame('ffmpeg loi: thieu file clip', $result->error_message);
        $this->assertSame(0, VideoFinalRender::where('final_id', $final->id)->count());
    }

    public function test_recording_success_without_plan_json_fails_instead_of_faking_ready(): void
    {
        // #4: mo phong Python bao ket qua ma CHUA TUNG GET /video-finals/composing
        // (plan_json van null) — khong duoc danh dau ready voi 0 cut.
        $final = VideoFinal::create(['session_id' => $this->session->id, 'status' => 'composing']);

        $result = $this->service->recordFinalCompositionResult(
            $final->id, true, '/renders/finals/x/x.mp4', 13000, null,
        );

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('plan_json', $result->error_message);
        $this->assertSame(0, VideoFinalRender::where('final_id', $final->id)->count());
    }

    public function test_recording_result_twice_does_not_duplicate_cuts(): void
    {
        $this->renderBothScenes();
        [$final] = $this->service->startFinalComposition($this->session->id);
        $this->service->buildFinalCompositionPlan($this->session->code);

        $this->service->recordFinalCompositionResult($final->id, true, '/renders/finals/x/x.mp4', 13000, null);
        $this->service->recordFinalCompositionResult($final->id, true, '/renders/finals/x/x.mp4', 13000, null);

        $this->assertCount(2, VideoFinalRender::where('final_id', $final->id)->get());
    }

    /**
     * Compile TRỰC TIẾP đoạn Blade mới thêm (không qua @extends layout, tránh
     * kéo theo sidebar cần đăng nhập admin) — bắt lỗi cú pháp trong nút mới.
     */
    public function test_compose_final_button_markup_compiles_and_reflects_readiness(): void
    {
        $this->renderBothScenes();
        $session = $this->service->findForShow($this->session->id);
        $finalReadiness = $this->service->finalCompositionReadiness($session);

        $fragment = <<<'BLADE'
            <form method="post" action="{{ route('video-session.compose-final', $session->id) }}" style="display:inline">
                @csrf <button class="btn btn-primary btn-sm" {{ $finalReadiness['ready'] ? '' : 'disabled' }}
                  title="{{ $finalReadiness['ready'] ? '' : 'Còn thiếu render cho: '.implode(', ', $finalReadiness['missing']) }}">
                  🎬 Ghép video hoàn chỉnh
                </button>
            </form>
            BLADE;

        $html = \Illuminate\Support\Facades\Blade::render($fragment, compact('session', 'finalReadiness'));

        $this->assertStringContainsString('Ghép video hoàn chỉnh', $html);
        $this->assertStringNotContainsString('disabled', $html);
    }
}
