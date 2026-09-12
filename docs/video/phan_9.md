Được. **Phần 9** này tôi sẽ bám chặt Phần 1–8 và xử lý triệt để điểm còn nguy hiểm nhất hiện tại:

> **DTO → JSON không được làm mất kiểu `{}` / `[]`, JSON đã validate phải chính là JSON được hash và freeze.**

Tôi cũng chỉnh một điểm kiến trúc nhỏ nhưng cần thiết: từ Phần 9, `CanonicalConceptProcessor` không nên chỉ trả `CanonicalDesignSpec`; nó phải trả cả **DTO + canonical JSON đã revalidate**. Nếu không, `Freezer` buộc phải serialize lại lần nữa và ta không còn bảo đảm “validated bytes = hashed bytes”.

---

# Phần 9 — Serialization + Hashing + Freeze

Cây code:

```text
app/Video/Concept/
├── Serialization/
│   ├── CanonicalJsonSerializer.php
│   ├── CanonicalSerializationException.php
│   ├── JsonSchemaTypeResolver.php
│   └── SchemaAwareCanonicalSerializer.php
│
├── Normalize/
│   └── CanonicalDesignSpecNormalizer.php
│
├── Hashing/
│   ├── CanonicalDesignSpecHasher.php
│   └── EffectiveConceptSchemaHasher.php
│
├── Processing/
│   └── ProcessedCanonicalConcept.php
│
├── Freeze/
│   ├── FrozenCanonicalConcept.php
│   ├── FrozenCanonicalConceptMetadata.php
│   └── CanonicalConceptFreezer.php
│
└── Support/
    ├── Clock.php
    └── SystemClock.php
```

Và flow chính thức:

```text
CanonicalDesignSpec
        ↓
Normalizer
        ↓
SchemaAwareCanonicalSerializer
        ↓
canonicalJson
        ↓
Core validation
Effective validation
Semantic validation
        ↓
ProcessedCanonicalConcept
    ├── spec
    ├── canonicalJson
    └── effectiveSchema
        ↓
Hasher(canonicalJson)
        ↓
Freezer
        ↓
FrozenCanonicalConcept
```

---

# 9.1 `CanonicalJsonSerializer.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Serialization;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Schema\EffectiveConceptSchema;

interface CanonicalJsonSerializer
{
    public function serialize(
        CanonicalDesignSpec $spec,
        EffectiveConceptSchema $schema,
    ): string;
}
```

Serializer bắt buộc nhận `EffectiveConceptSchema`.

Không serialize DTO “mù” nữa.

---

# 9.2 `CanonicalSerializationException.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Serialization;

use RuntimeException;

final class CanonicalSerializationException
    extends RuntimeException
{
}
```

---

# 9.3 `JsonSchemaTypeResolver.php`

Đây là component quan trọng.

Nó phải hiểu:

```text
type
$ref
oneOf
const discriminator
```

để biết PHP `[]` phải encode thành:

```json
{}
```

hay:

```json
[]
```

Code:

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Serialization;

use RuntimeException;

final class JsonSchemaTypeResolver
{
    /**
     * @param array<string,mixed> $rootSchema
     * @param array<string,mixed> $nodeSchema
     *
     * @return array<string,mixed>
     */
    public function resolveNode(
        array $rootSchema,
        array $nodeSchema,
        mixed $value,
    ): array {
        $resolved =
            $this->resolveRef(
                rootSchema: $rootSchema,
                nodeSchema: $nodeSchema,
            );

        if (isset($resolved['oneOf'])) {
            return $this->resolveOneOf(
                rootSchema: $rootSchema,
                nodeSchema: $resolved,
                value: $value,
            );
        }

        return $resolved;
    }

    /**
     * @param array<string,mixed> $rootSchema
     * @param array<string,mixed> $nodeSchema
     *
     * @return array<string,mixed>
     */
    private function resolveRef(
        array $rootSchema,
        array $nodeSchema,
    ): array {
        $ref =
            $nodeSchema['$ref']
            ?? null;

        if ($ref === null) {
            return $nodeSchema;
        }

        if (!is_string($ref)) {
            throw new CanonicalSerializationException(
                'JSON Schema $ref must be a string.'
            );
        }

        if (!str_starts_with($ref, '#/')) {
            throw new CanonicalSerializationException(
                'Only local JSON Schema references are supported: '
                . $ref
            );
        }

        $segments =
            explode(
                '/',
                substr($ref, 2)
            );

        $current =
            $rootSchema;

        foreach ($segments as $segment) {
            $segment =
                str_replace(
                    ['~1', '~0'],
                    ['/', '~'],
                    $segment
                );

            if (
                !is_array($current)
                || !array_key_exists(
                    $segment,
                    $current
                )
            ) {
                throw new CanonicalSerializationException(
                    'Unable to resolve JSON Schema reference: '
                    . $ref
                );
            }

            $current =
                $current[$segment];
        }

        if (
            !is_array($current)
            || array_is_list($current)
        ) {
            throw new CanonicalSerializationException(
                'Resolved JSON Schema reference '
                . 'must point to an object schema: '
                . $ref
            );
        }

        /*
         * Nếu $ref có sibling keywords,
         * merge chúng lên resolved schema.
         */
        $siblings =
            $nodeSchema;

        unset($siblings['$ref']);

        return array_replace(
            $current,
            $siblings
        );
    }

    /**
     * @param array<string,mixed> $rootSchema
     * @param array<string,mixed> $nodeSchema
     *
     * @return array<string,mixed>
     */
    private function resolveOneOf(
        array $rootSchema,
        array $nodeSchema,
        mixed $value,
    ): array {
        $branches =
            $nodeSchema['oneOf']
            ?? null;

        if (
            !is_array($branches)
            || $branches === []
        ) {
            throw new CanonicalSerializationException(
                'oneOf must contain at least one schema.'
            );
        }

        /*
         * Canonical V1 relationships dùng:
         *
         * {
         *   "type": {"const":"count"}
         * }
         *
         * nên discriminator "type" là lựa chọn
         * deterministic nhất.
         */
        if (
            is_array($value)
            && isset($value['type'])
            && is_string($value['type'])
        ) {
            foreach ($branches as $branch) {
                if (!is_array($branch)) {
                    continue;
                }

                $candidate =
                    $this->resolveNode(
                        rootSchema: $rootSchema,
                        nodeSchema: $branch,
                        value: $value,
                    );

                $const =
                    $candidate['properties']
                        ['type']
                        ['const']
                    ?? null;

                if (
                    is_string($const)
                    && $const === $value['type']
                ) {
                    return $candidate;
                }
            }
        }

        /*
         * Generic fallback:
         * tìm branch có required/const tương thích.
         */
        $matches = [];

        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                continue;
            }

            $candidate =
                $this->resolveNode(
                    rootSchema: $rootSchema,
                    nodeSchema: $branch,
                    value: $value,
                );

            if (
                $this->structurallyMatches(
                    $candidate,
                    $value
                )
            ) {
                $matches[] =
                    $candidate;
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        throw new CanonicalSerializationException(
            sprintf(
                'Unable to deterministically select oneOf branch; matches=%d.',
                count($matches)
            )
        );
    }

    /**
     * @param array<string,mixed> $schema
     */
    private function structurallyMatches(
        array $schema,
        mixed $value,
    ): bool {
        $type =
            $schema['type']
            ?? null;

        if (
            $type === 'object'
            && !is_array($value)
        ) {
            return false;
        }

        if (
            $type === 'array'
            && !is_array($value)
        ) {
            return false;
        }

        if (
            $type === 'object'
            && is_array($value)
        ) {
            $required =
                $schema['required']
                ?? [];

            if (is_array($required)) {
                foreach ($required as $key) {
                    if (
                        !is_string($key)
                        || !array_key_exists(
                            $key,
                            $value
                        )
                    ) {
                        return false;
                    }
                }
            }

            $properties =
                $schema['properties']
                ?? [];

            if (is_array($properties)) {
                foreach (
                    $properties
                    as $key => $propertySchema
                ) {
                    if (
                        !array_key_exists(
                            $key,
                            $value
                        )
                        || !is_array($propertySchema)
                    ) {
                        continue;
                    }

                    if (
                        isset($propertySchema['const'])
                        && $value[$key]
                            !== $propertySchema['const']
                    ) {
                        return false;
                    }
                }
            }
        }

        return true;
    }
}
```

