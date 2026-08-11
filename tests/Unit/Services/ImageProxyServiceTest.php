<?php

namespace Tests\Unit\Services;

use App\Services\ImageProxyService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

class ImageProxyServiceTest extends TestCase
{
    public function test_private_hosts_are_rejected_before_any_http_request(): void
    {
        [$service, $history] = $this->serviceWithResponses(new Response(200));

        $this->expectException(RuntimeException::class);
        try {
            $service->fetch('http://127.0.0.1/internal');
        } finally {
            $this->assertCount(0, $history->entries);
        }
    }

    public function test_it_disables_redirects_verifies_tls_and_detects_mime_from_bytes(): void
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        [$service, $history] = $this->serviceWithResponses(
            new Response(200, ['Content-Type' => 'text/html'], $png),
        );

        $image = $service->fetch('https://93.184.216.34/image.png');

        $this->assertSame('image/png', $image['mime']);
        $this->assertSame($png, $image['body']);
        $this->assertFalse($history->entries[0]['options']['allow_redirects']);
        $this->assertTrue($history->entries[0]['options']['verify']);
        $this->assertTrue($history->entries[0]['options']['stream']);
        $this->assertSame(
            ['93.184.216.34:443:93.184.216.34'],
            $history->entries[0]['options']['curl'][CURLOPT_RESOLVE],
        );
    }

    public function test_redirect_responses_are_not_followed(): void
    {
        [$service, $history] = $this->serviceWithResponses(
            new Response(302, ['Location' => 'http://127.0.0.1/private']),
        );

        $this->expectException(RuntimeException::class);
        try {
            $service->fetch('https://93.184.216.34/image.png');
        } finally {
            $this->assertCount(1, $history->entries);
        }
    }

    /** @return array{ImageProxyService, stdClass} */
    private function serviceWithResponses(Response ...$responses): array
    {
        $history = new stdClass;
        $history->entries = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history->entries));

        return [new ImageProxyService(new Client(['handler' => $stack])), $history];
    }
}
