<?php

namespace Tests\Feature;

use App\Enums\PlanningStageName;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Services\Video\DesignApprovalLock;
use App\Services\Video\DesignImageStore;
use App\Services\Video\ModelSheetService;
use App\Services\Video\PlanningStageStore;
use App\Video\YachtDesign\YachtDesignCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Two real connections, so the rows are committed: another process holds the project lock, commits
 * what it did and lets go while this one waits on the lock. What must hold is not only that this
 * one waits, but that what it decides afterwards reads what the other one just committed.
 */
final class DesignApprovalRaceTest extends TestCase
{
    private const HOLD_MS = 700;

    private string $project;

    private string $dna;

    private VideoPlanningStage $design;

    private VideoPlanningStage $set;

    private PlanningStageStore $store;

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private ?string $jobFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDatabaseName() !== 'news24h_test' || ! function_exists('proc_open')) {
            self::markTestSkipped('This test commits rows, so it runs only on the isolated test database.');
        }

        $this->store = app(PlanningStageStore::class);
        $this->project = (string) Str::uuid();
        DB::table('video_projects')->insert(['id' => $this->project, 'title' => 'race', 'project_type' => 'article_video', 'created_at' => now(), 'updated_at' => now()]);
        $this->dna = (string) VideoPlanningStage::create(['project_id' => $this->project, 'stage' => PlanningStageName::DESIGN_DNA->value, 'planning_revision' => 1, 'status' => 'succeeded',
            'input_json' => ['dna' => 1], 'input_hash' => hash('sha256', 'dna'), 'output_json' => ['check' => ['verdict' => 'PASS']]])->id;
        $this->design = VideoPlanningStage::create(['project_id' => $this->project, 'stage' => PlanningStageName::YACHT_DESIGN->value, 'planning_revision' => 1, 'status' => 'succeeded',
            'input_json' => ['dna_stage_id' => $this->dna], 'input_hash' => hash('sha256', 'design'), 'raw_response' => '{}', 'output_hash' => hash('sha256', '{}'),
            'output_json' => ['raw_text' => '{}', 'design' => ['data' => ['canonical' => ['hash' => 'canonical']]], 'check' => ['verdict' => YachtDesignCheck::PASS, 'warnings' => []]]]);
        $this->set = VideoPlanningStage::create(['project_id' => $this->project, 'stage' => PlanningStageName::MODEL_SHEET->value, 'planning_revision' => 1, 'status' => 'succeeded',
            'input_json' => ['design_stage_id' => $this->design->id, 'design_canonical_hash' => 'canonical', 'execution' => 'model-sheet-master-v2'], 'input_hash' => hash('sha256', 'set'), 'output_json' => []]);
    }

    protected function tearDown(): void
    {
        if (isset($this->project)) {
            $this->finishOther();
            DB::table('video_artifacts')->where('project_id', $this->project)->delete();
            DB::table('video_design_images')->where('project_id', $this->project)->delete();
            DB::table('video_planning_stages')->where('project_id', $this->project)->delete();
            DB::table('video_projects')->where('id', $this->project)->delete();
        }

        parent::tearDown();
    }

    public function test_an_approval_that_waited_sees_the_dna_committed_while_it_waited(): void
    {
        $this->otherHoldsTheLock([$this->stageRow(PlanningStageName::DESIGN_DNA, 2, ['check' => ['verdict' => 'PASS']])]);

        [$problem, $seconds] = $this->approvalAfterWaiting();

        self::assertGreaterThan(0.2, $seconds, 'it waited on the lock');
        self::assertStringContainsString('Design DNA đã đổi', (string) $problem);
        $this->assertOtherCommitted();
    }

    public function test_an_approval_that_waited_sees_the_design_run_committed_while_it_waited(): void
    {
        $this->otherHoldsTheLock([$this->stageRow(PlanningStageName::YACHT_DESIGN, 2, null, 'pending', (string) Str::uuid())]);

        [$problem] = $this->approvalAfterWaiting();

        self::assertStringContainsString('lượt thiết kế', (string) $problem);
        $this->assertOtherCommitted();
    }

    public function test_an_approval_that_waited_sees_the_new_design_committed_while_it_waited(): void
    {
        $this->otherHoldsTheLock([$this->stageRow(PlanningStageName::YACHT_DESIGN, 2, ['check' => ['verdict' => 'PASS']])]);

        [$problem] = $this->approvalAfterWaiting();

        self::assertStringContainsString('nguồn đã đổi', (string) $problem);
        $this->assertOtherCommitted();
    }

    public function test_an_approval_that_waited_for_nothing_relevant_goes_through(): void
    {
        $this->otherHoldsTheLock([]);

        [$problem, $seconds] = $this->approvalAfterWaiting();

        self::assertGreaterThan(0.2, $seconds);
        self::assertNull($problem);
        $this->assertOtherCommitted();
    }

    public function test_a_new_design_that_waited_sees_the_approval_committed_while_it_waited(): void
    {
        [$rows, $updates] = $this->approvalRows();
        $this->otherHoldsTheLock($rows, $updates);
        $asked = 0;
        $lock = app(DesignApprovalLock::class);

        [$stage, $token, $reason] = $this->store->queueProjectStage($this->project, PlanningStageName::YACHT_DESIGN, ['run' => 2], false, [], '{}', null, function () use (&$asked, $lock): bool {
            $asked++;

            return $lock->holds($this->project);
        });

        self::assertSame(1, $asked);
        self::assertSame('refused', $reason);
        self::assertNull($stage);
        self::assertNull($token);
        self::assertSame(1, VideoPlanningStage::query()->where('project_id', $this->project)->where('stage', PlanningStageName::YACHT_DESIGN->value)->count(), 'no run was opened');
        $this->assertOtherCommitted();
    }

    public function test_a_new_design_that_waited_for_nothing_relevant_opens_its_run(): void
    {
        $this->otherHoldsTheLock([]);
        $lock = app(DesignApprovalLock::class);

        [, $token, $reason] = $this->store->queueProjectStage($this->project, PlanningStageName::YACHT_DESIGN, ['run' => 2], false, [], '{}', null, fn (): bool => $lock->holds($this->project));

        self::assertSame('queued', $reason);
        self::assertNotNull($token);
        $this->assertOtherCommitted();
    }

    /** @return array{0: ?string, 1: float} what the approval said once it got the lock, and how long that took */
    private function approvalAfterWaiting(): array
    {
        $sheet = app(ModelSheetService::class);
        $method = new ReflectionMethod($sheet, 'assertApprovable');
        $started = microtime(true);
        $problem = null;

        try {
            DB::transaction(fn () => $method->invoke($sheet, $this->project, $this->set));
        } catch (RuntimeException $e) {
            $problem = $e->getMessage();
        }

        return [$problem, microtime(true) - $started];
    }

    /**
     * @param  list<array{0: string, 1: array<string, mixed>}>  $rows  what the other process commits before it lets go
     * @param  list<array{0: string, 1: list<mixed>}>  $updates  statements it runs after those rows
     */
    private function otherHoldsTheLock(array $rows, array $updates = []): void
    {
        $connection = (array) config('database.connections.testing');
        $this->jobFile = (string) tempnam(sys_get_temp_dir(), 'race');
        file_put_contents($this->jobFile, json_encode([
            'database' => array_intersect_key($connection, array_flip(['host', 'port', 'database', 'username', 'password'])),
            'project' => $this->project,
            'hold_ms' => self::HOLD_MS,
            'rows' => $rows,
            'updates' => $updates,
        ], JSON_THROW_ON_ERROR));

        $this->process = proc_open([PHP_BINARY, base_path('tests/Support/hold_project_lock.php'), $this->jobFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        self::assertIsResource($this->process);

        $line = fgets($this->pipes[1]);

        if ($line === false) {
            self::fail('The other process did not take the lock: '.stream_get_contents($this->pipes[2]));
        }

        self::assertSame('locked', trim($line));
    }

    /** A failed insert rolls the other process back and lets go of the lock just the same, so a test must not take that for the race it meant. */
    private function assertOtherCommitted(): void
    {
        [$code, $errors] = $this->finishOther();

        self::assertSame(0, $code, 'The other process failed: '.$errors);
    }

    /** @return array{0: int, 1: string} its exit code and what it wrote to stderr */
    private function finishOther(): array
    {
        $code = 0;
        $errors = '';

        if (is_resource($this->process)) {
            stream_get_contents($this->pipes[1]);
            $errors = (string) stream_get_contents($this->pipes[2]);

            foreach ($this->pipes as $pipe) {
                fclose($pipe);
            }

            $code = proc_close($this->process);
        }

        $this->process = null;

        if ($this->jobFile !== null) {
            @unlink($this->jobFile);
            $this->jobFile = null;
        }

        return [$code, $errors];
    }

    /**
     * @param  ?array<string, mixed>  $output
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function stageRow(PlanningStageName $stage, int $revision, ?array $output, string $status = 'succeeded', ?string $token = null): array
    {
        return ['video_planning_stages', [
            'id' => (string) Str::uuid(), 'project_id' => $this->project, 'stage' => $stage->value, 'planning_revision' => $revision, 'status' => $status,
            'claim_token' => $token, 'input_json' => '{"other":1}', 'input_hash' => hash('sha256', (string) Str::uuid()), 'output_json' => $output === null ? null : json_encode($output),
            'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        ]];
    }

    /** @return array{0: list<array{0: string, 1: array<string, mixed>}>, 1: list<array{0: string, 1: list<mixed>}>} the rows an approval commits, then the choice of the artifact: image and artifact point at each other */
    private function approvalRows(): array
    {
        $image = (string) Str::uuid();
        $artifact = (string) Str::uuid();
        $sha = hash('sha256', 'master');
        $now = now()->toDateTimeString();
        $input = ModelSheetService::reviewInput(
            $this->set,
            (new VideoDesignImage)->forceFill(['id' => $image]),
            (new VideoArtifact)->forceFill(['id' => $artifact, 'sha256' => $sha]),
        );

        return [[
            ['video_design_images', ['id' => $image, 'project_id' => $this->project, 'image_code' => 'master', 'image_type' => DesignImageStore::MODEL_SHEET_MASTER_TYPE, 'status' => 'approved',
                'prompt_spec_json' => json_encode(['model_sheet_set_id' => $this->set->id, 'design_stage_id' => $this->design->id, 'design_canonical_hash' => 'canonical']), 'created_at' => $now, 'updated_at' => $now]],
            ['video_artifacts', ['id' => $artifact, 'project_id' => $this->project, 'design_image_id' => $image, 'artifact_type' => 'image', 'role' => 'candidate', 'storage_disk' => 'video_artifacts', 'storage_path' => 'race/master.png', 'sha256' => $sha, 'created_at' => $now, 'updated_at' => $now]],
            ['video_planning_stages', ['id' => (string) Str::uuid(), 'project_id' => $this->project, 'stage' => PlanningStageName::MODEL_SHEET_REVIEW->value, 'planning_revision' => 1, 'status' => 'succeeded',
                'input_json' => json_encode($input), 'input_hash' => $this->store->inputHash($input), 'output_json' => '{}', 'created_at' => $now, 'updated_at' => $now]],
        ], [
            ['UPDATE video_design_images SET selected_artifact_id = ? WHERE id = ?', [$artifact, $image]],
        ]];
    }
}
