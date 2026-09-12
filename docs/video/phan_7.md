Đây là **Phần 7 — `CategoryCreativeProfileRegistry` + `EffectiveConceptSchemaBuilder` + profile schemas**. Đây là phần giải quyết đúng “khoảng trống” giữa **Core V1 universal** và **schema đủ chặt để Sonnet 5 sinh structured output ổn định theo từng nhóm đối tượng**, nhưng vẫn tránh rơi vào kiểu “mỗi sản phẩm một prompt/schema”.

Mục tiêu của Phần 7 là:

```text
Canonical Core V1
        +
Category Profile Schema
        ↓
EffectiveConceptSchemaBuilder
        ↓
Effective Schema cho Sonnet 5
        ↓
Structured Output
        ↓
Local validation:
Core V1
+
Profile schema
+
Semantic validators
```

Điểm quan trọng nhất: **Core V1 không đổi.** Profile chỉ refine những vùng domain-open như:

```text
dimensions
permanent_geometry
form_relationships
finished_materials
```

và có thể thêm profile-specific semantic requirements, nhưng không được phá cấu trúc canonical gốc.

---

# 1. Cấu trúc thư mục sau Phần 7

```text
app/Video/
├── Concept/
│   ├── ...
│   ├── Schema/
│   │   ├── EffectiveConceptSchemaBuilder.php
│   │   ├── JsonSchemaMerger.php
│   │   └── EffectiveConceptSchema.php
│   │
│   └── Validation/
│       └── ...
│
└── Profiles/
    ├── CategoryCreativeProfile.php
    ├── CategoryCreativeProfileRegistry.php
    ├── CategoryCreativeProfileNotFound.php
    └── CategoryProfileSchemaProvider.php

resources/ai/schemas/
├── canonical_design_spec_v1.json
└── profiles/
    ├── generic_physical_object_v1.json
    ├── marine_vessel_v1.json
    ├── aircraft_v1.json
    ├── architecture_v1.json
    ├── road_vehicle_v1.json
    └── industrial_machine_v1.json
```

Tôi khuyên bắt đầu với 5–10 profile rộng, không làm hàng trăm profile.

---

# 2. `CategoryCreativeProfile.php`

Đây là metadata/runtime contract của một category profile.

```php
<?php

declare(strict_types=1);

namespace App\Video\Profiles;

use InvalidArgumentException;

final class CategoryCreativeProfile
{
    /**
     * @param list<string> $inspectionAspects
     */
    public function __construct(
        public readonly string $key,
        public readonly string $version,
        public readonly string $schemaPath,
        public readonly array $inspectionAspects,
    ) {
        $this->guard();
    }

    private function guard(): void
    {
        if (trim($this->key) === '') {
            throw new InvalidArgumentException(
                'CategoryCreativeProfile.key must not be empty.'
            );
        }

        if (trim($this->version) === '') {
            throw new InvalidArgumentException(
                'CategoryCreativeProfile.version must not be empty.'
            );
        }

        if (trim($this->schemaPath) === '') {
            throw new InvalidArgumentException(
                'CategoryCreativeProfile.schemaPath must not be empty.'
            );
        }

        if ($this->inspectionAspects === []) {
            throw new InvalidArgumentException(
                'CategoryCreativeProfile.inspectionAspects must not be empty.'
            );
        }

        foreach ($this->inspectionAspects as $index => $aspect) {
            if (
                !is_string($aspect)
                || trim($aspect) === ''
            ) {
                throw new InvalidArgumentException(
                    "inspectionAspects[{$index}] must be a non-empty string."
                );
            }
        }

        if (
            count($this->inspectionAspects)
            !== count(array_unique($this->inspectionAspects))
        ) {
            throw new InvalidArgumentException(
                'inspectionAspects must be unique.'
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'key' =>
                $this->key,

            'version' =>
                $this->version,

            'inspection_aspects' =>
                array_values(
                    $this->inspectionAspects
                ),
        ];
    }

    public function identifier(): string
    {
        return sprintf(
            '%s@%s',
            $this->key,
            $this->version
        );
    }
}
```

## Vai trò của class này

Nó không chứa geometry.

Nó chỉ mô tả:

```text
profile key
profile version
schema file
inspection aspects
```

Ví dụ marine:

```text
marine_vessel@1.0
```

có thể inspection:

```text
size_and_dimensions
form_and_proportions
primary_massing
bow_geometry
stern_geometry
openings
spatial_layout
materials
```

Không nên nhét vào class:

```php
if ($profile->key === 'marine_vessel') {
    ...
}
```

Các rule domain nên nằm trong:

```text
profile schema
profile semantic validator
```

---

# 3. `CategoryCreativeProfileNotFound.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Profiles;

use RuntimeException;

final class CategoryCreativeProfileNotFound
    extends RuntimeException
{
    public static function forKey(
        string $key
    ): self {
        return new self(
            "Category creative profile not found: {$key}"
        );
    }
}
```

---

# 4. `CategoryCreativeProfileRegistry.php`

Registry chịu trách nhiệm resolve profile theo key.

```php
<?php

declare(strict_types=1);

namespace App\Video\Profiles;

final class CategoryCreativeProfileRegistry
{
    /**
     * @var array<string,CategoryCreativeProfile>
     */
    private array $profiles = [];

    /**
     * @param list<CategoryCreativeProfile> $profiles
     */
    public function __construct(
        array $profiles
    ) {
        foreach ($profiles as $profile) {
            $this->register($profile);
        }
    }

    public function register(
        CategoryCreativeProfile $profile
    ): void {
        if (
            isset(
                $this->profiles[
                    $profile->key
                ]
            )
        ) {
            throw new \InvalidArgumentException(
                'Duplicate category creative profile key: '
                . $profile->key
            );
        }

        $this->profiles[
            $profile->key
        ] = $profile;
    }

    public function get(
        string $key
    ): CategoryCreativeProfile {
        $key = trim($key);

        if (
            $key === ''
            || !isset($this->profiles[$key])
        ) {
            throw CategoryCreativeProfileNotFound
                ::forKey($key);
        }

        return $this->profiles[$key];
    }

    public function has(
        string $key
    ): bool {
        return isset(
            $this->profiles[
                trim($key)
            ]
        );
    }

