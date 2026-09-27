<?php

namespace Tests\Feature\Video;

use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\ImageVariations;
use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Category;
use App\Models\Role;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Video\CharacterAnchorPromptService;
use App\Services\Video\DesignImageDirectRenderer;
use App\Services\Video\ProductionSelectionService;
use App\Services\Video\ScreenplayApprovalService;
use App\Services\Video\ScreenplaySubjectService;
use App\Services\VideoProjectService;
use App\Video\Media\OpenAiImagePricing;
use App\Video\Prompt\GeometryPromptAuthor;
use App\Video\Prompt\TextCompletionClient;
use App\Video\Prompt\TextCompletionResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CharacterAnchorPromptTest extends TestCase
{
    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'keywords', 'categories', 'articles', 'video_projects', 'video_planning_stages', 'video_design_images',
    ];

    private VideoProject $project;

    private bool $inTransaction = false;

    /** @var list<array{system: string, user: string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::REQUIRED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $this->markTestSkipped("The isolated database is missing {$table}.");
            }
        }

        DB::beginTransaction();
        $this->inTransaction = true;

        $category = Category::create(['name' => 'TEST yacht '.uniqid(), 'slug' => 'yacht']);
        $keyword = (string) Str::uuid();
        DB::table('keywords')->insert([
            'id' => $keyword, 'name' => 'TEST anchor '.uniqid(), 'search_keyword' => 'test anchor',
            'category_id' => $category->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $article = Article::create([
            'keyword_id' => $keyword, 'category_id' => $category->id,
            'source_url' => 'https://example.com/'.uniqid(), 'source_url_hash' => md5(uniqid('x', true)),
            'source_title' => 'TEST anchor source', 'title' => 'TEST anchor article '.uniqid(),
            'slug' => 'test-anchor-'.uniqid(), 'content' => 'noi dung test', 'status' => 'pending',
        ]);
        $this->project = VideoProject::create(['title' => 'TEST anchor '.uniqid(), 'article_id' => $article->id]);

        $client = new class($this->calls) implements TextCompletionClient
        {
            public function __construct(private array &$calls) {}

            public function complete(string $model, string $system, string $user, int $maxTokens, ?array $outputSchema = null): TextCompletionResponse
            {
                $this->calls[] = ['system' => $system, 'user' => $user];

                return new TextCompletionResponse(
                    text: 'IDENTITY PROMPT #'.count($this->calls),
                    model: 'gpt-5.6-terra',
                    stopReason: 'stop',
                    inputTokens: 1000,
                    outputTokens: 400,
                );
            }
        };

        $this->app->instance(TextCompletionClient::class, $client);

        foreach ([GeometryPromptAuthor::class, 'video.character_prompt_author', CharacterAnchorPromptService::class, VideoProjectService::class] as $key) {
            $this->app->forgetInstance($key);
        }
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction) {
            DB::rollBack();
            $this->inTransaction = false;
        }

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $mainIds */
    private function storeScenes(bool $select = true, array $mainIds = ['ch_ferry', 'ch_master', 'ch_crew']): VideoPlanningStage
    {
        $dir = resource_path('ai/screenplay/scene_expansion_v1/');
        $v4 = $this->readJson($dir.'04_worked_example.input.json')['foundation']
            + $this->readJson($dir.'04_worked_example.json')
            + ['schema_version' => 'screenplay_v4'];

        foreach ($v4['characters'] as $index => $character) {
            $v4['characters'][$index]['role'] = in_array($character['id'], $mainIds, true)
                ? ScreenplaySubjectService::MAIN_ROLE
                : 'supporting';
        }

        $stage = VideoPlanningStage::create([
            'project_id' => $this->project->id,
            'planning_revision' => 1,
            'stage' => PlanningStageName::SCREENPLAY->value,
            'status' => VideoPlanningStageStatus::SUCCEEDED->value,
            'input_json' => ['contract_version' => 'screenplay_scene_expansion_v1', 'fingerprint' => 'x'],
            'input_hash' => hash('sha256', uniqid('scenes', true)),
            'output_json' => $v4,
            'finished_at' => now(),
        ]);

        if ($select) {
            $approvals = $this->app->make(ScreenplayApprovalService::class);
            $approvals->approve($this->project->id, $stage->id, null, (string) Str::uuid());
            $this->app->make(ProductionSelectionService::class)->selectScreenplay($this->project->id, $stage->id, 0);
        }

        return $stage;
    }

    private function service(): CharacterAnchorPromptService
    {
        return $this->app->make(CharacterAnchorPromptService::class);
    }

    /** @return array{0: mixed, 1: string, 2: mixed} */
    private function author(string $characterId, bool $force = false): array
    {
        return $this->service()->author(
            $this->project->id,
            $characterId,
            \App\Enums\AnchorStage::FABRICATION_GEOMETRY_ANCHOR,
            ImageSize::LANDSCAPE,
            CharacterAnchorPromptService::anchorModel(),
            $force,
        );
    }

    private function actAsAdmin(): void
    {
        $admin = new Admin(['name' => 'Anchor test admin']);
        $admin->id = (string) Str::uuid();
        $admin->setRelation('role', new Role(['name' => 'admin']));
        $this->actingAs($admin);
    }

    public function test_the_characters_come_from_the_production_screenplay_only(): void
    {
        $this->assertSame([], $this->service()->characters($this->project->id));

        $stage = $this->storeScenes(select: false);
        $this->assertSame([], $this->service()->characters($this->project->id), 'an unselected breakdown is not production');

        $this->app->make(ScreenplayApprovalService::class)
            ->approve($this->project->id, $stage->id, null, (string) Str::uuid());
        $this->app->make(ProductionSelectionService::class)->selectScreenplay($this->project->id, $stage->id, 0);
        $characters = $this->service()->characters($this->project->id);

        $this->assertSame(['ch_ferry', 'ch_master', 'ch_crew'], array_column($characters, 'id'), 'only main characters get anchors');
        $this->assertSame(['id' => 'ch_ferry', 'name' => 'The ferry', 'kind' => 'object', 'role' => 'protagonist'], $characters[0]);
    }

    public function test_the_vessel_uses_the_geometry_skill_and_people_use_the_character_skill(): void
    {
        $this->storeScenes();

        [$vessel] = $this->author('ch_ferry');
        [$master] = $this->author('ch_master');
        [$crew] = $this->author('ch_crew');

        $this->assertSame('IDENTITY PROMPT #1', $vessel->prompt);
        $this->assertStringContainsString('GEOMETRY REFERENCE IMAGE PROMPT', $this->calls[0]['system']);
        $this->assertStringContainsString('"design"', $this->calls[0]['user']);
        $this->assertStringContainsString('principal_dimensions', $this->calls[0]['user']);

        foreach ([1, 2] as $call) {
            $this->assertStringContainsString('IDENTITY REFERENCE IMAGE PROMPT', $this->calls[$call]['system']);
            $this->assertStringNotContainsString('GEOMETRY REFERENCE', $this->calls[$call]['system']);
        }

        $masterSource = json_decode(substr($this->calls[1]['user'], strlen('SOURCE MATERIAL:')), true);
        $this->assertSame('ch_master', $masterSource['participant']['id']);
        $this->assertSame(['completion'], array_column($masterSource['appearances'], 'stage'));

        $crewSource = json_decode(substr($this->calls[2]['user'], strlen('SOURCE MATERIAL:')), true);
        $this->assertSame(['operation', 'operation'], array_column($crewSource['appearances'], 'stage'));
        $this->assertSame('gpt-image-2.5-flare', $master->model);
        $this->assertNotSame($master->promptHash, $crew->promptHash);
    }

    public function test_each_character_is_cached_on_its_own_and_force_writes_again(): void
    {
        $this->storeScenes();

        $this->author('ch_master');
        [, $cached] = $this->author('ch_master');
        $this->assertSame('cached', $cached);
        $this->assertCount(1, $this->calls);

        [, $other] = $this->author('ch_crew');
        $this->assertSame('ok', $other);

        [$again, $forced] = $this->author('ch_master', true);
        $this->assertSame('ok', $forced);
        $this->assertSame('IDENTITY PROMPT #3', $again->prompt);
        $this->assertCount(3, $this->calls);
    }

    public function test_without_a_scene_breakdown_or_for_an_unknown_character_nothing_is_called(): void
    {
        [, $reason] = $this->author('ch_ferry');
        $this->assertSame('character_prompt_no_screenplay', $reason);

        $this->storeScenes();
        [, $reason] = $this->author('ch_nobody');
        $this->assertSame('character_prompt_unknown_character', $reason);

        [, $reason] = $this->author('ch_passengers');
        $this->assertSame('character_not_main', $reason);

        $this->assertSame([], $this->calls);
    }

    public function test_the_page_writes_and_shows_a_prompt_for_the_chosen_character(): void
    {
        $this->actAsAdmin();
        $this->storeScenes();
        $page = route('video-projects.anchor', $this->project->id);

        $this->get($page)->assertOk()
            ->assertSee('Candidate images')
            ->assertSee('Chưa có prompt cho The ferry')
            ->assertSee('Chưa có prompt cho The master')
            ->assertSee('Chưa có prompt cho The crew')
            ->assertSee('name="character_id" value="ch_master"', false)
            ->assertDontSee('name="character" onchange', false);

        $this->from($page)->post(route('video-projects.concept', $this->project->id), ['character_id' => 'ch_master'])
            ->assertRedirect($page)
            ->assertSessionHas('success');

        $preview = $this->app->make(VideoProjectService::class)->anchorPromptPreview($this->project->id, 'ch_master');
        $this->assertSame('IDENTITY PROMPT #1', $preview['prompt']);
        $this->assertSame('The master', $preview['character_name']);
        $this->assertSame('gpt-image-2.5-flare', $preview['lineage']['model']);
        $this->assertNull($this->app->make(VideoProjectService::class)->anchorPromptPreview($this->project->id, 'ch_crew'));

        $html = (string) $this->get($page)->assertOk()
            ->assertSee('IDENTITY PROMPT #1')
            ->assertSee('name="prompt_sha256" value="'.$preview['prompt_sha256'].'"', false)
            ->getContent();

        $this->assertMatchesRegularExpression('/value="gpt-image-2\.5-flare"\s+selected/', $html);
    }

    public function test_render_uses_the_chosen_characters_prompt_and_refuses_a_stale_hash(): void
    {
        $this->storeScenes();
        [$compiled, , $character] = $this->author('ch_master');
        $service = $this->app->make(VideoProjectService::class);
        $service->storeAnchorPromptPreview(
            $this->project->id,
            \App\Enums\AnchorStage::FABRICATION_GEOMETRY_ANCHOR,
            \App\Video\Concept\Viewpoint::FrontThreeQuarter,
            ImageSize::LANDSCAPE,
            $compiled,
            [],
            $character,
        );

        $renderer = \Mockery::mock(DesignImageDirectRenderer::class);
        $renderer->shouldReceive('renderNow')->once()->andReturnUsing(
            static fn (string $id): array => [VideoDesignImage::query()->find($id), 'rendered'],
        );
        $this->app->instance(DesignImageDirectRenderer::class, $renderer);
        $this->app->forgetInstance(VideoProjectService::class);
        $service = $this->app->make(VideoProjectService::class);

        $args = [$this->project->id, 'admin', str_repeat('a', 64), ImageSize::LANDSCAPE,
            ImageModel::GPT_IMAGE_2_5_FLARE, ImageQuality::LOW, ImageVariations::ONE, 'ch_master'];
        $this->assertSame([null, 'anchor_prompt_stale'], $service->renderAnchorFromPreview(...$args));

        $args[2] = $compiled->promptHash;
        [$image, $reason] = $service->renderAnchorFromPreview(...$args);

        $this->assertSame('rendered', $reason);
        $spec = $image->prompt_spec_json;
        $this->assertSame('IDENTITY PROMPT #1', $spec['prompt']);
        $this->assertSame('gpt-image-2.5-flare', $spec['model']);
        $this->assertSame('ch_master', $spec['character_id']);
        $this->assertSame('The master', $spec['character_name']);
        $this->assertStringStartsWith('sp_', $spec['subject_key']);
        $this->assertSame(
            (string) $this->project->refresh()->selected_screenplay_stage_id,
            $spec['screenplay_stage_id'],
        );
        $this->assertGreaterThan(0, (float) $spec['unit_cost_usd']);
    }

    public function test_the_anchor_page_offers_only_main_characters(): void
    {
        $this->actAsAdmin();
        $this->storeScenes(mainIds: ['ch_ferry']);

        $this->get(route('video-projects.anchor', $this->project->id))->assertOk()
            ->assertSee('value="ch_ferry"', false)
            ->assertDontSee('value="ch_master"', false)
            ->assertDontSee('CHỦ THỂ PRODUCTION');
    }

    public function test_a_supporting_character_is_never_rendered(): void
    {
        $this->storeScenes();
        [$compiled, , $character] = $this->author('ch_master');
        $service = $this->app->make(VideoProjectService::class);
        $service->storeAnchorPromptPreview(
            $this->project->id,
            \App\Enums\AnchorStage::FABRICATION_GEOMETRY_ANCHOR,
            \App\Video\Concept\Viewpoint::FrontThreeQuarter,
            ImageSize::LANDSCAPE,
            $compiled,
            [],
            $character,
        );

        $stage = VideoPlanningStage::query()->findOrFail($this->project->refresh()->selected_screenplay_stage_id);
        $output = $stage->output_json;
        foreach ($output['characters'] as $index => $row) {
            if ($row['id'] === 'ch_master') {
                $output['characters'][$index]['role'] = 'supporting';
            }
        }
        $stage->forceFill(['output_json' => $output])->save();
        $this->app->make(ScreenplayApprovalService::class)
            ->approve($this->project->id, $stage->id, null, (string) Str::uuid());

        $renderer = \Mockery::mock(DesignImageDirectRenderer::class);
        $renderer->shouldNotReceive('renderNow');
        $this->app->instance(DesignImageDirectRenderer::class, $renderer);
        $this->app->forgetInstance(VideoProjectService::class);

        $this->assertSame(
            [null, 'character_not_main'],
            $this->app->make(VideoProjectService::class)->renderAnchorFromPreview(
                $this->project->id, 'admin', $compiled->promptHash, ImageSize::LANDSCAPE,
                ImageModel::GPT_IMAGE_2_5_FLARE, ImageQuality::LOW, ImageVariations::ONE, 'ch_master',
            ),
        );
    }

    public function test_a_failed_attempt_never_hides_the_screenplay_the_buttons_act_on(): void
    {
        $this->actAsAdmin();
        $this->storeScenes(select: false);

        VideoPlanningStage::create([
            'project_id' => $this->project->id,
            'planning_revision' => 2,
            'stage' => PlanningStageName::SCREENPLAY->value,
            'status' => VideoPlanningStageStatus::FAILED->value,
            'input_json' => ['contract_version' => 'screenplay_scene_expansion_v1', 'fingerprint' => 'y'],
            'input_hash' => hash('sha256', uniqid('failed', true)),
            'error_message' => 'TEST expansion timed out',
            'finished_at' => now()->addMinute(),
        ]);

        $this->get(route('video-projects.anchor', $this->project->id))->assertOk()
            ->assertSee('Lượt tạo phân cảnh gần nhất lỗi: TEST expansion timed out')
            ->assertSee('bản thành công gần nhất (rev 1)')
            ->assertSee('Duyệt phân cảnh rev 1')
            ->assertSee('The ferry');
    }

    public function test_gpt_image_2_5_is_offered_priced_and_backed_by_evidence(): void
    {
        $evidence = $this->readJson(resource_path('ai/providers/openai_models_2026_09_25.json'));
        $pricing = new OpenAiImagePricing;

        foreach ([ImageModel::GPT_IMAGE_2_5_FLARE, ImageModel::GPT_IMAGE_2_5_SUNBURST] as $model) {
            $this->assertContains($model->value, $evidence['image_model_ids']);
            $this->assertSame('openai', $model->provider());

            foreach (['low', 'medium', 'high'] as $quality) {
                $this->assertSame(
                    $pricing->unitFor('gpt-image-2', $quality),
                    $pricing->unitFor($model->value, $quality),
                    "{$model->value} {$quality}",
                );
            }
        }

        $this->assertSame(ImageModel::GPT_IMAGE_2_5_FLARE, CharacterAnchorPromptService::anchorModel());
    }
}
