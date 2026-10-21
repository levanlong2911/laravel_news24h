<?php

namespace App\Video\Superyacht\Spec;

use App\Models\AiPhaseSchema;
use RuntimeException;

final class ActionContract
{
    /** @var list<string> */
    public const EXECUTORS = ['none', 'code', 'ai'];

    /** @var list<string> */
    public const RECORD_ROLES = ['lock', 'review'];

    private const KEYS = ['phase', 'instructions', 'executor', 'model_profile', 'contract_version', 'outputs'];

    private const OPTIONAL_KEYS = ['records'];

    private const SLOT_KEYS = ['namespace', 'schema', 'version', 'pointer', 'mode', 'required', 'source'];

    private const OUTPUT_OPTIONAL_KEYS = ['items'];

    private const RECORD_OPTIONAL_KEYS = ['match'];

    /**
     * @param  array<string, mixed>  $action
     * @return array{phase: string, instructions: list<string>, executor: string, model_profile: ?string, contract_version: string, outputs: array<string, ?array<string, mixed>>, records: array<string, array<string, mixed>>}
     */
    public static function normalise(string $key, array $action): array
    {
        $missing = array_diff(self::KEYS, array_keys($action));
        $unknown = array_diff(array_keys($action), [...self::KEYS, ...self::OPTIONAL_KEYS]);

        if ($key === '' || $missing !== [] || $unknown !== []) {
            throw new RuntimeException("Action {$key} is missing [".implode(', ', $missing).'] or has unknown keys ['.implode(', ', $unknown).'].');
        }

        $instructions = $action['instructions'];

        if (! is_string($action['phase']) || $action['phase'] === ''
            || ! is_array($instructions) || ! array_is_list($instructions) || count(array_unique($instructions)) !== count($instructions)
            || in_array($action['phase'], $instructions, true)
            || ! is_string($action['contract_version']) || $action['contract_version'] === '') {
            throw new RuntimeException("Action {$key} is malformed: it needs a phase, distinct instructions without the phase and a contract version.");
        }

        if (! in_array($action['executor'], self::EXECUTORS, true)) {
            throw new RuntimeException("Action {$key} has executor ".json_encode($action['executor']).'; allowed: '.implode(', ', self::EXECUTORS).'.');
        }

        if ($action['executor'] === 'ai' ? ! is_string($action['model_profile']) || $action['model_profile'] === '' : $action['model_profile'] !== null) {
            throw new RuntimeException("Action {$key}: executor ai needs a model profile; executors none and code take none.");
        }

        if (! is_array($action['outputs']) || $action['outputs'] === [] || array_is_list($action['outputs'])) {
            throw new RuntimeException("Action {$key} must name its output slots, each with its contract or null while unconfirmed.");
        }

        $outputs = [];

        foreach ($action['outputs'] as $slot => $output) {
            $outputs[(string) $slot] = $output === null
                ? null
                : self::slot("Action {$key} output slot {$slot}", $output, self::OUTPUT_OPTIONAL_KEYS);
        }

        $records = [];

        foreach ((array) ($action['records'] ?? []) as $name => $record) {
            [$role, $slot] = array_pad(explode('/', (string) $name, 2), 2, '');

            if (! in_array($role, self::RECORD_ROLES, true) || $slot === '') {
                throw new RuntimeException("Action {$key} record {$name} must be named lock/<slot> or review/<slot>.");
            }

            $records[(string) $name] = self::slot("Action {$key} record {$name}", $record, self::RECORD_OPTIONAL_KEYS);
        }

        ksort($outputs);
        ksort($records);

        return [
            'phase' => $action['phase'],
            'instructions' => array_map('strval', $instructions),
            'executor' => $action['executor'],
            'model_profile' => $action['model_profile'],
            'contract_version' => $action['contract_version'],
            'outputs' => $outputs,
            'records' => $records,
        ];
    }

