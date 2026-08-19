<?php

namespace Tests\Feature\Video;

use App\Models\VideoProject;
use App\Models\VideoSession;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * KHONG dung DatabaseTransactions o day: ca test can HAI KET NOI DB THAT nhin
 * thay CUNG mot hang DA COMMIT. Trong mot transaction bao ngoai (nhu moi test
 * khac trong suite Video dung), ket noi thu hai khong the thay du lieu chua
 * commit cua ket noi thu nhat — khong the chung minh khoa that su chan nhau,
 * chi la goi lai tren cung mot connection. Doi lai: test nay tao/xoa du lieu
 * THAT tren DB dev, don dep tuyet doi trong tearDown() — cung ky luat da dung
 * khi benchmark EXPLAIN o Nhom 1 (claim/lease).
 */
class FinalCompositionConcurrencyTest extends TestCase
{
    private ?string $sessionId = null;

    private ?string $projectId = null;

    protected function tearDown(): void
    {
        if ($this->sessionId) {
            DB::table('video_sessions')->where('id', $this->sessionId)->delete();
        }
        if ($this->projectId) {
            DB::table('video_projects')->where('id', $this->projectId)->delete();
        }
        DB::disconnect('mysql_race_test');

        parent::tearDown();
    }

    /**
     * Chung minh co che startFinalComposition() dua vao (lockForUpdate() trong
     * DB::transaction() tren hang video_sessions) THAT SU chan mot ket noi
     * khac, khong chi doc code roi tin. Ket noi 1 giu khoa CHUA commit — dung
     * y het startFinalComposition() dang lam giua chung; ket noi 2 hoan toan
     * tach biet, dat innodb_lock_wait_timeout ngan de khong treo test.
     */
    public function test_locking_the_session_row_genuinely_blocks_a_second_connection(): void
    {
        $project = VideoProject::create(['title' => 'TEST race lock '.uniqid()]);
        $this->projectId = $project->id;
        $session = VideoSession::create([
            'project_id' => $project->id,
            'code' => 'test_race_lock_'.uniqid(),
            'status' => 'done',
        ]);
        $this->sessionId = $session->id;

        config(['database.connections.mysql_race_test' => config('database.connections.mysql')]);

        DB::beginTransaction();
        DB::table('video_sessions')->where('id', $session->id)->lockForUpdate()->first();

        $blocked = false;
        $errorMessage = '';
        DB::connection('mysql_race_test')->statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            DB::connection('mysql_race_test')->transaction(function () use ($session) {
                DB::connection('mysql_race_test')
                    ->table('video_sessions')
                    ->where('id', $session->id)
                    ->lockForUpdate()
                    ->first();
            });
        } catch (QueryException $e) {
            $blocked = true;
            $errorMessage = $e->getMessage();
        }

        DB::rollBack();

        $this->assertTrue($blocked,
            'ket noi thu hai phai bi chan (lock wait timeout) khi ket noi 1 con giu lockForUpdate() chua commit — '.
            'neu khong bi chan thi hai request dong thoi co the cung di qua nhanh "chua co final composing"');
        $this->assertStringContainsStringIgnoringCase('lock wait timeout', $errorMessage);
    }
}
