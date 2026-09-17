<?php

namespace Tests\Feature\Video;

use App\Models\Admin;
use App\Services\VideoProjectService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Mo man Final Composition tren DU AN CO THAT cua may nay.
 *
 * KHONG chay cung suite mac dinh (nhom `smoke`, bi loai o phpunit.xml): ket qua cua
 * no phu thuoc du lieu tung may, nen de chung voi cac test fixture thi mot suite
 * xanh khong con nghia gi chung.
 *
 * Fixture chi chung minh duoc cai minh vua dung ra. Cai nay bat mot thu khac: mot
 * du an that — co ban ke hoach, co keyframe, co canary xen giua — lam trang chet.
 *
 * @group smoke
 */
class FinalCompositionSmokeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Phai la admin THAT: `VideoProjectPolicy` cho qua khi `isAdmin()`, hoac khi
        // la thanh vien SO HUU du an. Du an co san tren may khong thuoc ve ai trong
        // test nay, nen chi duong admin mo duoc.
        $roleId = DB::table('roles')->where('name', 'admin')->value('id');

        if ($roleId === null) {
            $roleId = (string) Str::uuid();
            DB::table('roles')->insert(['id' => $roleId, 'name' => 'admin']);
        }

        $this->actingAs(Admin::create([
            'name' => 'TEST final composition smoke '.uniqid(),
            'email' => 'test_fc_smoke_'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'role_id' => $roleId,
        ]));
    }

    /**
     * Mot clip du dieu kien len final, tim bang SQL THUAN.
     *
     * Khong dung `finalCompositionCells()` de tim du lieu roi lai dung chinh no de
     * kiem: mot bo loc hong se tu chung minh la dung, va test bien thanh `skip`.
     *
     * Dieu kien phai khop dung man hinh, va THU TU cua no cung phai khop:
     *
     *   1. luot production MOI NHAT cua tung shot   (`keyBy` giu phan tu cuoi)
     *   2. roi moi hoi luot ay co thanh cong khong
     *
     * Dao hai buoc nay la test doi sai: mot shot render lai hai lan cung thanh cong
     * thi service chi tra lan sau, con "moi lan thanh cong" se doi ca hai. Va mot
     * shot thanh cong o lan 1 roi hong o lan 2 thi service loai han shot do.
     *
     * Scene con phai thuoc ban ke hoach MOI NHAT cua du an — lay dai mot scene co
     * the roi vao revision cu roi skip oan trong khi du an khac co du lieu hop le.
     *
     * @return array{project_id: string, render_ids: list<string>}|null
     */
    private function qualifyingClip(): ?array
    {
        $rows = DB::table('video_renders as r')
            ->join('video_shots as sh', 'sh.id', '=', 'r.shot_id')
            ->join('video_render_scenes as sc', 'sc.id', '=', 'sh.scene_id')
            ->where('r.render_kind', 'video')
            ->where('r.execution_purpose', 'production')
            ->whereNotExists(static fn ($query) => $query
                ->from('video_renders as newer')
                ->whereColumn('newer.shot_id', 'r.shot_id')
                ->where('newer.render_kind', 'video')
                ->where('newer.execution_purpose', 'production')
                ->whereColumn('newer.attempt_no', '>', 'r.attempt_no')
                ->selectRaw('1'))
            ->where('r.execution_status', 'succeeded')
            ->select('r.id as render_id', 'sc.project_id as project_id', 'sc.revision as revision')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $latest = DB::table('video_render_scenes')
            ->whereIn('project_id', $rows->pluck('project_id')->unique()->all())
            ->groupBy('project_id')
            ->select('project_id', DB::raw('max(revision) as revision'))
            ->pluck('revision', 'project_id');

        $current = $rows->filter(
            static fn ($row) => (int) $row->revision === (int) ($latest[$row->project_id] ?? -1),
        );

        if ($current->isEmpty()) {
            return null;
        }

        $projectId = (string) $current->first()->project_id;

        return [
            'project_id' => $projectId,
            'render_ids' => $current
                ->where('project_id', $projectId)
                ->pluck('render_id')
                ->map('strval')
                ->values()
                ->all(),
        ];
    }

    public function test_a_project_that_really_has_clips_opens(): void
    {
        $found = $this->qualifyingClip();

        // Skip CHI o day, va chi vi may nay khong co du lieu. Tu day tro xuong moi
        // sai lech deu la do, khong duoc bo qua.
        if ($found === null) {
            $this->markTestSkipped('May nay chua co clip production nao dung xong o ban ke hoach moi nhat.');
        }

        $cells = app(VideoProjectService::class)->finalCompositionCells($found['project_id']);
        $served = array_column($cells['clips'], 'render_id');

        // Chieu 1 — KHONG BO SOT: clip SQL tim duoc phai co mat.
        foreach ($found['render_ids'] as $renderId) {
            $this->assertContains(
                $renderId,
                $served,
                'SQL thuan tim duoc mot clip du dieu kien ma service khong tra ra.',
            );
        }

        // Chieu 2 — KHONG NHAN BUA: thu gi service tra ra cung phai du dieu kien.
        // Chieu nay bat luot canary chiem cho clip that, thu ma chieu 1 khong thay.
        foreach ($served as $renderId) {
            $this->assertContains(
                $renderId,
                $found['render_ids'],
                'Service tra ra mot render khong dat dieu kien video+succeeded+production o revision moi nhat.',
            );
        }

        $response = $this->get(route('video-projects.final-composition-preview', $found['project_id']));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('Chưa có clip nào dựng xong', $html);
        $this->assertStringContainsString('<span>'.count($cells['clips']).' clips</span>', $html);

        // Moi clip mot dong ben trai VA mot khoi tren timeline — hai cho, cung mot
        // mang, nen khong the lech.
        $this->assertSame(count($cells['clips']), substr_count($html, 'fcomp-clip-item'));
        $this->assertSame(count($cells['clips']), substr_count($html, 'fcomp-shot'));
    }
}
