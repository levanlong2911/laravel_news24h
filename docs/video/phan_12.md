Được. **Phần 12** sẽ nối trực tiếp với output của Phần 11 và tôi sẽ không thay đổi ownership đã khóa:

```text
Laravel
    = Canonical Design Truth

Python AssetProjection
    = asset-specific view of truth

ConstraintSet
    = machine-readable render constraints

PromptSpec
    = provider-independent prompt IR

ProviderCapabilityProjection
    = map PromptSpec vào khả năng provider

Provider Adapter
    = request thật tới GPT Image / Gemini / ...
```

Điểm quan trọng nhất của Phần 12 là **không compile thẳng `ConstraintSet → một chuỗi prompt`**. Ta cần một IR trung gian để tránh sau này mỗi provider có một prompt compiler riêng và hệ thống lại phân nhánh.

Flow chính thức:

```text
Frozen Canonical Revision
        ↓
AssetProjection
        ↓
ConstraintSet
        ↓
ConstraintCoalescer
        ↓
CoalescedConstraintSet
        ↓
PromptCompilerV1
        ↓
PromptSpec
        ↓
ProviderCapabilityProjection
        ↓
ProviderPromptPlan
        ↓
Deterministic Text Renderer
        ↓
CompiledPrompt
        ↓
Provider Adapter
```

---

# PHẦN 12 — Cấu trúc code

Tôi chốt cấu trúc:

```text
media_runtime/
├── canonical/
├── projection/
├── constraints/
│
├── prompt/
│   ├── __init__.py
│   │
│   ├── enums.py
│   ├── exceptions.py
│   │
│   ├── coalescing/
│   │   ├── __init__.py
│   │   ├── coalesced_constraint.py
│   │   ├── coalesced_constraint_set.py
│   │   └── constraint_coalescer.py
│   │
│   ├── spec/
│   │   ├── __init__.py
│   │   ├── instruction.py
│   │   ├── section.py
│   │   ├── presentation_intent.py
│   │   └── prompt_spec.py
│   │
│   ├── renderers/
│   │   ├── __init__.py
│   │   ├── base.py
│   │   ├── count.py
│   │   ├── proportion.py
│   │   ├── position.py
│   │   ├── topology.py
│   │   ├── geometry.py
│   │   ├── material.py
│   │   ├── state.py
│   │   ├── exclusion.py
│   │   ├── camera.py
│   │   └── registry.py
│   │
│   ├── compiler.py
│   │
│   ├── capabilities/
│   │   ├── __init__.py
│   │   ├── capability.py
│   │   ├── profile.py
│   │   ├── registry.py
│   │   ├── projection.py
│   │   └── provider_prompt_plan.py
│   │
│   ├── text_renderer.py
│   ├── compiled_prompt.py
│   ├── hashing.py
│   └── service.py
│
└── tests/
    └── prompt/
        ├── test_constraint_coalescer.py
        ├── test_prompt_compiler.py
        ├── test_capability_projection.py
        ├── test_prompt_text_renderer.py
        ├── test_prompt_hash.py
        └── test_prompt_lineage.py
```

---

# 12.1 Hai chỉnh sửa nhỏ từ Phần 11 phải chốt trước

Phần 11 cuối cùng tôi đã đề xuất thêm:

```python
semantic_key
```

vào `Constraint`.

Ta **chốt chính thức từ Part 12**, vì `ConstraintCoalescer` cần nó.

`constraints/constraint.py` final:

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

Tất cả nơi khởi tạo `Constraint(...)` ở Part 11 phải thêm `semantic_key`.

Ví dụ geometry leaf:

```python
semantic_key=source_path,
```

relationship:

```python
semantic_key=(
    self._relationship_semantic_key(
        relationship
    )
),
```

camera:

```python
semantic_key=(
    f"camera.{key}"
),
```

state:

```python
semantic_key=(
    f"state.{key}"
),
```

exclusion:

```python
semantic_key=(
    f"exclusion:{target_path}"
),
```

Đây **không thay Canonical contract**. Chỉ bổ sung metadata cho derived `ConstraintSet`.

---

# 12.2 Provenance phải có trong `AssetProjection`

Chốt thêm:

```python
provenance: tuple[
    FrozenJsonValue,
    ...,
]
```

vào:

```python
AssetProjection
```

và tất cả policy:

```python
include_provenance=True
```

Điều này chỉ phục vụ lineage/audit.

Không dùng:

```text
inspired = hard
invented = soft
```

Provenance hoàn toàn không quyết định severity.

---

# 12.3 Prompt enums

`prompt/enums.py`

```python
from __future__ import annotations

from enum import IntEnum, StrEnum


class PromptSectionKind(StrEnum):
    IDENTITY = "identity"

    COUNT_TOPOLOGY = (
        "count_topology"
    )

    PROPORTIONS = "proportions"

    GEOMETRY = "geometry"

    OPENINGS = "openings"

    STATE_MATERIAL = (
        "state_material"
    )

    CAMERA = "camera"

    PRESENTATION = "presentation"

    EXCLUSIONS = "exclusions"


class PromptInstructionSeverity(StrEnum):
    HARD = "hard"
    SOFT = "soft"


class DeliveryChannel(StrEnum):
    TEXT_PROMPT = "text_prompt"

    NEGATIVE_PROMPT = (
        "negative_prompt"
    )

    NATIVE_CONTROL = (
        "native_control"
    )

    REFERENCE_INPUT = (
        "reference_input"
    )

    OMITTED = "omitted"


class CapabilitySupport(StrEnum):
    SUPPORTED = "supported"

    UNSUPPORTED = "unsupported"

    PROMPT_ONLY = "prompt_only"


class CapabilityFailurePolicy(StrEnum):
    FAIL_HARD = "fail_hard"

    WARN_AND_PROMPT = (
        "warn_and_prompt"
    )

    WARN_AND_DROP_SOFT = (
        "warn_and_drop_soft"
    )


class PromptWarningSeverity(StrEnum):
    INFO = "info"
    WARNING = "warning"
    ERROR = "error"
```

---

# 12.4 Exceptions

`prompt/exceptions.py`

```python
from __future__ import annotations


class PromptPipelineError(RuntimeError):
    """Base prompt pipeline error."""


class ConstraintCoalescingError(
    PromptPipelineError
):
    """Constraints cannot be safely coalesced."""


class PromptCompilationError(
    PromptPipelineError
):
    """ConstraintSet could not be compiled."""


class ProviderCapabilityError(
    PromptPipelineError
):
    """Provider cannot satisfy required prompt semantics."""


class PromptSerializationError(
    PromptPipelineError
):
    """Prompt IR could not be serialized deterministically."""
```

---

# 12.5 Tại sao cần `ConstraintCoalescer`

Ví dụ từ Part 11:

```text
Constraint A
source_path:
permanent_geometry.superstructure.primary_tier_count
value = 4

Constraint B
relationship R001
type = count
subject_path:
permanent_geometry.superstructure.primary_tier_count
value = 4

Invariant I001
cũng bảo:
count HARD
```

Nếu compile thẳng:

```text
The superstructure has exactly four tiers.
The superstructure must have exactly four tiers.
Maintain exactly four superstructure tiers.
```

Provider nhận 3 câu cùng nghĩa.

Không tốt.

Nhưng nếu vứt B/I001:

```text
mất lineage.
```

Giải pháp:

```text
3 source constraints
      ↓
1 CoalescedConstraint
      ├── effective semantics
      └── source_constraint_ids = [A,B,I001...]
```

---

# 12.6 `CoalescedAssertion`

`prompt/coalescing/coalesced_constraint.py`

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
class ConstraintAssertion:
    """
    One source assertion retained for lineage.

    Coalescing never destroys the original
    semantic assertions.
    """

    constraint_id: str

    source_kind: ConstraintSourceKind

    source_path: str | None

    source_id: str | None

    primitive: ConstraintPrimitive

    value: Any


@dataclass(
    frozen=True,
    slots=True,
)
class CoalescedConstraint:
    """
    Effective constraint passed to PromptCompiler.
    """

    semantic_key: str

    primitive: ConstraintPrimitive

    severity: ConstraintSeverity

    priority: ConstraintPriority

    visual_verification: bool

    effective_value: Any

    assertions: tuple[
        ConstraintAssertion,
        ...,
    ]

    provenance_origins: tuple[
        str,
        ...,
    ]

    source_constraint_ids: tuple[
        str,
        ...,
    ]

    description: str | None = None
```

---

# 12.7 Coalesced set

`prompt/coalescing/coalesced_constraint_set.py`

```python
from __future__ import annotations

import hashlib
import json

from dataclasses import (
    asdict,
    dataclass,
)
from enum import Enum
from typing import Any

from media_runtime.prompt.coalescing.coalesced_constraint import (
    CoalescedConstraint,
)


@dataclass(
    frozen=True,
    slots=True,
)
class CoalescedConstraintSet:
    version: str

    asset_id: str

    source_revision_id: str

    source_canonical_hash: str

    source_constraint_set_hash: str

    constraints: tuple[
        CoalescedConstraint,
        ...,
    ]

    def fingerprint(
        self,
    ) -> str:
        payload = {
            "version":
                self.version,

            "asset_id":
                self.asset_id,

            "source_revision_id":
                self.source_revision_id,

            "source_canonical_hash":
                self.source_canonical_hash,

            "source_constraint_set_hash":
                self.source_constraint_set_hash,

            "constraints": [
                self._plain(
                    asdict(item)
                )
                for item in self.constraints
            ],
        }

        raw = json.dumps(
            payload,
            ensure_ascii=False,
            separators=(",", ":"),
            sort_keys=True,
        ).encode("utf-8")

        return hashlib.sha256(
            raw
        ).hexdigest()

    @classmethod
    def _plain(
        cls,
        value: Any,
    ) -> Any:
        if isinstance(value, Enum):
            return value.value

        if isinstance(value, dict):
            return {
                key: cls._plain(child)
                for key, child
                in value.items()
            }

        if isinstance(
            value,
            (
                tuple,
                list,
            ),
        ):
            return [
                cls._plain(item)
                for item in value
            ]

        return value
```

---

# 12.8 Coalescing policy

Không merge chỉ vì text giống nhau.

Merge dựa trên:

```text
semantic_key
+
compatible semantic primitive
```

Ví dụ:

```text
permanent_geometry.superstructure.primary_tier_count
```

là identity semantic key.

Nếu có:

```text
GEOMETRY
COUNT
```

thì COUNT phải thắng vì nó cụ thể hơn.

Priority/specificity:

```text
COUNT
GROUPING
CONNECTIVITY
CONTINUITY
CONTAINMENT
ORDER
ALIGNMENT
SYMMETRY
PROPORTION
VISIBILITY
POSITION
GEOMETRY
LAYOUT
MATERIAL
STATE
EXCLUSION
```

Nhưng ta không được tự convert MATERIAL ↔ GEOMETRY chẳng hạn.

---

# 12.9 `ConstraintCoalescer`

`prompt/coalescing/constraint_coalescer.py`

```python
from __future__ import annotations

import json

from collections import defaultdict
from dataclasses import replace
from enum import Enum
from typing import Any

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
)
from media_runtime.prompt.coalescing.coalesced_constraint import (
    CoalescedConstraint,
    ConstraintAssertion,
)
from media_runtime.prompt.coalescing.coalesced_constraint_set import (
    CoalescedConstraintSet,
)
from media_runtime.prompt.exceptions import (
    ConstraintCoalescingError,
)


