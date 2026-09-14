<?php

namespace Tests\Feature\Video;

use App\Enums\DesignImageStatus;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Services\Video\DesignImageQueue;
use App\Services\Video\DesignImageStore;
use App\Video\Media\ImagePriceResolver;
use App\Video\Media\MediaModelRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RenderPriceBackfillTest extends TestCase
{
    use DatabaseTransactions;

    private VideoProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $this->project = VideoProject::create(['title' => 'TEST backfill '.uniqid()]);
    }

    public function test_a_worker_never_receives_a_cell_that_has_no_price_yet(): void
    {
        $image = $this->cell(['prompt' => 'x', 'model' => 'gpt-image-2', 'quality' => 'low']);
        $queue = app(DesignImageQueue::class);

        $queue->enqueue($image->id);

        $claimed = $queue->claimForRender('worker-1', $token = (string) Str::uuid(), 10, now()->addSeconds(600));

        $this->assertCount(1, $claimed);

        $spec = $image->refresh()->prompt_spec_json;

        $this->assertSame(0.015, $spec['unit_cost_usd'], 'gia phai co TRUOC khi worker nhin thay spec');
        $this->assertSame('openai-image-inherited-2026-09-14', $spec['pricing_version']);
        $this->assertNotEmpty($spec['pricing_backfilled_at']);
        $this->assertSame($token, $image->claim_token);
    }

    public function test_a_cell_nobody_can_price_leaves_the_batch_and_says_why(): void
    {
        $image = $this->cell(['prompt' => 'x', 'model' => 'gpt-image-9000', 'quality' => 'low']);
        $queue = app(DesignImageQueue::class);

        $queue->enqueue($image->id);

        $claimed = $queue->claimForRender('worker-1', (string) Str::uuid(), 10, now()->addSeconds(600));

        $this->assertSame([], $claimed, 'o khong dinh gia duoc thi khong duoc phat ra ngoai');

        $image->refresh();

        $this->assertSame(DesignImageStatus::FAILED->value, $image->status);
        $this->assertSame('pricing_unavailable', $image->render_error);
        $this->assertNull($image->claim_token);
    }

    public function test_a_legacy_cell_without_a_quality_is_priced_the_way_it_will_be_rendered(): void
    {
        // Renderer mac dinh `high` khi spec thieu quality, nen gia cung phai la `high`.
        $image = $this->cell(['prompt' => 'x', 'model' => 'gpt-image-2']);

        [, $token, $reason] = app(DesignImageQueue::class)->claimForDirectRender($image->id, 90);

        $this->assertNotNull($token, $reason);
        $this->assertSame(0.11, $image->refresh()->prompt_spec_json['unit_cost_usd']);
    }

    public function test_a_pricing_state_nobody_defined_stops_the_claim(): void
    {
        $image = $this->cell([
            'prompt' => 'x', 'model' => 'gpt-image-2', 'quality' => 'low', 'pricing' => 'bogus',
        ]);

        [, $token, $reason] = app(DesignImageQueue::class)->claimForDirectRender($image->id, 90);

        $this->assertNull($token);
        $this->assertSame('pricing_state_unknown', $reason);
        $this->assertSame(DesignImageStatus::FAILED->value, $image->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_cell_that_already_froze_its_price_is_never_repriced(): void
    {
        $image = $this->cell([
            'prompt' => 'x',
            'model' => 'gpt-image-2',
            'quality' => 'low',
            'pricing' => 'estimated',
            'unit_cost_usd' => 0.009,
            'pricing_version' => 'openai-image-old-2026-01-01',
        ]);

        [, $token, $reason] = app(DesignImageQueue::class)->claimForDirectRender($image->id, 90);

        $this->assertNotNull($token, $reason);

        $spec = $image->refresh()->prompt_spec_json;

        $this->assertSame(0.009, $spec['unit_cost_usd'], 'gia cu la gia cua o do, khong duoc viet lai');
        $this->assertSame('openai-image-old-2026-01-01', $spec['pricing_version']);
        $this->assertArrayNotHasKey('pricing_backfilled_at', $spec);
    }

    public function test_a_registry_entry_for_another_model_prices_nothing(): void
    {
        $registry = app(MediaModelRegistry::class);
        $lite = $registry->find('environment_plate', 'gemini:gemini-3.1-flash-lite-image');
        $resolver = new ImagePriceResolver;

        $spec = ['provider' => 'gemini', 'model' => 'gemini-3.1-flash-image', 'image_size' => '1K'];

        $this->assertNull(
            $resolver->forSpec($spec, $lite),
            'entry cua model khac khong duoc dung de dinh gia model nay',
        );
        $this->assertSame(
            0.067,
            $resolver->forSpec($spec, $registry->find('environment_plate', 'gemini:gemini-3.1-flash-image'))['usd'],
        );
    }

    public function test_a_lost_claim_never_touches_the_cell_that_now_belongs_to_someone_else(): void
    {
        $image = $this->cell(['prompt' => 'x', 'model' => 'gpt-image-2', 'quality' => 'low']);
        $stolen = false;

        DB::beforeExecuting(function (string $query) use ($image, &$stolen): void {
            if ($stolen || ! str_contains($query, 'prompt_spec_json')) {
                return;
            }

            $stolen = true;

            DB::table('video_design_images')->where('id', $image->id)->update([
                'worker_id' => 'worker-2',
                'claim_token' => 'token-2',
                'status' => DesignImageStatus::RENDERING->value,
            ]);
        });

        [, $token, $reason] = app(DesignImageQueue::class)->claimForDirectRender($image->id, 90);

        $this->assertTrue($stolen, 'phai cuop duoc claim dung luc backfill ghi gia');
        $this->assertNull($token);
        $this->assertSame('claim_lost_before_pricing', $reason);

        $image->refresh();

        $this->assertSame('worker-2', $image->worker_id, 'o dang thuoc worker khac, khong duoc dong vao');
        $this->assertSame('token-2', $image->claim_token);
        $this->assertSame(DesignImageStatus::RENDERING->value, $image->status);
        $this->assertNull($image->render_error);
    }

    public function test_a_lease_that_ran_out_before_the_release_leaves_the_cell_alone(): void
    {
        $image = $this->cell(['prompt' => 'x', 'model' => 'gpt-image-9000', 'quality' => 'low']);
        $expired = false;

        DB::beforeExecuting(function (string $query) use ($image, &$expired): void {
            if ($expired || ! preg_match('/^update .*where .*claim_token/is', $query)) {
                return;
            }

            $expired = true;

            DB::table('video_design_images')->where('id', $image->id)
                ->update(['lease_expires_at' => now()->subSecond()]);
        });

        [, $token, $reason] = app(DesignImageQueue::class)->claimForDirectRender($image->id, 90);

        $this->assertTrue($expired, 'lease phai het han dung truoc luc release chay');
        $this->assertNull($token);
        $this->assertSame('claim_lost_before_pricing', $reason);

        $image->refresh();

        $this->assertSame(
            DesignImageStatus::RENDERING->value,
            $image->status,
            'het lease la het quyen danh that bai',
        );
        $this->assertNull($image->render_error);
    }

    /** @param array<string, mixed> $spec */
    private function cell(array $spec): VideoDesignImage
    {
        return VideoDesignImage::create([
            'project_id' => $this->project->id,
            'image_code' => 'backfill_'.uniqid(),
            'image_type' => DesignImageStore::ANCHOR_TYPE,
            'prompt_spec_json' => $spec,
            'prompt_sha256' => hash('sha256', uniqid('', true)),
            'status' => DesignImageStatus::CANDIDATE->value,
            'revision' => 1,
        ]);
    }
}
