<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\EventBus;

/**
 * Synchronous, in-process event bus for FilmOS modules.
 *
 * Design:
 *   - Modules emit events via dispatch().
 *   - Other modules subscribe via subscribe() or subscribeAll().
 *   - No module knows about any other module — only about event types.
 *
 * This is the v1 sync bus. A future distributed bus (Redis Streams,
 * Laravel Queue) can implement the same contract and swap in transparently.
 *
 * History mode (constructor param) stores all dispatched events for
 * test introspection without affecting production memory usage.
 */
final class EventBus
{
    /** @var array<string, callable[]> eventName → handlers */
    private array $subscribers = [];

    /** @var FilmOSEvent[] */
    private array $history = [];

    public function __construct(
        private readonly bool $recordHistory = false,
    ) {}

    // ── Subscription ─────────────────────────────────────────────────────────

    /**
     * Subscribe to a specific event by name.
     * $handler receives the FilmOSEvent instance.
     */
    public function subscribe(string $eventName, callable $handler): void
    {
        $this->subscribers[$eventName][] = $handler;
    }

    /**
     * Subscribe to ALL events.
     * Used by observability, logging, and audit modules.
     */
    public function subscribeAll(callable $handler): void
    {
        $this->subscribers['*'][] = $handler;
    }

    // ── Dispatch ──────────────────────────────────────────────────────────────

    public function dispatch(FilmOSEvent $event): void
    {
        if ($this->recordHistory) {
            $this->history[] = $event;
        }

        foreach ($this->subscribers[$event->eventName()] ?? [] as $handler) {
            $handler($event);
        }

        foreach ($this->subscribers['*'] ?? [] as $handler) {
            $handler($event);
        }
    }

    // ── History (test/debug mode) ─────────────────────────────────────────────

    /** @return FilmOSEvent[] all events dispatched since last clearHistory() */
    public function history(): array
    {
        return $this->history;
    }

    /** @return FilmOSEvent[] events matching a specific name */
    public function historyOf(string $eventName): array
    {
        return array_values(
            array_filter($this->history, fn(FilmOSEvent $e) => $e->eventName() === $eventName)
        );
    }

    public function clearHistory(): void
    {
        $this->history = [];
    }

    /** Number of times a specific event was dispatched. */
    public function countOf(string $eventName): int
    {
        return count($this->historyOf($eventName));
    }
}
