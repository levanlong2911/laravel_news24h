Được. Tôi sẽ làm **Phần 8** bám sát các contract và signature đã hình thành ở Phần 1–7, không “đẻ” thêm một architecture khác.

Có ba nguyên tắc tôi giữ cố định:

```text
1. Canonical Core V1 không đổi.
2. Category validator không sửa DTO, chỉ trả ValidationResult.
3. Profile resolution và semantic validation đều deterministic;
   Sonnet chỉ repair sau khi deterministic validator báo lỗi.
```

Flow sau Phần 8 sẽ là:

```text
ConceptInput
    │
    ├── objectType
    ├── InspirationBrief
    └── CategoryCreativeProfile
            ↓
Claude Sonnet 5
            ↓
Canonical JSON
            ↓
Core Schema Validation
            ↓
Effective Profile Schema Validation
            ↓
CanonicalDesignSpec DTO
            ↓
CanonicalDesignSpecValidator
    ├── CanonicalCrossFieldValidator
    ├── CanonicalProvenanceValidator
    └── CategorySemanticValidatorRegistry
            ↓
      validator của profile
            ↓
Normalize
            ↓
Core + Effective + Semantic re-validation
            ↓
Hash
            ↓
Freeze
```

---

# Phần 8 — Category Semantic Validation

## 8.1 Cấu trúc file

Tiếp tục trực tiếp trên cây code trước:

```text
app/Video/
├── Concept/
│   ├── Canonical/
│   ├── Validation/
│   │   ├── ValidationError.php
│   │   ├── ValidationResult.php
│   │   ├── CanonicalSchemaValidator.php
│   │   ├── EffectiveSchemaValidator.php
│   │   ├── CanonicalPathValidator.php
│   │   ├── CanonicalCrossFieldValidator.php
│   │   ├── CanonicalProvenanceValidator.php
│   │   └── CanonicalDesignSpecValidator.php
│   │
│   └── ...
│
└── Profiles/
    ├── CategoryCreativeProfile.php
    ├── CategoryCreativeProfileRegistry.php
    ├── CategoryCreativeProfileResolver.php
    ├── CategoryProfileSchemaProvider.php
    │
    └── Validation/
        ├── CategorySemanticValidator.php
        ├── CategorySemanticValidatorRegistry.php
        ├── GenericPhysicalObjectSemanticValidator.php
        ├── MarineVesselSemanticValidator.php
        ├── AircraftSemanticValidator.php
        └── ArchitectureSemanticValidator.php
```

Tôi **không thêm `CompositeCategorySemanticValidator`** ở bản này vì sau khi rà lại architecture Phần 7, nó chưa cần thiết.

Registry đã chính là dispatcher/composition layer.

Nếu sau này một profile cần nhiều validator:

```text
marine_vessel
├── DimensionsValidator
├── HullValidator
└── SuperstructureValidator
```

thì lúc đó mới tạo composite nội bộ cho marine.

Không cần abstraction sớm.

---

# 8.2 Trước hết chốt semantics của `object_type` và `profile`

Ta đã có:

```php
ConceptInput(
    string $objectType,
    InspirationBrief $inspiration,
    CategoryCreativeProfile $profile,
    array $projectRequirements,
)
```

Giữ nguyên.

Ví dụ:

```text
object_type = superyacht
profile     = marine_vessel
```

hoặc:

```text
object_type = business_jet
profile     = aircraft
```

Do đó:

```text
object_type ≠ profile key
```

và đây **không phải lỗi**.

Profile resolver chịu trách nhiệm:

```text
superyacht
     ↓
marine_vessel
```

Semantic validator sau đó phải kiểm tra rằng:

```text
object_type thực sự tương thích với profile đã chọn
```

nhưng không bằng cách bắt hai string phải giống nhau.

---

# 8.3 `CategorySemanticValidator.php`

Interface rất nhỏ.

```php
<?php

declare(strict_types=1);

namespace App\Video\Profiles\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Validation\ValidationResult;

interface CategorySemanticValidator
{
    /**
     * Profile mà validator này chịu trách nhiệm.
     *
     * Ví dụ:
     *
     * marine_vessel
     * aircraft
     * architecture
     */
    public function profileKey(): string;

    /**
     * Validate semantic/domain consistency.
     *
     * IMPORTANT:
     *
     * - Không mutate $spec.
     * - Không repair.
     * - Không gọi LLM.
     * - Không query DB.
     * - Không normalize.
     *
     * Chỉ trả deterministic ValidationResult.
     */
    public function validate(
        CanonicalDesignSpec $spec,
        ConceptInput $input,
    ): ValidationResult;
}
```

### Vì sao truyền cả `ConceptInput`?

Nếu chỉ truyền:

```php
CanonicalDesignSpec $spec
```

validator không biết:

```text
object_type
profile
project requirements
inspiration
```

Nhưng nếu truyền toàn bộ `ConceptInput`, category validator có đủ context mà không cần query ra ngoài.

Quan trọng hơn, `ConceptInput` đã là contract có từ Phần 6–7.

Không cần tạo thêm DTO mới.

---

# 8.4 `CategorySemanticValidatorRegistry.php`

Registry không có fallback im lặng sai category.

Fallback chỉ được dùng khi profile thực sự là:

```text
generic_physical_object
```

Không được:

```text
marine_vessel validator không tồn tại
→ tự dùng generic
```

vì như vậy lỗi configuration bị che mất.

```php
<?php

declare(strict_types=1);

namespace App\Video\Profiles\Validation;

use App\Video\Profiles\CategoryCreativeProfile;
use RuntimeException;

final class CategorySemanticValidatorRegistry
{
    /**
     * @var array<string,CategorySemanticValidator>
     */
    private array $validators = [];

    /**
     * @param list<CategorySemanticValidator> $validators
     */
    public function __construct(
        array $validators
    ) {
        foreach ($validators as $validator) {
            $this->register($validator);
        }
    }

    public function register(
        CategorySemanticValidator $validator
    ): void {
        $key =
            trim(
                $validator->profileKey()
            );

        if ($key === '') {
            throw new RuntimeException(
                'Category semantic validator '
                . 'profile key must not be empty.'
            );
        }

        if (isset($this->validators[$key])) {
            throw new RuntimeException(
                'Duplicate category semantic '
                . 'validator for profile: '
                . $key
            );
        }

        $this->validators[$key] =
            $validator;
    }

    public function forProfile(
        CategoryCreativeProfile $profile
    ): CategorySemanticValidator {
        $validator =
            $this->validators[
                $profile->key
            ]
            ?? null;

        if ($validator === null) {
            throw new RuntimeException(
                sprintf(
                    'No semantic validator registered '
                    . 'for category profile %s@%s.',
                    $profile->key,
                    $profile->version,
                )
            );
        }

        return $validator;
    }

    public function has(
        string $profileKey
    ): bool {
        return isset(
            $this->validators[
                trim($profileKey)
            ]
        );
    }
}
```

---

# 8.5 Object-type resolver

Ở Phần 7 tôi đưa `CategoryCreativeProfileResolver`.

Ta giữ class đó, nhưng production nên thêm một lớp mapping rõ ràng hơn thay vì nhét object types trực tiếp vào registry.

Tên tôi chốt là:

```text
CategoryCreativeProfileResolver
```

không cần tạo thêm `ObjectTypeProfileResolver` thứ hai.

Đây là điểm tôi chỉnh để **không duplicate abstraction**.

---

# 8.6 `CategoryCreativeProfileResolver.php`

Bản chặt hơn:

