Dưới đây là **Phần 2 — toàn bộ 11 Relationship DTO + `RelationshipFactory`**, đúng theo `canonical_design_spec_v1.json`, không rút gọn code. Tôi giữ luôn `Relationship.php` và `RelationshipGuards.php` vì hai file này là phần nền để 11 DTO chạy đúng và không lặp validation.

## 1. `Canonical/Relationships/Relationship.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;

interface Relationship
{
    public function id(): string;

    public function type(): RelationshipType;

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array;
}
```

### Dùng để làm gì

Đây là contract chung cho mọi relationship.

Mọi relationship đều bắt buộc có:

```text
id
type
toArray()
```

Nhờ vậy `CanonicalDesignSpec` không cần biết cụ thể đang cầm:

```text
CountRelationship
GroupingRelationship
PositionRelationship
...
```

Nó chỉ cần:

```php
/** @var list<Relationship> */
public readonly array $relationships;
```

Sau này Python compiler cũng có thể dispatch theo:

```text
relationship.type
```

thay vì đọc prose.

---

# 2. `Canonical/Relationships/RelationshipGuards.php`

```php
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
```

### Dùng để làm gì

Nó gom validation cơ bản dùng chung:

```text
R001
R002
R003
```

thay vì mỗi DTO lặp lại regex.

Đây không phải semantic validator.

Ví dụ nó biết:

```text
R12
```

là sai format.

Nhưng nó không biết:

```text
permanent_geometry.hull
```

có thật sự tồn tại hay không.

Việc đó thuộc:

```text
CanonicalPathValidator
CanonicalCrossFieldValidator
```

---

# 3. `CountRelationship.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;
use InvalidArgumentException;

final class CountRelationship implements Relationship
{
    public function __construct(
        public readonly string $relationshipId,
        public readonly string $subjectPath,
        public readonly int $value,
    ) {
        RelationshipGuards::relationshipId(
            $relationshipId
        );

        RelationshipGuards::nonEmpty(
            $subjectPath,
            'subject_path'
        );

        if ($value < 0) {
            throw new InvalidArgumentException(
                'CountRelationship.value '
                . 'must be >= 0.'
            );
        }
    }

    public function id(): string
    {
        return $this->relationshipId;
    }

    public function type(): RelationshipType
    {
        return RelationshipType::COUNT;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->relationshipId,

            'type' =>
                $this->type()->value,

            'subject_path' =>
                $this->subjectPath,

            'value' =>
                $this->value,
        ];
    }
}
```

Schema tương ứng với `countRelationship`. 

### Dùng để làm gì

Dùng khi một số lượng là **thiết kế có chủ ý**.

Ví dụ:

```json
{
    "id": "R001",
    "type": "count",
    "subject_path":
        "permanent_geometry.superstructure.primary_tier_count",
    "value": 4
}
```

Ý nghĩa:

> thiết kế có đúng 4 primary tiers.

Sau này compiler có thể biến thành hard constraint:

```text
exactly four primary enclosed tiers
```

Vision QA cũng có thể kiểm:

```text
expected = 4
observed = 3
→ fail
```

Điểm quan trọng: `CountRelationship` không nên được tạo chỉ vì tình cờ một field có số `4`.

Nó phải biểu diễn một count **có semantic importance**.

---

# 4. `GroupingRelationship.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;
use InvalidArgumentException;

final class GroupingRelationship implements Relationship
{
    public function __construct(
        public readonly string $relationshipId,
        public readonly string $subjectPath,
        public readonly int $groupCount,
        public readonly ?string $groupedIntoPath = null,
        public readonly ?string $meaning = null,
    ) {
        RelationshipGuards::relationshipId(
            $relationshipId
        );

        RelationshipGuards::nonEmpty(
            $subjectPath,
            'subject_path'
        );

        if ($groupCount < 1) {
            throw new InvalidArgumentException(
                'GroupingRelationship.group_count '
                . 'must be >= 1.'
            );
        }
    }

    public function id(): string
    {
        return $this->relationshipId;
    }

