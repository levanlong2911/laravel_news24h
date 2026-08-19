<?php

namespace Tests\Feature\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Enums\VideoSessionStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Models\VideoSession;
use App\Services\Video\PlanningStageStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PlanningStageStoreTest extends TestCase
{
    use DatabaseTransactions;

    private PlanningStageStore $store;

    private VideoSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new PlanningStageStore;
        $project = VideoProject::create(['title' => 'TEST stage '.uniqid()]);
        $this->session = VideoSession::create([
            'project_id' => $project->id,
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
}