---

# 9.4 Vì sao resolver này cần thiết?

Canonical Core có:

```json
"relationships": {
  "type": "array",
  "items": {
    "oneOf": [
      {"$ref":"#/$defs/countRelationship"},
      {"$ref":"#/$defs/groupingRelationship"},
      ...
    ]
  }
}
```

Khi serializer gặp:

```php
[
    'id' => 'R001',
    'type' => 'count',
    ...
]
```

nó phải biết branch là:

```text
countRelationship
```

để serialize đúng nested schema.

Không nên serialize `oneOf` bằng heuristic mơ hồ.

Canonical V1 đã có discriminator:

```text
type
```

nên tận dụng nó.

---

# 9.5 `SchemaAwareCanonicalSerializer.php`

Đây là file chính.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Serialization;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Schema\EffectiveConceptSchema;
use JsonException;
use stdClass;

final class SchemaAwareCanonicalSerializer
    implements CanonicalJsonSerializer
{
    public const VERSION =
        'canonical-json-v1';

    public function __construct(
        private readonly JsonSchemaTypeResolver $typeResolver,
    ) {
    }

    public function serialize(
        CanonicalDesignSpec $spec,
        EffectiveConceptSchema $schema,
    ): string {
        $data =
            $spec->toArray();

        $normalized =
            $this->convert(
                value:
                    $data,

                nodeSchema:
                    $schema->schema,

                rootSchema:
                    $schema->schema,

                path:
                    '$',
            );

        try {
            return json_encode(
                $normalized,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (JsonException $e) {
            throw new CanonicalSerializationException(
                'Unable to encode canonical JSON: '
                . $e->getMessage(),
                previous:
                    $e,
            );
        }
    }

    /**
     * @param array<string,mixed> $nodeSchema
     * @param array<string,mixed> $rootSchema
     */
    private function convert(
        mixed $value,
        array $nodeSchema,
        array $rootSchema,
        string $path,
    ): mixed {
        $schema =
            $this->typeResolver
                ->resolveNode(
                    rootSchema:
                        $rootSchema,

                    nodeSchema:
                        $nodeSchema,

                    value:
                        $value,
                );

        $type =
            $schema['type']
            ?? null;

        if (!is_string($type)) {
            /*
             * const-only schemas như:
             *
             * "type": {"const":"count"}
             *
             * không nhất thiết có "type":"string".
             */
            if (array_key_exists('const', $schema)) {
                return $this->convertConst(
                    value:
                        $value,

                    const:
                        $schema['const'],

                    path:
                        $path,
                );
            }

            throw new CanonicalSerializationException(
                "Schema type is unresolved at {$path}."
            );
        }

        return match ($type) {
            'object' =>
                $this->convertObject(
                    value:
                        $value,

                    schema:
                        $schema,

                    rootSchema:
                        $rootSchema,

                    path:
                        $path,
                ),

            'array' =>
                $this->convertArray(
                    value:
                        $value,

                    schema:
                        $schema,

                    rootSchema:
                        $rootSchema,

                    path:
                        $path,
                ),

            'string' =>
                $this->convertString(
                    $value,
                    $path
                ),

            'integer' =>
                $this->convertInteger(
                    $value,
                    $path
                ),

            'number' =>
                $this->convertNumber(
                    $value,
                    $path
                ),

            'boolean' =>
                $this->convertBoolean(
                    $value,
                    $path
                ),

            'null' =>
                $this->convertNull(
                    $value,
                    $path
                ),

            default =>
                throw new CanonicalSerializationException(
                    sprintf(
                        'Unsupported schema type "%s" at %s.',
                        $type,
                        $path
                    )
                ),
        };
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<string,mixed> $rootSchema
     */
    private function convertObject(
        mixed $value,
        array $schema,
        array $rootSchema,
        string $path,
    ): stdClass {
        if (!is_array($value)) {
            throw new CanonicalSerializationException(
                "Expected object-compatible array at {$path}."
            );
        }

        $properties =
            $schema['properties']
            ?? [];

        if (!is_array($properties)) {
            throw new CanonicalSerializationException(
                "Object schema properties invalid at {$path}."
            );
        }

        $additionalProperties =
            $schema['additionalProperties']
            ?? true;

        $result = [];

        foreach ($value as $key => $child) {
            if (!is_string($key)) {
                throw new CanonicalSerializationException(
                    "Object key must be string at {$path}."
                );
            }

            $childPath =
                $path . '.' . $key;

            if (isset($properties[$key])) {
                if (!is_array($properties[$key])) {
                    throw new CanonicalSerializationException(
                        "Invalid property schema at {$childPath}."
                    );
                }

                $result[$key] =
                    $this->convert(
                        value:
                            $child,

                        nodeSchema:
                            $properties[$key],

                        rootSchema:
                            $rootSchema,

                        path:
                            $childPath,
                    );

                continue;
            }

            if ($additionalProperties === false) {
                throw new CanonicalSerializationException(
                    "Unknown property {$childPath}."
                );
            }

            /*
             * Production effective profiles của ta
             * phải closed.
             *
             * Nếu additionalProperties là schema,
             * vẫn support.
             */
            if (is_array($additionalProperties)) {
                $result[$key] =
                    $this->convert(
                        value:
                            $child,

                        nodeSchema:
                            $additionalProperties,

                        rootSchema:
                            $rootSchema,

                        path:
                            $childPath,
                    );

                continue;
            }

            /*
             * Không serialize schema-less
             * arbitrary value trong canonical path.
             */
            throw new CanonicalSerializationException(
                'Unresolved open object property at '
                . $childPath
            );
        }

        /*
         * Object key order là canonical set.
         */
        ksort(
            $result,
            SORT_STRING
        );

        $object =
            new stdClass();

        foreach ($result as $key => $child) {
            $object->{$key} =
                $child;
        }

        /*
         * Empty PHP [] trở thành stdClass {}
         * vì schema nói đây là object.
         */
        return $object;
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<string,mixed> $rootSchema
     */
    private function convertArray(
        mixed $value,
        array $schema,
        array $rootSchema,
        string $path,
    ): array {
        if (!is_array($value)) {
            throw new CanonicalSerializationException(
                "Expected array at {$path}."
            );
        }

        /*
         * JSON array phải là PHP list.
         *
         * Không tự array_values() một associative
         * array vì như vậy sẽ che lỗi data.
         */
        if (!array_is_list($value)) {
            throw new CanonicalSerializationException(
                "Expected list array at {$path}."
            );
        }

        $itemsSchema =
            $schema['items']
            ?? null;

        if (!is_array($itemsSchema)) {
            throw new CanonicalSerializationException(
                "Array items schema missing at {$path}."
            );
        }

        $result = [];

        foreach (
            $value
            as $index => $child
        ) {
            $result[] =
                $this->convert(
                    value:
                        $child,

                    nodeSchema:
                        $itemsSchema,

                    rootSchema:
                        $rootSchema,

                    path:
                        $path
                        . '['
                        . $index
                        . ']',
                );
        }

        /*
         * List order được giữ nguyên.
         *
         * Serializer không sort arrays.
         */
        return $result;
    }

    private function convertString(
        mixed $value,
        string $path,
    ): string {
        if (!is_string($value)) {
            throw new CanonicalSerializationException(
                "Expected string at {$path}."
            );
        }

        return $value;
    }

    private function convertInteger(
        mixed $value,
        string $path,
    ): int {
        if (!is_int($value)) {
            throw new CanonicalSerializationException(
                "Expected integer at {$path}."
            );
        }

        return $value;
    }

    private function convertNumber(
        mixed $value,
        string $path,
    ): float {
        if (
            !is_int($value)
            && !is_float($value)
        ) {
            throw new CanonicalSerializationException(
                "Expected number at {$path}."
            );
        }

        if (!is_finite((float) $value)) {
            throw new CanonicalSerializationException(
                "Non-finite number at {$path}."
            );
        }

        /*
         * Canonical-json-v1 policy:
         *
         * JSON Schema number -> PHP float.
         *
         * Nhờ vậy:
         *
         * 6
         * 6.0
         *
         * đều canonicalize thành cùng numeric
         * representation 6.0.
         */
        return (float) $value;
    }

    private function convertBoolean(
        mixed $value,
        string $path,
    ): bool {
        if (!is_bool($value)) {
            throw new CanonicalSerializationException(
                "Expected boolean at {$path}."
            );
        }

        return $value;
    }

    private function convertNull(
        mixed $value,
        string $path,
    ): null {
        if ($value !== null) {
            throw new CanonicalSerializationException(
                "Expected null at {$path}."
            );
        }

        return null;
    }

    private function convertConst(
        mixed $value,
        mixed $const,
        string $path,
    ): mixed {
        if ($value !== $const) {
            throw new CanonicalSerializationException(
                sprintf(
                    'Const mismatch at %s.',
                    $path
                )
            );
        }

        return $value;
    }
}
```

---

# 9.6 Đây giải quyết `{}` / `[]` như thế nào?

Ví dụ DTO chứa:

```php
$spec->finishedMaterials = [];
```

PHP bình thường:

```php
json_encode([])
```

ra:

```json
[]
```

Sai vì schema nói:

```json
{
    "finished_materials": {
        "type": "object"
    }
}
```

Serializer mới đọc Effective Schema và tạo:

```php
new stdClass()
```

nên JSON thành:

```json
"finished_materials": {}
```

Ngược lại nếu:

```text
relationships
```

schema nói array thì empty PHP list:

```php
[]
```

vẫn serialize:

```json
"relationships": []
```

Quan trọng hơn, cách này hoạt động **recursive**, không chỉ bốn field root.

Ví dụ:

```php
[
    'superstructure' => [],
]
```

nếu schema nói:

```text
superstructure = object
```

thì:

```json
{
  "superstructure": {}
}
```

chứ không thành:

```json
{
  "superstructure": []
}
```

Đây mới là cách giải quyết triệt để bug mà serializer root-only ở phần trước chưa giải quyết được.

---

# 9.7 Normalizer

Ta giữ nguyên nguyên tắc Phần 4:

```text
normalizer
≠ repairer
≠ validator
```

Normalizer chỉ canonicalize các collection mà semantics đã xác định là unordered set.

Bản chốt:

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Normalize;

use App\Video\Concept\Canonical\CanonicalDesignSpec;

final class CanonicalDesignSpecNormalizer
{
    public const VERSION =
        'canonical-normalizer-v1';

    public function normalize(
        CanonicalDesignSpec $spec
    ): CanonicalDesignSpec {
        $data =
            $spec->toArray();

        /*
         * relationships là set keyed by stable ID.
         */
        usort(
            $data['relationships'],
            static fn (
                array $a,
                array $b
            ): int =>
                strcmp(
                    (string) $a['id'],
                    (string) $b['id']
                )
        );

        /*
         * exclusions là set keyed by stable ID.
         */
        usort(
            $data['exclusions'],
            static fn (
                array $a,
                array $b
            ): int =>
                strcmp(
                    (string) $a['id'],
                    (string) $b['id']
                )
        );

        /*
         * invariants là set keyed by stable ID.
         */
        usort(
            $data['invariants'],
            static fn (
                array $a,
                array $b
            ): int =>
                strcmp(
                    (string) $a['id'],
                    (string) $b['id']
                )
        );

        /*
         * provenance target_path là identity key.
         */
        foreach (
            $data['provenance']
            as &$entry
        ) {
            if (
                isset($entry['source_aspects'])
                && is_array(
                    $entry['source_aspects']
                )
            ) {
                sort(
                    $entry['source_aspects'],
                    SORT_STRING
                );
            }
        }

        unset($entry);

        usort(
            $data['provenance'],
            static fn (
                array $a,
                array $b
            ): int =>
                strcmp(
                    (string) $a['target_path'],
                    (string) $b['target_path']
                )
        );

        /*
         * KHÔNG sort:
         *
         * identity.identity_basis
         * OrderRelationship.items
         * hoặc bất kỳ list nào order có nghĩa.
         */

        return CanonicalDesignSpec
            ::fromArray(
                $data
            );
    }
}
```

---

# 9.8 Không normalize dimension math ở đây

Không:

```php
$ratio = $length / $beam;
```

Không:

```php
round($length, 2);
```

Không:

```php
lowercase($stem);
```

Không:

```php
trim semantic prose aggressively
```

Vì những việc đó có thể thay design meaning.

Pipeline vẫn là:

```text
Validator detects contradiction
↓
Sonnet repair once
↓
Normalizer only canonicalizes representation
```

---

# 9.9 `CanonicalDesignSpecHasher.php`

Hasher từ giờ chỉ nhận **string canonical JSON**.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Hashing;

use InvalidArgumentException;

final class CanonicalDesignSpecHasher
{
    public const ALGORITHM =
        'sha256';

    public function hash(
        string $canonicalJson
    ): string {
        if ($canonicalJson === '') {
            throw new InvalidArgumentException(
                'Canonical JSON must not be empty.'
            );
        }

        return hash(
            self::ALGORITHM,
            $canonicalJson
        );
    }
}
```

Không còn:

```php
hash($spec->toArray())
```

Không còn:

```php
json_encode trong Hasher
```

Hasher không có quyền quyết định representation.

---

# 9.10 Invariant quan trọng

Từ Phần 9:

```text
Serializer owns bytes.
Validator approves bytes.
Hasher hashes those bytes.
Freezer stores those bytes.
```

Hay:

```text
serialized bytes
      ==
validated bytes
      ==
hashed bytes
      ==
frozen bytes
```

Đây là invariant production-grade.

---

# 9.11 `EffectiveConceptSchemaHasher.php`

Ta cũng nên fingerprint effective schema.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Hashing;

use App\Video\Concept\Schema\EffectiveConceptSchema;
use JsonException;
use stdClass;

final class EffectiveConceptSchemaHasher
{
    public const VERSION =
        'effective-schema-hash-v1';

    public function hash(
        EffectiveConceptSchema $schema
    ): string {
        $canonical =
            $this->canonicalize(
                $schema->schema
            );

        try {
            $json =
                json_encode(
                    $canonical,
                    JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_PRESERVE_ZERO_FRACTION
                );
        } catch (JsonException $e) {
            throw new \RuntimeException(
                'Unable to encode effective schema.',
                previous:
                    $e,
            );
        }

        return hash(
            'sha256',
            $json
        );
    }

    private function canonicalize(
        mixed $value
    ): mixed {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed =>
                    $this->canonicalize(
                        $item
                    ),
                $value
            );
        }

        ksort(
            $value,
            SORT_STRING
        );

        $object =
            new stdClass();

        foreach (
            $value
            as $key => $child
        ) {
            $object->{$key} =
                $this->canonicalize(
                    $child
                );
        }

        return $object;
    }
}
```

Ở đây schema arrays như:

```text
required
oneOf
enum
```

giữ nguyên order.

Object keys sort.

---

# 9.12 `ProcessedCanonicalConcept.php`

Đây là thay đổi quan trọng so với Phần 7–8.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Processing;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Schema\EffectiveConceptSchema;

final class ProcessedCanonicalConcept
{
    public function __construct(
        public readonly CanonicalDesignSpec $spec,
        public readonly string $canonicalJson,
        public readonly EffectiveConceptSchema $effectiveSchema,
    ) {
        if ($this->canonicalJson === '') {
            throw new \InvalidArgumentException(
                'canonicalJson must not be empty.'
            );
        }
    }
}
```

Processor từ giờ trả class này.

---

# 9.13 Cập nhật `CanonicalConceptProcessor`

Signature cũ:

```php
public function process(...): CanonicalDesignSpec
```

thành:

```php
public function process(
    string $rawJson,
    ConceptInput $input,
): ProcessedCanonicalConcept
```

Bản full logic:

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Exceptions\CanonicalValidationException;
use App\Video\Concept\Normalize\CanonicalDesignSpecNormalizer;
use App\Video\Concept\Processing\ProcessedCanonicalConcept;
use App\Video\Concept\Schema\EffectiveConceptSchemaBuilder;
use App\Video\Concept\Serialization\CanonicalJsonSerializer;
use App\Video\Concept\Validation\CanonicalDesignSpecValidator;
use App\Video\Concept\Validation\CanonicalSchemaValidator;
use App\Video\Concept\Validation\EffectiveSchemaValidator;
use App\Video\Concept\Validation\ValidationError;
use JsonException;
use Throwable;

final class CanonicalConceptProcessor
{
    public function __construct(
        private readonly CanonicalSchemaValidator $coreSchemaValidator,
        private readonly EffectiveSchemaValidator $effectiveSchemaValidator,
        private readonly EffectiveConceptSchemaBuilder $schemaBuilder,
        private readonly CanonicalDesignSpecValidator $semanticValidator,
        private readonly CanonicalDesignSpecNormalizer $normalizer,
        private readonly CanonicalJsonSerializer $serializer,
    ) {
    }

    public function process(
        string $rawJson,
        ConceptInput $input,
    ): ProcessedCanonicalConcept {
        /*
         * -----------------------------------------
         * 1. Build Effective Schema exactly once.
         * -----------------------------------------
         */
        $effective =
            $this->schemaBuilder
                ->build(
                    $input->profile
                );

        /*
         * -----------------------------------------
         * 2. Core schema validation.
         * -----------------------------------------
         */
        $this->coreSchemaValidator
            ->validateJsonOrFail(
                $rawJson
            );

        /*
         * -----------------------------------------
         * 3. Effective profile validation.
         * -----------------------------------------
         */
        $effectiveResult =
            $this->effectiveSchemaValidator
                ->validate(
                    $rawJson,
                    $effective
                );

        if ($effectiveResult->fails()) {
            throw new CanonicalValidationException(
                errors:
                    $effectiveResult->errors,

                failedRawJson:
                    $rawJson,

                message:
                    'Effective profile schema '
                    . 'validation failed.'
            );
        }

        /*
         * -----------------------------------------
         * 4. DTO hydration.
         * -----------------------------------------
         */
        try {
            $data =
                json_decode(
                    $rawJson,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

            if (
                !is_array($data)
                || array_is_list($data)
            ) {
                throw new \RuntimeException(
                    'Canonical JSON root '
                    . 'must be an object.'
                );
            }

            $spec =
                CanonicalDesignSpec
                    ::fromArray(
                        $data
                    );
        } catch (Throwable $e) {
            throw new CanonicalValidationException(
                errors: [
                    new ValidationError(
                        code:
                            'dto_hydration_failed',

                        path:
                            '$',

                        message:
                            $e->getMessage(),
                    ),
                ],

                failedRawJson:
                    $rawJson,

                message:
                    'Canonical DTO hydration failed.'
            );
        }

        /*
         * -----------------------------------------
         * 5. Semantic validation.
         * -----------------------------------------
         */
        $semantic =
            $this->semanticValidator
                ->validate(
                    $spec,
                    $input
                );

        if ($semantic->fails()) {
            throw new CanonicalValidationException(
                errors:
                    $semantic->errors,

                failedRawJson:
                    $rawJson,

                message:
                    'Canonical semantic validation failed.'
            );
        }

        /*
         * -----------------------------------------
         * 6. Normalize.
         * -----------------------------------------
         */
        $normalized =
            $this->normalizer
                ->normalize(
                    $spec
                );

        /*
         * -----------------------------------------
         * 7. Schema-aware canonical serialization.
         * -----------------------------------------
         */
        $canonicalJson =
            $this->serializer
                ->serialize(
                    spec:
                        $normalized,

                    schema:
                        $effective,
                );

        /*
         * IMPORTANT:
         *
         * Từ đây canonicalJson chính là
         * candidate frozen bytes.
         */

        /*
         * -----------------------------------------
         * 8. Core re-validation.
         * -----------------------------------------
         */
        $this->coreSchemaValidator
            ->validateJsonOrFail(
                $canonicalJson
            );

        /*
         * -----------------------------------------
         * 9. Effective schema re-validation.
         * -----------------------------------------
         */
        $effectiveAfterNormalize =
            $this->effectiveSchemaValidator
                ->validate(
                    $canonicalJson,
                    $effective
                );

        if (
            $effectiveAfterNormalize
                ->fails()
        ) {
            throw new CanonicalValidationException(
                errors:
                    $effectiveAfterNormalize
                        ->errors,

                failedRawJson:
                    $canonicalJson,

                message:
                    'Canonical serialized document '
                    . 'failed effective schema validation.'
            );
        }

        /*
         * -----------------------------------------
         * 10. Semantic re-validation.
         * -----------------------------------------
         */
        $semanticAfterNormalize =
            $this->semanticValidator
                ->validate(
                    $normalized,
                    $input
                );

        if (
            $semanticAfterNormalize
                ->fails()
        ) {
            throw new CanonicalValidationException(
                errors:
                    $semanticAfterNormalize
                        ->errors,

                failedRawJson:
                    $canonicalJson,

                message:
                    'Canonical serialized document '
                    . 'failed semantic validation.'
            );
        }

        /*
         * -----------------------------------------
         * 11. Return exactly what was validated.
         * -----------------------------------------
         */
        return new ProcessedCanonicalConcept(
            spec:
                $normalized,

            canonicalJson:
                $canonicalJson,

            effectiveSchema:
                $effective,
        );
    }
}
```

---

# 9.14 Vì sao `ProcessedCanonicalConcept` cần thiết?

Nếu processor chỉ trả:

```php
CanonicalDesignSpec
```

thì Freezer phải làm:

```php
$json = $serializer->serialize($spec);
```

lần thứ hai.

Dù deterministic, architecture vẫn trở thành:

```text
validated JSON A
↓
DTO
↓
serialize JSON B
↓
hash B
```

Ta muốn:

```text
serialize B
↓
validate B
↓
return B
↓
hash B
↓
freeze B
```

Không có serialization nào giữa validation và hashing.

---

# 9.15 `Clock.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Support;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
```

---

# 9.16 `SystemClock.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Support;

use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC')
        );
    }
}
```

Frozen timestamp nên UTC.

---

# 9.17 `FrozenCanonicalConceptMetadata.php`

Metadata không cho vào Canonical Core V1.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Freeze;

final class FrozenCanonicalConceptMetadata
{
    public function __construct(
        public readonly string $canonicalSchemaVersion,
        public readonly string $profileKey,
        public readonly string $profileVersion,
        public readonly string $effectiveSchemaHash,
        public readonly string $semanticValidatorVersion,
        public readonly string $normalizerVersion,
        public readonly string $canonicalizerVersion,
        public readonly string $conceptModel,
        public readonly string $conceptPromptVersion,
    ) {
    }

    /**
     * @return array<string,string>
     */
    public function toArray(): array
    {
        return [
            'canonical_schema_version' =>
                $this->canonicalSchemaVersion,

            'profile_key' =>
                $this->profileKey,

            'profile_version' =>
                $this->profileVersion,

            'effective_schema_hash' =>
                $this->effectiveSchemaHash,

            'semantic_validator_version' =>
                $this->semanticValidatorVersion,

            'normalizer_version' =>
                $this->normalizerVersion,

            'canonicalizer_version' =>
                $this->canonicalizerVersion,

            'concept_model' =>
                $this->conceptModel,

            'concept_prompt_version' =>
                $this->conceptPromptVersion,
        ];
    }
}
```

---

# 9.18 `FrozenCanonicalConcept.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Freeze;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use DateTimeImmutable;
use InvalidArgumentException;

final class FrozenCanonicalConcept
{
    public function __construct(
        public readonly CanonicalDesignSpec $spec,
        public readonly string $canonicalJson,
        public readonly string $hash,
        public readonly int $revision,
        public readonly DateTimeImmutable $frozenAt,
        public readonly FrozenCanonicalConceptMetadata $metadata,
    ) {
        if ($this->canonicalJson === '') {
            throw new InvalidArgumentException(
                'canonicalJson must not be empty.'
            );
        }

        if (
            !preg_match(
                '/^[a-f0-9]{64}$/',
                $this->hash
            )
        ) {
            throw new InvalidArgumentException(
                'hash must be a SHA-256 hex digest.'
            );
        }

        if ($this->revision < 1) {
            throw new InvalidArgumentException(
                'revision must be >= 1.'
            );
        }
    }

    /**
     * Persistence representation.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'revision' =>
                $this->revision,

            'hash' =>
                $this->hash,

            'frozen_at' =>
                $this->frozenAt
                    ->format(DATE_ATOM),

            /*
             * Đây là canonical source-of-truth bytes.
             */
            'canonical_json' =>
                $this->canonicalJson,

            'metadata' =>
                $this->metadata
                    ->toArray(),
        ];
    }
}
```

Tôi **không đưa `spec->toArray()` vào persistence payload chính**.

Vì nếu DB lưu:

```text
canonical_json
```

thì đó mới là frozen truth.

DTO có thể hydrate lại từ canonical JSON.

---

# 9.19 `CanonicalConceptFreezer.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Freeze;

