<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark\Selection;

/**
 * One unused fact, its attribution class, and the minimal evidence for that class.
 *
 * The class is a conclusion; the evidence is what makes it checkable without
 * reading the whole Article Model back. "F1 — entity_never_staged — stadium_obj"
 * can be verified by looking at one thing.
 *
 * `evidence`, not `witness`: what proves a class differs BY class — entities for
 * one, an entity combination for another, beats for the third. One name for three
 * meanings would make the reader guess which they are holding.
 *
 * It is minimal on purpose. Listing every entity of the fact would make the reader
 * find the blocker again; listing only what actually establishes the class is the
 * shortest thing that proves it.
 */
final class Attribution
{
    /** @param string[] $evidence entities, an entity combination, or beats — per class */
    public function __construct(
        public readonly string $factId,
        public readonly AttributionClass $class,
        public readonly array $evidence,
    ) {}

    public function describe(): string
    {
        return sprintf('%s [%s]', $this->class->value, implode(', ', $this->evidence));
    }
}