```php
<?php

declare(strict_types=1);

namespace App\Video\Profiles;

use InvalidArgumentException;

final class CategoryCreativeProfileResolver
{
    /**
     * @param array<string,string> $objectTypeMap
     */
    public function __construct(
        private readonly CategoryCreativeProfileRegistry $registry,
        private readonly array $objectTypeMap,
        private readonly string $fallbackProfileKey =
            'generic_physical_object',
    ) {
        $this->guardConfiguration();
    }

    public function resolve(
        string $objectType
    ): CategoryCreativeProfile {
        $objectType =
            $this->normalizeObjectType(
                $objectType
            );

        /*
         * Explicit mapping ưu tiên cao nhất.
         */
        $mappedProfile =
            $this->objectTypeMap[
                $objectType
            ]
            ?? null;

        if ($mappedProfile !== null) {
            return $this->registry
                ->get(
                    $mappedProfile
                );
        }

        /*
         * Nếu object_type tự nó đã là một
         * broad profile key, cho phép dùng trực tiếp.
         *
         * Ví dụ:
         *
         * marine_vessel -> marine_vessel
         * aircraft      -> aircraft
         */
        if (
            $this->registry
                ->has($objectType)
        ) {
            return $this->registry
                ->get($objectType);
        }

        /*
         * Unknown object type:
         * dùng generic profile.
         */
        return $this->registry
            ->get(
                $this->fallbackProfileKey
            );
    }

    public function isCompatible(
        string $objectType,
        CategoryCreativeProfile $profile
    ): bool {
        $resolved =
            $this->resolve(
                $objectType
            );

        return $resolved->key
            === $profile->key;
    }

    private function normalizeObjectType(
        string $objectType
    ): string {
        $value =
            strtolower(
                trim($objectType)
            );

        $value =
            preg_replace(
                '/[^a-z0-9]+/',
                '_',
                $value
            );

        $value =
            trim(
                (string) $value,
                '_'
            );

        if ($value === '') {
            throw new InvalidArgumentException(
                'objectType must not be empty.'
            );
        }

        return $value;
    }

    private function guardConfiguration(): void
    {
        if (
            !$this->registry
                ->has(
                    $this->fallbackProfileKey
                )
        ) {
            throw new InvalidArgumentException(
                'Fallback category profile '
                . 'does not exist: '
                . $this->fallbackProfileKey
            );
        }

        foreach (
            $this->objectTypeMap
            as $objectType => $profileKey
        ) {
            if (
                !is_string($objectType)
                || trim($objectType) === ''
                || !is_string($profileKey)
                || trim($profileKey) === ''
            ) {
                throw new InvalidArgumentException(
                    'Invalid object_type_map entry.'
                );
            }

            if (
                !$this->registry
                    ->has($profileKey)
            ) {
                throw new InvalidArgumentException(
                    sprintf(
                        'object_type_map references '
                        . 'unknown profile "%s".',
                        $profileKey
                    )
                );
            }
        }
    }
}
```

---

# 8.7 Config mapping

Ta **không tạo config mới**.

Tiếp tục dùng file:

```text
config/category_creative_profiles.php
```

nhưng mở rộng nó thành:

```php
<?php

declare(strict_types=1);

return [

    'fallback_profile' =>
        'generic_physical_object',

    'object_type_map' => [

        /*
         * Marine
         */
        'superyacht' =>
            'marine_vessel',

        'motor_yacht' =>
            'marine_vessel',

        'sailing_yacht' =>
            'marine_vessel',

        'cargo_ship' =>
            'marine_vessel',

        'container_ship' =>
            'marine_vessel',

        'ferry' =>
            'marine_vessel',

        'passenger_ship' =>
            'marine_vessel',

        /*
         * Aircraft
         */
        'business_jet' =>
            'aircraft',

        'passenger_aircraft' =>
            'aircraft',

        'cargo_aircraft' =>
            'aircraft',

        'private_jet' =>
            'aircraft',

        /*
         * Architecture
         */
        'villa' =>
            'architecture',

        'house' =>
            'architecture',

        'office_tower' =>
            'architecture',

        'hotel_building' =>
            'architecture',

        /*
         * Road vehicle
         */
        'sedan' =>
            'road_vehicle',

        'suv' =>
            'road_vehicle',

        'truck' =>
            'road_vehicle',

        'bus' =>
            'road_vehicle',
    ],

    'profiles' => [

        'generic_physical_object' => [
            'version' => '1.0',

            'schema_path' =>
                resource_path(
                    'ai/schemas/profiles/'
                    . 'generic_physical_object_v1.json'
                ),

            'inspection_aspects' => [
                'size_and_dimensions',
                'form_and_proportions',
                'primary_massing',
                'permanent_geometry',
                'openings',
                'spatial_layout',
                'materials',
            ],
        ],

        'marine_vessel' => [
            'version' => '1.0',

            'schema_path' =>
                resource_path(
                    'ai/schemas/profiles/'
                    . 'marine_vessel_v1.json'
                ),

            'inspection_aspects' => [
                'size_and_dimensions',
                'form_and_proportions',
                'primary_massing',
                'bow_geometry',
                'stern_geometry',
                'hull_geometry',
                'superstructure',
                'openings',
                'spatial_layout',
                'materials',
            ],
        ],

        'aircraft' => [
            'version' => '1.0',

            'schema_path' =>
                resource_path(
                    'ai/schemas/profiles/'
                    . 'aircraft_v1.json'
                ),

            'inspection_aspects' => [
                'size_and_dimensions',
                'fuselage_geometry',
                'wing_geometry',
                'engine_configuration',
                'tail_geometry',
                'landing_configuration',
                'openings',
                'materials',
            ],
        ],

        'architecture' => [
            'version' => '1.0',

            'schema_path' =>
                resource_path(
                    'ai/schemas/profiles/'
                    . 'architecture_v1.json'
                ),

            'inspection_aspects' => [
                'size_and_dimensions',
                'primary_massing',
                'floor_organization',
                'facade_geometry',
                'opening_pattern',
                'circulation',
                'structural_expression',
                'materials',
            ],
        ],

        'road_vehicle' => [
            'version' => '1.0',

            'schema_path' =>
                resource_path(
                    'ai/schemas/profiles/'
                    . 'road_vehicle_v1.json'
                ),

            'inspection_aspects' => [
                'size_and_dimensions',
                'body_geometry',
                'cabin_proportion',
                'wheel_configuration',
                'opening_pattern',
                'lighting_geometry',
                'materials',
            ],
        ],

        'industrial_machine' => [
            'version' => '1.0',

            'schema_path' =>
                resource_path(
                    'ai/schemas/profiles/'
                    . 'industrial_machine_v1.json'
                ),

            'inspection_aspects' => [
                'size_and_dimensions',
                'primary_massing',
                'structural_frame',
                'functional_modules',
                'interfaces',
                'openings',
                'materials',
            ],
        ],
    ],
];
```

### Chú ý

Ở Phần 7 config cũ có profiles nằm root.

Giờ tôi đổi thành:

```php
'profiles' => [...]
```

để cùng file chứa:

```text
fallback_profile
object_type_map
profiles
```

Đây là một thay đổi cấu trúc config, **không phải thay Canonical Core contract**.

ServiceProvider Phần 7 cần cập nhật tương ứng.

---

# 8.8 Generic validator

Generic validator không nên bịa domain rules.

Nó chỉ kiểm compatibility cơ bản.

```php
<?php

declare(strict_types=1);

namespace App\Video\Profiles\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Validation\ValidationResult;
use App\Video\Profiles\CategoryCreativeProfileResolver;

final class GenericPhysicalObjectSemanticValidator
    implements CategorySemanticValidator
{
    public function __construct(
        private readonly CategoryCreativeProfileResolver $profileResolver,
    ) {
    }

    public function profileKey(): string
    {
        return 'generic_physical_object';
    }

    public function validate(
        CanonicalDesignSpec $spec,
        ConceptInput $input,
    ): ValidationResult {
        /*
         * Generic không enforce domain-specific
         * relationships.
         *
         * Core validators đã xử lý:
         *
         * - paths
         * - relationship IDs
         * - invariant IDs
         * - provenance
         * - general count relationships
         *
         * Generic validator chỉ là valid
         * no-op category layer.
         */
        return ValidationResult::valid();
    }
}
```

Ở đây `profileResolver` thực ra chưa dùng.

Vậy **không inject nó**.

Bản sạch hơn:

```php
<?php

declare(strict_types=1);

namespace App\Video\Profiles\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Validation\ValidationResult;

final class GenericPhysicalObjectSemanticValidator
    implements CategorySemanticValidator
{
    public function profileKey(): string
    {
        return 'generic_physical_object';
    }

    public function validate(
        CanonicalDesignSpec $spec,
        ConceptInput $input,
    ): ValidationResult {
        return ValidationResult::valid();
    }
}
```

Tôi chọn bản này.

---

# 8.9 Marine validator

Đây là nơi kiểm những thứ Core không được biết.

Ví dụ hiện profile marine có:

```text
dimensions.length_m
dimensions.beam_m
dimensions.draft_m
dimensions.length_to_beam_ratio

permanent_geometry.hull
permanent_geometry.bow
permanent_geometry.stern
permanent_geometry.superstructure.primary_tier_count
```

Ta bám đúng các field đã khai báo trong `marine_vessel_v1.json`.

Không thêm yacht-specific field.

