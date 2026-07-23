<?php

namespace Tests\Video\Pipeline;

use App\Video\Editorial\EditorialPolicy;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Llm\LlmResponse;
use App\Video\Pipeline\VideoPipelineFactory;
use App\Video\Pipeline\VideoPlanningPipeline;
use PHPUnit\Framework\TestCase;

class VideoPipelineFactoryTest extends TestCase
{
    private function stubClient(): LlmClient
    {
        return new class implements LlmClient {
            public function complete(LlmRequest $request): LlmResponse
            {
                return new LlmResponse('{}', 'sonnet');
            }
        };
    }

    public function test_claude_builds_a_pipeline(): void
    {
        $pipeline = VideoPipelineFactory::claude($this->stubClient());

        $this->assertInstanceOf(VideoPlanningPipeline::class, $pipeline);
    }

    public function test_claude_accepts_policies_without_error(): void
    {
        $policy = new EditorialPolicy(['builder' => 'Feadship'], 'domes', true, 'reason');

        $pipeline = VideoPipelineFactory::claude($this->stubClient(), [$policy]);

        $this->assertInstanceOf(VideoPlanningPipeline::class, $pipeline);
    }
}
