# ADR-010: Decision Engine & Quality Optimization

**Status:** Draft  
**Date:** 2026-07-06  
**Deciders:** Project Lead  
**Depends on:** ADR-004, ADR-006, ADR-007, ADR-009  
**Planned phase:** Phase G (after Phase B–F complete)

---

## Context

ADR-006 introduced `QualityEngine` with a simple evaluation loop:

```
render(shot)
  → evaluate(video) → QualityReport
  → decide: ACCEPT / RETRY_SAME / RETRY_REFINED / SWITCH_BACKEND / ESCALATE
```

This is a **quality gate** — it evaluates one result and decides what to do next.

What it cannot do:
1. **Compare** multiple candidates before picking the best
2. **Optimize** by rendering low-cost draft → evaluate → only render final if draft passes
3. **Learn** which combination of backend + prompt strategy produces the best score for this scene type
4. **Budget** quality evaluations — a FILLER shot doesn't warrant 3 parallel renders
5. **Explain** why a candidate was rejected (audit trail for the producer)

The consequence of a gate-only model: **quality depends on the backend, not the system**.
If backend output is mediocre, the gate can only retry. It cannot reason about *why*
it's mediocre or *how* to improve it systematically.

A `Decision Engine` is different: it is the **optimization loop** that wraps the
quality gate. It generates candidates, evaluates them, selects or improves, and
records what worked. Quality becomes a system property, not a model property.

---

## Decision

Introduce `DecisionEngine`: an autonomous optimization loop for shot rendering.
`QualityEngine` (ADR-006) becomes the **scorer** inside this loop.
`CapabilityResolver` (ADR-007) becomes the **provider picker** inside this loop.

The Decision Engine owns:
- How many candidates to generate
- When to stop (budget, threshold, max iterations)
- How to improve a failing candidate (refinement strategy)
- Which candidate wins (selection strategy)
- What to record for future learning (PromptIntelligence, ADR-006)

---

## Core Model

### DecisionBudget

Per-shot resource envelope for the Decision Engine:

```php
namespace App\Services\AI\FilmOS\DecisionEngine;

final class DecisionBudget
{
    public function __construct(
        public readonly int    $maxCandidates,       // how many renders to attempt total
        public readonly int    $maxParallelRenders,  // how many to run simultaneously
        public readonly float  $maxCostUsd,          // total cost ceiling for this shot
        public readonly float  $targetQualityScore,  // stop when any candidate reaches this
        public readonly float  $acceptableQualityScore, // accept even if target not reached
        public readonly int    $maxRefinementRounds,  // how many prompt-refinement cycles
    ) {}

    public static function forPriority(ShotPriority $priority): self
    {
        return match ($priority) {
            ShotPriority::CRITICAL => new self(
                maxCandidates: 3, maxParallelRenders: 3,
                maxCostUsd: 2.00, targetQualityScore: 90.0,
                acceptableQualityScore: 80.0, maxRefinementRounds: 2,
            ),
            ShotPriority::IMPORTANT => new self(
                maxCandidates: 2, maxParallelRenders: 1,
                maxCostUsd: 0.80, targetQualityScore: 82.0,
                acceptableQualityScore: 72.0, maxRefinementRounds: 1,
            ),
            ShotPriority::FILLER => new self(
                maxCandidates: 1, maxParallelRenders: 1,
                maxCostUsd: 0.20, targetQualityScore: 70.0,
                acceptableQualityScore: 60.0, maxRefinementRounds: 0,
            ),
        };
    }
}
```

### Candidate

A single render attempt:

```php
final class Candidate
{
    public function __construct(
        public readonly string          $candidateId,
        public readonly string          $providerId,
        public readonly PromptIRSnapshot $snapshot,
        public readonly RenderContext   $renderContext,
        public readonly ?string         $videoUrl,       // null = not yet rendered
        public readonly ?QualityReport  $qualityReport,  // null = not yet evaluated
        public readonly float           $costUsd,
        public readonly int             $renderMs,
        public readonly CandidateStatus $status,
    ) {}
}

enum CandidateStatus: string
{
    case PENDING    = 'pending';
    case RENDERING  = 'rendering';
    case EVALUATING = 'evaluating';
    case SCORED     = 'scored';
    case FAILED     = 'failed';
}
```

### DecisionRecord

The audit trail of why a candidate won or lost:

```php
final class DecisionRecord
{
    public function __construct(
        public readonly string      $shotId,
        public readonly string      $productionId,
        public readonly array       $candidates,        // Candidate[]
        public readonly string      $winnerId,          // candidateId
        public readonly float       $winnerScore,
        public readonly string      $selectionReason,   // human-readable explanation
        public readonly float       $totalCostUsd,
        public readonly int         $totalRenderMs,
        public readonly int         $roundsUsed,
        public readonly \DateTimeImmutable $decidedAt,
    ) {}
}
```

---

## Decision Engine Interface

