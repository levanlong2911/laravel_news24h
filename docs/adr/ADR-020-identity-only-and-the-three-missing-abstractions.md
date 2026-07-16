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

### 9.1 The order is also about what the next measurement can mean

**Persistent Context precedes Coverage not only because it is smaller and already has a consumer, but
because it removes a known source of measurement noise.** The current coverage score (13/17)
conflates at least two mechanisms: facts that cannot be selected because required ambient entities
are never considered present, and the policy's own distribution decisions. Until the former is
removed, the benchmark cannot attribute the remainder to Coverage Policy with confidence.

**This asymmetry is intentional.** The focus residual is already informative: its remaining failures
do not depend on location persistence and therefore continue to point at missing event semantics
(§4.1 stands). The coverage residual is not yet interpretable in the same way, because part of it is
explained by a known upstream modelling limitation.

> **Not every metric ripens at the same time.** A residual may only be used to design the next module
> once known sources of noise have been removed from it.

So the roadmap is not decided by which module is smallest. It is decided by **which module makes the
next measurement mean something.** Building Coverage now would tune a policy against a number that is
partly measuring a different bug.

---

## 10. The loop this established

Wider than Phase 1A, and the reason the ADRs came before the code:

```
   Boundary
      ↓
   Implementation
      ↓
   Measurement
      ↓
   Remove known noise        §10.2 — without this the rest is not residual analysis
      ↓                             but error aggregation
   Residual analysis         §10.1 — reproducible; a projection onto the feature
      ↓                             space the model already has
   Vocabulary extension      §10.1 — not automatable; it CHANGES that space
      ↓
   New abstraction
      ↓
   (Boundary again)
```

The two middle steps are not two grades of the same work — they are **different operations**.
Residual analysis *projects* onto the existing feature space; vocabulary extension *changes* the
space. The gap between them is the gap between *"these two failures are alike"* and *"these two
failures are alike along a dimension the model has no word for"*.

> **Do not optimise what is working. Model what the residual — after known noise is removed — has in
> common.**

A metric answers *how good is it*. A residual answers *what must be built next*. They are not the
same instrument, and only one of them points anywhere.

Had Phase 1A returned only "10/12", the reflex would have been to tune the policy: add a heuristic,
shift a weight. Because it returned *"both failures are motion"*, the answer is that **no policy can
fix it** and an abstraction is missing. Same run, same zero cost, two roadmaps that share nothing.

This is also what the session that produced ADR-019 got wrong first: seeing a bad prompt and reaching
for the formatter. The formatter was working. The residual shared one shape — **information discarded
upstream and re-invented downstream** — and that is what had to be modelled.

> **A new abstraction is justified not when we can imagine it, but when the residual repeatedly
> demands a dimension the current language cannot express.**

"Never before" is the rule. That sentence is why the rule exists — which is the part that survives
contact with an abstraction nobody has thought of yet.

### 10.3 The gate

Any proposal for a new module answers three questions before it is accepted:

1. **Which residual does it explain?**
2. **Has that residual been causally isolated?** (§10.2 — otherwise it is error aggregation)
3. **Is the new dimension genuinely inexpressible in the current ontology?**

Fail any one and the module has not earned its existence yet. Note that none of the three names
Event Model, Persistent Context or Coverage: when the roadmap turns to Temporal Context, an Intent
Model, or something unnamed today, the questions are unchanged.

**Question 3 is the one that bites**, and it separates two things this ADR had been treating as one:

| | Residual | Question 3 |
|---|---|---|
| **Event Model** | focus misses | *motion* is genuinely absent from the ontology — **inexpressible** |
| **Persistent context** | unshowable location facts | **expressible today** — an author can simply list the location in every beat's `scene_nodes` |

So persistent context may not be a new abstraction at all. The ontology can already say it; it can
only say it by **filing an identity fact inside a staging structure**, which §3 forbids. That is a
*boundary violation*, not an expressiveness gap.

**But note exactly how far that gets, because it is easy to take one step too many.** Question 3
refutes **new vocabulary**. It does not refute **new mechanism** — those are different layers, and
both of these pass the gate:

| Remedy | New words? | New mechanism? |
|---|---|---|
| *"a location active at one beat stays active until replaced"* | no | no — a **boundary invariant** |
| *"compute the scene's ambient closure before selection runs"* | no | yes — a **runtime computation** |

Neither adds an entity type, an enum, or a concept. One is a rule; the other is machinery. **The gate
cannot choose between them**, and reading "no new abstraction" as "no new mechanism" is a conflation
the gate does not license.

So the opening question of the next phase is narrower than a design — it is a question about *where*:

> **Is location persistence an invariant of the boundary, or a computation of the runtime?**

