Dưới đây là **Phần 3 — Validation đầy đủ**, theo đúng kiến trúc production V1 đã chốt. Tôi sẽ đi theo đúng flow runtime:

```text
Raw Sonnet JSON
↓
CanonicalSchemaValidator
↓
CanonicalDesignSpec DTO
↓
CanonicalPathValidator
↓
CanonicalCrossFieldValidator
↓
CanonicalProvenanceValidator
↓
CanonicalDesignSpecValidator
↓
FAIL?
└── CanonicalValidationException
```

Tôi dùng `opis/json-schema` 2.x cho JSON Schema validation. API dưới đây bám theo tài liệu chính thức hiện tại của Opis: `Validator::validate()`, `ErrorFormatter`, `setMaxErrors()`, `setStopAtFirstError(false)`. ([Opis][1])

---

# 1. `Validation/ValidationError.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Validation;

final class ValidationError
{
    public function __construct(
        public readonly string $code,
        public readonly string $path,
        public readonly string $message,
        public readonly mixed $expected = null,
        public readonly mixed $actual = null,
    ) {
    }

    /**
     * @return array{
     *     code:string,
     *     path:string,
     *     message:string,
     *     expected:mixed,
     *     actual:mixed
     * }
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'path' => $this->path,
            'message' => $this->message,
            'expected' => $this->expected,
            'actual' => $this->actual,
        ];
    }
}
```

## File này dùng để làm gì?

Đây là format lỗi chung cho toàn bộ validation layer.

Thay vì mỗi validator trả về một loại lỗi khác nhau:

```php
[
    'message' => 'wrong'
]
```

hoặc:

```php
[
    'error' => 'invalid path'
]
```

thì toàn bộ hệ thống thống nhất thành:

```php
ValidationError
```

Ví dụ:

```php
new ValidationError(
    code: 'relationship_path_missing',
    path: 'relationships.2.subject_path',
    message: 'Referenced canonical path does not exist.',
    expected: 'existing canonical path',
    actual: 'permanent_geometry.fake_part',
);
```

Sau đó khi gọi:

```php
$error->toArray();
```

sẽ ra:

```json
{
    "code": "relationship_path_missing",
    "path": "relationships.2.subject_path",
    "message": "Referenced canonical path does not exist.",
    "expected": "existing canonical path",
    "actual": "permanent_geometry.fake_part"
}
```

### Ý nghĩa từng field

```text
code
```

là machine-readable.

Ví dụ:

```text
json_schema
relationship_path_missing
count_relationship_mismatch
unknown_source_aspect
duplicate_relationship_id
```

Sau này Laravel có thể:

```php
match ($error->code) {
    'unknown_source_aspect' => ...,
    'relationship_path_missing' => ...,
};
```

---

```text
path
```

cho biết lỗi nằm ở đâu.

Ví dụ:

```text
relationships.3.value
provenance.2.source_aspects
invariants.0.source_path
```

---

```text
message
```

là thông báo cho developer / repair model.

---

```text
expected
actual
```

rất quan trọng cho repair.

Ví dụ:

```json
{
    "expected": 4,
    "actual": 5
}
```

Sonnet Repairer sẽ hiểu chính xác cần sửa cái gì.

---

# 2. `Validation/ValidationResult.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Validation;

final class ValidationResult
{
    /**
     * @param list<ValidationError> $errors
     */
    public function __construct(
        public readonly array $errors = [],
    ) {
    }

    public static function valid(): self
    {
        return new self();
    }

    /**
     * @param list<ValidationError> $errors
     */
    public static function invalid(array $errors): self
    {
        return new self($errors);
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * @return list<array{
     *     code:string,
     *     path:string,
     *     message:string,
     *     expected:mixed,
     *     actual:mixed
     * }>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (ValidationError $error): array =>
                $error->toArray(),
            $this->errors,
        );
    }

    /**
     * @param list<ValidationResult> $results
     */
    public static function merge(array $results): self
    {
        $errors = [];

        foreach ($results as $result) {
            foreach ($result->errors as $error) {
                $errors[] = $error;
            }
        }

        return new self($errors);
    }
}
```

## Tại sao cần `ValidationResult`?

Không nên validator gặp lỗi đầu tiên là:

```php
throw new Exception();
```

ngay.

Ví dụ canonical spec có cùng lúc:

```text
R003 path sai
R005 count sai
I002 path sai
provenance source aspect sai
```

Nếu dừng ở lỗi đầu:

```text
fix
↓
gọi Sonnet lại
↓
lỗi thứ hai
↓
fix
↓
gọi lại
```

rất tốn API.

Production tốt hơn là:

```text
validate toàn bộ
↓
collect tất cả deterministic errors
↓
gửi một lần cho repair
```

Cho nên:

```php
ValidationResult
```

là container cho **nhiều lỗi**.

Ví dụ:

```php
$result = new ValidationResult([
    $error1,
    $error2,
    $error3,
]);

if ($result->fails()) {
    // repair once
}
```

---

# 3. `Exceptions/CanonicalValidationException.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Exceptions;

use App\Video\Concept\Validation\ValidationError;
use RuntimeException;

final class CanonicalValidationException extends RuntimeException
{
    /**
     * @param list<ValidationError> $errors
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'Canonical design validation failed.',
    ) {
        parent::__construct($message);
    }

    /**
     * @return list<ValidationError>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<array{
     *     code:string,
     *     path:string,
     *     message:string,
     *     expected:mixed,
     *     actual:mixed
     * }>
     */
    public function errorPayload(): array
    {
        return array_map(
            static fn (ValidationError $error): array =>
                $error->toArray(),
            $this->errors,
        );
    }
}
```

## Tại sao vừa có `ValidationResult` vừa có Exception?

Hai cái phục vụ hai tầng khác nhau.

Validator cấp thấp:

```text
CanonicalCrossFieldValidator
CanonicalProvenanceValidator
```

nên trả:

```php
ValidationResult
```

để gom lỗi.

Nhưng orchestration cấp cao:

```text
CanonicalConceptProcessor
```

cần dừng pipeline khi invalid.

Do đó nó chuyển:

```text
ValidationResult
↓
CanonicalValidationException
```

Ví dụ:

```php
$result = $validator->validate($spec, $brief);

