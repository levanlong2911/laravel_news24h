<?php

namespace Tests\Feature\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\Admin;
use App\Models\Article;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Video\PythonPromptCompiler;
use App\Services\VideoProjectService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AnchorImageRequestTest extends TestCase
{
    use DatabaseTransactions;

    private VideoProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = VideoProject::create(['title' => 'TEST anchor request '.uniqid()]);
        $this->actingAs(Admin::create([
            'name' => 'TEST anchor admin '.uniqid(),
            'email' => 'test_anchor_'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'role_id' => DB::table('roles')->value('id'),
        ]));
    }

    private function url(): string
    {
        return route('video-projects.anchor-image', $this->project->id);
    }

    /** @return array<string, string|int> */
    private function settings(array $override = []): array
    {
        return $override + [
            'model' => 'gpt-image-2',
            'quality' => 'medium',
            'resolution' => '1024x1536',
            'variations' => 2,
        ];
    }

    public function test_a_setting_outside_the_enum_never_reaches_the_store(): void
    {
        $response = $this->from(route('video-projects.anchor', $this->project->id))
            ->post($this->url(), $this->settings(['quality' => 'ultra']));

        $response->assertSessionHasErrors('quality');
        $this->assertSame(0, VideoDesignImage::where('project_id', $this->project->id)->count());
    }

    public function test_a_missing_setting_is_reported_field_by_field(): void
    {
        $response = $this->from(route('video-projects.anchor', $this->project->id))->post($this->url(), []);

        $response->assertSessionHasErrors(['model', 'quality', 'resolution', 'variations']);
        $this->assertSame(0, VideoDesignImage::where('project_id', $this->project->id)->count());
    }

    public function test_a_variation_count_outside_the_enum_is_refused(): void
    {
        $response = $this->from(route('video-projects.anchor', $this->project->id))
            ->post($this->url(), $this->settings(['variations' => 5]));

        $response->assertSessionHasErrors('variations');
    }

    private function projectWithArticle(): VideoProject
    {
        $article = Article::create([
            'keyword_id' => DB::table('keywords')->value('id'),
            'category_id' => DB::table('categories')->value('id'),
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('', true)),
            'source_title' => 'TEST anchor source',
            'title' => 'TEST anchor article '.uniqid(),
            'slug' => 'test-anchor-article-'.uniqid(),
            'content' => 'noi dung test',
            'status' => 'pending',
        ]);

        return VideoProject::create([
            'title' => 'TEST anchor concept '.uniqid(),
            'article_id' => $article->id,
        ]);
    }

    private function projectWithConcept(): VideoProject
    {
        $project = $this->projectWithArticle();

        VideoPlanningStage::create([
            'project_id' => $project->id,
            'planning_revision' => 1,
            'stage' => PlanningStageName::CONCEPT->value,
            'status' => VideoPlanningStageStatus::SUCCEEDED->value,
            'input_json' => [],
            'input_hash' => hash('sha256', ''),
            'output_json' => ['design_identity' => ['subject' => 'a hull']],
        ]);

        return $project;
    }

    public function test_a_valid_submit_creates_one_candidate_and_says_so(): void
    {
        $project = $this->projectWithConcept();

        $compiler = Mockery::mock(PythonPromptCompiler::class);
        $compiler->shouldReceive('compile')->once()->andReturn(['CAMERA: front three-quarter.', '']);
        $this->instance(PythonPromptCompiler::class, $compiler);
        $this->forgetService();

        $response = $this->from(route('video-projects.anchor', $project->id))
            ->post(route('video-projects.anchor-image', $project->id), $this->settings());

        $images = VideoDesignImage::where('project_id', $project->id)->get();

        $response->assertRedirect(route('video-projects.anchor', $project->id));
        $this->assertCount(1, $images);
        $this->assertSame('candidate', $images->first()->status);
        $this->assertSame('CAMERA: front three-quarter.', $images->first()->prompt_spec_json['prompt']);
        $this->assertSame(
            __('messages.anchor_image_created', ['code' => $images->first()->image_code]),
            session('success'),
        );
    }

    public function test_an_unknown_project_is_named_as_such_not_blamed_on_the_category(): void
    {
        [$prompt, $reason] = app(VideoProjectService::class)->compiledAnchorPrompt((string) Str::uuid());

        $this->assertNull($prompt);
        $this->assertSame('project_not_found', $reason);
    }

    private function forgetService(): void
    {
        app()->forgetInstance(VideoProjectService::class);
    }

    public function test_a_project_without_a_category_is_told_why_instead_of_writing(): void
    {
        $response = $this->from(route('video-projects.anchor', $this->project->id))->post($this->url(), $this->settings());

        $response->assertRedirect(route('video-projects.anchor', $this->project->id));
        $response->assertSessionHas('error', __('messages.anchor_no_category'));
        $this->assertSame(0, VideoDesignImage::where('project_id', $this->project->id)->count());
    }

    public function test_a_project_without_a_concept_is_told_why_instead_of_writing(): void
    {
        $project = $this->projectWithArticle();

        $response = $this->from(route('video-projects.anchor', $project->id))
            ->post(route('video-projects.anchor-image', $project->id), $this->settings());

        $response->assertRedirect(route('video-projects.anchor', $project->id));
        $response->assertSessionHas('error', __('messages.anchor_no_concept'));
        $this->assertSame(0, VideoDesignImage::where('project_id', $project->id)->count());
    }
}