    /**
     * @return list<CategoryCreativeProfile>
     */
    public function all(): array
    {
        return array_values(
            $this->profiles
        );
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys =
            array_keys(
                $this->profiles
            );

        sort(
            $keys,
            SORT_STRING
        );

        return $keys;
    }
}
```

## Vì sao registry không tự map bằng `match`?

Không nên:

```php
return match ($key) {
    'marine_vessel' => ...,
    'aircraft' => ...,
};
```

Vì sau này thêm profile sẽ phải sửa code.

Registry dạng data-driven cho phép ServiceProvider đăng ký profile từ config.

---

# 5. `config/category_creative_profiles.php`

Đây là nơi khai báo profile rộng.

```php
<?php

declare(strict_types=1);

return [

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

];
```

---

# 6. ServiceProvider đăng ký Registry

Thêm vào `CanonicalConceptServiceProvider`.

```php
use App\Video\Profiles\CategoryCreativeProfile;
use App\Video\Profiles\CategoryCreativeProfileRegistry;
```

Binding:

```php
$this->app->singleton(
    CategoryCreativeProfileRegistry::class,
    function (): CategoryCreativeProfileRegistry {
        $config =
            config(
                'category_creative_profiles',
                []
            );

        if (!is_array($config)) {
            throw new RuntimeException(
                'category_creative_profiles config must be an array.'
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
                    'Invalid category creative profile configuration.'
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
                    "Invalid profile configuration: {$key}"
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

# 7. `CategoryProfileSchemaProvider.php`

Loader riêng cho profile schema.

```php
<?php

declare(strict_types=1);

namespace App\Video\Profiles;

use JsonException;
use RuntimeException;

final class CategoryProfileSchemaProvider
{
    /**
     * @var array<string,array<string,mixed>>
     */
    private array $cache = [];

    /**
     * @return array<string,mixed>
     */
    public function schema(
        CategoryCreativeProfile $profile
    ): array {
        $cacheKey =
            $profile->identifier();

        if (
            isset(
                $this->cache[
                    $cacheKey
                ]
            )
        ) {
            return $this->cache[
                $cacheKey
            ];
        }

        if (
            !is_file(
                $profile->schemaPath
            )
        ) {
            throw new RuntimeException(
                'Profile schema not found: '
                . $profile->schemaPath
            );
        }

        $json =
            file_get_contents(
                $profile->schemaPath
            );

        if ($json === false) {
            throw new RuntimeException(
                'Unable to read profile schema: '
                . $profile->schemaPath
            );
        }

        try {
            $schema =
                json_decode(
                    $json,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
        } catch (JsonException $e) {
            throw new RuntimeException(
                'Invalid profile schema JSON: '
                . $profile->schemaPath
                . ' — '
                . $e->getMessage(),
                previous:
                    $e,
            );
        }

        if (
            !is_array($schema)
            || array_is_list($schema)
        ) {
            throw new RuntimeException(
                'Profile schema root '
                . 'must be a JSON object.'
            );
        }

        return $this->cache[
            $cacheKey
        ] = $schema;
    }
}
```

---

# 8. Profile schema không nên là full copy của Core

Đây là điểm rất quan trọng.

Sai:

```text
marine_vessel_v1.json
=
copy nguyên canonical_design_spec_v1.json
+
sửa dimensions
+
sửa geometry
```

Làm vậy sau này Core thay đổi thì profile drift.

Đúng hơn: profile file chỉ chứa **refinements**.

Ví dụ:

```json
{
  "profile_key": "marine_vessel",
  "profile_version": "1.0",
  "refinements": {
    "dimensions": { ... },
    "permanent_geometry": { ... },
    "form_relationships": { ... },
    "finished_materials": { ... }
  }
}
```

Sau đó builder merge refinements vào Core.

---

# 9. `marine_vessel_v1.json`

Đây là profile schema minh họa production-friendly.

Không yacht-specific.

```json
{
  "profile_key": "marine_vessel",
  "profile_version": "1.0",

  "refinements": {

    "dimensions": {
      "type": "object",
      "additionalProperties": false,

      "required": [
        "length_m",
        "beam_m"
      ],

      "properties": {
        "length_m": {
          "type": "number",
          "exclusiveMinimum": 0
        },

        "beam_m": {
          "type": "number",
          "exclusiveMinimum": 0
        },

        "draft_m": {
          "type": "number",
          "exclusiveMinimum": 0
        },

        "overall_height_m": {
          "type": "number",
          "exclusiveMinimum": 0
        },

        "length_to_beam_ratio": {
          "type": "number",
          "exclusiveMinimum": 0
        }
      }
    },

    "permanent_geometry": {
      "type": "object",
      "additionalProperties": false,

      "required": [
        "hull",
        "bow",
        "stern",
        "superstructure"
      ],

      "properties": {

        "hull": {
          "type": "object",
          "additionalProperties": false,

          "required": [
            "type",
            "sheer"
          ],

          "properties": {
            "type": {
              "type": "string"
            },

            "sheer": {
              "type": "string"
            },

            "chine": {
              "type": "string"
            },

            "keel": {
              "type": "string"
            },

            "midbody": {
              "type": "string"
            }
          }
        },

        "bow": {
          "type": "object",
          "additionalProperties": false,

          "required": [
            "stem",
            "waterline_entry"
          ],

          "properties": {
            "stem": {
              "type": "string"
            },

            "rake_degrees": {
              "type": "number"
            },

            "waterline_entry": {
              "type": "string"
            },

            "forefoot": {
              "type": "string"
            }
          }
        },

        "stern": {
          "type": "object",
          "additionalProperties": false,

          "required": [
            "type"
          ],

          "properties": {
            "type": {
              "type": "string"
            },

            "transom_form": {
              "type": "string"
            },

            "platform_geometry": {
              "type": "string"
            }
          }
        },

        "superstructure": {
          "type": "object",
          "additionalProperties": false,

          "required": [
            "primary_tier_count",
            "massing"
          ],

          "properties": {
            "primary_tier_count": {
              "type": "integer",
              "minimum": 1
            },

            "massing": {
              "type": "string"
            },

            "tier_progression": {
              "type": "string"
            },

            "longitudinal_extent": {
              "type": "string"
            }
          }
        },

        "openings": {
          "type": "object",
          "additionalProperties": false,

          "properties": {
            "window_strategy": {
              "type": "string"
            },

            "door_strategy": {
              "type": "string"
            },

            "hull_openings": {
              "type": "string"
            }
          }
        }
      }
    },

    "form_relationships": {
      "type": "object",
      "additionalProperties": false,

      "properties": {
        "hull_to_superstructure": {
          "type": "string"
        },

        "tier_progression": {
          "type": "string"
        },

        "bow_to_midbody": {
          "type": "string"
        },

        "midbody_to_stern": {
          "type": "string"
        }
      }
    },

    "finished_materials": {
      "type": "object",
      "additionalProperties": false,

      "properties": {
        "hull": {
          "type": "string"
        },

        "superstructure": {
          "type": "string"
        },

        "glazing": {
          "type": "string"
        },

        "exterior_decking": {
          "type": "string"
        }
      }
    }
  }
}
```

## Vì sao schema này không có yacht-specific field?

Không có:

```text
beach_club
pool
helipad
owner_deck
spa
cinema
```

vì những thứ đó là feature-specific, không phải category-level universal vessel structure.

Nếu design cần pool, nó vẫn có thể xuất hiện ở một profile extension tương lai hoặc generic geometry field strategy. Nhưng không nên làm profile thành catalogue feature.

---

# 10. `aircraft_v1.json`

```json
{
  "profile_key": "aircraft",
  "profile_version": "1.0",

  "refinements": {

    "dimensions": {
      "type": "object",
      "additionalProperties": false,

      "required": [
        "length_m",
        "wingspan_m"
      ],

      "properties": {
        "length_m": {
          "type": "number",
          "exclusiveMinimum": 0
        },

        "wingspan_m": {
          "type": "number",
          "exclusiveMinimum": 0
        },

        "height_m": {
          "type": "number",
          "exclusiveMinimum": 0
        }
      }
    },

    "permanent_geometry": {
      "type": "object",
      "additionalProperties": false,

      "required": [
        "fuselage",
        "wing",
        "tail"
      ],

      "properties": {

        "fuselage": {
          "type": "object",
          "additionalProperties": false,

          "required": [
            "cross_section",
            "nose_geometry"
          ],

          "properties": {
            "cross_section": {
              "type": "string"
            },

            "nose_geometry": {
              "type": "string"
            },

            "aft_taper": {
              "type": "string"
            }
          }
        },

        "wing": {
          "type": "object",
          "additionalProperties": false,

          "required": [
            "planform",
            "mount_position"
          ],

          "properties": {
            "planform": {
              "type": "string"
            },

            "mount_position": {
              "type": "string"
            },

            "sweep": {
              "type": "string"
            },

            "tip_geometry": {
              "type": "string"
            }
          }
        },

        "engine_configuration": {
          "type": "object",
          "additionalProperties": false,

          "properties": {
            "count": {
              "type": "integer",
              "minimum": 0
            },

            "mounting": {
              "type": "string"
            },

            "integration": {
              "type": "string"
            }
          }
        },

        "tail": {
          "type": "object",
          "additionalProperties": false,

          "required": [
            "configuration"
          ],

          "properties": {
            "configuration": {
              "type": "string"
            },

            "vertical_surface": {
              "type": "string"
            },

            "horizontal_surface": {
              "type": "string"
            }
          }
        },

        "landing_configuration": {
          "type": "object",
          "additionalProperties": false,

          "properties": {
            "type": {
              "type": "string"
            },

            "primary_gear_layout": {
              "type": "string"
            }
          }
        }
      }
    },

    "form_relationships": {
      "type": "object",
      "additionalProperties": false,

      "properties": {
        "wing_to_fuselage": {
          "type": "string"
        },

        "tail_to_fuselage": {
          "type": "string"
        },

        "engine_to_airframe": {
          "type": "string"
        }
      }
    },

    "finished_materials": {
      "type": "object",
      "additionalProperties": false,

      "properties": {
        "primary_skin": {
          "type": "string"
        },

        "glazing": {
          "type": "string"
        },

        "leading_surfaces": {
          "type": "string"
        }
      }
    }
  }
}
```

---

# 11. `architecture_v1.json`

```json
{
  "profile_key": "architecture",
  "profile_version": "1.0",

  "refinements": {

    "dimensions": {
      "type": "object",
      "additionalProperties": false,

      "properties": {
        "overall_length_m": {
          "type": "number",
          "exclusiveMinimum": 0
        },

        "overall_width_m": {
          "type": "number",
          "exclusiveMinimum": 0
        },

        "overall_height_m": {
          "type": "number",
          "exclusiveMinimum": 0
        },

        "floor_count": {
          "type": "integer",
          "minimum": 1
        }
      }
    },

    "permanent_geometry": {
      "type": "object",
      "additionalProperties": false,

      "required": [
        "primary_massing",
        "facade",
        "roof"
      ],

      "properties": {

        "primary_massing": {
          "type": "object",
          "additionalProperties": false,

          "required": [
            "organization"
          ],

          "properties": {
            "organization": {
              "type": "string"
            },

            "volume_count": {
              "type": "integer",
              "minimum": 1
            },

            "vertical_progression": {
              "type": "string"
            }
          }
        },

        "facade": {
          "type": "object",
          "additionalProperties": false,

          "properties": {
            "primary_geometry": {
              "type": "string"
            },

            "opening_pattern": {
              "type": "string"
            },

            "depth_strategy": {
              "type": "string"
            }
          }
        },

        "circulation": {
          "type": "object",
          "additionalProperties": false,

          "properties": {
            "primary_vertical": {
              "type": "string"
            },

            "primary_horizontal": {
              "type": "string"
            }
          }
        },

        "roof": {
          "type": "object",
          "additionalProperties": false,

          "required": [
            "geometry"
          ],

          "properties": {
            "geometry": {
              "type": "string"
            },

            "edge_condition": {
              "type": "string"
            }
          }
        }
      }
    },

    "form_relationships": {
      "type": "object",
      "additionalProperties": false,

      "properties": {
        "base_to_upper_mass": {
          "type": "string"
        },

        "facade_to_structure": {
          "type": "string"
        },

        "circulation_to_massing": {
          "type": "string"
        }
      }
    },

    "finished_materials": {
      "type": "object",
      "additionalProperties": false,

      "properties": {
        "primary_facade": {
          "type": "string"
        },

        "secondary_facade": {
          "type": "string"
        },

        "glazing": {
          "type": "string"
        },

        "roof": {
          "type": "string"
        }
      }
    }
  }
}
```

---

# 12. `generic_physical_object_v1.json`

Fallback này rất quan trọng.

Không nên throw chỉ vì chưa có category-specific profile.

```json
{
  "profile_key": "generic_physical_object",
  "profile_version": "1.0",

  "refinements": {

    "dimensions": {
      "type": "object",
      "additionalProperties": false,

      "properties": {
        "overall_length": {
          "type": "number"
        },

        "overall_width": {
          "type": "number"
        },

        "overall_height": {
          "type": "number"
        },

        "dimension_unit": {
          "type": "string"
        }
      }
    },

    "permanent_geometry": {
      "type": "object",
      "additionalProperties": false,

      "required": [
        "primary_form"
      ],

      "properties": {
        "primary_form": {
          "type": "string"
        },

        "secondary_forms": {
          "type": "array",
          "items": {
            "type": "string"
          }
        },

        "openings": {
          "type": "string"
        },

        "structural_expression": {
          "type": "string"
        }
      }
    },

    "form_relationships": {
      "type": "object",
      "additionalProperties": false,

      "properties": {
        "primary_to_secondary": {
          "type": "string"
        },

        "overall_balance": {
          "type": "string"
        }
      }
    },

    "finished_materials": {
      "type": "object",
      "additionalProperties": false,

      "properties": {
        "primary": {
          "type": "string"
        },

        "secondary": {
          "type": "string"
        }
      }
    }
  }
}
```

Fallback phải đủ generic nhưng vẫn **closed** để structured output hoạt động ổn định.

---

# 13. `EffectiveConceptSchema.php`

Thay vì trả array thẳng, tôi khuyên có DTO chứa metadata.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Schema;

final class EffectiveConceptSchema
{
    /**
     * @param array<string,mixed> $schema
     */
    public function __construct(
        public readonly string $coreVersion,
        public readonly string $profileKey,
        public readonly string $profileVersion,
        public readonly array $schema,
    ) {
    }

    public function identifier(): string
    {
        return sprintf(
            'canonical@%s+%s@%s',
            $this->coreVersion,
            $this->profileKey,
            $this->profileVersion,
        );
    }
}
```

Ví dụ:

```text
canonical@1.0+marine_vessel@1.0
```

Rất hữu ích để persist cùng artifact.

---

# 14. Không nên generic-deep-merge mọi JSON Schema

JSON Schema merge rất nguy hiểm.

Ví dụ:

```text
required
oneOf
properties
$defs
```

không thể cứ recursive merge vô điều kiện.

V1 của bạn có lợi thế: profile chỉ được refine **4 root properties cụ thể**.

Do đó builder nên strict.

Không cần generic “magic merger” quá thông minh.

---

# 15. `EffectiveConceptSchemaBuilder.php`

Đây là file chính của Phase 7.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Schema;

use App\Video\Concept\Claude\CanonicalSchemaProvider;
use App\Video\Profiles\CategoryCreativeProfile;
use App\Video\Profiles\CategoryProfileSchemaProvider;
use RuntimeException;

final class EffectiveConceptSchemaBuilder
{
    /**
     * Profile chỉ được refine những field này
     * trong Canonical Core V1.
     *
     * @var list<string>
     */
    private const REFINABLE_ROOT_FIELDS = [
        'dimensions',
        'permanent_geometry',
        'form_relationships',
        'finished_materials',
    ];

    public function __construct(
        private readonly CanonicalSchemaProvider $coreSchemaProvider,
        private readonly CategoryProfileSchemaProvider $profileSchemaProvider,
    ) {
    }

    public function build(
        CategoryCreativeProfile $profile
    ): EffectiveConceptSchema {
        $core =
            $this->coreSchemaProvider
                ->schema();

        $profileSchema =
            $this->profileSchemaProvider
                ->schema(
                    $profile
                );

        $this->assertProfileMetadata(
            $profile,
            $profileSchema
        );

        $refinements =
            $profileSchema['refinements']
            ?? null;

        if (
            !is_array($refinements)
            || array_is_list($refinements)
        ) {
            throw new RuntimeException(
                'Profile schema refinements '
                . 'must be a JSON object.'
            );
        }

        $this->assertOnlyAllowedRefinements(
            $refinements
        );

        if (
            !isset($core['properties'])
            || !is_array(
                $core['properties']
            )
        ) {
            throw new RuntimeException(
                'Canonical core schema '
                . 'has no properties object.'
            );
        }

        foreach (
            self::REFINABLE_ROOT_FIELDS
            as $field
        ) {
            if (
                !array_key_exists(
                    $field,
                    $refinements
                )
            ) {
                throw new RuntimeException(
                    sprintf(
                        'Profile %s must refine "%s".',
                        $profile->identifier(),
                        $field
                    )
                );
            }

            $refinement =
                $refinements[$field];

            if (
                !is_array($refinement)
                || array_is_list($refinement)
            ) {
                throw new RuntimeException(
                    "Profile refinement {$field} "
                    . 'must be a JSON object.'
                );
            }

            $this->assertClosedObjectSchema(
                field:
                    $field,

                schema:
                    $refinement,
            );

            /*
             * Important:
             *
             * REPLACE Core's intentionally-open
             * domain field schema with the
             * profile's explicit refinement.
             *
             * Không generic deep merge.
             */
            $core['properties'][$field] =
                $refinement;
        }

        return new EffectiveConceptSchema(
            coreVersion:
                $this->coreVersion($core),

            profileKey:
                $profile->key,

            profileVersion:
                $profile->version,

            schema:
                $core,
        );
    }

    /**
     * @param array<string,mixed> $profileSchema
     */
    private function assertProfileMetadata(
        CategoryCreativeProfile $profile,
        array $profileSchema
    ): void {
        $key =
            $profileSchema['profile_key']
            ?? null;

        $version =
            $profileSchema['profile_version']
            ?? null;

        if (
            $key !== $profile->key
        ) {
            throw new RuntimeException(
                sprintf(
                    'Profile schema key mismatch. '
                    . 'Registry=%s Schema=%s',
                    $profile->key,
                    (string) $key
                )
            );
        }

        if (
            $version !== $profile->version
        ) {
            throw new RuntimeException(
                sprintf(
                    'Profile schema version mismatch. '
                    . 'Registry=%s Schema=%s',
                    $profile->version,
                    (string) $version
                )
            );
        }
    }

    /**
     * @param array<string,mixed> $refinements
     */
    private function assertOnlyAllowedRefinements(
        array $refinements
    ): void {
        foreach (
            array_keys($refinements)
            as $field
        ) {
            if (
                !in_array(
                    $field,
                    self::REFINABLE_ROOT_FIELDS,
                    true
                )
            ) {
                throw new RuntimeException(
                    'Profile attempts to refine '
                    . 'a protected canonical field: '
                    . $field
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $schema
     */
    private function assertClosedObjectSchema(
        string $field,
        array $schema
    ): void {
        if (
            ($schema['type'] ?? null)
            !== 'object'
        ) {
            throw new RuntimeException(
                "{$field} refinement "
                . 'must have type=object.'
            );
        }

        if (
            ($schema['additionalProperties']
                ?? null)
            !== false
        ) {
            throw new RuntimeException(
                "{$field} refinement "
                . 'must set '
                . 'additionalProperties=false.'
            );
        }

        if (
            !isset($schema['properties'])
            || !is_array(
                $schema['properties']
            )
        ) {
            throw new RuntimeException(
                "{$field} refinement "
                . 'must define properties.'
            );
        }
    }

    /**
     * @param array<string,mixed> $core
     */
    private function coreVersion(
        array $core
    ): string {
        $schemaVersion =
            $core['properties']
                ['schema_version']
                ['const']
            ?? null;

        if (!is_string($schemaVersion)) {
            throw new RuntimeException(
                'Unable to determine '
                . 'canonical core version.'
            );
        }

        return $schemaVersion;
    }
}
```

---

# 16. Vì sao builder dùng REPLACE thay vì merge?

Core có:

```json
"dimensions": {
  "type": "object",
  "description": "Domain-specific measurable dimensions..."
}
```

Profile có:

```json
"dimensions": {
  "type": "object",
  "additionalProperties": false,
  "required": ["length_m", "beam_m"],
  "properties": { ... }
}
```

Profile không “mở rộng” field theo kiểu loose.

Nó **refine complete schema** cho field đó.

Vì vậy:

```php
$core['properties']['dimensions'] =
    $refinement;
```

là dễ hiểu và an toàn hơn.

---

# 17. Profile không được sửa Core fields khác

Ví dụ marine profile cố:

```json
{
  "refinements": {
    "schema_version": {
      "const": "9.0"
    }
  }
}
```

builder sẽ throw:

```text
Profile attempts to refine a protected canonical field
```

Tương tự profile không được sửa:

```text
relationships
invariants
provenance
identity
exclusions
```

Những phần này là universal contract.

---

# 18. `ClaudeSchemaAdapter` phải thay đổi

Sau Phase 7, adapter không còn nên “im lặng” trước unresolved open objects.

Provider schema phải là **effective schema**.

Nếu nó còn gặp domain-open object unresolved, đó là programming/configuration error.

Tôi khuyên nâng adapter.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Claude;

use RuntimeException;

final class ClaudeSchemaAdapter
{
    /**
     * @var list<string>
     */
    private const REMOVED_KEYWORDS = [
        'minimum',
        'maximum',
        'exclusiveMinimum',
        'exclusiveMaximum',
        'multipleOf',

        'minLength',
        'maxLength',

        'minItems',
        'maxItems',
        'uniqueItems',

        'minProperties',
        'maxProperties',
    ];

    /**
     * @param array<string,mixed> $effectiveSchema
     *
     * @return array<string,mixed>
     */
    public function adapt(
        array $effectiveSchema
    ): array {
        /*
         * Trước khi projection,
         * đảm bảo không còn các domain-open
         * Core objects chưa được profile refine.
         */
        $this->assertResolvedDomainObjects(
            $effectiveSchema
        );

        $adapted =
            $this->walk(
                $effectiveSchema
            );

        /*
         * $schema metadata không cần
         * gửi provider.
         */
        unset(
            $adapted['$schema']
        );

        return $adapted;
    }

    /**
     * @param array<string,mixed> $schema
     */
    private function assertResolvedDomainObjects(
        array $schema
    ): void {
        $properties =
            $schema['properties']
            ?? null;

        if (!is_array($properties)) {
            throw new RuntimeException(
                'Effective schema properties '
                . 'are missing.'
            );
        }

        foreach (
            [
                'dimensions',
                'permanent_geometry',
                'form_relationships',
                'finished_materials',
            ]
            as $field
        ) {
            $node =
                $properties[$field]
                ?? null;

            if (
                !is_array($node)
                || ($node['type'] ?? null)
                    !== 'object'
            ) {
                throw new RuntimeException(
                    "Effective schema {$field} "
                    . 'is not a valid object schema.'
                );
            }

            if (
                ($node['additionalProperties']
                    ?? null)
                !== false
            ) {
                throw new RuntimeException(
                    "Effective schema {$field} "
                    . 'is still domain-open. '
                    . 'A category profile must '
                    . 'resolve it before sending '
                    . 'structured output to Claude.'
                );
            }

            if (
                !isset($node['properties'])
                || !is_array(
                    $node['properties']
                )
            ) {
                throw new RuntimeException(
                    "Effective schema {$field} "
                    . 'does not define properties.'
                );
            }
        }
    }

    private function walk(
        mixed $value
    ): mixed {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $child): mixed =>
                    $this->walk($child),

                $value
            );
        }

        $result = [];

        foreach (
            $value as $key => $child
        ) {
            if (
                in_array(
                    $key,
                    self::REMOVED_KEYWORDS,
                    true
                )
            ) {
                continue;
            }

            $result[$key] =
                $this->walk($child);
        }

        return $result;
    }
}
```

Đây là thay đổi rất quan trọng.

Sai trước đây:

```text
Core open object
↓
adapter tự thêm additionalProperties:false
↓
object rỗng
```

Đúng bây giờ:

```text
Core open object
↓
Profile resolves it
↓
Effective schema closed object
↓
Adapter
```

---

# 19. `ClaudeConceptDesigner` đổi sang Effective Schema

Constructor:

```php
use App\Video\Concept\Schema\EffectiveConceptSchemaBuilder;
```

```php
public function __construct(
    private readonly StructuredOutputLlmClient $client,
    private readonly EffectiveConceptSchemaBuilder $schemaBuilder,
    private readonly ClaudeSchemaAdapter $schemaAdapter,
    private readonly CanonicalJsonPayloadFactory $payloadFactory,
    private readonly string $model,
    private readonly string $systemPrompt,
    private readonly int $maxTokens = 8192,
    private readonly string $effort = 'high',
) {
}
```

Generate:

```php
public function generate(
    ConceptInput $input
): CanonicalJsonPayload {
    $effective =
        $this->schemaBuilder
            ->build(
                $input->profile
            );

    $providerSchema =
        $this->schemaAdapter
            ->adapt(
                $effective->schema
            );

    $response =
        $this->client
            ->create(
                model:
                    $this->model,

                system:
                    $this->systemPrompt,

                messages: [
                    [
                        'role' =>
                            'user',

                        'content' =>
                            $this->encodeInput(
                                $input,
                                $effective->identifier()
                            ),
                    ],
                ],

                outputSchema:
                    $providerSchema,

                maxTokens:
                    $this->maxTokens,

                effort:
                    $this->effort,
            );

    return $this->payloadFactory
        ->create(
            $response->rawText
        );
}
```

Input payload nên ghi:

```php
private function encodeInput(
    ConceptInput $input,
    string $effectiveSchemaId
): string {
    return json_encode(
        [
            'task' =>
                'Create one original canonical physical design.',

            'effective_schema_id' =>
                $effectiveSchemaId,

            'concept_input' =>
                $input->toArray(),
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
        | JSON_THROW_ON_ERROR
    );
}
```

---

# 20. Repairer cũng phải dùng đúng cùng Effective Schema

Đây là bắt buộc.

Không được:

```text
Designer
→ marine effective schema

Repairer
→ core schema
```

Repair phải dùng chính schema family ban đầu.

```php
$effective =
    $this->schemaBuilder
        ->build(
            $input->profile
        );

$providerSchema =
    $this->schemaAdapter
        ->adapt(
            $effective->schema
        );
```

Như vậy initial và repair cùng contract:

```text
canonical@1.0+marine_vessel@1.0
```

---

# 21. Local validation cũng phải validate profile schema

Hiện Phase 3 mới:

```text
Core JSON Schema
↓
Semantic Validator
```

Sau Phase 7 phải là:

```text
Core Schema
+
Effective/Profile Schema
↓
Semantic Validator
```

Vì nếu Sonnet trả:

```json
{
  "dimensions": {
    "length_m": 90,
    "banana": "hello"
  }
}
```

Core V1 vẫn chấp nhận vì `dimensions` domain-open.

Nhưng marine profile phải reject `banana`.

---

# 22. `EffectiveSchemaValidator.php`

Có thể dùng cùng Opis validator.

```php
<?php

declare(strict_types=1);

namespace App\Video\Concept\Validation;

use App\Video\Concept\Schema\EffectiveConceptSchema;
use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

final class EffectiveSchemaValidator
{
    public function validate(
        string $rawJson,
        EffectiveConceptSchema $effective
    ): ValidationResult {
        try {
            $data =
                json_decode(
                    $rawJson,
                    associative: false,
                    depth: 512,
                    flags:
                        JSON_THROW_ON_ERROR
                );
        } catch (JsonException $e) {
            return ValidationResult::invalid([
                new ValidationError(
                    code:
                        'invalid_json',

                    path:
                        '$',

                    message:
                        $e->getMessage(),
                ),
            ]);
        }

        /*
         * Convert schema array back to object-mode
         * để Opis thấy JSON object/list chính xác.
         */
        $schemaJson =
            json_encode(
                $effective->schema,
                JSON_THROW_ON_ERROR
            );

        $schema =
            json_decode(
                $schemaJson,
                associative: false,
                depth: 512,
                flags:
                    JSON_THROW_ON_ERROR
            );

        $validator =
            new Validator();

        $validator->setMaxErrors(100);
        $validator->setStopAtFirstError(false);

        $result =
            $validator->validate(
                $data,
                $schema
            );

        if ($result->isValid()) {
            return ValidationResult::valid();
        }

        $root =
            $result->error();

        if ($root === null) {
            return ValidationResult::invalid([
                new ValidationError(
                    code:
                        'effective_schema',

                    path:
                        '$',

                    message:
                        'Effective schema '
                        . 'validation failed.',
                ),
            ]);
        }

        $formatter =
            new ErrorFormatter();

        $flat =
            $formatter->formatFlat(
                $root,
                static function (
                    $error
                ) use (
                    $formatter
                ): array {
                    return [
                        'keyword' =>
                            $error->keyword(),

                        'message' =>
                            $formatter
                                ->formatErrorMessage(
                                    $error
                                ),

                        'path' =>
                            $formatter
                                ->formatErrorKey(
                                    $error
                                ),

                        'actual' =>
                            $error
                                ->data()
                                ->value(),
                    ];
                }
            );

        $errors = [];

        foreach ($flat as $item) {
            if (!is_array($item)) {
                continue;
            }

            $errors[] =
                new ValidationError(
                    code:
                        'effective_schema.'
                        . (
                            $item['keyword']
                            ?? 'unknown'
                        ),

                    path:
                        (string) (
                            $item['path']
                            ?? '$'
                        ),

                    message:
                        (string) (
                            $item['message']
                            ?? 'Effective schema validation failed.'
                        ),

                    actual:
                        $item['actual']
                        ?? null,
                );
        }

        return ValidationResult::invalid(
            $errors
        );
    }
}
```

---

# 23. Processor sau Phase 7

Flow đúng phải là:

```text
raw JSON
↓
Core schema validation
↓
Effective profile schema validation
↓
DTO hydrate
↓
Semantic validation
↓
Normalize
↓
serialize
↓
Core validation again
↓
Effective validation again
↓
Semantic validation again
```

Tức:

```text
Core
= universal safety contract

Effective
= category shape contract

Semantic
= cross-field meaning contract
```

Ba lớp khác nhau.

---

# 24. `CanonicalConceptProcessor` bản Phase 7

Phần constructor:

```php
public function __construct(
    private readonly CanonicalSchemaValidator $coreSchemaValidator,
    private readonly EffectiveSchemaValidator $effectiveSchemaValidator,
    private readonly EffectiveConceptSchemaBuilder $schemaBuilder,
    private readonly CanonicalDesignSpecValidator $semanticValidator,
    private readonly CanonicalDesignSpecNormalizer $normalizer,
    private readonly CanonicalDesignSpecSerializer $serializer,
) {
}
```

Process:

```php
public function process(
    string $rawJson,
    ConceptInput $input
): CanonicalDesignSpec {
    /*
     * Build exactly one effective schema
     * from selected profile.
     */
    $effective =
        $this->schemaBuilder
            ->build(
                $input->profile
            );

    /*
     * 1. Universal Core.
     */
    $this->coreSchemaValidator
        ->validateJsonOrFail(
            $rawJson
        );

    /*
     * 2. Category-specific effective schema.
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
                'Effective profile schema validation failed.'
        );
    }

    /*
     * 3. Hydration.
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
    } catch (\Throwable $e) {
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
     * 4. Semantic validation.
     */
    $semantic =
        $this->semanticValidator
            ->validate(
                $spec,
                $input->inspiration
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
     * 5. Normalize.
     */
    $normalized =
        $this->normalizer
            ->normalize(
                $spec
            );

    /*
     * 6. Serialize with canonical JSON types.
     */
    $normalizedJson =
        $this->serializer
            ->toJson(
                $normalized
            );

    /*
     * 7. Core revalidation.
     */
    $this->coreSchemaValidator
        ->validateJsonOrFail(
            $normalizedJson
        );

    /*
     * 8. Effective schema revalidation.
     */
    $effectiveAfterNormalize =
        $this->effectiveSchemaValidator
            ->validate(
                $normalizedJson,
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
                $normalizedJson,

            message:
                'Normalized canonical design '
                . 'failed effective schema validation.'
        );
    }

    /*
     * 9. Semantic revalidation.
     */
    $semanticAfterNormalize =
        $this->semanticValidator
            ->validate(
                $normalized,
                $input->inspiration
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
                $normalizedJson,

            message:
                'Normalized canonical design '
                . 'failed semantic validation.'
        );
    }

    return $normalized;
}
```

Lưu ý: signature giờ nên nhận:

```php
ConceptInput $input
```

thay vì chỉ:

```php
InspirationBrief $brief
```

vì processor cần profile.

---

# 25. `BuildCanonicalConcept` cũng đổi signature gọi processor

Initial:

```php
$normalized =
    $this->processor
        ->process(
            rawJson:
                $initial->rawJson,

            input:
                $input,
        );
```

Repair:

```php
$normalized =
    $this->processor
        ->process(
            rawJson:
                $repaired->rawJson,

            input:
                $input,
        );
```

Như vậy profile xuyên suốt:

```text
ConceptInput
↓
Designer schema
↓
Processor schema
↓
Repairer schema
```

không có nguy cơ mismatch.

---

# 26. Object type và profile key có bắt buộc giống nhau không?

Không nên bắt buộc 100% ở Core.

Ví dụ:

```text
object_type = superyacht
profile = marine_vessel
```

là hợp lý.

Hoặc:

```text
object_type = passenger_aircraft
profile = aircraft
```

Do đó:

```text
object_type
= subject taxonomy

profile
= validation/design category
```

Không nên viết:

```php
if ($input->objectType !== $input->profile->key) {
    fail;
}
```

Nhưng bạn có thể có một resolver mapping:

```text
superyacht
cargo_ship
ferry
naval_vessel
→ marine_vessel

sedan
truck
bus
supercar
→ road_vehicle
```

---

# 27. `CategoryCreativeProfileResolver`

Đây là optional nhưng tôi khuyên thêm.

```php
<?php

declare(strict_types=1);

namespace App\Video\Profiles;

final class CategoryCreativeProfileResolver
{
    /**
     * @var array<string,string>
     */
    private array $mapping;

    /**
     * @param array<string,string> $mapping
     */
    public function __construct(
        private readonly CategoryCreativeProfileRegistry $registry,
        array $mapping = [],
    ) {
        $this->mapping =
            $mapping;
    }

    public function resolve(
        string $objectType
    ): CategoryCreativeProfile {
        $objectType =
            trim(
                $objectType
            );

        $profileKey =
            $this->mapping[
                $objectType
            ]
            ?? (
                $this->registry
                    ->has($objectType)
                    ? $objectType
                    : 'generic_physical_object'
            );

        return $this->registry
            ->get(
                $profileKey
            );
    }
}
```

Config:

```php
'object_type_map' => [
    'superyacht' =>
        'marine_vessel',

    'motor_yacht' =>
        'marine_vessel',

    'cargo_ship' =>
        'marine_vessel',

    'passenger_aircraft' =>
        'aircraft',

    'business_jet' =>
        'aircraft',

    'sedan' =>
        'road_vehicle',

    'truck' =>
        'road_vehicle',

    'villa' =>
        'architecture',

    'office_tower' =>
        'architecture',
],
```

Nếu không map được:

```text
generic_physical_object
```

Điều này giúp hệ thống scale lên hàng nghìn object types mà không cần hàng nghìn schemas.

---

# 28. Đây là điểm scale quan trọng nhất

Giả sử bạn có 10.000 topic/product/object types.

Không cần:

```text
10.000 profile schemas
```

Có thể chỉ cần:

```text
15–30 broad profiles
```

Ví dụ:

```text
marine_vessel
aircraft
road_vehicle
rail_vehicle
architecture
industrial_machine
robotics
furniture
consumer_product
spacecraft
infrastructure
agricultural_machine
medical_device
electronic_device
generic_physical_object
```

Sau đó:

```text
object_type
→ broad profile
```

Concept Designer vẫn invent subject-specific geometry.

Profile chỉ cung cấp **grammar of design**, không cung cấp design.

---

# 29. Không biến Profile Schema thành prompt

Ví dụ không nên schema hóa mọi creative detail:

```json
{
  "bow_style": {
    "enum": [
      "plumb",
      "raked",
      "axe",
      "clipper"
    ]
  }
}
```

Nếu enum quá đóng, Sonnet chỉ remix catalogue.

Tốt hơn:

```json
{
  "bow": {
    "properties": {
      "stem": {
        "type": "string"
      }
    }
  }
}
```

Structured output giữ shape ổn định, nhưng creative vocabulary vẫn mở.

Đây là balance tốt:

```text
structure closed
values creative
```

---

# 30. Profile schema có nên cho `additionalProperties=true` ở nested object?

Trong production structured output, tôi nghiêng về:

```text
false
```

cho các nested object được định nghĩa.

Lý do:

```text
predictability
validation
prompt compiler stability
QA mapping
```

Nhưng để không bóp creativity, giữ nhiều value là:

```text
string
number
```

không enum trừ khi đó là thật sự semantic primitive.

---

# 31. Profile-specific semantic validator

Schema chỉ kiểm shape.

Marine còn cần logic:

```text
length > 0
beam > 0
ratio consistency
tier count consistency
```

Một số minimum đã có schema, nhưng derived semantics cần validator.

Tôi khuyên interface:

```php
<?php

declare(strict_types=1);

namespace App\Video\Profiles\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Validation\ValidationResult;

interface CategorySemanticValidator
{
    public function profileKey(): string;

    public function validate(
        CanonicalDesignSpec $spec
    ): ValidationResult;
}
```

Marine:

```php
final class MarineVesselSemanticValidator
    implements CategorySemanticValidator
{
    public function profileKey(): string
    {
        return 'marine_vessel';
    }

    public function validate(
        CanonicalDesignSpec $spec
    ): ValidationResult {
        $errors = [];

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
            is_numeric($length)
            && is_numeric($beam)
            && is_numeric($ratio)
            && (float) $beam > 0.0
        ) {
            $computed =
                (float) $length
                / (float) $beam;

            if (
                abs(
                    $computed
                    - (float) $ratio
                ) > 0.05
            ) {
                $errors[] =
                    new ValidationError(
                        code:
                            'marine.length_to_beam_ratio_mismatch',

                        path:
                            'dimensions.length_to_beam_ratio',

                        message:
                            'Declared length_to_beam_ratio '
                            . 'does not match length_m / beam_m.',

                        expected:
                            $computed,

                        actual:
                            $ratio,
                    );
            }
        }

        return new ValidationResult(
            $errors
        );
    }
}
```

Nhưng lưu ý: đây là **Phần 8 hợp lý hơn**. Phase 7 tập trung schema/profile.

---

# 32. Effective Schema phải được persist metadata

Mỗi frozen concept nên biết nó được generate/validate bằng:

```text
canonical_core_version
profile_key
profile_version
effective_schema_hash
```

Tôi khuyên hash effective schema.

Ví dụ:

```php
$effectiveHash =
    hash(
        'sha256',
        json_encode(
            $this->sortKeysRecursively(
                $effective->schema
            ),
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        )
    );