if ($result->fails()) {
    throw new CanonicalValidationException(
        $result->errors
    );
}
```

`BuildCanonicalConcept` có thể catch exception đó:

```php
try {
    $spec = $processor->process(...);
} catch (CanonicalValidationException $e) {
    $repairer->repair(
        errors: $e->errors()
    );
}
```

Đây là boundary rất sạch:

```text
validator
= trả result

processor
= quyết định fail

orchestrator
= quyết định repair
```

---

# 4. `Validation/CanonicalSchemaValidator.php`

Đây là validator đầu tiên.

Nó kiểm:

```text
shape
types
required
enum
pattern
minItems
uniqueItems
minimum
additionalProperties
oneOf
$ref
...
```

chứ chưa kiểm logic semantic.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Validation;

use App\Video\Concept\Exceptions\CanonicalValidationException;
use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class CanonicalSchemaValidator
{
    private Validator $validator;

    public function __construct(
        private readonly string $schemaPath,
    ) {
        $this->validator = new Validator();

        /*
         * Production:
         * thu thập nhiều schema errors trong cùng một pass,
         * không stop ngay ở lỗi đầu.
         */
        $this->validator->setMaxErrors(100);
        $this->validator->setStopAtFirstError(false);
    }

    /**
     * Validate RAW JSON.
     *
     * Quan trọng:
     * dùng raw JSON để không làm mất phân biệt:
     *
     * {}  = JSON object
     * []  = JSON array
     */
    public function validateJson(
        string $rawJson
    ): ValidationResult {
        try {
            $data = json_decode(
                $rawJson,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            return ValidationResult::invalid([
                new ValidationError(
                    code: 'invalid_json',
                    path: '$',
                    message: $e->getMessage(),
                ),
            ]);
        }

        $schemaJson = $this->loadSchemaJson();

        try {
            $schema = json_decode(
                $schemaJson,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new RuntimeException(
                'canonical_design_spec_v1.json '
                . 'is not valid JSON: '
                . $e->getMessage(),
                previous: $e,
            );
        }

        $result = $this->validator->validate(
            $data,
            $schema,
        );

        if ($result->isValid()) {
            return ValidationResult::valid();
        }

        $rootError = $result->error();

        if ($rootError === null) {
            return ValidationResult::invalid([
                new ValidationError(
                    code: 'json_schema',
                    path: '$',
                    message:
                        'Schema validation failed '
                        . 'without an error object.',
                ),
            ]);
        }

        $formatter = new ErrorFormatter();

        /*
         * formatFlat() cho ta toàn bộ nested errors
         * dưới dạng flat list.
         */
        $formatted = $formatter->formatFlat(
            $rootError,
            static function ($error) use ($formatter): array {
                return [
                    'keyword' =>
                        $error->keyword(),

                    'message' =>
                        $formatter
                            ->formatErrorMessage(
                                $error
                            ),

                    'data_path' =>
                        $formatter
                            ->formatErrorKey(
                                $error
                            ),

                    'actual' =>
                        $error->data()->value(),
                ];
            },
        );

        $errors = [];

        foreach ($formatted as $item) {
            if (!is_array($item)) {
                continue;
            }

            $errors[] = new ValidationError(
                code:
                    'json_schema.'
                    . (
                        isset($item['keyword'])
                            ? (string) $item['keyword']
                            : 'unknown'
                    ),

                path:
                    isset($item['data_path'])
                        ? (string) $item['data_path']
                        : '$',

                message:
                    isset($item['message'])
                        ? (string) $item['message']
                        : 'JSON Schema validation failed.',

                actual:
                    $item['actual'] ?? null,
            );
        }

        if ($errors === []) {
            $errors[] = new ValidationError(
                code: 'json_schema',
                path: '$',
                message:
                    'Canonical JSON does not satisfy '
                    . 'canonical_design_spec_v1.json.',
            );
        }

        return ValidationResult::invalid(
            $errors
        );
    }

    public function validateJsonOrFail(
        string $rawJson
    ): void {
        $result = $this->validateJson(
            $rawJson
        );

        if ($result->fails()) {
            throw new CanonicalValidationException(
                errors: $result->errors,
                message:
                    'Canonical JSON Schema validation failed.',
            );
        }
    }

    private function loadSchemaJson(): string
    {
        if (!is_file($this->schemaPath)) {
            throw new RuntimeException(
                'Canonical schema file not found: '
                . $this->schemaPath
            );
        }

        $schema = file_get_contents(
            $this->schemaPath
        );

        if ($schema === false) {
            throw new RuntimeException(
                'Unable to read canonical schema: '
                . $this->schemaPath
            );
        }

        return $schema;
    }
}
```

Opis 2.x hỗ trợ Draft 2020-12 cùng các keyword mà V1 của bạn đang dùng như `$defs`, `$ref`, `oneOf`, `const`, `additionalProperties`, `uniqueItems`, `minimum`, v.v. ([Opis][2])

## Vì sao phải validate RAW JSON?

Đây là một bug PHP rất dễ dính.

JSON:

```json
{
    "finished_materials": {}
}
```

Nếu làm:

```php
$data = json_decode(
    $json,
    true
);
```

PHP sẽ cho:

```php
[
    'finished_materials' => []
]
```

Lúc này PHP không còn biết ban đầu là:

```text
{}
```

hay:

```text
[]
```

