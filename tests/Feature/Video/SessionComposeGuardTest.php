<?php

namespace Tests\Feature\Video;

use App\Enums\VideoSessionStatus;
use App\Enums\VideoShotStatus;
use App\Models\VideoProject;
use App\Models\VideoSession;
use App\Models\VideoShot;
use App\Services\VideoSessionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * §Priority Group #4 (phần 2) — storeFromPython() (POST /api/render-plans, Python
 * compose lại) chỉ được đi ĐÚNG một cạnh của state machine: composing → reviewing
 * (hoặc tạo mới). Callback trễ/lặp cho một session đã reviewing/rendering/done/
 * failed phải bị TỪ CHỐI TOÀN BỘ — không đụng session, không đụng shot, không
 * đụng cost_estimate_total. Cùng nguyên nhân với lỗi shot đã sửa (đi ngược
 * VideoShotStatus), nhưng ở cấp session.
 */
class SessionComposeGuardTest extends TestCase
{
    use DatabaseTransactions;

    private VideoSessionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(VideoSessionService::class);
    }

    private function makeSession(string $status, array $overrides = []): VideoSession
    {
        $project = VideoProject::create(['title' => 'TEST compose guard '.uniqid()]);

        $session = VideoSession::create(array_merge([
            'project_id' => $project->id,
            'code' => 'test_compose_guard_'.uniqid(),
            'status' => $status,
            'cost_estimate_total' => 1.23,
        ], $overrides));

        $this->storeRenderPlan($session, ['scenes' => ['old']]);

        return $session;
    }

    private function payload(VideoSession $session, string $shotCode = 's1'): array
    {
        return [
            'project' => $session->project->title,
            'code' => $session->code,
            'renderplan' => ['scenes' => ['new']],
            'shots' => [[
                'shot_code' => $shotCode, 'kind' => 'motion', 'beat' => 'b1',
                'shot_type' => 'establish', 'spec' => [], 'compiled_prompt' => 'prompt moi',
                'render_plan' => ['cost_estimate' => 9.99],
            ]],
        ];
    }

    public function test_compose_creates_a_new_session_as_reviewing(): void
    {
        $project = VideoProject::create(['title' => 'TEST compose guard '.uniqid()]);
        $result = $this->service->storeFromPython([
            'project' => $project->title, 'code' => 'test_new_'.uniqid(),
            'renderplan' => ['scenes' => []], 'shots' => [[
                'shot_code' => 's1', 'kind' => 'motion', 'beat' => 'b1',
                'render_plan' => ['cost_estimate' => 0.1],
            ]],
        ]);

        $session = VideoSession::find($result['session_id']);
        $this->assertSame(VideoSessionStatus::REVIEWING->value, $session->status);
        $this->assertArrayNotHasKey('skipped', $result);
    }

    public function test_compose_transitions_a_composing_session_to_reviewing(): void
    {
        $session = $this->makeSession(VideoSessionStatus::COMPOSING->value);

        $result = $this->service->storeFromPython($this->payload($session));

        $fresh = $session->fresh();
        $this->assertSame(VideoSessionStatus::REVIEWING->value, $fresh->status);
        $this->assertSame(['scenes' => ['new']], $fresh->renderplan_json);
        $this->assertEqualsWithDelta(9.99, (float) $fresh->cost_estimate_total, 0.0001);
        $this->assertArrayNotHasKey('skipped', $result);
    }

    /** @dataProvider nonComposingStatuses */
    public function test_compose_is_rejected_and_changes_nothing_for_a_non_composing_session(string $status): void
    {
        $session = $this->makeSession($status);
        $shot = VideoShot::create([
            'session_id' => $session->id, 'beat' => 'b1', 'shot_code' => 's1', 'kind' => 'motion',
            'shot_type' => 'establish', 'spec_json' => [], 'compiled_prompt' => 'prompt cu',
            'status' => VideoShotStatus::RENDERED->value, 'artifact_path' => '/renders/shots/s1/x.mp4',
        ]);

        $result = $this->service->storeFromPython($this->payload($session));

        $this->assertTrue($result['skipped']);
        $this->assertSame($status, $result['status']);

        $freshSession = $session->fresh();
        $this->assertSame($status, $freshSession->status);
        $this->assertSame(['scenes' => ['old']], $freshSession->renderplan_json);
        $this->assertEqualsWithDelta(1.23, (float) $freshSession->cost_estimate_total, 0.0001);

        $freshShot = $shot->fresh();
        $this->assertSame(VideoShotStatus::RENDERED->value, $freshShot->status);
        $this->assertSame('/renders/shots/s1/x.mp4', $freshShot->artifact_path);
        $this->assertSame('prompt cu', $freshShot->compiled_prompt, 'noi dung shot cung KHONG duoc dung vao khi ca goi bi tu choi');
        $this->assertSame(1, VideoShot::where('session_id', $session->id)->count(), 'khong tao them shot nao');
    }

    public static function nonComposingStatuses(): array
    {
        return [
            'reviewing' => [VideoSessionStatus::REVIEWING->value],
            'rendering' => [VideoSessionStatus::RENDERING->value],
            'done' => [VideoSessionStatus::DONE->value],
            'failed' => [VideoSessionStatus::FAILED->value],
        ];
    }

    public function test_api_returns_409_when_session_is_not_composing(): void
    {
        config(['video.api_token' => 'compose-guard-test-token']);
        $session = $this->makeSession(VideoSessionStatus::DONE->value);

        $response = $this->postJson('/api/render-plans', $this->payload($session), [
            'X-Video-Token' => 'compose-guard-test-token',
        ]);

        $response->assertStatus(409)->assertJson(['status' => VideoSessionStatus::DONE->value]);
        $this->assertSame(VideoSessionStatus::DONE->value, $session->fresh()->status);
    }

    public function test_api_returns_409_with_plan_revision_conflict_when_a_rendered_shot_would_be_orphaned(): void
    {
        config(['video.api_token' => 'compose-guard-test-token']);
        $session = $this->makeSession(VideoSessionStatus::COMPOSING->value);
        $shot = VideoShot::create([
            'session_id' => $session->id, 'beat' => 'b1', 'shot_code' => 's1', 'kind' => 'motion',
            'shot_type' => 'establish', 'spec_json' => [], 'compiled_prompt' => 'prompt cu',
            'status' => VideoShotStatus::RENDERED->value,
        ]);

        $response = $this->postJson('/api/render-plans', $this->payload($session, 's2'), [
            'X-Video-Token' => 'compose-guard-test-token',
        ]);

        $response->assertStatus(409)->assertJson([
            'error' => 'plan_revision_conflict',
            'status' => VideoSessionStatus::COMPOSING->value,
            'protected_shot_ids' => [$shot->id],
        ]);
    }

    /** Gắn regression vào ĐÚNG vị trí — cùng lý do với các test khoá FOR UPDATE khác trong suite Video. */
    public function test_compose_guard_actually_issues_a_row_lock_on_the_session(): void
    {
        $session = $this->makeSession(VideoSessionStatus::COMPOSING->value);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->service->storeFromPython($this->payload($session));

        DB::listen(fn () => null);

        $lockQueries = array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'video_sessions') && str_contains($sql, 'for update'),
        );

        $this->assertNotEmpty(
            $lockQueries,
            "storeFromPython() phai phat mot truy van SELECT ... FOR UPDATE tren video_sessions.\n".
            'SQL da chay: '.implode("\n", $queries),
        );
    }
}
