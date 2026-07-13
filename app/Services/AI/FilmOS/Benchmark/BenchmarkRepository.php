<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark;

use Illuminate\Support\Facades\DB;

/**
 * Thin append-only repository for BenchmarkResult persistence.
 *
 * No UPDATE operations — each render run produces a new row.
 * Query patterns:
 *   - by traceId         (replay / debug)
 *   - by provider        (full history — use sparingly on large datasets)
 *   - recentByProvider   (bounded window for learning — prefer this in C.6+)
 *   - by goalId          (domain-specific learning)
 */
final class BenchmarkRepository
{
    private const TABLE = 'filmos_benchmark_results';

    public function save(BenchmarkResult $result): void
    {
        DB::table(self::TABLE)->insert([
            'schema_version'  => 1,
            'trace_id'        => $result->traceId,
            'provider'        => $result->provider,
            'planner_name'    => $result->plannerName,
            'goal_id'         => $result->goalId,
            'request_id'      => (string) ($result->attributes['requestId'] ?? ''),
            'asset_url'       => (string) ($result->attributes['assetUrl'] ?? ''),
            'duration'        => (float)  ($result->attributes['duration']  ?? 0.0),
            'cost'            => $result->cost,
            'latency_seconds' => $result->latencySeconds,
            'quality_score'   => $result->qualityScore,
            'roi'             => $result->roi,
            'score'           => $result->score,
            'attributes'      => $this->encodeAttributes($result->attributes),
        ]);
    }

    /** Returns the most recent record for this traceId, or null if none. */
    public function findByTraceId(string $traceId): ?BenchmarkResult
    {
        $row = DB::table(self::TABLE)
            ->where('trace_id', $traceId)
            ->orderByDesc('id')
            ->first();

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Returns all records for a provider, newest first.
     * Loads entire history — only use for debugging; prefer findRecentByProvider for learning.
     *
     * @return iterable<BenchmarkResult>
     */
    public function findByProvider(string $provider): iterable
    {
        return DB::table(self::TABLE)
            ->where('provider', $provider)
            ->orderByDesc('id')
            ->get()
            ->map(fn (object $row) => $this->hydrate($row));
    }

    /**
     * Returns the N most recent records for a provider, newest first.
     * Use this in learning pipelines to avoid loading unbounded history.
     *
     * @return iterable<BenchmarkResult>
     */
    public function findRecentByProvider(string $provider, int $limit = 100): iterable
    {
        return DB::table(self::TABLE)
            ->where('provider', $provider)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (object $row) => $this->hydrate($row));
    }

    /**
     * Returns all records for a goalId, newest first.
     *
     * @return iterable<BenchmarkResult>
     */
    public function findByGoalId(string $goalId): iterable
    {
        return DB::table(self::TABLE)
            ->where('goal_id', $goalId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (object $row) => $this->hydrate($row));
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function hydrate(object $row): BenchmarkResult
    {
        $attributes = json_decode($row->attributes ?? 'null', associative: true) ?? [];

        return new BenchmarkResult(
            traceId:        $row->trace_id,
            provider:       $row->provider,
            plannerName:    $row->planner_name,
            goalId:         $row->goal_id,
            score:          (float) $row->score,
            roi:            (float) $row->roi,
            cost:           (float) $row->cost,
            latencySeconds: (float) $row->latency_seconds,
            qualityScore:   (float) $row->quality_score,
            attributes:     $attributes,
        );
    }

    private function encodeAttributes(array $attributes): ?string
    {
        if (empty($attributes)) {
            return null;
        }
        return json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
