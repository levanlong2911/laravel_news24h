<?php

namespace Tests\Video\Llm;

use App\Video\Llm\CostAccumulatingLlmClient;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Llm\LlmResponse;
use PHPUnit\Framework\TestCase;

class CostAccumulatingLlmClientTest extends TestCase
{
    private function request(): LlmRequest
    {
        return new LlmRequest('instruction', 'input', 'v1', 'sonnet');
    }

    private function stubClient(LlmResponse $response): LlmClient
    {
        return new class ($response) implements LlmClient {
            public function __construct(private readonly LlmResponse $response)
            {
            }

            public function complete(LlmRequest $request): LlmResponse
            {
                return $this->response;
            }
        };
    }

    public function test_passes_through_the_response_unchanged(): void
    {
        $response = new LlmResponse('text', 'sonnet', 100, 50, 200, 0.01);
        $client   = new CostAccumulatingLlmClient($this->stubClient($response));

        $this->assertSame($response, $client->complete($this->request()));
    }

    public function test_accumulates_across_multiple_calls(): void
    {
        $client = new CostAccumulatingLlmClient($this->stubClient(
            new LlmResponse('text', 'sonnet', 100, 50, 200, 0.01),
        ));

        $client->complete($this->request());
        $client->complete($this->request());
        $client->complete($this->request());

        $this->assertSame([
            'call_count' => 3,
            'tokens_in'  => 300,
            'tokens_out' => 150,
            'cost_usd'   => 0.03,
            'latency_ms' => 600,
        ], $client->totals());
    }

    public function test_totals_zero_before_any_call(): void
    {
        $client = new CostAccumulatingLlmClient($this->stubClient(new LlmResponse('text', 'sonnet')));

        $this->assertSame([
            'call_count' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0.0, 'latency_ms' => 0,
        ], $client->totals());
    }

    public function test_reset_clears_totals(): void
    {
        $client = new CostAccumulatingLlmClient($this->stubClient(
            new LlmResponse('text', 'sonnet', 100, 50, 200, 0.01),
        ));

        $client->complete($this->request());
        $client->reset();

        $this->assertSame(0, $client->totals()['call_count']);
    }
}
