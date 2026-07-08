# ADR-014: FilmOS Architecture v3.0 — Graph-First Architecture

**Status:** Amended by ADR-015  
**Date:** 2026-07-08  
**Revision:** 2 (Graph-First elevated to Principle 1; FilmKernel task-agnostic via plugin model; Layer 3 renamed Decision → Planning with goal decomposition)  
**Extended by:** ADR-015 adds Principle 4 (Layer ≠ Execution Order), GoalGraph, CausalMeaningGraph, Multi-objective Planning, PredictiveLearning  
**Deciders:** Chief Architect + Project Lead  
**Amends:** ADR-013 (FilmOS Architecture v2.0 — The Meaning Layer)  
**Scope:** 5 foundational changes before Phase 1 — cheaper now than after implementation begins

---

## Foundational Principles

These three principles govern every architectural decision in FilmOS. When a future decision conflicts with one of these, the principle wins — not the convenience of the moment.

### Principle 1 — Everything meaningful is a graph

Facts, meanings, plans, execution traces, evaluations, and learned knowledge are all graph structures. Services never exchange disconnected scalar objects when the information has relational structure.

**Consequences that follow automatically:**
- No fat DTOs between layers — a layer boundary is always a graph-to-graph transformation
- Any step can be replayed by replaying its nodes from the DAGRuntime
- A graph database migration is possible in the future without changing the domain model
- Cross-layer queries ("why did this shot use 85mm?") are graph traversals, not log scans
- New layers can be inserted between existing layers without breaking either side — they consume a graph and emit a graph

### Principle 2 — The kernel knows tasks, not domain

FilmKernel manages tasks: priority, dependencies, resources, deadlines. It does not know what Kling is, what a prompt is, or what a shot is. Domain knowledge lives in plugins. The kernel schedules and dispatches; plugins execute.

### Principle 3 — The system plans, not just decides

FilmOS is a director AI, not a shot generator. A director does not make one decision per shot — a director decomposes a narrative goal into a sequence of sub-goals, then finds the shots that achieve them in order. Layer 3 is the Planning Layer: it takes a goal and returns a shot sequence plan. Shot-level decisions are internal to the planner.

**The closed loop this creates:**

```
Knowledge → Meaning → Planning → Execution → Evaluation → Learning → Knowledge
```

This is not a pipeline. It is a loop. The system evolves.

---

## 8-Layer Architecture (ADR-014 revision)

```
┌──────────────────────────────────────────────────────────────────────┐
│  LAYER 1: KNOWLEDGE                                                  │
│  FactGraph · ProductionBible · WorldState · SceneTimeline            │
│  Source of truth. Temporal history across shots.                     │
├──────────────────────────────────────────────────────────────────────┤
│  LAYER 2: MEANING  [scope-limited: semantics only]                   │
│  MeaningResolver → MeaningGraph (causal graph, not flat object)      │
│  Outputs: MeaningGraph + CinematicFunction + TensionLevel            │
├──────────────────────────────────────────────────────────────────────┤
│  LAYER 3: PLANNING  [was: Decision — renamed per Principle 3]        │
│  FilmPlanner · GoalDecomposer · SubGoalPlanner                       │
│  ShotSequencePlanner · SequenceOptimizer · ConstraintEngine          │
│  Input: NarrativeGoal + MeaningGraph                                 │
│  Output: ShotSequencePlan (ordered PlannedShot[])                    │
├──────────────────────────────────────────────────────────────────────┤
│  LAYER 4: INTENT                                                     │
│  DirectorIntent = { MeaningContext + ExecutionContext + EvalContext } │
│  Per-shot intent assembled from ShotSequencePlan entries.            │
├──────────────────────────────────────────────────────────────────────┤
│  LAYER 5: COMPILATION  [AFOS — frozen, ADR-001]                      │
│  ShotGoalIR → 16 planners → CameraIR → PromptIR                      │
│  Reads ExecutionContext only.                                         │
├──────────────────────────────────────────────────────────────────────┤
│  LAYER 6: RENDERING                                                  │
│  FilmKernel (task-agnostic) · RenderPlugin · ProviderManager         │
├──────────────────────────────────────────────────────────────────────┤
│  LAYER 7: EVALUATION                                                 │
│  MultiAgentReview: Visual · Narrative · Fact(veto) · Emotion · Brand │
│  EvaluationPlugin (registered in FilmKernel)                         │
├──────────────────────────────────────────────────────────────────────┤
│  LAYER 8: LEARNING                                                   │
│  KnowledgeEvolution · HypothesisGenerator · HypothesisTester         │
│  PatternCandidate → accepted patterns feed back into Layer 1         │
└──────────────────────────────────────────────────────────────────────┘

Cross-cutting:
  DAGRuntime         — execution model (not log): every operation = one DAG node
  ConfidencePropagator — confidence decay per hop
  FilmKernel         — task scheduler (task-agnostic OS layer)
```

