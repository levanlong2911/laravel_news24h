<?php

namespace Tests\Unit\Validators;

use App\DTOs\AssetRefDTO;
use App\DTOs\SceneDTO;
use App\DTOs\ShotDTO;
use App\Services\AI\Validators\SceneValidator;
use PHPUnit\Framework\TestCase;

class SceneValidatorRuleOneTest extends TestCase
{
    private function makeShot(array $overrides = []): ShotDTO
    {
        return ShotDTO::fromArray(array_merge([
            'shot_order'   => 1,
            'cam'          => 'MACRO',
            'lens'         => '85',
            'light'        => 'W1',
            'move'         => 'P1',
            'emo'          => 'CRAFT',
            'dur'          => 1.5,
            'motion_level' => 'low',
            'realism'      => 'high',
            'has_human'    => false,
        ], $overrides));
    }

    private function makeScene(array $shots, float $duration = 1.5): SceneDTO
    {
        return new SceneDTO('scene-1', 1, 'Test Scene', 'anticipation', $duration, $shots);
    }

    public function test_valid_scene_passes(): void
    {
        $scene = $this->makeScene([$this->makeShot()]);
        $this->expectNotToPerformAssertions();
        (new SceneValidator())->validate($scene);
    }

    public function test_provider_name_flux_triggers_rule_one_violation(): void
    {
        // Simulates a Planner that incorrectly added "flux" to camera_goal
        $shot  = $this->makeShot(['camera_goal' => 'use flux model']);
        $scene = $this->makeScene([$shot]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/RULE VIOLATION.*flux/');
        (new SceneValidator())->validate($scene);
    }

    public function test_provider_name_kling_triggers_rule_one_violation(): void
    {
        $shot  = $this->makeShot(['camera_goal' => 'kling motion']);
        $scene = $this->makeScene([$shot]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/RULE VIOLATION.*kling/');
        (new SceneValidator())->validate($scene);
    }

    public function test_empty_shots_throws(): void
    {
        $scene = $this->makeScene([]);
        $this->expectException(\InvalidArgumentException::class);
        (new SceneValidator())->validate($scene);
    }
}
