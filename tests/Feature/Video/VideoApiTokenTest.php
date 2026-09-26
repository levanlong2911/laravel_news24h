<?php

namespace Tests\Feature\Video;

use Tests\TestCase;

class VideoApiTokenTest extends TestCase
{
    /** @return list<array{0: string, 1: string}> */
    public static function everyPythonRoute(): array
    {
        $id = '00000000-0000-0000-0000-000000000000';

        return [
            ['post', '/api/render-plans'],
            ['get', '/api/video-sessions/composing'],
            ['get', '/api/video-sessions/test_code/design-cells'],
            ['get', '/api/video-shots/queued'],
            ['post', '/api/video-shots/claim'],
            ['post', '/api/video-shots/reclaim-expired'],
            ['patch', "/api/video-shots/{$id}/heartbeat"],
            ['patch', "/api/video-shots/{$id}/result"],
            ['get', '/api/video-finals/composing'],
            ['patch', "/api/video-finals/{$id}/result"],
        ];
    }

    /** @dataProvider everyPythonRoute */
    public function test_the_retired_python_composer_routes_are_not_registered(string $method, string $url): void
    {
        $this->withHeader('X-Video-Token', 'even-an-old-valid-token')
            ->json($method, $url)
            ->assertNotFound();
    }
}