---

## Change 1: MeaningGraph replaces flat SemanticMeaning

### Why

`SemanticMeaning` was a flat object — it lost the causal chain. "Cockroach → health hazard → unsafe hotel → travel warning" is not a list of labels. It is a graph. The Planning Layer needs to traverse it to understand the depth of the meaning, not just read the top label.

### Change

```php
namespace App\Services\AI\FilmOS\Meaning;

final class MeaningNode
{
    public function __construct(
        public readonly string $nodeId,
        public readonly string $conceptId,  // ontology class ID (ADR-009)
        public readonly string $label,      // 'health_hazard', 'unsafe_hotel', 'travel_warning'
        public readonly float  $weight,     // 0.0–1.0 — how strongly this applies
        public readonly string $evidence,   // "fact contains 'cockroach found in room'"
    ) {}
}

final class MeaningEdge
{
    public function __construct(
        public readonly string $fromNodeId,
        public readonly string $toNodeId,
        public readonly string $relation,  // 'supports' | 'implies' | 'contradicts' | 'modulates'
        public readonly float  $strength,  // 0.0–1.0
    ) {}
}

final class MeaningGraph
{
    public function __construct(
        public readonly string  $subject,
        public readonly array   $nodes,       // MeaningNode[]
        public readonly array   $edges,       // MeaningEdge[]
        public readonly string  $rootNodeId,  // terminal/dominant meaning node
        public readonly float   $confidence,
        public readonly string  $resolvedBy,  // 'context_rules' | 'ai_inference' | 'ontology'
    ) {}

    public function root(): MeaningNode
    {
        return $this->nodeById($this->rootNodeId);
    }

    /** Full causal chain from source leaves to root, ordered bottom-up */
    public function causalChain(): array  // MeaningNode[]
    {
        // BFS from leaves → rootNodeId
    }

    public function above(float $threshold): array  // MeaningNode[]
    {
        return array_values(array_filter($this->nodes, fn($n) => $n->weight >= $threshold));
    }

    private function nodeById(string $id): MeaningNode
    {
        return array_values(array_filter($this->nodes, fn($n) => $n->nodeId === $id))[0];
    }
}
```

**Updated MeaningResolver interface:**

```php
interface MeaningResolver
{
    public function resolve(
        AssetDefinition  $asset,
        array            $facts,
        NarrativeContext $narrativeCtx,
        WorldState       $worldState,
    ): MeaningGraph;  // was: SemanticMeaning
}
```

**Updated MeaningContext:**

```php
final class MeaningContext
{
    public function __construct(
        public readonly MeaningGraph       $graph,            // was: SemanticMeaning
        public readonly CinematicFunction  $function,
        public readonly float              $tensionLevel,
        public readonly ?string            $referencesShotId,
        public readonly float              $meaningConfidence,
    ) {}
}
```

**Example — same asset, different graph structure:**

| Asset | Context | Root meaning | Chain |
|---|---|---|---|
| broken_glass | crime scene | crime_evidence | glass → broken_property → crime_evidence |
| broken_glass | earthquake | disaster_aftermath | glass → structural_damage → disaster_aftermath |
| broken_glass | art gallery | art_installation | glass → curated_object → art_installation |
| broken_glass | family home + "childhood" quote | memory_trigger | glass → nostalgic_object → memory_trigger |

---

## Change 2: FilmPlanner — Layer 3 is Planning, not Decision

### Why Decision → Planning

ADR-014 Rev 1 had `DecisionGraph` with cross-voting candidates — a good mechanism, but still thinking one shot at a time. A director does not think one shot at a time.

A director receives a goal ("Reveal corruption in this scene") and produces a shot sequence. The sequence is designed to achieve the goal — not every shot in isolation, but the sequence as a whole. Some shots are evidence. Some are emotional. Some are contextual. The order matters.

If Layer 3 is only deciding per-shot, the system can never plan a sequence. Renaming it Planning Layer and building `FilmPlanner` locks in the right mental model before any code is written.

**The difference:**