    public function type(): RelationshipType
    {
        return RelationshipType::GROUPING;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $out = [
            'id' =>
                $this->relationshipId,

            'type' =>
                $this->type()->value,

            'subject_path' =>
                $this->subjectPath,

            'group_count' =>
                $this->groupCount,
        ];

        if (
            $this->groupedIntoPath !== null
        ) {
            $out['grouped_into_path'] =
                $this->groupedIntoPath;
        }

        if ($this->meaning !== null) {
            $out['meaning'] =
                $this->meaning;
        }

        return $out;
    }
}
```

Schema tương ứng. 

### Dùng để làm gì

`count` nói:

> có bao nhiêu.

`grouping` nói:

> những phần tử đó được tổ chức thành bao nhiêu nhóm.

Ví dụ một facade có:

```text
12 cửa sổ
```

nhưng thiết kế lại tổ chức:

```text
3 cụm
mỗi cụm 4 cửa
```

Thì có thể tồn tại cả:

```text
COUNT = 12
GROUPING = 3
```

Ví dụ:

```json
{
    "id": "R004",
    "type": "grouping",
    "subject_path":
        "permanent_geometry.facade.window_modules",
    "group_count": 3,
    "grouped_into_path":
        "permanent_geometry.facade.window_bays",
    "meaning":
        "windows are organized into three primary bays"
}
```

Đây rất hữu ích với image model vì:

```text
12 objects
```

và:

```text
3 groups of objects
```

là hai constraint khác nhau.

---

# 5. `OneToOneRelationship.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;

final class OneToOneRelationship implements Relationship
{
    public function __construct(
        public readonly string $relationshipId,
        public readonly string $sourcePath,
        public readonly string $targetPath,
        public readonly ?string $meaning = null,
    ) {
        RelationshipGuards::relationshipId(
            $relationshipId
        );

        RelationshipGuards::nonEmpty(
            $sourcePath,
            'source_path'
        );

        RelationshipGuards::nonEmpty(
            $targetPath,
            'target_path'
        );
    }

    public function id(): string
    {
        return $this->relationshipId;
    }

    public function type(): RelationshipType
    {
        return RelationshipType::ONE_TO_ONE;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $out = [
            'id' =>
                $this->relationshipId,

            'type' =>
                $this->type()->value,

            'source_path' =>
                $this->sourcePath,

            'target_path' =>
                $this->targetPath,
        ];

        if ($this->meaning !== null) {
            $out['meaning'] =
                $this->meaning;
        }

        return $out;
    }
}
```

Schema tương ứng. 

### Dùng để làm gì

Dùng khi mỗi phần tử bên A phải map đúng với một phần tử bên B.

Ví dụ:

```text
mỗi engine nacelle
↔
một engine
```

hoặc:

```text
mỗi exterior staircase
↔
một opening
```

Ví dụ:

```json
{
    "id": "R005",
    "type": "one_to_one",
    "source_path":
        "permanent_geometry.engine_nacelles",
    "target_path":
        "permanent_geometry.engines",
    "meaning":
        "each nacelle contains exactly one engine"
}
```

Điểm quan trọng:

```text
count A = 4
count B = 4
```

không tự động có nghĩa:

```text
A ↔ B one-to-one
```

Sonnet chỉ được khai báo nếu đó là relationship có chủ ý.

---

# 6. `ProportionRelationship.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;
use InvalidArgumentException;

final class ProportionRelationship implements Relationship
{
    public function __construct(
        public readonly string $relationshipId,
        public readonly string $subjectPath,
        public readonly string $metric,
        public readonly float $value,
        public readonly ?float $tolerance = null,
    ) {
        RelationshipGuards::relationshipId(
            $relationshipId
        );

        RelationshipGuards::nonEmpty(
            $subjectPath,
            'subject_path'
        );

        RelationshipGuards::nonEmpty(
            $metric,
            'metric'
        );

        if (
            $tolerance !== null
            && $tolerance < 0
        ) {
            throw new InvalidArgumentException(
                'ProportionRelationship.tolerance '
                . 'must be >= 0.'
            );
        }
    }

    public function id(): string
    {
        return $this->relationshipId;
    }