class ConstraintCoalescer:
    VERSION = "constraint-coalescer-v1"

    _SPECIFICITY: dict[
        ConstraintPrimitive,
        int,
    ] = {
        ConstraintPrimitive.COUNT: 100,
        ConstraintPrimitive.GROUPING: 95,

        ConstraintPrimitive.CONNECTIVITY: 90,
        ConstraintPrimitive.CONTINUITY: 90,
        ConstraintPrimitive.CONTAINMENT: 90,
        ConstraintPrimitive.ORDER: 90,
        ConstraintPrimitive.ALIGNMENT: 90,
        ConstraintPrimitive.SYMMETRY: 90,

        ConstraintPrimitive.PROPORTION: 80,

        ConstraintPrimitive.VISIBILITY: 75,

        ConstraintPrimitive.POSITION: 70,
        ConstraintPrimitive.GEOMETRY: 65,
        ConstraintPrimitive.LAYOUT: 65,

        ConstraintPrimitive.MATERIAL: 60,
        ConstraintPrimitive.STATE: 60,

        ConstraintPrimitive.EXCLUSION: 100,
    }

    def coalesce(
        self,
        constraint_set: ConstraintSet,
    ) -> CoalescedConstraintSet:
        groups: dict[
            str,
            list[Constraint],
        ] = defaultdict(list)

        for constraint in (
            constraint_set.constraints
        ):
            if not constraint.semantic_key:
                raise ConstraintCoalescingError(
                    "Constraint semantic_key "
                    f"is empty: {constraint.id}"
                )

            groups[
                constraint.semantic_key
            ].append(
                constraint
            )

        result: list[
            CoalescedConstraint
        ] = []

        for semantic_key in sorted(
            groups.keys()
        ):
            members = groups[
                semantic_key
            ]

            /*
             * Exclusions should never collapse
             * into positive geometry/state
             * constraints merely because a bad
             * semantic_key was generated.
             */
            result.extend(
                self._coalesce_group(
                    semantic_key,
                    members,
                )
            )

        result.sort(
            key=lambda item: (
                int(item.priority),
                item.semantic_key,
                item.primitive.value,
            )
        )

        return CoalescedConstraintSet(
            version=self.VERSION,

            asset_id=(
                constraint_set.asset_id
            ),

            source_revision_id=(
                constraint_set
                .source_revision_id
            ),

            source_canonical_hash=(
                constraint_set
                .source_canonical_hash
            ),

            source_constraint_set_hash=(
                constraint_set.fingerprint()
            ),

            constraints=tuple(
                result
            ),
        )

    def _coalesce_group(
        self,
        semantic_key: str,
        members: list[Constraint],
    ) -> list[CoalescedConstraint]:
        positive = [
            item
            for item in members
            if item.primitive
            != ConstraintPrimitive.EXCLUSION
        ]

        exclusions = [
            item
            for item in members
            if item.primitive
            == ConstraintPrimitive.EXCLUSION
        ]

        result: list[
            CoalescedConstraint
        ] = []

        if positive:
            result.append(
                self._merge_positive(
                    semantic_key,
                    positive,
                )
            )

        /*
         * Multiple exclusions on same target
         * are retained separately unless their
         * exact values match.
         */
        exclusion_groups: dict[
            str,
            list[Constraint],
        ] = defaultdict(list)

        for item in exclusions:
            key = self._value_key(
                item.value
            )

            exclusion_groups[key].append(
                item
            )

        for key in sorted(
            exclusion_groups.keys()
        ):
            result.append(
                self._merge_same_primitive(
                    semantic_key=(
                        semantic_key
                    ),
                    primitive=(
                        ConstraintPrimitive.EXCLUSION
                    ),
                    members=(
                        exclusion_groups[key]
                    ),
                )
            )

        return result

    def _merge_positive(
        self,
        semantic_key: str,
        members: list[Constraint],
    ) -> CoalescedConstraint:
        primitive = (
            self._select_effective_primitive(
                members
            )
        )

        compatible = [
            item
            for item in members
            if self._compatible_with(
                primitive,
                item.primitive,
            )
        ]

        incompatible = [
            item
            for item in members
            if item not in compatible
        ]

        /*
         * We do not silently discard a semantically
         * different constraint.
         *
         * Example:
         * same key accidentally has MATERIAL and COUNT.
         */
        if incompatible:
            primitives = sorted(
                {
                    item.primitive.value
                    for item in members
                }
            )

            raise ConstraintCoalescingError(
                "Incompatible primitives share "
                f"semantic_key={semantic_key}: "
                + ", ".join(primitives)
            )

        return self._merge_same_primitive(
            semantic_key=semantic_key,
            primitive=primitive,
            members=members,
        )

    def _merge_same_primitive(
        self,
        *,
        semantic_key: str,
        primitive: ConstraintPrimitive,
        members: list[Constraint],
    ) -> CoalescedConstraint:
        if not members:
            raise ConstraintCoalescingError(
                "Cannot merge empty "
                "constraint group"
            )

        severity = (
            ConstraintSeverity.HARD
            if any(
                item.severity
                == ConstraintSeverity.HARD
                for item in members
            )
            else ConstraintSeverity.SOFT
        )

        priority = min(
            (
                item.priority
                for item in members
            ),
            key=int,
        )

        visual_verification = any(
            item.visual_verification
            for item in members
        )

        effective_value = (
            self._select_effective_value(
                primitive=primitive,
                members=members,
            )
        )

        assertions = tuple(
            ConstraintAssertion(
                constraint_id=item.id,

                source_kind=(
                    item.source_kind
                ),

                source_path=(
                    item.source_path
                ),

                source_id=(
                    item.source_id
                ),

                primitive=(
                    item.primitive
                ),

                value=item.value,
            )
            for item in sorted(
                members,
                key=lambda item: item.id,
            )
        )

        origins = tuple(
            sorted(
                {
                    item.provenance_origin
                    for item in members
                    if item.provenance_origin
                }
            )
        )

        ids = tuple(
            sorted(
                item.id
                for item in members
            )
        )

        descriptions = [
            item.description
            for item in members
            if item.description
        ]

        return CoalescedConstraint(
            semantic_key=semantic_key,

            primitive=primitive,

            severity=severity,

            priority=priority,

            visual_verification=(
                visual_verification
            ),

            effective_value=(
                effective_value
            ),

            assertions=assertions,

            provenance_origins=origins,

            source_constraint_ids=ids,

            description=(
                descriptions[0]
                if descriptions
                else None
            ),
        )

    def _select_effective_primitive(
        self,
        members: list[Constraint],
    ) -> ConstraintPrimitive:
        ranked = sorted(
            members,
            key=lambda item: (
                -self._SPECIFICITY[
                    item.primitive
                ],
                int(item.priority),
                item.id,
            )
        )

        return ranked[0].primitive

    def _select_effective_value(
        self,
        *,
        primitive: ConstraintPrimitive,
        members: list[Constraint],
    ) -> Any:
        /*
         * Prefer explicit structured relationship
         * because it contains richer semantics.
         *
         * Example:
         *
         * scalar 4
         *
         * plus
         *
         * {
         *   "type":"count",
         *   "subject_path":"...",
         *   "value":4
         * }
         *
         * Keep relationship object as effective
         * value while preserving scalar assertion
         * in lineage.
         */
        structured = [
            item
            for item in members
            if isinstance(
                item.value,
                dict,
            )
            and item.value.get("type")
        ]

        if structured:
            structured.sort(
                key=lambda item: item.id
            )

            candidate = structured[0].value

            /*
             * Check multiple structured
             * assertions do not contradict.
             */
            for other in structured[1:]:
                if not self._values_equivalent(
                    candidate,
                    other.value,
                ):
                    raise ConstraintCoalescingError(
                        "Conflicting structured "
                        "constraints for semantic key "
                        f"{members[0].semantic_key}"
                    )

            self._assert_scalar_consistency(
                primitive=primitive,
                structured_value=candidate,
                members=members,
            )

            return candidate

        first = members[0].value

        for item in members[1:]:
            if not self._values_equivalent(
                first,
                item.value,
            ):
                raise ConstraintCoalescingError(
                    "Conflicting canonical constraints "
                    f"for semantic_key="
                    f"{item.semantic_key}"
                )

        return first

    def _assert_scalar_consistency(
        self,
        *,
        primitive: ConstraintPrimitive,
        structured_value: dict,
        members: list[Constraint],
    ) -> None:
        /*
         * Only compare values where contract
         * explicitly exposes a comparable
         * relationship value.
         */
        comparable = None

        if primitive == ConstraintPrimitive.COUNT:
            comparable = (
                structured_value.get("value")
            )

        elif (
            primitive
            == ConstraintPrimitive.PROPORTION
        ):
            comparable = (
                structured_value.get("ratio")
                or structured_value.get(
                    "value"
                )
            )

        if comparable is None:
            return

        for item in members:
            if isinstance(
                item.value,
                dict,
            ):
                continue

            if not self._values_equivalent(
                comparable,
                item.value,
            ):
                raise ConstraintCoalescingError(
                    "Structured relationship "
                    "contradicts canonical leaf "
                    f"for {item.semantic_key}"
                )

    @classmethod
    def _compatible_with(
        cls,
        effective: ConstraintPrimitive,
        candidate: ConstraintPrimitive,
    ) -> bool:
        if effective == candidate:
            return True

        /*
         * Geometry leaf may be specialized by
         * an explicit canonical invariant or
         * relationship.
         */
        if (
            candidate
            == ConstraintPrimitive.GEOMETRY
            and effective
            in {
                ConstraintPrimitive.COUNT,
                ConstraintPrimitive.PROPORTION,
                ConstraintPrimitive.POSITION,
                ConstraintPrimitive.ORDER,
                ConstraintPrimitive.CONNECTIVITY,
                ConstraintPrimitive.CONTINUITY,
                ConstraintPrimitive.GROUPING,
                ConstraintPrimitive.ALIGNMENT,
                ConstraintPrimitive.CONTAINMENT,
                ConstraintPrimitive.SYMMETRY,
                ConstraintPrimitive.VISIBILITY,
                ConstraintPrimitive.LAYOUT,
            }
        ):
            return True

        return False

    @classmethod
    def _values_equivalent(
        cls,
        left: Any,
        right: Any,
    ) -> bool:
        return (
            cls._value_key(left)
            == cls._value_key(right)
        )

    @staticmethod
    def _value_key(
        value: Any,
    ) -> str:
        def plain(
            current: Any,
        ) -> Any:
            if isinstance(
                current,
                Enum,
            ):
                return current.value

            if isinstance(
                current,
                dict,
            ):
                return {
                    key: plain(child)
                    for key, child
                    in current.items()
                }

            if isinstance(
                current,
                (
                    tuple,
                    list,
                ),
            ):
                return [
                    plain(item)
                    for item in current
                ]

            return current

        return json.dumps(
            plain(value),
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        )
```

---

# 12.10 Coalescer tuyệt đối không sửa contradiction

Nếu có:

```text
leaf:
primary_tier_count = 4

relationship R001:
count = 5
```

Part 8 Laravel đáng lẽ đã fail.

Nhưng nếu frozen artifact kiểu đó vẫn tới Python vì bug/version drift:

```text
ConstraintCoalescer
→ FAIL
```

Không:

```text
chọn 4
```

Không:

```text
chọn 5
```

Không:

```text
average
```

Đây là runtime integrity failure.

---

# 12.11 Presentation intent

Canonical và ConstraintSet không nên chứa:

```text
photorealistic
documentary photograph
neutral studio
9:16
lighting
background
```

Đó là render/presentation intent.

Ta tạo DTO riêng.

`prompt/spec/presentation_intent.py`

```python
from __future__ import annotations

from pydantic import (
    BaseModel,
    ConfigDict,
    Field,
)


class OutputCanvasIntent(BaseModel):
    model_config = ConfigDict(
        frozen=True,
        extra="forbid",
        strict=True,
    )

    aspect_ratio: str | None = None

    width: int | None = Field(
        default=None,
        ge=1,
    )

    height: int | None = Field(
        default=None,
        ge=1,
    )

    crop_policy: str | None = None


class PresentationIntent(BaseModel):
    """
    Non-canonical rendering presentation.

    Owned by Laravel RenderPlan / asset request,
    never written back into CanonicalDesignSpec.
    """

    model_config = ConfigDict(
        frozen=True,
        extra="forbid",
        strict=True,
    )

    use_case: str | None = None

    realism: str | None = None

    environment: str | None = None

    lighting: str | None = None

    background: str | None = None

    output_canvas: (
        OutputCanvasIntent | None
    ) = None

    additional_directives: tuple[
        str,
        ...,
    ] = ()
```

---

# 12.12 Prompt instruction

`prompt/spec/instruction.py`

```python
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.constraints.enums import (
    ConstraintPriority,
)
from media_runtime.prompt.enums import (
    PromptInstructionSeverity,
    PromptSectionKind,
)


@dataclass(
    frozen=True,
    slots=True,
)
class PromptInstruction:
    id: str

    semantic_key: str

    section: PromptSectionKind

    priority: ConstraintPriority

    severity: PromptInstructionSeverity

    directive: str

    source_constraint_ids: tuple[
        str,
        ...,
    ]

    visual_verification: bool

    provenance_origins: tuple[
        str,
        ...,
    ] = ()
```

`directive` là provider-neutral English render language.

Ví dụ:

```text
Maintain exactly four primary superstructure tiers.
```

Không có OpenAI/Gemini-specific syntax.

---

# 12.13 Prompt section

`prompt/spec/section.py`

```python
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.prompt.enums import (
    PromptSectionKind,
)
from media_runtime.prompt.spec.instruction import (
    PromptInstruction,
)


@dataclass(
    frozen=True,
    slots=True,
)
class PromptSection:
    kind: PromptSectionKind

    instructions: tuple[
        PromptInstruction,
        ...,
    ]
```

---

# 12.14 PromptSpec

`prompt/spec/prompt_spec.py`

```python
from __future__ import annotations

import hashlib
import json

from dataclasses import (
    asdict,
    dataclass,
)
from enum import Enum
from typing import Any

from media_runtime.prompt.spec.presentation_intent import (
    PresentationIntent,
)
from media_runtime.prompt.spec.section import (
    PromptSection,
)


@dataclass(
    frozen=True,
    slots=True,
)
class PromptSpec:
    """
    Provider-independent prompt IR.

    This is NOT yet the final provider request.
    """

    version: str

    asset_id: str
    asset_type: str
    view_role: str

    object_type: str

    source_revision_id: str
    source_revision: int

    source_canonical_hash: str

    source_projection_hash: str

    source_constraint_set_hash: str

    source_coalesced_constraint_hash: str

    sections: tuple[
        PromptSection,
        ...,
    ]

    presentation: PresentationIntent

    def fingerprint(
        self,
    ) -> str:
        payload = self._plain(
            asdict(self)
        )

        raw = json.dumps(
            payload,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        ).encode("utf-8")

        return hashlib.sha256(
            raw
        ).hexdigest()

    @classmethod
    def _plain(
        cls,
        value: Any,
    ) -> Any:
        if isinstance(value, Enum):
            return value.value

        if isinstance(value, dict):
            return {
                key: cls._plain(child)
                for key, child
                in value.items()
            }

        if isinstance(
            value,
            (
                tuple,
                list,
            ),
        ):
            return [
                cls._plain(item)
                for item in value
            ]

        return value
```

---

# 12.15 Directive renderer interface

Ta không viết một `PromptCompiler` có 500 dòng `if primitive == ...`.

Tạo renderer registry.

`prompt/renderers/base.py`

```python
from __future__ import annotations

from abc import ABC, abstractmethod

from media_runtime.constraints.enums import (
    ConstraintPrimitive,
)
from media_runtime.prompt.coalescing.coalesced_constraint import (
    CoalescedConstraint,
)


class ConstraintDirectiveRenderer(
    ABC
):
    @property
    @abstractmethod
    def primitive(
        self,
    ) -> ConstraintPrimitive:
        raise NotImplementedError

    @abstractmethod
    def render(
        self,
        constraint: CoalescedConstraint,
    ) -> str:
        raise NotImplementedError
```

---

# 12.16 Common formatting helper

`prompt/renderers/helpers.py`

```python
from __future__ import annotations

import json
import re
from typing import Any


def humanize_path(
    path: str,
) -> str:
    leaf = path.split(".")[-1]

    leaf = leaf.replace(
        "_",
        " ",
    )

    return leaf.strip()


def format_value(
    value: Any,
) -> str:
    if isinstance(value, bool):
        return (
            "true"
            if value
            else "false"
        )

    if isinstance(
        value,
        float,
    ):
        return format(
            value,
            ".12g",
        )

    if isinstance(
        value,
        int,
    ):
        return str(value)

    if isinstance(
        value,
        str,
    ):
        return value.replace(
            "_",
            " ",
        ).strip()

    if isinstance(
        value,
        list,
    ):
        return ", ".join(
            format_value(item)
            for item in value
        )

    if isinstance(
        value,
        dict,
    ):
        return json.dumps(
            value,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        )

    return str(value)


def ensure_period(
    value: str,
) -> str:
    value = value.strip()

    if not value:
        return value

    if value[-1] in ".!?":
        return value

    return value + "."
```

---

# 12.17 Count renderer

`prompt/renderers/count.py`

```python
from __future__ import annotations

from media_runtime.constraints.enums import (
    ConstraintPrimitive,
)
from media_runtime.prompt.coalescing.coalesced_constraint import (
    CoalescedConstraint,
)
from media_runtime.prompt.renderers.base import (
    ConstraintDirectiveRenderer,
)
from media_runtime.prompt.renderers.helpers import (
    ensure_period,
    humanize_path,
)


class CountDirectiveRenderer(
    ConstraintDirectiveRenderer
):
    @property
    def primitive(
        self,
    ) -> ConstraintPrimitive:
        return ConstraintPrimitive.COUNT

    def render(
        self,
        constraint: CoalescedConstraint,
    ) -> str:
        value = constraint.effective_value

        if isinstance(value, dict):
            relationship_type = (
                value.get("type")
            )

            if relationship_type == "count":
                subject_path = (
                    value.get(
                        "subject_path"
                    )
                    or constraint.semantic_key
                )

                count = value.get(
                    "value"
                )

                return ensure_period(
                    "Maintain exactly "
                    f"{count} "
                    f"{humanize_path(subject_path)}"
                )

            if relationship_type == "one_to_one":
                source = (
                    value.get("source_path")
                    or value.get(
                        "subject_path"
                    )
                    or "source elements"
                )

                target = (
                    value.get("target_path")
                    or "target elements"
                )

                return ensure_period(
                    "Maintain a strict one-to-one "
                    "correspondence between "
                    f"{humanize_path(source)} and "
                    f"{humanize_path(target)}"
                )

        return ensure_period(
            "Maintain the declared count for "
            f"{humanize_path(constraint.semantic_key)}: "
            f"{value}"
        )
```

---

# 12.18 Proportion renderer

`prompt/renderers/proportion.py`

```python
from __future__ import annotations