```
Decision engine (old):   Meaning → pick camera strategy → one ExecutionContext
Planning engine (new):   NarrativeGoal → decompose → SubGoal[] → shot sequence → ShotSequencePlan
```

The cross-voting `DecisionStrategy` mechanism from Rev 1 still exists — it becomes the internal mechanism that `SubGoalPlanner` uses to find the best shot for each sub-goal. It moves from top-level architecture to an internal detail of the planner.

### Planning types and models

```php
namespace App\Services\AI\FilmOS\Planning;

final class NarrativeGoal
{
    public function __construct(
        public readonly string  $goalId,
        public readonly string  $intent,        // "Reveal corruption", "Build emotional tension", "Establish location"
        public readonly array   $requirements,  // what must be true in the final sequence for this goal to be met
        public readonly float   $priority,      // 0.0–1.0 — relative to other goals in the same scene
        public readonly ?int    $maxShots,      // optional budget constraint
    ) {}
}

final class SubGoal
{
    public function __construct(
        public readonly string  $subGoalId,
        public readonly string  $type,      // 'evidence' | 'emotion' | 'context' | 'pacing' | 'revelation'
        public readonly string  $label,     // "Need visual evidence of wrongdoing"
        public readonly float   $weight,    // how important this sub-goal is to the parent goal
        public readonly array   $constraints, // must-have requirements for this sub-goal's shot
    ) {}
}

final class PlannedShot
{
    public function __construct(
        public readonly int             $position,      // position in sequence (1-indexed)
        public readonly string          $subGoalId,     // which sub-goal this shot satisfies
        public readonly ExecutionContext $execution,     // what AFOS/compilation layer receives
        public readonly string          $rationale,     // why this shot is here ("establishes tension before reveal")
        public readonly float           $goalContribution, // how much this shot contributes to the parent goal
    ) {}
}

final class ShotSequencePlan
{
    public function __construct(
        public readonly NarrativeGoal $goal,
        public readonly array         $shots,            // PlannedShot[] — ordered
        public readonly float         $goalConfidence,   // 0.0–1.0 — how well this plan achieves the goal
        public readonly array         $subGoalsCovered,  // SubGoal[] — which sub-goals are satisfied
        public readonly array         $alternatives,     // ShotSequencePlan[] — runner-up plans (for DAG)
        public readonly string        $planId,
    ) {}

    /** Shots that satisfy a specific sub-goal */
    public function shotsFor(string $subGoalId): array  // PlannedShot[]
    {
        return array_values(array_filter($this->shots, fn($s) => $s->subGoalId === $subGoalId));
    }
}
```

### FilmPlanner interface

```php
interface FilmPlanner
{
    /**
     * Given a narrative goal and the meaning graph for this scene,
     * produce an ordered shot sequence plan that achieves the goal.
     *
     * This is NOT per-shot. This is sequence-level planning.
     */
    public function plan(
        NarrativeGoal    $goal,
        MeaningGraph     $meaning,
        WorldState       $world,
        ConstraintEngine $constraints,
    ): ShotSequencePlan;
}
```

### GoalDecomposer + SubGoalPlanner

