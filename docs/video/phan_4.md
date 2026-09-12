Dưới đây là **Phần 4 — Normalization + Hash + Freeze**, tiếp tục đúng production V1. Mục tiêu của phần này là biến một `CanonicalDesignSpec` đã valid thành một representation ổn định để hash, version và freeze.

Flow:

```text
Validated CanonicalDesignSpec
        ↓
CanonicalDesignSpecNormalizer
        ↓
Re-validation
        ↓
CanonicalDesignSpecHasher
        ↓
SHA-256
        ↓
CanonicalConceptFreezer
        ↓
FrozenCanonicalConcept
```

## 1. `Normalize/CanonicalDesignSpecNormalizer.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Normalize;

use App\Video\Concept\Canonical\CanonicalDesignSpec;

final class CanonicalDesignSpecNormalizer
{
    public function normalize(
        CanonicalDesignSpec $spec
    ): CanonicalDesignSpec {
        $data = $spec->toArray();

        /*
         * relationships là semantic set ở root.
         * Thứ tự xuất hiện không mang meaning.
         *
         * Do đó sort theo stable id để:
         *
         * cùng một semantic spec
         * luôn tạo cùng canonical representation.
         *
         * QUAN TRỌNG:
         * chỉ sort danh sách relationship ở root.
         * KHÔNG sort OrderRelationship.items.
         */
        usort(
            $data['relationships'],
            static fn (
                array $left,
                array $right
            ): int =>
                strcmp(
                    (string) $left['id'],
                    (string) $right['id']
                )
        );

        /*
         * exclusions cũng là root-level set.
         */
        usort(
            $data['exclusions'],
            static fn (
                array $left,
                array $right
            ): int =>
                strcmp(
                    (string) $left['id'],
                    (string) $right['id']
                )
        );

        /*
         * invariants cũng dùng stable id.
         */
        usort(
            $data['invariants'],
            static fn (
                array $left,
                array $right
            ): int =>
                strcmp(
                    (string) $left['id'],
                    (string) $right['id']
                )
        );

        /*
         * provenance không có id.
         *
         * V1 policy:
         * mỗi target_path chỉ có một provenance entry,
         * nên target_path là stable sort key.
         */
        usort(
            $data['provenance'],
            static fn (
                array $left,
                array $right
            ): int =>
                strcmp(
                    (string) $left['target_path'],
                    (string) $right['target_path']
                )
        );

        /*
         * identity_basis được schema khai báo
         * uniqueItems=true.
         *
         * Không sort list này ở V1.
         *
         * Lý do:
         * dù hiện tại identity_basis được xem
         * gần giống semantic set, schema không nói
         * order hoàn toàn vô nghĩa.
         *
         * Giữ order đầu vào an toàn hơn.
         */
        $data['identity']['identity_basis'] =
            array_values(
                $data['identity']['identity_basis']
            );

        /*
         * source_aspects theo semantic policy là set.
         *
         * Thứ tự:
         * [
         *   "size_and_dimensions",
         *   "spatial_layout"
         * ]
         *
         * không mang meaning.
         *
         * Vì vậy sort để hash deterministic.
         */
        foreach (
            $data['provenance']
            as &$entry
        ) {
            $sourceAspects =
                $entry['source_aspects'];

            sort(
                $sourceAspects,
                SORT_STRING
            );

            $entry['source_aspects'] =
                array_values(
                    $sourceAspects
                );
        }

        unset($entry);

        /*
         * Không normalize:
         *
         * dimensions
         * permanent_geometry
         * form_relationships
         * finished_materials
         *
         * bằng domain logic ở đây.
         *
         * Core Normalizer không được:
         *
         * - tính ratio
         * - sửa dimension
         * - đổi geometry
         * - thêm missing field
         * - suy diễn semantic
         *
         * Vì đó sẽ trở thành semantic repair.
         */

        return CanonicalDesignSpec::fromArray(
            $data
        );
    }
}
```

### Normalizer này thực sự làm gì?

Nó chỉ làm một việc:

> đưa các phần **không mang ý nghĩa thứ tự** về một thứ tự deterministic.

Ví dụ Sonnet trả:

```json
"relationships": [
    {
        "id": "R005",
        "type": "position"
    },
    {
        "id": "R001",
        "type": "count"
    },
    {
        "id": "R003",
        "type": "continuity"
    }
]
```

Về mặt semantic, nó giống:

```json
"relationships": [
    {
        "id": "R001",
        "type": "count"
    },
    {
        "id": "R003",
        "type": "continuity"
    },
    {
        "id": "R005",
        "type": "position"
    }
]
```

Nếu không normalize, hai JSON có thể tạo hai SHA-256 khác nhau dù design giống nhau.

Sau normalize:

```text
R001
R003
R005
```

luôn ổn định.

---

## Điều gì tuyệt đối không được sort?

Ví dụ:

```json
{
    "id": "R008",
    "type": "order",
    "items": [
        "tier_1",
        "tier_2",
        "tier_3",
        "tier_4"
    ],
    "direction": "bottom_to_top"
}
```

Không được:

```php
sort($relationship['items']);
```

vì `items` là **semantic order**.

Nếu một design có:

```text
A → C → B
```

thì sort alphabetically thành:

```text
A → B → C
```

là đổi design.

Đó là lý do normalizer chỉ sort:

```text
root relationships
root exclusions
root invariants
root provenance
source_aspects
```

nhưng không sort các ordered substructures.

---

# 2. Tại sao không trim hoặc rewrite toàn bộ string?

Có vẻ hấp dẫn để làm:

```php
trim($value);
strtolower($value);
preg_replace('/\s+/', ' ', $value);
```

toàn bộ tree.

Không nên.

Ví dụ:

```text
"near-plumb"
```

và:

```text
"near plumb"
```

có thể được domain/compiler xử lý khác.

Hoặc prose:

```text
A / B
```

với:

```text
A/B
```

không nhất thiết muốn canonicalizer tự quyết định chúng giống nhau.

Production principle:

```text
Normalizer
= deterministic representation normalization

