<?php

namespace App\Services\AI\AFOS\Backends;

/**
 * BackendRegistry — maps backend IDs to BackendInterface implementations.
 *
 * LLVM analogue: TargetRegistry.
 * Immutable value object — register() returns a new registry with the backend added.
 *
 * Usage:
 *   $registry = BackendRegistry::withDefaults();              // Kling only
 *   $registry = BackendRegistry::withDefaults()
 *                   ->register(new VeoBackend())              // Kling + Veo
 *                   ->register(new SoraBackend());            // Kling + Veo + Sora
 *
 * BackendEmitter takes a BackendRegistry; BackendStage takes a BackendEmitter.
 * BackendStage knows nothing about which backends exist.
 */
final class BackendRegistry
{
    /** @param array<string, BackendInterface> $backends */
    private function __construct(private array $backends = []) {}

    /**
     * Return a new registry with $backend added (or replaced if same id).
     * Immutable — does not mutate the original registry.
     */
    public function register(BackendInterface $backend): self
    {
        $clone = clone $this;
        $clone->backends[$backend->id()] = $backend;
        return $clone;
    }

    /**
     * Retrieve a backend by ID.
     *
     * @throws \InvalidArgumentException if the backend is not registered.
     */
    public function backend(string $id): BackendInterface
    {
        return $this->backends[$id] ?? throw new \InvalidArgumentException(
            sprintf(
                "Unknown backend '%s'. Registered: [%s].",
                $id,
                $this->backends === [] ? 'none' : implode(', ', array_keys($this->backends)),
            )
        );
    }

    public function has(string $id): bool
    {
        return isset($this->backends[$id]);
    }

    /** @return string[] All registered backend IDs. */
    public function registeredIds(): array
    {
        return array_keys($this->backends);
    }

    /** Default registry: KlingBackend registered. */
    public static function withDefaults(): self
    {
        return (new self)->register(new KlingBackend());
    }
}
