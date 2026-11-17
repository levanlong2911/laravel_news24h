<?php

declare(strict_types=1);

namespace App\Video\Moto;

/**
 * The design refs of a beat as code projects them under moto-validation-v2: the refs the beat
 * names, then the refs each of its events names in their order, then the refs it puts in use, each
 * once. Only ids the answer itself wrote in those id fields are taken, each checked against the
 * design first, the beat's own refs as much as the others: no prose is read, no feature is opened
 * into its parts, and an id the design does not hold fails at its own path and is never projected.
 *
 * The model's answer is never changed: the projection is derived data of the run, and the phases
 * that read the script receive the beats with their projected refs.
 */
final class MotoScriptReferences
{
    public const VERSION = 'moto-script-references-v1';

    /**
     * @param  array<string, mixed>  $design  the design facts of MotoScriptCheck::facts()
     * @return bool the design holds a record with this id that a beat may name: a part, system, feature, moment, connection, line, identity rule, configuration or light — the rule MotoScriptCheck applies to the refs of a beat
     */
    public static function known(mixed $ref, array $design): bool
    {
        if (! is_string($ref) || $ref === '') {
            return false;
        }

        $records = ['P' => 'parts', 'S' => 'systems', 'F' => 'features', 'M' => 'moments'];

        if (isset($records[$ref[0]]) && isset(((array) ($design[$records[$ref[0]]] ?? []))[$ref])) {
            return true;
        }

        return in_array($ref, (array) ($design['other_refs'] ?? []), true)
            || isset(((array) ($design['configurations'] ?? []))[$ref])
            || in_array($ref, (array) ($design['lights'] ?? []), true);
    }

    /**
     * @param  list<array<string, mixed>>  $beats  the beats as the answer wrote them
     * @param  array<string, mixed>  $design  the design facts of MotoScriptCheck::facts()
     * @return array{beats: list<array<string, mixed>>, references: array<string, list<string>>, issues: list<string>} the beats with their projected design_refs, the projected refs by beat id, and every beat, event or in_use ref the design does not hold, at each path that names it
     */
    public static function project(array $beats, array $design): array
    {
        $references = [];
        $issues = [];

        foreach ($beats as $index => $beat) {
            $refs = [];
            // Each of the three groups is checked on its own: an id with no source fails at every path that names it, however often it is repeated, and is never projected.
            $take = static function (mixed $ref, string $at) use (&$refs, &$issues, $design): void {
                if (! self::known($ref, $design)) {
                    $issues[] = $at.': '.self::text($ref).' không có trong thiết kế.';
                } elseif (! in_array($ref, $refs, true)) {
                    $refs[] = $ref;
                }
            };

            foreach ((array) ($beat['design_refs'] ?? []) as $ref) {
                $take($ref, ($beat['id'] ?? '?').'.design_refs');
            }

            foreach ((array) ($beat['events'] ?? []) as $event) {
                foreach ((array) ($event['design_refs'] ?? []) as $ref) {
                    $take($ref, ($event['id'] ?? '?').'.design_refs');
                }
            }

            foreach ((array) ($beat['in_use'] ?? []) as $ref) {
                $take($ref, ($beat['id'] ?? '?').'.in_use');
            }

            $beats[$index]['design_refs'] = $refs;

            if (is_string($beat['id'] ?? null)) {
                $references[$beat['id']] = $refs;
            }
        }

        return ['beats' => array_values($beats), 'references' => $references, 'issues' => $issues];
    }

    /**
     * @param  array<string, mixed>  $document  a script document as the model returned it
     * @param  array<string, list<string>>  $references  the projected refs by beat id
     * @return array<string, mixed> the same document with the design_refs of every beat replaced by its projection; nothing else changes
     */
    public static function apply(array $document, array $references): array
    {
        foreach ((array) ($document['data']['beats'] ?? []) as $index => $beat) {
            if (is_array($beat) && is_string($beat['id'] ?? null) && isset($references[$beat['id']])) {
                $document['data']['beats'][$index]['design_refs'] = $references[$beat['id']];
            }
        }

        return $document;
    }

    private static function text(mixed $ref): string
    {
        return is_string($ref) ? $ref : (string) json_encode($ref);
    }
}
