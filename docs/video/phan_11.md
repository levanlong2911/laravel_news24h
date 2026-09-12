Tiếp tục đúng kiến trúc đã khóa ở Phần 1–10, **Phần 11 là ranh giới Laravel → Python**. Tôi giữ nguyên nguyên tắc:

> **Laravel sở hữu Canonical Design Truth. Python chỉ nhận frozen bytes, verify SHA-256, tạo derived Render IR. Python tuyệt đối không sửa, repair, normalize lại hoặc “hiểu lại” Canonical Design.**

Từ đây chúng ta bắt đầu chuyển:

```text
Semantic IR
CanonicalDesignSpec
        ↓
Frozen canonical_json
        ↓
Laravel → Python
        ↓
Integrity verification
        ↓
AssetProjection
        ↓
ConstraintNormalizer
        ↓
ConstraintSet
        ↓
Phần 12 Prompt Compiler
```

---

# PHẦN 11 — Python `AssetProjection` + `ConstraintSet` + `ConstraintNormalizer`

Cấu trúc tôi chốt:

```text
media_runtime/
├── canonical/
│   ├── __init__.py
│   ├── exceptions.py
│   ├── revision_envelope.py
│   ├── integrity.py
│   └── frozen_json.py
│
├── projection/
│   ├── __init__.py
│   ├── enums.py
│   ├── request.py
│   ├── policy.py
│   ├── policy_registry.py
│   ├── asset_projection.py
│   └── projector.py
│
├── constraints/
│   ├── __init__.py
│   ├── enums.py
│   ├── constraint.py
│   ├── constraint_set.py
│   ├── path_resolver.py
│   ├── stable_id.py
│   └── normalizer.py
│
└── tests/
    ├── test_canonical_integrity.py
    ├── test_asset_projection.py
    ├── test_constraint_normalizer.py
    └── test_no_canonical_mutation.py
```

Dependency Python:

```text
Python 3.12+
pydantic >= 2.8,<3
```

Không cần SQLAlchemy.

Không cần Laravel DB access.

Không cần Claude/OpenAI SDK trong Part 11.

---

# 11.1 Contract Laravel gửi cho Python

Laravel không gửi:

```text
project_id
→ Python query DB
→ tìm canonical revision
```

Sai.

Laravel gửi exact frozen artifact:

```json
{
  "canonical_revision": {
    "revision_id": "018f...",
    "project_id": "018e...",
    "revision": 3,
    "canonical_hash": "a94f...",
    "canonical_json": "{\"dimensions\":...}",
    "schema_version": "1.0",
    "profile_key": "marine_vessel",
    "profile_version": "1.0",
    "effective_schema_hash": "9ab2...",
    "normalizer_version": "canonical-normalizer-v1",
    "canonicalizer_version": "canonical-json-v1"
  },

  "asset_request": {
    "asset_id": "master_vessel",
    "asset_type": "identity_anchor",
    "view_role": "master_geometry",
    "required_paths": [],
    "optional_paths": [],
    "excluded_paths": [],
    "state": null,
    "camera": {
      "view": "front_three_quarter",
      "side": "port",
      "elevation": "slightly_above_waterline"
    }
  }
}
```

Hai phần tách biệt:

```text
canonical_revision
= immutable truth

asset_request
= execution intent
```

---

# 11.2 Python tuyệt đối không rewrite canonical JSON

Ví dụ không được:

```python
canonical["dimensions"]["length_to_beam_ratio"] = (
    canonical["dimensions"]["length_m"]
    / canonical["dimensions"]["beam_m"]
)
```

Không được.

Không:

```python
canonical["permanent_geometry"]["bow"]["stem"] = "near_plumb"
```

Không.

Không:

```python
canonical.setdefault(...)
```

Python chỉ:

```text
READ
PROJECT
DERIVE
COMPILE
VERIFY
```

---

# 11.3 `canonical/exceptions.py`

```python
from __future__ import annotations


class CanonicalRuntimeError(RuntimeError):
    """Base exception for frozen canonical runtime errors."""


class CanonicalIntegrityError(CanonicalRuntimeError):
    """Frozen canonical bytes do not match the declared hash."""


class CanonicalEnvelopeError(CanonicalRuntimeError):
    """Malformed Laravel -> Python canonical envelope."""


class CanonicalMutationError(CanonicalRuntimeError):
    """Attempted mutation or unsupported conversion of frozen data."""


class ProjectionError(CanonicalRuntimeError):
    """Asset projection could not be constructed deterministically."""


class ConstraintNormalizationError(CanonicalRuntimeError):
    """Canonical data could not be converted into derived constraints."""
```

---

# 11.4 `canonical/revision_envelope.py`

Dùng Pydantic để validate transport contract.

```python
from __future__ import annotations

from pydantic import BaseModel, ConfigDict, Field, field_validator


class CanonicalRevisionMetadata(BaseModel):
    model_config = ConfigDict(
        frozen=True,
        extra="forbid",
        strict=True,
    )

    revision_id: str = Field(min_length=1)
    project_id: str = Field(min_length=1)

    revision: int = Field(ge=1)

    canonical_hash: str = Field(
        pattern=r"^[a-f0-9]{64}$"
    )

    schema_version: str = Field(min_length=1)

    profile_key: str = Field(min_length=1)
    profile_version: str = Field(min_length=1)

    effective_schema_hash: str = Field(
        pattern=r"^[a-f0-9]{64}$"
    )

    normalizer_version: str = Field(min_length=1)
    canonicalizer_version: str = Field(min_length=1)


class FrozenCanonicalRevisionEnvelope(BaseModel):
    """
    Exact immutable handoff from Laravel.

    canonical_json is the exact frozen UTF-8 JSON string.
    canonical_hash must be SHA-256(canonical_json UTF-8 bytes).
    """

    model_config = ConfigDict(
        frozen=True,
        extra="forbid",
        strict=True,
    )

    metadata: CanonicalRevisionMetadata

    canonical_json: str = Field(
        min_length=2
    )

    @field_validator("canonical_json")
    @classmethod
    def validate_canonical_json_not_blank(
        cls,
        value: str,
    ) -> str:
        if not value.strip():
            raise ValueError(
                "canonical_json must not be blank"
            )

        return value
```

Tôi chủ động không decode JSON trong DTO này.

Nó giữ exact bytes/string trước.

---

# 11.5 `canonical/integrity.py`

Đây là gate đầu tiên của Python.

```python
from __future__ import annotations

import hashlib

from media_runtime.canonical.exceptions import (
    CanonicalIntegrityError,
)
from media_runtime.canonical.revision_envelope import (
    FrozenCanonicalRevisionEnvelope,
)


class CanonicalIntegrityVerifier:
    """
    Verifies exact frozen bytes produced by Laravel.

    IMPORTANT:
    Do not json.loads()/json.dumps() before hashing.
    """

    @staticmethod
    def sha256_utf8(value: str) -> str:
        return hashlib.sha256(
            value.encode("utf-8")
        ).hexdigest()

    def verify(
        self,
        envelope: FrozenCanonicalRevisionEnvelope,
    ) -> None:
        actual = self.sha256_utf8(
            envelope.canonical_json
        )

        expected = (
            envelope.metadata.canonical_hash
        )

        if not hashlib.compare_digest(
            actual,
            expected,
        ):
            raise CanonicalIntegrityError(
                "Frozen canonical SHA-256 mismatch. "
                f"revision_id={envelope.metadata.revision_id} "
                f"expected={expected} "
                f"actual={actual}"
            )
```

Đây là invariant:

```text
Laravel hash
==
Python SHA256(exact canonical_json UTF-8 bytes)
```

---

# 11.6 `canonical/frozen_json.py`

Sau khi verify hash mới decode.

Ta không để các service nhận mutable `dict`.

Tôi freeze recursively:

```python
from __future__ import annotations

import json

from collections.abc import Mapping
from types import MappingProxyType
from typing import Any

from media_runtime.canonical.exceptions import (
    CanonicalEnvelopeError,
)


FrozenJsonValue = (
    str
    | int
    | float
    | bool
    | None
    | tuple["FrozenJsonValue", ...]
    | Mapping[str, "FrozenJsonValue"]
)


def freeze_json(
    value: Any,
) -> FrozenJsonValue:
    if isinstance(
        value,
        (
            str,
            int,
            float,
            bool,
            type(None),
        ),
    ):
        return value

    if isinstance(value, list):
        return tuple(
            freeze_json(item)
            for item in value
        )

    if isinstance(value, dict):
        result = {
            str(key): freeze_json(child)
            for key, child in value.items()
        }

        return MappingProxyType(result)

    raise CanonicalEnvelopeError(
        "Unsupported JSON value type: "
        f"{type(value).__name__}"
    )


def parse_frozen_canonical_json(
    canonical_json: str,
) -> Mapping[str, FrozenJsonValue]:
    try:
        parsed = json.loads(
            canonical_json
        )
    except json.JSONDecodeError as exc:
        raise CanonicalEnvelopeError(
            "Frozen canonical_json is invalid JSON"
        ) from exc

    if not isinstance(parsed, dict):
        raise CanonicalEnvelopeError(
            "Canonical root must be a JSON object"
        )

    frozen = freeze_json(parsed)

    if not isinstance(frozen, Mapping):
        raise CanonicalEnvelopeError(
            "Frozen canonical root is not a mapping"
        )

    return frozen
```

Sau đó nếu ai thử:

```python
canonical["dimensions"] = {}
```

sẽ fail.

Tuple cũng không append được.

---

# 11.7 Frozen document wrapper

Thêm vào cùng file:

```python
from dataclasses import dataclass


@dataclass(
    frozen=True,
    slots=True,
)
class FrozenCanonicalDocument:
    revision_id: str
    project_id: str
    revision: int

    canonical_hash: str

    schema_version: str

    profile_key: str
    profile_version: str

    effective_schema_hash: str

    normalizer_version: str
    canonicalizer_version: str

    data: Mapping[
        str,
        FrozenJsonValue,
    ]
```

Loader:

```python
class FrozenCanonicalLoader:
    def __init__(
        self,
        verifier,
    ) -> None:
        self._verifier = verifier

    def load(
        self,
        envelope: FrozenCanonicalRevisionEnvelope,
    ) -> FrozenCanonicalDocument:
        self._verifier.verify(
            envelope
        )

        data = parse_frozen_canonical_json(
            envelope.canonical_json
        )

        metadata = envelope.metadata

        return FrozenCanonicalDocument(
            revision_id=metadata.revision_id,
            project_id=metadata.project_id,
            revision=metadata.revision,

            canonical_hash=metadata.canonical_hash,

            schema_version=metadata.schema_version,

            profile_key=metadata.profile_key,
            profile_version=metadata.profile_version,

            effective_schema_hash=(
                metadata.effective_schema_hash
            ),

            normalizer_version=(
                metadata.normalizer_version
            ),

            canonicalizer_version=(
                metadata.canonicalizer_version
            ),

            data=data,
        )
```

---

# 11.8 Asset types

`projection/enums.py`

