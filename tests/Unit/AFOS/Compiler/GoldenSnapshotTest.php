<?php

namespace Tests\Unit\AFOS\Compiler;

use App\Services\AI\AFOS\Creative\CinematographyProfile;
use App\Services\AI\AFOS\Creative\DirectorProfile;
use App\Services\AI\AFOS\Creative\Intent;
use App\Services\AI\AFOS\Ir\ShotGoalIR;
use App\Services\AI\AFOS\Passes\AfosPassManager;
use PHPUnit\Framework\TestCase;

/**
 * GoldenSnapshotTest — regression gate for the full AFOS compiler pipeline.
 *
 * Each test compiles a known input through AfosPassManager::defaults() and
 * compares the output against a committed JSON fixture. Any change to IR
 * logic, prompt templates, or entity vocabulary will fail these tests,
 * which forces an explicit decision: is the change intentional?
 *
 * To regenerate all fixtures after an intentional change:
 *   AFOS_UPDATE_SNAPSHOTS=1 php artisan test tests/Unit/AFOS/Compiler/GoldenSnapshotTest.php
 *
 * Fixtures live in: resources/afos/snapshots/*.json
 * Commit the fixtures alongside the code change that produced them.
 */
class GoldenSnapshotTest extends TestCase
{
    private const SNAPSHOT_DIR = __DIR__ . '/../../../../resources/afos/snapshots';

    // ── Input factories ────────────────────────────────────────────────────────

    private function luxuryVillaPoolDawn(): array
    {
        return [
            'shot'     => ShotGoalIR::fromArray([
                'shotId'             => 'golden_lv_pool_dawn',
                'durationSec'        => 6.0,
                'goalType'           => 'reveal',
                'goalTarget'         => 'pool_reflection',
                'viewerShouldNotice' => ['pool_reflection', 'light'],
                'viewerShouldIgnore' => [],
                'emotion'            => 'serenity',
                'energy'             => 0.25,
                'narrativeFunction'  => 'establish',
            ]),
            'director' => DirectorProfile::fromArray([
                'name'                => 'golden_terrence_malick',
                'observationWeight'   => 0.90,
                'motionWeight'        => 0.10,
                'revealWeight'        => 0.40,
                'negativeSpaceWeight' => 0.55,
                'symmetryWeight'      => 0.30,
                'cutFrequency'        => 'slow',
                'cameraPhilosophy'    => 'slow_observation',
                'colorPhilosophy'     => 'warm_golden',
            ]),
            'dp'       => CinematographyProfile::fromArray([
                'name'                => 'golden_luxury_standard',
                'lensVocabularyMm'    => [35, 85],
                'lightingStyle'       => 'natural_warm',
                'motionStyle'         => 'SLOW_PUSH',
                'depthLayersPreferred' => 3,
            ]),
            'intent'   => Intent::fromArray([
                'primaryEmotion'   => 'serenity',
                'secondaryEmotion' => 'luxury',
                'narrative'        => 'reveal_beauty',
                'tempo'            => 'meditative',
                'viewerExperience' => 'aspiration',
                'desiredTakeaway'  => 'This property offers unparalleled tranquility',
            ]),
        ];
    }

    private function superyachtHullAerial(): array
    {
        return [
            'shot'     => ShotGoalIR::fromArray([
                'shotId'             => 'golden_sy_hull_aerial',
                'durationSec'        => 8.0,
                'goalType'           => 'establish',
                'goalTarget'         => 'vehicle',
                'viewerShouldNotice' => ['vehicle', 'ocean'],
                'viewerShouldIgnore' => [],
                'emotion'            => 'power',
                'energy'             => 0.70,
                'narrativeFunction'  => 'reveal',
            ]),
            'director' => DirectorProfile::fromArray([
                'name'                => 'golden_michael_bay_restrained',
                'observationWeight'   => 0.60,
                'motionWeight'        => 0.80,
                'revealWeight'        => 0.70,
                'negativeSpaceWeight' => 0.20,
                'symmetryWeight'      => 0.50,
                'cutFrequency'        => 'medium',
                'cameraPhilosophy'    => 'cinematic_orbit',
                'colorPhilosophy'     => 'cool_blue',
            ]),
            'dp'       => CinematographyProfile::fromArray([
                'name'                => 'golden_superyacht_aerial',
                'lensVocabularyMm'    => [24, 35],
                'lightingStyle'       => 'golden_hour',
                'motionStyle'         => 'ENERGETIC',
                'depthLayersPreferred' => 2,
            ]),
            'intent'   => Intent::fromArray([
                'primaryEmotion'   => 'power',
                'secondaryEmotion' => 'wonder',
                'narrative'        => 'demonstrate_power',
                'tempo'            => 'building',
                'viewerExperience' => 'excitement',
                'desiredTakeaway'  => 'This vessel commands the sea',
            ]),
        ];
    }