```php
interface DecisionEngine
{
    /**
     * Run the full optimization loop for a single shot.
     * Returns the winning candidate and the full decision record.
     */
    public function decide(
        ShotCompiled   $event,          // shot to render (from ADR-004 event)
        DecisionBudget $budget,
        CapabilitySpec $spec,           // from ADR-007
    ): DecisionOutcome;
}

final class DecisionOutcome
{
    public function __construct(
        public readonly Candidate      $winner,
        public readonly DecisionRecord $record,
        public readonly bool           $meetsTarget,       // winner.score >= budget.targetQualityScore
        public readonly bool           $acceptable,        // winner.score >= budget.acceptableQualityScore
    ) {}
}
```

---

## Default Implementation: `TournamentDecisionEngine`

Tournament model: render N candidates, score all, pick best, optionally refine and retry.

```php
final class TournamentDecisionEngine implements DecisionEngine
{
    public function __construct(
        private readonly CapabilityResolver $capabilityResolver,
        private readonly RendererPlugin     $renderer,         // via PluginRegistry
        private readonly QualityEngine      $qualityEngine,    // scorer (ADR-006)
        private readonly RefinementEngine   $refinement,
        private readonly PromptIntelligence $promptIntelligence, // (ADR-006)
        private readonly DecisionRepository $decisionRepository,
    ) {}

    public function decide(
        ShotCompiled   $event,
        DecisionBudget $budget,
        CapabilitySpec $spec,
    ): DecisionOutcome {

        $candidates = [];
        $round = 0;

        // Round 0: Generate initial candidates
        $providers = $this->capabilityResolver->candidates($spec, $budget->toEnvelope());
        $providers = array_slice($providers, 0, $budget->maxCandidates);

        $candidates = $this->renderParallel($providers, $event, $budget->maxParallelRenders);
        $candidates = $this->evaluateAll($candidates, $event);

        // Check if target met
        $best = $this->selectBest($candidates);

        while ($best->qualityReport->score < $budget->targetQualityScore
            && $round < $budget->maxRefinementRounds)
        {
            $round++;

            // Refine the best candidate's prompt
            $refined = $this->refinement->refine(
                $best->snapshot,
                $best->qualityReport,
                $event->renderContext,
            );

            $refinedCandidate = $this->renderOne($best->providerId, $refined, $event);
            $refinedCandidate = $this->evaluateOne($refinedCandidate, $event);
            $candidates[] = $refinedCandidate;

            $best = $this->selectBest($candidates);
        }

        // Record for PromptIntelligence learning
        $this->promptIntelligence->record(new PromptRecord(
            promptHash:  $best->snapshot->promptHash(),
            providerId:  $best->providerId,
            qualityScore: $best->qualityReport->score,
            context:     $event->renderContext,
        ));

        $record = new DecisionRecord(
            shotId:          $event->shotId,
            productionId:    $event->productionId,
            candidates:      $candidates,
            winnerId:        $best->candidateId,
            winnerScore:     $best->qualityReport->score,
            selectionReason: $this->explain($best, $candidates),
            totalCostUsd:    array_sum(array_map(fn($c) => $c->costUsd, $candidates)),
            totalRenderMs:   array_sum(array_map(fn($c) => $c->renderMs, $candidates)),
            roundsUsed:      $round,
            decidedAt:       new \DateTimeImmutable(),
        );

        $this->decisionRepository->save($record);

        return new DecisionOutcome(
            winner:      $best,
            record:      $record,
            meetsTarget: $best->qualityReport->score >= $budget->targetQualityScore,
            acceptable:  $best->qualityReport->score >= $budget->acceptableQualityScore,
        );
    }
}
```

---

## RefinementEngine

Produces an improved `PromptIRSnapshot` given a quality failure reason:

```php
interface RefinementEngine
{
    public function refine(
        PromptIRSnapshot $original,
        QualityReport    $failureReport,
        RenderContext    $context,
    ): PromptIRSnapshot;
}
```

Default implementation uses `QualityReport.failures` to target specific dimensions:

```php
final class FailureTargetedRefinementEngine implements RefinementEngine
{
    public function refine(
        PromptIRSnapshot $original,
        QualityReport    $failureReport,
        RenderContext    $context,
    ): PromptIRSnapshot {
        $patch = [];

        foreach ($failureReport->failures as $failure) {
            $patch[] = match ($failure->dimension) {
                QualityDimension::CHARACTER_CONSISTENCY =>
                    "Ensure {$context->characters[0]->name} appearance exactly matches reference image.",
                QualityDimension::LIGHTING =>
                    "Increase {$failure->expected} lighting intensity. Avoid flat illumination.",
                QualityDimension::COMPOSITION =>
                    "Follow {$failure->expected} composition rule. Subject at {$failure->expectedValue}.",
                QualityDimension::MOTION =>
                    "Character movement must be {$failure->expected}. Avoid jerky or unnatural motion.",
                default => null,
            };
        }

        return $original->withAdditionalInstructions(array_filter($patch));
    }
}
```

---

## Selection Strategy

`selectBest()` uses a **weighted multi-dimensional score**:

