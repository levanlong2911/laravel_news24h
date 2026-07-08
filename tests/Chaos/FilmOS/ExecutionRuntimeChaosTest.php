<?php

declare(strict_types=1);

namespace Tests\Chaos\FilmOS;

use App\Services\AI\FilmOS\ExecutionGraph\CheckpointStore;
use App\Services\AI\FilmOS\ExecutionGraph\ExecutionEdge;
use App\Services\AI\FilmOS\ExecutionGraph\ExecutionGraph;
use App\Services\AI\FilmOS\ExecutionGraph\ExecutionNode;
use App\Services\AI\FilmOS\ExecutionGraph\ExecutionNodeStatus;
use App\Services\AI\FilmOS\ExecutionGraph\ExecutionRelation;
use App\Services\AI\FilmOS\ExecutionGraph\ExecutionRuntime;
use App\Services\AI\FilmOS\ExecutionGraph\InMemoryCheckpointStore;
use PHPUnit\Framework\TestCase;

/**
 * Chaos Tests — kiến trúc ExecutionGraph dưới điều kiện thất bại.
 *
 * Không test happy path.
 * Test: hệ thống có tiếp tục đúng không khi node chết giữa chừng?
 *
 * Scenario thực tế:
 *   fact → meaning → plan → render_a
 *                        → render_b  ← dies (GPU OOM)
 *                        → render_c
 *                        → render_d
 *
 * Kỳ vọng:
 *   1. render_a, render_c, render_d hoàn thành bình thường
 *   2. render_b ghi lại error, status = FAILED
 *   3. Checkpoint lưu trạng thái đúng sau mỗi node
 *   4. Resume → chỉ render_b được chạy lại (3 node còn lại từ checkpoint)
 *   5. Sau resume, tất cả 5 node COMPLETED
 */
final class ExecutionRuntimeChaosTest extends TestCase
{
    private function buildLinearPipeline(): ExecutionGraph
    {
        // fact_1 → meaning_1 → plan_1 → render_a
        //                             → render_b
        //                             → render_c
        //                             → render_d
        $graph = new ExecutionGraph('exec_chaos_01', 'prod_chaos_01');

        $nodes = [
            new ExecutionNode('fact_1',    'task_fact',    'Source facts'),
            new ExecutionNode('meaning_1', 'task_meaning', 'Resolve meaning'),
            new ExecutionNode('plan_1',    'task_plan',    'Build shot plan'),
            new ExecutionNode('render_a',  'task_render_a', 'Hotel exterior'),
            new ExecutionNode('render_b',  'task_render_b', 'Cockroach closeup'),
            new ExecutionNode('render_c',  'task_render_c', 'Health notice'),
            new ExecutionNode('render_d',  'task_render_d', 'Travel advisory'),
        ];

        foreach ($nodes as $node) {
            $graph->addNode($node);
        }

        $graph->addEdge(new ExecutionEdge('fact_1',    'meaning_1'));
        $graph->addEdge(new ExecutionEdge('meaning_1', 'plan_1'));
        $graph->addEdge(new ExecutionEdge('plan_1',    'render_a'));
        $graph->addEdge(new ExecutionEdge('plan_1',    'render_b'));
        $graph->addEdge(new ExecutionEdge('plan_1',    'render_c'));
        $graph->addEdge(new ExecutionEdge('plan_1',    'render_d'));

        return $graph;
    }

    // ── Scenario 1: Single node failure ──────────────────────────────────────

