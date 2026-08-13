<?php

namespace Tests\Feature\Video;

use App\Enums\VideoSessionStatus;
use App\Models\Admin;
use App\Models\Article;
use App\Models\VideoSession;
use App\Services\VideoRenderPlanService;
use App\Services\VideoSessionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * §18.30 — pipeline Claude chạy nền qua `video:build-plan --session=`, tách
 * khỏi request HTTP. Hai method dưới quyền kiểm ở đây:
 *
 *   startVideoPlanning()        chạy TRONG request — nhanh, khoá + tạo session
 *   runVideoPlanningPipeline()  chạy NGOÀI request — gọi Claude thật (mock ở đây)
 *
 * KHÔNG gọi VideoRenderPlanService::build() thật — nó gọi Claude thật, tốn
 * tiền. Mock toàn bộ và bind vào container, cùng kỹ thuật test usage-log đã
 * có (VideoRenderPlanUsageLogTest).
 *
 * `$adminId` KHÔNG còn nullable ở `startVideoPlanning()` (2026-08-12, review
 * độc lập lần hai) — provenance chi phí phải có ngay từ đầu. Mọi test dưới
 * đây dùng admin thật, trừ hai test cố tình mô phỏng session KHÔNG có admin
 * (đường vào duy nhất còn lại là ghi thẳng DB, không qua startVideoPlanning()).
 */
class VideoPlanningBackgroundTest extends TestCase
{
    use DatabaseTransactions;

    private VideoSessionService $service;

