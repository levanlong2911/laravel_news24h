<?php

namespace Tests\Feature;

use App\Enums\PlanningStageName;
use App\Models\AiJsonSchema;
use App\Models\AiPhase;
use App\Models\AiPhaseSchema;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Video\ModelSheetQaService;
use App\Services\Video\ModelSheetService;
use App\Services\Video\PlanningStageStore;
use App\Services\Video\YachtReferencePackService;
use App\Video\ReferencePack\ReferencePackValidator;
use App\Video\Superyacht\Support\CanonicalJson;
use App\Video\YachtDesign\YachtDesignCheck;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Support\YachtDesignFixture;
use Tests\TestCase;

/** Phase 3R tren DB rieng: nguon ghim, dung lai anh da duyet, chi tao phan thieu, duyet co kiem phien ban. */
final class ReferencePackServiceTest extends TestCase
{
    use DatabaseTransactions;

    private string $project;
    private string $category;
    private string $adminId;
    private string $reviewId;
    private YachtReferencePackService $packs;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->category = (string) Str::uuid();
        $keyword = (string) Str::uuid();
        $article = (string) Str::uuid();
        DB::table('categories')->insert(['id' => $this->category, 'name' => 'Yacht', 'slug' => 'yacht', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('keywords')->insert(['id' => $keyword, 'name' => 'Yacht', 'category_id' => $this->category, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('articles')->insert([
            'id' => $article, 'keyword_id' => $keyword, 'category_id' => $this->category, 'source_url' => 'https://example.test/'.$article,
            'source_url_hash' => md5($article), 'source_title' => 'Yacht', 'title' => 'Yacht', 'slug' => $article, 'content' => 'Yacht', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $role = (string) Str::uuid();
        $this->adminId = (string) Str::uuid();
        DB::table('roles')->insert(['id' => $role, 'name' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('admins')->insert(['id' => $this->adminId, 'name' => 'Tester', 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        $this->project = VideoProject::create(['title' => 'Pack', 'article_id' => $article, 'project_type' => 'article_video', 'admin_id' => $this->adminId])->id;
        foreach (['phase-3', config('video.superyacht.model_sheet.qa.phase_code'), YachtReferencePackService::PHASE_CODE] as $code) {
            $phase = $this->phase($code, 2);
            if ($code === 'phase-3') $designPhase = $phase;
        }
        $design = YachtDesignFixture::envelope($this->project);
        $design['data']['canonical']['coordinate_system'] = 'x from bow to stern as fraction of LOA; y in metres, positive port from centreline; z in metres above design waterline.';
        $design['data']['canonical']['hash'] = CanonicalJson::hash($design['data']['canonical'], ['hash']);
        $stage = VideoPlanningStage::create([
            'project_id' => $this->project, 'stage' => PlanningStageName::YACHT_DESIGN->value, 'planning_revision' => 1,
            'status' => 'succeeded', 'input_json' => ['phase_id' => $designPhase->id, 'phase_revision' => 2], 'input_hash' => hash('sha256', 'design'),
            'raw_response' => json_encode($design), 'output_json' => ['design' => $design, 'check' => ['verdict' => YachtDesignCheck::PASS]],
        ]);
        $sheet = app(ModelSheetService::class);
        (new ReflectionProperty($sheet, 'designPanels'))->setValue($sheet, [$this->project => [
            'current' => true, 'dna_stale' => false, 'stage_id' => $stage->id, 'check' => ['verdict' => YachtDesignCheck::PASS], 'name' => 'Arclight',
        ]]);
        [$set] = (new ReflectionMethod($sheet, 'activeSet'))->invoke($sheet, $this->project);
        $store = app(PlanningStageStore::class);
        [$qa, $token] = $store->claimProjectStage($this->project, PlanningStageName::MODEL_SHEET_PROMPT, app(ModelSheetQaService::class)->input($set));
        $prompt = 'Render this approved yacht in eight consistent views.';
        $store->finishSucceeded($qa->id, $token, '{}', ['ready' => true, 'prompt' => $prompt, 'prompt_sha256' => hash('sha256', $prompt)]);
        $this->fakeImages(3840, 2160);
        [$image, $reason] = $sheet->render($this->project, 'Tester', ['model' => 'gpt-image-2.5-flare', 'quality' => 'high', 'attempt' => (string) Str::uuid(), 'qa_stage_id' => $qa->id, 'prompt_sha256' => hash('sha256', $prompt)]);
        self::assertSame('rendered', $reason, $image?->render_error ?? $reason);
        [$done, $why] = $sheet->approve($this->project, (string) $image->artifacts()->firstOrFail()->id, $this->adminId);
        self::assertTrue($done, $why);
        $this->reviewId = (string) VideoPlanningStage::where('project_id', $this->project)->where('stage', PlanningStageName::MODEL_SHEET_REVIEW->value)->value('id');
        $this->packs = app(YachtReferencePackService::class);
    }

    public function test_opening_the_panel_writes_nothing(): void
    {
        $stages = VideoPlanningStage::where('project_id', $this->project)->count();
        $panel = $this->packs->panel($this->project);

        self::assertSame($this->reviewId, $panel['review_stage_id']);
        self::assertNull($panel['plan']);
        self::assertSame($stages, VideoPlanningStage::where('project_id', $this->project)->count());
    }

    public function test_plan_pins_the_review_reuses_the_eight_views_and_builds_the_color_sheet_without_calling_a_model(): void
    {
        Http::fake();
        [$plan, $reason] = $this->packs->plan($this->project, $this->reviewId);
        self::assertSame('planned', $reason);
        self::assertSame($this->reviewId, $plan->output_json['sources']['model_sheet']['review_stage_id']);

        [$again, $second] = $this->packs->plan($this->project, $this->reviewId);
        self::assertSame('existing', $second);
        self::assertSame($plan->id, $again->id);
        Http::assertNothingSent();

        $panel = $this->packs->panel($this->project);
        self::assertSame(['pass'], array_values(array_unique(array_map(static fn ($i) => $i['status']['rule'], $panel['groups']['views']))));
        self::assertSame('pass', $panel['groups']['colors'][0]['status']['rule']);
        self::assertSame(1, VideoArtifact::where('project_id', $this->project)->where('role', 'reference_pack_code')->count());
        self::assertSame('incomplete', $panel['readiness']['exterior']['status']);
    }

    public function test_only_missing_items_are_rendered_and_a_repeat_buys_nothing(): void
    {
        [$plan] = $this->packs->plan($this->project, $this->reviewId);
        $items = $this->renderable();
        $this->fakeImages(1536, 1024);
        $attempt = (string) Str::uuid();

        [$results] = $this->packs->render($this->project, $plan->id, $items, ['model' => 'gpt-image-2.5-flare', 'quality' => 'medium', 'attempt' => $attempt], 'Tester');
        self::assertSame(array_fill(0, count($items), 'rendered'), array_column($results, 'reason'));
        Http::assertSentCount(count($items));

        $this->packs->render($this->project, $plan->id, $items, ['model' => 'gpt-image-2.5-flare', 'quality' => 'medium', 'attempt' => (string) Str::uuid()], 'Tester');
        Http::assertSentCount(count($items));
    }

    public function test_a_rejected_item_is_the_only_one_rendered_again(): void
    {
        [$plan] = $this->packs->plan($this->project, $this->reviewId);
        $this->fakeImages(1536, 1024);
        $this->packs->render($this->project, $plan->id, $this->renderable(), ['model' => 'gpt-image-2.5-flare', 'quality' => 'medium', 'attempt' => (string) Str::uuid()], 'Tester');
        $bow = VideoDesignImage::where('project_id', $this->project)->where('image_type', 'reference_pack_item')->get()->first(fn ($i) => $i->prompt_spec_json['item_id'] === 'detail.bow');

        [$rejected] = $this->packs->reject($this->project, $plan->id, $bow->id);
        self::assertTrue($rejected);
        [$results] = $this->packs->render($this->project, $plan->id, $this->renderable(), ['model' => 'gpt-image-2.5-flare', 'quality' => 'medium', 'attempt' => (string) Str::uuid()], 'Tester');
        self::assertSame(['detail.bow'], array_column(array_filter($results, static fn ($r) => $r['reason'] === 'rendered'), 'item_id'));
    }

    public function test_approve_requires_exterior_ready_and_refuses_a_stale_selection_version(): void
    {
        [$plan] = $this->packs->plan($this->project, $this->reviewId);
        [$early] = $this->packs->check($this->project, $plan->id);
        [$refused, $why] = $this->packs->approve($this->project, $early->id, 0, 'user:'.$this->adminId);
        self::assertFalse($refused);
        self::assertStringContainsString('detail.bow', $why);

        $this->fakeImages(1536, 1024);
        $this->packs->render($this->project, $plan->id, $this->renderable(), ['model' => 'gpt-image-2.5-flare', 'quality' => 'medium', 'attempt' => (string) Str::uuid()], 'Tester');
        [$pack] = $this->packs->check($this->project, $plan->id);
        self::assertSame('ready', $pack->output_json['ref_pack']['readiness']['exterior']['status']);
        self::assertSame('unchecked', $pack->output_json['ref_pack']['validation']['vision']);

        [$ok, $result] = $this->packs->approve($this->project, $pack->id, 0, 'user:'.$this->adminId);
        self::assertTrue($ok);
        self::assertSame('approved_unlocked', $result, 'Fixture chưa có canonical khoá và constraint registry.');
        [$stale] = $this->packs->approve($this->project, $pack->id, 0, 'user:'.$this->adminId);
        self::assertFalse($stale);
        self::assertSame(1, VideoProject::findOrFail($this->project)->metadata_json['reference_pack_selection']['version']);
    }

    public function test_a_newer_prompt_revision_does_not_change_an_existing_pack(): void
    {
        [$plan] = $this->packs->plan($this->project, $this->reviewId);
        AiPhase::where('category_id', $this->category)->where('phase_code', YachtReferencePackService::PHASE_CODE)->update(['is_current' => false]);
        $this->phase(YachtReferencePackService::PHASE_CODE, 3);

        $panel = $this->packs->panel($this->project);
        self::assertSame($plan->id, $panel['plan']['id']);
        self::assertSame(2, $panel['plan']['sources']['phase']['revision']);
    }

    public function test_a_prompt_revision_without_the_reference_pack_contract_cannot_plan(): void
    {
        AiPhase::where('category_id', $this->category)->where('phase_code', YachtReferencePackService::PHASE_CODE)->update(['is_current' => false]);
        $this->phase(YachtReferencePackService::PHASE_CODE, 3, false);

        [$plan, $reason] = $this->packs->plan($this->project, $this->reviewId);
        self::assertNull($plan);
        self::assertStringContainsString('chưa liên kết schema ReferencePack', $reason);
    }

    public function test_a_plan_made_before_the_contract_is_view_only(): void
    {
        [$plan] = $this->packs->plan($this->project, $this->reviewId);
        $out = $plan->output_json;
        unset($out['sources']['phase']['schema_id']);
        DB::table('video_planning_stages')->where('id', $plan->id)->update(['output_json' => json_encode($out)]);
        Http::fake();

        [, $render] = $this->packs->render($this->project, $plan->id, ['detail.bow'], ['model' => 'gpt-image-2.5-flare', 'quality' => 'medium', 'attempt' => (string) Str::uuid()], 'Tester');
        [$pack, $check] = $this->packs->check($this->project, $plan->id);
        self::assertStringContainsString('chỉ xem', $render);
        self::assertNull($pack);
        self::assertStringContainsString('chỉ xem', $check);
        self::assertNotNull($this->packs->panel($this->project)['plan']['contract_problem']);
        Http::assertNothingSent();
    }

    public function test_a_checked_pack_stays_current_on_the_panel_and_is_usable_only_after_approval(): void
    {
        $pack = $this->readyPack();
        $panel = $this->packs->panel($this->project);
        self::assertTrue($panel['pack']['current'], 'Mở trang ngay sau khi kiểm không được báo nội dung đã đổi.');

        $request = ['kind' => 'exterior', 'finished' => true, 'views' => ['MS2'], 'details' => ['detail.bow'], 'max_images' => 4];
        self::assertFalse($this->packs->selectFor($this->project, $request)['ok'], 'Chưa duyệt thì chưa dùng được.');

        [$ok, $why] = $this->packs->approve($this->project, $pack->id, 0, 'user:'.$this->adminId);
        self::assertTrue($ok, $why);
        $use = $this->packs->selectFor($this->project, $request);
        self::assertTrue($use['ok'], (string) $use['error']);
        self::assertSame($pack->id, $use['pack_stage_id']);
        self::assertFalse($this->packs->selectFor($this->project, $request, true)['ok'], 'Phase cần nguồn đã khoá không nhận pack chưa có DESIGN LOCK.');

        $reopened = $this->packs->panel($this->project, (string) $pack->input_json['plan_id']);
        self::assertTrue($reopened['selected']['valid']);
        self::assertTrue($reopened['plan']['is_selected']);
        self::assertTrue($reopened['pack']['selected']);
    }

    /** @return array<string, array<string, mixed>> ten ca => thao tac lam hong pack da chon */
    public static function brokenAfterSelection(): array
    {
        return [
            'mất file chi tiết' => ['missing_file'],
            'thay file chi tiết (artifact và file khớp nhau)' => ['replaced_file'],
            'từ chối ảnh sau khi chọn' => ['rejected'],
            'mất file master' => ['missing_master'],
            'kế hoạch không còn' => ['missing_plan'],
            'manifest bị sửa sau khi duyệt' => ['tampered_manifest'],
        ];
    }

    /** @dataProvider brokenAfterSelection */
    public function test_a_selected_pack_that_breaks_after_approval_stops_being_used(string $break): void
    {
        $pack = $this->readyPack();
        [$ok, $why] = $this->packs->approve($this->project, $pack->id, 0, 'user:'.$this->adminId);
        self::assertTrue($ok, $why);
        $request = ['kind' => 'exterior', 'finished' => true, 'views' => ['MS2'], 'details' => ['detail.bow'], 'max_images' => 4];
        self::assertTrue($this->packs->selectFor($this->project, $request)['ok']);

        $bow = collect($pack->output_json['ref_pack']['details'])->firstWhere('id', 'detail.bow');
        $artifact = VideoArtifact::findOrFail($bow['artifact_id']);
        $master = VideoArtifact::findOrFail($pack->output_json['ref_pack']['sources']['model_sheet']['master_artifact_id']);

        match ($break) {
            'missing_file' => Storage::disk($artifact->storage_disk)->delete($artifact->storage_path),
            'replaced_file' => (function () use ($artifact): void {
                $bytes = 'replaced-'.Str::uuid();
                Storage::disk($artifact->storage_disk)->put($artifact->storage_path, $bytes);
                DB::table('video_artifacts')->where('id', $artifact->id)->update(['sha256' => hash('sha256', $bytes)]);
            })(),
            'rejected' => self::assertTrue($this->packs->reject($this->project, (string) $pack->input_json['plan_id'], (string) $artifact->design_image_id)[0]),
            'missing_master' => Storage::disk($master->storage_disk)->delete($master->storage_path),
            'missing_plan' => DB::table('video_planning_stages')->where('id', $pack->input_json['plan_id'])->update(['status' => 'failed']),
            'tampered_manifest' => (function () use ($pack): void {
                $out = $pack->output_json;
                $out['ref_pack']['details'][0]['subject'] = 'edited after approval';
                DB::table('video_planning_stages')->where('id', $pack->id)->update(['output_json' => json_encode($out)]);
            })(),
        };

        $use = $this->packs->selectFor($this->project, $request);
        self::assertFalse($use['ok'], $break);
        self::assertStringContainsString('không còn hợp lệ', (string) $use['error']);
        $selected = $this->packs->panel($this->project)['selected'];
        self::assertFalse($selected['valid'], 'Panel (kiểm nông) cũng báo bộ đang dùng không còn hợp lệ.');
        self::assertNotSame([], $selected['problems']);
    }

    public function test_a_missing_master_fails_the_check_even_when_the_eight_crops_are_intact(): void
    {
        [$plan] = $this->packs->plan($this->project, $this->reviewId);
        $master = VideoArtifact::findOrFail($plan->output_json['sources']['model_sheet']['master_artifact_id']);
        Storage::disk($master->storage_disk)->put($master->storage_path, 'corrupted');

        [$pack] = $this->packs->check($this->project, $plan->id);
        $views = $pack->output_json['ref_pack']['views'];
        self::assertSame(['fail'], array_values(array_unique(array_column(array_column($views, 'status'), 'rule'))));
        self::assertSame(['pending'], array_values(array_unique(array_column(array_column($views, 'status'), 'acceptance'))));
        self::assertNull($this->packs->plan($this->project, $this->reviewId)[0], 'Lập kế hoạch từ master hỏng cũng bị chặn.');
    }

    public function test_a_construction_source_from_the_caller_is_not_trusted(): void
    {
        $pack = $this->readyPack();
        $this->packs->approve($this->project, $pack->id, 0, 'user:'.$this->adminId);

        $use = $this->packs->selectFor($this->project, ['kind' => 'exterior', 'finished' => false, 'views' => ['MS1'], 'max_images' => 4,
            'state_source' => ['artifact_id' => 'does-not-exist', 'sha256' => 'not-a-sha']]);
        self::assertFalse($use['ok']);
        self::assertStringContainsString('chưa hỗ trợ', $use['error']);
        self::assertNull($use['edit_input']);
    }

    public function test_a_replaced_crop_no_longer_inherits_the_model_sheet_approval(): void
    {
        [$plan] = $this->packs->plan($this->project, $this->reviewId);
        $crop = collect($plan->output_json['sources']['model_sheet']['crops'])->firstWhere('view_id', 'MS3');
        $artifact = VideoArtifact::findOrFail($crop['artifact_id']);
        $bytes = 'replaced-'.Str::uuid();
        Storage::disk($artifact->storage_disk)->put($artifact->storage_path, $bytes);
        DB::table('video_artifacts')->where('id', $artifact->id)->update(['sha256' => hash('sha256', $bytes)]);

        [$pack] = $this->packs->check($this->project, $plan->id);
        $ms3 = collect($pack->output_json['ref_pack']['views'])->firstWhere('id', 'view.MS3');
        self::assertSame('fail', $ms3['status']['rule'], 'File và artifact khớp nhau nhưng khác snapshot.');
        self::assertSame('pending', $ms3['status']['acceptance']);
        self::assertContains('view.MS3', $pack->output_json['ref_pack']['readiness']['exterior']['blocking']);
    }

    public function test_an_old_lock_whose_sources_no_longer_match_is_not_a_lock(): void
    {
        [$plan] = $this->packs->plan($this->project, $this->reviewId);
        $sources = $plan->output_json['sources'];
        $component = ['artifact_id' => 'gone', 'artifact_type' => 'canonical', 'revision' => 1, 'hash' => 'sha256:'.str_repeat('f', 64)];
        $bundle = CanonicalJson::bundleHash([$component['hash']]);
        VideoPlanningStage::create([
            'project_id' => $this->project, 'stage' => PlanningStageName::DESIGN_LOCK->value, 'planning_revision' => 1, 'status' => 'succeeded',
            'input_json' => ['execution' => 'design-lock-v1', 'design_stage_id' => $sources['design_stage_id'], 'model_sheet_review_stage_id' => $this->reviewId, 'bundle_hash' => $bundle],
            'input_hash' => hash('sha256', 'lock'),
            'output_json' => ['lock_record' => ['id' => (string) Str::uuid(), 'project_id' => $this->project, 'lock_type' => 'design', 'components' => [$component],
                'bundle_hash' => $bundle, 'approver' => 'user:x', 'approved_at' => now()->toIso8601String()]],
        ]);

        $status = $this->packs->panel($this->project)['lock'];
        self::assertFalse($status['locked']);
        self::assertStringContainsString('không còn hiệu lực', $status['missing'][0]);
    }

    public function test_a_render_request_arriving_while_the_item_is_in_flight_sends_nothing(): void
    {
        [$plan] = $this->packs->plan($this->project, $this->reviewId);
        $canvas = imagecreatetruecolor(1536, 1024);
        ob_start();
        imagepng($canvas);
        $png = (string) ob_get_clean();
        $sent = 0;
        $inner = null;
        Http::fake(function () use (&$sent, &$inner, $plan, $png) {
            $sent++;
            // Request thu hai (attempt khac) den dung luc request dau dang gui provider.
            $inner ??= $this->packs->render($this->project, $plan->id, ['detail.bow'], ['model' => 'gpt-image-2.5-flare', 'quality' => 'medium', 'attempt' => (string) Str::uuid()], 'Tester');

            return Http::response(['data' => [['b64_json' => base64_encode($png)]], 'usage' => ['total_tokens' => 1]]);
        });

        [$results] = $this->packs->render($this->project, $plan->id, ['detail.bow'], ['model' => 'gpt-image-2.5-flare', 'quality' => 'medium', 'attempt' => (string) Str::uuid()], 'Tester');
        self::assertSame('rendered', $results[0]['reason']);
        self::assertSame(1, $sent);
        self::assertStringContainsString('không render lại', $inner[0][0]['reason']);
        self::assertSame(1, VideoDesignImage::where('project_id', $this->project)->where('image_type', 'reference_pack_item')->count());
    }

    public function test_a_review_from_another_project_cannot_be_a_source(): void
    {
        $source = (array) DB::table('articles')->where('id', VideoProject::findOrFail($this->project)->article_id)->first();
        $article = (string) Str::uuid();
        DB::table('articles')->insert(['id' => $article, 'source_url' => 'https://example.test/'.$article, 'source_url_hash' => md5($article), 'slug' => $article] + $source);
        $other = VideoProject::create(['title' => 'Other', 'article_id' => $article, 'project_type' => 'article_video'])->id;

        [$plan, $reason] = $this->packs->plan($other, $this->reviewId);
        self::assertNull($plan);
        self::assertStringContainsString('không thuộc dự án', $reason);
    }

    /** @return list<string> */
    private function renderable(): array
    {
        return array_column(array_filter($this->packs->panel($this->project)['groups']['details'], static fn ($i) => $i['renderable']), 'id');
    }

    /** Revision phase-3r mac dinh lien ket schema ReferencePack trung hop dong (cong tuong thich). */
    private function phase(string $code, int $revision, bool $linkContract = true): AiPhase
    {
        $content = "Test prompt {$code} rev {$revision}";
        $link = $linkContract && $code === YachtReferencePackService::PHASE_CODE;
        $phase = AiPhase::create([
            'category_id' => $this->category, 'workflow_code' => config('video.superyacht.workflow_code'), 'phase_code' => $code,
            'revision' => $revision, 'kind' => 'phase', 'name' => $code, 'sort_order' => 1, 'prompt_content' => $content,
            'content_sha256' => hash('sha256', $content), 'status' => $link ? AiPhase::DRAFT : AiPhase::PUBLISHED, 'is_current' => ! $link,
        ]);

        if ($link) {
            $file = (string) file_get_contents(resource_path(ReferencePackValidator::SCHEMA_FILE));
            $schema = AiJsonSchema::query()->firstOrCreate(['namespace' => 'superyacht', 'schema_key' => 'ReferencePack', 'version' => '1.0.0-test'], [
                'name' => 'ReferencePack', 'schema_content' => $file, 'schema_sha256' => hash('sha256', $file), 'status' => AiJsonSchema::PUBLISHED, 'published_at' => now(),
            ]);
            AiPhaseSchema::create(['phase_id' => $phase->id, 'schema_id' => $schema->id, 'role' => 'output', 'slot_key' => 'ref_pack', 'data_pointer' => '', 'validation_mode' => 'whole', 'is_required' => true]);
            DB::table('ai_phases')->where('id', $phase->id)->update(['status' => AiPhase::PUBLISHED, 'is_current' => true]);
            $phase->refresh();
        }

        return $phase;
    }

    /** Render du cac muc con thieu roi kiem bo. */
    private function readyPack(): VideoPlanningStage
    {
        [$plan] = $this->packs->plan($this->project, $this->reviewId);
        $this->fakeImages(1536, 1024);
        $this->packs->render($this->project, $plan->id, $this->renderable(), ['model' => 'gpt-image-2.5-flare', 'quality' => 'medium', 'attempt' => (string) Str::uuid()], 'Tester');
        [$pack] = $this->packs->check($this->project, $plan->id);

        return $pack;
    }

    private function fakeImages(int $width, int $height): void
    {
        $canvas = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($canvas);
        $png = (string) ob_get_clean();
        Http::fake(['*' => Http::response(['data' => [['b64_json' => base64_encode($png)]], 'usage' => ['total_tokens' => 1]])]);
    }
}
