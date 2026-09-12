<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use InvalidArgumentException;

final class RelationshipGuards
{
    public static function relationshipId(
        string $id
    ): void {
        if (
            ! preg_match(
                '/^R[0-9]{3}$/',
                $id
            )
        ) {
            throw new InvalidArgumentException(
                "Relationship id must match "
                . "^R[0-9]{3}$: {$id}"
            );
        }
    }

    public static function nonEmpty(
        string $value,
        string $field
    ): void {
        if (trim($value) === '') {
            throw new InvalidArgumentException(
                "{$field} must not be empty."
            );
        }
    }

    /**
     * @param list<string> $items
     */
    public static function nonEmptyStrings(
        array $items,
        string $field,
        int $minItems = 0
    ): void {
        if (count($items) < $minItems) {
            throw new InvalidArgumentException(
                "{$field} must contain "
                . "at least {$minItems} items."
            );
        }

        foreach (
            $items as $index => $item
        ) {
            if (
                ! is_string($item)
                || trim($item) === ''
            ) {
                throw new InvalidArgumentException(
                    "{$field}[{$index}] must be "
                    . 'a non-empty string.'
                );
            }
        }
    }
}
