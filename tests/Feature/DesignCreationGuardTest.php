<?php

namespace Tests\Feature;

use App\Enums\PlanningStageName;
use App\Models\AiPhase;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Video\DesignApprovalLock;
use App\Services\Video\DesignDnaService;
use App\Services\Video\DesignImageStore;
use App\Services\Video\ModelSheetService;
use App\Services\Video\PlanningStageStore;
use App\Services\Video\StageQueue;
use App\Services\Video\YachtDesignService;
use App\Video\ModelSheet\ModelSheetMaster;
use App\Video\YachtDesign\DesignRunPolicy;
use App\Video\YachtDesign\YachtDesignCheck;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\YachtDesignFixture;
use Tests\TestCase;

final class DesignCreationGuardTest extends TestCase
{
    use DatabaseTransactions;

    private const DISK = 'video_artifacts';

    private string $project;

    private string $category;

    private ModelSheetService $sheet;

    private PlanningStageStore $store;

    private VideoPlanningStage $design;

    private VideoPlanningStage $set;

    private string $artifact;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(self::DISK);
        Queue::fake();
        Http::fake();
        config(['video.async.enabled' => false]);
        $this->store = app(PlanningStageStore::class);
        $this->category = (string) (DB::table('categories')->where('slug', 'yacht')->value('id') ?? Str::uuid());
        DB::table('categories')->updateOrInsert(['id' => $this->category], ['name' => 'Yacht', 'slug' => 'yacht', 'created_at' => now(), 'updated_at' => now()]);
        $this->project = $this->newProject();

        foreach (['phase-3', config('video.superyacht.model_sheet.qa.phase_code')] as $code) {
            $phase = AiPhase::create([
                'category_id' => $this->category, 'workflow_code' => config('video.superyacht.workflow_code'), 'phase_code' => $code,
                'revision' => 1, 'kind' => 'phase', 'name' => $code, 'sort_order' => 1, 'prompt_content' => 'Test prompt',
                'content_sha256' => hash('sha256', 'Test prompt'), 'status' => AiPhase::PUBLISHED, 'is_current' => true,
            ]);
            $designPhase ??= $phase;
        }

