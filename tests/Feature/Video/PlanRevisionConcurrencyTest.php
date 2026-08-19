<?php

namespace Tests\Feature\Video;

use App\Enums\VideoShotStatus;
use App\Models\VideoProject;
use App\Models\VideoSession;
use App\Models\VideoShot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * KHONG dung DatabaseTransactions — cung ly do voi FinalCompositionConcurrencyTest:
 * can HAI KET NOI DB THAT, mot ket noi giu lockForUpdate() CHUA commit, mot ket
 * noi khac phai bi chan that su. Don du lieu that trong tearDown().
 */
class PlanRevisionConcurrencyTest extends TestCase
{
    private ?string $sessionId = null;

    private ?string $projectId = null;

    protected function tearDown(): void
    {
        if ($this->sessionId) {
            DB::table('video_shots')->where('session_id', $this->sessionId)->delete();
            DB::table('video_sessions')->where('id', $this->sessionId)->delete();
        }
        if ($this->projectId) {
            DB::table('video_projects')->where('id', $this->projectId)->delete();
        }
        DB::disconnect('mysql_race_test');

        parent::tearDown();
    }

    /**
     * Chung minh lockForUpdate() tren video_shots (storeFromPython() ban trong
     * DB::transaction()) THAT SU chan cau UPDATE...LIMIT rieng ma
     * claimForSession() dung — khong chi doc code roi tin. Ket noi 1 mo phong
     * dung phan storeFromPython() dang lam (khoa hang shot, chua commit); ket
     * noi 2 chay y het cau SQL cua claimForSession(), dat lock_wait_timeout
     * ngan de khong treo test.
     */
    public function test_locking_the_shots_during_compose_genuinely_blocks_a_concurrent_claim(): void
    {
        $project = VideoProject::create(['title' => 'TEST plan revision race '.uniqid()]);
        $this->projectId = $project->id;
        $session = VideoSession::create([
            'project_id' => $project->id,
            'code' => 'test_plan_revision_race_'.uniqid(),
            'status' => 'composing',
        ]);
        $this->sessionId = $session->id;
        $shot = VideoShot::create([
            'session_id' => $session->id, 'beat' => 'b1', 'shot_code' => 's1', 'kind' => 'motion',
            'shot_type' => 'establish', 'spec_json' => [], 'compiled_prompt' => 'p',
            'status' => VideoShotStatus::QUEUED->value,
        ]);

        config(['database.connections.mysql_race_test' => config('database.connections.mysql')]);

        DB::beginTransaction();
        DB::table('video_shots')->where('session_id', $session->id)->lockForUpdate()->get();

        $blocked = false;
        $errorMessage = '';
        DB::connection('mysql_race_test')->statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            DB::connection('mysql_race_test')->update(
                'UPDATE video_shots
                 SET status = ?, worker_id = ?, claim_token = ?, claimed_at = NOW(), lease_expires_at = NOW(), updated_at = NOW()
                 WHERE status = ? AND session_id = ?
                 ORDER BY created_at, id
                 LIMIT 10',
                [VideoShotStatus::CLAIMED->value, 'worker-race', 'token-race', VideoShotStatus::QUEUED->value, $session->id],
            );
        } catch (QueryException $e) {
            $blocked = true;
            $errorMessage = $e->getMessage();
        }

        DB::rollBack();

        $this->assertTrue($blocked,
            'ket noi claim phai bi chan (lock wait timeout) khi storeFromPython() con giu lockForUpdate() tren video_shots chua commit — '.
            'neu khong bi chan, mot shot dang duoc claim co the bi bulk-supersede ghi de giua chung');
        $this->assertStringContainsStringIgnoringCase('lock wait timeout', $errorMessage);
        $this->assertSame(VideoShotStatus::QUEUED->value, $shot->fresh()->status);
    }
}
