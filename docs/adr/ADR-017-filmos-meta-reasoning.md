# ADR-017: FilmOS Meta Reasoning Architecture

**Status:** Accepted  
**Date:** 2026-07-08  
**Revision:** 1  
**Deciders:** Chief Architect + Project Lead  
**Type:** Meta Architecture (above all layers — not a new layer)  
**Depends on:** ADR-016 (Architecture Lock), ADR-015 (Graph Execution Model)

---

## Context

ADR-012 through ADR-016 fully define *how* FilmOS creates video:
- Knowledge → Meaning → Planning → Execution → Evaluation → Learning

What they do not address: **who decides the strategy of the system itself?**

Currently, all strategy decisions are hardcoded:

```php
match ($domain) {
    'travel_warning' => $this->travelWarningTemplate($meaning),
    default          => $this->genericTemplate($meaning),
}
```

```php
PlanObjectives::breakingNews()  // always
```

This means FilmOS knows how to make video, but does not know how to choose *how* to make video given the current context.

### The core question ADR-017 answers

> Given a production context (domain, urgency, budget, deadline), which combination of planner, providers, reviewers, retry budget, and learning weight should FilmOS use?

---

## The Missing Layer: Meta Planner

The Meta Planner is **not a Layer 9**. It is a **system-level configurator** that runs before the FilmKernel starts.

```
Production Context
  ↓
MetaPlanner.decide(context) → SystemStrategy
  ↓
FilmKernel.configure(strategy)
  ↓
[L1→L8 execute with the decided strategy]
  ↓
MetaLearner.record(context, strategy, outcomes)
  ↓
MetaPlanner improves (post-Phase 2)
```

The layers below are **completely unaware** of which strategy was selected. They only see the resolved FilmKernel configuration.

---

## Decision

### Core Types

```php
final class ProductionContext
{
    public function __construct(
        public readonly string       $productionId,
        public readonly string       $domain,          // travel_warning, sports, documentary...
        public readonly ContentType  $contentType,     // BREAKING_NEWS, FEATURE, DOCUMENTARY
        public readonly Urgency      $urgency,         // HIGH | MEDIUM | LOW
        public readonly float        $budgetUsd,
        public readonly int          $deadlineMs,
        public readonly array        $historySignals,  // prior CTR, review scores for this domain
    ) {}
}

enum ContentType: string
{
    case BREAKING_NEWS = 'breaking_news';
    case FEATURE       = 'feature';
    case DOCUMENTARY   = 'documentary';
    case SPORTS        = 'sports';
    case EDITORIAL     = 'editorial';
}

enum Urgency: string
{
    case HIGH   = 'high';
    case MEDIUM = 'medium';
    case LOW    = 'low';
}
```

### SystemStrategy

```php
final class SystemStrategy
{
    public function __construct(
        public readonly string         $strategyId,
        public readonly PlannerProfile $plannerProfile,    // FAST | BALANCED | DEEP
        public readonly int            $reviewerCount,     // 1–5 (Phase 1: always 1 real, rest stubs)
        public readonly string         $renderProvider,    // 'kling' | 'veo' | 'runway'
        public readonly RetryBudget    $retryBudget,
        public readonly float          $learningWeight,    // 0.0 = ignore prediction, 1.0 = trust fully
        public readonly string         $rationale,
    ) {}
}

enum PlannerProfile: string
{
    case FAST     = 'fast';      // 1 strategy per shot, first candidate wins
    case BALANCED = 'balanced';  // 2 strategies, cross-vote
    case DEEP     = 'deep';      // all strategies, Pareto front
}

final class RetryBudget
{
    public function __construct(
        public readonly int   $maxAttempts,
        public readonly int   $backoffMs,
        public readonly float $maxExtraCostUsd,
    ) {}

    public static function none(): self    { return new self(0, 0, 0.0); }
    public static function standard(): self { return new self(2, 3000, 0.50); }
    public static function generous(): self { return new self(3, 5000, 2.00); }
}
```

### MetaPlanner Interface

```php
interface MetaPlanner
{
    public function decide(ProductionContext $context): SystemStrategy;
}
```

### Phase 1: RuleBasedMetaPlanner

```php
final class RuleBasedMetaPlanner implements MetaPlanner
{
    public function decide(ProductionContext $context): SystemStrategy
    {
        return match ($context->contentType) {
            ContentType::BREAKING_NEWS => new SystemStrategy(
                strategyId:     'breaking_news_fast',
                plannerProfile: PlannerProfile::FAST,
                reviewerCount:  1,
                renderProvider: 'kling',
                retryBudget:    RetryBudget::none(),
                learningWeight: 0.30,
                rationale:      'Breaking news: speed > quality, 1 reviewer, no retry',
            ),

            ContentType::DOCUMENTARY => new SystemStrategy(
                strategyId:     'documentary_deep',
                plannerProfile: PlannerProfile::DEEP,
                reviewerCount:  5,
                renderProvider: 'kling',
                retryBudget:    RetryBudget::generous(),
                learningWeight: 0.80,
                rationale:      'Documentary: quality > speed, all reviewers, generous retry',
            ),

            ContentType::SPORTS => new SystemStrategy(
                strategyId:     'sports_balanced',
                plannerProfile: PlannerProfile::BALANCED,
                reviewerCount:  2,
                renderProvider: 'kling',
                retryBudget:    RetryBudget::standard(),
                learningWeight: 0.60,
                rationale:      'Sports: balanced, 2 reviewers, standard retry',
            ),

            default => new SystemStrategy(
                strategyId:     'default_balanced',
                plannerProfile: PlannerProfile::BALANCED,
                reviewerCount:  2,
                renderProvider: 'kling',
                retryBudget:    RetryBudget::standard(),
                learningWeight: 0.50,
                rationale:      'Default balanced strategy',
            ),
        };
    }
}
```

