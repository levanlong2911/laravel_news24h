<?php

namespace Tests\Feature\Video;

use App\Enums\DesignImageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoCostEntry;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Services\PythonRunner;
use App\Services\Video\DesignImageDirectRenderer;
use App\Services\Video\DesignImageQueue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Duong render dong bo — cung hinh dang voi duong Haiku/Sonnet: claim + lease
 * ngay tren hang du lieu, roi moi goi provider.
 */
class DesignImageDirectRenderTest extends TestCase
{
    use DatabaseTransactions;

    private VideoProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = VideoProject::create(['title' => 'TEST direct '.uniqid()]);
        config(['video.render_mode' => 'direct']);
    }

    private function cell(string $status = 'candidate'): VideoDesignImage
    {
        return VideoDesignImage::create([
            'project_id' => $this->project->id,
            'image_code' => 'direct_'.uniqid(),
            'image_type' => 'identity_anchor',
            'prompt_spec_json' => [
                'prompt' => 'CAMERA: front three-quarter.',
                'model' => 'gpt-image-2',
                'quality' => 'low',
                'size' => '1024x1536',
                'variations' => 1,
            ],
            'prompt_sha256' => hash('sha256', uniqid('', true)),
            'status' => $status,
            'revision' => 1,
        ]);
    }

    /** @return array<string, mixed> */
    private function renderItem(array $override = []): array
    {
        return $override + [
            'idempotency_key' => (string) Str::uuid(),
            'artifact_sha256' => hash('sha256', 'anh '.uniqid()),
            'storage_path' => '/renders/design-images/'.uniqid().'/1.png',
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

    private function workerReturns(array $payload): void
    {
        $runner = Mockery::mock(PythonRunner::class);
        $runner->shouldReceive('runAndWait')->once()
            ->andReturn([true, "chan doan tren stderr\n".json_encode($payload)]);
        $this->instance(PythonRunner::class, $runner);
    }

    // ---- cau dao tong -------------------------------------------------

    public function test_the_breaker_stops_a_python_process_from_ever_starting(): void
    {
        // phpunit.xml dat VIDEO_PYTHON_RUNNER=false. Day la cua DUY NHAT moi duong
        // di qua de sinh tien trinh Python — chot o day thi duong moi them vao
        // khong the di vong qua no.
        $this->assertFalse(config('video.python_runner_enabled'));

        [$ok, $output] = app(PythonRunner::class)->runAndWait('render_design_image_once.py', []);

        $this->assertFalse($ok);
        $this->assertStringContainsString('VIDEO_PYTHON_RUNNER', $output);
    }

    public function test_a_run_with_the_breaker_off_leaves_the_cell_failed_and_says_why(): void
    {
        $cell = $this->cell();

        [$done, $reason] = app(DesignImageDirectRenderer::class)->renderNow($cell->id);

        $this->assertSame('failed', $reason);
        $this->assertSame(DesignImageStatus::FAILED->value, $done->refresh()->status);
        $this->assertStringContainsString('VIDEO_PYTHON_RUNNER', $done->render_error);
        $this->assertSame(0, VideoRender::where('design_image_id', $cell->id)->count());
    }

    // ---- claim / lease ------------------------------------------------

    public function test_the_cell_is_held_before_the_provider_is_ever_called(): void
    {
        $cell = $this->cell();

        [$image, $token, $reason] = app(DesignImageQueue::class)->claimForDirectRender($cell->id);

        $this->assertSame('claimed', $reason);
        $this->assertTrue(Str::isUuid($token));

        $image->refresh();
        $this->assertSame(DesignImageStatus::RENDERING->value, $image->status);
        $this->assertSame('laravel:direct', $image->worker_id);
        $this->assertTrue($image->lease_expires_at->isFuture());
    }

    public function test_a_second_press_during_a_render_never_calls_the_provider_again(): void
    {
        // Bam hai lan cach nhau 5 giay trong luc render: khong co buoc giu o thi
        // ca hai deu thay `candidate` va deu chay — tra tien hai lan.
        $cell = $this->cell();
        app(DesignImageQueue::class)->claimForDirectRender($cell->id);

        $runner = Mockery::mock(PythonRunner::class);
        $runner->shouldNotReceive('runAndWait');
        $this->instance(PythonRunner::class, $runner);

        [$image, $reason] = app(DesignImageDirectRenderer::class)->renderNow($cell->id);

        $this->assertSame('not_enqueueable', $reason);
        $this->assertSame(DesignImageStatus::RENDERING->value, $image->refresh()->status);
    }

    public function test_a_cell_that_already_has_an_image_is_refused_before_the_provider(): void
    {
        $cell = $this->cell(DesignImageStatus::RENDERED->value);

        $runner = Mockery::mock(PythonRunner::class);
        $runner->shouldNotReceive('runAndWait');
        $this->instance(PythonRunner::class, $runner);

        [, $reason] = app(DesignImageDirectRenderer::class)->renderNow($cell->id);

        $this->assertSame('not_enqueueable', $reason);
    }

    // ---- ghi so cai ---------------------------------------------------

    public function test_a_finished_render_lands_in_all_three_books(): void
    {
        $cell = $this->cell();
        $this->workerReturns(['ok' => true, 'error' => null, 'renders' => [$this->renderItem()]]);

        [$done, $reason] = app(DesignImageDirectRenderer::class)->renderNow($cell->id);

        $this->assertSame('rendered', $reason);
        $this->assertSame(DesignImageStatus::RENDERED->value, $done->status);
        $this->assertSame(1, VideoRender::where('design_image_id', $cell->id)->count());
        $this->assertSame(1, VideoArtifact::where('design_image_id', $cell->id)->count());
        $this->assertSame(1, VideoCostEntry::where('entity_id', $cell->id)->count());
        $this->assertNull($done->worker_id, 'Lease phai duoc tra lai sau khi xong');
    }

    public function test_the_python_spec_carries_the_render_operation(): void
    {
        $cell = $this->cell();
        $spec = $cell->prompt_spec_json;
        $spec['operation'] = 'edit';
        $cell->update(['prompt_spec_json' => $spec]);

        $seen = null;
        $runner = Mockery::mock(PythonRunner::class);
        $runner->shouldReceive('runAndWait')->once()
            ->andReturnUsing(function (string $script, array $args) use (&$seen) {
                $this->assertSame('render_design_image_once.py', $script);
                $this->assertSame('--spec', $args[0]);
                $seen = json_decode(file_get_contents($args[1]), true);

                return [true, json_encode(['ok' => false, 'error' => 'stop after inspect', 'renders' => []])];
            });
        $this->instance(PythonRunner::class, $runner);

        app(DesignImageDirectRenderer::class)->renderNow($cell->id);

        $this->assertSame('edit', $seen['operation']);
        $this->assertSame('gpt-image-2', $seen['model']);
        $this->assertSame('1024x1536', $seen['size']);
    }

    public function test_a_batch_that_died_halfway_still_records_the_image_it_paid_for(): void
    {
        $cell = $this->cell();
        $this->workerReturns([
            'ok' => false,
            'error' => 'anh 2/2 hong: openai 500',
            'renders' => [$this->renderItem()],
        ]);

        [$done, $reason] = app(DesignImageDirectRenderer::class)->renderNow($cell->id);

        $this->assertSame('failed', $reason);
        $this->assertSame('anh 2/2 hong: openai 500', $done->render_error);
        $this->assertSame(1, VideoRender::where('design_image_id', $cell->id)->count());
        $this->assertSame(1, VideoCostEntry::where('entity_id', $cell->id)->count());
    }

    public function test_a_result_carrying_the_wrong_token_is_refused(): void
    {
        $cell = $this->cell();
        [, $token] = app(DesignImageQueue::class)->claimForDirectRender($cell->id);

        [$done, $reason] = app(DesignImageQueue::class)->recordDirectResult(
            $cell->id, (string) Str::uuid(), true, null, [$this->renderItem()],
        );

        $this->assertNull($done);
        $this->assertSame('claim_not_owned_or_expired', $reason);
        $this->assertSame(0, VideoRender::where('design_image_id', $cell->id)->count());
        $this->assertNotSame($token, 'khong dung toi, giu de doc ra y dinh');
    }

    public function test_a_result_arriving_after_the_lease_expired_is_refused(): void
    {
        $cell = $this->cell();
        [$image, $token] = app(DesignImageQueue::class)->claimForDirectRender($cell->id);
        $image->update(['lease_expires_at' => now()->subMinute()]);

        [$done, $reason] = app(DesignImageQueue::class)->recordDirectResult(
            $cell->id, $token, true, null, [$this->renderItem()],
        );

        $this->assertNull($done);
        $this->assertSame('claim_not_owned_or_expired', $reason);
    }

    // ---- thu hoi lease theo che do ------------------------------------

    public function test_an_expired_direct_lease_fails_the_cell_instead_of_leaving_it_waiting(): void
    {
        // Che do direct khong co worker nen nao nhat lai. De `queued` thi man hinh
        // trong nhu dang cho ai do, ma khong ai chay.
        $cell = $this->cell();
        [$image] = app(DesignImageQueue::class)->claimForDirectRender($cell->id);
        $image->update(['lease_expires_at' => now()->subMinute()]);

        app(DesignImageQueue::class)->reclaimExpiredLeases();
        $image->refresh();

        $this->assertSame(DesignImageStatus::FAILED->value, $image->status);
        $this->assertStringContainsString('lease', $image->render_error);
        $this->assertContains($image->status, DesignImageStatus::enqueueableValues(),
            'O phai render lai duoc ngay tu man hinh');
    }

    public function test_an_expired_queue_lease_still_goes_back_to_the_queue(): void
    {
        config(['video.render_mode' => 'queue']);
        $cell = $this->cell();
        [$image] = app(DesignImageQueue::class)->claimForDirectRender($cell->id);
        $image->update(['lease_expires_at' => now()->subMinute()]);

        app(DesignImageQueue::class)->reclaimExpiredLeases();

        $this->assertSame(DesignImageStatus::QUEUED->value, $image->refresh()->status);
    }
}