Opis cũng lưu ý việc convert PHP arrays có thể không phân biệt empty indexed array và empty object. ([Opis][3])

Cho nên schema validator phải chạy:

```text
raw JSON
↓
json_decode(... object mode)
↓
Opis
```

trước khi hydrate thành associative array.

Đây là lý do hardened pipeline đúng phải là:

```text
Sonnet raw text
        ↓
SchemaValidator.validateJson(raw)
        ↓
json_decode(raw, true)
        ↓
DTO hydration
```

chứ không phải:

```text
json_decode(raw, true)
↓
json_encode lại
↓
schema validate
```

---

## Ví dụ Schema Validator bắt lỗi gì?

Nếu Sonnet trả:

```json
{
    "schema_version": "2.0"
}
```

schema sẽ bắt:

```text
const violation
```

Nếu:

```json
{
    "invariants": []
}
```

schema bắt:

```text
minItems
```

Nếu:

```json
{
    "severity": "critical"
}
```

schema bắt:

```text
enum
```

Nếu:

```json
{
    "id": "HELLO"
}
```

schema bắt:

```text
pattern
```

Nếu relationship:

```json
{
    "type": "count",
    "value": -5
}
```

schema bắt:

```text
minimum
```

Nếu Sonnet tự thêm:

```json
{
    "random_extra_field": "abc"
}
```

ở object có:

```json
"additionalProperties": false
```

schema bắt ngay.

---

# 5. `Validation/CanonicalPathValidator.php`

Đây là một phần rất quan trọng.

Canonical relationships không chứa trực tiếp object reference.

Chúng chứa:

```text
permanent_geometry.superstructure.primary_tier_count
```

Nên cần một resolver kiểm xem path đó có tồn tại không.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Validation;

final class CanonicalPathValidator
{
    /**
     * @param array<string,mixed> $document
     */
    public function exists(
        array $document,
        string $path
    ): bool {
        [, $found] = $this->resolve(
            $document,
            $path
        );

        return $found;
    }

