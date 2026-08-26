<?php

namespace Tests\Video\Llm;

use App\Services\Admin\ClaudeWriterService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Seam cuoi truoc curl. Test o tang adapter chi chung minh temperature toi
 * duoc THAM SO cua service — xoa dong ghi vao body thi chung van xanh.
 */
class ClaudeRequestBodyTest extends TestCase
{
    /** @return array<string, mixed> */
    private function body(?float $temperature): array
    {
        $method = new ReflectionMethod(ClaudeWriterService::class, 'requestBody');
        $method->setAccessible(true);

        return $method->invoke(
            new ClaudeWriterService,
            'prompt',
            'claude-sonnet-4-6',
            2500,
            'instruction',
            $temperature,
        );
    }

    public function test_an_absent_temperature_never_reaches_the_wire(): void
    {
        $this->assertArrayNotHasKey('temperature', $this->body(null));
    }

    public function test_zero_is_written_to_the_body_rather_than_treated_as_absent(): void
    {
        $body = $this->body(0.0);

        $this->assertArrayHasKey('temperature', $body);
        $this->assertSame(0.0, $body['temperature']);
    }

    public function test_a_creative_temperature_reaches_the_body(): void
    {
        $this->assertSame(0.7, $this->body(0.7)['temperature']);
    }

    /** @param array<string, mixed> $json */
    private function parsed(array $json): \App\Services\Admin\ClaudeResponse
    {
        $method = new ReflectionMethod(ClaudeWriterService::class, 'responseFromBody');
        $method->setAccessible(true);

        return $method->invoke(new ClaudeWriterService, $json);
    }

    public function test_the_provider_model_is_read_from_the_response_body(): void
    {
        $response = $this->parsed([
            'model' => 'claude-sonnet-4-6',
            'stop_reason' => 'end_turn',
            'content' => [['text' => 'xong']],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 40],
        ]);

        $this->assertSame('claude-sonnet-4-6', $response->providerModel);
        $this->assertSame('xong', $response->text);
        $this->assertSame(120, $response->inputTokens);
        $this->assertSame(40, $response->outputTokens);
    }

    public function test_a_body_without_a_model_yields_an_empty_provider_model(): void
    {
        $this->assertSame('', $this->parsed(['content' => [['text' => 'xong']]])->providerModel);
    }

    public function test_the_provider_model_is_not_confused_with_the_alias(): void
    {
        $response = $this->parsed(['model' => 'claude-haiku-4-5-20251001']);

        $this->assertSame('claude-haiku-4-5-20251001', $response->providerModel);
        $this->assertNotSame('haiku', $response->providerModel);
    }
}