        $answer = YachtDesignFixture::envelope($this->project);
        $answer['data']['canonical']['coordinate_system'] = 'x from bow to stern as fraction of LOA; y in metres, positive port from centreline; z in metres above design waterline.';
        $answer['data']['canonical']['hash'] = \App\Video\Superyacht\Support\CanonicalJson::hash($answer['data']['canonical'], ['hash']);
        $this->design = VideoPlanningStage::create([
            'project_id' => $this->project, 'stage' => PlanningStageName::YACHT_DESIGN->value, 'planning_revision' => 1,
            'status' => 'succeeded', 'input_json' => ['phase_id' => $designPhase->id], 'input_hash' => hash('sha256', 'design'),
            'raw_response' => json_encode($answer), 'output_json' => ['design' => $answer, 'check' => ['verdict' => YachtDesignCheck::PASS, 'warnings' => []]],
        ]);
        $this->sheet = app(ModelSheetService::class);
        // Isolate the already-passed design from DNA orchestration, as ModelSheetWithoutProxyTest does.
        (new ReflectionProperty($this->sheet, 'designPanels'))->setValue($this->sheet, [$this->project => [
            'current' => true, 'dna_stale' => false, 'stage_id' => $this->design->id, 'check' => ['verdict' => YachtDesignCheck::PASS], 'name' => 'Arclight',
        ]]);
        $this->set = $this->openSet();
        $this->artifact = $this->renderedMaster();
    }

    public function test_approving_locks_the_design_and_losing_the_files_does_not_reopen_it(): void
    {
        self::assertFalse($this->lock()->holds($this->project), 'a rendered master is not an approval');

        $this->approved();

        self::assertTrue($this->lock()->holds($this->project));

        Storage::fake(self::DISK);

        self::assertTrue($this->lock()->holds($this->project), 'the lock reads rows, a lost file is a problem of that file');

        $this->expectException(RuntimeException::class);
        $this->sheet->approvedSheet($this->project, false);
    }

    public function test_an_approved_image_without_its_review_or_with_another_hash_is_no_approval(): void
    {
        $this->approved();
        $spec = (array) json_decode((string) DB::table('video_design_images')->where('project_id', $this->project)->value('prompt_spec_json'), true);

        DB::table('video_design_images')->where('project_id', $this->project)->update(['prompt_spec_json' => json_encode(['design_canonical_hash' => 'other'] + $spec)]);
        self::assertFalse($this->lock()->holds($this->project), 'another canonical hash');

        DB::table('video_design_images')->where('project_id', $this->project)->update(['prompt_spec_json' => json_encode($spec)]);
        self::assertTrue($this->lock()->holds($this->project));

        DB::table('video_planning_stages')->where('project_id', $this->project)->where('stage', PlanningStageName::MODEL_SHEET_REVIEW->value)->delete();
        self::assertFalse($this->lock()->holds($this->project), 'no review row');
    }

    public function test_a_newer_design_is_not_locked_by_the_approval_of_an_older_one(): void
    {
        $this->approved();
        $this->storedDesign($this->input(), $this->payload());

        self::assertFalse($this->lock()->holds($this->project));
    }

    public function test_a_design_run_in_progress_refuses_the_approval(): void
    {
        [$stage, $token] = $this->store->queueProjectStage($this->project, PlanningStageName::YACHT_DESIGN, ['run' => 1], false, [], '{}', null);
        self::assertNotNull($token);

        [$done, $reason] = $this->sheet->approve($this->project, $this->artifact, null);

        self::assertFalse($done);
        self::assertStringContainsString('lượt thiết kế', $reason);
        self::assertSame('rendered', DB::table('video_design_images')->where('project_id', $this->project)->value('status'));

        $this->store->finishFailed((string) $stage->id, (string) $token, 'failed');
        self::assertSame([true, 'approved'], $this->sheet->approve($this->project, $this->artifact, null), 'a settled run lets it through');
    }

    public function test_a_dna_run_in_progress_refuses_the_approval(): void
    {
        [, $token] = $this->store->claimProjectStage($this->project, PlanningStageName::DESIGN_DNA, ['run' => 1]);
        self::assertNotNull($token);

        [$done, $reason] = $this->sheet->approve($this->project, $this->artifact, null);

        self::assertFalse($done);
        self::assertStringContainsString('lượt DNA', $reason);
        self::assertFalse($this->lock()->holds($this->project));
    }

    public function test_a_stuck_run_refuses_the_approval_until_someone_settles_it(): void
    {
        [$stage] = $this->store->claimProjectStage($this->project, PlanningStageName::YACHT_DESIGN, ['run' => 1]);
        VideoPlanningStage::query()->whereKey($stage->id)->update(['lease_expires_at' => now()->subHour()]);

        self::assertFalse($this->sheet->approve($this->project, $this->artifact, null)[0]);
    }

    public function test_a_design_that_changed_after_the_set_was_opened_refuses_the_approval(): void
    {
        $this->storedDesign($this->input(), $this->payload());

        [$done, $reason] = $this->sheet->approve($this->project, $this->artifact, null);

        self::assertFalse($done);
        self::assertStringContainsString('nguồn đã đổi', $reason);
    }

    public function test_a_dna_finished_after_the_page_was_read_refuses_the_approval(): void
    {
        $pinned = $this->dnaStage();
        $this->design->forceFill(['input_json' => ['dna_stage_id' => $pinned] + $this->design->input_json])->save();
        // The panel kept in this service still says the design is fine, as it did before the DNA finished.
        $this->dnaStage(YachtDesignCheck::FAIL);
        $this->assertApprovable();

        $this->dnaStage();

        [$done, $reason] = $this->sheet->approve($this->project, $this->artifact, null);

        self::assertFalse($done);
        self::assertStringContainsString('Design DNA đã đổi', $reason);
        self::assertSame('rendered', DB::table('video_design_images')->where('project_id', $this->project)->value('status'));
        self::assertFalse($this->lock()->holds($this->project));
    }

    public function test_the_approval_checks_the_design_itself_again_under_the_lock(): void
    {
        $this->assertApprovable();
        $check = $this->design->output_json;

        $this->design->forceFill(['output_json' => ['check' => ['verdict' => YachtDesignCheck::FAIL]] + $check])->save();
        self::assertStringContainsString('chưa đạt', $this->approvalProblem());

        $this->design->forceFill(['output_json' => $check, 'output_hash' => hash('sha256', 'another answer')])->save();
        self::assertStringContainsString('mã băm', $this->approvalProblem());

        $this->design->forceFill(['output_hash' => null, 'input_json' => ['dna_stage_id' => (string) Str::uuid()] + $this->design->input_json])->save();
        self::assertStringContainsString('DNA nguồn', $this->approvalProblem());

        $this->design->forceFill(['input_json' => array_diff_key($this->design->input_json, ['dna_stage_id' => true])])->save();
        $this->assertApprovable();

        $this->set->forceFill(['input_json' => ['design_canonical_hash' => 'other'] + $this->set->input_json]);
        self::assertStringContainsString('hash', $this->approvalProblem());
    }

    public function test_the_same_input_with_a_broken_answer_is_never_served_from_the_cache(): void
    {
        $stored = $this->storedDesign($this->input(), $this->payload());
        $stored->forceFill(['raw_response' => '{"tampered":true}'])->save();
        $run = $this->preparedRun();
        $service = app(YachtDesignService::class);
        $decision = $service->decision($this->project, $run);

        self::assertTrue($decision['exact']);
        self::assertStringContainsString('mã băm', (string) $decision['integrity_error']);
        self::assertSame(DesignRunPolicy::REPLACEABLE, $decision['status']);
        self::assertTrue($decision['regenerate']);

        $rows = VideoPlanningStage::query()->count();
        $calls = 0;
        [$stage, $reason] = $this->submitted($run, false, $calls);

        self::assertNull($stage);
        self::assertStringContainsString('Thiết kế lại', $reason);
        self::assertSame(0, $calls);
        self::assertSame($rows, VideoPlanningStage::query()->count());

        [$stage, $reason] = $this->submitted($run, true, $calls);

        self::assertSame('ok', $reason, 'the deliberate action replaces it, the force sees the broken answer too');
        self::assertSame(1, $calls);
        self::assertNotSame((string) $stored->id, (string) $stage->id);
    }

    public function test_the_same_input_with_a_valid_answer_is_served_and_a_force_buys_nothing(): void
    {
        $stored = $this->storedDesign($this->input(), $this->payload());
        $calls = 0;

        foreach ([false, true] as $regenerate) {
            $service = app(YachtDesignService::class);
            self::assertNull($service->refusal($this->project, $this->preparedRun(), $regenerate));
            $served = $this->store->latestSucceededForInput($this->project, PlanningStageName::YACHT_DESIGN, $this->preparedRun()['input']);
            self::assertSame((string) $stored->id, (string) $served->id);
        }

        [$stage, $reason] = $this->submitted($this->preparedRun(), false, $calls);

        self::assertSame('cached', $reason);
        self::assertSame((string) $stored->id, (string) $stage->id);
        self::assertSame(0, $calls);
    }

    public function test_the_script_step_keeps_one_adapter_per_project(): void
    {
        $sources = app(\App\Services\Video\ScriptSourceService::class);

        self::assertSame($sources->workflowFor($this->project)['adapter'], $sources->workflowFor($this->project)['adapter']);
    }

    public function test_the_approval_reads_active_runs_only_under_the_project_lock(): void
    {
        $base = DB::transactionLevel();
        $seen = [];
        DB::listen(static function ($query) use (&$seen): void {
            $seen[] = ['sql' => strtolower($query->sql), 'level' => DB::transactionLevel()];
        });

        self::assertTrue($this->sheet->approve($this->project, $this->artifact, null)[0]);

        $lock = array_key_first(array_filter($seen, static fn (array $row): bool => str_contains($row['sql'], '`video_projects`') && str_contains($row['sql'], 'for update')));
        $active = array_key_first(array_filter($seen, static fn (array $row): bool => str_contains($row['sql'], '`video_planning_stages`') && str_contains($row['sql'], '`claim_token` is not null')));
        self::assertNotNull($lock);
        self::assertNotNull($active);
        self::assertLessThan($active, $lock);
        self::assertGreaterThan($base, $seen[$lock]['level'], 'the lock is taken inside the transaction that writes the approval');
    }

    public function test_after_the_approval_a_new_design_is_refused_before_any_row_or_model_call(): void
    {
        $this->approved();
        $service = app(YachtDesignService::class);
        $run = $this->preparedRun();
        $rows = VideoPlanningStage::query()->count();
        $called = false;

        foreach ([false, true] as $regenerate) {
            [$stage, $reason] = (new StageQueue($this->store))->submit(
                'design', $this->project, PlanningStageName::YACHT_DESIGN, static fn (): array => $run, $regenerate, (string) Str::uuid(), null,
                static function () use (&$called): array {
                    $called = true;

                    return [null, 'called'];
                },
                'thiết kế', 'test', null, 'Chưa gọi model: ',
                static fn (array $prepared): ?string => $service->refusal(self::projectOf($prepared), $prepared, $regenerate),
            );

            self::assertNull($stage);
            self::assertSame('Chưa gọi model: '.DesignApprovalLock::MESSAGE, $reason);
        }

        self::assertFalse($called);
        self::assertSame($rows, VideoPlanningStage::query()->count());
        Http::assertNothingSent();
    }

    public function test_after_the_approval_a_new_dna_is_refused(): void
    {
        self::assertNull(app(DesignDnaService::class)->refusal($this->project));

        $this->approved();

        self::assertSame(DesignApprovalLock::MESSAGE, app(DesignDnaService::class)->refusal($this->project));
    }

    public function test_a_queued_design_or_dna_stops_at_the_worker_once_the_project_is_locked(): void
    {
        [$design, $designToken] = $this->store->queueProjectStage($this->project, PlanningStageName::YACHT_DESIGN, ['run' => 'design'], false, [], '{}', null);
        [$dna, $dnaToken] = $this->store->queueProjectStage($this->project, PlanningStageName::DESIGN_DNA, ['run' => 'dna'], false, [], '{}', null);
        $this->lockWithoutActiveRunCheck();

        self::assertSame([null, 'Chưa gọi model thiết kế: '.DesignApprovalLock::MESSAGE], app(YachtDesignService::class)->execute((string) $design->id, (string) $designToken));
        self::assertSame([null, 'Chưa gọi model DNA: '.DesignApprovalLock::MESSAGE], app(DesignDnaService::class)->execute((string) $dna->id, (string) $dnaToken));

        foreach ([$design, $dna] as $stage) {
            $stage = VideoPlanningStage::query()->withoutSnapshot()->findOrFail($stage->id);
            self::assertSame('failed', $stage->status);
            self::assertStringContainsString(DesignApprovalLock::MESSAGE, (string) $stage->error_message);
        }

        self::assertTrue($this->lock()->holds($this->project), 'the approval stays');
        Http::assertNothingSent();
    }

    public function test_only_a_model_sheet_config_change_keeps_the_design_and_refuses_a_paid_run(): void
    {
        $this->storedDesign($this->input(), $this->payload());
        $run = $this->preparedRun(payload: ['model_sheet' => ['rules' => ['keep', 'a rule that changes what the sheet shows'], 'max_chars' => 27000] + $this->payload()['model_sheet']]);
        $service = app(YachtDesignService::class);

        $decision = $service->decision($this->project, $run);

        self::assertSame(DesignRunPolicy::KEPT, $decision['status']);
        self::assertFalse($decision['exact'], 'the inputs are reported as different');
        self::assertSame(['rules', 'max_chars'], $decision['changes']['next_sheet']);
        self::assertFalse($decision['create']);
        self::assertFalse($decision['regenerate']);
        self::assertNotNull($service->refusal($this->project, $run, false));
        self::assertNotNull($service->refusal($this->project, $run, true), 'a crafted force does not buy a run either');
    }

    public function test_a_new_source_prompt_or_camera_opens_only_the_deliberate_action(): void
    {
        $service = app(YachtDesignService::class);
        $this->storedDesign($this->input(), $this->payload());
        $otherDna = $this->dnaStage();

        foreach ([
            [$this->preparedRun(input: ['phase_revision' => 9]), ['phase_revision']],
            [$this->preparedRun(input: ['dna_stage_id' => $otherDna]), ['dna_stage_id']],
            [$this->preparedRun(payload: ['model_sheet' => ['cameras' => ['MS1' => ['azimuth_deg' => 45]]] + $this->payload()['model_sheet']]), ['model_sheet.cameras']],
        ] as [$run, $fields]) {
            $decision = $service->decision($this->project, $run);

            self::assertSame(DesignRunPolicy::INPUT_CHANGED, $decision['status']);
            self::assertSame($fields, $decision['changes']['design']);
            self::assertFalse($decision['create']);
            self::assertTrue($decision['regenerate']);
            self::assertNotNull($service->refusal($this->project, $run, false), 'the page has to send the paid action');
            self::assertNull($service->refusal($this->project, $run, true));
        }
    }

    public function test_a_model_effort_or_check_change_keeps_the_design(): void
    {
        $this->storedDesign($this->input(), $this->payload());
        $run = $this->preparedRun(input: ['model' => 'other', 'effort' => 'high', 'check_version' => 'check-v9']);

        $decision = app(YachtDesignService::class)->decision($this->project, $run);

        self::assertSame(DesignRunPolicy::KEPT, $decision['status']);
        self::assertSame(['check_version', 'model', 'effort'], $decision['changes']['next_run']);
    }

    public function test_the_same_input_is_served_from_the_stored_design(): void
    {
        $stored = $this->storedDesign($this->input(), $this->payload());
        $run = $this->preparedRun();
        $service = app(YachtDesignService::class);

        self::assertSame(DesignRunPolicy::CURRENT, $service->decision($this->project, $run)['status']);
        self::assertNull($service->refusal($this->project, $run, false));

        [$stage, $reason] = (new StageQueue($this->store))->submit(
            'design', $this->project, PlanningStageName::YACHT_DESIGN, static fn (): array => $run, false, (string) Str::uuid(), null,
            static fn (): array => [null, 'called'], 'thiết kế', 'test', null, 'Chưa gọi model: ',
            fn (array $prepared): ?string => $service->refusal($this->project, $prepared, false),
        );

        self::assertSame('cached', $reason);
        self::assertSame((string) $stored->id, (string) $stage->id);
    }

    public function test_a_failed_or_warned_design_asks_for_the_deliberate_action(): void
    {
        $service = app(YachtDesignService::class);

        foreach ([['verdict' => YachtDesignCheck::FAIL, 'warnings' => []], ['verdict' => YachtDesignCheck::PASS, 'warnings' => ['confirm this']]] as $check) {
            $this->storedDesign($this->input(), $this->payload(), check: $check);
            $run = $this->preparedRun(payload: ['model_sheet' => ['max_chars' => 27000] + $this->payload()['model_sheet']]);

            self::assertSame(DesignRunPolicy::REPLACEABLE, $service->decision($this->project, $run)['status']);
            self::assertNotNull($service->refusal($this->project, $run, false));
            self::assertNull($service->refusal($this->project, $run, true));
        }
    }

    public function test_a_history_design_without_snapshot_is_kept_and_its_approval_stands(): void
    {
        $this->approved();
        $this->design->forceFill(['input_json' => $this->input(), 'input_hash' => hash('sha256', 'historic')])->save();
        $run = $this->preparedRun(payload: ['model_sheet' => ['max_chars' => 27000] + $this->payload()['model_sheet']]);

        $decision = app(YachtDesignService::class)->decision($this->project, $run);

        self::assertNull($this->design->fresh()->getRawOriginal('request_snapshot'));
        self::assertTrue($decision['history_unverified']);
        self::assertTrue($decision['changes']['payload_unread']);
        self::assertTrue($decision['locked']);
        self::assertFalse($decision['create'] || $decision['regenerate']);
    }

    public function test_a_history_design_without_snapshot_and_unlocked_opens_no_paid_run(): void
    {
        $this->storedDesign($this->input(), $this->payload(), snapshot: false);

        $decision = app(YachtDesignService::class)->decision($this->project, $this->preparedRun(payload: ['model_sheet' => ['max_chars' => 27000] + $this->payload()['model_sheet']]));

        self::assertSame(DesignRunPolicy::KEPT, $decision['status']);
        self::assertTrue($decision['history_unverified']);
    }

    public function test_a_stored_pass_with_a_broken_answer_is_not_a_valid_design(): void
    {
        $stored = $this->storedDesign($this->input(), $this->payload());
        $stored->forceFill(['raw_response' => '{"tampered":true}'])->save();
        $service = app(YachtDesignService::class);
        $run = $this->preparedRun();

        $decision = $service->decision($this->project, $run);

        self::assertStringContainsString('mã băm', (string) $decision['integrity_error']);
        self::assertSame(DesignRunPolicy::REPLACEABLE, $decision['status']);
        self::assertTrue($decision['regenerate']);
    }

    public function test_a_broken_answer_stops_the_model_sheet_source_and_keeps_the_lock(): void
    {
        $this->approved();
        $this->design->forceFill(['output_hash' => hash('sha256', 'another answer')])->save();

        self::assertTrue($this->lock()->holds($this->project));
        $decision = app(YachtDesignService::class)->decision($this->project, $this->preparedRun());
        self::assertTrue($decision['locked']);
        self::assertNotNull($decision['integrity_error']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mã băm');
        $this->sheet->approvedSource($this->project);
    }

    public function test_a_design_whose_dna_is_gone_is_not_valid(): void
    {
        $this->storedDesign(['dna_stage_id' => (string) Str::uuid()] + $this->input(), $this->payload());

        $decision = app(YachtDesignService::class)->decision($this->project, $this->preparedRun());

        self::assertStringContainsString('DNA nguồn', (string) $decision['integrity_error']);
    }

    public function test_without_a_design_a_run_is_open_when_it_can_be_prepared(): void
    {
        $other = $this->newProject();
        $service = app(YachtDesignService::class);
        $run = $this->preparedRun();

        self::assertSame(['create' => true, 'regenerate' => false, 'status' => DesignRunPolicy::NO_DESIGN], array_intersect_key($service->decision($other, $run), array_flip(['create', 'regenerate', 'status'])));
        self::assertNull($service->refusal($other, $run, false));
        self::assertSame(DesignRunPolicy::NOT_READY, $service->decision($other, null)['status']);
    }

    public function test_the_panels_write_nothing_and_never_reach_the_model_sheet_service(): void
    {
        $this->approved();
        $writes = [];
        DB::listen(static function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });
        $this->app->resolving(ModelSheetService::class, static function (): void {
            self::fail('the design panel asked the model sheet service, which asks the design panel');
        });

        $design = app(YachtDesignService::class)->panel($this->project);
        $dna = app(DesignDnaService::class)->panel($this->project);

        self::assertSame([], $writes);
        self::assertTrue($design['locked']);
        self::assertFalse($design['can_run'] || $design['can_regenerate']);
        self::assertNull($design['run_error'], 'nothing can run, so nothing has to be fixed to run it');
        self::assertSame('Bản thiết kế này đã duyệt cùng model sheet', $design['blocked_reason']);
        self::assertTrue($dna['locked']);
        self::assertFalse($dna['can_run'] || $dna['can_regenerate']);
    }

    public function test_the_panel_shows_what_the_service_decided(): void
    {
        $this->startSession();
        $project = VideoProject::query()->findOrFail($this->project);
        $page = fn (array $state): string => view('video-projects.partials.vessel-design-panel', ['project' => $project, 'yacht' => $state + $this->panelState()])->render();

        $locked = $page(['locked' => true, 'blocked_reason' => 'Bản thiết kế này đã duyệt cùng model sheet']);
        self::assertStringContainsString('Đã duyệt (model sheet)', $locked);
        self::assertStringNotContainsString('yachtDesignForm', $locked);
        self::assertStringContainsString('disabled title="Bản thiết kế này đã duyệt cùng model sheet"', $locked);

        $kept = $page(['changes' => ['next_sheet' => ['rules', 'max_chars']] + DesignRunPolicy::NO_CHANGES, 'blocked_reason' => 'kept']);
        self::assertStringNotContainsString('yachtDesignForm', $kept);
        self::assertStringContainsString('Cấu hình model sheet hiện hành đã thay đổi (rules, max_chars)', $kept);

        $changed = $page(['can_regenerate' => true, 'run_model' => 'sonnet55 (effort max)', 'last_cost_usd' => 0.77, 'changes' => ['design' => ['phase_revision']] + DesignRunPolicy::NO_CHANGES]);
        self::assertStringContainsString('name="force"', $changed);
        self::assertStringContainsString('Thiết kế lại (tính phí)', $changed);
        self::assertStringContainsString('Gọi sonnet55 (effort max)', $changed);
        self::assertStringContainsString('Thay đổi: phase_revision. Lượt trước tốn $0.770.', $changed);

        $first = $page(['can_run' => true, 'analysed' => false, 'run_model' => 'sonnet55 (effort max)']);
        self::assertStringContainsString('Creat design', $first);
        self::assertStringNotContainsString('name="force"', $first);

        self::assertStringContainsString('Bản lịch sử đang được giữ; chưa kiểm lại đầy đủ theo bộ kiểm mới.', $page(['history_unverified' => true]));
        self::assertStringContainsString('không khớp mã băm', $page(['integrity_error' => 'Phản hồi đã lưu của bản thiết kế không khớp mã băm.']));
    }

    private function lock(): DesignApprovalLock
    {
        return app(DesignApprovalLock::class);
    }

    private function assertApprovable(): void
    {
        (new \ReflectionMethod($this->sheet, 'assertApprovable'))->invoke($this->sheet, $this->project, $this->set);
    }

    private function approvalProblem(): string
    {
        try {
            $this->assertApprovable();
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return 'no problem';
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array{0: ?VideoPlanningStage, 1: string} the run through the same guard and force the service gives the queue
     */
    private function submitted(array $run, bool $regenerate, int &$calls): array
    {
        $service = app(YachtDesignService::class);

        return (new StageQueue($this->store))->submit(
            'design', $this->project, PlanningStageName::YACHT_DESIGN, static fn (): array => $run,
            static fn (VideoPlanningStage $served, array $prepared): bool => $service->mayReplace($served, $prepared, $regenerate),
            (string) Str::uuid(), null,
            static function (VideoPlanningStage $stage) use (&$calls): array {
                $calls++;

                return [$stage, 'ok'];
            },
            'thiết kế', 'test', null, 'Chưa gọi model: ',
            fn (array $prepared): ?string => $service->refusal($this->project, $prepared, $regenerate),
        );
    }

    private function approved(): void
    {
        self::assertSame([true, 'approved'], $this->sheet->approve($this->project, $this->artifact, null));
    }

    /** The relation an earlier approval left, with runs queued before it: the case the worker check exists for. */
    private function lockWithoutActiveRunCheck(): void
    {
        $image = \App\Models\VideoDesignImage::query()->where('project_id', $this->project)->firstOrFail();
        $artifact = \App\Models\VideoArtifact::query()->findOrFail($this->artifact);
        $image->forceFill(['status' => 'approved', 'selected_artifact_id' => $artifact->id, 'approved_at' => now()])->save();
        [$stage, $token] = $this->store->claimProjectStage($this->project, PlanningStageName::MODEL_SHEET_REVIEW, ModelSheetService::reviewInput($this->set, $image, $artifact), true);
        self::assertTrue($this->store->finishSucceeded((string) $stage->id, (string) $token, '', ['approved_by' => null]));
    }

    private function newProject(): string
    {
        $keyword = (string) Str::uuid();
        $article = (string) Str::uuid();
        DB::table('keywords')->insert(['id' => $keyword, 'name' => 'Yacht', 'category_id' => $this->category, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('articles')->insert([
            'id' => $article, 'keyword_id' => $keyword, 'category_id' => $this->category, 'source_url' => 'https://example.test/'.$article,
            'source_url_hash' => md5($article), 'source_title' => 'Yacht', 'title' => 'Yacht', 'slug' => $article, 'content' => 'Yacht', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (string) VideoProject::create(['title' => 'Guard', 'article_id' => $article, 'project_type' => 'article_video'])->id;
    }

    private function openSet(): VideoPlanningStage
    {
        [$set, $error] = (new \ReflectionMethod($this->sheet, 'activeSet'))->invoke($this->sheet, $this->project);
        self::assertNull($error);

        return $set;
    }

    /** @return string the id of a master with its eight crops on disk, ready to approve */
    private function renderedMaster(): string
    {
        $disk = Storage::disk(self::DISK);
        $bytes = 'master image';
        $sha = hash('sha256', $bytes);
        $image = (string) Str::uuid();
        $artifact = (string) Str::uuid();
        $disk->put('sheet/master.png', $bytes);
        DB::table('video_design_images')->insert([
            'id' => $image, 'project_id' => $this->project, 'image_code' => 'master_v1', 'image_type' => DesignImageStore::MODEL_SHEET_MASTER_TYPE, 'status' => 'rendered',
            'prompt_spec_json' => json_encode(['execution' => $this->set->input_json['execution'], 'model_sheet_set_id' => $this->set->id, 'design_stage_id' => $this->design->id, 'design_canonical_hash' => $this->set->input_json['design_canonical_hash']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('video_artifacts')->insert([
            'id' => $artifact, 'project_id' => $this->project, 'design_image_id' => $image, 'artifact_type' => 'image', 'role' => 'candidate', 'storage_disk' => self::DISK, 'storage_path' => 'sheet/master.png',
            'sha256' => $sha, 'width' => 3840, 'height' => 2160, 'metadata_json' => json_encode(['model_sheet' => ['ok' => true]]), 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (ModelSheetMaster::fromConfig()->cells() as $index => $cell) {
            $path = "sheet/{$cell['id']}.png";
            $disk->put($path, 'crop '.$cell['id']);
            DB::table('video_artifacts')->insert([
                'id' => (string) Str::uuid(), 'project_id' => $this->project, 'artifact_type' => 'image', 'role' => DesignImageStore::MODEL_SHEET_CROP_ROLE, 'storage_disk' => self::DISK, 'storage_path' => $path,
                'sha256' => hash('sha256', 'crop '.$cell['id']), 'width' => $cell['width'], 'height' => $cell['height'],
                'metadata_json' => json_encode(['master_artifact_id' => $artifact, 'view_id' => $cell['id'], 'index' => $index, 'x' => $cell['x'], 'y' => $cell['y'], 'master_sha256' => $sha]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $artifact;
    }

    private function dnaStage(string $verdict = YachtDesignCheck::PASS): string
    {
        return (string) VideoPlanningStage::create([
            'project_id' => $this->project, 'stage' => PlanningStageName::DESIGN_DNA->value, 'planning_revision' => (int) VideoPlanningStage::query()->where('project_id', $this->project)->where('stage', PlanningStageName::DESIGN_DNA->value)->max('planning_revision') + 1,
            'status' => 'succeeded', 'input_json' => ['dna' => (string) Str::uuid()], 'input_hash' => hash('sha256', (string) Str::uuid()), 'output_json' => ['check' => ['verdict' => $verdict]],
        ])->id;
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        static $dna = [];
        $dna[$this->project] ??= $this->dnaStage();

        return [
            'execution' => 'db-phase-3-v2', 'article_id' => 'article', 'category_id' => $this->category, 'workflow' => (string) config('video.superyacht.workflow_code'), 'bundle_hash' => 'sha256:bundle',
            'phase_id' => 'phase', 'phase_revision' => 8, 'prompt_sha256' => 'prompt', 'dna_stage_id' => $dna[$this->project],
            'payload_sha256' => hash('sha256', (string) json_encode($this->payload())),
            'check_version' => 'check-v2', 'model' => 'sonnet55', 'effort' => 'max', 'max_tokens' => 1000, 'timeout_seconds' => 60, 'stream' => true, 'retry_times' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'project_id' => $this->project, 'prompt_version' => 'v8', 'dna' => ['brief' => 'a muscle bobber'],
            'model_sheet' => ['version' => 'sheet-v1', 'cameras' => ['MS1' => ['azimuth_deg' => 0]], 'panels' => ['one'], 'rules' => ['keep'], 'max_chars' => 20000],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $check
     */
    private function storedDesign(array $input, array $payload, array $check = ['verdict' => YachtDesignCheck::PASS, 'warnings' => []], bool $snapshot = true): VideoPlanningStage
    {
        $raw = '{"design":1}';
        $text = (string) json_encode($payload);

        return VideoPlanningStage::create([
            'project_id' => $this->project, 'stage' => PlanningStageName::YACHT_DESIGN->value,
            'planning_revision' => (int) VideoPlanningStage::query()->where('project_id', $this->project)->where('stage', PlanningStageName::YACHT_DESIGN->value)->max('planning_revision') + 1,
            'status' => 'succeeded', 'input_json' => ['_meta' => ['operation_id' => (string) Str::uuid()]] + $input, 'input_hash' => $this->store->inputHash($input),
            'request_snapshot' => $snapshot ? json_encode(['format' => StageQueue::SNAPSHOT_FORMAT, 'payload' => $text]) : null,
            'raw_response' => $raw, 'output_hash' => hash('sha256', $raw), 'output_json' => ['raw_text' => $raw, 'check' => $check],
        ]);
    }

    /**
     * @param  array<string, mixed>  $input  fields of the current input that differ
     * @param  array<string, mixed>  $payload  fields of the current payload that differ
     * @return array<string, mixed>
     */
    private function preparedRun(array $input = [], array $payload = []): array
    {
        $text = (string) json_encode($payload + $this->payload());

        return [
            'system' => 'system', 'payload' => $text, 'prompt_version' => 'v8', 'metadata' => ['manifest' => []],
            'settings' => ['model' => 'sonnet55', 'effort' => 'max', 'max_tokens' => 1000, 'timeout_seconds' => 60],
            'input' => array_replace($this->input(), ['payload_sha256' => hash('sha256', $text)], $input),
        ];
    }

    /** @param array<string, mixed> $run */
    private static function projectOf(array $run): string
    {
        return (string) json_decode((string) $run['payload'], true)['project_id'];
    }

    /** @return array<string, mixed> a panel as the service returns it: a design that passed, nothing open */
    private function panelState(): array
    {
        return [
            'moto' => true, 'can_run' => false, 'can_regenerate' => false, 'locked' => false, 'input_matches' => false, 'changes' => DesignRunPolicy::NO_CHANGES,
            'history_unverified' => false, 'integrity_error' => null, 'blocked_reason' => null, 'prompt' => null, 'run_model' => null, 'last_cost_usd' => null, 'run_error' => null,
            'operation_id' => (string) Str::uuid(), 'stage_id' => 's', 'analysed' => true, 'status' => 'succeeded', 'running' => false, 'pending' => false, 'stuck' => false, 'error' => null, 'task' => null,
            'current' => false, 'dna_stale' => false, 'check' => ['verdict' => 'PASS', 'issues' => [], 'warnings' => []], 'name' => 'Channel Drop', 'loa' => null, 'yacht_anchor' => null, 'prose' => 'text', 'json_error' => null,
        ];
    }
}
