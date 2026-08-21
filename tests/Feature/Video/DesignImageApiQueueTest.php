<?php

namespace Tests\Feature\Video;

use App\Enums\DesignImageStatus;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Services\Video\DesignImageQueue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class DesignImageApiQueueTest extends TestCase
{
    use DatabaseTransactions;

    private VideoProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        config(['video.api_token' => 'token-loaded-before-runtime']);
        $this->withHeader('X-Video-Token', 'token-loaded-before-runtime');
        $this->project = VideoProject::create(['title' => 'TEST api queue '.uniqid()]);
    }

    /** @return array<string, mixed> */
    private function spec(): array
    {
        return [
            'prompt' => 'CAMERA: front three-quarter. SUBJECT: a hull.',
            'model' => 'gpt-image-2',
            'quality' => 'medium',
            'size' => '1024x1536',
            'variations' => 2,
        ];
    }

    private function image(string $status = 'candidate'): VideoDesignImage
    {
        return VideoDesignImage::create([
            'project_id' => $this->project->id,
            'image_code' => 'test_api_'.uniqid(),
            'image_type' => 'identity_anchor',
            'prompt_spec_json' => $this->spec(),
            'prompt_sha256' => hash('sha256', uniqid('', true)),
            'status' => $status,
            'revision' => 1,
        ]);
    }

    private function queued(): VideoDesignImage
    {
        $image = $this->image();
        app(DesignImageQueue::class)->enqueue($image->id);

        return $image->refresh();
    }

    public function test_the_work_order_carries_every_setting_the_screen_chose(): void
    {
        // Python KHONG duoc tu quyet model, quality hay khung anh — nam gia tri
        // nay den tu chinh form nguoi dung da submit o 3.2.
        $image = $this->queued();

        $order = collect($this->getJson('/api/video-design-images/queued')->assertOk()->json())
            ->firstWhere('id', $image->id);

        $this->assertSame($this->spec()['prompt'], $order['prompt']);
        $this->assertSame('gpt-image-2', $order['model']);
        $this->assertSame('medium', $order['quality']);
        $this->assertSame('1024x1536', $order['size']);
        $this->assertSame(2, $order['variations']);
        $this->assertSame($image->image_code, $order['image_code']);
        $this->assertNotNull($order['queued_at']);
    }

    public function test_the_work_order_prices_the_image_so_python_never_guesses(): void
    {
        // Mot hang so chung cho moi quality la sai 7 lan giua low va high. Gia
        // di theo don hang vi Laravel la noi biet nguoi dung chon gi.
        foreach (['low' => 0.015, 'medium' => 0.041, 'high' => 0.11, 'auto' => 0.11] as $quality => $price) {
            $image = $this->image();
            $image->update(['prompt_spec_json' => ['quality' => $quality] + $this->spec()]);
            app(DesignImageQueue::class)->enqueue($image->id);

            $order = collect($this->getJson('/api/video-design-images/queued')->assertOk()->json())
                ->firstWhere('id', $image->id);

            $this->assertSame($quality, $order['quality']);
            $this->assertSame($price, $order['cost_estimate']);
        }
    }

    public function test_a_setting_outside_the_vocabulary_is_held_back_not_guessed_at(): void
    {
        // Python khong sua duoc `quality => ultra`: no chi fail SAU khi da nhan
        // viec va giu lease. Chan o day, dung doan mot gia roi phat viec hong.
        Log::spy();

        foreach ([['quality' => 'ultra'], ['model' => 'dall-e-9']] as $broken) {
            $image = $this->image();
            $image->update(['prompt_spec_json' => $broken + $this->spec()]);
            app(DesignImageQueue::class)->enqueue($image->id);

            $ids = collect($this->getJson('/api/video-design-images/queued')->assertOk()->json())
                ->pluck('id');

            $this->assertNotContains($image->id, $ids, 'thiet lap la ma van duoc phat viec');
        }

        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_a_price_is_never_guessed_low_when_the_quality_cannot_be_read(): void
    {
        // Lop thu hai: neu mot ngay nao do co duong khac dua spec la vao day.
        $this->assertSame(
            0.11,
            (new \ReflectionMethod(\App\Http\Controllers\VideoDesignImagesController::class, 'costEstimate'))
                ->invoke(app(\App\Http\Controllers\VideoDesignImagesController::class), ['quality' => 'ultra']),
        );
    }

    public function test_a_cell_nobody_queued_is_not_offered_as_work(): void
    {
        $candidate = $this->image();

        $ids = collect($this->getJson('/api/video-design-images/queued')->assertOk()->json())->pluck('id');

        $this->assertNotContains($candidate->id, $ids);
    }

    public function test_a_cell_with_an_incomplete_spec_is_never_handed_to_a_worker(): void
    {
        // Phat mot don hang thieu prompt cho worker la tra tien de lay ve mot tam
        // anh vo nghia. Bo qua, va ghi log de o do khong nam im lang mai.
        Log::spy();
        $broken = $this->image();
        $broken->update(['prompt_spec_json' => ['model' => 'gpt-image-2']]);
        app(DesignImageQueue::class)->enqueue($broken->id);
        $healthy = $this->queued();

        $ids = collect($this->getJson('/api/video-design-images/queued')->assertOk()->json())->pluck('id');

        $this->assertContains($healthy->id, $ids);
        $this->assertNotContains($broken->id, $ids);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'incomplete prompt spec')
                && in_array($broken->image_code, $context['image_codes'], true))
            ->once();
    }

    public function test_laravel_hands_out_the_claim_token_python_never_sends_one(): void
    {
        $image = $this->queued();

        $body = $this->postJson('/api/video-design-images/claim', [
            'worker_id' => 'worker-a',
            'claim_token' => 'ke-gian-tu-chon-token-nay',
        ])->assertOk()->json();

        $this->assertTrue(Str::isUuid($body['claim_token']));
        $this->assertNotSame('ke-gian-tu-chon-token-nay', $body['claim_token']);
        $this->assertSame($body['claim_token'], $image->refresh()->claim_token);
        $this->assertSame(DesignImageStatus::CLAIMED->value, $image->status);
    }

    public function test_a_claim_can_name_the_one_cell_it_wants(): void
    {
        // Nguoi bam nut tren man hinh doi DUNG o cua ho. Khong co `image_id` thi
        // luot chay dong bo se cam luon nhung o dang cho cua du an khac.
        $mine = $this->queued();
        $other = $this->queued();

        $body = $this->postJson('/api/video-design-images/claim', [
            'worker_id' => 'worker-a',
            'image_id' => $mine->id,
        ])->assertOk()->json();

        $this->assertSame([$mine->id], collect($body['images'])->pluck('id')->all());
        $this->assertSame(DesignImageStatus::CLAIMED->value, $mine->refresh()->status);
        $this->assertSame(DesignImageStatus::QUEUED->value, $other->refresh()->status);
    }

    public function test_a_claim_without_a_worker_is_rejected(): void
    {
        $this->postJson('/api/video-design-images/claim', [])->assertStatus(422);
    }

    /** @return array{0: VideoDesignImage, 1: string} */
    private function claimed(): array
    {
        $image = $this->queued();

        $token = $this->postJson('/api/video-design-images/claim', ['worker_id' => 'worker-a'])
            ->assertOk()->json('claim_token');

        return [$image->refresh(), $token];
    }

    public function test_a_heartbeat_moves_the_cell_into_rendering(): void
    {
        [$image, $token] = $this->claimed();

        $this->patchJson("/api/video-design-images/{$image->id}/heartbeat", [
            'worker_id' => 'worker-a',
            'claim_token' => $token,
        ])->assertOk()->assertJson(['status' => DesignImageStatus::RENDERING->value]);

        $this->assertSame(DesignImageStatus::RENDERING->value, $image->refresh()->status);
    }

    public function test_a_heartbeat_from_a_stranger_gets_a_conflict(): void
    {
        [$image] = $this->claimed();

        $this->patchJson("/api/video-design-images/{$image->id}/heartbeat", [
            'worker_id' => 'worker-b',
            'claim_token' => (string) Str::uuid(),
        ])->assertStatus(409)->assertJson(['error' => 'claim_not_owned_or_expired']);
    }

    /** @return array<string, mixed> */
    private function item(array $override = []): array
    {
        return $override + [
            'idempotency_key' => (string) Str::uuid(),
            'artifact_sha256' => hash('sha256', 'anh '.uniqid()),
            'storage_path' => '/renders/design/'.uniqid().'.png',
            'bytes' => 812345,
            'width' => 1024,
            'height' => 1536,
            'cost' => 0.015,
            'render' => [
                'render_kind' => 'image',
                'provider' => 'openai',
                'model' => 'gpt-image-2',
                'sent_prompt' => 'CAMERA: front three-quarter.',
            ],
        ];
    }

    public function test_a_finished_batch_is_recorded_and_the_cell_says_rendered(): void
    {
        [$image, $token] = $this->claimed();

        $this->patchJson("/api/video-design-images/{$image->id}/result", [
            'success' => true,
            'worker_id' => 'worker-a',
            'claim_token' => $token,
            'renders' => [$this->item(), $this->item()],
        ])->assertOk()->assertJson([
            'status' => DesignImageStatus::RENDERED->value,
            'result' => 'recorded',
        ]);

        $this->assertSame(2, VideoRender::where('design_image_id', $image->id)->count());
    }

    public function test_replaying_the_same_callback_answers_two_hundred_so_the_outbox_clears(): void
    {
        // 200 chu khong phai 409: Python phai XOA callback nay khoi outbox, neu
        // khong no quay vong mai.
        [$image, $token] = $this->claimed();
        $items = [$this->item()];

        $this->patchJson("/api/video-design-images/{$image->id}/result", [
            'success' => true, 'worker_id' => 'worker-a', 'claim_token' => $token, 'renders' => $items,
        ])->assertOk();

        $this->patchJson("/api/video-design-images/{$image->id}/result", [
            'success' => true, 'worker_id' => 'worker-a', 'claim_token' => $token, 'renders' => $items,
        ])->assertOk()->assertJson(['result' => 'replayed']);

        $this->assertSame(1, VideoRender::where('design_image_id', $image->id)->count());
    }

    public function test_an_unknown_cell_answers_four_oh_four_so_python_drops_the_callback(): void
    {
        $this->patchJson('/api/video-design-images/'.Str::uuid().'/result', [
            'success' => true,
            'worker_id' => 'worker-a',
            'claim_token' => (string) Str::uuid(),
            'renders' => [$this->item()],
        ])->assertStatus(404)->assertJson(['error' => 'image_not_found']);
    }

    public function test_a_lost_claim_answers_four_oh_nine(): void
    {
        [$image] = $this->claimed();

        $this->patchJson("/api/video-design-images/{$image->id}/result", [
            'success' => true,
            'worker_id' => 'worker-b',
            'claim_token' => (string) Str::uuid(),
            'renders' => [$this->item()],
        ])->assertStatus(409)->assertJson(['error' => 'claim_not_owned_or_expired']);
    }

    public function test_a_malformed_item_answers_four_two_two_and_names_the_item(): void
    {
        [$image, $token] = $this->claimed();

        $this->patchJson("/api/video-design-images/{$image->id}/result", [
            'success' => true,
            'worker_id' => 'worker-a',
            'claim_token' => $token,
            'renders' => [$this->item(), $this->item(['idempotency_key' => ''])],
        ])->assertStatus(422)->assertJson(['error' => 'render_item_1_missing_idempotency_key']);

        $this->assertSame(0, VideoRender::where('design_image_id', $image->id)->count());
    }

    public function test_a_failed_batch_puts_the_reason_where_the_screen_can_read_it(): void
    {
        [$image, $token] = $this->claimed();

        $this->patchJson("/api/video-design-images/{$image->id}/result", [
            'success' => false,
            'render_error' => 'openai tra 500 sau 3 lan thu',
            'worker_id' => 'worker-a',
            'claim_token' => $token,
            'renders' => [],
        ])->assertOk()->assertJson(['status' => DesignImageStatus::FAILED->value]);

        $this->assertSame('openai tra 500 sau 3 lan thu', $image->refresh()->render_error);
    }

    public function test_the_reclaim_endpoint_and_the_command_agree(): void
    {
        [$image] = $this->claimed();
        $image->update(['lease_expires_at' => now()->subMinute()]);

        $this->postJson('/api/video-design-images/reclaim-expired')
            ->assertOk()
            ->assertJsonStructure(['requeued']);

        $this->assertSame(DesignImageStatus::QUEUED->value, $image->refresh()->status);
    }

    public function test_the_scheduled_command_requeues_a_dead_workers_cell(): void
    {
        [$image] = $this->claimed();
        $image->update(['lease_expires_at' => now()->subMinute()]);

        $this->artisan('video:reclaim-expired-design-image-leases')->assertSuccessful();

        $this->assertSame(DesignImageStatus::QUEUED->value, $image->refresh()->status);
    }
}
