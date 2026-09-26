<?php

namespace Tests\Feature\Video;

use App\Video\Concept\Claude\AnthropicStructuredOutputClient;
use App\Video\Concept\Exceptions\AnthropicRequestException;
use App\Video\Concept\Exceptions\AnthropicTruncatedOutputException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Tests\TestCase;

class AnthropicStreamingClientTest extends TestCase
{
    /** @param  list<array<string, mixed>>  $events */
    private function sse(array $events): string
    {
        $lines = '';

        foreach ($events as $event) {
            $lines .= 'event: '.$event['type']."\n".'data: '.json_encode($event)."\n\n";
        }

        return $lines;
    }

    /** @return list<array<string, mixed>> */
    private function answer(string $text, string $stopReason = 'end_turn', int $outputTokens = 1234): array
    {
        return [
            ['type' => 'message_start', 'message' => [
                'id' => 'msg_test', 'model' => 'claude-sonnet-5',
                'usage' => ['input_tokens' => 24680, 'output_tokens' => 1],
            ]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'ping'],
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => mb_substr($text, 0, 5)]],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => mb_substr($text, 5)]],
            ['type' => 'content_block_stop', 'index' => 1],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => $stopReason],
                'usage' => ['output_tokens' => $outputTokens, 'output_tokens_details' => ['thinking_tokens' => 800]]],
            ['type' => 'message_stop'],
        ];
    }

    private function client(Factory $http, bool $stream = true, ?string $effort = 'medium'): AnthropicStructuredOutputClient
    {
        return new AnthropicStructuredOutputClient(
            $http, 'fake-key', 'https://stream.test', '2023-06-01', 900, 1, 0, $stream, $effort,
        );
    }

    private function fake(string $body): Factory
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['https://stream.test/*' => $http->response($body, 200, ['content-type' => 'text/event-stream'])]);

        return $http;
    }

    private function send(AnthropicStructuredOutputClient $client): \App\Video\Concept\Claude\AnthropicStructuredOutputResponse
    {
        return $client->create(
            'claude-sonnet-5',
            'System rules.',
            [['role' => 'user', 'content' => '{"foundation":{}}']],
            ['type' => 'object'],
            32000,
        );
    }

    public function test_a_streamed_answer_is_assembled_from_its_text_deltas_with_its_usage(): void
    {
        $json = '{"characters":[],"locations":[],"scenes":[],"coverage":[]}';
        $http = $this->fake($this->sse($this->answer($json)));

        $response = $this->send($this->client($http));

        $this->assertSame($json, $response->rawText);
        $this->assertSame('end_turn', $response->stopReason);
        $this->assertSame(24680, $response->inputTokens);
        $this->assertSame(1234, $response->outputTokens);
        $this->assertSame('claude-sonnet-5', $response->model);

        $http->assertSent(static fn (Request $request): bool => $request['stream'] === true
            && $request['output_config']['effort'] === 'medium'
            && $request['output_config']['format']['type'] === 'json_schema'
            && $request['max_tokens'] === 32000);
    }

    public function test_a_streamed_answer_cut_at_the_token_limit_keeps_its_usage(): void
    {
        $http = $this->fake($this->sse($this->answer('{"characters":[', 'max_tokens', 32000)));

        try {
            $this->send($this->client($http));
            $this->fail('a truncated stream must not pass as an answer');
        } catch (AnthropicTruncatedOutputException $e) {
            $this->assertSame(32000, $e->response?->outputTokens);
            $this->assertSame(24680, $e->response?->inputTokens);
            $this->assertStringContainsString('"stop_reason":"max_tokens"', (string) $e->response?->rawText);
            $this->assertStringContainsString('{\"characters\":[', (string) $e->response?->rawText);
        }
    }

    public function test_an_error_event_inside_the_stream_is_reported(): void
    {
        $events = array_slice($this->answer('{}'), 0, 3);
        $events[] = ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']];
        $http = $this->fake($this->sse($events));

        $this->expectException(AnthropicRequestException::class);
        $this->expectExceptionMessage('Anthropic stream error: overloaded_error: Overloaded');

        $this->send($this->client($http));
    }

    public function test_a_stream_that_stops_before_message_stop_is_not_taken_as_complete(): void
    {
        $events = array_slice($this->answer('{"characters":[]}'), 0, 9);
        $http = $this->fake($this->sse($events));

        $this->expectException(AnthropicRequestException::class);
        $this->expectExceptionMessage('stream ended before message_stop');

        $this->send($this->client($http));
    }

    public function test_the_default_client_sends_neither_stream_nor_effort(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['https://stream.test/*' => $http->response([
            'model' => 'claude-sonnet-5',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => '{"ok":true}']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200)]);

        $response = $this->send($this->client($http, stream: false, effort: null));

        $this->assertSame('{"ok":true}', $response->rawText);
        $http->assertSent(static fn (Request $request): bool => ! array_key_exists('stream', $request->data())
            && ! array_key_exists('effort', $request['output_config']));
    }

    public function test_an_unknown_effort_is_refused_before_any_request(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Anthropic effort must be one of');

        $this->client(new Factory, effort: 'extreme');
    }

    public function test_the_production_scene_author_streams_at_medium_effort_and_nothing_else_does(): void
    {
        $read = static function (object $object, string $name): mixed {
            $property = (new \ReflectionClass($object))->getProperty($name);
            $property->setAccessible(true);

            return $property->getValue($object);
        };

        $this->app->forgetInstance('video.screenplay.scene_author');
        $this->app->forgetInstance('video.screenplay.foundation_author');
        $scenes = $read($this->app->make('video.screenplay.scene_author'), 'client');
        $foundation = $read($this->app->make('video.screenplay.foundation_author'), 'client');

        $this->assertTrue($scenes->streams());
        $this->assertSame('medium', $scenes->effort());
        $this->assertFalse($foundation->streams());
        $this->assertNull($foundation->effort());
    }
}