### MetaLearner (Phase 2 — Phase 1 is stub)

```php
interface MetaLearner
{
    /**
     * Records which strategy was used and what outcomes resulted.
     * After sufficient data, MetaPlanner switches from rule-based to learned.
     */
    public function record(
        ProductionContext $context,
        SystemStrategy    $strategy,
        array             $outcomes,     // ['ctr' => 0.071, 'review_score' => 0.89, 'latency_ms' => 8200]
    ): void;

    /**
     * Returns the strategy with the best outcomes for this context.
     * Returns null if not enough data (< 20 comparable productions).
     */
    public function bestStrategy(ProductionContext $context): ?SystemStrategy;
}
```

### LearningMetaPlanner (Phase 2)

```php
final class LearningMetaPlanner implements MetaPlanner
{
    public function __construct(
        private readonly MetaLearner   $learner,
        private readonly MetaPlanner   $fallback,   // RuleBasedMetaPlanner
    ) {}

    public function decide(ProductionContext $context): SystemStrategy
    {
        $learned = $this->learner->bestStrategy($context);

        // Fall back to rule-based when not enough data
        return $learned ?? $this->fallback->decide($context);
    }
}
```

---

## What MetaPlanner does NOT control

| Concern | Owned by |
|---|---|
| GPU allocation, queue priority | FilmKernel TaskScheduler |
| Rate limits, circuit breakers | Provider adapters |
| Shot-level camera decisions | CameraStrategy |
| Meaning resolution logic | ContextualMeaningResolver |
| Invariant enforcement | DAGRuntime / CheckInvariantsCommand |

MetaPlanner decides **which version** of these subsystems runs. It does not replace them.

---

## Integration with FilmKernel

```php
// Before production starts:
$context  = new ProductionContext(...);
$strategy = $metaPlanner->decide($context);

// FilmKernel reads strategy to configure itself:
$kernel->applyStrategy($strategy);  // sets retry budget, plugin selection, etc.

// Layer 3 reads PlannerProfile to decide how many candidates to generate:
$subGoalPlanner = SubGoalPlanner::fromProfile($strategy->plannerProfile, $strategies);
```

---

## Phase 1 implementation scope

| Component | Phase 1 |
|---|---|
| `ProductionContext` | Real — built from article domain + deadline |
| `SystemStrategy` | Real — value object |
| `RuleBasedMetaPlanner` | Real — 4 content type rules |
| `MetaLearner` | **Stub** — `record()` logs to DB; `bestStrategy()` always returns null |
| `LearningMetaPlanner` | Not built — Phase 2 |
| `FilmKernel.applyStrategy()` | Real — applies retry budget + reviewerCount |

---

## Consequences

**Gains:**
- FilmOS can now vary its behavior per production context without changing core code
- A/B testing strategies becomes a first-class operation
- Opens path to fully learned meta-strategy in Phase 2
- Breaking news and documentary can run the same pipeline with different quality/speed tradeoffs

**Risks:**
- MetaPlanner adds one more configuration layer — wrong rules produce systematically wrong productions
- Must log which strategy was used alongside production outcomes (audit requirement)

**Invariant compliance:**
- Does not violate any of the 6 invariants from ADR-016
- MetaPlanner runs before DAGRuntime starts — no dual-write, no execution outside DAGRuntime
- SystemStrategy is a graph-compatible value object (can be stored as a DAG node)

---

## Files to create

```
app/Services/AI/FilmOS/Meta/
├── ProductionContext.php
├── ContentType.php           (enum)
├── Urgency.php               (enum)
├── SystemStrategy.php
├── PlannerProfile.php        (enum)
├── RetryBudget.php
├── MetaPlanner.php           (interface)
├── MetaLearner.php           (interface)
├── RuleBasedMetaPlanner.php
├── StubMetaLearner.php
└── LearningMetaPlanner.php   (Phase 2)
```

---

## References

- ADR-016 Invariant 2: "Layers are logical boundaries, not execution order" — MetaPlanner operates before DAGRuntime, not inside it
- ADR-015 MultiObjectiveOptimizer — MetaPlanner's `learningWeight` feeds into how much PredictiveLearning predictions are trusted
- ADR-016 Walking Skeleton: `RunGoldenScenarioCommand` should accept `--content-type` arg to exercise MetaPlanner
