<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark\Selection;

/**
 * Why a selectable fact never reached any shot — as ATTRIBUTION, not as cause.
 *
 * The distinction is the whole point and it is easy to lose. `ENTITY_NEVER_STAGED`
 * is derived from the data: this entity appears in no beat. It does NOT prove *why*
 * it was never staged — that would be a causal claim, and this layer discharges
 * benchmark validity only (ADR-020 §10.4: an obligation may only be discharged by
 * the evidence that belongs to it).
 *
 * So these are not "reasons". They are classes an attribution falls into, each
 * computable from the Article Model and the beat contexts alone, identically for
 * anyone who runs it.
 */
enum AttributionClass: string
{
    /** Some entity the fact describes appears in no beat at all. Upstream of everything else. */
    case ENTITY_NEVER_STAGED = 'entity_never_staged';

    /** Every entity appears somewhere, but no single beat holds all of them at once. */
    case ENTITIES_NEVER_CO_PRESENT = 'entities_never_co_present';

    /** The fact WAS filmable in at least one beat and the policy did not take it. */
    case POLICY_DECLINED = 'policy_declined';

    /**
     * A partial order over the classes: lower is further upstream.
     *
     * This is an INVARIANT, not a preference, and it lives here as data rather than
     * as the order of `return` statements in the attributor — because reordering
     * those would silently make the benchmark discharge a different obligation, and
     * nothing would fail.
     *
     * The rule it encodes: **a downstream attribution may never mask an upstream
     * one.** If an entity appears in no beat, whether the beats could have held the
     * set together is a moot question, and answering it would report a fact as
     * blocked by co-presence when it was blocked before co-presence could apply.
     */
    public function precedence(): int
    {
        return match ($this) {
            self::ENTITY_NEVER_STAGED       => 0,
            self::ENTITIES_NEVER_CO_PRESENT => 1,
            self::POLICY_DECLINED           => 2,
        };
    }

    /**
     * Is this class evidence about the policy, or about the data feeding it?
     *
     * Only POLICY_DECLINED carries coverage signal. The other two are known noise:
     * a coverage score that includes them is measuring the benchmark's staging as
     * much as the policy's distribution, which is what makes it non-isolating.
     */
    public function isCoverageSignal(): bool
    {
        return $this === self::POLICY_DECLINED;
    }
}
