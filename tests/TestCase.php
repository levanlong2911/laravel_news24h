<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    private static bool $databaseIsolationVerified = false;

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
}
