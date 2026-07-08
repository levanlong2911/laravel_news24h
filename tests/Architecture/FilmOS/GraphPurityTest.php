<?php

declare(strict_types=1);

namespace Tests\Architecture\FilmOS;

use App\Services\AI\FilmOS\DecisionDAG\DecisionDAG;
use App\Services\AI\FilmOS\Graph\Graph;
use App\Services\AI\FilmOS\Graph\GraphAlgorithms;
use App\Services\AI\FilmOS\Graph\GraphAlgorithmsService;
use App\Services\AI\FilmOS\Graph\GraphEngine;
use App\Services\AI\FilmOS\Graph\GraphQuery;
use App\Services\AI\FilmOS\Graph\GraphSerializer;
use App\Services\AI\FilmOS\Graph\GraphTraversal;
use App\Services\AI\FilmOS\Graph\GraphValidation;
use App\Services\AI\FilmOS\Meaning\MeaningGraph;
use App\Services\AI\FilmOS\Planning\GoalGraph;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tier 1: Architecture Invariants.
 *
 * These tests enforce structural constraints that NO PR should break.
 * They run in CI before any feature tests.
 *
 * Core invariant: Graph is PURE STORAGE. Algorithms live outside it.
 */
final class GraphPurityTest extends TestCase
{
    private const FORBIDDEN_ON_GRAPH = [
        'topoSort', 'detectCycle', 'connectedComponents',
        'bfs', 'dfs', 'traceBack',
        'hasOrphans', 'hasCycles', 'validate', 'findOrphanIds',
        'find', 'filter', 'ancestors', 'descendants',
        'sources', 'sinks', 'neighbors', 'reachableSet',
        'commonAncestor', 'subgraph', 'findByProperty',
        'toJson', 'toArray', 'snapshot', 'toAdjacencyList',
    ];

    /** @test */
    public function graph_base_class_has_no_algorithm_methods(): void
    {
        $ref = new ReflectionClass(Graph::class);

        foreach (self::FORBIDDEN_ON_GRAPH as $method) {
            $this->assertFalse(
                $ref->hasMethod($method),
                "Graph base class must not have {$method}() — algorithms live in Graph Platform utilities."
            );
        }
    }

    /** @test */
    public function domain_graphs_have_no_algorithm_methods(): void
    {
        $domainGraphs = [
            MeaningGraph::class,
            GoalGraph::class,
            DecisionDAG::class,
        ];

        $checked = 0;

        foreach ($domainGraphs as $class) {
            $ref = new ReflectionClass($class);
            foreach (self::FORBIDDEN_ON_GRAPH as $method) {
                if ($ref->hasMethod($method)) {
                    $declaring = $ref->getMethod($method)->getDeclaringClass()->getName();
                    $this->assertNotEquals(
                        $class,
                        $declaring,
                        "{$class} must not declare {$method}() — delegate to Graph Platform."
                    );
                }
                $checked++;
            }
        }

        // Explicit count: 3 domain graphs × N forbidden methods
        $this->assertSame(
            count($domainGraphs) * count(self::FORBIDDEN_ON_GRAPH),
            $checked,
            'All domain graphs × forbidden methods must be checked.'
        );
    }

    /** @test */
    public function graph_algorithm_utility_classes_have_only_static_public_methods(): void
    {
        $utilities = [
            GraphAlgorithms::class,
            GraphTraversal::class,
            GraphValidation::class,
            GraphQuery::class,
            GraphSerializer::class,
        ];

        foreach ($utilities as $class) {
            $ref = new ReflectionClass($class);
            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isConstructor() || $method->isDestructor()) {
                    continue;
                }
                $this->assertTrue(
                    $method->isStatic(),
                    "{$class}::{$method->getName()}() must be static — utility classes are stateless."
                );
            }
        }
    }

    /** @test */
    public function graph_engine_is_a_service_locator_not_a_delegator(): void
    {
        $ref = new ReflectionClass(GraphEngine::class);

        // Engine must have accessor methods
        $this->assertTrue($ref->hasMethod('algorithms'), 'GraphEngine must have algorithms()');
        $this->assertTrue($ref->hasMethod('query'),      'GraphEngine must have query()');
        $this->assertTrue($ref->hasMethod('serializer'), 'GraphEngine must have serializer()');
        $this->assertTrue($ref->hasMethod('validator'),  'GraphEngine must have validator()');
        $this->assertTrue($ref->hasMethod('traversal'),  'GraphEngine must have traversal()');

        // Engine must NOT delegate algorithm calls directly
        foreach (self::FORBIDDEN_ON_GRAPH as $method) {
            $this->assertFalse(
                $ref->hasMethod($method),
                "GraphEngine must not expose {$method}() directly — callers call engine->algorithms()->{$method}()"
            );
        }
    }

    /** @test */
    public function graph_engine_returns_fresh_service_instances_per_call(): void
    {
        $engine = GraphEngine::make();
        $a = $engine->algorithms();
        $b = $engine->algorithms();

        // Lazy init: same instance returned on repeat calls (not a new object each time)
        $this->assertSame($a, $b, 'GraphEngine::algorithms() should return the same instance (lazy init).');
    }

    /** @test */
    public function graph_engine_with_methods_return_new_instance(): void
    {
        $engine    = GraphEngine::make();
        $modified  = $engine->withAlgorithms($engine->algorithms());

        $this->assertNotSame($engine, $modified, 'withAlgorithms() must return a clone, not mutate in place.');
    }
}