    public function type(): RelationshipType
    {
        return RelationshipType::PROPORTION;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $out = [
            'id' =>
                $this->relationshipId,

            'type' =>
                $this->type()->value,

            'subject_path' =>
                $this->subjectPath,

            'metric' =>
                $this->metric,

            'value' =>
                $this->value,
        ];

        if ($this->tolerance !== null) {
            $out['tolerance'] =
                $this->tolerance;
        }

        return $out;
    }
}
```

Schema tương ứng. 

### Dùng để làm gì

Dùng cho ratio/proportion có tính nhận diện.

Ví dụ:

```json
{
    "id": "R006",
    "type": "proportion",
    "subject_path": "dimensions",
    "metric": "length_to_beam_ratio",
    "value": 5.8,
    "tolerance": 0.15
}
```

Compiler có thể hiểu:

```text
maintain a long, slender 5.8:1 overall proportion
```

Vision QA có thể đánh giá gần đúng silhouette.

Không nên dùng cho mọi numeric measurement.

Ví dụ:

```text
length = 90m
```

đó là dimension.

Còn:

```text
length / beam = 5.8
```

nếu nó định nghĩa silhouette thì rất hợp với `proportion`.

---

# 7. `PositionRelationship.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;

final class PositionRelationship implements Relationship
{
    public function __construct(
        public readonly string $relationshipId,
        public readonly string $subjectPath,
        public readonly string $referencePath,
        public readonly string $value,
    ) {
        RelationshipGuards::relationshipId(
            $relationshipId
        );

        RelationshipGuards::nonEmpty(
            $subjectPath,
            'subject_path'
        );

        RelationshipGuards::nonEmpty(
            $referencePath,
            'reference_path'
        );

        RelationshipGuards::nonEmpty(
            $value,
            'value'
        );
    }

    public function id(): string
    {
        return $this->relationshipId;
    }

    public function type(): RelationshipType
    {
        return RelationshipType::POSITION;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' =>
                $this->relationshipId,

            'type' =>
                $this->type()->value,

            'subject_path' =>
                $this->subjectPath,

            'reference_path' =>
                $this->referencePath,

            'value' =>
                $this->value,
        ];
    }
}
```

Schema tương ứng. 

### Dùng để làm gì

Mô tả vị trí của subject **so với một reference khác**.

Ví dụ:

```json
{
    "id": "R007",
    "type": "position",
    "subject_path":
        "permanent_geometry.observation_fin",
    "reference_path":
        "permanent_geometry.bow",
    "value":
        "approximately two metres aft of bow crown"
}
```

Nó tốt hơn prose rải rác bởi vì compiler biết rõ:

```text
subject
reference
relationship value
```

Sau này có thể chuyển thành constraint:

```text
POSITION
subject = observation_fin
reference = bow
value = 2m aft
```

---

# 8. `OrderRelationship.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;

final class OrderRelationship implements Relationship
{
    /**
     * @param list<string> $items
     */
    public function __construct(
        public readonly string $relationshipId,
        public readonly array $items,
        public readonly ?string $direction = null,
    ) {
        RelationshipGuards::relationshipId(
            $relationshipId
        );

        RelationshipGuards::nonEmptyStrings(
            $items,
            'items',
            2
        );
    }

    public function id(): string
    {
        return $this->relationshipId;
    }

    public function type(): RelationshipType
    {
        return RelationshipType::ORDER;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $out = [
            'id' =>
                $this->relationshipId,

            'type' =>
                $this->type()->value,

            'items' =>
                array_values($this->items),
        ];

        if ($this->direction !== null) {
            $out['direction'] =
                $this->direction;
        }

        return $out;
    }
}
```

Schema tương ứng. 

### Dùng để làm gì

Dùng khi **thứ tự** là semantic.

Ví dụ:

```json
{
    "id": "R008",
    "type": "order",
    "items": [
        "permanent_geometry.superstructure.tier_1",
        "permanent_geometry.superstructure.tier_2",
        "permanent_geometry.superstructure.tier_3",
        "permanent_geometry.superstructure.tier_4"
    ],
    "direction":
        "bottom_to_top"
}
```

Điểm rất quan trọng:

`Normalizer` **không được sort `items`**.

Vì:

```text
tier_1
tier_2
tier_3
tier_4
```

là semantic order.

Nếu sort bừa thì contract bị thay đổi nghĩa.

---

# 9. `ContinuityRelationship.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;

final class ContinuityRelationship implements Relationship
{
    public function __construct(
        public readonly string $relationshipId,
        public readonly string $subjectPath,
        public readonly string $value,
        public readonly ?string $startPath = null,
        public readonly ?string $endPath = null,
    ) {
        RelationshipGuards::relationshipId(
            $relationshipId
        );

        RelationshipGuards::nonEmpty(
            $subjectPath,
            'subject_path'
        );

        RelationshipGuards::nonEmpty(
            $value,
            'value'
        );
    }