```php
// QualityReport.score is already a weighted composite (from QualityEngine)
// but DecisionEngine can apply additional production-level weights:

private function selectBest(array $candidates): Candidate
{
    $scored = array_filter($candidates, fn($c) => $c->status === CandidateStatus::SCORED);

    usort($scored, function (Candidate $a, Candidate $b) {
        // Primary: quality score (higher is better)
        $scoreDiff = $b->qualityReport->score - $a->qualityReport->score;
        if (abs($scoreDiff) > 2.0) {
            return (int) ($scoreDiff * 100);
        }

        // Tie-break: lower cost (if scores within 2 points)
        return $a->costUsd <=> $b->costUsd;
    });

    return $scored[0];
}
```

---

## Persistence

`DecisionRecord` persisted to audit table:

```sql
CREATE TABLE production_decision_records (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    production_id   VARCHAR(36) NOT NULL,
    shot_id         VARCHAR(36) NOT NULL,
    winner_id       VARCHAR(36) NOT NULL,       -- candidateId
    winner_score    DECIMAL(5,2) NOT NULL,
    total_cost_usd  DECIMAL(8,4) NOT NULL,
    total_render_ms INT UNSIGNED NOT NULL,
    rounds_used     TINYINT UNSIGNED NOT NULL,
    candidate_count TINYINT UNSIGNED NOT NULL,
    selection_reason TEXT NOT NULL,
    payload         JSON NOT NULL,              -- full DecisionRecord serialized
    created_at      TIMESTAMP NOT NULL,
    KEY idx_production_shot (production_id, shot_id),
    KEY idx_winner_score (production_id, winner_score)
);
```

---

## Decision Engine vs QualityEngine

| | `QualityEngine` (ADR-006) | `DecisionEngine` (ADR-010) |
|---|---|---|
| Role | Scorer: evaluate one video | Optimizer: manage the whole loop |
| Input | One rendered video + snapshot | ShotCompiled event + budget |
| Output | `QualityReport` (score + failures) | `DecisionOutcome` (winner + audit) |
| Generates candidates? | No | Yes (N parallel renders) |
| Refines prompts? | No | Yes (RefinementEngine) |
| Records learning? | No | Yes (PromptIntelligence) |
| Knows about budget? | No | Yes (DecisionBudget) |
| Knows about providers? | No | Yes (via CapabilityResolver) |

`QualityEngine` is called *inside* `DecisionEngine`. They are not alternatives.

---

## Event Integration

`DecisionEngine.decide()` produces a `ShotDecided` event (new, Phase G):

```php
final class ShotDecided implements ProductionEvent
{
    public function __construct(
        public readonly string  $productionId,
        public readonly string  $shotId,
        public readonly string  $videoUrl,           // winner's URL
        public readonly float   $winnerScore,
        public readonly float   $totalCostUsd,
        public readonly bool    $meetsTarget,
        public readonly \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {}
}
```

`ShotDecided` replaces `VideoRendered` (ADR-004) as the canonical "shot is done" signal.
`VideoRendered` becomes an internal event within DecisionEngine's render loop.

---

## Directory Structure

```
app/Services/AI/FilmOS/DecisionEngine/
├── DecisionEngine.php              (interface)
├── TournamentDecisionEngine.php    (default impl)
├── DecisionBudget.php
├── Candidate.php
├── DecisionRecord.php
├── DecisionOutcome.php
├── Enums/
│   └── CandidateStatus.php
├── Refinement/
│   ├── RefinementEngine.php            (interface)
│   └── FailureTargetedRefinementEngine.php
├── Selection/
│   └── WeightedScoreSelector.php
└── Persistence/
    └── DecisionRepository.php          (interface)
```

---

## Consequences

### Positive
- Quality becomes a **system property**, not a backend property
- CRITICAL shots get 3 parallel renders + 2 refinement rounds; FILLER shots get 1 render (cost control)
- `PromptIntelligence` receives structured feedback from every decision → improves over time
- `DecisionRecord` gives full audit trail: why shot 12 cost $1.40 instead of $0.20
- Can A/B test providers: render same shot on Kling + Veo, pick winner → informs catalog rankings

### Negative
- Parallel renders for CRITICAL shots multiply cost (mitigated by `DecisionBudget.maxCostUsd`)
- `TournamentDecisionEngine` is a long-running async job; needs queue timeout > 10 minutes for 3 renders
- `ShotDecided` replaces `VideoRendered` as canonical signal — listeners must be updated

### Not changing
- `QualityEngine` (ADR-006) — unchanged, used as scorer inside DecisionEngine
- `PromptIntelligence` (ADR-006) — unchanged, receives records from DecisionEngine
- `CapabilityResolver` (ADR-007) — unchanged, used for candidate provider selection
- AFOS Compiler — unchanged

---

## References

- ADR-004: Production Event Bus (ShotDecided event, VideoRendered as internal event)
- ADR-006: QualityEngine (scorer), PromptIntelligence (learner), PluginRegistry (renderer access)
- ADR-007: CapabilityResolver (provider selection for candidates)
- ADR-009: KnowledgeOS (enriched RenderContext → better quality scores)
