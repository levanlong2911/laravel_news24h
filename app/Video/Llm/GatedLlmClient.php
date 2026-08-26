<?php

namespace App\Video\Llm;


final class GatedLlmClient implements LlmClient
{
    public function __construct(
        private readonly LlmClient $inner,
        private readonly ApprovalGate $gate = new DenyByDefaultGate(),

        private readonly float $inputPricePerMillion = 3.0,
    ) {
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $tokens = $request->estimatedInputTokens();
        $cost   = $tokens / 1_000_000 * $this->inputPricePerMillion;

        if (! $this->gate->allows($request, $cost)) {
            throw new ApprovalRequired(
                sprintf('Gọi %s (instruction %s)', $request->model, $request->instructionVersion),
                $cost,
                $tokens,
            );
        }

        return $this->inner->complete($request);
    }
}