    public function id(): string
    {
        return $this->relationshipId;
    }

    public function type(): RelationshipType
    {
        return RelationshipType::CONTINUITY;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $out = [
            'id' =>
                $this->relationshipId,

            'type' =>
                $this->type()->value,

            'subject_path' =>
                $this->subjectPath,

            'value' =>
                $this->value,
        ];

        if ($this->startPath !== null) {
            $out['start_path'] =
                $this->startPath;
        }

        if ($this->endPath !== null) {
            $out['end_path'] =
                $this->endPath;
        }

        return $out;
    }
}
```

Schema tương ứng. 

### Dùng để làm gì

Dùng cho đường, surface, shell, ribbon, edge hoặc feature phải **liên tục**, không bị chia đoạn.

Ví dụ:

```json
{
    "id": "R009",
    "type": "continuity",
    "subject_path":
        "form_relationships.sheer_line",
    "value":
        "continuous_unbroken",
    "start_path":
        "permanent_geometry.bow",
    "end_path":
        "permanent_geometry.stern"
}
```

Đây là primitive cực kỳ quan trọng trong tạo ảnh.

Image model rất hay:

```text
đứt ribbon window
chia sheer line
tách shell
```

Compiler có thể ưu tiên:

```text
one continuous uninterrupted line from bow to stern
```

---

# 10. `ConnectivityRelationship.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;

final class ConnectivityRelationship implements Relationship
{
    /**
     * @param list<string> $members
     */
    public function __construct(
        public readonly string $relationshipId,
        public readonly array $members,
        public readonly string $value,
    ) {
        RelationshipGuards::relationshipId(
            $relationshipId
        );

        RelationshipGuards::nonEmptyStrings(
            $members,
            'members',
            2
        );

        RelationshipGuards::nonEmpty(
            $value,
            'value'
        );
    }

    public function id(): string
    {
        return $this->relationshipId;
    }

    public function type(): RelationshipType
    {
        return RelationshipType::CONNECTIVITY;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' =>
                $this->relationshipId,

            'type' =>
                $this->type()->value,

            'members' =>
                array_values($this->members),

            'value' =>
                $this->value,
        ];
    }
}
```

Schema tương ứng. 

### Dùng để làm gì

Khác `continuity`.

`continuity` hỏi:

> feature này có chạy liên tục không?

`connectivity` hỏi:

> các bộ phận này có kết nối với nhau không?

Ví dụ:

```json
{
    "id": "R010",
    "type": "connectivity",
    "members": [
        "permanent_geometry.primary_mass",
        "permanent_geometry.secondary_mass_a",
        "permanent_geometry.secondary_mass_b"
    ],
    "value":
        "physically_integrated"
}
```

Rất hữu ích để tránh model render thành:

```text
3 khối rời nhau
```

trong khi canonical design muốn:

```text
3 masses nhưng thành một object duy nhất.
```

---

# 11. `SymmetryRelationship.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;

final class SymmetryRelationship implements Relationship
{
    public function __construct(
        public readonly string $relationshipId,
        public readonly string $subjectPath,
        public readonly string $axis,
        public readonly string $value,
    ) {
        RelationshipGuards::relationshipId(
            $relationshipId
        );

        RelationshipGuards::nonEmpty(
            $subjectPath,
            'subject_path'
        );

        RelationshipGuards::nonEmpty(
            $axis,
            'axis'
        );

        RelationshipGuards::nonEmpty(
            $value,
            'value'
        );
    }

    public function id(): string
    {
        return $this->relationshipId;
    }

    public function type(): RelationshipType
    {
        return RelationshipType::SYMMETRY;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' =>
                $this->relationshipId,

            'type' =>
                $this->type()->value,

            'subject_path' =>
                $this->subjectPath,

            'axis' =>
                $this->axis,

            'value' =>
                $this->value,
        ];
    }
}
```

Schema tương ứng. 

### Dùng để làm gì

Khai báo symmetry có chủ ý.

Ví dụ:

```json
{
    "id": "R011",
    "type": "symmetry",
    "subject_path":
        "permanent_geometry.exterior_staircases",
    "axis":
        "longitudinal_centerline",
    "value":
        "bilateral_mirror_symmetry"
}
```

Compiler hiểu:

```text
port and starboard staircases are mirrored
```

Vision QA cũng có thể kiểm hai phía.

Không nên mặc định mọi vật thể là symmetric.

Nếu design asymmetric thì không tạo relationship này.

---

# 12. `ContainmentRelationship.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;

