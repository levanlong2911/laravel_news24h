<?php

namespace Tests\Feature\Video;

use App\Services\Video\DesignImageDirectRenderer;
use App\Services\Video\DesignImageQueue;
use Tests\TestCase;

class DesignImageRenderLeaseTest extends TestCase
{
    public function test_the_lease_outlasts_a_slower_gemini_timeout(): void
    {
        $this->assertLeaseFor(openai: 300, gemini: 900, expected: 960);
    }

    public function test_the_lease_still_covers_a_slower_openai_timeout(): void
    {
        $this->assertLeaseFor(openai: 400, gemini: 300, expected: 460);
    }

    private function assertLeaseFor(int $openai, int $gemini, int $expected): void
    {
        config([
            'video.openai_image.timeout' => $openai,
            'video.gemini.image_timeout' => $gemini,
        ]);

        $this->mock(DesignImageQueue::class)
            ->shouldReceive('claimForDirectRender')
            ->once()
            ->with('image-1', $expected, null)
            ->andReturn([null, null, 'image_not_found']);

        [$image, $reason] = app(DesignImageDirectRenderer::class)->renderNow('image-1');

        $this->assertNull($image);
        $this->assertSame('image_not_found', $reason);
    }
}