```python
from __future__ import annotations

from enum import StrEnum


class AssetType(StrEnum):
    IDENTITY_ANCHOR = "identity_anchor"

    IDENTITY_REFERENCE = (
        "identity_reference"
    )

    CONSTRUCTION_REFERENCE = (
        "construction_reference"
    )

    SCENE_IMAGE = "scene_image"

    VIDEO_KEYFRAME = "video_keyframe"

    VIDEO_REFERENCE = "video_reference"


class ViewRole(StrEnum):
    MASTER_GEOMETRY = "master_geometry"

    SIDE_PROFILE = "side_profile"

    FRONT = "front"

    REAR = "rear"

    THREE_QUARTER_FRONT = (
        "three_quarter_front"
    )

    THREE_QUARTER_REAR = (
        "three_quarter_rear"
    )

    HIGH_OVERVIEW = "high_overview"

    CAMERA_MATCHED = "camera_matched"

    SCENE_SPECIFIC = "scene_specific"
```

Asset type không phụ thuộc yacht.

---

# 11.9 `projection/request.py`

Laravel quyết định asset intent.

Python không tự nghĩ scene/camera mới.

```python
from __future__ import annotations

from typing import Any

from pydantic import (
    BaseModel,
    ConfigDict,
    Field,
)

from media_runtime.projection.enums import (
    AssetType,
    ViewRole,
)


class CameraIntent(BaseModel):
    model_config = ConfigDict(
        frozen=True,
        extra="allow",
        strict=True,
    )

    view: str | None = None
    side: str | None = None

    elevation: str | None = None

    framing: str | None = None

    perspective: str | None = None


class AssetProjectionRequest(BaseModel):
    model_config = ConfigDict(
        frozen=True,
        extra="forbid",
        strict=True,
    )

    asset_id: str = Field(
        min_length=1
    )

    asset_type: AssetType

    view_role: ViewRole

    required_paths: tuple[str, ...] = ()

    optional_paths: tuple[str, ...] = ()

    excluded_paths: tuple[str, ...] = ()

    state: dict[str, Any] | None = None

    camera: CameraIntent | None = None
```

`state` và `camera` là **execution intent từ Laravel**, không phải Canonical Design.

---

# 11.10 Projection policy

Không muốn mỗi asset type tự viết một projector.

Ta dùng policy data-driven.

`projection/policy.py`

```python
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.projection.enums import (
    AssetType,
)


@dataclass(
    frozen=True,
    slots=True,
)
class AssetProjectionPolicy:
    asset_type: AssetType

    include_identity: bool

    include_dimensions: bool

    include_permanent_geometry: bool

    include_relationships: bool

    include_form_relationships: bool

    include_finished_materials: bool

    include_exclusions: bool

    include_invariants: bool
```

---

# 11.11 `projection/policy_registry.py`

```python
from __future__ import annotations

from media_runtime.canonical.exceptions import (
    ProjectionError,
)
from media_runtime.projection.enums import (
    AssetType,
)
from media_runtime.projection.policy import (
    AssetProjectionPolicy,
)


class AssetProjectionPolicyRegistry:
    def __init__(
        self,
        policies: tuple[
            AssetProjectionPolicy,
            ...,
        ],
    ) -> None:
        registry: dict[
            AssetType,
            AssetProjectionPolicy,
        ] = {}

        for policy in policies:
            if policy.asset_type in registry:
                raise ProjectionError(
                    "Duplicate projection policy "
                    f"for {policy.asset_type}"
                )

            registry[
                policy.asset_type
            ] = policy

        self._policies = registry

    def get(
        self,
        asset_type: AssetType,
    ) -> AssetProjectionPolicy:
        try:
            return self._policies[
                asset_type
            ]
        except KeyError as exc:
            raise ProjectionError(
                "No projection policy registered "
                f"for asset_type={asset_type}"
            ) from exc


def default_projection_policy_registry(
) -> AssetProjectionPolicyRegistry:
    return AssetProjectionPolicyRegistry(
        policies=(
            AssetProjectionPolicy(
                asset_type=(
                    AssetType.IDENTITY_ANCHOR
                ),
                include_identity=True,
                include_dimensions=True,
                include_permanent_geometry=True,
                include_relationships=True,
                include_form_relationships=True,
                include_finished_materials=True,
                include_exclusions=True,
                include_invariants=True,
            ),

            AssetProjectionPolicy(
                asset_type=(
                    AssetType.IDENTITY_REFERENCE
                ),
                include_identity=True,
                include_dimensions=True,
                include_permanent_geometry=True,
                include_relationships=True,
                include_form_relationships=True,
                include_finished_materials=True,
                include_exclusions=True,
                include_invariants=True,
            ),

            AssetProjectionPolicy(
                asset_type=(
                    AssetType.CONSTRUCTION_REFERENCE
                ),
                include_identity=True,
                include_dimensions=True,
                include_permanent_geometry=True,
                include_relationships=True,
                include_form_relationships=True,

                /*
                 * Finished surface intent is not
                 * automatically applied as current
                 * construction state.
                 *
                 * But retain it as canonical context
                 * only if a future compiler needs
                 * "eventual finished identity".
                 *
                 * V1 projection excludes it.
                 */
                include_finished_materials=False,

                include_exclusions=True,
                include_invariants=True,
            ),

            AssetProjectionPolicy(
                asset_type=(
                    AssetType.SCENE_IMAGE
                ),
                include_identity=True,
                include_dimensions=True,
                include_permanent_geometry=True,
                include_relationships=True,
                include_form_relationships=True,
                include_finished_materials=True,
                include_exclusions=True,
                include_invariants=True,
            ),

            AssetProjectionPolicy(
                asset_type=(
                    AssetType.VIDEO_KEYFRAME
                ),
                include_identity=True,
                include_dimensions=True,
                include_permanent_geometry=True,
                include_relationships=True,
                include_form_relationships=True,
                include_finished_materials=True,
                include_exclusions=True,
                include_invariants=True,
            ),

            AssetProjectionPolicy(
                asset_type=(
                    AssetType.VIDEO_REFERENCE
                ),
                include_identity=True,
                include_dimensions=True,
                include_permanent_geometry=True,
                include_relationships=True,
                include_form_relationships=True,
                include_finished_materials=True,
                include_exclusions=True,
                include_invariants=True,
            ),
        )
    )
```

Quan trọng:

```text
CONSTRUCTION_REFERENCE
```

không đổi canonical finished materials.

Nó chỉ **không project chúng thành current-state constraint**.

---

# 11.12 `projection/asset_projection.py`

AssetProjection là **derived IR**.

```python
from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Mapping

from media_runtime.canonical.frozen_json import (
    FrozenJsonValue,
)
from media_runtime.projection.enums import (
    AssetType,
    ViewRole,
)
from media_runtime.projection.request import (
    CameraIntent,
)


@dataclass(
    frozen=True,
    slots=True,
)
class AssetProjection:
    projection_version: str

    asset_id: str
    asset_type: AssetType
    view_role: ViewRole

    source_revision_id: str
    source_revision: int
    source_canonical_hash: str

    object_type: str
    profile_key: str
    profile_version: str

    identity: Mapping[
        str,
        FrozenJsonValue,
    ] | None

    dimensions: Mapping[
        str,
        FrozenJsonValue,
    ] | None

    permanent_geometry: Mapping[
        str,
        FrozenJsonValue,
    ] | None

    relationships: tuple[
        FrozenJsonValue,
        ...,
    ]

    form_relationships: Mapping[
        str,
        FrozenJsonValue,
    ] | None

    finished_materials: Mapping[
        str,
        FrozenJsonValue,
    ] | None

    exclusions: tuple[
        FrozenJsonValue,
        ...,
    ]

    invariants: tuple[
        FrozenJsonValue,
        ...,
    ]

    required_paths: tuple[str, ...]
    optional_paths: tuple[str, ...]
    excluded_paths: tuple[str, ...]

    requested_state: Mapping[
        str,
        Any,
    ] | None

    camera: CameraIntent | None
```

Notice:

```text
requested_state
camera
```

không nằm trong canonical fields.

Đây là execution overlay.

---

# 11.13 Canonical path resolver

`constraints/path_resolver.py`

```python
from __future__ import annotations

from collections.abc import Mapping
from dataclasses import dataclass
from typing import Any


@dataclass(
    frozen=True,
    slots=True,
)
class PathResolution:
    found: bool
    value: Any = None


class CanonicalPathResolver:
    """
    Canonical V1 currently uses dot notation.

    Example:
    permanent_geometry.superstructure.primary_tier_count
    """

    def resolve(
        self,
        root: Mapping[str, Any],
        path: str,
    ) -> PathResolution:
        if not path:
            return PathResolution(
                found=False
            )

        current: Any = root

        for segment in path.split("."):
            if not isinstance(
                current,
                Mapping,
            ):
                return PathResolution(
                    found=False
                )

            if segment not in current:
                return PathResolution(
                    found=False
                )

            current = current[
                segment
            ]

        return PathResolution(
            found=True,
            value=current,
        )
```

Python không validate path semantics lại theo kiểu Laravel.

Nó chỉ resolve để projection/constraint derivation.

Nếu canonical đã frozen mà declared path không resolve:

```text
runtime integrity/config error
```

không tự repair.

---

# 11.14 Asset projector

`projection/projector.py`

