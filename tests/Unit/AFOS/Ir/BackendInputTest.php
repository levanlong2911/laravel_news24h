<?php

namespace Tests\Unit\AFOS\Ir;

use App\Services\AI\AFOS\Ir\BackendInput;
use App\Services\AI\AFOS\Ir\PromptIR;
use PHPUnit\Framework\TestCase;

final class BackendInputTest extends TestCase
{
    private function makePromptIR(string $shotId = 'test-shot'): PromptIR
    {
        return new PromptIR(
            shotId:            $shotId,
            subjectClause:     'A superyacht hull emerges from golden mist',
            atmosphereClause:  'Golden hour serenity, negative space above',
            cameraClause:      'Slow push-in, wide to medium, 35mm',
            compositionClause: 'Rule of thirds, eye flow left to right',
            emotionalClose:    'Quiet grandeur fills the frame',
            technicalSpec:     '4K cinematic, RAW, 24fps',
        );
    }

    public function test_construction_holds_prompt_and_backend_id(): void
    {
        $prompt = $this->makePromptIR();
        $input  = new BackendInput(prompt: $prompt, backendId: 'kling');

        $this->assertSame($prompt, $input->prompt);
        $this->assertSame('kling', $input->backendId);
    }

    public function test_is_immutable_value_object(): void
    {
        $prompt = $this->makePromptIR();
        $input  = new BackendInput(prompt: $prompt, backendId: 'kling');

        // readonly properties — PHP prevents mutation at language level
        $this->assertSame($prompt, $input->prompt);
        $this->assertSame('kling', $input->backendId);
    }

    public function test_accepts_different_backend_ids(): void
    {
        $prompt = $this->makePromptIR();

        $kling = new BackendInput(prompt: $prompt, backendId: 'kling');
        $veo   = new BackendInput(prompt: $prompt, backendId: 'veo');

        $this->assertSame('kling', $kling->backendId);
        $this->assertSame('veo', $veo->backendId);
        $this->assertSame($prompt, $kling->prompt);
        $this->assertSame($prompt, $veo->prompt);
    }

    public function test_different_prompts_produce_different_inputs(): void
    {
        $p1 = $this->makePromptIR('shot-a');
        $p2 = $this->makePromptIR('shot-b');

        $in1 = new BackendInput(prompt: $p1, backendId: 'kling');
        $in2 = new BackendInput(prompt: $p2, backendId: 'kling');

        $this->assertNotSame($in1->prompt, $in2->prompt);
        $this->assertSame($in1->backendId, $in2->backendId);
    }
}
