<?php

namespace Tests\Unit\AFOS\Backends;

use App\Services\AI\AFOS\Backends\BackendInterface;
use App\Services\AI\AFOS\Backends\BackendRegistry;
use App\Services\AI\AFOS\Backends\KlingBackend;
use App\Services\AI\AFOS\Ir\PromptIR;
use PHPUnit\Framework\TestCase;

final class BackendRegistryTest extends TestCase
{
    private function fakeBackend(string $id): BackendInterface
    {
        return new class($id) implements BackendInterface {
            public function __construct(private string $backendId) {}
            public function id(): string { return $this->backendId; }
            public function serialize(PromptIR $p): string { return "serialized-by-{$this->backendId}"; }
        };
    }

    // ── withDefaults ──────────────────────────────────────────────────────────

    public function test_with_defaults_registers_kling(): void
    {
        $registry = BackendRegistry::withDefaults();
        $this->assertTrue($registry->has('kling'));
        $this->assertInstanceOf(KlingBackend::class, $registry->backend('kling'));
    }

    public function test_with_defaults_has_kling_as_only_backend(): void
    {
        $registry = BackendRegistry::withDefaults();
        $this->assertSame(['kling'], $registry->registeredIds());
    }

    // ── register ─────────────────────────────────────────────────────────────

    public function test_register_adds_new_backend(): void
    {
        $registry = BackendRegistry::withDefaults()->register($this->fakeBackend('veo'));

        $this->assertTrue($registry->has('veo'));
        $this->assertTrue($registry->has('kling'));
    }

    public function test_register_is_immutable(): void
    {
        $original = BackendRegistry::withDefaults();
        $extended = $original->register($this->fakeBackend('veo'));

        $this->assertFalse($original->has('veo'), 'register() must not mutate original');
        $this->assertTrue($extended->has('veo'));
    }

    public function test_register_replaces_backend_with_same_id(): void
    {
        $v1 = $this->fakeBackend('kling');
        $v2 = $this->fakeBackend('kling');

        $registry = BackendRegistry::withDefaults()->register($v2);
        $this->assertSame($v2, $registry->backend('kling'));
    }

    public function test_register_multiple_backends(): void
    {
        $registry = (new \ReflectionClass(BackendRegistry::class))->newInstanceWithoutConstructor();
        $registry = BackendRegistry::withDefaults()
            ->register($this->fakeBackend('veo'))
            ->register($this->fakeBackend('sora'))
            ->register($this->fakeBackend('runway'));

        $ids = $registry->registeredIds();
        sort($ids);
        $this->assertSame(['kling', 'runway', 'sora', 'veo'], $ids);
    }

    // ── backend ───────────────────────────────────────────────────────────────

    public function test_backend_returns_correct_implementation(): void
    {
        $veo = $this->fakeBackend('veo');
        $registry = BackendRegistry::withDefaults()->register($veo);

        $this->assertSame($veo, $registry->backend('veo'));
    }

    public function test_backend_throws_for_unknown_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/unknown backend 'sora'/i");

        BackendRegistry::withDefaults()->backend('sora');
    }

    public function test_backend_error_message_lists_registered_ids(): void
    {
        $registry = BackendRegistry::withDefaults()->register($this->fakeBackend('veo'));

        try {
            $registry->backend('sora');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('kling', $e->getMessage());
            $this->assertStringContainsString('veo',   $e->getMessage());
        }
    }
}