```python
from __future__ import annotations

from collections.abc import Mapping

from media_runtime.canonical.exceptions import (
    ProjectionError,
)
from media_runtime.canonical.frozen_json import (
    FrozenCanonicalDocument,
    FrozenJsonValue,
)
from media_runtime.constraints.path_resolver import (
    CanonicalPathResolver,
)
from media_runtime.projection.asset_projection import (
    AssetProjection,
)
from media_runtime.projection.policy_registry import (
    AssetProjectionPolicyRegistry,
)
from media_runtime.projection.request import (
    AssetProjectionRequest,
)


class AssetProjector:
    VERSION = "asset-projection-v1"

    def __init__(
        self,
        policy_registry: (
            AssetProjectionPolicyRegistry
        ),
        path_resolver: CanonicalPathResolver,
    ) -> None:
        self._policies = (
            policy_registry
        )
        self._paths = path_resolver

    def project(
        self,
        canonical: FrozenCanonicalDocument,
        request: AssetProjectionRequest,
    ) -> AssetProjection:
        root = canonical.data

        policy = self._policies.get(
            request.asset_type
        )

        object_type = self._required_string(
            root,
            "object_type",
        )

        identity = (
            self._mapping(
                root,
                "identity",
            )
            if policy.include_identity
            else None
        )

        dimensions = (
            self._mapping(
                root,
                "dimensions",
            )
            if policy.include_dimensions
            else None
        )

        permanent_geometry = (
            self._mapping(
                root,
                "permanent_geometry",
            )
            if policy.include_permanent_geometry
            else None
        )

        relationships = (
            self._tuple(
                root,
                "relationships",
            )
            if policy.include_relationships
            else ()
        )

        form_relationships = (
            self._mapping(
                root,
                "form_relationships",
            )
            if policy.include_form_relationships
            else None
        )

        finished_materials = (
            self._mapping(
                root,
                "finished_materials",
            )
            if policy.include_finished_materials
            else None
        )

        exclusions = (
            self._tuple(
                root,
                "exclusions",
            )
            if policy.include_exclusions
            else ()
        )

        invariants = (
            self._tuple(
                root,
                "invariants",
            )
            if policy.include_invariants
            else ()
        )

        self._assert_required_paths_exist(
            root=root,
            paths=request.required_paths,
        )

        self._assert_excluded_paths_do_not_overlap_required(
            required=request.required_paths,
            excluded=request.excluded_paths,
        )

        return AssetProjection(
            projection_version=self.VERSION,

            asset_id=request.asset_id,
            asset_type=request.asset_type,
            view_role=request.view_role,

            source_revision_id=(
                canonical.revision_id
            ),
            source_revision=(
                canonical.revision
            ),
            source_canonical_hash=(
                canonical.canonical_hash
            ),

            object_type=object_type,

            profile_key=(
                canonical.profile_key
            ),
            profile_version=(
                canonical.profile_version
            ),

            identity=identity,
            dimensions=dimensions,
            permanent_geometry=(
                permanent_geometry
            ),
            relationships=relationships,
            form_relationships=(
                form_relationships
            ),
            finished_materials=(
                finished_materials
            ),
            exclusions=exclusions,
            invariants=invariants,

            required_paths=(
                request.required_paths
            ),
            optional_paths=(
                request.optional_paths
            ),
            excluded_paths=(
                request.excluded_paths
            ),

            requested_state=(
                request.state
            ),

            camera=request.camera,
        )

    def _assert_required_paths_exist(
        self,
        root: Mapping[str, FrozenJsonValue],
        paths: tuple[str, ...],
    ) -> None:
        for path in paths:
            resolution = (
                self._paths.resolve(
                    root,
                    path,
                )
            )

            if not resolution.found:
                raise ProjectionError(
                    "AssetProjection required path "
                    f"does not exist: {path}"
                )

    @staticmethod
    def _assert_excluded_paths_do_not_overlap_required(
        required: tuple[str, ...],
        excluded: tuple[str, ...],
    ) -> None:
        overlap = (
            set(required)
            & set(excluded)
        )

        if overlap:
            raise ProjectionError(
                "Asset projection path is both "
                "required and excluded: "
                + ", ".join(
                    sorted(overlap)
                )
            )

    @staticmethod
    def _required_string(
        root: Mapping[str, FrozenJsonValue],
        key: str,
    ) -> str:
        value = root.get(key)

        if not isinstance(value, str):
            raise ProjectionError(
                f"Canonical {key} must be string"
            )

        return value

    @staticmethod
    def _mapping(
        root: Mapping[str, FrozenJsonValue],
        key: str,
    ) -> Mapping[str, FrozenJsonValue]:
        value = root.get(key)

        if not isinstance(
            value,
            Mapping,
        ):
            raise ProjectionError(
                f"Canonical {key} must be object"
            )

        return value

    @staticmethod
    def _tuple(
        root: Mapping[str, FrozenJsonValue],
        key: str,
    ) -> tuple[FrozenJsonValue, ...]:
        value = root.get(key)

        if not isinstance(value, tuple):
            raise ProjectionError(
                f"Canonical {key} must be array"
            )

        return value
```

---

# 11.15 Constraint primitive enum

Phải mirror chính xác Core V1.

`constraints/enums.py`

```python
from __future__ import annotations

from enum import IntEnum, StrEnum


class ConstraintPrimitive(StrEnum):
    COUNT = "count"
    PROPORTION = "proportion"
    POSITION = "position"
    ORDER = "order"
    CONNECTIVITY = "connectivity"
    CONTINUITY = "continuity"
    VISIBILITY = "visibility"
    MATERIAL = "material"
    STATE = "state"
    EXCLUSION = "exclusion"
    GEOMETRY = "geometry"
    LAYOUT = "layout"
    GROUPING = "grouping"
    ALIGNMENT = "alignment"
    CONTAINMENT = "containment"
    SYMMETRY = "symmetry"


class ConstraintSeverity(StrEnum):
    HARD = "hard"
    SOFT = "soft"


class ConstraintPriority(IntEnum):
    """
    Lower number = higher render priority.

    Mirrors compiler priority locked earlier.
    """

    P0_IDENTITY = 0

    P1_COUNT = 1

    P2_TOPOLOGY = 2

    P3_PROPORTION = 3

    P4_GEOMETRY = 4

    P5_PERMANENT_OPENINGS = 5

    P6_STATE_MATERIAL = 6

    P7_CAMERA = 7

    P8_STYLE_LIGHTING = 8


class ConstraintSourceKind(StrEnum):
    IDENTITY = "identity"

    DIMENSION = "dimension"

    GEOMETRY = "geometry"

    RELATIONSHIP = "relationship"

    FORM_RELATIONSHIP = (
        "form_relationship"
    )

    MATERIAL = "material"

    EXCLUSION = "exclusion"

    INVARIANT = "invariant"

    EXECUTION_STATE = (
        "execution_state"
    )

    CAMERA = "camera"
```

---

# 11.16 Stable derived constraint IDs

Không tái sử dụng `R001`, `I001`, `E001` làm constraint ID vì một canonical item có thể produce nhiều render constraints.

`constraints/stable_id.py`

```python
from __future__ import annotations

import hashlib


class StableConstraintId:
    @staticmethod
    def make(
        *,
        source_hash: str,
        asset_id: str,
        primitive: str,
        source_key: str,
    ) -> str:
        raw = (
            f"{source_hash}|"
            f"{asset_id}|"
            f"{primitive}|"
            f"{source_key}"
        )

        digest = hashlib.sha256(
            raw.encode("utf-8")
        ).hexdigest()[:16]

        return f"C-{digest}"
```

Constraint IDs deterministic.

Same:

```text
canonical revision/hash
asset
source
```

→ same constraint ID.

---

# 11.17 Constraint DTO

`constraints/constraint.py`

```python
from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from media_runtime.constraints.enums import (
    ConstraintPrimitive,
    ConstraintPriority,
    ConstraintSeverity,
    ConstraintSourceKind,
)


@dataclass(
    frozen=True,
    slots=True,
)
class Constraint:
    id: str

    primitive: ConstraintPrimitive

    severity: ConstraintSeverity

    priority: ConstraintPriority

    source_kind: ConstraintSourceKind

    source_path: str | None

    source_id: str | None

    subject_path: str | None

    value: Any

    visual_verification: bool

    description: str | None = None

    provenance_origin: str | None = None
```

Đây chưa có prompt text.

Không có:

```python
prompt = "make the yacht..."
```

Prompt thuộc Part 12.

---

# 11.18 `ConstraintSet`

`constraints/constraint_set.py`

```python
from __future__ import annotations

import hashlib
import json

from dataclasses import (
    asdict,
    dataclass,
)

from media_runtime.constraints.constraint import (
    Constraint,
)


@dataclass(
    frozen=True,
    slots=True,
)
class ConstraintSet:
    version: str

    asset_id: str

    source_revision_id: str

    source_canonical_hash: str

    constraints: tuple[
        Constraint,
        ...,
    ]

    def hard_constraints(
        self,
    ) -> tuple[Constraint, ...]:
        return tuple(
            item
            for item in self.constraints
            if item.severity.value
            == "hard"
        )

    def visually_verifiable(
        self,
    ) -> tuple[Constraint, ...]:
        return tuple(
            item
            for item in self.constraints
            if item.visual_verification
        )

    def by_priority(
        self,
    ) -> tuple[Constraint, ...]:
        return tuple(
            sorted(
                self.constraints,
                key=lambda item: (
                    int(item.priority),
                    item.id,
                ),
            )
        )

    def fingerprint(
        self,
    ) -> str:
        payload = {
            "version": self.version,
            "asset_id": self.asset_id,
            "source_revision_id": (
                self.source_revision_id
            ),
            "source_canonical_hash": (
                self.source_canonical_hash
            ),
            "constraints": [
                self._constraint_dict(c)
                for c in self.by_priority()
            ],
        }

        encoded = json.dumps(
            payload,
            ensure_ascii=False,
            separators=(",", ":"),
            sort_keys=True,
        ).encode("utf-8")

        return hashlib.sha256(
            encoded
        ).hexdigest()

    @staticmethod
    def _constraint_dict(
        constraint: Constraint,
    ) -> dict:
        value = asdict(
            constraint
        )

        value["primitive"] = (
            constraint.primitive.value
        )

        value["severity"] = (
            constraint.severity.value
        )

        value["priority"] = int(
            constraint.priority
        )

        value["source_kind"] = (
            constraint.source_kind.value
        )

        return value
```

`ConstraintSet.hash` là hash derived artifact.

Nó **không thay canonical hash**.

---

# 11.19 Normalizer không được semantic-infer

Đây là nguyên tắc quan trọng nhất của class sau:

```text
Canonical says:
R001 count = 4
→ Constraint COUNT = 4

Canonical says:
length=120 beam=20
but no relationship says ratio?
→ Python DOES NOT invent ratio constraint.
```

Laravel đã làm semantic consistency.

Python chỉ translate declared truth.

---

# 11.20 Priority mapping

Ta khóa mapping deterministic:

```text
identity             → P0
count                → P1
grouping             → P1

connectivity         → P2
continuity           → P2
containment          → P2
order                → P2
alignment            → P2
symmetry             → P2

proportion           → P3

geometry             → P4
position             → P4
layout               → P4

openings             → P5

material             → P6
state                → P6

camera               → P7
```

Exclusion priority tùy target.

Default hard exclusion:

```text
P4
```

---

# 11.21 `ConstraintNormalizer`

Đây là file trung tâm.

`constraints/normalizer.py`

