<?php

declare(strict_types=1);

namespace App\Video\Locations;

use RuntimeException;

final class LocationReferencePrompt
{
    public const VERSION = 'phase5-reference-prompt-v3';

    public const TASK = 'location_reference';

    public const OPERATION = 'location_reference';

    /**
     * @param  array<string, mixed>  $document  the approved Phase 5 document
     * @param  array<string, mixed>  $request  one row of LocationReferencePlanner::plan()
     * @param  array{suffix: array<string, string>, roles: array<string, string>, camera_label: string, palette_label: string}  $config  video.superyacht.locations.reference
     * @return string anchor, then for interiors the room identity and the state's detail and palette, the view's camera, the light, the configured suffix and one sentence per input role, each verbatim
     */
    public static function compose(array $document, array $request, array $config): string
    {
        $data = (array) $document['data'];
        $space = (array) $request['space'];
        $definition = self::definition($data, $space);
        $parts = [(string) $definition['anchor']];

        if ($space['kind'] === 'interior') {
            $identity = (array) $definition['identity'];
            $state = (array) $definition['state'];
            array_push($parts, (string) $identity['geometry'], (string) $identity['window'], (string) $identity['structure']);

            if ($state['detail'] !== null) {
                $parts[] = (string) $state['detail'];
            }

            $parts[] = $config['palette_label'].' '.implode(', ', (array) $state['palette']).'.';
        }

        $parts[] = $config['camera_label'].' '.self::pick((array) $definition['views'], (string) $request['view'], 'view', $request)['camera'];
        $parts[] = (string) self::pick((array) $definition['lighting'], (string) $request['lighting'], 'lighting', $request)['description'];
        $parts[] = $config['suffix'][$space['kind']].'.';
        $roles = array_unique(array_column((array) $request['inputs'], 'role'));

        foreach ($config['roles'] as $role => $sentence) {
            if (in_array($role, $roles, true)) {
                $parts[] = (string) $sentence;
            }
        }

        return implode(' ', array_map(static fn (string $part): string => trim($part), $parts));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $space
     * @return array{anchor: string, lighting: list<array<string, mixed>>, views: list<array<string, mixed>>, identity?: array<string, mixed>, state?: array<string, mixed>}
     */
    private static function definition(array $data, array $space): array
    {
        $id = (string) $space['id'];
        $list = match ($space['kind']) {
            'location' => 'locations',
            'interior' => 'interior_sets',
            'exterior' => 'exterior_amenities',
            default => throw new RuntimeException("Unknown space kind {$space['kind']}."),
        };

        foreach ((array) $data[$list] as $entry) {
            if ($space['kind'] === 'location' && $entry['id'] === $id || $space['kind'] === 'exterior' && $entry['zone'] === $id) {
                return ['anchor' => (string) $entry['anchor'], 'lighting' => (array) $entry['lighting'], 'views' => (array) $entry['views']];
            }

            if ($space['kind'] === 'interior' && $entry['room'] === $id) {
                foreach ((array) $entry['states'] as $state) {
                    if ($state['state'] === $space['state']) {
                        return ['anchor' => (string) $state['anchor'], 'lighting' => (array) $entry['lighting'], 'views' => (array) $entry['views'], 'identity' => (array) $entry['identity'], 'state' => (array) $state];
                    }
                }
            }
        }

        throw new RuntimeException("{$space['kind']} {$id} ".($space['state'] ?? '').' is not defined.');
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private static function pick(array $entries, string $id, string $label, array $request): array
    {
        foreach ($entries as $entry) {
            if (($entry['id'] ?? null) === $id) {
                return $entry;
            }
        }

        throw new RuntimeException("Request {$request['id']} names {$label} {$id}, which its space does not define.");
    }
}
