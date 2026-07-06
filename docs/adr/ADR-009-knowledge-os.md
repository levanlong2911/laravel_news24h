# ADR-009: KnowledgeOS / Ontology Engine

**Status:** Draft  
**Date:** 2026-07-06  
**Deciders:** Project Lead  
**Depends on:** ADR-002, ADR-003, ADR-006, ADR-008  
**Planned phase:** Phase G (after Phase B–F complete)

---

## Context

All current ADRs treat knowledge as **data**:
- `StyleBible` = arrays of allowed values ("focal length: 24mm, 35mm, 50mm")
- `CharacterDefinition` = string fields ("tall, athletic build, navy suit")
- `AssetDefinition` = string descriptions
- `FilmKnowledgeBase` (ADR-006) = reference examples from director/cinematographer history

Data is searchable but not **inferrable**. The system can retrieve facts but cannot
deduce implicit consequences.

Examples of what is currently impossible without an ontology:

| Request | Problem |
|---------|---------|
| "Character draws sword" | System doesn't know: Hand must be visible, sleeve must not cover wrist, lighting must illuminate foreground |
| "Character wears trench coat" | System doesn't know: Coat IS-A Covering, covers torso + legs, hides any holster/weapon underneath |
| "Coffee cup falls" | System doesn't know: Cup IS-A Container with state liquid=coffee, falling triggers SPILL, liquid propagates to Floor |
| "Scene is nighttime" | System doesn't know: NaturalLight is absent, ArtificialLight must compensate, exposure settings change |
| "Character is injured (arm)" | System doesn't know: Arm movement is constrained, holding weapons with that arm is impossible, costume may need blood FX |

Without ontology, AI prompts receive raw descriptions and must infer all of this themselves.
With ontology, FilmOS can **pre-compute** implicit constraints and enrich `PlanningContext`
before any AI call is made.

This is the difference between:
- *"The character is wearing a navy suit"* (data)
- *"The character is wearing a navy suit, which is a Clothing covering Torso+Legs+Arms, therefore the chest holster (if any) is not visible, and hand/wrist RenderContext must include the suit cuffs"* (inference)

---

## Decision

Introduce `KnowledgeOS`: a lightweight domain ontology engine for film production.
This is **not** a general-purpose reasoner (not OWL, not RDF). It is a
production-specific ontology that encodes filmmaking knowledge as typed
class hierarchies + property relationships + inference rules.

`KnowledgeOS` sits below `FilmOS` and above `Persistence`. It is a read-mostly service
that any FilmOS component can query for implicit facts.

---

## Architecture

```
FilmOS Components (ConstraintEngine, RenderContext builder, CharacterBrain...)
        │
        ▼
   KnowledgeOS (query interface)
        │
        ├── OntologyRegistry   (class hierarchy)
        ├── PropertyRegistry   (relationships between classes)
        ├── InferenceEngine    (derive implicit facts)
        └── FilmKnowledgeBase  (director/cinematographer examples — ADR-006 merged here)
```

---

## Core Types

### OntologyClass

A class in the ontology hierarchy:

```php
namespace App\Services\AI\FilmOS\KnowledgeOS;

final class OntologyClass
{
    public function __construct(
        public readonly string  $classId,       // 'clothing', 'weapon', 'light_source', ...
        public readonly string  $label,
        public readonly ?string $parentClassId,  // null = root class
        public readonly array   $properties,     // OntologyProperty[]
        public readonly array   $tags,           // ['physical', 'consumable', 'wearable', ...]
    ) {}
}
```

Example hierarchy:

```
PhysicalObject
├── Clothing
│    ├── OuterGarment
│    │    ├── Coat        (covers: torso, legs, arms)
│    │    └── Jacket      (covers: torso, arms)
│    ├── InnerGarment
│    │    └── Shirt       (covers: torso, arms)
│    └── Accessory
│         ├── Glove       (covers: hand)
│         ├── Hat         (covers: head)
│         └── Badge       (attached_to: outer_garment, visible: true)
├── Weapon
│    ├── Firearm
│    │    ├── Pistol      (held_by: hand, concealable: true)
│    │    └── Rifle       (requires: both_hands, not_concealable)
│    └── MeleeWeapon
│         ├── Knife       (held_by: hand, size: small)
│         └── Sword       (held_by: hand, size: large, requires: unobstructed_arm)
├── Container
│    ├── Rigid            (cup, box, case)
│    └── Flexible         (bag, sack)
├── Vehicle
│    ├── GroundVehicle
│    │    ├── Car
│    │    └── Motorcycle  (requires: balanced_stance)
│    └── AerialVehicle
LightSource
├── NaturalLight
│    ├── Sunlight         (direction: depends_on_time_of_day)
│    └── Moonlight        (intensity: low, color_temp: cool)
└── ArtificialLight
     ├── Lamp
     └── Fire             (flicker: true, color_temp: warm)
```

### OntologyProperty

A semantic relationship between two classes:

