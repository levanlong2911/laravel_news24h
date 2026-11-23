<?php

declare(strict_types=1);

namespace App\Video\Moto;

use stdClass;

/** Only an empty colour outside the object contract is removed; no supplied colour is discarded. */
final class MotoLocationsAnswer
{
    public const VERSION = 'moto-locations-answer-v1';

    public const INSTRUCTION = 'Output contract: set_design.objects[] must never contain a colour property, including colour: null. Architecture has its own colour property; objects do not. Use only the properties declared for each object in the supplied JSON schema. If an object needs a colour for recognition, describe it in material or identity; never add a new property.';

    /** @param list<array{content: string}> $schemas */
    public static function applies(array $schemas): bool
    {
        foreach ($schemas as $row) {
            // A malformed schema remains the schema checker's configuration error, never a repair.
            $schema = json_decode($row['content'], true);
            $object = $schema['$defs']['object'] ?? null;

            if (($schema['properties']['format']['const'] ?? null) === MotoContract::LOCATIONS_FORMAT_V4
                && is_array($object) && ($object['additionalProperties'] ?? null) === false
                && is_array($object['properties'] ?? null) && ! array_key_exists('colour', $object['properties'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{content: string}> $schemas the actual pinned contract, never the current release
     * @return array{answer: object, version: ?string, changes: list<array{path: string, action: string, before: mixed}>}
     */
    public static function prepare(object $answer, array $schemas): array
    {
        $result = ['answer' => $answer, 'version' => null, 'changes' => []];

        if (($answer->format ?? null) !== MotoContract::LOCATIONS_FORMAT_V4 || ! self::applies($schemas)) {
            return $result;
        }

        $result['version'] = self::VERSION;
        // Preserve empty objects and arrays, and never change the provider's parsed answer in place.
        $prepared = json_decode(json_encode($answer, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        foreach (is_array($prepared->data->locations ?? null) ? $prepared->data->locations : [] as $locationIndex => $location) {
            foreach (is_array($location->set_design->objects ?? null) ? $location->set_design->objects : [] as $objectIndex => $object) {
                if ($object instanceof stdClass && property_exists($object, 'colour') && $object->colour === null) {
                    unset($object->colour);
                    $result['changes'][] = ['path' => "/data/locations/{$locationIndex}/set_design/objects/{$objectIndex}/colour", 'action' => 'remove', 'before' => null];
                }
            }
        }

        $result['answer'] = $prepared;

        return $result;
    }
}
