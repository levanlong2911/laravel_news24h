<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Observability;

/**
 * Lightweight profiler for FilmOS pipeline phases.
 * Records timing per phase and free-form counters.
 * In Phase 1: in-memory only. Phase 2: flush to telemetry store.
 */
final class FilmOSProfiler
{
    /** @var array<string, float> phase start timestamps */
    private array $starts = [];

    /** @var array<string, float> phase elapsed ms */
    private array $timings = [];

    /** @var array<string, int> arbitrary counters */
    private array $counters = [];

    public function startPhase(string $phase): void
    {
        $this->starts[$phase] = hrtime(true) / 1e6; // ns → ms
    }

    public function endPhase(string $phase): void
    {
        if (!isset($this->starts[$phase])) {
            return;
        }
        $this->timings[$phase] = (hrtime(true) / 1e6) - $this->starts[$phase];
        unset($this->starts[$phase]);
    }

    public function incrementCounter(string $key, int $by = 1): void
    {
        $this->counters[$key] = ($this->counters[$key] ?? 0) + $by;
    }

    /** @return array<string, float> phase name → ms */
    public function timings(): array
    {
        return $this->timings;
    }

    /** @return array<string, int> */
    public function counters(): array
    {
        return $this->counters;
    }

    public function phaseMs(string $phase): float
    {
        return $this->timings[$phase] ?? 0.0;
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $total = array_sum($this->timings);
        return [
            'phases'   => $this->timings,
            'counters' => $this->counters,
            'totalMs'  => $total,
        ];
    }

    public function formatReport(): string
    {
        $lines = [];
        foreach ($this->timings as $phase => $ms) {
            $lines[] = sprintf('  %-30s %6.1f ms', $phase, $ms);
        }
        foreach ($this->counters as $key => $count) {
            $lines[] = sprintf('  %-30s %6d', $key, $count);
        }
        $lines[] = sprintf('  %-30s %6.1f ms', 'TOTAL', array_sum($this->timings));
        return implode("\n", $lines);
    }
}