```php
final class GoalDecomposer
{
    /**
     * Decompose a high-level goal into ordered sub-goals.
     *
     * "Reveal corruption" →
     *   [evidence: show incriminating document]
     *   [emotion: show person's reaction]
     *   [context: wide shot establishing power imbalance]
     *   [pacing: tight close-up to hold tension]
     */
    public function decompose(NarrativeGoal $goal, MeaningGraph $meaning): array  // SubGoal[]
    {
        // Uses goal.intent + meaning.root() to select decomposition template
        // Templates come from ProductionBible (Layer 1), not hardcoded here
    }
}

final class SubGoalPlanner
{
    /**
     * For a single sub-goal, find the best shot using cross-voting strategies.
     * This is where the DecisionGraph from Rev 1 lives — inside SubGoalPlanner.
     */
    public function __construct(private readonly array $strategies) {}  // DecisionStrategy[]

    public function planShot(
        SubGoal          $subGoal,
        MeaningGraph     $meaning,
        WorldState       $world,
        ConstraintEngine $constraints,
    ): PlannedShot {
        // Phase 1: Each strategy proposes a candidate for this sub-goal
        $candidates = array_map(
            fn($s) => $s->propose($meaning, $world, $subGoal->constraints),
            $this->strategies
        );

        // Phase 2: Cross-voting
        foreach ($this->strategies as $strategy) {
            foreach ($candidates as $candidate) {
                $candidate->addVote($strategy->id(), $strategy->vote($candidate, $meaning));
            }
        }

        // Phase 3: Filter HARD violations
        $valid = $constraints->filterHard($candidates);
        if (empty($valid)) {
            $valid = [$this->safeFallback($subGoal)];
        }

        $winner = $this->winner($valid);

        return new PlannedShot(
            position:          0,  // set by SequenceOptimizer
            subGoalId:         $subGoal->subGoalId,
            execution:         $this->toExecutionContext($winner, $meaning),
            rationale:         $this->describeRationale($winner, $subGoal),
            goalContribution:  $winner->totalScore() * $subGoal->weight,
        );
    }

    private function winner(array $candidates): DecisionCandidate
    {
        usort($candidates, fn($a, $b) => $b->totalScore() <=> $a->totalScore());
        return $candidates[0];
    }

    private function safeFallback(SubGoal $subGoal): DecisionCandidate
    {
        return new DecisionCandidate(
            proposedBy:         'fallback',
            visualStrategy:     VisualStrategy::OBSERVATIONAL,
            cameraParams:       ['stability' => 'LOCKED', 'cut_rhythm' => 3.0, 'lens_pref' => [50]],
            proposerConfidence: 0.5,
        );
    }
}

final class SequenceOptimizer
{
    /**
     * Given a set of planned shots (one per sub-goal),
     * determine the optimal sequence order for narrative flow.
     *
     * e.g., context → evidence → emotion → pacing
     * not: emotion → evidence → context → pacing (loses tension build)
     */
    public function optimize(array $shots, NarrativeGoal $goal): array  // PlannedShot[] with position set
    {
        // Uses narrative arc templates from ProductionBible
        // Reorders shots so the sequence builds toward the goal's intent
        // Sets PlannedShot.position for each shot
    }
}
```

**End-to-end planning example for "Reveal corruption" goal:**

```
NarrativeGoal: "Reveal corruption" (priority=0.95, maxShots=4)

GoalDecomposer:
  SubGoal 1 [context, w=0.6]:   "Establish power structure"       → wide shot, OBSERVATIONAL
  SubGoal 2 [evidence, w=0.9]:  "Show incriminating document"     → close-up, LOCKED, 85mm
  SubGoal 3 [emotion, w=0.8]:   "Capture reaction of witness"     → medium, CLOSE
  SubGoal 4 [pacing, w=0.5]:    "Hold tension after revelation"   → extreme close, 135mm, 3s hold

SubGoalPlanner (per sub-goal): cross-voting strategies pick best ExecutionContext for each

SequenceOptimizer: orders → [context, evidence, pacing, emotion]
  (pacing before emotion: tension builds from reveal, emotion releases it)

ShotSequencePlan: 4 shots, goalConfidence=0.91
```

**Scalability of the planning model:**

The same `FilmPlanner` interface works at every scale:
- Single shot: goal = "establish character"
- Scene (5–10 shots): goal = "reveal corruption"
- Sequence (10–30 shots): goal = "first act turning point"
- Film (future): goal = "complete narrative arc"

The architecture does not need to change — only the decomposition templates in `ProductionBible` grow.

---

## Change 3: DAGRuntime — DAG as execution model, not log

### Why

ADR-013 DAG was a log — decisions happened first, then got recorded (dual-write). If an operation threw, the DAG had no record. If logging failed, the decision happened but was invisible.

**The fix: the operation IS the node.** `DAGRuntime.execute()` wraps every operation. The node is created if and only if the operation succeeds. No dual-write. No inconsistency.

```php
namespace App\Services\AI\FilmOS\DecisionDAG;

final class DAGRuntime
{
    private array $nodes = [];
    private array $edges = [];

    /**
     * Execute an operation. The result becomes the node payload.
     * Node is created if and only if operation succeeds.
     *
     * @param  callable                                         $operation  () => mixed
     * @param  DAGNodeType                                      $type
     * @param  array<array{0: DAGNode, 1: string, 2: float}>   $parents    [node, relation, weight]
     */
    public function execute(
        callable    $operation,
        DAGNodeType $type,
        array       $parents = [],
    ): DAGNode {
        $payload    = $operation();
        $confidence = $this->propagateConfidence($parents);

        $node = new DAGNode(
            nodeId:     (string) \Symfony\Component\Uid\Uuid::v4(),
            type:       $type,
            payload:    $payload,
            confidence: $confidence,
            createdAt:  new \DateTimeImmutable(),
        );

        $this->nodes[$node->nodeId] = $node;

        foreach ($parents as [$parentNode, $relation, $weight]) {
            $this->edges[] = new DAGEdge($parentNode->nodeId, $node->nodeId, $relation, $weight);
        }

        return $node;
    }

    private function propagateConfidence(array $parents): float
    {
        if (empty($parents)) return 1.0;
        $minParentConf = min(array_map(fn($p) => $p[0]->confidence, $parents));
        return $minParentConf * 0.98;  // 2% decay per hop
    }

    public function toDecisionDAG(string $productionId, string $shotId): DecisionDAG
    {
        return new DecisionDAG($productionId, $shotId, $this->nodes, $this->edges);
    }
}
```

