<?php

declare(strict_types=1);

namespace App\Video\Locations;

final class LocationReferencePlanner
{
    public const VERSION = 'phase5-reference-plan-v3';

    /**
     * @param  array<string, mixed>  $space
     * @return string the key a binding and a request share when they show the same space, state, light and view
     */
    public static function usageKey(array $space, string $lighting, string $view): string
    {
        return $space['kind'].':'.$space['id'].':'.($space['state'] ?? '-').':'.$lighting.':'.$view;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array{version: string, requests: list<array{id: string, space: array<string, mixed>, lighting: string, view: string, purpose: string, inputs: list<array{kind: string, id: ?string, role: string}>, required: bool, uses: list<string>, order: int}>, required: list<string>}
     */
    public static function plan(array $document): array
    {
        $data = (array) $document['data'];
        $uses = [];

        foreach ((array) $data['bindings'] as $binding) {
            $uses[self::usageKey((array) $binding['space'], (string) $binding['lighting'], (string) $binding['view'])][] = (string) $binding['beat'];
        }

        $requests = [];

        foreach ((array) $data['reference_requests'] as $request) {
            $requests[(string) $request['id']] = $request;
        }

        $required = [];

        foreach ($requests as $id => $request) {
            if (isset($uses[self::usageKey((array) $request['space'], (string) $request['lighting'], (string) $request['view'])])) {
                self::require((string) $id, $requests, $required);
            }
        }

        $order = self::order($requests);
        $rows = [];

        foreach ($order as $position => $id) {
            $request = $requests[$id];
            $rows[] = [
                'id' => $id,
                'space' => (array) $request['space'],
                'lighting' => (string) $request['lighting'],
                'view' => (string) $request['view'],
                'purpose' => (string) $request['purpose'],
                'inputs' => array_values((array) $request['inputs']),
                'required' => isset($required[$id]),
                'uses' => array_values(array_unique($uses[self::usageKey((array) $request['space'], (string) $request['lighting'], (string) $request['view'])] ?? [])),
                'order' => $position,
            ];
        }

        return ['version' => self::VERSION, 'requests' => $rows, 'required' => array_values(array_filter($order, static fn (string $id): bool => isset($required[$id])))];
    }

    /**
     * @param  array<string, array<string, mixed>>  $requests
     * @param  array<string, true>  $required
     */
    private static function require(string $id, array $requests, array &$required): void
    {
        if (isset($required[$id]) || ! isset($requests[$id])) {
            return;
        }

        $required[$id] = true;

        foreach ((array) $requests[$id]['inputs'] as $input) {
            if ($input['kind'] === 'request' && is_string($input['id'])) {
                self::require($input['id'], $requests, $required);
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $requests
     * @return list<string> ids so that every request comes after the requests it takes as input
     */
    private static function order(array $requests): array
    {
        $done = [];
        $order = [];
        $visit = static function (string $id) use (&$visit, &$done, &$order, $requests): void {
            if (isset($done[$id]) || ! isset($requests[$id])) {
                return;
            }

            $done[$id] = true;

            foreach ((array) $requests[$id]['inputs'] as $input) {
                if ($input['kind'] === 'request' && is_string($input['id'])) {
                    $visit($input['id']);
                }
            }

            $order[] = $id;
        };

        foreach (array_keys($requests) as $id) {
            $visit((string) $id);
        }

        return $order;
    }
}
