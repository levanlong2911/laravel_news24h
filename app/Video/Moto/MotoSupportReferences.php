<?php

declare(strict_types=1);

namespace App\Video\Moto;

/**
 * What may hold the motorcycle, read by the script, location and shot checks alike.
 *
 * A support is a part of the design (its side stand), a character who holds the motorcycle, or a
 * temporary item. Under the legacy policy a temporary item must be one the beat's own build state
 * lists; under moto-validation-v2 it must be one the pinned design lists at any of its build states,
 * so a move from the support of one stage to the next is not judged here: PHP checks that the item
 * exists, the prompt and the reviewer judge where it belongs. Nothing is renamed or guessed.
 */
final class MotoSupportReferences
{
    /**
     * @param  array<string, list<string>>  $byState  the temporary items of the pinned design, by build state
     * @return list<string> every temporary item the design lists at any state, each once, in the order of the states
     */
    public static function designTemporary(array $byState): array
    {
        $items = [];

        foreach ($byState as $list) {
            foreach ((array) $list as $item) {
                if (is_string($item) && $item !== '') {
                    $items[$item] = true;
                }
            }
        }

        return array_keys($items);
    }

    /**
     * @param  array<string, list<string>>  $byState  the temporary items of the pinned design, by build state
     * @param  ?string  $state  the build state of the beat or scene
     * @param  bool  $byDesign  the run pinned moto-validation-v2
     * @return list<string> the temporary items a support may name there
     */
    public static function allowed(array $byState, ?string $state, bool $byDesign): array
    {
        if ($byDesign) {
            return self::designTemporary($byState);
        }

        return is_string($state) ? array_values(array_filter((array) ($byState[$state] ?? []), 'is_string')) : [];
    }

    /**
     * @param  array<string, mixed>  $parts  the parts of the design by id
     * @param  list<string>  $characters  the characters a support may name there
     * @param  list<string>  $temporary  the result of allowed()
     * @param  string  $characterSource  where those characters come from, for the message
     * @param  string  $temporarySource  where those temporary items come from, for the message
     * @return ?string why the support is not one of them; null when it is
     */
    public static function problem(mixed $support, array $parts, array $characters, array $temporary, string $characterSource, string $temporarySource): ?string
    {
        $support = (string) $support;

        return match (true) {
            preg_match('/^P\d{2,3}$/', $support) === 1 => isset($parts[$support]) ? null : 'không có trong parts của thiết kế',
            preg_match('/^CH\d{2}$/', $support) === 1 => in_array($support, $characters, true) ? null : "không có trong {$characterSource}",
            default => in_array($support, $temporary, true) ? null : "không có trong {$temporarySource}",
        };
    }
}
