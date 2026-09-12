<?php

declare(strict_types=1);

namespace App\Video\Concept\Processing;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Schema\EffectiveConceptSchema;
use InvalidArgumentException;

final class ProcessedCanonicalConcept
{
    /**
     * @param  list<ValidationStageReport>  $validationReports
     */
    public function __construct(
        public readonly CanonicalDesignSpec $spec,
        public readonly string $canonicalJson,
        public readonly EffectiveConceptSchema $effectiveSchema,
        public readonly array $validationReports = [],
    ) {
        if ($this->canonicalJson === '') {
            throw new InvalidArgumentException(
                'canonicalJson must not be empty.'
            );
        }
    }
}
