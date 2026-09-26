<?php

namespace Tests\Feature\Video;

use App\Video\Prompt\Exceptions\TextCompletionException;
use App\Video\Prompt\Exceptions\TextCompletionRefusalException;
use App\Video\Prompt\OpenAiTextClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Tests\TestCase;

class OpenAiStreamingClientTest extends TestCase
{
    private Factory $http;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new Factory;
        $this->http->preventStrayRequests();
    }

    /** @param list<array<string, mixed>|string> $events */
    private function streamBody(array $events): string
    {
        return implode('', array_map(
            static fn (array|string $event): string => 'data: '
                .(is_string($event) ? $event : json_encode($event, JSON_THROW_ON_ERROR))."\n\n",
            $events,
        ));
    }

    /** @param list<array<string, mixed>|string> $events */
    private function client(array $events, bool $stream = true): OpenAiTextClient
    {
        $this->http->fake(['https://openai.test/*' => function (Request $request) use ($events, $stream) {
            $this->sent[] = $request->data();

            return $stream
                ? $this->http->response($this->streamBody($events), 200, ['content-type' => 'text/event-stream'])
                : $this->http->response($events[0], 200);
        }]);

        return new OpenAiTextClient($this->http, 'fake-key', 'https://openai.test', 'medium', 900, 1, 0, $stream);
    }

    /** @return array<string, mixed> */
    private function delta(string $content, ?string $finish = null): array
    {
        return [
            'model' => 'gpt-5.6-terra-2026-09-01',
            'choices' => [['index' => 0, 'delta' => ['content' => $content], 'finish_reason' => $finish]],
        ];
    }

    /** @return array<string, mixed> */
    private function usage(): array
    {
        return [
            'model' => 'gpt-5.6-terra-2026-09-01',
            'choices' => [],
            'usage' => [
                'prompt_tokens' => 17000,
                'completion_tokens' => 9000,
                'completion_tokens_details' => ['reasoning_tokens' => 4000],
            ],
        ];
    }

    public function test_a_stream_is_assembled_with_usage_and_reasoning_tokens(): void
    {
        $response = $this->client([
            $this->delta('{"scenes":'),
            $this->delta('[]}', 'stop'),
            $this->usage(),
            '[DONE]',
        ])->complete('gpt-5.6-terra', 'system', 'user', 32000, ['type' => 'object']);

        $this->assertSame('{"scenes":[]}', $response->text);
        $this->assertSame('gpt-5.6-terra-2026-09-01', $response->model);
        $this->assertSame('stop', $response->stopReason);
        $this->assertSame(17000, $response->inputTokens);
        $this->assertSame(9000, $response->outputTokens);
        $this->assertSame(4000, $response->reasoningTokens);

        $this->assertCount(1, $this->sent);
        $this->assertTrue($this->sent[0]['stream']);
        $this->assertSame(['include_usage' => true], $this->sent[0]['stream_options']);
        $this->assertSame('json_schema', $this->sent[0]['response_format']['type']);
        $this->assertSame(32000, $this->sent[0]['max_completion_tokens']);
    }

    public function test_a_length_stop_is_reported_as_truncated(): void
    {
        $response = $this->client([
            $this->delta('{"scenes":[', 'length'),
            $this->usage(),
            '[DONE]',
        ])->complete('gpt-5.6-terra', 'system', 'user', 32000);

        $this->assertTrue($response->wasTruncated());
        $this->assertSame('{"scenes":[', $response->text);
    }

    public function test_a_stream_that_never_finishes_is_an_error(): void
    {
        $this->expectException(TextCompletionException::class);
        $this->expectExceptionMessage('ended before [DONE]');

        $this->client([$this->delta('{"scenes":')])->complete('gpt-5.6-terra', 'system', 'user', 32000);
    }

    public function test_an_error_event_inside_the_stream_is_raised(): void
    {
        $this->expectException(TextCompletionException::class);
        $this->expectExceptionMessage('OpenAI stream error (server_error): boom');

        $this->client([
            $this->delta('{"scenes":'),
            ['error' => ['code' => 'server_error', 'message' => 'boom']],
        ])->complete('gpt-5.6-terra', 'system', 'user', 32000);
    }

    public function test_a_streamed_refusal_is_raised_as_a_refusal(): void
    {
        $this->expectException(TextCompletionRefusalException::class);

        $this->client([
            ['choices' => [['index' => 0, 'delta' => ['refusal' => 'I cannot help.'], 'finish_reason' => 'stop']]],
            '[DONE]',
        ])->complete('gpt-5.6-terra', 'system', 'user', 32000);
    }

    public function test_the_default_client_still_reads_a_plain_response(): void
    {
        $response = $this->client([[
            'model' => 'gpt-5.6-terra-2026-09-01',
            'choices' => [['message' => ['content' => 'plain text'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]], false)->complete('gpt-5.6-terra', 'system', 'user', 100);

        $this->assertSame('plain text', $response->text);
        $this->assertSame(10, $response->inputTokens);
        $this->assertArrayNotHasKey('stream', $this->sent[0]);
    }
}
