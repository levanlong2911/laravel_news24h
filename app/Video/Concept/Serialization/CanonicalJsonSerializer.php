<?php

declare(strict_types=1);

namespace App\Video\Concept\Serialization;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Schema\EffectiveConceptSchema;

interface CanonicalJsonSerializer
{
    public function serialize(
        CanonicalDesignSpec $spec,
        EffectiveConceptSchema $schema,
    ): string;
}
