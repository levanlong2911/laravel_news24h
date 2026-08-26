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
        return $this->bodyFor('sonnet', 'claude-sonnet-4-6', $temperature);
    }

    /** @return array<string, mixed> */
    private function bodyFor(string $modelType, string $model, ?float $temperature = null): array
    {
        $method = new ReflectionMethod(ClaudeWriterService::class, 'requestBody');
        $method->setAccessible(true);

        return $method->invoke(
            new ClaudeWriterService,
            'prompt',
            $modelType,
            $model,
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
            'content' => [['type' => 'text', 'text' => 'xong']],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 40],
        ]);

        $this->assertSame('claude-sonnet-4-6', $response->providerModel);
        $this->assertSame('xong', $response->text);
        $this->assertSame(120, $response->inputTokens);
        $this->assertSame(40, $response->outputTokens);
    }

    public function test_a_body_without_a_model_yields_an_empty_provider_model(): void
    {
        $this->assertSame('', $this->parsed(['content' => [['type' => 'text', 'text' => 'xong']]])->providerModel);
    }

    public function test_the_provider_model_is_not_confused_with_the_alias(): void
    {
        $response = $this->parsed(['model' => 'claude-haiku-4-5-20251001']);

        $this->assertSame('claude-haiku-4-5-20251001', $response->providerModel);
        $this->assertNotSame('haiku', $response->providerModel);
    }

    public function test_the_text_block_after_a_thinking_block_is_the_one_that_is_read(): void
    {
        $response = $this->parsed([
            'model' => 'claude-sonnet-5',
            'stop_reason' => 'end_turn',
            'content' => [
                ['type' => 'thinking', 'thinking' => 'can nhac...'],
                ['type' => 'text', 'text' => '{"design_thesis":"mot vo lien tuc"}'],
            ],
            'usage' => ['input_tokens' => 2700, 'output_tokens' => 1400],
        ]);

        $this->assertSame('{"design_thesis":"mot vo lien tuc"}', $response->text);
        $this->assertSame('claude-sonnet-5', $response->providerModel);
    }

    public function test_several_text_blocks_are_joined_without_inserting_anything(): void
    {
        $response = $this->parsed([
            'content' => [
                ['type' => 'thinking', 'thinking' => 'bo qua'],
                ['type' => 'text', 'text' => '{"decision": "mot cau bi cat'],
                ['type' => 'text', 'text' => ' lam doi"}'],
            ],
        ]);

        $this->assertSame('{"decision": "mot cau bi cat lam doi"}', $response->text);
        $this->assertIsArray(json_decode($response->text, true));
    }

    public function test_a_thinking_only_body_is_empty_text_not_a_crash(): void
    {
        $response = $this->parsed([
            'model' => 'claude-sonnet-5',
            'stop_reason' => 'max_tokens',
            'content' => [['type' => 'thinking', 'thinking' => 'nghi het 2500 token']],
            'usage' => ['input_tokens' => 2700, 'output_tokens' => 2500],
        ]);

        $this->assertSame('', $response->text);
        $this->assertTrue($response->wasTruncated());
        $this->assertSame(2500, $response->outputTokens);
        $this->assertSame('claude-sonnet-5', $response->providerModel);
    }

    public function test_a_body_with_no_content_at_all_is_empty_text(): void
    {
        $this->assertSame('', $this->parsed([])->text);
    }

    public function test_the_sonnet_five_row_switches_thinking_off_on_the_wire(): void
    {
        $body = $this->bodyFor('sonnet5', 'claude-sonnet-5');

        $this->assertSame(['type' => 'disabled'], $body['thinking']);
    }

    public function test_rows_that_declare_no_thinking_send_no_thinking_key(): void
    {
        $this->assertArrayNotHasKey('thinking', $this->bodyFor('sonnet', 'claude-sonnet-4-6'));
        $this->assertArrayNotHasKey('thinking', $this->bodyFor('haiku', 'claude-haiku-4-5-20251001'));
    }

    public function test_the_thinking_tokens_are_read_from_the_usage_details(): void
    {
        $response = $this->parsed([
            'model' => 'claude-sonnet-5',
            'stop_reason' => 'max_tokens',
            'content' => [['type' => 'thinking', 'thinking' => 'nghi rat lau']],
            'usage' => [
                'input_tokens' => 2700,
                'output_tokens' => 2500,
                'output_tokens_details' => ['thinking_tokens' => 2500],
            ],
        ]);

        $this->assertSame(2500, $response->thinkingTokens);
        $this->assertSame(2500, $response->outputTokens);
        $this->assertSame('', $response->text);
    }

    public function test_a_usage_without_details_reports_no_thinking_tokens(): void
    {
        $response = $this->parsed([
            'content' => [['type' => 'text', 'text' => 'OK']],
            'usage' => ['input_tokens' => 16, 'output_tokens' => 4],
        ]);

        $this->assertSame(0, $response->thinkingTokens);
    }
}
