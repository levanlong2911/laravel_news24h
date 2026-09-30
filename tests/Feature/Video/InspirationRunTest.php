<?php

namespace Tests\Feature\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Category;
use App\Models\Role;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Video\PlanningStageStore;
use App\Services\VideoProjectService;
use App\Video\Concept\Orchestration\CanonicalConceptInputBuilder;
use App\Video\Inspiration\ClaudeInspirationAnalyst;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Llm\LlmResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class InspirationRunTest extends TestCase
{
    /** @var list<string> */
    private const REQUIRED_TABLES = ['keywords', 'categories', 'articles', 'video_projects', 'video_planning_stages'];

    private const ARTICLE = 'The shipyard confirmed a 90-metre explorer yacht with a glass-walled owner deck and a helipad.';

    private VideoProject $project;

    private bool $inTransaction = false;

    private int $calls = 0;

    /** @var list<array<string, mixed>> */
    private array $insights = [];

    /** @var (callable(): void)|null */
    private $duringCall = null;

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

        $this->project = $this->projectInCategory('yacht');
        $this->insights = [[
            'aspect' => 'size_and_dimensions',
            'summary' => 'A 90-metre explorer yacht.',
            'source_quotes' => ['90-metre explorer yacht'],
        ]];

        $this->useAnalyst(new ClaudeInspirationAnalyst($this->fakeLlm()));
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction) {
            DB::rollBack();
            $this->inTransaction = false;
        }

        parent::tearDown();
    }

    private function projectInCategory(string $slug): VideoProject
    {
        $category = Category::create(['name' => 'TEST inspiration '.uniqid(), 'slug' => $slug]);
        $keyword = (string) Str::uuid();
        DB::table('keywords')->insert([
            'id' => $keyword, 'name' => 'TEST inspiration '.uniqid(), 'search_keyword' => 'test inspiration',
            'category_id' => $category->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $article = Article::create([
            'keyword_id' => $keyword, 'category_id' => $category->id,
            'source_url' => 'https://example.com/'.uniqid(), 'source_url_hash' => md5(uniqid('x', true)),
            'source_title' => 'TEST inspiration source', 'title' => 'TEST inspiration article '.uniqid(),
            'slug' => 'test-inspiration-'.uniqid(), 'content' => self::ARTICLE, 'status' => 'pending',
        ]);

        return VideoProject::create(['title' => 'TEST inspiration '.uniqid(), 'article_id' => $article->id]);
    }

    private function fakeLlm(): LlmClient
    {
        return new class($this) implements LlmClient
        {
            public function __construct(private readonly InspirationRunTest $test) {}

            public function complete(LlmRequest $request): LlmResponse
            {
                $text = $this->test->answer();

                return new LlmResponse($text, 'haiku', 1200, 300, 10, 0.0027, $text, 'claude-haiku-4-5-20251001');
            }
        };
    }

    public function answer(): string
    {
        $this->calls++;

        if ($this->duringCall !== null) {
            ($this->duringCall)();
        }

        return json_encode([
            'article_patterns' => ['new_build_launch_or_delivery'],
            'article_focus' => 'A new explorer yacht build.',
            'source_insights' => $this->insights,
            'excluded_context' => [],
        ], JSON_THROW_ON_ERROR);
    }

    private function useAnalyst(ClaudeInspirationAnalyst $analyst): void
    {
        $this->app->instance(ClaudeInspirationAnalyst::class, $analyst);
        $this->app->forgetInstance(CanonicalConceptInputBuilder::class);
        $this->app->forgetInstance(VideoProjectService::class);
    }

    private function service(): VideoProjectService
    {
        return $this->app->make(VideoProjectService::class);
    }

    private function stages()
    {
        return VideoPlanningStage::query()
            ->where('project_id', $this->project->id)
            ->where('stage', PlanningStageName::INSPIRATION->value);
    }

    public function test_one_run_makes_exactly_one_ai_call(): void
    {
        [$brief, $reason] = $this->service()->runInspiration($this->project->id);

        $this->assertSame('ok', $reason);
        $this->assertSame('A new explorer yacht build.', $brief['article_focus']);
        $this->assertSame(1, $this->calls, 'the extractor call was paid for and thrown away');
        $this->assertSame(VideoPlanningStageStatus::SUCCEEDED->value, $this->stages()->sole()->status);
    }

    public function test_a_brief_without_insights_succeeds_and_the_next_click_is_cached(): void
    {
        $this->insights = [];

        [$brief, $reason] = $this->service()->runInspiration($this->project->id);

        $this->assertSame('ok', $reason);
        $this->assertSame([], $brief['source_insights']);
        $this->assertSame(VideoPlanningStageStatus::SUCCEEDED->value, $this->stages()->sole()->status);

        [, $again] = $this->service()->runInspiration($this->project->id);

        $this->assertSame('cached', $again);
        $this->assertSame(1, $this->calls);
        $this->assertFalse($this->service()->latestInspiration($this->project->id)['can_run']);
    }

    public function test_a_changed_profile_is_not_served_from_cache(): void
    {
        $this->service()->runInspiration($this->project->id);

        config(['video.creative_profiles.profiles.luxury_vessel.mission' => 'A different mission for the same article.']);

        $this->assertTrue($this->service()->latestInspiration($this->project->id)['can_run']);

        [, $reason] = $this->service()->runInspiration($this->project->id);

        $this->assertSame('ok', $reason);
        $this->assertSame(2, $this->calls);
    }

    public function test_a_profile_field_the_analyst_never_reads_keeps_the_cache(): void
    {
        $this->service()->runInspiration($this->project->id);

        config(['video.creative_profiles.profiles.luxury_vessel.concept_mission' => 'A changed concept mission.']);

        [, $reason] = $this->service()->runInspiration($this->project->id);

        $this->assertSame('cached', $reason);
        $this->assertSame(1, $this->calls);
    }

    public function test_the_analyst_fingerprint_carries_the_instruction_version_and_model(): void
    {
        $haiku = (new ClaudeInspirationAnalyst($this->fakeLlm()))->fingerprint();
        $sonnet = (new ClaudeInspirationAnalyst($this->fakeLlm(), 'sonnet'))->fingerprint();

        $this->assertSame(ClaudeInspirationAnalyst::INSTRUCTION_VERSION, $haiku['instruction_version']);
        $this->assertSame('claude-haiku-4-5-20251001', $haiku['provider_model']);
        $this->assertNotEquals($haiku, $sonnet);
    }

    public function test_a_lost_claim_never_reports_success_and_leaves_the_new_run_visible(): void
    {
        $this->duringCall = function (): void {
            $this->stages()->where('status', VideoPlanningStageStatus::RUNNING->value)->update([
                'claim_token' => 'claimed-by-another-request',
                'lease_expires_at' => now()->addHour(),
            ]);
        };

        [$brief, $reason] = $this->service()->runInspiration($this->project->id);

        $this->assertNull($brief);
        $this->assertStringContainsString('giành mất', $reason);

        $orphan = $this->stages()->whereNotNull('input_json->orphan_of_stage_id')->sole();
        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $orphan->status);
        $this->assertSame('A new explorer yacht build.', $orphan->output_json['article_focus']);
        $this->assertSame(1200, (int) $orphan->tokens_in);

        $panel = $this->service()->latestInspiration($this->project->id);
        $this->assertTrue($panel['running'], 'the orphan row must not hide the run that took the claim');

        $this->assertSame([true, 'ok'], $this->service()->resetInspiration($this->project->id));
    }

    public function test_a_category_without_a_profile_opens_no_stage_and_calls_nothing(): void
    {
        $this->project = $this->projectInCategory('test-no-profile-'.uniqid());

        [$brief, $reason] = $this->service()->runInspiration($this->project->id);

        $this->assertNull($brief);
        $this->assertStringContainsString('chua co creative profile', $reason);
        $this->assertSame(0, $this->calls);
        $this->assertSame(0, $this->stages()->count());
    }

    public function test_the_inspiration_button_redirects_with_a_message(): void
    {
        $admin = new Admin(['name' => 'Inspiration test admin']);
        $admin->id = (string) Str::uuid();
        $admin->setRelation('role', new Role(['name' => 'admin']));
        $this->actingAs($admin);

        $this->from(route('video-projects.anchor', $this->project->id))
            ->post(route('video-projects.inspiration', $this->project->id))
            ->assertRedirect(route('video-projects.anchor', $this->project->id))
            ->assertSessionHas('success');
    }

    public function test_the_claim_store_skips_orphans_only_when_asked(): void
    {
        $store = $this->app->make(PlanningStageStore::class);
        [$stage, $token] = $store->claimProjectStage($this->project->id, PlanningStageName::INSPIRATION, ['x' => 1]);
        $store->recordOrphanAttempt(
            $this->project->id, PlanningStageName::INSPIRATION, ['x' => 1], [], $stage->id, (string) $token, 'claim_lost',
        );

        [$plain] = $store->latestStageForProject($this->project->id, PlanningStageName::INSPIRATION, ['x' => 1]);
        [$skipped] = $store->latestStageForProject($this->project->id, PlanningStageName::INSPIRATION, ['x' => 1], skipOrphans: true);

        $this->assertNotSame($stage->id, $plain->id);
        $this->assertSame($stage->id, $skipped->id);
    }
}