from media_runtime.constraints.enums import (
    ConstraintPrimitive,
)
from media_runtime.prompt.coalescing.coalesced_constraint import (
    CoalescedConstraint,
)
from media_runtime.prompt.renderers.base import (
    ConstraintDirectiveRenderer,
)
from media_runtime.prompt.renderers.helpers import (
    ensure_period,
    format_value,
    humanize_path,
)


class ProportionDirectiveRenderer(
    ConstraintDirectiveRenderer
):
    @property
    def primitive(
        self,
    ) -> ConstraintPrimitive:
        return ConstraintPrimitive.PROPORTION

    def render(
        self,
        constraint: CoalescedConstraint,
    ) -> str:
        value = constraint.effective_value

        if isinstance(value, dict):
            relationship_type = value.get(
                "type"
            )

            if (
                relationship_type
                == "proportion"
            ):
                source = (
                    value.get(
                        "source_path"
                    )
                    or value.get(
                        "subject_path"
                    )
                )

                reference = (
                    value.get(
                        "reference_path"
                    )
                    or value.get(
                        "target_path"
                    )
                )

                ratio = (
                    value.get("ratio")
                    or value.get("value")
                )

                if (
                    source
                    and reference
                    and ratio is not None
                ):
                    return ensure_period(
                        "Preserve the declared "
                        "proportion between "
                        f"{humanize_path(source)} and "
                        f"{humanize_path(reference)}, "
                        f"with ratio {format_value(ratio)}"
                    )

        return ensure_period(
            "Preserve "
            f"{humanize_path(constraint.semantic_key)} "
            "at the declared value "
            f"{format_value(value)}"
        )
```

---

# 12.19 Position renderer

`prompt/renderers/position.py`

```python
from __future__ import annotations

from media_runtime.constraints.enums import (
    ConstraintPrimitive,
    ConstraintSourceKind,
)
from media_runtime.prompt.coalescing.coalesced_constraint import (
    CoalescedConstraint,
)
from media_runtime.prompt.renderers.base import (
    ConstraintDirectiveRenderer,
)
from media_runtime.prompt.renderers.helpers import (
    ensure_period,
    format_value,
    humanize_path,
)


class PositionDirectiveRenderer(
    ConstraintDirectiveRenderer
):
    @property
    def primitive(
        self,
    ) -> ConstraintPrimitive:
        return ConstraintPrimitive.POSITION

    def render(
        self,
        constraint: CoalescedConstraint,
    ) -> str:
        value = constraint.effective_value

        source_kinds = {
            assertion.source_kind
            for assertion
            in constraint.assertions
        }

        if (
            ConstraintSourceKind.CAMERA
            in source_kinds
        ):
            if isinstance(value, dict):
                prop = value.get(
                    "camera_property"
                )

                camera_value = value.get(
                    "value"
                )

                return ensure_period(
                    f"Camera {humanize_path(str(prop))}: "
                    f"{format_value(camera_value)}"
                )

        if isinstance(value, dict):
            source = (
                value.get("subject_path")
                or value.get(
                    "source_path"
                )
            )

            reference = (
                value.get(
                    "reference_path"
                )
                or value.get(
                    "target_path"
                )
            )

            relation = (
                value.get("position")
                or value.get(
                    "relation"
                )
                or value.get(
                    "value"
                )
            )

            if source and relation:
                if reference:
                    return ensure_period(
                        f"Position "
                        f"{humanize_path(source)} "
                        f"{format_value(relation)} "
                        "relative to "
                        f"{humanize_path(reference)}"
                    )

                return ensure_period(
                    f"Position "
                    f"{humanize_path(source)} "
                    f"{format_value(relation)}"
                )

        return ensure_period(
            "Maintain the declared position of "
            f"{humanize_path(constraint.semantic_key)}: "
            f"{format_value(value)}"
        )
```

---

# 12.20 Topology renderer

Một renderer dùng cho:

```text
GROUPING
ORDER
CONNECTIVITY
CONTINUITY
ALIGNMENT
CONTAINMENT
SYMMETRY
LAYOUT
```

`prompt/renderers/topology.py`

```python
from __future__ import annotations

from media_runtime.constraints.enums import (
    ConstraintPrimitive,
)
from media_runtime.prompt.coalescing.coalesced_constraint import (
    CoalescedConstraint,
)
from media_runtime.prompt.renderers.base import (
    ConstraintDirectiveRenderer,
)
from media_runtime.prompt.renderers.helpers import (
    ensure_period,
    format_value,
    humanize_path,
)


class TopologyDirectiveRenderer(
    ConstraintDirectiveRenderer
):
    def __init__(
        self,
        primitive: ConstraintPrimitive,
    ) -> None:
        if primitive not in {
            ConstraintPrimitive.GROUPING,
            ConstraintPrimitive.ORDER,
            ConstraintPrimitive.CONNECTIVITY,
            ConstraintPrimitive.CONTINUITY,
            ConstraintPrimitive.ALIGNMENT,
            ConstraintPrimitive.CONTAINMENT,
            ConstraintPrimitive.SYMMETRY,
            ConstraintPrimitive.LAYOUT,
        }:
            raise ValueError(
                "Unsupported topology primitive "
                f"{primitive}"
            )

        self._primitive = primitive

    @property
    def primitive(
        self,
    ) -> ConstraintPrimitive:
        return self._primitive

    def render(
        self,
        constraint: CoalescedConstraint,
    ) -> str:
        value = constraint.effective_value

        if isinstance(value, dict):
            return self._render_relationship(
                value
            )

        label = humanize_path(
            constraint.semantic_key
        )

        verb = {
            ConstraintPrimitive.GROUPING:
                "Maintain the declared grouping of",

            ConstraintPrimitive.ORDER:
                "Maintain the declared order of",

            ConstraintPrimitive.CONNECTIVITY:
                "Maintain the declared connectivity of",

            ConstraintPrimitive.CONTINUITY:
                "Maintain continuous geometry for",

            ConstraintPrimitive.ALIGNMENT:
                "Maintain the declared alignment of",

            ConstraintPrimitive.CONTAINMENT:
                "Maintain the declared containment of",

            ConstraintPrimitive.SYMMETRY:
                "Maintain the declared symmetry of",

            ConstraintPrimitive.LAYOUT:
                "Maintain the declared layout of",
        }[
            self._primitive
        ]

        return ensure_period(
            f"{verb} {label}: "
            f"{format_value(value)}"
        )

    def _render_relationship(
        self,
        value: dict,
    ) -> str:
        relation_type = value.get(
            "type"
        )

        if relation_type == "continuity":
            subject = (
                value.get("subject_path")
                or value.get(
                    "source_path"
                )
                or "the declared geometry"
            )

            return ensure_period(
                "Keep "
                f"{humanize_path(subject)} "
                "visually and geometrically continuous"
            )

        if relation_type == "connectivity":
            source = (
                value.get("source_path")
            )

            target = (
                value.get("target_path")
            )

            return ensure_period(
                "Keep "
                f"{humanize_path(str(source))} "
                "physically connected to "
                f"{humanize_path(str(target))}"
            )

        if relation_type == "order":
            items = value.get(
                "items",
                [],
            )

            if isinstance(items, list):
                labels = [
                    humanize_path(
                        str(item)
                    )
                    for item in items
                ]

                return ensure_period(
                    "Preserve this order: "
                    + " → ".join(labels)
                )

        if relation_type == "containment":
            container = (
                value.get(
                    "container_path"
                )
            )

            contained = (
                value.get(
                    "contained_path"
                )
            )

            return ensure_period(
                f"Keep "
                f"{humanize_path(str(contained))} "
                "contained within "
                f"{humanize_path(str(container))}"
            )

        if relation_type == "alignment":
            source = value.get(
                "source_path"
            )

            target = value.get(
                "target_path"
            )

            return ensure_period(
                "Maintain the declared alignment "
                "between "
                f"{humanize_path(str(source))} and "
                f"{humanize_path(str(target))}"
            )

        if relation_type == "symmetry":
            subject = (
                value.get("subject_path")
                or value.get(
                    "source_path"
                )
            )

            return ensure_period(
                "Preserve the declared symmetry of "
                f"{humanize_path(str(subject))}"
            )

        if relation_type == "grouping":
            return ensure_period(
                "Preserve the canonical grouping "
                "relationship exactly as declared"
            )

        return ensure_period(
            "Preserve the canonical "
            f"{self._primitive.value} relationship"
        )
```

---

# 12.21 Geometry renderer

`prompt/renderers/geometry.py`

```python
from __future__ import annotations

from media_runtime.constraints.enums import (
    ConstraintPrimitive,
)
from media_runtime.prompt.coalescing.coalesced_constraint import (
    CoalescedConstraint,
)
from media_runtime.prompt.renderers.base import (
    ConstraintDirectiveRenderer,
)
from media_runtime.prompt.renderers.helpers import (
    ensure_period,
    format_value,
    humanize_path,
)


class GeometryDirectiveRenderer(
    ConstraintDirectiveRenderer
):
    @property
    def primitive(
        self,
    ) -> ConstraintPrimitive:
        return ConstraintPrimitive.GEOMETRY

    def render(
        self,
        constraint: CoalescedConstraint,
    ) -> str:
        label = humanize_path(
            constraint.semantic_key
        )

        return ensure_period(
            f"Maintain {label}: "
            f"{format_value(constraint.effective_value)}"
        )
```

---

# 12.22 Visibility renderer

`prompt/renderers/visibility.py`

```python
from __future__ import annotations

from media_runtime.constraints.enums import (
    ConstraintPrimitive,
)
from media_runtime.prompt.coalescing.coalesced_constraint import (
    CoalescedConstraint,
)
from media_runtime.prompt.renderers.base import (
    ConstraintDirectiveRenderer,
)
from media_runtime.prompt.renderers.helpers import (
    ensure_period,
    format_value,
    humanize_path,
)


class VisibilityDirectiveRenderer(
    ConstraintDirectiveRenderer
):
    @property
    def primitive(
        self,
    ) -> ConstraintPrimitive:
        return ConstraintPrimitive.VISIBILITY

    def render(
        self,
        constraint: CoalescedConstraint,
    ) -> str:
        return ensure_period(
            "Maintain the required visibility of "
            f"{humanize_path(constraint.semantic_key)}: "
            f"{format_value(constraint.effective_value)}"
        )
```

---

# 12.23 Material renderer

`prompt/renderers/material.py`

```python
from __future__ import annotations

from media_runtime.constraints.enums import (
    ConstraintPrimitive,
)
from media_runtime.prompt.coalescing.coalesced_constraint import (
    CoalescedConstraint,
)
from media_runtime.prompt.renderers.base import (
    ConstraintDirectiveRenderer,
)
from media_runtime.prompt.renderers.helpers import (
    ensure_period,
    format_value,
    humanize_path,
)


class MaterialDirectiveRenderer(
    ConstraintDirectiveRenderer
):
    @property
    def primitive(
        self,
    ) -> ConstraintPrimitive:
        return ConstraintPrimitive.MATERIAL

    def render(
        self,
        constraint: CoalescedConstraint,
    ) -> str:
        return ensure_period(
            "Use the declared material/finish for "
            f"{humanize_path(constraint.semantic_key)}: "
            f"{format_value(constraint.effective_value)}"
        )
```

---

# 12.24 State renderer

`prompt/renderers/state.py`

```python
from __future__ import annotations

from media_runtime.constraints.enums import (
    ConstraintPrimitive,
)
from media_runtime.prompt.coalescing.coalesced_constraint import (
    CoalescedConstraint,
)
from media_runtime.prompt.renderers.base import (
    ConstraintDirectiveRenderer,
)
from media_runtime.prompt.renderers.helpers import (
    ensure_period,
    format_value,
    humanize_path,
)


class StateDirectiveRenderer(
    ConstraintDirectiveRenderer
):
    @property
    def primitive(
        self,
    ) -> ConstraintPrimitive:
        return ConstraintPrimitive.STATE

    def render(
        self,
        constraint: CoalescedConstraint,
    ) -> str:
        value = constraint.effective_value

        if isinstance(value, dict):
            key = value.get("key")
            state_value = value.get(
                "value"
            )

            return ensure_period(
                "Current asset state — "
                f"{humanize_path(str(key))}: "
                f"{format_value(state_value)}"
            )

        return ensure_period(
            "Maintain the requested state of "
            f"{humanize_path(constraint.semantic_key)}: "
            f"{format_value(value)}"
        )
```

---

# 12.25 Exclusion renderer

`prompt/renderers/exclusion.py`

```python
from __future__ import annotations

from media_runtime.constraints.enums import (
    ConstraintPrimitive,
)
from media_runtime.prompt.coalescing.coalesced_constraint import (
    CoalescedConstraint,
)
from media_runtime.prompt.renderers.base import (
    ConstraintDirectiveRenderer,
)
from media_runtime.prompt.renderers.helpers import (
    ensure_period,
    format_value,
    humanize_path,
)


class ExclusionDirectiveRenderer(
    ConstraintDirectiveRenderer
):
    @property
    def primitive(
        self,
    ) -> ConstraintPrimitive:
        return ConstraintPrimitive.EXCLUSION

    def render(
        self,
        constraint: CoalescedConstraint,
    ) -> str:
        value = (
            constraint.effective_value
        )

        target = (
            constraint.semantic_key
        )

        if target.startswith(
            "exclusion:"
        ):
            target = target[
                len("exclusion:"):
            ]

        return ensure_period(
            "Do not introduce "
            f"{format_value(value)} "
            "at or affecting "
            f"{humanize_path(target)}"
        )
```

---

# 12.26 Registry

`prompt/renderers/registry.py`

```python
from __future__ import annotations

from media_runtime.constraints.enums import (
    ConstraintPrimitive,
)
from media_runtime.prompt.exceptions import (
    PromptCompilationError,
)
from media_runtime.prompt.renderers.base import (
    ConstraintDirectiveRenderer,
)
from media_runtime.prompt.renderers.count import (
    CountDirectiveRenderer,
)
from media_runtime.prompt.renderers.exclusion import (
    ExclusionDirectiveRenderer,
)
from media_runtime.prompt.renderers.geometry import (
    GeometryDirectiveRenderer,
)
from media_runtime.prompt.renderers.material import (
    MaterialDirectiveRenderer,
)
from media_runtime.prompt.renderers.position import (
    PositionDirectiveRenderer,
)
from media_runtime.prompt.renderers.proportion import (
    ProportionDirectiveRenderer,
)
from media_runtime.prompt.renderers.state import (
    StateDirectiveRenderer,
)
from media_runtime.prompt.renderers.topology import (
    TopologyDirectiveRenderer,
)
from media_runtime.prompt.renderers.visibility import (
    VisibilityDirectiveRenderer,
)


class DirectiveRendererRegistry:
    def __init__(
        self,
        renderers: tuple[
            ConstraintDirectiveRenderer,
            ...,
        ],
    ) -> None:
        registry: dict[
            ConstraintPrimitive,
            ConstraintDirectiveRenderer,
        ] = {}

        for renderer in renderers:
            primitive = renderer.primitive

            if primitive in registry:
                raise PromptCompilationError(
                    "Duplicate directive renderer "
                    f"for {primitive.value}"
                )

            registry[
                primitive
            ] = renderer

        self._registry = registry

    def get(
        self,
        primitive: ConstraintPrimitive,
    ) -> ConstraintDirectiveRenderer:
        try:
            return self._registry[
                primitive
            ]
        except KeyError as exc:
            raise PromptCompilationError(
                "No directive renderer registered "
                f"for {primitive.value}"
            ) from exc