**Full pipeline as DAG-native (Planning Layer example):**

```php
$runtime = new DAGRuntime();

$factNode = $runtime->execute(
    fn() => $factGraph->factsFor($sceneId),
    DAGNodeType::FACT,
);

$meaningNode = $runtime->execute(
    fn() => $meaningResolver->resolve($asset, $factNode->payload, $ctx, $world),
    DAGNodeType::MEANING,
    parents: [[$factNode, 'caused', 0.92]],
);

$planNode = $runtime->execute(
    fn() => $filmPlanner->plan($narrativeGoal, $meaningNode->payload, $world, $constraints),
    DAGNodeType::STRATEGY,      // DAGNodeType::PLAN could be added in Phase 1
    parents: [[$meaningNode, 'caused', $meaningNode->confidence]],
);

// Each PlannedShot becomes its own intent node in the DAG:
foreach ($planNode->payload->shots as $shot) {
    $intentNode = $runtime->execute(
        fn() => $intentAssembler->assemble($meaningNode->payload, $shot->execution),
        DAGNodeType::INTENT,
        parents: [[$planNode, 'caused', $shot->goalContribution]],
    );
}
```

---

## Change 4: HypothesisGenerator — closed-loop learning

### Why

ADR-013 Learning was passive (discover, compress, archive). A true learning system generates falsifiable hypotheses about better behavior and verifies them with controlled A/B experiments. Passive mining finds correlations in existing behavior. Active hypothesis testing finds improvements.

```php
namespace App\Services\AI\FilmOS\Learning;

final class Hypothesis
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $statement,         // "OBSERVATIONAL + 135mm + crime_documentary → watch_time +11%"
        public readonly array   $conditions,        // [VisualStrategy::OBSERVATIONAL, 'domain=crime_documentary', 'lens>=85']
        public readonly array   $predictedOutcome,  // ['watch_time_delta' => 0.11, 'ctr_delta' => 0.03]
        public readonly float   $priorConfidence,   // from supporting pattern evidence
        public readonly string  $status,            // 'pending_test' | 'testing' | 'accepted' | 'rejected'
        public readonly \DateTimeImmutable $generatedAt,
    ) {}
}

interface HypothesisGenerator
{
    /**
     * Given discovered patterns and past outcomes, generate testable hypotheses.
     * Each hypothesis must be falsifiable and actionable (can route shots to treatment).
     *
     * @param  PatternCandidate[] $patterns
     * @param  array              $outcomes  ['production_id' => ['ctr' => 0.08, 'watch_time' => 0.87]]
     * @return Hypothesis[]
     */
    public function generate(array $patterns, array $outcomes): array;
}

final class ABTestPlan
{
    public function __construct(
        public readonly Hypothesis         $hypothesis,
        public readonly int                $requiredSamples,
        public readonly float              $significanceLevel,  // e.g., 0.95
        public readonly string             $controlCondition,   // baseline
        public readonly string             $treatmentCondition, // what is tested
        public readonly \DateTimeImmutable $deadline,
    ) {}
}

final class HypothesisVerdict
{
    public function __construct(
        public readonly bool   $accepted,
        public readonly float  $observedConfidence,
        public readonly array  $observedOutcome,
        public readonly array  $deltaVsPredicted,
        public readonly string $evidence,
    ) {}
}

interface HypothesisTester
{
    public function plan(Hypothesis $hypothesis): ABTestPlan;
    public function record(string $hypothesisId, string $condition, array $outcome): void;
    public function verify(string $hypothesisId): ?HypothesisVerdict;  // null = not enough data yet
}
```

**Updated KnowledgeEvolution (additive):**