```python
from __future__ import annotations

from collections.abc import Mapping
from typing import Any

from media_runtime.canonical.exceptions import (
    ConstraintNormalizationError,
)
from media_runtime.constraints.constraint import (
    Constraint,
)
from media_runtime.constraints.constraint_set import (
    ConstraintSet,
)
from media_runtime.constraints.enums import (
    ConstraintPrimitive,
    ConstraintPriority,
    ConstraintSeverity,
    ConstraintSourceKind,
)
from media_runtime.constraints.stable_id import (
    StableConstraintId,
)
from media_runtime.projection.asset_projection import (
    AssetProjection,
)


class ConstraintNormalizer:
    VERSION = "constraint-set-v1"

    def normalize(
        self,
        projection: AssetProjection,
    ) -> ConstraintSet:
        constraints: list[
            Constraint
        ] = []

        invariant_index = (
            self._invariant_index(
                projection
            )
        )

        /*
         * -----------------------------------------
         * P0 — identity basis
         * -----------------------------------------
         */
        constraints.extend(
            self._identity_constraints(
                projection,
                invariant_index,
            )
        )

        /*
         * -----------------------------------------
         * Dimensions
         * -----------------------------------------
         */
        constraints.extend(
            self._dimension_constraints(
                projection,
                invariant_index,
            )
        )

        /*
         * -----------------------------------------
         * Permanent geometry
         * -----------------------------------------
         */
        constraints.extend(
            self._geometry_constraints(
                projection,
                invariant_index,
            )
        )

        /*
         * -----------------------------------------
         * Explicit structured relationships
         * -----------------------------------------
         */
        constraints.extend(
            self._relationship_constraints(
                projection,
                invariant_index,
            )
        )

        /*
         * -----------------------------------------
         * Higher-level form relationships
         * -----------------------------------------
         */
        constraints.extend(
            self._form_relationship_constraints(
                projection,
                invariant_index,
            )
        )

        /*
         * -----------------------------------------
         * Finished material intent
         * -----------------------------------------
         */
        constraints.extend(
            self._material_constraints(
                projection,
                invariant_index,
            )
        )

        /*
         * -----------------------------------------
         * Canonical exclusions
         * -----------------------------------------
         */
        constraints.extend(
            self._exclusion_constraints(
                projection
            )
        )

        /*
         * -----------------------------------------
         * Asset execution state
         * -----------------------------------------
         */
        constraints.extend(
            self._state_constraints(
                projection
            )
        )

        /*
         * -----------------------------------------
         * Camera intent
         * -----------------------------------------
         */
        constraints.extend(
            self._camera_constraints(
                projection
            )
        )

        /*
         * -----------------------------------------
         * Explicit invariant-only constraints.
         *
         * If an invariant already upgraded a
         * generated constraint, do not duplicate it.
         * -----------------------------------------
         */
        constraints = (
            self._apply_invariants(
                constraints,
                projection,
            )
        )

        constraints = (
            self._apply_projection_path_policy(
                constraints,
                projection,
            )
        )

        constraints = (
            self._deduplicate(
                constraints
            )
        )

        constraints.sort(
            key=lambda item: (
                int(item.priority),
                item.source_path or "",
                item.source_id or "",
                item.id,
            )
        )

        return ConstraintSet(
            version=self.VERSION,

            asset_id=projection.asset_id,

            source_revision_id=(
                projection.source_revision_id
            ),

            source_canonical_hash=(
                projection.source_canonical_hash
            ),

            constraints=tuple(
                constraints
            ),
        )

    # -------------------------------------------------
    # Identity
    # -------------------------------------------------

    def _identity_constraints(
        self,
        projection: AssetProjection,
        invariant_index: dict[str, list[dict]],
    ) -> list[Constraint]:
        identity = projection.identity

        if identity is None:
            return []

        basis = identity.get(
            "identity_basis"
        )

        if not isinstance(
            basis,
            tuple,
        ):
            return []

        result: list[Constraint] = []

        for index, statement in enumerate(
            basis
        ):
            if not isinstance(
                statement,
                str,
            ):
                raise ConstraintNormalizationError(
                    "identity.identity_basis "
                    "must contain strings"
                )

            source_path = (
                f"identity.identity_basis.{index}"
            )

            result.append(
                Constraint(
                    id=self._id(
                        projection,
                        primitive=(
                            ConstraintPrimitive.GEOMETRY
                        ),
                        source_key=source_path,
                    ),

                    primitive=(
                        ConstraintPrimitive.GEOMETRY
                    ),

                    severity=(
                        ConstraintSeverity.HARD
                    ),

                    priority=(
                        ConstraintPriority.P0_IDENTITY
                    ),

                    source_kind=(
                        ConstraintSourceKind.IDENTITY
                    ),

                    source_path=source_path,

                    source_id=None,

                    subject_path=None,

                    value=statement,

                    visual_verification=True,

                    description=(
                        "Identity-defining canonical basis"
                    ),
                )
            )

        return result

    # -------------------------------------------------
    # Dimensions
    # -------------------------------------------------

    def _dimension_constraints(
        self,
        projection: AssetProjection,
        invariant_index: dict[str, list[dict]],
    ) -> list[Constraint]:
        dimensions = projection.dimensions

        if dimensions is None:
            return []

        result: list[Constraint] = []

        self._flatten_mapping(
            mapping=dimensions,
            root_path="dimensions",
            callback=lambda path, value: (
                result.append(
                    self._make_leaf_constraint(
                        projection=projection,

                        primitive=(
                            ConstraintPrimitive.PROPORTION
                        ),

                        default_severity=(
                            ConstraintSeverity.SOFT
                        ),

                        default_priority=(
                            ConstraintPriority.P3_PROPORTION
                        ),

                        source_kind=(
                            ConstraintSourceKind.DIMENSION
                        ),

                        source_path=path,

                        value=value,

                        invariant_index=(
                            invariant_index
                        ),

                        visual_verification=True,
                    )
                )
            ),
        )

        return result

    # -------------------------------------------------
    # Permanent geometry
    # -------------------------------------------------

    def _geometry_constraints(
        self,
        projection: AssetProjection,
        invariant_index: dict[str, list[dict]],
    ) -> list[Constraint]:
        geometry = (
            projection.permanent_geometry
        )

        if geometry is None:
            return []

        result: list[Constraint] = []

        def append(
            path: str,
            value: Any,
        ) -> None:
            priority = (
                ConstraintPriority.P5_PERMANENT_OPENINGS
                if self._looks_like_opening_path(
                    path
                )
                else ConstraintPriority.P4_GEOMETRY
            )

            result.append(
                self._make_leaf_constraint(
                    projection=projection,

                    primitive=(
                        ConstraintPrimitive.GEOMETRY
                    ),

                    default_severity=(
                        ConstraintSeverity.SOFT
                    ),

                    default_priority=priority,

                    source_kind=(
                        ConstraintSourceKind.GEOMETRY
                    ),

                    source_path=path,

                    value=value,

                    invariant_index=(
                        invariant_index
                    ),

                    visual_verification=True,
                )
            )

        self._flatten_mapping(
            mapping=geometry,
            root_path=(
                "permanent_geometry"
            ),
            callback=append,
        )

        return result

    # -------------------------------------------------
    # Structured relationships
    # -------------------------------------------------

    def _relationship_constraints(
        self,
        projection: AssetProjection,
        invariant_index: dict[str, list[dict]],
    ) -> list[Constraint]:
        result: list[Constraint] = []

        for relationship in (
            projection.relationships
        ):
            if not isinstance(
                relationship,
                Mapping,
            ):
                raise ConstraintNormalizationError(
                    "Canonical relationship "
                    "must be object"
                )

            relationship_id = (
                relationship.get("id")
            )

            relationship_type = (
                relationship.get("type")
            )

            if not isinstance(
                relationship_id,
                str,
            ):
                raise ConstraintNormalizationError(
                    "Relationship id missing"
                )

            if not isinstance(
                relationship_type,
                str,
            ):
                raise ConstraintNormalizationError(
                    "Relationship type missing"
                )

            try:
                primitive = (
                    ConstraintPrimitive(
                        relationship_type
                    )
                )
            except ValueError as exc:
                if (
                    relationship_type
                    == "one_to_one"
                ):
                    /*
                     * one_to_one is a relationship
                     * vocabulary, not a Canonical
                     * constraintPrimitive enum value.
                     *
                     * Render semantics are cardinality/
                     * topology. Normalize it as COUNT.
                     */
                    primitive = (
                        ConstraintPrimitive.COUNT
                    )
                else:
                    raise (
                        ConstraintNormalizationError(
                            "Unsupported relationship "
                            f"type={relationship_type}"
                        )
                    ) from exc

            result.append(
                Constraint(
                    id=self._id(
                        projection,

                        primitive=primitive,

                        source_key=(
                            f"relationship:"
                            f"{relationship_id}"
                        ),
                    ),

                    primitive=primitive,

                    severity=(
                        ConstraintSeverity.HARD
                    ),

                    priority=(
                        self._priority_for_primitive(
                            primitive
                        )
                    ),

                    source_kind=(
                        ConstraintSourceKind.RELATIONSHIP
                    ),

                    source_path=None,

                    source_id=relationship_id,

                    subject_path=(
                        self._relationship_subject_path(
                            relationship
                        )
                    ),

                    /*
                     * Keep complete normalized
                     * relationship object.
                     *
                     * PromptCompiler Part 12 knows
                     * how to express each primitive.
                     */
                    value=self._plain_value(
                        relationship
                    ),

                    visual_verification=True,

                    description=(
                        "Explicit canonical relationship"
                    ),
                )
            )

        return result

    # -------------------------------------------------
    # Form relationships
    # -------------------------------------------------

    def _form_relationship_constraints(
        self,
        projection: AssetProjection,
        invariant_index: dict[str, list[dict]],
    ) -> list[Constraint]:
        form = projection.form_relationships

        if form is None:
            return []

        result: list[Constraint] = []

        self._flatten_mapping(
            mapping=form,
            root_path=(
                "form_relationships"
            ),

            callback=lambda path, value: (
                result.append(
                    self._make_leaf_constraint(
                        projection=projection,

                        primitive=(
                            ConstraintPrimitive.GEOMETRY
                        ),

                        default_severity=(
                            ConstraintSeverity.SOFT
                        ),

                        default_priority=(
                            ConstraintPriority.P4_GEOMETRY
                        ),

                        source_kind=(
                            ConstraintSourceKind.FORM_RELATIONSHIP
                        ),

                        source_path=path,

                        value=value,

                        invariant_index=(
                            invariant_index
                        ),

                        visual_verification=True,
                    )
                )
            ),
        )

        return result

    # -------------------------------------------------
    # Materials
    # -------------------------------------------------

    def _material_constraints(
        self,
        projection: AssetProjection,
        invariant_index: dict[str, list[dict]],
    ) -> list[Constraint]:
        materials = (
            projection.finished_materials
        )

        if materials is None:
            return []

        result: list[Constraint] = []

        self._flatten_mapping(
            mapping=materials,
            root_path=(
                "finished_materials"
            ),

            callback=lambda path, value: (
                result.append(
                    self._make_leaf_constraint(
                        projection=projection,

                        primitive=(
                            ConstraintPrimitive.MATERIAL
                        ),

                        default_severity=(
                            ConstraintSeverity.SOFT
                        ),

                        default_priority=(
                            ConstraintPriority.P6_STATE_MATERIAL
                        ),

                        source_kind=(
                            ConstraintSourceKind.MATERIAL
                        ),

                        source_path=path,

                        value=value,

                        invariant_index=(
                            invariant_index
                        ),

                        visual_verification=True,
                    )
                )
            ),
        )

        return result

    # -------------------------------------------------
    # Exclusions
    # -------------------------------------------------

    def _exclusion_constraints(
        self,
        projection: AssetProjection,
    ) -> list[Constraint]:
        result: list[Constraint] = []

        for exclusion in (
            projection.exclusions
        ):
            if not isinstance(
                exclusion,
                Mapping,
            ):
                raise ConstraintNormalizationError(
                    "Exclusion must be object"
                )

            exclusion_id = exclusion.get(
                "id"
            )

            target_path = exclusion.get(
                "target_path"
            )

            forbid = exclusion.get(
                "forbid"
            )

            if not all(
                isinstance(value, str)
                for value in (
                    exclusion_id,
                    target_path,
                    forbid,
                )
            ):
                raise ConstraintNormalizationError(
                    "Malformed canonical exclusion"
                )

            result.append(
                Constraint(
                    id=self._id(
                        projection,

                        primitive=(
                            ConstraintPrimitive.EXCLUSION
                        ),

                        source_key=(
                            f"exclusion:"
                            f"{exclusion_id}"
                        ),
                    ),

                    primitive=(
                        ConstraintPrimitive.EXCLUSION
                    ),

                    severity=(
                        ConstraintSeverity.HARD
                    ),

                    priority=(
                        ConstraintPriority.P4_GEOMETRY
                    ),

                    source_kind=(
                        ConstraintSourceKind.EXCLUSION
                    ),

                    source_path=(
                        target_path
                    ),

                    source_id=(
                        exclusion_id
                    ),

                    subject_path=(
                        target_path
                    ),

                    value=forbid,

                    visual_verification=True,

                    description=(
                        "Canonical exclusion"
                    ),
                )
            )

        return result

    # -------------------------------------------------
    # Execution state
    # -------------------------------------------------

    def _state_constraints(
        self,
        projection: AssetProjection,
    ) -> list[Constraint]:
        state = projection.requested_state

        if not state:
            return []

        result: list[Constraint] = []

        for key in sorted(
            state.keys()
        ):
            value = state[key]

            source_key = (
                f"execution_state.{key}"
            )

            result.append(
                Constraint(
                    id=self._id(
                        projection,

                        primitive=(
                            ConstraintPrimitive.STATE
                        ),

                        source_key=source_key,
                    ),

                    primitive=(
                        ConstraintPrimitive.STATE
                    ),

                    severity=(
                        ConstraintSeverity.HARD
                    ),

                    priority=(
                        ConstraintPriority.P6_STATE_MATERIAL
                    ),

                    source_kind=(
                        ConstraintSourceKind.EXECUTION_STATE
                    ),

                    source_path=None,

                    source_id=None,

                    subject_path=None,

                    value={
                        "key": key,
                        "value": value,
                    },

                    visual_verification=True,

                    description=(
                        "Asset execution-state overlay"
                    ),
                )
            )

        return result

    # -------------------------------------------------
    # Camera
    # -------------------------------------------------

    def _camera_constraints(
        self,
        projection: AssetProjection,
    ) -> list[Constraint]:
        camera = projection.camera

        if camera is None:
            return []

        values = camera.model_dump(
            exclude_none=True
        )

        result: list[Constraint] = []

        for key in sorted(
            values.keys()
        ):
            source_key = (
                f"camera.{key}"
            )

            result.append(
                Constraint(
                    id=self._id(
                        projection,

                        primitive=(
                            ConstraintPrimitive.POSITION
                        ),

                        source_key=source_key,
                    ),

                    primitive=(
                        ConstraintPrimitive.POSITION
                    ),

                    severity=(
                        ConstraintSeverity.HARD
                    ),

                    priority=(
                        ConstraintPriority.P7_CAMERA
                    ),

                    source_kind=(
                        ConstraintSourceKind.CAMERA
                    ),

                    source_path=None,

                    source_id=None,

                    subject_path=None,

                    value={
                        "camera_property":
                            key,

                        "value":
                            values[key],
                    },

                    visual_verification=True,

                    description=(
                        "Laravel-declared camera intent"
                    ),
                )
            )

        return result

    # -------------------------------------------------
    # Invariants
    # -------------------------------------------------

    def _invariant_index(
        self,
        projection: AssetProjection,
    ) -> dict[str, list[dict]]:
        index: dict[
            str,
            list[dict],
        ] = {}

        for invariant in (
            projection.invariants
        ):
            if not isinstance(
                invariant,
                Mapping,
            ):
                raise ConstraintNormalizationError(
                    "Invariant must be object"
                )

            source_path = invariant.get(
                "source_path"
            )

            if not isinstance(
                source_path,
                str,
            ):
                raise ConstraintNormalizationError(
                    "Invariant source_path missing"
                )

            index.setdefault(
                source_path,
                []
            ).append(
                self._plain_value(
                    invariant
                )
            )

        return index

    def _make_leaf_constraint(
        self,
        *,
        projection: AssetProjection,

        primitive: ConstraintPrimitive,

        default_severity: ConstraintSeverity,

        default_priority: ConstraintPriority,

        source_kind: ConstraintSourceKind,

        source_path: str,

        value: Any,

        invariant_index: dict[
            str,
            list[dict],
        ],

        visual_verification: bool,
    ) -> Constraint:
        invariants = invariant_index.get(
            source_path,
            [],
        )

        severity = default_severity
        priority = default_priority

        declared_primitive = primitive

        invariant_ids: list[str] = []

        for invariant in invariants:
            invariant_id = invariant.get(
                "id"
            )

            if isinstance(
                invariant_id,
                str,
            ):
                invariant_ids.append(
                    invariant_id
                )

            if (
                invariant.get("severity")
                == "hard"
            ):
                severity = (
                    ConstraintSeverity.HARD
                )

            constraint_type = (
                invariant.get(
                    "constraint_type"
                )
            )

            if isinstance(
                constraint_type,
                str,
            ):
                try:
                    declared_primitive = (
                        ConstraintPrimitive(
                            constraint_type
                        )
                    )
                except ValueError:
                    pass

        return Constraint(
            id=self._id(
                projection,

                primitive=declared_primitive,

                source_key=source_path,
            ),

            primitive=declared_primitive,

            severity=severity,

            priority=(
                self._priority_for_primitive(
                    declared_primitive,
                    fallback=priority,
                )
            ),

            source_kind=source_kind,

            source_path=source_path,

            source_id=(
                ",".join(
                    sorted(invariant_ids)
                )
                if invariant_ids
                else None
            ),

            subject_path=source_path,

            value=self._plain_value(
                value
            ),

            visual_verification=(
                visual_verification
                or any(
                    item.get(
                        "visual_verification"
                    )
                    is True
                    for item in invariants
                )
            ),

            description=(
                "Canonical semantic value"
            ),
        )

    def _apply_invariants(
        self,
        constraints: list[Constraint],
        projection: AssetProjection,
    ) -> list[Constraint]:
        by_source_path = {
            item.source_path
            for item in constraints
            if item.source_path
        }

        result = list(
            constraints
        )

        for invariant in (
            projection.invariants
        ):
            if not isinstance(
                invariant,
                Mapping,
            ):
                continue

            source_path = invariant.get(
                "source_path"
            )

            invariant_id = invariant.get(
                "id"
            )

            constraint_type = (
                invariant.get(
                    "constraint_type"
                )
            )

            severity = invariant.get(
                "severity"
            )

            visual = invariant.get(
                "visual_verification"
            )

            if not isinstance(
                source_path,
                str,
            ):
                continue

            /*
             * Already represented by leaf/
             * relationship constraint.
             */
            if source_path in by_source_path:
                continue

            if not isinstance(
                invariant_id,
                str,
            ):
                continue

            if not isinstance(
                constraint_type,
                str,
            ):
                continue

            try:
                primitive = (
                    ConstraintPrimitive(
                        constraint_type
                    )
                )
            except ValueError:
                continue

            result.append(
                Constraint(
                    id=self._id(
                        projection,

                        primitive=primitive,

                        source_key=(
                            f"invariant:"
                            f"{invariant_id}"
                        ),
                    ),

                    primitive=primitive,

                    severity=(
                        ConstraintSeverity.HARD
                        if severity == "hard"
                        else ConstraintSeverity.SOFT
                    ),

                    priority=(
                        self._priority_for_primitive(
                            primitive
                        )
                    ),

                    source_kind=(
                        ConstraintSourceKind.INVARIANT
                    ),

                    source_path=source_path,

                    source_id=invariant_id,

                    subject_path=source_path,

                    /*
                     * Do NOT invent a new value.
                     * Invariant only points to an
                     * already-declared canonical path.
                     */
                    value=None,

                    visual_verification=(
                        visual is True
                    ),

                    description=(
                        "Canonical preservation invariant"
                    ),
                )
            )

        return result

    # -------------------------------------------------
    # Projection filters
    # -------------------------------------------------

    def _apply_projection_path_policy(
        self,
        constraints: list[Constraint],
        projection: AssetProjection,
    ) -> list[Constraint]:
        excluded = set(
            projection.excluded_paths
        )

        required = set(
            projection.required_paths
        )

        result: list[Constraint] = []

        for item in constraints:
            path = item.source_path

            if (
                path is not None
                and self._matches_any_path(
                    path,
                    excluded,
                )
                and not self._matches_any_path(
                    path,
                    required,
                )
            ):
                continue

            if (
                path is not None
                and self._matches_any_path(
                    path,
                    required,
                )
                and item.severity
                != ConstraintSeverity.HARD
            ):
                item = Constraint(
                    id=item.id,
                    primitive=item.primitive,

                    severity=(
                        ConstraintSeverity.HARD
                    ),

                    priority=item.priority,

                    source_kind=(
                        item.source_kind
                    ),

                    source_path=(
                        item.source_path
                    ),

                    source_id=(
                        item.source_id
                    ),

                    subject_path=(
                        item.subject_path
                    ),

                    value=item.value,

                    visual_verification=(
                        item.visual_verification
                    ),

                    description=(
                        item.description
                    ),

                    provenance_origin=(
                        item.provenance_origin
                    ),
                )

            result.append(item)

        return result

    # -------------------------------------------------
    # Utility
    # -------------------------------------------------

    def _id(
        self,
        projection: AssetProjection,
        *,
        primitive: ConstraintPrimitive,
        source_key: str,
    ) -> str:
        return StableConstraintId.make(
            source_hash=(
                projection.source_canonical_hash
            ),

            asset_id=(
                projection.asset_id
            ),

            primitive=primitive.value,

            source_key=source_key,
        )

    @staticmethod
    def _flatten_mapping(
        *,
        mapping: Mapping[str, Any],
        root_path: str,
        callback,
    ) -> None:
        for key in sorted(
            mapping.keys()
        ):
            value = mapping[key]

            path = (
                f"{root_path}.{key}"
            )

            if isinstance(
                value,
                Mapping,
            ):
                ConstraintNormalizer._flatten_mapping(
                    mapping=value,
                    root_path=path,
                    callback=callback,
                )

                continue

            /*
             * Tuple/list remains one semantic leaf.
             *
             * Do not invent indexed semantic paths
             * for arbitrary lists.
             */
            callback(
                path,
                value,
            )

    @staticmethod
    def _plain_value(
        value: Any,
    ) -> Any:
        if isinstance(
            value,
            Mapping,
        ):
            return {
                key: ConstraintNormalizer
                ._plain_value(child)
                for key, child
                in value.items()
            }

        if isinstance(
            value,
            tuple,
        ):
            return [
                ConstraintNormalizer
                ._plain_value(item)
                for item in value
            ]

        return value

    @staticmethod
    def _relationship_subject_path(
        relationship: Mapping[str, Any],
    ) -> str | None:
        for key in (
            "subject_path",
            "source_path",
            "container_path",
        ):
            value = relationship.get(
                key
            )

            if isinstance(
                value,
                str,
            ):
                return value

        return None

    @staticmethod
    def _priority_for_primitive(
        primitive: ConstraintPrimitive,
        fallback: (
            ConstraintPriority | None
        ) = None,
    ) -> ConstraintPriority:
        mapping = {
            ConstraintPrimitive.COUNT:
                ConstraintPriority.P1_COUNT,

            ConstraintPrimitive.GROUPING:
                ConstraintPriority.P1_COUNT,

            ConstraintPrimitive.CONNECTIVITY:
                ConstraintPriority.P2_TOPOLOGY,

            ConstraintPrimitive.CONTINUITY:
                ConstraintPriority.P2_TOPOLOGY,

            ConstraintPrimitive.CONTAINMENT:
                ConstraintPriority.P2_TOPOLOGY,

            ConstraintPrimitive.ORDER:
                ConstraintPriority.P2_TOPOLOGY,

            ConstraintPrimitive.ALIGNMENT:
                ConstraintPriority.P2_TOPOLOGY,

            ConstraintPrimitive.SYMMETRY:
                ConstraintPriority.P2_TOPOLOGY,

            ConstraintPrimitive.PROPORTION:
                ConstraintPriority.P3_PROPORTION,

            ConstraintPrimitive.GEOMETRY:
                ConstraintPriority.P4_GEOMETRY,

            ConstraintPrimitive.POSITION:
                ConstraintPriority.P4_GEOMETRY,

            ConstraintPrimitive.LAYOUT:
                ConstraintPriority.P4_GEOMETRY,

            ConstraintPrimitive.EXCLUSION:
                ConstraintPriority.P4_GEOMETRY,

            ConstraintPrimitive.VISIBILITY:
                ConstraintPriority.P5_PERMANENT_OPENINGS,

            ConstraintPrimitive.MATERIAL:
                ConstraintPriority.P6_STATE_MATERIAL,

            ConstraintPrimitive.STATE:
                ConstraintPriority.P6_STATE_MATERIAL,
        }

        return mapping.get(
            primitive,
            fallback
            or ConstraintPriority.P4_GEOMETRY,
        )

    @staticmethod
    def _looks_like_opening_path(
        path: str,
    ) -> bool:
        tokens = {
            "opening",
            "openings",
            "window",
            "windows",
            "door",
            "doors",
            "glazing",
            "portlight",
            "portlights",
        }

        parts = {
            part.lower()
            for part in path.split(".")
        }

        return bool(
            parts & tokens
        )

    @staticmethod
    def _matches_any_path(
        candidate: str,
        declared: set[str],
    ) -> bool:
        for path in declared:
            if candidate == path:
                return True

            if candidate.startswith(
                path + "."
            ):
                return True

        return False

    @staticmethod
    def _deduplicate(
        constraints: list[Constraint],
    ) -> list[Constraint]:
        result: dict[
            str,
            Constraint,
        ] = {}

        for item in constraints:
            existing = result.get(
                item.id
            )

            if existing is None:
                result[item.id] = item
                continue

            if existing != item:
                raise ConstraintNormalizationError(
                    "Same stable constraint ID "
                    "resolved to different content: "
                    f"{item.id}"
                )

        return list(
            result.values()
        )
```

