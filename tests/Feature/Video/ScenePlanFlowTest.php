<?php

namespace Tests\Feature\Video;

use App\Enums\DesignImageStatus;
use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\Admin;
use App\Models\Article;
use App\Models\VideoArtifact;
use App\Models\VideoCostEntry;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Models\VideoRenderScene;
use App\Services\Video\DesignImageDirectRenderer;
use App\Services\Video\DesignImageStore;
use App\Services\VideoProjectService;
use App\Video\Prompt\TextCompletionClient;
use App\Video\Scene\ScenePlanAuthor;
use App\Video\Scene\ScenePreservationPrompt;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fakes\RecordingTextCompletionClient;
use Tests\TestCase;

class ScenePlanFlowTest extends TestCase
{
    use DatabaseTransactions;

    private const MARKER = 'PLANNING INPUT:';

    private const PNG_3X5 = 'iVBORw0KGgoAAAANSUhEUgAAAAMAAAAFCAIAAAAPE8H1AAAACXBIWXMAAA7EAAAOxAGVKw4b'
        .'AAAAF0lEQVQImWPkEpFjYGBgYGBgYoAB/CwADFgARjw2UTkAAAAASUVORK5CYII=';

    private const ANCHOR_PROMPT = "ASSET TYPE\nCanonical geometry identity anchor.\n\n"
        ."PRIMARY SUBJECT\nA large steel motor yacht under construction.\n\n"
        ."P0 - CANONICAL IDENTITY\nKnife-like vertical plumb bow, wide flat stern, unbroken sheer.\n\n"
        ."P3 - PROPORTION\nSlender, about six times longer than it is wide.\n\n"
        ."SUBJECT STATE\nBare fabrication hull.";

    private VideoProject $project;

    private Admin $owner;

    private RecordingTextCompletionClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('video_artifacts');

        $this->client = new RecordingTextCompletionClient();
        $this->app->forgetInstance(ScenePlanAuthor::class);
        $this->app->instance(TextCompletionClient::class, $this->client);

        [$categoryId, $slug] = $this->category();

        config([
            'video.scene_plan.profiles.'.$slug => 'vessel_v1',
            'video.environment.profiles.'.$slug => 'vessel_v2',
        ]);

        $this->owner = $this->admin();
        $this->project = VideoProject::create([
            'title' => 'TEST scene plan '.uniqid(),
            'article_id' => $this->article($categoryId),
            'admin_id' => $this->owner->id,
        ]);

        $this->approvedAnchor(self::ANCHOR_PROMPT);
        $this->approveEveryPlate();
        $this->inspirationBrief();

