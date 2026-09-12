<?php

namespace Tests\Feature\Video;

use App\Enums\PlanningStageName;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Video\PlanningStageStore;
use App\Video\Scene\ScenePreservationPrompt;
use App\Video\Scene\ScenePurpose;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScenePlanContractTest extends TestCase
{
    use DatabaseTransactions;

    public function test_every_planning_stage_names_its_successor(): void
    {
        $budget = count(PlanningStageName::cases()) + 1;
        $walked = [];
        $stage = PlanningStageName::INSPIRATION;

        while ($stage !== null && count($walked) < $budget) {
            $walked[] = $stage->value;
            $stage = $stage->next();
        }

        $this->assertNull($stage, 'the stage chain never terminates — a case points backwards');

        $this->assertSame(
            ['inspiration', 'concept', 'anchor_prompt', 'scene_plan', 'finalize'],
            $walked,
        );

        $this->assertSame(count(PlanningStageName::cases()), count($walked));
    }

    public function test_an_unknown_transition_mode_is_refused_rather_than_defaulted(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ScenePreservationPrompt::forMode('whatever');
    }

    public function test_the_two_preservation_blocks_disagree_about_the_camera(): void
    {
        $continuation = ScenePreservationPrompt::forMode(ScenePreservationPrompt::CONTINUATION);
        $hardCut = ScenePreservationPrompt::forMode(ScenePreservationPrompt::HARD_CUT);

        $this->assertNotSame($continuation, $hardCut);
        $this->assertStringContainsString('camera position', $continuation);
        $this->assertStringNotContainsString('camera position', $hardCut);

        foreach ([$continuation, $hardCut] as $block) {
            $this->assertStringNotContainsString('construction state', $block);
        }
    }

    public function test_the_database_refuses_a_project_scene_without_a_valid_transition_mode(): void
    {
        $project = VideoProject::create(['title' => 'TEST scene contract '.uniqid()]);

        foreach ([null, 'whatever'] as $mode) {
            $refused = false;

            try {
                DB::table('video_render_scenes')->insert($this->sceneRow($project->id, $mode));
            } catch (QueryException) {
                $refused = true;
            }

            $this->assertTrue($refused, 'transition_mode '.var_export($mode, true).' should be refused');
        }

        foreach (ScenePreservationPrompt::modes() as $mode) {
            DB::table('video_render_scenes')->insert($this->sceneRow($project->id, $mode));
        }

        $this->assertSame(
            count(ScenePreservationPrompt::modes()),
            DB::table('video_render_scenes')->where('project_id', $project->id)->count(),
        );
    }

    public function test_a_scene_row_belongs_to_exactly_one_parent(): void
    {
        $project = VideoProject::create(['title' => 'TEST scene scope '.uniqid()]);
        $row = $this->sceneRow($project->id, ScenePreservationPrompt::CONTINUATION);

        $this->assertTrue(
            $this->refuses(['project_id' => null] + $row),
            'a scene with neither parent key should be refused',
        );

        $this->assertTrue(
            $this->refuses(['render_plan_id' => $this->renderPlanId($project->id)] + $row),
            'a scene claiming both a project and a render plan should be refused',
        );
    }

    public function test_a_render_plan_scene_carries_none_of_the_project_payload(): void
    {
        $project = VideoProject::create(['title' => 'TEST legacy scope '.uniqid()]);
        $planId = $this->renderPlanId($project->id);

        DB::table('video_render_scenes')->insert([
            'id' => (string) Str::uuid(),
            'render_plan_id' => $planId,
            'project_id' => null,
            'revision' => null,
            'scene_index' => 1,
            'scene_code' => 'legacy_'.uniqid(),
            'delta_prompt' => null,
            'prompt_version' => null,
            'transition_mode' => null,
        ]);

        $row = DB::table('video_render_scenes')->where('render_plan_id', $planId)->sole();

        foreach ([
            'project_id', 'revision', 'delta_prompt', 'prompt_version', 'transition_mode',
            'references_json', 'reference_manifest_hash', 'design_image_id',
            'milestone_keys', 'basis', 'video_plan_json',
            'continuity_group', 'source_scene_code', 'camera_change_reason',
        ] as $column) {
            $this->assertNull($row->{$column}, $column.' must stay empty on a render-plan scene');
        }
    }

    public function test_the_lifecycle_runs_from_design_through_sea_trial_to_operation(): void
    {
        $phases = config('video.creation_arc.phase_sets.vessel.phases');

        $this->assertIsArray($phases);
        $this->assertSame(
            ['design', 'construction_hull', 'construction_engine', 'craftsmanship',
                'sea_trial', 'experience_exterior', 'experience_onboard'],
            array_keys($phases),
        );

        foreach ($phases as $key => $phase) {
            $this->assertNotNull(
                ScenePurpose::tryFrom((string) $phase['purpose']),
                $key.' declares a purpose outside ScenePurpose',
            );
        }

        $this->assertSame('finished_vessel', $phases['sea_trial']['requires_state']);
    }

    public function test_the_planner_skill_carries_exactly_one_insertion_point(): void
    {
        $skill = (string) file_get_contents((string) config('video.scene_plan.prompt_path'));

        $this->assertSame(1, substr_count($skill, '{{PLANNING_INPUT}}'));
        $this->assertStringNotContainsString('{{PLANNING\_INPUT}}', $skill);
        $this->assertStringContainsString('PLANNING INPUT:', $skill);

        foreach (ScenePreservationPrompt::modes() as $mode) {
            $this->assertStringContainsString($mode, $skill, 'skill never names '.$mode);
        }
    }

    /**
     * Van ban v1 duoc CHEP CUNG o day, khong lay tu implementation: neu lay tu
     * `continuationV1()` thi sua ham do se lam expected doi theo actual va chot
     * nay khong con chung minh duoc gi.
     */
    public function test_the_v1_preservation_text_is_frozen(): void
    {
        $this->assertSame(
            'The supplied image is the authoritative record of this subject and of the work already '
            .'done to it. The render keeps the same physical object: the same silhouette, proportions, '
            .'topology, structural relationships, permanent openings and overhangs, each exactly as the '
            .'image shows. It keeps the same camera position, framing and lighting, and it keeps every '
            .'structure that is already built, in the place the image shows it. The single change this '
            .'render makes is the one stated below; the build advances by that change and by nothing '
            .'else. Where anything conflicts, the identity in the supplied image wins.',
            ScenePreservationPrompt::forMode(
                ScenePreservationPrompt::CONTINUATION,
                ScenePreservationPrompt::LEGACY_VERSION,
            ),
        );

        $this->assertSame(
            'The supplied image is the authoritative record of this subject\'s identity: the same '
            .'silhouette, proportions, topology and permanent openings, each exactly as the image shows. '
            .'This render places that same subject in the situation stated below, which sets the camera, '
            .'the setting, the lighting and the composition for this frame. Where anything conflicts, '
            .'the identity in the supplied image wins.',
            ScenePreservationPrompt::forMode(
                ScenePreservationPrompt::HARD_CUT,
                ScenePreservationPrompt::LEGACY_VERSION,
            ),
        );
    }

    public function test_the_current_blocks_no_longer_freeze_the_whole_silhouette(): void
    {
        foreach (ScenePreservationPrompt::modes() as $mode) {
            $current = ScenePreservationPrompt::forMode($mode);
            $legacy = ScenePreservationPrompt::forMode($mode, ScenePreservationPrompt::LEGACY_VERSION);

            $this->assertNotSame($legacy, $current);
            $this->assertStringNotContainsString('the same silhouette', $current);
        }

        $this->assertStringContainsString(
            'the outline of the object changes only as far as that change requires',
            ScenePreservationPrompt::forMode(ScenePreservationPrompt::CONTINUATION),
        );
    }

    public function test_an_unknown_preservation_version_is_refused(): void
    {
        $this->assertSame(
            ['scene-preservation-v1', 'scene-preservation-v2', 'scene-preservation-v3'],
            ScenePreservationPrompt::versions(),
        );

        $this->expectException(\InvalidArgumentException::class);

        ScenePreservationPrompt::forMode(ScenePreservationPrompt::HARD_CUT, 'scene-preservation-v9');
    }

    /**
     * Chot chong XOA NHAM. No khong chung minh model tuan thu quy tac nao —
     * chi chung minh quy tac van con trong file.
     */
    public function test_the_planner_skill_still_carries_its_load_bearing_rules(): void
    {
        $skill = (string) file_get_contents((string) config('video.scene_plan.prompt_path'));

        foreach ([
            'STORYBOARD BEFORE PROMPTS',
            'No cutaway or transparent hull',
            'are separate concerns',
            'not a record of previous scene progress',
            'does not itself contain those numerical facts',
            'not freeze the position, visibility, occupancy',
            'may change viewpoint, location or time',
        ] as $rule) {
            $this->assertStringContainsString($rule, $skill, 'the skill no longer carries: '.$rule);
        }
    }

    public function test_a_new_pricing_table_does_not_reopen_a_finished_plan(): void
    {
        $project = VideoProject::create(['title' => 'TEST pricing hash '.uniqid()]);
        $store = app(PlanningStageStore::class);
        $input = ['brief_hash' => 'abc', 'model' => 'gpt-5.6-terra'];

        [$first, $token] = $store->claimProjectStage(
            $project->id, PlanningStageName::SCENE_PLAN, $input, false, ['pricing' => 'unpriced'],
        );

        $this->assertNotNull($token);

        $first->refresh();

        $this->assertSame(
            'unpriced',
            $first->input_json['_meta']['pricing'] ?? null,
            'the claim path must store its metadata beside the input',
        );

        $withoutMeta = $first->input_json;
        unset($withoutMeta['_meta']);

        $this->assertSame(
            hash('sha256', json_encode(
                $withoutMeta,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )),
            $first->input_hash,
            'the claim hash must be taken from the input without its metadata',
        );

        $this->assertTrue($store->finishSucceeded($first->id, $token, '{}', ['revision' => 1]));

        [$again, $second, $reason] = $store->claimProjectStage(
            $project->id, PlanningStageName::SCENE_PLAN, $input, false, ['pricing' => 'terra-v1'],
        );

        $this->assertNull($second, 'a pricing table is not a planning input');
        $this->assertSame('already_succeeded', $reason);
        $this->assertSame($first->id, $again->id);

        $first->refresh();

        $this->assertSame(
            'unpriced',
            $first->input_json['_meta']['pricing'] ?? null,
            'a refused claim must not rewrite the metadata of the finished row',
        );

        [, $third, $changed] = $store->claimProjectStage(
            $project->id,
            PlanningStageName::SCENE_PLAN,
            ['brief_hash' => 'different', 'model' => 'gpt-5.6-terra'],
            false,
            ['pricing' => 'unpriced'],
        );

        $this->assertNotNull($third, 'a changed brief must reopen the stage');
        $this->assertSame('claimed', $changed);
    }

    public function test_one_lost_attempt_is_recorded_once_whatever_the_pricing(): void
    {
        $project = VideoProject::create(['title' => 'TEST orphan dedup '.uniqid()]);
        $store = app(PlanningStageStore::class);
        $input = ['brief_hash' => 'abc'];

        $this->assertTrue($store->recordOrphanAttempt(
            $project->id, PlanningStageName::SCENE_PLAN, $input, ['pricing' => 'unpriced'],
            'stage-1', 'token-1', 'claim lost', [], '{"scenes":[]}',
        ));

        $this->assertFalse($store->recordOrphanAttempt(
            $project->id, PlanningStageName::SCENE_PLAN, $input, ['pricing' => 'terra-v1'],
            'stage-1', 'token-1', 'claim lost', [], '{"scenes":[]}',
        ), 'the same lost attempt must not be written twice');

        $this->assertTrue($store->recordOrphanAttempt(
            $project->id, PlanningStageName::SCENE_PLAN, $input, [],
            'stage-2', 'token-2', 'claim lost again', [], '{"scenes":[]}',
        ), 'a different lost attempt must get its own row');

        $this->assertSame(2, VideoPlanningStage::query()
            ->where('project_id', $project->id)
            ->where('stage', PlanningStageName::SCENE_PLAN->value)
            ->count());
    }

    public function test_a_lost_attempt_keeps_its_metadata_raw_and_usage(): void
    {
        $project = VideoProject::create(['title' => 'TEST orphan payload '.uniqid()]);
        $store = app(PlanningStageStore::class);

        $input = ['brief_hash' => 'abc', 'max_scenes' => 24];
        $raw = '{"scenes":[{"scene_code":"keel_laid"}]}';
        $usage = [
            'model' => 'openai',
            'provider_model' => 'gpt-5.6-terra',
            'instruction_version' => 'scene-plan-v1',
            'tokens_in' => 4210,
            'tokens_out' => 1180,
            'thinking_tokens' => 640,
        ];

        $this->assertTrue($store->recordOrphanAttempt(
            $project->id, PlanningStageName::SCENE_PLAN, $input, ['pricing' => 'unpriced'],
            'stage-lost', 'token-lost', 'claim lost before the ledger could record it',
            $usage, $raw,
        ));

        $row = VideoPlanningStage::query()
            ->where('project_id', $project->id)
            ->where('stage', PlanningStageName::SCENE_PLAN->value)
            ->sole();

        $this->assertSame('unpriced', $row->input_json['_meta']['pricing'] ?? null);
        $this->assertSame('abc', $row->input_json['brief_hash'] ?? null);
        $this->assertSame('stage-lost', $row->input_json['orphan_of_stage_id'] ?? null);
        $this->assertSame('token-lost', $row->input_json['orphan_claim_token'] ?? null);

        $withoutMeta = $row->input_json;
        unset($withoutMeta['_meta']);

        $this->assertSame(
            hash('sha256', json_encode(
                $withoutMeta,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )),
            $row->input_hash,
            'the stored hash must be taken from the input without its metadata',
        );

        $this->assertSame($raw, $row->raw_response);
        $this->assertSame(hash('sha256', $raw), $row->output_hash);
        $this->assertSame('gpt-5.6-terra', $row->provider_model);
        $this->assertSame('scene-plan-v1', $row->instruction_version);
        $this->assertSame(4210, (int) $row->tokens_in);
        $this->assertSame(1180, (int) $row->tokens_out);
        $this->assertSame(640, (int) $row->thinking_tokens);
        $this->assertNull($row->claim_token);

        $this->assertFalse($store->recordOrphanAttempt(
            $project->id, PlanningStageName::SCENE_PLAN, $input, ['pricing' => 'terra-v1'],
            'stage-lost', 'token-lost', 'a different error', ['tokens_in' => 1], '{"scenes":[]}',
        ));

        $row->refresh();

        $this->assertSame('unpriced', $row->input_json['_meta']['pricing'] ?? null);
        $this->assertSame($raw, $row->raw_response);
        $this->assertSame(hash('sha256', $raw), $row->output_hash);
        $this->assertSame('claim lost before the ledger could record it', $row->error_message);
        $this->assertSame(4210, (int) $row->tokens_in);
        $this->assertSame(1180, (int) $row->tokens_out);
        $this->assertSame(640, (int) $row->thinking_tokens);
    }

    public function test_planning_input_may_not_carry_the_reserved_metadata_key(): void
    {
        $project = VideoProject::create(['title' => 'TEST reserved meta '.uniqid()]);
        $store = app(PlanningStageStore::class);

        $this->expectException(\InvalidArgumentException::class);

        $store->claimProjectStage(
            $project->id,
            PlanningStageName::SCENE_PLAN,
            ['brief_hash' => 'abc', '_meta' => ['pricing' => 'forged']],
        );
    }

    /** @param array<string, mixed> $row */
    private function refuses(array $row): bool
    {
        try {
            DB::table('video_render_scenes')->insert($row);
        } catch (QueryException) {
            return true;
        }

        return false;
    }

    private function renderPlanId(string $projectId): string
    {
        $sessionId = (string) Str::uuid();

        DB::table('video_sessions')->insert([
            'id' => $sessionId,
            'project_id' => $projectId,
            'code' => 'test_'.uniqid(),
        ]);

        $planId = (string) Str::uuid();

        DB::table('video_render_plans')->insert([
            'id' => $planId,
            'session_id' => $sessionId,
            'schema_version' => '1.0',
            'plan_json' => '{}',
            'plan_hash' => hash('sha256', '{}'),
        ]);

        return $planId;
    }

    /** @return array<string, mixed> */
    private function sceneRow(string $projectId, ?string $mode): array
    {
        return [
            'id' => (string) Str::uuid(),
            'project_id' => $projectId,
            'revision' => 1,
            'scene_index' => random_int(1, 60000),
            'scene_code' => 'test_'.uniqid(),
            'delta_prompt' => 'The berth stands empty between the support rows.',
            'prompt_version' => ScenePreservationPrompt::VERSION,
            'transition_mode' => $mode,
        ];
    }
}