Normalizer
≠ semantic canonicalization bằng NLP
```

---

# 3. `Hashing/CanonicalDesignSpecHasher.php`

Sau normalize, ta cần biến spec thành canonical JSON ổn định và SHA-256.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Hashing;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use JsonException;

final class CanonicalDesignSpecHasher
{
    public function hash(
        CanonicalDesignSpec $spec
    ): string {
        return hash(
            'sha256',
            $this->canonicalJson($spec)
        );
    }

    /**
     * @throws JsonException
     */
    public function canonicalJson(
        CanonicalDesignSpec $spec
    ): string {
        $canonicalData =
            $this->sortObjectKeysRecursively(
                $spec->toArray()
            );

        return json_encode(
            $canonicalData,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR
        );
    }

    private function sortObjectKeysRecursively(
        mixed $value
    ): mixed {
        if (!is_array($value)) {
            return $value;
        }

        /*
         * JSON list:
         *
         * preserve order.
         *
         * Ví dụ:
         * order relationship items
         * bắt buộc giữ đúng thứ tự.
         */
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $child): mixed =>
                    $this->sortObjectKeysRecursively(
                        $child
                    ),
                $value
            );
        }

        /*
         * JSON object:
         *
         * property order không có semantic meaning.
         * Sort key alphabetically.
         */
        ksort(
            $value,
            SORT_STRING
        );

        foreach (
            $value as $key => $child
        ) {
            $value[$key] =
                $this->sortObjectKeysRecursively(
                    $child
                );
        }

        return $value;
    }
}
```

## Có hai cấp normalization khác nhau

Đây là điểm rất dễ nhầm.

### Semantic collection normalization

Được làm ở:

```text
CanonicalDesignSpecNormalizer
```

