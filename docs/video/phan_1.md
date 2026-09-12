Được. Tôi sẽ đưa **toàn bộ source của `Canonical Concept Production V1 — Hardened` trực tiếp trong chat**, theo đúng thứ tự file để bạn đọc và hỏi từng phần.

Tuy nhiên bộ này khá dài, nếu dồn toàn bộ 40–50 file vào **một message** sẽ rất khó đọc và dễ bị cắt. Tôi sẽ chia thành các phần liên tục, nhưng **không rút gọn code**:

**Phần 1 — Core Contract + Enums + DTO**

```text
Canonical/
├── Enums/
├── DesignThesis.php
├── DesignIdentity.php
├── Exclusion.php
├── Invariant.php
├── ProvenanceEntry.php
└── CanonicalDesignSpec.php
```

**Phần 2 — 11 Relationship DTO + Factory**

**Phần 3 — Validation**

**Phần 4 — Normalization + Hash + Freeze**

**Phần 5 — Claude/Sonnet Structured Output**

**Phần 6 — Orchestrator + Laravel ServiceProvider + config**

**Phần 7 — Prompts + tests + schema + README**

### Bắt đầu — PHẦN 1

#### `Canonical/Enums/ConstraintPrimitive.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Enums;

enum ConstraintPrimitive: string
{
    case COUNT = 'count';
    case PROPORTION = 'proportion';
    case POSITION = 'position';
    case ORDER = 'order';
    case CONNECTIVITY = 'connectivity';
    case CONTINUITY = 'continuity';
    case VISIBILITY = 'visibility';
    case MATERIAL = 'material';
    case STATE = 'state';
    case EXCLUSION = 'exclusion';
    case GEOMETRY = 'geometry';
    case LAYOUT = 'layout';
    case GROUPING = 'grouping';
    case ALIGNMENT = 'alignment';
    case CONTAINMENT = 'containment';
    case SYMMETRY = 'symmetry';
}
```

Enum này map trực tiếp với `$defs.constraintPrimitive` trong schema V1. Schema của bạn hiện quy định đúng 16 giá trị trên. 

---

#### `Canonical/Enums/InvariantSeverity.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Enums;

enum InvariantSeverity: string
{
    case HARD = 'hard';
    case SOFT = 'soft';
}
```

Tương ứng:

```json
"severity": {
    "type": "string",
    "enum": [
        "hard",
        "soft"
    ]
}
```

trong schema. 

---

#### `Canonical/Enums/ProvenanceOrigin.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Enums;

enum ProvenanceOrigin: string
{
    case INSPIRED = 'inspired';
    case INVENTED = 'invented';
}
```

Schema V1 quy định chính xác hai provenance origin này. 

---

#### `Canonical/Enums/RelationshipType.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Enums;

enum RelationshipType: string
{
    case COUNT = 'count';
    case GROUPING = 'grouping';
    case ONE_TO_ONE = 'one_to_one';
    case PROPORTION = 'proportion';
    case POSITION = 'position';
    case ORDER = 'order';
    case CONTINUITY = 'continuity';
    case CONNECTIVITY = 'connectivity';
    case SYMMETRY = 'symmetry';
    case CONTAINMENT = 'containment';
    case ALIGNMENT = 'alignment';
}
```

Đây là 11 relationship types tương ứng với `oneOf` trong `relationships[]`. 

---

# 5. `Canonical/DesignThesis.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical;

use InvalidArgumentException;

final class DesignThesis
{
    public const ROLE = 'soft_design_guidance';

    public function __construct(
        public readonly string $text,
        public readonly string $role = self::ROLE,
    ) {
        if (trim($text) === '') {
            throw new InvalidArgumentException(
                'design_thesis.text must not be empty.'
            );
        }

        if ($role !== self::ROLE) {
            throw new InvalidArgumentException(
                'design_thesis.role must equal soft_design_guidance.'
            );
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            text: (string) ($data['text'] ?? ''),
            role: (string) ($data['role'] ?? ''),
        );
    }