use App\Video\Concept\Hashing\CanonicalDesignSpecHasher;
use App\Video\Concept\Hashing\EffectiveConceptSchemaHasher;
use App\Video\Concept\Normalize\CanonicalDesignSpecNormalizer;
use App\Video\Concept\Processing\ProcessedCanonicalConcept;
use App\Video\Concept\Serialization\SchemaAwareCanonicalSerializer;
use App\Video\Concept\Support\Clock;
use App\Video\Profiles\Validation\CategorySemanticValidatorRegistry;

final class CanonicalConceptFreezer
{
    public function __construct(
        private readonly CanonicalDesignSpecHasher $designHasher,
        private readonly EffectiveConceptSchemaHasher $schemaHasher,
        private readonly CategorySemanticValidatorRegistry $semanticValidators,
        private readonly Clock $clock,
        private readonly string $conceptModel,
        private readonly string $conceptPromptVersion,
    ) {
    }

    public function freeze(
        ProcessedCanonicalConcept $processed,
        int $revision,
    ): FrozenCanonicalConcept {
        /*
         * Hash CHÍNH canonical JSON đã được
         * processor revalidate.
         */
        $hash =
            $this->designHasher
                ->hash(
                    $processed->canonicalJson
                );

        $effectiveSchemaHash =
            $this->schemaHasher
                ->hash(
                    $processed->effectiveSchema
                );

        $categoryValidator =
            $this->semanticValidators
                ->forProfile(
                    new \App\Video\Profiles\CategoryCreativeProfile(
                        key:
                            $processed
                                ->effectiveSchema
                                ->profileKey,

                        version:
                            $processed
                                ->effectiveSchema
                                ->profileVersion,

                        /*
                         * Freezer chỉ cần identity để lookup.
                         * Nhưng tạo profile giả ở đây không đẹp.
                         *
                         * Ta sẽ sửa ngay bên dưới.
                         */
                        schemaPath:
                            '__unused__',

                        inspectionAspects: [
                            '__unused__',
                        ],
                    )
                );

        return new FrozenCanonicalConcept(
            spec:
                $processed->spec,

            canonicalJson:
                $processed->canonicalJson,

            hash:
                $hash,

            revision:
                $revision,

            frozenAt:
                $this->clock->now(),

            metadata:
                new FrozenCanonicalConceptMetadata(
                    canonicalSchemaVersion:
                        $processed
                            ->effectiveSchema
                            ->coreVersion,

                    profileKey:
                        $processed
                            ->effectiveSchema
                            ->profileKey,

                    profileVersion:
                        $processed
                            ->effectiveSchema
                            ->profileVersion,

                    effectiveSchemaHash:
                        $effectiveSchemaHash,

                    semanticValidatorVersion:
                        $categoryValidator
                            ->version(),

                    normalizerVersion:
                        CanonicalDesignSpecNormalizer::VERSION,

                    canonicalizerVersion:
                        SchemaAwareCanonicalSerializer::VERSION,

                    conceptModel:
                        $this->conceptModel,

                    conceptPromptVersion:
                        $this->conceptPromptVersion,
                ),
        );
    }
}
```

Nhưng phần tạo `CategoryCreativeProfile` giả ở đây **không nên để production**.

Ta sửa Registry ngay.

---

# 9.20 Thêm `forProfileKey()` vào Registry

Trong:

```text
CategorySemanticValidatorRegistry.php
```

thêm:

```php
public function forProfileKey(
    string $profileKey
): CategorySemanticValidator {
    $profileKey =
        trim(
            $profileKey
        );

    $validator =
        $this->validators[
            $profileKey
        ]
        ?? null;

    if ($validator === null) {
        throw new RuntimeException(
            'No semantic validator registered '
            . 'for profile: '
            . $profileKey
        );
    }

    return $validator;
}
```

Rồi method cũ:

```php
public function forProfile(
    CategoryCreativeProfile $profile
): CategorySemanticValidator {
    return $this->forProfileKey(
        $profile->key
    );
}
```

---

# 9.21 `CanonicalConceptFreezer` final

Bản production:

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Freeze;

use App\Video\Concept\Hashing\CanonicalDesignSpecHasher;
use App\Video\Concept\Hashing\EffectiveConceptSchemaHasher;
use App\Video\Concept\Normalize\CanonicalDesignSpecNormalizer;
use App\Video\Concept\Processing\ProcessedCanonicalConcept;
use App\Video\Concept\Serialization\SchemaAwareCanonicalSerializer;
use App\Video\Concept\Support\Clock;
use App\Video\Profiles\Validation\CategorySemanticValidatorRegistry;
use InvalidArgumentException;

final class CanonicalConceptFreezer
{
    public function __construct(
        private readonly CanonicalDesignSpecHasher $designHasher,
        private readonly EffectiveConceptSchemaHasher $schemaHasher,
        private readonly CategorySemanticValidatorRegistry $semanticValidators,
        private readonly Clock $clock,
        private readonly string $conceptModel,
        private readonly string $conceptPromptVersion,
    ) {
    }

    public function freeze(
        ProcessedCanonicalConcept $processed,
        int $revision,
    ): FrozenCanonicalConcept {
        if ($revision < 1) {
            throw new InvalidArgumentException(
                'revision must be >= 1.'
            );
        }

        /*
         * Validated bytes = hashed bytes.
         */
        $designHash =
            $this->designHasher
                ->hash(
                    $processed->canonicalJson
                );

        $effectiveSchemaHash =
            $this->schemaHasher
                ->hash(
                    $processed->effectiveSchema
                );

        $categoryValidator =
            $this->semanticValidators
                ->forProfileKey(
                    $processed
                        ->effectiveSchema
                        ->profileKey
                );

        $metadata =
            new FrozenCanonicalConceptMetadata(
                canonicalSchemaVersion:
                    $processed
                        ->effectiveSchema
                        ->coreVersion,

                profileKey:
                    $processed
                        ->effectiveSchema
                        ->profileKey,

                profileVersion:
                    $processed
                        ->effectiveSchema
                        ->profileVersion,

                effectiveSchemaHash:
                    $effectiveSchemaHash,

                semanticValidatorVersion:
                    $categoryValidator
                        ->version(),

                normalizerVersion:
                    CanonicalDesignSpecNormalizer
                        ::VERSION,

                canonicalizerVersion:
                    SchemaAwareCanonicalSerializer
                        ::VERSION,

                conceptModel:
                    $this->conceptModel,

                conceptPromptVersion:
                    $this->conceptPromptVersion,
            );

        return new FrozenCanonicalConcept(
            spec:
                $processed->spec,

            canonicalJson:
                $processed->canonicalJson,

            hash:
                $designHash,

            revision:
                $revision,

            frozenAt:
                $this->clock->now(),

            metadata:
                $metadata,
        );
    }
}
```

