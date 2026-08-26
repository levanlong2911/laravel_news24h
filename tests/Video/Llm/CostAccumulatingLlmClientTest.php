<?php

namespace Tests\Video\Llm;

use App\Video\Llm\CostAccumulatingLlmClient;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Llm\LlmResponse;
use App\Video\Llm\LlmUnavailable;
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
            'provider_model' => '',
            'thinking_tokens' => 0,
        ], $client->totals());
    }

    public function test_totals_zero_before_any_call(): void
    {
        $client = new CostAccumulatingLlmClient($this->stubClient(new LlmResponse('text', 'sonnet')));

        $this->assertSame([
            'call_count' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0.0, 'latency_ms' => 0,
            'provider_model' => '',
            'thinking_tokens' => 0,
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

    public function test_repeated_calls_to_one_model_list_it_once(): void
    {
        $client = new CostAccumulatingLlmClient($this->stubClient(
            new LlmResponse('text', 'sonnet', 100, 50, 200, 0.01, 'text', 'claude-sonnet-4-6'),
        ));

        $client->complete($this->request());
        $client->complete($this->request());

        $this->assertSame('claude-sonnet-4-6', $client->totals()['provider_model']);
    }

    public function test_a_response_that_names_no_provider_model_adds_nothing(): void
    {
        $client = new CostAccumulatingLlmClient($this->stubClient(new LlmResponse('text', 'sonnet')));

        $client->complete($this->request());

        $this->assertSame('', $client->totals()['provider_model']);
    }

    private function failingClient(?LlmResponse $billed): LlmClient
    {
        return new class($billed) implements LlmClient
        {
            public function __construct(private readonly ?LlmResponse $billed) {}

            public function complete(LlmRequest $request): LlmResponse
            {
                throw new LlmUnavailable('bi cat', $this->billed);
            }
        };
    }

    public function test_a_failed_but_paid_call_still_moves_the_meter(): void
    {
        $client = new CostAccumulatingLlmClient($this->failingClient(
            new LlmResponse('', 'sonnet5', 2700, 2500, 900, 0.0304, '', 'claude-sonnet-5'),
        ));

        try {
            $client->complete($this->request());
            $this->fail('phai nem tiep');
        } catch (LlmUnavailable $e) {
            $totals = $client->totals();

            $this->assertSame(1, $totals['call_count']);
            $this->assertSame(2700, $totals['tokens_in']);
            $this->assertSame(2500, $totals['tokens_out']);
            $this->assertSame(0.0304, $totals['cost_usd']);
            $this->assertSame('claude-sonnet-5', $totals['provider_model']);
        }
    }

    public function test_an_unreachable_provider_leaves_the_meter_alone(): void
    {
        $client = new CostAccumulatingLlmClient($this->failingClient(null));

        try {
            $client->complete($this->request());
            $this->fail('phai nem tiep');
        } catch (LlmUnavailable $e) {
            $this->assertSame(0, $client->totals()['call_count']);
            $this->assertSame(0.0, $client->totals()['cost_usd']);
            $this->assertSame('', $client->totals()['provider_model']);
        }
    }

    public function test_thinking_tokens_are_summed_across_calls(): void
    {
        $client = new CostAccumulatingLlmClient($this->stubClient(
            new LlmResponse('text', 'sonnet5', 2700, 2500, 900, 0.03, 'text', 'claude-sonnet-5', 1800),
        ));

        $client->complete($this->request());
        $client->complete($this->request());

        $this->assertSame(3600, $client->totals()['thinking_tokens']);
    }

    public function test_a_failed_but_paid_call_reports_what_thinking_ate(): void
    {
        $client = new CostAccumulatingLlmClient($this->failingClient(
            new LlmResponse('', 'sonnet5', 2700, 2500, 900, 0.0304, '', 'claude-sonnet-5', 2500),
        ));

        try {
            $client->complete($this->request());
            $this->fail('phai nem tiep');
        } catch (LlmUnavailable $e) {
            $this->assertSame(2500, $client->totals()['thinking_tokens']);
            $this->assertSame(2500, $client->totals()['tokens_out']);
        }
    }

    public function test_reset_clears_the_thinking_meter_too(): void
    {
        $client = new CostAccumulatingLlmClient($this->stubClient(
            new LlmResponse('text', 'sonnet5', 10, 10, 10, 0.001, 'text', 'claude-sonnet-5', 7),
        ));

        $client->complete($this->request());
        $client->reset();

        $this->assertSame(0, $client->totals()['thinking_tokens']);
    }
}