---

# 11.22 Có một điểm cần giải thích về Dimensions

Tôi cố ý không map:

```text
dimensions.length_m
→ COUNT
```

hay:

```text
length_m → GEOMETRY
```

Dimensions mặc định được normalize thành:

```text
PROPORTION / dimensional geometry context
```

ở `P3`.

Nhưng nếu Canonical invariant nói:

```json
{
  "source_path": "dimensions.length_m",
  "constraint_type": "geometry",
  "severity": "hard"
}
```

thì invariant nâng nó thành:

```text
GEOMETRY
HARD
```

Đây là cách đúng vì **Canonical quyết định semantics**, Python không đoán.

---

# 11.23 `one_to_one` nuance

Canonical relationships có:

```text
one_to_one
```

nhưng Core `constraintPrimitive` enum không có `one_to_one`.

Do đó Python không được tạo enum mới lén lút.

V1 normalization:

```text
one_to_one
→ COUNT
```

vì render implication chủ yếu là cardinality correspondence.

Nhưng complete relationship object vẫn còn:

```json
{
  "type": "one_to_one",
  "source_path": "...",
  "target_path": "..."
}
```

Trong `Constraint.value`.

Part 12 PromptCompiler có thể viết:

```text
one corresponding X for each Y
```

chính xác.