final class ContainmentRelationship implements Relationship
{
    public function __construct(
        public readonly string $relationshipId,
        public readonly string $containerPath,
        public readonly string $containedPath,
    ) {
        RelationshipGuards::relationshipId(
            $relationshipId
        );

        RelationshipGuards::nonEmpty(
            $containerPath,
            'container_path'
        );

        RelationshipGuards::nonEmpty(
            $containedPath,
            'contained_path'
        );
    }

    public function id(): string
    {
        return $this->relationshipId;
    }

    public function type(): RelationshipType
    {
        return RelationshipType::CONTAINMENT;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' =>
                $this->relationshipId,

            'type' =>
                $this->type()->value,

            'container_path' =>
                $this->containerPath,

            'contained_path' =>
                $this->containedPath,
        ];
    }
}
```

Schema tương ứng. 

### Dùng để làm gì

Mô tả:

```text
A chứa B
```

Ví dụ:

```json
{
    "id": "R012",
    "type": "containment",
    "container_path":
        "permanent_geometry.transom_platform",
    "contained_path":
        "permanent_geometry.pool"
}
```

Ý nghĩa:

```text
pool nằm trong platform
```

chứ không phải:

```text
pool đứng cạnh platform
```

Với image generation, khác biệt này rất lớn.

---

# 13. `AlignmentRelationship.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;

final class AlignmentRelationship implements Relationship
{
    /**
     * @param list<string> $members
     */
    public function __construct(
        public readonly string $relationshipId,
        public readonly array $members,
        public readonly string $value,
    ) {
        RelationshipGuards::relationshipId(
            $relationshipId
        );

        RelationshipGuards::nonEmptyStrings(
            $members,
            'members',
            2
        );

        RelationshipGuards::nonEmpty(
            $value,
            'value'
        );
    }

    public function id(): string
    {
        return $this->relationshipId;
    }

