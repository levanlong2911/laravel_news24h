<?php

namespace App\Services\AI\AFOS\Ir\Temporal\Motion;

/**
 * NullMotionVerbRegistry — identity pass-through, no translation.
 *
 * Used by default until a backend-specific registry is wired in.
 * Every verb is its own canonical form; nothing is substitutable.
 */
final class NullMotionVerbRegistry implements MotionVerbRegistry
{
    public function canonicalForm(string $verb): string
    {
        return $verb;
    }

    public function equivalents(string $verb): array
    {
        return [$verb];
    }

    public function isSubstitutable(string $from, string $to): bool
    {
        return $from === $to;
    }

    public function similarity(string $from, string $to): float
    {
        return $from === $to ? 1.0 : 0.0;
    }
}
