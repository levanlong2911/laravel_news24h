# ADR-008: Asset Graph & World Graph

**Status:** Draft  
**Date:** 2026-07-06  
**Deciders:** Project Lead  
**Depends on:** ADR-002, ADR-005, ADR-009  
**Planned phase:** Phase G (after Phase B–F complete)

---

## Context

ADR-002 introduces `AssetDefinition` + `AssetInstance` (ECS pattern) and `WorldModule`
(world definitions). ADR-006 introduces `AssetDependencyGraph` for invalidation cascade
(change wardrobe → invalidate downstream shots).

However, neither of these models the **spatial, semantic, and physical relationships** between
assets in a scene. Real production has complex relationships:

```
Location: Hotel Lobby
 └── Prop: Reception Desk [BREAKABLE, SURFACE]
      └── Prop: Hotel Register [CONTAINS: pages]
           └── Prop: Pen [HELD_BY: Receptionist]
 └── Character: Receptionist
      └── Costume: Blazer [COVERS: torso]
      └── Accessory: Badge [ATTACHED_TO: Blazer, READABLE]
 └── Prop: CCTV Camera [MOUNTED_ON: wall, OBSERVES: zone_A]
 └── Vehicle: Luggage Cart [CONTAINS: Suitcase]
      └── Prop: Suitcase [LOCKED, CONTAINS: documents]
```

Without this graph, the system cannot:
- Know that breaking the desk exposes the register
- Know that the receptionist's hand must be visible when holding the pen
- Know that CCTV camera footage would reveal the suitcase
- Know that destroying the luggage cart invalidates the suitcase's position

ADR-006 `AssetDependencyGraph` solves *compilation invalidation*.
ADR-008 `WorldGraph` solves *semantic understanding of the scene*.

These are **two different graphs** with different purposes.

---

## Decision

Introduce `WorldGraph`: a directed graph of assets and characters with typed
relationships, state vectors, and interaction rules. This is the semantic model
of the physical world — not a render graph, not a dependency graph.

`WorldGraph` is the source of truth for:
- What contains what
- What is attached to what
- What can interact with what
- What state things are in

It feeds `WorldStateEngine` (ADR-006), `ConstraintEngine` (ADR-002), and
`KnowledgeOS` (ADR-009).

---

## Core Types

### AssetNode

Each entity in the graph — character, prop, costume, vehicle, FX, location:

```php
namespace App\Services\AI\FilmOS\WorldGraph;

final class AssetNode
{
    public function __construct(
        public readonly string          $nodeId,
        public readonly string          $assetId,       // → AssetDefinition.id (ADR-002)
        public readonly AssetNodeType   $type,          // CHARACTER, PROP, COSTUME, VEHICLE, LOCATION, FX, SOUND
        public readonly string          $displayName,
        public readonly StateVector     $state,         // current physical state
        public readonly array           $tags,          // ['breakable', 'readable', 'locked', ...]
    ) {}
}
```

### RelationshipType

The typed edge between two nodes:

```php
enum RelationshipType: string
{
    // Spatial / Physical
    case CONTAINS      = 'contains';        // Room contains Table
    case MOUNTED_ON    = 'mounted_on';      // Camera mounted_on Wall
    case ATTACHED_TO   = 'attached_to';     // Badge attached_to Blazer
    case ADJACENT_TO   = 'adjacent_to';     // Desk adjacent_to Chair

    // Ownership / Interaction
    case HELD_BY       = 'held_by';         // Pen held_by Character
    case WORN_BY       = 'worn_by';         // Blazer worn_by Character
    case DRIVEN_BY     = 'driven_by';       // Car driven_by Character
    case OPERATED_BY   = 'operated_by';     // Camera operated_by Character

    // Semantic / Narrative
    case COVERS        = 'covers';          // Blazer covers Torso
    case OBSERVES      = 'observes';        // CCTV observes Zone
    case BLOCKS        = 'blocks';          // Wall blocks Line-of-sight
    case REQUIRES      = 'requires';        // KeyPad requires Key

    // State dependency
    case ENABLES       = 'enables';         // Key enables Door[OPEN]
    case DESTROYS      = 'destroys';        // Explosion destroys Table
    case CONTAINS_FX   = 'contains_fx';     // Cup contains_fx Steam
}
```