def default_directive_renderer_registry(
) -> DirectiveRendererRegistry:
    topology = {
        ConstraintPrimitive.GROUPING,
        ConstraintPrimitive.ORDER,
        ConstraintPrimitive.CONNECTIVITY,
        ConstraintPrimitive.CONTINUITY,
        ConstraintPrimitive.ALIGNMENT,
        ConstraintPrimitive.CONTAINMENT,
        ConstraintPrimitive.SYMMETRY,
        ConstraintPrimitive.LAYOUT,
    }

    renderers: list[
        ConstraintDirectiveRenderer
    ] = [
        CountDirectiveRenderer(),
        ProportionDirectiveRenderer(),
        PositionDirectiveRenderer(),
        GeometryDirectiveRenderer(),
        VisibilityDirectiveRenderer(),
        MaterialDirectiveRenderer(),
        StateDirectiveRenderer(),
        ExclusionDirectiveRenderer(),
    ]

    for primitive in sorted(
        topology,
        key=lambda item: item.value,
    ):
        renderers.append(
            TopologyDirectiveRenderer(
                primitive
            )
        )

    return DirectiveRendererRegistry(
        tuple(renderers)
    )
```

---

# 12.27 Section mapping

Compiler phải giữ priority P0→P8.

Map:

```text
P0 → IDENTITY
P1/P2 → COUNT_TOPOLOGY
P3 → PROPORTIONS
P4 → GEOMETRY
P5 → OPENINGS
P6 → STATE_MATERIAL
P7 → CAMERA
P8 → PRESENTATION
EXCLUSION → EXCLUSIONS
```

Exclusion nên cuối prompt text nhưng severity vẫn HARD.

---

# 12.28 Prompt instruction stable ID

Không dùng random UUID.

Tạo:

`prompt/hashing.py`

```python
from __future__ import annotations

import hashlib


def stable_prompt_instruction_id(
    *,
    semantic_key: str,
    primitive: str,
    source_ids: tuple[str, ...],
) -> str:
    raw = "|".join(
        (
            semantic_key,
            primitive,
            ",".join(
                sorted(source_ids)
            ),
        )
    )

    digest = hashlib.sha256(
        raw.encode("utf-8")
    ).hexdigest()[:16]

    return f"PI-{digest}"
```

---

# 12.29 `PromptCompilerV1`

`prompt/compiler.py`

```python
from __future__ import annotations

from collections import defaultdict

from media_runtime.constraints.enums import (
    ConstraintPriority,
    ConstraintPrimitive,
    ConstraintSeverity,
)
from media_runtime.prompt.coalescing.coalesced_constraint_set import (
    CoalescedConstraintSet,
)
from media_runtime.prompt.enums import (
    PromptInstructionSeverity,
    PromptSectionKind,
)
from media_runtime.prompt.exceptions import (
    PromptCompilationError,
)
from media_runtime.prompt.hashing import (
    stable_prompt_instruction_id,
)
from media_runtime.prompt.renderers.registry import (
    DirectiveRendererRegistry,
)
from media_runtime.prompt.spec.instruction import (
    PromptInstruction,
)
from media_runtime.prompt.spec.presentation_intent import (
    PresentationIntent,
)
from media_runtime.prompt.spec.prompt_spec import (
    PromptSpec,
)
from media_runtime.prompt.spec.section import (
    PromptSection,
)
from media_runtime.projection.asset_projection import (
    AssetProjection,
)
from media_runtime.projection.serialization import (
    projection_fingerprint,
)


class PromptCompilerV1:
    VERSION = "prompt-spec-v1"

    _SECTION_ORDER = (
        PromptSectionKind.IDENTITY,
        PromptSectionKind.COUNT_TOPOLOGY,
        PromptSectionKind.PROPORTIONS,
        PromptSectionKind.GEOMETRY,
        PromptSectionKind.OPENINGS,
        PromptSectionKind.STATE_MATERIAL,
        PromptSectionKind.CAMERA,
        PromptSectionKind.PRESENTATION,
        PromptSectionKind.EXCLUSIONS,
    )

    def __init__(
        self,
        renderer_registry: (
            DirectiveRendererRegistry
        ),
    ) -> None:
        self._renderers = (
            renderer_registry
        )

    def compile(
        self,
        *,
        projection: AssetProjection,
        constraints: CoalescedConstraintSet,
        presentation: PresentationIntent,
    ) -> PromptSpec:
        self._assert_lineage(
            projection,
            constraints,
        )

        sections: dict[
            PromptSectionKind,
            list[PromptInstruction],
        ] = defaultdict(list)

        for constraint in (
            constraints.constraints
        ):
            renderer = (
                self._renderers.get(
                    constraint.primitive
                )
            )

            directive = renderer.render(
                constraint
            ).strip()

            if not directive:
                raise PromptCompilationError(
                    "Renderer generated empty "
                    "directive for "
                    f"{constraint.semantic_key}"
                )

            section = (
                self._section_for(
                    primitive=(
                        constraint.primitive
                    ),
                    priority=(
                        constraint.priority
                    ),
                )
            )

            severity = (
                PromptInstructionSeverity.HARD
                if constraint.severity
                == ConstraintSeverity.HARD
                else PromptInstructionSeverity.SOFT
            )

            instruction = (
                PromptInstruction(
                    id=(
                        stable_prompt_instruction_id(
                            semantic_key=(
                                constraint.semantic_key
                            ),

                            primitive=(
                                constraint
                                .primitive
                                .value
                            ),

                            source_ids=(
                                constraint
                                .source_constraint_ids
                            ),
                        )
                    ),

                    semantic_key=(
                        constraint.semantic_key
                    ),

                    section=section,

                    priority=(
                        constraint.priority
                    ),

                    severity=severity,

                    directive=directive,

                    source_constraint_ids=(
                        constraint
                        .source_constraint_ids
                    ),

                    visual_verification=(
                        constraint
                        .visual_verification
                    ),

                    provenance_origins=(
                        constraint
                        .provenance_origins
                    ),
                )
            )

            sections[
                section
            ].append(
                instruction
            )

        /*
         * Presentation intent is not a canonical
         * ConstraintSet. It remains separately
         * typed in PromptSpec.
         *
         * ProviderCapabilityProjection will decide
         * whether aspect ratio etc. belongs in
         * native parameters or prompt text.
         */

        built_sections: list[
            PromptSection
        ] = []

        for kind in (
            self._SECTION_ORDER
        ):
            instructions = (
                sections.get(
                    kind,
                    []
                )
            )

            instructions.sort(
                key=lambda item: (
                    int(item.priority),
                    item.semantic_key,
                    item.id,
                )
            )

            if instructions:
                built_sections.append(
                    PromptSection(
                        kind=kind,
                        instructions=tuple(
                            instructions
                        ),
                    )
                )

        return PromptSpec(
            version=self.VERSION,

            asset_id=(
                projection.asset_id
            ),

            asset_type=(
                projection
                .asset_type
                .value
            ),

            view_role=(
                projection
                .view_role
                .value
            ),

            object_type=(
                projection.object_type
            ),

            source_revision_id=(
                projection
                .source_revision_id
            ),

            source_revision=(
                projection
                .source_revision
            ),

            source_canonical_hash=(
                projection
                .source_canonical_hash
            ),

            source_projection_hash=(
                projection_fingerprint(
                    projection
                )
            ),

            source_constraint_set_hash=(
                constraints
                .source_constraint_set_hash
            ),

            source_coalesced_constraint_hash=(
                constraints.fingerprint()
            ),

            sections=tuple(
                built_sections
            ),

            presentation=(
                presentation
            ),
        )

    @staticmethod
    def _assert_lineage(
        projection: AssetProjection,
        constraints: CoalescedConstraintSet,
    ) -> None:
        if (
            projection.asset_id
            != constraints.asset_id
        ):
            raise PromptCompilationError(
                "Projection/ConstraintSet "
                "asset_id mismatch"
            )

        if (
            projection.source_revision_id
            != constraints.source_revision_id
        ):
            raise PromptCompilationError(
                "Projection/ConstraintSet "
                "revision mismatch"
            )

        if (
            projection.source_canonical_hash
            != constraints
            .source_canonical_hash
        ):
            raise PromptCompilationError(
                "Projection/ConstraintSet "
                "canonical hash mismatch"
            )

    @staticmethod
    def _section_for(
        *,
        primitive: ConstraintPrimitive,
        priority: ConstraintPriority,
    ) -> PromptSectionKind:
        if (
            primitive
            == ConstraintPrimitive.EXCLUSION
        ):
            return PromptSectionKind.EXCLUSIONS

        if (
            priority
            == ConstraintPriority.P0_IDENTITY
        ):
            return PromptSectionKind.IDENTITY

        if priority in {
            ConstraintPriority.P1_COUNT,
            ConstraintPriority.P2_TOPOLOGY,
        }:
            return (
                PromptSectionKind.COUNT_TOPOLOGY
            )

        if (
            priority
            == ConstraintPriority.P3_PROPORTION
        ):
            return (
                PromptSectionKind.PROPORTIONS
            )

        if (
            priority
            == ConstraintPriority.P4_GEOMETRY
        ):
            return PromptSectionKind.GEOMETRY

        if (
            priority
            == ConstraintPriority.P5_PERMANENT_OPENINGS
        ):
            return PromptSectionKind.OPENINGS

        if (
            priority
            == ConstraintPriority.P6_STATE_MATERIAL
        ):
            return (
                PromptSectionKind.STATE_MATERIAL
            )

        if (
            priority
            == ConstraintPriority.P7_CAMERA
        ):
            return PromptSectionKind.CAMERA

        if (
            priority
            == ConstraintPriority.P8_STYLE_LIGHTING
        ):
            return (
                PromptSectionKind.PRESENTATION
            )

        raise PromptCompilationError(
            "Unsupported constraint priority: "
            f"{priority}"
        )
```

---

# 12.30 Một điểm rất quan trọng: PromptCompiler không biết provider

Không:

```python
if provider == "openai":
    ...
elif provider == "gemini":
    ...
```

trong `PromptCompilerV1`.

Nếu có logic đó thì Part 12 hỏng architecture.

Đúng:

```text
ConstraintSet
    ↓
PromptCompilerV1
    ↓
same PromptSpec

PromptSpec
    ↓
ProviderCapabilityProjection
    ├── OpenAI profile
    ├── Gemini profile
    └── future provider
```

---

# 12.31 Provider capabilities

Ta không hard-code API assumptions vào compiler.

`prompt/capabilities/capability.py`

```python
from __future__ import annotations

from enum import StrEnum


class ProviderCapability(StrEnum):
    TEXT_PROMPT = "text_prompt"

    NEGATIVE_PROMPT = (
        "negative_prompt"
    )

    REFERENCE_IMAGES = (
        "reference_images"
    )

    MULTIPLE_REFERENCE_IMAGES = (
        "multiple_reference_images"
    )

    ASPECT_RATIO_NATIVE = (
        "aspect_ratio_native"
    )

    WIDTH_HEIGHT_NATIVE = (
        "width_height_native"
    )

    CAMERA_NATIVE = "camera_native"

    STYLE_NATIVE = "style_native"

    QUALITY_NATIVE = "quality_native"

    SEED_NATIVE = "seed_native"
```

---

# 12.32 Capability state

`prompt/capabilities/profile.py`

```python
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.prompt.capabilities.capability import (
    ProviderCapability,
)
from media_runtime.prompt.enums import (
    CapabilityFailurePolicy,
    CapabilitySupport,
)


@dataclass(
    frozen=True,
    slots=True,
)
class CapabilityState:
    support: CapabilitySupport

    max_count: int | None = None


@dataclass(
    frozen=True,
    slots=True,
)
class ProviderCapabilityProfile:
    """
    Provider capability declaration.

    This object is owned by the provider adapter
    configuration, NOT by PromptCompiler.
    """

    provider_key: str

    model_key: str

    capability_version: str

    capabilities: dict[
        ProviderCapability,
        CapabilityState,
    ]

    hard_failure_policy: (
        CapabilityFailurePolicy
    ) = (
        CapabilityFailurePolicy.FAIL_HARD
    )

    soft_failure_policy: (
        CapabilityFailurePolicy
    ) = (
        CapabilityFailurePolicy.WARN_AND_DROP_SOFT
    )

    def state(
        self,
        capability: ProviderCapability,
    ) -> CapabilityState:
        return self.capabilities.get(
            capability,
            CapabilityState(
                support=(
                    CapabilitySupport.UNSUPPORTED
                )
            ),
        )
```

---

# 12.33 Tại sao capability profile phải versioned?

Ví dụ provider thay API:

```text
provider-model capability-v1
→ 1 reference image

capability-v2
→ multiple reference images
```

Prompt artifact cũ phải biết nó được compile theo capability nào.

Do đó sau này Artifact Ledger có:

```text
provider_key
model_key
capability_version
capability_profile_hash
```

---

# 12.34 Capability registry

`prompt/capabilities/registry.py`

```python
from __future__ import annotations

from media_runtime.prompt.capabilities.profile import (
    ProviderCapabilityProfile,
)
from media_runtime.prompt.exceptions import (
    ProviderCapabilityError,
)


class ProviderCapabilityRegistry:
    def __init__(
        self,
        profiles: tuple[
            ProviderCapabilityProfile,
            ...,
        ],
    ) -> None:
        self._profiles: dict[
            tuple[str, str],
            ProviderCapabilityProfile,
        ] = {}

        for profile in profiles:
            key = (
                profile.provider_key,
                profile.model_key,
            )

            if key in self._profiles:
                raise ProviderCapabilityError(
                    "Duplicate provider capability "
                    f"profile: {key}"
                )

            self._profiles[
                key
            ] = profile

    def get(
        self,
        *,
        provider_key: str,
        model_key: str,
    ) -> ProviderCapabilityProfile:
        key = (
            provider_key,
            model_key,
        )

        try:
            return self._profiles[
                key
            ]
        except KeyError as exc:
            raise ProviderCapabilityError(
                "No provider capability profile "
                f"registered for "
                f"{provider_key}/{model_key}"
            ) from exc
```

---

# 12.35 Không hard-code GPT/Gemini capability trong Part 12

Production nên để:

```text
provider adapter
        ↓
declares ProviderCapabilityProfile
```

chứ không để universal compiler tự nghĩ:

```text
GPT Image chắc support X
Gemini chắc support Y
```

Capability thay đổi theo model/API version.

Part 12 chỉ định nghĩa **framework**.

Đến Provider Adapter phase ta khai báo profile thật theo model/API đang dùng.

---

# 12.36 Prompt delivery item

Ta cần biết mỗi instruction đi đâu.

`prompt/capabilities/provider_prompt_plan.py`

```python
from __future__ import annotations

import hashlib
import json

from dataclasses import (
    asdict,
    dataclass,
)
from enum import Enum
from typing import Any