```php
<?php

declare(strict_types=1);

namespace App\Video\Profiles\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Canonical\Relationships\CountRelationship;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Validation\ValidationError;
use App\Video\Concept\Validation\ValidationResult;

final class MarineVesselSemanticValidator
    implements CategorySemanticValidator
{
    private const RATIO_TOLERANCE =
        0.05;

    public function profileKey(): string
    {
        return 'marine_vessel';
    }

    public function validate(
        CanonicalDesignSpec $spec,
        ConceptInput $input,
    ): ValidationResult {
        $errors = [];

        $this->validateDimensions(
            $spec,
            $errors
        );

        $this->validateSuperstructureCounts(
            $spec,
            $errors
        );

        $this->validateGeometryPresence(
            $spec,
            $errors
        );

        return new ValidationResult(
            $errors
        );
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function validateDimensions(
        CanonicalDesignSpec $spec,
        array &$errors,
    ): void {
        $length =
            $spec->dimensions[
                'length_m'
            ]
            ?? null;

        $beam =
            $spec->dimensions[
                'beam_m'
            ]
            ?? null;

        $draft =
            $spec->dimensions[
                'draft_m'
            ]
            ?? null;

        $declaredRatio =
            $spec->dimensions[
                'length_to_beam_ratio'
            ]
            ?? null;

        /*
         * Shape/type đã được Effective Schema
         * kiểm trước.
         *
         * Nhưng validator vẫn defensive.
         */
        if (
            !is_numeric($length)
            || !is_numeric($beam)
        ) {
            return;
        }

        $length =
            (float) $length;

        $beam =
            (float) $beam;

        if ($length <= 0.0) {
            $errors[] =
                new ValidationError(
                    code:
                        'marine.dimensions.length_non_positive',

                    path:
                        'dimensions.length_m',

                    message:
                        'Marine vessel length_m '
                        . 'must be greater than zero.',

                    expected:
                        '> 0',

                    actual:
                        $length,
                );
        }

        if ($beam <= 0.0) {
            $errors[] =
                new ValidationError(
                    code:
                        'marine.dimensions.beam_non_positive',

                    path:
                        'dimensions.beam_m',

                    message:
                        'Marine vessel beam_m '
                        . 'must be greater than zero.',

                    expected:
                        '> 0',

                    actual:
                        $beam,
                );

            return;
        }

        if (
            is_numeric($draft)
            && (float) $draft <= 0.0
        ) {
            $errors[] =
                new ValidationError(
                    code:
                        'marine.dimensions.draft_non_positive',

                    path:
                        'dimensions.draft_m',

                    message:
                        'Marine vessel draft_m '
                        . 'must be greater than zero.',

                    expected:
                        '> 0',

                    actual:
                        $draft,
                );
        }

        /*
         * Chỉ recompute derived ratio nếu
         * Sonnet thực sự khai báo ratio.
         *
         * Không tự thêm ratio nếu thiếu.
         */
        if (
            $length > 0.0
            && is_numeric($declaredRatio)
        ) {
            $computed =
                $length / $beam;

            $declared =
                (float) $declaredRatio;

            if (
                abs(
                    $computed
                    - $declared
                )
                > self::RATIO_TOLERANCE
            ) {
                $errors[] =
                    new ValidationError(
                        code:
                            'marine.dimensions.'
                            . 'length_to_beam_ratio_mismatch',

                        path:
                            'dimensions.length_to_beam_ratio',

                        message:
                            'Declared length_to_beam_ratio '
                            . 'does not match '
                            . 'length_m / beam_m.',

                        expected:
                            round(
                                $computed,
                                4
                            ),

                        actual:
                            $declared,
                    );
            }
        }
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function validateSuperstructureCounts(
        CanonicalDesignSpec $spec,
        array &$errors,
    ): void {
        $tierCount =
            $spec->permanentGeometry[
                'superstructure'
            ]['primary_tier_count']
            ?? null;

        if (!is_int($tierCount)) {
            return;
        }

        /*
         * Core CrossFieldValidator đã có thể
         * kiểm CountRelationship nếu subject_path
         * resolve trực tiếp thành integer.
         *
         * Ở đây ta kiểm một domain-specific
         * semantic:
         *
         * nếu relationship count target đúng
         * primary_tier_count thì nó phải đồng nhất.
         */
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

            if (
                $relationship->subjectPath
                !==
                'permanent_geometry.'
                . 'superstructure.'
                . 'primary_tier_count'
            ) {
                continue;
            }

            if (
                $relationship->value
                !== $tierCount
            ) {
                $errors[] =
                    new ValidationError(
                        code:
                            'marine.superstructure.'
                            . 'tier_count_relationship_mismatch',

                        path:
                            "relationships.{$index}.value",

                        message:
                            'Superstructure count '
                            . 'relationship conflicts '
                            . 'with primary_tier_count.',

                        expected:
                            $tierCount,

                        actual:
                            $relationship->value,
                    );
            }
        }
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function validateGeometryPresence(
        CanonicalDesignSpec $spec,
        array &$errors,
    ): void {
        /*
         * Effective schema đã required:
         *
         * hull
         * bow
         * stern
         * superstructure
         *
         * Vì vậy đây KHÔNG duplicate shape check.
         *
         * Ta chỉ kiểm semantic emptiness
         * defensive nếu DTO được tạo từ code khác.
         */
        foreach (
            [
                'hull',
                'bow',
                'stern',
                'superstructure',
            ]
            as $section
        ) {
            $value =
                $spec->permanentGeometry[
                    $section
                ]
                ?? null;

            if (
                !is_array($value)
                || $value === []
            ) {
                $errors[] =
                    new ValidationError(
                        code:
                            'marine.geometry.'
                            . 'empty_section',

                        path:
                            'permanent_geometry.'
                            . $section,

                        message:
                            sprintf(
                                'Marine vessel geometry '
                                . 'section "%s" '
                                . 'must not be empty.',
                                $section
                            ),
                    );
            }
        }
    }
}
```

---

# 8.10 Có duplicate với Core Count validator không?

Một phần đúng.

Core đã kiểm:

```text
CountRelationship
subject_path
→ resolve int
→ compare value
```

Do đó `validateSuperstructureCounts()` trên thực tế có thể bị trùng.

Với nguyên tắc:

> Core xử lý primitive semantics universal.

thì **CountRelationship integer mismatch phải để Core xử lý**, không phải marine.

Vậy bản production tôi khuyên **bỏ `validateSuperstructureCounts()` khỏi Marine validator**.

Marine validator chỉ nên làm:

```text
length/beam derived relationship
marine geometry compatibility
marine-specific physical relationships
```

Đây là boundary chuẩn hơn.

Bản final Marine validator:

```php
<?php

declare(strict_types=1);

namespace App\Video\Profiles\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Validation\ValidationError;
use App\Video\Concept\Validation\ValidationResult;

final class MarineVesselSemanticValidator
    implements CategorySemanticValidator
{
    private const RATIO_TOLERANCE =
        0.05;

    public function profileKey(): string
    {
        return 'marine_vessel';
    }

    public function validate(
        CanonicalDesignSpec $spec,
        ConceptInput $input,
    ): ValidationResult {
        $errors = [];

        $this->validateDimensions(
            $spec,
            $errors
        );

        $this->validateGeometry(
            $spec,
            $errors
        );

        return new ValidationResult(
            $errors
        );
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function validateDimensions(
        CanonicalDesignSpec $spec,
        array &$errors,
    ): void {
        $length =
            $spec->dimensions[
                'length_m'
            ]
            ?? null;

        $beam =
            $spec->dimensions[
                'beam_m'
            ]
            ?? null;

        $ratio =
            $spec->dimensions[
                'length_to_beam_ratio'
            ]
            ?? null;

        if (
            !is_numeric($length)
            || !is_numeric($beam)
        ) {
            return;
        }

        $length =
            (float) $length;

        $beam =
            (float) $beam;

        if (
            $length <= 0.0
            || $beam <= 0.0
        ) {
            /*
             * Effective JSON Schema handles
             * positivity.
             */
            return;
        }

        if (!is_numeric($ratio)) {
            return;
        }

        $computed =
            $length / $beam;

        $declared =
            (float) $ratio;

        if (
            abs(
                $computed
                - $declared
            )
            > self::RATIO_TOLERANCE
        ) {
            $errors[] =
                new ValidationError(
                    code:
                        'marine.dimensions.'
                        . 'length_to_beam_ratio_mismatch',

                    path:
                        'dimensions.length_to_beam_ratio',

                    message:
                        'Declared length_to_beam_ratio '
                        . 'does not match '
                        . 'length_m / beam_m.',

                    expected:
                        round(
                            $computed,
                            4
                        ),

                    actual:
                        $declared,
                );
        }
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function validateGeometry(
        CanonicalDesignSpec $spec,
        array &$errors,
    ): void {
        $bow =
            $spec->permanentGeometry[
                'bow'
            ]
            ?? null;

        $stern =
            $spec->permanentGeometry[
                'stern'
            ]
            ?? null;

        $hull =
            $spec->permanentGeometry[
                'hull'
            ]
            ?? null;

        /*
         * Schema handles presence/shape.
         * Category semantic layer only catches
         * impossible semantic emptiness.
         */
        foreach (
            [
                'hull' => $hull,
                'bow' => $bow,
                'stern' => $stern,
            ]
            as $name => $section
        ) {
            if (
                is_array($section)
                && $section === []
            ) {
                $errors[] =
                    new ValidationError(
                        code:
                            'marine.geometry.empty_section',

                        path:
                            'permanent_geometry.'
                            . $name,

                        message:
                            sprintf(
                                'Marine geometry section '
                                . '"%s" cannot be empty.',
                                $name
                            ),
                    );
            }
        }
    }
}
```

