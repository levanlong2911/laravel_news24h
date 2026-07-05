<?php

namespace App\Services\AI\AFOS\Passes\Config;

use App\Services\AI\AFOS\Passes\PassParameterSchema;

/**
 * CompositionPassConfig — typed configuration for ShotGoalToCompositionPass implementations.
 *
 * Experience Engine tunes these values, not the DirectorProfile.
 * DirectorProfile describes the director's creative identity (immutable per shot).
 * CompositionPassConfig describes the optimizer's current best parameters (mutable).
 *
 * negativeSpaceBias: additive offset to director.negativeSpaceWeight.
 *   DirectorProfile says "I prefer 0.55 negative space."
 *   Experience Engine says "add 0.10 — high-QA luxury shots used more."
 *   Effective = min(1.0, 0.55 + 0.10) = 0.65.
 *
 * goldenRatioBias: when ≥ 0.5, forces GOLDEN_RATIO composition rule regardless
 *   of other signals. Experience Engine learns whether golden ratio outperforms
 *   rule-of-thirds for the target domain.
 *
 * attentionWeight: reserved for Phase B AttentionPass. Carried here so Experience
 *   Engine can start logging observed values before the pass is implemented.
 */
final class CompositionPassConfig
{
    public function __construct(
        public readonly float $negativeSpaceBias = 0.0,
        public readonly float $goldenRatioBias   = 0.0,
        public readonly float $attentionWeight   = 0.5,
    ) {}

    public static function defaults(): self
    {
        return new self();
    }

    /** @return PassParameterSchema[] */
    public static function schema(): array
    {
        return [
            new PassParameterSchema(
                'negativeSpaceBias', 'float', 0.0, 0.5, 0.0,
                'Additive offset to director.negativeSpaceWeight. Experience Engine learns the optimal bias per domain.'
            ),
            new PassParameterSchema(
                'goldenRatioBias', 'float', 0.0, 1.0, 0.0,
                'Force GOLDEN_RATIO composition rule when ≥ 0.5. Tuned by Experience Engine per emotion/domain pair.'
            ),
            new PassParameterSchema(
                'attentionWeight', 'float', 0.0, 1.0, 0.5,
                'Reserved for Phase B AttentionPass. Logged from Phase A for baseline collection.'
            ),
        ];
    }

    public function toArray(): array
    {
        return [
            'negativeSpaceBias' => $this->negativeSpaceBias,
            'goldenRatioBias'   => $this->goldenRatioBias,
            'attentionWeight'   => $this->attentionWeight,
        ];
    }
}
