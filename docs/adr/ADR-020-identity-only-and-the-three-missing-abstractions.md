# ADR-020 — Identity-only selection, and the three abstractions the benchmark found missing

**Status:** Accepted. Phase 1A is measured and committed (`484461a`); what follows is deferred with reasons.
**Date:** 2026-07-16
**Builds on:** ADR-019. Nothing here overturns it.

Like ADR-019, this records **boundaries and evidence**, not classes. Class names will churn; what
Phase 1A *measured* will not.

---

## 1. What Phase 1A was — and was not

**Policy validation with a human-authored Article Model.** It answers exactly one question:

> If the Article Model is correct, can a policy choose what a person chose?

It does **not** answer whether extraction works. The Article Model was written by a person, and that
person has already been wrong on record (the `hero_moment.description` reasoning that did not
generalise). Coupling the two would mean a failure blames the wrong one.

The split is itself the test of ADR-019's boundary: **if replacing the human author with a machine
changes the policy by zero lines, the boundary was drawn correctly.**

---

## 2. The numbers — and why they are smaller than they look

```
focus agreement with author   10/12
coverage of selectable facts  13/17
origin   shot_truth 100%   default_semantics 0%
```

**10/12 is inflated, and the code says so.** `supercar`'s hook agrees by accident: the topic entity
was absent from the beat, so the policy fell back to *"hold whatever is present"* and happened to
land on the author's choice. That is a refusal to invent a target, not reasoning. Any future report
that quotes 10/12 without this sentence is quoting folklore.

**Know the sample before quoting the number.** Twelve beats, four scenarios, **all authored by one
person**. On the current benchmark, identity alone accounts for approximately four fifths of the
*observed selection agreement* — that is a measurement on this data, not a property of the system,
and production data may well move it. Read the second decimal place of nothing here.

**The real result is the shape of the residue, which does not depend on the ratio.** Selection used
nothing but identity — `Article Model` + who is on screen — and both failures fell in the same place,
and that place was not identity. Had they scattered, identity would be suspect. Had there been none,
the missing model below would still be invisible. Two failures landing together on *motion* is what
carries the finding; four-fifths is only how many were left over.

> **On this evidence the Entity Model looks like the floor rather than a redundant layer.** What comes
> next covers the residue rather than replacing it. A new module that negates the old one is a
> redesign; one that covers what the old one provably cannot is an architecture.

---

## 3. The principle Phase 1A established

> **Identity is not staging.**

| Statement | Scope | Belongs to |
|---|---|---|
| the article is about Moonrise | article | Article Model (`topic_entity`) |
| F6 describes Nebula | article | Article Model (`entity_refs`) |
| Nebula appears only at the payoff | shot | Selection / staging |
| the payoff holds the football | shot | Selection |

This is what makes `topic_entity` and `entity_refs` legal inside the Article Model — they are true in
every shot, including the shots their entity is absent from. It is also what keeps `preferred_beat`,
`visible_from` and `recommended_shot` **out**: those are selection baked into the data, and data that
contains the answer cannot measure a policy.

It also names why `topic_entity` is not called a subject or a focus: `focus` is taken and
shot-scoped, and the two legitimately disagree — an article whose topic is the quarterback ends on a
shot that holds the football.

---

## 4. Three abstractions the benchmark found missing

None of these were predicted from a whiteboard. Each was produced by a measurement that cost nothing.

### 4.1 Event Model — focus is underdetermined without it

Both focus misses say one thing:

| | policy | author | what the author actually followed |
|---|---|---|---|
| `glacier` escalation | `glacier_obj` | **`sun_obj`** | the light line, which is what *moves* |
| `nfl` payoff | `qb_obj` | **`football_obj`** | the ball, which is what *flies* |

The Article Model has entities and facts and **no action structure**. Nothing in it says a throw has
a target. Payoff focus is therefore **mathematically underdetermined** — every policy must guess, and
a better policy cannot fix it. **The finding is about the model.**

**Role would not fix it either**, which is the useful part: `glacier` wants the **actor**, `nfl` wants
the **thing thrown**. Opposite roles. What both share is **motion** — saliency, an Event Model's
business, not a grammar's.

### 4.2 Participants / role — deferred, with the reason

`entity_refs` loses relation: *"the silver car overtakes the orange car"* and *"the orange car is
followed by the silver car"* annotate identically. Real, and Phase 2 will feel it.