```php
final class OntologyProperty
{
    public function __construct(
        public readonly string          $propertyId,     // 'covers', 'hides', 'requires', ...
        public readonly string          $domain,         // class this property applies to
        public readonly string          $range,          // class or value this property points to
        public readonly PropertyType    $type,           // IS_A, HAS_PART, COVERS, HIDES, REQUIRES, etc.
        public readonly bool            $isInherited,    // subclasses inherit this property?
    ) {}
}

enum PropertyType: string
{
    case IS_A          = 'is_a';
    case HAS_PART      = 'has_part';         // Body has_part Hand
    case COVERS        = 'covers';           // Glove covers Hand
    case HIDES         = 'hides';            // Coat hides Holster
    case REQUIRES      = 'requires';         // Sword requires unobstructed_arm
    case ENABLES       = 'enables';          // Key enables Door[open]
    case INTERACTS_WITH = 'interacts_with';  // Fire interacts_with Flammable
    case PRODUCES      = 'produces';         // Fire produces Light, Smoke
    case CONSTRAINS    = 'constrains';       // Injury constrains BodyPart[movement]
}
```

### InferenceEngine

The core reasoning component:

```php
interface InferenceEngine
{
    /**
     * Given a fact (e.g., "character holds Pistol"),
     * return all implied facts that are not explicitly stated.
     *
     * @return InferredFact[]
     */
    public function infer(Fact $fact, OntologyRegistry $ontology): array;

    /**
     * Given a ConstraintReport draft, enrich it with
     * violations derivable from ontology (e.g., "Sword requires unobstructed_arm
     * but character has InjuryLevel::SEVERE on arm").
     */
    public function enrichConstraints(
        ConstraintReport $report,
        CharacterState   $state,
        WorldGraph       $graph,
        OntologyRegistry $ontology,
    ): ConstraintReport;
}
```

### InferredFact

```php
final class InferredFact
{
    public function __construct(
        public readonly string      $subject,        // what the fact is about
        public readonly string      $predicate,      // what is true
        public readonly mixed       $object,         // what value
        public readonly float       $confidence,     // 0.0–1.0
        public readonly string      $derivedFrom,    // which ontology rule produced this
        public readonly FactImpact  $impact,         // RENDER_CONTEXT, CONSTRAINT, PLANNING, PROMPT
    ) {}
}

enum FactImpact: string
{
    case RENDER_CONTEXT = 'render_context';  // affects what RenderContext sends to backend
    case CONSTRAINT     = 'constraint';      // may produce a ConstraintViolation
    case PLANNING       = 'planning';        // informs PlanningContext enrichment
    case PROMPT         = 'prompt';          // should be added to PromptIR
}
```

---

## Inference Examples

### Example 1: Character draws sword

Input facts:
- `Character.holds(Sword)`
- `Character.arm_injury_level = 40` (moderate)

Ontology rules:
- `Sword IS-A MeleeWeapon`
- `MeleeWeapon REQUIRES unobstructed_arm`
- `Sword REQUIRES both_hands = false` (one-handed)

Inferred facts:
```
→ hand[dominant] must be visible in frame       [impact: RENDER_CONTEXT]
→ sleeve must not obscure wrist                  [impact: RENDER_CONTEXT]
→ lighting must illuminate foreground            [impact: PLANNING]
→ arm_injury_level=40 does NOT block sword use   [confidence: 0.9] [impact: CONSTRAINT]
→ BUT: grip may appear strained                  [impact: PROMPT, confidence: 0.7]
```

### Example 2: Character wears coat

Input facts:
- `Character.wears(TrenchCoat)`
- `Character.holster(Pistol) = true`

Ontology rules:
- `TrenchCoat IS-A Coat IS-A OuterGarment`
- `OuterGarment COVERS torso, legs, arms`
- `OuterGarment HIDES all_inner_accessories`

Inferred facts:
```
→ Pistol holster is NOT visible                  [impact: RENDER_CONTEXT, confidence: 1.0]
→ Body shape is obscured by coat                 [impact: RENDER_CONTEXT]
→ Badge (if any) is NOT visible unless on coat   [impact: CONSTRAINT, confidence: 1.0]
→ Movement is slightly restricted                [impact: PROMPT, confidence: 0.6]
```

### Example 3: Night scene + character reads document

Input facts:
- `Scene.time_of_day = NIGHT`
- `Character.action = reads(Document)`

Ontology rules:
- `NIGHT → NaturalLight is ABSENT`
- `Reading REQUIRES sufficient_light_on(Document)`

Inferred facts:
```
→ ArtificialLight must be present near Document  [impact: PLANNING, confidence: 1.0]
→ Exposure: ISO high, aperture wide              [impact: RENDER_CONTEXT, confidence: 0.9]
→ LightingBible night_interior rule applies      [impact: PLANNING]
→ Shadow on face is expected (noir aesthetic)    [impact: PROMPT, confidence: 0.7]
```

---

## Integration with FilmOS Components

### 1. ConstraintEngine enrichment