```

DB metadata:

```json
{
  "canonical_schema": "1.0",
  "profile": "marine_vessel",
  "profile_version": "1.0",
  "effective_schema_hash": "..."
}
```

Khi 6 tháng sau regenerate, bạn biết chính xác schema nào đã tạo design cũ.

---

# 33. Không mutate profile version

Nếu thay:

```text
marine_vessel_v1.json
```

theo cách làm output contract khác đi đáng kể, không nên giữ:

```text
version=1.0
```

Hãy tạo:

```text
marine_vessel_v1_1.json
```

hoặc:

```text
marine_vessel_v2.json
```

depending breaking/non-breaking policy.

Tôi khuyên:

```text
profile semantic/shape breaking
→ major bump

thêm optional field
→ minor bump
```

Ví dụ:

```text
marine_vessel@1.0
marine_vessel@1.1
marine_vessel@2.0
```

---

# 34. ServiceProvider wiring Phase 7

Thêm:

```php
use App\Video\Concept\Schema\EffectiveConceptSchemaBuilder;
use App\Video\Concept\Validation\EffectiveSchemaValidator;
use App\Video\Profiles\CategoryProfileSchemaProvider;
```

Bindings:

```php
$this->app->singleton(
    CategoryProfileSchemaProvider::class
);

$this->app->singleton(
    EffectiveConceptSchemaBuilder::class,
    function (
        Application $app
    ): EffectiveConceptSchemaBuilder {
        return new EffectiveConceptSchemaBuilder(
            coreSchemaProvider:
                $app->make(
                    CanonicalSchemaProvider::class
                ),

            profileSchemaProvider:
                $app->make(
                    CategoryProfileSchemaProvider::class
                ),
        );
    }
);