---

# 9.22 BuildCanonicalConcept phải sửa

Trước:

```php
$normalized =
    $this->processor->process(...);

return $this->freezer
    ->freeze(
        $normalized,
        $revision
    );
```

Sau Phần 9:

```php
$processed =
    $this->processor
        ->process(
            rawJson:
                $initial->rawJson,

            input:
                $input,
        );

return $this->freezer
    ->freeze(
        processed:
            $processed,

        revision:
            $revision,
    );
```

Repair branch tương tự:

```php
$processed =
    $this->processor
        ->process(
            rawJson:
                $repaired->rawJson,

            input:
                $input,
        );

return $this->freezer
    ->freeze(
        processed:
            $processed,

        revision:
            $revision,
    );
```

---

# 9.23 `BuildCanonicalConcept` full flow

```php
public function build(
    ConceptInput $input,
    int $revision,
): FrozenCanonicalConcept {
    try {
        /*
         * Initial Sonnet generation.
         */
        $initial =
            $this->designer
                ->generate(
                    $input
                );

        /*
         * Complete processing:
         *
         * Core
         * Effective
         * DTO
         * Semantic
         * Normalize
         * Serialize
         * Revalidate
         */
        $processed =
            $this->processor
                ->process(
                    rawJson:
                        $initial->rawJson,

                    input:
                        $input,
                );

        return $this->freezer
            ->freeze(
                processed:
                    $processed,

                revision:
                    $revision,
            );
    } catch (
        CanonicalValidationException $firstFailure
    ) {
        $failedRawJson =
            $firstFailure
                ->failedRawJson();

        $errors =
            $firstFailure
                ->errors();

        if (
            $failedRawJson === null
            || $failedRawJson === ''
            || $errors === []
        ) {
            throw $firstFailure;
        }

        /*
         * Exactly ONE semantic repair.
         */
        $repaired =
            $this->repairer
                ->repair(
                    input:
                        $input,

                    failedRawJson:
                        $failedRawJson,

                    errors:
                        $errors,
                );

        /*
         * IMPORTANT:
         *
         * Không catch CanonicalValidationException
         * lần thứ hai để repair tiếp.
         */
        $processed =
            $this->processor
                ->process(
                    rawJson:
                        $repaired->rawJson,

                    input:
                        $input,
                );

        return $this->freezer
            ->freeze(
                processed:
                    $processed,

                revision:
                    $revision,
            );
    }
}
```