```php
interface KnowledgeEvolution
{
    // ADR-013 (unchanged):
    public function mergeFacts(array $productionIds): MergedFactGraph;
    public function pruneObsolete(FactGraph $graph, \DateTimeImmutable $cutoff): FactGraph;
    public function discoverPatterns(array $productionIds): array;
    public function recalibrateConfidence(FactGraph $graph, array $results): FactGraph;
    public function compress(FactGraph $graph): CompressedFactGraph;
    public function archive(FactGraph $graph, \DateTimeImmutable $cutoff): ArchiveResult;
    public function summarize(array $factClusters): array;

    // New in ADR-014:
    public function generateHypotheses(array $patterns, array $outcomes): array;  // Hypothesis[]
    public function planTest(Hypothesis $hypothesis): ABTestPlan;
    public function acceptPattern(Hypothesis $h, HypothesisVerdict $v): void;  // → ProductionBible
    public function rejectPattern(Hypothesis $h, HypothesisVerdict $v): void;  // → archived
}
```

**Closed-loop learning flow:**

```
PatternDiscovery (passive find what happened)
    → HypothesisGenerator.generate() (active: propose what to test)
        → HypothesisTester.plan() (A/B spec)
            → Pipeline routes N% of shots to treatment
                → HypothesisTester.record() (collects outcomes per shot)
                    → HypothesisTester.verify() (significance threshold met?)
                        → KnowledgeEvolution.acceptPattern() → ProductionBible
                        → KnowledgeEvolution.rejectPattern() → archived with evidence
```

---

## Change 5: FilmKernel — task-agnostic OS (updated from Rev 1)

### Why the kernel must not know Kling

Rev 1 `FilmKernel` had `ProviderManager` (Kling/Veo/Sora routing) as a direct sub-component. That means the kernel knows about rendering. After Phase 3, when evaluation tasks and meaning-resolution tasks also need scheduling, the kernel will need to know about evaluation and meaning too. This coupling grows unboundedly.

An operating system kernel does not know what applications run on it. It knows tasks: priority, dependencies, resources, deadline. Applications (plugins) know their domain.

### Kernel types

```php
namespace App\Services\AI\FilmOS\Kernel;

enum TaskType: string
{
    case MEANING_RESOLUTION = 'meaning_resolution';
    case PLANNING           = 'planning';
    case COMPILATION        = 'compilation';
    case RENDER             = 'render';
    case EVALUATE           = 'evaluate';
    case LEARN              = 'learn';
    case ANALYTICS          = 'analytics';
}

final class FilmTask
{
    public function __construct(
        public readonly string       $taskId,
        public readonly TaskType     $type,
        public readonly ShotPriority $priority,
        public readonly array        $dependencies,    // taskId[] — must complete before this runs
        public readonly array        $resources,       // ResourceRequirement[]
        public readonly mixed        $payload,         // task-specific input — kernel does not inspect this
        public readonly \DateTimeImmutable $deadline,
    ) {}
}

final class TaskResult
{
    public function __construct(
        public readonly string $taskId,
        public readonly bool   $success,
        public readonly mixed  $output,       // task-specific — kernel does not inspect this
        public readonly float  $costUsd,
        public readonly int    $durationMs,
        public readonly string $executedBy,   // which plugin ran this
    ) {}
}

final class TaskHandle
{
    public function __construct(
        public readonly string $taskId,
        public readonly \DateTimeImmutable $submittedAt,
    ) {}
}
```

### Plugin interface

```php
interface KernelPlugin
{
    /** Which task types this plugin can execute */
    public function taskTypes(): array;  // TaskType[]

    /** Execute a task. The kernel guarantees dependencies are already complete. */
    public function execute(FilmTask $task): TaskResult;
}
```

### FilmKernel — knows tasks, not domain

```php
final class FilmKernel
{
    /** @var KernelPlugin[] keyed by TaskType value */
    private array $plugins = [];
    private array $queue   = [];

    public function __construct(
        private readonly TaskScheduler $scheduler,
        private readonly MemoryManager $memory,
        private readonly CacheManager  $cache,
    ) {}

    public function register(KernelPlugin $plugin): void
    {
        foreach ($plugin->taskTypes() as $type) {
            $this->plugins[$type->value] = $plugin;
        }
    }

    public function submit(FilmTask $task): TaskHandle
    {
        $this->queue[$task->taskId] = $task;
        return new TaskHandle($task->taskId, new \DateTimeImmutable());
    }

    public function tick(): ?TaskResult
    {
        $task = $this->scheduler->next($this->queue);
        if ($task === null) return null;

        if (!$this->memory->canFit($task)) {
            $this->memory->evict();
        }

        $plugin = $this->plugins[$task->type->value]
            ?? throw new \RuntimeException("No plugin registered for {$task->type->value}");

        $result = $plugin->execute($task);

        unset($this->queue[$task->taskId]);
        $this->memory->release($task);

        return $result;
    }
}
```

