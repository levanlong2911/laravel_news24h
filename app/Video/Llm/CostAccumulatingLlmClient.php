<?php

namespace App\Video\Llm;

final class CostAccumulatingLlmClient implements LlmClient
{
    private int $callCount = 0;

    private int $tokensIn = 0;

    private int $tokensOut = 0;

    private float $costUsd = 0.0;

    private int $latencyMs = 0;

    /** @var list<string> */
    private array $providerModels = [];

    public function __construct(
        private readonly LlmClient $inner,
    ) {}

    public function complete(LlmRequest $request): LlmResponse
    {
        $response = $this->inner->complete($request);

        $this->callCount++;
        $this->tokensIn += $response->tokensIn;
        $this->tokensOut += $response->tokensOut;
        $this->costUsd += $response->costUsd;
        $this->latencyMs += $response->latencyMs;

        if ($response->providerModel !== '' && ! in_array($response->providerModel, $this->providerModels, true)) {
            $this->providerModels[] = $response->providerModel;
        }

        return $response;
    }

    /**
     * @return array{call_count: int, tokens_in: int, tokens_out: int, cost_usd: float, latency_ms: int, provider_model: string}
     */
    public function totals(): array
    {
        return [
            'call_count' => $this->callCount,
            'tokens_in' => $this->tokensIn,
            'tokens_out' => $this->tokensOut,
            'cost_usd' => $this->costUsd,
            'latency_ms' => $this->latencyMs,
            'provider_model' => implode(', ', $this->providerModels),
        ];
    }

    public function reset(): void
    {
        $this->callCount = 0;
        $this->tokensIn = 0;
        $this->tokensOut = 0;
        $this->costUsd = 0.0;
        $this->latencyMs = 0;
        $this->providerModels = [];
    }
}