    /** @test */
    public function render_b_fails_but_other_renders_complete(): void
    {
        $store   = new InMemoryCheckpointStore();
        $runtime = new ExecutionRuntime($store);
        $graph   = $this->buildLinearPipeline();

        $callLog = [];

        $handlers = [
            'task_fact'     => function () use (&$callLog) { $callLog[] = 'fact';    return 'facts'; },
            'task_meaning'  => function () use (&$callLog) { $callLog[] = 'meaning'; return 'meaning'; },
            'task_plan'     => function () use (&$callLog) { $callLog[] = 'plan';    return 'plan'; },
            'task_render_a' => function () use (&$callLog) { $callLog[] = 'render_a'; return 'url_a'; },
            'task_render_b' => function () use (&$callLog) {
                $callLog[] = 'render_b';
                throw new \RuntimeException('GPU out of memory');
            },
            'task_render_c' => function () use (&$callLog) { $callLog[] = 'render_c'; return 'url_c'; },
            'task_render_d' => function () use (&$callLog) { $callLog[] = 'render_d'; return 'url_d'; },
        ];

        $result = $runtime->run('exec_chaos_01', $graph, $handlers);

        // render_b FAILED
        $this->assertSame(ExecutionNodeStatus::FAILED, $result->graph->node('render_b')->status);
        $this->assertStringContainsString('GPU out of memory', $result->graph->node('render_b')->error);

        // render_a, render_c, render_d COMPLETED (không bị ảnh hưởng)
        $this->assertSame(ExecutionNodeStatus::COMPLETED, $result->graph->node('render_a')->status);
        $this->assertSame(ExecutionNodeStatus::COMPLETED, $result->graph->node('render_c')->status);
        $this->assertSame(ExecutionNodeStatus::COMPLETED, $result->graph->node('render_d')->status);

        // fact, meaning, plan cũng hoàn thành
        $this->assertSame(ExecutionNodeStatus::COMPLETED, $result->graph->node('fact_1')->status);
        $this->assertSame(ExecutionNodeStatus::COMPLETED, $result->graph->node('meaning_1')->status);
        $this->assertSame(ExecutionNodeStatus::COMPLETED, $result->graph->node('plan_1')->status);

        // Tất cả 7 task đều được gọi
        $this->assertContains('render_a', $callLog);
        $this->assertContains('render_b', $callLog);
        $this->assertContains('render_c', $callLog);
        $this->assertContains('render_d', $callLog);

        // Result có failures
        $this->assertTrue($result->hasFailures());
        $this->assertCount(1, $result->failedNodes());
        $this->assertSame('render_b', $result->failedNodes()[0]->id);
    }

    // ── Scenario 2: Checkpoint captured correct state ─────────────────────────

    /** @test */
    public function checkpoint_reflects_exact_state_after_failure(): void
    {
        $store   = new InMemoryCheckpointStore();
        $runtime = new ExecutionRuntime($store);

        $handlers = [
            'task_fact'     => fn() => 'facts',
            'task_meaning'  => fn() => 'meaning',
            'task_plan'     => fn() => 'plan',
            'task_render_a' => fn() => 'url_a',
            'task_render_b' => fn() => throw new \RuntimeException('GPU OOM'),
            'task_render_c' => fn() => 'url_c',
            'task_render_d' => fn() => 'url_d',
        ];

        $runtime->run('exec_ckpt_01', $this->buildLinearPipeline(), $handlers);

        // Checkpoint phải tồn tại (chưa bị clear vì có failure)
        $checkpoint = $store->load('exec_ckpt_01');
        $this->assertNotNull($checkpoint, 'Checkpoint phải tồn tại sau failure');

        // Checkpoint phản ánh đúng trạng thái
        $this->assertSame(ExecutionNodeStatus::COMPLETED, $checkpoint->node('fact_1')->status);
        $this->assertSame(ExecutionNodeStatus::COMPLETED, $checkpoint->node('meaning_1')->status);
        $this->assertSame(ExecutionNodeStatus::COMPLETED, $checkpoint->node('plan_1')->status);
        $this->assertSame(ExecutionNodeStatus::COMPLETED, $checkpoint->node('render_a')->status);
        $this->assertSame(ExecutionNodeStatus::FAILED,    $checkpoint->node('render_b')->status);
        $this->assertSame(ExecutionNodeStatus::COMPLETED, $checkpoint->node('render_c')->status);
        $this->assertSame(ExecutionNodeStatus::COMPLETED, $checkpoint->node('render_d')->status);
    }

    // ── Scenario 3: Resume chỉ re-run node bị fail ───────────────────────────

