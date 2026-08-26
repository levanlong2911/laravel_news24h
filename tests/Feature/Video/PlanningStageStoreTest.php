<?php

namespace Tests\Feature\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Enums\VideoSessionStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Models\VideoSession;
use App\Services\Video\PlanningStageStore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PlanningStageStoreTest extends TestCase
{
    use DatabaseTransactions;

    private PlanningStageStore $store;

    private VideoSession $session;

    private VideoProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new PlanningStageStore;
        $this->project = VideoProject::create(['title' => 'TEST stage '.uniqid()]);
        $this->session = VideoSession::create([
            'project_id' => $this->project->id,
            'code' => 'test_stage_'.uniqid(),
            'status' => VideoSessionStatus::PLANNING->value,
        ]);
    }

    public function test_a_first_claim_opens_the_stage_and_records_its_input(): void
    {
        [$stage, $token, $reason] = $this->store->claim(
            $this->session->id, 0, PlanningStageName::INSPIRATION, ['title' => 'A yacht'],
        );

        $this->assertSame('claimed', $reason);
        $this->assertNotNull($token);
        $this->assertSame(VideoPlanningStageStatus::RUNNING->value, $stage->status);
        $this->assertSame(['title' => 'A yacht'], $stage->input_json);
        $this->assertSame(64, strlen($stage->input_hash));
    }

    public function test_a_second_worker_cannot_claim_a_stage_someone_else_holds(): void
    {
        $this->store->claim($this->session->id, 0, PlanningStageName::INSPIRATION, []);

        [, $token, $reason] = $this->store->claim($this->session->id, 0, PlanningStageName::INSPIRATION, []);

        $this->assertSame('claimed_by_other', $reason);
        $this->assertNull($token);
    }

    public function test_a_succeeded_stage_is_never_claimed_again(): void
    {
        // Day la bat bien "khong tra tien lai": chang da xong thi lan goi sau
        // KHONG duoc cap token, nen job khong co duong nao goi model lan nua.
        [$stage, $token] = $this->store->claim($this->session->id, 0, PlanningStageName::INSPIRATION, []);
        $this->store->finishSucceeded($stage->id, $token, '{"brief":"done"}', ['brief' => 'done'], ['cost_usd' => 0.002]);

        [$again, $newToken, $reason] = $this->store->claim($this->session->id, 0, PlanningStageName::INSPIRATION, []);

        $this->assertSame('already_succeeded', $reason);
        $this->assertNull($newToken);
        $this->assertSame(['brief' => 'done'], $again->output_json);
    }

    public function test_finishing_with_a_stale_token_writes_nothing(): void
    {
        [$stage, $token] = $this->store->claim($this->session->id, 0, PlanningStageName::CONCEPT, []);
        $this->store->finishSucceeded($stage->id, $token, '{"concept":"first"}', ['concept' => 'first'], []);

        $wrote = $this->store->finishSucceeded($stage->id, (string) \Illuminate\Support\Str::uuid(), '{"concept":"second"}', ['concept' => 'second'], []);

        $this->assertFalse($wrote);
        $this->assertSame(['concept' => 'first'], VideoPlanningStage::find($stage->id)->output_json);
    }

    public function test_a_failed_stage_can_be_claimed_again(): void
    {
        [$stage, $token] = $this->store->claim($this->session->id, 0, PlanningStageName::CONCEPT, []);
        $this->store->finishFailed($stage->id, $token, 'Sonnet tu choi');

        [, $newToken, $reason] = $this->store->claim($this->session->id, 0, PlanningStageName::CONCEPT, []);

        $this->assertSame('claimed', $reason);
        $this->assertNotNull($newToken);
    }

    public function test_output_is_readable_only_after_the_stage_succeeded(): void
    {
        [$stage, $token] = $this->store->claim($this->session->id, 0, PlanningStageName::INSPIRATION, []);

        $this->assertNull($this->store->outputOf($this->session->id, 0, PlanningStageName::INSPIRATION));

        $this->store->finishSucceeded($stage->id, $token, '{"brief":"ready"}', ['brief' => 'ready'], []);

        $this->assertSame(
            ['brief' => 'ready'],
            $this->store->outputOf($this->session->id, 0, PlanningStageName::INSPIRATION),
        );
    }

    public function test_the_raw_model_response_survives_verbatim_and_is_what_gets_hashed(): void
    {
        $raw = "```json\n{\"design_focus\": \"hull\"}\n```";

        [$stage, $token] = $this->store->claim($this->session->id, 0, PlanningStageName::INSPIRATION, []);
        $this->store->finishSucceeded($stage->id, $token, $raw, ['design_focus' => 'hull'], []);

        $this->assertSame($raw, $this->store->rawResponseOf($this->session->id, 0, PlanningStageName::INSPIRATION));
        $this->assertSame(hash('sha256', $raw), VideoPlanningStage::find($stage->id)->output_hash);
    }

    public function test_a_new_planning_revision_does_not_reuse_the_old_stage(): void
    {
        [$stage, $token] = $this->store->claim($this->session->id, 0, PlanningStageName::INSPIRATION, []);
        $this->store->finishSucceeded($stage->id, $token, '{"brief":"rev0"}', ['brief' => 'rev0'], []);

        [, $newToken, $reason] = $this->store->claim($this->session->id, 1, PlanningStageName::INSPIRATION, []);

        $this->assertSame('claimed', $reason);
        $this->assertNotNull($newToken);
        $this->assertSame(['brief' => 'rev0'], $this->store->outputOf($this->session->id, 0, PlanningStageName::INSPIRATION));
    }

    public function test_a_project_scoped_stage_records_the_project_not_the_session(): void
    {
        [$stage, $token, $reason] = $this->store->claimProjectStage(
            $this->project->id, PlanningStageName::INSPIRATION, ['title' => 'A yacht'],
        );

        $this->assertSame('claimed', $reason);
        $this->assertNotNull($token);
        $this->assertSame($this->project->id, $stage->project_id);
        $this->assertNull($stage->session_id);
    }

    public function test_two_projects_do_not_see_each_others_stages(): void
    {
        $other = VideoProject::create(['title' => 'TEST stage other '.uniqid()]);

        [$mine, $token] = $this->store->claimProjectStage($this->project->id, PlanningStageName::INSPIRATION, []);
        $this->store->finishSucceeded($mine->id, $token, 'raw', ['article_focus' => 'cua toi']);

        $this->assertNull($this->store->latestOutputForProject($other->id, PlanningStageName::INSPIRATION));
        $this->assertSame(
            ['article_focus' => 'cua toi'],
            $this->store->latestOutputForProject($this->project->id, PlanningStageName::INSPIRATION),
        );
    }

    public function test_the_latest_revision_wins_when_the_article_changed(): void
    {
        [$first, $t1] = $this->store->claimProjectStage($this->project->id, PlanningStageName::INSPIRATION, ['content' => 'ban goc']);
        $this->store->finishSucceeded($first->id, $t1, 'raw', ['article_focus' => 'ban 1']);

        [$second, $t2, $reason] = $this->store->claimProjectStage($this->project->id, PlanningStageName::INSPIRATION, ['content' => 'da sua']);

        $this->assertSame('claimed', $reason);
        $this->assertSame(2, $second->planning_revision);

        $this->store->finishSucceeded($second->id, $t2, 'raw', ['article_focus' => 'ban 2']);

        $this->assertSame(
            ['article_focus' => 'ban 2'],
            $this->store->latestOutputForProject($this->project->id, PlanningStageName::INSPIRATION),
        );
    }

    public function test_the_same_article_is_never_analysed_twice(): void
    {
        $input = ['content' => 'khong doi'];

        [$first, $token] = $this->store->claimProjectStage($this->project->id, PlanningStageName::INSPIRATION, $input);
        $this->store->finishSucceeded($first->id, $token, 'raw', ['article_focus' => 'ban dau']);

        [$again, $newToken, $reason] = $this->store->claimProjectStage($this->project->id, PlanningStageName::INSPIRATION, $input);

        $this->assertSame('already_succeeded', $reason);
        $this->assertNull($newToken);
        $this->assertSame(['article_focus' => 'ban dau'], $again->output_json);
    }

    public function test_a_second_click_while_the_first_is_still_running_gets_nothing(): void
    {
        $input = ['content' => 'dang chay'];

        [, $token] = $this->store->claimProjectStage($this->project->id, PlanningStageName::INSPIRATION, $input);
        $this->assertNotNull($token);

        [, $secondToken, $reason] = $this->store->claimProjectStage($this->project->id, PlanningStageName::INSPIRATION, $input);

        $this->assertSame('claimed_by_other', $reason);
        $this->assertNull($secondToken);
        $this->assertSame(1, VideoPlanningStage::where('project_id', $this->project->id)->count());
    }

    public function test_a_dead_run_is_taken_over_once_its_lease_expired(): void
    {
        [$dead, $token] = $this->store->claimProjectStage($this->project->id, PlanningStageName::INSPIRATION, ['content' => 'chet giua chung']);
        $dead->update(['lease_expires_at' => now()->subMinute()]);

        [$taken, $newToken, $reason] = $this->store->claimProjectStage($this->project->id, PlanningStageName::INSPIRATION, ['content' => 'chet giua chung']);

        $this->assertSame('claimed', $reason);
        $this->assertNotNull($newToken);
        $this->assertNotSame($token, $newToken);
        $this->assertSame($dead->id, $taken->id);
        $this->assertSame(1, VideoPlanningStage::where('project_id', $this->project->id)->count());
    }

    public function test_an_unknown_project_cannot_be_claimed(): void
    {
        [$stage, $token, $reason] = $this->store->claimProjectStage(
            'khong-co-that', PlanningStageName::INSPIRATION, [],
        );

        $this->assertNull($stage);
        $this->assertNull($token);
        $this->assertSame('project_not_found', $reason);
    }

    public function test_a_project_cannot_hold_two_stages_at_the_same_revision(): void
    {
        $this->store->claimProjectStage($this->project->id, PlanningStageName::INSPIRATION, []);

        $this->expectException(UniqueConstraintViolationException::class);

        VideoPlanningStage::create([
            'project_id' => $this->project->id,
            'planning_revision' => 1,
            'stage' => PlanningStageName::INSPIRATION->value,
            'status' => VideoPlanningStageStatus::RUNNING->value,
        ]);
    }

    public function test_a_paid_call_that_failed_still_keeps_what_it_paid_for(): void
    {
        // 2026-08-23: mot luot concept fail voi cost_usd = 0.0269 nhung
        // raw_response = NULL, nen khong ai doc duoc Sonnet da tra ve con so nao.
        // Duong thanh cong luu raw, hash, model, phien ban; duong that bai chi
        // luu tien. Bat doi xung do chinh la con bo.
        [$stage, $token] = $this->store->claim($this->session->id, 0, PlanningStageName::CONCEPT, []);

        $this->store->finishFailed($stage->id, $token, 'design_length_m ngoai khoang', [
            'model' => 'sonnet',
            'instruction_version' => 'concept-v11',
            'tokens_in' => 1200,
            'tokens_out' => 800,
            'cost_usd' => 0.0269,
        ], '{"design_length_m": 36.6}');

        $row = VideoPlanningStage::find($stage->id);

        $this->assertSame('{"design_length_m": 36.6}', $row->raw_response);
        $this->assertSame(hash('sha256', '{"design_length_m": 36.6}'), $row->output_hash);
        $this->assertSame('sonnet', $row->model);
        $this->assertSame('concept-v11', $row->instruction_version);
        $this->assertSame(1200, $row->tokens_in);
        $this->assertEqualsWithDelta(0.0269, (float) $row->cost_usd, 0.00001);
    }

    public function test_a_failure_with_no_answer_records_no_answer(): void
    {
        // Loi mang thi THAT SU khong co raw. Ghi chuoi rong vao day se khien mot
        // hang "co raw nhung raw rong" khong phan biet duoc voi "chua bao gio
        // nhan duoc gi".
        [$stage, $token] = $this->store->claim($this->session->id, 0, PlanningStageName::CONCEPT, []);

        $this->store->finishFailed($stage->id, $token, 'cURL error 28: timeout');

        $row = VideoPlanningStage::find($stage->id);

        $this->assertNull($row->raw_response);
        $this->assertNull($row->output_hash);
        $this->assertNull($row->model);
    }

    public function test_a_finished_stage_keeps_the_alias_and_the_provider_model_apart(): void
    {
        [$stage, $token] = $this->store->claim($this->session->id, 0, PlanningStageName::CONCEPT, []);

        $this->store->finishSucceeded($stage->id, $token, '{"ok": true}', ['ok' => true], [
            'model' => 'sonnet',
            'provider_model' => 'claude-sonnet-4-6',
            'instruction_version' => 'concept-v15',
            'tokens_in' => 100,
            'tokens_out' => 50,
            'cost_usd' => 0.001,
        ]);

        $row = VideoPlanningStage::find($stage->id);

        $this->assertSame('sonnet', $row->model);
        $this->assertSame('claude-sonnet-4-6', $row->provider_model);
    }

    public function test_a_failed_stage_records_the_provider_model_too(): void
    {
        [$stage, $token] = $this->store->claim($this->session->id, 0, PlanningStageName::CONCEPT, []);

        $this->store->finishFailed($stage->id, $token, 'khong hop le', [
            'model' => 'sonnet',
            'provider_model' => 'claude-sonnet-4-6',
        ], '{"raw": 1}');

        $this->assertSame('claude-sonnet-4-6', VideoPlanningStage::find($stage->id)->provider_model);
    }

    public function test_an_unknown_provider_model_is_stored_as_null_not_as_a_guess(): void
    {
        [$stage, $token] = $this->store->claim($this->session->id, 0, PlanningStageName::CONCEPT, []);

        $this->store->finishFailed($stage->id, $token, 'cURL error 28: timeout');

        $this->assertNull(VideoPlanningStage::find($stage->id)->provider_model);
    }
}
