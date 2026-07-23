<?php

namespace Tests\Video\Llm;

use App\Video\Llm\CostCeilingGate;
use App\Video\Llm\LlmRequest;
use PHPUnit\Framework\TestCase;

class CostCeilingGateTest extends TestCase
{
    private function request(): LlmRequest
    {
        return new LlmRequest('instruction', 'input', 'v1', 'sonnet');
    }

    public function test_allows_when_cost_at_or_below_ceiling(): void
    {
        $gate = new CostCeilingGate(0.05);

        $this->assertTrue($gate->allows($this->request(), 0.05));
        $this->assertTrue($gate->allows($this->request(), 0.01));
    }

    public function test_denies_when_cost_exceeds_ceiling(): void
    {
        $gate = new CostCeilingGate(0.05);

        $this->assertFalse($gate->allows($this->request(), 0.051));
        $this->assertFalse($gate->allows($this->request(), 5.0));
    }

    public function test_default_ceiling_is_five_cents(): void
    {
        $gate = new CostCeilingGate();

        $this->assertTrue($gate->allows($this->request(), 0.05));
        $this->assertFalse($gate->allows($this->request(), 0.06));
    }
}
