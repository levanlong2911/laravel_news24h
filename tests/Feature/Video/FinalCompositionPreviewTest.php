<?php

namespace Tests\Feature\Video;

use App\Models\Admin;
use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Models\VideoRenderScene;
use App\Models\VideoSession;
use App\Models\VideoShot;
use App\Services\VideoProjectService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Man Final Composition doc clip that va ban final that, nhung CHUA co hanh dong
 * nao — moi nut deu tro y nguyen chu dinh.
 *
 * Cai dang khoa o day la: trang phai song khi du an chua co gi, va khong duoc gia
 * vo co du lieu minh khong co.
 */
class FinalCompositionPreviewTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Vai tro phai dung TEN, khong phai "role dau tien co trong bang":
        // `Admin::isAdmin()` so `role->name === 'admin'`, va `VideoProjectPolicy`
        // chi cho qua khi la admin HOAC la thanh vien so huu du an. Lay bua mot
        // role, gap phai `member`, la ca file 403 — va no phu thuoc thu tu hang.
        $roleId = DB::table('roles')->where('name', 'admin')->value('id');

        if ($roleId === null) {
            $roleId = (string) Str::uuid();
            DB::table('roles')->insert(['id' => $roleId, 'name' => 'admin']);
        }

        $this->actingAs(Admin::create([
            'name' => 'TEST final composition admin '.uniqid(),
            'email' => 'test_final_composition_'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'role_id' => $roleId,
        ]));
    }

    private function openBareProject(): string
    {
        $project = VideoProject::create(['title' => 'TEST final composition '.uniqid()]);

        $response = $this->get(route('video-projects.final-composition-preview', $project->id));

        $response->assertOk();

        return $response->getContent();
    }

    public function test_the_screen_opens_for_a_project_with_nothing_in_it(): void
    {
        $this->assertStringContainsString('Final Composition', $this->openBareProject());
    }

    public function test_an_empty_project_says_so_instead_of_showing_made_up_rows(): void
    {
        $html = $this->openBareProject();

        $this->assertStringContainsString('Chưa có clip nào dựng xong', $html);
        $this->assertStringContainsString('Chưa có lần render nào', $html);
        $this->assertStringNotContainsString('data-fcomp-playlist', $html);
    }

    public function test_the_screen_never_calls_a_finished_clip_an_approved_one(): void
    {
        // `execution_status = succeeded` nghia la DUNG XONG, khong phai duoc duyet.
        // Duyet la mot chuyen khac va co cot rieng — `video_shots.approved_at`, hien
        // trong rong. Goi nham la noi voi nguoi dung rang co mot buoc kiem da xay ra.
        $this->assertStringNotContainsString('đã duyệt', $this->openBareProject());
    }

    public function test_an_empty_project_counts_zero_everywhere(): void
    {
        $html = $this->openBareProject();

        // Tong thoi luong va so clip deu phai di ra tu CUNG mot con so, khong phai
        // tu hai cho khac nhau roi tinh co bang nhau.
        $this->assertStringContainsString('<span>0 clips</span>', $html);
        $this->assertStringContainsString('<dt>Số clip</dt><dd>0</dd>', $html);
        $this->assertStringContainsString('<dt>Duration (ước tính)</dt><dd>00:00</dd>', $html);
        $this->assertStringContainsString('Timeline <span>00:00</span>', $html);
    }

    public function test_the_only_thing_the_screen_submits_is_a_render(): void
    {
        // Doc THAN VIEW chu khong doc trang da render: layout chung co san form dang
        // xuat va form tim kiem, nen dem `<form` tren trang la bat nham ca vo.
        $view = file_get_contents(resource_path('views/video-projects/final-composition-preview.blade.php'));

        // DUNG MOT form. Luu ban nhap, sap xep clip, cat clip — chua lam, nen chung
        // van tro. Mot nut bam duoc ma khong co dich la mot loi hua voi nguoi dung.
        $this->assertSame(1, substr_count($view, '<form'));
        $this->assertStringContainsString("route('video-projects.final-render', \$id)", $view);
        $this->assertStringContainsString('@csrf', $view);
    }

    public function test_an_empty_project_cannot_start_a_render(): void
    {
        // Khong co clip thi khong co gi de ghep, va nut phai tro — chu khong phai
        // bam duoc roi server moi tu choi.
        $this->assertStringContainsString('Chưa có clip nào dựng xong nên chưa ghép được', $this->openBareProject());
    }

    public function test_the_six_steps_carry_three_different_states(): void
    {
        $html = $this->openBareProject();

        $this->assertSame(3, substr_count($html, '<div class="done">'));
        $this->assertSame(1, substr_count($html, '<div class="complete">'));
        $this->assertSame(1, substr_count($html, '<div class="active">'));
        $this->assertSame(1, substr_count($html, '<div class="">'));
    }

    public function test_the_timeline_keeps_four_lanes_even_with_no_data(): void
    {
        $html = $this->openBareProject();

        foreach (['fcomp-video-track', 'fcomp-bgm', 'fcomp-voice', 'fcomp-sfx'] as $lane) {
            $this->assertStringContainsString($lane, $html);
        }

        // Ba lane tieng chua co nguon du lieu nao — noi that ra, khong ve chip gia.
        $this->assertSame(3, substr_count($html, 'chưa nối'));
        $this->assertSame(1, substr_count($html, 'fcomp-playhead'));
    }

    public function test_each_export_setting_is_a_label_and_a_control_on_one_row(): void
    {
        $html = $this->openBareProject();

        // Nhan phai nam trong mot the rieng. De tran ra lam text node thi luoi hai
        // cot phu thuoc vao o vo danh trinh duyet tu sinh — dung, nhung mong manh.
        $labels = ['Độ phân giải', 'Tỉ lệ khung hình', 'Frame rate (FPS)', 'Video codec', 'Audio codec', 'Chất lượng (CRF)'];

        foreach ($labels as $label) {
            $this->assertStringContainsString('<span>'.$label.'</span>', $html);
        }
    }

    private function scene(VideoProject $project, int $index): VideoRenderScene
    {
        return VideoRenderScene::create([
            'project_id' => $project->id,
            'revision' => 1,
            'scene_index' => $index,
            'scene_code' => 'S'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'scene_type' => 'build',
            'title' => 'Scene '.$index,
            'purpose' => 'test',
            'delta_prompt' => 'delta',
            'prompt_version' => 'v1',
            'transition_mode' => 'hard_cut_edit',
        ]);
    }

    private function shot(VideoSession $session, VideoRenderScene $scene): VideoShot
    {
        return VideoShot::create([
            'session_id' => $session->id,
            'scene_id' => $scene->id,
            'beat' => 'b'.$scene->scene_index,
            'shot_code' => 'shot_'.uniqid(),
            'shot_type' => 'video',
            'kind' => 'video',
            'spec_json' => [],
            'status' => 'pending',
            'scene_status' => 'pending',
        ]);
    }

    private function attempt(
        VideoShot $shot,
        string $executionStatus,
        int $attemptNo = 1,
        int $durationMs = 8000,
        int $width = 720,
        int $height = 1280,
        string $purpose = 'production',
    ): VideoRender {
        return VideoRender::create([
            'shot_id' => $shot->id,
            'attempt_no' => $attemptNo,
            'render_kind' => 'video',
            'execution_purpose' => $purpose,
            'provider' => 'veo',
            'model' => 'veo-3',
            'sent_prompt' => 'test',
            'prompt_sha256' => hash('sha256', 'test'),
            'cost_usd' => 0,
            'status' => 'succeeded',
            'execution_status' => $executionStatus,
            'duration_ms' => $durationMs,
            'width' => $width,
            'height' => $height,
            'proof_verified' => false,
        ]);
    }

    private function clip(
        VideoSession $session,
        VideoRenderScene $scene,
        string $executionStatus,
        int $durationMs = 8000,
        int $width = 720,
        int $height = 1280,
    ): VideoRender {
        return $this->attempt(
            $this->shot($session, $scene), $executionStatus, 1, $durationMs, $width, $height,
        );
    }

    /** @return array{0: VideoProject, 1: VideoSession} */
    private function projectWithSession(): array
    {
        $project = VideoProject::create(['title' => 'TEST final composition '.uniqid()]);

        $session = VideoSession::create([
            'project_id' => $project->id,
            'code' => 'fc_'.uniqid(),
            'status' => 'draft',
        ]);

        return [$project, $session];
    }

    /** @return array<string, mixed> */
    private function cellsFor(VideoProject $project): array
    {
        return app(VideoProjectService::class)->finalCompositionCells($project->id);
    }

    public function test_a_clip_that_has_not_finished_is_not_approved(): void
    {
        [$project, $session] = $this->projectWithSession();

        // Mot luot that bai va mot luot dang chay deu KHONG co file de ghep, nen
        // chung khong phai "da duyet" theo bat ky nghia nao.
        $this->clip($session, $this->scene($project, 1), 'failed');
        $this->clip($session, $this->scene($project, 2), 'provider_running');

        $this->assertSame([], $this->cellsFor($project)['clips']);
        $this->assertSame(0, $this->cellsFor($project)['total_duration_ms']);
    }

    public function test_a_shot_rendered_twice_contributes_only_its_latest_attempt(): void
    {
        [$project, $session] = $this->projectWithSession();
        $shot = $this->shot($session, $this->scene($project, 1));

        $this->attempt($shot, 'succeeded', 1);
        $second = $this->attempt($shot, 'succeeded', 2);

        $clips = $this->cellsFor($project)['clips'];

        // Mot canh, mot cho tren dong thoi gian — du da render hai lan va ca hai deu
        // xong. Lay ca hai la canh do xuat hien hai lan trong phim.
        $this->assertCount(1, $clips);
        $this->assertSame((string) $second->id, $clips[0]['render_id']);
    }

    public function test_a_shot_whose_latest_attempt_failed_is_left_out_entirely(): void
    {
        [$project, $session] = $this->projectWithSession();
        $shot = $this->shot($session, $this->scene($project, 1));

        $this->attempt($shot, 'succeeded', 1);
        $this->attempt($shot, 'failed', 2);

        // Lan dau da xong, nhung nguoi dung render lai va lan sau hong. Quay ve dung
        // file cu la dua ra mot canh ma ho da chu y thay the.
        $this->assertSame([], $this->cellsFor($project)['clips']);
    }

    public function test_a_canary_attempt_never_takes_the_place_of_the_real_clip(): void
    {
        [$project, $session] = $this->projectWithSession();
        $shot = $this->shot($session, $this->scene($project, 1));

        $production = $this->attempt($shot, 'succeeded', 1);
        $this->attempt($shot, 'succeeded', 2, purpose: 'canary');

        $clips = $this->cellsFor($project)['clips'];

        // Luot canary dung chung shot va co `attempt_no` lon hon, nen no se thang o
        // phep "lay luot moi nhat" neu khong loc `production` truoc.
        $this->assertCount(1, $clips);
        $this->assertSame((string) $production->id, $clips[0]['render_id']);
    }

    public function test_the_order_follows_the_plan_and_the_numbering_closes_the_gaps(): void
    {
        [$project, $session] = $this->projectWithSession();

        $this->clip($session, $this->scene($project, 1), 'failed');
        $this->clip($session, $this->scene($project, 2), 'succeeded');
        $this->clip($session, $this->scene($project, 3), 'succeeded');

        $clips = $this->cellsFor($project)['clips'];

        // Scene 1 hong nen khong vao final. Nhung hai clip con lai phai la S01 va
        // S02 cua BAN FINAL, khong phai S02/S03 — so thu tu la vi tri trong phim.
        $this->assertSame([1, 2], array_column($clips, 'ordinal'));
        $this->assertSame(['S02', 'S03'], array_column($clips, 'scene_code'));
    }

    public function test_the_total_duration_is_the_sum_of_the_clips_that_got_in(): void
    {
        [$project, $session] = $this->projectWithSession();

        $this->clip($session, $this->scene($project, 1), 'succeeded', 8000);
        $this->clip($session, $this->scene($project, 2), 'succeeded', 5500);
        $this->clip($session, $this->scene($project, 3), 'failed', 9000);

        $this->assertSame(13500, $this->cellsFor($project)['total_duration_ms']);
    }

    public function test_clips_of_different_sizes_are_reported_as_different(): void
    {
        [$project, $session] = $this->projectWithSession();

        $this->clip($session, $this->scene($project, 1), 'succeeded', 8000, 720, 1280);
        $this->clip($session, $this->scene($project, 2), 'succeeded', 8000, 1080, 1920);

        $cells = $this->cellsFor($project);

        // Lay co cua clip DAU roi goi do la "co nguon" la noi sai — day la du an
        // that o may nay: S01 720x1280 con S02/S03 1080x1920.
        $this->assertNull($cells['uniform_size']);
        $this->assertSame(['720 × 1280', '1080 × 1920'], $cells['sizes']);

        $response = $this->get(route('video-projects.final-composition-preview', $project->id));

        $response->assertSee('lệch nhau — 720 × 1280, 1080 × 1920', false);
    }

    public function test_clips_of_one_size_offer_that_size_as_the_export_default(): void
    {
        [$project, $session] = $this->projectWithSession();

        $this->clip($session, $this->scene($project, 1), 'succeeded', 8000, 720, 1280);
        $this->clip($session, $this->scene($project, 2), 'succeeded', 8000, 720, 1280);

        $this->assertSame('720 × 1280', $this->cellsFor($project)['uniform_size']);

        $html = $this->get(route('video-projects.final-composition-preview', $project->id))->getContent();

        // Co nguon dong nhat thi no la co DUOC CHON SAN — khong bat nguoi dung tu di
        // tim lai mot con so ma he thong da biet.
        $this->assertMatchesRegularExpression('/<option value="720x1280"[^>]*\sselected/', $html);
    }

    public function test_with_no_final_the_player_plays_the_clips_themselves(): void
    {
        [$project, $session] = $this->projectWithSession();

        $first = $this->clip($session, $this->scene($project, 1), 'succeeded');
        $second = $this->clip($session, $this->scene($project, 2), 'succeeded');

        $response = $this->get(route('video-projects.final-composition-preview', $project->id));

        $html = $response->getContent();

        // Khung xem truoc phai PHAT DUOC. Mot loi nhan "chua co ban final" o day chan
        // dung thu man nay ton tai de lam.
        //
        // HAI the video, khong phai mot: doi `src` tren mot the buoc trinh duyet tai
        // lai tu dau — do la khoang den giua hai canh.
        $this->assertSame(2, substr_count($html, 'data-fcomp-deck'));
        $this->assertStringContainsString('data-fcomp-transport', $html);
        $response->assertSee(route('video-projects.scene-clip-file', [$project->id, $first->id]), false);

        // Va ca chuoi phai co mat, khong chi clip dau — het clip nay thi sang clip ke.
        $this->assertSame([
            route('video-projects.scene-clip-file', [$project->id, $first->id]),
            route('video-projects.scene-clip-file', [$project->id, $second->id]),
        ], array_column($this->playlistFrom($html), 'src'));
    }

    /** @return list<array<string, mixed>> */
    private function playlistFrom(string $html): array
    {
        preg_match('/<script type="application\/json" data-fcomp-playlist>(.*?)<\/script>/s', $html, $m);

        return json_decode(html_entity_decode($m[1] ?? '[]', ENT_QUOTES), true) ?? [];
    }

    public function test_the_playlist_carries_every_clip_in_order(): void
    {
        [$project, $session] = $this->projectWithSession();

        $this->clip($session, $this->scene($project, 1), 'succeeded', 8000);
        $this->clip($session, $this->scene($project, 2), 'failed', 9000);
        $this->clip($session, $this->scene($project, 3), 'succeeded', 5500);

        $playlist = $this->playlistFrom(
            $this->get(route('video-projects.final-composition-preview', $project->id))->getContent(),
        );

        // Duong phat va timeline phai doc CUNG mot mang: lech nhau thi playhead chi
        // vao mot cho con hinh chay o cho khac.
        $this->assertSame([8000, 5500], array_column($playlist, 'ms'));
        $this->assertSame(['S01 · Scene 1', 'S02 · Scene 3'], array_column($playlist, 'label'));
    }

    public function test_the_aspect_ratio_is_derived_from_the_resolution(): void
    {
        [$project, $session] = $this->projectWithSession();

        $this->clip($session, $this->scene($project, 1), 'succeeded', 8000, 720, 1280);

        $html = $this->get(route('video-projects.final-composition-preview', $project->id))->getContent();

        // Ti le KHONG phai mot lua chon rieng — hai o chon doc lap thi chung mau thuan
        // duoc voi nhau. No di theo do phan giai, va o hien no phai tro.
        $this->assertStringContainsString('<option value="720x1280" data-ratio="9:16 (dọc)"', $html);
        $this->assertStringContainsString('<option value="1920x1080" data-ratio="16:9 (ngang)"', $html);
        $this->assertMatchesRegularExpression('/data-fcomp-ratio>\s*<option>9:16 \(dọc\)<\/option>/u', $html);
        $this->assertStringContainsString('<select disabled data-fcomp-ratio>', $html);
    }

    public function test_the_render_history_sits_under_all_three_columns(): void
    {
        $html = $this->openBareProject();

        // No la hang rieng cuoi luoi, khong phai the cuoi cung cua cot giua.
        $this->assertTrue(strpos($html, 'fcomp-right') < strpos($html, 'fcomp-history'));
        $this->assertStringContainsString('<th>Thao tác</th>', $html);
    }
}