---

# 11.24 Asset projection pipeline service

Ta cần một façade duy nhất.

Tạo:

```text
media_runtime/projection/service.py
```

```python
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.canonical.frozen_json import (
    FrozenCanonicalLoader,
)
from media_runtime.canonical.revision_envelope import (
    FrozenCanonicalRevisionEnvelope,
)
from media_runtime.constraints.constraint_set import (
    ConstraintSet,
)
from media_runtime.constraints.normalizer import (
    ConstraintNormalizer,
)
from media_runtime.projection.asset_projection import (
    AssetProjection,
)
from media_runtime.projection.projector import (
    AssetProjector,
)
from media_runtime.projection.request import (
    AssetProjectionRequest,
)


@dataclass(
    frozen=True,
    slots=True,
)
class ProjectionResult:
    projection: AssetProjection

    constraints: ConstraintSet


class CanonicalAssetProjectionService:
    def __init__(
        self,
        canonical_loader: FrozenCanonicalLoader,
        projector: AssetProjector,
        normalizer: ConstraintNormalizer,
    ) -> None:
        self._canonical_loader = (
            canonical_loader
        )

        self._projector = projector
        self._normalizer = normalizer

    def build(
        self,
        *,
        revision: (
            FrozenCanonicalRevisionEnvelope
        ),
        request: AssetProjectionRequest,
    ) -> ProjectionResult:
        canonical = (
            self._canonical_loader.load(
                revision
            )
        )

        projection = (
            self._projector.project(
                canonical,
                request,
            )
        )

        constraints = (
            self._normalizer.normalize(
                projection
            )
        )

        if (
            constraints.source_canonical_hash
            != revision.metadata.canonical_hash
        ):
            raise RuntimeError(
                "ConstraintSet canonical hash "
                "lineage mismatch"
            )

        return ProjectionResult(
            projection=projection,
            constraints=constraints,
        )
```

---

# 11.25 Bootstrap

`media_runtime/bootstrap.py`

```python
from __future__ import annotations

from media_runtime.canonical.frozen_json import (
    FrozenCanonicalLoader,
)
from media_runtime.canonical.integrity import (
    CanonicalIntegrityVerifier,
)
from media_runtime.constraints.normalizer import (
    ConstraintNormalizer,
)
from media_runtime.constraints.path_resolver import (
    CanonicalPathResolver,
)
from media_runtime.projection.policy_registry import (
    default_projection_policy_registry,
)
from media_runtime.projection.projector import (
    AssetProjector,
)
from media_runtime.projection.service import (
    CanonicalAssetProjectionService,
)


def build_asset_projection_service(
) -> CanonicalAssetProjectionService:
    verifier = (
        CanonicalIntegrityVerifier()
    )

    loader = FrozenCanonicalLoader(
        verifier=verifier
    )

    path_resolver = (
        CanonicalPathResolver()
    )

    policies = (
        default_projection_policy_registry()
    )

    projector = AssetProjector(
        policy_registry=policies,
        path_resolver=path_resolver,
    )

    normalizer = (
        ConstraintNormalizer()
    )

    return CanonicalAssetProjectionService(
        canonical_loader=loader,
        projector=projector,
        normalizer=normalizer,
    )
```

---

# 11.26 HTTP/request transport DTO

Nếu Laravel gọi Python HTTP worker, endpoint nhận:

```python
from __future__ import annotations

from pydantic import (
    BaseModel,
    ConfigDict,
)

from media_runtime.canonical.revision_envelope import (
    FrozenCanonicalRevisionEnvelope,
)
from media_runtime.projection.request import (
    AssetProjectionRequest,
)


class BuildAssetProjectionPayload(
    BaseModel
):
    model_config = ConfigDict(
        extra="forbid",
        strict=True,
    )

    canonical_revision: (
        FrozenCanonicalRevisionEnvelope
    )

    asset_request: (
        AssetProjectionRequest
    )
```

---

# 11.27 Laravel payload nên gửi metadata như thế nào?

Từ frozen revision:

```php
return [
    'canonical_revision' => [
        'metadata' => [
            'revision_id' =>
                $revision->id,

            'project_id' =>
                $revision->video_project_id,

            'revision' =>
                $revision->revision,

            'canonical_hash' =>
                $revision->canonical_hash,

            'schema_version' =>
                $revision
                    ->canonical_schema_version,

            'profile_key' =>
                $revision->profile_key,

            'profile_version' =>
                $revision->profile_version,

            'effective_schema_hash' =>
                $revision
                    ->effective_schema_hash,

            'normalizer_version' =>
                $revision
                    ->normalizer_version,

            'canonicalizer_version' =>
                $revision
                    ->canonicalizer_version,
        ],

        /*
         * EXACT string from DB.
         *
         * Do not json_decode then json_encode.
         */
        'canonical_json' =>
            $revision->canonical_json,
    ],

    'asset_request' =>
        $assetRequest,
];
```

Điểm cực kỳ quan trọng:

```php
'canonical_json' => $revision->canonical_json
```

không:

```php
json_encode(
    json_decode(
        $revision->canonical_json,
        true
    )
)
```

vì có thể đổi bytes/hash.

---

# 11.28 Output artifact từ Python

Python nên persist derived artifact, ví dụ:

```text
work/artifacts/
{session_code}/
{run_id}/
canonical/
    revision.json
projection/
    master_vessel.projection.json
constraints/
    master_vessel.constraints.json
```

Không ghi lại canonical như design mới.

Nếu cần debug copy:

```text
canonical/revision.json
```

chỉ là exact input snapshot.

---

# 11.29 Serialize `AssetProjection`

Do chứa MappingProxyType/tuple, cần helper.

`projection/serialization.py`

```python
from __future__ import annotations

import json

from dataclasses import asdict
from enum import Enum
from types import MappingProxyType
from typing import Any


def to_plain_json_value(
    value: Any,
) -> Any:
    if isinstance(
        value,
        MappingProxyType,
    ):
        return {
            key: to_plain_json_value(child)
            for key, child in value.items()
        }

    if isinstance(value, dict):
        return {
            key: to_plain_json_value(child)
            for key, child in value.items()
        }

    if isinstance(
        value,
        (
            tuple,
            list,
        ),
    ):
        return [
            to_plain_json_value(item)
            for item in value
        ]

    if isinstance(value, Enum):
        return value.value

    return value


def json_bytes(
    value: Any,
) -> bytes:
    return json.dumps(
        to_plain_json_value(value),
        ensure_ascii=False,
        separators=(",", ":"),
        sort_keys=True,
    ).encode("utf-8")
```

---

# 11.30 `AssetProjection` hash

Ta nên fingerprint projection.

Thêm method:

```python
import hashlib

from dataclasses import asdict

from media_runtime.projection.serialization import (
    json_bytes,
)


def projection_fingerprint(
    projection: AssetProjection,
) -> str:
    return hashlib.sha256(
        json_bytes(
            asdict(
                projection
            )
        )
    ).hexdigest()
```

