<?php

namespace Tests\Feature\Video;

use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Models\VideoSession;
use App\Models\VideoShot;
use App\Services\VideoSessionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * MOI ARTIFACT PHAI TRUY NGUOC DUOC TOI DUNG LUOT RENDER DA TAO RA NO.
 *
 * Bat bien nay hong that ngay 2026-08-07: soan lai session ghi de `compiled_prompt`
 * trong khi `artifact_path` giu nguyen, nen ba dong chuoi khoe mot prompt KHONG HE
 * sinh ra tam anh dung canh no. Khong loi, khong log.
 *
 * `DatabaseTransactions` chu KHONG `RefreshDatabase`: suite nay chay tren DB THAT
 * cua may dev (phpunit.xml khong khai DB rieng), va `RefreshDatabase` se
 * `migrate:fresh` — tuc xoa sach du lieu that. Transaction thi rollback.
 */
class RenderLineageTest extends TestCase
{
    use DatabaseTransactions;

    private VideoSessionService $service;

    private VideoSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(VideoSessionService::class);

        $project = VideoProject::create(['name' => 'TEST lineage '.uniqid()]);
        $this->session = VideoSession::create([
            'project_id' => $project->id,
            'code' => 'test_lineage_'.uniqid(),
            'status' => 'reviewing',
        ]);
    }

    private function shot(string $code, string $kind = 'chain'): VideoShot
    {
        return VideoShot::create([
            'session_id' => $this->session->id,
            'beat' => $code, 'shot_code' => $code, 'shot_type' => 'establish',
            'kind' => $kind, 'spec_json' => [], 'compiled_prompt' => "prompt {$code}",
        ]);
    }

    /** Ho so nhu Python gui ve — sha la thu noi mot luot render voi luot sinh ra nguon cua no. */
    private function event(string $prompt, ?string $sourceSha, array $extra = []): array
    {
        return array_merge([
            'render_kind' => 'image', 'provider' => 'fal', 'model' => 'openai/gpt-image-2/edit',
            'sent_prompt' => $prompt, 'prompt_sha256' => hash('sha256', $prompt),
            'request_sha256' => hash('sha256', $prompt.'|req'),
            'source_prompt_sha256' => $sourceSha,
            'source_kind' => $sourceSha === null ? 'text' : 'render',
        ], $extra);
    }

    public function test_the_chain_becomes_a_real_graph_not_a_string(): void
    {
        $hall = $this->shot('chain_superyacht_hall');
        $keel = $this->shot('chain_superyacht_keel');
        $shell = $this->shot('chain_superyacht_shell');

        $this->service->reportShotResult($hall->id, true, '/r/hall.jpg', 0.015,
            $this->event('P_hall', null, ['proves_state' => 'hull_preassembly']));
        $this->service->reportShotResult($keel->id, true, '/r/keel.jpg', 0.015,
            $this->event('P_keel', hash('sha256', 'P_hall'), ['proves_state' => 'keel_framework']));
        $this->service->reportShotResult($shell->id, true, '/r/shell.jpg', 0.015,
            $this->event('P_shell', hash('sha256', 'P_keel'), ['proves_state' => 'hull_shell']));

        $motion = $this->shot('creation_craftsmanship_motion', 'motion');
        $this->service->reportShotResult($motion->id, true, '/r/craft.mp4', 0.18,
            $this->event('P_motion', hash('sha256', 'P_shell'), [
                'render_kind' => 'video', 'provider' => 'veo',
                'model' => 'fal-ai/veo3.1/lite/image-to-video',
                'source_kind' => 'state', 'requires_state' => 'hull_shell',
            ]));

        // Lan nguoc TOAN BO cay tu clip ve toi tam anh dau tien, khong doc mot
        // duong dan file nao.
        $clip = $motion->fresh()->latestRender;
        $this->assertSame('hull_shell', $clip->requires_state);
        $this->assertSame('P_shell', $clip->sourceRender->sent_prompt);
        $this->assertSame('P_keel', $clip->sourceRender->sourceRender->sent_prompt);
        $this->assertSame('P_hall', $clip->sourceRender->sourceRender->sourceRender->sent_prompt);
        $this->assertNull($clip->sourceRender->sourceRender->sourceRender->sourceRender,
            'mat goc sinh tu chu — khong co nguon');
    }

    public function test_rendering_again_adds_a_row_and_never_edits_the_old_one(): void
    {
        $shell = $this->shot('chain_superyacht_shell');

        $this->service->reportShotResult($shell->id, true, '/r/shell_v1.jpg', 0.015,
            $this->event('P_shell_v1', null, ['proves_state' => 'hull_shell']));
        $this->service->reportShotResult($shell->id, true, '/r/shell_v2.jpg', 0.015,
            $this->event('P_shell_v2', null, ['proves_state' => 'hull_shell']));

        $renders = $shell->fresh()->renders;
        $this->assertCount(2, $renders);
        $this->assertSame([1, 2], $renders->pluck('attempt_no')->all());

        // Dong cu KHONG bi sua — day la toan bo ly do bang nay ton tai.
        $this->assertSame('P_shell_v1', $renders[0]->sent_prompt);
        $this->assertSame('/r/shell_v1.jpg', $renders[0]->artifact_path);
        $this->assertSame('P_shell_v2', $renders[1]->sent_prompt);

        // `video_shots.artifact_path` la CON TRO toi ban moi nhat.
        $this->assertSame('/r/shell_v2.jpg', $shell->fresh()->artifact_path);
    }

    public function test_retrying_the_same_attempt_does_not_duplicate_cost_or_ledger(): void
    {
        $shot = $this->shot('idempotent_motion', 'motion');
        $event = $this->event('P_motion', null, ['render_kind' => 'video']);

        $this->service->reportShotResult(
            $shot->id, true, '/r/motion.mp4', 0.18, $event, 'attempt-one',
        );
        $this->service->reportShotResult(
            $shot->id, true, '/r/motion.mp4', 0.18, $event, 'attempt-one',
        );

        $this->assertSame(1, VideoRender::where('shot_id', $shot->id)->count());
        $this->assertEqualsWithDelta(0.18, (float) $this->session->fresh()->cost_actual, 0.0001);
    }

    public function test_same_request_with_a_new_attempt_key_is_a_real_rerender(): void
    {
        $shot = $this->shot('intentional_rerender');
        $event = $this->event('same prompt', null);

        $this->service->reportShotResult(
            $shot->id, true, '/r/v1.jpg', 0.015, $event, 'attempt-one',
        );
        $this->service->reportShotResult(
            $shot->id, true, '/r/v2.jpg', 0.015, $event, 'attempt-two',
        );

        $renders = VideoRender::where('shot_id', $shot->id)->orderBy('attempt_no')->get();
        $this->assertSame(['attempt-one', 'attempt-two'], $renders->pluck('idempotency_key')->all());
        $this->assertSame([1, 2], $renders->pluck('attempt_no')->all());
        $this->assertEqualsWithDelta(0.03, (float) $this->session->fresh()->cost_actual, 0.0001);
    }

    public function test_a_new_clip_points_at_the_new_image_while_the_old_clip_keeps_the_old_one(): void
    {
        // Day la phep thu ma `video_shots.artifact_path` KHONG the bieu dien: mot
        // cot don thi render lai `shell` se lam clip cu tro thanh noi doi.
        $shell = $this->shot('chain_superyacht_shell');
        $this->service->reportShotResult($shell->id, true, '/r/shell_v1.jpg', 0.015,
            $this->event('P_shell_v1', null, ['proves_state' => 'hull_shell']));

        $clipA = $this->shot('motion_a', 'motion');
        $this->service->reportShotResult($clipA->id, true, '/r/a.mp4', 0.18,
            $this->event('P_a', hash('sha256', 'P_shell_v1'), ['render_kind' => 'video']));

        // shell render lai
        $this->service->reportShotResult($shell->id, true, '/r/shell_v2.jpg', 0.015,
            $this->event('P_shell_v2', null, ['proves_state' => 'hull_shell']));

        $clipB = $this->shot('motion_b', 'motion');
        $this->service->reportShotResult($clipB->id, true, '/r/b.mp4', 0.18,
            $this->event('P_b', hash('sha256', 'P_shell_v2'), ['render_kind' => 'video']));

        $this->assertSame('P_shell_v1', $clipA->fresh()->latestRender->sourceRender->sent_prompt,
            'clip cu phai van tro ve tam anh DA de ra no');
        $this->assertSame('P_shell_v2', $clipB->fresh()->latestRender->sourceRender->sent_prompt);
    }

    public function test_a_failed_render_is_recorded_not_discarded(): void
    {
        // Luot hong cung la mot su kien da xay ra: no ton thoi gian, doi khi ton
        // tien, va la thu duy nhat tra loi "da thu cai nay chua".
        $shot = $this->shot('chain_superyacht_keel');
        $this->service->reportShotResult($shot->id, false, null, 0,
            $this->event('P_keel', null, ['error_message' => 'provider tu choi']));

        $render = $shot->fresh()->latestRender;
        $this->assertSame('failed', $render->status);
        $this->assertSame('provider tu choi', $render->error_message);
        $this->assertNull($render->artifact_path);
    }

    public function test_the_write_path_can_never_claim_a_render_was_visually_verified(): void
    {
        // `proof_verified` chi duoc dat boi thu THAT SU soi pixel. Duong ghi luc
        // render khong soi gi ca, nen no khong co tu cach khai true — ke ca khi
        // Python gui len.
        $shot = $this->shot('chain_superyacht_hall');
        $this->service->reportShotResult($shot->id, true, '/r/hall.jpg', 0.015,
            $this->event('P_hall', null, ['proof_verified' => true, 'proof_method' => 'human_qa']));

        $this->assertFalse($shot->fresh()->latestRender->proof_verified);
    }

    public function test_a_report_without_render_metadata_still_works(): void
    {
        // Runner cu (chua gui `render`) van phai chay duoc — trien khai lech phien
        // ban khong duoc lam hong duong bao ket qua.
        $shot = $this->shot('chain_superyacht_hall');
        $this->service->reportShotResult($shot->id, true, '/r/hall.jpg', 0.015);

        $this->assertSame('rendered', $shot->fresh()->status);
        $this->assertSame(0, VideoRender::where('shot_id', $shot->id)->count());
    }

    public function test_session_finishes_only_after_the_last_queued_shot(): void
    {
        $this->session->update(['status' => 'rendering']);
        $first = $this->shot('terminal_first');
        $second = $this->shot('terminal_second');
        $first->update(['status' => 'queued']);
        $second->update(['status' => 'queued']);

        $this->service->reportShotResult($first->id, true, '/r/first.jpg', 0.015);
        $this->assertSame('rendering', $this->session->fresh()->status);

        $this->service->reportShotResult($second->id, true, '/r/second.jpg', 0.015);
        $this->assertSame('done', $this->session->fresh()->status);
    }

    public function test_session_fails_immediately_even_when_later_shots_remain_queued(): void
    {
        $this->session->update(['status' => 'rendering']);
        $first = $this->shot('terminal_success');
        $second = $this->shot('terminal_failure');
        $first->update(['status' => 'queued']);
        $second->update(['status' => 'queued']);

        $this->service->reportShotResult($first->id, false, null, 0);

        $this->assertSame('failed', $this->session->fresh()->status);
        $this->assertSame('queued', $second->fresh()->status);
    }
}