---

# 8.11 Aircraft validator

Bám đúng `aircraft_v1.json` Phần 7:

```text
dimensions:
length_m
wingspan_m
height_m

geometry:
fuselage
wing
engine_configuration
tail
landing_configuration
```

Ta không invent aerodynamics calculator.

V1 chỉ làm deterministic relationships mà dữ liệu hỗ trợ.

```php
<?php

declare(strict_types=1);

namespace App\Video\Profiles\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Validation\ValidationError;
use App\Video\Concept\Validation\ValidationResult;

final class AircraftSemanticValidator
    implements CategorySemanticValidator
{
    public function profileKey(): string
    {
        return 'aircraft';
    }

    public function validate(
        CanonicalDesignSpec $spec,
        ConceptInput $input,
    ): ValidationResult {
        $errors = [];

        $this->validateDimensions(
            $spec,
            $errors
        );

        $this->validateEngineConfiguration(
            $spec,
            $errors
        );

        return new ValidationResult(
            $errors
        );
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function validateDimensions(
        CanonicalDesignSpec $spec,
        array &$errors,
    ): void {
        $length =
            $spec->dimensions[
                'length_m'
            ]
            ?? null;

        $wingspan =
            $spec->dimensions[
                'wingspan_m'
            ]
            ?? null;

        /*
         * Effective schema handles >0.
         *
         * Category layer hiện không áp đặt
         * "realistic aircraft ratio" tùy tiện
         * vì không có canonical derived field
         * tương ứng trong profile V1.
         */
        if (
            !is_numeric($length)
            || !is_numeric($wingspan)
        ) {
            return;
        }

        if (
            (float) $length <= 0.0
            || (float) $wingspan <= 0.0
        ) {
            return;
        }
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function validateEngineConfiguration(
        CanonicalDesignSpec $spec,
        array &$errors,
    ): void {
        $config =
            $spec->permanentGeometry[
                'engine_configuration'
            ]
            ?? null;

        if (!is_array($config)) {
            return;
        }

        $count =
            $config['count']
            ?? null;

        $mounting =
            $config['mounting']
            ?? null;

        /*
         * Nếu count = 0 nhưng lại có mounting
         * mô tả engine mount, đó là contradiction
         * có thể kiểm deterministic.
         */
        if (
            $count === 0
            && is_string($mounting)
            && trim($mounting) !== ''
        ) {
            $errors[] =
                new ValidationError(
                    code:
                        'aircraft.engine.'
                        . 'zero_count_with_mounting',

                    path:
                        'permanent_geometry.'
                        . 'engine_configuration',

                    message:
                        'Aircraft engine count is zero '
                        . 'but engine mounting '
                        . 'geometry is declared.',

                    expected:
                        'no engine mounting when count=0',

                    actual:
                        $mounting,
                );
        }
    }
}
```

---

# 8.12 Không nên “đoán vật lý” quá mức

Ví dụ không viết:

```php
if ($wingspan < $length * 0.7) {
    fail();
}
```

vì:

```text
fighter
glider
airliner
business jet
experimental aircraft
```

có tỷ lệ khác nhau.

Validator chỉ được enforce:

```text
relationship chắc chắn từ chính contract
```

không enforce “kinh nghiệm phổ biến”.

Đây là nguyên tắc quan trọng để hệ thống không bóp creativity.

---

# 8.13 Architecture validator

Bám profile Phần 7:

```text
dimensions.floor_count
permanent_geometry.primary_massing.volume_count
primary_massing.vertical_progression
facade
circulation
roof
```

Có một cross-field deterministic hợp lý:

```text
floor_count > 1
và vertical circulation được khai báo "none"
```

Nhưng vì `primary_vertical` đang là free string, parse `"none"` không reliable.

Không nên semantic-parse arbitrary prose.

Vậy V1 architecture validator chỉ kiểm những relationships structurally determinable.

```php
<?php

declare(strict_types=1);

namespace App\Video\Profiles\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Validation\ValidationError;
use App\Video\Concept\Validation\ValidationResult;

final class ArchitectureSemanticValidator
    implements CategorySemanticValidator
{
    public function profileKey(): string
    {
        return 'architecture';
    }

    public function validate(
        CanonicalDesignSpec $spec,
        ConceptInput $input,
    ): ValidationResult {
        $errors = [];

        $this->validateMassing(
            $spec,
            $errors
        );

        return new ValidationResult(
            $errors
        );
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function validateMassing(
        CanonicalDesignSpec $spec,
        array &$errors,
    ): void {
        $massing =
            $spec->permanentGeometry[
                'primary_massing'
            ]
            ?? null;

        if (!is_array($massing)) {
            return;
        }

        $volumeCount =
            $massing[
                'volume_count'
            ]
            ?? null;

        /*
         * Shape/minimum đã do profile schema xử lý.
         *
         * Không parse "organization" string để
         * suy ra cardinality.
         *
         * Semantic inference từ free text
         * không deterministic.
         */
        if (
            is_int($volumeCount)
            && $volumeCount < 1
        ) {
            $errors[] =
                new ValidationError(
                    code:
                        'architecture.massing.'
                        . 'invalid_volume_count',

                    path:
                        'permanent_geometry.'
                        . 'primary_massing.'
                        . 'volume_count',

                    message:
                        'Architecture volume_count '
                        . 'must be at least one.',

                    expected:
                        '>= 1',

                    actual:
                        $volumeCount,
                );
        }
    }
}
```

Nhưng lại có vấn đề:

```text
minimum: 1
```

đã được profile schema kiểm.

Vậy category validator này gần như no-op.

Đó **không phải vấn đề**.

Không cần cố nhét logic chỉ để validator “có việc”.

Bản production sạch hơn:

```php
<?php

declare(strict_types=1);

namespace App\Video\Profiles\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Validation\ValidationResult;

final class ArchitectureSemanticValidator
    implements CategorySemanticValidator
{
    public function profileKey(): string
    {
        return 'architecture';
    }

    public function validate(
        CanonicalDesignSpec $spec,
        ConceptInput $input,
    ): ValidationResult {
        /*
         * Architecture profile V1 currently has
         * no additional deterministic semantic
         * relationships beyond:
         *
         * - Effective Profile Schema
         * - Core relationship validators
         *
         * Keep this intentionally empty until
         * profile V1 introduces structured fields
         * supporting deterministic cross-field
         * validation.
         */
        return ValidationResult::valid();
    }
}
```

Tôi chọn bản này.

---

# 8.14 Road vehicle và Industrial Machine thì sao?

Phần 7 đã có profile config:

```text
road_vehicle
industrial_machine
```

nhưng chưa viết schema đầy đủ trong câu trả lời trước.

Do đó Phần 8 **không nên giả vờ có validator đầy đủ** cho hai profile đó.

Có hai lựa chọn:

```text
A. chưa register profile nếu chưa có schema+validator hoàn chỉnh

B. tạo no-op validator tạm thời rõ ràng
```

Production tôi chọn A:

> Một profile được active chỉ khi có đủ schema + validator registration.

Như vậy startup fail sớm nếu cấu hình thiếu.

---

# 8.15 Profile compatibility validator nên nằm ở đâu?

Không cho Marine validator tự kiểm:

```php
if ($input->profile->key !== 'marine_vessel')
```

Registry đã đảm bảo đúng validator.

Compatibility:

```text
object_type
↔
profile
```

là universal orchestration/domain concern.

Ta thêm một validator riêng.

### `ProfileCompatibilityValidator.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Profiles\Validation;

use App\Video\Concept\ConceptInput;
use App\Video\Concept\Validation\ValidationError;
use App\Video\Concept\Validation\ValidationResult;
use App\Video\Profiles\CategoryCreativeProfileResolver;

final class ProfileCompatibilityValidator
{
    public function __construct(
        private readonly CategoryCreativeProfileResolver $resolver,
    ) {
    }