Ví dụ:

```text
relationships sorted by id
provenance sorted by target_path
```

Vì đây là các **list**, và ta phải chủ động xác định list nào semantic là set.

### JSON object key canonicalization

Được làm ở:

```text
CanonicalDesignSpecHasher
```

Ví dụ:

```json
{
    "length_m": 90,
    "beam_m": 15
}
```

và:

```json
{
    "beam_m": 15,
    "length_m": 90
}
```

là cùng object.

Hasher đổi cả hai thành một ordering ổn định:

```json
{
    "beam_m": 15,
    "length_m": 90
}
```

trước khi hash.

---

# 4. Tại sao phải phân biệt JSON object và list?

Hàm:

```php
array_is_list($value)
```

được dùng để biết:

```php
[
    0 => 'A',
    1 => 'B'
]
```

là list.

List phải giữ thứ tự:

```text
A
B
```

Không được biến thành:

```text
B
A
```

Nhưng associative object:

```php
[
    'z' => 1,
    'a' => 2
]
```

thì property order không quan trọng.

Ta sort thành:

```php
[
    'a' => 2,
    'z' => 1
]
```

để hash ổn định.

---

# 5. Tại sao dùng `JSON_PRESERVE_ZERO_FRACTION`?

Ví dụ:

```php
5.0
```

nếu encode bình thường có thể thành:

```json
5
```

Trong một số trường hợp ta muốn giữ numeric representation:

```json
5.0
```

nên dùng:

```php
JSON_PRESERVE_ZERO_FRACTION
```

Điều này tốt cho canonical serialization.

---

# 6. Tại sao dùng `JSON_UNESCAPED_UNICODE`?

Nếu canonical có:

```text
"đường cong liên tục"
```

không muốn JSON thành:

```text
"\u0111\u01b0\u1eddng..."
```

Ta giữ UTF-8 tự nhiên.

Nó không thay đổi semantic, nhưng canonical JSON dễ debug hơn.

---

# 7. Tại sao SHA-256?

Ta cần identifier đủ mạnh cho frozen semantic state.

Ví dụ:

```text
canonical_hash =
21f32af...
```

Sau này:

```text
RenderPlan
↓
anchor generation
↓
reference generation
↓
scene generation
```

đều có thể lưu:

```text
canonical_spec_hash
```

Khi một artifact được tạo từ:

```text
hash ABC
```

nhưng current project đang ở:

```text
hash XYZ
```

ta biết artifact đó thuộc revision cũ.

Đây là nền cho lineage.

---

# 8. Ví dụ deterministic hash

Spec A:

```json
{
    "dimensions": {
        "length_m": 100,
        "beam_m": 20
    }
}
```

Spec B:

```json
{
    "dimensions": {
        "beam_m": 20,
        "length_m": 100
    }
}
```

Raw JSON:

```text
khác nhau
```

Nhưng sau recursive key sort:

```json
{
    "dimensions": {
        "beam_m": 20,
        "length_m": 100
    }
}
```

cả hai giống nhau.

→ SHA-256 giống nhau.

---

# 9. `FrozenCanonicalConcept.php`

Đây là immutable value object đại diện cho canonical revision đã freeze.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use DateTimeImmutable;

final class FrozenCanonicalConcept
{
    public function __construct(
        public readonly CanonicalDesignSpec $spec,
        public readonly int $revision,
        public readonly string $hash,
        public readonly DateTimeImmutable $frozenAt,
    ) {
    }

