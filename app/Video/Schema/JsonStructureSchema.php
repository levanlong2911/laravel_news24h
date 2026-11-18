<?php

declare(strict_types=1);

namespace App\Video\Schema;

use stdClass;

/**
 * The structure of a released JSON Schema, for the check of what an AI answered.
 *
 * Kept: types, the fields an object has, which are required, which extra fields it refuses, the
 * shape of nested objects and arrays, the fixed positions of a tuple, and references. Left out:
 * what a value says — enum, const, pattern, format, lengths, numeric limits, counts of items,
 * uniqueness — and every constraint that holds only for certain values (a contains or a not that
 * reads values). A field declared by its values alone keeps the types those values have, so a
 * string field never starts to accept a number or an object. A condition (if) that reads a value
 * to choose which structure applies stays as written; the structure it chooses is checked as
 * structure. Branches of a oneOf must still be told apart by structure (type, a field one requires
 * and the other refuses) once values are not read, or the schema is refused as unsupported.
 *
 * Built in memory for the check alone: the released schema, its hash, the manifest and what the
 * model is sent never change. A form this class does not support is a configuration fault
 * (JsonStructureSchemaException), never a pass and never an error of the answer.
 */
final class JsonStructureSchema
{
    public const VERSION = 'json-structure-v1';

    /** @var list<string> what a value may say */
    private const VALUE_KEYWORDS = [
        'enum', 'const', 'pattern', 'format', 'minLength', 'maxLength', 'minimum', 'maximum',
        'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'uniqueItems', 'contentEncoding', 'contentMediaType',
    ];

    /** @var list<string> counts of items: structure only where the array has fixed positions */
    private const COUNT_KEYWORDS = ['minItems', 'maxItems'];

