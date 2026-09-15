<?php

namespace Tests\Feature\Video;

use App\Models\VideoProject;
use App\Models\VideoRenderScene;
use App\Models\VideoSession;
use App\Models\VideoShot;
use App\Video\Render\Video\SceneShotFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

/**
 * Shot duoc sinh luc nguoi dung bam Render video, khong phai luc lap ke hoach.
 */
class SceneShotFactoryTest extends TestCase
{
    use DatabaseTransactions;

    private VideoProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = VideoProject::create(['title' => 'TEST shot factory '.uniqid()]);
    }

    public function test_a_project_without_any_session_still_gets_a_shot(): void
    {
        $scene = $this->scene();

        $this->assertSame(0, VideoSession::query()->where('project_id', $this->project->id)->count());

        $shot = app(SceneShotFactory::class)->forScene($scene, $this->plan());

        $this->assertNotNull($shot->session_id);
        $this->assertSame($scene->id, $shot->scene_id);
        $this->assertSame('the camera pushes in', $shot->compiled_prompt);
        $this->assertSame('motion', $shot->kind);
        $this->assertSame(
            $this->project->id,
            VideoSession::query()->whereKey($shot->session_id)->value('project_id'),
        );
    }

    public function test_pressing_render_twice_never_makes_two_shots(): void
    {
        $scene = $this->scene();
        $factory = app(SceneShotFactory::class);

        $first = $factory->forScene($scene, $this->plan());
        $second = $factory->forScene($scene, $this->plan());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, VideoShot::query()->where('scene_id', $scene->id)->count());
    }

    public function test_a_changed_prompt_updates_the_same_shot(): void
    {
        $scene = $this->scene();
        $factory = app(SceneShotFactory::class);

        $first = $factory->forScene($scene, $this->plan());
        $second = $factory->forScene($scene, $this->plan(['video_prompt' => 'the camera pulls back']));

        $this->assertSame($first->id, $second->id);
        $this->assertSame('the camera pulls back', $second->compiled_prompt);
    }

    public function test_a_scene_with_no_clip_prompt_makes_no_shot(): void
    {
        $scene = $this->scene();

        $this->expectExceptionMessageMatches('/prompt clip/');

        try {
            app(SceneShotFactory::class)->forScene($scene, $this->plan(['video_prompt' => '']));
        } finally {
            $this->assertSame(0, VideoShot::query()->where('scene_id', $scene->id)->count());
        }
    }

    private function scene(): VideoRenderScene
    {
        return VideoRenderScene::create([
            'project_id' => $this->project->id,
            'revision' => 1,
            'scene_code' => 'scene_'.uniqid(),
            'scene_index' => 1,
            'delta_prompt' => 'x',
            'prompt_version' => 'test-v1',
            'transition_mode' => 'hard_cut_edit',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function plan(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Mo xuong',
            'purpose' => 'establish',
            'phase' => 'opening',
            'video_prompt' => 'the camera pushes in',
            'duration_seconds' => 8,
        ], $overrides);
    }
}