    /**
     * @param array<string,mixed> $document
     */
    public function value(
        array $document,
        string $path
    ): mixed {
        [$value, $found] = $this->resolve(
            $document,
            $path
        );

        if (!$found) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $document
     *
     * @return array{0:mixed,1:bool}
     */
    public function resolveWithStatus(
        array $document,
        string $path
    ): array {
        return $this->resolve(
            $document,
            $path
        );
    }

    /**
     * @param array<string,mixed> $document
     *
     * @return array{0:mixed,1:bool}
     */
    private function resolve(
        array $document,
        string $path
    ): array {
        $path = trim($path);

        if ($path === '') {
            return [
                null,
                false,
            ];
        }

        $cursor = $document;

        foreach (
            explode('.', $path)
            as $segment
        ) {
            if ($segment === '') {
                return [
                    null,
                    false,
                ];
            }

            if (
                !is_array($cursor)
                || !array_key_exists(
                    $segment,
                    $cursor
                )
            ) {
                return [
                    null,
                    false,
                ];
            }

            $cursor =
                $cursor[$segment];
        }

        return [
            $cursor,
            true,
        ];
    }
}
```

## Tại sao bản này dùng:

```php
[$value, $found]
```

thay vì chỉ:

```php
$value
```

Vì một canonical path có thể tồn tại nhưng giá trị của nó là:

```php
null
```

Ví dụ:

```php
[
    'foo' => null
]
```

Nếu code chỉ kiểm:

```php
$value !== null
```

thì sẽ hiểu sai:

```text
foo không tồn tại
```

Trong khi thật ra:

```text
foo tồn tại nhưng value = null
```

Do đó cần tách:

```text
value
found
```

Ví dụ:

```php
[$value, $found] =
    $validator->resolveWithStatus(
        $doc,
        'foo'
    );
```

kết quả:

```text
value = null
found = true
```

---

## Ví dụ path resolver

Document:

```php
$document = [
    'permanent_geometry' => [
        'superstructure' => [
            'primary_tier_count' => 4,
        ],
    ],
];
```

Call:

```php
$paths->exists(
    $document,
    'permanent_geometry.superstructure.primary_tier_count'
);
```

→

```text
true
```

Call:

```php
$paths->value(
    $document,
    'permanent_geometry.superstructure.primary_tier_count'
);
```

→

```text
4
```

Call:

```php
$paths->exists(
    $document,
    'permanent_geometry.superstructure.fake'
);
```

→

```text
false
```

---

# 6. `Validation/CanonicalCrossFieldValidator.php`

Đây là validator lớn nhất.

JSON Schema chỉ nhìn từng shape.

Cross-field validator kiểm:

```text
field A
có consistent với field B hay không
```

Ví dụ:

```text
permanent_geometry.tier_count = 4
```

nhưng relationship:

```text
COUNT value = 5
```

Schema vẫn valid.

Cross-field validator mới bắt.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Canonical\Relationships\AlignmentRelationship;
use App\Video\Concept\Canonical\Relationships\ConnectivityRelationship;
use App\Video\Concept\Canonical\Relationships\ContainmentRelationship;
use App\Video\Concept\Canonical\Relationships\ContinuityRelationship;
use App\Video\Concept\Canonical\Relationships\CountRelationship;
use App\Video\Concept\Canonical\Relationships\GroupingRelationship;
use App\Video\Concept\Canonical\Relationships\OneToOneRelationship;
use App\Video\Concept\Canonical\Relationships\OrderRelationship;
use App\Video\Concept\Canonical\Relationships\PositionRelationship;
use App\Video\Concept\Canonical\Relationships\ProportionRelationship;
use App\Video\Concept\Canonical\Relationships\Relationship;
use App\Video\Concept\Canonical\Relationships\SymmetryRelationship;

final class CanonicalCrossFieldValidator
{
    public function __construct(
        private readonly CanonicalPathValidator $paths,
    ) {
    }

    /**
     * @return list<ValidationError>
     */
    public function validate(
        CanonicalDesignSpec $spec
    ): array {
        $errors = [];

        $document =
            $spec->toArray();

        $errors = [
            ...$errors,
            ...$this->validateRelationshipIds(
                $spec
            ),
        ];

        $errors = [
            ...$errors,
            ...$this->validateRelationshipPaths(
                $spec,
                $document
            ),
        ];

        $errors = [
            ...$errors,
            ...$this->validateCountRelationships(
                $spec,
                $document
            ),
        ];

        $errors = [
            ...$errors,
            ...$this->validateInvariantIdsAndPaths(
                $spec,
                $document
            ),
        ];

        $errors = [
            ...$errors,
            ...$this->validateExclusionIdsAndPaths(
                $spec,
                $document
            ),
        ];

        return $errors;
    }

    /**
     * @return list<ValidationError>
     */
    private function validateRelationshipIds(
        CanonicalDesignSpec $spec
    ): array {
        $errors = [];
        $seen = [];

        foreach (
            $spec->relationships
            as $index => $relationship
        ) {
            $id = $relationship->id();

            if (isset($seen[$id])) {
                $errors[] =
                    new ValidationError(
                        code:
                            'duplicate_relationship_id',

                        path:
                            "relationships.{$index}.id",

                        message:
                            'Relationship ids '
                            . 'must be unique.',

                        actual:
                            $id,
                    );
            }

            $seen[$id] = true;
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $document
     *
     * @return list<ValidationError>
     */
    private function validateRelationshipPaths(
        CanonicalDesignSpec $spec,
        array $document
    ): array {
        $errors = [];

        foreach (
            $spec->relationships
            as $index => $relationship
        ) {
            foreach (
                $this->referencedPaths(
                    $relationship
                )
                as $field => $path
            ) {
                if (
                    !$this->paths->exists(
                        $document,
                        $path
                    )
                ) {
                    $errors[] =
                        new ValidationError(
                            code:
                                'relationship_path_missing',

                            path:
                                "relationships."
                                . "{$index}.{$field}",

                            message:
                                'Referenced canonical '
                                . 'path does not exist.',

                            expected:
                                'existing canonical path',

                            actual:
                                $path,
                        );
                }
            }
        }

        return $errors;
    }

    /**
     * V1 semantics:
     *
     * CountRelationship may directly reference an integer
     * count-bearing field.
     *
     * If subject_path resolves to an integer,
     * relationship.value must equal it.
     *
     * If subject_path resolves to an object/list,
     * this validator does NOT guess count semantics.
     * Category/profile validator may add stronger rules.
     *
     * @param array<string,mixed> $document
     *
     * @return list<ValidationError>
     */
    private function validateCountRelationships(
        CanonicalDesignSpec $spec,
        array $document
    ): array {
        $errors = [];

        foreach (
            $spec->relationships
            as $index => $relationship
        ) {
            if (
                !$relationship
                    instanceof CountRelationship
            ) {
                continue;
            }

            [
                $actual,
                $found,
            ] = $this->paths
                ->resolveWithStatus(
                    $document,
                    $relationship->subjectPath
                );

            if (!$found) {
                /*
                 * Path missing đã được
                 * validateRelationshipPaths()
                 * report rồi.
                 */
                continue;
            }

            if (!is_int($actual)) {
                /*
                 * Core V1 không tự đoán:
                 *
                 * array => count(array)?
                 * object => property count?
                 *
                 * Không.
                 *
                 * Việc đó thuộc profile semantics.
                 */
                continue;
            }

            if (
                $actual
                !== $relationship->value
            ) {
                $errors[] =
                    new ValidationError(
                        code:
                            'count_relationship_mismatch',

                        path:
                            "relationships."
                            . "{$index}.value",

                        message:
                            'Count relationship value '
                            . 'conflicts with the '
                            . 'referenced integer field.',

                        expected:
                            $actual,

                        actual:
                            $relationship->value,
                    );
            }
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $document
     *
     * @return list<ValidationError>
     */
    private function validateInvariantIdsAndPaths(
        CanonicalDesignSpec $spec,
        array $document
    ): array {
        $errors = [];
        $seen = [];

        foreach (
            $spec->invariants
            as $index => $invariant
        ) {
            if (isset($seen[$invariant->id])) {
                $errors[] =
                    new ValidationError(
                        code:
                            'duplicate_invariant_id',

                        path:
                            "invariants.{$index}.id",

                        message:
                            'Invariant ids '
                            . 'must be unique.',

                        actual:
                            $invariant->id,
                    );
            }

            $seen[$invariant->id] =
                true;

            if (
                !$this->paths->exists(
                    $document,
                    $invariant->sourcePath
                )
            ) {
                $errors[] =
                    new ValidationError(
                        code:
                            'invariant_source_path_missing',

                        path:
                            "invariants."
                            . "{$index}.source_path",

                        message:
                            'Invariant source_path '
                            . 'does not exist.',

                        expected:
                            'existing canonical path',

                        actual:
                            $invariant->sourcePath,
                    );
            }
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $document
     *
     * @return list<ValidationError>
     */
    private function validateExclusionIdsAndPaths(
        CanonicalDesignSpec $spec,
        array $document
    ): array {
        $errors = [];
        $seen = [];

        foreach (
            $spec->exclusions
            as $index => $exclusion
        ) {
            if (isset($seen[$exclusion->id])) {
                $errors[] =
                    new ValidationError(
                        code:
                            'duplicate_exclusion_id',

                        path:
                            "exclusions.{$index}.id",

                        message:
                            'Exclusion ids '
                            . 'must be unique.',

                        actual:
                            $exclusion->id,
                    );
            }

            $seen[$exclusion->id] =
                true;

            if (
                !$this->paths->exists(
                    $document,
                    $exclusion->targetPath
                )
            ) {
                $errors[] =
                    new ValidationError(
                        code:
                            'exclusion_target_path_missing',

                        path:
                            "exclusions."
                            . "{$index}.target_path",

                        message:
                            'Exclusion target_path '
                            . 'does not exist.',

                        expected:
                            'existing canonical path',

                        actual:
                            $exclusion->targetPath,
                    );
            }
        }

        return $errors;
    }

    /**
     * Trả về tất cả canonical paths mà
     * relationship đang reference.
     *
     * Key = field trong relationship.
     * Value = canonical path.
     *
     * @return array<string,string>
     */
    private function referencedPaths(
        Relationship $relationship
    ): array {
        if (
            $relationship
                instanceof CountRelationship
        ) {
            return [
                'subject_path' =>
                    $relationship->subjectPath,
            ];
        }

        if (
            $relationship
                instanceof GroupingRelationship
        ) {
            $paths = [
                'subject_path' =>
                    $relationship->subjectPath,
            ];

            if (
                $relationship
                    ->groupedIntoPath
                !== null
            ) {
                $paths['grouped_into_path'] =
                    $relationship
                        ->groupedIntoPath;
            }

            return $paths;
        }

        if (
            $relationship
                instanceof OneToOneRelationship
        ) {
            return [
                'source_path' =>
                    $relationship->sourcePath,

                'target_path' =>
                    $relationship->targetPath,
            ];
        }

        if (
            $relationship
                instanceof ProportionRelationship
        ) {
            return [
                'subject_path' =>
                    $relationship->subjectPath,
            ];
        }

        if (
            $relationship
                instanceof PositionRelationship
        ) {
            return [
                'subject_path' =>
                    $relationship->subjectPath,

                'reference_path' =>
                    $relationship->referencePath,
            ];
        }

        if (
            $relationship
                instanceof OrderRelationship
        ) {
            $paths = [];

            foreach (
                $relationship->items
                as $itemIndex => $path
            ) {
                $paths[
                    "items.{$itemIndex}"
                ] = $path;
            }

            return $paths;
        }

        if (
            $relationship
                instanceof ContinuityRelationship
        ) {
            $paths = [
                'subject_path' =>
                    $relationship->subjectPath,
            ];

            if (
                $relationship->startPath
                !== null
            ) {
                $paths['start_path'] =
                    $relationship->startPath;
            }

            if (
                $relationship->endPath
                !== null
            ) {
                $paths['end_path'] =
                    $relationship->endPath;
            }

            return $paths;
        }

        if (
            $relationship
                instanceof ConnectivityRelationship
        ) {
            $paths = [];

            foreach (
                $relationship->members
                as $memberIndex => $path
            ) {
                $paths[
                    "members.{$memberIndex}"
                ] = $path;
            }

            return $paths;
        }

        if (
            $relationship
                instanceof SymmetryRelationship
        ) {
            return [
                'subject_path' =>
                    $relationship->subjectPath,
            ];
        }

        if (
            $relationship
                instanceof ContainmentRelationship
        ) {
            return [
                'container_path' =>
                    $relationship->containerPath,

                'contained_path' =>
                    $relationship->containedPath,
            ];
        }

        if (
            $relationship
                instanceof AlignmentRelationship
        ) {
            $paths = [];

            foreach (
                $relationship->members
                as $memberIndex => $path
            ) {
                $paths[
                    "members.{$memberIndex}"
                ] = $path;
            }

            return $paths;
        }

        return [];
    }
}
```

## Phân tích `CanonicalCrossFieldValidator`

Phần:

```php
$document = $spec->toArray();
```

biến typed DTO trở lại canonical tree.

Ví dụ:

```php
[
    'dimensions' => [...],
    'permanent_geometry' => [...],
    ...
]
```

để path resolver có thể truy cập.

---

### `validateRelationshipIds()`

Schema của từng relationship chỉ nói:

```text
id phải dạng R001
```

nhưng schema hiện tại không đảm bảo:

```text
R001
R001
```

không bị lặp giữa hai relationship.

Cho nên cross-field check:

```php
$seen[$id]
```

Nếu gặp lại:

```text
duplicate_relationship_id
```

---

### `validateRelationshipPaths()`

Ví dụ Sonnet tạo:

```json
{
    "type": "position",
    "subject_path":
        "permanent_geometry.pool",
    "reference_path":
        "permanent_geometry.transom"
}
```

nhưng canonical không có:

```text
permanent_geometry.pool
```

JSON schema vẫn valid vì path chỉ là:

```json
"type": "string"
```

Cross-field mới biết:

```text
string đó phải resolve được
```

Đây là một trong các validator quan trọng nhất.

---

### Tại sao `CountRelationship` không tự `count()` array?

Giả sử:

```php
subject_path =
'permanent_geometry.windows'
```

và path resolve thành:

```php
[
    'port' => ...,
    'starboard' => ...,
]
```

Không thể mặc định:

```php
count($array) = 2
```

rồi nói:

```text
window count = 2
```

vì array đó có thể chứa:

```text
configuration fields
groups
metadata
```

chứ không phải collection.

Vì vậy Core V1 chỉ deterministic khi target là:

```php
int
```

Ví dụ:

```text
primary_tier_count = 4
```

thì mới so:

```text
relationship.value == 4
```

Đây là nguyên tắc cực quan trọng:

```text
Core validator
không đoán semantic meaning
```

Domain-specific interpretation thuộc:

```text
Category Profile Validator
```

---

# 7. `Validation/CanonicalProvenanceValidator.php`

Đây là validator nối:

```text
CanonicalDesignSpec
↔
InspirationBrief
```

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Canonical\Enums\ProvenanceOrigin;
use App\Video\Inspiration\InspirationBrief;

final class CanonicalProvenanceValidator
{
    /**
     * @return list<ValidationError>
     */
    public function validate(
        CanonicalDesignSpec $spec,
        InspirationBrief $brief
    ): array {
        $errors = [];

        $seenTargets = [];

        foreach (
            $spec->provenance
            as $index => $entry
        ) {
            /*
             * V1 policy:
             * mỗi target_path chỉ nên có một
             * provenance declaration.
             */
            if (
                isset(
                    $seenTargets[
                        $entry->targetPath
                    ]
                )
            ) {
                $errors[] =
                    new ValidationError(
                        code:
                            'duplicate_provenance_target',

                        path:
                            "provenance."
                            . "{$index}.target_path",

                        message:
                            'A canonical target path '
                            . 'must not have multiple '
                            . 'provenance entries.',

                        actual:
                            $entry->targetPath,
                    );
            }

            $seenTargets[
                $entry->targetPath
            ] = true;

            /*
             * invented:
             *
             * source_aspects phải rỗng.
             */
            if (
                $entry->origin
                === ProvenanceOrigin::INVENTED
                && $entry->sourceAspects !== []
            ) {
                $errors[] =
                    new ValidationError(
                        code:
                            'invented_provenance_has_sources',

                        path:
                            "provenance."
                            . "{$index}.source_aspects",

                        message:
                            'Invented provenance '
                            . 'must have an empty '
                            . 'source_aspects array.',

                        expected:
                            [],

                        actual:
                            $entry->sourceAspects,
                    );
            }

            /*
             * inspired:
             *
             * phải chỉ ra source aspect thật.
             */
            if (
                $entry->origin
                === ProvenanceOrigin::INSPIRED
                && $entry->sourceAspects === []
            ) {
                $errors[] =
                    new ValidationError(
                        code:
                            'inspired_provenance_missing_sources',

                        path:
                            "provenance."
                            . "{$index}.source_aspects",

                        message:
                            'Inspired provenance '
                            . 'must reference at least '
                            . 'one source aspect.',
                    );
            }

            /*
             * Mọi source_aspect khai báo
             * phải có thật trong InspirationBrief.
             */
            foreach (
                $entry->sourceAspects
                as $aspect
            ) {
                if (
                    !$brief->hasSourceAspect(
                        $aspect
                    )
                ) {
                    $errors[] =
                        new ValidationError(
                            code:
                                'unknown_source_aspect',

                            path:
                                "provenance."
                                . "{$index}"
                                . '.source_aspects',

                            message:
                                'Provenance references '
                                . 'a source aspect that '
                                . 'does not exist in '
                                . 'InspirationBrief.',

                            expected:
                                $brief
                                    ->coveredAspects(),

                            actual:
                                $aspect,
                        );
                }
            }
        }

        return $errors;
    }
}
```

## Ví dụ `inspired`

InspirationBrief:

```json
{
    "source_insights": [
        {
            "aspect": "size_and_dimensions",
            "summary": "...",
            "source_quotes": ["..."]
        },
        {
            "aspect": "spatial_layout",
            "summary": "...",
            "source_quotes": ["..."]
        }
    ]
}
```

Canonical:

```json
{
    "target_path": "dimensions",
    "origin": "inspired",
    "source_aspects": [
        "size_and_dimensions"
    ]
}
```

→ valid.

---

Nhưng:

```json
{
    "target_path": "dimensions",
    "origin": "inspired",
    "source_aspects": [
        "jet_engine_geometry"
    ]
}
```

trong khi InspirationBrief không có aspect đó.

→

```text
unknown_source_aspect
```

---

## Ví dụ `invented`

```json
{
    "target_path":
        "permanent_geometry.observation_fin",
    "origin": "invented",
    "source_aspects": []
}
```

→ valid.

Nhưng:

```json
{
    "origin": "invented",
    "source_aspects": [
        "size_and_dimensions"
    ]
}
```

→ contradictory.

Validator bắt:

```text
invented_provenance_has_sources
```

---

## Vì sao provenance quan trọng?

Nếu không có layer này, Sonnet rất dễ nói:

```text
feature X được inspired từ source
```

nhưng source thực tế không hề có.

Khi đó lineage:

```text
Article
↓
Evidence
↓
Inspiration
↓
Canonical
```

bị giả.

Với validator này:

```text
inspired
↓
source_aspects
↓
must exist in InspirationBrief
```

lineage được deterministic kiểm.

---

# 8. `Validation/CanonicalDesignSpecValidator.php`

Đây là aggregator.

Không tự chứa nhiều logic.

Nó gọi các semantic validators bên dưới.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Inspiration\InspirationBrief;

final class CanonicalDesignSpecValidator
{
    public function __construct(
        private readonly CanonicalCrossFieldValidator $crossFieldValidator,
        private readonly CanonicalProvenanceValidator $provenanceValidator,
    ) {
    }

    public function validate(
        CanonicalDesignSpec $spec,
        InspirationBrief $brief
    ): ValidationResult {
        $crossFieldErrors =
            $this->crossFieldValidator
                ->validate($spec);

        $provenanceErrors =
            $this->provenanceValidator
                ->validate(
                    $spec,
                    $brief
                );

        return new ValidationResult([
            ...$crossFieldErrors,
            ...$provenanceErrors,
        ]);
    }

    public function validateOrFail(
        CanonicalDesignSpec $spec,
        InspirationBrief $brief
    ): void {
        $result = $this->validate(
            $spec,
            $brief
        );

        if ($result->fails()) {
            throw new \App\Video\Concept\Exceptions\CanonicalValidationException(
                errors:
                    $result->errors,

                message:
                    'Canonical semantic validation failed.',
            );
        }
    }
}
```

## Tại sao cần aggregator này?

Thay vì processor biết từng validator:

```php
$cross->validate();
$provenance->validate();
$path->validate();
...
```

processor chỉ cần:

```php
$semanticValidator->validate(
    $spec,
    $brief
);
```

Sau này bạn thêm:

```text
CanonicalInvariantValidator
CanonicalExclusionConflictValidator
CanonicalIdentityValidator
ProfileSemanticValidator
```

thì chỉ sửa aggregator.

Không phải sửa orchestration.

Đây là Dependency Inversion khá quan trọng.

---

# 9. Validation pipeline hoàn chỉnh

Bây giờ ta ghép toàn bộ lại.

## Stage 1 — RAW JSON

Sonnet trả:

```json
{
    "schema_version": "1.0",
    ...
}
```

Ta giữ nguyên:

```php
$rawJson
```

---

## Stage 2 — JSON Schema

```php
$schemaResult =
    $schemaValidator
        ->validateJson(
            $rawJson
        );
```

Nó kiểm:

```text
shape
type
enum
oneOf
required
additionalProperties
min/max
pattern
uniqueItems
```

---

## Stage 3 — hydrate DTO

Chỉ khi schema pass:

```php
$data = json_decode(
    $rawJson,
    true,
    512,
    JSON_THROW_ON_ERROR
);

$spec =
    CanonicalDesignSpec::fromArray(
        $data
    );
```

Lúc này:

```text
JSON
↓
typed PHP state
```

---

## Stage 4 — semantic validation

```php
$result =
    $canonicalValidator->validate(
        $spec,
        $brief
    );
```

Nó kiểm:

```text
relationship IDs unique
relationship paths exist
count consistency
invariant IDs unique
invariant source paths exist
exclusion IDs unique
exclusion target paths exist
provenance target/source logic
```

---

# 10. Những gì từng tầng chịu trách nhiệm

Đây là bảng bạn nên nhớ khi code tiếp:

| Lỗi                                                           | Ai bắt                                          |
| ------------------------------------------------------------- | ----------------------------------------------- |
| Thiếu `schema_version`                                        | JSON Schema                                     |
| `schema_version = 2.0`                                        | JSON Schema                                     |
| relationship `type=abc`                                       | JSON Schema                                     |
| `R12` sai format                                              | JSON Schema                                     |
| `value=-1` cho count                                          | JSON Schema                                     |
| extra field                                                   | JSON Schema                                     |
| `R001` bị duplicate                                           | Cross-field                                     |
| relationship path không tồn tại                               | Path/Cross-field                                |
| count relationship nói 4 nhưng canonical field = 5            | Cross-field                                     |
| invariant source path không tồn tại                           | Cross-field                                     |
| `E001` duplicate                                              | Cross-field                                     |
| exclusion target không tồn tại                                | Cross-field                                     |
| provenance inspired nhưng không source                        | Provenance                                      |
| provenance invented nhưng có source                           | Provenance                                      |
| provenance source aspect không tồn tại trong InspirationBrief | Provenance                                      |
| geometry yacht có `beam > length`                             | **không phải Core validator**                   |
| aircraft wing span logic sai                                  | **Profile validator**                           |
| Source nói 6 cabins nhưng Sonnet biến thành 8                 | cần provenance/design policy hoặc profile logic |
| image render ra 3 tiers thay vì 4                             | **Vision QA**, không phải canonical validator   |

---

# 11. Có một ranh giới rất quan trọng: Core Semantic Validator ≠ Category Validator

Ví dụ canonical:

```json
{
    "dimensions": {
        "length_m": 90,
        "beam_m": 100
    }
}
```

Schema V1 vẫn valid vì:

```text
dimensions
= domain-open object
```

Core validator **không được tự quyết định**:

```text
beam phải < length
```

vì với một domain khác có thể không có nghĩa đó.

Do đó sau này kiến trúc nên là:

```text
Canonical Core Validation
        ↓
Profile Semantic Validation
```

Ví dụ marine profile:

```php
final class MarineVesselSemanticValidator
{
    public function validate(
        CanonicalDesignSpec $spec
    ): array {
        // length
        // beam
        // draft
        // hull topology
        // bow/stern rules...
    }
}
```

Aircraft:

```php
AircraftSemanticValidator
```

Architecture:

```php
ArchitectureSemanticValidator
```

Nhưng **Core không chứa if yacht / aircraft / building**.

---

# 12. Tôi khuyên bổ sung interface ngay từ production V1

Không phải thay schema. Chỉ làm code đẹp hơn.

### `Validation/CanonicalSemanticValidator.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Inspiration\InspirationBrief;

interface CanonicalSemanticValidator
{
    public function validate(
        CanonicalDesignSpec $spec,
        InspirationBrief $brief
    ): ValidationResult;
}
```

Sau đó:

```php
final class CanonicalDesignSpecValidator
    implements CanonicalSemanticValidator
```

Điều này giúp processor depend vào abstraction:

```php
public function __construct(
    private readonly CanonicalSemanticValidator $semanticValidator,
)
```

thay vì concrete class.

Không bắt buộc để chạy nhưng production sạch hơn.

---

# 13. Có 3 validation mà tôi chưa cho vào Core này một cách cố ý

Đừng nghĩ là thiếu.

### One-to-one cardinality

Ví dụ:

```text
source path
target path
```

cùng tồn tại.

Nhưng Core chưa tự:

```php
count(source) === count(target)
```

vì path có thể resolve tới object semantic chứ không phải list.

Nếu category profile biết hai path đó là collection thì profile mới kiểm.

---

### Proportion recomputation

Ví dụ:

```text
length = 100
beam = 20
ratio declared = 6
```

thực tế là:

```text
100 / 20 = 5
```

Core không biết:

```text
metric="length_to_beam_ratio"
```

có nghĩa toán học nào.

Profile biết.

Marine profile có thể:

```php
$ratio = $length / $beam;
```

và kiểm tolerance.

---

### Exclusion conflict bằng NLP

Ví dụ:

```text
forbid = additional fifth tier
```

và geometry lại mô tả fifth tier bằng prose.

Core V1 không nên cố NLP compare string.

Đó có thể là:

```text
profile validator
```

hoặc Sonnet repair/reviewer.

---

# 14. Processor sau này sẽ dùng Validation layer này như thế nào?

Đây là đoạn bạn sẽ thấy ở Phần 4/5, nhưng tôi đưa trước để bạn hiểu flow:

```php
$schemaResult =
    $this->schemaValidator
        ->validateJson(
            $payload->rawJson
        );

if ($schemaResult->fails()) {
    throw new CanonicalValidationException(
        $schemaResult->errors,
        'Canonical schema validation failed.'
    );
}

$spec =
    CanonicalDesignSpec::fromArray(
        $payload->data
    );

$semanticResult =
    $this->semanticValidator
        ->validate(
            $spec,
            $brief
        );

if ($semanticResult->fails()) {
    throw new CanonicalValidationException(
        $semanticResult->errors,
        'Canonical semantic validation failed.'
    );
}
```

Nếu fail:

```text
CanonicalValidationException
        ↓
BuildCanonicalConcept catches
        ↓
ClaudeConceptRepairer
        ↓
exact validation errors
        ↓
Sonnet repairs once
        ↓
full validation again
```

Không có:

```text
while fail
    repair
```

Không có infinite repair loop.

Chỉ:

```text
generate
↓
validate
↓
repair tối đa 1 lần
↓
validate
↓
pass hoặc fatal
```

---

# 15. Ví dụ hoàn chỉnh: lỗi được bắt như thế nào

Giả sử Sonnet output:

```json
{
    "schema_version": "1.0",

    "object_type": "marine_vessel",

    "design_thesis": {
        "text": "A long vessel with four tiers.",
        "role": "soft_design_guidance"
    },

    "identity": {
        "subject_class": "superyacht",
        "identity_basis": [
            "four primary tiers"
        ]
    },

    "dimensions": {
        "length_m": 90
    },

    "permanent_geometry": {
        "superstructure": {
            "primary_tier_count": 4
        }
    },

    "relationships": [
        {
            "id": "R001",
            "type": "count",
            "subject_path":
                "permanent_geometry.superstructure.primary_tier_count",
            "value": 5
        },

        {
            "id": "R001",
            "type": "position",
            "subject_path":
                "permanent_geometry.fake_fin",
            "reference_path":
                "permanent_geometry.superstructure",
            "value": "forward"
        }
    ],

    "form_relationships": {},

    "finished_materials": {},

    "exclusions": [],

    "invariants": [
        {
            "id": "I001",
            "name": "Four tiers",
            "source_path":
                "permanent_geometry.missing_path",
            "constraint_type": "count",
            "severity": "hard",
            "visual_verification": true
        }
    ],

    "provenance": [
        {
            "target_path":
                "permanent_geometry.superstructure",

            "origin":
                "inspired",

            "source_aspects": [
                "fake_source_aspect"
            ]
        }
    ]
}
```

JSON Schema:

```text
PASS
```

Vì shape đều hợp lệ.

Nhưng semantic validator trả cùng lúc:

```json
[
    {
        "code":
            "duplicate_relationship_id",

        "path":
            "relationships.1.id",

        "actual":
            "R001"
    },

    {
        "code":
            "relationship_path_missing",

        "path":
            "relationships.1.subject_path",

        "actual":
            "permanent_geometry.fake_fin"
    },

    {
        "code":
            "count_relationship_mismatch",

        "path":
            "relationships.0.value",

        "expected":
            4,

        "actual":
            5
    },

    {
        "code":
            "invariant_source_path_missing",

        "path":
            "invariants.0.source_path",

        "actual":
            "permanent_geometry.missing_path"
    },

    {
        "code":
            "unknown_source_aspect",

        "path":
            "provenance.0.source_aspects",

        "actual":
            "fake_source_aspect"
    }
]
```

Một lần repair có thể sửa toàn bộ 5 lỗi.

Đây chính là lợi ích của việc **collect-all deterministic validation errors**.

---

## Kết luận của Phần 3

Sau phần này, responsibility đã rõ hoàn toàn:

```text
canonical_design_spec_v1.json
        │
        ▼
CanonicalSchemaValidator
        │
        │ shape/type/enum/required
        ▼
CanonicalDesignSpec DTO
        │
        ▼
CanonicalPathValidator
        │
        ▼
CanonicalCrossFieldValidator
        │
        │ internal consistency
        ▼
CanonicalProvenanceValidator
        │
        │ Inspiration lineage
        ▼
CanonicalDesignSpecValidator
        │
        ├── PASS
        │
        └── FAIL
              ↓
        CanonicalValidationException
              ↓
        ClaudeConceptRepairer
```

Tôi đặc biệt khuyên giữ nguyên nguyên tắc: **JSON Schema không làm semantic validation; DTO không repair; CrossField không đoán domain; Provenance không thiết kế lại concept; Repairer là nơi duy nhất được gọi LLM để sửa.** Đây là ranh giới giúp pipeline không dần biến thành một khối logic khó kiểm soát.

[1]: https://opis.io/json-schema/2.x/quick-start.html?utm_source=chatgpt.com "Quick start | Opis JSON Schema"
[2]: https://opis.io/json-schema/2.x/?utm_source=chatgpt.com "About | Opis JSON Schema"
[3]: https://opis.io/json-schema/2.x/examples.html?utm_source=chatgpt.com "Opis JSON Schema examples | Opis JSON Schema"
