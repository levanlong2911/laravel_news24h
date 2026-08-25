<?php

namespace Tests\Feature\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Video\PlanningStageStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * `dd()` giet script giua chung nen `finishSucceeded()` khong chay va claim nam
 * lai het lease. Lenh nay bo qua khoang cho do khi debug.
 */
class VideoFreeClaimsCommandTest extends TestCase
{
    use DatabaseTransactions;

    private PlanningStageStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new PlanningStageStore;
    }

    private function claimedStage(): VideoPlanningStage
    {
        $project = VideoProject::create(['title' => 'TEST free claims '.uniqid()]);

        [$stage] = $this->store->claimProjectStage(
            $project->id, PlanningStageName::CONCEPT, ['x' => uniqid()],
        );

        return $stage;
    }

    public function test_it_releases_a_claim_without_waiting_out_the_lease(): void
    {
        $stage = $this->claimedStage();
        $this->assertNotNull($stage->claim_token);
        $this->assertTrue($stage->lease_expires_at->isFuture());

        $this->artisan('video:free-claims')->assertSuccessful();
        $stage->refresh();

        $this->assertNull($stage->claim_token);
        $this->assertNull($stage->lease_expires_at);
        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $stage->status);
    }

    public function test_it_says_why_the_stage_failed_instead_of_leaving_it_blank(): void
    {
        // Mot hang `failed` khong noi vi sao la cau hoi khong tra loi duoc sau
        // vai thang — nut Reset tren man hinh cung ghi ly do, lenh nay phai the.
        $stage = $this->claimedStage();

        $this->artisan('video:free-claims');

        $this->assertStringContainsString('video:free-claims', $stage->refresh()->error_message);
    }

    public function test_it_can_never_touch_work_that_already_finished(): void
    {
        // Hang da `succeeded` co claim_token = null. Bo loc `whereNotNull` la thu
        // dung giua lenh debug nay va mot ket qua DA TRA TIEN.
        $stage = $this->claimedStage();
        $this->store->finishSucceeded($stage->id, $stage->claim_token, '{}', ['ok' => 1]);

        $this->artisan('video:free-claims');
        $stage->refresh();

        $this->assertSame(VideoPlanningStageStatus::SUCCEEDED->value, $stage->status);
        $this->assertSame(['ok' => 1], $stage->output_json);
        $this->assertNull($stage->error_message);
    }

    public function test_it_can_be_narrowed_to_one_project(): void
    {
        $mine = $this->claimedStage();
        $other = $this->claimedStage();

        // Id DAY DU, khong phai tien to: UUID cua repo mang dau thoi gian nen hai
        // du an tao cach nhau vai mili giay co the trung 8 ky tu dau.
        $this->artisan('video:free-claims', ['--project' => $mine->project_id]);

        $this->assertNull($mine->refresh()->claim_token);
        $this->assertNotNull($other->refresh()->claim_token, 'Khong duoc cat ngang du an khac');
    }

    public function test_it_refuses_to_run_outside_local_without_being_told_twice(): void
    {
        // Cat ngang mot luot dang chay THAT la lam mat ket qua da tra tien.
        $stage = $this->claimedStage();
        app()->detectEnvironment(fn () => 'production');

        $this->artisan('video:free-claims')->assertFailed();

        $this->assertNotNull($stage->refresh()->claim_token);
    }
}