But adding `role` today is **a field before its consumer** (ADR-019 rule 7; the `estimatedValue`
lesson). Nothing reads it, and §4.1 shows its shape is **not yet known to be right**. Designing a
contract without its consumer is how you get a contract the consumer cannot use.

**`entity_refs` is therefore PROVISIONAL.** It answers exactly one question — *which entities does
this fact describe* — and deliberately nothing about causality, action, attention or saliency. It is
a benchmark annotation and one internal value object: changing it later costs four JSON files and one
class. Design `participants` **together with** the Event Model.

### 4.3 Persistent context — locations do not stage

```
nfl  F1  "scoreboard under stadium lights"  needs stadium_obj    -> never in scene_nodes -> unshowable
supercar hook                                                     -> films nothing at all
```

The stadium is obviously present in every beat. The expressway is obviously present — the cars are
driving on it. The author simply never re-listed them, because **a location is not staged: it is
where the scene IS.** Treating one like an actor makes facts about it permanently unfilmable.

And it is the same rule a third time: **the presence of a location is identity, not staging.**

Unlike §4.1 and §4.2 this one **has a consumer today** — it directly fixes both failures above — so it
is not speculative. It is deferred only to keep Phase 1A a measurement rather than an optimisation.
The likely shape is a visibility class (`REQUIRED` / `OPTIONAL` / `AMBIENT`) so that ambient entities
need not be carried into frame by an actor.

---

## 5. Coverage — measured, never implemented

```
moonrise   coverage 5/5 (100%)   said in every beat: F1, F2, F3, F4
```

**100% coverage is the failure mode.** Full marks while every beat says the same four facts means the
policy is not selecting, it is dumping. **Coverage alone is a bad metric**; it is only readable beside
repetition.

This is ADR-019 §6.3's prediction with a number against it, produced by a baseline that was written
*without* anti-starvation precisely so the cost of its absence could be seen. The measurement is what
earns Coverage its implementation — the policy did not get to fix itself on the way past.

**Coverage's denominator is SELECTABLE facts, never all facts.** A fact with no visual hint, or of low
relevance, *should* go unused — "has never been chartered" is a fact about paperwork. Counting it
would report a starvation that is correct behaviour.

---

## 6. Traceability

`origin ∈ {shot_truth, default_semantics}` ships now. It is **evidence, not metadata**: it makes
ADR-019 §7 ("no layer has the right to invent") checkable on a concrete prompt instead of only
true-if-the-code-is-right. A line reading *"five radar domes"* with `origin = default_semantics` is a
self-proving bug.

`confidence` and `selected_by` **wait for Phase 2**. Today they have exactly one value each —
`1.0` and `HumanSelection` — and a field with one value is a field with no reader.

> **Phase 1 admits metadata that changes behaviour or verifiability.**
> **Phase 2 admits metadata that evaluates the extractor.**

`origin` currently reports 100% `shot_truth` and **cannot yet be measured**: `default_semantics` has
no route in, because Precedence needs phrasebook *tokens* and the semantic-tier planners still emit
prose (ADR-019 §1, finding 3). The KPI arrives when they are converted.

---

## 7. Statement of intent

> Phase 1A intentionally models **identity only**. Event semantics, persistent ambient context, and
> coverage optimisation are explicitly deferred because **the benchmark demonstrates they require
> independent abstractions rather than incremental annotations**.

That last clause is the whole value of Phase 1A. The three gaps were not argued into existence; each
was produced by a measurement, for free, before a line of production code was touched or a cent was
spent on a render.

---

## 8. Invariant added to ADR-019 §9

| Layer | Owns | Must never |
|---|---|---|
| **Selection** | subsetting the Article Model | **parse natural language** from facts, actions or prompts |

Enforced structurally, not by review: `ArticleFact` carries no `text`. Selection cannot read English
because it is never handed any. This is the fence that stops the Phase 2 extractor leaking backwards —
the day someone writes `str_contains($fact->text, 'support vessel')` inside a policy, the extractor has
moved into Selection wearing a disguise, and Phase 1A's separation is gone.

---

## 9. Order of work

1. **Persistent context** — has a consumer, fixes two measured failures, smallest of the three.
2. **Event Model** — the only route to focus prediction; `participants`/`role` designed *with* it.
3. **Coverage** — last: it optimises a selection that must first be correct.

Then Phase 2 (extraction), where the boundary gets its real test.
