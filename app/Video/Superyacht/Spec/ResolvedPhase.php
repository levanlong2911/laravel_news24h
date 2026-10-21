<?php

namespace App\Video\Superyacht\Spec;

use App\Video\Superyacht\Validation\ValidationReport;
use App\Video\Superyacht\Validation\Violation;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Uri;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class ResolvedPhase
{
    public const MANIFEST_VERSION = 1;

    public const COMPILER_VERSION = 'spec-contract-3';

    private ?Validator $validator = null;

    /**
     * @param  array<string, mixed>  $manifest  canonical manifest including bundle_hash
     * @param  list<array{code: string, kind: string, content: string}>  $parts  instructions in the configured order, then the phase
     * @param  array<string, array{key: string, json_schema_id: string, content: string}>  $schemas  every schema of the bundle by id
     */
    public function __construct(
        private readonly array $manifest,
        private readonly array $parts,
        private readonly array $schemas,
    ) {}

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return $this->manifest;
    }

    public function bundleHash(): string
    {
        return $this->manifest['bundle_hash'];
    }

    public function ready(): bool
    {
        return $this->manifest['ready'];
    }

    /** @return list<string> */
    public function mismatches(): array
    {
        return $this->manifest['contract_mismatches'];
    }

    /** @return list<array{code: string, kind: string, content: string}> */
    public function parts(): array
    {
        return $this->parts;
    }

    /**
     * @param  ?string  $projectId  only for a bundle resolved by category; a bundle resolved for a project checks against its own project
     * @return array{report: ValidationReport, status: ?string, applicable: bool, notices: list<string>}
     */
    public function checkOutput(string $json, ?string $projectId = null): array
    {
        $refused = static fn (string $code, string $message): array => [
            'report' => new ValidationReport([new Violation($code, $message)]),
            'status' => null,
            'applicable' => false,
            'notices' => [],
        ];
        $bound = $this->manifest['project_id'];

        if ($bound !== null && $projectId !== null && $projectId !== $bound) {
            return $refused('CONTRACT-PROJECT', "This bundle was resolved for project {$bound}; it does not check output of project {$projectId}.");
        }

        $project = $bound ?? $projectId;

        if ($project === null || $project === '') {
            return $refused('CONTRACT-PROJECT', 'This bundle was resolved by category without a project; name the project whose output is checked.');
        }

        if (! $this->ready()) {
            return $refused('CONTRACT-NOT-READY', "Action {$this->manifest['action']['key']} is not ready: ".implode('; ', $this->mismatches()).'.');
        }

        $decoded = json_decode($json, false);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['report' => new ValidationReport([new Violation('JSON', json_last_error_msg())]), 'status' => null, 'applicable' => false, 'notices' => []];
        }

        $envelope = (new EnvelopeValidator())->check($decoded, $this->manifest['phase']['code'], $project);
        $violations = $envelope['report']->violations;

        if (is_object($decoded)) {
            foreach ($this->links('output') as $link) {
                array_push($violations, ...$this->checkLink($link, $decoded));
                array_push($violations, ...$this->checkItems($link, $decoded));
            }
        }

        $report = new ValidationReport($violations);

        return [
            'report' => $report,
            'status' => $envelope['status'],
            'applicable' => $report->passes() && $envelope['status'] === 'ok',
            'notices' => $envelope['notices'],
        ];
    }

    public function validateSlot(string $role, string $slot, string $json): ValidationReport
    {
        if ($role === 'output') {
            return new ValidationReport([new Violation('CONTRACT-OUTPUT', 'Output slots are checked by checkOutput(), together with the envelope and the task context.')]);
        }

        $link = array_values(array_filter($this->links($role), static fn (array $link): bool => $link['slot'] === $slot))[0] ?? null;

        if ($link === null) {
            return new ValidationReport([new Violation('CONTRACT-NOT-CONFIGURED', "{$this->manifest['phase']['code']} has no {$role} slot {$slot} in this bundle.")]);
        }

        $decoded = json_decode($json, false);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new ValidationReport([new Violation('JSON', json_last_error_msg())]);
        }

        return new ValidationReport([...$this->checkLink($link, $decoded), ...$this->checkMatch($role, $slot, $link, $decoded)]);
    }

    /**
     * @param  array<string, mixed>  $link
     * @return list<Violation>
     */
    private function checkItems(array $link, mixed $root): array
    {
        $items = $this->manifest['action']['outputs'][$link['slot']]['items'] ?? null;

        if ($items === null) {
            return [];
        }

        [$found, $value] = $this->resolvePointer($root, $link['data_pointer']);

        if (! $found || ! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        $at = $link['data_pointer'] === '' ? '/' : $link['data_pointer'];
        $violations = [];

        if (count($value) !== $items['count']) {
            $violations[] = new Violation('CONTRACT-ITEM-COUNT', "Output slot {$link['slot']} holds ".count($value)." item(s); this task produces exactly {$items['count']}.", $at);
        }

        $expected = $this->manifest['context']['input_id'] ?? null;

        if ($expected === null) {
            return [...$violations, new Violation('CONTRACT-ITEM-KEY', "Output slot {$link['slot']} is keyed by {$items['key']} but the bundle has no task context.", $at)];
        }

        $seen = [];

        foreach ($value as $index => $item) {
            $key = is_object($item) && property_exists($item, $items['key']) ? $item->{$items['key']} : null;

            if ($key !== $expected) {
                $violations[] = new Violation('CONTRACT-ITEM-KEY', "{$items['key']} is ".json_encode($key)."; this task was resolved for {$expected}.", "{$at}/{$index}/{$items['key']}");
            }

            if (is_string($key) && isset($seen[$key])) {
                $violations[] = new Violation('CONTRACT-ITEM-KEY', "{$items['key']} {$key} appears more than once.", "{$at}/{$index}/{$items['key']}");
            }

            if (is_string($key)) {
                $seen[$key] = true;
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $link
     * @return list<Violation>
     */
    private function checkMatch(string $role, string $slot, array $link, mixed $root): array
    {
        $match = $this->manifest['action']['records']["{$role}/{$slot}"]['match'] ?? [];
        [$found, $payload] = $this->resolvePointer($root, $link['data_pointer']);

        if (! $found) {
            return [];
        }

        $violations = [];

        foreach ($match as $pointer => $allowed) {
            [$present, $value] = $this->resolvePointer($payload, $pointer);

            if (! $present) {
                $violations[] = new Violation('CONTRACT-MATCH', "{$role} slot {$slot} needs {$pointer}, which is missing.", $pointer);
            } elseif (! in_array($value, $allowed, true)) {
                $violations[] = new Violation('CONTRACT-MATCH', "{$role} slot {$slot} accepts {$pointer} in ".json_encode($allowed).', not '.json_encode($value).'.', $pointer);
            }
        }

        return $violations;
    }

    /** @return list<array<string, mixed>> */
    private function links(string $role): array
    {
        return array_values(array_filter($this->manifest['links'], static fn (array $link): bool => $link['role'] === $role));
    }

    /**
     * @param  array<string, mixed>  $link
     * @return list<Violation>
     */
    private function checkLink(array $link, mixed $root): array
    {
        $pointer = $link['data_pointer'];
        [$found, $value] = $this->resolvePointer($root, $pointer);

        if (! $found) {
            return $link['is_required']
                ? [new Violation('CONTRACT-MISSING', "Required {$link['role']} slot {$link['slot']} is missing.", $pointer === '' ? '/' : $pointer)]
                : [];
        }

        if ($link['validation_mode'] === 'whole') {
            return $this->validateValue($link['schema_id'], $value, $pointer);
        }

        if (! is_array($value) || ! array_is_list($value)) {
            return [new Violation('CONTRACT-NOT-A-LIST', "{$link['role']} slot {$link['slot']} is checked item by item and must be a JSON array.", $pointer === '' ? '/' : $pointer)];
        }

        $violations = [];

        foreach ($value as $index => $item) {
            array_push($violations, ...$this->validateValue($link['schema_id'], $item, $pointer.'/'.$index));
        }

        return $violations;
    }

    /** @return list<Violation> */
    private function validateValue(string $schemaId, mixed $value, string $at): array
    {
        $schema = $this->schemas[$schemaId] ?? throw new RuntimeException("Schema {$schemaId} is not part of this bundle.");
        $result = $this->validator()->validate($value, Uri::parse($schema['json_schema_id'], true));

        if ($result->isValid()) {
            return [];
        }

        $violations = [];

        foreach ((new ErrorFormatter())->format($result->error(), true) as $path => $messages) {
            foreach ((array) $messages as $message) {
                $violations[] = new Violation('SCHEMA-'.$schema['key'], (string) $message, $at.($path === '/' ? '' : $path));
            }
        }

        return $violations;
    }

    private function validator(): Validator
    {
        if ($this->validator === null) {
            $this->validator = new Validator();
            $this->validator->setMaxErrors(50);

            foreach ($this->schemas as $id => $schema) {
                if (! $this->validator->resolver()->registerRaw($schema['content'], $schema['json_schema_id'])) {
                    throw new RuntimeException("Schema {$id} could not be registered.");
                }
            }
        }

        return $this->validator;
    }

    /** @return array{0: bool, 1: mixed} */
    private function resolvePointer(mixed $root, string $pointer): array
    {
        if ($pointer === '') {
            return [true, $root];
        }

        $node = $root;

        foreach (array_slice(explode('/', $pointer), 1) as $token) {
            $token = str_replace(['~1', '~0'], ['/', '~'], $token);

            if (is_object($node) && property_exists($node, $token)) {
                $node = $node->{$token};
            } elseif (is_array($node) && array_is_list($node) && preg_match('/^(0|[1-9][0-9]*)$/', $token) === 1 && array_key_exists((int) $token, $node)) {
                $node = $node[(int) $token];
            } else {
                return [false, null];
            }
        }

        return [true, $node];
    }
}