    /**
     * @return array{
     *     revision:int,
     *     hash:string,
     *     frozen_at:string,
     *     spec:array<string,mixed>
     * }
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

            'spec' =>
                $this->spec->toArray(),
        ];
    }
}
```

## `FrozenCanonicalConcept` khác `CanonicalDesignSpec` ở đâu?

`CanonicalDesignSpec` là:

```text
design state
```

Ví dụ:

```text
90m vessel
4 tiers
certain geometry
```

`FrozenCanonicalConcept` là:

```text
design state
+
revision identity
+
hash
+
freeze timestamp
```

Ví dụ:

```json
{
    "revision": 3,
    "hash": "a52b...",
    "frozen_at": "2026-08-30T08:12:00+07:00",
    "spec": {
        ...
    }
}
```

---

# 10. `CanonicalConceptFreezer.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Hashing\CanonicalDesignSpecHasher;
use DateTimeImmutable;
use InvalidArgumentException;

final class CanonicalConceptFreezer
{
    public function __construct(
        private readonly CanonicalDesignSpecHasher $hasher,
    ) {
    }

    public function freeze(
        CanonicalDesignSpec $spec,
        int $revision
    ): FrozenCanonicalConcept {
        if ($revision < 1) {
            throw new InvalidArgumentException(
                'revision must be >= 1.'
            );
        }

        return new FrozenCanonicalConcept(
            spec:
                $spec,

            revision:
                $revision,

            hash:
                $this->hasher
                    ->hash($spec),

            frozenAt:
                new DateTimeImmutable(),
        );
    }
}
```

### Flow bên trong rất đơn giản

```text
spec
revision
↓
hash(spec)
↓
timestamp
↓
FrozenCanonicalConcept
```

Freezer **không validate**.

Đây là chủ ý.

Không được gọi:

```text
raw Sonnet JSON
↓
Freezer
```

Flow đúng phải là:

```text
Sonnet
↓
schema validation
↓
DTO
↓
semantic validation
↓
normalization
↓
re-validation
↓
freeze
```

Freezer giả định input đã được processor chứng minh là valid.

---

# 11. Một cải tiến production tôi khuyên dùng: Clock injection

Đoạn:

```php
new DateTimeImmutable()
```

chạy được.

Nhưng test deterministic sẽ khó hơn.

Production tốt hơn có thể dùng `Clock`.

Ví dụ tạo interface:

### `Support/Clock.php`

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

### `Support/SystemClock.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Support;

use DateTimeImmutable;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
```

Sau đó Freezer:

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Hashing\CanonicalDesignSpecHasher;
use App\Video\Concept\Support\Clock;
use InvalidArgumentException;

final class CanonicalConceptFreezer
{
    public function __construct(
        private readonly CanonicalDesignSpecHasher $hasher,
        private readonly Clock $clock,
    ) {
    }

    public function freeze(
        CanonicalDesignSpec $spec,
        int $revision
    ): FrozenCanonicalConcept {
        if ($revision < 1) {
            throw new InvalidArgumentException(
                'revision must be >= 1.'
            );
        }

        return new FrozenCanonicalConcept(
            spec: $spec,
            revision: $revision,
            hash: $this->hasher->hash($spec),
            frozenAt: $this->clock->now(),
        );
    }
}
```

Tôi khuyên bản production thật dùng version này.

Lúc test có thể inject:

```php
final class FixedClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            '2026-08-30T08:00:00+07:00'
        );
    }
}
```

Test luôn deterministic.

---

# 12. Có nên hash cả revision và timestamp không?

**Không.**

Hash phải đại diện cho:

```text
canonical semantic content
```

nên chỉ:

```php
hash(
    canonicalJson($spec)
)
```

Không hash:

```text
revision
timestamp
database id
session id
model
token usage
```

Ví dụ revision 2 và revision 3 có content giống hoàn toàn:

```text
hash giống nhau
```

Điều này rất có giá trị.

Ta có thể phát hiện:

```text
revision tăng
nhưng semantic content không đổi
```

---

# 13. Revision và hash khác nhau thế nào?

Ví dụ:

```text
Revision 1
hash AAA

Revision 2
hash BBB

Revision 3
hash BBB
```

Điều đó nghĩa:

```text
R1 → R2
semantic thay đổi

R2 → R3
revision record mới
nhưng canonical semantic content giống nhau
```

`revision` là lifecycle identity.

`hash` là content identity.

Đừng dùng chúng thay nhau.

---

# 14. Re-validation phải xảy ra trước Freeze

Flow production không được:

```text
validate
↓
normalize
↓
freeze
```

mà phải:

```text
validate
↓
normalize
↓
validate lại
↓
freeze
```

Tại sao?

Normalizer về lý thuyết phải semantics-preserving.

Nhưng production không nên chỉ “tin” điều đó.

Ta chứng minh bằng deterministic validator:

```text
Normalized spec
↓
Schema Validation
↓
Semantic Validation
```

Nếu normalizer có bug:

```text
sort nhầm order items
delete field
change value
```

re-validation có cơ hội bắt.

---

# 15. `CanonicalConceptProcessor.php`

Phần này nằm giữa Validation và Freeze. Đây là file ghép Phase 3 + Phase 4.

Tôi gửi luôn bản production để bạn thấy toàn bộ flow.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Exceptions\CanonicalValidationException;
use App\Video\Concept\Normalize\CanonicalDesignSpecNormalizer;
use App\Video\Concept\Validation\CanonicalDesignSpecValidator;
use App\Video\Concept\Validation\CanonicalSchemaValidator;
use App\Video\Inspiration\InspirationBrief;
use JsonException;
use Throwable;

final class CanonicalConceptProcessor
{
    public function __construct(
        private readonly CanonicalSchemaValidator $schemaValidator,
        private readonly CanonicalDesignSpecValidator $semanticValidator,
        private readonly CanonicalDesignSpecNormalizer $normalizer,
    ) {
    }

    /**
     * @throws CanonicalValidationException
     * @throws JsonException
     */
    public function process(
        string $rawJson,
        InspirationBrief $brief
    ): CanonicalDesignSpec {
        /*
         * STEP 1
         *
         * Validate raw provider JSON trước.
         *
         * Giữ chính xác {} vs [].
         */
        $this->schemaValidator
            ->validateJsonOrFail(
                $rawJson
            );

        /*
         * STEP 2
         *
         * Sau schema pass mới decode
         * associative để hydrate DTO.
         */
        try {
            $data = json_decode(
                $rawJson,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (
                !is_array($data)
                || array_is_list($data)
            ) {
                throw new JsonException(
                    'Canonical root must decode '
                    . 'to an object.'
                );
            }

            $spec =
                CanonicalDesignSpec::fromArray(
                    $data
                );
        } catch (Throwable $e) {
            throw new CanonicalValidationException(
                errors: [],
                message:
                    'Canonical DTO hydration failed: '
                    . $e->getMessage(),
            );
        }

        /*
         * STEP 3
         *
         * Semantic validation.
         */
        $semantic =
            $this->semanticValidator
                ->validate(
                    $spec,
                    $brief
                );

        if ($semantic->fails()) {
            throw new CanonicalValidationException(
                errors:
                    $semantic->errors,

                message:
                    'Canonical semantic '
                    . 'validation failed.',
            );
        }

        /*
         * STEP 4
         *
         * Deterministic normalization.
         */
        $normalized =
            $this->normalizer
                ->normalize($spec);

        /*
         * STEP 5
         *
         * Re-serialize normalized DTO.
         */
        $normalizedJson =
            json_encode(
                $normalized->toArray(),
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
            );

        /*
         * STEP 6
         *
         * Full schema validation lại
         * sau normalization.
         */
        $this->schemaValidator
            ->validateJsonOrFail(
                $normalizedJson
            );

        /*
         * STEP 7
         *
         * Semantic validation lại.
         */
        $postNormalization =
            $this->semanticValidator
                ->validate(
                    $normalized,
                    $brief
                );

        if (
            $postNormalization->fails()
        ) {
            throw new CanonicalValidationException(
                errors:
                    $postNormalization->errors,

                message:
                    'Canonical design became '
                    . 'invalid after normalization.',
            );
        }

        return $normalized;
    }
}
```

## Processor này là boundary rất quan trọng

Nó bảo đảm rằng nếu bên ngoài nhận được:

```php
CanonicalDesignSpec
```

từ:

```php
$processor->process(...)
```

thì object đó đã:

```text
✓ schema valid
✓ DTO hydrated
✓ semantic valid
✓ normalized
✓ schema valid again
✓ semantic valid again
```

Sau đó mới được freeze.

---

# 16. Có một vấn đề với `{}` sau normalization cần hiểu kỹ

Ta đã nói raw JSON:

```json
"finished_materials": {}
```

sau:

```php
json_decode($rawJson, true)
```

trở thành:

```php
[]
```

Khi:

```php
json_encode([])
```

nó thành:

```json
[]
```

không còn `{}`.

Do đó nếu re-validation bằng:

```php
json_encode(
    $normalized->toArray()
)
```

thì domain-open empty object có thể bị đổi type.

Vì vậy bản hardened production nên có một serializer biết các root domain-open fields phải encode thành object.

Tôi khuyên thêm file này.

# 17. `Canonical/CanonicalDesignSpecSerializer.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical;

use JsonException;
use stdClass;

final class CanonicalDesignSpecSerializer
{
    /**
     * @throws JsonException
     */
    public function toJson(
        CanonicalDesignSpec $spec
    ): string {
        $data = $spec->toArray();

        /*
         * Core V1 contract nói bốn field này
         * là JSON object.
         *
         * Empty PHP [] phải serialize thành {}.
         */
        $data['dimensions'] =
            $this->forceObject(
                $data['dimensions']
            );

        $data['permanent_geometry'] =
            $this->forceObject(
                $data['permanent_geometry']
            );

        $data['form_relationships'] =
            $this->forceObject(
                $data['form_relationships']
            );

        $data['finished_materials'] =
            $this->forceObject(
                $data['finished_materials']
            );

        return json_encode(
            $data,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR
        );
    }

