<?php

namespace Tests\Feature\Video;

use App\Enums\VideoShotStatus;
use App\Models\VideoProject;
use App\Models\VideoSession;
use App\Models\VideoShot;
use App\Services\VideoSessionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Priority Group #4 — hai đường ghi `video_shots.status` từng cho phép đi
 * NGƯỢC state machine đã tài liệu ở VideoShotStatus:
 *
 *   1. storeFromPython() (Python compose lại session) ghi đè status/approved_at/
 *      artifact_path/ownership-lease của MỌI shot đã tồn tại về draft — kể cả
 *      shot đã RENDERED (tốn tiền thật).
 *   2. approveByIds()/updateShotStatus() (nút Duyệt/Sửa/Từ chối) không kiểm
 *      trạng thái nguồn — một shot đã rendered/queued/claimed/rendering vẫn
 *      có thể bị đẩy lại APPROVED qua gọi API trực tiếp.
 */
class ShotStateMachineTest extends TestCase
{
    use DatabaseTransactions;

    private VideoSessionService $service;

    private VideoSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(VideoSessionService::class);

        $project = VideoProject::create(['name' => 'TEST state machine '.uniqid()]);
        $this->session = VideoSession::create([
            'project_id' => $project->id,
            'code' => 'test_statemachine_'.uniqid(),
            'status' => 'composing',
        ]);
    }

    /**
     * §Priority Group #4 (phần 2) chỉ cho storeFromPython() ghi khi session
     * đang `composing` — sau lần compose đầu, session tự chuyển `reviewing`.
     * Đặt lại `composing` mô phỏng đúng đường CÒN LẠI để re-compose xảy ra
     * (operator tự tay reset qua tinker/thao tác vận hành), không phải né
     * guard — bản thân guard đó có bộ test riêng ở SessionComposeGuardTest.
     */
    private function forceBackToComposing(): void
    {
        // Update qua query builder, KHONG qua $this->session->update(): storeFromPython()
        // da doi status trong DB o mot instance khac, nen $this->session dang cache gia
        // tri CU — Eloquent so sanh 'composing' moi voi 'composing' cache roi coi la
        // khong doi gi va bo qua UPDATE that su (dirty-tracking tren instance cu).
        VideoSession::whereKey($this->session->id)->update(['status' => 'composing']);
    }

    private function composePayload(string $shotCode, array $overrides = []): array
    {
        return [
            'project' => $this->session->project->name,
            'code' => $this->session->code,
            'renderplan' => ['scenes' => []],
            'shots' => [array_merge([
                'shot_code' => $shotCode, 'kind' => 'motion', 'beat' => 'b1',
                'shot_type' => 'establish', 'spec' => ['v' => 1],
                'compiled_prompt' => 'prompt v1', 'negative_prompt' => null,
                'render_plan' => ['cost_estimate' => 0.18],
            ], $overrides)],
        ];
    }

    // ---- storeFromPython(): compose lại KHÔNG được ghi đè cột vận hành ----

    public function test_a_new_shot_from_compose_defaults_to_draft(): void
    {
        $this->service->storeFromPython($this->composePayload('s1'));

        $shot = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's1')->first();
        $this->assertSame(VideoShotStatus::DRAFT->value, $shot->status);
    }

    public function test_recompose_preserves_an_approved_shots_status(): void
    {
        $this->service->storeFromPython($this->composePayload('s1'));
        $shot = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's1')->first();
        $shot->update(['status' => VideoShotStatus::APPROVED->value, 'approved_at' => now()]);
        $this->forceBackToComposing();

        $this->service->storeFromPython($this->composePayload('s1', ['compiled_prompt' => 'prompt v2']));

        $fresh = $shot->fresh();
        $this->assertSame(VideoShotStatus::APPROVED->value, $fresh->status);
        $this->assertNotNull($fresh->approved_at);
        $this->assertSame('prompt v2', $fresh->compiled_prompt, 'noi dung van phai duoc cap nhat');
    }

    public function test_recompose_preserves_a_rendered_shots_status_and_artifact(): void
    {
        $this->service->storeFromPython($this->composePayload('s1'));
        $shot = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's1')->first();
        $this->service->reportShotResult($shot->id, true, '/renders/shots/s1/x.mp4', 0.18, [
            'render_kind' => 'video', 'provider' => 'fal', 'model' => 'veo',
            'sent_prompt' => 'prompt v1', 'prompt_sha256' => hash('sha256', 'prompt v1'),
        ]);
        $this->forceBackToComposing();

        $this->service->storeFromPython($this->composePayload('s1', ['compiled_prompt' => 'prompt v2']));

        $fresh = $shot->fresh();
        $this->assertSame(VideoShotStatus::RENDERED->value, $fresh->status);
        $this->assertSame('/renders/shots/s1/x.mp4', $fresh->artifact_path);
    }

    public function test_recompose_preserves_review_note_and_ownership_lease_fields(): void
    {
        $this->service->storeFromPython($this->composePayload('s1'));
        $shot = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's1')->first();
        $shot->update([
            'status' => VideoShotStatus::CLAIMED->value,
            'review_note' => 'ghi chu nguoi duyet',
            'worker_id' => 'worker-a',
            'claim_token' => 'token-abc',
            'claimed_at' => now(),
            'lease_expires_at' => now()->addMinutes(10),
        ]);
        $this->forceBackToComposing();

        $this->service->storeFromPython($this->composePayload('s1'));

        $fresh = $shot->fresh();
        $this->assertSame(VideoShotStatus::CLAIMED->value, $fresh->status);
        $this->assertSame('ghi chu nguoi duyet', $fresh->review_note);
        $this->assertSame('worker-a', $fresh->worker_id);
        $this->assertSame('token-abc', $fresh->claim_token);
        $this->assertNotNull($fresh->claimed_at);
        $this->assertNotNull($fresh->lease_expires_at);
    }

    public function test_recompose_still_updates_content_fields_on_a_non_draft_shot(): void
    {
        $this->service->storeFromPython($this->composePayload('s1'));
        $shot = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's1')->first();
        $shot->update(['status' => VideoShotStatus::APPROVED->value]);
        $this->forceBackToComposing();

        $this->service->storeFromPython($this->composePayload('s1', [
            'compiled_prompt' => 'prompt v2', 'negative_prompt' => 'blurry',
            'render_plan' => ['cost_estimate' => 0.25], 'shot_type' => 'hero',
        ]));

        $fresh = $shot->fresh();
        $this->assertSame('prompt v2', $fresh->compiled_prompt);
        $this->assertSame('blurry', $fresh->negative_prompt);
        $this->assertSame('hero', $fresh->shot_type);
        $this->assertEqualsWithDelta(0.25, (float) $fresh->cost_estimate, 0.0001);
    }

    public function test_recompose_still_creates_a_genuinely_new_shot_as_draft(): void
    {
        $this->service->storeFromPython($this->composePayload('s1'));
        VideoShot::where('session_id', $this->session->id)->where('shot_code', 's1')
            ->update(['status' => VideoShotStatus::RENDERED->value]);
        $this->forceBackToComposing();

        $payload = $this->composePayload('s1');
        $payload['shots'][] = [
            'shot_code' => 's2', 'kind' => 'motion', 'beat' => 'b1', 'shot_type' => 'establish',
            'spec' => [], 'compiled_prompt' => 'prompt s2', 'render_plan' => ['cost_estimate' => 0.1],
        ];
        $this->service->storeFromPython($payload);

        $s2 = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's2')->first();
        $this->assertNotNull($s2);
        $this->assertSame(VideoShotStatus::DRAFT->value, $s2->status);
    }

    // ---- approveByIds()/updateShotStatus(): tu choi duyet/sua/tu choi shot da qua hang doi ----

    public function test_approve_by_ids_skips_shots_past_the_review_gate(): void
    {
        $this->service->storeFromPython($this->composePayload('s1'));
        $shot = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's1')->first();
        $shot->update(['status' => VideoShotStatus::RENDERED->value]);

        $this->service->approveSelectedShots($this->session->id, [$shot->id]);

        $this->assertSame(VideoShotStatus::RENDERED->value, $shot->fresh()->status);
    }

    public function test_approve_by_ids_still_approves_a_reviewable_shot(): void
    {
        $this->service->storeFromPython($this->composePayload('s1'));
        $shot = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's1')->first();

        $this->service->approveSelectedShots($this->session->id, [$shot->id]);

        $this->assertSame(VideoShotStatus::APPROVED->value, $shot->fresh()->status);
    }

    public function test_update_shot_status_rejects_action_on_a_shot_past_the_review_gate(): void
    {
        $this->service->storeFromPython($this->composePayload('s1'));
        $shot = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's1')->first();
        $shot->update(['status' => VideoShotStatus::QUEUED->value]);

        $ok = $this->service->updateShotStatus($shot->id, 'approve', null);

        $this->assertFalse($ok);
        $this->assertSame(VideoShotStatus::QUEUED->value, $shot->fresh()->status);
    }

    public function test_update_shot_status_still_works_on_a_reviewable_shot(): void
    {
        $this->service->storeFromPython($this->composePayload('s1'));
        $shot = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's1')->first();

        $ok = $this->service->updateShotStatus($shot->id, 'reject', 'khong dat');

        $this->assertTrue($ok);
        $fresh = $shot->fresh();
        $this->assertSame(VideoShotStatus::REJECTED->value, $fresh->status);
        $this->assertSame('khong dat', $fresh->review_note);
    }
}