from media_runtime.prompt.enums import (
    DeliveryChannel,
    PromptWarningSeverity,
)


@dataclass(
    frozen=True,
    slots=True,
)
class ProviderPromptWarning:
    code: str

    severity: PromptWarningSeverity

    message: str

    instruction_id: str | None = None


@dataclass(
    frozen=True,
    slots=True,
)
class ProviderInstructionDelivery:
    instruction_id: str

    semantic_key: str

    channel: DeliveryChannel

    directive: str

    native_key: str | None = None

    native_value: Any = None


@dataclass(
    frozen=True,
    slots=True,
)
class ProviderPromptPlan:
    version: str

    provider_key: str
    model_key: str

    capability_version: str

    source_prompt_spec_hash: str

    text_instructions: tuple[
        ProviderInstructionDelivery,
        ...,
    ]

    negative_instructions: tuple[
        ProviderInstructionDelivery,
        ...,
    ]

    native_controls: tuple[
        ProviderInstructionDelivery,
        ...,
    ]

    warnings: tuple[
        ProviderPromptWarning,
        ...,
    ]

    presentation_native_controls: dict[
        str,
        Any,
    ]

    def fingerprint(
        self,
    ) -> str:
        payload = self._plain(
            asdict(self)
        )

        raw = json.dumps(
            payload,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        ).encode("utf-8")

        return hashlib.sha256(
            raw
        ).hexdigest()

    @classmethod
    def _plain(
        cls,
        value: Any,
    ) -> Any:
        if isinstance(value, Enum):
            return value.value

        if isinstance(value, dict):
            return {
                key: cls._plain(child)
                for key, child
                in value.items()
            }

        if isinstance(
            value,
            (
                tuple,
                list,
            ),
        ):
            return [
                cls._plain(item)
                for item in value
            ]

        return value
```

---

# 12.37 ProviderCapabilityProjection

Đây là component user hỏi trực tiếp.

Trách nhiệm:

```text
PromptSpec
+
ProviderCapabilityProfile
        ↓
ProviderPromptPlan
```

Nó không rewrite design.

Nó chỉ quyết định:

```text
camera → text hay native?
aspect ratio → text hay native?
exclusion → negative prompt hay main prompt?
```

---

# 12.38 `projection.py`

```python
from __future__ import annotations

from media_runtime.prompt.capabilities.capability import (
    ProviderCapability,
)
from media_runtime.prompt.capabilities.profile import (
    ProviderCapabilityProfile,
)
from media_runtime.prompt.capabilities.provider_prompt_plan import (
    ProviderInstructionDelivery,
    ProviderPromptPlan,
    ProviderPromptWarning,
)
from media_runtime.prompt.enums import (
    CapabilityFailurePolicy,
    CapabilitySupport,
    DeliveryChannel,
    PromptInstructionSeverity,
    PromptSectionKind,
    PromptWarningSeverity,
)
from media_runtime.prompt.exceptions import (
    ProviderCapabilityError,
)
from media_runtime.prompt.spec.instruction import (
    PromptInstruction,
)
from media_runtime.prompt.spec.prompt_spec import (
    PromptSpec,
)


class ProviderCapabilityProjection:
    VERSION = "provider-prompt-plan-v1"

    def project(
        self,
        *,
        prompt_spec: PromptSpec,
        profile: ProviderCapabilityProfile,
    ) -> ProviderPromptPlan:
        text: list[
            ProviderInstructionDelivery
        ] = []

        negative: list[
            ProviderInstructionDelivery
        ] = []

        native: list[
            ProviderInstructionDelivery
        ] = []

        warnings: list[
            ProviderPromptWarning
        ] = []

        for section in (
            prompt_spec.sections
        ):
            for instruction in (
                section.instructions
            ):
                delivery = (
                    self._project_instruction(
                        instruction=instruction,
                        section=section.kind,
                        profile=profile,
                        warnings=warnings,
                    )
                )

                if delivery is None:
                    continue

                if (
                    delivery.channel
                    == DeliveryChannel.TEXT_PROMPT
                ):
                    text.append(
                        delivery
                    )

                elif (
                    delivery.channel
                    == DeliveryChannel.NEGATIVE_PROMPT
                ):
                    negative.append(
                        delivery
                    )

                elif (
                    delivery.channel
                    == DeliveryChannel.NATIVE_CONTROL
                ):
                    native.append(
                        delivery
                    )

                else:
                    raise ProviderCapabilityError(
                        "Unexpected delivery channel "
                        f"{delivery.channel}"
                    )

        presentation_controls = (
            self._project_presentation(
                prompt_spec=prompt_spec,
                profile=profile,
                warnings=warnings,
            )
        )

        return ProviderPromptPlan(
            version=self.VERSION,

            provider_key=(
                profile.provider_key
            ),

            model_key=(
                profile.model_key
            ),

            capability_version=(
                profile.capability_version
            ),

            source_prompt_spec_hash=(
                prompt_spec.fingerprint()
            ),

            text_instructions=tuple(
                text
            ),

            negative_instructions=tuple(
                negative
            ),

            native_controls=tuple(
                native
            ),

            warnings=tuple(
                warnings
            ),

            presentation_native_controls=(
                presentation_controls
            ),
        )

    def _project_instruction(
        self,
        *,
        instruction: PromptInstruction,
        section: PromptSectionKind,
        profile: ProviderCapabilityProfile,
        warnings: list[
            ProviderPromptWarning
        ],
    ) -> (
        ProviderInstructionDelivery
        | None
    ):
        /*
         * Canonical exclusions can use provider
         * negative-prompt channel when available.
         *
         * Otherwise they remain explicit text.
         */
        if (
            section
            == PromptSectionKind.EXCLUSIONS
        ):
            negative_state = profile.state(
                ProviderCapability.NEGATIVE_PROMPT
            )

            if (
                negative_state.support
                == CapabilitySupport.SUPPORTED
            ):
                return ProviderInstructionDelivery(
                    instruction_id=(
                        instruction.id
                    ),

                    semantic_key=(
                        instruction.semantic_key
                    ),

                    channel=(
                        DeliveryChannel.NEGATIVE_PROMPT
                    ),

                    directive=(
                        instruction.directive
                    ),
                )

            /*
             * Negative-prompt unsupported does NOT
             * mean the exclusion cannot be honored.
             *
             * Main text prompt is a valid fallback.
             */
            return ProviderInstructionDelivery(
                instruction_id=(
                    instruction.id
                ),

                semantic_key=(
                    instruction.semantic_key
                ),

                channel=(
                    DeliveryChannel.TEXT_PROMPT
                ),

                directive=(
                    instruction.directive
                ),
            )

        /*
         * Camera may have native controls.
         *
         * Unless an adapter explicitly declares
         * camera-native capability, retain text.
         */
        if (
            section
            == PromptSectionKind.CAMERA
        ):
            state = profile.state(
                ProviderCapability.CAMERA_NATIVE
            )

            if (
                state.support
                == CapabilitySupport.SUPPORTED
            ):
                native = (
                    self._native_camera(
                        instruction
                    )
                )

                if native is not None:
                    return native

        /*
         * General semantic instructions require
         * text prompt support.
         */
        text_state = profile.state(
            ProviderCapability.TEXT_PROMPT
        )

        if (
            text_state.support
            in {
                CapabilitySupport.SUPPORTED,
                CapabilitySupport.PROMPT_ONLY,
            }
        ):
            return ProviderInstructionDelivery(
                instruction_id=(
                    instruction.id
                ),

                semantic_key=(
                    instruction.semantic_key
                ),

                channel=(
                    DeliveryChannel.TEXT_PROMPT
                ),

                directive=(
                    instruction.directive
                ),
            )

        return self._unsupported_instruction(
            instruction=instruction,
            profile=profile,
            warnings=warnings,
        )

    def _unsupported_instruction(
        self,
        *,
        instruction: PromptInstruction,
        profile: ProviderCapabilityProfile,
        warnings: list[
            ProviderPromptWarning
        ],
    ) -> None:
        hard = (
            instruction.severity
            == PromptInstructionSeverity.HARD
        )

        policy = (
            profile.hard_failure_policy
            if hard
            else profile.soft_failure_policy
        )

        if (
            hard
            or policy
            == CapabilityFailurePolicy.FAIL_HARD
        ):
            raise ProviderCapabilityError(
                "Provider cannot deliver hard "
                "prompt instruction "
                f"{instruction.id} "
                f"({instruction.semantic_key})"
            )

        warnings.append(
            ProviderPromptWarning(
                code=(
                    "provider.soft_instruction_"
                    "unsupported"
                ),

                severity=(
                    PromptWarningSeverity.WARNING
                ),

                message=(
                    "Provider cannot directly "
                    "represent soft instruction "
                    f"{instruction.semantic_key}"
                ),

                instruction_id=(
                    instruction.id
                ),
            )
        )

        return None

    @staticmethod
    def _native_camera(
        instruction: PromptInstruction,
    ) -> (
        ProviderInstructionDelivery
        | None
    ):
        /*
         * PromptInstruction currently contains
         * neutral directive only, not raw camera
         * value.
         *
         * Therefore V1 does NOT guess native
         * provider camera parameters from prose.
         *
         * Keep as text until a typed native camera
         * mapping is supplied by provider adapter.
         */
        return None

    def _project_presentation(
        self,
        *,
        prompt_spec: PromptSpec,
        profile: ProviderCapabilityProfile,
        warnings: list[
            ProviderPromptWarning
        ],
    ) -> dict:
        presentation = (
            prompt_spec.presentation
        )

        native: dict = {}

        canvas = (
            presentation.output_canvas
        )

        if canvas is not None:
            if canvas.aspect_ratio:
                state = profile.state(
                    ProviderCapability.ASPECT_RATIO_NATIVE
                )

                if (
                    state.support
                    == CapabilitySupport.SUPPORTED
                ):
                    native[
                        "aspect_ratio"
                    ] = canvas.aspect_ratio

            if (
                canvas.width is not None
                and canvas.height is not None
            ):
                state = profile.state(
                    ProviderCapability.WIDTH_HEIGHT_NATIVE
                )

                if (
                    state.support
                    == CapabilitySupport.SUPPORTED
                ):
                    native[
                        "width"
                    ] = canvas.width

                    native[
                        "height"
                    ] = canvas.height

        return native
```

---

# 12.39 Camera native mapping chưa làm trong universal layer

Tôi cố ý không parse:

```text
Camera view: front three quarter.
```

rồi tự convert:

```json
{
  "camera_mode": "3q_front"
}
```

vì đó là provider-specific semantics.

Khi GPT adapter có native camera control:

```text
OpenAIImageCapabilityMapper
```

sẽ map từ **typed `AssetProjection.camera`**, không parse prompt prose.

Đây là boundary đúng.

---

# 12.40 Presentation text fallback

Nếu provider không có native aspect ratio nhưng text prompt vẫn cần biết framing:

Ta phải đưa presentation vào text renderer.

`ProviderCapabilityProjection` native mapping không xóa PresentationIntent.

`PromptTextRenderer` sẽ kiểm:

```text
native aspect ratio exists?
→ không cần nhắc mạnh trong prompt

native không có?
→ ghi output framing vào prompt text
```

---

# 12.41 Compiled prompt DTO

`prompt/compiled_prompt.py`

```python
from __future__ import annotations

import hashlib

from dataclasses import dataclass
from typing import Any


@dataclass(
    frozen=True,
    slots=True,
)
class CompiledPrompt:
    version: str

    provider_key: str
    model_key: str

    source_prompt_spec_hash: str

    source_provider_plan_hash: str

    prompt: str

    negative_prompt: str | None

    native_controls: dict[
        str,
        Any,
    ]

    prompt_hash: str

    negative_prompt_hash: (
        str | None
    )

    @staticmethod
    def sha256_text(
        value: str,
    ) -> str:
        return hashlib.sha256(
            value.encode("utf-8")
        ).hexdigest()
```

---

# 12.42 Deterministic final text renderer

`prompt/text_renderer.py`

```python
from __future__ import annotations

from collections import defaultdict

from media_runtime.prompt.capabilities.provider_prompt_plan import (
    ProviderPromptPlan,
)
from media_runtime.prompt.compiled_prompt import (
    CompiledPrompt,
)
from media_runtime.prompt.enums import (
    PromptSectionKind,
)
from media_runtime.prompt.spec.prompt_spec import (
    PromptSpec,
)


