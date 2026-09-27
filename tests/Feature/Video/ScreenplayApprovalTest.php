<?php

namespace Tests\Feature\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Models\VideoReviewDecision;
use App\Services\Video\ScreenplayApprovalService;
use App\Services\Video\ProductionSelectionService;
use App\Video\Screenplay\ScreenplayContentHash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScreenplayApprovalTest extends TestCase
{
    private bool $inTransaction = false;

    private VideoProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $application = (string) DB::connection('mysql')
            ->selectOne('SELECT DATABASE() AS db')->db;
        DB::purge('testing');
        $isolated = (string) DB::connection('testing')->selectOne('SELECT DATABASE() AS db')->db;

        if ($isolated === '' || $isolated === $application) {
            $this->markTestSkipped("Refusing to write to the application database ({$application}).");
        }

        config(['database.default' => 'testing']);

        foreach (['video_projects', 'video_planning_stages', 'video_review_decisions'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->markTestSkipped("The isolated database is missing {$table}.");
            }
        }

        DB::beginTransaction();
        $this->inTransaction = true;
        $this->project = VideoProject::create(['title' => 'TEST approval '.uniqid()]);
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
    private function screenplay(): array
    {
        return [
            'schema_version' => 'screenplay_v4',
            'logline' => 'A vessel is built around one coherent spatial decision.',
            'scenes' => [['id' => 'sc_01', 'action' => 'The design team releases the agreed arrangement.']],
            'coverage' => [],
            'warnings' => ['Operational metadata is not approved content.'],
            'author_model' => 'claude-sonnet-5',
        ];
    }

    private function stage(?VideoProject $project = null, int $revision = 3, ?string $logline = null): VideoPlanningStage
    {
        $output = $this->screenplay();
        if ($logline !== null) {
            $output['logline'] = $logline;
        }

        return VideoPlanningStage::create([
            'project_id' => ($project ?? $this->project)->id,
            'planning_revision' => $revision,
            'stage' => PlanningStageName::SCREENPLAY->value,
            'status' => VideoPlanningStageStatus::SUCCEEDED->value,
            'input_json' => [],
            'input_hash' => hash('sha256', uniqid('', true)),
            'output_json' => $output,
        ]);
    }

    public function test_approval_is_bound_to_the_stage_contract_and_content_hash(): void
    {
        $stage = $this->stage();
        $operation = (string) Str::uuid();
        $service = new ScreenplayApprovalService;

        [$decision, $reason] = $service->approve(
            $this->project->id, $stage->id, null, $operation, 'Ready for breakdown.',
        );

        $this->assertSame('approved', $reason);
        $this->assertSame('screenplay', $decision->entity_type);
        $this->assertSame($stage->id, $decision->entity_id);
        $this->assertSame('screenplay_v4', $decision->metadata_json['contract_version']);
        $this->assertSame(ScreenplayContentHash::of($stage->output_json), $decision->metadata_json['content_hash']);
        $this->assertTrue($decision->is($service->matchingApproval($stage)));

        $changed = $stage->output_json;
        $changed['scenes'][0]['action'] = 'The arrangement has changed.';
        $stage->forceFill(['output_json' => $changed])->save();

        $this->assertNull($service->matchingApproval($stage->refresh()));
    }

    public function test_retry_replays_one_decision_but_a_new_operation_appends_history(): void
    {
        $stage = $this->stage();
        $operation = (string) Str::uuid();
        $service = new ScreenplayApprovalService;

        [$first, $firstReason] = $service->approve($this->project->id, $stage->id, null, $operation);
        [$replayed, $replayReason] = $service->approve($this->project->id, $stage->id, null, $operation);
        [$second, $secondReason] = $service->approve(
            $this->project->id, $stage->id, null, (string) Str::uuid(),
        );

        $this->assertSame('approved', $firstReason);
        $this->assertSame('replayed', $replayReason);
        $this->assertTrue($first->is($replayed));
        $this->assertSame('approved', $secondReason);
        $this->assertSame(2, $second->revision);
        $this->assertSame(2, VideoReviewDecision::query()->where('entity_id', $stage->id)->count());
    }

    public function test_reusing_an_operation_for_another_payload_is_rejected(): void
    {
        $stage = $this->stage();
        $operation = (string) Str::uuid();
        $service = new ScreenplayApprovalService;

        $service->approve($this->project->id, $stage->id, null, $operation, 'First review.');
        [$decision, $reason] = $service->approve(
            $this->project->id, $stage->id, null, $operation, 'Different review.',
        );

        $this->assertNull($decision);
        $this->assertSame('approval_operation_conflict', $reason);
        $this->assertSame(1, VideoReviewDecision::query()->where('entity_id', $stage->id)->count());
    }

    public function test_another_projects_or_failed_stage_cannot_be_approved(): void
    {
        $other = VideoProject::create(['title' => 'TEST other approval '.uniqid()]);
        $foreign = $this->stage($other);
        $failed = $this->stage();
        $failed->forceFill(['status' => VideoPlanningStageStatus::FAILED->value])->save();
        $service = new ScreenplayApprovalService;

        foreach ([$foreign, $failed] as $stage) {
            [$decision, $reason] = $service->approve(
                $this->project->id, $stage->id, null, (string) Str::uuid(),
            );
            $this->assertNull($decision);
            $this->assertSame('screenplay_not_approvable', $reason);
        }

        $this->assertSame(0, VideoReviewDecision::query()->count());
    }

    public function test_a_legacy_or_unversioned_screenplay_cannot_be_approved_for_production(): void
    {
        $service = new ScreenplayApprovalService;

        foreach (['screenplay_v3', ''] as $index => $version) {
            $stage = $this->stage(revision: 10 + $index);
            $output = $stage->output_json;
            $output['schema_version'] = $version;
            $stage->forceFill(['output_json' => $output])->save();

            [$decision, $reason] = $service->approve(
                $this->project->id, $stage->id, null, (string) Str::uuid(),
            );

            $this->assertNull($decision);
            $this->assertSame('screenplay_not_approvable', $reason);
        }

        $this->assertSame(0, VideoReviewDecision::query()->count());
    }

    public function test_decisions_cannot_be_updated_or_deleted_through_the_model(): void
    {
        $stage = $this->stage();
        [$decision] = (new ScreenplayApprovalService)->approve(
            $this->project->id, $stage->id, null, (string) Str::uuid(),
        );

        try {
            $decision->forceFill(['reason' => 'rewrite'])->save();
            $this->fail('An existing review decision was updated.');
        } catch (\LogicException $e) {
            $this->assertSame('Review decisions are append-only.', $e->getMessage());
        }

        $this->expectException(\LogicException::class);
        $decision->delete();
    }

    public function test_production_selection_requires_matching_approval_and_compare_and_swap(): void
    {
        $stage = $this->stage();
        $approvals = new ScreenplayApprovalService;
        $selector = new ProductionSelectionService($approvals);

        [$missing, $missingReason] = $selector->selectScreenplay($this->project->id, $stage->id, 0);
        $this->assertNull($missing);
        $this->assertSame('screenplay_not_approved', $missingReason);

        $approvals->approve($this->project->id, $stage->id, null, (string) Str::uuid());
        [$selected, $selectedReason] = $selector->selectScreenplay($this->project->id, $stage->id, 0);

        $this->assertSame('selected', $selectedReason);
        $this->assertSame($stage->id, $selected->selected_screenplay_stage_id);
        $this->assertSame(1, $selected->production_selection_version);

        [$replayed, $replayReason] = $selector->selectScreenplay($this->project->id, $stage->id, 0);
        $this->assertSame($stage->id, $replayed->selected_screenplay_stage_id);
        $this->assertSame('already_selected', $replayReason);
        $this->assertSame(1, $replayed->production_selection_version);
    }

    public function test_new_draft_does_not_move_production_and_an_approved_switch_keeps_history(): void
    {
        $approvals = new ScreenplayApprovalService;
        $selector = new ProductionSelectionService($approvals);
        $first = $this->stage(revision: 3, logline: 'First production screenplay.');
        $approvals->approve($this->project->id, $first->id, null, (string) Str::uuid());
        $selector->selectScreenplay($this->project->id, $first->id, 0);

        $draft = $this->stage(revision: 4, logline: 'A later draft.');
        $unchanged = $this->project->refresh();
        $this->assertSame($first->id, $unchanged->selected_screenplay_stage_id);
        $this->assertSame(1, $unchanged->production_selection_version);

        $plan = VideoPlanningStage::create([
            'project_id' => $this->project->id,
            'planning_revision' => 1,
            'stage' => PlanningStageName::SCENE_PLAN->value,
            'status' => VideoPlanningStageStatus::SUCCEEDED->value,
            'input_json' => [],
            'input_hash' => hash('sha256', uniqid('', true)),
            'output_json' => ['scenes' => []],
        ]);
        $unchanged->forceFill(['selected_scene_plan_stage_id' => $plan->id])->save();

        $approvals->approve($this->project->id, $draft->id, null, (string) Str::uuid());
        [$switched, $reason] = $selector->selectScreenplay($this->project->id, $draft->id, 1);

        $this->assertSame('selected', $reason);
        $this->assertSame($draft->id, $switched->selected_screenplay_stage_id);
        $this->assertNull($switched->selected_scene_plan_stage_id);
        $this->assertSame(2, $switched->production_selection_version);
        $this->assertSame(
            [$first->id, $draft->id],
            array_column($switched->metadata_json['production_selection_history'], 'screenplay_stage_id'),
        );
        $this->assertNotNull(VideoPlanningStage::query()->find($plan->id), 'Switching never deletes the old plan.');
    }
}
