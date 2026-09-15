<?php

namespace Tests\Feature\Video;

use App\Models\Admin;
use App\Models\VideoProject;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Man hinh render video phai DUNG DUOC, ke ca khi du an chua co scene nao.
 *
 * Mot trang chet vi thieu du lieu la loi hay gap nhat cua mang nay: no chi lo ra
 * khi co nguoi mo that, va luc do thi da muon.
 */
class RenderVideoScreenTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(Admin::create([
            'name' => 'TEST render video admin '.uniqid(),
            'email' => 'test_render_video_'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'role_id' => DB::table('roles')->value('id'),
        ]));
    }

    public function test_the_screen_opens_for_a_project_with_no_scenes_at_all(): void
    {
        $project = VideoProject::create(['title' => 'TEST render video '.uniqid()]);

        $response = $this->get(route('video-projects.render-video', $project->id));

        $response->assertOk();
        $response->assertSee('Chưa có scene nào', false);
    }

    public function test_the_screen_lays_out_the_four_columns(): void
    {
        $project = VideoProject::create(['title' => 'TEST render video '.uniqid()]);

        $response = $this->get(route('video-projects.render-video', $project->id));

        // Bam cau truc chu khong bam chu: tieu de cot la thu doi duoc, con luoi bon
        // cot thi khong — doi no la doi ca man hinh.
        $response->assertSee('vs-grid vs-clip', false);
        $this->assertSame(4, substr_count($response->getContent(), 'class="vs-h"'));
    }

    public function test_the_screen_submits_without_reloading(): void
    {
        $project = VideoProject::create(['title' => 'TEST render video '.uniqid()]);

        $response = $this->get(route('video-projects.render-video', $project->id));

        // Form clip mang `data-action` chu khong phai `action`, va submit bi chan
        // bang preventDefault — nen trang khong bao gio tai lai khi gui render.
        $response->assertSee('js-clip-form', false);
        $response->assertSee('e.preventDefault()', false);
        $response->assertSee('clipModal', false);
    }

    public function test_the_screen_watches_a_running_clip_by_itself(): void
    {
        $project = VideoProject::create(["title" => "TEST render video ".uniqid()]);

        $response = $this->get(route("video-projects.render-video", $project->id));

        // Khong con nut bam tay de tien len: man hinh tu hoi lai theo nhip gian dan,
        // dung khi tab an, va co tran so luot.
        $response->assertDontSee("Kiểm tra trạng thái", false);
        $response->assertSee("var STEPS", false);
        $response->assertSee("document.hidden", false);
        $response->assertSee("row.dataset.pollUrl", false);
    }

    public function test_the_screen_never_offers_a_python_button(): void
    {
        $project = VideoProject::create(['title' => 'TEST render video '.uniqid()]);

        $response = $this->get(route('video-projects.render-video', $project->id));

        foreach (['video-session/queue', 'preflight', 'compose-final'] as $gone) {
            $response->assertDontSee($gone, false);
        }
    }
}
