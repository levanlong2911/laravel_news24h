<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Provider;

/**
 * Records which provider was selected for each task during a production run.
 *
 * Mutable during execution; read-only after the run completes.
 * ProviderLayerBuilder reads the accumulated routes to build providerRouteHash and capabilityHash.
 *
 * This is intentionally thin — it is a record, not an execution engine.
 * Provider calls themselves belong to the Python runtime; Laravel only records
 * which route a task was planned onto.
 */
final class ProviderRuntime
{
    /** @var array<string, ProviderRoute>  taskId → route taken */
    private array $routes = [];

    public function record(ProviderRoute $route): void
    {
        $this->routes[$route->taskId] = $route;
    }

    public function get(string $taskId): ?ProviderRoute
    {
        return $this->routes[$taskId] ?? null;
    }

    /**
     * All routes recorded in this run.
     * @return array<string, ProviderRoute>  taskId → ProviderRoute
     */
    public function all(): array
    {
        return $this->routes;
    }

    public function count(): int
    {
        return count($this->routes);
    }

    /** True if no routes have been recorded yet. */
    public function isEmpty(): bool
    {
        return empty($this->routes);
    }
}