    private function article(): Article
    {
        $keywordId = DB::table('keywords')->value('id');

        return Article::create([
            'keyword_id' => $keywordId,
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('', true)),
            'source_title' => 'TEST source title',
            'title' => 'TEST article '.uniqid(),
            'slug' => 'test-article-'.uniqid(),
            'content' => 'noi dung test',
            'status' => 'pending',
        ]);
    }

    private function admin(): Admin
    {
        $roleId = DB::table('roles')->value('id');

        return Admin::create([
            'name' => 'TEST admin '.uniqid(),
            'email' => 'test_'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'role_id' => $roleId,
        ]);
    }

    /** Mock build() KHÔNG bao giờ gọi Claude thật — bind vào container. */
    private function bindFakeRenderPlanService(?callable $expectation = null): void
    {
        $mock = Mockery::mock(VideoRenderPlanService::class);
        if ($expectation) {
            $expectation($mock);
        } else {
            $mock->shouldNotReceive('build');
        }
        $this->app->instance(VideoRenderPlanService::class, $mock);
        $this->service = app(VideoSessionService::class);
    }

    public function test_start_planning_creates_a_session_with_requested_admin_and_planning_status(): void
    {
        $this->bindFakeRenderPlanService();
        $article = $this->article();
        $admin = $this->admin();

        [$session, , $reason] = $this->service->startVideoPlanning($article->id, $admin->id);

        // Moi truong test khong cau hinh VIDEO_RUNNER_DIR nen spawnArtisan()
        // tra false mot cach co chu dich (xem PythonRunner::spawn()) — 'ok' hay
        // 'spawn_failed' deu la NHANH DA-TAO-SESSION, khac han 'already_in_progress'.
        $this->assertContains($reason, ['ok', 'spawn_failed']);
        $this->assertNotNull($session);
        $this->assertSame(VideoSessionStatus::PLANNING->value, $session->status);
        $this->assertSame($article->id, $session->article_id);
        $this->assertSame($admin->id, $session->requested_by_admin_id);
        $this->assertNull($session->renderplan_json);
        $this->assertStringStartsWith('art_'.substr($article->id, 0, 8).'_', $session->code);
    }

    public function test_start_planning_refuses_when_admin_does_not_exist(): void
    {
        $this->bindFakeRenderPlanService();
        $article = $this->article();

        [$session, $spawned, $reason] = $this->service->startVideoPlanning($article->id, 'khong-ton-tai');

        $this->assertNull($session);
        $this->assertFalse($spawned);
        $this->assertSame('admin_not_found', $reason);
        $this->assertSame(0, VideoSession::where('article_id', $article->id)->count());
    }

    public function test_start_planning_refuses_a_second_call_for_the_same_article(): void
    {
        $this->bindFakeRenderPlanService();
        $article = $this->article();
        $admin = $this->admin();

        [$first] = $this->service->startVideoPlanning($article->id, $admin->id);
        [$second, $spawned, $reason] = $this->service->startVideoPlanning($article->id, $admin->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('already_in_progress', $reason);
        $this->assertFalse($spawned);
        $this->assertSame(1, VideoSession::where('article_id', $article->id)->count());
    }

    public function test_start_planning_allows_a_new_attempt_after_the_previous_one_reached_a_terminal_state(): void
    {
        $this->bindFakeRenderPlanService();
        $article = $this->article();
        $admin = $this->admin();

        [$first] = $this->service->startVideoPlanning($article->id, $admin->id);
        $first->update(['status' => VideoSessionStatus::FAILED->value]);

        [$second, , $reason] = $this->service->startVideoPlanning($article->id, $admin->id);

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame('already_in_progress', $reason);
    }

    /**
     * Gắn regression vào ĐÚNG vị trí — không chỉ chứng minh MariaDB tuần tự
     * hoá FOR UPDATE (đó là việc của test khoá 2 kết nối riêng), mà chứng
     * minh CHÍNH startVideoPlanning() phát ra khoá đó trên bảng `articles`.
     */
    public function test_start_planning_actually_issues_a_row_lock_on_the_article(): void
    {
        $this->bindFakeRenderPlanService();
        $article = $this->article();
        $admin = $this->admin();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->service->startVideoPlanning($article->id, $admin->id);

        DB::listen(fn () => null);

        $articleLockQueries = array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'articles') && str_contains($sql, 'for update'),
        );

        $this->assertNotEmpty(
            $articleLockQueries,
            "startVideoPlanning() phai phat mot truy van SELECT ... FOR UPDATE tren articles.\n".
            'SQL da chay: '.implode("\n", $queries),
        );
    }

    public function test_pipeline_no_ops_when_session_is_not_found(): void
    {
        $this->bindFakeRenderPlanService();

        $this->assertFalse($this->service->runVideoPlanningPipeline('khong-ton-tai'));
    }

    public function test_pipeline_no_ops_when_session_already_left_planning(): void
    {
        $this->bindFakeRenderPlanService();
        $article = $this->article();
        $admin = $this->admin();
        [$session] = $this->service->startVideoPlanning($article->id, $admin->id);
        $session->update(['status' => VideoSessionStatus::COMPOSING->value, 'renderplan_json' => ['x' => 1]]);

        $this->assertTrue($this->service->runVideoPlanningPipeline($session->code));
    }

    public function test_pipeline_fails_without_calling_claude_when_admin_no_longer_exists(): void
    {
        $this->bindFakeRenderPlanService(); // shouldNotReceive('build')
        $article = $this->article();
        $admin = $this->admin();
        [$session] = $this->service->startVideoPlanning($article->id, $admin->id);
        $admin->delete();

        $ok = $this->service->runVideoPlanningPipeline($session->code);

        $this->assertFalse($ok);
        $fresh = $session->fresh();
        $this->assertSame(VideoSessionStatus::FAILED->value, $fresh->status);
        $this->assertStringContainsString('Admin', $fresh->error_message);
        $this->assertNull($fresh->renderplan_json);
    }

    public function test_pipeline_fails_without_calling_claude_when_session_has_no_admin_at_all(): void
    {
        // startVideoPlanning() khong con cho tao session thieu admin — mo
        // phong duong con lai (du lieu cu, hoac ghi thang DB) bang cach tao
        // session TRUC TIEP, bo qua service. Admin::find(null) tra null tu
        // nhien, di CHUNG duong voi "admin da bi xoa" — cung mot thong bao.
        $this->bindFakeRenderPlanService(); // shouldNotReceive('build')
        $article = $this->article();
        $project = \App\Models\VideoProject::create(['name' => 'TEST no-admin '.uniqid()]);
        $session = VideoSession::create([
            'project_id' => $project->id,
            'article_id' => $article->id,
            'requested_by_admin_id' => null,
            'code' => 'test_no_admin_'.uniqid(),
            'status' => VideoSessionStatus::PLANNING->value,
        ]);

        $ok = $this->service->runVideoPlanningPipeline($session->code);

        $this->assertFalse($ok);
        $fresh = $session->fresh();
        $this->assertSame(VideoSessionStatus::FAILED->value, $fresh->status);
        $this->assertStringContainsString('Admin', $fresh->error_message);
        $this->assertNull($fresh->renderplan_json);
    }

    public function test_pipeline_reads_admin_from_the_session_row_not_an_external_argument(): void
    {
        // Khong co cach nao truyen admin id khac qua runVideoPlanningPipeline()
        // — chu ky chi nhan $sessionCode. Test nay khoa DUNG dieu do: doi admin
        // luu tren session thi ket qua auth() phai doi theo, khong co tham so
        // nao khac anh huong duoc.
        $article = $this->article();
        $adminA = $this->admin();
        $adminB = $this->admin();

        $this->bindFakeRenderPlanService(function ($mock) {
            $mock->shouldReceive('build')->once()->andReturn(['scenes' => []]);
        });

        [$session] = $this->service->startVideoPlanning($article->id, $adminB->id);

        $this->service->runVideoPlanningPipeline($session->code);

        $this->assertSame($adminB->id, auth()->id());
        $this->assertNotSame($adminA->id, auth()->id());
    }

    public function test_pipeline_success_saves_render_plan_and_moves_to_composing(): void
    {
        $article = $this->article();
        $admin = $this->admin();
        $renderPlan = ['scenes' => [['id' => 'a']]];

        $this->bindFakeRenderPlanService(function ($mock) use ($renderPlan) {
            $mock->shouldReceive('build')->once()->andReturn($renderPlan);
        });

        [$session] = $this->service->startVideoPlanning($article->id, $admin->id);

        $ok = $this->service->runVideoPlanningPipeline($session->code);

        $this->assertTrue($ok);
        $fresh = $session->fresh();
        $this->assertSame(VideoSessionStatus::COMPOSING->value, $fresh->status);
        $this->assertSame($renderPlan, $fresh->renderplan_json);
        $this->assertNull($fresh->error_message);
    }

    public function test_pipeline_passes_the_session_id_to_build_for_usage_attribution(): void
    {
        $this->bindFakeRenderPlanService();
        $article = $this->article();
        $admin = $this->admin();
        [$session] = $this->service->startVideoPlanning($article->id, $admin->id);

        $this->bindFakeRenderPlanService(function ($mock) use ($article, $session) {
            $mock->shouldReceive('build')
                ->once()
                ->with(Mockery::on(fn ($a) => $a->id === $article->id), $session->id)
                ->andReturn(['scenes' => []]);
        });

        $this->service->runVideoPlanningPipeline($session->code);
    }

    public function test_pipeline_failure_marks_session_failed_with_the_real_message(): void
    {
        $article = $this->article();
        $admin = $this->admin();

        $this->bindFakeRenderPlanService(function ($mock) {
            $mock->shouldReceive('build')->once()->andThrow(new \RuntimeException('Claude tu choi: rate limited'));
        });

        [$session] = $this->service->startVideoPlanning($article->id, $admin->id);

        $ok = $this->service->runVideoPlanningPipeline($session->code);

        $this->assertFalse($ok);
        $fresh = $session->fresh();
        $this->assertSame(VideoSessionStatus::FAILED->value, $fresh->status);
        $this->assertSame('Claude tu choi: rate limited', $fresh->error_message);
        $this->assertNull($fresh->renderplan_json);
    }

    // ---- Claim atomic: chống 2 tiến trình video:build-plan cùng gọi Claude ----

    public function test_pipeline_second_concurrent_run_is_refused_while_the_first_claim_is_fresh(): void
    {
        $this->bindFakeRenderPlanService(); // shouldNotReceive('build')
        $article = $this->article();
        $admin = $this->admin();

        [$session] = $this->service->startVideoPlanning($article->id, $admin->id);

        // Mo phong tien trinh THU NHAT da claim (dang giua chung goi Claude,
        // chua xong) — goi runVideoPlanningPipeline() lan nay dai dien cho
        // tien trinh THU HAI chay toi dung luc do.
        $session->update(['planning_claimed_at' => now()]);

        $secondRunResult = $this->service->runVideoPlanningPipeline($session->code);

        // Khong phai loi — chi la "khong con viec gi de lam" cho lan goi nay.
        // shouldNotReceive('build') o tren moi la khang dinh cot loi: KHONG
        // duoc goi Claude lan thu hai trong khi claim con han.
        $this->assertTrue($secondRunResult);
        $this->assertNull($session->fresh()->renderplan_json);
    }

    public function test_claim_does_not_auto_expire_even_after_a_long_time(): void
    {
        // Dao nguoc y dinh cua ban dau (han 10 phut) — mot cu goi Claude don
        // le gap 529 Overloaded co the mat toi ~10 phut RIENG NO (5 lan thu x
        // 60s + backoff 30/60/90/120s), ma pipeline goi 11+ lan. Han tu dong
        // la khong an toan: worker cu con song, worker moi tuong da chet.
        $this->bindFakeRenderPlanService(); // shouldNotReceive('build')
        $article = $this->article();
        $admin = $this->admin();

        [$session] = $this->service->startVideoPlanning($article->id, $admin->id);
        $session->update(['planning_claimed_at' => now()->subHours(3)]);

        $result = $this->service->runVideoPlanningPipeline($session->code);

        $this->assertTrue($result); // "khong con viec gi de lam", khong phai loi
        $this->assertNull($session->fresh()->renderplan_json);
    }

    public function test_pipeline_discards_the_result_when_ownership_was_reclaimed_during_build(): void
    {
        // Mo phong dung tinh huong review phat hien: worker A dang giua chung
        // goi Claude thi ai do (nham hoac co y) reset + mot worker KHAC claim
        // lai. Worker A hoan tat MUON van khong duoc ghi de ket qua cua worker
        // moi — token khong con khop.
        $article = $this->article();
        $admin = $this->admin();
        $renderPlan = ['scenes' => ['tu-worker-A']];

        $this->bindFakeRenderPlanService(function ($mock) use ($renderPlan) {
            $mock->shouldReceive('build')->once()->andReturnUsing(function () use ($renderPlan) {
                $claimed = VideoSession::whereNotNull('planning_claim_token')->first();
                $this->service->resetPlanningClaim($claimed->code);
                $claimed->fresh()->update([
                    'planning_claimed_at' => now(),
                    'planning_claim_token' => (string) \Illuminate\Support\Str::uuid(),
                ]);

                return $renderPlan;
            });
        });

        [$session] = $this->service->startVideoPlanning($article->id, $admin->id);

        $ok = $this->service->runVideoPlanningPipeline($session->code);

        $this->assertFalse($ok);
        $this->assertNull($session->fresh()->renderplan_json,
            'worker mat quyen so huu claim khong duoc phep ghi ket qua da tinh');
    }

    /**
     * Cung tinh huong o tren nhung pipeline THAT BAI thay vi thanh cong — nhanh
     * catch() cung phai tu choi ghi `status=failed` khi mat quyen so huu, y het
     * nhanh thanh cong. Truoc ban va nay nhanh catch ghi de KHONG DIEU KIEN.
     */
    public function test_pipeline_discards_the_failure_write_when_ownership_was_reclaimed_during_build(): void
    {
        $article = $this->article();
        $admin = $this->admin();

        $this->bindFakeRenderPlanService(function ($mock) {
            $mock->shouldReceive('build')->once()->andReturnUsing(function () {
                $claimed = VideoSession::whereNotNull('planning_claim_token')->first();
                $this->service->resetPlanningClaim($claimed->code);
                $claimed->fresh()->update([
                    'planning_claimed_at' => now(),
                    'planning_claim_token' => (string) \Illuminate\Support\Str::uuid(),
                ]);

                throw new \RuntimeException('Claude tu choi giua chung');
            });
        });

        [$session] = $this->service->startVideoPlanning($article->id, $admin->id);

        $ok = $this->service->runVideoPlanningPipeline($session->code);

        $this->assertFalse($ok);
        // worker mat quyen so huu KHONG duoc ghi de status/error_message cua
        // worker moi (dang giu claim that su o thoi diem nay).
        $fresh = $session->fresh();
        $this->assertNotSame('Claude tu choi giua chung', $fresh->error_message);
    }

    public function test_reset_planning_claim_does_nothing_when_session_has_no_active_claim(): void
    {
        // planning nhung CHUA TUNG duoc claim (session vua tao) — khong co gi
        // de reset, phai tra false, khong duoc bao thanh cong gia.
        $this->bindFakeRenderPlanService();
        $article = $this->article();
        $admin = $this->admin();
        [$session] = $this->service->startVideoPlanning($article->id, $admin->id);

        $this->assertNull($session->planning_claimed_at);
        $this->assertFalse($this->service->resetPlanningClaim($session->code));
    }

    public function test_reset_planning_claim_clears_the_claim_and_allows_a_new_run(): void
    {
        $article = $this->article();
        $admin = $this->admin();
        $renderPlan = ['scenes' => ['sau-khi-reset']];

        $this->bindFakeRenderPlanService(function ($mock) use ($renderPlan) {
            $mock->shouldReceive('build')->once()->andReturn($renderPlan);
        });

        [$session] = $this->service->startVideoPlanning($article->id, $admin->id);
        $session->update(['planning_claimed_at' => now(), 'planning_claim_token' => 'tien-trinh-cu-da-chet']);

        $reset = $this->service->resetPlanningClaim($session->code);
        $this->assertTrue($reset);
        $this->assertNull($session->fresh()->planning_claimed_at);

        $ok = $this->service->runVideoPlanningPipeline($session->code);

        $this->assertTrue($ok);
        $this->assertSame($renderPlan, $session->fresh()->renderplan_json);
    }

    public function test_reset_planning_claim_does_nothing_once_session_left_planning(): void
    {
        $this->bindFakeRenderPlanService();
        $article = $this->article();
        $admin = $this->admin();
        [$session] = $this->service->startVideoPlanning($article->id, $admin->id);
        $session->update(['status' => VideoSessionStatus::COMPOSING->value]);

        $this->assertFalse($this->service->resetPlanningClaim($session->code));
    }

    /** Gắn regression vào ĐÚNG vị trí — cùng lý do với hai test khoá `FOR UPDATE` khác trong suite này. */
    public function test_pipeline_claim_actually_issues_a_row_lock_on_the_session(): void
    {
        $article = $this->article();
        $admin = $this->admin();

        $this->bindFakeRenderPlanService(function ($mock) {
            $mock->shouldReceive('build')->once()->andReturn(['scenes' => []]);
        });

        [$session] = $this->service->startVideoPlanning($article->id, $admin->id);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->service->runVideoPlanningPipeline($session->code);

        DB::listen(fn () => null);

        $sessionLockQueries = array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'video_sessions') && str_contains($sql, 'for update'),
        );

        $this->assertNotEmpty(
            $sessionLockQueries,
            "runVideoPlanningPipeline() phai phat mot truy van SELECT ... FOR UPDATE tren video_sessions luc claim.\n".
            'SQL da chay: '.implode("\n", $queries),
        );
    }
}
