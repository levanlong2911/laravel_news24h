<?php

namespace Tests;

use App\Models\VideoRenderPlan;
use App\Models\VideoSession;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    private static bool $databaseIsolationVerified = false;

    /**
     * Mot request HTTP khong duoc fake se nem thay vi di ra ngoai. Ngay
     * 2026-09-07 bon test con tin vao cau dao `VIDEO_PYTHON_RUNNER=false` sau
     * khi duong render da chuyen sang REST, va da goi that gpt-image-2 het
     * $0.25. Chot nay nam o day de moi test moi them vao deu duoc bao ve.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertIsolatedTestDatabase();
        Http::preventStrayRequests();
    }

    private function assertIsolatedTestDatabase(): void
    {
        if (self::$databaseIsolationVerified) {
            return;
        }

        if ((string) config('database.default') !== 'testing') {
            throw new RuntimeException('PHPUnit must use the testing database connection.');
        }

        $application = (string) DB::connection('mysql')->selectOne('SELECT DATABASE() AS db')->db;
        $isolated = (string) DB::connection('testing')->selectOne('SELECT DATABASE() AS db')->db;

        if ($isolated === '' || $isolated === $application) {
            throw new RuntimeException(
                "Refusing to run tests against the application database ({$application}). "
                .'Set DB_TEST_DATABASE or DB_TEST_DATABASE_URL to a separate database.',
            );
        }

        self::$databaseIsolationVerified = true;
    }

    /**
     * Ke hoach render da roi khoi `video_sessions.renderplan_json` sang bang
     * rieng, nen test khong con nhet duoc no vao VideoSession::create(). Dat o
     * TestCase vi bon file test cung can, va `schema_version`/`plan_hash` la
     * NOT NULL — lap lai o moi file la moi lan quen mot cot.
     */
    protected function storeRenderPlan(VideoSession $session, array $plan, int $revision = 1): VideoRenderPlan
    {
        $encoded = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stored = VideoRenderPlan::updateOrCreate(
            ['session_id' => $session->id, 'revision' => $revision],
            [
                'schema_version' => (string) ($plan['schema_version'] ?? ''),
                'status' => 'active',
                'scene_count' => count($plan['scenes'] ?? []),
                'plan_json' => $plan,
                'plan_hash' => hash('sha256', $encoded === false ? '' : $encoded),
            ],
        );

        $session->unsetRelation('latestRenderPlan');

        return $stored;
    }
}
