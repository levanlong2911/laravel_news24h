<?php

namespace Tests\Feature\Video;

use App\Enums\DesignImageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoCostEntry;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Services\Video\DesignImageQueue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class DesignImageQueueTest extends TestCase
{
    use DatabaseTransactions;

    private DesignImageQueue $queue;

    private VideoProject $project;

    private string $workerId = 'test-worker-1';

    private string $claimToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queue = new DesignImageQueue;
        $this->project = VideoProject::create(['title' => 'TEST queue '.uniqid()]);
        $this->claimToken = (string) Str::uuid();
    }

    private function image(string $status = 'candidate'): VideoDesignImage
    {
        return VideoDesignImage::create([
            'project_id' => $this->project->id,
            'image_code' => 'test_queue_'.uniqid(),
            'image_type' => 'identity_anchor',
            'status' => $status,
            'revision' => 1,
        ]);
    }

    private function claimed(): VideoDesignImage
    {
        $image = $this->image();
        $this->queue->enqueue($image->id);
        $this->queue->claimForRender($this->workerId, $this->claimToken, 10, now()->addSeconds(600));

        return $image->refresh();
    }

    /** @return array<string, mixed> */
    private function item(array $override = []): array
    {
        return $override + [
            'idempotency_key' => (string) Str::uuid(),
            'artifact_sha256' => hash('sha256', 'anh '.uniqid()),
            'storage_path' => '/renders/design/'.uniqid().'.png',
            'storage_disk' => 'public',
            'mime_type' => 'image/png',
            'bytes' => 812345,
            'width' => 1024,
            'height' => 1536,
            'cost' => 0.015,
            'render' => [
                'render_kind' => 'image',
                'provider' => 'openai',
                'model' => 'gpt-image-2',
                'sent_prompt' => 'CAMERA: front three-quarter. SUBJECT: a hull.',
                'request_sha256' => hash('sha256', 'req'),
                'artifact_dir' => 'renders/design/abc',
                'provider_ms' => 9412,
            ],
        ];
    }

    // ---- hang doi ------------------------------------------------------

    public function test_a_candidate_can_enter_the_queue(): void
    {
        [$image, $reason] = $this->queue->enqueue($this->image()->id);

        $this->assertSame('queued', $reason);
        $this->assertSame(DesignImageStatus::QUEUED->value, $image->status);
        $this->assertNotNull($image->queued_at);
    }

    public function test_a_failed_cell_may_try_again(): void
    {
        $image = $this->image(DesignImageStatus::FAILED->value);
        $image->update(['render_error' => 'provider 500']);

        [$again, $reason] = $this->queue->enqueue($image->id);

        $this->assertSame('queued', $reason);
        $this->assertNull($again->render_error, 'Loi cu phai bi xoa khi vao lai hang doi');
    }

    public function test_a_cell_that_already_has_an_image_is_refused(): void
    {
        foreach ([DesignImageStatus::RENDERED, DesignImageStatus::APPROVED] as $status) {
            [$image, $reason] = $this->queue->enqueue($this->image($status->value)->id);

            $this->assertSame('not_enqueueable', $reason);
            $this->assertSame($status->value, $image->status);
        }
    }

    public function test_enqueueing_twice_does_not_move_the_cell_back(): void
    {
        $image = $this->image();
        $this->queue->enqueue($image->id);
        $first = $image->refresh()->queued_at;

        [$second, $reason] = $this->queue->enqueue($image->id);

        $this->assertSame('already_queued', $reason);
        $this->assertEquals($first, $second->queued_at);
    }

    public function test_an_unknown_cell_is_named_not_written(): void
    {
        [$image, $reason] = $this->queue->enqueue((string) Str::uuid());

        $this->assertNull($image);
        $this->assertSame('image_not_found', $reason);
    }

    // ---- claim ---------------------------------------------------------

    public function test_a_second_worker_cannot_take_a_cell_already_claimed(): void
    {
        $image = $this->image();
        $this->queue->enqueue($image->id);

        $first = $this->queue->claimForRender('worker-a', (string) Str::uuid(), 10, now()->addSeconds(600));
        $second = $this->queue->claimForRender('worker-b', (string) Str::uuid(), 10, now()->addSeconds(600));

        $this->assertCount(1, collect($first)->where('id', $image->id));
        $this->assertCount(0, collect($second)->where('id', $image->id));
    }

    public function test_the_first_heartbeat_moves_a_claimed_cell_into_rendering(): void
    {
        $image = $this->claimed();

        $beaten = $this->queue->heartbeat($image->id, $this->workerId, $this->claimToken, now()->addSeconds(600));

        $this->assertTrue($beaten);
        $this->assertSame(DesignImageStatus::RENDERING->value, $image->refresh()->status);
    }

    public function test_a_heartbeat_from_a_stranger_changes_nothing(): void
    {
        $image = $this->claimed();

        $beaten = $this->queue->heartbeat($image->id, 'someone-else', (string) Str::uuid(), now()->addSeconds(600));

        $this->assertFalse($beaten);
        $this->assertSame(DesignImageStatus::CLAIMED->value, $image->refresh()->status);
    }

    public function test_an_expired_lease_returns_the_cell_to_the_queue(): void
    {
        $image = $this->claimed();
        $image->update(['lease_expires_at' => now()->subMinute()]);

        $this->assertGreaterThanOrEqual(1, $this->queue->reclaimExpiredLeases());
        $image->refresh();

        $this->assertSame(DesignImageStatus::QUEUED->value, $image->status);
        $this->assertNull($image->worker_id);
        $this->assertNull($image->claim_token);
    }

    // ---- hop dong callback ---------------------------------------------

    public function test_the_callback_contract_a_python_worker_must_send(): void
    {
        // BAN HOP DONG. 3.3.4 doc test nay de biet phai gui gi, khong doan shape.
        $image = $this->claimed();

        [$done, $reason] = $this->queue->reportResult($image->id, true, null, [
            $this->item(),
        ], $this->workerId, $this->claimToken);

        $this->assertSame('recorded', $reason);
        $this->assertSame(DesignImageStatus::RENDERED->value, $done->status);
        $this->assertNull($done->render_error);
        $this->assertNull($done->worker_id);
        $this->assertNull($done->claim_token);
    }

    public function test_a_report_without_a_claim_is_refused(): void
    {
        $image = $this->claimed();

        [$done, $reason] = $this->queue->reportResult(
            $image->id, true, null, [$this->item()], 'worker-b', (string) Str::uuid(),
        );

        $this->assertNull($done);
        $this->assertSame('claim_not_owned_or_expired', $reason);
        $this->assertSame(0, VideoRender::where('design_image_id', $image->id)->count());
    }

    public function test_an_expired_claim_can_no_longer_report(): void
    {
        $image = $this->claimed();
        $image->update(['lease_expires_at' => now()->subMinute()]);

        [$done, $reason] = $this->queue->reportResult(
            $image->id, true, null, [$this->item()], $this->workerId, $this->claimToken,
        );

        $this->assertNull($done);
        $this->assertSame('claim_not_owned_or_expired', $reason);
    }

    public function test_success_without_a_single_render_item_is_meaningless(): void
    {
        $image = $this->claimed();

        [$done, $reason] = $this->queue->reportResult($image->id, true, null, [], $this->workerId, $this->claimToken);

        $this->assertNull($done);
        $this->assertSame('result_success_without_renders', $reason);
        $this->assertSame(DesignImageStatus::CLAIMED->value, $image->refresh()->status);
    }

    public function test_an_item_without_an_idempotency_key_is_refused_before_any_write(): void
    {
        $image = $this->claimed();

        [$done, $reason] = $this->queue->reportResult($image->id, true, null, [
            $this->item(['idempotency_key' => '']),
        ], $this->workerId, $this->claimToken);

        $this->assertNull($done);
        $this->assertSame('render_item_0_missing_idempotency_key', $reason);
        $this->assertSame(0, VideoCostEntry::where('entity_id', $image->id)->count());
    }

    public function test_an_item_that_spent_money_must_name_the_model_and_the_prompt(): void
    {
        $image = $this->claimed();

        foreach (['provider', 'model', 'render_kind', 'sent_prompt'] as $key) {
            $item = $this->item();
            $item['render'][$key] = '';

            [$done, $reason] = $this->queue->reportResult(
                $image->id, true, null, [$item], $this->workerId, $this->claimToken,
            );

            $this->assertNull($done);
            $this->assertSame("render_item_0_missing_render_{$key}", $reason);
        }
    }

    public function test_a_file_needs_both_its_path_and_its_checksum(): void
    {
        $image = $this->claimed();

        [, $missingSha] = $this->queue->reportResult($image->id, true, null, [
            $this->item(['artifact_sha256' => '']),
        ], $this->workerId, $this->claimToken);

        [, $badSha] = $this->queue->reportResult($image->id, true, null, [
            $this->item(['artifact_sha256' => 'not-a-hash']),
        ], $this->workerId, $this->claimToken);

        $this->assertSame('render_item_0_needs_both_storage_path_and_artifact_sha256', $missingSha);
        $this->assertSame('render_item_0_artifact_sha256_is_not_a_sha256', $badSha);
    }

    // ---- tien ----------------------------------------------------------

    public function test_two_variations_leave_two_rows_in_every_book(): void
    {
        $image = $this->claimed();

        $this->queue->reportResult($image->id, true, null, [
            $this->item(), $this->item(),
        ], $this->workerId, $this->claimToken);

        $this->assertSame(2, VideoRender::where('design_image_id', $image->id)->count());
        $this->assertSame(2, VideoArtifact::where('design_image_id', $image->id)->count());
        $this->assertSame(2, VideoCostEntry::where('entity_id', $image->id)->count());
        $this->assertEquals(
            [1, 2],
            VideoRender::where('design_image_id', $image->id)->orderBy('attempt_no')->pluck('attempt_no')->all(),
        );
    }

    public function test_replaying_the_same_callback_never_charges_twice(): void
    {
        $image = $this->claimed();
        $items = [$this->item(), $this->item()];

        $this->queue->reportResult($image->id, true, null, $items, $this->workerId, $this->claimToken);

        // Outbox phat lai NGUYEN TRANG: cung payload, cung idempotency_key, va
        // claim da bi tra lai o luot truoc. Day la tinh huong that, khong phai gia
        // dinh — mat mang giua chung la du de xay ra.
        [, $reason] = $this->queue->reportResult($image->id, true, null, $items, $this->workerId, $this->claimToken);

        $this->assertSame('replayed', $reason);
        $this->assertSame(2, VideoRender::where('design_image_id', $image->id)->count());
        $this->assertSame(2, VideoArtifact::where('design_image_id', $image->id)->count());
        $this->assertEquals(
            0.03,
            round((float) VideoCostEntry::where('entity_id', $image->id)->sum('cost_usd'), 6),
        );
    }

    public function test_a_batch_that_died_halfway_still_records_what_it_spent(): void
    {
        // "All-or-fail" ap cho TRANG THAI O, khong ap cho so cai: tien cua tam
        // anh dau da roi khoi tai khoan roi.
        $image = $this->claimed();

        [$done, $reason] = $this->queue->reportResult($image->id, false, 'provider tra 500 o anh thu hai', [
            $this->item(),
            $this->item(['artifact_sha256' => '', 'storage_path' => '', 'cost' => 0, 'error' => 'HTTP 500']),
        ], $this->workerId, $this->claimToken);

        $this->assertSame('recorded', $reason);
        $this->assertSame(DesignImageStatus::FAILED->value, $done->status);
        $this->assertSame('provider tra 500 o anh thu hai', $done->render_error);
        $this->assertSame(2, VideoRender::where('design_image_id', $image->id)->count());
        $this->assertSame(1, VideoArtifact::where('design_image_id', $image->id)->count(),
            'Chi tam anh co file that moi thanh artifact');
        $this->assertSame(2, VideoCostEntry::where('entity_id', $image->id)->count());
    }

    public function test_a_failure_always_leaves_a_reason_on_the_cell(): void
    {
        $image = $this->claimed();

        [$done] = $this->queue->reportResult($image->id, false, null, [], $this->workerId, $this->claimToken);

        $this->assertSame(DesignImageStatus::FAILED->value, $done->status);
        $this->assertNotEmpty($done->render_error, 'Khong bao gio de o hong ma khong noi vi sao');
    }

    public function test_an_artifact_of_a_project_level_anchor_belongs_to_no_session(): void
    {
        $image = $this->claimed();
        $item = $this->item();

        $this->queue->reportResult($image->id, true, null, [$item], $this->workerId, $this->claimToken);

        $artifact = VideoArtifact::where('design_image_id', $image->id)->firstOrFail();

        $this->assertSame($this->project->id, $artifact->project_id);
        $this->assertNull($artifact->shot_id);
        $this->assertNull($artifact->session_id);
        $this->assertNotNull($artifact->render_id);
        $this->assertSame('image', $artifact->artifact_type);
        $this->assertSame('candidate', $artifact->role);
        $this->assertSame($item['artifact_sha256'], $artifact->sha256);
        $this->assertSame(1024, $artifact->width);
        $this->assertSame(1536, $artifact->height);
    }

    public function test_the_cost_book_names_this_entity_type_and_no_other(): void
    {
        // Bang da doi ten tu video_design_cells sang video_design_images. Khoa
        // ten `design_image` lai de sau nay khong lan hai ten trong bao cao tien.
        $image = $this->claimed();

        $this->queue->reportResult($image->id, true, null, [$this->item()], $this->workerId, $this->claimToken);

        $entry = VideoCostEntry::where('entity_id', $image->id)->firstOrFail();

        $this->assertSame('design_image', $entry->entity_type);
        $this->assertNull($entry->session_id);
        $this->assertSame($this->project->id, $entry->project_id);
        $this->assertSame('render', $entry->stage);
    }

    public function test_the_ledger_hashes_the_prompt_it_actually_stored(): void
    {
        $image = $this->claimed();
        $item = $this->item();
        $item['render']['prompt_sha256'] = hash('sha256', 'mot chuoi hoan toan khac');

        $this->queue->reportResult($image->id, true, null, [$item], $this->workerId, $this->claimToken);

        $render = VideoRender::where('design_image_id', $image->id)->firstOrFail();

        $this->assertSame(hash('sha256', $render->sent_prompt), $render->prompt_sha256);
    }
}