    /** @test */
    public function resume_only_reruns_failed_node_not_completed_ones(): void
    {
        $store   = new InMemoryCheckpointStore();
        $runtime = new ExecutionRuntime($store);

        $callCount = array_fill_keys(
            ['task_fact', 'task_meaning', 'task_plan', 'task_render_a', 'task_render_b', 'task_render_c', 'task_render_d'],
            0
        );

        $failingHandlers = [
            'task_fact'     => function () use (&$callCount) { $callCount['task_fact']++;     return 'facts'; },
            'task_meaning'  => function () use (&$callCount) { $callCount['task_meaning']++;  return 'meaning'; },
            'task_plan'     => function () use (&$callCount) { $callCount['task_plan']++;     return 'plan'; },
            'task_render_a' => function () use (&$callCount) { $callCount['task_render_a']++; return 'url_a'; },
            'task_render_b' => function () use (&$callCount) {
                $callCount['task_render_b']++;
                throw new \RuntimeException('GPU OOM');
            },
            'task_render_c' => function () use (&$callCount) { $callCount['task_render_c']++; return 'url_c'; },
            'task_render_d' => function () use (&$callCount) { $callCount['task_render_d']++; return 'url_d'; },
        ];

        // Run 1: render_b fails
        $runtime->run('exec_resume_01', $this->buildLinearPipeline(), $failingHandlers);

        // Reset count để đếm lần 2 riêng
        $callCount = array_fill_keys(array_keys($callCount), 0);

        // Run 2: fixed render_b handler, resume từ checkpoint
        $fixedHandlers = array_merge($failingHandlers, [
            'task_render_b' => function () use (&$callCount) {
                $callCount['task_render_b']++;
                return 'url_b_fixed';
            },
        ]);

        $result = $runtime->run('exec_resume_01', $this->buildLinearPipeline(), $fixedHandlers);

        // Chỉ render_b được chạy lại
        $this->assertSame(1, $callCount['task_render_b'], 'render_b phải được chạy lại đúng 1 lần');

        // Không node nào khác được chạy lại (đã COMPLETED trong checkpoint)
        $this->assertSame(0, $callCount['task_fact'],     'fact đã COMPLETED, không được chạy lại');
        $this->assertSame(0, $callCount['task_meaning'],  'meaning đã COMPLETED, không được chạy lại');
        $this->assertSame(0, $callCount['task_plan'],     'plan đã COMPLETED, không được chạy lại');
        $this->assertSame(0, $callCount['task_render_a'], 'render_a đã COMPLETED, không được chạy lại');
        $this->assertSame(0, $callCount['task_render_c'], 'render_c đã COMPLETED, không được chạy lại');
        $this->assertSame(0, $callCount['task_render_d'], 'render_d đã COMPLETED, không được chạy lại');

        // Sau resume: tất cả COMPLETED, không failure
        $this->assertFalse($result->hasFailures());
        $this->assertTrue($result->isFullyCompleted());
        $this->assertTrue($result->resumedFromCheckpoint);

        // render_b kết quả mới
        $this->assertSame('url_b_fixed', $result->graph->node('render_b')->result);

        // Checkpoint phải được clear vì hoàn thành thành công
        $this->assertNull($store->load('exec_resume_01'), 'Checkpoint phải được clear sau success');
    }

    // ── Scenario 4: Hard dependency failure cascades ──────────────────────────

    /** @test */
    public function hard_dependency_failure_causes_dependent_to_be_skipped(): void
    {
        // pipeline: source → middle (FAILS) → sink
        $graph = new ExecutionGraph('exec_cascade_01', 'prod_chaos_01');
        $graph->addNode(new ExecutionNode('source', 'task_source', 'Source'));
        $graph->addNode(new ExecutionNode('middle', 'task_middle', 'Middle'));
        $graph->addNode(new ExecutionNode('sink',   'task_sink',   'Sink'));
        $graph->addEdge(new ExecutionEdge('source', 'middle', ExecutionRelation::REQUIRES));
        $graph->addEdge(new ExecutionEdge('middle', 'sink',   ExecutionRelation::REQUIRES));

        $store   = new InMemoryCheckpointStore();
        $runtime = new ExecutionRuntime($store);

        $handlers = [
            'task_source' => fn() => 'ok',
            'task_middle' => fn() => throw new \RuntimeException('middle died'),
            'task_sink'   => fn() => 'should not run',
        ];

        $result = $runtime->run('exec_cascade_01', $graph, $handlers);

        $this->assertSame(ExecutionNodeStatus::COMPLETED, $result->graph->node('source')->status);
        $this->assertSame(ExecutionNodeStatus::FAILED,    $result->graph->node('middle')->status);
        $this->assertSame(ExecutionNodeStatus::SKIPPED,   $result->graph->node('sink')->status,
            'sink phải bị SKIPPED vì dependency middle FAILED');
    }

    // ── Scenario 5: Soft dependency không block ────────────────────────────────

    /** @test */
    public function soft_dependency_failure_does_not_skip_dependent(): void
    {
        // source → middle (FAILS, SOFT dep) → sink (vẫn chạy)
        $graph = new ExecutionGraph('exec_soft_01', 'prod_chaos_01');
        $graph->addNode(new ExecutionNode('source', 'task_source', 'Source'));
        $graph->addNode(new ExecutionNode('middle', 'task_middle', 'Middle (SOFT parent)'));
        $graph->addNode(new ExecutionNode('sink',   'task_sink',   'Sink (still runs)'));
        $graph->addEdge(new ExecutionEdge('source', 'middle', ExecutionRelation::REQUIRES));
        $graph->addEdge(new ExecutionEdge('middle', 'sink',   ExecutionRelation::SOFT)); // ← soft

        $store   = new InMemoryCheckpointStore();
        $runtime = new ExecutionRuntime($store);

        $handlers = [
            'task_source' => fn() => 'ok',
            'task_middle' => fn() => throw new \RuntimeException('middle died'),
            'task_sink'   => fn() => 'sink ran anyway',
        ];

        $result = $runtime->run('exec_soft_01', $graph, $handlers);

        $this->assertSame(ExecutionNodeStatus::FAILED,    $result->graph->node('middle')->status);
        $this->assertSame(ExecutionNodeStatus::COMPLETED, $result->graph->node('sink')->status,
            'SOFT dependency: sink phải COMPLETED dù middle FAILED');
        $this->assertSame('sink ran anyway', $result->graph->node('sink')->result);
    }