Repair policy Phần 5 không đổi.

---

# 9.24 ServiceProvider bindings

Thêm imports:

```php
use App\Video\Concept\Hashing\CanonicalDesignSpecHasher;
use App\Video\Concept\Hashing\EffectiveConceptSchemaHasher;
use App\Video\Concept\Serialization\CanonicalJsonSerializer;
use App\Video\Concept\Serialization\JsonSchemaTypeResolver;
use App\Video\Concept\Serialization\SchemaAwareCanonicalSerializer;
use App\Video\Concept\Support\Clock;
use App\Video\Concept\Support\SystemClock;
```

Bindings:

```php
$this->app->singleton(
    JsonSchemaTypeResolver::class
);

$this->app->singleton(
    SchemaAwareCanonicalSerializer::class
);

$this->app->singleton(
    CanonicalJsonSerializer::class,
    SchemaAwareCanonicalSerializer::class
);

$this->app->singleton(
    CanonicalDesignSpecHasher::class
);

$this->app->singleton(
    EffectiveConceptSchemaHasher::class
);

$this->app->singleton(
    Clock::class,
    SystemClock::class
);
```

---

# 9.25 Freezer binding

```php
$this->app->singleton(
    CanonicalConceptFreezer::class,
    function (
        Application $app
    ): CanonicalConceptFreezer {
        $model =
            config(
                'canonical_concept.anthropic.model'
            );

        $promptVersion =
            config(
                'canonical_concept.prompt_version',
                'concept-v1'
            );

        if (
            !is_string($model)
            || trim($model) === ''
        ) {
            throw new RuntimeException(
                'Canonical concept model '
                . 'must be explicitly configured.'
            );
        }

        if (
            !is_string($promptVersion)
            || trim($promptVersion) === ''
        ) {
            throw new RuntimeException(
                'Canonical concept prompt version '
                . 'must be configured.'
            );
        }

        return new CanonicalConceptFreezer(
            designHasher:
                $app->make(
                    CanonicalDesignSpecHasher::class
                ),

            schemaHasher:
                $app->make(
                    EffectiveConceptSchemaHasher::class
                ),

            semanticValidators:
                $app->make(
                    CategorySemanticValidatorRegistry::class
                ),

            clock:
                $app->make(
                    Clock::class
                ),

            conceptModel:
                $model,

            conceptPromptVersion:
                $promptVersion,
        );
    }
);
```