    /**
     * @param  array<string, mixed>  $workflow  video.superyacht.contracts.workflows.<code>
     * @return list<array{phase: string, role: string, slot: string, namespace: string, schema: string, version: string, pointer: string, mode: string, required: bool, source: string}>
     */
    public static function desiredLinks(array $workflow): array
    {
        $rows = [];
        $seen = [];
        $add = static function (array $row, array $contract) use (&$rows, &$seen): void {
            $id = "{$row['phase']}|{$row['role']}|{$row['slot']}";

            if (isset($seen[$id]) && $seen[$id] != $contract) {
                throw new RuntimeException("{$row['phase']} {$row['role']}/{$row['slot']} is declared twice with different contracts.");
            }

            if (! isset($seen[$id])) {
                $seen[$id] = $contract;
                $rows[] = $row;
            }
        };
        $link = static fn (string $phase, string $role, string $slot, array $contract): array => [
            'phase' => $phase,
            'role' => $role,
            'slot' => $slot,
            'namespace' => $contract['namespace'],
            'schema' => $contract['schema'],
            'version' => $contract['version'],
            'pointer' => $contract['pointer'],
            'mode' => $contract['mode'],
            'required' => $contract['required'],
            'source' => $contract['source'],
        ];

        foreach ((array) ($workflow['actions'] ?? []) as $key => $action) {
            $action = self::normalise((string) $key, (array) $action);

            foreach ($action['outputs'] as $slot => $output) {
                if ($output !== null) {
                    $add($link($action['phase'], 'output', $slot, $output), $output);
                }
            }

            foreach ($action['records'] as $name => $record) {
                [$role, $slot] = explode('/', $name, 2);
                $add($link($action['phase'], $role, $slot, $record), $record);
            }
        }

        foreach ((array) ($workflow['links'] ?? []) as $index => $row) {
            $id = ($row['phase'] ?? '').'|'.($row['role'] ?? '').'|'.($row['slot'] ?? '');

            if (($row['role'] ?? null) === 'output') {
                throw new RuntimeException("links[{$index}] is an output link; outputs are declared once, in the action that reads them.");
            }

            if (isset($seen[$id])) {
                throw new RuntimeException("links[{$index}] repeats {$row['phase']} {$row['role']}/{$row['slot']}, which an action already declares.");
            }

            $seen[$id] = $row;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array{outputs: array<string, ?array<string, mixed>>, records: array<string, array<string, mixed>>}  $action  normalised action
     * @param  iterable<array{role: string, slot: string, namespace: ?string, schema: ?string, version: ?string, data_pointer: string, validation_mode: string, is_required: bool}>  $links
     * @return list<string>
     */
    public static function mismatches(array $action, iterable $links): array
    {
        $governed = ['output', ...self::RECORD_ROLES];
        $actual = [];

        foreach ($links as $link) {
            if (in_array($link['role'], $governed, true)) {
                $actual["{$link['role']}/{$link['slot']}"] = $link;
            }
        }

        $expected = $action['records'];

        foreach ($action['outputs'] as $slot => $output) {
            $expected["output/{$slot}"] = $output;
        }

        ksort($expected);
        $found = [];

        foreach ($expected as $name => $contract) {
            [$role, $slot] = explode('/', $name, 2);
            $label = "{$role} slot {$slot}";

            if ($contract === null) {
                $found[] = "{$label}: its contract is not confirmed";

                continue;
            }

            $link = $actual[$name] ?? null;

            if ($link === null) {
                $found[] = "{$label} is missing";

                continue;
            }

            $wanted = "{$contract['namespace']}/{$contract['schema']}@{$contract['version']}";
            $linked = "{$link['namespace']}/{$link['schema']}@{$link['version']}";

            if ($linked !== $wanted) {
                $found[] = "{$label} points to {$linked}; the contract names {$wanted}";
            }

            if ($link['data_pointer'] !== $contract['pointer']) {
                $found[] = "{$label} reads \"{$link['data_pointer']}\"; the contract names \"{$contract['pointer']}\"";
            }

            if ($link['validation_mode'] !== $contract['mode']) {
                $found[] = "{$label} is checked {$link['validation_mode']}; the contract names {$contract['mode']}";
            }

            if ($link['is_required'] !== $contract['required']) {
                $found[] = "{$label} is ".($link['is_required'] ? 'required' : 'optional').'; the contract makes it '.($contract['required'] ? 'required' : 'optional');
            }
        }

        foreach (array_diff_key($actual, $expected) as $name => $link) {
            [$role, $slot] = explode('/', $name, 2);
            $found[] = "{$role} slot {$slot} is not part of this action's contract";
        }

        return $found;
    }

    /**
     * @param  list<string>  $optional
     * @return array<string, mixed>
     */
    private static function slot(string $label, mixed $spec, array $optional): array
    {
        if (! is_array($spec)) {
            throw new RuntimeException("{$label} must be a contract.");
        }

        $missing = array_diff(self::SLOT_KEYS, array_keys($spec));
        $unknown = array_diff(array_keys($spec), [...self::SLOT_KEYS, ...$optional]);

        if ($missing !== [] || $unknown !== []
            || ! is_string($spec['namespace']) || $spec['namespace'] === ''
            || ! is_string($spec['schema']) || $spec['schema'] === ''
            || ! is_string($spec['version']) || $spec['version'] === ''
            || ! AiPhaseSchema::isPointer($spec['pointer'])
            || ! in_array($spec['mode'], AiPhaseSchema::MODES, true)
            || ! is_bool($spec['required'])
            || ! is_string($spec['source']) || $spec['source'] === '') {
            throw new RuntimeException("{$label} needs exactly namespace, schema, version, a JSON Pointer, a known mode, a required flag and a source"
                .($optional === [] ? '' : ' (optional: '.implode(', ', $optional).')').'.');
        }

        $contract = array_intersect_key($spec, array_flip(self::SLOT_KEYS));

        if (array_key_exists('items', $spec)) {
            $items = $spec['items'];

            if ($spec['mode'] !== 'each_item'
                || ! is_array($items) || (array_keys($items) !== ['count', 'key'] && array_keys($items) !== ['key', 'count'])
                || ! is_int($items['count']) || $items['count'] < 1
                || ! is_string($items['key']) || $items['key'] === '') {
                throw new RuntimeException("{$label}: items needs mode each_item, a positive integer count and a key.");
            }

            $contract['items'] = ['count' => $items['count'], 'key' => $items['key']];
        }

        if (array_key_exists('match', $spec)) {
            $match = $spec['match'];

            if (! is_array($match) || $match === [] || array_is_list($match)) {
                throw new RuntimeException("{$label}: match maps JSON Pointers to the allowed values.");
            }

            foreach ($match as $pointer => $allowed) {
                if (! AiPhaseSchema::isPointer((string) $pointer) || $pointer === ''
                    || ! is_array($allowed) || $allowed === [] || ! array_is_list($allowed)
                    || array_filter($allowed, static fn (mixed $value): bool => ! is_scalar($value)) !== []) {
                    throw new RuntimeException("{$label}: match {$pointer} needs a non-empty JSON Pointer and a non-empty list of scalar values.");
                }
            }

            ksort($match);
            $contract['match'] = $match;
        }

        return $contract;
    }
}