    /**
     * @param array<string,mixed> $value
     */
    private function forceObject(
        array $value
    ): array|stdClass {
        if ($value === []) {
            return new stdClass();
        }

        return $value;
    }
}
```

Đây là một điểm tôi muốn chốt lại cho production:

```text
Raw JSON validation
giải quyết {} vs []

nhưng
Normalized reserialization
cũng phải giữ {} vs []
```

Nếu không, pipeline có thể pass lần đầu nhưng fail sau normalization chỉ vì representation PHP.

---

# 18. Processor bản tốt hơn dùng Serializer

Thay:

```php
$normalizedJson =
    json_encode(
        $normalized->toArray(),
        ...
    );
```

bằng:

```php
$normalizedJson =
    $this->serializer
        ->toJson($normalized);
```

Constructor:

```php
public function __construct(
    private readonly CanonicalSchemaValidator $schemaValidator,
    private readonly CanonicalDesignSpecValidator $semanticValidator,
    private readonly CanonicalDesignSpecNormalizer $normalizer,
    private readonly CanonicalDesignSpecSerializer $serializer,
) {
}
```

Và phần revalidate:

```php
$normalizedJson =
    $this->serializer
        ->toJson(
            $normalized
        );

$this->schemaValidator
    ->validateJsonOrFail(
        $normalizedJson
    );
```

Tôi khuyên dùng bản này trong project thực tế.

---

# 19. Hasher cũng nên dùng serializer canonical riêng?

Có.

Với bốn empty object, nếu Hasher trực tiếp:

```php
$spec->toArray()
```

thì có thể hash:

```json
"finished_materials":[]
```

trong khi contract nói:

```json
"finished_materials":{}
```

Tốt hơn cho hasher dùng canonical representation có type đúng.

Bản production mạnh hơn:

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Hashing;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use JsonException;
use stdClass;

final class CanonicalDesignSpecHasher
{
    public function hash(
        CanonicalDesignSpec $spec
    ): string {
        return hash(
            'sha256',
            $this->canonicalJson($spec)
        );
    }

    /**
     * @throws JsonException
     */
    public function canonicalJson(
        CanonicalDesignSpec $spec
    ): string {
        $data = $spec->toArray();

        foreach (
            [
                'dimensions',
                'permanent_geometry',
                'form_relationships',
                'finished_materials',
            ]
            as $objectField
        ) {
            if (
                $data[$objectField] === []
            ) {
                $data[$objectField] =
                    new stdClass();
            }
        }

        $canonical =
            $this->sortObjectKeysRecursively(
                $data
            );

        return json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR
        );
    }

    private function sortObjectKeysRecursively(
        mixed $value
    ): mixed {
        if ($value instanceof stdClass) {
            return $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $child): mixed =>
                    $this->sortObjectKeysRecursively(
                        $child
                    ),
                $value
            );
        }

        ksort(
            $value,
            SORT_STRING
        );

        foreach (
            $value as $key => $child
        ) {
            $value[$key] =
                $this->sortObjectKeysRecursively(
                    $child
                );
        }

        return $value;
    }
}
```

Như vậy hash thực sự dựa trên **canonical V1 JSON**, không phải representation ngẫu nhiên của PHP.

---

# 20. Bản final của Phase 4 tôi khuyên dùng

Cây nên thành:

```text
Concept/
├── Canonical/
│   ├── CanonicalDesignSpec.php
│   └── CanonicalDesignSpecSerializer.php
│
├── Normalize/
│   └── CanonicalDesignSpecNormalizer.php
│
├── Hashing/
│   └── CanonicalDesignSpecHasher.php
│
├── Support/
│   ├── Clock.php
│   └── SystemClock.php
│
├── CanonicalConceptProcessor.php
├── FrozenCanonicalConcept.php
└── CanonicalConceptFreezer.php
```

Nếu muốn production ổn định hơn nữa, tôi chọn version có:

```text
Serializer
+
Clock abstraction
```

chứ không chọn bản tối giản.

---

# 21. Toàn bộ lifecycle sau Phase 4

Bây giờ hệ thống đã đi được đến:

```text
Article
↓
Haiku Extraction
↓
Evidence Verification
↓
InspirationBrief
↓
ConceptInput
↓
Sonnet
↓
RAW canonical JSON
↓
Schema Validation
↓
DTO Hydration
↓
Cross-field Validation
↓
Provenance Validation
↓
Normalization
↓
Canonical serialization
↓
Re-validation
↓
SHA-256
↓
Revision
↓
Freeze
```

Và dữ liệu frozen có thể giống:

```json
{
    "revision": 1,
    "hash": "8a20eb7551c7...",
    "frozen_at": "2026-08-30T08:25:31+07:00",
    "spec": {
        "schema_version": "1.0",
        "object_type": "marine_vessel",
        "...": "..."
    }
}
```

Từ thời điểm freeze:

```text
spec hash = immutable truth
```

Python Asset Projection không được sửa canonical spec.

Nếu Python cần:

```text
camera
prompt
provider syntax
render parameters
reference selection
```

thì tạo derived artifact mới:

```text
CanonicalDesignSpec
↓
AssetProjection
↓
ConstraintSet
↓
PromptSpec
```

không mutate canonical.

Phần tiếp theo hợp lý là **Phần 5 — Claude Sonnet Structured Output đầy đủ**, gồm `CanonicalJsonPayload`, `CanonicalSchemaProvider`, `ClaudeSchemaAdapter`, `AnthropicStructuredOutputClient`, `AnthropicStructuredOutputResponse`, `ClaudeConceptDesigner`, `ClaudeConceptRepairer` và quan trọng nhất là cách đảm bảo **repair chỉ đúng 1 lần nhưng lỗi schema từ lần generate đầu tiên vẫn được truyền chính xác cho Sonnet Repairer**.