---

# 9.26 Config thêm version metadata

```php
return [

    'prompt_version' =>
        env(
            'CANONICAL_CONCEPT_PROMPT_VERSION',
            'concept-v1'
        ),

    // ...

];
```

`.env`:

```dotenv
CANONICAL_CONCEPT_MODEL=claude-sonnet-5
CANONICAL_CONCEPT_PROMPT_VERSION=concept-v1
```

Normalizer/canonicalizer version là constants trong code:

```text
canonical-normalizer-v1
canonical-json-v1
```

---

# 9.27 Không hash revision

Ví dụ canonical design giống hệt nhau:

```text
revision 1
hash = abc...

revision 2
hash = abc...
```

Điều này **đúng**.

Hash trả lời:

> Nội dung canonical design này là gì?

Revision trả lời:

> Đây là lần freeze thứ mấy?

Do đó không hash:

```text
revision
frozen_at
model
profile metadata
```

vào design hash.

---

# 9.28 Nhưng Effective Schema có hash riêng

Frozen record có hai hash:

```text
canonical_hash
effective_schema_hash
```

Ví dụ:

```json
{
  "canonical_hash":
    "abc123...",

  "metadata": {
    "effective_schema_hash":
      "def456..."
  }
}
```

Ta biết:

```text
Design bytes nào?
↓
canonical_hash

Validated bởi exact schema nào?
↓
effective_schema_hash
```