    /**
     * @return array{
     *     text:string,
     *     role:string
     * }
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'role' => $this->role,
        ];
    }
}
```

Schema tương ứng yêu cầu:

```text
design_thesis
├── text
└── role = soft_design_guidance
```



`design_thesis` chỉ là **soft guidance**. Nó không được quyền override các structured constraints phía dưới.

Ví dụ:

```json
{
    "design_thesis": {
        "text": "One continuous shell unifies four stepped volumes.",
        "role": "soft_design_guidance"
    }
}
```

Nếu thesis nói `four`, nhưng:

```json
"relationships": [
    {
        "type": "count",
        "value": 5
    }
]
```

thì downstream phải tin structured relationship/invariant, không tin câu prose.

---

# 6. `Canonical/DesignIdentity.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical;

use InvalidArgumentException;

final class DesignIdentity
{
    /**
     * @param list<string> $identityBasis
     */
    public function __construct(
        public readonly string $subjectClass,
        public readonly array $identityBasis,
    ) {
        if (trim($subjectClass) === '') {
            throw new InvalidArgumentException(
                'identity.subject_class must not be empty.'
            );
        }

        if ($identityBasis === []) {
            throw new InvalidArgumentException(
                'identity.identity_basis must contain at least one item.'
            );
        }

        if (
            count($identityBasis)
            !== count(array_unique($identityBasis))
        ) {
            throw new InvalidArgumentException(
                'identity.identity_basis must contain unique items.'
            );
        }

        foreach ($identityBasis as $index => $item) {
            if (
                !is_string($item)
                || trim($item) === ''
            ) {
                throw new InvalidArgumentException(
                    "identity.identity_basis[{$index}] "
                    . 'must be a non-empty string.'
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $basis = $data['identity_basis'] ?? [];

        if (
            !is_array($basis)
            || !array_is_list($basis)
        ) {
            throw new InvalidArgumentException(
                'identity.identity_basis must be a list.'
            );
        }

        return new self(
            subjectClass:
                (string) ($data['subject_class'] ?? ''),

            identityBasis:
                array_values($basis),
        );
    }

    /**
     * @return array{
     *     subject_class:string,
     *     identity_basis:list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'subject_class' => $this->subjectClass,

            'identity_basis' =>
                array_values($this->identityBasis),
        ];
    }
}
```

Nó map 1:1 với:

```text
identity
├── subject_class
└── identity_basis[]
```

và `identity_basis` bắt buộc `minItems: 1` + `uniqueItems: true`. 

---

# 7. `Canonical/Exclusion.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical;

use InvalidArgumentException;

final class Exclusion
{
    public function __construct(
        public readonly string $id,
        public readonly string $targetPath,
        public readonly string $forbid,
    ) {
        if (!preg_match('/^E[0-9]{3}$/', $id)) {
            throw new InvalidArgumentException(
                "Exclusion id must match "
                . "^E[0-9]{3}$: {$id}"
            );
        }

        if (
            trim($targetPath) === ''
            || trim($forbid) === ''
        ) {
            throw new InvalidArgumentException(
                'Exclusion target_path and forbid '
                . 'must not be empty.'
            );
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:
                (string) ($data['id'] ?? ''),

            targetPath:
                (string) ($data['target_path'] ?? ''),

            forbid:
                (string) ($data['forbid'] ?? ''),
        );
    }

    /**
     * @return array{
     *     id:string,
     *     target_path:string,
     *     forbid:string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'target_path' => $this->targetPath,
            'forbid' => $this->forbid,
        ];
    }
}
```

Schema yêu cầu đúng ba field này và ID dạng `E001`. 

Ví dụ:

```json
{
    "id": "E001",
    "target_path": "permanent_geometry.superstructure",
    "forbid": "additional fifth enclosed tier"
}
```

Đây là **design exclusion của thiết kế mới**.

Nó khác với:

```text
InspirationBrief.excluded_context
```

`excluded_context` là:

> thông tin nguồn không được Sonnet mang sang thiết kế.

Còn `CanonicalDesignSpec.exclusions` là:

> điều thiết kế canonical mới chủ động cấm downstream tạo ra.

Hai khái niệm không được trộn.

---

# 8. `Canonical/Invariant.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical;

use App\Video\Concept\Canonical\Enums\ConstraintPrimitive;
use App\Video\Concept\Canonical\Enums\InvariantSeverity;
use InvalidArgumentException;

final class Invariant
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $sourcePath,
        public readonly ConstraintPrimitive $constraintType,
        public readonly InvariantSeverity $severity,
        public readonly bool $visualVerification,
    ) {
        if (!preg_match('/^I[0-9]{3}$/', $id)) {
            throw new InvalidArgumentException(
                "Invariant id must match "
                . "^I[0-9]{3}$: {$id}"
            );
        }

        if (
            trim($name) === ''
            || trim($sourcePath) === ''
        ) {
            throw new InvalidArgumentException(
                'Invariant name and source_path '
                . 'must not be empty.'
            );
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:
                (string) ($data['id'] ?? ''),

            name:
                (string) ($data['name'] ?? ''),

            sourcePath:
                (string) ($data['source_path'] ?? ''),

            constraintType:
                ConstraintPrimitive::from(
                    (string) (
                        $data['constraint_type'] ?? ''
                    )
                ),

            severity:
                InvariantSeverity::from(
                    (string) (
                        $data['severity'] ?? ''
                    )
                ),

            visualVerification:
                (bool) (
                    $data['visual_verification'] ?? false
                ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,

            'name' => $this->name,

            'source_path' =>
                $this->sourcePath,

            'constraint_type' =>
                $this->constraintType->value,

            'severity' =>
                $this->severity->value,

            'visual_verification' =>
                $this->visualVerification,
        ];
    }
}
```

Nó map đúng sáu fields của `invariants[]`. 

Đây là phần cực kỳ quan trọng cho pipeline ảnh/video sau này.

Ví dụ:

```json
{
    "id": "I001",
    "name": "Exactly four primary tiers",
    "source_path":
        "permanent_geometry.superstructure.primary_tier_count",
    "constraint_type": "count",
    "severity": "hard",
    "visual_verification": true
}
```

Sau này Python có thể biến nó thành:

```text
Canonical invariant
        ↓
Constraint Normalizer
        ↓
COUNT constraint
        ↓
Prompt Compiler
        ↓
Vision QA
```

---

# 9. `Canonical/ProvenanceEntry.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical;

use App\Video\Concept\Canonical\Enums\ProvenanceOrigin;
use InvalidArgumentException;

final class ProvenanceEntry
{
    /**
     * @param list<string> $sourceAspects
     */
    public function __construct(
        public readonly string $targetPath,
        public readonly ProvenanceOrigin $origin,
        public readonly array $sourceAspects,
    ) {
        if (trim($targetPath) === '') {
            throw new InvalidArgumentException(
                'provenance.target_path must not be empty.'
            );
        }

        if (
            count($sourceAspects)
            !== count(array_unique($sourceAspects))
        ) {
            throw new InvalidArgumentException(
                'provenance.source_aspects '
                . 'must contain unique items.'
            );
        }

        foreach ($sourceAspects as $index => $aspect) {
            if (
                !is_string($aspect)
                || trim($aspect) === ''
            ) {
                throw new InvalidArgumentException(
                    "provenance.source_aspects[{$index}] "
                    . 'must be a non-empty string.'
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $sourceAspects =
            $data['source_aspects'] ?? [];

        if (
            !is_array($sourceAspects)
            || !array_is_list($sourceAspects)
        ) {
            throw new InvalidArgumentException(
                'provenance.source_aspects '
                . 'must be a list.'
            );
        }

        return new self(
            targetPath:
                (string) (
                    $data['target_path'] ?? ''
                ),

            origin:
                ProvenanceOrigin::from(
                    (string) (
                        $data['origin'] ?? ''
                    )
                ),

            sourceAspects:
                array_values($sourceAspects),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'target_path' =>
                $this->targetPath,

            'origin' =>
                $this->origin->value,

            'source_aspects' =>
                array_values($this->sourceAspects),
        ];
    }
}
```

Schema V1 định nghĩa chính xác `target_path`, `origin`, `source_aspects`. 

Semantic rule của chúng ta mạnh hơn JSON Schema:

```text
origin = inspired
→ source_aspects >= 1
→ mỗi aspect phải tồn tại trong InspirationBrief

origin = invented
→ source_aspects = []
```

Schema chỉ kiểm shape. `CanonicalProvenanceValidator` mới kiểm logic này.

---

# 10. `Canonical/CanonicalDesignSpec.php`

Đây là **root DTO quan trọng nhất**.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical;

use App\Video\Concept\Canonical\Relationships\Relationship;
use App\Video\Concept\Canonical\Relationships\RelationshipFactory;
use InvalidArgumentException;

final class CanonicalDesignSpec
{
    public const SCHEMA_VERSION = '1.0';

    /**
     * @param array<string,mixed> $dimensions
     * @param array<string,mixed> $permanentGeometry
     * @param list<Relationship> $relationships
     * @param array<string,mixed> $formRelationships
     * @param array<string,mixed> $finishedMaterials
     * @param list<Exclusion> $exclusions
     * @param list<Invariant> $invariants
     * @param list<ProvenanceEntry> $provenance
     */
    public function __construct(
        public readonly string $schemaVersion,
        public readonly string $objectType,
        public readonly DesignThesis $designThesis,
        public readonly DesignIdentity $identity,

        public readonly array $dimensions,

        public readonly array $permanentGeometry,

        public readonly array $relationships,

        public readonly array $formRelationships,

        public readonly array $finishedMaterials,

        public readonly array $exclusions,

        public readonly array $invariants,

        public readonly array $provenance,
    ) {
        if (
            $schemaVersion
            !== self::SCHEMA_VERSION
        ) {
            throw new InvalidArgumentException(
                'schema_version must equal '
                . self::SCHEMA_VERSION
            );
        }

        if (trim($objectType) === '') {
            throw new InvalidArgumentException(
                'object_type must not be empty.'
            );
        }

        if ($invariants === []) {
            throw new InvalidArgumentException(
                'invariants must contain '
                . 'at least one item.'
            );
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(
        array $data
    ): self {
        return new self(
            schemaVersion:
                (string) (
                    $data['schema_version'] ?? ''
                ),

            objectType:
                (string) (
                    $data['object_type'] ?? ''
                ),

            designThesis:
                DesignThesis::fromArray(
                    self::object(
                        $data,
                        'design_thesis'
                    )
                ),

            identity:
                DesignIdentity::fromArray(
                    self::object(
                        $data,
                        'identity'
                    )
                ),

            dimensions:
                self::object(
                    $data,
                    'dimensions'
                ),

            permanentGeometry:
                self::object(
                    $data,
                    'permanent_geometry'
                ),

            relationships:
                array_map(
                    static fn (
                        array $item
                    ): Relationship =>
                        RelationshipFactory::fromArray(
                            $item
                        ),

                    self::objectList(
                        $data,
                        'relationships'
                    ),
                ),

            formRelationships:
                self::object(
                    $data,
                    'form_relationships'
                ),

            finishedMaterials:
                self::object(
                    $data,
                    'finished_materials'
                ),

            exclusions:
                array_map(
                    static fn (
                        array $item
                    ): Exclusion =>
                        Exclusion::fromArray(
                            $item
                        ),

                    self::objectList(
                        $data,
                        'exclusions'
                    ),
                ),

            invariants:
                array_map(
                    static fn (
                        array $item
                    ): Invariant =>
                        Invariant::fromArray(
                            $item
                        ),

                    self::objectList(
                        $data,
                        'invariants'
                    ),
                ),

            provenance:
                array_map(
                    static fn (
                        array $item
                    ): ProvenanceEntry =>
                        ProvenanceEntry::fromArray(
                            $item
                        ),

                    self::objectList(
                        $data,
                        'provenance'
                    ),
                ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' =>
                $this->schemaVersion,

            'object_type' =>
                $this->objectType,

            'design_thesis' =>
                $this->designThesis->toArray(),

            'identity' =>
                $this->identity->toArray(),

            'dimensions' =>
                $this->dimensions,

            'permanent_geometry' =>
                $this->permanentGeometry,

            'relationships' =>
                array_map(
                    static fn (
                        Relationship $item
                    ): array =>
                        $item->toArray(),

                    $this->relationships,
                ),

            'form_relationships' =>
                $this->formRelationships,

            'finished_materials' =>
                $this->finishedMaterials,

            'exclusions' =>
                array_map(
                    static fn (
                        Exclusion $item
                    ): array =>
                        $item->toArray(),

                    $this->exclusions,
                ),

            'invariants' =>
                array_map(
                    static fn (
                        Invariant $item
                    ): array =>
                        $item->toArray(),

                    $this->invariants,
                ),

            'provenance' =>
                array_map(
                    static fn (
                        ProvenanceEntry $item
                    ): array =>
                        $item->toArray(),

                    $this->provenance,
                ),
        ];
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private static function object(
        array $data,
        string $key
    ): array {
        $value = $data[$key] ?? null;

        if (!is_array($value)) {
            throw new InvalidArgumentException(
                "{$key} must be an object."
            );
        }

        /*
         * IMPORTANT:
         *
         * PHP cannot distinguish an empty JSON object {}
         * from an empty associative array after decoding
         * with json_decode(..., true).
         *
         * Therefore [] is accepted here for an EMPTY
         * domain-open object. JSON Schema validation has
         * already been performed against the raw JSON
         * before hydration.
         *
         * Non-empty list arrays remain invalid objects.
         */
        if (
            $value !== []
            && array_is_list($value)
        ) {
            throw new InvalidArgumentException(
                "{$key} must be an object."
            );
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return list<array<string,mixed>>
     */
    private static function objectList(
        array $data,
        string $key
    ): array {
        $value = $data[$key] ?? null;

        if (
            !is_array($value)
            || !array_is_list($value)
        ) {
            throw new InvalidArgumentException(
                "{$key} must be a list."
            );
        }

        foreach (
            $value as $index => $item
        ) {
            if (
                !is_array($item)
                || $item === []
                || array_is_list($item)
            ) {
                throw new InvalidArgumentException(
                    "{$key}[{$index}] "
                    . 'must be an object.'
                );
            }
        }

        return array_values($value);
    }
}
```

Root này map với toàn bộ required contract V1 của bạn. 

Và tôi muốn bạn đặc biệt nhớ ranh giới này:

```text
CanonicalDesignSpec
│
├── typed Core
│   ├── DesignThesis
│   ├── DesignIdentity
│   ├── Relationship[]
│   ├── Exclusion[]
│   ├── Invariant[]
│   └── ProvenanceEntry[]
│
└── domain-open Core
    ├── dimensions
    ├── permanent_geometry
    ├── form_relationships
    └── finished_materials
```

Bốn `domain-open` này **không phải thiết kế thiếu**. Schema V1 chủ động để `dimensions` và `permanent_geometry` cho category profile refine. 

Ví dụ:

```text
Core V1
        ↓
CategoryCreativeProfile
        ↓
marine_vessel profile
        ↓
dimensions:
  length_m
  beam_m
  draft_m
  ...

permanent_geometry:
  bow
  hull
  stern
  superstructure
```

Nhưng với aircraft:

```text
Core V1
        ↓
aircraft profile
        ↓
dimensions:
  length_m
  wingspan_m
  height_m

permanent_geometry:
  fuselage
  wing
  tail
  engine_mounting
```

Nhờ vậy Core không bị biến thành:

```text
if yacht...
if aircraft...
if building...
if car...
```

Đây chính là phần giữ cho hệ thống của bạn có thể mở rộng đa chủ đề.

**Đây mới là Phần 1.** Tôi sẽ giữ nguyên cách trình bày này cho phần tiếp theo: **toàn bộ 11 Relationship DTO + `RelationshipFactory` + giải thích từng loại dùng để làm gì**, không rút gọn code.
