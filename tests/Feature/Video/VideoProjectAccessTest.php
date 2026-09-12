<?php

namespace Tests\Feature\Video;

use App\Models\Admin;
use App\Models\Article;
use App\Models\VideoArtifact;
use App\Models\VideoProject;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VideoProjectAccessTest extends TestCase
{
    use DatabaseTransactions;

    private Admin $owner;

    private VideoProject $project;

    private string $articleId;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('video_artifacts');

        $this->owner = $this->account();
        $this->articleId = $this->article();

        $this->project = VideoProject::create([
            'title' => 'TEST access '.uniqid(),
            'article_id' => $this->articleId,
            'admin_id' => $this->owner->id,
        ]);
    }

    public function test_a_member_may_read_an_artifact_of_a_project_it_owns(): void
    {
        $artifact = $this->artifact($this->project->id);

        $this->actingAs($this->owner)
            ->get(route('video-artifacts.show', $artifact->id))
            ->assertOk();
    }

    public function test_a_member_may_not_read_an_artifact_of_another_project(): void
    {
        $artifact = $this->artifact($this->project->id);

        $this->actingAs($this->account())
            ->get(route('video-artifacts.show', $artifact->id))
            ->assertForbidden();
    }

    public function test_an_artifact_without_a_project_is_admin_only(): void
    {
        $artifact = $this->artifact(null);

        $this->actingAs($this->owner)
            ->get(route('video-artifacts.show', $artifact->id))
            ->assertForbidden();

        $this->actingAs($this->account('admin'))
            ->get(route('video-artifacts.show', $artifact->id))
            ->assertOk();
    }

    public function test_pressing_create_video_never_hands_over_another_members_project(): void
    {
        $this->actingAs($this->account())
            ->post(route('video-projects.store', $this->articleId))
            ->assertForbidden();

        $this->assertSame(
            $this->owner->id,
            $this->project->fresh()->admin_id,
            'a refused attempt must not change the owner',
        );

        $this->assertSame(
            1,
            VideoProject::query()->where('article_id', $this->articleId)->count(),
            'a refused attempt must not create a duplicate project',
        );
    }

    public function test_an_unknown_role_writes_no_project_row_before_it_is_refused(): void
    {
        $fresh = $this->article();

        $this->actingAs($this->account('viewer'))
            ->post(route('video-projects.store', $fresh))
            ->assertForbidden();

        $this->assertSame(
            0,
            VideoProject::query()->where('article_id', $fresh)->count(),
            'a refused role must not leave a row holding the unique article_id',
        );

        $member = $this->account();

        $this->actingAs($member)
            ->post(route('video-projects.store', $fresh))
            ->assertRedirect();

        $this->assertSame(
            $member->id,
            VideoProject::query()->where('article_id', $fresh)->sole()->admin_id,
            'the article must still be free for a legitimate actor',
        );
    }

    public function test_pressing_create_video_reopens_the_project_for_its_own_member(): void
    {
        $this->actingAs($this->owner)
            ->post(route('video-projects.store', $this->articleId))
            ->assertRedirect(route('video-projects.anchor', $this->project->id));
    }

    public function test_the_index_lists_only_what_the_actor_may_open(): void
    {
        $stranger = $this->account();

        VideoProject::create([
            'title' => 'TEST access other '.uniqid(),
            'article_id' => $this->article(),
            'admin_id' => $stranger->id,
        ]);

        VideoProject::create(['title' => 'TEST access unowned '.uniqid()]);

        $this->actingAs($this->owner)
            ->get(route('video-projects.index'))
            ->assertOk()
            ->assertViewHas('projects', fn ($projects) => collect($projects)
                ->pluck('id')->all() === [$this->project->id]);

        $this->actingAs($this->account('admin'))
            ->get(route('video-projects.index'))
            ->assertOk()
            ->assertViewHas('projects', fn ($projects) => collect($projects)->count() >= 3);
    }

    private function artifact(?string $projectId): VideoArtifact
    {
        $path = 'access/'.uniqid().'.png';

        Storage::disk('video_artifacts')->put($path, 'artifact-bytes');

        return VideoArtifact::create([
            'project_id' => $projectId,
            'artifact_type' => 'image',
            'role' => 'candidate',
            'storage_disk' => 'video_artifacts',
            'storage_path' => $path,
            'mime_type' => 'image/png',
            'sha256' => hash('sha256', 'artifact-bytes'),
        ]);
    }

    private function account(string $role = 'member'): Admin
    {
        $roleId = (string) Str::uuid();

        DB::table('roles')->insert(['id' => $roleId, 'name' => $role]);

        return Admin::create([
            'name' => 'TEST access admin '.uniqid(),
            'email' => 'test_access_'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'role_id' => $roleId,
        ]);
    }

    private function article(): string
    {
        $categoryId = (string) Str::uuid();

        DB::table('categories')->insert([
            'id' => $categoryId,
            'name' => 'TEST access category '.uniqid(),
            'slug' => 'test-access-'.uniqid(),
        ]);

        $keywordId = (string) Str::uuid();

        DB::table('keywords')->insert([
            'id' => $keywordId,
            'name' => 'TEST access keyword '.uniqid(),
            'category_id' => $categoryId,
        ]);

        return (string) Article::create([
            'keyword_id' => $keywordId,
            'category_id' => $categoryId,
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('', true)),
            'source_title' => 'TEST access source',
            'title' => 'TEST access article '.uniqid(),
            'slug' => 'test-access-'.uniqid(),
            'content' => 'A yard begins a new steel motor yacht.',
            'status' => 'pending',
        ])->id;
    }
}