    public function validate(
        ConceptInput $input
    ): ValidationResult {
        $expectedProfile =
            $this->resolver
                ->resolve(
                    $input->objectType
                );

        if (
            $expectedProfile->key
            === $input->profile->key
        ) {
            return ValidationResult::valid();
        }

        return ValidationResult::invalid([
            new ValidationError(
                code:
                    'profile.object_type_mismatch',

                path:
                    'profile.key',

                message:
                    sprintf(
                        'Object type "%s" resolves to '
                        . 'profile "%s", but ConceptInput '
                        . 'uses profile "%s".',
                        $input->objectType,
                        $expectedProfile->key,
                        $input->profile->key,
                    ),

                expected:
                    $expectedProfile->key,

                actual:
                    $input->profile->key,
            ),
        ]);
    }
}
```

---

# 8.16 Một nuance rất quan trọng với fallback

Giả sử:

```text
object_type = quantum_excavator
```

không map được.

Resolver:

```text
→ generic_physical_object
```

Nếu input profile cũng generic:

```text
PASS
```

Nếu caller cố truyền:

```text
profile = industrial_machine
```

nhưng mapping chưa có:

```text
FAIL
```

Điều này là tốt vì selection phải deterministic.

Không cho caller tùy ý override profile mà không có mapping rõ ràng.

Nếu tương lai muốn explicit override, tạo separate policy:

```text
profile_override_allowed
```

không lén bypass resolver.

---

# 8.17 Cập nhật `CanonicalDesignSpecValidator`

Phần 3 trước đây:

```php
validate(
    CanonicalDesignSpec $spec,
    InspirationBrief $brief
)
```

Phần 7 processor đã cần `ConceptInput`.

Bây giờ chốt signature mới:

```php
validate(
    CanonicalDesignSpec $spec,
    ConceptInput $input
)
```

Không truyền InspirationBrief riêng nữa vì:

```php
$input->inspiration
```

đã có.

### Bản production:

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\ConceptInput;
use App\Video\Profiles\Validation\CategorySemanticValidatorRegistry;
use App\Video\Profiles\Validation\ProfileCompatibilityValidator;

final class CanonicalDesignSpecValidator
{
    public function __construct(
        private readonly CanonicalCrossFieldValidator $crossFieldValidator,
        private readonly CanonicalProvenanceValidator $provenanceValidator,
        private readonly ProfileCompatibilityValidator $profileCompatibilityValidator,
        private readonly CategorySemanticValidatorRegistry $categoryValidatorRegistry,
    ) {
    }

    public function validate(
        CanonicalDesignSpec $spec,
        ConceptInput $input,
    ): ValidationResult {
        $result =
            ValidationResult::valid();

        /*
         * -----------------------------------------
         * 1. Universal semantic/cross-field rules
         * -----------------------------------------
         */
        $result =
            $result->merge(
                $this->crossFieldValidator
                    ->validate(
                        $spec
                    )
            );

        /*
         * -----------------------------------------
         * 2. Provenance
         * -----------------------------------------
         *
         * InspirationBrief lấy từ ConceptInput.
         */
        $result =
            $result->merge(
                $this->provenanceValidator
                    ->validate(
                        $spec,
                        $input->inspiration
                    )
            );

        /*
         * -----------------------------------------
         * 3. Object type/profile compatibility
         * -----------------------------------------
         */
        $result =
            $result->merge(
                $this->profileCompatibilityValidator
                    ->validate(
                        $input
                    )
            );

        /*
         * Nếu profile selection đã sai,
         * vẫn có thể chạy category validator,
         * nhưng không có nhiều giá trị.
         *
         * Để error payload gọn và deterministic,
         * ta chỉ dispatch category validator khi
         * compatibility pass.
         */
        $compatibility =
            $this->profileCompatibilityValidator
                ->validate(
                    $input
                );

        if ($compatibility->passes()) {
            $categoryValidator =
                $this->categoryValidatorRegistry
                    ->forProfile(
                        $input->profile
                    );

            $result =
                $result->merge(
                    $categoryValidator
                        ->validate(
                            $spec,
                            $input
                        )
                );
        }

        return $result;
    }
}
```

Có duplicate call:

```php
profileCompatibilityValidator->validate()
```

hai lần.

Sửa sạch:

```php
public function validate(
    CanonicalDesignSpec $spec,
    ConceptInput $input,
): ValidationResult {
    $result =
        ValidationResult::valid();

    $result =
        $result->merge(
            $this->crossFieldValidator
                ->validate(
                    $spec
                )
        );

    $result =
        $result->merge(
            $this->provenanceValidator
                ->validate(
                    $spec,
                    $input->inspiration
                )
        );

    $compatibility =
        $this->profileCompatibilityValidator
            ->validate(
                $input
            );

    $result =
        $result->merge(
            $compatibility
        );

    if ($compatibility->passes()) {
        $categoryValidator =
            $this->categoryValidatorRegistry
                ->forProfile(
                    $input->profile
                );

        $result =
            $result->merge(
                $categoryValidator
                    ->validate(
                        $spec,
                        $input
                    )
            );
    }

    return $result;
}
```

Đây là bản chốt.

---

# 8.18 `ValidationResult` phải hỗ trợ `merge()`

Nếu bản Phần 3 của bạn chưa có implementation chính xác, dùng bản này:

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
        return new self([]);
    }

    /**
     * @param list<ValidationError> $errors
     */
    public static function invalid(
        array $errors
    ): self {
        return new self(
            array_values(
                $errors
            )
        );
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    public function merge(
        self $other
    ): self {
        if ($other->passes()) {
            return $this;
        }

        if ($this->passes()) {
            return $other;
        }

        return new self(
            array_values(
                array_merge(
                    $this->errors,
                    $other->errors,
                )
            )
        );
    }
}
```

Không deduplicate error bằng message.

Mỗi validator phải tạo stable `code + path`.

---

# 8.19 Processor Phần 7 cần sửa một dòng quan trọng

Ở Phần 7 tôi viết:

```php
$semantic =
    $this->semanticValidator
        ->validate(
            $spec,
            $input->inspiration
        );
```

Sau Phần 8 phải đổi thành:

```php
$semantic =
    $this->semanticValidator
        ->validate(
            $spec,
            $input
        );
```

và revalidation:

```php
$semanticAfterNormalize =
    $this->semanticValidator
        ->validate(
            $normalized,
            $input
        );
```

Đây là signature chính thức từ Phần 8 trở đi.

---

# 8.20 `CanonicalConceptProcessor` final semantic section

Để tránh nhầm khi ghép code:

```php
/*
 * -----------------------------------------
 * SEMANTIC VALIDATION
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
```

Sau normalization:

```php
/*
 * -----------------------------------------
 * SEMANTIC RE-VALIDATION
 * -----------------------------------------
 */
$semanticAfterNormalize =
    $this->semanticValidator
        ->validate(
            $normalized,
            $input
        );

if ($semanticAfterNormalize->fails()) {
    throw new CanonicalValidationException(
        errors:
            $semanticAfterNormalize
                ->errors,

        failedRawJson:
            $normalizedJson,

        message:
            'Normalized canonical design '
            . 'failed semantic validation.'
    );
}
```

Như vậy repair flow Phần 5 vẫn hoạt động nguyên vẹn.

---

# 8.21 Error từ category validator vẫn đi thẳng vào Sonnet Repairer

Ví dụ Sonnet tạo:

```json
{
  "dimensions": {
    "length_m": 120,
    "beam_m": 20,
    "length_to_beam_ratio": 5.1
  }
}
```

Deterministic calculation:

```text
120 / 20 = 6.0
```

Marine validator trả:

```json
{
  "code": "marine.dimensions.length_to_beam_ratio_mismatch",
  "path": "dimensions.length_to_beam_ratio",
  "message": "Declared length_to_beam_ratio does not match length_m / beam_m.",
  "expected": 6.0,
  "actual": 5.1
}
```

`CanonicalDesignSpecValidator`

```text
↓
CanonicalConceptProcessor
↓
CanonicalValidationException
```

Exception giữ:

```text
failedRawJson
+
ValidationError[]
```

`BuildCanonicalConcept` Phần 5:

```text
↓
ClaudeConceptRepairer
```

Sonnet 5 nhận chính xác:

```text
expected = 6.0
actual   = 5.1
path     = dimensions.length_to_beam_ratio
```

và repair chỉ 1 lần.

Không thay đổi architecture repair đã chốt.

---

# 8.22 Cảnh báo: ratio repair nên sửa field nào?

Trong ví dụ:

```text
length = 120
beam   = 20
ratio  = 5.1
```

Validator không biết chắc:

```text
ratio sai
hay
length sai
hay
beam sai
```

Nhưng vì `length` và `beam` là primary measurements còn ratio là derived field, repair prompt đã có policy:

```text
prefer deterministically supported expected value
```

nên sửa:

```text
ratio 5.1 → 6.0
```

là hợp lý.

Ta nên formalize semantics:

```text
primary field:
length_m
beam_m

