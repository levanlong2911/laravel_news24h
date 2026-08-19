<?php

namespace Tests\Feature\Video;

use App\Enums\VideoSessionStatus;
use App\Enums\VideoShotStatus;
use App\Models\VideoProject;
use App\Models\VideoSession;
use App\Models\VideoShot;
use App\Repositories\Interfaces\VideoShotRepositoryInterface;
use App\Services\VideoSessionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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

        $project = VideoProject::create(['title' => 'TEST state machine '.uniqid()]);
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
            'project' => $this->session->project->title,
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

    public function test_recompose_refuses_to_change_a_rendered_shots_content(): void
    {
        $this->service->storeFromPython($this->composePayload('s1'));
        $shot = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's1')->first();
        $this->service->reportShotResult($shot->id, true, '/renders/shots/s1/x.mp4', 0.18, [
            'render_kind' => 'video', 'provider' => 'fal', 'model' => 'veo',
            'sent_prompt' => 'prompt v1', 'prompt_sha256' => hash('sha256', 'prompt v1'),
        ]);
        $this->forceBackToComposing();

        $result = $this->service->storeFromPython($this->composePayload('s1', ['compiled_prompt' => 'prompt v2']));

        $this->assertTrue($result['skipped']);
        $this->assertSame('plan_revision_conflict', $result['error']);
        $this->assertSame([$shot->id], $result['protected_changed_shot_ids']);
        $this->assertSame(['compiled_prompt'], $result['changed_fields'][$shot->id]);

        $fresh = $shot->fresh();
        $this->assertSame(VideoShotStatus::RENDERED->value, $fresh->status);
        $this->assertSame('/renders/shots/s1/x.mp4', $fresh->artifact_path);
        $this->assertSame('prompt v1', $fresh->compiled_prompt);
    }

    public function test_recompose_allows_an_identical_payload_for_a_rendered_shot(): void
    {
        $this->service->storeFromPython($this->composePayload('s1', [
            'spec' => ['camera' => 'wide', 'duration_s' => 5],
        ]));
        $shot = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's1')->first();
        $this->service->reportShotResult($shot->id, true, '/renders/shots/s1/x.mp4', 0.18, [
            'render_kind' => 'video', 'provider' => 'fal', 'model' => 'veo',
            'sent_prompt' => 'prompt v1', 'prompt_sha256' => hash('sha256', 'prompt v1'),
        ]);
        $this->forceBackToComposing();

        $result = $this->service->storeFromPython($this->composePayload('s1', [
            'spec' => ['duration_s' => 5, 'camera' => 'wide'],
        ]));

        $this->assertArrayNotHasKey('skipped', $result);
        $this->assertSame(VideoShotStatus::RENDERED->value, $shot->fresh()->status);
    }

    public function test_recompose_ignores_a_changed_cost_estimate_for_a_rendered_shot(): void
    {
        $this->service->storeFromPython($this->composePayload('s1'));
        $shot = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's1')->first();
        $this->service->reportShotResult($shot->id, true, '/renders/shots/s1/x.mp4', 0.18, [
            'render_kind' => 'video', 'provider' => 'fal', 'model' => 'veo',
            'sent_prompt' => 'prompt v1', 'prompt_sha256' => hash('sha256', 'prompt v1'),
        ]);
        $this->forceBackToComposing();

        $result = $this->service->storeFromPython($this->composePayload('s1', [
            'render_plan' => ['cost_estimate' => 0.99],
        ]));

        $this->assertArrayNotHasKey('skipped', $result);
    }

    public function test_recompose_treats_a_cost_estimate_key_inside_spec_json_as_real_content(): void
    {
        $this->service->storeFromPython($this->composePayload('s1', [
            'spec' => ['cost_estimate' => 'not-actually-a-cost', 'camera' => 'wide'],
        ]));
        $shot = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's1')->first();
        $this->service->reportShotResult($shot->id, true, '/renders/shots/s1/x.mp4', 0.18, [
            'render_kind' => 'video', 'provider' => 'fal', 'model' => 'veo',
            'sent_prompt' => 'prompt v1', 'prompt_sha256' => hash('sha256', 'prompt v1'),
        ]);
        $this->forceBackToComposing();

        $result = $this->service->storeFromPython($this->composePayload('s1', [
            'spec' => ['cost_estimate' => 'changed', 'camera' => 'wide'],
        ]));

        $this->assertTrue($result['skipped']);
        $this->assertSame(['spec_json'], $result['changed_fields'][$shot->id]);
    }

    public function test_recompose_does_not_block_content_changes_on_a_failed_shot(): void
    {
        $this->service->storeFromPython($this->composePayload('s1'));
        $shot = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's1')->first();
        $shot->update(['status' => VideoShotStatus::FAILED->value]);
        $this->forceBackToComposing();

        $result = $this->service->storeFromPython($this->composePayload('s1', ['compiled_prompt' => 'prompt v2']));

        $this->assertArrayNotHasKey('skipped', $result);
        $fresh = $shot->fresh();
        $this->assertSame('prompt v2', $fresh->compiled_prompt);
    }

    /** @dataProvider contentProtectedStatuses */
    public function test_recompose_refuses_content_change_for_each_protected_status(string $status): void
    {
        $this->service->storeFromPython($this->composePayload('s1'));
        $shot = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's1')->first();
        $shot->update(['status' => $status]);
        $this->forceBackToComposing();

        $result = $this->service->storeFromPython($this->composePayload('s1', ['negative_prompt' => 'blurry']));

        $this->assertTrue($result['skipped']);
        $this->assertSame('plan_revision_conflict', $result['error']);
        $this->assertSame([$shot->id], $result['protected_changed_shot_ids']);
        $this->assertSame($status, $shot->fresh()->status);
        $this->assertNull($shot->fresh()->negative_prompt);
    }

    public static function contentProtectedStatuses(): array
    {
        return [
            'claimed' => [VideoShotStatus::CLAIMED->value],
            'rendering' => [VideoShotStatus::RENDERING->value],
            'rendered' => [VideoShotStatus::RENDERED->value],
        ];
    }

    public function test_recompose_refuses_a_beat_change_on_a_claimed_shot(): void
    {
        $this->service->storeFromPython($this->composePayload('s1'));
        $shot = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's1')->first();
        $shot->update(['status' => VideoShotStatus::CLAIMED->value]);
        $this->forceBackToComposing();

        $result = $this->service->storeFromPython($this->composePayload('s1', ['beat' => 'b2']));

        $this->assertTrue($result['skipped']);
        $this->assertSame(['beat'], $result['changed_fields'][$shot->id]);
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

    // ---- plan_revision + superseded: bo shot khoi payload moi khong duoc de mo mo ----

    public function test_first_compose_sets_plan_revision_to_one(): void
    {
        $this->service->storeFromPython($this->composePayload('s1'));

        $this->assertSame(1, $this->session->fresh()->plan_revision);
    }

    public function test_recompose_increments_plan_revision_on_session_and_touched_shots(): void
    {
        $this->service->storeFromPython($this->composePayload('s1'));
        $this->forceBackToComposing();

        $this->service->storeFromPython($this->composePayload('s1'));

        $this->assertSame(2, $this->session->fresh()->plan_revision);
        $shot = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's1')->first();
        $this->assertSame(2, $shot->plan_revision);
    }

    public function test_recompose_marks_a_dropped_shot_as_superseded(): void
    {
        $payload = $this->composePayload('s1');
        $payload['shots'][] = ['shot_code' => 's2', 'kind' => 'motion', 'beat' => 'b1', 'shot_type' => 'establish', 'spec' => [], 'compiled_prompt' => 'p2', 'render_plan' => ['cost_estimate' => 0.1]];
        $this->service->storeFromPython($payload);
        $this->forceBackToComposing();

        // s2 bien mat khoi payload lan nay
        $this->service->storeFromPython($this->composePayload('s1'));

        $s1 = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's1')->first();
        $s2 = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's2')->first();
        $this->assertSame(VideoShotStatus::DRAFT->value, $s1->status);
        $this->assertSame(VideoShotStatus::SUPERSEDED->value, $s2->status);
    }

    public function test_recompose_handles_a_dropped_a_changed_and_an_added_shot_in_one_call(): void
    {
        $payload = $this->composePayload('s1');
        $payload['shots'][] = ['shot_code' => 's2', 'kind' => 'motion', 'beat' => 'b1', 'shot_type' => 'establish', 'spec' => [], 'compiled_prompt' => 'p2 v1', 'render_plan' => ['cost_estimate' => 0.1]];
        $this->service->storeFromPython($payload);
        $this->forceBackToComposing();

        // s1 bi bo, s2 doi noi dung, s3 la shot moi
        $this->service->storeFromPython([
            'project' => $this->session->project->title,
            'code' => $this->session->code,
            'renderplan' => ['scenes' => []],
            'shots' => [
                ['shot_code' => 's2', 'kind' => 'motion', 'beat' => 'b1', 'shot_type' => 'establish', 'spec' => [], 'compiled_prompt' => 'p2 v2', 'render_plan' => ['cost_estimate' => 0.2]],
                ['shot_code' => 's3', 'kind' => 'motion', 'beat' => 'b1', 'shot_type' => 'establish', 'spec' => [], 'compiled_prompt' => 'p3', 'render_plan' => ['cost_estimate' => 0.3]],
            ],
        ]);

        $s1 = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's1')->first();
        $s2 = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's2')->first();
        $s3 = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's3')->first();

        $this->assertSame(VideoShotStatus::SUPERSEDED->value, $s1->status);
        $this->assertSame('p2 v2', $s2->compiled_prompt);
        $this->assertNotSame(VideoShotStatus::SUPERSEDED->value, $s2->status);
        $this->assertNotNull($s3);
        $this->assertSame(VideoShotStatus::DRAFT->value, $s3->status);
    }

    /** @dataProvider protectedStatuses */
    public function test_recompose_refuses_entirely_when_a_dropped_shot_is_in_a_protected_status(string $status): void
    {
        $this->service->storeFromPython($this->composePayload('s1'));
        $shot = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's1')->first();
        $shot->update(['status' => $status]);
        $this->forceBackToComposing();

        // Payload moi bo s1, chi con s2 — s1 dang o trang thai bao ve.
        $result = $this->service->storeFromPython([
            'project' => $this->session->project->title,
            'code' => $this->session->code,
            'renderplan' => ['scenes' => []],
            'shots' => [['shot_code' => 's2', 'kind' => 'motion', 'beat' => 'b1', 'shot_type' => 'establish', 'spec' => [], 'compiled_prompt' => 'p2', 'render_plan' => ['cost_estimate' => 0.1]]],
        ]);

        $this->assertTrue($result['skipped']);
        $this->assertSame('plan_revision_conflict', $result['error']);
        $this->assertSame([$shot->id], $result['protected_shot_ids']);

        // Khong dung gi ca: khong shot moi, s1 khong bi doi, plan_revision khong tang.
        $this->assertSame($status, $shot->fresh()->status);
        $this->assertNull(VideoShot::where('session_id', $this->session->id)->where('shot_code', 's2')->first());
        $this->assertSame(1, $this->session->fresh()->plan_revision);
        $this->assertSame(VideoSessionStatus::COMPOSING->value, $this->session->fresh()->status);
    }

    public static function protectedStatuses(): array
    {
        return [
            'claimed' => [VideoShotStatus::CLAIMED->value],
            'rendering' => [VideoShotStatus::RENDERING->value],
            'rendered' => [VideoShotStatus::RENDERED->value],
            'failed' => [VideoShotStatus::FAILED->value],
        ];
    }

    public function test_a_superseded_shot_is_resurrected_when_it_reappears_in_a_later_compose(): void
    {
        $payload = $this->composePayload('s1');
        $payload['shots'][] = ['shot_code' => 's2', 'kind' => 'motion', 'beat' => 'b1', 'shot_type' => 'establish', 'spec' => [], 'compiled_prompt' => 'p2', 'render_plan' => ['cost_estimate' => 0.1]];
        $this->service->storeFromPython($payload);
        $this->forceBackToComposing();
        $this->service->storeFromPython($this->composePayload('s1')); // s2 bi bo -> superseded
        $s2 = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's2')->first();
        $this->assertSame(VideoShotStatus::SUPERSEDED->value, $s2->status);
        $this->forceBackToComposing();

        $payloadAgain = $this->composePayload('s1');
        $payloadAgain['shots'][] = ['shot_code' => 's2', 'kind' => 'motion', 'beat' => 'b1', 'shot_type' => 'establish', 'spec' => [], 'compiled_prompt' => 'p2 v2', 'render_plan' => ['cost_estimate' => 0.15]];
        $this->service->storeFromPython($payloadAgain);

        $fresh = $s2->fresh();
        $this->assertSame(VideoShotStatus::DRAFT->value, $fresh->status);
        $this->assertSame('p2 v2', $fresh->compiled_prompt);
    }

    public function test_recompose_locks_the_sessions_shots_for_update(): void
    {
        $this->service->storeFromPython($this->composePayload('s1'));
        $this->forceBackToComposing();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->service->storeFromPython($this->composePayload('s1'));

        DB::listen(fn () => null);

        $lockQueries = array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'video_shots') && str_contains($sql, 'for update'),
        );

        $this->assertNotEmpty(
            $lockQueries,
            "storeFromPython() phai phat mot truy van SELECT ... FOR UPDATE tren video_shots.\n".
            'SQL da chay: '.implode("\n", $queries),
        );
    }

    public function test_a_shot_dropped_into_superseded_can_no_longer_be_claimed(): void
    {
        $payload = $this->composePayload('s1');
        $payload['shots'][] = ['shot_code' => 's2', 'kind' => 'motion', 'beat' => 'b1', 'shot_type' => 'establish', 'spec' => [], 'compiled_prompt' => 'p2', 'render_plan' => ['cost_estimate' => 0.1]];
        $this->service->storeFromPython($payload);
        $this->service->approveSelectedShots(
            $this->session->id,
            VideoShot::where('session_id', $this->session->id)->pluck('id')->all(),
        );
        $this->service->queueApproved($this->session->id);
        $this->forceBackToComposing();

        // s2 bi bo khoi payload lan nay -> superseded, du no dang QUEUED
        $this->service->storeFromPython($this->composePayload('s1'));
        $s2 = VideoShot::where('session_id', $this->session->id)->where('shot_code', 's2')->first();
        $this->assertSame(VideoShotStatus::SUPERSEDED->value, $s2->status);

        $claimed = app(VideoShotRepositoryInterface::class)->claimForSession(
            $this->session->id, 'worker-a', 'token-a', 10, now()->addMinutes(10),
        );

        $this->assertFalse($claimed->contains('id', $s2->id));
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