### Plugins — domain knowledge lives here, not in kernel

```php
// RenderPlugin: contains ProviderManager, CacheManager, RetryManager
final class RenderPlugin implements KernelPlugin
{
    public function __construct(
        private readonly ProviderManager $provider,
        private readonly CacheManager    $cache,
        private readonly RetryManager    $retry,
    ) {}

    public function taskTypes(): array { return [TaskType::RENDER]; }

    public function execute(FilmTask $task): TaskResult
    {
        // $task->payload = RenderJob — plugin unpacks it
        $job      = $task->payload;
        $provider = $this->provider->select($job);
        // ... render via selected provider
    }
}

// MeaningPlugin: contains MeaningResolver
final class MeaningPlugin implements KernelPlugin
{
    public function taskTypes(): array { return [TaskType::MEANING_RESOLUTION]; }
    public function execute(FilmTask $task): TaskResult { ... }
}

// EvaluationPlugin: contains MultiAgentReview
final class EvaluationPlugin implements KernelPlugin
{
    public function taskTypes(): array { return [TaskType::EVALUATE]; }
    public function execute(FilmTask $task): TaskResult { ... }
}

// PlanningPlugin: contains FilmPlanner
final class PlanningPlugin implements KernelPlugin
{
    public function taskTypes(): array { return [TaskType::PLANNING]; }
    public function execute(FilmTask $task): TaskResult { ... }
}
```

### TaskScheduler and MemoryManager (unchanged from Rev 1)

```php
final class TaskScheduler
{
    // Priority: CRITICAL > IMPORTANT > FILLER
    // Within same priority: earliest deadline first
    // Detects and resolves priority inversion
    public function next(array $queue): ?FilmTask { ... }
    public function detectInversion(array $queue): ?PriorityInversion { ... }
    public function resolveInversion(PriorityInversion $inv, array $queue): array { ... }
}

final class MemoryManager
{
    public function canFit(FilmTask $task): bool { ... }
    public function prefetch(array $upcomingTasks): void { ... }
    public function evict(): void { ... }
    public function release(FilmTask $task): void { ... }
    public function pressureLevel(): float { ... }  // 0.0–1.0
}

final class PriorityInversion
{
    public function __construct(
        public readonly string $blockedTaskId,   // CRITICAL task waiting
        public readonly string $blockingTaskId,  // FILLER task holding resource
        public readonly string $resource,
    ) {}
}
```

**Adding a new task type (e.g., SPECULATIVE_RENDER for prefetching):**

```php
// Step 1: Add to enum
case SPECULATIVE_RENDER = 'speculative_render';

// Step 2: Implement plugin
final class SpeculativeRenderPlugin implements KernelPlugin { ... }

// Step 3: Register
$kernel->register(new SpeculativeRenderPlugin(...));

// That's it. FilmKernel code does not change.
```

---

## Graph-First Unification (Principle 1 applied)

Every layer is a graph. Every layer transition is a graph transformation. A layer that receives a graph emits a graph.

```
FactGraph (Layer 1)
    ──[MeaningResolver]──▶ MeaningGraph (Layer 2)
    ──[FilmPlanner]──────▶ ShotSequencePlan (Layer 3) ← sequence of graphs
    ──[IntentAssembler]──▶ DAGNode[] (Layer 4 — one node per shot via DAGRuntime)
    ──[AFOS Compiler]────▶ PromptIR (Layer 5, frozen)
    ──[FilmKernel+Plugin]▶ RenderResult (Layer 6)
    ──[EvalPlugin]───────▶ ConsensusVerdict (Layer 7)
    ──[KnowledgeEvolution]▶ updated FactGraph + ProductionBible (Layer 8 → Layer 1)
```

The closed loop is now explicit:

```
Layer 8 (Learning) feeds back into Layer 1 (Knowledge)
Layer 1 informs Layer 2 (Meaning)
Layer 2 enables Layer 3 (Planning)
...and so on
```

This is not a pipeline that runs once. It is a loop that evolves.

---

## Updated 8-Layer Summary