    // ── Scenario 6: Stress — nhiều failures đồng thời ────────────────────────

    /** @test */
    public function multiple_concurrent_failures_all_captured_in_checkpoint(): void
    {
        // root → A, B, C, D, E, F (6 parallel renders, 3 fail)
        $graph = new ExecutionGraph('exec_multi_01', 'prod_chaos_01');
        $graph->addNode(new ExecutionNode('root', 'task_root', 'Root'));
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $id) {
            $graph->addNode(new ExecutionNode($id, "task_{$id}", "Render {$id}"));
            $graph->addEdge(new ExecutionEdge('root', $id));
        }

        $store   = new InMemoryCheckpointStore();
        $runtime = new ExecutionRuntime($store);

        $handlers = [
            'task_root' => fn() => 'root',
            'task_a'    => fn() => 'url_a',
            'task_b'    => fn() => throw new \RuntimeException('b failed'),
            'task_c'    => fn() => 'url_c',
            'task_d'    => fn() => throw new \RuntimeException('d failed'),
            'task_e'    => fn() => 'url_e',
            'task_f'    => fn() => throw new \RuntimeException('f failed'),
        ];

        $result = $runtime->run('exec_multi_01', $graph, $handlers);

        $this->assertCount(3, $result->failedNodes(), '3 nodes phải FAILED');
        $this->assertSame(ExecutionNodeStatus::COMPLETED, $result->graph->node('a')->status);
        $this->assertSame(ExecutionNodeStatus::FAILED,    $result->graph->node('b')->status);
        $this->assertSame(ExecutionNodeStatus::COMPLETED, $result->graph->node('c')->status);
        $this->assertSame(ExecutionNodeStatus::FAILED,    $result->graph->node('d')->status);
        $this->assertSame(ExecutionNodeStatus::COMPLETED, $result->graph->node('e')->status);
        $this->assertSame(ExecutionNodeStatus::FAILED,    $result->graph->node('f')->status);

        // Resume: fix tất cả 3 failures
        $fixedHandlers = array_merge($handlers, [
            'task_b' => fn() => 'url_b_fixed',
            'task_d' => fn() => 'url_d_fixed',
            'task_f' => fn() => 'url_f_fixed',
        ]);

        $resumed = $runtime->run('exec_multi_01', $this->buildClonedGraph($graph), $fixedHandlers);

        $this->assertTrue($resumed->isFullyCompleted());
        $this->assertFalse($resumed->hasFailures());
        $this->assertCount(3, $resumed->executedNodeIds, 'Chỉ 3 failed node được re-run');
    }

    // ── Scenario 7: Execution timing is recorded ──────────────────────────────

    /** @test */
    public function execution_records_timing_for_all_completed_nodes(): void
    {
        $graph = new ExecutionGraph('exec_timing_01', 'prod_chaos_01');
        $graph->addNode(new ExecutionNode('n1', 'task_n1', 'Node 1'));
        $graph->addNode(new ExecutionNode('n2', 'task_n2', 'Node 2'));
        $graph->addEdge(new ExecutionEdge('n1', 'n2'));

        $store   = new InMemoryCheckpointStore();
        $runtime = new ExecutionRuntime($store);

        $handlers = [
            'task_n1' => fn() => 'r1',
            'task_n2' => fn() => 'r2',
        ];

        $result = $runtime->run('exec_timing_01', $graph, $handlers);

        foreach (['n1', 'n2'] as $nodeId) {
            $node = $result->graph->node($nodeId);
            $this->assertNotNull($node->startedAt,   "{$nodeId} phải có startedAt");
            $this->assertNotNull($node->completedAt, "{$nodeId} phải có completedAt");
            $this->assertGreaterThanOrEqual(0.0, $node->elapsedMs(), "{$nodeId} elapsedMs >= 0");
        }
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /**
     * Tạo ExecutionGraph mới từ cùng structure nhưng KHÔNG copy state.
     * Dùng cho resume test — runtime sẽ load state từ checkpoint.
     */
    private function buildClonedGraph(ExecutionGraph $source): ExecutionGraph
    {
        $fresh = new ExecutionGraph($source->executionId, $source->productionId);
        foreach ($source->nodes() as $node) {
            $fresh->addNode(new ExecutionNode($node->id, $node->taskId, $node->description));
        }
        foreach ($source->edges() as $edge) {
            $fresh->addEdge(new ExecutionEdge($edge->fromId, $edge->toId, $edge->relation));
        }
        return $fresh;
    }
}