class PromptSpecTextRenderer:
    VERSION = "prompt-text-v1"

    _SECTION_TITLES = {
        PromptSectionKind.IDENTITY:
            "IDENTITY",

        PromptSectionKind.COUNT_TOPOLOGY:
            "COUNT AND TOPOLOGY",

        PromptSectionKind.PROPORTIONS:
            "PROPORTIONS",

        PromptSectionKind.GEOMETRY:
            "PERMANENT GEOMETRY",

        PromptSectionKind.OPENINGS:
            "PERMANENT OPENINGS",

        PromptSectionKind.STATE_MATERIAL:
            "STATE AND MATERIAL",

        PromptSectionKind.CAMERA:
            "CAMERA",

        PromptSectionKind.PRESENTATION:
            "PRESENTATION",

        PromptSectionKind.EXCLUSIONS:
            "EXCLUSIONS",
    }

    _SECTION_ORDER = (
        PromptSectionKind.IDENTITY,
        PromptSectionKind.COUNT_TOPOLOGY,
        PromptSectionKind.PROPORTIONS,
        PromptSectionKind.GEOMETRY,
        PromptSectionKind.OPENINGS,
        PromptSectionKind.STATE_MATERIAL,
        PromptSectionKind.CAMERA,
        PromptSectionKind.PRESENTATION,
    )

    def render(
        self,
        *,
        spec: PromptSpec,
        plan: ProviderPromptPlan,
    ) -> CompiledPrompt:
        if (
            plan.source_prompt_spec_hash
            != spec.fingerprint()
        ):
            raise ValueError(
                "ProviderPromptPlan does not "
                "belong to PromptSpec"
            )

        delivery_by_id = {
            item.instruction_id: item
            for item
            in plan.text_instructions
        }

        lines: list[str] = []

        lines.extend(
            self._header(
                spec
            )
        )

        for section_kind in (
            self._SECTION_ORDER
        ):
            directives: list[str] = []

            section = next(
                (
                    section
                    for section
                    in spec.sections
                    if section.kind
                    == section_kind
                ),
                None,
            )

            if section is None:
                continue

            for instruction in (
                section.instructions
            ):
                delivery = (
                    delivery_by_id.get(
                        instruction.id
                    )
                )

                if delivery is None:
                    continue

                directives.append(
                    delivery.directive
                )

            if directives:
                lines.append("")

                lines.append(
                    self._SECTION_TITLES[
                        section_kind
                    ]
                )

                lines.extend(
                    directives
                )

        presentation_lines = (
            self._presentation_lines(
                spec=spec,
                plan=plan,
            )
        )

        if presentation_lines:
            lines.append("")
            lines.append(
                "OUTPUT / PRESENTATION"
            )
            lines.extend(
                presentation_lines
            )

        prompt = "\n".join(
            lines
        ).strip()

        negative_prompt = (
            self._negative_prompt(
                plan
            )
        )

        native_controls = dict(
            plan.presentation_native_controls
        )

        for item in (
            plan.native_controls
        ):
            if item.native_key:
                native_controls[
                    item.native_key
                ] = item.native_value

        return CompiledPrompt(
            version=self.VERSION,

            provider_key=(
                plan.provider_key
            ),

            model_key=(
                plan.model_key
            ),

            source_prompt_spec_hash=(
                spec.fingerprint()
            ),

            source_provider_plan_hash=(
                plan.fingerprint()
            ),

            prompt=prompt,

            negative_prompt=(
                negative_prompt
            ),

            native_controls=(
                native_controls
            ),

            prompt_hash=(
                CompiledPrompt.sha256_text(
                    prompt
                )
            ),

            negative_prompt_hash=(
                CompiledPrompt.sha256_text(
                    negative_prompt
                )
                if negative_prompt
                else None
            ),
        )

    @staticmethod
    def _header(
        spec: PromptSpec,
    ) -> list[str]:
        return [
            (
                "Render one coherent physical "
                f"{spec.object_type}."
            ),

            (
                "Preserve the same canonical "
                "identity throughout this asset."
            ),

            (
                "Identity-critical geometry, "
                "counts, topology and proportions "
                "take precedence over composition, "
                "style and lighting."
            ),
        ]

    @staticmethod
    def _negative_prompt(
        plan: ProviderPromptPlan,
    ) -> str | None:
        directives = [
            item.directive
            for item
            in plan.negative_instructions
        ]

        if not directives:
            return None

        return "\n".join(
            directives
        )

    @staticmethod
    def _presentation_lines(
        *,
        spec: PromptSpec,
        plan: ProviderPromptPlan,
    ) -> list[str]:
        presentation = (
            spec.presentation
        )

        native = (
            plan
            .presentation_native_controls
        )

        result: list[str] = []

        if presentation.use_case:
            result.append(
                "Use case: "
                + presentation.use_case
                + "."
            )

        if presentation.realism:
            result.append(
                "Rendering character: "
                + presentation.realism
                + "."
            )

        if presentation.environment:
            result.append(
                "Environment: "
                + presentation.environment
                + "."
            )

        if presentation.lighting:
            result.append(
                "Lighting: "
                + presentation.lighting
                + "."
            )

        if presentation.background:
            result.append(
                "Background: "
                + presentation.background
                + "."
            )

        canvas = (
            presentation.output_canvas
        )

        if canvas is not None:
            if (
                canvas.aspect_ratio
                and "aspect_ratio"
                not in native
            ):
                result.append(
                    "Output aspect ratio: "
                    + canvas.aspect_ratio
                    + "."
                )

            if (
                canvas.width is not None
                and canvas.height is not None
                and (
                    "width" not in native
                    or "height" not in native
                )
            ):
                result.append(
                    "Output canvas: "
                    f"{canvas.width} × "
                    f"{canvas.height} pixels."
                )

            if canvas.crop_policy:
                result.append(
                    "Cropping: "
                    + canvas.crop_policy
                    + "."
                )

        result.extend(
            directive.rstrip(".")
            + "."
            for directive
            in presentation
            .additional_directives
            if directive.strip()
        )

        return result
```

---

# 12.43 Tại sao exclusion có thể ở negative prompt hoặc main prompt?

Nếu provider support negative prompt:

```text
Canonical EXCLUSION
→ negative_prompt
```

Nếu không:

```text
Canonical EXCLUSION
→ main text
```

Nhưng **không được drop**.

Ví dụ:

```text
Do not introduce a fifth primary tier.
```

Nếu provider không có negative channel vẫn phải nằm trong main prompt.

---

# 12.44 Không biến negative prompt thành danh sách generic dài

Không tự thêm:

```text
no blur
no distortion
no artifacts
no bad anatomy
...
```

trong universal compiler.

Chỉ đưa:

```text
Canonical exclusions
+
provider-specific quality policy
```

nếu sau này adapter explicitly có.

Không để generic negative boilerplate lấn át identity constraints.

---

# 12.45 Reference images không thuộc PromptSpec text

Ảnh tham chiếu là asset transport.

Không viết:

```text
Use image 1 for geometry.
Use image 2 for style.
```

rồi hy vọng provider hiểu đúng.

Ta cần typed reference contract.

Tôi thêm ngay trong Part 12 vì `ProviderCapabilityProjection` cần nó.

---

# 12.46 Reference input DTO

`prompt/capabilities/reference_input.py`

```python
from __future__ import annotations

from dataclasses import dataclass
from enum import StrEnum


class ReferenceRole(StrEnum):
    IDENTITY = "identity"

    GEOMETRY = "geometry"

    VIEW = "view"

    STATE = "state"

    ENVIRONMENT = "environment"

    STYLE = "style"


@dataclass(
    frozen=True,
    slots=True,
)
class ReferenceInput:
    artifact_id: str

    artifact_hash: str

    role: ReferenceRole

    priority: int

    source_revision_id: str | None = None

    source_canonical_hash: str | None = None
```

---

# 12.47 Provider reference plan

Thêm vào `ProviderPromptPlan`:

```python
reference_inputs: tuple[
    ReferenceInput,
    ...,
]
```

Nhưng provider capability projection cần nhận references ngoài PromptSpec:

```python
project(
    prompt_spec=...,
    profile=...,
    references=...
)
```

---

# 12.48 Reference capability enforcement

Thêm vào `ProviderCapabilityProjection`:

```python
def _project_references(
    self,
    *,
    references: tuple[
        ReferenceInput,
        ...,
    ],
    profile: ProviderCapabilityProfile,
) -> tuple[ReferenceInput, ...]:
    if not references:
        return ()

    support = profile.state(
        ProviderCapability.REFERENCE_IMAGES
    )

    if (
        support.support
        == CapabilitySupport.UNSUPPORTED
    ):
        raise ProviderCapabilityError(
            "Asset requires reference images "
            "but provider does not support them"
        )

    ordered = tuple(
        sorted(
            references,
            key=lambda item: (
                item.priority,
                item.artifact_id,
            )
        )
    )

    multi = profile.state(
        ProviderCapability.MULTIPLE_REFERENCE_IMAGES
    )

    if len(ordered) > 1:
        if (
            multi.support
            == CapabilitySupport.UNSUPPORTED
        ):
            raise ProviderCapabilityError(
                "Asset requires multiple "
                "reference images but provider "
                "supports only one"
            )

        if (
            multi.max_count is not None
            and len(ordered)
            > multi.max_count
        ):
            raise ProviderCapabilityError(
                "Reference image count exceeds "
                "provider capability: "
                f"{len(ordered)} > "
                f"{multi.max_count}"
            )

    return ordered
```

Không silently drop reference #4/#5.

Nếu identity cần 4 ảnh mà provider chỉ nhận 1:

```text
FAIL / route provider khác
```

không:

```text
lấy đại ảnh đầu tiên.
```

---

# 12.49 ProviderPromptPlan final

Chốt DTO final:

```python
@dataclass(
    frozen=True,
    slots=True,
)
class ProviderPromptPlan:
    version: str

    provider_key: str
    model_key: str

    capability_version: str

    source_prompt_spec_hash: str

    text_instructions: tuple[
        ProviderInstructionDelivery,
        ...,
    ]

    negative_instructions: tuple[
        ProviderInstructionDelivery,
        ...,
    ]

    native_controls: tuple[
        ProviderInstructionDelivery,
        ...,
    ]

    reference_inputs: tuple[
        ReferenceInput,
        ...,
    ]

    warnings: tuple[
        ProviderPromptWarning,
        ...,
    ]

    presentation_native_controls: dict[
        str,
        Any,
    ]
```

---

# 12.50 Capability projection signature final

```python
def project(
    self,
    *,
    prompt_spec: PromptSpec,
    profile: ProviderCapabilityProfile,
    references: tuple[
        ReferenceInput,
        ...,
    ] = (),
) -> ProviderPromptPlan:
```

trong return:

```python
reference_inputs=(
    self._project_references(
        references=references,
        profile=profile,
    )
),
```

---

# 12.51 Prompt compilation service

Ta cần một façade giống Part 11.

`prompt/service.py`

```python
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.constraints.constraint_set import (
    ConstraintSet,
)
from media_runtime.prompt.capabilities.profile import (
    ProviderCapabilityProfile,
)
from media_runtime.prompt.capabilities.projection import (
    ProviderCapabilityProjection,
)
from media_runtime.prompt.capabilities.provider_prompt_plan import (
    ProviderPromptPlan,
)
from media_runtime.prompt.capabilities.reference_input import (
    ReferenceInput,
)
from media_runtime.prompt.coalescing.coalesced_constraint_set import (
    CoalescedConstraintSet,
)
from media_runtime.prompt.coalescing.constraint_coalescer import (
    ConstraintCoalescer,
)
from media_runtime.prompt.compiled_prompt import (
    CompiledPrompt,
)
from media_runtime.prompt.compiler import (
    PromptCompilerV1,
)
from media_runtime.prompt.spec.presentation_intent import (
    PresentationIntent,
)
from media_runtime.prompt.spec.prompt_spec import (
    PromptSpec,
)
from media_runtime.prompt.text_renderer import (
    PromptSpecTextRenderer,
)
from media_runtime.projection.asset_projection import (
    AssetProjection,
)


@dataclass(
    frozen=True,
    slots=True,
)
class PromptCompilationResult:
    coalesced_constraints: (
        CoalescedConstraintSet
    )

    prompt_spec: PromptSpec

    provider_plan: (
        ProviderPromptPlan
    )

    compiled_prompt: (
        CompiledPrompt
    )


class PromptCompilationService:
    def __init__(
        self,
        *,
        coalescer: ConstraintCoalescer,
        compiler: PromptCompilerV1,
        capability_projection: (
            ProviderCapabilityProjection
        ),
        text_renderer: (
            PromptSpecTextRenderer
        ),
    ) -> None:
        self._coalescer = coalescer
        self._compiler = compiler

        self._capabilities = (
            capability_projection
        )

        self._text_renderer = (
            text_renderer
        )

    def compile(
        self,
        *,
        projection: AssetProjection,
        constraint_set: ConstraintSet,
        presentation: PresentationIntent,
        provider_profile: (
            ProviderCapabilityProfile
        ),
        references: tuple[
            ReferenceInput,
            ...,
        ] = (),
    ) -> PromptCompilationResult:
        self._assert_source_lineage(
            projection,
            constraint_set,
        )

        coalesced = (
            self._coalescer
            .coalesce(
                constraint_set
            )
        )

        spec = self._compiler.compile(
            projection=projection,
            constraints=coalesced,
            presentation=presentation,
        )

        provider_plan = (
            self._capabilities.project(
                prompt_spec=spec,
                profile=provider_profile,
                references=references,
            )
        )

        compiled = (
            self._text_renderer.render(
                spec=spec,
                plan=provider_plan,
            )
        )

        return PromptCompilationResult(
            coalesced_constraints=(
                coalesced
            ),

            prompt_spec=spec,

            provider_plan=(
                provider_plan
            ),

            compiled_prompt=(
                compiled
            ),
        )

    @staticmethod
    def _assert_source_lineage(
        projection: AssetProjection,
        constraints: ConstraintSet,
    ) -> None:
        if (
            projection.asset_id
            != constraints.asset_id
        ):
            raise ValueError(
                "Projection and ConstraintSet "
                "asset mismatch"
            )

        if (
            projection.source_revision_id
            != constraints.source_revision_id
        ):
            raise ValueError(
                "Projection and ConstraintSet "
                "revision mismatch"
            )

        if (
            projection.source_canonical_hash
            != constraints
            .source_canonical_hash
        ):
            raise ValueError(
                "Projection and ConstraintSet "
                "canonical hash mismatch"
            )
```

---

# 12.52 Bootstrap Part 12

Update `media_runtime/bootstrap.py`:

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

from media_runtime.prompt.capabilities.projection import (
    ProviderCapabilityProjection,
)
from media_runtime.prompt.coalescing.constraint_coalescer import (
    ConstraintCoalescer,
)
from media_runtime.prompt.compiler import (
    PromptCompilerV1,
)
from media_runtime.prompt.renderers.registry import (
    default_directive_renderer_registry,
)
from media_runtime.prompt.service import (
    PromptCompilationService,
)
from media_runtime.prompt.text_renderer import (
    PromptSpecTextRenderer,
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


def build_prompt_compilation_service(
) -> PromptCompilationService:
    renderers = (
        default_directive_renderer_registry()
    )

    return PromptCompilationService(
        coalescer=(
            ConstraintCoalescer()
        ),

        compiler=(
            PromptCompilerV1(
                renderer_registry=(
                    renderers
                )
            )
        ),

        capability_projection=(
            ProviderCapabilityProjection()
        ),

        text_renderer=(
            PromptSpecTextRenderer()
        ),
    )
```

---

# 12.53 Provider profile example cho test

Không xem đây là capability thật của API production.

Đây chỉ là fixture.

```python
from media_runtime.prompt.capabilities.capability import (
    ProviderCapability,
)
from media_runtime.prompt.capabilities.profile import (
    CapabilityState,
    ProviderCapabilityProfile,
)
from media_runtime.prompt.enums import (
    CapabilitySupport,
)


TEST_PROVIDER = (
    ProviderCapabilityProfile(
        provider_key="test",
        model_key="image-v1",

        capability_version=(
            "test-capabilities-v1"
        ),

        capabilities={
            ProviderCapability.TEXT_PROMPT:
                CapabilityState(
                    CapabilitySupport.SUPPORTED
                ),

            ProviderCapability.NEGATIVE_PROMPT:
                CapabilityState(
                    CapabilitySupport.SUPPORTED
                ),

            ProviderCapability.REFERENCE_IMAGES:
                CapabilityState(
                    CapabilitySupport.SUPPORTED
                ),

            ProviderCapability.MULTIPLE_REFERENCE_IMAGES:
                CapabilityState(
                    support=(
                        CapabilitySupport.SUPPORTED
                    ),
                    max_count=8,
                ),

            ProviderCapability.ASPECT_RATIO_NATIVE:
                CapabilityState(
                    CapabilitySupport.SUPPORTED
                ),

            ProviderCapability.WIDTH_HEIGHT_NATIVE:
                CapabilityState(
                    CapabilitySupport.UNSUPPORTED
                ),
        },
    )
)
```

Provider thật ở Part adapter phải có profile riêng.

---

# 12.54 Ví dụ output PromptSpec

Từ superyacht canonical:

```json
{
  "version": "prompt-spec-v1",

  "asset_id": "master_vessel",

  "asset_type": "identity_anchor",

  "object_type": "superyacht",

  "source_canonical_hash": "...",

  "sections": [
    {
      "kind": "identity",

      "instructions": [
        {
          "semantic_key":
            "identity.identity_basis.0",

          "priority": 0,

          "severity": "hard",

          "directive":
            "Maintain a single continuous external shell integrating the hull and superstructure."
        }
      ]
    },

    {
      "kind": "count_topology",

      "instructions": [
        {
          "semantic_key":
            "permanent_geometry.superstructure.primary_tier_count",

          "severity": "hard",

          "priority": 1,

          "directive":
            "Maintain exactly 4 primary tier count."
        }
      ]
    },

    {
      "kind": "geometry",

      "instructions": [
        {
          "semantic_key":
            "permanent_geometry.bow.stem",

          "directive":
            "Maintain stem: near plumb."
        }
      ]
    }
  ]
}
```

