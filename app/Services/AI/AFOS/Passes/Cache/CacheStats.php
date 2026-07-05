<?php

namespace App\Services\AI\AFOS\Passes\Cache;

/**
 * CacheStats — aggregate hit/miss/size counters for one CompilerCache instance.
 */
final class CacheStats
{
    public readonly float $hitRate;

    public function __construct(
        public readonly int $hits,
        public readonly int $misses,
        public readonly int $size,
    ) {
        $total          = $hits + $misses;
        $this->hitRate  = $total > 0 ? round($hits / $total, 4) : 0.0;
    }

    public function toArray(): array
    {
        return [
            'hits'     => $this->hits,
            'misses'   => $this->misses,
            'size'     => $this->size,
            'hit_rate' => $this->hitRate,
        ];
    }
}
