<?php

namespace Tests\Feature;

use App\Enums\PlanningStageName;
use App\Models\AiPhase;
use App\Models\Admin;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Video\ModelSheetQaService;
use App\Services\Video\ModelSheetService;
use App\Services\Video\PlanningStageStore;
use App\Video\YachtDesign\YachtDesignCheck;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Support\YachtDesignFixture;
use Tests\TestCase;

final class ModelSheetWithoutProxyTest extends TestCase
{
    use DatabaseTransactions;

    private string $project;
    private ModelSheetService $sheet;
    private VideoPlanningStage $set;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $category = (string) Str::uuid();
        $keyword = (string) Str::uuid();
        $article = (string) Str::uuid();
        DB::table('categories')->insert(['id' => $category, 'name' => 'Yacht', 'slug' => 'yacht', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('keywords')->insert(['id' => $keyword, 'name' => 'Yacht', 'category_id' => $category, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('articles')->insert([
            'id' => $article, 'keyword_id' => $keyword, 'category_id' => $category, 'source_url' => 'https://example.test/'.$article,
            'source_url_hash' => md5($article), 'source_title' => 'Yacht', 'title' => 'Yacht', 'slug' => $article, 'content' => 'Yacht', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->project = VideoProject::create(['title' => 'Model sheet', 'article_id' => $article, 'project_type' => 'article_video'])->id;
        foreach (['phase-3', config('video.superyacht.model_sheet.qa.phase_code')] as $code) {
            $phase = AiPhase::create([
                'category_id' => $category, 'workflow_code' => config('video.superyacht.workflow_code'), 'phase_code' => $code,
                'revision' => 1, 'kind' => 'phase', 'name' => $code, 'sort_order' => 1, 'prompt_content' => 'Test prompt',
                'content_sha256' => hash('sha256', 'Test prompt'), 'status' => AiPhase::PUBLISHED, 'is_current' => true,
            ]);
            if ($code === 'phase-3') $designPhase = $phase;
        }
        $design = YachtDesignFixture::envelope($this->project);
        $design['data']['canonical']['coordinate_system'] = 'x from bow to stern as fraction of LOA; y in metres, positive port from centreline; z in metres above design waterline.';
        // Historic designs may retain geometry unsupported by the removed renderer.
        $design['data']['canonical']['proxy_geometry'] = ['version' => 'proxy-geometry-v1', 'parts' => [
            ['id' => 'gorge', 'op' => 'cut', 'params' => ['floor_width' => ['value' => 11, 'unit' => 'm']]],
        ]];
        $design['data']['canonical']['hash'] = \App\Video\Superyacht\Support\CanonicalJson::hash($design['data']['canonical'], ['hash']);
        $stage = VideoPlanningStage::create([
            'project_id' => $this->project, 'stage' => PlanningStageName::YACHT_DESIGN->value, 'planning_revision' => 1,
            'status' => 'succeeded', 'input_json' => ['phase_id' => $designPhase->id], 'input_hash' => hash('sha256', 'design'),
            'raw_response' => json_encode($design), 'output_json' => ['design' => $design, 'check' => ['verdict' => YachtDesignCheck::PASS]],
        ]);
        $this->sheet = app(ModelSheetService::class);
        // Isolate the already-passed design input from DNA/judge orchestration.
        (new ReflectionProperty($this->sheet, 'designPanels'))->setValue($this->sheet, [$this->project => [
            'current' => true, 'dna_stale' => false, 'stage_id' => $stage->id, 'check' => ['verdict' => YachtDesignCheck::PASS], 'name' => 'Arclight',
        ]]);
        [$set, $error] = (new ReflectionMethod($this->sheet, 'activeSet'))->invoke($this->sheet, $this->project);
        self::assertNull($error);
        self::assertNotNull($set);
        self::assertSame([], $set->output_json['blocking']);
        $this->set = $set;
    }

    public function test_render_still_requires_a_passing_qa(): void
    {
        [$image, $reason] = $this->sheet->render($this->project, 'Tester', $this->choice());
        self::assertNull($image);
        self::assertStringContainsString('QA', $reason);
        Http::assertNothingSent();
    }

    public function test_unsupported_historic_geometry_does_not_block_render_and_duplicate_attempt_is_reused(): void
    {
        $qa = $this->passingQa();
        $canvas = imagecreatetruecolor(3840, 2160);
        ob_start();
        imagepng($canvas);
        $png = ob_get_clean();
        imagedestroy($canvas);
        Http::fake(['*' => Http::response(['data' => [['b64_json' => base64_encode($png)]], 'usage' => ['total_tokens' => 1]])]);
        $choice = $this->choice($qa);
        [$image, $reason] = $this->sheet->render($this->project, 'Tester', $choice);
        self::assertSame('rendered', $reason, $image?->render_error ?? $reason);
        self::assertNotNull($image);
        [$again, $againReason] = $this->sheet->render($this->project, 'Tester', $choice);
        self::assertSame('already_exists', $againReason);
        self::assertSame($image->id, $again->id);
        Http::assertSentCount(1);
        $role = (string) Str::uuid();
        $adminId = (string) Str::uuid();
        DB::table('roles')->insert(['id' => $role, 'name' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('admins')->insert(['id' => $adminId, 'name' => 'Tester', 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
        Gate::before(static fn () => true);
        Queue::fake();
        $this->app->instance(ModelSheetService::class, $this->sheet);
        $anchor = route('video-projects.anchor', $this->project);
        $this->actingAs(Admin::findOrFail($adminId))->from($anchor)
            ->post(route('video-projects.model-sheet-approve', $this->project), ['artifact_id' => $image->artifacts()->firstOrFail()->id])
            ->assertRedirect($anchor)->assertSessionHasNoErrors()->assertSessionHas('success');
        self::assertSame(1, VideoPlanningStage::where('project_id', $this->project)->where('stage', PlanningStageName::MODEL_SHEET_REVIEW->value)->count());
        Queue::assertNothingPushed();
        self::assertSame(0, VideoPlanningStage::where('project_id', $this->project)->where('stage', 'like', 'proxy%')->count());
    }

    public function test_tampered_prompt_is_rejected_before_render(): void
    {
        $choice = $this->choice($this->passingQa());
        $choice['prompt_sha256'] = str_repeat('0', 64);
        [$image, $reason] = $this->sheet->render($this->project, 'Tester', $choice);
        self::assertNull($image);
        self::assertStringContainsString('Prompt', $reason);
        Http::assertNothingSent();
    }

    public function test_only_the_unrelated_image_proxy_route_remains(): void
    {
        $routes = app('router')->getRoutes();
        self::assertNotNull($routes->getByName('image.proxy'));
        foreach ($routes as $route) self::assertStringNotContainsString('/proxy', $route->uri());
    }

    private function passingQa(): VideoPlanningStage
    {
        $store = app(PlanningStageStore::class);
        [$stage, $token] = $store->claimProjectStage($this->project, PlanningStageName::MODEL_SHEET_PROMPT, app(ModelSheetQaService::class)->input($this->set));
        $prompt = 'Render this approved yacht in eight consistent views.';
        self::assertTrue($store->finishSucceeded($stage->id, $token, '{}', ['ready' => true, 'prompt' => $prompt, 'prompt_sha256' => hash('sha256', $prompt)]));
        return $stage->fresh();
    }

    private function choice(?VideoPlanningStage $qa = null): array
    {
        return ['model' => 'gpt-image-2.5-flare', 'quality' => 'high', 'attempt' => (string) Str::uuid(),
            'qa_stage_id' => $qa?->id ?? (string) Str::uuid(), 'prompt_sha256' => $qa?->output_json['prompt_sha256'] ?? ''];
    }
}