PromptSpec vẫn chưa biết GPT/Gemini.

---

# 12.55 Final compiled prompt ví dụ

Text renderer có thể tạo:

```text
Render one coherent physical superyacht.
Preserve the same canonical identity throughout this asset.
Identity-critical geometry, counts, topology and proportions take precedence over composition, style and lighting.

IDENTITY
Maintain a single continuous external shell integrating the hull and superstructure.

COUNT AND TOPOLOGY
Maintain exactly 4 primary superstructure tiers.
Keep the main superstructure geometry visually and geometrically continuous.

PROPORTIONS
Preserve length to beam ratio at the declared value 6.

PERMANENT GEOMETRY
Maintain stem: near plumb.
Maintain waterline entry: fine.
Maintain stern type: broad flat transom.

STATE AND MATERIAL
Current asset state — fabrication stage: structurally complete unfinished.
Current asset state — surface state: bare unpainted plating.

CAMERA
Camera view: front three quarter.
Camera side: port.
Camera elevation: slightly above waterline.

OUTPUT / PRESENTATION
Use case: technical fabrication-state geometry reference.
Rendering character: photorealistic natural.
Output aspect ratio: 2:3.
Cropping: leave clear margin around the entire subject.
```

Nếu provider support negative prompt:

```text
negative_prompt:
Do not introduce a fifth primary superstructure tier.
Do not introduce temporary geometry as permanent structure.
```

Nếu không support negative:

hai câu đó quay về main prompt.

---

# 12.56 Hard constraints không được drop

Đây là invariant cực quan trọng.

Giả sử provider:

```text
không support negative prompt
```

và instruction:

```text
HARD exclusion
```

Không:

```text
DROP
```

Mà:

```text
negative unsupported
↓
text prompt fallback
```

Nếu cả text/native đều không thể biểu diễn:

```text
ProviderCapabilityError
```

Không chạy render.

---

# 12.57 Soft constraints có thể degrade

Ví dụ presentation:

```text
specific style control
```

provider không support native.

Nếu text prompt support:

```text
→ text fallback
```

Nếu không có kênh nào:

```text
soft
→ warning
```

và có thể drop theo policy.

Nhưng warning phải persist vào Artifact Ledger.

---

# 12.58 Provider routing sau này

Nhờ capability projection, router có thể làm:

```text
Asset requires:
4 reference images
+
negative prompt
+
native 9:16

Provider A:
refs max 1
→ incompatible

Provider B:
refs max 8
→ compatible

Provider C:
refs max 4
→ compatible
```

Router không cần đọc prompt text.

Nó đọc:

```text
ProviderCapabilityProfile
```

---

# 12.59 Prompt budget

Một vấn đề production nữa: prompt quá dài.

Không nên xử lý bằng:

```python
prompt[:10000]
```

Ta cần deterministic budget.

Tôi thêm:

```text
PromptBudgetPolicy
```

ngay Part 12.

---

# 12.60 `PromptBudgetPolicy`

`prompt/budget.py`

```python
from __future__ import annotations

from dataclasses import dataclass


@dataclass(
    frozen=True,
    slots=True,
)
class PromptBudgetPolicy:
    """
    Character budget, not token budget.

    Exact provider tokenization belongs to
    provider adapter if necessary.
    """

    max_prompt_chars: int

    max_negative_prompt_chars: (
        int | None
    ) = None

    allow_drop_soft: bool = True
```

---

# 12.61 Không truncate HARD

Nếu prompt vượt budget:

```text
drop P8 soft
drop presentation soft
drop P7 soft
drop P6 soft
...
```

Nhưng:

```text
P0 hard
P1 hard
P2 hard
canonical exclusions hard
```

không bao giờ truncate.

Nếu chỉ hard constraints đã vượt provider budget:

```text
FAIL
```

hoặc chọn provider/model khác.

---

# 12.62 Budget reducer

`prompt/budget_reducer.py`

```python
from __future__ import annotations

from dataclasses import replace

from media_runtime.prompt.budget import (
    PromptBudgetPolicy,
)
from media_runtime.prompt.capabilities.provider_prompt_plan import (
    ProviderPromptPlan,
    ProviderPromptWarning,
)
from media_runtime.prompt.enums import (
    PromptWarningSeverity,
)
from media_runtime.prompt.exceptions import (
    ProviderCapabilityError,
)
from media_runtime.prompt.spec.prompt_spec import (
    PromptSpec,
)


class PromptBudgetReducer:
    VERSION = "prompt-budget-v1"

    def reduce(
        self,
        *,
        spec: PromptSpec,
        plan: ProviderPromptPlan,
        policy: PromptBudgetPolicy,
    ) -> ProviderPromptPlan:
        current = list(
            plan.text_instructions
        )

        def length(
            items,
        ) -> int:
            return sum(
                len(item.directive) + 1
                for item in items
            )

        if (
            length(current)
            <= policy.max_prompt_chars
        ):
            return plan

        severity_by_id = {}

        priority_by_id = {}

        for section in spec.sections:
            for instruction in (
                section.instructions
            ):
                severity_by_id[
                    instruction.id
                ] = instruction.severity.value

                priority_by_id[
                    instruction.id
                ] = int(
                    instruction.priority
                )

        removable = [
            item
            for item in current
            if severity_by_id.get(
                item.instruction_id
            ) == "soft"
        ]

        /*
         * Remove least important soft
         * constraints first:
         *
         * P8 -> P7 -> P6 -> ...
         */
        removable.sort(
            key=lambda item: (
                -priority_by_id.get(
                    item.instruction_id,
                    999,
                ),
                item.instruction_id,
            )
        )

        removed: set[str] = set()

        for item in removable:
            if (
                length(
                    [
                        candidate
                        for candidate
                        in current
                        if candidate
                        .instruction_id
                        not in removed
                    ]
                )
                <= policy.max_prompt_chars
            ):
                break

            removed.add(
                item.instruction_id
            )

        reduced = [
            item
            for item in current
            if item.instruction_id
            not in removed
        ]

        if (
            length(reduced)
            > policy.max_prompt_chars
        ):
            raise ProviderCapabilityError(
                "Hard prompt constraints exceed "
                "provider prompt budget."
            )

        warnings = list(
            plan.warnings
        )

        for instruction_id in sorted(
            removed
        ):
            warnings.append(
                ProviderPromptWarning(
                    code=(
                        "provider.prompt_budget."
                        "soft_instruction_removed"
                    ),

                    severity=(
                        PromptWarningSeverity.WARNING
                    ),

                    message=(
                        "Soft prompt instruction "
                        "removed to satisfy "
                        "provider prompt budget."
                    ),

                    instruction_id=(
                        instruction_id
                    ),
                )
            )

        return replace(
            plan,

            text_instructions=tuple(
                reduced
            ),

            warnings=tuple(
                warnings
            ),
        )
```

Đây là deterministic degradation.

---

# 12.63 Nhưng identity P0 nên HARD từ Part 11

Đúng.

Identity basis:

```text
P0
HARD
```

không bao giờ budget reducer bỏ.

Count/topology từ explicit relationships:

```text
HARD
```

nên giữ.

Geometry leaf không invariant:

```text
SOFT
```

có thể bị bỏ nếu prompt budget rất nhỏ.

Nhưng `required_paths` nâng nó thành HARD.

Đây là lý do asset planner của Laravel phải xác định:

```text
asset này cần chứng minh geometry nào?
```

---

# 12.64 Prompt validation

Sau compile phải validate.

Tạo:

`prompt/validator.py`

```python
from __future__ import annotations

from media_runtime.prompt.compiled_prompt import (
    CompiledPrompt,
)
from media_runtime.prompt.exceptions import (
    PromptCompilationError,
)


class CompiledPromptValidator:
    def validate(
        self,
        prompt: CompiledPrompt,
    ) -> None:
        if not prompt.prompt.strip():
            raise PromptCompilationError(
                "Compiled prompt is empty."
            )

        expected = (
            CompiledPrompt.sha256_text(
                prompt.prompt
            )
        )

        if (
            expected
            != prompt.prompt_hash
        ):
            raise PromptCompilationError(
                "Compiled prompt hash mismatch."
            )

        if (
            prompt.negative_prompt
            is not None
        ):
            expected_negative = (
                CompiledPrompt.sha256_text(
                    prompt
                    .negative_prompt
                )
            )

            if (
                expected_negative
                != prompt
                .negative_prompt_hash
            ):
                raise PromptCompilationError(
                    "Negative prompt hash mismatch."
                )
```

---

# 12.65 Stronger validation: all HARD instructions delivered

Cần thêm:

```python
class PromptDeliveryValidator:
    def validate(
        self,
        *,
        spec: PromptSpec,
        plan: ProviderPromptPlan,
    ) -> None:
        delivered = {
            item.instruction_id
            for item in (
                plan.text_instructions
                + plan.negative_instructions
                + plan.native_controls
            )
        }

        missing = []

        for section in spec.sections:
            for instruction in (
                section.instructions
            ):
                if (
                    instruction
                    .severity
                    .value
                    != "hard"
                ):
                    continue

                if (
                    instruction.id
                    not in delivered
                ):
                    missing.append(
                        instruction
                    )

        if missing:
            raise ProviderCapabilityError(
                "Undelivered hard prompt "
                "instructions: "
                + ", ".join(
                    item.id
                    for item in missing
                )
            )
```

Đây là test/invariant rất quan trọng.

---

# 12.66 PromptCompilationService final

Chúng ta update thêm budget + validator:

```python
class PromptCompilationService:
    def __init__(
        self,
        *,
        coalescer,
        compiler,
        capability_projection,
        budget_reducer,
        delivery_validator,
        text_renderer,
        compiled_validator,
    ) -> None:
        self._coalescer = coalescer
        self._compiler = compiler
        self._capabilities = (
            capability_projection
        )
        self._budget = budget_reducer
        self._delivery_validator = (
            delivery_validator
        )
        self._text_renderer = (
            text_renderer
        )
        self._compiled_validator = (
            compiled_validator
        )

    def compile(
        self,
        *,
        projection,
        constraint_set,
        presentation,
        provider_profile,
        references=(),
        budget_policy=None,
    ) -> PromptCompilationResult:
        self._assert_source_lineage(
            projection,
            constraint_set,
        )

        coalesced = (
            self._coalescer.coalesce(
                constraint_set
            )
        )

        spec = self._compiler.compile(
            projection=projection,
            constraints=coalesced,
            presentation=presentation,
        )

        plan = (
            self._capabilities.project(
                prompt_spec=spec,
                profile=provider_profile,
                references=references,
            )
        )

        if budget_policy is not None:
            plan = self._budget.reduce(
                spec=spec,
                plan=plan,
                policy=budget_policy,
            )

        self._delivery_validator.validate(
            spec=spec,
            plan=plan,
        )

        compiled = (
            self._text_renderer.render(
                spec=spec,
                plan=plan,
            )
        )

        self._compiled_validator.validate(
            compiled
        )

        return PromptCompilationResult(
            coalesced_constraints=(
                coalesced
            ),
            prompt_spec=spec,
            provider_plan=plan,
            compiled_prompt=compiled,
        )
```

---

# 12.67 Artifact persistence

Part 12 output nên lưu:

```text
work/artifacts/
{session_code}/
{run_id}/
shots/
{asset_id}/
    projection.json
    constraints.json
    constraints_coalesced.json
    prompt_spec.json
    provider_prompt_plan.json
    compiled_prompt.txt
    negative_prompt.txt
    prompt_manifest.json
```

`prompt_manifest.json`:

```json
{
  "canonical_hash": "...",

  "projection_hash": "...",

  "constraint_set_hash": "...",

  "coalesced_constraint_hash": "...",

  "prompt_spec_hash": "...",

  "provider_prompt_plan_hash": "...",

  "prompt_hash": "...",

  "negative_prompt_hash": "...",

  "provider_key": "...",

  "model_key": "...",

  "capability_version": "...",

  "compiler_version": "prompt-spec-v1",

  "text_renderer_version": "prompt-text-v1"
}
```

---

# 12.68 Manifest DTO

```python
from __future__ import annotations

from dataclasses import dataclass


@dataclass(
    frozen=True,
    slots=True,
)
class PromptArtifactManifest:
    canonical_hash: str

    projection_hash: str

    constraint_set_hash: str

    coalesced_constraint_hash: str

    prompt_spec_hash: str

    provider_prompt_plan_hash: str

    prompt_hash: str

    negative_prompt_hash: (
        str | None
    )

    provider_key: str

    model_key: str

    capability_version: str

    compiler_version: str

    text_renderer_version: str
```

---

# 12.69 Full lineage sau Part 12

Ta đã có:

```text
canonical_json
    ↓ SHA256
canonical_hash
    ↓
AssetProjection
    ↓ SHA256
projection_hash
    ↓
ConstraintSet
    ↓ SHA256
constraint_set_hash
    ↓
ConstraintCoalescer
    ↓ SHA256
coalesced_constraint_hash
    ↓
PromptSpec
    ↓ SHA256
prompt_spec_hash
    ↓
ProviderCapabilityProjection
    ↓ SHA256
provider_prompt_plan_hash
    ↓
CompiledPrompt
    ↓ SHA256
prompt_hash
```

Sau này render record chỉ cần giữ chain này.

---

# 12.70 Test Coalescer count duplication

```python
def test_count_leaf_and_relationship_coalesce():
    constraint_set = make_constraint_set(
        constraints=(
            Constraint(
                id="C1",

                semantic_key=(
                    "permanent_geometry."
                    "superstructure."
                    "primary_tier_count"
                ),

                primitive=(
                    ConstraintPrimitive.COUNT
                ),

                severity=(
                    ConstraintSeverity.HARD
                ),

                priority=(
                    ConstraintPriority.P1_COUNT
                ),

                source_kind=(
                    ConstraintSourceKind.GEOMETRY
                ),

                source_path=(
                    "permanent_geometry."
                    "superstructure."
                    "primary_tier_count"
                ),

                source_id=None,

                subject_path=(
                    "permanent_geometry."
                    "superstructure."
                    "primary_tier_count"
                ),

                value=4,

                visual_verification=True,
            ),

            Constraint(
                id="C2",

                semantic_key=(
                    "permanent_geometry."
                    "superstructure."
                    "primary_tier_count"
                ),

                primitive=(
                    ConstraintPrimitive.COUNT
                ),

                severity=(
                    ConstraintSeverity.HARD
                ),

                priority=(
                    ConstraintPriority.P1_COUNT
                ),

                source_kind=(
                    ConstraintSourceKind.RELATIONSHIP
                ),

                source_path=None,

                source_id="R001",

                subject_path=(
                    "permanent_geometry."
                    "superstructure."
                    "primary_tier_count"
                ),

                value={
                    "id": "R001",
                    "type": "count",
                    "subject_path": (
                        "permanent_geometry."
                        "superstructure."
                        "primary_tier_count"
                    ),
                    "value": 4,
                },

                visual_verification=True,
            ),
        )
    )

    result = (
        ConstraintCoalescer()
        .coalesce(
            constraint_set
        )
    )

    assert len(
        result.constraints
    ) == 1

    item = (
        result.constraints[0]
    )

    assert (
        item.primitive
        == ConstraintPrimitive.COUNT
    )

    assert (
        item.source_constraint_ids
        == ("C1", "C2")
    )

    assert (
        item.effective_value[
            "value"
        ]
        == 4
    )
```