Lineage sau này:

```text
canonical_hash
↓
projection_hash
↓
constraint_set_hash
↓
prompt_spec_hash
↓
provider request
```

Rất quan trọng cho Artifact Ledger.

---

# 11.31 Example output `ConstraintSet`

Ví dụ canonical:

```json
{
  "object_type": "superyacht",

  "dimensions": {
    "length_m": 120,
    "beam_m": 20,
    "length_to_beam_ratio": 6.0
  },

  "permanent_geometry": {
    "bow": {
      "stem": "near_plumb",
      "waterline_entry": "fine"
    },

    "superstructure": {
      "primary_tier_count": 4,
      "massing": "continuous"
    }
  },

  "relationships": [
    {
      "id": "R001",
      "type": "count",
      "subject_path": "permanent_geometry.superstructure.primary_tier_count",
      "value": 4
    }
  ],

  "invariants": [
    {
      "id": "I001",
      "name": "Primary tier count",
      "source_path": "permanent_geometry.superstructure.primary_tier_count",
      "constraint_type": "count",
      "severity": "hard",
      "visual_verification": true
    }
  ]
}
```

Python có thể tạo:

```json
{
  "version": "constraint-set-v1",

  "asset_id": "master_vessel",

  "source_canonical_hash": "...",

  "constraints": [
    {
      "primitive": "count",
      "severity": "hard",
      "priority": 1,

      "source_path":
        "permanent_geometry.superstructure.primary_tier_count",

      "value": 4,

      "visual_verification": true
    },

    {
      "primitive": "count",
      "severity": "hard",
      "priority": 1,

      "source_id": "R001",

      "value": {
        "id": "R001",
        "type": "count",
        "subject_path":
          "permanent_geometry.superstructure.primary_tier_count",
        "value": 4
      }
    },

    {
      "primitive": "geometry",
      "priority": 4,

      "source_path":
        "permanent_geometry.bow.stem",

      "value":
        "near_plumb"
    }
  ]
}
```

---

# 11.32 Có duplicate count constraint không?

Trong ví dụ trên có khả năng:

```text
leaf:
primary_tier_count = 4

relationship:
R001 count = 4
```

Đó **không phải duplicate semantic hoàn toàn**.

Một cái là:

```text
canonical field value
```

Một cái là:

```text
explicit relationship assertion
```

Tuy nhiên PromptCompiler Part 12 không nên viết:

```text
exactly 4 tiers
exactly 4 tiers
```

hai lần.

Do đó:

> ConstraintNormalizer giữ lineage đầy đủ. PromptCompiler sẽ coalesce các constraint tương đương khi compile language.

Không nên vứt lineage ở Part 11.

---

# 11.33 Nhưng có thể thêm `semantic_key`

Để Part 12 coalesce tốt hơn, tôi khuyên bổ sung vào `Constraint`:

```python
semantic_key: str
```

Ví dụ:

```text
permanent_geometry.superstructure.primary_tier_count
```

Cả field constraint và R001 có cùng semantic key.

Sửa DTO:

```python
@dataclass(
    frozen=True,
    slots=True,
)
class Constraint:
    id: str

    semantic_key: str

    primitive: ConstraintPrimitive
    severity: ConstraintSeverity
    priority: ConstraintPriority

    source_kind: ConstraintSourceKind

    source_path: str | None
    source_id: str | None
    subject_path: str | None

    value: Any

    visual_verification: bool

    description: str | None = None
    provenance_origin: str | None = None
```

Tôi khuyên **thêm ngay từ Part 11**.

---

# 11.34 Semantic key cho relationship

Helper:

```python
@staticmethod
def _relationship_semantic_key(
    relationship: Mapping[str, Any],
) -> str:
    for key in (
        "subject_path",
        "source_path",
        "container_path",
    ):
        value = relationship.get(
            key
        )

        if isinstance(
            value,
            str,
        ):
            return value

    relationship_id = (
        relationship.get("id")
    )

    return (
        f"relationship:{relationship_id}"
    )
```

Field:

```python
semantic_key=source_path
```

Exclusion:

```python
semantic_key=(
    "exclusion:"
    + target_path
)
```

Camera:

```python
semantic_key="camera.view"
```

State:

```python
semantic_key="state.fabrication_stage"
```

Part 12 dễ group hơn rất nhiều.

Tôi chốt **nên có `semantic_key`**.

---

# 11.35 Provenance trong ConstraintSet

Canonical provenance hiện nằm root:

```text
provenance[]
```

AssetProjection hiện chưa carry provenance.

Ta cần vì PromptCompiler/QA sau này nên biết:

```text
inspired
invented
```

không phải để thay prompt style, nhưng để lineage/audit.

Thêm vào `AssetProjection`:

```python
provenance: tuple[
    FrozenJsonValue,
    ...,
]
```

Policy:

```python
include_provenance=True
```

cho tất cả asset types.

---

# 11.36 Update policy

Thêm:

```python
include_provenance: bool
```

và mọi default:

```python
include_provenance=True
```

AssetProjector:

```python
provenance = (
    self._tuple(
        root,
        "provenance",
    )
    if policy.include_provenance
    else ()
)
```

---

# 11.37 Provenance index

Trong normalizer:

```python
def _provenance_index(
    self,
    projection: AssetProjection,
) -> dict[str, dict]:
    result: dict[
        str,
        dict,
    ] = {}

    for entry in projection.provenance:
        if not isinstance(
            entry,
            Mapping,
        ):
            continue

        target_path = entry.get(
            "target_path"
        )

        if not isinstance(
            target_path,
            str,
        ):
            continue

        result[target_path] = (
            self._plain_value(
                entry
            )
        )

    return result
```

Sau đó `_make_leaf_constraint()`:

```python
provenance = provenance_index.get(
    source_path
)

origin = (
    provenance.get("origin")
    if provenance
    else None
)
```

Constraint:

```python
provenance_origin=origin
```

---

# 11.38 Không dùng provenance để giảm độ ưu tiên

Sai:

```text
invented → soft
inspired → hard
```

Không đúng.

`origin` chỉ trả lời:

```text
design decision came from where?
```

Hard/soft đến từ:

```text
invariant
relationship
asset required path
canonical semantics
```

Không từ provenance.

---

# 11.39 Tests — hash mismatch

`tests/test_canonical_integrity.py`

```python
import hashlib

import pytest

from media_runtime.canonical.exceptions import (
    CanonicalIntegrityError,
)
from media_runtime.canonical.integrity import (
    CanonicalIntegrityVerifier,
)
from media_runtime.canonical.revision_envelope import (
    CanonicalRevisionMetadata,
    FrozenCanonicalRevisionEnvelope,
)


def test_rejects_tampered_canonical_json():
    original = (
        '{"schema_version":"1.0",'
        '"object_type":"superyacht"}'
    )

    digest = hashlib.sha256(
        original.encode("utf-8")
    ).hexdigest()

    envelope = (
        FrozenCanonicalRevisionEnvelope(
            metadata=(
                CanonicalRevisionMetadata(
                    revision_id="r1",
                    project_id="p1",
                    revision=1,

                    canonical_hash=digest,

                    schema_version="1.0",

                    profile_key=(
                        "marine_vessel"
                    ),

                    profile_version="1.0",

                    effective_schema_hash=(
                        "a" * 64
                    ),

                    normalizer_version=(
                        "canonical-normalizer-v1"
                    ),

                    canonicalizer_version=(
                        "canonical-json-v1"
                    ),
                )
            ),

            canonical_json=(
                '{"schema_version":"1.0",'
                '"object_type":"aircraft"}'
            ),
        )
    )

    with pytest.raises(
        CanonicalIntegrityError
    ):
        CanonicalIntegrityVerifier().verify(
            envelope
        )
```

---

# 11.40 Test whitespace cũng làm hash fail

Rất quan trọng:

```python
def test_hash_is_over_exact_bytes():
    original = '{"a":1}'

    digest = hashlib.sha256(
        original.encode("utf-8")
    ).hexdigest()

    tampered = '{ "a": 1 }'

    assert (
        hashlib.sha256(
            tampered.encode("utf-8")
        ).hexdigest()
        != digest
    )
```

Python không được nói:

```text
JSON semantic giống nhau nên okay.
```

Không.

Frozen byte integrity là exact.

---

# 11.41 Test canonical is immutable

```python
import pytest


def test_loaded_canonical_root_is_immutable(
    loaded_document,
):
    with pytest.raises(
        TypeError
    ):
        loaded_document.data[
            "object_type"
        ] = "aircraft"
```

Nested:

```python
def test_nested_canonical_is_immutable(
    loaded_document,
):
    geometry = (
        loaded_document.data[
            "permanent_geometry"
        ]
    )

    bow = geometry["bow"]

    with pytest.raises(
        TypeError
    ):
        bow["stem"] = "changed"
```

---

# 11.42 Test tuple arrays immutable

```python
def test_canonical_arrays_become_tuples(
    loaded_document,
):
    relationships = (
        loaded_document.data[
            "relationships"
        ]
    )

    assert isinstance(
        relationships,
        tuple,
    )
```

---

# 11.43 Test projector does not mutate canonical

```python
def test_projection_does_not_mutate_canonical(
    frozen_document,
    projector,
    anchor_request,
):
    before = (
        frozen_document.canonical_hash
    )

    projector.project(
        frozen_document,
        anchor_request,
    )

    after = (
        frozen_document.canonical_hash
    )

    assert before == after
```

Hash string alone doesn't prove data unchanged, but MappingProxy/tuple does the actual protection.

---

# 11.44 Test identity anchor includes all permanent geometry

```python
def test_identity_anchor_projects_geometry(
    service,
    canonical_envelope,
    anchor_request,
):
    result = service.build(
        revision=canonical_envelope,
        request=anchor_request,
    )

    assert (
        result.projection
        .permanent_geometry
        is not None
    )

    assert (
        result.projection
        .relationships
    )
```

---

# 11.45 Test construction projection excludes finished material

```python
def test_construction_projection_does_not_apply_finished_materials(
    service,
    canonical_envelope,
    construction_request,
):
    result = service.build(
        revision=canonical_envelope,
        request=construction_request,
    )

    assert (
        result.projection
        .finished_materials
        is None
    )
```

Lưu ý wording:

> doesn't apply finished materials.

Không có nghĩa canonical materials biến mất khỏi nguồn.

Nguồn vẫn nguyên vẹn.

---

# 11.46 Test hard invariant upgrades geometry

Canonical:

```json
{
  "id": "I001",
  "source_path":
    "permanent_geometry.bow.stem",
  "constraint_type": "geometry",
  "severity": "hard",
  "visual_verification": true
}
```

Test:

```python
def test_invariant_upgrades_constraint_to_hard(
    constraint_set,
):
    target = next(
        item
        for item in (
            constraint_set.constraints
        )
        if item.source_path
        == "permanent_geometry.bow.stem"
    )

    assert (
        target.severity.value
        == "hard"
    )

    assert (
        target.visual_verification
        is True
    )
```

