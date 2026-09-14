<?php

namespace Tests\Feature\Video;

use App\Enums\DesignImageStatus;
use App\Models\Admin;
use App\Models\Article;
use App\Models\VideoCostEntry;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Services\Video\DesignImageDirectRenderer;
use App\Services\Video\DesignImageQueue;
use App\Services\Video\DesignImageStore;
use App\Repositories\Eloquent\VideoProjectRepository;
use App\Services\VideoProjectService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SceneKeyframeClaimTest extends TestCase
{
    use DatabaseTransactions;

    private VideoProject $project;

    private Admin $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();

        [$categoryId] = $this->category();

        $this->owner = $this->admin();
        $this->project = VideoProject::create([
            'title' => 'TEST keyframe claim '.uniqid(),
            'article_id' => $this->article($categoryId),
            'admin_id' => $this->owner->id,
        ]);
    }

    public function test_the_old_render_route_refuses_a_scene_keyframe(): void
    {
        $cell = $this->cell(DesignImageStatus::FAILED);

        $this->actingAs($this->owner)
            ->post(route('video-projects.design-image-enqueue', [$this->project->id, $cell->id]))
            ->assertSessionHas('error');

        $this->assertSame(DesignImageStatus::FAILED->value, $cell->fresh()->status);
        $this->assertNull($cell->fresh()->claim_token);
        Http::assertNothingSent();
    }

    public function test_the_service_names_the_scene_flow_as_the_only_way_in(): void
    {
        $cell = $this->cell(DesignImageStatus::CANDIDATE);

        [$image, $reason] = app(VideoProjectService::class)
            ->renderDesignImage($this->project->id, $cell->id);

        $this->assertNull($image);
        $this->assertSame('scene_keyframe_needs_scene_flow', $reason);
        Http::assertNothingSent();
    }

    public function test_an_anchor_cell_still_reaches_the_old_route(): void
    {
        $anchor = VideoDesignImage::create([
            'project_id' => $this->project->id,
            'image_code' => 'anchor_'.uniqid(),
            'image_type' => DesignImageStore::ANCHOR_TYPE,
            'prompt_spec_json' => ['prompt' => 'unused', 'model' => 'gpt-image-2', 'quality' => 'low'],
            'prompt_sha256' => hash('sha256', uniqid('', true)),
            'status' => DesignImageStatus::RENDERED->value,
            'revision' => 1,
        ]);

        [, $reason] = app(VideoProjectService::class)
            ->renderDesignImage($this->project->id, $anchor->id);

        $this->assertSame('not_enqueueable', $reason, 'the old route must still own the anchor path');
    }

    /** @return iterable<string, array{0: ?string, 1: string, 2: ?float}> */
    public static function pricingProvider(): iterable
    {
        yield 'khong khai bao' => [null, 'estimated', 0.015];
        yield 'chua dinh gia' => ['unpriced', 'unpriced', null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pricingProvider')]
    public function test_the_renderer_carries_the_pricing_state_it_was_given(
        ?string $declared,
        string $expected,
        ?float $estimate,
    ): void {
        $cell = $this->cell(DesignImageStatus::CANDIDATE);
        $stored = ['prompt' => 'p', 'quality' => 'low', 'size' => '1152x2048'];

        if ($declared !== null) {
            $stored['pricing'] = $declared;
        }

        $method = new \ReflectionMethod(DesignImageDirectRenderer::class, 'spec');
        $spec = $method->invoke(app(DesignImageDirectRenderer::class), $cell, $stored, 'token-1');

        $this->assertSame($expected, $spec['pricing']);
        $this->assertSame($estimate, $spec['cost_estimate']);
    }

    public function test_a_failed_cell_is_refused_when_retry_was_not_confirmed(): void
    {
        $cell = $this->cell(DesignImageStatus::FAILED);

        [$image, $token, $reason] = $this->queue()->claimForDirectRender(
            $cell->id, 90, [DesignImageStatus::CANDIDATE->value],
        );

        $this->assertNull($token);
        $this->assertSame('retry_not_confirmed', $reason);
        $this->assertSame(DesignImageStatus::FAILED->value, $image->fresh()->status);
        $this->assertNull($image->fresh()->claim_token);
    }

    public function test_a_failed_cell_is_claimed_once_retry_is_confirmed(): void
    {
        $cell = $this->cell(DesignImageStatus::FAILED);

        [, $token, $reason] = $this->queue()->claimForDirectRender($cell->id, 90, [
            DesignImageStatus::CANDIDATE->value,
            DesignImageStatus::FAILED->value,
        ]);

        $this->assertNotNull($token);
        $this->assertSame('claimed', $reason);
    }

    public function test_a_fresh_cell_is_claimed_by_the_narrow_list(): void
    {
        $cell = $this->cell(DesignImageStatus::CANDIDATE);

        [, $token, $reason] = $this->queue()->claimForDirectRender(
            $cell->id, 90, [DesignImageStatus::CANDIDATE->value],
        );

        $this->assertNotNull($token);
        $this->assertSame('claimed', $reason);
    }

    public function test_a_rendering_cell_is_not_enqueueable_at_all(): void
    {
        $cell = $this->cell(DesignImageStatus::RENDERING);

        [, $token, $reason] = $this->queue()->claimForDirectRender(
            $cell->id, 90, [DesignImageStatus::CANDIDATE->value],
        );

        $this->assertNull($token);
        $this->assertSame('not_enqueueable', $reason);
    }

    public function test_the_accept_list_can_only_narrow_never_widen(): void
    {
        $cell = $this->cell(DesignImageStatus::RENDERING);
        $writes = [];

        DB::listen(function ($query) use (&$writes): void {
            if (stripos($query->sql, 'update') === 0) {
                $writes[] = $query->sql;
            }
        });

        [$image, $token, $reason] = $this->queue()->claimForDirectRender(
            $cell->id, 90, [DesignImageStatus::RENDERING->value],
        );

        $this->assertNull($image);
        $this->assertNull($token);
        $this->assertSame('no_acceptable_status', $reason);
        $this->assertSame([], $writes, 'no UPDATE statement may be observed for a list that narrows to nothing');
    }

    /** @return iterable<string, array{0: DesignImageStatus}> */
    public static function legacyClaimProvider(): iterable
    {
        yield 'candidate' => [DesignImageStatus::CANDIDATE];
        yield 'failed' => [DesignImageStatus::FAILED];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('legacyClaimProvider')]
    public function test_omitting_the_list_keeps_the_contract_the_anchor_path_relies_on(
        DesignImageStatus $status,
    ): void {
        $cell = $this->cell($status);

        [, $token, $reason] = $this->queue()->claimForDirectRender($cell->id, 90);

        $this->assertNotNull($token);
        $this->assertSame('claimed', $reason);
    }

    public function test_the_renderer_forwards_the_accept_list_before_touching_the_provider(): void
    {
        $cell = $this->cell(DesignImageStatus::FAILED);

        [, $reason] = app(DesignImageDirectRenderer::class)->renderNow(
            $cell->id, [DesignImageStatus::CANDIDATE->value],
        );

        $this->assertSame('retry_not_confirmed', $reason);
        Http::assertNothingSent();
    }

    public function test_a_cell_that_turned_failed_after_the_outside_check_is_still_refused(): void
    {
        $cell = $this->cell(DesignImageStatus::CANDIDATE);

        DB::table('video_design_images')
            ->where('id', $cell->id)
            ->update(['status' => DesignImageStatus::FAILED->value]);

        [, $reason] = $this->dispatch($cell, false);

        $this->assertSame('retry_not_confirmed', $reason);
        Http::assertNothingSent();
    }

    /** @return iterable<string, array{0: DesignImageStatus, 1: ?int, 2: string}> */
    public static function dispatchProvider(): iterable
    {
        yield 'rendered' => [DesignImageStatus::RENDERED, null, 'already_exists'];
        yield 'approved' => [DesignImageStatus::APPROVED, null, 'already_exists'];
        yield 'queued khong lease' => [DesignImageStatus::QUEUED, null, 'render_in_flight'];
        yield 'rendering thieu lease' => [DesignImageStatus::RENDERING, null, 'lease_missing'];
        yield 'claimed het lease' => [DesignImageStatus::CLAIMED, -60, 'lease_expired'];
        yield 'rendering con lease' => [DesignImageStatus::RENDERING, 600, 'render_in_flight'];
        yield 'failed chua xac nhan' => [DesignImageStatus::FAILED, null, 'previous_render_failed'];
        yield 'superseded' => [DesignImageStatus::SUPERSEDED, null, 'not_enqueueable'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dispatchProvider')]
    public function test_every_state_reports_its_own_reason_without_spending(
        DesignImageStatus $status,
        ?int $leaseOffset,
        string $expected,
    ): void {
        $cell = $this->cell($status, $leaseOffset);

        [, $reason] = $this->dispatch($cell, false);

        $this->assertSame($expected, $reason);
        $this->assertSame($status->value, $cell->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_an_approved_cell_is_refused_even_when_retry_is_confirmed(): void
    {
        $cell = $this->cell(DesignImageStatus::APPROVED);

        [, $reason] = $this->dispatch($cell, true);

        $this->assertSame('already_exists', $reason);
        Http::assertNothingSent();
    }

    /** @return iterable<string, array{0: ?float, 1: ?string, 2: float, 3: string}> */
    public static function ledgerPricingProvider(): iterable
    {
        yield 'chua dinh gia' => [null, 'unpriced', 0.0, 'unpriced'];
        yield 'co uoc luong' => [null, 'estimated', 0.0, 'estimated'];
        yield 'mien phi' => [0.0, 'free', 0.0, 'free'];
        yield 'khong khai bao' => [null, null, 0.0, 'estimated'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ledgerPricingProvider')]
    public function test_the_ledger_keeps_the_pricing_state_apart_from_the_amount(
        ?float $cost,
        ?string $pricing,
        float $expectedUsd,
        string $expectedPricing,
    ): void {
        $cell = $this->cell(DesignImageStatus::CANDIDATE);

        [, $token] = $this->queue()->claimForDirectRender($cell->id, 90);

        [$done, $reason] = $this->queue()->recordDirectResult(
            $cell->id, (string) $token, true, null, [$this->renderItem($cost, $pricing)],
        );

        $this->assertNotNull($done, $reason);

        $entry = VideoCostEntry::query()
            ->where('entity_id', $cell->id)
            ->sole();

        $this->assertSame($expectedUsd, (float) $entry->cost_usd);
        $this->assertSame($expectedPricing, $entry->metadata_json['pricing']);
    }

    public function test_recording_the_same_result_twice_never_doubles_the_ledger(): void
    {
        $cell = $this->cell(DesignImageStatus::CANDIDATE);

        [, $token] = $this->queue()->claimForDirectRender($cell->id, 90);
        $item = $this->renderItem(null, 'unpriced');

        $this->queue()->recordDirectResult($cell->id, (string) $token, true, null, [$item]);
        $before = VideoCostEntry::query()->where('entity_id', $cell->id)->sole()->toArray();

        $this->queue()->recordDirectResult($cell->id, (string) $token, true, null, [$item]);
        $after = VideoCostEntry::query()->where('entity_id', $cell->id)->sole()->toArray();

        $this->assertSame(1, VideoCostEntry::query()->where('entity_id', $cell->id)->count());
        $this->assertSame($before, $after, 'the second report must not rewrite the row it found');
    }

    public function test_the_listing_says_a_total_is_incomplete_when_a_render_has_no_price(): void
    {
        $cell = $this->cell(DesignImageStatus::CANDIDATE);

        [, $token] = $this->queue()->claimForDirectRender($cell->id, 90);
        $this->queue()->recordDirectResult(
            $cell->id, (string) $token, true, null, [$this->renderItem(null, 'unpriced')],
        );

        $row = collect(app(VideoProjectRepository::class)->listAllWithCounts($this->owner))
            ->firstWhere('id', $this->project->id);

        $this->assertSame(0.0, (float) $row->cost_actual_sum);
        $this->assertSame(1, (int) $row->unpriced_cost_count);
    }

    public function test_the_index_page_warns_that_a_total_hides_an_unpriced_render(): void
    {
        $this->recordOneRender(null, 'unpriced');

        $this->actingAs($this->owner)
            ->get(route('video-projects.index'))
            ->assertOk()
            ->assertSee('have no price yet');
    }

    public function test_the_index_page_stays_quiet_when_every_render_has_a_price(): void
    {
        $this->recordOneRender(null, 'estimated');

        $this->actingAs($this->owner)
            ->get(route('video-projects.index'))
            ->assertOk()
            ->assertDontSee('have no price yet');
    }

    private function recordOneRender(?float $cost, string $pricing): void
    {
        $cell = $this->cell(DesignImageStatus::CANDIDATE);

        [, $token] = $this->queue()->claimForDirectRender($cell->id, 90);

        [$done, $reason] = $this->queue()->recordDirectResult(
            $cell->id, (string) $token, true, null, [$this->renderItem($cost, $pricing)],
        );

        $this->assertNotNull($done, $reason);
    }

    public function test_a_priced_render_leaves_the_listing_total_complete(): void
    {
        $cell = $this->cell(DesignImageStatus::CANDIDATE);

        [, $token] = $this->queue()->claimForDirectRender($cell->id, 90);
        $this->queue()->recordDirectResult(
            $cell->id, (string) $token, true, null,
            [$this->renderItem(null, 'estimated') + ['estimated_cost_usd' => 0.015]],
        );

        $row = collect(app(VideoProjectRepository::class)->listAllWithCounts($this->owner))
            ->firstWhere('id', $this->project->id);

        $this->assertSame(0.0, (float) $row->cost_actual_sum, 'uoc tinh khong phai tien da xac nhan');
        $this->assertSame(0.015, (float) $row->estimated_cost_sum);
        $this->assertSame(0, (int) $row->unpriced_cost_count);
    }

    /** @return array<string, mixed> */
    private function renderItem(?float $cost, ?string $pricing): array
    {
        $bytes = 'keyframe-bytes';
        $path = 'keyframes/'.uniqid().'.png';
        Storage::disk('local')->put($path, $bytes);

        $item = [
            'idempotency_key' => 'claim:0',
            'storage_disk' => 'local',
            'storage_path' => $path,
            'artifact_sha256' => hash('sha256', $bytes),
            'mime_type' => 'image/png',
            'width' => 1152,
            'height' => 2048,
            'bytes' => strlen($bytes),
            'cost' => $cost,
            'provider_request_id' => null,
            'provider_usage' => null,
            'render' => [
                'provider' => 'openai',
                'model' => 'gpt-image-2',
                'render_kind' => 'scene_keyframe',
                'sent_prompt' => 'unused in this test',
                'source_kind' => 'image',
                'artifact_dir' => 'keyframes',
                'provider_ms' => 10,
                'request_sha256' => hash('sha256', 'req'),
            ],
        ];

        if ($pricing !== null) {
            $item['pricing'] = $pricing;
        }

        return $item;
    }

    private function queue(): DesignImageQueue
    {
        return app(DesignImageQueue::class);
    }

    /** @return array{0: ?VideoDesignImage, 1: string} */
    private function dispatch(VideoDesignImage $cell, bool $retry): array
    {
        $method = new \ReflectionMethod(VideoProjectService::class, 'dispatchSceneCandidate');

        return $method->invoke(app(VideoProjectService::class), $cell, $retry);
    }

    private function cell(DesignImageStatus $status, ?int $leaseOffset = null): VideoDesignImage
    {
        return VideoDesignImage::create([
            'project_id' => $this->project->id,
            'image_code' => 'keyframe_'.uniqid(),
            'image_type' => DesignImageStore::SCENE_KEYFRAME_TYPE,
            'prompt_spec_json' => ['prompt' => 'unused in this test', 'model' => 'gpt-image-2', 'quality' => 'low'],
            'prompt_sha256' => hash('sha256', uniqid('', true)),
            'status' => $status->value,
            'revision' => 1,
            'lease_expires_at' => $leaseOffset === null ? null : now()->addSeconds($leaseOffset),
        ]);
    }

    /** @return array{0: string, 1: string} */
    private function category(): array
    {
        $id = (string) Str::uuid();
        $slug = 'test-keyframe-'.uniqid();

        DB::table('categories')->insert([
            'id' => $id,
            'name' => 'TEST keyframe category '.uniqid(),
            'slug' => $slug,
        ]);

        return [$id, $slug];
    }

    private function admin(string $role = 'member'): Admin
    {
        $roleId = (string) Str::uuid();

        DB::table('roles')->insert(['id' => $roleId, 'name' => $role]);

        return Admin::create([
            'name' => 'TEST keyframe admin '.uniqid(),
            'email' => 'test_keyframe_'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'role_id' => $roleId,
        ]);
    }

    private function article(string $categoryId): string
    {
        $keywordId = (string) Str::uuid();

        DB::table('keywords')->insert([
            'id' => $keywordId,
            'name' => 'TEST keyframe keyword '.uniqid(),
            'category_id' => $categoryId,
        ]);

        return (string) Article::create([
            'keyword_id' => $keywordId,
            'category_id' => $categoryId,
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('', true)),
            'source_title' => 'TEST keyframe source',
            'title' => 'TEST keyframe article '.uniqid(),
            'slug' => 'test-keyframe-'.uniqid(),
            'content' => 'A yard begins a new steel motor yacht.',
            'status' => 'pending',
        ])->id;
    }
}