        $this->actingAs($this->owner);
    }

    public function test_another_member_cannot_spend_money_on_someone_elses_project(): void
    {
        $this->actingAs($this->admin());

        $this->post($this->url())->assertForbidden();

        $this->assertSame(0, $this->client->calls, 'the model must not be called for a project the actor does not own');
        $this->assertSame(0, $this->scenePlanStages()->count());
        $this->assertSame(0, VideoRenderScene::query()->where('project_id', $this->project->id)->count());
    }

    public function test_an_admin_may_plan_a_project_owned_by_someone_else(): void
    {
        $this->actingAs($this->admin('admin'));
        $this->client->text = $this->plan();

        $this->post($this->url())->assertSessionHas('success');

        $this->assertSame(1, $this->client->authorCalls);
        $this->assertSame(11, VideoRenderScene::query()->where('project_id', $this->project->id)->count());
    }

    public function test_a_member_is_refused_an_unowned_project(): void
    {
        $this->project->forceFill(['admin_id' => null])->save();

        $this->post($this->url())->assertForbidden();

        $this->assertSame(0, $this->client->calls);
        $this->assertSame(0, $this->scenePlanStages()->count());
    }

    public function test_an_admin_may_open_an_unowned_project(): void
    {
        $this->project->forceFill(['admin_id' => null])->save();
        $this->actingAs($this->admin('admin'));

        $this->get(route('video-projects.scene', $this->project->id))->assertOk();
    }

    public function test_a_valid_plan_is_written_as_revision_one(): void
    {
        $this->client->text = $this->plan();

        $this->post($this->url())->assertRedirect()->assertSessionHas('success');

        $this->assertSame(1, $this->client->authorCalls);
        $this->assertSame(1, $this->client->reviewCalls);
        $this->assertSame(2, $this->client->calls);

        $scenes = VideoRenderScene::query()
            ->where('project_id', $this->project->id)
            ->orderBy('scene_index')
            ->get();

        $this->assertCount(11, $scenes);
        $this->assertSame(range(1, 11), $scenes->pluck('scene_index')->all());
        $this->assertSame('profile_drawing', $scenes->first()->scene_code);
        $this->assertSame('in_service', $scenes->last()->scene_code);
        $this->assertSame(array_fill(0, 11, 1), $scenes->pluck('revision')->all());
        $this->assertSame('scene-plan-v3', $scenes->first()->prompt_version);
        $this->assertSame(ScenePreservationPrompt::HARD_CUT, $scenes->first()->transition_mode);
        $this->assertSame('empty_desk', $scenes->first()->state_json['state_before']);
        $this->assertSame('first_sketch_pinned', $scenes->first()->state_json['scene_state']);
        $this->assertSame(['concept_sketch'], $scenes->first()->milestone_keys);
        $this->assertSame('inferred_process', $scenes->first()->basis);
        $this->assertSame('two_sheets_pinned', $scenes->first()->video_plan_json['end_state']);
        $this->assertSame('locked', $scenes->first()->video_plan_json['camera_mode']);
        $this->assertSame(
            ['deck_fitted', 'superstructure'],
            $scenes->firstWhere('scene_code', 'deck_and_house')->milestone_keys,
        );

        $stage = $this->scenePlanStages()->sole();

        $this->assertSame(VideoPlanningStageStatus::SUCCEEDED->value, $stage->status);
        $this->assertSame(1, (int) $stage->output_json['revision']);
        $this->assertSame($this->client->text, $stage->raw_response);
        $this->assertSame(8420, (int) $stage->tokens_in, 'the column carries author plus review');
        $this->assertSame(1280, (int) $stage->thinking_tokens);
        $this->assertSame(4210, $stage->output_json['usage']['author']['tokens_in']);
        $this->assertSame(4210, $stage->output_json['usage']['reviews'][0]['tokens_in']);
        $this->assertSame(2, $stage->output_json['usage']['total']['calls']);
        $this->assertArrayNotHasKey('incomplete', $stage->output_json['usage']['total']);
        $this->assertSame('passed', $stage->output_json['review']['status']);
        $this->assertSame('passed', $stage->output_json['review']['reason']);
        $this->assertNotNull($stage->output_json['review']['reviewed_plan_sha256']);
        $this->assertCount(1, $stage->output_json['review']['rounds']);
        $this->assertSame('unpriced', $stage->input_json['_meta']['pricing'] ?? null);
        $this->assertNull($stage->claim_token);
        $this->assertSame([], $stage->output_json['warnings']);

        $summary = $this->planningInput()['identity_summary'];

        $this->assertSame('anchor_prompt', $summary['subject_class_source']);
        $this->assertSame('A large steel motor yacht under construction.', $summary['subject_class']);
        $this->assertStringContainsString('plumb bow', $summary['identity']);
        $this->assertStringContainsString('six times longer', $summary['proportion']);
    }

    public function test_an_anchor_without_a_subject_heading_falls_back_to_the_category_class(): void
    {
        VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', DesignImageStore::ANCHOR_TYPE)
            ->sole()
            ->forceFill(['prompt_spec_json' => ['prompt' =>
                "ASSET TYPE\nCanonical geometry identity anchor.\n\n"
                ."P0 - CANONICAL IDENTITY\nKnife-like vertical plumb bow, wide flat stern.\n\n"
                ."P3 - PROPORTION\nSlender, about six times longer than it is wide.",
            ]])
            ->save();

        $this->client->text = $this->plan();

        $this->post($this->url())->assertSessionHas('success');

        $summary = $this->planningInput()['identity_summary'];

        $this->assertSame('superyacht', $summary['subject_class']);
        $this->assertSame('category_subject_class', $summary['subject_class_source']);
        $this->assertStringContainsString('plumb bow', $summary['identity']);
    }

    public function test_a_heuristic_warning_is_stored_against_its_revision_and_shown(): void
    {
        $this->client->text = $this->plan(ScenePreservationPrompt::HARD_CUT, true);

        $this->post($this->url())->assertSessionHas('success');

        $warnings = $this->scenePlanStages()->sole()->output_json['warnings'];

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('scene 2', $warnings[0]);
        $this->assertStringContainsString('describing motion', $warnings[0]);

        $response = $this->get(route('video-projects.scene', $this->project->id));

        $response->assertOk();
        $response->assertViewHas('warnings', $warnings);
        $response->assertSee($warnings[0]);
    }

    public function test_pressing_the_button_twice_calls_the_model_once(): void
    {
        $this->client->text = $this->plan();

        $this->post($this->url())->assertSessionHas('success');
        $this->post($this->url())->assertSessionHas('error');

        $this->assertSame(1, $this->client->authorCalls, 'an unchanged input must not be re-planned');
        $this->assertSame(1, $this->client->reviewCalls, 'a dedup hit must not reach the reviewer either');
        $this->assertSame(2, $this->client->calls);
        $this->assertSame(11, VideoRenderScene::query()->where('project_id', $this->project->id)->count());
    }

    public function test_a_truncated_response_fails_and_keeps_what_was_paid_for(): void
    {
        $this->client->text = '{"scenes":[{"scene_code":"profile_dra';
        $this->client->stopReason = 'max_tokens';

        $this->post($this->url())->assertSessionHas('error');

        $stage = $this->scenePlanStages()->sole();

        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $stage->status);
        $this->assertSame($this->client->text, $stage->raw_response);
        $this->assertSame(4210, (int) $stage->tokens_in);
        $this->assertSame(1180, (int) $stage->tokens_out);
        $this->assertStringContainsString('max_tokens', (string) $stage->error_message);
        $this->assertSame(0, VideoRenderScene::query()->where('project_id', $this->project->id)->count());
    }

    public function test_malformed_json_fails_and_keeps_the_raw_response(): void
    {
        $this->client->text = 'the plan, but not as JSON';

        $this->post($this->url())->assertSessionHas('error');

        $stage = $this->scenePlanStages()->sole();

        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $stage->status);
        $this->assertSame('the plan, but not as JSON', $stage->raw_response);
    }

    public function test_a_first_scene_that_continues_is_refused_before_anything_is_written(): void
    {
        $this->client->text = $this->plan(ScenePreservationPrompt::CONTINUATION);

        $this->post($this->url())->assertSessionHas('error');

        $this->assertSame(1, $this->client->calls);
        $this->assertSame(0, VideoRenderScene::query()->where('project_id', $this->project->id)->count());

        $stage = $this->scenePlanStages()->sole();

        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $stage->status);
        $this->assertSame($this->client->text, $stage->raw_response, 'a paid response must survive a rejected plan');
        $this->assertSame(4210, (int) $stage->tokens_in);
        $this->assertSame(1180, (int) $stage->tokens_out);
        $this->assertSame(640, (int) $stage->thinking_tokens);
    }

    public function test_losing_the_claim_rolls_the_scenes_back_and_leaves_one_orphan(): void
    {
        $this->client->text = $this->plan();

        $stolen = (string) Str::uuid();

        $this->client->before = function () use ($stolen) {
            DB::table('video_planning_stages')
                ->where('project_id', $this->project->id)
                ->where('stage', PlanningStageName::SCENE_PLAN->value)
                ->update(['claim_token' => $stolen]);
        };

        $this->post($this->url())->assertSessionHas('error');

        $this->assertSame(
            0,
            VideoRenderScene::query()->where('project_id', $this->project->id)->count(),
            'the scene rows must roll back when the ledger refuses the claim',
        );

        $running = $this->scenePlanStages()
            ->where('status', VideoPlanningStageStatus::RUNNING->value)
            ->sole();

        $this->assertSame($stolen, $running->claim_token, 'the row now holding the token must not be touched');

        $orphan = $this->scenePlanStages()
            ->where('status', VideoPlanningStageStatus::FAILED->value)
            ->sole();

        $this->assertSame($this->client->text, $orphan->raw_response);
        $this->assertSame(4210, (int) $orphan->tokens_in);
        $this->assertSame(640, (int) $orphan->thinking_tokens);
        $this->assertSame('unpriced', $orphan->input_json['_meta']['pricing'] ?? null);
        $this->assertSame($running->id, $orphan->input_json['orphan_of_stage_id'] ?? null);
    }

    public function test_the_scene_page_shows_the_written_plan(): void
    {
        $this->client->text = $this->plan();

        $this->post($this->url());

        $response = $this->get(route('video-projects.scene', $this->project->id));

        $response->assertOk();
        $response->assertViewHas('revision', 1);
        $response->assertViewHas('records', 1);
        $response->assertViewHas('unpriced', true);
        $response->assertViewHas('profileNotice', null);
        $response->assertViewHas('scenes', fn (array $scenes) => count($scenes) === 11
            && $scenes[0]['id'] === 'profile_drawing'
            && $scenes[0]['transition_mode'] === ScenePreservationPrompt::HARD_CUT
            && $scenes[0]['milestones'] === ['First exterior sketch']
            && $scenes[0]['basis'] === 'inferred_process'
            && $scenes[0]['state_before'] === 'empty_desk'
            && $scenes[0]['end_state'] === 'two_sheets_pinned'
            && str_contains((string) $scenes[0]['video_prompt'], 'ACTION: ')
            && str_contains((string) $scenes[0]['video_prompt'], 'same position, same lens'));

        $response->assertSee('Chưa định giá');
        $response->assertSee('Exterior Profile Sketch');
        $response->assertSee('First exterior sketch');
        $response->assertSee('suy diễn');
    }

    public function test_a_category_without_a_profile_never_reaches_the_model(): void
    {
        config(['video.scene_plan.profiles' => []]);

        $this->post($this->url())->assertSessionHas('error');

        $this->assertSame(0, $this->client->calls);
        $this->assertSame(0, VideoRenderScene::query()->where('project_id', $this->project->id)->count());
    }

    public function test_a_plan_missing_a_required_milestone_is_refused_by_name(): void
    {
        $this->client->text = $this->planWith(8, ['milestone_keys' => ['surface_faired', 'glazed']]);

        $this->post($this->url())->assertSessionHas('error');

        $stage = $this->scenePlanStages()->sole();

        $this->assertStringContainsString('painted', (string) $stage->error_message);
        $this->assertSame(0, VideoRenderScene::query()->where('project_id', $this->project->id)->count());
    }

    public function test_milestones_may_not_step_backwards(): void
    {
        $this->client->text = $this->planWith(5, [
            'phase' => 'rough_build',
            'milestone_keys' => ['bottom_structure'],
        ]);

        $this->post($this->url())->assertSessionHas('error');

        $this->assertStringContainsString(
            'move backwards',
            (string) $this->scenePlanStages()->sole()->error_message,
        );
    }

    public function test_a_milestone_must_belong_to_the_declared_phase(): void
    {
        $this->client->text = $this->planWith(3, ['milestone_keys' => ['painted']]);

        $this->post($this->url())->assertSessionHas('error');

        $this->assertStringContainsString(
            'is not in phase',
            (string) $this->scenePlanStages()->sole()->error_message,
        );
    }

    public function test_a_scene_may_not_claim_more_milestones_than_the_profile_allows(): void
    {
        $this->client->text = $this->planWith(3, [
            'milestone_keys' => ['bottom_structure', 'hull_framing', 'shell_plating', 'deck_fitted'],
        ]);

        $this->post($this->url())->assertSessionHas('error');

        $this->assertStringContainsString(
            'milestone_keys must be a list of 1 to 3',
            (string) $this->scenePlanStages()->sole()->error_message,
        );
    }

    public function test_a_free_camera_mode_is_refused(): void
    {
        $this->client->text = $this->planWith(0, ['camera_mode' => 'slow_push_in']);

        $this->post($this->url())->assertSessionHas('error');

        $this->assertStringContainsString(
            'camera_mode must be locked',
            (string) $this->scenePlanStages()->sole()->error_message,
        );
    }

    public function test_a_plan_below_the_profile_floor_is_refused(): void
    {
        $plan = json_decode($this->plan(), true, 512, JSON_THROW_ON_ERROR);
        $plan['scenes'] = array_slice($plan['scenes'], 0, 4);
        $this->client->text = json_encode($plan, JSON_THROW_ON_ERROR);

        $this->post($this->url())->assertSessionHas('error');

        $this->assertStringContainsString(
            'requires at least 10',
            (string) $this->scenePlanStages()->sole()->error_message,
        );
    }

    public function test_a_scenes_object_instead_of_a_list_is_refused_with_its_raw_kept(): void
    {
        $plan = json_decode($this->plan(), true, 512, JSON_THROW_ON_ERROR);
        $this->client->text = json_encode(
            ['scenes' => ['a' => $plan['scenes'][0], 'b' => $plan['scenes'][1]]],
            JSON_THROW_ON_ERROR,
        );

        $this->post($this->url())->assertSessionHas('error');

        $stage = $this->scenePlanStages()->sole();

        $this->assertStringContainsString('no scenes list', (string) $stage->error_message);
        $this->assertSame($this->client->text, $stage->raw_response);
        $this->assertSame(4210, (int) $stage->tokens_in);
        $this->assertSame(640, (int) $stage->thinking_tokens);
        $this->assertSame(0, VideoRenderScene::query()->where('project_id', $this->project->id)->count());
    }

    public function test_a_plan_above_the_configured_ceiling_is_refused(): void
    {
        config(['video.scene_plan.max_scenes' => 10]);
        $this->app->forgetInstance(ScenePlanAuthor::class);

        $this->client->text = $this->plan();

        $this->post($this->url())->assertSessionHas('error');

        $this->assertStringContainsString(
            'the ceiling is 10',
            (string) $this->scenePlanStages()->sole()->error_message,
        );
    }

    public function test_a_title_longer_than_the_field_is_refused(): void
    {
        $this->client->text = $this->planWith(0, ['title' => str_repeat('Long ', 3).str_repeat('x', 200)]);

        $this->post($this->url())->assertSessionHas('error');

        $this->assertStringContainsString(
            'at most 120 characters',
            (string) $this->scenePlanStages()->sole()->error_message,
        );
    }

    public function test_a_broken_state_chain_only_warns(): void
    {
        $this->client->text = $this->planWith(4, ['state_before' => 'something_else_entirely']);

        $this->post($this->url())->assertSessionHas('success');

        $warnings = $this->scenePlanStages()->sole()->output_json['warnings'];

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('does not continue the previous keyframe state', $warnings[0]);
        $this->assertSame(11, VideoRenderScene::query()->where('project_id', $this->project->id)->count());
    }

    public function test_a_revision_keeps_the_preservation_block_it_was_planned_under(): void
    {
        $this->client->text = $this->plan();
        $this->post($this->url())->assertSessionHas('success');

        $scenes = $this->sceneViews();
        $first = VideoRenderScene::query()
            ->where('project_id', $this->project->id)
            ->orderBy('scene_index')
            ->first();

        $this->assertSame(
            ScenePreservationPrompt::forMode(ScenePreservationPrompt::HARD_CUT)."\n\n".$first->delta_prompt,
            $scenes[0]['image_prompt'],
        );

        $this->rewritePreservationVersion(ScenePreservationPrompt::LEGACY_VERSION);

        $this->assertSame(
            ScenePreservationPrompt::forMode(
                ScenePreservationPrompt::HARD_CUT,
                ScenePreservationPrompt::LEGACY_VERSION,
            )."\n\n".$first->delta_prompt,
            $this->sceneViews()[0]['image_prompt'],
            'an older revision must keep the block it was written under',
        );
    }

    public function test_an_unknown_preservation_version_leaves_the_page_readable(): void
    {
        $this->client->text = $this->plan();
        $this->post($this->url())->assertSessionHas('success');

        $this->rewritePreservationVersion('scene-preservation-v9');

        $response = $this->get(route('video-projects.scene', $this->project->id));

        $response->assertOk();
        $response->assertViewHas('preservationNotice', 'preservation_unknown');
        $response->assertViewHas('scenes', fn (array $scenes) => count($scenes) === 11
            && $scenes[0]['image_prompt'] === null
            && str_contains((string) $scenes[0]['video_prompt'], 'ACTION: ')
            && $scenes[0]['title'] === 'Exterior Profile Sketch');
    }

    public function test_a_revision_without_the_key_falls_back_to_the_first_block(): void
    {
        $this->client->text = $this->plan();
        $this->post($this->url())->assertSessionHas('success');

        $stage = $this->scenePlanStages()->sole();
        $input = $stage->input_json;
        unset($input['preservation_version']);
        $stage->forceFill(['input_json' => $input])->save();

        $first = VideoRenderScene::query()
            ->where('project_id', $this->project->id)
            ->orderBy('scene_index')
            ->first();

        $this->assertSame(
            ScenePreservationPrompt::forMode(
                ScenePreservationPrompt::HARD_CUT,
                ScenePreservationPrompt::LEGACY_VERSION,
            )."\n\n".$first->delta_prompt,
            $this->sceneViews()[0]['image_prompt'],
        );
    }

    public function test_a_null_preservation_version_is_not_treated_as_an_old_revision(): void
    {
        $this->assertBrokenPlanningKeepsThePageReadable(
            fn () => $this->rewritePreservationVersion(null),
            null,
        );
    }

    public function test_an_empty_preservation_version_is_not_treated_as_an_old_revision(): void
    {
        $this->assertBrokenPlanningKeepsThePageReadable(
            fn () => $this->rewritePreservationVersion(''),
            null,
        );
    }

    public function test_a_missing_planning_record_leaves_the_page_readable(): void
    {
        $this->assertBrokenPlanningKeepsThePageReadable(
            fn () => $this->scenePlanStages()->delete(),
            'profile_unverifiable',
        );
    }

    public function test_a_corrupted_planning_record_leaves_the_page_readable(): void
    {
        $this->assertBrokenPlanningKeepsThePageReadable(
            function () {
                DB::table('video_planning_stages')
                    ->where('id', $this->scenePlanStages()->sole()->id)
                    ->update(['input_json' => '123']);
            },
            'profile_unverifiable',
        );
    }

    /**
     * Chup danh sach TRUOC khi lam hong roi so nguyen van SAU: chi nhu vay moi
     * chung minh duoc resolver hong khong keo prompt clip theo. `$after` lay tu
     * `viewData()` cua chinh lan GET, khong goi lai service.
     *
     * @param  \Closure(): void  $break
     */
    private function assertBrokenPlanningKeepsThePageReadable(\Closure $break, ?string $profileNotice): void
    {
        $this->client->text = $this->plan();
        $this->post($this->url())->assertSessionHas('success');

        $before = $this->get(route('video-projects.scene', $this->project->id))->viewData('scenes');

        $this->assertCount(11, $before);
        $this->assertNotNull(
            $before[0]['image_prompt'],
            'the page must carry an image prompt before the planning record is broken',
        );

        $break();

        $response = $this->get(route('video-projects.scene', $this->project->id));

        $response->assertOk();
        $response->assertViewHas('preservationNotice', 'preservation_unknown');
        $response->assertViewHas('profileNotice', $profileNotice);

        $after = $response->viewData('scenes');

        $this->assertCount(11, $after);
        $this->assertNull($after[0]['image_prompt']);
        $this->assertSame(
            array_column($before, 'video_prompt'),
            array_column($after, 'video_prompt'),
            'a broken planning record must not disturb the clip drafts',
        );
    }

    /** @return list<array<string, mixed>> */
    private function sceneViews(): array
    {
        return app(\App\Services\VideoProjectService::class)
            ->latestScenePlan($this->project->id)['scenes'];
    }

    private function rewritePreservationVersion(?string $version): void
    {
        $stage = $this->scenePlanStages()->sole();
        $input = $stage->input_json;
        $input['preservation_version'] = $version;

        $stage->forceFill(['input_json' => $input])->save();
    }

    public function test_the_outgoing_schema_omits_unique_items_and_keeps_supported_constraints(): void
    {
        config(['video.scene_plan.max_scenes' => 18]);
        $this->app->forgetInstance(ScenePlanAuthor::class);

        $this->client->text = $this->plan();
        $this->post($this->url())->assertSessionHas('success');

        $schema = $this->client->authorSchema;

        $this->assertIsArray($schema);
        $this->assertStringNotContainsString(
            'uniqueItems',
            json_encode($schema, JSON_THROW_ON_ERROR),
            'the provider rejected uniqueItems; PHP carries that rule instead',
        );

        $scenes = $schema['properties']['scenes'];
        $items = $scenes['items']['properties'];

        $this->assertArrayNotHasKey('uniqueItems', $items['milestone_keys']);

        $this->assertSame(10, $scenes['minItems']);
        $this->assertSame(18, $scenes['maxItems']);
        $this->assertSame(1, $items['milestone_keys']['minItems']);
        $this->assertSame(3, $items['milestone_keys']['maxItems']);
        $this->assertSame('^[a-z][a-z0-9_]{2,59}$', $items['scene_code']['pattern']);
    }

    public function test_a_repeated_milestone_inside_one_scene_is_refused(): void
    {
        $this->client->text = $this->planWith(6, [
            'milestone_keys' => ['deck_fitted', 'deck_fitted'],
        ]);

        $this->post($this->url())->assertSessionHas('error');

        $stage = $this->scenePlanStages()->sole();

        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $stage->status);
        $this->assertStringContainsString('repeats within the scene', (string) $stage->error_message);
        $this->assertSame(0, VideoRenderScene::query()->where('project_id', $this->project->id)->count());
    }

    public function test_a_purpose_below_the_field_minimum_is_refused(): void
    {
        $this->client->text = $this->planWith(0, ['purpose' => 'ok']);

        $this->post($this->url())->assertSessionHas('error');

        $stage = $this->scenePlanStages()->sole();

        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $stage->status);
        $this->assertStringContainsString(
            'purpose must be 3 to 500 characters',
            (string) $stage->error_message,
        );
        $this->assertSame(0, VideoRenderScene::query()->where('project_id', $this->project->id)->count());
    }

    /**
     * Bon delta duoi day la VAN BAN THAT tu revision 2 — chinh bon cau da sinh
     * ra bao dong gia: `lower hull`, `lower vessel body`, `internal framing`,
     * `frames rise from`.
     */
    public function test_shipbuilding_vocabulary_no_longer_raises_false_warnings(): void
    {
        $plan = json_decode($this->plan(), true, 512, JSON_THROW_ON_ERROR);

        $plan['scenes'][0]['delta'] = "Change only the setting to a naval architect's studio: a large "
            .'hand-drawn exterior profile sketch lies centered on a drafting table. A designer hand rests '
            .'beside the completed sketch, seen from a close overhead three-quarter view under warm task lighting.';

        $plan['scenes'][4]['delta'] = 'Change only the hull assembly: evenly spaced transverse frames and '
            .'longitudinal stringers rise from the completed bottom structure, defining the open hull volume '
            .'from bow through stern.';

        $plan['scenes'][5]['delta'] = 'Change only the hull exterior: large fitted shell plates cover most of '
            .'the lower hull, while a remaining midship opening exposes the internal framing.';

        $plan['scenes'][6]['delta'] = 'Change only the upper hull: the shell is fully plated and a continuous '
            .'deck structure spans the hull. The hull reads as a complete enclosed lower vessel body.';

        $this->client->text = json_encode($plan, JSON_THROW_ON_ERROR);

        $this->post($this->url())->assertSessionHas('success');

        $this->assertSame([], $this->scenePlanStages()->sole()->output_json['warnings']);
    }

    public function test_real_motion_is_still_caught(): void
    {
        $this->client->text = $this->planWith(4, [
            'delta' => 'The crane lowers the last frame onto its prepared supports while workers '
                .'steady it at the lower edges.',
        ]);

        $this->post($this->url())->assertSessionHas('success');

        $warnings = $this->scenePlanStages()->sole()->output_json['warnings'];

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('scene 5', $warnings[0]);
        $this->assertStringContainsString('motion', $warnings[0]);
    }

    /**
     * Danh sach tu chi chung minh duoc CO NHAC camera, khong chung minh duoc
     * KHONG CO cau camera. Ca hai chot vi vay da tat; camera nam o tieu chi 5
     * cua checklist, doc tay.
     */
    public function test_camera_wording_no_longer_raises_warnings(): void
    {
        $this->client->text = $this->planWith(5, [
            'delta' => 'A wide shot from the quay holds the hull with its plating half closed.',
        ]);

        $this->post($this->url())->assertSessionHas('success');

        $this->assertSame([], $this->scenePlanStages()->sole()->output_json['warnings']);
    }

    public function test_a_hard_cut_without_camera_wording_no_longer_raises_warnings(): void
    {
        $this->client->text = $this->planWith(2, [
            'delta' => 'The empty assembly hall holds the building berth prepared, '
                .'its support rows set in a straight line.',
        ]);

        $this->post($this->url())->assertSessionHas('success');

        $this->assertSame([], $this->scenePlanStages()->sole()->output_json['warnings']);
    }

    public function test_the_planner_sends_the_configured_skill_and_records_its_hash(): void
    {
        $bytes = (string) file_get_contents((string) config('video.scene_plan.prompt_path'));

        $this->assertStringContainsString(self::MARKER, $bytes);

        $this->client->text = $this->plan();
        $this->post($this->url())->assertSessionHas('success');

        $this->assertSame(
            trim(substr($bytes, 0, (int) strpos($bytes, self::MARKER))),
            $this->client->authorSystem,
            'the planner must send the skill file the configuration points at',
        );

        $this->assertSame(
            hash('sha256', $bytes),
            $this->scenePlanStages()->sole()->input_json['skill_hash'],
            'skill_hash must be the sha256 of the whole file that was read',
        );
    }

    /**
     * Dong moi duoc chen TRUOC marker: them cuoi file chi doi hash chu khong
     * doi phan system that su duoc gui, nen test se khong chung minh duoc gi.
     * Va KHONG dung force — ep chay lai thi test van xanh ke ca khi skill_hash
     * bi bo khoi khoa dedup.
     */
    public function test_editing_the_skill_reopens_planning_while_an_unchanged_one_does_not(): void
    {
        $dir = storage_path('framework/testing');

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $originalPath = (string) config('video.scene_plan.prompt_path');
        $original = (string) file_get_contents($originalPath);
        $tmp = $dir.DIRECTORY_SEPARATOR.'skill_'.uniqid().'.txt';

        $this->assertStringContainsString(self::MARKER, $original);

        try {
            file_put_contents($tmp, $original);
            config(['video.scene_plan.prompt_path' => $tmp]);
            $this->app->forgetInstance(ScenePlanAuthor::class);

            $this->client->text = $this->plan();

            $this->post($this->url())->assertSessionHas('success');

            $this->assertSame(1, $this->client->authorCalls);
            $this->assertSame(1, $this->latestSceneRevision());

            $revisionOne = $this->sceneRowsAt(1);
            $this->assertCount(11, $revisionOne);

            $this->post($this->url())->assertSessionHas('error');

            $this->assertSame(1, $this->client->authorCalls, 'an unchanged skill must not be re-planned');
            $this->assertSame(1, $this->latestSceneRevision());

            $line = 'A NEW RULE THAT MUST REACH THE MODEL.';
            file_put_contents($tmp, str_replace(self::MARKER, $line."\n\n".self::MARKER, $original));
            $this->app->forgetInstance(ScenePlanAuthor::class);

            $this->post($this->url())->assertSessionHas('success');

            $this->assertSame(2, $this->client->authorCalls, 'an edited skill must reopen planning');
            $this->assertSame(2, $this->latestSceneRevision());
            $this->assertStringContainsString($line, (string) $this->client->authorSystem);

            $this->assertSame(
                hash('sha256', (string) file_get_contents($tmp)),
                $this->scenePlanStages()
                    ->orderByDesc('planning_revision')
                    ->first()
                    ->input_json['skill_hash'],
            );

            $this->assertSame(
                $revisionOne,
                $this->sceneRowsAt(1),
                'the earlier revision must survive byte for byte, not merely keep its row count',
            );
        } finally {
            config(['video.scene_plan.prompt_path' => $originalPath]);
            $this->app->forgetInstance(ScenePlanAuthor::class);

            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function sceneRowsAt(int $revision): array
    {
        return DB::table('video_render_scenes')
            ->where('project_id', $this->project->id)
            ->where('revision', $revision)
            ->orderBy('scene_index')
            ->get()
            ->map(static fn ($row) => (array) $row)
            ->all();
    }

    private function latestSceneRevision(): int
    {
        return (int) VideoRenderScene::query()
            ->where('project_id', $this->project->id)
            ->max('revision');
    }

    public function test_a_counted_identity_fact_in_a_delta_is_flagged_for_review(): void
    {
        $this->client->text = $this->planWith(0, [
            'delta' => 'A wide shot across the design office holds a profile sketch of the stepped '
                .'three-tier superstructure pinned to the wall.',
        ]);

        $this->post($this->url())->assertSessionHas('success');

        $warnings = $this->scenePlanStages()->sole()->output_json['warnings'];

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('scene 1', $warnings[0]);
        $this->assertStringContainsString('may restate an identity fact', $warnings[0]);
    }

    /**
     * Ghi ro GIOI HAN cua co ra soat, de khong ai doc no thanh mot bo phan loai:
     * mot so cau thao tac cung bi bat, va mot so cach viet nhan dang thi lot.
     */
    public function test_the_count_warning_is_a_review_flag_not_a_classifier(): void
    {
        $flagged = [
            'three-tier superstructure',
            'six cabins',
            'six clearly divided cabin areas',
            'two-tier deckhouse',
            'three deck panels',
        ];

        $ignored = [
            'one deck panel',
            'a single cabin door',
            'the lower deck',
            'four workers on the deck',
        ];

        foreach ($flagged as $phrase) {
            $this->assertSame(1, preg_match($this->countPattern(), $phrase), $phrase.' should be flagged');
        }

        foreach ($ignored as $phrase) {
            $this->assertSame(0, preg_match($this->countPattern(), $phrase), $phrase.' should stay quiet');
        }
    }

    public function test_an_operational_count_does_not_raise_the_review_flag(): void
    {
        $this->client->text = $this->planWith(4, [
            'delta' => 'One deck panel rests flat beside the completed bottom structure, ready for '
                .'the next lift.',
        ]);

        $this->post($this->url())->assertSessionHas('success');

        foreach ($this->scenePlanStages()->sole()->output_json['warnings'] as $warning) {
            $this->assertStringNotContainsString('may restate an identity fact', $warning);
        }
    }

    private function countPattern(): string
    {
        return (string) (new \ReflectionClassConstant(
            \App\Services\VideoProjectService::class,
            'COUNT_PATTERN',
        ))->getValue();
    }

    public function test_a_first_round_patch_is_applied_and_a_second_pass_marks_it_passed(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [$this->finding('arrangement_laid')], [
                $this->sceneAt(1, ['title' => 'Arrangement Sheet Flat']),
            ]),
            $this->review('pass'),
        ];

        $this->post($this->url())->assertSessionHas('success');

        $review = $this->scenePlanStages()->sole()->output_json['review'];

        $this->assertSame('passed', $review['status']);
        $this->assertSame(2, $this->client->reviewCalls);
        $this->assertCount(2, $review['rounds']);
        $this->assertSame(['arrangement_laid'], $review['rounds'][0]['patched_codes']);
        $this->assertCount(2, $review['plan_hashes'], 'only an accepted patch extends the history');
        $this->assertSame(
            'Arrangement Sheet Flat',
            $this->sceneRowsAt(1)[1]['title'],
            'the committed row must carry the patched title',
        );
        $this->assertSame(
            $review['rounds'][0]['plan_sha256_out'],
            $review['reviewed_plan_sha256'],
            'the reviewed hash must be the plan the second round actually read',
        );
    }

    public function test_two_revising_rounds_save_the_patch_but_do_not_mark_it_passed(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [$this->finding('arrangement_laid')], [
                $this->sceneAt(1, ['title' => 'Arrangement One']),
            ]),
            $this->review('revise', [$this->finding('arrangement_laid')], [
                $this->sceneAt(1, ['title' => 'Arrangement Two']),
            ]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $review = $this->scenePlanStages()->sole()->output_json['review'];

        $this->assertSame('needs_review', $review['status']);
        $this->assertSame('patched_but_unreviewed', $review['reason']);
        $this->assertNull($review['reviewed_plan_sha256']);
        $this->assertSame(2, $this->client->reviewCalls, 'the cap must hold');
        $this->assertSame('Arrangement Two', $this->sceneRowsAt(1)[1]['title']);
    }

    public function test_a_review_failure_keeps_the_plan_the_author_paid_for(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [new \RuntimeException('network down')];

        $this->post($this->url())->assertSessionHas('warning');

        $output = $this->scenePlanStages()->sole()->output_json;

        $this->assertCount(11, $this->sceneRowsAt(1), 'a reviewer failure must not discard the plan');
        $this->assertSame('needs_review', $output['review']['status']);
        $this->assertSame('review_call_failed', $output['review']['reason']);
        $this->assertTrue($output['review']['rounds'][0]['completion_attempted']);
        $this->assertSame('RuntimeException', $output['review']['rounds'][0]['error']['class']);
        $this->assertNull($output['review']['rounds'][0]['usage'], 'unknown usage is null, not zero');
        $this->assertTrue($output['usage']['total']['incomplete']);
        $this->assertSame(2, $output['usage']['total']['calls'], 'an attempted call still counts');
        $this->assertSame(4210, $output['usage']['total']['tokens_in'], 'only the author tokens are known');
    }

    public function test_a_truncated_review_keeps_its_raw_and_usage(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [[
            'text' => $this->review('pass'),
            'stopReason' => 'max_tokens',
        ]];

        $this->post($this->url())->assertSessionHas('warning');

        $output = $this->scenePlanStages()->sole()->output_json;
        $round = $output['review']['rounds'][0];

        $this->assertSame('review_call_failed', $output['review']['reason']);
        $this->assertStringContainsString(
            'cut off at the token limit',
            $round['error']['message']['text'],
            'valid JSON must still be refused when the response was truncated',
        );
        $this->assertSame($this->review('pass'), $round['raw']['text']);
        $this->assertSame('utf8', $round['raw']['encoding']);
        $this->assertSame(4210, $round['usage']['tokens_in'], 'a truncated call was still paid for');
    }

    public function test_a_pass_carrying_a_patch_is_refused_and_the_patch_is_not_applied(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('pass', [], [$this->sceneAt(1, ['title' => 'Smuggled Title'])]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $review = $this->scenePlanStages()->sole()->output_json['review'];

        $this->assertSame('review_response_incoherent', $review['reason']);
        $this->assertSame('General Arrangement Laid', $this->sceneRowsAt(1)[1]['title']);
        $this->assertArrayNotHasKey('plan_sha256_out', $review['rounds'][0]);
        $this->assertCount(1, $review['plan_hashes']);
    }

    public function test_a_revise_without_a_blocking_finding_is_refused(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [$this->finding('arrangement_laid', 'advisory')], [
                $this->sceneAt(1, ['title' => 'Advisory Only']),
            ]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $this->assertSame(
            'review_response_incoherent',
            $this->scenePlanStages()->sole()->output_json['review']['reason'],
        );
        $this->assertSame('General Arrangement Laid', $this->sceneRowsAt(1)[1]['title']);
    }

    /**
     * `severity` la mot chuoi la se khong bang 'blocking', nen phep dem thuan
     * tuy se coi day la `pass` hop le. Provider schema khong duoc lam chot duy nhat.
     */
    public function test_a_malformed_severity_cannot_smuggle_a_pass(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('pass', [
                ['scene_code' => 'arrangement_laid', 'rule' => 'content', 'severity' => 'invalid',
                    'problem' => 'Something is wrong here.', 'fix' => 'Rewrite the title.'],
            ]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $review = $this->scenePlanStages()->sole()->output_json['review'];

        $this->assertSame('needs_review', $review['status']);
        $this->assertSame('review_response_incoherent', $review['reason']);
        $this->assertNull($review['reviewed_plan_sha256']);
    }

    public function test_requires_replan_is_recorded_as_its_own_reason(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('requires_replan', [$this->finding('arrangement_laid', 'blocking', 'milestone_basis')]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $review = $this->scenePlanStages()->sole()->output_json['review'];

        $this->assertSame('needs_review', $review['status']);
        $this->assertSame('requires_replan', $review['reason']);
        $this->assertCount(11, $this->sceneRowsAt(1));
    }

    public function test_a_patch_that_returns_the_plan_to_an_earlier_state_is_caught(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [$this->finding('arrangement_laid')], [
                $this->sceneAt(1, ['title' => 'Arrangement Moved']),
            ]),
            $this->review('revise', [$this->finding('arrangement_laid')], [
                $this->sceneAt(1),
            ]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $review = $this->scenePlanStages()->sole()->output_json['review'];

        $this->assertSame('oscillated', $review['reason']);
        $this->assertCount(2, $review['plan_hashes'], 'the round that oscillated must not extend the history');
        $this->assertSame('Arrangement Moved', $this->sceneRowsAt(1)[1]['title']);
    }

    public function test_a_patch_that_breaks_the_plan_keeps_the_previous_one(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [$this->finding('arrangement_laid')], [
                $this->sceneAt(1, ['milestone_keys' => ['launched']]),
            ]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $review = $this->scenePlanStages()->sole()->output_json['review'];

        $this->assertSame('patch_invalid', $review['reason']);
        $this->assertNotEmpty($review['rounds'][0]['patch_error']['text']);
        $this->assertCount(1, $review['plan_hashes']);
        $this->assertSame(
            ['general_arrangement'],
            json_decode((string) $this->sceneRowsAt(1)[1]['milestone_keys'], true, 512, JSON_THROW_ON_ERROR),
            'the rejected patch must not reach the database',
        );
    }

    public function test_a_patch_naming_an_unknown_scene_is_refused(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [$this->finding('arrangement_laid')], [
                $this->sceneAt(1, ['scene_code' => 'no_such_scene']),
            ]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $this->assertSame(
            'review_response_incoherent',
            $this->scenePlanStages()->sole()->output_json['review']['reason'],
        );
        $this->assertCount(11, $this->sceneRowsAt(1), 'no scene may be added by a patch');
    }

    public function test_a_patch_that_changes_nothing_stops_the_loop(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [$this->finding('arrangement_laid')], [$this->sceneAt(1)]),
            $this->review('pass'),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $this->assertSame('no_progress', $this->scenePlanStages()->sole()->output_json['review']['reason']);
        $this->assertSame(1, $this->client->reviewCalls, 'no progress must not buy another round');
    }

    public function test_disabling_review_calls_the_model_once_and_leaves_the_plan_unreviewed(): void
    {
        config(['video.scene_plan.review.enabled' => false]);

        $this->client->text = $this->plan();

        $this->post($this->url())->assertSessionHas('warning');

        $stage = $this->scenePlanStages()->sole();

        $this->assertSame(1, $this->client->calls);
        $this->assertSame(0, $this->client->reviewCalls);
        $this->assertSame('unreviewed', $stage->output_json['review']['status']);
        $this->assertSame('review_disabled', $stage->output_json['review']['reason']);
        $this->assertFalse($stage->input_json['review_enabled']);
        $this->assertNull($stage->input_json['review_skill_hash']);
        $this->assertCount(11, $this->sceneRowsAt(1));
    }

    public function test_a_round_cap_of_zero_is_refused_before_the_model_is_called(): void
    {
        config(['video.scene_plan.review.max_rounds' => 0]);

        $this->client->text = $this->plan();

        $this->post($this->url())->assertSessionHas('error');

        $this->assertSame(0, $this->client->calls);
        $this->assertSame(0, $this->scenePlanStages()->count());
        $this->assertSame(0, VideoRenderScene::query()->where('project_id', $this->project->id)->count());
    }

    public function test_an_unreadable_review_skill_is_refused_before_the_model_is_called(): void
    {
        config(['video.scene_plan.review.prompt_path' => storage_path('no_such_review_skill.txt')]);

        $this->client->text = $this->plan();

        $this->post($this->url())->assertSessionHas('error');

        $this->assertSame(0, $this->client->calls);
        $this->assertSame(0, $this->scenePlanStages()->count());
    }

    public function test_the_reviewer_receives_the_plan_the_profile_and_the_heuristic_warnings(): void
    {
        $this->client->text = $this->plan(warn: true);

        $this->post($this->url())->assertSessionHas('success');

        $sent = json_decode(
            substr((string) $this->client->reviewUsers[0], strlen('REVIEW INPUT:')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(
            ['article', 'inspiration_brief', 'identity_summary', 'profile',
                'planning_requirements', 'plan', 'heuristic_warnings'],
            array_keys($sent),
        );
        $this->assertCount(11, $sent['plan']['scenes']);
        $this->assertNotEmpty($sent['heuristic_warnings'], 'the reviewer must see the flags a human would read');
        $this->assertArrayHasKey('subject_class', $sent['identity_summary']);
    }

    /**
     * Contract viet tay, khong lay tu `$profile`, de sua sai helper thi test do
     * chu khong xanh theo.
     */
    public function test_the_reviewer_patches_against_the_author_scene_contract(): void
    {
        $this->client->text = $this->plan();

        $this->post($this->url())->assertSessionHas('success');

        $author = $this->client->authorSchema['properties']['scenes']['items'];
        $patch = $this->client->reviewSchema['properties']['patch']['items'];

        $this->assertSame($author, $patch, 'a patched scene must satisfy the same contract as a written one');

        $this->assertFalse($author['additionalProperties']);
        $this->assertSame([
            'scene_code', 'title', 'purpose', 'phase', 'milestone_keys',
            'basis', 'state_before', 'scene_state', 'transition_mode',
            'continuity_group', 'source_scene_code', 'camera_change_reason',
            'camera_mode', 'delta', 'video',
        ], $author['required']);
        $this->assertSame($author['required'], array_keys($author['properties']));
        $this->assertSame('^[a-z][a-z0-9_]{2,59}$', $author['properties']['scene_code']['pattern']);
        $this->assertSame(['locked'], $author['properties']['camera_mode']['enum']);
        $this->assertSame(
            '^[a-z][a-z0-9_]{2,59}$',
            $author['properties']['continuity_group']['pattern'],
        );
        $this->assertSame(300, $author['properties']['camera_change_reason']['maxLength']);
        $this->assertSame(1000, $author['properties']['delta']['maxLength']);
        $this->assertSame(
            ['action', 'preserve', 'end_state'],
            $author['properties']['video']['required'],
        );
    }

    /** @param list<array<string, mixed>> $findings
     *  @param list<array<string, mixed>> $patch */
    private function review(string $verdict, array $findings = [], array $patch = []): string
    {
        return json_encode(
            ['verdict' => $verdict, 'findings' => $findings, 'patch' => $patch],
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * `evidence` trich mot doan CO THAT trong `delta` cua chinh scene do —
     * validator doi quote la chuoi con that, nen fixture khong duoc bia.
     *
     * @param  list<array<string, mixed>>|null  $evidence
     * @return array<string, mixed>
     */
    private function finding(
        string $code,
        string $severity = 'blocking',
        string $rule = 'content',
        ?array $evidence = null,
    ): array {
        return [
            'scene_code' => $code,
            'rule' => $rule,
            'severity' => $severity,
            'problem' => 'The title does not describe what the clip shows.',
            'fix' => 'Rewrite the title so it matches the action.',
            'evidence' => $evidence ?? [[
                'source' => 'scene',
                'scene_code' => $code,
                'field' => 'delta',
                'quote' => mb_substr($this->deltaOf($code), 0, 40),
            ]],
        ];
    }

    private function deltaOf(string $code): string
    {
        $plan = json_decode($this->plan(), true, 512, JSON_THROW_ON_ERROR);

        foreach ($plan['scenes'] as $scene) {
            if ($scene['scene_code'] === $code) {
                return (string) $scene['delta'];
            }
        }

        throw new \RuntimeException('khong co scene '.$code.' trong fixture');
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function sceneAt(int $index, array $override = []): array
    {
        $plan = json_decode($this->plan(), true, 512, JSON_THROW_ON_ERROR);

        return array_replace($plan['scenes'][$index], $override);
    }

    /**
     * `skillAnchorPrompt()` dung CHUNG ten bien `$author` voi `planScenes()`,
     * nen mot phep thay chuoi trung lan xuat hien dau tien da tung xoa lo goi
     * `skillHash()` o day. Test di qua route `concept` de khoa dong do.
     */
    public function test_the_skill_anchor_prompt_records_its_own_skill_hash(): void
    {
        $this->client->text = '{"not":"a geometry prompt"}';

        $this->post(route('video-projects.concept', $this->project->id));

        $stage = DB::table('video_planning_stages')
            ->where('project_id', $this->project->id)
            ->where('stage', PlanningStageName::ANCHOR_PROMPT->value)
            ->first();

        $this->assertNotNull($stage, 'the concept route must reach the anchor prompt claim');

        $input = json_decode((string) $stage->input_json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            hash('sha256', (string) file_get_contents((string) config('image_prompt.prompt_path'))),
            $input['skill_hash'],
        );
    }

    public function test_a_later_pass_clears_the_findings_an_earlier_round_raised(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [$this->finding('arrangement_laid')], [
                $this->sceneAt(1, ['title' => 'Arrangement Flat']),
            ]),
            $this->review('pass', [$this->finding('first_water', 'advisory', 'camera')]),
        ];

        $this->post($this->url())->assertSessionHas('success');

        $review = $this->reviewView();

        $this->assertSame('passed', $review['status']);
        $this->assertFalse($review['patched_but_unverified']);
        $this->assertCount(2, $review['rounds'], 'the history is still there to trace');
        $this->assertSame(
            ['arrangement_laid'],
            $review['rounds'][0]['patched_codes'],
            'the round that raised the blocking finding is kept',
        );

        $this->assertSame(
            ['first_water'],
            array_column($review['open_findings'], 'scene_code'),
            'only the last round speaks about the plan on screen',
        );

        $this->get(route('video-projects.scene', $this->project->id))
            ->assertOk()
            ->assertSee('Đã rà tự động')
            ->assertSee('first_water')
            ->assertSee('ghi nhận');
    }

    public function test_findings_no_patch_addressed_stay_open_on_the_page(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('requires_replan', [
                $this->finding('shell_closed', 'blocking', 'milestone_basis'),
            ]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $review = $this->reviewView();

        $this->assertSame('needs_review', $review['status']);
        $this->assertSame('requires_replan', $review['reason']);
        $this->assertCount(1, $review['open_findings']);
        $this->assertSame('shell_closed', $review['open_findings'][0]['scene_code']);

        $this->get(route('video-projects.scene', $this->project->id))
            ->assertOk()
            ->assertSee('Cần kiểm tra')
            ->assertSee('shell_closed')
            ->assertSee('chặn render');
    }

    public function test_a_patched_but_unreviewed_plan_does_not_show_stale_findings_as_open(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [$this->finding('arrangement_laid')], [
                $this->sceneAt(1, ['title' => 'Arrangement One']),
            ]),
            $this->review('revise', [$this->finding('arrangement_laid')], [
                $this->sceneAt(1, ['title' => 'Arrangement Two']),
            ]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $review = $this->reviewView();

        $this->assertSame('patched_but_unreviewed', $review['reason']);
        $this->assertTrue($review['patched_but_unverified']);
        $this->assertSame([], $review['open_findings'], 'the last patch answered them; nobody re-read it');

        $this->get(route('video-projects.scene', $this->project->id))
            ->assertOk()
            ->assertSee('chưa ai đọc lại');
    }

    public function test_a_revision_recorded_before_the_review_loop_is_shown_as_unreviewed(): void
    {
        $this->client->text = $this->plan();
        $this->post($this->url())->assertSessionHas('success');

        $this->rewriteReview(static function (array $output): array {
            unset($output['review']);

            return $output;
        });

        $review = $this->reviewView();

        $this->assertSame('unreviewed', $review['status']);
        $this->assertSame('not_recorded', $review['reason']);
        $this->assertSame([], $review['rounds']);

        $this->get(route('video-projects.scene', $this->project->id))
            ->assertOk()
            ->assertSee('Chưa rà')
            ->assertSee('trước khi vòng rà tồn tại');
    }

    public function test_an_unreadable_review_record_is_reported_not_guessed(): void
    {
        $this->client->text = $this->plan();
        $this->post($this->url())->assertSessionHas('success');

        $this->rewriteReview(static function (array $output): array {
            $output['review'] = 'passed';

            return $output;
        });

        $review = $this->reviewView();

        $this->assertSame('unreviewed', $review['status'], 'a broken record must never read as passed');
        $this->assertSame('unreadable', $review['reason']);
        $this->assertNull($review['reviewed_plan_sha256']);

        $this->get(route('video-projects.scene', $this->project->id))->assertOk();
    }

    public function test_a_project_with_no_plan_reports_an_unverifiable_review(): void
    {
        $review = $this->reviewView();

        $this->assertSame('unreviewed', $review['status']);
        $this->assertSame('unverifiable', $review['reason']);
        $this->assertSame([], $review['open_findings']);
    }

    /**
     * `passed` la trang thai duy nhat mo cong render. Mot ban ghi khai passed
     * ma khong co vong nao va khong co hash thi khong con bang chung nao ca.
     */
    public function test_a_passed_status_without_evidence_is_refused(): void
    {
        $this->client->text = $this->plan();
        $this->post($this->url())->assertSessionHas('success');

        $this->rewriteReview(static function (array $output): array {
            $output['review'] = ['status' => 'passed', 'reason' => 'passed', 'rounds' => []];

            return $output;
        });

        $review = $this->reviewView();

        $this->assertSame('unreviewed', $review['status'], 'a passed claim needs a round and a hash');
        $this->assertSame('unreadable', $review['reason']);

        $this->get(route('video-projects.scene', $this->project->id))
            ->assertOk()
            ->assertDontSee('Đã rà tự động');
    }

    public function test_a_passed_status_with_a_malformed_hash_is_refused(): void
    {
        $this->client->text = $this->plan();
        $this->post($this->url())->assertSessionHas('success');

        $this->rewriteReview(static function (array $output): array {
            $output['review']['reviewed_plan_sha256'] = 'not-a-hash';

            return $output;
        });

        $this->assertSame('unreadable', $this->reviewView()['reason']);
    }

    /**
     * `count($round['findings'])` tren trang se VO nếu findings la chuoi, nen
     * hinh dang long nhau phai bi chan o service chu khong o Blade.
     */
    public function test_a_round_with_a_malformed_findings_list_never_reaches_the_page(): void
    {
        $this->client->text = $this->plan();
        $this->post($this->url())->assertSessionHas('success');

        $this->rewriteReview(static function (array $output): array {
            $output['review']['rounds'][0]['findings'] = 'broken';

            return $output;
        });

        $this->assertSame('unreadable', $this->reviewView()['reason']);

        $this->get(route('video-projects.scene', $this->project->id))->assertOk();
    }

    public function test_the_round_history_carries_the_text_of_the_findings_it_kept(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [$this->finding('arrangement_laid')], [
                $this->sceneAt(1, ['title' => 'Arrangement Flat']),
            ]),
            $this->review('pass'),
        ];

        $this->post($this->url())->assertSessionHas('success');

        $this->assertSame([], $this->reviewView()['open_findings']);

        $this->get(route('video-projects.scene', $this->project->id))
            ->assertOk()
            ->assertSee('Lịch sử 2 vòng rà')
            ->assertSee('The title does not describe what the clip shows.')
            ->assertSee('Rewrite the title so it matches the action.')
            ->assertSee('kết quả tại thời điểm từng vòng rà');
    }

    /**
     * File skill doi giua chung khong duoc lam prompt gui di lech khoi hash da
     * luu: ban chup duoc doc mot lan o preflight, truoc luot author.
     */
    public function test_the_review_skill_is_snapshotted_before_the_author_runs(): void
    {
        $path = (string) config('video.scene_plan.review.prompt_path');
        $original = (string) file_get_contents($path);

        $this->client->text = $this->plan();
        $this->client->before = function () use ($path, $original): void {
            file_put_contents($path, 'EDITED MID RUN'.PHP_EOL.$original);
        };

        try {
            $this->post($this->url())->assertSessionHas('success');

            $stage = $this->scenePlanStages()->sole();

            $this->assertSame(
                hash('sha256', $original),
                $stage->input_json['review_skill_hash'],
                'the recorded hash must be the snapshot, not the edited file',
            );
            $this->assertStringNotContainsString(
                'EDITED MID RUN',
                (string) $this->client->reviewSystem,
                'the reviewer must be sent the snapshot the hash was taken from',
            );
        } finally {
            file_put_contents($path, $original);
        }
    }

    /**
     * Bang chung MAU THUAN, khac han voi bang chung THIEU: hash dung dinh dang
     * va co mot vong, nhung vong do khong he pass.
     */
    public function test_a_passed_status_whose_last_round_did_not_pass_is_refused(): void
    {
        $this->client->text = $this->plan();
        $this->post($this->url())->assertSessionHas('success');

        $this->rewriteReview(static function (array $output): array {
            $output['review'] = [
                'status' => 'passed',
                'reason' => 'passed',
                'reviewed_plan_sha256' => str_repeat('a', 64),
                'rounds' => [[
                    'round' => 1,
                    'verdict' => 'requires_replan',
                    'plan_sha256_in' => str_repeat('a', 64),
                ]],
            ];

            return $output;
        });

        $this->assertSame('unreviewed', $this->reviewView()['status']);
        $this->assertSame('unreadable', $this->reviewView()['reason']);
    }

    public function test_a_passed_status_reading_a_different_plan_is_refused(): void
    {
        $this->client->text = $this->plan();
        $this->post($this->url())->assertSessionHas('success');

        $this->rewriteReview(static function (array $output): array {
            $output['review']['rounds'][0]['plan_sha256_in'] = str_repeat('b', 64);

            return $output;
        });

        $this->assertSame(
            'unreadable',
            $this->reviewView()['reason'],
            'the confirmed hash must be the plan that round actually read',
        );
    }

    public function test_a_passed_round_carrying_a_blocking_finding_is_refused(): void
    {
        $this->client->text = $this->plan();
        $this->post($this->url())->assertSessionHas('success');

        $this->rewriteReview(function (array $output): array {
            $output['review']['rounds'][0]['findings'] = [$this->finding('shell_closed')];

            return $output;
        });

        $this->assertSame('unreadable', $this->reviewView()['reason']);
    }

    /**
     * `{{ $finding['problem'] }}` nem loi neu gia tri la mang, nen kieu phai bi
     * chan o service. Trang van phai mo duoc.
     */
    public function test_a_finding_field_that_is_not_a_string_never_reaches_the_page(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('requires_replan', [$this->finding('shell_closed')]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $this->rewriteReview(static function (array $output): array {
            $output['review']['rounds'][0]['findings'][0]['problem'] = ['broken'];

            return $output;
        });

        $this->assertSame('unreadable', $this->reviewView()['reason']);
        $this->assertSame([], $this->reviewView()['open_findings']);

        $this->get(route('video-projects.scene', $this->project->id))->assertOk();
    }

    public function test_a_token_count_of_the_wrong_type_never_reaches_the_page(): void
    {
        $this->client->text = $this->plan();
        $this->post($this->url())->assertSessionHas('success');

        $stage = $this->scenePlanStages()->sole();
        $output = $stage->output_json;
        $output['usage']['total']['tokens_in'] = ['broken'];

        DB::table('video_planning_stages')
            ->where('id', $stage->id)
            ->update(['output_json' => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);

        $this->assertNull($this->reviewView()['usage']);

        $this->get(route('video-projects.scene', $this->project->id))->assertOk();
    }

    /**
     * `roundProvesPass()` chi tu choi dung chu `blocking`, nen mot finding
     * THIEU `severity` se lang le doc thanh advisory va mo cong render.
     */
    public function test_a_finding_without_a_severity_cannot_leave_a_plan_passed(): void
    {
        $this->client->text = $this->plan();
        $this->post($this->url())->assertSessionHas('success');

        $this->rewriteReview(function (array $output): array {
            $finding = $this->finding('shell_closed');
            unset($finding['severity']);

            $output['review']['rounds'][0]['findings'] = [$finding];

            return $output;
        });

        $this->assertSame('unreviewed', $this->reviewView()['status']);
        $this->assertSame('unreadable', $this->reviewView()['reason']);
    }

    public function test_a_severity_outside_the_enum_cannot_be_read_as_advisory(): void
    {
        $this->client->text = $this->plan();
        $this->post($this->url())->assertSessionHas('success');

        $this->rewriteReview(function (array $output): array {
            $output['review']['rounds'][0]['findings'] = [
                $this->finding('shell_closed', 'BLOCKING'),
            ];

            return $output;
        });

        $this->assertSame('unreadable', $this->reviewView()['reason']);

        $this->get(route('video-projects.scene', $this->project->id))
            ->assertOk()
            ->assertDontSee('Đã rà tự động');
    }

    public function test_a_rule_outside_the_enum_is_refused(): void
    {
        $this->client->text = $this->plan();
        $this->post($this->url())->assertSessionHas('success');

        $this->rewriteReview(function (array $output): array {
            $output['review']['rounds'][0]['findings'] = [
                $this->finding('shell_closed', 'advisory', 'not_a_rule'),
            ];

            return $output;
        });

        $this->assertSame('unreadable', $this->reviewView()['reason']);
    }

    public function test_a_finding_without_evidence_is_refused(): void
    {
        $finding = $this->finding('arrangement_laid');
        unset($finding['evidence']);

        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [$finding], [$this->sceneAt(1, ['title' => 'Nope'])]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $this->assertSame(
            'review_response_incoherent',
            $this->scenePlanStages()->sole()->output_json['review']['reason'],
        );
        $this->assertSame('General Arrangement Laid', $this->sceneRowsAt(1)[1]['title']);
    }

    /**
     * Chuoi rong la chuoi con cua moi thu, nen de lot la chot trich dan mat
     * tac dung hoan toan.
     */
    public function test_an_empty_quote_is_refused(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [
                $this->finding('arrangement_laid', 'blocking', 'content', [[
                    'source' => 'scene',
                    'scene_code' => 'arrangement_laid',
                    'field' => 'delta',
                    'quote' => '   ',
                ]]),
            ], [$this->sceneAt(1, ['title' => 'Nope'])]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $this->assertSame(
            'review_response_incoherent',
            $this->scenePlanStages()->sole()->output_json['review']['reason'],
        );
    }

    public function test_a_quote_that_is_not_in_the_named_field_is_refused(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [
                $this->finding('arrangement_laid', 'blocking', 'source_image', [[
                    'source' => 'scene',
                    'scene_code' => 'profile_drawing',
                    'field' => 'delta',
                    'quote' => 'a curved frame hangs on taut crane slings',
                ]]),
            ], [$this->sceneAt(1, ['title' => 'Nope'])]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $this->assertSame(
            'review_response_incoherent',
            $this->scenePlanStages()->sole()->output_json['review']['reason'],
            'a quote nobody wrote must not pass as evidence',
        );
        $this->assertSame('General Arrangement Laid', $this->sceneRowsAt(1)[1]['title']);
    }

    public function test_article_evidence_must_name_no_scene_and_a_real_article_field(): void
    {
        $plan = $this->plan();

        $this->client->text = $plan;
        $this->client->reviewQueue = [
            $this->review('revise', [
                $this->finding('arrangement_laid', 'blocking', 'milestone_basis', [[
                    'source' => 'article',
                    'scene_code' => 'arrangement_laid',
                    'field' => 'title',
                    'quote' => 'TEST',
                ]]),
            ], [$this->sceneAt(1, ['title' => 'Nope'])]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $this->assertSame(
            'review_response_incoherent',
            $this->scenePlanStages()->sole()->output_json['review']['reason'],
            'article evidence must carry an empty scene_code',
        );
    }

    /**
     * `camera_mode` CO THAT tren scene va quote khop — nen chi mot minh chot
     * enum field tu choi duoc no. Dung mot truong khong ton tai thi phep doi
     * chieu quote bat truoc, va chot enum khong duoc kiem.
     */
    public function test_a_scene_field_outside_the_contract_is_refused(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [
                $this->finding('arrangement_laid', 'blocking', 'content', [[
                    'source' => 'scene',
                    'scene_code' => 'arrangement_laid',
                    'field' => 'camera_mode',
                    'quote' => 'locked',
                ]]),
            ], [$this->sceneAt(1, ['title' => 'Nope'])]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $this->assertSame(
            'review_response_incoherent',
            $this->scenePlanStages()->sole()->output_json['review']['reason'],
        );
    }

    /** Ban ghi truoc hop dong v2 khong co `evidence` — trang van phai doc duoc. */
    public function test_findings_recorded_before_the_evidence_contract_still_display(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('requires_replan', [$this->finding('shell_closed')]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $this->rewriteReview(static function (array $output): array {
            unset($output['review']['rounds'][0]['findings'][0]['evidence']);

            return $output;
        });

        $review = $this->reviewView();

        $this->assertSame('requires_replan', $review['reason'], 'an old record must stay readable');
        $this->assertCount(1, $review['open_findings']);
        $this->assertArrayNotHasKey('evidence', $review['open_findings'][0]);

        $this->get(route('video-projects.scene', $this->project->id))
            ->assertOk()
            ->assertSee('shell_closed');
    }

    /**
     * Hard cut GIU `previous_scene_code` (thu tu ke) nhung KHONG co anh nguon.
     * Lan chay that dau tien cho thay reviewer nham hai quan he nay.
     */
    public function test_the_reviewer_is_told_the_scene_order_and_the_source_keyframe_apart(): void
    {
        $this->client->text = $this->plan();

        $this->post($this->url())->assertSessionHas('success');

        $sent = json_decode(
            substr((string) $this->client->reviewUsers[0], strlen('REVIEW INPUT:')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $scenes = $sent['plan']['scenes'];

        $this->assertNull($scenes[0]['previous_scene_code'], 'the first scene has nothing before it');
        $this->assertNull($scenes[0]['source_keyframe_scene_code']);
        $this->assertNull($scenes[0]['previous_clip_action']);

        $this->assertSame('profile_drawing', $scenes[1]['previous_scene_code']);
        $this->assertSame('profile_drawing', $scenes[1]['source_keyframe_scene_code']);
        $this->assertSame(
            'The architect pins a second sheet beside the first.',
            $scenes[1]['previous_clip_action'],
        );

        $this->assertSame('hard_cut_edit', $scenes[2]['transition_mode']);
        $this->assertSame(
            'arrangement_laid',
            $scenes[2]['previous_scene_code'],
            'a hard cut still follows a scene in the story',
        );
        $this->assertNull(
            $scenes[2]['source_keyframe_scene_code'],
            'a hard cut takes no keyframe from the scene before it',
        );

        $this->assertSame(1, $scenes[0]['index']);
        $this->assertSame(11, $scenes[10]['index']);
    }

    /**
     * Toan tu `+` giu ve TRAI, nen mot scene mang san khoa metadata se de bep
     * gia tri backend tinh. Gia tri backend phai LUON thang.
     */
    public function test_metadata_carried_inside_a_scene_never_beats_the_backend(): void
    {
        $this->client->text = $this->planWith(1, [
            'previous_scene_code' => 'bogus_scene',
            'source_keyframe_scene_code' => 'bogus_scene',
            'previous_clip_action' => 'a lie',
            'index' => 99,
        ]);

        $this->post($this->url())->assertSessionHas('success');

        $sent = json_decode(
            substr((string) $this->client->reviewUsers[0], strlen('REVIEW INPUT:')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $scene = $sent['plan']['scenes'][1];

        $this->assertSame('profile_drawing', $scene['previous_scene_code']);
        $this->assertSame('profile_drawing', $scene['source_keyframe_scene_code']);
        $this->assertSame(
            'The architect pins a second sheet beside the first.',
            $scene['previous_clip_action'],
        );
        $this->assertSame(2, $scene['index']);
    }

    public function test_a_quote_shorter_than_the_schema_floor_is_refused(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [
                $this->finding('arrangement_laid', 'blocking', 'content', [[
                    'source' => 'scene',
                    'scene_code' => 'arrangement_laid',
                    'field' => 'delta',
                    'quote' => 'Th',
                ]]),
            ], [$this->sceneAt(1, ['title' => 'Nope'])]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $this->assertSame(
            'review_response_incoherent',
            $this->scenePlanStages()->sole()->output_json['review']['reason'],
        );
    }

    /** Hop dong doi trich NGUYEN VAN, nen doi hoa thuong khong con la trich dan. */
    public function test_a_quote_that_only_matches_in_lower_case_is_refused(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [
                $this->finding('arrangement_laid', 'blocking', 'content', [[
                    'source' => 'scene',
                    'scene_code' => 'arrangement_laid',
                    'field' => 'delta',
                    'quote' => mb_strtolower(mb_substr($this->deltaOf('arrangement_laid'), 0, 40)),
                ]]),
            ], [$this->sceneAt(1, ['title' => 'Nope'])]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $this->assertSame(
            'review_response_incoherent',
            $this->scenePlanStages()->sole()->output_json['review']['reason'],
        );
    }

    /**
     * Luot that: model tra CUNG MOT scene ba lan, ba ban giong het. Gop lai va
     * ap tiep; van chay du validator sau khi gop.
     */
    public function test_identical_duplicate_patch_entries_are_merged(): void
    {
        $patched = $this->sceneAt(1, ['title' => 'Arrangement Flat']);

        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [$this->finding('arrangement_laid')], [$patched, $patched, $patched]),
            $this->review('pass'),
        ];

        $this->post($this->url())->assertSessionHas('success');

        $round = $this->scenePlanStages()->sole()->output_json['review']['rounds'][0];

        $this->assertSame(2, $round['merged_duplicate_patches']);
        $this->assertSame(['arrangement_laid'], $round['patched_codes']);
        $this->assertSame('Arrangement Flat', $this->sceneRowsAt(1)[1]['title']);
    }

    public function test_duplicate_patch_entries_that_disagree_are_refused(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [$this->finding('arrangement_laid')], [
                $this->sceneAt(1, ['title' => 'Arrangement One']),
                $this->sceneAt(1, ['title' => 'Arrangement Two']),
            ]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $this->assertSame(
            'review_response_incoherent',
            $this->scenePlanStages()->sole()->output_json['review']['reason'],
            'the same scene given two different bodies is a contradiction, not a repeat',
        );
        $this->assertSame('General Arrangement Laid', $this->sceneRowsAt(1)[1]['title']);
    }

    /** Gop trung KHONG lam ban va hop le: validator van phai chay sau do. */
    public function test_a_merged_patch_still_has_to_pass_the_validator(): void
    {
        $broken = $this->sceneAt(1, ['milestone_keys' => ['launched']]);

        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [$this->finding('arrangement_laid')], [$broken, $broken]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $round = $this->scenePlanStages()->sole()->output_json['review']['rounds'][0];

        $this->assertSame(1, $round['merged_duplicate_patches']);
        $this->assertSame(
            'patch_invalid',
            $this->scenePlanStages()->sole()->output_json['review']['reason'],
        );
    }

    /** Sap khoa object: cung noi dung, khac thu tu khoa -> van la mot ban. */
    public function test_duplicate_patches_differing_only_in_key_order_are_merged(): void
    {
        $patched = $this->sceneAt(6, ['title' => 'Deck And House']);
        $reordered = array_reverse($patched, true);

        $this->assertNotSame(
            array_keys($patched),
            array_keys($reordered),
            'the fixture must actually reorder the keys',
        );

        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [$this->finding('deck_and_house')], [$patched, $reordered]),
            $this->review('pass'),
        ];

        $this->post($this->url())->assertSessionHas('success');

        $round = $this->scenePlanStages()->sole()->output_json['review']['rounds'][0];

        $this->assertSame(1, $round['merged_duplicate_patches']);
        $this->assertSame(['deck_and_house'], $round['patched_codes']);
        $this->assertSame('Deck And House', $this->sceneRowsAt(1)[6]['title']);
    }

    /** Thu tu phan tu array MANG NGHIA — dao lai la noi dung khac, khong gop. */
    public function test_duplicate_patches_differing_in_array_order_are_refused(): void
    {
        $first = $this->sceneAt(6, ['title' => 'Deck And House']);
        $second = $first;
        $second['milestone_keys'] = array_reverse($second['milestone_keys']);

        $this->assertCount(2, $first['milestone_keys'], 'the fixture scene needs two milestones');

        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [$this->finding('deck_and_house')], [$first, $second]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $this->assertSame(
            'review_response_incoherent',
            $this->scenePlanStages()->sole()->output_json['review']['reason'],
        );
        $this->assertSame('Deck And Superstructure', $this->sceneRowsAt(1)[6]['title']);
    }

    public function test_a_group_that_reopens_after_it_closed_is_refused(): void
    {
        $plan = json_decode($this->plan(), true, 512, JSON_THROW_ON_ERROR);
        $plan['scenes'][9]['continuity_group'] = $plan['scenes'][0]['continuity_group'];

        $this->client->text = json_encode($plan, JSON_THROW_ON_ERROR);

        $this->post($this->url())->assertSessionHas('error');

        $this->assertStringContainsString(
            'reopens after it closed',
            (string) $this->scenePlanStages()->sole()->error_message,
        );
    }

    public function test_a_scene_opening_a_group_must_be_a_hard_cut(): void
    {
        $plan = json_decode($this->plan(), true, 512, JSON_THROW_ON_ERROR);
        $plan['scenes'][2]['transition_mode'] = ScenePreservationPrompt::CONTINUATION;

        $this->client->text = json_encode($plan, JSON_THROW_ON_ERROR);

        $this->post($this->url())->assertSessionHas('error');

        $this->assertStringContainsString(
            'opens a group must be a hard cut',
            (string) $this->scenePlanStages()->sole()->error_message,
        );
    }

    public function test_a_continuing_scene_must_name_the_scene_before_it(): void
    {
        $plan = json_decode($this->plan(), true, 512, JSON_THROW_ON_ERROR);
        $plan['scenes'][4]['source_scene_code'] = 'berth_ready';

        $this->client->text = json_encode($plan, JSON_THROW_ON_ERROR);

        $this->post($this->url())->assertSessionHas('error');

        $this->assertStringContainsString(
            'source_scene_code must be the scene before it',
            (string) $this->scenePlanStages()->sole()->error_message,
        );
    }

    public function test_a_continuing_scene_may_not_change_the_viewpoint(): void
    {
        $plan = json_decode($this->plan(), true, 512, JSON_THROW_ON_ERROR);
        $plan['scenes'][4]['camera_change_reason'] = 'Swing round to the bow.';

        $this->client->text = json_encode($plan, JSON_THROW_ON_ERROR);

        $this->post($this->url())->assertSessionHas('error');

        $this->assertStringContainsString(
            'does not change the viewpoint',
            (string) $this->scenePlanStages()->sole()->error_message,
        );
    }

    /**
     * Scene dau khong co "doi goc", nhung van phai noi vi sao chon goc mo dau
     * — dung o truong do thay vi them mot field nua.
     */
    public function test_a_scene_opening_a_group_must_say_why_this_viewpoint(): void
    {
        $plan = json_decode($this->plan(), true, 512, JSON_THROW_ON_ERROR);
        $plan['scenes'][0]['camera_change_reason'] = '';

        $this->client->text = json_encode($plan, JSON_THROW_ON_ERROR);

        $this->post($this->url())->assertSessionHas('error');

        $this->assertStringContainsString(
            'must say why this viewpoint',
            (string) $this->scenePlanStages()->sole()->error_message,
        );
    }

    public function test_an_opening_scene_takes_no_source(): void
    {
        $plan = json_decode($this->plan(), true, 512, JSON_THROW_ON_ERROR);
        $plan['scenes'][2]['source_scene_code'] = 'arrangement_laid';

        $this->client->text = json_encode($plan, JSON_THROW_ON_ERROR);

        $this->post($this->url())->assertSessionHas('error');

        $this->assertStringContainsString(
            'takes no source_scene_code',
            (string) $this->scenePlanStages()->sole()->error_message,
        );
    }

    public function test_the_group_contract_reaches_the_database_and_the_reviewer(): void
    {
        $this->client->text = $this->plan();

        $this->post($this->url())->assertSessionHas('success');

        $rows = $this->sceneRowsAt(1);

        $this->assertSame('g_profile_drawing', $rows[0]['continuity_group']);
        $this->assertNull($rows[0]['source_scene_code']);
        $this->assertNotNull($rows[0]['camera_change_reason']);

        $this->assertSame('g_profile_drawing', $rows[1]['continuity_group']);
        $this->assertSame('profile_drawing', $rows[1]['source_scene_code']);
        $this->assertNull($rows[1]['camera_change_reason']);

        $this->assertSame('g_berth_ready', $rows[2]['continuity_group']);

        $sent = json_decode(
            substr((string) $this->client->reviewUsers[0], strlen('REVIEW INPUT:')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertNull($sent['plan']['scenes'][0]['source_keyframe_scene_code']);
        $this->assertSame('profile_drawing', $sent['plan']['scenes'][1]['source_keyframe_scene_code']);
    }

    public function test_the_scene_contract_version_is_part_of_the_dedup_key(): void
    {
        $this->client->text = $this->plan();
        $this->post($this->url())->assertSessionHas('success');

        $stage = $this->scenePlanStages()->sole();

        $this->assertSame(
            ScenePlanAuthor::SCENE_CONTRACT_VERSION,
            $stage->input_json['scene_contract_version'],
        );

        $withoutVersion = $stage->input_json;
        unset($withoutVersion['scene_contract_version'], $withoutVersion['_meta']);

        $this->assertNotSame(
            hash('sha256', json_encode(
                $withoutVersion,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )),
            $stage->input_hash,
            'the contract version must move the dedup key, not just sit in the record',
        );
    }

    /**
     * Doi nhom cua mot scene lam nguon/mode cua scene KE TIEP sai — ban va
     * phai bi tu choi ca cum, khong ap nua vo.
     */
    public function test_a_patch_that_breaks_the_next_scenes_source_is_refused(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [$this->finding('bottom_set')], [
                $this->sceneAt(3, [
                    'continuity_group' => 'g_bottom_set',
                    'transition_mode' => ScenePreservationPrompt::HARD_CUT,
                    'source_scene_code' => '',
                    'camera_change_reason' => 'Move round to see the keel line.',
                ]),
            ]),
        ];

        $this->post($this->url())->assertSessionHas('warning');

        $review = $this->scenePlanStages()->sole()->output_json['review'];

        $this->assertSame('patch_invalid', $review['reason']);
        $this->assertStringContainsString(
            'reopens after it closed',
            $review['rounds'][0]['patch_error']['text'],
            'moving one scene out of its group orphans the run that followed it',
        );
        $this->assertSame('continuation_edit', $this->sceneRowsAt(1)[4]['transition_mode']);
    }

    /** Sua dong bo ca cum thi duoc nhan. */
    public function test_a_patch_that_moves_a_group_boundary_consistently_is_accepted(): void
    {
        $this->client->text = $this->plan();
        $this->client->reviewQueue = [
            $this->review('revise', [$this->finding('bottom_set')], [
                $this->sceneAt(3, [
                    'continuity_group' => 'g_bottom_set',
                    'transition_mode' => ScenePreservationPrompt::HARD_CUT,
                    'source_scene_code' => '',
                    'camera_change_reason' => 'Move round to see the keel line.',
                ]),
                $this->sceneAt(4, ['continuity_group' => 'g_bottom_set']),
                $this->sceneAt(5, ['continuity_group' => 'g_bottom_set']),
                $this->sceneAt(6, ['continuity_group' => 'g_bottom_set']),
                $this->sceneAt(7, ['continuity_group' => 'g_bottom_set']),
                $this->sceneAt(8, ['continuity_group' => 'g_bottom_set']),
            ]),
            $this->review('pass'),
        ];

        $this->post($this->url())->assertSessionHas('success');

        $rows = $this->sceneRowsAt(1);

        $this->assertSame('g_bottom_set', $rows[3]['continuity_group']);
        $this->assertSame('hard_cut_edit', $rows[3]['transition_mode']);
        $this->assertNull($rows[3]['source_scene_code']);
        $this->assertSame('g_bottom_set', $rows[8]['continuity_group']);
        $this->assertSame('surfaces_done', $this->sceneRowsAt(1)[8]['scene_code']);

        $sent = json_decode(
            substr((string) $this->client->reviewUsers[1], strlen('REVIEW INPUT:')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertNull(
            $sent['plan']['scenes'][3]['source_keyframe_scene_code'],
            'the second round must be told the patched source, not the old one',
        );
        $this->assertSame('bottom_set', $sent['plan']['scenes'][4]['source_keyframe_scene_code']);
    }

    /** Revision cu khong co ba cot: trang phai noi "chua co nhom canh". */
    public function test_a_revision_without_group_metadata_is_shown_as_having_none(): void
    {
        $this->client->text = $this->plan();
        $this->post($this->url())->assertSessionHas('success');

        DB::table('video_render_scenes')
            ->where('project_id', $this->project->id)
            ->update([
                'continuity_group' => null,
                'source_scene_code' => null,
                'camera_change_reason' => null,
            ]);

        $scenes = $this->sceneViews();

        $this->assertNull($scenes[0]['continuity_group']);
        $this->assertNull($scenes[0]['source_scene_code']);

        $this->get(route('video-projects.scene', $this->project->id))
            ->assertOk()
            ->assertSee('chưa có nhóm cảnh');
    }

    /** @return array<string, mixed> */
    private function reviewView(): array
    {
        return $this->get(route('video-projects.scene', $this->project->id))->viewData('review');
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutate */
    private function rewriteReview(callable $mutate): void
    {
        $stage = $this->scenePlanStages()->sole();

        DB::table('video_planning_stages')
            ->where('id', $stage->id)
            ->update(['output_json' => json_encode(
                $mutate($stage->output_json),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )]);
    }

    public function test_a_hard_cut_preview_takes_the_anchor_the_plan_was_written_against(): void
    {
        $this->planOnce();

        [$preview, $reason] = $this->previewOf($this->sceneRow(1));

        $this->assertSame('ok', $reason);
        $this->assertSame('1152x2048', $preview['size']);
        $this->assertSame('low', $preview['quality']);
        $this->assertNull($preview['cost_estimate']);
        $this->assertCount(2, $preview['sources']);
        $this->assertSame('anchor', $preview['sources'][0]['role']);
        $this->assertSame(0, $preview['sources'][0]['position']);
        $this->assertSame('current', $preview['sources'][0]['state']);
        $this->assertSame('environment', $preview['sources'][1]['role']);
        $this->assertSame(1, $preview['sources'][1]['position']);
        $this->assertSame(
            (string) $this->approvedAnchorRow()->selected_artifact_id,
            $preview['sources'][0]['artifact_id'],
        );
    }

    public function test_a_continuation_preview_waits_for_the_keyframe_it_continues_from(): void
    {
        $this->planOnce();

        [$preview, $reason] = $this->previewOf($this->sceneRow(2));

        $this->assertNull($preview);
        $this->assertSame('source_keyframe_not_approved', $reason);
    }

    public function test_a_continuation_preview_takes_the_approved_keyframe_of_its_source_scene(): void
    {
        $this->planOnce();
        $artifact = $this->approvedKeyframeFor($this->sceneRow(1));

        [$preview, $reason] = $this->previewOf($this->sceneRow(2));

        $this->assertSame('ok', $reason);
        $this->assertSame('source_keyframe', $preview['sources'][0]['role']);
        $this->assertSame((string) $artifact->id, $preview['sources'][0]['artifact_id']);
        $this->assertNull($preview['anchor_confirm_artifact_id']);
    }

    public function test_a_stale_preview_hash_never_reaches_the_provider(): void
    {
        Http::fake();
        $this->planOnce();
        $scene = $this->sceneRow(1);
        [$preview] = $this->previewOf($scene);

        [$image, $reason] = $this->renderScene($scene, str_repeat('0', 64), $preview['anchor_confirm_artifact_id']);

        $this->assertNull($image);
        $this->assertSame('preview_stale', $reason);
        $this->assertSame(0, $this->keyframeCount());
        Http::assertNothingSent();
    }

    public function test_writing_the_first_keyframe_demands_the_anchor_be_confirmed(): void
    {
        Http::fake();
        $this->planOnce();
        $scene = $this->sceneRow(1);
        [$preview] = $this->previewOf($scene);

        [$image, $reason] = $this->renderScene($scene, $preview['prompt_sha256'], null);

        $this->assertNull($image);
        $this->assertSame('anchor_not_confirmed', $reason);
        $this->assertSame(0, $this->keyframeCount());
        Http::assertNothingSent();
    }

    public function test_confirming_the_wrong_anchor_is_refused(): void
    {
        Http::fake();
        $this->planOnce();
        $scene = $this->sceneRow(1);
        [$preview] = $this->previewOf($scene);

        [$image, $reason] = $this->renderScene(
            $scene, $preview['prompt_sha256'], (string) Str::uuid(),
        );

        $this->assertNull($image);
        $this->assertSame('anchor_confirmation_stale', $reason);
        $this->assertSame(0, $this->keyframeCount());
        Http::assertNothingSent();
    }

    public function test_an_anchor_approved_after_planning_cannot_be_rendered_against(): void
    {
        Http::fake();
        $this->planOnce();
        $scene = $this->sceneRow(1);
        [$preview] = $this->previewOf($scene);

        $this->approveAnotherAnchor();

        [$image, $reason] = $this->renderScene(
            $scene, $preview['prompt_sha256'], $preview['anchor_confirm_artifact_id'],
        );

        $this->assertNull($image);
        $this->assertSame('anchor_changed_since_planning', $reason);
        $this->assertSame(0, $this->keyframeCount());
        Http::assertNothingSent();
    }

    public function test_the_first_keyframe_goes_out_on_the_single_image_field_and_lands_in_the_ledger(): void
    {
        Http::fake(['*' => Http::response([
            'created' => 1, 'data' => [['b64_json' => self::PNG_3X5]],
        ], 200)]);

        $this->planOnce();
        $scene = $this->sceneRow(1);
        [$preview] = $this->previewOf($scene);

        [$image, $reason] = $this->renderScene(
            $scene, $preview['prompt_sha256'], $preview['anchor_confirm_artifact_id'],
        );

        $this->assertSame('rendered', $reason, (string) $image?->render_error);
        $this->assertSame((string) $scene->id, (string) $image->render_scene_id);
        $this->assertSame(DesignImageStore::SCENE_KEYFRAME_TYPE, $image->image_type);
        $this->assertSame($preview['prompt_sha256'], (string) $image->prompt_sha256);
        $this->assertSame(1, $image->artifacts()->count());

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($preview): bool {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://api.openai.com/v1/images/edits', $request->url());

            $files = array_values(array_filter(
                $request->data(),
                static fn (array $part): bool => isset($part['filename']),
            ));
            $fields = collect($request->data())
                ->filter(static fn (array $part): bool => ! isset($part['filename']))
                ->mapWithKeys(static fn (array $part): array => [$part['name'] => $part['contents']]);

            $this->assertCount(2, $files, 'anchor plus the approved environment plate');
            $this->assertSame(
                ['image[]', 'image[]'],
                array_column($files, 'name'),
                'order is the contract: the prompt names each image by index',
            );
            $this->assertSame(
                ['source_00.png', 'source_01.png'],
                array_column($files, 'filename'),
            );
            $this->assertSame('anchor-bytes', $files[0]['contents']);
            $this->assertSame('plate-bytes-design_studio', $files[1]['contents']);
            $this->assertSame($preview['prompt'], $fields['prompt']);
            $this->assertSame('1152x2048', $fields['size']);
            $this->assertSame('low', $fields['quality']);

            return true;
        });

        $entry = VideoCostEntry::query()->where('entity_id', $image->id)->sole();

        $this->assertSame(0.0, (float) $entry->cost_usd);
        $this->assertSame('unpriced', $entry->metadata_json['pricing']);
    }

    public function test_pressing_render_twice_on_one_preview_pays_once(): void
    {
        Http::fake(['*' => Http::response([
            'created' => 1, 'data' => [['b64_json' => self::PNG_3X5]],
        ], 200)]);

        $this->planOnce();
        $scene = $this->sceneRow(1);
        [$preview] = $this->previewOf($scene);

        [$first, $firstReason] = $this->renderScene(
            $scene, $preview['prompt_sha256'], $preview['anchor_confirm_artifact_id'],
        );
        [$second, $secondReason] = $this->renderScene(
            $scene, $preview['prompt_sha256'], $preview['anchor_confirm_artifact_id'],
        );

        $this->assertSame('rendered', $firstReason);
        $this->assertSame('already_exists', $secondReason);
        $this->assertSame((string) $first->id, (string) $second->id);
        $this->assertSame(1, $this->keyframeCount());
        $this->assertSame(1, VideoCostEntry::query()->where('entity_id', $first->id)->count());
        Http::assertSentCount(1);
    }

    public function test_a_later_hard_cut_keeps_the_anchor_the_first_one_froze(): void
    {
        Http::fake(['*' => Http::response([
            'created' => 1, 'data' => [['b64_json' => self::PNG_3X5]],
        ], 200)]);

        $this->planOnce();
        $first = $this->sceneRow(1);
        [$preview] = $this->previewOf($first);
        $locked = $preview['anchor_confirm_artifact_id'];

        [, $reason] = $this->renderScene($first, $preview['prompt_sha256'], $locked);
        $this->assertSame('rendered', $reason);

        $this->approveAnotherAnchor();

        $later = VideoRenderScene::query()
            ->where('project_id', $this->project->id)
            ->where('revision', $first->revision)
            ->where('transition_mode', ScenePreservationPrompt::HARD_CUT)
            ->where('scene_index', '>', $first->scene_index)
            ->orderBy('scene_index')
            ->firstOrFail();

        [$laterPreview, $laterReason] = $this->previewOf($later);

        $this->assertSame('ok', $laterReason);
        $this->assertSame($locked, $laterPreview['sources'][0]['artifact_id']);
        $this->assertSame('changed', $laterPreview['sources'][0]['state']);

        [$laterImage, $laterRender] = $this->renderScene(
            $later, $laterPreview['prompt_sha256'], $laterPreview['anchor_confirm_artifact_id'],
        );

        $this->assertSame('rendered', $laterRender, (string) $laterImage?->render_error);
        $this->assertSame($locked, $laterImage->prompt_spec_json['sources'][0]['artifact_id']);

        $this->assertSame(
            ['anchor-bytes', 'anchor-bytes'],
            array_map(fn (array $pair) => $this->firstFileOf($pair[0]), Http::recorded()->all()),
            'both hard cuts must ride the anchor the first one froze',
        );
    }

    public function test_an_unreadable_anchor_lock_never_falls_back_to_the_current_anchor(): void
    {
        Http::fake(['*' => Http::response([
            'created' => 1, 'data' => [['b64_json' => self::PNG_3X5]],
        ], 200)]);

        $this->planOnce();
        $first = $this->sceneRow(1);
        [$preview] = $this->previewOf($first);

        [$candidate, $reason] = $this->renderScene(
            $first, $preview['prompt_sha256'], $preview['anchor_confirm_artifact_id'],
        );
        $this->assertSame('rendered', $reason);

        $spec = $candidate->prompt_spec_json;
        $spec['sources'][0]['position'] = 2;

        DB::table('video_design_images')->where('id', $candidate->id)->update([
            'prompt_spec_json' => json_encode($spec, JSON_THROW_ON_ERROR),
        ]);

        [$later, $laterReason] = $this->previewOf($this->sceneRow(1));

        $this->assertNull($later);
        $this->assertSame('anchor_lock_unreadable', $laterReason);
    }

    public function test_a_missing_source_file_is_named_before_the_provider_is_called(): void
    {
        Http::fake();
        $this->planOnce();
        $scene = $this->sceneRow(1);
        [$preview] = $this->previewOf($scene);

        $artifact = VideoArtifact::query()
            ->whereKey($preview['sources'][0]['artifact_id'])
            ->firstOrFail();
        Storage::disk('video_artifacts')->delete((string) $artifact->storage_path);

        [$image, $reason] = $this->renderScene(
            $scene, $preview['prompt_sha256'], $preview['anchor_confirm_artifact_id'],
        );

        $this->assertNull($image);
        $this->assertSame('artifact_file_not_found', $reason);
        $this->assertSame(0, $this->keyframeCount());
        Http::assertNothingSent();
    }

    public function test_a_source_file_rewritten_behind_its_checksum_is_refused(): void
    {
        Http::fake();
        $this->planOnce();
        $scene = $this->sceneRow(1);
        [$preview] = $this->previewOf($scene);

        $artifact = VideoArtifact::query()
            ->whereKey($preview['sources'][0]['artifact_id'])
            ->firstOrFail();
        Storage::disk('video_artifacts')->put((string) $artifact->storage_path, 'swapped-bytes');

        [$image, $reason] = $this->renderScene(
            $scene, $preview['prompt_sha256'], $preview['anchor_confirm_artifact_id'],
        );

        $this->assertNull($image);
        $this->assertSame('artifact_checksum_mismatch', $reason);
        $this->assertSame(0, $this->keyframeCount());
        Http::assertNothingSent();
    }

    public function test_a_retry_still_runs_when_its_source_has_since_been_superseded(): void
    {
        Http::fakeSequence()
            ->push(['error' => ['message' => 'provider said no']], 400)
            ->push(['created' => 1, 'data' => [['b64_json' => self::PNG_3X5]]], 200);

        $this->planOnce();
        $scene = $this->sceneRow(1);
        [$preview] = $this->previewOf($scene);

        [$candidate, $reason] = $this->renderScene(
            $scene, $preview['prompt_sha256'], $preview['anchor_confirm_artifact_id'],
        );

        $this->assertSame('failed', $reason);

        $snapshotBefore = $candidate->prompt_spec_json;
        $this->approveAnotherAnchor();

        [$resumed, $resumeReason] = app(VideoProjectService::class)->resumeSceneCandidate(
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $candidate->id,
            (string) $candidate->prompt_sha256,
        );

        $this->assertSame('rendered', $resumeReason, (string) $resumed?->render_error);
        $this->assertSame((string) $candidate->id, (string) $resumed->id);
        $this->assertSame($snapshotBefore, $resumed->prompt_spec_json);

        Http::assertSentCount(2);
        $this->assertSame(
            ['anchor-bytes', 'anchor-bytes'],
            array_map(fn (array $pair) => $this->firstFileOf($pair[0]), Http::recorded()->all()),
            'the retry must resend the source the snapshot froze, not the anchor approved since',
        );
    }

    public function test_a_retry_whose_source_left_its_candidate_never_reaches_the_provider(): void
    {
        Http::fakeSequence()->push(['error' => ['message' => 'provider said no']], 400);

        $this->planOnce();
        $scene = $this->sceneRow(1);
        [$preview] = $this->previewOf($scene);

        [$candidate] = $this->renderScene(
            $scene, $preview['prompt_sha256'], $preview['anchor_confirm_artifact_id'],
        );

        DB::table('video_artifacts')
            ->where('id', $preview['sources'][0]['artifact_id'])
            ->update(['design_image_id' => null]);

        [$resumed, $reason] = app(VideoProjectService::class)->resumeSceneCandidate(
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $candidate->id,
            (string) $candidate->prompt_sha256,
        );

        $this->assertNull($resumed);
        $this->assertSame('source_artifact_not_in_candidate', $reason);
        Http::assertSentCount(1);
    }

    public function test_the_renderer_refuses_bytes_that_no_longer_match_the_snapshot(): void
    {
        Http::fake();
        [$candidate, $preview] = $this->writtenCandidate();

        $artifact = VideoArtifact::query()
            ->whereKey($preview['sources'][0]['artifact_id'])
            ->firstOrFail();
        Storage::disk('video_artifacts')->put((string) $artifact->storage_path, 'swapped-after-preflight');

        [$done, $renderReason] = app(DesignImageDirectRenderer::class)
            ->renderNow((string) $candidate->id, [DesignImageStatus::CANDIDATE->value]);

        $this->assertSame('failed', $renderReason);
        $this->assertSame(DesignImageStatus::FAILED->value, $done->status);
        $this->assertStringContainsString('checksum', (string) $done->render_error);
        $this->assertSame(0, $done->artifacts()->count());
        Http::assertNothingSent();
    }

    public function test_the_renderer_refuses_a_snapshot_past_the_source_cap(): void
    {
        Http::fake();
        [$candidate] = $this->writtenCandidate();

        $spec = $candidate->prompt_spec_json;

        for ($position = count($spec['sources']); $position < 6; $position++) {
            $spec['sources'][] = array_replace($spec['sources'][0], ['position' => $position]);
        }

        DB::table('video_design_images')->where('id', $candidate->id)->update([
            'prompt_spec_json' => json_encode($spec, JSON_THROW_ON_ERROR),
        ]);

        [$done, $reason] = app(DesignImageDirectRenderer::class)
            ->renderNow((string) $candidate->id, [DesignImageStatus::CANDIDATE->value]);

        $this->assertSame('failed', $reason);
        $this->assertStringContainsString('source cap', (string) $done->render_error);
        $this->assertSame(0, $done->artifacts()->count());
        Http::assertNothingSent();
    }

    public function test_the_renderer_refuses_a_source_that_left_its_candidate(): void
    {
        Http::fake();
        [$candidate, $preview] = $this->writtenCandidate();

        DB::table('video_artifacts')
            ->where('id', $preview['sources'][0]['artifact_id'])
            ->update(['design_image_id' => null]);

        [$done, $renderReason] = app(DesignImageDirectRenderer::class)
            ->renderNow((string) $candidate->id, [DesignImageStatus::CANDIDATE->value]);

        $this->assertSame('failed', $renderReason);
        $this->assertStringContainsString('no longer belongs', (string) $done->render_error);
        $this->assertSame(0, $done->artifacts()->count());
        Http::assertNothingSent();
    }

    public function test_the_cell_map_is_empty_before_anything_is_rendered(): void
    {
        $scene = $this->planOnce();

        $this->assertSame([], app(VideoProjectService::class)->sceneKeyframeCells(
            (string) $this->project->id, (int) $scene->revision,
        ));
    }

    public function test_the_cell_map_keeps_the_approved_apart_from_the_candidate(): void
    {
        $this->fakeImageProvider();
        $this->planOnce();

        $approved = $this->renderedKeyframeFor($this->sceneRow(1));
        app(VideoProjectService::class)->approveSceneKeyframe(
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $approved->id,
            (string) $approved->artifacts()->sole()->id,
        );

        $pending = $this->renderedKeyframeFor($this->hardCutAfter($this->sceneRow(1)));

        $cells = app(VideoProjectService::class)->sceneKeyframeCells(
            (string) $this->project->id, (int) $this->sceneRow(1)->revision,
        );

        $this->assertSame(
            (string) $approved->id,
            $cells[(string) $approved->render_scene_id]['approved']['id'],
        );
        $this->assertNull($cells[(string) $approved->render_scene_id]['candidate']);
        $this->assertSame(
            (string) $pending->id,
            $cells[(string) $pending->render_scene_id]['candidate']['id'],
        );
        $this->assertNull($cells[(string) $pending->render_scene_id]['approved']);
    }

    /** @return iterable<string, array{0: DesignImageStatus, 1: bool, 2: bool}> */
    public static function cellSlotProvider(): iterable
    {
        yield 'candidate' => [DesignImageStatus::CANDIDATE, true, true];
        yield 'failed' => [DesignImageStatus::FAILED, true, true];
        yield 'rendering' => [DesignImageStatus::RENDERING, true, false];
        yield 'rendered' => [DesignImageStatus::RENDERED, true, false];
        yield 'superseded' => [DesignImageStatus::SUPERSEDED, false, false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cellSlotProvider')]
    public function test_only_a_current_status_fills_the_candidate_slot(
        DesignImageStatus $status,
        bool $present,
        bool $resumable,
    ): void {
        Http::fake();
        [$candidate] = $this->writtenCandidate();

        DB::table('video_design_images')->where('id', $candidate->id)->update([
            'status' => $status->value,
        ]);

        $cells = app(VideoProjectService::class)->sceneKeyframeCells(
            (string) $this->project->id, (int) $this->sceneRow(1)->revision,
        );
        $cell = $cells[(string) $candidate->render_scene_id]['candidate'] ?? null;

        $this->assertSame($present, $cell !== null);

        if ($present) {
            $this->assertSame($resumable, $cell['resumable']);
        }
    }





    public function test_a_snapshot_naming_two_images_for_one_role_is_refused(): void
    {
        Http::fake();
        $this->planOnce();
        $this->approvedReference('port_side', 'port-bytes');
        $this->approvedReference('bow_front', 'bow-bytes');

        $scene = $this->sceneRow(1);
        [$preview] = $this->previewOf($scene);
        $candidate = $this->writeCandidate($scene, $preview);
        $spec = $candidate->prompt_spec_json;

        $this->assertCount(4, $spec['sources'], 'this case needs a manifest with three references');

        $spec['sources'][2] = array_replace($spec['sources'][2], [
            'role' => $spec['sources'][1]['role'],
        ]);

        DB::table('video_design_images')->where('id', $candidate->id)->update([
            'prompt_spec_json' => json_encode($spec, JSON_THROW_ON_ERROR),
        ]);

        [$shown, $reason] = app(VideoProjectService::class)->previewFromCandidate(
            (string) $this->project->id, (string) $this->owner->id, (string) $candidate->id,
        );

        $this->assertNull($shown);
        $this->assertSame('candidate_snapshot_duplicated_role', $reason);
        Http::assertNothingSent();
    }

    public function test_a_hard_cut_manifest_is_anchor_then_typed_references(): void
    {
        $scene = $this->planOnce();
        $this->approvedReference('port_side', 'port-bytes');
        $this->approvedReference('bow_front', 'bow-bytes');

        $cell = app(VideoProjectService::class)->sceneSourceCells(
            (string) $this->project->id, (int) $scene->revision,
        )[(string) $scene->id];

        $this->assertSame(
            ['anchor', 'identity', 'environment', 'geometry'],
            array_column($cell['slots'], 'role'),
        );
        $this->assertSame([0, 1, 2, 3], array_column($cell['slots'], 'position'));
        $this->assertTrue($cell['slots'][0]['primary']);
        $this->assertFalse($cell['slots'][1]['primary']);
        $this->assertSame('Ảnh neo', $cell['slots'][0]['title']);
        $this->assertSame('Port Side', $cell['slots'][1]['title']);
        $this->assertSame('Design studio', $cell['slots'][2]['title']);
    }

    public function test_a_continuation_manifest_puts_the_anchor_second(): void
    {
        $scene = $this->planOnce();
        $this->approvedReference('port_side', 'port-bytes');
        $this->approvedKeyframeFor($this->sceneRow(1));

        $cell = app(VideoProjectService::class)->sceneSourceCells(
            (string) $this->project->id, (int) $scene->revision,
        )[(string) $this->sceneRow(2)->id];

        $this->assertSame(
            ['source_keyframe', 'identity', 'environment', 'geometry'],
            array_column($cell['slots'], 'role'),
        );
        $this->assertStringContainsString(
            (string) $this->sceneRow(1)->scene_code,
            $cell['slots'][0]['title'],
        );
        $this->assertSame('Ảnh neo', $cell['slots'][1]['title']);
    }

    public function test_no_manifest_repeats_an_artifact_or_a_role(): void
    {
        $scene = $this->planOnce();
        $this->approvedReference('port_side', 'port-bytes');
        $this->approvedReference('bow_front', 'bow-bytes');
        $this->approvedReference('stern_rear', 'stern-bytes');
        $this->approvedReference('top_deck', 'deck-bytes');

        foreach (app(VideoProjectService::class)->sceneSourceCells(
            (string) $this->project->id, (int) $scene->revision,
        ) as $cell) {
            $roles = array_column($cell['slots'], 'role');
            $shas = array_column($cell['slots'], 'sha');

            $this->assertLessThanOrEqual(VideoProjectService::SCENE_MAX_SOURCE_IMAGES, count($roles));
            $this->assertSame($roles, array_values(array_unique($roles)));
            $this->assertSame($shas, array_values(array_unique($shas)));
        }
    }

    public function test_an_unapproved_plate_blocks_the_scene_and_names_the_place(): void
    {
        $scene = $this->planOnce();
        $this->approvedReference('port_side', 'port-bytes');
        $this->dropPlates();

        $cell = $this->cellFor($scene, $scene->id);

        $this->assertSame([], $cell['slots']);
        $this->assertSame('environment_plate_missing|Design studio', $cell['blocked_reason']);
    }

    public function test_a_scene_spanning_two_places_blocks_instead_of_guessing(): void
    {
        $scene = $this->planOnce();
        $this->sceneRow(1)->forceFill(['milestone_keys' => ['launched', 'sea_trial']])->save();

        $cell = $this->cellFor($scene, $this->sceneRow(1)->id);

        $this->assertSame([], $cell['slots']);
        $this->assertSame('environment_ambiguous', $cell['blocked_reason']);
    }

    public function test_a_milestone_the_environment_profile_never_heard_of_blocks(): void
    {
        $scene = $this->planOnce();
        $this->sceneRow(1)->forceFill(['milestone_keys' => ['nothing_like_this']])->save();

        $this->assertSame(
            'environment_unknown_milestone',
            $this->cellFor($scene, $this->sceneRow(1)->id)['blocked_reason'],
        );
    }

    public function test_a_category_without_an_environment_library_blocks_every_scene(): void
    {
        $scene = $this->planOnce();

        config(['video.environment.profiles' => []]);

        $this->assertSame(
            'environment_no_profile',
            $this->cellFor($scene, $scene->id)['blocked_reason'],
        );
    }

    public function test_a_plate_of_the_wrong_place_does_not_satisfy_a_scene(): void
    {
        $scene = $this->planOnce();
        $this->dropPlates();
        $this->approvedPlate('open_water');

        $this->assertSame(
            'environment_plate_missing|Design studio',
            $this->cellFor($scene, $scene->id)['blocked_reason'],
        );
    }

    public function test_a_plate_that_is_only_rendered_is_not_good_enough(): void
    {
        $scene = $this->planOnce();
        $this->dropPlates();

        $artifact = $this->approvedPlate('design_studio');

        VideoDesignImage::query()
            ->whereKey($artifact->design_image_id)
            ->update(['status' => DesignImageStatus::RENDERED->value]);

        $this->assertSame(
            'environment_plate_missing|Design studio',
            $this->cellFor($scene, $scene->id)['blocked_reason'],
        );
    }

    /** @return array<string, mixed> */
    private function cellFor(VideoRenderScene $scene, string $sceneId): array
    {
        return app(VideoProjectService::class)->sceneSourceCells(
            (string) $this->project->id, (int) $scene->revision,
        )[$sceneId];
    }

    private function dropPlates(): void
    {
        VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', DesignImageStore::ENVIRONMENT_TYPE)
            ->delete();
    }

    public function test_the_prompt_names_every_image_by_index(): void
    {
        $scene = $this->planOnce();
        $this->approvedReference('port_side', 'port-bytes');

        $cell = app(VideoProjectService::class)->sceneSourceCells(
            (string) $this->project->id, (int) $scene->revision,
        )[(string) $scene->id];

        $this->assertStringContainsString('IMAGE 1 is the editable primary source', $cell['prompt']);
        $this->assertStringContainsString('IMAGE 2 is an identity reference only', $cell['prompt']);
        $this->assertStringContainsString('Only IMAGE 1 is edited', $cell['prompt']);
    }

    public function test_the_reference_column_numbers_the_images_it_will_send(): void
    {
        $this->planOnce();
        $this->approvedReference('port_side', 'port-bytes');

        $html = $this->get(route('video-projects.scene', $this->project->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('vs-slot filled sent', $html);
        $this->assertStringContainsString('Ảnh neo', $html);
        $this->assertStringContainsString('Port Side', $html);
        $this->assertStringContainsString('SỬA', $html);
        $this->assertStringContainsString('Environment', $html);
    }

    public function test_the_grid_offers_a_render_button_carrying_both_urls(): void
    {
        $scene = $this->planOnce();

        $html = $this->get(route('video-projects.scene', $this->project->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'data-preview="'.route('video-projects.scene-keyframe-preview', [$this->project->id, $scene->id]).'"',
            $html,
        );
        $this->assertStringContainsString(
            'data-render="'.route('video-projects.scene-keyframe-render', [$this->project->id, $scene->id]).'"',
            $html,
        );
        $this->assertStringNotContainsString('Chưa nối', $html);
    }

    public function test_the_grid_shows_retry_for_a_failed_cell_and_approve_for_a_rendered_one(): void
    {
        $this->fakeImageProvider();
        $this->planOnce();
        $candidate = $this->renderedKeyframeFor($this->sceneRow(1));

        $rendered = $this->get(route('video-projects.scene', $this->project->id))->getContent();

        $this->assertStringContainsString(
            route('video-projects.scene-keyframe-approve', [$this->project->id, $candidate->id]),
            $rendered,
        );
        $this->assertStringNotContainsString('↻ Thử lại', $rendered);

        DB::table('video_design_images')->where('id', $candidate->id)->update([
            'status' => DesignImageStatus::FAILED->value,
        ]);

        $failed = $this->get(route('video-projects.scene', $this->project->id))->getContent();

        $this->assertStringContainsString('↻ Thử lại', $failed);
        $this->assertStringContainsString(
            route('video-projects.scene-keyframe-retry', [$this->project->id, $candidate->id]),
            $failed,
        );
    }

    public function test_an_approved_scene_still_offers_another_take(): void
    {
        $this->fakeImageProvider();
        $this->planOnce();
        $candidate = $this->renderedKeyframeFor($this->sceneRow(1));

        app(VideoProjectService::class)->approveSceneKeyframe(
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $candidate->id,
            (string) $candidate->artifacts()->sole()->id,
        );

        $html = $this->get(route('video-projects.scene', $this->project->id))->getContent();

        $this->assertStringContainsString('Tạo bản khác', $html);
        $this->assertStringContainsString('ĐÃ DUYỆT', $html);
    }

    public function test_a_stranger_reaches_none_of_the_five_keyframe_routes(): void
    {
        Http::fake();
        $this->planOnce();
        $scene = $this->sceneRow(1);
        $image = (string) Str::uuid();

        $this->actingAs($this->admin());

        foreach ([
            ['get', route('video-projects.scene-keyframe-preview', [$this->project->id, $scene->id])],
            ['post', route('video-projects.scene-keyframe-render', [$this->project->id, $scene->id])],
            ['get', route('video-projects.scene-keyframe-state', [$this->project->id, $image])],
            ['post', route('video-projects.scene-keyframe-retry', [$this->project->id, $image])],
            ['post', route('video-projects.scene-keyframe-approve', [$this->project->id, $image])],
        ] as [$verb, $url]) {
            $this->{$verb}($url)->assertForbidden();
        }

        $this->assertSame(0, $this->keyframeCount());
        Http::assertNothingSent();
    }

    public function test_the_preview_endpoint_hands_back_what_the_modal_needs(): void
    {
        $this->planOnce();
        $scene = $this->sceneRow(1);

        $body = $this->getJson(
            route('video-projects.scene-keyframe-preview', [$this->project->id, $scene->id]),
        )->assertOk()->json();

        $this->assertTrue($body['ok']);
        $this->assertSame('1152x2048', $body['preview']['size']);
        $this->assertSame('low', $body['preview']['quality']);
        $this->assertNull($body['preview']['cost_estimate']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $body['preview']['prompt_sha256']);
        $this->assertSame('anchor', $body['preview']['sources'][0]['role']);
        $this->assertSame('current', $body['preview']['sources'][0]['state']);
    }

    public function test_the_preview_endpoint_names_a_red_gate_in_words(): void
    {
        $scene = $this->planOnce();

        DB::table('video_render_scenes')->where('id', $scene->id)->update([
            'title' => 'Edited straight in the database',
        ]);

        $body = $this->getJson(
            route('video-projects.scene-keyframe-preview', [$this->project->id, $scene->id]),
        )->assertStatus(422)->json();

        $this->assertFalse($body['ok']);
        $this->assertSame('scene_plan_changed_since_review', $body['reason']);
        $this->assertSame(
            __('messages.scene_keyframe_scene_plan_changed_since_review'),
            $body['message'],
        );
    }

    public function test_the_state_endpoint_refuses_a_cell_outside_the_project(): void
    {
        $this->planOnce();

        $body = $this->getJson(route('video-projects.scene-keyframe-state', [
            $this->project->id, Str::uuid(),
        ]))->assertStatus(422)->json();

        $this->assertSame('candidate_outside_scene', $body['reason']);
        $this->assertSame(
            __('messages.scene_keyframe_candidate_outside_scene'),
            $body['message'],
        );
    }

    public function test_render_without_a_hash_never_reaches_the_provider(): void
    {
        Http::fake();
        $this->planOnce();
        $scene = $this->sceneRow(1);

        $this->post(
            route('video-projects.scene-keyframe-render', [$this->project->id, $scene->id]),
        )->assertSessionHasErrors('prompt_sha256');

        $this->assertSame(0, $this->keyframeCount());
        Http::assertNothingSent();
    }

    public function test_picks_posted_by_hand_never_change_the_manifest(): void
    {
        $this->fakeImageProvider();
        $this->planOnce();
        $scene = $this->sceneRow(1);
        $port = $this->approvedReference('port_side', 'port-bytes');

        $preview = $this->getJson(
            route('video-projects.scene-keyframe-preview', [$this->project->id, $scene->id]),
        )->json('preview');

        $this->post(
            route('video-projects.scene-keyframe-render', [$this->project->id, $scene->id]),
            [
                'prompt_sha256' => $preview['prompt_sha256'],
                'anchor_artifact_id' => $preview['anchor_confirm_artifact_id'],
                'picks' => [(string) $port->id],
            ],
        )->assertSessionHas('success', __('messages.scene_keyframe_rendered'));

        $candidate = VideoDesignImage::query()
            ->where('render_scene_id', $scene->id)
            ->sole();

        $this->assertSame(
            ['anchor', 'identity', 'environment'],
            array_column($candidate->prompt_spec_json['sources'], 'role'),
            'the manifest comes from the contract, never from posted picks',
        );

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $files = array_values(array_filter(
                $request->data(),
                static fn (array $part): bool => isset($part['filename']),
            ));

            $this->assertCount(3, $files);
            $this->assertSame(['image[]', 'image[]', 'image[]'], array_column($files, 'name'));
            $this->assertSame(
                ['anchor-bytes', 'port-bytes', 'plate-bytes-design_studio'],
                array_column($files, 'contents'),
                'bytes go out in manifest order',
            );

            return true;
        });
    }

    public function test_a_valid_submit_renders_and_reports_success(): void
    {
        $this->fakeImageProvider();
        $this->planOnce();
        $scene = $this->sceneRow(1);

        $preview = $this->getJson(
            route('video-projects.scene-keyframe-preview', [$this->project->id, $scene->id]),
        )->json('preview');

        $this->post(
            route('video-projects.scene-keyframe-render', [$this->project->id, $scene->id]),
            [
                'prompt_sha256' => $preview['prompt_sha256'],
                'anchor_artifact_id' => $preview['anchor_confirm_artifact_id'],
            ],
        )->assertSessionHas('success', __('messages.scene_keyframe_rendered'));

        $this->assertSame(1, $this->keyframeCount());
        Http::assertSentCount(1);
    }

    public function test_pressing_render_twice_over_http_reports_success_and_pays_once(): void
    {
        $this->fakeImageProvider();
        $this->planOnce();
        $scene = $this->sceneRow(1);

        $preview = $this->getJson(
            route('video-projects.scene-keyframe-preview', [$this->project->id, $scene->id]),
        )->json('preview');

        $body = [
            'prompt_sha256' => $preview['prompt_sha256'],
            'anchor_artifact_id' => $preview['anchor_confirm_artifact_id'],
        ];
        $url = route('video-projects.scene-keyframe-render', [$this->project->id, $scene->id]);

        $this->post($url, $body)->assertSessionHas('success', __('messages.scene_keyframe_rendered'));
        $this->post($url, $body)->assertSessionHas(
            'success', __('messages.scene_keyframe_already_exists'),
        );

        $this->assertSame(1, $this->keyframeCount());
        Http::assertSentCount(1);
    }

    public function test_a_retry_on_an_approved_keyframe_reports_success_not_an_error(): void
    {
        $this->fakeImageProvider();
        $this->planOnce();
        $candidate = $this->renderedKeyframeFor($this->sceneRow(1));

        $this->post(
            route('video-projects.scene-keyframe-retry', [$this->project->id, $candidate->id]),
            ['prompt_sha256' => (string) $candidate->prompt_sha256],
        )->assertSessionHas('success', __('messages.scene_keyframe_already_exists'));

        Http::assertSentCount(1);
    }

    public function test_a_retry_over_http_with_the_wrong_hash_is_refused(): void
    {
        $this->fakeImageProvider();
        $this->planOnce();
        $candidate = $this->renderedKeyframeFor($this->sceneRow(1));

        $this->post(
            route('video-projects.scene-keyframe-retry', [$this->project->id, $candidate->id]),
            ['prompt_sha256' => str_repeat('b', 64)],
        )->assertSessionHas('error', __('messages.scene_keyframe_preview_stale'));

        Http::assertSentCount(1);
    }

    public function test_approve_over_http_refuses_an_artifact_from_another_cell(): void
    {
        $this->fakeImageProvider();
        $this->planOnce();
        $mine = $this->renderedKeyframeFor($this->sceneRow(1));
        $other = $this->renderedKeyframeFor($this->hardCutAfter($this->sceneRow(1)));

        $this->post(
            route('video-projects.scene-keyframe-approve', [$this->project->id, $mine->id]),
            ['artifact_id' => (string) $other->artifacts()->sole()->id],
        )->assertSessionHas('error', __('messages.scene_keyframe_artifact_not_in_candidate'));

        $this->assertSame(DesignImageStatus::RENDERED->value, $mine->fresh()->status);
    }

    public function test_approve_over_http_marks_the_chosen_candidate(): void
    {
        $this->fakeImageProvider();
        $this->planOnce();
        $candidate = $this->renderedKeyframeFor($this->sceneRow(1));
        $artifact = $candidate->artifacts()->sole();

        $this->post(
            route('video-projects.scene-keyframe-approve', [$this->project->id, $candidate->id]),
            ['artifact_id' => (string) $artifact->id],
        )->assertSessionHas('success', __('messages.scene_keyframe_approved'));

        $this->assertSame(DesignImageStatus::APPROVED->value, $candidate->fresh()->status);
        $this->assertSame((string) $artifact->id, (string) $candidate->fresh()->selected_artifact_id);
    }

    public function test_a_rendered_keyframe_can_be_approved(): void
    {
        $this->fakeImageProvider();
        $this->planOnce();
        $candidate = $this->renderedKeyframeFor($this->sceneRow(1));
        $artifact = $candidate->artifacts()->sole();

        [$done, $reason] = app(VideoProjectService::class)->approveSceneKeyframe(
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $candidate->id,
            (string) $artifact->id,
        );

        $this->assertTrue($done, $reason);
        $this->assertSame('approved', $reason);
        $this->assertSame(DesignImageStatus::APPROVED->value, $candidate->fresh()->status);
        $this->assertSame((string) $artifact->id, (string) $candidate->fresh()->selected_artifact_id);
    }

    public function test_approving_supersedes_only_the_other_candidate_of_that_scene(): void
    {
        $this->fakeImageProvider();
        $this->planOnce();

        $first = $this->renderedKeyframeFor($this->sceneRow(1));
        $later = $this->renderedKeyframeFor($this->hardCutAfter($this->sceneRow(1)));
        $service = app(VideoProjectService::class);

        [$laterDone, $laterReason] = $service->approveSceneKeyframe(
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $later->id,
            (string) $later->artifacts()->sole()->id,
        );

        $this->assertTrue($laterDone, $laterReason);

        [$firstDone, $firstReason] = $service->approveSceneKeyframe(
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $first->id,
            (string) $first->artifacts()->sole()->id,
        );

        $this->assertTrue($firstDone, $firstReason);
        $this->assertSame(DesignImageStatus::APPROVED->value, $first->fresh()->status);
        $this->assertSame(
            DesignImageStatus::APPROVED->value,
            $later->fresh()->status,
            'approving one scene must not supersede the keyframe of another scene',
        );
    }

    public function test_an_artifact_from_another_candidate_cannot_be_approved_through_this_one(): void
    {
        $this->fakeImageProvider();
        $this->planOnce();

        $mine = $this->renderedKeyframeFor($this->sceneRow(1));
        $other = $this->renderedKeyframeFor($this->hardCutAfter($this->sceneRow(1)));

        [$done, $reason] = app(VideoProjectService::class)->approveSceneKeyframe(
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $mine->id,
            (string) $other->artifacts()->sole()->id,
        );

        $this->assertFalse($done);
        $this->assertSame('artifact_not_in_candidate', $reason);
        $this->assertSame(DesignImageStatus::RENDERED->value, $mine->fresh()->status);
        $this->assertSame(DesignImageStatus::RENDERED->value, $other->fresh()->status);
    }

    public function test_another_member_cannot_approve_a_keyframe(): void
    {
        $this->fakeImageProvider();
        $this->planOnce();
        $candidate = $this->renderedKeyframeFor($this->sceneRow(1));

        [$done, $reason] = app(VideoProjectService::class)->approveSceneKeyframe(
            (string) $this->project->id,
            (string) $this->admin()->id,
            (string) $candidate->id,
            (string) $candidate->artifacts()->sole()->id,
        );

        $this->assertFalse($done);
        $this->assertSame('candidate_outside_scene', $reason);
        $this->assertSame(DesignImageStatus::RENDERED->value, $candidate->fresh()->status);
    }

    public function test_an_anchor_cell_cannot_be_approved_through_the_keyframe_path(): void
    {
        $anchor = $this->approvedAnchorRow();

        [$done, $reason] = app(VideoProjectService::class)->approveSceneKeyframe(
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $anchor->id,
            (string) $anchor->selected_artifact_id,
        );

        $this->assertFalse($done);
        $this->assertSame('candidate_outside_scene', $reason);
    }

    public function test_a_reference_cell_wearing_a_scene_id_cannot_be_approved_as_a_keyframe(): void
    {
        $this->planOnce();
        $artifact = $this->approvedReference('port_side', 'port-bytes');

        DB::table('video_design_images')->where('id', $artifact->design_image_id)->update([
            'render_scene_id' => $this->sceneRow(1)->id,
            'status' => DesignImageStatus::RENDERED->value,
        ]);

        [$done, $reason] = app(VideoProjectService::class)->approveSceneKeyframe(
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $artifact->design_image_id,
            (string) $artifact->id,
        );

        $this->assertFalse($done);
        $this->assertSame('candidate_outside_scene', $reason);
        $this->assertSame(
            DesignImageStatus::RENDERED->value,
            VideoDesignImage::query()->whereKey($artifact->design_image_id)->value('status'),
        );
    }

    public function test_a_candidate_cut_loose_from_its_scene_cannot_be_approved(): void
    {
        $this->fakeImageProvider();
        $this->planOnce();
        $candidate = $this->renderedKeyframeFor($this->sceneRow(1));

        DB::table('video_design_images')->where('id', $candidate->id)->update([
            'render_scene_id' => null,
        ]);

        [$done, $reason] = app(VideoProjectService::class)->approveSceneKeyframe(
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $candidate->id,
            (string) $candidate->artifacts()->sole()->id,
        );

        $this->assertFalse($done);
        $this->assertSame('candidate_outside_scene', $reason);
    }

    public function test_a_candidate_still_rendering_is_not_approvable(): void
    {
        $this->fakeImageProvider();
        $this->planOnce();
        $candidate = $this->renderedKeyframeFor($this->sceneRow(1));
        $artifact = $candidate->artifacts()->sole();

        DB::table('video_design_images')->where('id', $candidate->id)->update([
            'status' => DesignImageStatus::RENDERING->value,
        ]);

        [$done, $reason] = app(VideoProjectService::class)->approveSceneKeyframe(
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $candidate->id,
            (string) $artifact->id,
        );

        $this->assertFalse($done);
        $this->assertSame('not_approvable', $reason);
    }

    public function test_the_anchor_route_cannot_approve_a_scene_keyframe(): void
    {
        $this->fakeImageProvider();
        $this->planOnce();
        $candidate = $this->renderedKeyframeFor($this->sceneRow(1));

        [$done, $reason] = app(VideoProjectService::class)->approveAnchor(
            (string) $this->project->id,
            (string) $candidate->artifacts()->sole()->id,
            (string) $this->owner->id,
        );

        $this->assertFalse($done);
        $this->assertSame('image_type_mismatch', $reason);
        $this->assertSame(DesignImageStatus::RENDERED->value, $candidate->fresh()->status);
    }

    public function test_the_store_refuses_an_artifact_whose_cell_left_the_named_scene(): void
    {
        $this->fakeImageProvider();
        $this->planOnce();
        $candidate = $this->renderedKeyframeFor($this->sceneRow(1));

        [$done, $reason] = app(DesignImageStore::class)->approve(
            (string) $this->project->id,
            (string) $candidate->artifacts()->sole()->id,
            (string) $this->owner->id,
            (string) $candidate->id,
            DesignImageStore::SCENE_KEYFRAME_TYPE,
            (string) $this->sceneRow(5)->id,
        );

        $this->assertFalse($done);
        $this->assertSame('candidate_outside_scene', $reason);
        $this->assertSame(DesignImageStatus::RENDERED->value, $candidate->fresh()->status);
    }

    public function test_the_anchor_path_still_approves_without_naming_an_image(): void
    {
        $anchor = $this->approvedAnchorRow();

        DB::table('video_design_images')->where('id', $anchor->id)->update([
            'status' => DesignImageStatus::RENDERED->value,
            'selected_artifact_id' => null,
        ]);

        [$done, $reason] = app(VideoProjectService::class)->approveAnchor(
            (string) $this->project->id,
            (string) $anchor->selected_artifact_id ?: (string) $anchor->artifacts()->sole()->id,
            (string) $this->owner->id,
        );

        $this->assertTrue($done, $reason);
        $this->assertSame('approved', $reason);
    }

    private function fakeImageProvider(): void
    {
        Http::fake(['*' => Http::response([
            'created' => 1, 'data' => [['b64_json' => self::PNG_3X5]],
        ], 200)]);
    }

    private function hardCutAfter(VideoRenderScene $scene): VideoRenderScene
    {
        return VideoRenderScene::query()
            ->where('project_id', $this->project->id)
            ->where('revision', $scene->revision)
            ->where('transition_mode', ScenePreservationPrompt::HARD_CUT)
            ->where('scene_index', '>', $scene->scene_index)
            ->orderBy('scene_index')
            ->firstOrFail();
    }

    private function renderedKeyframeFor(VideoRenderScene $scene): VideoDesignImage
    {
        [$preview, $previewReason] = $this->previewOf($scene);

        $this->assertNotNull($preview, $previewReason);

        [$image, $reason] = $this->renderScene(
            $scene, $preview['prompt_sha256'], $preview['anchor_confirm_artifact_id'],
        );

        $this->assertSame('rendered', $reason, (string) $image?->render_error);

        return $image;
    }

    public function test_the_grid_row_carries_both_the_code_and_the_uuid(): void
    {
        $scene = $this->planOnce();

        $row = $this->get(route('video-projects.scene', $this->project->id))
            ->assertOk()
            ->viewData('scenes')[0];

        $this->assertSame((string) $scene->scene_code, $row['id']);
        $this->assertSame((string) $scene->id, $row['scene_id']);
    }

    public function test_a_row_holding_that_hash_with_a_broken_snapshot_fails_clean(): void
    {
        Http::fake();
        [$candidate] = $this->writtenCandidate();

        $spec = $candidate->prompt_spec_json;
        $spec['reference_manifest_hash'] = str_repeat('0', 64);

        DB::table('video_design_images')->where('id', $candidate->id)->update([
            'prompt_spec_json' => json_encode($spec, JSON_THROW_ON_ERROR),
        ]);

        $scene = $this->sceneRow(1);
        [$preview, $previewReason] = $this->previewOf($scene);

        $this->assertNotNull($preview, $previewReason);

        $method = new \ReflectionMethod(VideoProjectService::class, 'claimSceneRender');

        [$written, $reason] = $method->invoke(
            app(VideoProjectService::class),
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $scene->id,
            $preview["prompt_sha256"],
            $preview['anchor_confirm_artifact_id'],
            $preview['reference_manifest_hash'],
        );

        $this->assertNull($written);
        $this->assertSame('candidate_snapshot_manifest_hash_mismatch', $reason);
        $this->assertSame(1, $this->keyframeCount());
        Http::assertNothingSent();
    }

    public function test_a_row_that_carried_that_hash_away_to_another_scene_fails_clean(): void
    {
        Http::fake();
        [$candidate] = $this->writtenCandidate();

        DB::table('video_design_images')->where('id', $candidate->id)->update([
            'render_scene_id' => $this->sceneRow(5)->id,
        ]);

        $scene = $this->sceneRow(1);
        [$preview, $previewReason] = $this->previewOf($scene);

        $this->assertNotNull($preview, $previewReason);

        $method = new \ReflectionMethod(VideoProjectService::class, 'claimSceneRender');

        [$written, $reason] = $method->invoke(
            app(VideoProjectService::class),
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $scene->id,
            $preview["prompt_sha256"],
            $preview['anchor_confirm_artifact_id'],
            $preview['reference_manifest_hash'],
        );

        $this->assertNull($written);
        $this->assertSame('candidate_outside_scene', $reason);
        $this->assertSame(1, $this->keyframeCount());
        Http::assertNothingSent();
    }

    public function test_another_member_reaches_no_part_of_the_scene_render_flow(): void
    {
        Http::fake();
        [$candidate, $preview] = $this->writtenCandidate();
        $scene = $this->sceneRow(1);
        $stranger = (string) $this->admin()->id;
        $service = app(VideoProjectService::class);
        $projectId = (string) $this->project->id;

        [$shown, $previewReason] = $service->sceneImagePreview($projectId, $stranger, (string) $scene->id);
        $this->assertNull($shown);
        $this->assertSame('scene_not_found', $previewReason);

        [$written, $renderReason] = $service->renderSceneImage(
            $projectId, $stranger, (string) $scene->id,
            $preview['prompt_sha256'], $preview['anchor_confirm_artifact_id'],
        );
        $this->assertNull($written);
        $this->assertSame('scene_not_found', $renderReason);

        [$snapshot, $snapshotReason] = $service->previewFromCandidate(
            $projectId, $stranger, (string) $candidate->id,
        );
        $this->assertNull($snapshot);
        $this->assertSame('candidate_outside_scene', $snapshotReason);

        [$resumed, $resumeReason] = $service->resumeSceneCandidate(
            $projectId, $stranger, (string) $candidate->id, (string) $candidate->prompt_sha256,
        );
        $this->assertNull($resumed);
        $this->assertSame('candidate_outside_scene', $resumeReason);

        $this->assertSame(1, $this->keyframeCount());
        Http::assertNothingSent();
    }

    /** @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}> */
    public static function brokenSnapshotProvider(): iterable
    {
        yield 'operation la' => [
            static function (array $spec): array {
                $spec['operation'] = 'edit';

                return $spec;
            },
            'candidate_snapshot_unreadable',
        ];

        yield 'spec version la' => [
            static function (array $spec): array {
                $spec['spec_version'] = 'scene-keyframe-v0';

                return $spec;
            },
            'candidate_snapshot_unreadable',
        ];

        yield 'vi tri lech' => [
            static function (array $spec): array {
                $spec['sources'][0]['position'] = 1;

                return $spec;
            },
            'candidate_snapshot_unreadable',
        ];

        yield 'vai tro sai cho hard cut' => [
            static function (array $spec): array {
                $spec['sources'][0]['role'] = 'source_keyframe';

                return $spec;
            },
            'candidate_snapshot_role_mismatch',
        ];

        yield 'vai tro khong ton tai' => [
            static function (array $spec): array {
                $spec['sources'][0]['role'] = 'backdrop';

                return $spec;
            },
            'candidate_snapshot_unreadable',
        ];

        yield 'manifest hash bi sua' => [
            static function (array $spec): array {
                $spec['reference_manifest_hash'] = str_repeat('0', 64);

                return $spec;
            },
            'candidate_snapshot_manifest_hash_mismatch',
        ];

        yield 'prompt bi sua sau khi bam' => [
            static function (array $spec): array {
                $spec['prompt'] = 'a different prompt entirely';

                return $spec;
            },
            'candidate_snapshot_identity_mismatch',
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenSnapshotProvider')]
    public function test_a_broken_snapshot_is_named_and_never_rendered(
        callable $break,
        string $expected,
    ): void {
        Http::fake();
        [$candidate] = $this->writtenCandidate();

        DB::table('video_design_images')->where('id', $candidate->id)->update([
            'prompt_spec_json' => json_encode($break($candidate->prompt_spec_json), JSON_THROW_ON_ERROR),
        ]);

        [$shown, $previewReason] = app(VideoProjectService::class)->previewFromCandidate(
            (string) $this->project->id, (string) $this->owner->id, (string) $candidate->id,
        );

        [$resumed, $resumeReason] = app(VideoProjectService::class)->resumeSceneCandidate(
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $candidate->id,
            (string) $candidate->prompt_sha256,
        );

        $this->assertNull($shown);
        $this->assertSame($expected, $previewReason);
        $this->assertNull($resumed);
        $this->assertSame($expected, $resumeReason);
        Http::assertNothingSent();
    }



    public function test_a_continuation_snapshot_pointing_at_another_scene_is_refused(): void
    {
        Http::fake();
        $this->planOnce();
        $this->approvedKeyframeFor($this->sceneRow(1));

        $scene = $this->sceneRow(2);
        [$preview] = $this->previewOf($scene);
        $candidate = $this->writeCandidate($scene, $preview, []);

        $stray = $this->approvedKeyframeFor($this->sceneRow(3));
        $spec = $candidate->prompt_spec_json;
        $spec['sources'][0]['candidate_id'] = (string) VideoArtifact::query()
            ->whereKey($stray->id)->value('design_image_id');

        DB::table('video_design_images')->where('id', $candidate->id)->update([
            'prompt_spec_json' => json_encode($spec, JSON_THROW_ON_ERROR),
        ]);

        [$shown, $reason] = app(VideoProjectService::class)->previewFromCandidate(
            (string) $this->project->id, (string) $this->owner->id, (string) $candidate->id,
        );

        $this->assertNull($shown);
        $this->assertSame('candidate_snapshot_wrong_source_scene', $reason);
        Http::assertNothingSent();
    }

    public function test_a_red_gate_still_shows_the_snapshot_but_blocks_the_retry(): void
    {
        Http::fake();
        [$candidate] = $this->writtenCandidate();

        DB::table('video_render_scenes')
            ->where('id', $candidate->render_scene_id)
            ->update(['title' => 'Edited straight in the database']);

        [$shown, $previewReason] = app(VideoProjectService::class)->previewFromCandidate(
            (string) $this->project->id, (string) $this->owner->id, (string) $candidate->id,
        );

        $this->assertNotNull($shown);
        $this->assertSame('scene_plan_changed_since_review', $previewReason);
        $this->assertSame('scene_plan_changed_since_review', $shown['blocked_reason']);
        $this->assertTrue($shown['from_snapshot']);
        $this->assertCount(2, $shown['sources']);

        [$resumed, $resumeReason] = app(VideoProjectService::class)->resumeSceneCandidate(
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $candidate->id,
            (string) $candidate->prompt_sha256,
        );

        $this->assertNull($resumed);
        $this->assertSame('scene_plan_changed_since_review', $resumeReason);
        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $preview */
    private function writeCandidate(VideoRenderScene $scene, array $preview): VideoDesignImage
    {
        $method = new \ReflectionMethod(VideoProjectService::class, 'claimSceneRender');

        [$candidate, $reason] = $method->invoke(
            app(VideoProjectService::class),
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $scene->id,
            $preview['prompt_sha256'],
            $preview['anchor_confirm_artifact_id'],
            $preview['reference_manifest_hash'],
        );

        $this->assertSame('created', $reason);

        return $candidate;
    }

    /** @return array{0: VideoDesignImage, 1: array<string, mixed>} */
    private function writtenCandidate(): array
    {
        $this->planOnce();
        $scene = $this->sceneRow(1);
        [$preview] = $this->previewOf($scene);

        $method = new \ReflectionMethod(VideoProjectService::class, 'claimSceneRender');
        [$candidate, $reason] = $method->invoke(
            app(VideoProjectService::class),
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $scene->id,
            $preview["prompt_sha256"],
            $preview['anchor_confirm_artifact_id'],
            $preview['reference_manifest_hash'],
        );

        $this->assertSame('created', $reason);

        return [$candidate, $preview];
    }

    public function test_the_write_gate_refuses_a_stale_preview_hash_inside_the_lock(): void
    {
        Http::fake();
        $this->planOnce();
        $scene = $this->sceneRow(1);
        [$preview] = $this->previewOf($scene);

        $method = new \ReflectionMethod(VideoProjectService::class, 'claimSceneRender');

        [$candidate, $reason] = $method->invoke(
            app(VideoProjectService::class),
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $scene->id,
            str_repeat('0', 64),
            $preview['anchor_confirm_artifact_id'],
            $preview['reference_manifest_hash'],
        );

        $this->assertNull($candidate);
        $this->assertSame('preview_stale', $reason);
        $this->assertSame(0, $this->keyframeCount());
        Http::assertNothingSent();
    }

    public function test_the_write_gate_refuses_a_manifest_that_moved_since_the_bytes_were_checked(): void
    {
        Http::fake();
        $this->planOnce();
        $scene = $this->sceneRow(1);
        [$preview] = $this->previewOf($scene);

        $method = new \ReflectionMethod(VideoProjectService::class, 'claimSceneRender');

        [$candidate, $reason] = $method->invoke(
            app(VideoProjectService::class),
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $scene->id,
            $preview["prompt_sha256"],
            $preview['anchor_confirm_artifact_id'],
            str_repeat('0', 64),
        );

        $this->assertNull($candidate);
        $this->assertSame('source_changed_before_write', $reason);
        $this->assertSame(0, $this->keyframeCount());
        Http::assertNothingSent();
    }

    public function test_the_write_gate_accepts_the_hashes_the_preview_handed_out(): void
    {
        Http::fake();
        $this->planOnce();
        $scene = $this->sceneRow(1);
        [$preview] = $this->previewOf($scene);

        $method = new \ReflectionMethod(VideoProjectService::class, 'claimSceneRender');

        [$candidate, $reason] = $method->invoke(
            app(VideoProjectService::class),
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $scene->id,
            $preview["prompt_sha256"],
            $preview['anchor_confirm_artifact_id'],
            $preview['reference_manifest_hash'],
        );

        $this->assertNotNull($candidate);
        $this->assertSame('created', $reason);
        $this->assertSame(1, $this->keyframeCount());
        Http::assertNothingSent();
    }

    /** @return array{0: ?array<string, mixed>, 1: string} */
    private function previewOf(VideoRenderScene $scene): array
    {
        return app(VideoProjectService::class)->sceneImagePreview(
            (string) $this->project->id, (string) $this->owner->id, (string) $scene->id,
        );
    }

    /** @return array{0: ?VideoDesignImage, 1: string} */
    private function renderScene(
        VideoRenderScene $scene,
        string $previewHash,
        ?string $confirmAnchorArtifactId,
    ): array {
        return app(VideoProjectService::class)->renderSceneImage(
            (string) $this->project->id,
            (string) $this->owner->id,
            (string) $scene->id,
            $previewHash,
            $confirmAnchorArtifactId,
        );
    }

    private function sceneRow(int $index): VideoRenderScene
    {
        return VideoRenderScene::query()
            ->where('project_id', $this->project->id)
            ->orderByDesc('revision')
            ->where('scene_index', $index)
            ->firstOrFail();
    }

    private function keyframeCount(): int
    {
        return VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->count();
    }

    private function approvedAnchorRow(): VideoDesignImage
    {
        return VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', DesignImageStore::ANCHOR_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->orderByDesc('approved_at')
            ->firstOrFail();
    }

    private function firstFileOf(\Illuminate\Http\Client\Request $request): string
    {
        foreach ($request->data() as $part) {
            if (isset($part['filename'])) {
                return (string) $part['contents'];
            }
        }

        return '';
    }

    private function bytesOf(string $artifactId): string
    {
        $artifact = VideoArtifact::query()->whereKey($artifactId)->firstOrFail();

        return (string) Storage::disk((string) $artifact->storage_disk)->get((string) $artifact->storage_path);
    }

    /** @return array<string, VideoArtifact> */
    private function approveEveryPlate(): array
    {
        $plates = [];

        foreach (['design_studio', 'shipyard_hall', 'paint_shed', 'launch_quay', 'open_water'] as $key) {
            $plates[$key] = $this->approvedPlate($key);
        }

        return $plates;
    }

    private function approvedPlate(string $environmentKey): VideoArtifact
    {
        $candidate = VideoDesignImage::create([
            'project_id' => $this->project->id,
            'image_code' => 'environment_'.uniqid(),
            'image_type' => DesignImageStore::ENVIRONMENT_TYPE,
            'environment_key' => $environmentKey,
            'prompt_spec_json' => [
                'operation' => 'environment_plate',
                'spec_version' => \App\Video\Environment\EnvironmentPlatePrompt::VERSION,
                'environment_key' => $environmentKey,
                'prompt' => 'An empty location plate. '.$environmentKey,
                'model' => 'gpt-image-2',
                'quality' => 'low',
                'size' => '1152x2048',
                'variations' => 1,
            ],
            'prompt_sha256' => hash('sha256', uniqid('', true)),
            'status' => DesignImageStatus::RENDERED->value,
            'revision' => 1,
        ]);

        $bytes = 'plate-bytes-'.$environmentKey;
        $path = 'plates/'.uniqid().'.png';
        Storage::disk('video_artifacts')->put($path, $bytes);

        $artifact = VideoArtifact::create([
            'project_id' => $this->project->id,
            'design_image_id' => $candidate->id,
            'artifact_type' => 'image',
            'role' => 'candidate',
            'storage_disk' => 'video_artifacts',
            'storage_path' => $path,
            'mime_type' => 'image/png',
            'sha256' => hash('sha256', $bytes),
            'width' => 1152,
            'height' => 2048,
        ]);

        $candidate->forceFill([
            'selected_artifact_id' => $artifact->id,
            'status' => DesignImageStatus::APPROVED->value,
            'approved_at' => now(),
        ])->save();

        return $artifact;
    }

    private function approvedReference(
        string $viewKey,
        string $bytes,
        string $environment = 'neutral_studio',
    ): VideoArtifact {
        $candidate = VideoDesignImage::create([
            'project_id' => $this->project->id,
            'image_code' => 'reference_'.uniqid(),
            'image_type' => DesignImageStore::REFERENCE_TYPE,
            'slot_index' => \App\Video\Reference\ReferenceView::from($viewKey)->slot(),
            'prompt_spec_json' => [
                'prompt' => 'reference view',
                'view_key' => $viewKey,
                'environment' => $environment,
            ],
            'prompt_sha256' => hash('sha256', uniqid('', true)),
            'status' => DesignImageStatus::RENDERED->value,
            'revision' => 1,
        ]);

        $path = 'references/'.uniqid().'.png';
        Storage::disk('video_artifacts')->put($path, $bytes);

        $artifact = VideoArtifact::create([
            'project_id' => $this->project->id,
            'design_image_id' => $candidate->id,
            'artifact_type' => 'image',
            'role' => 'candidate',
            'storage_disk' => 'video_artifacts',
            'storage_path' => $path,
            'mime_type' => 'image/png',
            'sha256' => hash('sha256', $bytes),
            'width' => 1152,
            'height' => 2048,
        ]);

        $candidate->forceFill([
            'selected_artifact_id' => $artifact->id,
            'status' => DesignImageStatus::APPROVED->value,
            'approved_at' => now(),
        ])->save();

        return $artifact;
    }

    private function approveAnotherAnchor(): VideoArtifact
    {
        $candidate = VideoDesignImage::create([
            'project_id' => $this->project->id,
            'image_code' => 'anchor_'.uniqid(),
            'image_type' => DesignImageStore::ANCHOR_TYPE,
            'prompt_spec_json' => ['prompt' => self::ANCHOR_PROMPT."\n\nREVISED\nA second hull."],
            'prompt_sha256' => hash('sha256', uniqid('', true)),
            'status' => DesignImageStatus::RENDERED->value,
            'revision' => 1,
        ]);

        $bytes = 'second-anchor-bytes';
        $path = 'anchors/'.uniqid().'.png';
        Storage::disk('video_artifacts')->put($path, $bytes);

        $artifact = VideoArtifact::create([
            'project_id' => $this->project->id,
            'design_image_id' => $candidate->id,
            'artifact_type' => 'image',
            'role' => 'candidate',
            'storage_disk' => 'video_artifacts',
            'storage_path' => $path,
            'mime_type' => 'image/png',
            'sha256' => hash('sha256', $bytes),
            'width' => 1536,
            'height' => 1024,
        ]);

        [$done, $reason] = app(VideoProjectService::class)->approveAnchor(
            (string) $this->project->id, (string) $artifact->id, (string) $this->owner->id,
        );

        $this->assertTrue($done, $reason);

        return $artifact;
    }

    private function approvedKeyframeFor(VideoRenderScene $scene): VideoArtifact
    {
        $anchor = $this->approvedAnchorRow();
        $anchorArtifact = VideoArtifact::query()
            ->whereKey($anchor->selected_artifact_id)
            ->firstOrFail();

        $candidate = VideoDesignImage::create([
            'project_id' => $this->project->id,
            'render_scene_id' => $scene->id,
            'image_code' => 'keyframe_'.uniqid(),
            'image_type' => DesignImageStore::SCENE_KEYFRAME_TYPE,
            'prompt_spec_json' => [
                'prompt' => 'already rendered elsewhere',
                'sources' => [[
                    'artifact_id' => (string) $anchorArtifact->id,
                    'candidate_id' => (string) $anchor->id,
                    'position' => 0,
                    'role' => 'anchor',
                    'sha256' => (string) $anchorArtifact->sha256,
                ]],
            ],
            'prompt_sha256' => hash('sha256', uniqid('', true)),
            'status' => DesignImageStatus::RENDERED->value,
            'revision' => 1,
        ]);

        $bytes = 'keyframe-bytes-'.$scene->scene_code;
        $path = 'keyframes/'.uniqid().'.png';
        Storage::disk('video_artifacts')->put($path, $bytes);

        $artifact = VideoArtifact::create([
            'project_id' => $this->project->id,
            'design_image_id' => $candidate->id,
            'artifact_type' => 'image',
            'role' => 'candidate',
            'storage_disk' => 'video_artifacts',
            'storage_path' => $path,
            'mime_type' => 'image/png',
            'sha256' => hash('sha256', $bytes),
            'width' => 1152,
            'height' => 2048,
        ]);

        $candidate->forceFill([
            'selected_artifact_id' => $artifact->id,
            'status' => DesignImageStatus::APPROVED->value,
            'approved_at' => now(),
        ])->save();

        return $artifact;
    }

    /** @return array{0: bool, 1: string} */
    private function gate(?VideoRenderScene $scene = null): array
    {
        $scene ??= VideoRenderScene::query()
            ->where('project_id', $this->project->id)
            ->orderByDesc('revision')->orderBy('scene_index')->firstOrFail();

        $method = new \ReflectionMethod(VideoProjectService::class, 'sceneRenderGate');

        [$ok, $reason] = $method->invoke(app(VideoProjectService::class), $scene->refresh());

        return [$ok, $reason];
    }

    private function planOnce(): VideoRenderScene
    {
        $this->client->text = $this->plan();
        $this->post($this->url())->assertSessionHas('success');

        return VideoRenderScene::query()
            ->where('project_id', $this->project->id)
            ->orderBy('scene_index')->firstOrFail();
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutate */
    private function rewriteStageInput(callable $mutate): void
    {
        $stage = $this->scenePlanStages()->sole();

        DB::table('video_planning_stages')->where('id', $stage->id)->update([
            'input_json' => json_encode($mutate($stage->input_json), JSON_THROW_ON_ERROR),
        ]);
    }

    public function test_the_gate_lets_a_reviewed_untouched_revision_through(): void
    {
        $this->planOnce();

        $this->assertSame([true, 'ok'], $this->gate());
    }

    public function test_the_gate_refuses_a_revision_whose_stage_is_gone(): void
    {
        $this->planOnce();
        $this->scenePlanStages()->delete();

        $this->assertSame([false, 'scene_plan_unverifiable'], $this->gate());
    }

    /** Dung hinh dang cua revision truoc hop dong nhom: khong co khoa contract. */
    public function test_the_gate_refuses_a_revision_recorded_before_the_group_contract(): void
    {
        $this->planOnce();

        $this->rewriteStageInput(static function (array $input): array {
            unset($input['scene_contract_version']);

            return $input;
        });

        $this->assertSame([false, 'scene_plan_has_no_continuity_contract'], $this->gate());
    }

    public function test_the_gate_refuses_a_contract_version_it_does_not_know(): void
    {
        $this->planOnce();

        $this->rewriteStageInput(static function (array $input): array {
            $input['scene_contract_version'] = 'scene-contract-v99';

            return $input;
        });

        $this->assertSame([false, 'scene_contract_unsupported'], $this->gate());
    }

    public function test_the_gate_refuses_a_plan_the_reviewer_never_passed(): void
    {
        $this->planOnce();

        $this->rewriteReview(static function (array $output): array {
            $output['review']['status'] = 'needs_review';
            $output['review']['reason'] = 'rounds_exhausted';
            $output['review']['reviewed_plan_sha256'] = null;

            return $output;
        });

        $this->assertSame([false, 'scene_plan_not_reviewed'], $this->gate());
    }

    public function test_the_gate_refuses_a_review_record_it_cannot_read(): void
    {
        $this->planOnce();

        $this->rewriteReview(static function (array $output): array {
            $output['review'] = 'passed';

            return $output;
        });

        $this->assertSame([false, 'scene_plan_not_reviewed'], $this->gate());
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function editedRowProvider(): iterable
    {
        yield 'delta' => [['delta_prompt' => 'Change only something else entirely.']];
        yield 'title' => [['title' => 'Another Title Here']];
        yield 'basis' => [['basis' => 'source_supported']];
        yield 'milestone_keys' => [['milestone_keys' => '["general_arrangement"]']];
        yield 'transition_mode' => [['transition_mode' => 'continuation_edit']];
        yield 'continuity_group' => [['continuity_group' => 'g_something_else']];
        yield 'camera_change_reason' => [['camera_change_reason' => null]];
        yield 'state_json' => [['state_json' => '{"state_before":"x","scene_state":"y"}']];
        yield 'video_plan_json' => [[
            'video_plan_json' => '{"action":"a","preserve":"b","end_state":"c","camera_mode":"locked"}',
        ]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('editedRowProvider')]
    public function test_the_gate_refuses_a_plan_edited_in_the_database(array $change): void
    {
        $scene = $this->planOnce();

        DB::table('video_render_scenes')->where('id', $scene->id)->update($change);

        $this->assertSame([false, 'scene_plan_changed_since_review'], $this->gate());
    }

    public function test_the_gate_refuses_when_the_scene_order_moves(): void
    {
        $scene = $this->planOnce();

        DB::table('video_render_scenes')->where('id', $scene->id)->update(['scene_index' => 99]);

        $this->assertSame([false, 'scene_plan_changed_since_review'], $this->gate($scene));
    }

    public function test_the_gate_refuses_a_scene_that_left_the_revision(): void
    {
        $scene = $this->planOnce();

        DB::table('video_render_scenes')->where('id', $scene->id)->delete();

        $method = new \ReflectionMethod(VideoProjectService::class, 'sceneRenderGate');
        [$ok, $reason] = $method->invoke(app(VideoProjectService::class), $scene);

        $this->assertFalse($ok);
        $this->assertSame('scene_plan_unverifiable', $reason);
    }

    public function test_the_gate_refuses_a_preservation_block_it_cannot_resolve(): void
    {
        $this->planOnce();
        $this->rewritePreservationVersion('scene-preservation-v99');

        $this->assertSame([false, 'preservation_unknown'], $this->gate());
    }

    private function url(): string
    {
        return route('video-projects.scenes-plan', $this->project->id);
    }

    /** @return array<string, mixed> */
    private function planningInput(): array
    {
        $this->assertNotNull($this->client->lastUser, 'the model was never called');

        return json_decode(
            substr((string) $this->client->lastUser, strlen('PLANNING INPUT:')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function scenePlanStages()
    {
        return VideoPlanningStage::query()
            ->where('project_id', $this->project->id)
            ->where('stage', PlanningStageName::SCENE_PLAN->value);
    }

    private function plan(
        string $firstMode = ScenePreservationPrompt::HARD_CUT,
        bool $warn = false,
    ): string {
        $rows = [
            ['profile_drawing', 'Exterior Profile Sketch', 'design', ['concept_sketch'], $firstMode,
                'empty_desk', 'first_sketch_pinned',
                'A wide shot across the design office holds a first exterior sketch pinned to the wall.',
                'The architect pins a second sheet beside the first.', 'two_sheets_pinned'],
            ['arrangement_laid', 'General Arrangement Laid', 'design', ['general_arrangement'], 'continuation_edit',
                'first_sketch_pinned', 'arrangement_laid_out',
                $warn
                    ? 'The arrangement sheet slides open beside the sketch, its deck plans drawn in full.'
                    : 'The arrangement sheet lies open on the desk beside the sketch, its deck plans drawn in full.',
                'A hand slides the sheet flat across the desk.', 'arrangement_flat'],
            ['berth_ready', 'Building Berth Ready', 'preparation', ['berth_prepared'], 'hard_cut_edit',
                'arrangement_laid_out', 'berth_ready',
                'A wide shot inside the empty assembly hall holds the building berth prepared, its support rows set in a straight line.',
                'The hall lights come up along the support rows.', 'berth_lit'],
            ['bottom_set', 'Bottom Structure Set', 'rough_build', ['bottom_structure'], 'continuation_edit',
                'berth_ready', 'bottom_structure_set',
                'The bottom structure sits complete on the support rows, its plating and longitudinal girders in bare steel.',
                'A welder works along the centre girder.', 'bottom_welded'],
            ['frames_standing', 'Transverse Frames Standing', 'rough_build', ['hull_framing'], 'continuation_edit',
                'bottom_structure_set', 'frames_standing',
                'Transverse frames stand upright along the bottom structure, evenly spaced from bow to stern.',
                'A crane hook settles onto the next frame.', 'frame_hooked'],
            ['shell_closed', 'Shell Plating Closed', 'rough_build', ['shell_plating'], 'continuation_edit',
                'frames_standing', 'shell_closed',
                'Shell plates close the hull sides completely, weld seams running the full length in bare grey steel.',
                'Sparks flare along the last seam.', 'shell_welded'],
            ['deck_and_house', 'Deck And Superstructure', 'rough_build', ['deck_fitted', 'superstructure'], 'continuation_edit',
                'shell_closed', 'superstructure_set',
                'The flat main deck spans the whole length and the superstructure blocks sit stacked above it in bare steel.',
                'A worker walks the length of the new deck.', 'deck_walked'],
            ['machinery_seated', 'Machinery Seated Below', 'installation', ['machinery_installed'], 'continuation_edit',
                'superstructure_set', 'machinery_seated',
                'Two engines sit seated in the open engine room, their mounts bolted and the hatch above them clear.',
                'A fitter torques the forward mounting bolts.', 'mounts_torqued'],
            ['surfaces_done', 'Paint And Glazing', 'finishing', ['surface_faired', 'painted', 'glazed'], 'continuation_edit',
                'machinery_seated', 'finished_surfaces',
                'The hull sides are faired smooth, finished in deep navy paint, with dark tinted glazing fitted in every opening.',
                'A polisher passes along the painted flank.', 'flank_polished'],
            ['first_water', 'Launched And Running', 'testing', ['launched', 'sea_trial'], 'hard_cut_edit',
                'finished_surfaces', 'afloat_at_sea',
                'A wide shot from the water holds the vessel afloat under an overcast sky, crew at the bridge wing rail.',
                'The bow wave builds along the forward entry.', 'running_at_speed'],
            ['in_service', 'Vessel In Service', 'in_use', ['vessel_in_service'], 'hard_cut_edit',
                'afloat_at_sea', 'vessel_in_service',
                'A wide shot at golden hour holds the finished vessel at anchor in calm water, guests along the upper deck.',
                'A tender pulls away from the stern platform.', 'tender_away'],
        ];

        $scenes = [];
        $group = null;
        $previousCode = '';

        foreach ($rows as $row) {
            [$code, $title, $phase, $milestones, $mode, $before, $state, $delta, $action, $end] = $row;

            $opens = $group === null || $mode === ScenePreservationPrompt::HARD_CUT;

            if ($opens) {
                $group = 'g_'.$code;
            }

            $scenes[] = [
                'scene_code' => $code,
                'title' => $title,
                'purpose' => 'This step earns its place in the build.',
                'phase' => $phase,
                'milestone_keys' => $milestones,
                'basis' => 'inferred_process',
                'state_before' => $before,
                'scene_state' => $state,
                'transition_mode' => $mode,
                'continuity_group' => $group,
                'source_scene_code' => $opens ? '' : $previousCode,
                'camera_change_reason' => $opens
                    ? 'Open on this viewpoint so the work stays visible.'
                    : '',
                'camera_mode' => 'locked',
                'delta' => $delta,
                'video' => [
                    'action' => $action,
                    'preserve' => 'The hall, the existing structure and the support positions stay fixed.',
                    'end_state' => $end,
                ],
            ];

            $previousCode = $code;
        }

        return json_encode(['scenes' => $scenes], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $override */
    private function planWith(int $index, array $override): string
    {
        $plan = json_decode($this->plan(), true, 512, JSON_THROW_ON_ERROR);
        $plan['scenes'][$index] = array_replace($plan['scenes'][$index], $override);

        return json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array{0: string, 1: string} */
    private function category(): array
    {
        $id = (string) Str::uuid();
        $slug = 'test-yacht-'.uniqid();

        DB::table('categories')->insert([
            'id' => $id,
            'name' => 'TEST scene plan category '.uniqid(),
            'slug' => $slug,
        ]);

        return [$id, $slug];
    }

    private function admin(string $role = 'member'): Admin
    {
        $roleId = (string) Str::uuid();

        DB::table('roles')->insert(['id' => $roleId, 'name' => $role]);

        return Admin::create([
            'name' => 'TEST scene plan admin '.uniqid(),
            'email' => 'test_scene_plan_'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'role_id' => $roleId,
        ]);
    }

    private function article(string $categoryId): string
    {
        $keywordId = (string) Str::uuid();

        DB::table('keywords')->insert([
            'id' => $keywordId,
            'name' => 'TEST scene plan keyword '.uniqid(),
            'category_id' => $categoryId,
        ]);

        return (string) Article::create([
            'keyword_id' => $keywordId,
            'category_id' => $categoryId,
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('', true)),
            'source_title' => 'TEST scene plan source',
            'title' => 'TEST scene plan article '.uniqid(),
            'slug' => 'test-scene-plan-'.uniqid(),
            'content' => 'A yard begins a new steel motor yacht.',
            'status' => 'pending',
        ])->id;
    }

    private function approvedAnchor(string $prompt): void
    {
        $anchor = VideoDesignImage::create([
            'project_id' => $this->project->id,
            'image_code' => 'anchor_'.uniqid(),
            'image_type' => 'identity_anchor',
            'prompt_spec_json' => ['prompt' => $prompt],
            'prompt_sha256' => hash('sha256', uniqid('', true)),
            'status' => 'rendered',
            'revision' => 1,
        ]);

        $path = 'anchors/'.uniqid().'.png';
        Storage::disk('video_artifacts')->put($path, 'anchor-bytes');

        $artifact = VideoArtifact::create([
            'project_id' => $this->project->id,
            'design_image_id' => $anchor->id,
            'artifact_type' => 'image',
            'role' => 'candidate',
            'storage_disk' => 'video_artifacts',
            'storage_path' => $path,
            'mime_type' => 'image/png',
            'sha256' => hash('sha256', 'anchor-bytes'),
            'width' => 1536,
            'height' => 1024,
        ]);

        $anchor->forceFill([
            'selected_artifact_id' => $artifact->id,
            'status' => DesignImageStatus::APPROVED->value,
            'approved_at' => now(),
        ])->save();
    }

    private function inspirationBrief(): void
    {
        VideoPlanningStage::create([
            'project_id' => $this->project->id,
            'planning_revision' => 1,
            'stage' => PlanningStageName::INSPIRATION->value,
            'status' => VideoPlanningStageStatus::SUCCEEDED->value,
            'input_json' => ['article_id' => $this->project->article_id],
            'input_hash' => hash('sha256', 'inspiration'),
            'output_json' => [
                'article_focus' => 'A yard begins a new steel motor yacht.',
                'article_patterns' => ['build sequence'],
                'source_insights' => [],
                'excluded_context' => [],
                'uncovered_aspects' => [],
            ],
            'raw_response' => '{}',
            'output_hash' => hash('sha256', '{}'),
        ]);
    }
}
