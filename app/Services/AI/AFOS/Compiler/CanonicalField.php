<?php

namespace App\Services\AI\AFOS\Compiler;

use App\Services\AI\AFOS\Ir\SemanticState;
use Closure;

/**
 * CanonicalField — one typed dimension of semantic identity.
 *
 * SemanticHashPolicy holds a list of CanonicalFields. Each field knows:
 *   - $key:        the canonical key name used in the hash payload
 *   - $extractor:  fn(SemanticState): mixed — reads the raw value
 *   - $normalizer: fn(mixed): mixed — optional normalization before hashing
 *                  (e.g. lowercase, rounding focal length to nearest 5mm)
 *
 * Adding a new semantic dimension (e.g. "weather"):
 *   $policy->withField(new CanonicalField('weather', fn($s) => $s->weather));
 *
 * The Hasher never needs to know which fields exist — it just iterates.
 */
final class CanonicalField
{
    public function __construct(
        public readonly string   $key,
        private readonly Closure $extractor,
        private readonly ?Closure $normalizer = null,
    ) {}

    public function extract(SemanticState $state): mixed
    {
        $value = ($this->extractor)($state);
        return $this->normalizer ? ($this->normalizer)($value) : $value;
    }
}