derived field:
length_to_beam_ratio
```

Nhưng **không thêm metadata này vào Canonical Core V1**.

Có thể đặt ở profile semantic validator.

---

# 8.23 Không để category validator normalize dữ liệu

Sai:

```php
$spec->dimensions[
    'length_to_beam_ratio'
] =
    $length / $beam;
```

Category validator không được sửa output Sonnet.

Đúng:

```php
return ValidationError(
    expected: 6.0,
    actual: 5.1,
);
```

Sau đó:

```text
Sonnet Repair
```

sửa semantic document.

Tại sao không deterministic auto-fix ratio?

Vì về lâu dài có những contradictions không biết field nào authoritative.

Ta giữ một policy nhất quán:

```text
validator detects
repairer repairs
normalizer only canonicalizes
```

---

# 8.24 ServiceProvider — Profile Registry update

Vì config Phase 8 đã chuyển profiles vào:

```php
category_creative_profiles.profiles
```

binding Phần 7 đổi thành:

```php
$this->app->singleton(
    CategoryCreativeProfileRegistry::class,
    function (): CategoryCreativeProfileRegistry {
        $config =
            config(
                'category_creative_profiles.profiles',
                []
            );

        if (!is_array($config)) {
            throw new RuntimeException(
                'category_creative_profiles.profiles '
                . 'must be an array.'
            );
        }

        $profiles = [];

        foreach (
            $config as $key => $item
        ) {
            if (
                !is_string($key)
                || !is_array($item)
            ) {
                throw new RuntimeException(
                    'Invalid category creative '
                    . 'profile configuration.'
                );
            }

            $version =
                $item['version']
                ?? null;

            $schemaPath =
                $item['schema_path']
                ?? null;

            $inspectionAspects =
                $item['inspection_aspects']
                ?? null;

            if (
                !is_string($version)
                || !is_string($schemaPath)
                || !is_array($inspectionAspects)
            ) {
                throw new RuntimeException(
                    sprintf(
                        'Invalid category creative '
                        . 'profile "%s".',
                        $key
                    )
                );
            }

            $profiles[] =
                new CategoryCreativeProfile(
                    key:
                        $key,

                    version:
                        $version,

                    schemaPath:
                        $schemaPath,

                    inspectionAspects:
                        array_values(
                            $inspectionAspects
                        ),
                );
        }

        return new CategoryCreativeProfileRegistry(
            $profiles
        );
    }
);
```

---

# 8.25 ServiceProvider — Profile Resolver

```php
$this->app->singleton(
    CategoryCreativeProfileResolver::class,
    function (
        Application $app
    ): CategoryCreativeProfileResolver {
        $map =
            config(
                'category_creative_profiles.'
                . 'object_type_map',
                []
            );

        if (!is_array($map)) {
            throw new RuntimeException(
                'category_creative_profiles.'
                . 'object_type_map '
                . 'must be an array.'
            );
        }

        $fallback =
            config(
                'category_creative_profiles.'
                . 'fallback_profile',
                'generic_physical_object'
            );

        if (
            !is_string($fallback)
            || trim($fallback) === ''
        ) {
            throw new RuntimeException(
                'Invalid fallback category profile.'
            );
        }

        return new CategoryCreativeProfileResolver(
            registry:
                $app->make(
                    CategoryCreativeProfileRegistry::class
                ),

            objectTypeMap:
                $map,

            fallbackProfileKey:
                $fallback,
        );
    }
);
```

---

# 8.26 ServiceProvider — validators

Imports:

```php
use App\Video\Profiles\Validation\AircraftSemanticValidator;
use App\Video\Profiles\Validation\ArchitectureSemanticValidator;
use App\Video\Profiles\Validation\CategorySemanticValidatorRegistry;
use App\Video\Profiles\Validation\GenericPhysicalObjectSemanticValidator;
use App\Video\Profiles\Validation\MarineVesselSemanticValidator;
use App\Video\Profiles\Validation\ProfileCompatibilityValidator;
```

Bindings:

```php
$this->app->singleton(
    GenericPhysicalObjectSemanticValidator::class
);

$this->app->singleton(
    MarineVesselSemanticValidator::class
);

$this->app->singleton(
    AircraftSemanticValidator::class
);

$this->app->singleton(
    ArchitectureSemanticValidator::class
);

$this->app->singleton(
    ProfileCompatibilityValidator::class
);

$this->app->singleton(
    CategorySemanticValidatorRegistry::class,
    function (
        Application $app
    ): CategorySemanticValidatorRegistry {
        return new CategorySemanticValidatorRegistry([
            $app->make(
                GenericPhysicalObjectSemanticValidator::class
            ),

            $app->make(
                MarineVesselSemanticValidator::class
            ),

            $app->make(
                AircraftSemanticValidator::class
            ),

            $app->make(
                ArchitectureSemanticValidator::class
            ),
        ]);
    }
);
```

---

# 8.27 Một startup check rất nên có

Có thể config profile:

```text
marine_vessel
aircraft
architecture
road_vehicle
industrial_machine
generic
```

nhưng registry semantic chỉ có:

```text
marine
aircraft
architecture
generic
```

`road_vehicle` và `industrial_machine` sẽ thiếu validator.

Thay vì để lỗi tới runtime, thêm consistency check.

### `ProfileSystemIntegrityChecker.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Profiles;

use App\Video\Profiles\Validation\CategorySemanticValidatorRegistry;
use RuntimeException;

final class ProfileSystemIntegrityChecker
{
    public function __construct(
        private readonly CategoryCreativeProfileRegistry $profiles,
        private readonly CategorySemanticValidatorRegistry $validators,
    ) {
    }

    public function assertValid(): void
    {
        foreach (
            $this->profiles->all()
            as $profile
        ) {
            if (
                !$this->validators
                    ->has(
                        $profile->key
                    )
            ) {
                throw new RuntimeException(
                    sprintf(
                        'Profile "%s@%s" has no '
                        . 'registered semantic validator.',
                        $profile->key,
                        $profile->version,
                    )
                );
            }
        }
    }
}
```

---

# 8.28 Vậy road_vehicle/industrial_machine phải xử lý sao?

Vì Phần 7 mới khai config mà chưa triển khai đầy đủ schema/semantic logic, để integrity checker pass tạm thời có thể tạo explicit no-op validators.

Đây là trường hợp no-op hợp lệ nếu schema đã kiểm shape.

### `RoadVehicleSemanticValidator.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Profiles\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Validation\ValidationResult;

final class RoadVehicleSemanticValidator
    implements CategorySemanticValidator
{
    public function profileKey(): string
    {
        return 'road_vehicle';
    }

    public function validate(
        CanonicalDesignSpec $spec,
        ConceptInput $input,
    ): ValidationResult {
        return ValidationResult::valid();
    }
}
```

### `IndustrialMachineSemanticValidator.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Profiles\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Validation\ValidationResult;

final class IndustrialMachineSemanticValidator
    implements CategorySemanticValidator
{
    public function profileKey(): string
    {
        return 'industrial_machine';
    }

    public function validate(
        CanonicalDesignSpec $spec,
        ConceptInput $input,
    ): ValidationResult {
        return ValidationResult::valid();
    }
}
```

Nhưng phải ghi rõ:

```text
no-op because Profile V1 currently declares no extra
deterministic semantic rules.
```

Không phải TODO mơ hồ.

---

# 8.29 Registry đầy đủ theo config hiện tại

```php
$this->app->singleton(
    CategorySemanticValidatorRegistry::class,
    function (
        Application $app
    ): CategorySemanticValidatorRegistry {
        return new CategorySemanticValidatorRegistry([
            $app->make(
                GenericPhysicalObjectSemanticValidator::class
            ),

            $app->make(
                MarineVesselSemanticValidator::class
            ),

            $app->make(
                AircraftSemanticValidator::class
            ),

            $app->make(
                ArchitectureSemanticValidator::class
            ),

            $app->make(
                RoadVehicleSemanticValidator::class
            ),

            $app->make(
                IndustrialMachineSemanticValidator::class
            ),
        ]);
    }
);
```

---

# 8.30 Cập nhật binding `CanonicalDesignSpecValidator`

Phần 6 binding cũ không còn đúng constructor.

Bản mới:

```php
$this->app->singleton(
    CanonicalDesignSpecValidator::class,
    function (
        Application $app
    ): CanonicalDesignSpecValidator {
        return new CanonicalDesignSpecValidator(
            crossFieldValidator:
                $app->make(
                    CanonicalCrossFieldValidator::class
                ),

            provenanceValidator:
                $app->make(
                    CanonicalProvenanceValidator::class
                ),

            profileCompatibilityValidator:
                $app->make(
                    ProfileCompatibilityValidator::class
                ),

            categoryValidatorRegistry:
                $app->make(
                    CategorySemanticValidatorRegistry::class
                ),
        );
    }
);
```

Notice:

```text
CanonicalPathValidator
```

không còn inject trực tiếp vào `CanonicalDesignSpecValidator`.

Nó nên nằm bên trong:

```text
CanonicalCrossFieldValidator
```

nếu CrossField cần resolve paths.

Đây bám đúng trách nhiệm Phần 3.

---

# 8.31 Boot integrity check

Có thể thêm trong ServiceProvider:

```php
public function boot(): void
{
    if (
        app()->runningUnitTests()
    ) {
        return;
    }

    $this->app
        ->make(
            ProfileSystemIntegrityChecker::class
        )
        ->assertValid();
}
```

Tuy nhiên tôi **không thích resolve cả dependency graph + potentially file IO trong `boot()` mỗi request**.

Tốt hơn:

```text
Artisan command
CI test
deployment smoke check
```

Nên tôi không khuyên boot runtime check.

Ta test integrity trong automated tests.

---

# 8.32 Tests bắt buộc

Đây là phần quan trọng hơn việc thêm nhiều validator.

## `tests/Unit/Video/Profiles/CategoryCreativeProfileResolverTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Video\Profiles;