The answer decides which document moves. A boundary invariant changes **ADR-019**. A runtime
computation leaves the ontology untouched and produces an implementation.

Which is the gate working on its first use: it did not approve a module, it asked whether one was
needed — and then declined to answer the next question too. §9's ordering stands either way; the work
still comes first because it still removes the noise. What changes is its expected size.

### 10.4 The gate is a proof obligation, not a checklist

The failure above generalises, so state it as a rule rather than an anecdote. The gate is not *"three
Yes answers and you may build"*. It is:

> **To claim anything further, name the question that warrants it.**

It does not forbid inference; it requires each step to show what guarantees it. Applied to §10.3:
Q3 warranted *"no new vocabulary is needed"*. **No question warranted** *"and therefore no new
mechanism"* — so that claim fell, and it fell one commit after the gate was written, having been made
by the person who wrote it.

The reason is that the gate answers exactly one **class** of decision:

| Decision | Question | Warranted by the gate? |
|---|---|---|
| **Ontology** — does the system need a new concept to describe the world? | Q3 | **yes** |
| **Execution** — where and when should it be computed? | — | **no; the gate is silent** |

Silence is not permission and it is not prohibition. It means the claim needs a different argument.

### 10.5 A general question is free; a general answer is not

Ask the widest question that does not smuggle in an answer. For the next phase that is not *"how do
we persist locations"* — which has already assumed both the problem and its shape — but:

> **What is the semantic lifetime of an entity once it has become active?**

Nothing in that adds vocabulary, and it survives the residual turning out to be camera ownership,
active participants, or something unnamed rather than location.

**But breadth of question does not license breadth of answer.** One measured residual is one use
case. Answering *"semantic lifetime"* with a mechanism general enough to cover weather and emotional
state would break the standing rule against abstraction without three real cases — and would do it
while sounding principled, which is the dangerous version. **Ask wide; answer only as wide as the
evidence.**

The sequence, then, is semantics before location and location before machinery:

1. Which residual is being **de-noised**? (§10.2 — not "which feature is being fixed")
2. Does the missing information **already exist** in the ontology? (Q3)
3. If it does, what is its **semantic lifetime**?

Only after (3) does *"boundary invariant or runtime computation"* become a design question at all.
Ordering it earlier lets implementation answer a question semantics has not finished asking.

### 10.1 Residual analysis is not clustering

**Residual analysis should be reproducible. Residual *interpretation* cannot be fully automated.** An
inspector may cluster failures only along dimensions **already present in the current model**.
Discovering that every member of a cluster shares a property the model **cannot yet express** is
precisely the evidence that a new abstraction is required.

This is a limit of expressive power, not a caution about tooling. If an inspector could group by
*motion*, then motion would already be a dimension of the model — the Event Model would exist and the
residual would not be a residual. **The step that cannot be automated is the one that supplies a
dimension the data does not have**, and it cannot be automated by construction.

| Role | Can do |
|---|---|
| Inspector | cluster along dimensions the model already has |
| Architect | recognise that a wholly new dimension is needed |

Worked through: nothing in the Article Model knows what *moves*. But an inspector can still report,
from data alone and identically for any two people who run it — *"policy chose the topic entity; the
author chose a different entity; that entity co-occurs with the topic inside one fact"*. That narrows
twelve beats to two and proves they share a structure. A person then looks at those two and says
*"both are the thing in flight"* — supplying the dimension. The inspector did not fail; it did the
only half that is mechanisable.

### 10.2 A reproducible cluster is not automatically a valid abstraction

**Residuals must first be purged of known upstream noise.** Otherwise the inspector may faithfully
cluster failures produced by several mechanisms at once, yielding a **stable but misleading**
signature — and a stable wrong answer is worse than a noisy one, because it survives review.

```
known noise → REMOVE → residual → cluster → human abstraction
```
never
```
                      residual → cluster → human abstraction
```

Dropping the first step costs the pipeline its causality. This is why §9.1 and §10 are one argument:
**improving the lens before cleaning the data only makes the contamination easier to see, not easier
to understand.**

It also sets a standard for the benchmark itself that this ADR had not previously named. Reproducible
and deterministic are not sufficient. A benchmark must be **causally isolating**: each residual must
correspond to *one* unmodelled mechanism, not a mixture of several. Without that it answers *"several
things are wrong here"* — true, and useless — instead of **"this is what to build next"**, which is
the only question worth running it for.

And it gives the loop a self-check with teeth: **analysing a residual that still contains known causes
is not residual analysis. It is error aggregation.** By that standard the coverage figure in §9.1 is
not yet admissible evidence for anything, and saying so costs nothing today and saves a wrong module
later.
