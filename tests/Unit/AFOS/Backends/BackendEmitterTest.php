<?php

namespace Tests\Unit\AFOS\Backends;

use App\Services\AI\AFOS\Backends\BackendEmitter;
use App\Services\AI\AFOS\Backends\BackendInterface;
use App\Services\AI\AFOS\Backends\BackendRegistry;
use App\Services\AI\AFOS\Backends\KlingBackend;
use App\Services\AI\AFOS\Ir\BackendInput;
use App\Services\AI\AFOS\Ir\PromptIR;
use PHPUnit\Framework\TestCase;

final class BackendEmitterTest extends TestCase
{
    private function makePromptIR(string $shotId = 'test'): PromptIR
    {
        return new PromptIR(
            shotId:            $shotId,
            subjectClause:     'A hull emerges',
            atmosphereClause:  'Golden hour',
            cameraClause:      'Slow push',
            compositionClause: 'Rule of thirds',
            emotionalClose:    'Quiet grandeur',
            technicalSpec:     '4K cinematic',
        );
    }

    private function fakeBackend(string $id, string $output = ''): BackendInterface
    {
        return new class($id, $output) implements BackendInterface {
            public function __construct(private string $bid, private string $out) {}
            public function id(): string { return $this->bid; }
            public function serialize(PromptIR $p): string { return $this->out ?: "output-from-{$this->bid}"; }
        };
    }

    // ── withDefaults ──────────────────────────────────────────────────────────

    public function test_with_defaults_emits_via_kling(): void
    {
        $emitter = BackendEmitter::withDefaults();
        $input   = new BackendInput($this->makePromptIR(), 'kling');

        $result = $emitter->emit($input);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    // ── emit routing ──────────────────────────────────────────────────────────

    public function test_emit_routes_to_correct_backend_by_id(): void
    {
        $registry = BackendRegistry::withDefaults()
            ->register($this->fakeBackend('veo',  'veo-output'))
            ->register($this->fakeBackend('sora', 'sora-output'));

        $emitter = new BackendEmitter($registry);

        $this->assertSame('veo-output',  $emitter->emit(new BackendInput($this->makePromptIR(), 'veo')));
        $this->assertSame('sora-output', $emitter->emit(new BackendInput($this->makePromptIR(), 'sora')));
    }

    public function test_emit_throws_for_unregistered_backend(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/unknown backend 'veo'/i");

        BackendEmitter::withDefaults()->emit(new BackendInput($this->makePromptIR(), 'veo'));
    }

    public function test_emit_passes_prompt_ir_to_backend(): void
    {
        $received = null;
        $spy = new class($received) implements BackendInterface {
            public function __construct(public ?PromptIR &$captured) {}
            public function id(): string { return 'spy'; }
            public function serialize(PromptIR $p): string { $this->captured = $p; return 'ok'; }
        };

        $registry = BackendRegistry::withDefaults()->register($spy);
        $emitter  = new BackendEmitter($registry);
        $prompt   = $this->makePromptIR('captured-shot');

        $emitter->emit(new BackendInput($prompt, 'spy'));

        $this->assertSame($prompt, $spy->captured);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_emit_is_deterministic_for_same_input(): void
    {
        $emitter = BackendEmitter::withDefaults();
        $input   = new BackendInput($this->makePromptIR(), 'kling');

        $this->assertSame($emitter->emit($input), $emitter->emit($input));
    }
}