    public function type(): RelationshipType
    {
        return RelationshipType::ALIGNMENT;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' =>
                $this->relationshipId,

            'type' =>
                $this->type()->value,

            'members' =>
                array_values($this->members),

            'value' =>
                $this->value,
        ];
    }
}
```

Schema tương ứng. 

### Dùng để làm gì

Mô tả nhiều bộ phận phải nằm chung một alignment.

Ví dụ:

```json
{
    "id": "R013",
    "type": "alignment",
    "members": [
        "permanent_geometry.window_band.port",
        "permanent_geometry.window_band.starboard"
    ],
    "value":
        "same_horizontal_datum"
}
```

Hoặc kiến trúc:

```text
columns
windows
balconies
```

cùng trục đứng.

Compiler có thể chuyển thành:

```text
strictly aligned along the same horizontal datum
```

---

# 14. `RelationshipFactory.php`

Đây là file trung tâm dùng để hydrate JSON của Sonnet thành đúng DTO subtype.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;
use InvalidArgumentException;

final class RelationshipFactory
{
    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(
        array $data
    ): Relationship {
        $type =
            RelationshipType::tryFrom(
                (string) (
                    $data['type'] ?? ''
                )
            );

        if ($type === null) {
            throw new InvalidArgumentException(
                'Unsupported relationship type: '
                . (string) (
                    $data['type'] ?? ''
                )
            );
        }

        return match ($type) {
            RelationshipType::COUNT =>
                new CountRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    subjectPath:
                        (string) (
                            $data['subject_path']
                            ?? ''
                        ),

                    value:
                        self::int(
                            $data,
                            'value'
                        ),
                ),

            RelationshipType::GROUPING =>
                new GroupingRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    subjectPath:
                        (string) (
                            $data['subject_path']
                            ?? ''
                        ),

                    groupCount:
                        self::int(
                            $data,
                            'group_count'
                        ),

                    groupedIntoPath:
                        self::nullableString(
                            $data,
                            'grouped_into_path'
                        ),

                    meaning:
                        self::nullableString(
                            $data,
                            'meaning'
                        ),
                ),

            RelationshipType::ONE_TO_ONE =>
                new OneToOneRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    sourcePath:
                        (string) (
                            $data['source_path']
                            ?? ''
                        ),

                    targetPath:
                        (string) (
                            $data['target_path']
                            ?? ''
                        ),

                    meaning:
                        self::nullableString(
                            $data,
                            'meaning'
                        ),
                ),

            RelationshipType::PROPORTION =>
                new ProportionRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    subjectPath:
                        (string) (
                            $data['subject_path']
                            ?? ''
                        ),

                    metric:
                        (string) (
                            $data['metric'] ?? ''
                        ),

                    value:
                        self::float(
                            $data,
                            'value'
                        ),

                    tolerance:
                        array_key_exists(
                            'tolerance',
                            $data
                        )
                            ? self::float(
                                $data,
                                'tolerance'
                            )
                            : null,
                ),

            RelationshipType::POSITION =>
                new PositionRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    subjectPath:
                        (string) (
                            $data['subject_path']
                            ?? ''
                        ),

                    referencePath:
                        (string) (
                            $data['reference_path']
                            ?? ''
                        ),

                    value:
                        (string) (
                            $data['value'] ?? ''
                        ),
                ),

            RelationshipType::ORDER =>
                new OrderRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    items:
                        self::stringList(
                            $data['items'] ?? []
                        ),

                    direction:
                        self::nullableString(
                            $data,
                            'direction'
                        ),
                ),

            RelationshipType::CONTINUITY =>
                new ContinuityRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    subjectPath:
                        (string) (
                            $data['subject_path']
                            ?? ''
                        ),

                    value:
                        (string) (
                            $data['value'] ?? ''
                        ),

                    startPath:
                        self::nullableString(
                            $data,
                            'start_path'
                        ),

                    endPath:
                        self::nullableString(
                            $data,
                            'end_path'
                        ),
                ),

            RelationshipType::CONNECTIVITY =>
                new ConnectivityRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    members:
                        self::stringList(
                            $data['members'] ?? []
                        ),

                    value:
                        (string) (
                            $data['value'] ?? ''
                        ),
                ),

            RelationshipType::SYMMETRY =>
                new SymmetryRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    subjectPath:
                        (string) (
                            $data['subject_path']
                            ?? ''
                        ),

                    axis:
                        (string) (
                            $data['axis'] ?? ''
                        ),

                    value:
                        (string) (
                            $data['value'] ?? ''
                        ),
                ),

            RelationshipType::CONTAINMENT =>
                new ContainmentRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    containerPath:
                        (string) (
                            $data['container_path']
                            ?? ''
                        ),

                    containedPath:
                        (string) (
                            $data['contained_path']
                            ?? ''
                        ),
                ),

            RelationshipType::ALIGNMENT =>
                new AlignmentRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    members:
                        self::stringList(
                            $data['members'] ?? []
                        ),

                    value:
                        (string) (
                            $data['value'] ?? ''
                        ),
                ),
        };
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function int(
        array $data,
        string $key
    ): int {
        if (
            ! array_key_exists(
                $key,
                $data
            )
            || ! is_int($data[$key])
        ) {
            throw new InvalidArgumentException(
                "{$key} must be an integer."
            );
        }

        return $data[$key];
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function float(
        array $data,
        string $key
    ): float {
        if (
            ! array_key_exists(
                $key,
                $data
            )
            || (
                ! is_int($data[$key])
                && ! is_float($data[$key])
            )
        ) {
            throw new InvalidArgumentException(
                "{$key} must be a number."
            );
        }

        return (float) $data[$key];
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function nullableString(
        array $data,
        string $key
    ): ?string {
        if (
            ! array_key_exists(
                $key,
                $data
            )
        ) {
            return null;
        }

        if (! is_string($data[$key])) {
            throw new InvalidArgumentException(
                "{$key} must be a string "
                . 'when present.'
            );
        }

        return $data[$key];
    }

    /**
     * @return list<string>
     */
    private static function stringList(
        mixed $value
    ): array {
        if (
            ! is_array($value)
            || ! array_is_list($value)
        ) {
            throw new InvalidArgumentException(
                'Expected a list of strings.'
            );
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException(
                    'Expected a list of strings.'
                );
            }
        }

        return array_values($value);
    }
}
```

## `RelationshipFactory` hoạt động như thế nào