### AssetEdge

Directed edge between two nodes:

```php
final class AssetEdge
{
    public function __construct(
        public readonly string           $fromNodeId,
        public readonly string           $toNodeId,
        public readonly RelationshipType $type,
        public readonly array            $metadata,     // e.g., ['zone' => 'zone_A'] for OBSERVES
        public readonly bool             $isMutable,    // can this relationship change during production?
    ) {}
}
```

### StateVector

Physical state of an asset node:

```php
final class StateVector
{
    public function __construct(
        public readonly string  $nodeId,
        public readonly array   $flags,    // ['locked' => true, 'broken' => false, 'open' => false]
        public readonly array   $scalars,  // ['fill_level' => 0.8, 'temperature' => 'hot']
        public readonly ?string $heldBy,   // characterId if currently held
        public readonly ?string $wornBy,   // characterId if currently worn
    ) {}

    public function with(string $key, mixed $value): self { ... }
}
```

### WorldGraph

The complete graph for a production:

```php
final class WorldGraph
{
    /** @param AssetNode[] $nodes */
    /** @param AssetEdge[] $edges */
    public function __construct(
        private readonly array $nodes,
        private readonly array $edges,
    ) {}

    /** Returns all nodes directly contained within the given node. */
    public function children(string $nodeId, RelationshipType $via): array { ... }

    /** Returns all ancestors up to root. */
    public function ancestors(string $nodeId): array { ... }

    /** Returns all nodes reachable from given node within N hops. */
    public function neighborhood(string $nodeId, int $maxDepth = 2): array { ... }

    /** Returns all edges of a given type. */
    public function edgesOfType(RelationshipType $type): array { ... }

    /** Returns nodes that would be affected if given node changes state. */
    public function dependents(string $nodeId): array { ... }

    /** Checks: can character perform this interaction on target node? */
    public function canInteract(string $characterNodeId, string $targetNodeId, string $interactionType): bool { ... }
}
```

---

## State Propagation Model

When a `WorldEvent` fires (e.g., table is broken), the `WorldStateEngine` (ADR-006)
uses `WorldGraph` to propagate state changes:

```php
// WorldStateEngine uses WorldGraph for traversal
final class WorldStateEngine
{
    public function applyEvent(WorldEvent $event, WorldGraph $graph): WorldStateDelta
    {
        $affected = $graph->dependents($event->targetNodeId);

        $deltas = [];
        foreach ($affected as $node) {
            $rule = $this->ruleRegistry->find($event->type, $node->type);
            if ($rule !== null) {
                $deltas[] = $rule->apply($node, $event);
            }
        }

        return new WorldStateDelta($event->targetNodeId, $deltas);
    }
}
```

Example propagation:
```
DESTROY(Table)
  → Table.state.broken = true
  → Cup.state.position = null       (fell when table broke)
  → Coffee.state = SPILLED
  → Floor.state.wetness += 0.6
  → Character[nearby].costume.dirt_level += 30   (spatter)
```

---

## WorldGraph vs AssetDependencyGraph

| | `AssetDependencyGraph` (ADR-006) | `WorldGraph` (ADR-008) |
|---|---|---|
| Purpose | Invalidate compiled shots when assets change | Model spatial/semantic relationships |
| Lives in | Production pipeline (compilation time) | FilmOS domain model (runtime) |
| Nodes | Shot outputs, thumbnails, memory entries | Characters, props, locations, FX |
| Edges | "must recompile because X changed" | "contains / wears / holds / observes" |
| State | None | StateVector per node |
| Query type | "what to invalidate?" | "what contains what?", "can X interact with Y?" |

Both exist. They are complementary.

---

## WorldGraph vs AssetModule (ADR-002)