After `ConstraintEngine` runs its 8 built-in constraints, `InferenceEngine.enrichConstraints()`
adds ontology-derived violations:

```php
// In ConstraintEngine.validate()
$report = $this->runBuiltinConstraints($scene, $bible);
$report = $this->inferenceEngine->enrichConstraints($report, $characterState, $worldGraph, $ontology);
return $report;
```

### 2. RenderContext enrichment

`RenderContextBuilder` queries `KnowledgeOS` for implied visible assets:

```php
// In RenderContextBuilder
$inferredFacts = $this->inference->infer(
    new Fact($characterId, 'wears', $costume->classId),
    $this->ontologyRegistry,
);

foreach ($inferredFacts as $fact) {
    if ($fact->impact === FactImpact::RENDER_CONTEXT) {
        $renderContext = $renderContext->withImpliedFact($fact);
    }
}
```

### 3. PlanningContext enrichment

`PlanningContextBuilder` uses ontology to auto-populate `VisualContext`:

```php
// Night scene → automatically set LightingContext parameters
$lighting = $this->inference->infer(
    new Fact('scene', 'time_of_day', $scene->timeOfDay),
    $this->ontologyRegistry,
);
// lighting facts → VisualContext.lightingContext defaults
```

---

## Ontology Loading

Ontology is loaded from PHP config files (not a database — it changes only
with code deployments, not with production data):

```php
// config/filmos_ontology.php
return [
    'classes' => [
        ['classId' => 'clothing',     'parentClassId' => 'physical_object', ...],
        ['classId' => 'outer_garment','parentClassId' => 'clothing', ...],
        ['classId' => 'coat',         'parentClassId' => 'outer_garment',
         'properties' => [
             ['type' => 'covers',  'range' => ['torso', 'legs', 'arms']],
             ['type' => 'hides',   'range' => ['inner_accessories']],
         ]],
        // ...
    ],
];
```

`OntologyRegistry` is a singleton service loaded at boot — no DB queries at runtime.

---

## KnowledgeOS vs FilmKnowledgeBase (ADR-006)

| | `FilmKnowledgeBase` (ADR-006) | `KnowledgeOS` (ADR-009) |
|---|---|---|
| Content | Reference examples: Kubrick shots, Deakins lighting | Semantic ontology: classes, properties, rules |
| Purpose | Inspiration + style reference | Constraint inference + context enrichment |
| Format | Retrieved examples (text + metadata) | Logic rules (class hierarchy + properties) |
| Query | "Show me Fincher's low-key lighting examples" | "What does wearing a coat imply for rendering?" |
| Updates | New examples added continuously | Updated only when domain model changes |

Both exist. ADR-006 `FilmKnowledgeBase` feeds creative inspiration.
ADR-009 `KnowledgeOS` feeds logical inference. They are complementary.

---

## Directory Structure

```
app/Services/AI/FilmOS/KnowledgeOS/
├── KnowledgeOS.php                 (facade: single query interface)
├── OntologyRegistry.php
├── OntologyClass.php
├── OntologyProperty.php
├── InferenceEngine.php             (interface)
├── DefaultInferenceEngine.php      (default impl — forward chaining)
├── Fact.php
├── InferredFact.php
├── Enums/
│   ├── PropertyType.php
│   └── FactImpact.php
└── Ontology/
    ├── PhysicalObjectOntology.php  (Clothing, Weapon, Container, Vehicle)
    ├── LightSourceOntology.php
    ├── BodyOntology.php             (Body has_part Head/Torso/Arm/Hand/Leg)
    └── NarrativeOntology.php        (Scene, Action, Emotion relationships)
```

---

## Consequences

### Positive
- `ConstraintEngine` catches implicit violations (hiding weapon under coat = invisible weapon) automatically
- `RenderContext` includes visibility inferences without explicit shot descriptions
- Prompt quality improves: prompts include implied visual consequences
- Foundation for future natural-language reasoning (Phase H+: "the gun falls → what changes?")
- Very few AI filmmaking platforms have an ontology layer — significant competitive advantage

### Negative
- Ontology must be curated by domain experts; incorrect rules produce incorrect inferences
- Forward-chaining can be slow for deeply nested hierarchies (mitigated: max depth = 4)
- `OntologyRegistry` must be versioned alongside `ProductionBible` — ontology changes may invalidate prior inferences

### Not changing
- AFOS Compiler — ontology inferences are consumed by `RenderContext`, not by AFOS
- `FilmKnowledgeBase` (ADR-006) — coexists, serves different purpose
- `Bible` classes (ADR-002/003) — ontology adds inference, does not replace data

---

## References

- ADR-002: CharacterDefinition, AssetDefinition (ontology classifies these)
- ADR-003: StyleBibles (ontology knows which LightingBible rules apply in which context)
- ADR-006: FilmKnowledgeBase (complementary system), WorldStateEngine (uses ontology for state rules)
- ADR-008: WorldGraph (topology feeds ontology inference)
- ADR-010: Decision Engine (ontology-enriched RenderContext → better quality scores)