use App\Video\Profiles\CategoryCreativeProfile;
use App\Video\Profiles\CategoryCreativeProfileRegistry;
use App\Video\Profiles\CategoryCreativeProfileResolver;
use PHPUnit\Framework\TestCase;

final class CategoryCreativeProfileResolverTest
    extends TestCase
{
    public function test_resolves_mapped_object_type(): void
    {
        $registry =
            new CategoryCreativeProfileRegistry([
                $this->profile(
                    'generic_physical_object'
                ),

                $this->profile(
                    'marine_vessel'
                ),
            ]);

        $resolver =
            new CategoryCreativeProfileResolver(
                registry:
                    $registry,

                objectTypeMap: [
                    'superyacht' =>
                        'marine_vessel',
                ],

                fallbackProfileKey:
                    'generic_physical_object',
            );

        self::assertSame(
            'marine_vessel',
            $resolver
                ->resolve(
                    'superyacht'
                )
                ->key
        );
    }

    public function test_unknown_type_uses_generic(): void
    {
        $registry =
            new CategoryCreativeProfileRegistry([
                $this->profile(
                    'generic_physical_object'
                ),

                $this->profile(
                    'marine_vessel'
                ),
            ]);

        $resolver =
            new CategoryCreativeProfileResolver(
                registry:
                    $registry,

                objectTypeMap: [],

                fallbackProfileKey:
                    'generic_physical_object',
            );

        self::assertSame(
            'generic_physical_object',
            $resolver
                ->resolve(
                    'unknown_machine_xyz'
                )
                ->key
        );
    }

    private function profile(
        string $key
    ): CategoryCreativeProfile {
        return new CategoryCreativeProfile(
            key:
                $key,

            version:
                '1.0',

            schemaPath:
                '/tmp/' . $key . '.json',

            inspectionAspects: [
                'form',
            ],
        );
    }
}
```

---

# 8.33 Marine ratio test

```php
public function test_rejects_inconsistent_length_to_beam_ratio(): void
{
    $spec =
        $this->makeSpec(
            dimensions: [
                'length_m' =>
                    120.0,

                'beam_m' =>
                    20.0,

                'length_to_beam_ratio' =>
                    5.1,
            ]
        );

    $validator =
        new MarineVesselSemanticValidator();

    $result =
        $validator->validate(
            $spec,
            $this->marineConceptInput()
        );

    self::assertTrue(
        $result->fails()
    );

    self::assertSame(
        'marine.dimensions.'
        . 'length_to_beam_ratio_mismatch',

        $result->errors[0]->code
    );

    self::assertSame(
        6.0,
        $result->errors[0]->expected
    );

    self::assertSame(
        5.1,
        $result->errors[0]->actual
    );
}
```

Và pass:

```php
public function test_accepts_consistent_length_to_beam_ratio(): void
{
    $spec =
        $this->makeSpec(
            dimensions: [
                'length_m' =>
                    120.0,

                'beam_m' =>
                    20.0,

                'length_to_beam_ratio' =>
                    6.0,
            ]
        );

    $result =
        (new MarineVesselSemanticValidator())
            ->validate(
                $spec,
                $this->marineConceptInput()
            );

    self::assertTrue(
        $result->passes()
    );
}
```

---

# 8.34 Critical repair integration test

Đây mới là test quan trọng nhất của Phần 8.

Ta phải chứng minh:

```text
category semantic error
↓
BuildCanonicalConcept catches
↓
Repairer receives exact error
↓
repair called exactly once
```

Pseudo PHPUnit:

```php
public function test_category_semantic_failure_is_sent_to_repair_once(): void
{
    $designer =
        $this->createMock(
            ClaudeConceptDesigner::class
        );

    $repairer =
        $this->createMock(
            ClaudeConceptRepairer::class
        );

    $designer
        ->expects(
            self::once()
        )
        ->method('generate')
        ->willReturn(
            $this->payloadWithRatio(
                5.1
            )
        );

    $repairer
        ->expects(
            self::once()
        )
        ->method('repair')
        ->with(
            self::anything(),
            self::anything(),
            self::callback(
                function (
                    array $errors
                ): bool {
                    return
                        count($errors) === 1
                        && $errors[0]->code
                            ===
                            'marine.dimensions.'
                            . 'length_to_beam_ratio_mismatch'
                        && $errors[0]->expected
                            === 6.0
                        && $errors[0]->actual
                            === 5.1;
                }
            )
        )
        ->willReturn(
            $this->payloadWithRatio(
                6.0
            )
        );

    /*
     * BuildCanonicalConcept...
     */

    $result =
        $builder->build(
            $this->marineConceptInput(),
            revision: 1,
        );

    self::assertSame(
        6.0,
        $result
            ->spec
            ->dimensions[
                'length_to_beam_ratio'
            ]
    );
}
```

Test này chứng minh architecture từ Phần 5 tới Phần 8 nối đúng nhau.

---

# 8.35 Test repair không được lần hai

```php
public function test_second_semantic_failure_is_not_repaired_again(): void
{
    $designer
        ->expects(
            self::once()
        );

    $repairer
        ->expects(
            self::once()
        );

    /*
     * Initial ratio = 5.1 -> fail.
     *
     * Repair output ratio = 5.2 -> vẫn fail.
     */

    $this->expectException(
        CanonicalValidationException::class
    );

    $builder->build(
        $input,
        revision: 1,
    );
}
```

Không:

```text
repairer twice
```

Phần 8 không thay policy Phần 5.

---

# 8.36 Test profile mismatch

Ví dụ:

```text
object_type = superyacht
profile = aircraft
```

phải tạo:

```text
profile.object_type_mismatch
```

Test:

```php
public function test_rejects_profile_mismatch(): void
{
    $input =
        new ConceptInput(
            objectType:
                'superyacht',

            inspiration:
                $this->inspiration(),

            profile:
                $this->aircraftProfile(),

            projectRequirements: [],
        );

    $result =
        $validator->validate(
            $input
        );

    self::assertTrue(
        $result->fails()
    );

    self::assertSame(
        'profile.object_type_mismatch',
        $result->errors[0]->code
    );

    self::assertSame(
        'marine_vessel',
        $result->errors[0]->expected
    );

    self::assertSame(
        'aircraft',
        $result->errors[0]->actual
    );
}
```

---

# 8.37 Một sửa đổi nhỏ nhưng rất cần ở `CanonicalProvenanceValidator`

Ở Phần 3 ta đã nói provenance validator cần kiểm source aspects.

Phần 8 không thay logic đó.

Signature vẫn:

```php
public function validate(
    CanonicalDesignSpec $spec,
    InspirationBrief $brief
): ValidationResult
```

`CanonicalDesignSpecValidator` gọi:

```php
$this->provenanceValidator
    ->validate(
        $spec,
        $input->inspiration
    );
```

Không inject profile vào ProvenanceValidator.

Provenance là universal.

---

# 8.38 Các trách nhiệm sau Phần 8 phải phân biệt rõ

| Layer                         | Kiểm gì                                       |
| ----------------------------- | --------------------------------------------- |
| Core Schema                   | shape universal                               |
| Effective Schema              | shape theo profile                            |
| CanonicalPathValidator        | path existence                                |
| CanonicalCrossFieldValidator  | relationship primitive universal              |
| CanonicalProvenanceValidator  | inspired/invented lineage                     |
| ProfileCompatibilityValidator | object type ↔ profile                         |
| Marine validator              | marine-specific derived semantics             |
| Aircraft validator            | aircraft-specific deterministic semantics     |
| Architecture validator        | architecture-specific deterministic semantics |
| Vision QA                     | hình render có thực sự đúng spec              |

Ví dụ:

```text
"exactly 4 tiers"
```

Nếu canonical relationship/count không khớp:

```text
CrossFieldValidator
```

Nếu canonical spec đúng 4 nhưng GPT Image render 3:

```text
Vision QA
```

Không để CategorySemanticValidator nhìn ảnh.

---

# 8.39 Điều CategorySemanticValidator tuyệt đối không làm

```php
// ❌ Không
$spec->dimensions['ratio'] = ...;