    /** @var list<string> keywords whose members are subschemas by name */
    private const SCHEMA_MAPS = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];

    /** @var list<string> keywords whose value is one subschema */
    private const SCHEMA_ONE = ['then', 'else', 'additionalProperties', 'unevaluatedProperties', 'unevaluatedItems', 'additionalItems', 'not', 'contains', 'propertyNames', 'if', 'items'];

    /** @var list<string> keywords whose value is a list of subschemas */
    private const SCHEMA_LIST = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

    /** @var array<string, string> the built schemas by the hash of the released content */
    private static array $built = [];

    private function __construct(private readonly stdClass $root, private readonly string $label) {}

    /**
     * @param  string  $content  a released schema, as pinned
     * @param  string  $label  how a fault names the schema
     * @return string the structure of that schema, as JSON
     *
     * @throws JsonStructureSchemaException when the schema holds a form the structure check does not support
     */
    public static function content(string $content, string $label): string
    {
        $key = hash('sha256', $content);

        if (isset(self::$built[$key])) {
            return self::$built[$key];
        }

        $root = json_decode($content);

        if (! $root instanceof stdClass) {
            throw new JsonStructureSchemaException($label, '', 'schema không phải một object JSON');
        }

        return self::$built[$key] = (string) json_encode((new self($root, $label))->node($root, ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function node(mixed $node, string $at): mixed
    {
        if (is_bool($node)) {
            return $node;
        }

        if (! $node instanceof stdClass) {
            throw new JsonStructureSchemaException($this->label, $at, 'một schema con không phải object hay boolean');
        }

        $out = new stdClass;
        $tuple = property_exists($node, 'prefixItems') || is_array($node->items ?? null);
        $containsKept = property_exists($node, 'contains') && ! $this->readsValues($node->contains, $at.'/contains');

        foreach (get_object_vars($node) as $key => $value) {
            $key = (string) $key;
            $path = $at.'/'.self::escape($key);

            if (in_array($key, self::VALUE_KEYWORDS, true)) {
                continue;
            }

            if (in_array($key, self::COUNT_KEYWORDS, true)) {
                if ($tuple) {
                    $out->{$key} = $value;
                }

                continue;
            }

            if (in_array($key, ['contains', 'minContains', 'maxContains'], true)) {
                if ($containsKept) {
                    $out->{$key} = $key === 'contains' ? $this->asWritten($value, $path) : $value;
                }

                continue;
            }

            $out->{$key} = match (true) {
                // A condition reads values to choose a structure: it stays as written.
                $key === 'if', $key === 'propertyNames' => $this->asWritten($value, $path),
                $key === 'not' => $this->readsValues($value, $path) ? null : $this->asWritten($value, $path),
                $key === 'oneOf' => $this->oneOf($value, $path),
                $key === 'anyOf' => $this->distinct($value, $path),
                in_array($key, ['allOf', 'prefixItems'], true) => $this->each($value, $path),
                $key === 'items' && is_array($value) => $this->each($value, $path),
                in_array($key, self::SCHEMA_ONE, true) => $this->node($value, $path),
                in_array($key, self::SCHEMA_MAPS, true) => $this->map($value, $path),
                // A reference inside this schema must point at something, or the check could never run it.
                $key === '$ref' => $this->checkedRef($value, $path),
                default => $value,
            };

            if ($key === 'not' && $out->not === null) {
                unset($out->not);
            }
        }

        if ((property_exists($node, 'enum') || property_exists($node, 'const')) && ! property_exists($node, 'type')) {
            $this->typeFromValues($node, $out, $at);
        }

        return $out;
    }

    /** The types the values a field may hold have, so leaving the values out keeps the field's type. */
    private function typeFromValues(stdClass $node, stdClass $out, string $at): void
    {
        if (property_exists($node, 'enum') && ! is_array($node->enum)) {
            throw new JsonStructureSchemaException($this->label, $at.'/enum', 'enum không phải một mảng');
        }

        $values = [...(property_exists($node, 'enum') ? $node->enum : []), ...(property_exists($node, 'const') ? [$node->const] : [])];
        $types = [];
        $itemTypes = [];

        foreach ($values as $value) {
            $type = self::typeOf($value);

            if ($type === 'object' && ! property_exists($node, 'properties') && ! property_exists($node, 'patternProperties') && ! property_exists($node, 'additionalProperties')) {
                throw new JsonStructureSchemaException($this->label, $at, 'giá trị cố định là object mà schema không khai cấu trúc của nó');
            }

            if ($type === 'array' && ! property_exists($node, 'items') && ! property_exists($node, 'prefixItems')) {
                foreach ($value as $item) {
                    $itemType = self::typeOf($item);

                    if ($itemType === 'object' || $itemType === 'array') {
                        throw new JsonStructureSchemaException($this->label, $at, 'giá trị cố định là mảng chứa '.$itemType.' mà schema không khai cấu trúc phần tử');
                    }

                    $itemTypes[$itemType] = true;
                }
            }

            $types[$type] = true;
        }

        if ($types === []) {
            return;
        }

        $out->type = self::typeList($types);

        if (isset($types['array']) && $itemTypes !== []) {
            $out->items = (object) ['type' => self::typeList($itemTypes)];
        }
    }

    /** @param  array<string, true>  $types */
    private static function typeList(array $types): string|array
    {
        if (isset($types['integer'], $types['number'])) {
            unset($types['integer']);
        }

        $list = array_keys($types);
        sort($list);

        return count($list) === 1 ? $list[0] : $list;
    }

    private static function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => 'array',
            default => 'object',
        };
    }

    /** @return list<mixed> */
    private function each(mixed $list, string $at): array
    {
        if (! is_array($list)) {
            throw new JsonStructureSchemaException($this->label, $at, 'cần một mảng schema con');
        }

        $out = [];

        foreach (array_values($list) as $index => $item) {
            $out[] = $this->node($item, $at.'/'.$index);
        }

        return $out;
    }

    private function map(mixed $map, string $at): stdClass
    {
        if (! $map instanceof stdClass) {
            throw new JsonStructureSchemaException($this->label, $at, 'cần một object các schema con');
        }

        $out = new stdClass;

        foreach (get_object_vars($map) as $name => $schema) {
            $out->{$name} = $this->node($schema, $at.'/'.self::escape((string) $name));
        }

        return $out;
    }

    /**
     * @return list<mixed> the branches once those with the same structure are folded into one
     */
    private function distinct(mixed $branches, string $at): array
    {
        return array_values($this->folded($branches, $at)[0]);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} the built branches and the branches as written, by their structure: branches that differ only in their values, even behind different references, become one
     */
    private function folded(mixed $branches, string $at): array
    {
        if (! is_array($branches)) {
            throw new JsonStructureSchemaException($this->label, $at, 'cần một mảng các nhánh');
        }

        $built = [];
        $written = [];

        foreach (array_values($branches) as $index => $branch) {
            $node = $this->node($branch, $at.'/'.$index);
            $key = $this->structureKey($branch, $node, $at.'/'.$index);

            if (! isset($built[$key])) {
                $built[$key] = $node;
                $written[$key] = $branch;
            }
        }

        return [$built, $written];
    }

    /** The structure a branch stands for, its references followed, so two references to the same structure compare equal. */
    private function structureKey(mixed $branch, mixed $built, string $at): string
    {
        try {
            return (string) json_encode($this->node($this->asWritten($branch, $at), $at));
        } catch (JsonStructureSchemaException) {
            // A recursive reference cannot be followed to its end: the branch is compared as written.
            return (string) json_encode($built);
        }
    }

    /**
     * Branches that differ only in their values become one; branches that stay apart must still be told
     * apart by structure — another type, or a field one requires and the other refuses — or the oneOf
     * would refuse a valid answer as matching two branches.
     *
     * @return list<mixed>
     */
    private function oneOf(mixed $branches, string $at): array
    {
        [$built, $written] = $this->folded($branches, $at);

        $keys = array_keys($built);

        foreach ($keys as $i => $a) {
            foreach (array_slice($keys, $i + 1) as $b) {
                if (! $this->apart($written[$a], $written[$b], $at)) {
                    throw new JsonStructureSchemaException($this->label, $at, 'các nhánh oneOf không còn phân biệt được bằng cấu trúc khi bỏ kiểm giá trị');
                }
            }
        }

        return array_values($built);
    }

    /** Whether no instance can match both branches, judged by structure alone. */
    private function apart(mixed $a, mixed $b, string $at): bool
    {
        if ($a === false || $b === false) {
            return true;
        }

        $typesA = $this->types($a, $at);
        $typesB = $this->types($b, $at);

        if ($typesA !== null && $typesB !== null && ! self::overlap($typesA, $typesB)) {
            return true;
        }

        return $this->excludes($a, $b, $at) || $this->excludes($b, $a, $at);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function overlap(array $a, array $b): bool
    {
        $widen = static fn (array $types): array => in_array('number', $types, true) ? [...$types, 'integer'] : $types;

        return array_intersect($widen($a), $widen($b)) !== [];
    }

    /** @return ?list<string> the types a schema allows, when it declares them (by type or by its values) */
    private function types(mixed $node, string $at, array $seen = []): ?array
    {
        if (! $node instanceof stdClass) {
            return null;
        }

        $sets = [];

        if (property_exists($node, 'type')) {
            $sets[] = array_values((array) $node->type);
        } elseif (property_exists($node, 'enum') || property_exists($node, 'const')) {
            $sets[] = array_values(array_unique(array_map(self::typeOf(...), [...(array) ($node->enum ?? []), ...(property_exists($node, 'const') ? [$node->const] : [])])));
        }

        foreach ($this->parts($node, $at, $seen) as [$part, $partSeen]) {
            $types = $this->types($part, $at, $partSeen);

            if ($types !== null) {
                $sets[] = $types;
            }
        }

        if ($sets === []) {
            return null;
        }

        $types = array_shift($sets);

        foreach ($sets as $set) {
            $types = array_values(array_filter($types, static fn (string $type): bool => self::overlap([$type], $set)));
        }

        return $types;
    }

    /** Whether some field the first branch requires is refused by the second. */
    private function excludes(mixed $a, mixed $b, string $at): bool
    {
        foreach ($this->required($a, $at) as $field) {
            if ($this->refuses($b, $field, $at)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function required(mixed $node, string $at, array $seen = []): array
    {
        if (! $node instanceof stdClass) {
            return [];
        }

        $fields = array_values((array) ($node->required ?? []));

        foreach ($this->parts($node, $at, $seen) as [$part, $partSeen]) {
            $fields = [...$fields, ...$this->required($part, $at, $partSeen)];
        }

        return array_values(array_unique($fields));
    }

    private function refuses(mixed $node, string $field, string $at, array $seen = []): bool
    {
        if (! $node instanceof stdClass) {
            return false;
        }

        $properties = $node->properties ?? null;
        $declared = $properties instanceof stdClass && property_exists($properties, $field);

        if ($declared && $properties->{$field} === false) {
            return true;
        }

        if (($node->additionalProperties ?? null) === false && ! $declared && ! property_exists($node, 'patternProperties')) {
            return true;
        }

        $not = $node->not ?? null;

        if ($not instanceof stdClass && ! $this->readsValues($not, $at)) {
            $alternatives = [$not, ...array_values((array) ($not->anyOf ?? []))];

            foreach ($alternatives as $alternative) {
                if ($alternative instanceof stdClass && array_values((array) ($alternative->required ?? [])) === [$field]
                    && array_diff(array_keys(get_object_vars($alternative)), ['required', 'description', 'title', '$comment']) === []) {
                    return true;
                }
            }
        }

        foreach ($this->parts($node, $at, $seen) as [$part, $partSeen]) {
            if ($this->refuses($part, $field, $at, $partSeen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $seen
     * @return list<array{0: mixed, 1: list<string>}> the schemas an instance must match as well: the target of $ref and every allOf member
     */
    private function parts(stdClass $node, string $at, array $seen): array
    {
        $parts = [];

        if (property_exists($node, '$ref')) {
            $ref = (string) $node->{'$ref'};

            if (! in_array($ref, $seen, true)) {
                $parts[] = [$this->resolve($ref, $at), [...$seen, $ref]];
            }
        }

        foreach ((array) ($node->allOf ?? []) as $member) {
            $parts[] = [$member, $seen];
        }

        return $parts;
    }

    /** Whether a subschema reads values: any value keyword, count, contains or condition in it or in what it references. */
    private function readsValues(mixed $node, string $at, array $seen = []): bool
    {
        if (is_array($node)) {
            foreach ($node as $item) {
                if ($this->readsValues($item, $at, $seen)) {
                    return true;
                }
            }

            return false;
        }

        if (! $node instanceof stdClass) {
            return false;
        }

        foreach (get_object_vars($node) as $key => $value) {
            $key = (string) $key;

            if (in_array($key, self::VALUE_KEYWORDS, true) || in_array($key, self::COUNT_KEYWORDS, true) || in_array($key, ['contains', 'minContains', 'maxContains', 'if'], true)) {
                return true;
            }

            if ($key === '$ref') {
                $ref = (string) $value;

                if (! in_array($ref, $seen, true) && $this->readsValues($this->resolve($ref, $at), $at, [...$seen, $ref])) {
                    return true;
                }

                continue;
            }

            if (in_array($key, self::SCHEMA_MAPS, true) && $value instanceof stdClass) {
                foreach (get_object_vars($value) as $member) {
                    if ($this->readsValues($member, $at, $seen)) {
                        return true;
                    }
                }

                continue;
            }

            if ((in_array($key, self::SCHEMA_ONE, true) || in_array($key, self::SCHEMA_LIST, true)) && $this->readsValues($value, $at, $seen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A subschema kept as written, with every local reference replaced by what it points to in the
     * released schema, so building the structure of the rest never changes what it reads.
     *
     * @param  list<string>  $stack
     */
    private function asWritten(mixed $node, string $at, array $stack = []): mixed
    {
        if (is_array($node)) {
            return array_map(fn (mixed $item): mixed => $this->asWritten($item, $at, $stack), $node);
        }

        if (! $node instanceof stdClass) {
            return $node;
        }

        $copy = new stdClass;

        foreach (get_object_vars($node) as $key => $value) {
            if ($key !== '$ref') {
                $copy->{$key} = $this->asWritten($value, $at, $stack);
            }
        }

        if (! property_exists($node, '$ref')) {
            return $copy;
        }

        $ref = (string) $node->{'$ref'};

        if (in_array($ref, $stack, true)) {
            throw new JsonStructureSchemaException($this->label, $at, "tham chiếu vòng {$ref} trong phần giữ nguyên");
        }

        $target = $this->asWritten($this->resolve($ref, $at), $at, [...$stack, $ref]);

        return get_object_vars($copy) === [] ? $target : (object) ['allOf' => [$target, $copy]];
    }

    /** A reference into this schema, checked to point at a node; a reference to another schema is left to the validator, which reports it as a fault of the check. */
    private function checkedRef(mixed $ref, string $at): mixed
    {
        if (! is_string($ref)) {
            throw new JsonStructureSchemaException($this->label, $at, '$ref không phải chuỗi');
        }

        $hash = strpos($ref, '#');
        $base = $hash === false ? $ref : substr($ref, 0, $hash);

        if ($base === '' || $base === (string) ($this->root->{'$id'} ?? '')) {
            $this->resolve($ref, $at);
        }

        return $ref;
    }

    private function resolve(string $ref, string $at): mixed
    {
        $hash = strpos($ref, '#');
        $base = $hash === false ? $ref : substr($ref, 0, $hash);

        if ($hash === false || ($base !== '' && $base !== (string) ($this->root->{'$id'} ?? ''))) {
            throw new JsonStructureSchemaException($this->label, $at, "tham chiếu ngoài schema {$ref}");
        }

        $node = $this->root;
        $pointer = substr($ref, $hash + 1);

        foreach ($pointer === '' ? [] : explode('/', ltrim($pointer, '/')) as $step) {
            $step = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($step));

            if ($node instanceof stdClass && property_exists($node, $step)) {
                $node = $node->{$step};
            } elseif (is_array($node) && ctype_digit($step) && array_key_exists((int) $step, $node)) {
                $node = $node[(int) $step];
            } else {
                throw new JsonStructureSchemaException($this->label, $at, "không tìm thấy {$ref}");
            }
        }

        return $node;
    }

    private static function escape(string $key): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $key);
    }
}