---

# 12.71 Test conflicting count fail

```python
import pytest


def test_conflicting_count_fails():
    constraint_set = (
        make_conflicting_count_set(
            leaf_value=4,
            relationship_value=5,
        )
    )

    with pytest.raises(
        ConstraintCoalescingError
    ):
        (
            ConstraintCoalescer()
            .coalesce(
                constraint_set
            )
        )
```

Không chọn một bên.

---

# 12.72 Test HARD survives coalescing

```python
def test_hard_wins_over_soft():
    constraint_set = (
        make_same_semantic_constraints(
            severities=(
                ConstraintSeverity.SOFT,
                ConstraintSeverity.HARD,
            )
        )
    )

    result = (
        ConstraintCoalescer()
        .coalesce(
            constraint_set
        )
    )

    assert (
        result.constraints[0]
        .severity
        == ConstraintSeverity.HARD
    )
```

---

# 12.73 Test priority cao hơn thắng

```python
def test_higher_priority_wins():
    result = (
        ConstraintCoalescer()
        .coalesce(
            make_same_semantic_constraints(
                priorities=(
                    ConstraintPriority.P4_GEOMETRY,
                    ConstraintPriority.P1_COUNT,
                )
            )
        )
    )

    assert int(
        result.constraints[0]
        .priority
    ) == 1
```

---

# 12.74 Test PromptCompiler giữ thứ tự P0 → P7

```python
def test_prompt_sections_are_priority_ordered(
    compiled_prompt_spec,
):
    kinds = [
        section.kind
        for section
        in compiled_prompt_spec.sections
    ]

    expected_order = [
        PromptSectionKind.IDENTITY,
        PromptSectionKind.COUNT_TOPOLOGY,
        PromptSectionKind.PROPORTIONS,
        PromptSectionKind.GEOMETRY,
        PromptSectionKind.OPENINGS,
        PromptSectionKind.STATE_MATERIAL,
        PromptSectionKind.CAMERA,
    ]

    positions = {
        kind: index
        for index, kind
        in enumerate(
            expected_order
        )
    }

    assert kinds == sorted(
        kinds,
        key=lambda kind: positions[
            kind
        ],
    )
```

---

# 12.75 Test compiler không biết provider

Đây là architecture test.

```python
def test_same_prompt_spec_for_different_providers(
    compiler,
    projection,
    coalesced_constraints,
    presentation,
):
    first = compiler.compile(
        projection=projection,
        constraints=(
            coalesced_constraints
        ),
        presentation=presentation,
    )

    second = compiler.compile(
        projection=projection,
        constraints=(
            coalesced_constraints
        ),
        presentation=presentation,
    )

    assert (
        first.fingerprint()
        == second.fingerprint()
    )
```

Provider chưa xuất hiện trong args.

---

# 12.76 Test exclusion negative fallback

Provider A support negative:

```python
def test_exclusion_uses_negative_channel_when_supported():
    plan = project_with_profile(
        negative_prompt_supported=True
    )

    assert (
        len(
            plan.negative_instructions
        )
        > 0
    )
```

Provider B không support:

```python
def test_exclusion_falls_back_to_main_text():
    plan = project_with_profile(
        negative_prompt_supported=False
    )

    exclusion_ids = {
        item.instruction_id
        for item
        in plan.text_instructions
    }

    assert expected_exclusion_id in (
        exclusion_ids
    )
```

---

# 12.77 Test provider không được drop HARD

```python
def test_provider_cannot_drop_hard_instruction():
    profile = (
        provider_without_text_channel()
    )

    with pytest.raises(
        ProviderCapabilityError
    ):
        (
            ProviderCapabilityProjection()
            .project(
                prompt_spec=(
                    hard_prompt_spec()
                ),
                profile=profile,
            )
        )
```

---

# 12.78 Test reference max count

```python
def test_reference_count_over_capability_fails():
    profile = (
        provider_with_reference_limit(
            2
        )
    )

    references = (
        ref("r1"),
        ref("r2"),
        ref("r3"),
    )

    with pytest.raises(
        ProviderCapabilityError
    ):
        (
            ProviderCapabilityProjection()
            .project(
                prompt_spec=(
                    prompt_spec()
                ),
                profile=profile,
                references=references,
            )
        )
```

Không silently truncate.

---

# 12.79 Test native aspect ratio không duplicate text

Nếu profile support native:

```python
def test_native_aspect_ratio_not_repeated_as_text():
    compiled = compile_with_native_ratio(
        "9:16"
    )

    assert (
        compiled.native_controls[
            "aspect_ratio"
        ]
        == "9:16"
    )

    assert (
        "Output aspect ratio: 9:16."
        not in compiled.prompt
    )
```

Nếu không:

```python
def test_aspect_ratio_falls_back_to_text():
    compiled = compile_without_native_ratio(
        "9:16"
    )

    assert (
        "Output aspect ratio: 9:16."
        in compiled.prompt
    )
```

---

# 12.80 Test prompt deterministic

```python
def test_same_inputs_generate_same_prompt_hash(
    service,
    all_inputs,
):
    first = service.compile(
        **all_inputs
    )

    second = service.compile(
        **all_inputs
    )

    assert (
        first.compiled_prompt
        .prompt
        == second.compiled_prompt
        .prompt
    )

    assert (
        first.compiled_prompt
        .prompt_hash
        == second.compiled_prompt
        .prompt_hash
    )
```

---

# 12.81 Test lineage không bị mất

```python
def test_prompt_instruction_keeps_all_source_constraints(
    prompt_spec,
):
    instruction = next(
        item
        for section
        in prompt_spec.sections
        for item
        in section.instructions
        if item.semantic_key
        == (
            "permanent_geometry."
            "superstructure."
            "primary_tier_count"
        )
    )

    assert set(
        instruction.source_constraint_ids
    ) == {
        "C1",
        "C2",
    }
```

Đây là lý do không compile thẳng text.

---

# 12.82 Prompt → Vision QA linkage

`visual_verification=True` được giữ đến `PromptInstruction`.

Sau render:

```text
PromptInstruction
    id PI-xxxx
    source constraints C1,C2
    semantic_key bow.stem
    visual_verification=true
          ↓
Vision QA
```

QA có thể trả:

```json
{
  "instruction_id": "PI-xxxx",
  "semantic_key": "permanent_geometry.bow.stem",
  "passed": false,
  "confidence": 0.91
}
```

Rồi truy ngược:

```text
PI
↓
C1
↓
I001
↓
Canonical path
↓
Revision/hash
```

Đây là lineage đầy đủ.

---

# 12.83 Không để PromptCompiler viết creative prose quá mạnh

Ví dụ Canonical:

```text
bow.stem = near_plumb
```

Compiler chỉ nên:

```text
Maintain stem: near plumb.
```

Không tự embellish:

```text
A dramatically aggressive monolithic near-plumb bow
with imposing sculptural tension...
```

Vì đó là semantic invention downstream.

Creative design đã xong ở Sonnet Concept stage.

PromptCompiler là **translator**, không phải creative designer.

---

# 12.84 Nhưng câu prompt vẫn phải tự nhiên

Ta không muốn:

```text
bow.stem = near_plumb
hull.type = displacement
stern.type = transom
```

quá máy móc.

Directive renderers tạo câu grammatical:

```text
Maintain a near-plumb stem.
Preserve the declared displacement hull geometry.
Keep the broad transom stern configuration.
```

Nhưng không thêm feature mới.

Về sau có thể nâng renderer quality mà vẫn giữ same semantics.

---

# 12.85 Prompt compiler version phải bump nếu text semantics thay đổi

Nếu chỉ sửa typo:

```text
prompt-text-v1.0.1
```

Nếu thay cách compile có thể ảnh hưởng render:

```text
prompt-spec-v2
```

Artifact Ledger phải giữ:

```text
constraint_coalescer_version
prompt_compiler_version
text_renderer_version
provider_capability_version
```

---

# 12.86 Không hash provider request giống prompt hash

Phân biệt:

```text
prompt_hash
```

chỉ:

```text
SHA256(compiled_prompt text)
```

Provider request hash sẽ bao gồm:

```text
prompt_hash
negative_prompt_hash
native_controls
reference hashes
provider
model
quality
size
seed
operation
```

Cái đó để Part Provider Adapter.

---

# 12.87 Laravel có cần lưu PromptSpec không?

Laravel có thể chỉ lưu metadata/artifact pointers.

Python Artifact Ledger nên trả Laravel:

```json
{
  "asset_id": "master_vessel",

  "canonical_hash": "...",

  "projection_hash": "...",

  "constraint_set_hash": "...",

  "prompt_spec_hash": "...",

  "provider_prompt_plan_hash": "...",

  "prompt_hash": "...",

  "provider": "...",

  "model": "..."
}
```

Laravel lưu vào:

```text
renders
artifacts
cost_entries
```

Không cần Laravel tự recompile prompt.

---

# 12.88 Không cho Laravel sửa compiled prompt sau Python

Sai:

```text
Python PromptCompiler
↓
compiled prompt
↓
Laravel concatenates:
"make it cinematic..."
↓
provider
```

Như vậy `prompt_hash` vô nghĩa.

Nếu Laravel cần thêm presentation:

```text
Laravel
↓
PresentationIntent
↓
Python PromptCompiler
```

Prompt cuối chỉ do Python tạo.

---

# 12.89 Nếu admin muốn sửa prompt thủ công?

Không overwrite compiled prompt.

Tạo:

```text
prompt revision
```

hoặc override artifact riêng:

```json
{
  "base_prompt_hash": "...",
  "override_type": "human_prompt_override",
  "override_text": "...",
  "admin_id": "...",
  "created_at": "..."
}
```

và tạo `effective_prompt_hash` mới.

Nhưng không cần đưa vào V1 automated path.

---

# 12.90 Constraint priority vẫn giữ chính xác architecture đã khóa

Sau Part 12:

| Priority | Meaning                | Prompt location        |
| -------- | ---------------------- | ---------------------- |
| P0       | identity integrity     | đầu prompt             |
| P1       | counts                 | rất sớm                |
| P2       | topology/connectivity  | rất sớm                |
| P3       | proportions            | trước geometry detail  |
| P4       | geometry relationships | giữa                   |
| P5       | openings/visibility    | sau geometry           |
| P6       | state/material         | sau permanent geometry |
| P7       | camera                 | gần cuối               |
| P8       | style/lighting         | cuối                   |

Và exclusions:

```text
negative channel nếu có
otherwise cuối main prompt
```

---

# 12.91 Tại sao camera sau geometry?

Vì nếu prompt có:

```text
close dramatic perspective
```

ở đầu trước identity:

provider dễ ưu tiên shot đẹp hơn geometry.

Ta bắt:

```text
P0 identity
P1 count
P2 topology
P3 proportions
P4 geometry
...
P7 camera
```

đúng với mục tiêu anchor/reference consistency.

---

# 12.92 Tại sao style cuối cùng?

Cùng lý do.

```text
photorealistic
cinematic
dramatic light
```

không được có quyền làm thay đổi:

```text
4 tiers
near-plumb bow
continuous shell
specific proportions
```

Style chỉ presentation.

---

# 12.93 Construction-state precedence

Prompt text renderer hiện section order:

```text
Geometry
↓
State/Material
```

Nếu construction asset:

```text
permanent geometry:
same vessel

requested state:
bare unfinished plating
```

compiler phải dùng state hiện tại.

Do Part 11 policy đã loại:

```text
finished_materials
```

khỏi construction projection, nên không có prompt contradiction:

```text
deep anthracite final paint
+
bare unpainted steel
```

Đây là lý do `AssetProjectionPolicy` đứng trước compiler.

---

# 12.94 Không để ProviderCapabilityProjection thay đổi semantics

Provider capability layer có thể:

```text
move aspect_ratio:
text → native

move exclusion:
text → negative channel
```

nhưng không:

```text
count 4 → approximate several
```

Không:

```text
near_plumb → modern sharp bow
```

Không:

```text
HARD → SOFT
```

Nếu provider không hỗ trợ semantics:

```text
route/fail
```

---

# 12.95 Production pipeline sau Phần 12

Bây giờ toàn pipeline thành:

```text
ARTICLE
  ↓
Haiku Evidence
  ↓
Deterministic Verification
  ↓
InspirationBrief
  ↓
CategoryCreativeProfile
  ↓
ConceptInput Snapshot
  ↓
Sonnet 5
  ↓
CanonicalDesignSpec V1
  ↓
Core + Effective Schema
  ↓
Semantic Validation
  ↓
Repair ≤ 1
  ↓
Normalize
  ↓
Schema-aware Serialization
  ↓
Revalidation
  ↓
SHA-256
  ↓
Decision Ledger
  ↓
Atomic Freeze
══════════════════════════════════════
      LARAVEL CANONICAL BOUNDARY
══════════════════════════════════════
  ↓
canonical_json
canonical_hash
asset_request
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
  ↓
ConstraintCoalescer
  ↓
CoalescedConstraintSet
  ↓
PromptCompilerV1
  ↓
PromptSpec
  ↓
ProviderCapabilityProjection
  ↓
ProviderPromptPlan
  ↓
PromptBudgetReducer
  ↓
PromptDeliveryValidator
  ↓
PromptSpecTextRenderer
  ↓
CompiledPrompt
  ↓
Prompt Hash
══════════════════════════════════════
      PROVIDER ADAPTER BOUNDARY
══════════════════════════════════════
  ↓
GPT Image / Gemini / other provider
```

---

# 12.96 Các invariant tôi chốt sau Part 12

```text
1. PromptCompiler không biết provider.

2. ProviderCapabilityProjection không biết
   CanonicalDesignSpec.

3. ConstraintCoalescer không làm mất lineage.

4. Conflicting constraints → fail,
   không chọn ngẫu nhiên.

5. HARD constraint không bao giờ bị
   silently dropped.

6. Negative prompt unsupported
   → exclusion fallback về main text.

7. Presentation/state không được ghi ngược
   vào Canonical Design.

8. Reference inputs là typed artifacts,
   không phải câu prose trong prompt.

9. Prompt budget chỉ được bỏ SOFT constraint.

10. Same inputs + same versions
    → same PromptSpec
    → same compiled prompt
    → same prompt hash.
```

Và separation quan trọng nhất hiện tại là:

```text
CanonicalDesignSpec
    = design truth

AssetProjection
    = asset-specific truth projection

ConstraintSet
    = render semantics

CoalescedConstraintSet
    = render semantics without redundant delivery

PromptSpec
    = provider-independent language IR

ProviderPromptPlan
    = provider delivery strategy

CompiledPrompt
    = exact text/native controls sent downstream
```

**Sau Phần 12, bước hợp lý tiếp theo là Phần 13 — `Provider Adapter + RenderRequest + ReferenceSet + Request Hash + Artifact Ledger + retry/idempotency`**. Phần đó sẽ nối `CompiledPrompt` vào GPT Image/Gemini một cách production-grade, nhưng provider adapter tuyệt đối không được sửa prompt semantics nữa.