Giả sử Sonnet trả về:

```json
{
    "id": "R001",
    "type": "count",
    "subject_path":
        "permanent_geometry.superstructure.primary_tier_count",
    "value": 4
}
```

Root DTO gọi:

```php
RelationshipFactory::fromArray(
    $relationshipData
);
```

Factory đọc:

```php
$type = RelationshipType::tryFrom(
    $data['type']
);
```

và dispatch:

```text
count
↓
CountRelationship

grouping
↓
GroupingRelationship

one_to_one
↓
OneToOneRelationship

...
```

Kết quả runtime không còn là:

```php
array
```

mà là:

```php
CountRelationship
```

Ví dụ downstream:

```php
foreach (
    $spec->relationships
    as $relationship
) {
    match ($relationship->type()) {
        RelationshipType::COUNT => ...,
        RelationshipType::PROPORTION => ...,
        RelationshipType::POSITION => ...,
        default => ...,
    };
}
```

Đó là lý do typed relationship quan trọng cho Python projection/compiler sau này.

---

# 15. Quan hệ giữa 11 primitive này

Có thể hình dung toàn bộ hệ như sau:

```text
RELATIONSHIPS
│
├── COUNT
│   └── Bao nhiêu?
│
├── GROUPING
│   └── Chia thành bao nhiêu nhóm?
│
├── ONE_TO_ONE
│   └── Mỗi A tương ứng đúng một B?
│
├── PROPORTION
│   └── Tỷ lệ hình học là bao nhiêu?
│
├── POSITION
│   └── A nằm ở đâu so với B?
│
├── ORDER
│   └── Thứ tự A → B → C?
│
├── CONTINUITY
│   └── Feature có liên tục không?
│
├── CONNECTIVITY
│   └── Các phần có nối với nhau không?
│
├── SYMMETRY
│   └── Có đối xứng theo trục nào?
│
├── CONTAINMENT
│   └── A có nằm trong B không?
│
└── ALIGNMENT
    └── Các phần có cùng trục/datum không?
```

Đây không chỉ là taxonomy để lưu JSON. Nó chính là lớp semantic trung gian giữa:

```text
Sonnet Concept Designer
        ↓
Canonical Design
        ↓
Python Constraint Normalizer
        ↓
Provider Prompt Compiler
        ↓
Vision QA
```

Ví dụ Canonical:

```json
[
    {
        "id": "R001",
        "type": "count",
        "subject_path":
            "permanent_geometry.superstructure.primary_tier_count",
        "value": 4
    },
    {
        "id": "R002",
        "type": "proportion",
        "subject_path": "dimensions",
        "metric": "length_to_beam_ratio",
        "value": 5.8
    },
    {
        "id": "R003",
        "type": "continuity",
        "subject_path":
            "form_relationships.window_ribbon",
        "value": "continuous_unbroken"
    }
]
```

Python sau này có thể compile thành:

```text
P1 COUNT
Exactly four primary superstructure tiers.

P3 PROPORTION
Maintain an overall length-to-beam proportion of approximately 5.8:1.

P2 TOPOLOGY / CONTINUITY
The window ribbon must remain one continuous uninterrupted element.
```

Đây chính là lý do architecture này tốt hơn việc Sonnet viết thẳng một prompt dài.

## Một lưu ý production quan trọng

`RelationshipFactory` vẫn giả định rằng **JSON Schema Validation đã chạy trước hydration**:

```text
raw Sonnet JSON
        ↓
CanonicalSchemaValidator
        ↓
RelationshipFactory
```

Không nên gọi trực tiếp:

```php
RelationshipFactory::fromArray(
    $untrustedRawArray
);
```

từ response provider.

Flow bắt buộc:

```text
Provider JSON
↓
schema validation
↓
DTO hydration
↓
semantic validation
```

Factory chỉ là **typed hydration layer**, không phải firewall thay thế JSON Schema.

Phần tiếp theo sẽ là **Phần 3 — Validation đầy đủ**: `ValidationError`, `ValidationResult`, `CanonicalSchemaValidator`, `CanonicalPathValidator`, `CanonicalCrossFieldValidator`, `CanonicalProvenanceValidator`, `CanonicalDesignSpecValidator`, cộng cả `CanonicalValidationException` và giải thích chính xác lỗi nào được bắt ở tầng nào.
