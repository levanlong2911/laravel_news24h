<?php

namespace Tests\Unit\DTOs;

use App\DTOs\AssetRefDTO;
use App\DTOs\ShotDTO;
use PHPUnit\Framework\TestCase;

class ShotDTOTest extends TestCase
{
    private function validShotData(): array
    {
        return [
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
            'camera_goal'  => 'show craftsmanship',
            'sub'          => ['actor' => 'mechanic', 'action' => 'install_seat', 'obj' => 'bike_seat'],
            'asset_ref'    => ['id' => 'bike_seat', 'type' => 'component', 'reuse' => true, 'variation' => 'black_matte'],
        ];
    }

    public function test_from_array_creates_shot(): void
    {
        $shot = ShotDTO::fromArray($this->validShotData());

        $this->assertSame(1, $shot->shotOrder);
        $this->assertSame('MACRO', $shot->cam);
        $this->assertSame('85', $shot->lens);
        $this->assertSame('low', $shot->motionLevel);
        $this->assertSame('high', $shot->realism);
        $this->assertFalse($shot->hasHuman);
        $this->assertInstanceOf(AssetRefDTO::class, $shot->assetRef);
        $this->assertSame('bike_seat', $shot->assetRef->id);
        $this->assertTrue($shot->assetRef->reuse);
    }

    public function test_to_array_round_trips(): void
    {
        $original = $this->validShotData();
        $shot     = ShotDTO::fromArray($original);
        $result   = $shot->toArray();

        $this->assertSame($original['shot_order'], $result['shot_order']);
        $this->assertSame($original['motion_level'], $result['motion_level']);
        $this->assertSame($original['realism'], $result['realism']);
        $this->assertSame($original['has_human'], $result['has_human']);
        $this->assertSame($original['asset_ref']['id'], $result['asset_ref']['id']);
    }

    public function test_no_provider_name_in_shot_data(): void
    {
        $shot = ShotDTO::fromArray($this->validShotData());
        $json = strtolower(json_encode($shot->toArray()));

        // Rule 1: Planner output must never contain provider names
        $forbiddenTerms = ['fal_flux', 'fal.ai', 'kling', 'veo', 'ideogram', 'replicate', 'dalle'];
        foreach ($forbiddenTerms as $term) {
            $this->assertStringNotContainsString($term, $json, "Provider name '{$term}' found in ShotDTO — Rule 1 violation");
        }
    }

    public function test_shot_without_asset_ref(): void
    {
        $data = $this->validShotData();
        unset($data['asset_ref']);

        $shot = ShotDTO::fromArray($data);
        $this->assertNull($shot->assetRef);

        $result = $shot->toArray();
        $this->assertArrayNotHasKey('asset_ref', $result);
    }
}
