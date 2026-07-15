<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Benchmark\Scenario;

use App\Services\AI\FilmOS\Benchmark\Scenario\Difficulty;
use App\Services\AI\FilmOS\Benchmark\Scenario\ScenarioDocument;
use App\Services\AI\FilmOS\Benchmark\Scenario\ScenarioLoader;
use App\Services\AI\FilmOS\Benchmark\Scenario\Suite;
use PHPUnit\Framework\TestCase;

/**
 * The gatekeeper of the benchmark dataset. Loads every real scenario file
 * through ScenarioLoader (so per-file validation runs on all of them) and
 * asserts catalog-level invariants: 16 files, 4 suites × 4, the frozen
 * difficulty distribution, unique ids matching filenames.
 */
final class ScenarioCatalogTest extends TestCase
{
    private const CATALOG_DIR = __DIR__ . '/../../../../../resources/filmos/benchmark/scenarios';

    /** @return string[] scenario ids (filenames without .json) */
    private function ids(): array
    {
        $ids = [];
        foreach (glob(self::CATALOG_DIR . '/*.json') ?: [] as $path) {
            $ids[] = basename($path, '.json');
        }
        sort($ids);
        return $ids;
    }

    /** @return ScenarioDocument[] */
    private function loadAll(): array
    {
        $loader = new ScenarioLoader(self::CATALOG_DIR);
        return array_map(fn(string $id) => $loader->fromId($id), $this->ids());
    }

    public function test_catalog_has_16_scenarios(): void
    {
        $this->assertCount(16, $this->ids());
    }

    public function test_every_file_loads_and_validates(): void
    {
        // Throws ScenarioSchemaException on any invalid file — that IS the assertion.
        $docs = $this->loadAll();
        $this->assertCount(16, $docs);
    }

    public function test_ids_are_unique_and_match_filenames(): void
    {
        $ids = $this->ids();
        $this->assertSame($ids, array_values(array_unique($ids)));

        foreach ($this->loadAll() as $doc) {
            $this->assertContains($doc->id, $ids);
        }
    }

    public function test_four_suites_of_four(): void
    {
        $bySuite = [];
        foreach ($this->loadAll() as $doc) {
            $bySuite[$doc->suite->value] = ($bySuite[$doc->suite->value] ?? 0) + 1;
        }
        ksort($bySuite);

        $this->assertSame(
            [Suite::CAMERA->value => 4, Suite::EMOTION->value => 4, Suite::MOTION->value => 4, Suite::WORLD->value => 4],
            $bySuite,
        );
    }

    public function test_difficulty_distribution_is_4_5_5_2(): void
    {
        $byDifficulty = [];
        foreach ($this->loadAll() as $doc) {
            $byDifficulty[$doc->difficulty->value] = ($byDifficulty[$doc->difficulty->value] ?? 0) + 1;
        }

        $this->assertSame(4, $byDifficulty[Difficulty::EASY->value]    ?? 0);
        $this->assertSame(5, $byDifficulty[Difficulty::MEDIUM->value]  ?? 0);
        $this->assertSame(5, $byDifficulty[Difficulty::HARD->value]    ?? 0);
        $this->assertSame(2, $byDifficulty[Difficulty::EXTREME->value] ?? 0);
    }

    public function test_every_scenario_has_a_primary_learning_dimension(): void
    {
        foreach ($this->loadAll() as $doc) {
            $this->assertNotSame('', $doc->primaryLearningDimension, "{$doc->id} missing primary_learning_dimension");
        }
    }

    public function test_every_scenario_has_at_least_one_shot(): void
    {
        foreach ($this->loadAll() as $doc) {
            $this->assertNotEmpty($doc->shots, "{$doc->id} has no shots");
        }
    }
}