---

# 11.47 Count gets P1

```python
def test_count_relationship_is_p1(
    constraint_set,
):
    count_constraint = next(
        item
        for item
        in constraint_set.constraints
        if (
            item.primitive.value
            == "count"
            and item.source_kind.value
            == "relationship"
        )
    )

    assert (
        int(
            count_constraint.priority
        )
        == 1
    )
```

---

# 11.48 Connectivity gets P2

```python
def test_connectivity_is_p2(
    constraint_set,
):
    item = next(
        c
        for c in constraint_set.constraints
        if c.primitive.value
        == "connectivity"
    )

    assert int(
        item.priority
    ) == 2
```

---

# 11.49 Material gets P6

```python
def test_material_is_p6(
    constraint_set,
):
    item = next(
        c
        for c in constraint_set.constraints
        if c.source_kind.value
        == "material"
    )

    assert int(
        item.priority
    ) == 6
```

---

# 11.50 Camera luôn đứng sau identity geometry

```python
def test_camera_has_lower_priority_than_identity(
    constraint_set,
):
    identity = [
        c
        for c in constraint_set.constraints
        if c.priority == 0
    ]

    camera = [
        c
        for c in constraint_set.constraints
        if c.priority == 7
    ]

    assert identity
    assert camera
```

Điều này giúp Part 12 không hi sinh geometry để phục vụ composition.

---

# 11.51 Required path nâng thành HARD

Ví dụ Laravel nói asset này đặc biệt phải chứng minh:

```text
permanent_geometry.bow.stem
```

Request:

```json
{
  "required_paths": [
    "permanent_geometry.bow.stem"
  ]
}
```

Normalizer nâng constraint đó:

```text
SOFT → HARD
```

Nhưng không đổi canonical invariant.

Đây là:

```text
asset-level preservation requirement
```

không phải:

```text
canonical design invariant
```

Rất quan trọng phải phân biệt.

---

# 11.52 `excluded_paths` không phải Canonical Exclusion

Hai khái niệm khác nhau:

Canonical:

```json
{
  "id": "E001",
  "target_path": "...",
  "forbid": "..."
}
```

nghĩa là:

```text
design MUST NOT contain X
```

AssetProjectionRequest:

```json
"excluded_paths": [
  "finished_materials"
]
```

nghĩa là:

```text
asset hiện tại không cần project section đó
```

Không được convert:

```text
excluded_paths → negative prompt
```

Chỉ `Canonical exclusion` mới tạo `EXCLUSION constraint`.

---

# 11.53 `state` overlay

Ví dụ construction reference:

```json
{
  "state": {
    "fabrication_stage":
      "structurally_complete_unfinished",

    "surface_state":
      "bare_unpainted_plating",

    "temporary_equipment":
      "allowed"
  }
}
```

Đây không mutate:

```text
finished_materials
```

Canonical có thể nói finished hull:

```text
deep anthracite satin
```

Asset state nói:

```text
bare unpainted plate
```

Hai cái không contradiction vì:

```text
Canonical finished_materials
= permanent final identity

Asset requested_state
= temporal rendering state
```

Part 12 compiler phải biết precedence theo asset type:

```text
current-state visibility
>
finished material visibility
```

mà không sửa canonical truth.

---

# 11.54 State precedence

Tôi khuyên khóa từ Part 11:

```text
Canonical geometry
       ALWAYS preserved

Canonical topology/count
       ALWAYS preserved

Requested temporary state
       controls current appearance

Canonical finished materials
       applied only when compatible
       with requested state
```

Ví dụ:

```text
final asset:
finished material visible

construction asset:
finished material not currently visible
```

nhưng hull geometry vẫn cùng hull.

Đây chính là cách duy trì identity từ:

```text
fabrication
→ completion
→ launch
→ operation
```

---

# 11.55 Không đưa temporary scenery vào Canonical

State request có thể chứa:

```text
construction stage
surface state
temporary equipment policy
```

Scene plan sau này có thể có:

```text
shipyard hall
workers
crane
scaffolding
lighting
weather
```

Những thứ đó phải ở:

```text
SceneSpec / WorldState
```

không:

```text
CanonicalDesignSpec
```

Part 11 chỉ chuẩn bị boundary.

---

# 11.56 AssetProjection nên persist JSON

Ví dụ output:

```json
{
  "projection_version":
    "asset-projection-v1",

  "asset_id":
    "master_vessel",

  "asset_type":
    "identity_anchor",

  "source_revision_id":
    "...",

  "source_canonical_hash":
    "...",

  "object_type":
    "superyacht",

  "profile_key":
    "marine_vessel",

  "required_paths": [],

  "camera": {
    "view":
      "front_three_quarter"
  },

  "identity": {...},

  "dimensions": {...},

  "permanent_geometry": {...}
}
```

Artifact:

```text
master_vessel.projection.json
```

---

# 11.57 ConstraintSet persist JSON

```text
master_vessel.constraints.json
```

Metadata nên có:

```json
{
  "constraint_set_version":
    "constraint-set-v1",

  "canonical_hash":
    "...",

  "projection_hash":
    "...",

  "constraint_set_hash":
    "...",

  "constraints":
    [...]
}
```

Part 12 đọc exact artifact này.

---

# 11.58 Artifact lineage

Từ Part 11, ta bắt đầu có chain hash rõ ràng:

```text
canonical_json
     │
     │ SHA256
     ▼
canonical_hash
     │
     ▼
AssetProjection
     │
     │ SHA256
     ▼
projection_hash
     │
     ▼
ConstraintSet
     │
     │ SHA256
     ▼
constraint_set_hash
```

Sau Part 12:

```text
constraint_set_hash
        ↓
PromptSpec
        ↓
prompt_spec_hash
        ↓
compiled_prompt
        ↓
prompt_hash
```

Sau render:

```text
prompt_hash
provider params
reference hashes
        ↓
render request
        ↓
render artifact
```

Đây đúng với Artifact Ledger trước đó.

---

# 11.59 Không dùng model AI ở Part 11

Toàn bộ Part 11 là:

```text
deterministic Python
```

Không:

```text
GPT
Claude
Gemini
```

AssetProjection và ConstraintNormalizer phải tạo cùng output cho cùng input.

Đây là điểm quan trọng vì nếu bạn để LLM ở đây:

```text
Canonical
↓
AI "interpret"
↓
constraints
```

thì Canonical source-of-truth mất ý nghĩa.

---

# 11.60 Không có profile-specific Python projector V1

Không tạo:

```text
MarineAssetProjector
AircraftAssetProjector
ArchitectureAssetProjector
```

V1 dùng:

```text
AssetProjector
+
universal canonical structure
+
AssetProjectionPolicy
```

Profile-specific logic chỉ thêm khi thật sự không thể biểu diễn bằng universal primitive.

Ví dụ:

```text
marine_vessel
aircraft
architecture
```

đều có thể translate:

```text
COUNT
PROPORTION
POSITION
ORDER
CONNECTIVITY
CONTINUITY
MATERIAL
GEOMETRY
EXCLUSION
...
```

bằng cùng `ConstraintNormalizer`.

Đây chính là mục tiêu scale 10.000 topics.

---

# 11.61 Không lặp lại CategorySemanticValidator ở Python

Laravel đã làm:

```text
marine ratio validation
profile compatibility
provenance validation
cross-field validation
```

Python không làm lại:

```python
assert length / beam == ratio
```

Nếu Python phát hiện frozen canonical contradictory thì đó là bug upstream/version mismatch.

Part 11 chỉ verify:

```text
hash integrity
structural runtime assumptions
```

không làm semantic repair.

---

# 11.62 Laravel → Python handoff final

Tôi chốt contract:

```text
Laravel Truth Layer
        │
        │
        ├── revision_id
        ├── canonical_json
        ├── canonical_hash
        ├── schema_version
        ├── profile_key/version
        ├── effective_schema_hash
        └── asset request
                │
                ▼
Python
        │
        ├── verify canonical_hash
        │
        ├── freeze decoded tree
        │
        ├── AssetProjector
        │
        ├── ConstraintNormalizer
        │
        └── persist derived artifacts
```

Python không cần:

```text
MariaDB credentials
Laravel models
concept revision repository
InspirationBrief
Sonnet
Haiku
source article
```

để compile một frozen asset.

Đó là isolation rất tốt.

---

# 11.63 Final production pipeline sau Part 11

```text
ARTICLE
  ↓
Haiku Evidence
  ↓
Verifier
  ↓
InspirationBrief
  ↓
Category Profile
  ↓
Sonnet 5
  ↓
Canonical Core + Effective Schema
  ↓
Semantic Validation
  ↓
Repair ≤ 1
  ↓
Normalization
  ↓
Schema-aware serialization
  ↓
Revalidation
  ↓
SHA-256
  ↓
DB Freeze
════════════════════════════════════
        LARAVEL TRUTH BOUNDARY
════════════════════════════════════
  ↓
canonical_json
canonical_hash
revision metadata
asset request
  ↓
PYTHON
  ↓
CanonicalIntegrityVerifier
  ↓
FrozenCanonicalDocument
  ↓
AssetProjector
  ↓
AssetProjection
  ↓
ConstraintNormalizer
  ↓
ConstraintSet
  │
  ├── P0 Identity
  ├── P1 Count
  ├── P2 Topology
  ├── P3 Proportion
  ├── P4 Geometry
  ├── P5 Openings
  ├── P6 State / Material
  └── P7 Camera
  ↓
ConstraintSet Hash
  ↓
════════════════════════════════════
        PHẦN 12
════════════════════════════════════
PromptSpec
  ↓
Prompt Compiler
  ↓
Provider Adapter
  ↓
GPT Image / Gemini / ...
```

## Có 7 invariant tôi chốt sau Phần 11

```text
1. Python không query canonical design từ DB.

2. Python hash exact canonical_json trước khi decode.

3. Hash mismatch → fail ngay, không repair.

4. Canonical decoded tree là immutable.

5. AssetProjection là derived artifact,
   không phải design truth.

6. ConstraintNormalizer chỉ translate
   explicit canonical semantics;
   không phát minh relationship mới.

7. Mọi downstream artifact giữ:
   source_revision_id + source_canonical_hash.
```

Và boundary quan trọng nhất:

```text
CanonicalDesignSpec
      =
WHAT THE OBJECT IS

AssetProjection
      =
WHICH PART OF THAT TRUTH
THIS ASSET NEEDS

ConstraintSet
      =
HOW THAT PROJECTED TRUTH
IS EXPRESSED AS MACHINE-READABLE
RENDER CONSTRAINTS

PromptSpec
      =
HOW THOSE CONSTRAINTS
WILL BE COMMUNICATED TO
A PARTICULAR GENERATOR
```

**Phần 12 tiếp theo nên là `PromptSpec + PromptCompilerV1 + ConstraintCoalescer + ProviderCapabilityProjection`.** Đây là nơi ta biến `ConstraintSet` thành prompt có thứ tự P0→P8, nhưng vẫn giữ `PromptCompiler` provider-independent trước, sau đó mới có adapter riêng cho GPT Image / Gemini / các image provider.