`AssetModule` in `ProductionBible` is the **definition layer**:
- Declares what assets *exist* in the production
- Holds `AssetDefinition` (static spec) + `AssetInstance` (state per shot)

`WorldGraph` is the **relationship layer**:
- Declares how assets *relate* to each other in a scene
- `WorldGraph` is built from `AssetModule` definitions at scene-planning time
- `AssetInstance.state` is updated by `WorldGraph` state propagation

```
AssetModule (definitions)
      ↓ build()
WorldGraph (relationships + state)
      ↓ applyEvent()
StateVector changes
      ↓ sync()
AssetInstance.state (per shot in AssetModule)
```

---

## Integration Points

| Component | How it uses WorldGraph |
|-----------|----------------------|
| `ConstraintEngine` (ADR-002) | `canInteract()` — validate that shot's described interaction is physically possible |
| `WorldStateEngine` (ADR-006) | `dependents()` — propagate state changes through graph |
| `KnowledgeOS` (ADR-009) | Graph topology feeds ontology inference |
| `RenderContext` (ADR-002) | `neighborhood()` — build list of visible assets for renderer |
| `ContinuityEngine` | `edgesOfType(WORN_BY)` — find wardrobe for character in shot |
| `AssetDependencyGraph` (ADR-006) | Shares node IDs; operates independently |

---

## Persistence

`WorldGraph` per production is persisted as a JSON blob (T3 storage, ADR-005):

```sql
CREATE TABLE production_world_graphs (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    production_id  VARCHAR(36) NOT NULL,
    bible_version  VARCHAR(20) NOT NULL,
    payload        JSON NOT NULL,          -- WorldGraph serialized
    node_count     SMALLINT UNSIGNED NOT NULL,
    edge_count     SMALLINT UNSIGNED NOT NULL,
    created_at     TIMESTAMP NOT NULL,
    UNIQUE KEY uq_production_bible (production_id, bible_version)
);
```

`StateVector` snapshots are stored alongside `CharacterState` in `production_character_states` (ADR-005):

```sql
-- Extend production_character_states
ALTER TABLE production_character_states
    ADD COLUMN world_state_delta JSON NULL;  -- WorldStateDelta for this shot
```

---

## Directory Structure

```
app/Services/AI/FilmOS/WorldGraph/
├── WorldGraph.php
├── AssetNode.php
├── AssetEdge.php
├── StateVector.php
├── WorldStateDelta.php
├── WorldEvent.php
├── WorldGraphBuilder.php               -- builds from AssetModule + scene plan
├── Enums/
│   ├── AssetNodeType.php
│   └── RelationshipType.php
└── Rules/
    ├── StateTransitionRule.php         (interface)
    ├── DestroyContainerRule.php        -- DESTROY propagates to children
    ├── SpillRule.php                   -- liquid CONTAINS → SPILLED
    └── WardrobeDirtRule.php            -- nearby DESTROY → costume dirt
```

---

## Consequences

### Positive
- `ConstraintEngine` can validate *physical possibility* of described interactions
- `WorldStateEngine` can propagate realistic state changes without hardcoded rules
- `RenderContext` can include a richer list of visible assets based on spatial proximity
- Continuity checking becomes graph traversal rather than text comparison
- Foundation for future physics simulation (Phase H+)

### Negative
- Graph construction adds overhead per scene (mitigated: built once per production, cached)
- Edge types must be curated; missing edge type = silent incorrect inference
- `production_world_graphs` table can grow large for complex productions

### Not changing
- `AssetModule` (ADR-002) — `WorldGraph` is built *from* it, not replacing it
- `AssetDependencyGraph` (ADR-006) — serves a different purpose, both coexist
- `WorldStateEngine` (ADR-006) — extended to accept `WorldGraph` parameter

---

## References

- ADR-002: AssetModule + AssetDefinition + AssetInstance (WorldGraph reads these)
- ADR-005: Persistence (production_world_graphs table)
- ADR-006: WorldStateEngine (uses WorldGraph for propagation), AssetDependencyGraph (coexists)
- ADR-009: KnowledgeOS (uses WorldGraph topology for ontology inference)
