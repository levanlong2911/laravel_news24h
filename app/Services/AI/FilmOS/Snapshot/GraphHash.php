<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

use App\Services\AI\FilmOS\Graph\Graph;
use App\Services\AI\FilmOS\Intent\DirectorIntent;
use App\Services\AI\FilmOS\Kernel\FilmTask;

/**
 * Deterministic sha256 hashing for FilmOS graph structures and intent/task layers.
 *
 * All methods produce canonical hashes: identical inputs → identical output across runs.
 *
 * GraphHashable contract (nodes / edges):
 *   Use canonicalData() when available — structural fields (id, type) only.
 *   Fall back to {id, label} for graph types not yet implementing GraphHashable.
 *
 * PromptHash contract (ofIntents):
 *   Hash ExecutionContext structural fields (mustShow, mustAvoid, visualStrategy,
 *   styleRule, beat) — NOT the rendered prompt string. Stable across template changes.
 *
 * SchedulerHash contract (ofTasks):
 *   Hash task topology (id, type, priority, dependsOn, deadlineMs) — NOT payload
 *   (DirectorIntent) or runtime state (startedAt, attempts, taskId from provider).
 */
final class GraphHash
{
    /**
     * Canonical topology hash of a Graph.
     *
     * Nodes sorted by id (ksort). Edges sorted lexicographically after JSON-encoding
     * their canonical data. Uses GraphHashable::canonicalData() when available;
     * falls back to {id, label} for non-implementing types.
     */
    public static function of(Graph $graph): string
    {
        $nodes = [];
        foreach ($graph->nodes() as $node) {
            if (!$node instanceof GraphHashable) {
                throw new \LogicException(
                    sprintf('Node %s (%s) must implement GraphHashable to participate in a canonical hash.',
                        $node->id, get_class($node))
                );
            }
            $nodes[$node->id] = $node->canonicalData();
        }
        ksort($nodes);

        $edges = [];
        foreach ($graph->edges() as $edge) {
            $edges[] = json_encode(
                $edge instanceof GraphHashable
                    ? $edge->canonicalData()
                    : ['from' => $edge->fromId, 'to' => $edge->toId],
                JSON_THROW_ON_ERROR,
            );
        }
        sort($edges);

        return hash('sha256', json_encode(
            ['nodes' => $nodes, 'edges' => $edges],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Canonical hash of the intent layer: structural ExecutionContext fields per shot.
     *
     * Hashes WHAT the director decided (mustShow, mustAvoid, visualStrategy, styleRule, beat),
     * not HOW the prompt was rendered. This makes the hash stable across prompt template edits
     * while still catching changes in directorial intent.
     *
     * @param  array<string, DirectorIntent> $intents  subGoalId → DirectorIntent
     */
    public static function ofIntents(array $intents): string
    {
        ksort($intents);
        $canonical = [];
        foreach ($intents as $id => $intent) {
            $mustShow  = $intent->execution->mustShow;
            $mustAvoid = $intent->execution->mustAvoid;
            $styleRule = $intent->execution->styleRule;
            sort($mustShow);
            sort($mustAvoid);
            ksort($styleRule);

            $canonical[$id] = [
                'shotId'         => $intent->shotId,
                'beat'           => $intent->execution->beat->value,
                'mustShow'       => $mustShow,
                'mustAvoid'      => $mustAvoid,
                'visualStrategy' => $intent->execution->visualStrategy->value,
                'styleRule'      => $styleRule,
            ];
        }
        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    /**
     * Canonical hash of the scheduler task topology.
     *
     * Hashes (id, type, priority, dependsOn sorted, deadlineMs) — NOT payload
     * (DirectorIntent objects) or provider-assigned task IDs. dependsOn is sorted
     * so insertion order does not affect the hash.
     *
     * @param  FilmTask[] $tasks  in submission order
     */
    public static function ofTasks(array $tasks): string
    {
        usort($tasks, fn(FilmTask $a, FilmTask $b) => strcmp($a->id, $b->id));

        $canonical = [];
        foreach ($tasks as $task) {
            $deps = $task->dependsOn;
            sort($deps);
            $canonical[] = [
                'id'         => $task->id,
                'type'       => $task->type->value,
                'priority'   => $task->priority->value,
                'dependsOn'  => $deps,
                'deadlineMs' => $task->deadlineMs,
            ];
        }
        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
}