| Layer | ADR-013 | ADR-014 Rev 1 | ADR-014 Rev 2 |
|---|---|---|---|
| 1 Knowledge | FactGraph | Unchanged | Unchanged |
| 2 Meaning | SemanticMeaning (flat) | **MeaningGraph** | Unchanged |
| 3 **Planning** | Decision (linear) | DecisionGraph (cross-voting) | **FilmPlanner** (goal decomposition → sequence) |
| 4 Intent | DirectorIntent | MeaningContext carries MeaningGraph | PlannedShot → DirectorIntent per shot |
| 5 Compilation | AFOS (frozen) | Unchanged | Unchanged |
| 6 Rendering | ResourceOrchestrator | FilmKernel (render-aware) | **FilmKernel (task-agnostic) + KernelPlugin** |
| 7 Evaluation | MultiAgentReview | Unchanged | **EvaluationPlugin** registered in FilmKernel |
| 8 Learning | Passive pattern mining | + HypothesisGenerator | Unchanged |

Cross-cutting: **DAGRuntime** · ConfidencePropagator · **FilmKernel**

---

## What Does NOT Change from ADR-013

- AFOS Compiler (ADR-001) — frozen. `ExecutionContext` is produced by `SubGoalPlanner`, same contract.
- `ConstraintEngine` (HARD/SOFT) — used inside `SubGoalPlanner`, same interface.
- `VisualStrategy` enum (5 values) — used inside `SubGoalPlanner.DecisionStrategy`.
- `PatternCandidate` confidence scoring — unchanged.
- `KnowledgeEvolution` base 7 methods — unchanged. 4 new methods added.
- `EvaluationContext` + `MultiAgentReview` reviewer logic — unchanged.
- All ADR-001 through ADR-012 — unchanged.

---

## Implementation Phase Mapping

| Phase | ADR-012 | ADR-013 | ADR-014 Rev 2 |
|---|---|---|---|
| 1 | FactGraph + Decision Ledger | SceneTimeline + DAGRuntime | **DAGRuntime as execution model from day 1.** FilmKernel skeleton + TaskScheduler. Register RenderPlugin only. |
| 2 | DomainStyleProfile + ConstraintEngine | MeaningResolver | **MeaningGraph.** FilmPlanner with GoalDecomposer + SubGoalPlanner (2 strategies). |
| 3 | EventTemplate Library | TensionCurve per domain | SubGoalPlanner + 5 strategies (Camera, Lighting, Composition, Motion, Editing). SequenceOptimizer. |
| 4 | NarrativePattern Library | TensionCurve per pattern | NarrativeGoal templates in ProductionBible. Scale sequence length. |
| 5 | AssetGraph + MultiAgentReview | FilmKernel | **MeaningPlugin + EvaluationPlugin + PlanningPlugin** registered in FilmKernel. MemoryManager prefetch. |
| 6 | DecisionReplay + StyleLearning | KnowledgeEvolution + Compression | **HypothesisGenerator + ABTestPlan + HypothesisTester.** Closed loop complete. |

---

## Architecture Readiness

| Tiêu chí | ADR-013 | ADR-014 Rev 1 | ADR-014 Rev 2 |
|---|---|---|---|
| Separation of concerns | 9.8 | 10.0 | 10.0 |
| Explainability | 10.0 | 10.0 | 10.0 |
| Evolvability | 9.8 | 10.0 | 10.0 |
| AI-native architecture | 10.0 | 10.0 | 10.0 |
| Production scalability | 9.7 | 9.9 | 10.0 |
| Long-term maintainability | 9.8 | 10.0 | 10.0 |
| Closed-loop learning | 7.0 | 10.0 | 10.0 |
| Kernel extensibility | — | 8.0 (render-coupled) | 10.0 (plugin model) |
| Director-level reasoning | — | 7.0 (per-shot only) | 10.0 (goal → sequence) |
| Production readiness | Draft | Draft | **Draft — ready for Phase 1** |

---

## References

- ADR-013 Rev 3 (Amends): 8 corrections still apply — ADR-014 builds on top
- ADR-001: AFOS Compiler — `ExecutionContext` produced by `SubGoalPlanner`, contract unchanged
- ADR-002: WorldState — input to `FilmPlanner.plan()`
- ADR-007: CapabilityResolver — wrapped inside `RenderPlugin`
- ADR-009: OntologyClass — `MeaningGraph` nodes reference ontology IDs
- ADR-010: ShotPriority — `FilmKernel TaskScheduler` uses CRITICAL/IMPORTANT/FILLER
- ADR-012: Phase order — unchanged; ADR-014 additions interleave per phase