    // ── Snapshot infrastructure ────────────────────────────────────────────────

    private function assertMatchesGoldenSnapshot(string $name, array $actual): void
    {
        if (!is_dir(self::SNAPSHOT_DIR)) {
            mkdir(self::SNAPSHOT_DIR, 0755, true);
        }

        $path = self::SNAPSHOT_DIR . "/{$name}.json";

        if (getenv('AFOS_UPDATE_SNAPSHOTS') === '1' || !file_exists($path)) {
            file_put_contents($path, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
            $this->markTestSkipped("Snapshot '{$name}' generated → commit {$name}.json and re-run.");
        }

        $expected = json_decode(file_get_contents($path), true);
        $this->assertEquals(
            $expected,
            $actual,
            "Golden snapshot '{$name}' mismatch — prompt or IR changed unexpectedly.\n" .
            "If intentional, regenerate: AFOS_UPDATE_SNAPSHOTS=1 php artisan test --filter=GoldenSnapshotTest"
        );
    }

    // ── Golden tests ───────────────────────────────────────────────────────────

    public function test_luxury_villa_pool_dawn_golden(): void
    {
        $inputs   = $this->luxuryVillaPoolDawn();
        $manager  = AfosPassManager::defaults();
        $snapshot = $manager->compileWithSnapshot(
            $inputs['shot'], $inputs['director'], $inputs['dp'], $inputs['intent']
        );

        $actual = $snapshot->toArray();

        // Structural sanity — always checked regardless of snapshot state
        $this->assertArrayHasKey('artifacts', $actual);
        $this->assertArrayHasKey('semantic', $actual);
        $this->assertNotEmpty($actual['artifacts']['compiled_prompt']);
        $this->assertSame('pool_reflection', $actual['semantic']['entity_id']);

        $this->assertMatchesGoldenSnapshot('luxury_villa_pool_dawn', $actual);
    }

    public function test_superyacht_hull_aerial_golden(): void
    {
        $inputs   = $this->superyachtHullAerial();
        $manager  = AfosPassManager::defaults();
        $snapshot = $manager->compileWithSnapshot(
            $inputs['shot'], $inputs['director'], $inputs['dp'], $inputs['intent']
        );

        $actual = $snapshot->toArray();

        $this->assertArrayHasKey('artifacts', $actual);
        $this->assertNotEmpty($actual['artifacts']['compiled_prompt']);
        $this->assertSame('vehicle', $actual['semantic']['entity_id']);

        $this->assertMatchesGoldenSnapshot('superyacht_hull_aerial', $actual);
    }

    public function test_compiledprompt_is_deterministic(): void
    {
        $inputs  = $this->luxuryVillaPoolDawn();
        $manager = AfosPassManager::defaults();

        $run1 = $manager->compileWithSnapshot(
            $inputs['shot'], $inputs['director'], $inputs['dp'], $inputs['intent']
        );
        $run2 = $manager->compileWithSnapshot(
            $inputs['shot'], $inputs['director'], $inputs['dp'], $inputs['intent']
        );

        $this->assertSame(
            $run1->artifacts->compiledPrompt,
            $run2->artifacts->compiledPrompt,
            'Compiler is not deterministic — same inputs produced different prompts.'
        );
        $this->assertSame($run1->semanticHash, $run2->semanticHash);
    }

    public function test_different_entity_produces_different_hash(): void
    {
        $base   = $this->luxuryVillaPoolDawn();
        $alt    = array_merge($base, [
            'shot' => ShotGoalIR::fromArray(array_merge($base['shot']->toArray(), [
                'goalTarget'         => 'terrace',
                'viewerShouldNotice' => ['terrace'],
            ])),
        ]);

        $manager = AfosPassManager::defaults();

        $hashBase = $manager->compileWithSnapshot($base['shot'], $base['director'], $base['dp'], $base['intent'])->semanticHash;
        $hashAlt  = $manager->compileWithSnapshot($alt['shot'], $alt['director'], $alt['dp'], $alt['intent'])->semanticHash;

        $this->assertNotSame($hashBase, $hashAlt, 'Entity change should produce different semantic hash.');
    }
}