// ❌ Không
$this->claude->repair(...);

// ❌ Không
VideoSession::update(...);

// ❌ Không
DB::table(...);

// ❌ Không
if (str_contains(
    $spec->formRelationships['description'],
    'three volumes'
)) {
    ...
}

// ❌ Không semantic-parse prose nếu không deterministic.
```

Đúng:

```php
// ✅
return new ValidationError(
    code: ...,
    path: ...,
    expected: ...,
    actual: ...,
);
```

---

# 8.40 Một điểm cần giữ để hệ thống 10.000 topics không nổ số validator

Ta không làm:

```text
SuperyachtSemanticValidator
CargoShipSemanticValidator
FerrySemanticValidator
ContainerShipSemanticValidator
DestroyerSemanticValidator
```

Mà:

```text
superyacht ──────┐
cargo_ship ──────┤
ferry ───────────┤
container_ship ──┤
                 ↓
       MarineVesselSemanticValidator
```

Tương tự:

```text
private_jet ───────┐
business_jet ──────┤
airliner ──────────┤
cargo_aircraft ────┤
                   ↓
        AircraftSemanticValidator
```

Đây chính là lý do có:

```text
object_type
+
profile
```

tách biệt.

---

# 8.41 Full semantic path sau khi ghép Phần 8

Giả sử article dẫn đến:

```text
object_type = superyacht
```

Resolver:

```text
superyacht
↓
marine_vessel@1.0
```

InspirationBuilder:

```text
↓
InspirationBrief
```

ConceptInput:

```json
{
  "object_type": "superyacht",
  "profile": {
    "key": "marine_vessel",
    "version": "1.0"
  }
}
```

Schema:

```text
Core V1
+
marine_vessel_v1
↓
Effective Schema
```

Sonnet 5:

```text
↓
Canonical JSON
```

Validation:

```text
Core Schema
↓
Marine Effective Schema
↓
CanonicalDesignSpec DTO
↓
CrossFieldValidator
↓
ProvenanceValidator
↓
ProfileCompatibilityValidator
↓
MarineVesselSemanticValidator
↓
PASS
```

Normalization:

```text
↓
Revalidation tất cả
↓
Canonical JSON
↓
SHA-256
↓
Freeze revision
```

Nếu Marine validator fail:

```text
ValidationError[]
↓
CanonicalValidationException
↓
ClaudeConceptRepairer
↓
repair exactly once
↓
full validation lại từ đầu
```

---

# 8.42 Một điều tôi muốn chỉnh nhẹ so với lời hứa trước về “cardinality”

Ta không nên cho category validator tự tính cardinality một cách mơ hồ.

Ví dụ:

```text
4 tiers
4 windows
```

không có nghĩa:

```text
one window per tier
```

Cardinality universal chỉ enforce khi canonical spec đã **explicitly declare**:

```json
{
  "type": "one_to_one",
  "source_path": "...",
  "target_path": "..."
}
```

hoặc:

```json
{
  "type": "count",
  ...
}
```

Do đó:

```text
declared cardinality
→ Core CrossFieldValidator

domain-derived mathematical relationship
→ Category validator
```

Đây là boundary an toàn hơn.

---

# 8.43 Về `length_to_beam_ratio`

Đây là ví dụ category semantic validator hoàn hảo vì:

```text
length
beam
ratio
```

đều explicit structured values.

Không cần AI inference.

Formula deterministic:

```text
ratio = length / beam
```

Tương lai tương tự:

```text
aircraft:
aspect_ratio nếu profile có
wingspan
wing_area

architecture:
floor_count
floor_to_floor_height
overall_height

road_vehicle:
wheelbase
overall_length
overhangs
```

Nhưng chỉ thêm validator khi schema đã có đủ operands.

---

# 8.44 Metadata cần lưu cùng frozen concept

Phần 8 không thay `CanonicalDesignSpec` schema.

Nhưng persistence metadata ngoài spec nên lưu:

```json
{
  "canonical_schema_version": "1.0",
  "profile_key": "marine_vessel",
  "profile_version": "1.0",
  "effective_schema_hash": "...",
  "semantic_validator_version": "1.0",
  "normalizer_version": "1.0",
  "canonicalizer_version": "1.0",
  "model": "claude-sonnet-5"
}
```

Tôi đặc biệt khuyên thêm:

```text
semantic_validator_version
```

vì một canonical JSON có thể:

```text
PASS validator v1
FAIL validator v2
```

sau khi bạn nâng rules.

Không thêm những metadata này vào locked `canonical_design_spec_v1.json`; lưu ở checkpoint/frozen record.

---

# 8.45 Version category validator

Không cần mỗi class có method phức tạp.

Có thể thêm interface:

```php
public function version(): string;
```

Tôi khuyên có.

Interface cuối:

```php
interface CategorySemanticValidator
{
    public function profileKey(): string;

    public function version(): string;

    public function validate(
        CanonicalDesignSpec $spec,
        ConceptInput $input,
    ): ValidationResult;
}
```

Marine:

```php
public function version(): string
{
    return '1.0';
}
```

Aircraft:

```php
public function version(): string
{
    return '1.0';
}
```

Registry có thể expose:

```php
$registry
    ->forProfile($profile)
    ->version();
```

để persistence ghi metadata.

Tôi chọn **thêm `version()` ngay từ V1** vì chi phí cực nhỏ nhưng traceability rất tốt.

---

# 8.46 Interface final tôi chốt

```php
<?php

declare(strict_types=1);

namespace App\Video\Profiles\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Validation\ValidationResult;

interface CategorySemanticValidator
{
    public function profileKey(): string;

    public function version(): string;

    public function validate(
        CanonicalDesignSpec $spec,
        ConceptInput $input,
    ): ValidationResult;
}
```

Mọi validator thêm:

```php
public function version(): string
{
    return '1.0';
}
```

---

# 8.47 Registry integrity có thể kiểm version compatibility

Nâng:

```php
public function forProfile(
    CategoryCreativeProfile $profile
): CategorySemanticValidator {
    $validator =
        $this->validators[
            $profile->key
        ]
        ?? null;

    if ($validator === null) {
        throw new RuntimeException(
            sprintf(
                'No semantic validator registered '
                . 'for profile %s@%s.',
                $profile->key,
                $profile->version,
            )
        );
    }

    /*
     * V1 policy:
     *
     * Semantic validator major/minor version
     * phải explicit, nhưng không bắt buộc bằng
     * profile version.
     *
     * Vì schema version và validator version
     * là hai lifecycle khác nhau.
     */

    return $validator;
}
```

Không bắt:

```text
profile version == validator version
```

vì hai thứ khác nhau.

---

# 8.48 Chốt Phần 8

Sau khi ghép Phần 8, architecture của Concept stage đã thành:

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
object_type
↓
CategoryCreativeProfileResolver
↓
CategoryCreativeProfile
↓
ConceptInput
↓
Core V1 + Profile Schema
↓
EffectiveConceptSchemaBuilder
↓
ClaudeSchemaAdapter
↓
Sonnet 5 Structured Output
↓
Canonical JSON
↓
Core Schema Validator
↓
Effective Schema Validator
↓
CanonicalDesignSpec DTO
↓
CanonicalCrossFieldValidator
↓
CanonicalProvenanceValidator
↓
ProfileCompatibilityValidator
↓
CategorySemanticValidatorRegistry
↓
Marine / Aircraft / Architecture / ...
↓
FAIL ───────────────→ Sonnet Repair ONCE
↓ PASS                        ↓
Normalize                 Full validate again
↓                             ↓
Core + Effective + Semantic  FAIL → fatal
re-validation
↓
Canonical JSON
↓
Hash
↓
Freeze
```

Và quan trọng hơn, ta vẫn giữ đúng separation:

```text
Canonical Core
    = universal design language

Category Profile
    = domain shape grammar

Category Semantic Validator
    = deterministic domain relationships

Sonnet
    = creative design + one repair

Python Compiler
    = downstream projection

Vision QA
    = kiểm output hình ảnh
```

Như vậy **không có domain rule marine chảy ngược vào Canonical Core**, và cũng không biến hệ thống thành hàng nghìn validator theo từng sản phẩm. Đây là điểm tôi xem là quan trọng nhất của Phần 8.