Rất hữu ích khi audit sau này.

---

# 9.29 Test `{}` vs `[]`

Đây là test bắt buộc.

```php
public function test_empty_object_is_serialized_as_json_object(): void
{
    $spec =
        $this->makeSpec(
            finishedMaterials: []
        );

    $json =
        $this->serializer()
            ->serialize(
                $spec,
                $this->marineEffectiveSchema()
            );

    $decoded =
        json_decode(
            $json,
            false,
            512,
            JSON_THROW_ON_ERROR
        );

    self::assertInstanceOf(
        \stdClass::class,
        $decoded->finished_materials
    );
}
```

---

# 9.30 Test empty array giữ là array

```php
public function test_empty_relationships_remain_json_array(): void
{
    $spec =
        $this->makeSpec(
            relationships: []
        );

    $json =
        $this->serializer()
            ->serialize(
                $spec,
                $this->effectiveSchema()
            );

    $decoded =
        json_decode(
            $json,
            false,
            512,
            JSON_THROW_ON_ERROR
        );

    self::assertIsArray(
        $decoded->relationships
    );

    self::assertSame(
        [],
        $decoded->relationships
    );
}
```

---

# 9.31 Test nested empty object

Đây là test mà root-only serializer trước đây không qua được.

Ví dụ schema:

```json
{
  "superstructure": {
    "type": "object",
    "additionalProperties": false,
    "properties": {}
  }
}
```

DTO:

```php
'permanent_geometry' => [
    'superstructure' => [],
]
```

Test:

```php
public function test_nested_empty_object_preserves_object_type(): void
{
    $json =
        $this->serializer()
            ->serialize(
                $this->specWithEmptyNestedObject(),
                $this->effectiveSchema()
            );

    $decoded =
        json_decode(
            $json,
            false,
            512,
            JSON_THROW_ON_ERROR
        );

    self::assertInstanceOf(
        \stdClass::class,
        $decoded
            ->permanent_geometry
            ->superstructure
    );
}
```

Đây là regression test quan trọng.

---

# 9.32 Hash determinism test

```php
public function test_same_semantics_produce_same_hash(): void
{
    $a =
        $this->makeEquivalentSpecA();

    $b =
        $this->makeEquivalentSpecB();

    $effective =
        $this->effectiveSchema();

    $jsonA =
        $this->serializer()
            ->serialize(
                $this->normalizer()
                    ->normalize($a),
                $effective
            );

    $jsonB =
        $this->serializer()
            ->serialize(
                $this->normalizer()
                    ->normalize($b),
                $effective
            );

    self::assertSame(
        $jsonA,
        $jsonB
    );

    self::assertSame(
        $this->hasher()
            ->hash($jsonA),

        $this->hasher()
            ->hash($jsonB)
    );
}
```

---

# 9.33 Number canonicalization test

Với schema:

```json
{
  "type": "number"
}
```

hai input:

```php
6
6.0
```

phải canonicalize cùng representation.

```php
public function test_number_schema_normalizes_int_and_float_equivalently(): void
{
    /*
     * Spec A:
     * ratio = 6
     *
     * Spec B:
     * ratio = 6.0
     */

    $jsonA = ...;
    $jsonB = ...;

    self::assertSame(
        $jsonA,
        $jsonB
    );
}
```

Với policy hiện tại sẽ thành:

```json
6.0
```

---

# 9.34 Integer không được biến thành float

Schema:

```json
{
  "type": "integer"
}
```

`primary_tier_count`:

```php
4
```

vẫn:

```json
4
```

không:

```json
4.0
```

Điều này quan trọng cho stable bytes.

---

# 9.35 Serializer phải reject unknown property

Nếu DTO somehow có:

