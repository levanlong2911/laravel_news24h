<?php

namespace Tests\Feature\Video;

use App\Models\Article;
use App\Models\VideoProject;
use App\Models\VideoSession;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ô thiết kế thuộc DỰ ÁN, không thuộc lượt chạy. Bộ test này khoá đúng điều đó:
 * một session mới toanh của cùng dự án vẫn thấy ảnh trạng thái đã duyệt, nên
 * không có lý do gì render lại chúng.
 */
class DesignCellApiTest extends TestCase
{
    use DatabaseTransactions;

    private function token(): array
    {
        config(['video.api_token' => 'test-token']);

        return ['X-Video-Token' => 'test-token'];
    }

    private function project(): VideoProject
    {
        $article = Article::create([
            'keyword_id' => DB::table('keywords')->value('id'),
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('', true)),
            'source_title' => 'A vessel',
            'title' => 'A vessel '.uniqid(),
            'slug' => 'a-vessel-'.uniqid(),
            'content' => 'Steel hull.',
            'status' => 'pending',
        ]);

        return VideoProject::create([
            'title' => 'A vessel '.uniqid(),
            'article_id' => $article->id,
        ]);
    }

    private function videoSession(VideoProject $project): VideoSession
    {
        return VideoSession::create([
            'project_id' => $project->id,
            'code' => 'sess_'.Str::random(8),
            'status' => 'composing',
        ]);
    }

    /** @return array{0: string, 1: string} [$cellId, $artifactId] */
    private function cell(VideoProject $project, string $code, string $provesState, string $status = 'approved', ?string $sourceCellId = null): array
    {
        $artifactId = (string) Str::uuid();
        $cellId = (string) Str::uuid();

        // design_cell_id noi SAU: hai bang tro vao nhau, chen thang la 1452.
        DB::table('video_artifacts')->insert([
            'id' => $artifactId, 'project_id' => $project->id,
            'artifact_type' => 'image', 'storage_disk' => 'public',
            'storage_path' => "/renders/design/{$code}.jpg",
            'sha256' => str_repeat('a', 64), 'width' => 720, 'height' => 1280,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('video_design_cells')->insert([
            'id' => $cellId, 'project_id' => $project->id, 'cell_code' => $code,
            'cell_type' => 'construction_state', 'state' => 'construction',
            'proves_state' => $provesState, 'source_cell_id' => $sourceCellId,
            'prompt_sha256' => hash('sha256', $code),
            'selected_artifact_id' => $artifactId, 'status' => $status, 'revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('video_artifacts')->where('id', $artifactId)->update(['design_cell_id' => $cellId]);

        return [$cellId, $artifactId];
    }

    public function test_a_brand_new_session_sees_design_cells_the_project_already_owns(): void
    {
        $project = $this->project();
        [$hall] = $this->cell($project, 'hall', 'hull_preassembly');
        $this->cell($project, 'keel', 'keel_framework', sourceCellId: $hall);

        // Session tao SAU khi o thiet ke da ton tai — day la ca thuc te: lam lai
        // video lan hai khong duoc render lai vo tau.
        $session = $this->videoSession($project);

        $response = $this->getJson("/api/video-sessions/{$session->code}/design-cells", $this->token());

        $response->assertOk();
        $this->assertSame($project->id, $response->json('project_id'));
        $this->assertCount(2, $response->json('design_cells'));

        $byState = collect($response->json('design_cells'))->keyBy('proves_state');
        $this->assertSame('/renders/design/hall.jpg', $byState['hull_preassembly']['artifact_path']);
        $this->assertNull($byState['hull_preassembly']['source_cell_code']);
        $this->assertSame('hall', $byState['keel_framework']['source_cell_code']);
    }

    public function test_a_candidate_cell_proves_nothing_and_is_not_served(): void
    {
        $project = $this->project();
        $this->cell($project, 'hall', 'hull_preassembly');
        $this->cell($project, 'shell', 'hull_shell', status: 'candidate');

        $response = $this->getJson(
            "/api/video-sessions/{$this->videoSession($project)->code}/design-cells",
            $this->token(),
        );

        $states = collect($response->json('design_cells'))->pluck('proves_state')->all();
        $this->assertSame(['hull_preassembly'], $states);
    }

    public function test_cells_of_another_project_never_leak_in(): void
    {
        $mine = $this->project();
        $this->cell($mine, 'hall', 'hull_preassembly');
        $this->cell($this->project(), 'hall', 'hull_preassembly');

        $response = $this->getJson(
            "/api/video-sessions/{$this->videoSession($mine)->code}/design-cells",
            $this->token(),
        );

        $this->assertCount(1, $response->json('design_cells'));
    }

    public function test_an_unknown_session_returns_an_empty_list_not_an_error(): void
    {
        $response = $this->getJson('/api/video-sessions/khong-ton-tai/design-cells', $this->token());

        $response->assertOk();
        $this->assertSame([], $response->json('design_cells'));
        $this->assertNull($response->json('project_id'));
    }

    public function test_the_endpoint_rejects_a_bad_token(): void
    {
        config(['video.api_token' => 'test-token']);

        $this->getJson('/api/video-sessions/whatever/design-cells', ['X-Video-Token' => 'sai'])
            ->assertStatus(401);
    }
}
