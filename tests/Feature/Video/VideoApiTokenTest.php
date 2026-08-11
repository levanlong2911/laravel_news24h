<?php

namespace Tests\Feature\Video;

use Tests\TestCase;

class VideoApiTokenTest extends TestCase
{
    public function test_python_api_authenticates_with_the_loaded_config_value(): void
    {
        config(['video.api_token' => 'token-loaded-before-runtime']);

        $this->withHeader('X-Video-Token', 'token-loaded-before-runtime')
            ->getJson('/api/video-sessions/composing')
            ->assertOk();
    }

    public function test_python_api_rejects_an_empty_configured_token(): void
    {
        config(['video.api_token' => null]);

        $this->withHeader('X-Video-Token', '')
            ->getJson('/api/video-sessions/composing')
            ->assertUnauthorized();
    }
}