$this->app->singleton(
    EffectiveSchemaValidator::class
);
```

Designer/Repairer wiring đổi từ:

```php
schemaProvider:
    CanonicalSchemaProvider::class
```

sang:

```php
schemaBuilder:
    EffectiveConceptSchemaBuilder::class
```

---

# 35. Flow cuối Phase 7

```text
object_type
    ↓
CategoryCreativeProfileResolver
    ↓
CategoryCreativeProfileRegistry
    ↓
marine_vessel@1.0
    │
    ├─────────────┐
    │             │
    ▼             ▼
inspection     profile schema
aspects             │
    │                │
    │      canonical_design_spec_v1
    │                │
    │                ▼
    │      EffectiveConceptSchemaBuilder
    │                │
    │                ▼
    │       effective schema
    │                │
    │                ▼
    │        ClaudeSchemaAdapter
    │                │
    │                ▼
    └───────────→ Sonnet 5
                     │
                     ▼
              Canonical JSON
                     │
           ┌─────────┴─────────┐
           ▼                   ▼
      Core Schema        Effective Schema
           │                   │
           └─────────┬─────────┘
                     ▼
                DTO Hydrate
                     ↓
             Semantic Validation
                     ↓
                Normalize
                     ↓
               Revalidate
                     ↓
                  Hash
                     ↓
                 Freeze
```

---

# 36. Chốt kiến trúc của Phase 7

Sau phần này ta đạt được đúng mục tiêu ban đầu:

```text
Universal Core V1
```

không biết yacht, aircraft, building.

`CategoryCreativeProfile` chỉ nói:

```text
category-level design grammar
```

`EffectiveConceptSchemaBuilder` tạo contract đủ cụ thể để Sonnet 5 structured output không sinh lung tung.

Và vẫn không rơi vào:

```text
mỗi article
mỗi product
mỗi brand
mỗi model
→ một schema riêng
```

Mà là:

```text
10.000 object types
↓
15–30 broad profiles
↓
1 universal Canonical Core
↓
1 common compiler architecture
```

Đây là hướng scale đúng cho hệ thống production-grade.

**Phần tiếp theo nên là Phần 8 — `CategorySemanticValidatorRegistry` + profile validators + object-type resolver**, để kiểm các logic mà JSON Schema không kiểm được như `length/beam ratio`, cardinality, geometry consistency, profile compatibility, rồi ghép chúng vào `CanonicalDesignSpecValidator` mà vẫn không nhét domain logic vào Core.