```php
'dimensions' => [
    'length_m' => 120,
    'beam_m' => 20,
    'banana' => 'x',
]
```

marine effective schema:

```text
additionalProperties=false
```

serializer phải throw:

```text
Unknown property $.dimensions.banana
```

Dù Effective Schema validator trước đó đáng lẽ đã bắt, serializer vẫn defensive.

---

# 9.36 Serializer không phải validator thay thế

Mặc dù serializer strict, pipeline vẫn phải:

```text
Schema Validation
↓
DTO
↓
Semantic
↓
Normalize
↓
Serializer
↓
Schema Revalidation
```

Không bỏ validation chỉ vì serializer strict.

Serializer chỉ bảo đảm representation.

---

# 9.37 Test `oneOf` relationship

Ví dụ:

```php
[
    'id' =>
        'R001',

    'type' =>
        'count',

    'subject_path' =>
        'permanent_geometry.superstructure.primary_tier_count',

    'value' =>
        4,
]
```

Serializer phải select:

```text
countRelationship
```

không phải 10 branches còn lại.

Test:

```php
public function test_relationship_one_of_uses_type_discriminator(): void
{
    $json =
        $this->serializer()
            ->serialize(
                $this->specWithCountRelationship(),
                $this->effectiveSchema()
            );

    $decoded =
        json_decode(
            $json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

    self::assertSame(
        'count',
        $decoded['relationships'][0]['type']
    );

    self::assertSame(
        4,
        $decoded['relationships'][0]['value']
    );
}
```

---

# 9.38 Freeze invariant test

Quan trọng nhất:

```php
public function test_freezer_hashes_exact_validated_canonical_json(): void
{
    $processed =
        $this->processedConcept();

    $frozen =
        $this->freezer()
            ->freeze(
                $processed,
                revision: 3
            );

    self::assertSame(
        $processed->canonicalJson,
        $frozen->canonicalJson
    );

    self::assertSame(
        hash(
            'sha256',
            $processed->canonicalJson
        ),
        $frozen->hash
    );
}
```

Đây là test khóa invariant Phần 9.

---

# 9.39 Clock test

Tạo fake clock:

```php
final class FrozenClock implements Clock
{
    public function __construct(
        private readonly DateTimeImmutable $time
    ) {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}
```

Test:

```php
$time =
    new DateTimeImmutable(
        '2026-08-30T03:00:00+00:00'
    );

$freezer =
    $this->makeFreezer(
        new FrozenClock($time)
    );

$frozen = ...;

self::assertSame(
    $time,
    $frozen->frozenAt
);
```

Không test phụ thuộc `now()` thật.

---

# 9.40 Một nuance về RFC 8785 / JCS

Tôi **chưa dùng RFC 8785 JCS trong V1**.

Hiện contract của ta là:

```text
canonical-json-v1
```

với policy:

```text
object keys       → lexical sort
arrays            → preserve order
schema number     → float
schema integer    → int
unicode           → unescaped
slashes           → unescaped
finite numbers    → required
JSON_PRESERVE_ZERO_FRACTION
```

Điều này đủ nếu:

> **Laravel là source of truth tạo hash.**

Python downstream chỉ nên nhận:

```text
canonical_json
canonical_hash
```

và verify:

```python
sha256(canonical_json_bytes)
```

Không reconstruct object rồi tự canonicalize lại.

Như vậy PHP/Python không cần phải cùng implement canonicalizer.

---

# 9.41 Python sau này verify hash thế nào?

Đơn giản:

```python
import hashlib

actual = hashlib.sha256(
    canonical_json.encode("utf-8")
).hexdigest()

if actual != canonical_hash:
    raise CanonicalIntegrityError(...)
```

Không:

```python
json.loads(...)
json.dumps(...)
sha256(...)
```

vì có thể representation khác.

Hash exact bytes/string được Laravel freeze.

---

# 9.42 Persistence sau Phần 9

DB sau này ở Phần 10 nên có ít nhất:

```text
canonical_json        LONGTEXT / JSON
canonical_hash        CHAR(64)

canonical_revision    INT

canonical_schema_version
profile_key
profile_version

effective_schema_hash

semantic_validator_version
normalizer_version
canonicalizer_version

concept_model
concept_prompt_version

canonical_frozen_at
```

Nhưng migration/Decision Ledger/changelog chúng ta để **Phần 10**.

Phần 9 chỉ tạo đúng contract runtime.

---

# 9.43 Một điều chưa nên làm

Không đưa:

```json
{
  "hash": "...",
  "revision": 1,
  "profile_version": "1.0"
}
```

vào:

```text
canonical_design_spec_v1.json
```

Core V1 vẫn giữ nguyên.

Design truth:

```text
CanonicalDesignSpec
```

Operational truth:

```text
FrozenCanonicalConceptMetadata
```

tách riêng.

---

# 9.44 Full flow sau Phần 9

Bây giờ Concept stage của bạn thành:

```text
Article
↓
Haiku Evidence Extraction
↓
Deterministic Evidence Verification
↓
InspirationBuilder
↓
InspirationBrief
↓
CategoryCreativeProfileResolver
↓
CategoryCreativeProfile
↓
ConceptInput
↓
Core V1
+
Profile Schema
↓
EffectiveConceptSchema
↓
ClaudeSchemaAdapter
↓
Sonnet 5
↓
Raw Canonical JSON
↓
Core Schema Validation
↓
Effective Schema Validation
↓
CanonicalDesignSpec DTO
↓
CrossField Validation
↓
Provenance Validation
↓
Profile Compatibility
↓
Category Semantic Validation
↓
FAIL ─────────────→ Sonnet Repair exactly once
↓ PASS
Normalize
↓
SchemaAwareCanonicalSerializer
↓
canonicalJson
↓
Core revalidation
↓
Effective revalidation
↓
Semantic revalidation
↓
ProcessedCanonicalConcept
    ├── spec
    ├── canonicalJson
    └── effectiveSchema
↓
SHA-256(canonicalJson exact bytes)
↓
CanonicalConceptFreezer
↓
FrozenCanonicalConcept
    ├── canonicalJson
    ├── hash
    ├── revision
    ├── frozenAt
    └── metadata
```

## Chốt Phần 9

Có bốn quyết định quan trọng cần giữ nguyên từ đây:

```text
1. Effective Schema quyết định JSON object/list type.

2. Serializer xử lý recursive,
   không chỉ 4 domain fields ở root.

3. Processor trả canonicalJson đã revalidate.

4. Freezer KHÔNG serialize lại.
   Nó hash chính canonicalJson đó.
```

Do đó invariant production trở thành:

```text
NORMALIZED DESIGN
       ↓
CANONICAL BYTES
       ↓
VALIDATED BYTES
       ↓
HASHED BYTES
       ↓
FROZEN BYTES

ALL THE SAME
```

Sau phần này, **Phần 10 nên là `Canonical Revision Persistence + Decision Ledger + Checkpoint/Freeze State Machine`**. Đó là nơi ta nối toàn bộ Canonical Concept system vừa xây vào DB Laravel thực tế: revision, raw Sonnet output, repair attempt, validation errors, normalized canonical JSON, hash, profile/schema versions, freeze transaction và idempotency.
